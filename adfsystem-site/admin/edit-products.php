<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/content-store.php';
adf_admin_require_login();

$content = adf_load_content();
$saved = false;
$error = '';

function adf_parse_lines(string $text): array
{
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $lines = array_map('trim', $lines);
    return array_values(array_filter($lines, fn($l) => $l !== ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update') {
            $index = (int) ($_POST['index'] ?? -1);
            if (isset($content['products'][$index])) {
                $content['products'][$index] = [
                    'title' => trim($_POST['title'] ?? ''),
                    'tagline' => trim($_POST['tagline'] ?? ''),
                    'price' => max(0, (int) ($_POST['price'] ?? 0)),
                    'featured' => !empty($_POST['featured']),
                    'features' => adf_parse_lines($_POST['features'] ?? ''),
                    'notes' => adf_parse_lines($_POST['notes'] ?? ''),
                ];
                $saved = adf_save_content($content);
                if (!$saved) {
                    $error = 'Gagal menyimpan perubahan.';
                }
            }
        } elseif ($action === 'delete') {
            $index = (int) ($_POST['index'] ?? -1);
            if (isset($content['products'][$index])) {
                array_splice($content['products'], $index, 1);
                $saved = adf_save_content($content);
            }
        } elseif ($action === 'add') {
            $title = trim($_POST['title'] ?? '');
            if ($title === '') {
                $error = 'Nama paket tidak boleh kosong.';
            } else {
                $content['products'][] = [
                    'title' => $title,
                    'tagline' => trim($_POST['tagline'] ?? ''),
                    'price' => max(0, (int) ($_POST['price'] ?? 0)),
                    'featured' => !empty($_POST['featured']),
                    'features' => adf_parse_lines($_POST['features'] ?? ''),
                    'notes' => adf_parse_lines($_POST['notes'] ?? ''),
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
$adminPageTitle = 'Edit Paket Harga';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container">
    <h1>Paket Harga</h1>
    <p class="admin-lead">Kartu ini tampil di halaman Harga. Isi <strong>Harga (Rp)</strong> dengan 0 jika paket ini bersifat custom/konsultasi (tombol akan mengarah ke Kontak, bukan pembayaran). Jika harga diisi &gt; 0, tombol "Langganan" akan muncul dan mengarah ke pembayaran otomatis via Pakasir.</p>

    <?php if ($saved): ?>
        <div class="admin-alert admin-alert-success">Perubahan tersimpan.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php foreach ($content['products'] as $i => $product): ?>
        <form method="post" class="admin-form admin-form-inline">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="index" value="<?php echo (int) $i; ?>">
            <div class="admin-form-row">
                <label>Nama Paket
                    <input type="text" name="title" value="<?php echo htmlspecialchars($product['title']); ?>" required>
                </label>
                <label class="admin-field-small">Harga (Rp, 0 = custom)
                    <input type="number" name="price" min="0" step="1000" value="<?php echo (int) $product['price']; ?>">
                </label>
                <label class="admin-checkbox-line" style="align-self:center;">
                    <input type="checkbox" name="featured" value="1" <?php echo !empty($product['featured']) ? 'checked' : ''; ?>> Paling Populer
                </label>
            </div>
            <label>Tagline
                <input type="text" name="tagline" value="<?php echo htmlspecialchars($product['tagline']); ?>">
            </label>
            <label>Fitur (satu per baris)
                <textarea name="features" rows="6"><?php echo htmlspecialchars(implode("\n", $product['features'] ?? [])); ?></textarea>
            </label>
            <label>Catatan Peringatan (opsional, satu per baris — misal fitur yang belum tersedia)
                <textarea name="notes" rows="2"><?php echo htmlspecialchars(implode("\n", $product['notes'] ?? [])); ?></textarea>
            </label>
            <div class="admin-form-actions">
                <button type="submit" name="action" value="update" class="btn btn-primary">Simpan</button>
                <button type="submit" name="action" value="delete" class="btn btn-outline admin-btn-danger" onclick="return confirm('Hapus paket ini?');">Hapus</button>
            </div>
        </form>
    <?php endforeach; ?>

    <h2 class="admin-subheading">Tambah Paket Baru</h2>
    <form method="post" class="admin-form">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <div class="admin-form-row">
            <label>Nama Paket
                <input type="text" name="title" required>
            </label>
            <label class="admin-field-small">Harga (Rp, 0 = custom)
                <input type="number" name="price" min="0" step="1000" value="0">
            </label>
            <label class="admin-checkbox-line" style="align-self:center;">
                <input type="checkbox" name="featured" value="1"> Paling Populer
            </label>
        </div>
        <label>Tagline
            <input type="text" name="tagline">
        </label>
        <label>Fitur (satu per baris)
            <textarea name="features" rows="6"></textarea>
        </label>
        <label>Catatan Peringatan (opsional, satu per baris)
            <textarea name="notes" rows="2"></textarea>
        </label>
        <button type="submit" name="action" value="add" class="btn btn-primary">Tambah Paket</button>
    </form>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
