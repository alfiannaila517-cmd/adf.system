<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/content-store.php';
adf_admin_require_login();

$content = adf_load_content();
$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $whatsapp = trim($_POST['whatsapp'] ?? '');
        $address = trim($_POST['address'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email tidak valid.';
        } else {
            $content['contact']['email'] = $email;
            $content['contact']['whatsapp'] = $whatsapp;
            $content['contact']['address'] = $address;
            if (adf_save_content($content)) {
                $saved = true;
            } else {
                $error = 'Gagal menyimpan perubahan. Periksa izin folder data/.';
            }
        }
    }
}

$csrf = adf_admin_csrf_token();
$adminPageTitle = 'Edit Kontak';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container">
    <h1>Info Kontak</h1>
    <p class="admin-lead">Ditampilkan di halaman Kontak dan footer website.</p>

    <?php if ($saved): ?>
        <div class="admin-alert admin-alert-success">Perubahan tersimpan.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" class="admin-form">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <label>Email
            <input type="email" name="email" value="<?php echo htmlspecialchars($content['contact']['email']); ?>" required>
        </label>
        <label>Nomor WhatsApp
            <input type="text" name="whatsapp" value="<?php echo htmlspecialchars($content['contact']['whatsapp']); ?>" placeholder="contoh: 62812xxxxxxx">
        </label>
        <label>Alamat
            <textarea name="address" rows="3"><?php echo htmlspecialchars($content['contact']['address']); ?></textarea>
        </label>
        <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
    </form>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
