<?php
/**
 * PWF Management - Settings/Branding
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../db-helper.php';

// Check access
$isOwner = isset($_SESSION['role']) && in_array($_SESSION['role'], ['owner', 'developer']);
if (!$isOwner) {
    http_response_code(403);
    echo 'Access Denied';
    exit;
}

$pdo = getPwfOfficePdo();
$msg = '';
$msgType = 'success';

// Get current settings
$settings = [];
$settingRows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('pwf_company_name','pwf_company_phone','pwf_company_address','pwf_login_logo','pwf_login_wallpaper','pwf_office_logo')")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($settingRows as $k => $v) {
    $settings[$k] = $v;
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';
    
    if ($action === 'save_info') {
        $companyName = trim($_POST['company_name'] ?? '');
        $companyPhone = trim($_POST['company_phone'] ?? '');
        $companyAddress = trim($_POST['company_address'] ?? '');
        
        if (empty($companyName)) {
            $msg = 'Nama perusahaan tidak boleh kosong';
            $msgType = 'error';
        } else {
            $saveSetting = function($key, $value) use ($pdo) {
                $check = $pdo->prepare('SELECT id FROM settings WHERE setting_key=?');
                $check->execute([$key]);
                if ($check->fetch()) {
                    $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute([$value, $key]);
                } else {
                    $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?)')->execute([$key, $value]);
                }
            };
            
            $saveSetting('pwf_company_name', $companyName);
            $saveSetting('pwf_company_phone', $companyPhone);
            $saveSetting('pwf_company_address', $companyAddress);
            
            $settings['pwf_company_name'] = $companyName;
            $settings['pwf_company_phone'] = $companyPhone;
            $settings['pwf_company_address'] = $companyAddress;
            
            $msg = 'Informasi perusahaan berhasil disimpan!';
            $msgType = 'success';
        }
    }
    elseif ($action === 'upload_logo' && isset($_FILES['logo'])) {
        $file = $_FILES['logo'];
        if ($file['size'] > 0) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $msg = 'Tipe file tidak didukung. Gunakan JPG, PNG, GIF, atau WebP';
                $msgType = 'error';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $msg = 'Ukuran file terlalu besar (max 5MB)';
                $msgType = 'error';
            } else {
                $dir = BASE_PATH . '/uploads/logos/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = 'pwf-logo-' . time() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                    $saveSetting = function($key, $value) use ($pdo) {
                        $check = $pdo->prepare('SELECT id FROM settings WHERE setting_key=?');
                        $check->execute([$key]);
                        if ($check->fetch()) {
                            $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute([$value, $key]);
                        } else {
                            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?)')->execute([$key, $value]);
                        }
                    };
                    $saveSetting('pwf_office_logo', 'uploads/logos/' . $filename);
                    $settings['pwf_office_logo'] = 'uploads/logos/' . $filename;
                    $msg = 'Logo berhasil diupload!';
                    $msgType = 'success';
                } else {
                    $msg = 'Gagal mengupload file';
                    $msgType = 'error';
                }
            }
        }
    }
    elseif ($action === 'upload_wallpaper' && isset($_FILES['wallpaper'])) {
        $file = $_FILES['wallpaper'];
        if ($file['size'] > 0) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $msg = 'Tipe file tidak didukung. Gunakan JPG, PNG, GIF, atau WebP';
                $msgType = 'error';
            } elseif ($file['size'] > 10 * 1024 * 1024) {
                $msg = 'Ukuran file terlalu besar (max 10MB)';
                $msgType = 'error';
            } else {
                $dir = BASE_PATH . '/uploads/backgrounds/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = 'pwf-wallpaper-' . time() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                    $saveSetting = function($key, $value) use ($pdo) {
                        $check = $pdo->prepare('SELECT id FROM settings WHERE setting_key=?');
                        $check->execute([$key]);
                        if ($check->fetch()) {
                            $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute([$value, $key]);
                        } else {
                            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?)')->execute([$key, $value]);
                        }
                    };
                    $saveSetting('pwf_login_wallpaper', 'uploads/backgrounds/' . $filename);
                    $settings['pwf_login_wallpaper'] = 'uploads/backgrounds/' . $filename;
                    $msg = 'Wallpaper login berhasil diupload!';
                    $msgType = 'success';
                } else {
                    $msg = 'Gagal mengupload file';
                    $msgType = 'error';
                }
            }
        }
    }
}

require_once __DIR__ . '/../layout.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Pengaturan Branding - PWF Management</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f3f0; color: #1c1511; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        .back-link { display: inline-block; margin-bottom: 20px; color: #B8860B; text-decoration: none; font-size: 14px; }
        .header { margin-bottom: 30px; }
        .header h1 { font-size: 24px; margin-bottom: 8px; display: flex; align-items: center; gap: 12px; }
        .header h1 i { color: #B8860B; }
        .card { background: white; border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
        .card h2 { font-size: 16px; margin-bottom: 16px; color: #1c1511; border-bottom: 2px solid #F5F3F0; padding-bottom: 12px; }
        .form-group { margin-bottom: 16px; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #666; }
        input[type="text"], textarea { width: 100%; padding: 10px 12px; border: 1px solid #E7E5E4; border-radius: 6px; font-size: 14px; font-family: Arial, sans-serif; }
        input[type="text"]:focus, textarea:focus { outline: none; border-color: #B8860B; }
        textarea { resize: vertical; min-height: 60px; }
        .preview { margin-top: 12px; max-width: 300px; }
        .preview img { max-width: 100%; border-radius: 6px; border: 1px solid #E7E5E4; }
        .file-input { position: relative; }
        input[type="file"] { padding: 8px 0; }
        .upload-btn { display: inline-block; padding: 10px 20px; background: #B8860B; color: white; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
        .upload-btn:hover { background: #9D6F0A; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 13px; }
        .alert.success { background: #F0FDF4; color: #166534; border: 1px solid #DCFCE7; }
        .alert.error { background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; }
        .button-group { display: flex; gap: 10px; margin-top: 20px; }
        button { padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; }
        .btn-primary { background: #B8860B; color: white; }
        .btn-primary:hover { background: #9D6F0A; }
        .btn-secondary { background: #F5F3F0; color: #1c1511; border: 1px solid #E7E5E4; }
        .btn-secondary:hover { background: #EAE8E5; }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link">← Kembali ke PWF Management</a>
        
        <div class="header">
            <h1><i class="bi bi-palette-fill"></i>Pengaturan Branding</h1>
        </div>
        
        <?php if ($msg): ?>
            <div class="alert <?= $msgType ?>">
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>
        
        <!-- Informasi Perusahaan -->
        <div class="card">
            <h2>📋 Informasi Perusahaan</h2>
            <form method="post">
                <input type="hidden" name="_action" value="save_info">
                
                <div class="form-group">
                    <label>Nama Perusahaan</label>
                    <input type="text" name="company_name" value="<?= htmlspecialchars($settings['pwf_company_name'] ?? 'Prapen Wood Furniture') ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Nomor Telepon</label>
                    <input type="text" name="company_phone" value="<?= htmlspecialchars($settings['pwf_company_phone'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label>Alamat Kantor</label>
                    <textarea name="company_address"><?= htmlspecialchars($settings['pwf_company_address'] ?? '') ?></textarea>
                </div>
                
                <button type="submit" class="btn-primary">Simpan Informasi</button>
            </form>
        </div>
        
        <!-- Logo Perusahaan -->
        <div class="card">
            <h2>🏢 Logo Perusahaan</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="_action" value="upload_logo">
                
                <div class="form-group">
                    <label>Upload Logo</label>
                    <input type="file" name="logo" accept="image/*" required>
                    <div style="font-size: 12px; color: #999; margin-top: 4px;">Format: JPG, PNG, GIF, WebP | Max: 5MB</div>
                </div>
                
                <?php if (!empty($settings['pwf_office_logo'])): ?>
                    <div class="preview">
                        <strong style="font-size: 12px;">Logo Saat Ini:</strong><br>
                        <img src="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/' . $settings['pwf_office_logo']) ?>" alt="Logo">
                    </div>
                <?php endif; ?>
                
                <button type="submit" class="btn-primary" style="margin-top: 16px;">Upload Logo</button>
            </form>
        </div>
        
        <!-- Wallpaper Login -->
        <div class="card">
            <h2>🎨 Wallpaper Halaman Login</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="_action" value="upload_wallpaper">
                
                <div class="form-group">
                    <label>Upload Wallpaper</label>
                    <input type="file" name="wallpaper" accept="image/*" required>
                    <div style="font-size: 12px; color: #999; margin-top: 4px;">Format: JPG, PNG, GIF, WebP | Max: 10MB | Rekomendasi: Landscape 1920×1080 atau lebih</div>
                </div>
                
                <?php if (!empty($settings['pwf_login_wallpaper'])): ?>
                    <div class="preview">
                        <strong style="font-size: 12px;">Wallpaper Saat Ini:</strong><br>
                        <img src="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/' . $settings['pwf_login_wallpaper']) ?>" alt="Wallpaper">
                    </div>
                <?php endif; ?>
                
                <button type="submit" class="btn-primary" style="margin-top: 16px;">Upload Wallpaper</button>
            </form>
        </div>
    </div>
</body>
</html>
