<?php
/**
 * Seefeed ICS parsing – map VEVENT blocks to the events.json shape.
 *
 * Used by fetch.php (CalDAV) and ics-parser-fixture-test.php (local fixtures).
 * Recurring VEVENTs with a supported RRULE are expanded into concrete
 * occurrences within a configurable time horizon.
 *
 * @file        fetch/ics-parser.php
 * @project     Seefeed
 * @author      elektrischerwalfisch
 * @see         fetch/fetch.php
 * @see         fetch/ics-parser-fixture-test.php
 */
declare(strict_types=1);

/** Default months before "now" included when expanding RRULE series. */
const RRULE_HORIZON_PAST_MONTHS = 3;

/** Default months after "now" included when expanding RRULE series. */
const RRULE_HORIZON_FUTURE_MONTHS = 6;

/**
 * Parse ICS and return public event objects (recurrence expanded, `id` set, `_ics` stripped).
 *
 * @param array{
 *   past_months?:int,
 *   future_months?:int,
 *   now?:DateTimeImmutable|string
 * } $options
 * @return list<array<string, mixed>>
 */
function parseIcsEvents(string $ics, array $options = []): array
{
    $events = expandIcsRecurringEvents(parseIcsEventsInternal($ics), $options);
    return array_map('stripIcsMetaFromEvent', $events);
}

/**
 * Parse VEVENTs and keep internal `_ics` recurrence metadata (no expansion).
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

    usort($events, static fn(array $a, array $b): int => ($b['start'] ?? '') <=> ($a['start'] ?? ''));

    return $events;
}

/**
 * Expand masters with a supported RRULE into occurrences inside the horizon.
 * Unsupported RRULEs stay as a single event (with a CLI warning).
 * EXDATE skips series slots; RECURRENCE-ID overrides replace the matching slot.
 *
 * @param list<array<string, mixed>> $events
 * @param array{
 *   past_months?:int,
 *   future_months?:int,
 *   now?:DateTimeImmutable|string
 * } $options
 * @return list<array<string, mixed>>
 */
function expandIcsRecurringEvents(array $events, array $options = []): array
{
    [$windowStart, $windowEnd] = rruleHorizonWindow($options);

    $plain = [];
    $masters = [];
    /** @var list<array<string, mixed>> $exceptions */
    $exceptions = [];

    foreach ($events as $event) {
        $rrule = (string) ($event['_ics']['rrule'] ?? '');
        $recurrenceId = (string) ($event['_ics']['recurrence_id'] ?? '');

        if ($recurrenceId !== '') {
            $exceptions[] = $event;
            continue;
        }
        if ($rrule !== '') {
            $masters[] = $event;
            continue;
        }
        $plain[] = $event;
    }

    /** @var array<string, true> $overrideKeys uid|recurrence_id */
    $overrideKeys = [];
    foreach ($exceptions as $exception) {
        $uid = (string) ($exception['_ics']['uid'] ?? '');
        $rid = (string) ($exception['_ics']['recurrence_id'] ?? '');
        if ($uid !== '' && $rid !== '') {
            $overrideKeys[$uid . '|' . $rid] = true;
        }
    }

    $out = $plain;

    foreach ($masters as $master) {
        $rule = parseRruleParts((string) ($master['_ics']['rrule'] ?? ''));
        if ($rule === null) {
            icsParserWarn('Unsupported RRULE, keeping single event: ' . ($master['_ics']['rrule'] ?? ''));
            $out[] = $master;
            continue;
        }

        $starts = generateRruleOccurrenceStarts($master, $rule, $windowStart, $windowEnd);
        if ($starts === []) {
            continue;
        }

        $uid = (string) ($master['_ics']['uid'] ?? '');
        $exdateSet = [];
        foreach ($master['_ics']['exdates'] ?? [] as $exdate) {
            if (is_string($exdate) && $exdate !== '') {
                $exdateSet[$exdate] = true;
            }
        }

        $allDay = !str_contains((string) ($master['start'] ?? ''), 'T');
        foreach ($starts as $occStart) {
            $startJson = dateTimeToJson($occStart, $allDay);
            if (isset($exdateSet[$startJson])) {
                continue;
            }
            if ($uid !== '' && isset($overrideKeys[$uid . '|' . $startJson])) {
                continue;
            }
            $out[] = occurrenceFromMaster($master, $occStart);
        }
    }

    foreach ($exceptions as $exception) {
        if (eventTouchesRruleHorizon($exception, $windowStart, $windowEnd)) {
            $out[] = $exception;
        }
    }

    usort($out, static fn(array $a, array $b): int => ($b['start'] ?? '') <=> ($a['start'] ?? ''));

    return $out;
}

/**
 * True if event start or RECURRENCE-ID falls inside the expansion horizon.
 *
 * @param array<string, mixed> $event
 */
