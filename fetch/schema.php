<?php
declare(strict_types=1);

/**
 * Seefeed – build Schema.org JSON-LD for events from events.json + venues.json.
 *
 * @file        fetch/schema.php
 * @project     Seefeed
 * @author      Seefeed
 * @version     0.1.0
 * @since       2026-09
 * @see         fetch/fetch.php
 */

/**
 * Build a list of Schema.org Event (or subtype) objects.
 *
 * Options:
 *   filter            "upcoming" (end >= today UTC) | "all" (default upcoming)
 *   eventType         Schema @type, e.g. "Event" or "ExhibitionEvent" (default Event)
 *   eventImageBase    If set and event has attach, build image URL (base + basename)
 *
 * @param list<array<string, mixed>> $events
 * @param list<array<string, mixed>> $venues
 * @param array{filter?:string,eventType?:string,eventImageBase?:string} $options
 * @return list<array<string, mixed>>
 */
function SeefeedBuildEventsJsonLd(array $events, array $venues, array $options = []): array
{
    $filter = strtolower((string) ($options['filter'] ?? 'upcoming'));
    $eventType = (string) ($options['eventType'] ?? 'Event');
    if ($eventType === '') {
        $eventType = 'Event';
    }
    $imageBase = (string) ($options['eventImageBase'] ?? '');

    $venuesByFn = [];
    foreach ($venues as $venue) {
        $fn = (string) ($venue['fn'] ?? '');
        if ($fn !== '') {
            $venuesByFn[$fn] = $venue;
        }
    }

    $today = gmdate('Y-m-d');
    $out = [];

    foreach ($events as $event) {
        $endDay = SeefeedSchemaDatePart((string) ($event['end'] ?? $event['start'] ?? ''));
        $startDay = SeefeedSchemaDatePart((string) ($event['start'] ?? ''));
        if ($startDay === '') {
            continue;
        }
        if ($filter === 'upcoming' && ($endDay === '' || $endDay < $today)) {
            continue;
        }

        $item = [
            '@context' => 'https://schema.org',
            '@type' => $eventType,
            'name' => (string) ($event['summary'] ?? ''),
            'startDate' => $startDay,
        ];
        if ($endDay !== '') {
            $item['endDate'] = $endDay;
        }

        $description = trim((string) ($event['description'] ?? ''));
        if ($description !== '') {
            $item['description'] = $description;
        }

        $locationName = (string) ($event['location'] ?? '');
        $venue = $locationName !== '' ? ($venuesByFn[$locationName] ?? null) : null;

        $place = [
            '@type' => 'Place',
            'name' => $locationName !== '' ? $locationName : 'Unknown',
        ];
        if (is_array($venue)) {
            $address = SeefeedSchemaPostalAddress($venue);
            if ($address !== null) {
                $place['address'] = $address;
            }
            $orgName = trim((string) ($venue['org'] ?? ''));
            if ($orgName === '') {
                $orgName = trim((string) ($venue['fn'] ?? ''));
            }
            if ($orgName !== '') {
                $item['organizer'] = [
                    '@type' => 'Organization',
                    'name' => $orgName,
                ];
            }
            $url = trim((string) ($venue['url'] ?? ''));
            if ($url !== '') {
                $item['url'] = $url;
            }
        }
        if ($locationName !== '' || isset($place['address'])) {
            $item['location'] = $place;
        }

        $attach = (string) ($event['attach'] ?? '');
        if ($attach !== '' && $imageBase !== '') {
            $filename = SeefeedSchemaAttachBasename($attach);
            if ($filename !== '') {
                $item['image'] = $imageBase . $filename;
            }
        } elseif ($attach !== '' && (str_starts_with($attach, 'http://') || str_starts_with($attach, 'https://'))) {
            $item['image'] = $attach;
        }

        $out[] = $item;
    }

    return $out;
}

/**
 * Load data/*.json, build JSON-LD, write data/events.schema.json atomically.
 *
 * @param array{filter?:string,eventType?:string,eventImageBase?:string} $options
 * @return int Number of schema items written
 */
function SeefeedWriteEventsSchemaFile(string $dataDir, array $options = []): int
{
    $eventsPath = $dataDir . '/events.json';
    $venuesPath = $dataDir . '/venues.json';
    $outPath = $dataDir . '/events.schema.json';

    $eventsData = SeefeedSchemaLoadJson($eventsPath);
    $venuesData = SeefeedSchemaLoadJson($venuesPath);
    $events = $eventsData['events'] ?? [];
    $venues = $venuesData['venues'] ?? [];
    if (!is_array($events) || !is_array($venues)) {
        throw new RuntimeException('Invalid events.json or venues.json shape');
    }

    /** @var list<array<string, mixed>> $events */
    /** @var list<array<string, mixed>> $venues */
    $schema = SeefeedBuildEventsJsonLd($events, $venues, $options);
    SeefeedSchemaWriteJsonAtomic($outPath, $schema);

    return count($schema);
}

/**
 * @return array<string, mixed>
 */
function SeefeedSchemaLoadJson(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Missing file: ' . $path);
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('Cannot read: ' . $path);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON: ' . $path);
    }

    return $data;
}

/**
 * @param list<array<string, mixed>>|array<string, mixed> $data
 */
function SeefeedSchemaWriteJsonAtomic(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('json_encode failed for ' . $path);
    }
    $json .= "\n";

    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Cannot write ' . $tmp);
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot replace ' . $path);
    }
}

function SeefeedSchemaDatePart(string $iso): string
{
    if ($iso === '') {
        return '';
    }

    return substr($iso, 0, 10);
}

/**
 * @param array<string, mixed> $venue
 * @return array<string, string>|null
 */
function SeefeedSchemaPostalAddress(array $venue): ?array
{
    $street = trim((string) ($venue['street'] ?? ''));
    $postal = trim((string) ($venue['postalCode'] ?? ''));
    $locality = trim((string) ($venue['locality'] ?? ''));
    $country = trim((string) ($venue['country'] ?? ''));
    if ($street === '' && $postal === '' && $locality === '') {
        return null;
    }

    $address = ['@type' => 'PostalAddress'];
    if ($street !== '') {
        $address['streetAddress'] = $street;
    }
    if ($postal !== '') {
        $address['postalCode'] = $postal;
    }
    if ($locality !== '') {
        $address['addressLocality'] = $locality;
    }
    if ($country !== '') {
        $address['addressCountry'] = $country;
    }

    return $address;
}

function SeefeedSchemaAttachBasename(string $attach): string
{
    $trimmed = rtrim($attach, '/');
    if ($trimmed === '') {
        return '';
    }
    if (str_contains($trimmed, '/')) {
        $parts = explode('/', $trimmed);

        return (string) end($parts);
    }

    return $trimmed;
}
