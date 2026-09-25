<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/content-store.php';
require_once __DIR__ . '/../includes/upload-helper.php';
adf_admin_require_login();

$content = adf_load_content();
$saved = false;
$error = '';

$colorOptions = ['c-orange', 'c-blue', 'c-teal', 'c-purple', 'c-pink', 'c-green'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update') {
            $index = (int) ($_POST['index'] ?? -1);
            if (isset($content['portfolio'][$index])) {
                $existingImage = $content['portfolio'][$index]['image'] ?? '';

                if (!empty($_POST['remove_image'])) {
                    adf_delete_uploaded_image($existingImage);
                    $existingImage = '';
                }

                $uploaded = adf_upload_image('image', 'portfolio');
                if ($uploaded !== null) {
                    adf_delete_uploaded_image($existingImage);
                    $existingImage = $uploaded;
                } elseif (!empty($_FILES['image']['name']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $error = 'Gambar tidak valid. Gunakan JPG/PNG/WEBP/GIF maksimal 3MB.';
                }

                $content['portfolio'][$index] = [
                    'icon' => trim($_POST['icon'] ?? ''),
                    'color' => in_array($_POST['color'] ?? '', $colorOptions, true) ? $_POST['color'] : 'c-orange',
                    'tag' => trim($_POST['tag'] ?? ''),
                    'title' => trim($_POST['title'] ?? ''),
                    'desc' => trim($_POST['desc'] ?? ''),
                    'link' => trim($_POST['link'] ?? ''),
                    'image' => $existingImage,
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
            if (isset($content['portfolio'][$index])) {
                adf_delete_uploaded_image($content['portfolio'][$index]['image'] ?? '');
                array_splice($content['portfolio'], $index, 1);
                $saved = adf_save_content($content);
            }
        } elseif ($action === 'add') {
            $title = trim($_POST['title'] ?? '');
            if ($title === '') {
                $error = 'Judul produk tidak boleh kosong.';
            } else {
                $uploaded = adf_upload_image('image', 'portfolio');
                if ($uploaded === null && !empty($_FILES['image']['name']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $error = 'Gambar tidak valid. Gunakan JPG/PNG/WEBP/GIF maksimal 3MB.';
                } else {
                    $content['portfolio'][] = [
                        'icon' => trim($_POST['icon'] ?? '') ?: '📦',
                        'color' => in_array($_POST['color'] ?? '', $colorOptions, true) ? $_POST['color'] : 'c-orange',
                        'tag' => trim($_POST['tag'] ?? ''),
                        'title' => $title,
                        'desc' => trim($_POST['desc'] ?? ''),
                        'link' => trim($_POST['link'] ?? ''),
                        'image' => $uploaded ?? '',
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
$adminPageTitle = 'Edit Portofolio';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container">
    <h1>Portofolio Produk</h1>
    <p class="admin-lead">Kartu ini tampil di section "Produk &amp; Website yang Telah Kami Bangun" di beranda. Kalau ada gambar/screenshot produk, upload di sini — akan menggantikan preview ilustrasi.</p>


    <?php if ($saved): ?>
        <div class="admin-alert admin-alert-success">Perubahan tersimpan.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php foreach ($content['portfolio'] as $i => $item): ?>
        <form method="post" class="admin-form admin-form-inline" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="index" value="<?php echo (int) $i; ?>">
            <div class="admin-form-row">
                <label class="admin-field-small">Icon (emoji)
                    <input type="text" name="icon" value="<?php echo htmlspecialchars($item['icon']); ?>">
                </label>
                <label class="admin-field-small">Warna
                    <select name="color">
                        <?php foreach ($colorOptions as $c): ?>
                            <option value="<?php echo $c; ?>" <?php echo $item['color'] === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="admin-field-small">Tag
                    <input type="text" name="tag" value="<?php echo htmlspecialchars($item['tag']); ?>">
                </label>
            </div>
            <label>Judul
                <input type="text" name="title" value="<?php echo htmlspecialchars($item['title']); ?>" required>
            </label>
            <label>Deskripsi
                <textarea name="desc" rows="2"><?php echo htmlspecialchars($item['desc']); ?></textarea>
            </label>
            <label>Link Website (kosongkan jika internal/tidak publik)
                <input type="url" name="link" value="<?php echo htmlspecialchars($item['link']); ?>" placeholder="https://...">
            </label>
            <label>Gambar/Screenshot Produk (opsional, menggantikan preview ilustrasi)
                <?php if (!empty($item['image'])): ?>
                    <img src="../<?php echo htmlspecialchars($item['image']); ?>" alt="Gambar saat ini" class="admin-image-preview">
                    <span class="admin-checkbox-line"><input type="checkbox" name="remove_image" value="1"> Hapus gambar saat ini</span>
                <?php endif; ?>
                <input type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif">
            </label>
            <div class="admin-form-actions">
                <button type="submit" name="action" value="update" class="btn btn-primary">Simpan</button>
                <button type="submit" name="action" value="delete" class="btn btn-outline admin-btn-danger" onclick="return confirm('Hapus kartu ini?');">Hapus</button>
            </div>
        </form>
    <?php endforeach; ?>

    <h2 class="admin-subheading">Tambah Produk Baru</h2>
    <form method="post" class="admin-form" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <div class="admin-form-row">
            <label class="admin-field-small">Icon (emoji)
                <input type="text" name="icon" placeholder="📦">
            </label>
            <label class="admin-field-small">Warna
                <select name="color">
                    <?php foreach ($colorOptions as $c): ?>
                        <option value="<?php echo $c; ?>"><?php echo $c; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="admin-field-small">Tag
                <input type="text" name="tag" placeholder="Kategori">
            </label>
        </div>
        <label>Judul
            <input type="text" name="title" required>
        </label>
        <label>Deskripsi
            <textarea name="desc" rows="2"></textarea>
        </label>
        <label>Link Website (kosongkan jika internal/tidak publik)
            <input type="url" name="link" placeholder="https://...">
        </label>
        <label>Gambar/Screenshot Produk (opsional, menggantikan preview ilustrasi)
            <input type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif">
        </label>
        <button type="submit" name="action" value="add" class="btn btn-primary">Tambah Produk</button>
    </form>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