function eventTouchesRruleHorizon(
    array $event,
    DateTimeImmutable $windowStart,
    DateTimeImmutable $windowEnd
): bool {
    $start = jsonDateToDateTime((string) ($event['start'] ?? ''));
    if ($start !== null && $start >= $windowStart && $start <= $windowEnd) {
        return true;
    }

    $rid = (string) ($event['_ics']['recurrence_id'] ?? '');
    if ($rid === '') {
        return false;
    }
    $ridDt = jsonDateToDateTime($rid);
    return $ridDt !== null && $ridDt >= $windowStart && $ridDt <= $windowEnd;
}

/**
 * @param array{
 *   past_months?:int,
 *   future_months?:int,
 *   now?:DateTimeImmutable|string
 * } $options
 * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
 */
function rruleHorizonWindow(array $options): array
{
    $now = $options['now'] ?? new DateTimeImmutable('now');
    if (is_string($now)) {
        $parsed = date_create_immutable($now);
        $now = $parsed !== false ? $parsed : new DateTimeImmutable('now');
    }

    $past = (int) ($options['past_months'] ?? RRULE_HORIZON_PAST_MONTHS);
    $future = (int) ($options['future_months'] ?? RRULE_HORIZON_FUTURE_MONTHS);
    if ($past < 0) {
        $past = RRULE_HORIZON_PAST_MONTHS;
    }
    if ($future < 0) {
        $future = RRULE_HORIZON_FUTURE_MONTHS;
    }

    return [
        $now->modify('-' . $past . ' months'),
        $now->modify('+' . $future . ' months'),
    ];
}

/**
 * @return array{
 *   FREQ:string,
 *   INTERVAL:int,
 *   UNTIL:?DateTimeImmutable,
 *   COUNT:?int,
 *   BYDAY:list<array{n:?int,day:string}>
 * }|null
 */
function parseRruleParts(string $rrule): ?array
{
    $parts = [];
    foreach (explode(';', $rrule) as $piece) {
        $piece = trim($piece);
        if ($piece === '') {
            continue;
        }
        if (!str_contains($piece, '=')) {
            return null;
        }
        [$key, $value] = explode('=', $piece, 2);
        $parts[strtoupper(trim($key))] = trim($value);
    }

    $allowed = ['FREQ', 'INTERVAL', 'UNTIL', 'COUNT', 'BYDAY'];
    foreach (array_keys($parts) as $key) {
        if (!in_array($key, $allowed, true)) {
            return null;
        }
    }

    $freq = strtoupper($parts['FREQ'] ?? '');
    if ($freq !== 'WEEKLY' && $freq !== 'MONTHLY') {
        return null;
    }

    $interval = isset($parts['INTERVAL']) ? (int) $parts['INTERVAL'] : 1;
    if ($interval < 1) {
        return null;
    }

    $until = null;
    if (!empty($parts['UNTIL'])) {
        $untilJson = icsDateToJson($parts['UNTIL'], strlen($parts['UNTIL']) === 8 ? 'VALUE=DATE' : '');
        $until = jsonDateToDateTime($untilJson);
        if ($until === null) {
            return null;
        }
    }

    $count = isset($parts['COUNT']) ? (int) $parts['COUNT'] : null;
    if ($count !== null && $count < 1) {
        return null;
    }

    $byday = [];
    if (!empty($parts['BYDAY'])) {
        foreach (explode(',', $parts['BYDAY']) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if (!preg_match('/^(-?\d{1,2})?(SU|MO|TU|WE|TH|FR|SA)$/i', $token, $m)) {
                return null;
            }
            $byday[] = [
                'n' => $m[1] !== '' ? (int) $m[1] : null,
                'day' => strtoupper($m[2]),
            ];
        }
        if ($byday === []) {
            return null;
        }
    }

    // Weekly + ordinal BYDAY (e.g. 3WE) is out of v1 scope.
    if ($freq === 'WEEKLY') {
        foreach ($byday as $item) {
            if ($item['n'] !== null) {
                return null;
            }
        }
    }

    return [
        'FREQ' => $freq,
        'INTERVAL' => $interval,
        'UNTIL' => $until,
        'COUNT' => $count,
        'BYDAY' => $byday,
    ];
}

/**
 * @param array<string, mixed> $event
 * @param array{
 *   FREQ:string,
 *   INTERVAL:int,
 *   UNTIL:?DateTimeImmutable,
 *   COUNT:?int,
 *   BYDAY:list<array{n:?int,day:string}>
 * } $rule
 * @return list<DateTimeImmutable>
 */
