<?php

/**
 * ADF Platform — titik masuk web (document root di VPS: /srv/adf/app/public).
 * Hanya folder public/ yang bisa dibuka dari internet; core/, tenants/, cli/ dan rahasia berada di luar jangkauan web.
 * Sementara ini: halaman status staging (versi kode, jumlah bisnis, koneksi database). Tidak menampilkan rahasia apa pun.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

$head = @file_get_contents(ADF_ROOT . '/.git/HEAD') ?: '';
$commit = '';
$branch = 'detached';
if (preg_match('#^ref: (.+)$#m', $head, $m)) {
    $branch = basename(trim($m[1]));
    $commit = trim((string)@file_get_contents(ADF_ROOT . '/.git/' . trim($m[1])));
}

$tenants = [];
try {
    $tenants = Tenant::all();
} catch (Throwable $e) {
    error_log('status: ' . $e->getMessage());
}

// Koneksi database memakai rahasia dari folder secrets (tidak pernah dari git)
$db = 'belum diatur';
if (Secrets::get('DB_PASS') !== null) {
    try {
        $pdo = new PDO('mysql:host=' . Secrets::get('DB_HOST', 'localhost') . ';charset=utf8mb4', Secrets::require('DB_USER'), Secrets::require('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
        $db = 'tersambung · MariaDB ' . preg_replace('/-.*/', '', (string)$pdo->query('SELECT VERSION()')->fetchColumn());
    } catch (Throwable $e) {
        $db = 'gagal tersambung';
        error_log('status db: ' . $e->getMessage());
    }
}
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>ADF Platform — Staging</title>
    <style>
        :root { --bg: #f6f3ee; --ink: #1f1b16; --mu: #6b6257; --gold: #a8884f; --ok: #2d8a4e; --bad: #c0392b; }
        @media (prefers-color-scheme: dark) { :root { --bg: #14110d; --ink: #f1ece4; --mu: #a69c8f; --ok: #6fcf8f; --bad: #ef8a7f; } }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--ink); font: 16px/1.6 system-ui, 'Segoe UI', sans-serif; padding: 16px; }
        main { max-width: 520px; width: 100%; text-align: center; }
        h1 { font-weight: 300; letter-spacing: .08em; text-transform: uppercase; margin: 0 0 6px; }
        p { color: var(--mu); margin: 4px 0; }
        code { color: var(--gold); }
        dl { display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; text-align: left; margin: 22px auto 0; padding: 16px 18px; border: 1px solid rgba(168, 136, 79, .35); max-width: 420px; }
        dt { color: var(--mu); }
        dd { margin: 0; }
        .ok { color: var(--ok); }
        .bad { color: var(--bad); }
    </style>
</head>

<body>
    <main>
        <h1>ADF Platform</h1>
        <p>VPS staging — rumah baru ADF System</p>
        <p>Branch <code><?= htmlspecialchars($branch) ?></code> · commit <code><?= htmlspecialchars(substr($commit, 0, 8) ?: '-') ?></code></p>
        <dl>
            <dt>PHP</dt>
            <dd><?= htmlspecialchars(PHP_VERSION) ?></dd>
            <dt>Waktu</dt>
            <dd><?= date('d M Y H:i') ?> WIB</dd>
            <dt>Bisnis</dt>
            <dd><?= count($tenants) ?> terdaftar</dd>
            <dt>Database</dt>
            <dd class="<?= str_starts_with($db, 'tersambung') ? 'ok' : 'bad' ?>"><?= htmlspecialchars($db) ?></dd>
        </dl>
    </main>
</body>

</html>
