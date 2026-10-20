<?php

/**
 * ADF Console — pusat kendali platform (pengganti admin adfsystem.store + panel developer).
 * Document root di VPS: /srv/adf/app/apps/console/public (domain: console.adfsystem.store).
 * Sementara: halaman penanda; dibangun bertahap (minggu 1: login & pengguna).
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/core/bootstrap.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>ADF Console</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #14110d; color: #f1ece4; font: 16px/1.6 system-ui, 'Segoe UI', sans-serif; padding: 16px; }
        main { text-align: center; }
        h1 { font-weight: 300; letter-spacing: .1em; text-transform: uppercase; margin: 0 0 6px; }
        p { color: #a69c8f; margin: 4px 0; }
    </style>
</head>

<body>
    <main>
        <h1>ADF Console</h1>
        <p>Pusat kendali ADF Platform — sedang dibangun</p>
        <p><?= date('d M Y H:i') ?> WIB</p>
    </main>
</body>

</html>