function generateRruleOccurrenceStarts(
    array $event,
    array $rule,
    DateTimeImmutable $windowStart,
    DateTimeImmutable $windowEnd
): array {
    $dtStart = jsonDateToDateTime((string) ($event['start'] ?? ''));
    if ($dtStart === null) {
        return [];
    }

    if ($rule['FREQ'] === 'WEEKLY') {
        $candidates = generateWeeklyOccurrenceStarts($dtStart, $rule, $windowEnd);
    } else {
        $candidates = generateMonthlyOccurrenceStarts($dtStart, $rule, $windowEnd);
    }

    $out = [];
    $seriesIndex = 0;
    foreach ($candidates as $candidate) {
        if ($candidate < $dtStart) {
            continue;
        }
        if ($rule['UNTIL'] !== null && $candidate > $rule['UNTIL']) {
            break;
        }

        $seriesIndex++;
        if ($rule['COUNT'] !== null && $seriesIndex > $rule['COUNT']) {
            break;
        }

        if ($candidate >= $windowStart && $candidate <= $windowEnd) {
            $out[] = $candidate;
        }

        if ($candidate > $windowEnd) {
            break;
        }
    }

    return $out;
}

/**
 * @param array{
 *   INTERVAL:int,
 *   BYDAY:list<array{n:?int,day:string}>
 * } $rule
 * @return list<DateTimeImmutable>
 */
