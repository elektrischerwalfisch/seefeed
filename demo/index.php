<?php
/**
 * Seefeed demo – list page (mounts + shared boot).
 *
 * @file        demo/index.php
 * @project     Seefeed
 * @author      elektrischerwalfisch
 * @see         demo/event-detail.php
 * @see         demo/include-seefeed.php
 */
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seefeed</title>
    <link rel="stylesheet" href="../core/css/seefeed.css">
    <link rel="stylesheet" href="../core/css/seefeed.theme.css">
    <link rel="stylesheet" href="./style.css">

    <?php
    // JSON-LD: versioned demo fixture (fetch writes data/events.schema.json for hosts).
    $schemaFile = __DIR__ . '/sample-data/events.schema.json';
    if (is_readable($schemaFile)) {
        echo '<script type="application/ld+json">' . file_get_contents($schemaFile) . '</script>';
    } else {
        echo '<!-- JSON-LD: demo/sample-data/events.schema.json missing -->';
    }
    ?>
    
</head>
<body>

    <div id="wrapper">

        <h1>Seefeed</h1>

        <div class="panel">
            <h2>First 3 Events</h2>
            <!-- List mount [data-events]:
                 data-filter:   upcoming | past | all | latest  (default: all)
                 data-template: short name → #event-{name}     (default: full)
                                demo includes: full | teaser | detail -->
            <section data-events data-filter="latest" data-template="full"></section>
        </div>

        <div class="panel">
            <h2>All Events</h2>
            <section data-events data-filter="all" data-template="teaser"></section>
        </div>

        <!-- Shared boot: templates, window.Seefeed config, seefeed.js -->
        <?php require __DIR__ . '/include-seefeed.php'; ?>

    </div>

</body>
</html>
