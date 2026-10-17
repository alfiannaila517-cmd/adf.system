<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/invoices-store.php';
adf_admin_require_role('admin');

$settings = adf_invoice_settings();
$error = '';
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } else {
        $banks = [];
        foreach ((array) ($_POST['bank_name'] ?? []) as $i => $bn) {
            $row = ['bank' => mb_substr(trim((string) $bn), 0, 60), 'number' => mb_substr(trim((string) ($_POST['bank_number'][$i] ?? '')), 0, 60), 'holder' => mb_substr(trim((string) ($_POST['bank_holder'][$i] ?? '')), 0, 100)];
            if ($row['bank'] !== '' || $row['number'] !== '') {
                $banks[] = $row;
            }
        }
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Format email perusahaan tidak valid.';
        } else {
            $new = [
                'company_name' => mb_substr(trim((string) ($_POST['company_name'] ?? '')), 0, 150) ?: $settings['company_name'],
                'tagline' => mb_substr(trim((string) ($_POST['tagline'] ?? '')), 0, 150),
                'address' => mb_substr(trim((string) ($_POST['address'] ?? '')), 0, 300),
                'email' => $email,
                'whatsapp' => mb_substr(trim((string) ($_POST['whatsapp'] ?? '')), 0, 40),
                'website' => mb_substr(trim((string) ($_POST['website'] ?? '')), 0, 120),
                'prefix' => preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['prefix'] ?? 'INV')) ?: 'INV',
                'default_due_days' => max(0, min(365, (int) ($_POST['default_due_days'] ?? 7))),
                'default_tax_percent' => max(0, min(100, (float) str_replace(',', '.', (string) ($_POST['default_tax_percent'] ?? 0)))),
                'banks' => $banks ?: [['bank' => '', 'number' => '', 'holder' => '']],
                'payment_note' => mb_substr(trim((string) ($_POST['payment_note'] ?? '')), 0, 400),
                'terms' => mb_substr(trim((string) ($_POST['terms'] ?? '')), 0, 1200),
                'signer_name' => mb_substr(trim((string) ($_POST['signer_name'] ?? '')), 0, 100),
                'signer_title' => mb_substr(trim((string) ($_POST['signer_title'] ?? '')), 0, 100),
            ];
            if (adf_invoice_settings_save($new)) {
                $saved = true;
                $settings = adf_invoice_settings();
            } else {
                $error = 'Gagal menyimpan pengaturan (periksa izin tulis folder data).';
            }
        }
    }
}

$banksForm = $settings['banks'];
while (count($banksForm) < 3) {
    $banksForm[] = ['bank' => '', 'number' => '', 'holder' => ''];
}
$csrf = adf_admin_csrf_token();
$adminNavForce = 'invoices.php';
$adminPageTitle = 'Pengaturan Invoice';
require __DIR__ . '/../includes/admin-header.php';
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<div class="container admin-container admin-container-wide">
    <div class="inv-pagehead">
        <div>
            <h1>Pengaturan Invoice</h1>
            <p class="admin-lead">Data ini tampil di setiap invoice: profil perusahaan, rekening pembayaran, dan syarat &amp; ketentuan.</p>
        </div>
        <a href="invoices.php" class="btn btn-outline">&larr; Daftar Invoice</a>
    </div>

    <?php if ($error): ?><div class="admin-alert admin-alert-error"><?php echo $e($error); ?></div><?php endif; ?>
    <?php if ($saved): ?><div class="admin-alert admin-alert-success">Pengaturan invoice tersimpan.</div><?php endif; ?>

    <form method="post" class="inv-form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?php echo $e($csrf); ?>">
        <div class="inv-grid">
            <div class="inv-main">
                <section class="inv-card">
                    <h2>Profil perusahaan</h2>
                    <div class="inv-fields two">
                        <label>Nama perusahaan<input type="text" name="company_name" maxlength="150" value="<?php echo $e($settings['company_name']); ?>"></label>
                        <label>Slogan<input type="text" name="tagline" maxlength="150" value="<?php echo $e($settings['tagline']); ?>"></label>
                        <label class="full">Alamat<textarea name="address" rows="2" maxlength="300"><?php echo $e($settings['address']); ?></textarea></label>
                        <label>Email<input type="email" name="email" maxlength="150" value="<?php echo $e($settings['email']); ?>"></label>
                        <label>WhatsApp / telepon<input type="text" name="whatsapp" maxlength="40" value="<?php echo $e($settings['whatsapp']); ?>"></label>
                        <label>Website<input type="text" name="website" maxlength="120" value="<?php echo $e($settings['website']); ?>"></label>
                    </div>
                </section>

                <section class="inv-card">
                    <h2>Rekening pembayaran</h2>
                    <p class="inv-hint">Maksimal 3 rekening ditampilkan di invoice. Kosongkan baris yang tidak dipakai.</p>
                    <?php foreach (array_slice($banksForm, 0, 3) as $b): ?>
                        <div class="inv-fields three bankrow">
                            <label>Bank / e-wallet<input type="text" name="bank_name[]" maxlength="60" value="<?php echo $e($b['bank'] ?? ''); ?>" placeholder="BCA / Mandiri / DANA"></label>
                            <label>Nomor rekening<input type="text" name="bank_number[]" maxlength="60" value="<?php echo $e($b['number'] ?? ''); ?>"></label>
                            <label>Atas nama<input type="text" name="bank_holder[]" maxlength="100" value="<?php echo $e($b['holder'] ?? ''); ?>"></label>
                        </div>
                    <?php endforeach; ?>
                    <label class="inv-block" style="margin-top:10px;">Catatan pembayaran
                        <textarea name="payment_note" rows="2" maxlength="400"><?php echo $e($settings['payment_note']); ?></textarea>
                    </label>
                </section>

                <section class="inv-card">
                    <h2>Syarat &amp; ketentuan</h2>
                    <label class="inv-block">Tampil di bagian bawah invoice (satu poin per baris)
                        <textarea name="terms" rows="5" maxlength="1200"><?php echo $e($settings['terms']); ?></textarea>
                    </label>
                </section>
            </div>

            <aside class="inv-side">
                <section class="inv-card">
                    <h2>Penomoran &amp; bawaan</h2>
                    <div class="inv-fields">
                        <label>Awalan nomor<input type="text" name="prefix" maxlength="10" value="<?php echo $e($settings['prefix']); ?>"><small class="inv-hint">Contoh: <?php echo $e(adf_invoice_next_number()); ?></small></label>
                        <label>Jatuh tempo bawaan (hari)<input type="number" name="default_due_days" min="0" max="365" value="<?php echo (int) $settings['default_due_days']; ?>"></label>
                        <label>PPN bawaan (%)<input type="number" name="default_tax_percent" min="0" max="100" step="any" value="<?php echo $e($settings['default_tax_percent']); ?>"></label>
                    </div>
                </section>
                <section class="inv-card">
                    <h2>Penandatangan</h2>
                    <div class="inv-fields">
                        <label>Nama<input type="text" name="signer_name" maxlength="100" value="<?php echo $e($settings['signer_name']); ?>" placeholder="Kosong = nama perusahaan"></label>
                        <label>Jabatan<input type="text" name="signer_title" maxlength="100" value="<?php echo $e($settings['signer_title']); ?>"></label>
                    </div>
                </section>
                <div class="inv-submit">
                    <button type="submit" class="btn btn-primary">Simpan Pengaturan</button>
                </div>
            </aside>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
