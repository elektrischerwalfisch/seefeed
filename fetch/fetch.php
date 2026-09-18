<?php
declare(strict_types=1);

/**
 * Seefeed fetch – pull Nextcloud calendar (CalDAV/ICS) and venues (CardDAV/vCard)
 * into data/events.json and data/venues.json.
 *
 * Setup: cp config.example.php config.php and fill credentials.
 * CLI:   php fetch/fetch.php
 * Cron:  curl -fsS "https://example/fetch/fetch.php?token=YOUR_FETCH_TOKEN"
 *
 * @file        fetch/fetch.php
 * @project     Seefeed
 * @author      Seefeed
 * @version     0.1.0
 * @since       2026-09
 * @see         fetch/config.example.php
 * @see         fetch/schema.php
 */

// --- Bootstrap --------------------------------------------------------------
// Load credentials from gitignored config.php; stop early if missing.
// Web runs require a matching fetch_token (query ?token= or header X-Fetch-Token).

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    failExit('Missing fetch/config.php — copy config.example.php and fill in values.', 1, 500);
}

/** @var array{nc_user:string,nc_app_password:string,calendar_url:string,addressbook_url:string,fetch_token?:string,debug?:bool,write_schema?:bool,schema_filter?:string,schema_event_type?:string,schema_event_image_base?:string} $config */
$config = require $configPath;

// CLI always verbose; URL runs follow config debug (default off).
$FETCH_DEBUG = PHP_SAPI === 'cli' || !empty($config['debug']);

if (PHP_SAPI !== 'cli') {
    $expected = trim((string) ($config['fetch_token'] ?? ''));
    $provided = trim((string) ($_GET['token'] ?? $_POST['token'] ?? $_SERVER['HTTP_X_FETCH_TOKEN'] ?? ''));
    if ($expected === '') {
        failExit('Forbidden: fetch_token is empty in config.php', 1, 403);
    }
    if ($provided === '') {
        failExit('Forbidden: missing token (use ?token=…)', 1, 403);
    }
    if (!hash_equals($expected, $provided)) {
        failExit('Forbidden: token mismatch', 1, 403);
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$dataDir = dirname(__DIR__) . '/data';

// --- Main: fetch, parse, write ----------------------------------------------
// On any failure keep existing JSON files and exit with status 1.

emitAlways('process started');

try {
    // 1) Calendar → events.json (CalDAV export is plain ICS)
    $ics = httpGet(calendarExportUrl($config['calendar_url']), $config['nc_user'], $config['nc_app_password']);
    $events = parseIcsEvents($ics);
    writeJsonAtomic($dataDir . '/events.json', ['events' => $events]);
    emitLine('Wrote events.json (' . count($events) . ' events)');

    // 2) Address book → venues.json (CardDAV REPORT returns vCards)
    $vcards = fetchAddressbookVcards($config['addressbook_url'], $config['nc_user'], $config['nc_app_password']);
    $venues = [];
    foreach ($vcards as $vcard) {
        $venue = parseVcardVenue($vcard);
        if ($venue !== null) {
            $venues[] = $venue;
        }
    }
    writeJsonAtomic($dataDir . '/venues.json', ['venues' => $venues]);
    emitLine('Wrote venues.json (' . count($venues) . ' venues)');

    // 3) Optional JSON-LD (same cron; toggle write_schema in config)
    if (!empty($config['write_schema'])) {
        require_once __DIR__ . '/schema.php';
        $schemaOptions = [
            'filter' => (string) ($config['schema_filter'] ?? 'upcoming'),
            'eventType' => (string) ($config['schema_event_type'] ?? 'Event'),
            'eventImageBase' => (string) ($config['schema_event_image_base'] ?? ''),
        ];
        $schemaCount = SeefeedWriteEventsSchemaFile($dataDir, $schemaOptions);
        emitLine('Wrote events.schema.json (' . $schemaCount . ' events)');
    }
    
} catch (Throwable $e) {
    $detail = 'Fetch failed, existing JSON kept: ' . $e->getMessage();
    failExit($FETCH_DEBUG ? $detail : 'process failed', 1, 500);
}

emitAlways('process stopped');
exit(0);

/** Always print (web body or CLI STDERR). */
function emitAlways(string $message): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . "\n");
        return;
    }
    echo $message . "\n";
}

/** Print a status line only when debug is on (CLI is always debug). */
function emitLine(string $message): void
{
    global $FETCH_DEBUG;
    if (!$FETCH_DEBUG) {
        return;
    }
    emitAlways($message);
}

/** Abort with message; set HTTP status when not running as CLI. */
function failExit(string $message, int $code = 1, int $httpStatus = 500): never
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($httpStatus);
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $message . "\n";
        echo "process stopped\n";
        exit($code);
    }
    fwrite(STDERR, $message . "\n");
    exit($code);
}

// --- HTTP / DAV -------------------------------------------------------------
// Authenticated requests to Nextcloud. Calendar uses GET ?export (ICS).
// Venues use CardDAV REPORT addressbook-query (vCard payloads in XML).

function calendarExportUrl(string $url): string
{
    $url = rtrim($url, '/');
    if (str_contains($url, '?')) {
        return $url;
    }
    return $url . '?export';
}

function httpGet(string $url, string $user, string $password): string
{
    return httpRequest('GET', $url, $user, $password);
}

