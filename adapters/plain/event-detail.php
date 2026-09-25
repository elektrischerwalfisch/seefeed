<?php
/**
 * Seefeed plain adapter – event detail demo page.
 *
 * Open with ?event=<id> (e.g. demo-evening-talk@seefeed.local).
 *
 * @file        adapters/plain/event-detail.php
 * @project     Seefeed
 * @author      elektrischerwalfisch
 * @see         adapters/plain/index.php
 * @see         adapters/plain/include-seefeed.php
 */
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seefeed – Event</title>
    <link rel="stylesheet" href="../../core/css/seefeed.css">
    <link rel="stylesheet" href="../../core/css/seefeed.theme.css">
    <link rel="stylesheet" href="./style.css">
    <!-- JSON-LD list embed lives on index.php only; detail pages may add a single Event later -->
</head>
<body class="page-event-detail">

    <div id="wrapper">

        <p class="page-back"><a href="./index.php">« Back to List</a></p>

        <div class="panel panel-detail">
            <section data-event-detail data-template="detail"></section>
        </div>

        <?php require __DIR__ . '/include-seefeed.php'; ?>

    </div>

</body>
</html>
