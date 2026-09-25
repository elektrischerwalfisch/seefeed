<?php
/**
 * Seefeed fetch config example – copy to config.php and fill in real values.
 * config.php is gitignored — never commit credentials.
 *
 * @file        fetch/config.example.php
 * @project     Seefeed
 * @author      elektrischerwalfisch
 * @see         fetch/fetch.php
 * @see         fetch/schema.php
 * @see         README.md (JSON-LD embed; ICS test fixtures)
 */
declare(strict_types=1);

return [
    // Nextcloud login (app password, not account password)
    'nc_user' => 'YOUR_NEXTCLOUD_USERNAME',
    'nc_app_password' => 'YOUR_APP_PASSWORD',

    // CalDAV calendar / CardDAV venues address book
    'calendar_url' => 'https://cloud.example/remote.php/dav/calendars/USER/events/',
    'addressbook_url' => 'https://cloud.example/remote.php/dav/addressbooks/users/USER/venues/',

    // URL cron secret; empty = disable web runs. Prefer A–Z a–z 0–9 - _ (avoid & = ? # + % space)
    'fetch_token' => 'CHANGE_ME_LONG_RANDOM_STRING',

    // true = verbose status on URL runs (CLI is always verbose)
    'debug' => false,

    // true = also write data/events.schema.json (file only; host embeds in <head>)
    'write_schema' => true,
    // "upcoming" | "all" — which events go into the schema file
    'schema_filter' => 'upcoming',
    // Schema.org @type, e.g. "Event" or "ExhibitionEvent"
    'schema_event_type' => 'Event',
    // Image URL prefix for ATTACH basenames (separate from window.Seefeed.eventImageBase)
    'schema_event_image_base' => '',

    // RRULE expansion window (months before / after "now"); does not drop single events
    'rrule_horizon_past_months' => 3,
    'rrule_horizon_future_months' => 6,
];
