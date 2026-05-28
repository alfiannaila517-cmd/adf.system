<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$pdo = getPwfOfficePdo();
ensurePwfOfficeTables($pdo);

$message = '';
$messageType = '';

// ─── SAVE SETTINGS ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';
    $uploadDir = defined('UPLOAD_PATH') ? UPLOAD_PATH . '/pwf-settings/' : __DIR__ . '/../../uploads/pwf-settings/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    if ($action === 'save_text') {
        // Simpan setting teks (nama perusahaan, tagline, dll)
        $textKeys = ['pwf_company_name', 'pwf_company_tagline', 'pwf_company_address', 'pwf_company_phone'];
        foreach ($textKeys as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
                    ON DUPLICATE KEY UPDATE setting_value=?");
                $stmt->execute([$key, $val, $val]);
            }
        }
        $message = 'Informasi perusahaan berhasil disimpan.';
        $messageType = 'success';

    } elseif ($action === 'upload_logo') {
        $file = $_FILES['logo_file'] ?? null;
        if ($file && $file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','svg','webp'])) {
                $message = 'Format file tidak didukung. Gunakan JPG, PNG, SVG, atau WebP.';
                $messageType = 'error';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $message = 'Ukuran file logo maksimal 2 MB.';
                $messageType = 'error';
            } else {
                $filename = 'logo_' . time() . '.' . $ext;
                $destPath = $uploadDir . $filename;
                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    $webPath = (defined('BASE_URL') ? BASE_URL : '') . '/uploads/pwf-settings/' . $filename;
                    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('pwf_login_logo',?)
                        ON DUPLICATE KEY UPDATE setting_value=?");
                    $stmt->execute([$webPath, $webPath]);
                    $message = 'Logo berhasil diupload.';
                    $messageType = 'success';
                } else {
                    $message = 'Gagal menyimpan file logo.';
                    $messageType = 'error';
                }
            }
        } else {
            $message = 'Pilih file logo terlebih dahulu.';
            $messageType = 'error';
        }

    } elseif ($action === 'upload_wallpaper') {
        $file = $_FILES['wallpaper_file'] ?? null;
        if ($file && $file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
                $message = 'Format wallpaper: JPG, PNG, atau WebP.';
                $messageType = 'error';
            } elseif ($file['size'] > 8 * 1024 * 1024) {
                $message = 'Ukuran file wallpaper maksimal 8 MB.';
                $messageType = 'error';
            } else {
                $filename = 'wallpaper_' . time() . '.' . $ext;
                $destPath = $uploadDir . $filename;
                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    $webPath = (defined('BASE_URL') ? BASE_URL : '') . '/uploads/pwf-settings/' . $filename;
                    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('pwf_login_wallpaper',?)
                        ON DUPLICATE KEY UPDATE setting_value=?");
                    $stmt->execute([$webPath, $webPath]);
                    $message = 'Wallpaper login berhasil diupload.';
                    $messageType = 'success';
                } else {
                    $message = 'Gagal menyimpan file wallpaper.';
                    $messageType = 'error';
                }
            }
        } else {
            $message = 'Pilih file wallpaper terlebih dahulu.';
            $messageType = 'error';
        }

    } elseif ($action === 'reset_wallpaper') {
        $pdo->exec("DELETE FROM settings WHERE setting_key='pwf_login_wallpaper'");
        $message = 'Wallpaper direset ke tampilan default.';
        $messageType = 'success';

    } elseif ($action === 'reset_logo') {
        $pdo->exec("DELETE FROM settings WHERE setting_key='pwf_login_logo'");
        $message = 'Logo direset ke default.';
        $messageType = 'success';
    }
}

// ─── LOAD CURRENT SETTINGS ────────────────────────────────────────────────────
$settingKeys = ['pwf_login_logo','pwf_login_wallpaper','pwf_company_name','pwf_company_tagline','pwf_company_address','pwf_company_phone'];
$settings = [];
$rows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('" . implode("','", $settingKeys) . "')")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($settingKeys as $k) $settings[$k] = $rows[$k] ?? '';

pwfOfficeHeader('Settings', 'settings');
?>

<div class="pwf-content-header">
    <h1 class="pwf-page-title"><span>⚙️</span> Settings Sistem</h1>
    <p class="pwf-page-sub">Konfigurasi tampilan dan informasi PWF Office</p>
