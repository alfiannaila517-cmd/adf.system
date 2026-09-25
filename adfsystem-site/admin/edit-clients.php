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
        $action = $_POST['action'] ?? '';

        if ($action === 'update') {
            $index = (int) ($_POST['index'] ?? -1);
            if (isset($content['clients'][$index])) {
                $existingLogo = $content['clients'][$index]['logo'] ?? '';

                if (!empty($_POST['remove_logo'])) {
                    adf_delete_uploaded_image($existingLogo);
                    $existingLogo = '';
                }

                $uploaded = adf_upload_image('logo', 'clients');
                if ($uploaded !== null) {
                    adf_delete_uploaded_image($existingLogo);
                    $existingLogo = $uploaded;
                } elseif (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $error = 'Logo tidak valid. Gunakan JPG/PNG/WEBP/GIF maksimal 3MB.';
                }

                $content['clients'][$index] = [
                    'name' => trim($_POST['name'] ?? ''),
                    'link' => trim($_POST['link'] ?? ''),
                    'logo' => $existingLogo,
                ];

                if ($error === '') {
                    $saved = adf_save_content($content);
                    if (!$saved) {
                        $error = 'Gagal menyimpan perubahan.';
                    }
                }
            }
        } elseif ($action === 'delete') {
            $index = (int) ($_POST['index'] ?? -1);
            if (isset($content['clients'][$index])) {
                adf_delete_uploaded_image($content['clients'][$index]['logo'] ?? '');
                array_splice($content['clients'], $index, 1);
                $saved = adf_save_content($content);
            }
        } elseif ($action === 'add') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                $error = 'Nama perusahaan tidak boleh kosong.';
            } else {
                $uploaded = adf_upload_image('logo', 'clients');
                if ($uploaded === null && !empty($_FILES['logo']['name']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $error = 'Logo tidak valid. Gunakan JPG/PNG/WEBP/GIF maksimal 3MB.';
                } else {
                    $content['clients'][] = [
                        'name' => $name,
                        'link' => trim($_POST['link'] ?? ''),
                        'logo' => $uploaded ?? '',
                    ];
                    $saved = adf_save_content($content);
                }
            }
        }

        if ($saved) {
            $content = adf_load_content();
        }
    }
}

$csrf = adf_admin_csrf_token();
$adminPageTitle = 'Edit Logo Klien';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container">
    <h1>Logo Perusahaan Klien</h1>
    <p class="admin-lead">Logo ini tampil di section "Dipercaya oleh Berbagai Perusahaan" di beranda, di bawah daftar produk &amp; website. Upload logo perusahaan yang sudah menggunakan jasa ADF System, dan tautan website-nya (opsional).</p>

    <?php if ($saved): ?>
        <div class="admin-alert admin-alert-success">Perubahan tersimpan.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php foreach ($content['clients'] as $i => $client): ?>
        <form method="post" class="admin-form admin-form-inline" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="index" value="<?php echo (int) $i; ?>">
            <label>Nama Perusahaan
                <input type="text" name="name" value="<?php echo htmlspecialchars($client['name']); ?>" required>
            </label>
            <label>Link Website (opsional)
                <input type="url" name="link" value="<?php echo htmlspecialchars($client['link']); ?>" placeholder="https://...">
            </label>
            <label>Logo Perusahaan
                <?php if (!empty($client['logo'])): ?>
                    <img src="../<?php echo htmlspecialchars($client['logo']); ?>" alt="Logo saat ini" class="admin-image-preview">
                    <span class="admin-checkbox-line"><input type="checkbox" name="remove_logo" value="1"> Hapus logo saat ini</span>
                <?php endif; ?>
                <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif">
            </label>
            <div class="admin-form-actions">
                <button type="submit" name="action" value="update" class="btn btn-primary">Simpan</button>
                <button type="submit" name="action" value="delete" class="btn btn-outline admin-btn-danger" onclick="return confirm('Hapus logo ini?');">Hapus</button>
            </div>
        </form>
    <?php endforeach; ?>

    <h2 class="admin-subheading">Tambah Logo Perusahaan Baru</h2>
    <form method="post" class="admin-form" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <label>Nama Perusahaan
            <input type="text" name="name" required>
        </label>
        <label>Link Website (opsional)
            <input type="url" name="link" placeholder="https://...">
        </label>
        <label>Logo Perusahaan
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif">
        </label>
        <button type="submit" name="action" value="add" class="btn btn-primary">Tambah Logo</button>
    </form>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
