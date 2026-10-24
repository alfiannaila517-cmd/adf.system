<?php

/**
 * Payment Gateway: Mayar (aktif) — API key & mode disimpan terenkripsi (SecureSettings), tes koneksi, daftarkan webhook.
 * Semua pembayaran online (tagihan langganan sistem klien, invoice, checkout website) dibuat lewat Console (Pay).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireAdmin();
$hookUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'console.adfsystem.store') . '/api/mayar-webhook.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        flash('Sesi form kedaluwarsa, silakan coba lagi.', 'err');
        redirect('/payment.php');
    }
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if ($action === 'save') {
            $key = trim((string)($_POST['api_key'] ?? ''));
            $mode = ($_POST['mode'] ?? '') === 'sandbox' ? 'sandbox' : 'production';
            $key = $key !== '' ? $key : (string)Secrets::get('MAYAR_API_KEY', '');
            // tes dulu dengan nilai baru (hanya untuk permintaan ini), baru simpan bila Mayar menerima
            Secrets::override('MAYAR_API_KEY', $key);
            Secrets::override('MAYAR_MODE', $mode);
            Mayar::ping();
            $changed = $key !== (string)(SecureSettings::all()['MAYAR_API_KEY'] ?? '');
            SecureSettings::save(['MAYAR_API_KEY' => $key, 'MAYAR_MODE' => $mode]);
            // Key / akun Mayar berganti (mis. subdomain toko diubah) → link lama tidak berlaku: tutup yang belum dibayar
            $closed = 0;
            if ($changed) {
                $closed = Auth::db()->exec("UPDATE payment_links SET status = 'expired' WHERE status = 'pending'");
            }
            Auth::audit('payment.mayar', $mode);
            flash(($closed ? $closed . ' link bayar lama ditutup. ' : '') . 'Mayar tersimpan dan terhubung (' . ($mode === 'sandbox' ? 'sandbox / uji coba' : 'production') . '). Sekarang klik "Daftarkan webhook".');
        } elseif ($action === 'webhook') {
            Mayar::registerWebhook($hookUrl);
            Auth::audit('payment.webhook', $hookUrl);
            flash('Webhook terdaftar di Mayar: ' . $hookUrl);
        } elseif ($action === 'test') {
            Mayar::ping();
            flash('Koneksi ke Mayar berhasil.');
        } elseif ($action === 'off') {
            SecureSettings::save(['MAYAR_API_KEY' => '', 'MAYAR_MODE' => '']);
            Auth::audit('payment.mayar.off', '');
            flash('Mayar dimatikan — tombol bayar online di sistem klien kembali ke cara lama.');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    redirect('/payment.php');
}

$key = (string)Secrets::get('MAYAR_API_KEY', '');
$recent = Auth::db()->query("SELECT * FROM payment_links ORDER BY id DESC LIMIT 10")->fetchAll();

View::start('Payment Gateway', 'payment', $me);
?>
<div class="pagehead"><div><h1>Payment Gateway</h1><p>Pembayaran online tagihan langganan sistem klien, invoice, dan checkout website — semuanya lewat Console.</p></div></div>
<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(320px,1fr));align-items:start">
    <form class="card" method="post" autocomplete="off" style="margin:0">
        <?= csrf_field() ?>
        <h2 style="display:flex;justify-content:space-between;gap:8px"><span>Mayar</span><?= Mayar::configured() ? '<span class="pill ok">aktif' . (Mayar::sandbox() ? ' · sandbox' : '') . '</span>' : '<span class="pill warn">belum diatur</span>' ?></h2>
        <div class="field"><label>API key <?= $key !== '' ? '<span class="pill ok">tersimpan ••••' . e(substr($key, -4)) . '</span>' : '' ?></label>
            <input type="password" name="api_key" placeholder="<?= $key !== '' ? 'kosongkan = tetap' : 'web.mayar.id → Integrasi → API Key (Read & Write)' ?>" autocomplete="new-password"></div>
        <div class="field"><label>Mode</label><select name="mode">
            <option value="production" <?= !Mayar::sandbox() ? 'selected' : '' ?>>Production (uang sungguhan)</option>
            <option value="sandbox" <?= Mayar::sandbox() ? 'selected' : '' ?>>Sandbox (uji coba, web.mayar.io)</option>
        </select></div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <button class="btn sm" name="action" value="save">Simpan & tes</button>
            <?php if (Mayar::configured()): ?>
                <button class="btn ghost sm" name="action" value="webhook">Daftarkan webhook</button>
                <button class="btn ghost sm" name="action" value="test">Tes koneksi</button>
                <button class="btn ghost sm" name="action" value="off" onclick="return confirm('Matikan pembayaran Mayar?')">Matikan</button>
            <?php endif; ?>
        </div>
    </form>
    <div class="card" style="margin:0">
        <h2>Cara kerja</h2>
        <ol class="muted" style="margin:0;padding-left:18px;font-size:12.5px;line-height:1.7">
            <li>Tempel API key Mayar → <b>Simpan & tes</b> → <b>Daftarkan webhook</b>.</li>
            <li>Klien menekan <b>Bayar</b> di menu Langganan sistemnya → halaman bayar Mayar (QRIS, VA, e-wallet).</li>
            <li>Setelah dibayar, Console mengecek ulang ke Mayar → tagihan <b>Lunas</b>, tercatat di Transaksi & Keuangan, dan ikut lunas di sistem klien.</li>
        </ol>
        <p class="muted" style="margin:10px 0 4px;font-size:11.5px">Alamat webhook (otomatis didaftarkan, atau isi manual di dashboard Mayar → Integrasi → Webhook):</p>
        <code style="display:block;padding:7px 9px;border-radius:8px;background:var(--bg2,#0f131b);word-break:break-all;font-size:11.5px"><?= e($hookUrl) ?></code>
        <p class="muted" style="margin:10px 0 0;font-size:11.5px">Pakasir sudah tidak dipakai. Pengaturan Pakasir lama untuk website ada di <a href="/site.php?tab=bayar">Konten Website → Pembayaran</a>.</p>
    </div>
</div>

<div class="card" style="padding:0;overflow-x:auto;margin-top:12px">
    <table class="stack">
        <thead><tr><th>Dibuat</th><th>Untuk</th><th style="text-align:right">Jumlah</th><th>Status</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($recent as $l): ?>
                <tr>
                    <td style="white-space:nowrap"><?= e(date('d M H:i', strtotime((string)$l['created_at']))) ?></td>
                    <td><?= e((string)$l['description']) ?></td>
                    <td style="text-align:right;white-space:nowrap"><?= Biz::rp($l['amount']) ?></td>
                    <td><span class="pill <?= $l['status'] === 'paid' ? 'ok' : ($l['status'] === 'pending' ? 'warn' : '') ?>"><?= ['paid' => 'lunas', 'pending' => 'menunggu', 'expired' => 'kedaluwarsa'][$l['status']] ?? e($l['status']) ?></span></td>
                    <td style="text-align:right"><a class="btn ghost sm" href="<?= e((string)$l['link']) ?>" target="_blank" rel="noopener">Link</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?><tr><td colspan="5" class="muted" style="text-align:center;padding:16px">Belum ada link pembayaran.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php View::end();
