<?php

/**
 * Pindah alamat (mis. adfsystem.online → nasita.biz.id), diatur dari Developer Panel → Maintenance.
 * Status di file domain-move.json di folder utama (tidak masuk git):
 *   { "from": "adfsystem.online", "to": "nasita.biz.id", "mode": "info" | "direct" }
 *   info   = halaman pemberitahuan alamat baru + tips menyimpan, lalu otomatis pindah setelah beberapa detik
 *   direct = langsung dialihkan (302)
 * Hanya membuka halaman (GET dari browser) yang dialihkan. Webhook/API/cron/Developer Panel/login Google tetap
 * dilayani di alamat lama, supaya Fingerspot, Cloudbeds, Pakasir, dll. tidak perlu diubah.
 */

function adf_domain_move_file(): string
{
    return dirname(__DIR__) . '/domain-move.json';
}

/** Pengaturan pindah alamat yang aktif, atau null. */
function adf_domain_move_state(): ?array
{
    $f = adf_domain_move_file();
    if (!is_file($f)) {
        return null;
    }
    $s = json_decode((string)@file_get_contents($f), true);
    return is_array($s) && !empty($s['from']) && !empty($s['to']) && in_array($s['mode'] ?? '', ['info', 'direct'], true) ? $s : null;
}

function adf_domain_move_gate(): void
{
    if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }
    $s = adf_domain_move_state();
    $host = strtolower((string)preg_replace(['/^www\./', '/:\d+$/'], '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($s === null || $host !== strtolower((string)$s['from'])) {
        return;
    }
    // Hanya navigasi halaman oleh browser (bukan fetch/XHR, manifest, gambar)
    $dest = (string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '');
    $isPage = $dest !== '' ? $dest === 'document' : stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html') !== false;
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string)parse_url($uri, PHP_URL_PATH);
    if (!$isPage || preg_match('#/(api|developer|cron)/|google-callback\.php|webhook|manifest\.php|cron-#i', $path)) {
        return;
    }

    $target = 'https://' . $s['to'] . $uri;
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Cache-Control: no-store');
    if ($s['mode'] === 'direct') {
        header('Location: ' . $target, true, 302);
        exit;
    }

    $e = static fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $to = (string)$s['to'];
    $isStaff = stripos($path, 'staff-portal') !== false || stripos($path, 'absen') !== false;
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Alamat baru: <?= $e($to) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 20px; background: radial-gradient(ellipse 70% 55% at 50% 0%, rgba(255, 106, 26, .14), transparent 70%), #0b0e14; color: #e6e9ef; font: 15px/1.6 'Segoe UI', system-ui, -apple-system, Roboto, sans-serif; text-align: center; }
        .box { max-width: 460px; width: 100%; }
        small.k { font-size: 12px; color: #7d8696; text-transform: uppercase; letter-spacing: .1em; }
        .dom { font-size: 30px; font-weight: 700; color: #fff; margin: 4px 0 6px; word-break: break-all; }
        .dom span { color: #ff6a1a; }
        p { color: #a3abba; margin: 0 0 18px; }
        .btn { display: block; background: #ff6a1a; color: #fff; text-decoration: none; font-weight: 600; padding: 13px; border-radius: 12px; margin: 0 0 8px; }
        .cd { font-size: 12px; color: #7d8696; margin: 0 0 20px; }
        .tips { text-align: left; background: #121620; border: 1px solid #222836; border-radius: 12px; padding: 12px 16px; font-size: 13px; color: #c7cdd8; }
        .tips b { color: #fff; }
        .tips div + div { margin-top: 6px; }
        a.stay { color: #7d8696; font-size: 12px; }
    </style>
</head>

<body>
    <div class="box">
        <small class="k">Alamat sistem sudah pindah · New address</small>
        <div class="dom"><span>●</span> <?= $e($to) ?></div>
        <p>Mulai sekarang buka sistem lewat alamat baru ini. Login seperti biasa (username &amp; password sama).</p>
        <a class="btn" id="go" href="<?= $e($target) ?>">Buka <?= $e($to) ?> →</a>
        <div class="cd">Otomatis pindah dalam <b id="n">10</b> detik · <a class="stay" href="#" id="wait">tunggu, saya baca dulu</a></div>
        <div class="tips">
            <div><b>Simpan alamat baru di laptop:</b> setelah terbuka, tekan <b>Ctrl + D</b> (bookmark). Atau menu <b>⋮</b> → <b>Instal aplikasi</b> supaya muncul ikon di desktop.</div>
            <?php if ($isStaff): ?>
                <div><b>Di HP (Staff Portal):</b> hapus ikon lama dari layar utama, lalu buka alamat baru dan pilih <b>Tambahkan ke Layar Utama</b>.</div>
            <?php else: ?>
                <div><b>Di HP:</b> buka alamat baru lalu menu → <b>Tambahkan ke Layar Utama</b>.</div>
            <?php endif; ?>
            <div><b>Password tersimpan:</b> browser akan menawarkan simpan password lagi untuk alamat baru — pilih Simpan.</div>
        </div>
    </div>
    <script>
        (function () {
            var n = 10, el = document.getElementById('n'), t = setInterval(function () {
                el.textContent = --n;
                if (n <= 0) { clearInterval(t); location.replace(document.getElementById('go').href); }
            }, 1000);
            document.getElementById('wait').onclick = function (ev) { ev.preventDefault(); clearInterval(t); this.parentNode.textContent = 'Klik tombol di atas bila sudah siap.'; };
        })();
    </script>
</body>

</html>
<?php
    exit;
}
