<?php

/**
 * Halaman bayar ADF (publik, tanpa login): /bayar.php?t=<token>
 *   QRIS dinamis Mayar dengan nominal + kode unik, cek status otomatis (polling), animasi lunas.
 *   &status=1 → JSON {status} (cek ke Mayar maks. 1×/10 detik per link)
 *   &alt=1    → halaman Mayar biasa (VA / e-wallet) untuk tagihan yang sama
 *   &renew=1  → QR baru bila yang lama kedaluwarsa
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$link = Pay::byToken((string)($_GET['t'] ?? ''));
$self = '/bayar.php?t=' . rawurlencode((string)($_GET['t'] ?? ''));

$fail = function (string $title, string $msg): never {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title>'
        . '<body style="font-family:Inter,system-ui,sans-serif;background:#f4f6fa;color:#0f172a;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px">'
        . '<div style="text-align:center;max-width:380px"><b style="font-size:17px">' . e($title) . '</b><p style="color:#64748b">' . e($msg) . '</p></div>';
    exit;
};
if (!$link || $link['gateway'] !== 'mayar_qr') {
    $fail('Link pembayaran tidak ditemukan', 'Periksa kembali link dari ADF System.');
}

// Status (dipanggil halaman tiap 5 detik)
if (isset($_GET['status'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if ($link['status'] === 'pending' && (!$link['checked_at'] || strtotime((string)$link['checked_at']) < time() - 10)) {
        try {
            Pay::check($link);
        } catch (Throwable) {
            // Mayar tidak bisa dihubungi sesaat — coba lagi di panggilan berikutnya
        }
        $link = Pay::byToken((string)$link['token']);
    }
    echo json_encode(['status' => $link['status']]);
    exit;
}
// Metode lain → invoice Mayar biasa
if (isset($_GET['alt']) && $link['status'] === 'pending') {
    try {
        header('Location: ' . Pay::hostedFor($link)['link']);
        exit;
    } catch (Throwable $e) {
        $fail('Metode lain belum bisa dibuka', $e->getMessage());
    }
}
// QR baru
if (isset($_GET['renew']) && $link['status'] !== 'paid') {
    try {
        $new = Pay::renew($link);
        redirect('/bayar.php?t=' . $new['token']);
    } catch (Throwable $e) {
        $fail('QR baru belum bisa dibuat', $e->getMessage());
    }
}

// Kembali setelah lunas: alamat dari sistem bisnis (&back=…, hanya http/https), atau halaman invoice untuk invoice
$back = (string)($_GET['back'] ?? '');
if (!preg_match('#^https?://[^\s"<>]{3,300}$#', $back)) {
    $back = '';
}
$invRow = $link['ref_type'] === 'invoice' ? Invoice::find((int)$link['ref']) : null;
if ($back === '' && $invRow) {
    $back = Invoice::publicUrl($invRow);
}
// Cetak: invoice (sudah berstatus lunas) untuk tagihan invoice; kuitansi untuk tagihan bulanan
$printUrl = $invRow ? Invoice::publicUrl($invRow) : $self . '&receipt=1';

// Kuitansi pembayaran (siap cetak) untuk tagihan bulanan
if (isset($_GET['receipt']) && $link['status'] === 'paid') {
    $sx = Invoice::settings();
    $bz = $link['business_id'] ? Biz::business((int)$link['business_id']) : null;
    $rpx = fn ($n) => 'Rp ' . number_format((float)$n, 0, ',', '.');
    ?>
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Kuitansi <?= e((string)$link['ref']) ?> · <?= e((string)$sx['company_name']) ?></title>
<style>
    body { font-family: Inter, 'Segoe UI', system-ui, sans-serif; color: #0f172a; background: #eef1f6; margin: 0; padding: 24px 12px; }
    .doc { max-width: 560px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 28px; box-shadow: 0 12px 30px -16px rgba(0, 0, 0, .3); }
    h1 { font-size: 20px; letter-spacing: .14em; margin: 0; } .mu { color: #64748b; font-size: 12px; }
    .row { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px solid #eef1f6; font-size: 13px; }
    .tot { font-size: 22px; font-weight: 800; } .stamp { display: inline-block; border: 2px solid #10b981; color: #10b981; font-weight: 800; letter-spacing: .12em; padding: 3px 12px; border-radius: 8px; transform: rotate(-4deg); }
    .bar { text-align: center; margin: 16px 0 0; } .bar button { border: 0; background: #ff6a1a; color: #fff; font-weight: 700; padding: 10px 18px; border-radius: 10px; cursor: pointer; }
    @media print { body { background: #fff; padding: 0; } .doc { box-shadow: none; } .bar { display: none; } }
</style></head>
<body><div class="doc">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
        <div><b style="font-size:16px"><?= e((string)$sx['company_name']) ?></b><div class="mu"><?= e(implode(' · ', array_filter([(string)$sx['email'], (string)$sx['whatsapp']]))) ?></div></div>
        <div style="text-align:right"><h1>KUITANSI</h1><div class="mu"><?= e((string)$link['gateway_id']) ?></div></div>
    </div>
    <div style="margin:22px 0 10px"><span class="stamp">LUNAS</span></div>
    <div class="row"><span class="mu">Diterima dari</span><b><?= e($bz['name'] ?? '-') ?></b></div>
    <div class="row"><span class="mu">Untuk pembayaran</span><span><?= e((string)$link['description']) ?></span></div>
    <div class="row"><span class="mu">Tanggal bayar</span><span><?= e(date('d M Y H:i', strtotime((string)$link['paid_at']))) ?> WIB</span></div>
    <div class="row"><span class="mu">Metode</span><span>QRIS (Mayar)</span></div>
    <?php if ((float)$link['base_amount'] && (float)$link['base_amount'] !== (float)$link['amount']): ?><div class="row"><span class="mu">Tagihan + kode unik</span><span><?= e($rpx($link['base_amount'])) ?> + <?= e($rpx((float)$link['amount'] - (float)$link['base_amount'])) ?></span></div><?php endif; ?>
    <div class="row" style="border:0;padding-top:14px"><span class="mu">Jumlah</span><span class="tot"><?= e($rpx($link['amount'])) ?></span></div>
    <p class="mu" style="margin:18px 0 0">Kuitansi ini dibuat otomatis dan sah tanpa tanda tangan. Terima kasih.</p>
</div><div class="bar"><button onclick="window.print()">Cetak / Simpan PDF</button></div></body></html>
<?php
    exit;
}

$expired = $link['status'] === 'expired' || ($link['status'] === 'pending' && strtotime((string)$link['expires_at']) < time());
$paid = $link['status'] === 'paid';
$biz = $link['business_id'] ? Biz::business((int)$link['business_id']) : null;
$payer = $biz['name'] ?? '';
if ($link['ref_type'] === 'invoice' && ($inv = Invoice::find((int)$link['ref']))) {
    $payer = (string)($inv['customer_company'] ?: $inv['customer_name']);
}
$code = (int)round((float)$link['amount'] - (float)($link['base_amount'] ?? $link['amount']));
$rp = fn ($n) => 'Rp ' . number_format((float)$n, 0, ',', '.');
$company = (string)(Invoice::settings()['company_name'] ?: 'ADF System');
$state = $paid ? 'paid' : ($expired ? 'expired' : 'pending');
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Bayar <?= e($rp($link['amount'])) ?> · <?= e($company) ?></title>
    <style>
        :root { --ink: #0f172a; --mu: #64748b; --line: #e6eaf1; --acc: #ff6a1a; --ok: #10b981; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; font-family: Inter, 'Segoe UI', system-ui, sans-serif; color: var(--ink); background: radial-gradient(1200px 500px at 50% -10%, #ffe7d9 0%, #f4f6fa 55%); display: flex; justify-content: center; padding: 22px 14px 40px; }
        .wrap { width: 100%; max-width: 400px; }
        .brand { display: flex; align-items: center; gap: 10px; margin: 0 0 14px; }
        .brand .mk { width: 34px; height: 34px; border-radius: 10px; background: #0b0e14; display: grid; place-items: center; }
        .brand .mk img { width: 22px; height: 22px; object-fit: contain; }
        .brand b { font-size: 14px; letter-spacing: -.01em; }
        .brand b em { font-style: normal; color: var(--acc); }
        .brand small { display: block; color: var(--mu); font-size: 11px; }
        .card { background: #fff; border-radius: 20px; box-shadow: 0 18px 50px -20px rgba(15, 23, 42, .25); overflow: hidden; }
        .top { padding: 18px 20px 14px; border-bottom: 1px dashed var(--line); }
        .top .to { font-size: 11px; color: var(--mu); text-transform: uppercase; letter-spacing: .08em; }
        .top .who { font-weight: 700; font-size: 15px; margin: 2px 0 6px; }
        .top .ds { font-size: 12px; color: var(--mu); }
        .amt { padding: 14px 20px 4px; text-align: center; }
        .amt .lb { font-size: 11px; color: var(--mu); }
        .amt .vl { font-size: 30px; font-weight: 800; letter-spacing: -.02em; margin: 2px 0; font-variant-numeric: tabular-nums; }
        .amt .uq { font-size: 11px; color: var(--mu); }
        .amt .uq b { color: var(--acc); }
        .qr { margin: 14px auto 6px; width: 236px; height: 236px; padding: 12px; border-radius: 18px; border: 1px solid var(--line); display: grid; place-items: center; position: relative; background: #fff; }
        .qr img { width: 100%; height: 100%; object-fit: contain; image-rendering: pixelated; }
        .qr .tag { position: absolute; top: -10px; left: 50%; transform: translateX(-50%); background: #0b0e14; color: #fff; font-size: 10px; font-weight: 800; letter-spacing: .14em; padding: 3px 10px; border-radius: 99px; }
        .timer { text-align: center; font-size: 11.5px; color: var(--mu); margin: 6px 0 12px; }
        .timer b { color: var(--ink); font-variant-numeric: tabular-nums; }
        .wait { display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 12px; color: var(--mu); margin: 0 0 14px; }
        .wait i { width: 8px; height: 8px; border-radius: 50%; background: var(--acc); animation: pulse 1.2s ease-in-out infinite; }
        @keyframes pulse { 0%, 100% { opacity: .25; transform: scale(.8); } 50% { opacity: 1; transform: scale(1.15); } }
        .steps { margin: 0; padding: 12px 20px 16px 38px; font-size: 12px; color: #334155; line-height: 1.75; background: #fafbfd; border-top: 1px solid var(--line); }
        .acts { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; padding: 14px 20px 18px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; height: 42px; border-radius: 12px; font-weight: 700; font-size: 12.5px; text-decoration: none; border: 1px solid var(--line); color: var(--ink); background: #fff; cursor: pointer; }
        .btn.pri { background: var(--acc); border-color: var(--acc); color: #fff; }
        .foot { text-align: center; font-size: 11px; color: var(--mu); margin-top: 14px; }
        /* lunas */
        .done { padding: 34px 20px 26px; text-align: center; }
        .ck { width: 84px; height: 84px; margin: 0 auto 14px; border-radius: 50%; background: rgba(16, 185, 129, .12); display: grid; place-items: center; animation: pop .45s cubic-bezier(.2, 1.4, .4, 1); }
        .ck svg { width: 46px; height: 46px; stroke: var(--ok); stroke-width: 3.2; fill: none; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 40; stroke-dashoffset: 40; animation: draw .5s .25s ease forwards; }
        @keyframes pop { from { transform: scale(.4); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        @keyframes draw { to { stroke-dashoffset: 0; } }
        .done h1 { font-size: 19px; margin: 0 0 4px; }
        .done p { margin: 0; color: var(--mu); font-size: 12.5px; }
        .hide { display: none; }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="brand"><span class="mk"><img src="/logo.png" alt=""></span><span><b>ADF<em>system</em></b><small><?= e($company) ?> · Pembayaran aman</small></span></div>
        <div class="card">
            <div class="top">
                <div class="to">Tagihan untuk</div>
                <div class="who"><?= e($payer ?: 'Pelanggan') ?></div>
                <div class="ds"><?= e((string)$link['description']) ?></div>
            </div>

            <div id="vPending" class="<?= $state === 'pending' ? '' : 'hide' ?>">
                <div class="amt">
                    <div class="lb">Total bayar</div>
                    <div class="vl"><?= e($rp($link['amount'])) ?></div>
                    <?php if ($code > 0): ?><div class="uq">termasuk kode unik <b><?= e($rp($code)) ?></b> — bayar <b>tepat</b> sejumlah ini agar terdeteksi otomatis</div><?php endif; ?>
                </div>
                <div class="qr"><span class="tag">QRIS</span>
                    <?php if ($link['qr_image']): ?><img src="<?= e((string)$link['qr_image']) ?>" alt="QRIS <?= e($rp($link['amount'])) ?>"><?php else: ?><span style="font-size:12px;color:#64748b;text-align:center">QR belum tersedia.<br>Pakai "Metode lain".</span><?php endif; ?>
                </div>
                <div class="timer">Berlaku <b id="cd">–</b></div>
                <div class="wait"><i></i> Menunggu pembayaran… halaman ini berubah otomatis</div>
                <ol class="steps">
                    <li>Buka aplikasi m-banking atau e-wallet (BCA, BRI, Mandiri, GoPay, OVO, DANA, ShopeePay, …).</li>
                    <li>Pilih <b>Scan / QRIS</b>, arahkan ke kode di atas — atau unduh QR lalu pilih dari galeri.</li>
                    <li>Pastikan nominal <b><?= e($rp($link['amount'])) ?></b>, lalu bayar.</li>
                </ol>
                <div class="acts">
                    <?php if ($link['qr_image']): ?><a class="btn" href="<?= e((string)$link['qr_image']) ?>" download="QRIS-<?= (int)$link['amount'] ?>.png" target="_blank" rel="noopener">Unduh QR</a><?php endif; ?>
                    <a class="btn<?= $link['qr_image'] ? '' : ' pri' ?>" href="<?= e($self) ?>&alt=1" <?= $link['qr_image'] ? '' : 'style="grid-column:1/-1"' ?>>Metode lain (VA / e-wallet)</a>
                </div>
            </div>

            <div id="vPaid" class="done <?= $state === 'paid' ? '' : 'hide' ?>">
                <div class="ck"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg></div>
                <h1>Pembayaran diterima</h1>
                <p><?= e($rp($link['amount'])) ?> · <?= e((string)$link['description']) ?></p>
                <p style="margin-top:10px">Terima kasih! Tagihan otomatis tercatat lunas.</p>
                <div class="acts" style="padding:18px 0 0">
                    <a class="btn" id="btnPrint" href="<?= e($printUrl) ?>" target="_blank" rel="noopener"><?= $invRow ? 'Cetak invoice' : 'Cetak kuitansi' ?></a>
                    <?php if ($back !== ''): ?><a class="btn pri" href="<?= e($back) ?>">Kembali</a><?php endif; ?>
                </div>
                <?php if ($back !== ''): ?><p id="autoBack" style="margin-top:12px;font-size:11.5px">Kembali otomatis dalam <b id="abN">8</b> detik…</p><?php endif; ?>
            </div>

            <div id="vExpired" class="done <?= $state === 'expired' ? '' : 'hide' ?>">
                <h1 style="font-size:17px">QR sudah kedaluwarsa</h1>
                <p>Buat QR baru untuk tagihan ini.</p>
                <div style="margin-top:16px"><a class="btn pri" style="width:100%" href="<?= e($self) ?>&renew=1">Buat QR baru</a></div>
            </div>
        </div>
        <div class="foot">Pembayaran diproses oleh Mayar · <?= e($company) ?></div>
    </div>
    <script>
        (function () {
            var state = <?= json_encode($state) ?>, exp = <?= (int)strtotime((string)$link['expires_at']) * 1000 ?>;
            var back = <?= json_encode($back) ?>, abTimer = null;
            var autoBack = function () {
                if (!back || abTimer) return;
                var n = 8, el = document.getElementById('abN');
                abTimer = setInterval(function () {
                    n--;
                    if (el) el.textContent = n;
                    if (n <= 0) { clearInterval(abTimer); location.href = back; }
                }, 1000);
            };
            var pb = document.getElementById('btnPrint');
            if (pb) pb.addEventListener('click', function () {
                if (abTimer) clearInterval(abTimer);
                var ab = document.getElementById('autoBack');
                if (ab) ab.textContent = 'Kembali otomatis dibatalkan — tekan Kembali bila sudah selesai.';
            });
            var show = function (s) {
                ['Pending', 'Paid', 'Expired'].forEach(function (k) { document.getElementById('v' + k).classList.toggle('hide', k.toLowerCase() !== s); });
                state = s;
                if (s === 'paid') autoBack();
            };
            if (state === 'paid') autoBack();
            // hitung mundur masa berlaku QR
            var cd = document.getElementById('cd');
            setInterval(function () {
                var left = Math.max(0, Math.floor((exp - Date.now()) / 1000));
                var h = Math.floor(left / 3600), m = Math.floor(left % 3600 / 60), s = left % 60;
                cd.textContent = (h ? h + ' jam ' : '') + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
                if (!left && state === 'pending') show('expired');
            }, 1000);
            // cek status tiap 5 detik selama menunggu
            var poll = function () {
                if (state !== 'pending') return;
                fetch(location.pathname + location.search + '&status=1', { cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) { if (d.status === 'paid') show('paid'); else if (d.status === 'expired') show('expired'); })
                    .catch(function () {})
                    .finally(function () { setTimeout(poll, 5000); });
            };
            setTimeout(poll, 4000);
        })();
    </script>
</body>

</html>
