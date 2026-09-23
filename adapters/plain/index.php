<?php
/**
 * Seefeed plain adapter – demo page with mounts, templates, and window.Seefeed.
 *
 * Uses versioned demo-data/; omit eventsUrl/venuesUrl to use core defaults (data/).
 *
 * @file        adapters/plain/index.php
 * @project     Seefeed
 * @author      Seefeed
 * @version     0.1.0
 * @since       2026-09
 * @see         core/js/seefeed.js
 */
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seefeed</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../core/css/seefeed.css">
    <link rel="stylesheet" href="../../core/css/seefeed.theme.css">
    <link rel="stylesheet" href="./style.css">
</head>
<body>

    <div id="wrapper">

        <h1>Seefeed</h1>

        <div class="panel">
            <h2>First 3 Events</h2>
            <section data-events data-filter="latest" data-template="full"></section>
        </div>

        <div class="panel">
            <h2>All Events</h2>
            <section data-events data-filter="all" data-template="teaser"></section>
        </div>

        <?php readfile(__DIR__ . "/../../core/templates/event-teaser.html"); ?>
        <?php readfile(__DIR__ . "/../../core/templates/event-full.html"); ?>

        <script>
            window.Seefeed = {
                eventsUrl: "../../demo-data/events.json",
                venuesUrl: "../../demo-data/venues.json",
                dateDisplay: {
                    timeZone: "UTC",
                    day: "2-digit",
                    month: "2-digit",
                    year: "numeric",
                    weekday: "long",
                },
                timeSuffix: "h",
                // Optional overrides (omit = core defaults):
                // dateLocale: "de-DE"
                // dateDisplay:
                //   timeZone: "UTC" | "Europe/Berlin" | … (IANA); omit = browser local
                //     (applied for date-only events; timed events use browser local TZ)
                //   day: "numeric" | "2-digit"
                //   month: "numeric" | "2-digit" | "long" | "short" | "narrow"
                //   year: "numeric" | "2-digit"
                //   weekday: "narrow" | "short" | "long" — .event-weekday only (not .event-date)
                // timeDisplay:
                //   hour / minute / second: "numeric" | "2-digit" (second optional)
                //   hour12: true | false (optional; omit = locale default)
                // timeSuffix: " Uhr"          // empty string = no suffix
                // eventImageBase: ""          // empty = use ATTACH URI as-is; omit = <events-dir>/img/
                // latestCount: 3
                // upcomingCount: 3
            };
        </script>
        <script src="../../core/js/seefeed.js"></script>

    </div>

</body>
</html>
