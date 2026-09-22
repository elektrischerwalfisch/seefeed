#!/usr/bin/env php
<?php
/**
 * Parse a local ICS fixture via fetch/ics-parser.php (no CalDAV, no config.php).
 *
 * Usage: php fetch/ics-parser-fixture-test.php fetch/fixtures/single-event.ics
 *        php fetch/ics-parser-fixture-test.php --internal fetch/fixtures/single-event.ics
 *
 * --internal keeps `_ics` recurrence metadata (for debugging).
 * Optional helper: safe to delete later together with fetch/fixtures/ without
 * changing fetch.php beyond its require of ics-parser.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/ics-parser.php';

$args = array_slice($argv, 1);
$keepInternal = false;
$path = '';

foreach ($args as $arg) {
    if ($arg === '--internal') {
        $keepInternal = true;
        continue;
    }
    if ($path === '') {
        $path = $arg;
    }
}

if ($path === '' || !is_readable($path)) {
    fwrite(STDERR, "Usage: php fetch/ics-parser-fixture-test.php [--internal] <file.ics>\n");
    exit(1);
}

$ics = (string) file_get_contents($path);
$events = $keepInternal ? parseIcsEventsInternal($ics) : parseIcsEvents($ics);

echo json_encode(
    ['events' => $events],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . "\n";
