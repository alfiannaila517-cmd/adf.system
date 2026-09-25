<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/content-store.php';
require_once __DIR__ . '/../includes/upload-helper.php';
adf_admin_require_login();

$content = adf_load_content();
$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        if ($title === '' || $subtitle === '') {
            $error = 'Judul dan deskripsi tidak boleh kosong.';
        } else {
            $content['hero']['title'] = $title;
            $content['hero']['subtitle'] = $subtitle;

            if (!empty($_POST['remove_background'])) {
                adf_delete_uploaded_image($content['hero']['background']);
                $content['hero']['background'] = '';
            }

            $uploaded = adf_upload_image('background', 'hero');
            if ($uploaded !== null) {
                adf_delete_uploaded_image($content['hero']['background']);
                $content['hero']['background'] = $uploaded;
            } elseif (!empty($_FILES['background']['name']) && $_FILES['background']['error'] !== UPLOAD_ERR_NO_FILE) {
                $error = 'Gambar tidak valid. Gunakan JPG/PNG/WEBP/GIF maksimal 3MB.';
            }

            if ($error === '') {
                if (adf_save_content($content)) {
                    $saved = true;
                } else {
                    $error = 'Gagal menyimpan perubahan. Periksa izin folder data/.';
                }
            }
        }
    }
}

$csrf = adf_admin_csrf_token();
$adminPageTitle = 'Edit Hero';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container">
    <h1>Hero Beranda</h1>
    <p class="admin-lead">Teks ini tampil paling atas di halaman depan website.</p>

    <?php if ($saved): ?>
        <div class="admin-alert admin-alert-success">Perubahan tersimpan.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" class="admin-form" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <label>Judul Utama
            <input type="text" name="title" value="<?php echo htmlspecialchars($content['hero']['title']); ?>" required>
        </label>
        <label>Deskripsi
            <textarea name="subtitle" rows="4" required><?php echo htmlspecialchars($content['hero']['subtitle']); ?></textarea>
        </label>
        <label>Gambar Latar Belakang Hero (opsional)
            <?php if (!empty($content['hero']['background'])): ?>
                <img src="../<?php echo htmlspecialchars($content['hero']['background']); ?>" alt="Background saat ini" class="admin-image-preview">
                <span class="admin-checkbox-line"><input type="checkbox" name="remove_background" value="1"> Hapus gambar latar saat ini</span>
            <?php endif; ?>
            <input type="file" name="background" accept="image/png,image/jpeg,image/webp,image/gif">
        </label>
        <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
    </form>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
