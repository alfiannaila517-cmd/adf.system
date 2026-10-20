<?php

/**
 * Portal monitor server (untuk developer/owner) — https://<SITE_HOST>/monitor/
 * Login: MONITOR_USER + MONITOR_PASS_HASH di secrets/portal.env (buat dengan: php cli/monitor.php --set-password).
 * Percobaan login gagal dibatasi: 5 kali per IP per 15 menit. Bisa dipasang di layar utama HP (PWA).
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/core/bootstrap.php';
require_once ADF_CORE . '/ServerHealth.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:");

session_name('adfmon');
session_set_cookie_params(['lifetime' => 0, 'path' => '/monitor/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
session_start();

const MAX_FAILS = 5;
const LOCK_SECONDS = 900;
$failFile = ADF_STORAGE . '/_platform/portal-fails.json';
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

/** @return array<string, array{n:int, t:int}> */
function fails(string $file): array
{
    $all = is_readable($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    return array_filter($all, fn ($r) => time() - (int)$r['t'] < LOCK_SECONDS);
}

$error = '';
if (($_GET['logout'] ?? '') === '1') {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_SESSION['monitor_user'])) {
    $all = fails($failFile);
    $mine = $all[$ip] ?? ['n' => 0, 't' => time()];
    $user = Secrets::get('MONITOR_USER');
    $hash = Secrets::get('MONITOR_PASS_HASH');
    if (!$user || !$hash) {
        $error = 'Login portal belum dibuat. Jalankan: php cli/monitor.php --set-password';
    } elseif ($mine['n'] >= MAX_FAILS) {
        $error = 'Terlalu banyak percobaan. Coba lagi dalam 15 menit.';
    } elseif (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Sesi kedaluwarsa, silakan coba lagi.';
    } elseif (hash_equals($user, (string)($_POST['u'] ?? '')) & password_verify((string)($_POST['p'] ?? ''), $hash)) {
        session_regenerate_id(true);
        $_SESSION['monitor_user'] = $user;
        unset($all[$ip]);
        @file_put_contents($failFile, json_encode($all), LOCK_EX);
        header('Location: ./');
        exit;
    } else {
        $all[$ip] = ['n' => $mine['n'] + 1, 't' => time()];
        @file_put_contents($failFile, json_encode($all), LOCK_EX);
        $left = MAX_FAILS - $all[$ip]['n'];
        $error = 'Username atau password salah' . ($left > 0 ? " (sisa {$left} percobaan)." : '. Akses dikunci 15 menit.');
        usleep(400000);
    }
}

