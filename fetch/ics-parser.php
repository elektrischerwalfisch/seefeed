<?php
/**
 * Seefeed ICS parsing – map VEVENT blocks to the events.json shape.
 *
 * Used by fetch.php (CalDAV) and ics-parser-fixture-test.php (local fixtures).
 * Recurrence fields live in `_ics` until stripped for public JSON;
 * RRULE expansion is added in later steps.
 *
 * @file        fetch/ics-parser.php
 * @project     Seefeed
 * @see         fetch/fetch.php
 * @see         fetch/ics-parser-fixture-test.php
 */
declare(strict_types=1);

/**
 * @return list<array<string, mixed>>
 */
function parseIcsEvents(string $ics): array
{
    $events = parseIcsEventsInternal($ics);
    return array_map('stripIcsMetaFromEvent', $events);
}

/**
 * Parse VEVENTs and keep internal `_ics` recurrence metadata (for expansion).
 *
 * @return list<array<string, mixed>>
 */
function parseIcsEventsInternal(string $ics): array
{
    $ics = unfoldIcs($ics);
    $events = [];

    if (!preg_match_all('/BEGIN:VEVENT\r?\n(.*?)\r?\nEND:VEVENT/s', $ics, $blocks)) {
        return $events;
    }

    foreach ($blocks[1] as $block) {
        // Drop nested VALARM components so alarm DESCRIPTION/SUMMARY do not
        // overwrite the parent VEVENT fields (e.g. Mozilla default alarm text).
        $block = preg_replace('/BEGIN:VALARM\r?\n.*?END:VALARM\r?\n?/s', '', $block) ?? $block;
        $props = parseIcsProperties($block);
        $event = mapIcsPropertiesToEvent($props);
        if ($event !== null) {
            $events[] = $event;
        }
    }

    // Newest first (ISO start strings compare correctly for date and datetime)
    usort($events, static fn(array $a, array $b): int => ($b['start'] ?? '') <=> ($a['start'] ?? ''));

    return $events;
}

/**
 * @param array<string, mixed> $props
 * @return array<string, mixed>|null
 */
function mapIcsPropertiesToEvent(array $props): ?array
{
    $startRaw = $props['DTSTART'] ?? '';
    $endRaw = $props['DTEND'] ?? '';
    if ($startRaw === '') {
        return null;
    }

    $start = icsDateToJson($startRaw, $props['DTSTART_PARAMS'] ?? '');
    $end = $endRaw !== ''
        ? icsDateToJson($endRaw, $props['DTEND_PARAMS'] ?? '')
        : $start;

    // All-day DTEND is exclusive in ICS → last exhibition day in JSON
    $startAllDay = !str_contains($start, 'T');
    $endAllDay = !str_contains($end, 'T');
    if ($startAllDay && $endAllDay && $end !== '') {
        $end = date('Y-m-d', strtotime($end . ' -1 day'));
    }

    $categories = [];
    if (!empty($props['CATEGORIES'])) {
        foreach (explode(',', (string) $props['CATEGORIES']) as $cat) {
            $cat = trim(unescapeIcsText($cat));
            if ($cat !== '') {
                $categories[] = $cat;
            }
        }
    }

    $event = [
        'summary' => unescapeIcsText($props['SUMMARY'] ?? ''),
        'start' => $start,
        'end' => $end,
        'location' => unescapeIcsText($props['LOCATION'] ?? ''),
        'categories' => $categories,
        'description' => unescapeIcsText($props['DESCRIPTION'] ?? ''),
    ];

    $attach = $props['ATTACH'] ?? '';
    if ($attach !== '') {
        $event['attach'] = $attach;
    }

    $exdates = [];
    /** @var list<array{value:string,params:string}> $exdateList */
    $exdateList = $props['EXDATE_LIST'] ?? [];
    foreach ($exdateList as $item) {
        $exdates[] = icsDateToJson($item['value'], $item['params']);
    }

    $recurrenceIdRaw = $props['RECURRENCE-ID'] ?? '';
    $recurrenceId = $recurrenceIdRaw !== ''
        ? icsDateToJson($recurrenceIdRaw, $props['RECURRENCE-ID_PARAMS'] ?? '')
        : '';

    $event['_ics'] = [
        'uid' => (string) ($props['UID'] ?? ''),
        'rrule' => (string) ($props['RRULE'] ?? ''),
        'exdates' => $exdates,
        'recurrence_id' => $recurrenceId,
    ];

    return $event;
}

/**
 * Remove internal `_ics` metadata from a parsed event (public JSON shape).
 *
 * @param array<string, mixed> $event
 * @return array<string, mixed>
 */
function stripIcsMetaFromEvent(array $event): array
{
    unset($event['_ics']);
    return $event;
}

function unfoldIcs(string $ics): string
{
    return preg_replace("/\r?\n[ \t]/", '', $ics) ?? $ics;
}

/**
 * Split an unfolded ICS/vCard content line into name+params and value.
 * Colons inside quoted parameter values (e.g. ALTREP="data:text/html,...")
 * must not be treated as the name/value separator.
 *
 * @return array{0: string, 1: string}|null
 */
function splitIcsContentLine(string $line): ?array
{
    $inQuotes = false;
    $length = strlen($line);
    for ($i = 0; $i < $length; $i++) {
        $char = $line[$i];
        if ($char === '"') {
            $inQuotes = !$inQuotes;
            continue;
        }
        if ($char === ':' && !$inQuotes) {
            return [substr($line, 0, $i), substr($line, $i + 1)];
        }
    }
    return null;
}

/**
 * @return array<string, mixed>
 */
function parseIcsProperties(string $block): array
{
    $props = [];
    foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        $parts = splitIcsContentLine($line);
        if ($parts === null) {
            continue;
        }
        [$namePart, $value] = $parts;
        $params = '';
        $name = $namePart;
        if (str_contains($namePart, ';')) {
            [$name, $params] = explode(';', $namePart, 2);
        }
        $name = strtoupper($name);

        // EXDATE may repeat; collect every occurrence for recurrence expansion.
        if ($name === 'EXDATE') {
            if (!isset($props['EXDATE_LIST']) || !is_array($props['EXDATE_LIST'])) {
                $props['EXDATE_LIST'] = [];
            }
            foreach (explode(',', $value) as $one) {
                $one = trim($one);
                if ($one === '') {
                    continue;
                }
                $props['EXDATE_LIST'][] = [
                    'value' => $one,
                    'params' => $params,
                ];
            }
        }

        $props[$name] = $value;
        $props[$name . '_PARAMS'] = $params;
    }
    return $props;
}

function icsDateToJson(string $value, string $params): string
{
    $isDate = str_contains(strtoupper($params), 'VALUE=DATE')
        || (strlen($value) === 8 && !str_contains($value, 'T'));

    if ($isDate) {
        return substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2);
    }

    // 20260620T180000 or 20260620T180000Z
    $value = rtrim($value, 'Z');
    if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})$/', $value, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3] . 'T' . $m[4] . ':' . $m[5] . ':' . $m[6];
    }

    return $value;
}

function unescapeIcsText(string $value): string
{
    return str_replace(
        ['\\\\', '\\;', '\\,', '\\n', '\\N'],
        ['\\', ';', ',', "\n", "\n"],
        $value
    );
}
