<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/content-store.php';
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

        if ($action === 'update_hero') {
            $title = trim($_POST['hero_title'] ?? '');
            $subtitle = trim($_POST['hero_subtitle'] ?? '');
            if ($title === '' || $subtitle === '') {
                $error = 'Judul dan deskripsi tidak boleh kosong.';
            } else {
                $content['layanan']['hero']['title'] = $title;
                $content['layanan']['hero']['subtitle'] = $subtitle;
                $saved = adf_save_content($content);
                if (!$saved) {
                    $error = 'Gagal menyimpan perubahan.';
                }
            }
        } elseif ($action === 'update') {
            $index = (int) ($_POST['index'] ?? -1);
            if (isset($content['layanan']['items'][$index])) {
                $title = trim($_POST['title'] ?? '');
                if ($title === '') {
                    $error = 'Judul layanan tidak boleh kosong.';
                } else {
                    $content['layanan']['items'][$index] = [
                        'icon' => trim($_POST['icon'] ?? '') ?: '📦',
                        'color' => in_array($_POST['color'] ?? '', $colorOptions, true) ? $_POST['color'] : 'c-orange',
                        'title' => $title,
                        'desc' => trim($_POST['desc'] ?? ''),
                    ];
                    $saved = adf_save_content($content);
                    if (!$saved) {
                        $error = 'Gagal menyimpan perubahan.';
                    }
                }
            }
        } elseif ($action === 'delete') {
            $index = (int) ($_POST['index'] ?? -1);
            if (isset($content['layanan']['items'][$index])) {
                array_splice($content['layanan']['items'], $index, 1);
                $saved = adf_save_content($content);
            }
        } elseif ($action === 'add') {
            $title = trim($_POST['title'] ?? '');
            if ($title === '') {
                $error = 'Judul layanan tidak boleh kosong.';
            } else {
                $content['layanan']['items'][] = [
                    'icon' => trim($_POST['icon'] ?? '') ?: '📦',
                    'color' => in_array($_POST['color'] ?? '', $colorOptions, true) ? $_POST['color'] : 'c-orange',
                    'title' => $title,
                    'desc' => trim($_POST['desc'] ?? ''),
                ];
                $saved = adf_save_content($content);
            }
        }

        if ($saved) {
            $content = adf_load_content();
        }
    }
}

$csrf = adf_admin_csrf_token();
$adminPageTitle = 'Edit Layanan';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container">
    <h1>Halaman Layanan</h1>
    <p class="admin-lead">Kelola judul halaman dan daftar modul yang tampil di halaman "Layanan".</p>

    <?php if ($saved): ?>
        <div class="admin-alert admin-alert-success">Perubahan tersimpan.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <h2 class="admin-subheading">Judul Halaman</h2>
    <form method="post" class="admin-form">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <label>Judul
            <input type="text" name="hero_title" value="<?php echo htmlspecialchars($content['layanan']['hero']['title']); ?>" required>
        </label>
        <label>Deskripsi
            <textarea name="hero_subtitle" rows="2" required><?php echo htmlspecialchars($content['layanan']['hero']['subtitle']); ?></textarea>
        </label>
        <button type="submit" name="action" value="update_hero" class="btn btn-primary">Simpan Judul</button>
    </form>

    <h2 class="admin-subheading">Daftar Modul Layanan</h2>
    <?php foreach ($content['layanan']['items'] as $i => $item): ?>
        <form method="post" class="admin-form admin-form-inline">
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
            </div>
            <label>Judul
                <input type="text" name="title" value="<?php echo htmlspecialchars($item['title']); ?>" required>
            </label>
            <label>Deskripsi
                <textarea name="desc" rows="2"><?php echo htmlspecialchars($item['desc']); ?></textarea>
            </label>
            <div class="admin-form-actions">
                <button type="submit" name="action" value="update" class="btn btn-primary">Simpan</button>
                <button type="submit" name="action" value="delete" class="btn btn-outline admin-btn-danger" onclick="return confirm('Hapus modul ini?');">Hapus</button>
            </div>
        </form>
    <?php endforeach; ?>

    <h2 class="admin-subheading">Tambah Modul Baru</h2>
    <form method="post" class="admin-form">
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
        </div>
        <label>Judul
            <input type="text" name="title" required>
        </label>
        <label>Deskripsi
            <textarea name="desc" rows="2"></textarea>
        </label>
        <button type="submit" name="action" value="add" class="btn btn-primary">Tambah Modul</button>
    </form>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