</div>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?> mb-4">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- LOGO PERUSAHAAN -->
    <div class="col-lg-6">
        <div class="pwf-card">
            <div class="pwf-card-header">
                <h5 class="mb-0">🖼️ Logo Perusahaan</h5>
            </div>
            <div class="pwf-card-body">
                <p class="text-muted small mb-3">Logo ini akan ditampilkan di halaman login dan navbar. Format: JPG, PNG, SVG, WebP. Maks 2 MB.</p>

                <?php if ($settings['pwf_login_logo']): ?>
                <div class="mb-3 text-center">
                    <div style="background:#1a1a2e;padding:20px;border-radius:12px;display:inline-block;">
                        <img src="<?= htmlspecialchars($settings['pwf_login_logo']) ?>"
                             alt="Logo saat ini" style="max-height:80px;max-width:180px;object-fit:contain;">
                    </div>
                    <div class="mt-2 text-muted small">Logo saat ini</div>
                </div>
                <?php else: ?>
                <div class="mb-3 text-center">
                    <div style="background:#1a1a2e;padding:20px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;width:100px;height:100px;font-size:40px;">🪵</div>
                    <div class="mt-2 text-muted small">Menggunakan ikon default</div>
                </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="_action" value="upload_logo">
                    <div class="mb-3">
                        <label class="form-label">Upload Logo Baru</label>
                        <input type="file" class="form-control" name="logo_file" accept=".jpg,.jpeg,.png,.svg,.webp" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Upload Logo</button>
                </form>

                <?php if ($settings['pwf_login_logo']): ?>
                <form method="post" class="mt-2" onsubmit="return confirm('Reset logo ke default?')">
                    <input type="hidden" name="_action" value="reset_logo">
                    <button type="submit" class="btn btn-outline-secondary btn-sm">Reset ke Default</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- WALLPAPER LOGIN -->
    <div class="col-lg-6">
        <div class="pwf-card">
            <div class="pwf-card-header">
                <h5 class="mb-0">🌅 Wallpaper Halaman Login</h5>
            </div>
            <div class="pwf-card-body">
                <p class="text-muted small mb-3">Background halaman login. Format: JPG, PNG, WebP. Maks 8 MB. Rekomendasi: 1920×1080px.</p>

                <?php if ($settings['pwf_login_wallpaper']): ?>
                <div class="mb-3">
                    <img src="<?= htmlspecialchars($settings['pwf_login_wallpaper']) ?>"
                         alt="Wallpaper saat ini"
                         style="width:100%;height:120px;object-fit:cover;border-radius:10px;border:1px solid rgba(255,255,255,.1)">
                    <div class="mt-1 text-muted small">Wallpaper saat ini</div>
                </div>
                <?php else: ?>
                <div class="mb-3" style="background:radial-gradient(ellipse at 70% 20%,#1a3a2e 0%,#0a0e1a 55%);height:120px;border-radius:10px;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.4);font-size:13px;">
                    Menggunakan gradient default
                </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="_action" value="upload_wallpaper">
                    <div class="mb-3">
                        <label class="form-label">Upload Wallpaper Baru</label>
                        <input type="file" class="form-control" name="wallpaper_file" accept=".jpg,.jpeg,.png,.webp" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Upload Wallpaper</button>
                </form>

                <?php if ($settings['pwf_login_wallpaper']): ?>
                <form method="post" class="mt-2" onsubmit="return confirm('Reset wallpaper ke gradient default?')">
                    <input type="hidden" name="_action" value="reset_wallpaper">
                    <button type="submit" class="btn btn-outline-secondary btn-sm">Reset ke Default</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- INFORMASI PERUSAHAAN -->
    <div class="col-12">
        <div class="pwf-card">
            <div class="pwf-card-header">
                <h5 class="mb-0">🏢 Informasi Perusahaan</h5>
            </div>
            <div class="pwf-card-body">
                <form method="post">
                    <input type="hidden" name="_action" value="save_text">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nama Perusahaan</label>
                            <input type="text" class="form-control" name="pwf_company_name"
                                   value="<?= htmlspecialchars($settings['pwf_company_name'] ?: 'Prapen Wood Furniture') ?>"
                                   placeholder="Prapen Wood Furniture">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tagline / Slogan</label>
                            <input type="text" class="form-control" name="pwf_company_tagline"
                                   value="<?= htmlspecialchars($settings['pwf_company_tagline'] ?: 'Crafting Excellence, Tracking Every Step') ?>"
                                   placeholder="Crafting Excellence, ...">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Alamat Lengkap</label>
                            <input type="text" class="form-control" name="pwf_company_address"
                                   value="<?= htmlspecialchars($settings['pwf_company_address'] ?: 'Jl. Ngabul - Batealit No.KM. 5 Godang, Mindahan, Kec. Batealit, Kab. Jepara, Jawa Tengah 59400') ?>"
                                   placeholder="Jl. ...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">No. Telepon / WhatsApp</label>
                            <input type="text" class="form-control" name="pwf_company_phone"
                                   value="<?= htmlspecialchars($settings['pwf_company_phone']) ?>"
                                   placeholder="+62 ...">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Simpan Informasi</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- PREVIEW LOGIN PAGE -->
    <div class="col-12">
        <div class="pwf-card">
            <div class="pwf-card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">👁️ Preview Halaman Login</h5>
                <a href="<?= defined('BASE_URL') ? BASE_URL : '' ?>/pwf-login.php" target="_blank" class="btn btn-outline-light btn-sm">
                    Buka Login Page
                </a>
            </div>
            <div class="pwf-card-body">
                <p class="text-muted small">Klik tombol di atas untuk melihat tampilan login page dengan setting yang sudah disimpan.</p>
                <div class="d-flex gap-2 flex-wrap">
                    <span class="badge bg-secondary">Logo: <?= $settings['pwf_login_logo'] ? '✅ Custom' : '🪵 Default' ?></span>
                    <span class="badge bg-secondary">Wallpaper: <?= $settings['pwf_login_wallpaper'] ? '✅ Custom' : '🎨 Gradient Default' ?></span>
                </div>
            </div>
        </div>
    </div>

</div>

<?php pwfOfficeFooter(); ?>
