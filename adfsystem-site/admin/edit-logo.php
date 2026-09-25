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
        if (!empty($_POST['remove_logo'])) {
            adf_delete_uploaded_image($content['branding']['logo']);
            $content['branding']['logo'] = '';
        }

        $uploaded = adf_upload_image('logo', 'branding');
        if ($uploaded !== null) {
            adf_delete_uploaded_image($content['branding']['logo']);
            $content['branding']['logo'] = $uploaded;
        } elseif (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
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

$csrf = adf_admin_csrf_token();
$adminPageTitle = 'Logo Perusahaan';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container">
    <h1>Logo Perusahaan</h1>
    <p class="admin-lead">Logo ini tampil di header website dan panel admin, menggantikan ikon huruf bawaan.</p>

    <?php if ($saved): ?>
        <div class="admin-alert admin-alert-success">Perubahan tersimpan.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" class="admin-form" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <label>Logo Perusahaan (opsional)
            <?php if (!empty($content['branding']['logo'])): ?>
                <img src="../<?php echo htmlspecialchars($content['branding']['logo']); ?>" alt="Logo saat ini" class="admin-image-preview">
                <span class="admin-checkbox-line"><input type="checkbox" name="remove_logo" value="1"> Hapus logo saat ini (kembali ke ikon bawaan)</span>
            <?php endif; ?>
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif">
        </label>
        <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
    </form>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
