<?php

/**
 * Halaman bayar tagihan langganan ADF System (desain sendiri, menggantikan halaman Pakasir).
 *
 *  GET  pay.php?period=X            → pilih metode / tampilkan QRIS atau nomor VA
 *  POST pay.php?period=X (method)   → buat transaksi QRIS / VA lewat Pakasir v2
 *  GET  pay.php?period=X&check=1    → JSON status (dipanggil otomatis tiap 4 detik)
 *
 * Saat lunas: popup "Pembayaran Berhasil" lalu kembali ke halaman Tagihan Langganan.
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/subscription_client.php';

$auth = new Auth();
$auth->requireLogin();
if (!in_array($_SESSION['role'] ?? '', ['developer', 'owner', 'admin', 'manager'], true)) {
    http_response_code(403);
    exit('Halaman ini khusus owner / admin.');
}

$pdo = adfsub_pdo();
adfsub_ensure_schema($pdo);
$period = (string) ($_GET['period'] ?? '');
$stmt = $pdo->prepare("SELECT * FROM adf_subscription_invoices WHERE period = ? LIMIT 1");
$stmt->execute([$period]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);

// ── Cek status (polling) ────────────────────────────────────────
if (isset($_GET['check'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if (!$inv) {
        echo json_encode(['status' => 'missing']);
        exit;
    }
    // Ke Pakasir paling sering 1x / 3 detik per sesi (batas API 2 request/detik).
    if ($inv['status'] === 'unpaid' && ($_SESSION['adfsub_poll_at'] ?? 0) < time() - 3) {
        $_SESSION['adfsub_poll_at'] = time();
        if (adfsub_reconcile($pdo, $inv)) {
            $inv['status'] = 'paid';
        }
    }
    echo json_encode(['status' => $inv['status']]);
    exit;
}

if (!$inv) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['adfsub_pay_csrf'])) {
    $_SESSION['adfsub_pay_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['adfsub_pay_csrf'];
$methods = adfsub_pay_methods();
$amount = (int) round((float) $inv['total_amount']);
$error = '';

// ── Pilih metode ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $inv['status'] === 'unpaid') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Sesi kedaluwarsa, silakan pilih lagi.';
    } elseif (($_POST['method'] ?? '') === 'payment_link') {
        $link = adfsub_create_payment($pdo, $period);
        if ($link) {
            header('Location: ' . $link);
            exit;
        }
        $error = 'Gagal membuka halaman pembayaran lain. Coba lagi.';
    } elseif (adfsub_create_direct_payment($pdo, $period, (string) ($_POST['method'] ?? ''))) {
        header('Location: pay.php?period=' . urlencode($period));
        exit;
    } else {
        $error = 'Gagal membuat pembayaran. Coba metode lain atau ulangi beberapa saat lagi.';
    }
}
if (isset($_GET['change'])) {
    $showPicker = true;
}

$stmt->execute([$period]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
$hasCode = $inv['status'] === 'unpaid' && !empty($inv['pay_code']) && isset($methods[$inv['pay_method'] ?? ''])
    && !empty($inv['pay_expires']) && strtotime($inv['pay_expires']) > time();
$showPicker = $inv['status'] === 'unpaid' && (!$hasCode || !empty($showPicker));
$isQris = ($inv['pay_method'] ?? '') === 'qris';
$rp = static fn($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
$bizName = defined('BUSINESS_NAME') ? BUSINESS_NAME : 'ADF System';
$backUrl = 'index.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Pembayaran Tagihan · ADF System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; --bg: #f1f5f9; --brand: #4f46e5; --ok: #16a34a; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, system-ui, sans-serif; background: var(--bg); color: var(--ink); min-height: 100vh; }
        .wrap { max-width: 440px; margin: 0 auto; padding: 18px 16px 40px; }
        .top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .top a { color: var(--muted); text-decoration: none; font-size: 13px; }
        .brand { display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 14px; }
        .brand i { width: 26px; height: 26px; border-radius: 8px; background: linear-gradient(135deg, #4f46e5, #7c3aed); display: grid; place-items: center; color: #fff; font-style: normal; font-size: 12px; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 18px; box-shadow: 0 10px 30px rgba(15, 23, 42, .05); margin-bottom: 12px; }
        .bill small { color: var(--muted); font-size: 12px; }
        .bill .amt { font-size: 28px; font-weight: 800; letter-spacing: -.5px; margin: 2px 0; }
        .bill .desc { font-size: 13px; color: var(--muted); }
        .row { display: flex; justify-content: space-between; font-size: 13px; padding: 6px 0; }
        .row span:first-child { color: var(--muted); }
        .row.total { border-top: 1px dashed var(--line); margin-top: 6px; padding-top: 10px; font-weight: 700; font-size: 14px; }
        h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin: 0 0 10px; }
        .m { display: flex; align-items: center; gap: 12px; width: 100%; padding: 12px 14px; border: 1px solid var(--line); background: #fff; border-radius: 12px; margin-bottom: 8px; cursor: pointer; font: inherit; text-align: left; }
        .m:hover { border-color: var(--brand); background: #f8f7ff; }
        .m:disabled { opacity: .45; cursor: not-allowed; }
        .m .ic { width: 38px; height: 38px; border-radius: 10px; display: grid; place-items: center; font-weight: 800; font-size: 11px; color: #fff; flex-shrink: 0; }
        .m b { display: block; font-size: 14px; }
        .m small { color: var(--muted); font-size: 12px; }
        .m .arr { margin-left: auto; color: #94a3b8; }
        .qrbox { display: grid; place-items: center; padding: 14px; border: 1px solid var(--line); border-radius: 14px; background: #fff; }
        #qr img, #qr canvas { width: 230px !important; height: 230px !important; }
        .va { font-size: 24px; font-weight: 800; letter-spacing: 2px; text-align: center; padding: 14px; border: 1px dashed #c7d2fe; background: #eef2ff; border-radius: 12px; font-variant-numeric: tabular-nums; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 10px 14px; border-radius: 10px; border: 1px solid var(--line); background: #fff; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; color: var(--ink); text-decoration: none; }
        .btn.primary { background: var(--brand); border-color: var(--brand); color: #fff; }
        .btn.block { width: 100%; }
        .wait { display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--muted); margin-top: 12px; }
        .dot { width: 9px; height: 9px; border-radius: 50%; background: #f59e0b; animation: pulse 1.2s infinite; flex-shrink: 0; }
        @keyframes pulse { 50% { opacity: .3; transform: scale(.8); } }
        .steps { font-size: 12.5px; color: var(--muted); padding-left: 18px; margin: 12px 0 0; line-height: 1.7; }
        .err { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; border-radius: 10px; padding: 10px 12px; font-size: 13px; margin-bottom: 12px; }
        .paid-banner { text-align: center; }
        .paid-banner .big { font-size: 46px; }
        .muted { color: var(--muted); font-size: 12px; text-align: center; margin-top: 12px; }
        /* Popup berhasil */
        .ok-ov { position: fixed; inset: 0; background: rgba(15, 23, 42, .55); display: none; align-items: center; justify-content: center; padding: 16px; z-index: 50; }
        .ok-ov.show { display: flex; animation: fade .2s ease; }
        .ok-box { background: #fff; border-radius: 20px; padding: 28px 22px; max-width: 340px; width: 100%; text-align: center; animation: pop .35s cubic-bezier(.2, 1.4, .4, 1); }
        .ok-check { width: 74px; height: 74px; border-radius: 50%; background: #dcfce7; display: grid; place-items: center; margin: 0 auto 12px; }
        .ok-check svg { width: 40px; height: 40px; stroke: var(--ok); stroke-width: 3; fill: none; stroke-dasharray: 50; stroke-dashoffset: 50; animation: draw .5s .2s forwards; }
        .ok-box h3 { margin: 0 0 6px; font-size: 19px; }
        .ok-box p { margin: 0 0 16px; color: var(--muted); font-size: 13px; line-height: 1.5; }
        @keyframes fade { from { opacity: 0; } }
        @keyframes pop { from { transform: scale(.85); opacity: 0; } }
        @keyframes draw { to { stroke-dashoffset: 0; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div class="brand"><i>ADF</i>Pembayaran Tagihan</div>
        <a href="<?php echo $backUrl; ?>">Kembali</a>
    </div>

    <?php if ($error): ?><div class="err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card bill">
        <small><?php echo htmlspecialchars($bizName); ?> · <?php echo htmlspecialchars($inv['description'] ?: $inv['period']); ?></small>
        <div class="amt"><?php echo $rp($hasCode && !$showPicker ? ($inv['pay_total'] ?: $amount) : $amount); ?></div>
        <div class="desc">
            <?php if ($inv['status'] === 'paid'): ?>
                Lunas <?php echo $inv['paid_at'] ? date('d M Y H:i', strtotime($inv['paid_at'])) : ''; ?>
            <?php elseif (!empty($inv['due_date'])): ?>
                Jatuh tempo <?php echo date('d M Y', strtotime($inv['due_date'])); ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($inv['status'] === 'paid'): ?>
        <div class="card paid-banner">
            <div class="big">✅</div>
            <b>Tagihan ini sudah lunas</b>
            <p class="muted">Terima kasih. Bukti pembayaran sudah dikirim ke email.</p>
            <a class="btn primary block" href="<?php echo $backUrl; ?>">Kembali ke Tagihan</a>
        </div>

    <?php elseif ($showPicker): ?>
        <div class="card">
            <h2>Pilih metode pembayaran</h2>
            <form method="post" action="pay.php?period=<?php echo urlencode($period); ?>">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <?php
                $colors = ['qris' => '#111827', 'bri_va' => '#00529c', 'bni_va' => '#f15a23', 'permata_va' => '#1b8a3e', 'cimb_niaga_va' => '#7a0019', 'maybank_va' => '#e8b200', 'bnc_va' => '#f5a800', 'artha_graha_va' => '#0f4c81', 'sampoerna_va' => '#c8102e'];
                foreach ($methods as $code => [$label, $min]):
                    $short = ['qris' => 'QRIS', 'bri_va' => 'BRI', 'bni_va' => 'BNI', 'permata_va' => 'PMT', 'cimb_niaga_va' => 'CIMB', 'maybank_va' => 'MBK', 'bnc_va' => 'BNC', 'artha_graha_va' => 'AG', 'sampoerna_va' => 'SBS'][$code] ?? 'VA';
                    $ok = $amount >= $min;
                ?>
                    <button type="submit" name="method" value="<?php echo $code; ?>" class="m" <?php echo $ok ? '' : 'disabled'; ?>>
                        <span class="ic" style="background:<?php echo $colors[$code] ?? '#334155'; ?>"><?php echo $short; ?></span>
                        <span>
                            <b><?php echo $label; ?></b>
                            <small><?php echo $code === 'qris' ? 'Scan pakai m-banking / GoPay / OVO / DANA / ShopeePay' : ($ok ? 'Transfer ke nomor virtual account' : 'Minimal Rp ' . number_format($min, 0, ',', '.')); ?></small>
                        </span>
                        <span class="arr">›</span>
                    </button>
                <?php endforeach; ?>
                <button type="submit" name="method" value="payment_link" class="m" style="border-style:dashed;">
                    <span class="ic" style="background:#94a3b8;">…</span>
                    <span><b>Metode lain</b><small>Buka halaman pembayaran Pakasir</small></span>
                    <span class="arr">›</span>
                </button>
            </form>
        </div>

    <?php else: ?>
        <div class="card">
            <h2><?php echo htmlspecialchars($methods[$inv['pay_method']][0]); ?></h2>
            <?php if ($isQris): ?>
                <div class="qrbox"><div id="qr" data-code="<?php echo htmlspecialchars($inv['pay_code']); ?>"></div></div>
                <ol class="steps">
                    <li>Buka aplikasi m-banking / e-wallet, pilih <b>Scan QRIS</b>.</li>
                    <li>Scan kode di atas, pastikan nominal <b><?php echo $rp($inv['pay_total'] ?: $amount); ?></b>.</li>
                    <li>Selesaikan pembayaran — halaman ini otomatis terkonfirmasi.</li>
                </ol>
            <?php else: ?>
                <div class="va" id="vaNum"><?php echo htmlspecialchars(trim(chunk_split((string) $inv['pay_code'], 4, ' '))); ?></div>
                <button type="button" class="btn block" style="margin-top:8px;" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($inv['pay_code']); ?>');this.textContent='✓ Nomor VA tersalin';">Salin nomor VA</button>
                <ol class="steps">
                    <li>Buka m-banking / ATM, pilih <b>Transfer → Virtual Account</b>.</li>
                    <li>Masukkan nomor di atas, bayar tepat <b><?php echo $rp($inv['pay_total'] ?: $amount); ?></b>.</li>
                    <li>Selesaikan pembayaran — halaman ini otomatis terkonfirmasi.</li>
                </ol>
            <?php endif; ?>

            <div class="row" style="margin-top:12px;"><span>Tagihan</span><span><?php echo $rp($amount); ?></span></div>
            <div class="row"><span>Biaya layanan</span><span><?php echo $rp($inv['pay_fee'] ?? 0); ?></span></div>
            <div class="row total"><span>Total bayar</span><span><?php echo $rp($inv['pay_total'] ?: $amount); ?></span></div>

            <div class="wait"><span class="dot"></span><span>Menunggu pembayaran… berlaku <b id="countdown" data-exp="<?php echo strtotime($inv['pay_expires']); ?>">-</b></span></div>
        </div>
        <a class="btn block" href="pay.php?period=<?php echo urlencode($period); ?>&change=1">Ganti metode pembayaran</a>
        <p class="muted">Jangan tutup halaman ini sampai muncul konfirmasi. Kalau sudah membayar lalu menutup halaman, status tetap diperbarui otomatis.</p>
    <?php endif; ?>
</div>

<!-- Popup pembayaran berhasil -->
<div class="ok-ov" id="okPopup">
    <div class="ok-box">
        <div class="ok-check"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.2 4.2L19 7"/></svg></div>
        <h3>Pembayaran Berhasil</h3>
        <p><?php echo htmlspecialchars($inv['description'] ?: $inv['period']); ?> sebesar <b><?php echo $rp($amount); ?></b> sudah lunas. Bukti pembayaran dikirim ke email.</p>
        <a class="btn primary block" href="<?php echo $backUrl; ?>">Selesai</a>
    </div>
</div>

<?php if ($inv['status'] === 'unpaid' && !$showPicker && $isQris): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        (function () {
            var el = document.getElementById('qr');
            new QRCode(el, { text: el.dataset.code, width: 460, height: 460, correctLevel: QRCode.CorrectLevel.M });
        })();
    </script>
<?php endif; ?>
<?php if ($inv['status'] === 'unpaid' && !$showPicker): ?>
    <script>
        (function () {
            // Hitung mundur masa berlaku kode
            var cd = document.getElementById('countdown');
            var exp = parseInt(cd.dataset.exp, 10) * 1000;
            function tick() {
                var s = Math.max(0, Math.floor((exp - Date.now()) / 1000));
                var h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), d = s % 60;
                cd.textContent = s === 0 ? 'kedaluwarsa — ganti metode' : (h ? h + ' jam ' : '') + m + ' mnt ' + (d < 10 ? '0' : '') + d + ' dtk';
            }
            tick(); setInterval(tick, 1000);

            // Cek status tiap 4 detik; saat lunas → popup berhasil, lalu kembali otomatis
            var url = 'pay.php?period=<?php echo rawurlencode($period); ?>&check=1';
            var done = false;
            function poll() {
                if (done) return;
                fetch(url, { cache: 'no-store', credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (j.status === 'paid') {
                            done = true;
                            document.getElementById('okPopup').classList.add('show');
                            setTimeout(function () { location.href = '<?php echo $backUrl; ?>?paid=1'; }, 6000);
                        }
                    })
                    .catch(function () {})
                    .finally(function () { if (!done) setTimeout(poll, 4000); });
            }
            setTimeout(poll, 3000);
        })();
    </script>
<?php endif; ?>
</body>
</html>
