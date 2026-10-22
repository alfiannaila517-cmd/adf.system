<?php

/**
 * Mode maintenance (mis. saat pemindahan data ke server baru).
 * Diaktifkan dari Developer Panel → Maintenance. Status di file maintenance.json di folder utama (bukan database,
 * supaya tidak ikut tertimpa saat database dipindah; tidak masuk git).
 *   { "on": true, "until": 1761150000, "message": "...", "website": true, "pass": "<acak>" }
 * Selama aktif: semua halaman sistem menampilkan pemberitahuan + hitung mundur (HTTP 503); permintaan API mendapat
 * JSON 503. Tetap bisa dibuka: Developer Panel, perintah terminal (cron/CLI), dan browser developer yang
 * mengaktifkannya (cookie adf_maint_pass).
 */

function adf_maintenance_file(): string
{
    return dirname(__DIR__) . '/maintenance.json';
}

/** Status maintenance aktif, atau null. */
function adf_maintenance_state(): ?array
{
    $f = adf_maintenance_file();
    if (!is_file($f)) {
        return null;
    }
    $s = json_decode((string)@file_get_contents($f), true);
    return is_array($s) && !empty($s['on']) ? $s : null;
}

/**
 * Hentikan permintaan bila maintenance aktif.
 * @param string $area 'system' (aplikasi) atau 'website' (website publik hotel; hanya bila dicentang)
 */
function adf_maintenance_gate(string $area = 'system'): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $s = adf_maintenance_state();
    if ($s === null || ($area === 'website' && empty($s['website']))) {
        return;
    }
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($area === 'system' && strpos($path, '/developer/') !== false) {
        return; // Developer Panel selalu terbuka (untuk mematikan maintenance)
    }
    if (!empty($s['pass']) && hash_equals((string)$s['pass'], (string)($_COOKIE['adf_maint_pass'] ?? ''))) {
        return; // browser developer yang mengaktifkan
    }

    $until = (int)($s['until'] ?? 0);
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code(503);
    header('Retry-After: ' . max(60, $until - time()));
    header('Cache-Control: no-store');

    $wantsJson = strpos($path, '/api/') !== false
        || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
        || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'maintenance' => true, 'until' => $until, 'message' => 'Sistem sedang maintenance (pemindahan data). Silakan coba lagi nanti.']);
        exit;
    }

    $msg = trim((string)($s['message'] ?? ''));
    $isWeb = $area === 'website';
    $title = $isWeb ? 'We\'ll be right back' : 'Sistem sedang dipindahkan';
    $lead = $isWeb
        ? 'Our website is being upgraded. Bookings will reopen shortly. · Website sedang ditingkatkan, pemesanan dibuka kembali sebentar lagi.'
        : 'Kami sedang memindahkan data ke server baru supaya sistem lebih cepat & aman. Mohon jangan input apa pun sampai selesai.';
    $e = static fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= $e($title) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 20px; background: radial-gradient(ellipse 70% 55% at 50% 0%, rgba(255, 106, 26, .14), transparent 70%), #0b0e14; color: #e6e9ef; font: 15px/1.6 'Segoe UI', system-ui, -apple-system, Roboto, sans-serif; text-align: center; }
        .box { max-width: 480px; }
        .ic { width: 64px; height: 64px; margin: 0 auto 18px; border-radius: 18px; display: grid; place-items: center; background: rgba(255, 106, 26, .12); color: #ff6a1a; font-size: 30px; animation: pulse 2s ease-in-out infinite; }
        @keyframes pulse { 50% { transform: scale(1.06); box-shadow: 0 0 0 10px rgba(255, 106, 26, .06); } }
        h1 { font-size: 22px; margin: 0 0 8px; }
        p { color: #a3abba; margin: 0 0 18px; }
        .msg { background: #121620; border: 1px solid #222836; border-radius: 12px; padding: 12px 16px; margin: 0 0 18px; color: #e6e9ef; }
        .cd { display: flex; gap: 10px; justify-content: center; margin: 0 0 8px; }
        .cd div { background: #121620; border: 1px solid #222836; border-radius: 12px; padding: 10px 0; width: 76px; }
        .cd b { display: block; font-size: 28px; font-variant-numeric: tabular-nums; color: #fff; }
        .cd small { font-size: 11px; color: #7d8696; text-transform: uppercase; letter-spacing: .08em; }
        .note { font-size: 12px; color: #7d8696; }
    </style>
</head>

<body>
    <div class="box">
        <div class="ic">⚙</div>
        <h1><?= $e($title) ?></h1>
        <p><?= $e($lead) ?></p>
        <?php if ($msg !== ''): ?><div class="msg"><?= nl2br($e($msg)) ?></div><?php endif; ?>
        <?php if ($until > 0): ?>
            <div class="cd" id="cd"><div><b id="h">00</b><small><?= $isWeb ? 'hours' : 'jam' ?></small></div><div><b id="m">00</b><small><?= $isWeb ? 'min' : 'menit' ?></small></div><div><b id="s">00</b><small><?= $isWeb ? 'sec' : 'detik' ?></small></div></div>
            <div class="note" id="note"><?= $isWeb ? 'Estimated time remaining' : 'Perkiraan selesai' ?> · <?= $e(date('H:i', $until)) ?> WIB</div>
        <?php endif; ?>
        <p class="note" style="margin-top:14px"><?= $isWeb ? 'This page refreshes automatically.' : 'Halaman ini terbuka sendiri begitu sistem siap — tidak perlu memuat ulang.' ?></p>
    </div>
    <script>
        (function () {
            var until = <?= (int)$until ?> * 1000, pad = function (n) { return (n < 10 ? '0' : '') + n; };
            function tick() {
                if (!until) return;
                var left = Math.max(0, Math.floor((until - Date.now()) / 1000));
                document.getElementById('h').textContent = pad(Math.floor(left / 3600));
                document.getElementById('m').textContent = pad(Math.floor(left % 3600 / 60));
                document.getElementById('s').textContent = pad(left % 60);
                if (left === 0) document.getElementById('note').textContent = '<?= $isWeb ? 'Almost done…' : 'Hampir selesai, sebentar lagi…' ?>';
            }
            tick(); setInterval(tick, 1000);
            // Cek tiap 30 detik: begitu maintenance dimatikan, halaman dimuat ulang otomatis
            setInterval(function () {
                fetch(location.href, { cache: 'no-store', credentials: 'same-origin' }).then(function (r) { if (r.status !== 503) location.reload(); }).catch(function () {});
            }, 30000);
        })();
    </script>
</body>

</html>
<?php
    exit;
}
