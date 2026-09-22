#!/usr/bin/env php
<?php
/**
 * Parse a local ICS fixture via fetch/ics-parser.php (no CalDAV, no config.php).
 *
 * Usage:
 *   php fetch/ics-parser-fixture-test.php fetch/fixtures/single-event.ics
 *   php fetch/ics-parser-fixture-test.php --now=2026-09-22 fetch/fixtures/monthly-byday-3we.ics
 *   php fetch/ics-parser-fixture-test.php --internal --now=2026-09-22 fetch/fixtures/series-meta-only.ics
 *   php fetch/ics-parser-fixture-test.php --raw fetch/fixtures/series-meta-only.ics
 *
 * --internal  keep `_ics` after expansion
 * --raw       no RRULE expansion (parsed VEVENTs only)
 * --now=ISO   fixed "now" for horizon (reproducible fixtures)
 * --past=N    override past horizon months
 * --future=N  override future horizon months
 */
declare(strict_types=1);

require_once __DIR__ . '/ics-parser.php';

$args = array_slice($argv, 1);
$keepInternal = false;
$raw = false;
$path = '';
$options = [];

foreach ($args as $arg) {
    if ($arg === '--internal') {
        $keepInternal = true;
        continue;
    }
    if ($arg === '--raw') {
        $raw = true;
        continue;
    }
    if (str_starts_with($arg, '--now=')) {
        $options['now'] = substr($arg, 6);
        continue;
    }
    if (str_starts_with($arg, '--past=')) {
        $options['past_months'] = (int) substr($arg, 7);
        continue;
    }
    if (str_starts_with($arg, '--future=')) {
        $options['future_months'] = (int) substr($arg, 9);
        continue;
    }
    if ($path === '') {
        $path = $arg;
    }
}

if ($path === '' || !is_readable($path)) {
    fwrite(STDERR, "Usage: php fetch/ics-parser-fixture-test.php [--raw|--internal] [--now=ISO] [--past=N] [--future=N] <file.ics>\n");
    exit(1);
}

$ics = (string) file_get_contents($path);

if ($raw) {
    $events = parseIcsEventsInternal($ics);
} elseif ($keepInternal) {
    $events = expandIcsRecurringEvents(parseIcsEventsInternal($ics), $options);
} else {
    $events = parseIcsEvents($ics, $options);
}

echo json_encode(
    ['events' => $events],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . "\n";