function generateWeeklyOccurrenceStarts(
    DateTimeImmutable $dtStart,
    array $rule,
    DateTimeImmutable $windowEnd
): array {
    $weekdays = [];
    if ($rule['BYDAY'] === []) {
        $weekdays[] = (int) $dtStart->format('N');
    } else {
        foreach ($rule['BYDAY'] as $item) {
            $weekdays[] = icsWeekdayToIso($item['day']);
        }
    }
    $weekdays = array_values(array_unique($weekdays));
    sort($weekdays);

    $time = $dtStart->format('H:i:s');
    $cursor = $dtStart->setTime(0, 0, 0);
    $weekStart = $cursor->modify('-' . (((int) $cursor->format('N')) - 1) . ' days');
    $interval = $rule['INTERVAL'];
    $out = [];
    $guard = 0;

    while ($guard++ < 2000) {
        if ($weekStart > $windowEnd->modify('+7 days')) {
            break;
        }
        foreach ($weekdays as $isoDay) {
            $day = $weekStart->modify('+' . ($isoDay - 1) . ' days');
            $day = date_create_immutable($day->format('Y-m-d') . ' ' . $time) ?: $day;
            $out[] = $day;
        }
        $weekStart = $weekStart->modify('+' . (7 * $interval) . ' days');
    }

    usort($out, static fn(DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
    return $out;
}

/**
 * @param array{
 *   INTERVAL:int,
 *   BYDAY:list<array{n:?int,day:string}>
 * } $rule
 * @return list<DateTimeImmutable>
 */
function generateMonthlyOccurrenceStarts(
    DateTimeImmutable $dtStart,
    array $rule,
    DateTimeImmutable $windowEnd
): array {
    $interval = $rule['INTERVAL'];
    $time = $dtStart->format('H:i:s');
    $startMonth = $dtStart->modify('first day of this month')->setTime(0, 0, 0);
    $out = [];
    $monthOffset = 0;
    $guard = 0;

    while ($guard++ < 500) {
        $monthDate = $startMonth->modify('+' . ($monthOffset * $interval) . ' months');
        if ($monthDate > $windowEnd->modify('first day of next month')) {
            break;
        }

        if ($rule['BYDAY'] === []) {
            $day = (int) $dtStart->format('j');
            $candidate = buildMonthDay($monthDate, $day, $time);
            if ($candidate !== null) {
                $out[] = $candidate;
            }
        } else {
            foreach ($rule['BYDAY'] as $item) {
                $isoDay = icsWeekdayToIso($item['day']);
                $n = $item['n'];
                if ($n === null) {
                    foreach (allWeekdaysInMonth($monthDate, $isoDay, $time) as $candidate) {
                        $out[] = $candidate;
                    }
                    continue;
                }
                $candidate = nthWeekdayOfMonth($monthDate, $isoDay, $n, $time);
                if ($candidate !== null) {
                    $out[] = $candidate;
                }
            }
        }

        $monthOffset++;
    }

    usort($out, static fn(DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
    return $out;
}

function icsWeekdayToIso(string $day): int
{
    $map = [
        'MO' => 1,
        'TU' => 2,
        'WE' => 3,
        'TH' => 4,
        'FR' => 5,
        'SA' => 6,
        'SU' => 7,
    ];
    return $map[strtoupper($day)] ?? 1;
}

function nthWeekdayOfMonth(
    DateTimeImmutable $anyDayInMonth,
    int $isoWeekday,
    int $ordinal,
    string $time
): ?DateTimeImmutable {
    if ($ordinal === 0) {
        return null;
    }

    if ($ordinal > 0) {
        $first = $anyDayInMonth->modify('first day of this month');
        $delta = ($isoWeekday - (int) $first->format('N') + 7) % 7;
        $day = $first->modify('+' . ($delta + 7 * ($ordinal - 1)) . ' days');
        if ($day->format('n') !== $first->format('n')) {
            return null;
        }
    } else {
        $last = $anyDayInMonth->modify('last day of this month');
        $delta = ((int) $last->format('N') - $isoWeekday + 7) % 7;
        $day = $last->modify('-' . ($delta + 7 * (abs($ordinal) - 1)) . ' days');
        if ($day->format('n') !== $last->format('n')) {
            return null;
        }
    }

    return date_create_immutable($day->format('Y-m-d') . ' ' . $time) ?: null;
}

/**
 * @return list<DateTimeImmutable>
 */
function allWeekdaysInMonth(
    DateTimeImmutable $anyDayInMonth,
    int $isoWeekday,
    string $time
): array {
    $out = [];
    for ($n = 1; $n <= 5; $n++) {
        $day = nthWeekdayOfMonth($anyDayInMonth, $isoWeekday, $n, $time);
        if ($day !== null) {
            $out[] = $day;
        }
    }
    return $out;
}

function buildMonthDay(
    DateTimeImmutable $anyDayInMonth,
    int $dayOfMonth,
    string $time
): ?DateTimeImmutable {
    $y = (int) $anyDayInMonth->format('Y');
    $m = (int) $anyDayInMonth->format('n');
    $daysInMonth = (int) $anyDayInMonth->format('t');
    if ($dayOfMonth < 1 || $dayOfMonth > $daysInMonth) {
        return null;
    }
    $date = sprintf('%04d-%02d-%02d', $y, $m, $dayOfMonth);
    return date_create_immutable($date . ' ' . $time) ?: null;
}

/**
 * @param array<string, mixed> $master
 * @return array<string, mixed>
 */
function occurrenceFromMaster(array $master, DateTimeImmutable $occStart): array
{
    $masterStart = jsonDateToDateTime((string) ($master['start'] ?? ''));
    $masterEnd = jsonDateToDateTime((string) ($master['end'] ?? ''));
    $allDay = !str_contains((string) ($master['start'] ?? ''), 'T');

    $occEnd = $occStart;
    if ($masterStart !== null && $masterEnd !== null) {
        $delta = $masterEnd->getTimestamp() - $masterStart->getTimestamp();
        if ($delta > 0) {
            $occEnd = $occStart->modify('+' . $delta . ' seconds');
        }
    }

    $event = $master;
    $event['start'] = dateTimeToJson($occStart, $allDay);
    $event['end'] = dateTimeToJson($occEnd, $allDay);
    if (isset($event['_ics']) && is_array($event['_ics'])) {
        $event['_ics']['rrule'] = '';
        $event['_ics']['exdates'] = [];
        $event['_ics']['recurrence_id'] = '';
        // Expanded from RRULE → public id is uid@start (see assignPublicEventId)
        $event['_ics']['is_occurrence'] = true;
    }

    return $event;
}

function jsonDateToDateTime(string $json): ?DateTimeImmutable
{
    if ($json === '') {
        return null;
    }
    if (!str_contains($json, 'T')) {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $json);
        return $dt instanceof DateTimeImmutable ? $dt : null;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $json);
    return $dt instanceof DateTimeImmutable ? $dt : null;
}

function dateTimeToJson(DateTimeImmutable $dt, bool $allDay): string
{
    return $allDay ? $dt->format('Y-m-d') : $dt->format('Y-m-d\TH:i:s');
}

function icsParserWarn(string $message): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'ics-parser: ' . $message . "\n");
    }
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
 * Build public `id` from ICS UID (and occurrence start for RRULE instances).
 *
 * Singles: UID. Expanded RRULE occurrences and RECURRENCE-ID exceptions: UID@start.
 *
 * @param array<string, mixed> $event
 */
function assignPublicEventId(array $event): array
{
    $meta = $event['_ics'] ?? null;
    if (!is_array($meta)) {
        return $event;
    }

    $uid = trim((string) ($meta['uid'] ?? ''));
    if ($uid === '') {
        return $event;
    }

    $isOccurrence = !empty($meta['is_occurrence'])
        || trim((string) ($meta['recurrence_id'] ?? '')) !== '';
    $start = (string) ($event['start'] ?? '');

    $event['id'] = $isOccurrence && $start !== ''
        ? $uid . '@' . $start
        : $uid;

    return $event;
}

/**
 * Remove internal `_ics` metadata and set public `id` (events.json shape).
 *
 * @param array<string, mixed> $event
 * @return array<string, mixed>
 */
function stripIcsMetaFromEvent(array $event): array
{
    $event = assignPublicEventId($event);
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
