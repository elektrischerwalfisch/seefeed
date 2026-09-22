<?php
/**
 * Seefeed fetch config example – copy to config.php and fill in real values.
 * config.php is gitignored — never commit credentials.
 *
 * Keys:
 *   calendar_url              CalDAV URL (…/calendars/USER/slug/)
 *   addressbook_url           CardDAV URL of Venues (…/addressbooks/users/USER/slug/)
 *   fetch_token               Secret for URL cron; empty disables web runs.
 *                             Prefer URL-safe chars: A–Z, a–z, 0–9, hyphen, underscore
 *                             (avoid & = ? # + % space).
 *   debug                     true = verbose status on URL runs; CLI always verbose.
 *   write_schema              true = after successful fetch, write data/events.schema.json
 *   schema_filter             "upcoming" (default) | "all"
 *   schema_event_type         Schema.org @type (default "Event"; e.g. "ExhibitionEvent")
 *   schema_event_image_base   Optional image URL prefix for JSON-LD (ATTACH basenames).
 *                             Separate from frontend window.Seefeed.eventImageBase.
 *   rrule_horizon_past_months   Months before now when expanding RRULE (default 3).
 *   rrule_horizon_future_months Months after now when expanding RRULE (default 6).
 *
 * @file        fetch/config.example.php
 * @project     Seefeed
 * @author      Seefeed
 * @version     0.1.0
 * @since       2026-09
 * @see         fetch/fetch.php
 * @see         fetch/schema.php
 * @see         fetch/ics-parser.php
 */
declare(strict_types=1);

return [
    'nc_user' => 'YOUR_NEXTCLOUD_USERNAME',
    'nc_app_password' => 'YOUR_APP_PASSWORD',
    'calendar_url' => 'https://cloud.example/remote.php/dav/calendars/USER/events/',
    'addressbook_url' => 'https://cloud.example/remote.php/dav/addressbooks/users/USER/venues/',
    'fetch_token' => 'CHANGE_ME_LONG_RANDOM_STRING',
    'debug' => false,
    'write_schema' => false,
    'schema_filter' => 'upcoming',
    'schema_event_type' => 'Event',
    'schema_event_image_base' => '',
    'rrule_horizon_past_months' => 3,
    'rrule_horizon_future_months' => 6,
];