$logged = !empty($_SESSION['monitor_user']);
if ($logged) {
    $f = ServerHealth::facts();
    $problems = ServerHealth::problems($f);
    $state = ServerHealth::lastState();
    $bill = ServerHealth::billing();
}
$h = fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$bar = function (int $pct, int $warn): string {
    $cls = $pct >= $warn ? 'bad' : ($pct >= $warn - 15 ? 'warn' : 'ok');
    return '<div class="bar"><span class="' . $cls . '" style="width:' . max(2, min(100, $pct)) . '%"></span></div>';
};
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#14110d">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="ADF Monitor">
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="icon.svg">
    <title>ADF Monitor</title>
    <style>
        :root { --bg: #14110d; --card: #1e1a15; --line: #332b22; --ink: #f1ece4; --mu: #a69c8f; --gold: #d6b16a; --ok: #5fc983; --warn: #f0b85a; --bad: #ef7b6e; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--ink); font: 15px/1.5 system-ui, -apple-system, 'Segoe UI', sans-serif; padding: max(16px, env(safe-area-inset-top)) 16px 32px; }
        .wrap { max-width: 560px; margin: 0 auto; }
        header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        h1 { font-size: 17px; font-weight: 600; letter-spacing: .06em; margin: 0; }
        h1 small { display: block; font-size: 12px; font-weight: 400; color: var(--mu); letter-spacing: 0; }
        a { color: var(--gold); text-decoration: none; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 14px 16px; margin-bottom: 12px; }
        .card h2 { font-size: 12px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: var(--mu); margin: 0 0 10px; }
        .status { display: flex; gap: 12px; align-items: center; font-size: 17px; font-weight: 600; }
        .status .dot { width: 14px; height: 14px; border-radius: 50%; background: var(--ok); box-shadow: 0 0 0 5px rgba(95, 201, 131, .15); flex: none; }
        .status.has-problem .dot { background: var(--bad); box-shadow: 0 0 0 5px rgba(239, 123, 110, .18); }
        ul.plain { list-style: none; margin: 10px 0 0; padding: 0; }
        ul.plain li { padding: 6px 0; border-top: 1px solid var(--line); color: var(--bad); }
        .row { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; margin: 8px 0 4px; }
        .row b { font-weight: 600; }
        .row span { color: var(--mu); font-size: 13px; }
        .bar { height: 8px; background: #2a241d; border-radius: 99px; overflow: hidden; }
        .bar span { display: block; height: 100%; border-radius: 99px; }
        .bar .ok { background: var(--ok); } .bar .warn { background: var(--warn); } .bar .bad { background: var(--bad); }
        .svc { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .svc div { display: flex; gap: 8px; align-items: center; font-size: 14px; }
        .svc i { width: 9px; height: 9px; border-radius: 50%; background: var(--ok); flex: none; }
        .svc i.off { background: var(--bad); }
        .kv { display: grid; grid-template-columns: auto 1fr; gap: 6px 14px; font-size: 14px; }
        .kv dt { color: var(--mu); } .kv dd { margin: 0; text-align: right; }
        .bill { font-size: 22px; font-weight: 600; } .bill small { display: block; font-size: 13px; font-weight: 400; color: var(--mu); }
        .bill.soon { color: var(--warn); } .bill.today { color: var(--bad); }
        .foot { text-align: center; color: var(--mu); font-size: 12px; margin-top: 16px; }
        form { display: grid; gap: 10px; }
        input { width: 100%; padding: 12px 14px; border-radius: 10px; border: 1px solid var(--line); background: #15120e; color: var(--ink); font: inherit; }
        button { padding: 12px; border: 0; border-radius: 10px; background: var(--gold); color: #1a150e; font: inherit; font-weight: 700; cursor: pointer; }
        .err { color: var(--bad); font-size: 14px; }
    </style>
</head>

<body>
    <div class="wrap">
        <?php if (!$logged): ?>
            <header>
                <h1>ADF MONITOR<small><?= $h(ServerHealth::serverName()) ?></small></h1>
            </header>
            <div class="card">
                <h2>Masuk</h2>
                <?php if ($error): ?><p class="err"><?= $h($error) ?></p><?php endif; ?>
                <form method="post" autocomplete="on">
                    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                    <input name="u" placeholder="Username" autocomplete="username" autocapitalize="none" required>
                    <input name="p" type="password" placeholder="Password" autocomplete="current-password" required>
                    <button type="submit">Masuk</button>
                </form>
            </div>
        <?php else: ?>
            <header>
                <h1>ADF MONITOR<small><?= $h(ServerHealth::serverName()) ?> · <?= $h($f['site_host']) ?></small></h1>
                <a href="?logout=1">Keluar</a>
            </header>

            <div class="card">
                <div class="status<?= $problems ? ' has-problem' : '' ?>"><span class="dot"></span><?= $problems ? count($problems) . ' perlu perhatian' : 'Semua normal' ?></div>
                <?php if ($problems): ?>
                    <ul class="plain"><?php foreach ($problems as $msg): ?><li><?= $h($msg) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>Sumber daya</h2>
                <div class="row"><b>Disk</b><span><?= $f['disk_pct'] ?>% · sisa <?= $f['disk_free_gb'] ?> / <?= $f['disk_total_gb'] ?> GB</span></div>
                <?= $bar($f['disk_pct'], ServerHealth::DISK_WARN) ?>
                <div class="row"><b>RAM</b><span><?= $f['mem_pct'] ?>% dari <?= $f['mem_total_gb'] ?> GB · swap <?= $f['swap_used_mb'] ?> MB</span></div>
                <?= $bar($f['mem_pct'], ServerHealth::MEM_WARN) ?>
                <?php $loadPct = (int)round($f['load'] / $f['cpus'] * 100); ?>
                <div class="row"><b>Beban CPU</b><span><?= number_format($f['load'], 2) ?> (<?= $f['cpus'] ?> core)</span></div>
                <?= $bar($loadPct, 150) ?>
            </div>

            <div class="card">
                <h2>Layanan</h2>
                <div class="svc">
                    <?php foreach ($f['services'] as $unit => $st): ?>
                        <div><i class="<?= $st === 'active' ? '' : 'off' ?>"></i><?= $h(ServerHealth::SERVICES[$unit]) ?></div>
                    <?php endforeach; ?>
                    <div><i class="<?= $f['web_ok'] ? '' : 'off' ?>"></i>Website</div>
                </div>
            </div>

            <div class="card">
                <h2>Keamanan & sistem</h2>
                <dl class="kv">
                    <dt>Sertifikat SSL</dt>
                    <dd><?= $f['ssl_days'] === null ? 'tidak terbaca' : 'sisa ' . $f['ssl_days'] . ' hari' ?></dd>
                    <dt>Update tersedia</dt>
                    <dd><?= $f['updates'] === null ? '-' : $f['updates'] . ($f['security_updates'] ? " ({$f['security_updates']} keamanan)" : '') ?></dd>
                    <dt>Perlu restart</dt>
                    <dd><?= $f['reboot'] ? 'ya' : 'tidak' ?></dd>
                    <dt>Server aktif</dt>
                    <dd><?= $f['uptime_days'] ?> hari</dd>
                    <dt>PHP</dt>
                    <dd><?= $h(PHP_VERSION) ?></dd>
                </dl>
            </div>

            <?php if ($bill): ?>
                <div class="card">
                    <h2>Tagihan VPS</h2>
                    <div class="bill <?= $bill['level'] ?>"><?= $bill['days'] === 0 ? 'Hari ini' : $bill['days'] . ' hari lagi' ?>
                        <small><?= $h($bill['due']->format('d M Y')) ?><?= $bill['price'] ? ' · Rp ' . number_format($bill['price'], 0, ',', '.') : '' ?><?= $bill['plan'] ? ' · ' . $h($bill['plan']) : '' ?> · <?= $bill['cycle'] === 'yearly' ? 'tahunan' : 'bulanan' ?></small>
                    </div>
                </div>
            <?php endif; ?>

            <p class="foot">
                Dibuka <?= date('d M Y H:i') ?> WIB
                · cek otomatis terakhir <?= !empty($state['checked']) ? date('H:i', (int)$state['checked']) : 'belum ada' ?>
                <br>Halaman diperbarui tiap 60 detik
            </p>
            <script>
                setInterval(function() { if (!document.hidden) location.reload(); }, 60000);
            </script>
        <?php endif; ?>
    </div>
</body>

</html>
