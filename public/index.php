<?php

/**
 * ADF Platform — titik masuk web (document root di VPS: /srv/adf/app/public).
 * Hanya folder public/ yang bisa dibuka dari internet; kode lain (core, tenants, tools) berada di luar jangkauan web.
 * Sementara ini: halaman status staging yang menampilkan versi kode yang sedang terpasang.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$head = @file_get_contents($root . '/.git/HEAD') ?: '';
$commit = '';
if (preg_match('#^ref: (.+)$#m', $head, $m)) {
    $commit = trim((string)@file_get_contents($root . '/.git/' . trim($m[1])));
}
$branch = isset($m[1]) ? basename(trim($m[1])) : 'detached';
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>ADF Platform — Staging</title>
    <style>
        :root { --bg: #f6f3ee; --ink: #1f1b16; --mu: #6b6257; --gold: #a8884f; }
        @media (prefers-color-scheme: dark) { :root { --bg: #14110d; --ink: #f1ece4; --mu: #a69c8f; } }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--ink); font: 16px/1.6 system-ui, 'Segoe UI', sans-serif; padding: 16px; }
        main { max-width: 520px; text-align: center; }
        h1 { font-weight: 300; letter-spacing: .08em; text-transform: uppercase; margin: 0 0 6px; }
        p { color: var(--mu); margin: 4px 0; }
        code { color: var(--gold); }
    </style>
</head>

<body>
    <main>
        <h1>ADF Platform</h1>
        <p>VPS staging — rumah baru ADF System</p>
        <p>Branch <code><?= htmlspecialchars($branch) ?></code> · commit <code><?= htmlspecialchars(substr($commit, 0, 8) ?: '-') ?></code></p>
        <p>PHP <?= htmlspecialchars(PHP_VERSION) ?> · <?= date('d M Y H:i') ?> WIB</p>
    </main>
</body>

</html>
