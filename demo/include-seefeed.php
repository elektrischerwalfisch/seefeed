<?php
/**
 * Shared demo boot: templates, window.Seefeed config, seefeed.js.
 *
 * Include once after the mounts (before </body>):
 *   <?php require __DIR__ . '/include-seefeed.php'; ?>
 *
 * @file        demo/include-seefeed.php
 * @project     Seefeed
 * @author      elektrischerwalfisch
 * @see         demo/index.php
 * @see         demo/event-detail.php
 */
declare(strict_types=1);

// Core templates (demo/). Replace any path with your own
// HTML file; keep template ids: event-teaser, event-full, event-detail.
// Example (host with Seefeed under vendor/):
//   readfile(__DIR__ . '/vendor/seefeed/core/templates/event-teaser.html');
//   readfile(__DIR__ . '/templates/my-event-detail.html'); // custom detail only
readfile(__DIR__ . '/../core/templates/event-teaser.html');
readfile(__DIR__ . '/../core/templates/event-full.html');
readfile(__DIR__ . '/../core/templates/event-detail.html');

?>
<script>
    window.Seefeed = {
        // JSON URLs (demo uses versioned fixtures under sample-data/):
        //   Demo:   "./sample-data/events.json" / "./sample-data/venues.json"
        //   Hosts:  omit both keys → core defaults to runtime data/ after fetch
        //           or set explicitly, e.g. "/vendor/seefeed/data/events.json"
        eventsUrl: "./sample-data/events.json",
        venuesUrl: "./sample-data/venues.json",
        eventDetailUrl: "./event-detail.php", // omit or "" = no list → detail links
        dateDisplay: {
            // timeZone: "UTC" | "Europe/Berlin" | … (IANA); omit = browser local
            //   (date-only events; timed events use browser local TZ)
            timeZone: "UTC",
            // day: "numeric" | "2-digit"
            day: "2-digit",
            // month: "numeric" | "2-digit" | "long" | "short" | "narrow"
            month: "2-digit",
            // year: "numeric" | "2-digit"
            year: "numeric",
            // weekday: "narrow" | "short" | "long" — .event-weekday only
            weekday: "long",
        },
        // timeSuffix: " Uhr" | "h" | "" (empty = no suffix); omit = " Uhr"
        timeSuffix: "h",
        // Optional overrides (omit = core defaults):
        // dateLocale: "de-DE"
        // timeDisplay:
        //   hour / minute / second: "numeric" | "2-digit" (second optional)
        //   hour12: true | false (optional; omit = locale default)
        // eventImageBase: ""          // empty = use ATTACH URI as-is; omit = <events-dir>/img/
        // eventIdParam: "event"       // query key for detail page
        // latestCount: 3
        // upcomingCount: 3
    };
</script>
<!-- Core JS (path from demo/). Host example: vendor/seefeed/core/js/seefeed.js -->
<script src="../core/js/seefeed.js"></script>
