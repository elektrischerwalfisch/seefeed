<?php
/**
 * Schaufeed plain adapter – demo page with mounts, templates, and window.Schaufeed.
 *
 * Uses versioned demo-data/; omit eventsUrl/venuesUrl to use core defaults (data/).
 *
 * @file        adapters/plain/index.php
 * @project     Schaufeed
 * @author      Schaufeed
 * @version     0.1.0
 * @since       2026-09
 * @see         core/js/schaufeed.js
 */
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schaufeed</title>
    <link rel="stylesheet" href="../../core/css/schaufeed.css">
    <link rel="stylesheet" href="./style.css">
</head>
<body>

    <div id="wrapper">

        <h1>Schaufeed</h1>

        <div class="panel">
            <h2>All Events</h2>
            <section data-events data-filter="all" data-template="full"></section>
        </div>

        <div class="panel">
            <h2>First 3 Events</h2>
            <section data-events data-filter="latest" data-template="teaser"></section>
        </div>

        <?php readfile(__DIR__ . "/../../core/templates/event-teaser.html"); ?>
        <?php readfile(__DIR__ . "/../../core/templates/event-full.html"); ?>

        <script>
            window.Schaufeed = {
                eventsUrl: "../../demo-data/events.json",
                venuesUrl: "../../demo-data/venues.json"
                // Optional overrides (omit = core defaults):
                // dateLocale: "de-DE"
                // dateDisplay: { timeZone: "UTC", day: "numeric", month: "short", year: "numeric" }
                // timeDisplay: { hour: "2-digit", minute: "2-digit" }
                // timeSuffix: " Uhr"          // empty string = no suffix
                // eventImageBase: ""          // empty = use ATTACH URI as-is; omit = <events-dir>/img/
                // latestCount: 3
                // upcomingCount: 3
            };
        </script>
        <script src="../../core/js/schaufeed.js"></script>

    </div>

</body>
</html>
