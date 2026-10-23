<?php

/** Pengaturan invoice: profil perusahaan di kop, rekening pembayaran, syarat, penanda tangan, nomor & default. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireAdmin();
$s = Invoice::settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        flash('Sesi form kedaluwarsa, silakan coba lagi.', 'err');
        redirect('/invoice-settings.php');
    }
    $new = $s;
    foreach (['company_name', 'tagline', 'address', 'email', 'whatsapp', 'website', 'payment_note', 'terms', 'signer_name', 'signer_title'] as $k) {
        $new[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $new['prefix'] = preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['prefix'] ?? '')) ?: 'INV';
    $new['default_due_days'] = max(0, min(90, (int)($_POST['default_due_days'] ?? 7)));
    $new['default_tax_percent'] = max(0.0, min(100.0, (float)str_replace(',', '.', (string)($_POST['default_tax_percent'] ?? 0))));
    $new['banks'] = [];
    foreach ((array)($_POST['bank'] ?? []) as $i => $bank) {
        $row = ['bank' => trim((string)$bank), 'number' => trim((string)($_POST['bank_number'][$i] ?? '')), 'holder' => trim((string)($_POST['bank_holder'][$i] ?? ''))];
        if ($row['bank'] !== '' || $row['number'] !== '') {
            $new['banks'][] = $row;
        }
    }
    Invoice::saveSettings($new);
    Auth::audit('invoice.settings', '');
    flash('Pengaturan invoice disimpan.');
    redirect('/invoice-settings.php');
}

$banks = $s['banks'];
while (count($banks) < 3) {
    $banks[] = ['bank' => '', 'number' => '', 'holder' => ''];
}
View::start('Pengaturan invoice', 'invoices', $me);
?>
<div class="crumb"><a href="/invoices.php">Invoice</a> ›</div>
<div class="pagehead"><div><h1>Pengaturan invoice</h1><p>Muncul di kop, bagian pembayaran, dan kaki setiap invoice.</p></div></div>
<form method="post" style="max-width:860px">
    <?= csrf_field() ?>
    <div class="card">
        <h2>Perusahaan</h2>
        <div class="row2">
            <div class="field"><label>Nama perusahaan / usaha</label><input name="company_name" value="<?= e($s['company_name']) ?>"></div>
            <div class="field"><label>Tagline</label><input name="tagline" value="<?= e($s['tagline']) ?>"></div>
            <div class="field"><label>Email</label><input name="email" value="<?= e($s['email']) ?>"></div>
            <div class="field"><label>WhatsApp</label><input name="whatsapp" value="<?= e($s['whatsapp']) ?>"></div>
            <div class="field"><label>Website</label><input name="website" value="<?= e($s['website']) ?>"></div>
        </div>
        <div class="field" style="margin:0"><label>Alamat</label><textarea name="address" rows="2"><?= e($s['address']) ?></textarea></div>
    </div>
    <div class="card">
        <h2>Rekening pembayaran</h2>
        <?php foreach ($banks as $b): ?>
            <div class="row2" style="margin-bottom:8px">
                <input name="bank[]" value="<?= e($b['bank'] ?? '') ?>" placeholder="Bank (mis. BCA)">
                <input name="bank_number[]" value="<?= e($b['number'] ?? '') ?>" placeholder="Nomor rekening">
                <input name="bank_holder[]" value="<?= e($b['holder'] ?? '') ?>" placeholder="Atas nama">
            </div>
        <?php endforeach; ?>
        <div class="field" style="margin:6px 0 0"><label>Catatan pembayaran</label><textarea name="payment_note" rows="2"><?= e($s['payment_note']) ?></textarea></div>
    </div>
    <div class="card">
        <h2>Syarat, tanda tangan & nomor</h2>
        <div class="field"><label>Syarat & ketentuan</label><textarea name="terms" rows="4"><?= e($s['terms']) ?></textarea></div>
        <div class="row2">
            <div class="field"><label>Nama penanda tangan</label><input name="signer_name" value="<?= e($s['signer_name']) ?>" placeholder="kosong = nama perusahaan"></div>
            <div class="field"><label>Jabatan</label><input name="signer_title" value="<?= e($s['signer_title']) ?>"></div>
            <div class="field"><label>Awalan nomor</label><input name="prefix" value="<?= e($s['prefix']) ?>" placeholder="INV"></div>
            <div class="field"><label>Jatuh tempo bawaan (hari)</label><input name="default_due_days" inputmode="numeric" value="<?= (int)$s['default_due_days'] ?>"></div>
            <div class="field"><label>PPN bawaan (%)</label><input name="default_tax_percent" inputmode="decimal" value="<?= e((string)$s['default_tax_percent']) ?>"></div>
        </div>
        <p class="muted" style="margin:0;font-size:12px">Contoh nomor berikutnya: <code><?= e(Invoice::nextNumber()) ?></code></p>
    </div>
    <button class="btn">Simpan pengaturan</button>
</form>
<?php View::end();
