<?php
/**
 * Seefeed plain adapter – demo list page (mounts + shared boot).
 *
 * @file        adapters/plain/index.php
 * @project     Seefeed
 * @see         adapters/plain/event-detail.php
 * @see         adapters/plain/include-seefeed.php
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

    <?php
    // JSON-LD: versioned demo fixture (fetch writes data/events.schema.json for hosts).
    $schemaFile = __DIR__ . '/../../demo-data/events.schema.json';
    if (is_readable($schemaFile)) {
        echo '<script type="application/ld+json">' . file_get_contents($schemaFile) . '</script>';
    } else {
        echo '<!-- JSON-LD: demo-data/events.schema.json missing -->';
    }
    ?>
    
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

        <?php require __DIR__ . '/include-seefeed.php'; ?>

    </div>

</body>
</html>