function httpRequest(
    string $method,
    string $url,
    string $user,
    string $password,
    ?string $body = null,
    array $headers = []
): string {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP curl extension required');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }

    $reqHeaders = array_merge([
        'User-Agent: Seefeed-fetch/1.0',
        'Accept: */*',
    ], $headers);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $user . ':' . $password,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => $reqHeaders,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('HTTP request failed: ' . $error);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('HTTP ' . $status . ' for ' . $method . ' ' . $url);
    }

    return $response;
}

/**
 * CardDAV addressbook-query: return raw vCard texts from the collection.
 *
 * @return list<string>
 */
function fetchAddressbookVcards(string $addressbookUrl, string $user, string $password): array
{
    $url = rtrim($addressbookUrl, '/') . '/';
    $body = <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<C:addressbook-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">
  <D:prop>
    <D:getetag/>
    <C:address-data/>
  </D:prop>
</C:addressbook-query>
XML;

    $xml = httpRequest('REPORT', $url, $user, $password, $body, [
        'Content-Type: application/xml; charset=utf-8',
        'Depth: 1',
    ]);

    return extractAddressData($xml);
}

/**
 * @return list<string>
 */
function extractAddressData(string $multistatusXml): array
{
    $cards = [];
    // Nextcloud uses xmlns prefix "card:"; other servers may use "C:" or "carddav:"
    if (!preg_match_all(
        '#<(?:[A-Za-z_][\w.-]*:)?address-data\b[^>]*>(.*?)</(?:[A-Za-z_][\w.-]*:)?address-data>#is',
        $multistatusXml,
        $matches
    )) {
        return $cards;
    }

    foreach ($matches[1] as $raw) {
        $text = html_entity_decode(trim($raw), ENT_QUOTES | ENT_XML1, 'UTF-8');
        if ($text !== '') {
            $cards[] = $text;
        }
    }

    return $cards;
}

// --- ICS --------------------------------------------------------------------
// Map VEVENT fields to the frontend JSON shape. All-day DTEND is exclusive in
// ICS; JSON stores the last exhibition day. ATTACH is kept as the full URI.
// Events are sorted by start descending (newest first).

/**
 * @return list<array<string, mixed>>
 */
function parseIcsEvents(string $ics): array
{
    $ics = unfoldIcs($ics);
    $events = [];

    if (!preg_match_all('/BEGIN:VEVENT\r?\n(.*?)\r?\nEND:VEVENT/s', $ics, $blocks)) {
        return $events;
    }

    foreach ($blocks[1] as $block) {
        $props = parseIcsProperties($block);
        $startRaw = $props['DTSTART'] ?? '';
        $endRaw = $props['DTEND'] ?? '';
        if ($startRaw === '') {
            continue;
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
            foreach (explode(',', $props['CATEGORIES']) as $cat) {
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

        $events[] = $event;
    }

    // Newest first (ISO start strings compare correctly for date and datetime)
    usort($events, static fn(array $a, array $b): int => ($b['start'] ?? '') <=> ($a['start'] ?? ''));

    return $events;
}

function unfoldIcs(string $ics): string
{
    return preg_replace("/\r?\n[ \t]/", '', $ics) ?? $ics;
}

/**
 * @return array<string, string>
 */
function parseIcsProperties(string $block): array
{
    $props = [];
    foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
        if ($line === '' || !str_contains($line, ':')) {
            continue;
        }
        [$namePart, $value] = explode(':', $line, 2);
        $params = '';
        $name = $namePart;
        if (str_contains($namePart, ';')) {
            [$name, $params] = explode(';', $namePart, 2);
        }
        $name = strtoupper($name);
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

// --- vCard ------------------------------------------------------------------
// Map organisation contacts (FN, ORG, ADR, URL) to venues.json entries.
// Lookup key on the website is Event.location === Venue.fn.

/**
 * @return array<string, string>|null
 */
function parseVcardVenue(string $vcard): ?array
{
    $vcard = unfoldIcs($vcard);
    $props = [];

    foreach (preg_split('/\r?\n/', $vcard) ?: [] as $line) {
        if ($line === '' || !str_contains($line, ':')) {
            continue;
        }
        [$namePart, $value] = explode(':', $line, 2);
        $name = strtoupper(explode(';', $namePart, 2)[0]);
        // Keep first FN/ORG/URL; ADR may appear once for venues
        if (!isset($props[$name]) || $name === 'ADR') {
            $props[$name] = $value;
        }
    }

    $fn = unescapeIcsText($props['FN'] ?? '');
    if ($fn === '') {
        return null;
    }

    $adr = $props['ADR'] ?? ';;;;;;';
    // po;ext;street;locality;region;postal;country
    $parts = explode(';', $adr);
    while (count($parts) < 7) {
        $parts[] = '';
    }

    $venue = [
        'fn' => $fn,
        'org' => unescapeIcsText($props['ORG'] ?? ''),
        'url' => unescapeIcsText($props['URL'] ?? ''),
        'street' => unescapeIcsText($parts[2]),
        'postalCode' => unescapeIcsText($parts[5]),
        'locality' => unescapeIcsText($parts[3]),
        'country' => unescapeIcsText($parts[6]),
    ];

    return $venue;
}

// --- JSON write -------------------------------------------------------------
// Write via temp file + rename so a crashed run does not leave half-written JSON.

function writeJsonAtomic(string $path, array $data): void
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
