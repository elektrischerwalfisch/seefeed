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
 * @author      elektrischerwalfisch
 * @since       2026-09
 * @see         fetch/config.example.php
 * @see         fetch/schema.php
 * @see         fetch/ics-parser.php
 */

require_once __DIR__ . '/ics-parser.php';

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
    $events = parseIcsEvents($ics, [
        'past_months' => (int) ($config['rrule_horizon_past_months'] ?? RRULE_HORIZON_PAST_MONTHS),
        'future_months' => (int) ($config['rrule_horizon_future_months'] ?? RRULE_HORIZON_FUTURE_MONTHS),
    ]);
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

// --- vCard ------------------------------------------------------------------
// Map organisation contacts (FN, ORG, ADR, URL) to venues.json entries.
// Lookup key on the website is Event.location === Venue.fn.
// Line helpers (unfoldIcs, splitIcsContentLine, unescapeIcsText) come from ics-parser.php.

/**
 * @return array<string, string>|null
 */
function parseVcardVenue(string $vcard): ?array
{
    $vcard = unfoldIcs($vcard);
    $props = [];

    foreach (preg_split('/\r?\n/', $vcard) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        $parts = splitIcsContentLine($line);
        if ($parts === null) {
            continue;
        }
        [$namePart, $value] = $parts;
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
