<?php

/**
 * Developer Panel - Developer Settings
 * Configure developer name, logo, login background, WhatsApp, footer text
 */

require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once dirname(dirname(__FILE__)) . '/config/database.php';
require_once __DIR__ . '/includes/dev_auth.php';
require_once dirname(dirname(__FILE__)) . '/includes/functions.php';
require_once dirname(dirname(__FILE__)) . '/includes/CloudinaryHelper.php';

$devAuth = new DevAuth();
$devAuth->requireLogin();

$db = Database::getInstance();
$pageTitle = 'Pengaturan & Branding';
$currentPage = 'developer-settings';

$error = '';
$success = '';

// Get settings from database
$loginBgSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'login_background'");
$currentLoginBg = $loginBgSetting['setting_value'] ?? null;

$loginLogoSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'login_logo'");
$currentLoginLogo = $loginLogoSetting['setting_value'] ?? null;

$faviconSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'site_favicon'");
$currentFavicon = $faviconSetting['setting_value'] ?? null;

$pwaIconSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'pwa_app_icon'");
$currentPwaIcon = $pwaIconSetting['setting_value'] ?? null;

// Per-business Staff Portal login background (used by modules/payroll/staff-portal.php)
$staffBusinesses = [];
foreach (glob(BASE_PATH . '/config/businesses/*.php') ?: [] as $bizFilePath) {
    $bizCfg = require $bizFilePath;
    if (!empty($bizCfg['business_id'])) {
        $staffBusinesses[$bizCfg['business_id']] = $bizCfg['name'] ?? $bizCfg['business_id'];
    }
}
$staffLoginBgSettings = [];
if ($staffBusinesses) {
    $staffBgRows = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'staff_login_bg_%'");
    foreach ($staffBgRows as $row) {
        $bizIdFromKey = substr($row['setting_key'], strlen('staff_login_bg_'));
        $staffLoginBgSettings[$bizIdFromKey] = $row['setting_value'];
    }
}

$waSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'developer_whatsapp'");
$currentWA = $waSetting['setting_value'] ?? '';

$footerCopyrightSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'footer_copyright'");
$currentFooterCopyright = $footerCopyrightSetting['setting_value'] ?? '';

$footerVersionSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'footer_version'");
$currentFooterVersion = $footerVersionSetting['setting_value'] ?? '';

// Get demo credentials
$demoUsernameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'demo_username'");
$currentDemoUsername = $demoUsernameSetting['setting_value'] ?? 'admin';

$demoPasswordSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'demo_password'");
$currentDemoPassword = $demoPasswordSetting['setting_value'] ?? 'admin';

// Read current config
$configFile = BASE_PATH . '/config/config.php';
$configContent = file_get_contents($configFile);

// Extract current values
preg_match("/define\('DEVELOPER_NAME',\s*'([^']*)'\);/", $configContent, $nameMatch);
preg_match("/define\('DEVELOPER_LOGO',\s*'([^']*)'\);/", $configContent, $logoMatch);

$currentDevName = $nameMatch[1] ?? 'DevTeam Studio';
$currentDevLogo = $logoMatch[1] ?? 'assets/img/developer-logo.png';

// Handle form submission for name
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['developer_name'])) {
    $newName = trim($_POST['developer_name']);

    if (!empty($newName)) {
        $newConfigContent = preg_replace(
            "/define\('DEVELOPER_NAME',\s*'[^']*'\);/",
            "define('DEVELOPER_NAME', '" . addslashes($newName) . "');",
            $configContent
        );

        if (file_put_contents($configFile, $newConfigContent)) {
            $success = 'Nama developer berhasil diupdate!';
            $currentDevName = $newName;
            $configContent = $newConfigContent;
        } else {
            $error = 'Gagal update config file. Periksa permission folder.';
        }
    } else {
        $error = 'Nama developer tidak boleh kosong.';
    }
}

// Handle login background upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['login_background']) && $_FILES['login_background']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['login_background'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
    $maxSize = 2 * 1024 * 1024;

    if (!in_array($file['type'], $allowedTypes)) {
        $error = 'Tipe file tidak diizinkan. Gunakan JPG atau PNG.';
    } elseif ($file['size'] > $maxSize) {
        $error = 'Ukuran file terlalu besar. Maksimal 2MB.';
    } else {
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $localFilename = 'login-bg.' . $extension;

        $cloudinary = CloudinaryHelper::getInstance();
        $uploadResult = $cloudinary->smartUpload($file, 'uploads/backgrounds', $localFilename, 'backgrounds', 'login_background');

        if ($uploadResult['success']) {
            $storedValue = $uploadResult['path'];
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('login_background', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$storedValue, $storedValue]);
            $success = 'Background login berhasil diupload!' . ($uploadResult['is_cloud'] ? ' (Cloudinary)' : '');
            $currentLoginBg = $storedValue;
        } else {
            $error = 'Gagal upload file.';
        }
    }
}

// Handle login logo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['login_logo']) && $_FILES['login_logo']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['login_logo'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/svg+xml'];
    $maxSize = 1 * 1024 * 1024;

    if (!in_array($file['type'], $allowedTypes)) {
        $error = 'Tipe file tidak diizinkan. Gunakan JPG, PNG, atau SVG.';
    } elseif ($file['size'] > $maxSize) {
        $error = 'Ukuran file terlalu besar. Maksimal 1MB.';
    } else {
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $localFilename = 'login-logo.' . $extension;

        $cloudinary = CloudinaryHelper::getInstance();
        $uploadResult = $cloudinary->smartUpload($file, 'uploads/logos', $localFilename, 'logos', 'login_logo');

        if ($uploadResult['success']) {
            $storedValue = $uploadResult['path'];
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('login_logo', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$storedValue, $storedValue]);
            $success = 'Logo login berhasil diupload!' . ($uploadResult['is_cloud'] ? ' (Cloudinary)' : '');
            $currentLoginLogo = $storedValue;
        } else {
            $error = 'Gagal upload file.';
        }
    }
}

// Handle delete login logo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_login_logo'])) {
    // Delete from Cloudinary if it's a cloud URL
    if ($currentLoginLogo && strpos($currentLoginLogo, 'http') === 0) {
        $cl = CloudinaryHelper::getInstance();
        $cl->delete('adf_system/logos/login_logo');
    }
    // Also remove local files
    $uploadDir = BASE_PATH . '/uploads/logos/';
    foreach (glob($uploadDir . 'login-logo.*') as $oldFile) {
        unlink($oldFile);
    }
    $db->query("DELETE FROM settings WHERE setting_key = 'login_logo'");
    $success = 'Logo login berhasil dihapus!';
    $currentLoginLogo = null;
}

// Handle favicon upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['site_favicon']) && $_FILES['site_favicon']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['site_favicon'];
    $allowedTypes = ['image/x-icon', 'image/vnd.microsoft.icon', 'image/png', 'image/svg+xml'];
    $maxSize = 500 * 1024; // 500KB

    if (!in_array($file['type'], $allowedTypes)) {
        $error = 'Tipe file tidak diizinkan. Gunakan ICO, PNG, atau SVG.';
    } elseif ($file['size'] > $maxSize) {
        $error = 'Ukuran file terlalu besar. Maksimal 500KB.';
    } else {
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $localFilename = 'favicon.' . $extension;

        $cloudinary = CloudinaryHelper::getInstance();
        $uploadResult = $cloudinary->smartUpload($file, 'uploads/icons', $localFilename, 'icons', 'site_favicon');

        if ($uploadResult['success']) {
            $storedValue = $uploadResult['path'];
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('site_favicon', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$storedValue, $storedValue]);
            $success = 'Favicon berhasil diupload!' . ($uploadResult['is_cloud'] ? ' (Cloudinary)' : '');
            $currentFavicon = $storedValue;
        } else {
            $error = 'Gagal upload file.';
        }
    }
}

// Handle delete favicon
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_favicon'])) {
    // Delete from Cloudinary if it's a cloud URL
    if ($currentFavicon && strpos($currentFavicon, 'http') === 0) {
        $cl = CloudinaryHelper::getInstance();
        $cl->delete('adf_system/icons/site_favicon');
    }
    // Also remove local files
    $uploadDir = BASE_PATH . '/uploads/icons/';
    foreach (glob($uploadDir . 'favicon.*') as $oldFile) {
        unlink($oldFile);
    }
    $db->query("DELETE FROM settings WHERE setting_key = 'site_favicon'");
    $success = 'Favicon berhasil dihapus!';
    $currentFavicon = null;
}

// Handle PWA App Icon upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pwa_app_icon']) && $_FILES['pwa_app_icon']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['pwa_app_icon'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
    $maxSize = 2 * 1024 * 1024;

    if (!in_array($file['type'], $allowedTypes)) {
        $error = 'Tipe file tidak diizinkan. Gunakan JPG atau PNG.';
    } elseif ($file['size'] > $maxSize) {
        $error = 'Ukuran file terlalu besar. Maksimal 2MB.';
    } else {
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $localFilename = 'pwa-app-icon.' . $extension;

        $cloudinary = CloudinaryHelper::getInstance();
        $uploadResult = $cloudinary->smartUpload($file, 'uploads/icons', $localFilename, 'icons', 'pwa_app_icon');

        if ($uploadResult['success']) {
            $storedValue = $uploadResult['path'];
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('pwa_app_icon', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$storedValue, $storedValue]);
            $success = 'App icon PWA berhasil diupload!' . ($uploadResult['is_cloud'] ? ' (Cloudinary)' : '');
            $currentPwaIcon = $storedValue;
        } else {
            $error = 'Gagal upload file.';
        }
    }
}

// Handle delete PWA icon
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_pwa_icon'])) {
    if ($currentPwaIcon && strpos($currentPwaIcon, 'http') === 0) {
        $cl = CloudinaryHelper::getInstance();
        $cl->delete('adf_system/icons/pwa_app_icon');
    }
    $uploadDir = BASE_PATH . '/uploads/icons/';
    foreach (glob($uploadDir . 'pwa-app-icon.*') as $oldFile) {
        @unlink($oldFile);
    }
    $db->query("DELETE FROM settings WHERE setting_key = 'pwa_app_icon'");
    $success = 'App icon PWA berhasil dihapus! (akan kembali ke icon default)';
    $currentPwaIcon = null;
}

// Handle Staff Portal login background upload (per business)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['staff_login_bg']) && $_FILES['staff_login_bg']['error'] === UPLOAD_ERR_OK) {
    $staffBizId = trim($_POST['staff_biz_id'] ?? '');
    if (!isset($staffBusinesses[$staffBizId])) {
        $error = 'Bisnis tidak valid.';
    } else {
        $file = $_FILES['staff_login_bg'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        $maxSize = 3 * 1024 * 1024;

        if (!in_array($file['type'], $allowedTypes)) {
            $error = 'Tipe file tidak diizinkan. Gunakan JPG, PNG, atau WEBP.';
        } elseif ($file['size'] > $maxSize) {
            $error = 'Ukuran file terlalu besar. Maksimal 3MB.';
        } else {
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $localFilename = 'staff-login-bg-' . $staffBizId . '.' . $extension;
            $settingKey = 'staff_login_bg_' . $staffBizId;

            $cloudinary = CloudinaryHelper::getInstance();
            $uploadResult = $cloudinary->smartUpload($file, 'uploads/backgrounds', $localFilename, 'backgrounds', $settingKey);

            if ($uploadResult['success']) {
                $storedValue = $uploadResult['path'];
                $db->query("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$settingKey, $storedValue, $storedValue]);
                $success = 'Background login staff portal (' . htmlspecialchars($staffBusinesses[$staffBizId]) . ') berhasil diupload!' . ($uploadResult['is_cloud'] ? ' (Cloudinary)' : '');
                $staffLoginBgSettings[$staffBizId] = $storedValue;
            } else {
                $error = 'Gagal upload file.';
            }
        }
    }
}

// Handle delete Staff Portal login background (per business)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_staff_login_bg'])) {
    $staffBizId = trim($_POST['delete_staff_login_bg']);
    if (isset($staffBusinesses[$staffBizId])) {
        $existingVal = $staffLoginBgSettings[$staffBizId] ?? null;
        if ($existingVal && strpos($existingVal, 'http') === 0) {
            $cl = CloudinaryHelper::getInstance();
            $cl->delete('adf_system/backgrounds/staff_login_bg_' . $staffBizId);
        }
        $uploadDir = BASE_PATH . '/uploads/backgrounds/';
        foreach (glob($uploadDir . 'staff-login-bg-' . $staffBizId . '.*') as $oldFile) {
            @unlink($oldFile);
        }
        $db->query("DELETE FROM settings WHERE setting_key = ?", ['staff_login_bg_' . $staffBizId]);
        $success = 'Background login staff portal (' . htmlspecialchars($staffBusinesses[$staffBizId]) . ') berhasil dihapus!';
        unset($staffLoginBgSettings[$staffBizId]);
    }
}

// Handle WhatsApp number update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['whatsapp_number'])) {
    $waNumber = trim($_POST['whatsapp_number']);
    $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('developer_whatsapp', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$waNumber, $waNumber]);
    $success = 'Nomor WhatsApp berhasil disimpan!';
    $currentWA = $waNumber;
}

// Handle footer text update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['footer_copyright'])) {
    $copyright = trim($_POST['footer_copyright']);
    $version = trim($_POST['footer_version']);

    $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('footer_copyright', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$copyright, $copyright]);
    $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('footer_version', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$version, $version]);

    $success = 'Teks footer berhasil diupdate!';
    $currentFooterCopyright = $copyright;
    $currentFooterVersion = $version;
}

// Handle demo credentials update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['demo_username'])) {
    $demoUsername = trim($_POST['demo_username']);
    $demoPassword = trim($_POST['demo_password']);

    if (!empty($demoUsername) && !empty($demoPassword)) {
        $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('demo_username', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$demoUsername, $demoUsername]);
        $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('demo_password', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$demoPassword, $demoPassword]);

        $success = 'Demo credentials berhasil diupdate!';
        $currentDemoUsername = $demoUsername;
        $currentDemoPassword = $demoPassword;
    } else {
        $error = 'Username dan password tidak boleh kosong.';
    }
}

// Handle logo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['developer_logo']) && $_FILES['developer_logo']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['developer_logo'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/svg+xml'];
    $maxSize = 1 * 1024 * 1024;

    if (!in_array($file['type'], $allowedTypes)) {
        $error = 'Tipe file tidak diizinkan. Gunakan JPG, PNG, SVG, atau GIF.';
    } elseif ($file['size'] > $maxSize) {
        $error = 'Ukuran file terlalu besar. Maksimal 1MB.';
    } else {
        $uploadDir = BASE_PATH . '/assets/img/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'developer-logo.' . $extension;
        $uploadPath = $uploadDir . $filename;

        foreach (glob($uploadDir . 'developer-logo.*') as $oldFile) {
            unlink($oldFile);
        }

        if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
            $newLogoPath = 'assets/img/' . $filename;
            $newConfigContent = preg_replace(
                "/define\('DEVELOPER_LOGO',\s*'[^']*'\);/",
                "define('DEVELOPER_LOGO', '" . $newLogoPath . "');",
                $configContent
            );

            if (file_put_contents($configFile, $newConfigContent)) {
                $success = 'Logo developer berhasil diupload!';
                $currentDevLogo = $newLogoPath;
            } else {
                $error = 'File uploaded tapi gagal update config.';
            }
        } else {
            $error = 'Gagal upload file.';
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* Pengaturan & Branding — tata letak ringkas (hanya untuk halaman ini) */
    .ds-section { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--dev-muted); margin: 18px 0 8px; }
    .ds-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .ds-grid-5 { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; }
    .ds-card { background: #fff; border: 1px solid var(--dev-border); border-radius: 10px; padding: 12px 14px; }
    .ds-card h6 { margin: 0; font-size: 12.5px; font-weight: 600; display: flex; align-items: center; gap: 6px; }
    .ds-card h6 i { color: var(--dev-primary); font-size: 13px; }
    .ds-card .hint { font-size: 11px; color: var(--dev-muted); margin: 2px 0 8px; }
    .ds-inline { display: flex; gap: 6px; }
    .ds-inline .form-control { height: 30px; padding: 4px 9px; font-size: 12px; }
    .ds-inline .btn { white-space: nowrap; }
    .ds-two { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 6px; }
    .ds-two label { font-size: 11px; color: var(--dev-muted); margin-bottom: 2px; display: block; }
    .ds-two .form-control { height: 30px; padding: 4px 9px; font-size: 12px; }
    .ds-preview-line { margin-top: 8px; font-size: 11px; color: var(--dev-muted); }

    /* Kartu gambar/ikon */
    .ds-asset { display: flex; flex-direction: column; }
    .ds-thumb { height: 64px; border-radius: 8px; background: var(--dev-light); border: 1px solid var(--dev-border); display: grid; place-items: center; overflow: hidden; margin-bottom: 8px; }
    .ds-thumb img { max-height: 52px; max-width: 80%; object-fit: contain; }
    .ds-thumb img.cover { width: 100%; height: 100%; max-width: none; max-height: none; object-fit: cover; }
    .ds-thumb .ph { font-size: 20px; color: #b6bcc9; }
    .ds-asset .ds-actions { display: flex; gap: 6px; margin-top: auto; }
    .ds-asset input[type="file"] { font-size: 11px; padding: 3px 6px; height: 28px; }
    .ds-asset .btn { padding: 3px 9px; font-size: 11.5px; }
    .ds-del { font-size: 11px; color: var(--dev-danger); background: none; border: 0; padding: 0; margin-top: 6px; }

    /* Background Staff Portal per bisnis */
    .ds-biz { display: flex; align-items: center; gap: 10px; padding: 7px 0; border-bottom: 1px solid #f0f1f5; }
    .ds-biz:last-of-type { border-bottom: 0; }
    .ds-biz .t { width: 44px; height: 30px; border-radius: 6px; overflow: hidden; flex-shrink: 0; background: var(--dev-light); border: 1px solid var(--dev-border); display: grid; place-items: center; color: #b6bcc9; }
    .ds-biz .t img { width: 100%; height: 100%; object-fit: cover; }
    .ds-biz .n { flex: 1; min-width: 0; font-size: 12px; font-weight: 600; }
    .ds-biz .n small { display: block; font-weight: 400; font-size: 10.5px; color: var(--dev-muted); }
    .ds-biz form { display: flex; gap: 6px; align-items: center; margin: 0; }
    .ds-biz input[type="file"] { font-size: 11px; padding: 3px 6px; height: 28px; width: 210px; }
    .ds-biz .btn { padding: 3px 9px; font-size: 11.5px; }

    @media (max-width: 1200px) { .ds-grid-5 { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (max-width: 768px) { .ds-grid, .ds-grid-5 { grid-template-columns: 1fr 1fr; } .ds-biz { flex-wrap: wrap; } .ds-biz input[type="file"] { width: 100%; } }
</style>

<div class="container-fluid py-4">
    <div class="page-head">
        <p>Identitas developer, gambar login, ikon aplikasi, dan teks footer. Perubahan langsung dipakai di semua bisnis.</p>
        <div class="actions">
            <a href="settings.php" class="btn btn-outline-secondary btn-sm" title="Editor semua setting sistem (key/value)"><i class="bi bi-gear me-1"></i>Pengaturan Lanjutan</a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ── Identitas ─────────────────────────────── -->
    <div class="ds-section" style="margin-top:0;">Identitas</div>
    <div class="ds-grid">
        <div class="ds-card">
            <h6><i class="bi bi-person-badge"></i>Nama Developer</h6>
            <div class="hint">Tampil di footer sidebar semua bisnis.</div>
            <form method="POST" class="ds-inline">
                <input type="text" name="developer_name" class="form-control" value="<?php echo htmlspecialchars($currentDevName); ?>" required maxlength="50" placeholder="DevTeam Studio">
                <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
            </form>
        </div>

        <div class="ds-card">
            <h6><i class="bi bi-whatsapp"></i>WhatsApp Developer</h6>
            <div class="hint">Format 628xxx (tanpa + dan spasi). Dipakai untuk notifikasi &amp; tombol hubungi developer.</div>
            <form method="POST" class="ds-inline">
                <input type="text" name="whatsapp_number" class="form-control" value="<?php echo htmlspecialchars($currentWA); ?>" placeholder="628123456789" required>
                <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
            </form>
        </div>

        <div class="ds-card">
            <h6><i class="bi bi-card-text"></i>Teks Footer</h6>
            <div class="hint">Kosongkan untuk memakai teks bawaan.</div>
            <form method="POST">
                <div class="ds-two">
                    <div>
                        <label>Copyright</label>
                        <input type="text" name="footer_copyright" class="form-control" maxlength="100"
                            value="<?php echo htmlspecialchars($currentFooterCopyright ?: '© ' . APP_YEAR . ' ' . APP_NAME . '. All rights reserved.'); ?>">
                    </div>
                    <div>
                        <label>Versi</label>
                        <input type="text" name="footer_version" class="form-control" maxlength="50"
                            value="<?php echo htmlspecialchars($currentFooterVersion ?: 'Version ' . APP_VERSION); ?>">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Simpan Footer</button>
            </form>
        </div>

        <div class="ds-card">
            <h6><i class="bi bi-key"></i>Akun Demo di Halaman Login</h6>
            <div class="hint">Ditampilkan di halaman login; pengunjung bisa klik untuk mengisi otomatis.</div>
            <form method="POST">
                <div class="ds-two">
                    <div>
                        <label>Username</label>
                        <input type="text" name="demo_username" class="form-control" value="<?php echo htmlspecialchars($currentDemoUsername); ?>" required maxlength="50">
                    </div>
                    <div>
                        <label>Password</label>
                        <input type="text" name="demo_password" class="form-control" value="<?php echo htmlspecialchars($currentDemoPassword); ?>" required maxlength="50">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Simpan Akun Demo</button>
            </form>
        </div>
    </div>

    <!-- ── Gambar & Ikon ─────────────────────────────── -->
    <?php
    $cl = CloudinaryHelper::getInstance();
    $logoFullPath = BASE_PATH . '/' . $currentDevLogo;
    $devLogoUrl = file_exists($logoFullPath) ? BASE_URL . '/' . $currentDevLogo . '?v=' . filemtime($logoFullPath) : null;
    $loginBgUrl = $currentLoginBg ? $cl->getDisplayUrl($currentLoginBg, 'uploads/backgrounds/') : null;
    $loginLogoUrl = $currentLoginLogo ? $cl->getDisplayUrl($currentLoginLogo, 'uploads/logos/') : null;
    $faviconUrl = $currentFavicon ? $cl->getDisplayUrl($currentFavicon, 'uploads/icons/') : null;
    $pwaIconUrl = $currentPwaIcon ? $cl->getDisplayUrl($currentPwaIcon, 'uploads/icons/') : null;
    // [judul, petunjuk, nama input file, accept, url preview, gambar cover?, nama field hapus (atau null), ikon placeholder]
    $assets = [
        ['Logo Developer', 'Persegi ±100×100 · maks 1MB', 'developer_logo', 'image/*', $devLogoUrl, false, null, 'code-slash'],
        ['Logo Halaman Login', 'Persegi ±100×100 · maks 1MB', 'login_logo', 'image/*', $loginLogoUrl, false, 'delete_login_logo', 'building'],
        ['Background Login', '1920×1080 · JPG/PNG · maks 2MB', 'login_background', 'image/*', $loginBgUrl, true, null, 'image'],
        ['Favicon (tab browser)', '32 / 64px · ICO/PNG/SVG · maks 500KB', 'site_favicon', '.ico,.png,.svg,image/x-icon,image/png,image/svg+xml', $faviconUrl, false, 'delete_favicon', 'window'],
        ['Ikon Aplikasi (PWA)', '512×512 · PNG/JPG · maks 2MB', 'pwa_app_icon', '.jpg,.jpeg,.png,image/jpeg,image/png', $pwaIconUrl ?: '../modules/payroll/absen-icon.php?size=192', false, $pwaIconUrl ? 'delete_pwa_icon' : null, 'phone'],
    ];
    ?>
    <div class="ds-section">Gambar &amp; Ikon</div>
    <div class="ds-grid-5">
        <?php foreach ($assets as [$title, $hint, $field, $accept, $url, $cover, $deleteField, $icon]): ?>
            <div class="ds-card ds-asset">
                <div class="ds-thumb">
                    <?php if ($url): ?>
                        <img src="<?php echo htmlspecialchars($url) . (strpos($url, '?') === false ? '?v=' . time() : ''); ?>" alt="" class="<?php echo $cover ? 'cover' : ''; ?>">
                    <?php else: ?>
                        <i class="bi bi-<?php echo $icon; ?> ph"></i>
                    <?php endif; ?>
                </div>
                <h6><?php echo $title; ?></h6>
                <div class="hint"><?php echo $hint; ?></div>
                <form method="POST" enctype="multipart/form-data" class="ds-actions">
                    <!-- Pilih file langsung mengunggah -->
                    <label class="btn btn-outline-primary btn-sm w-100 mb-0">
                        <i class="bi bi-upload me-1"></i><?php echo $url ? 'Ganti' : 'Upload'; ?>
                        <input type="file" name="<?php echo $field; ?>" accept="<?php echo $accept; ?>" hidden onchange="this.form.submit()">
                    </label>
                </form>
                <?php if ($deleteField && $url): ?>
                    <form method="POST" onsubmit="return confirm('Hapus <?php echo $title; ?> dan kembali ke bawaan?');">
                        <input type="hidden" name="<?php echo $deleteField; ?>" value="1">
                        <button type="submit" class="ds-del"><i class="bi bi-trash me-1"></i>Hapus</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Background login Staff Portal per bisnis ─────────────────────────────── -->
    <div class="ds-section">Background Login Staff Portal (per bisnis)</div>
    <div class="ds-card">
        <?php if (!$staffBusinesses): ?>
            <div class="hint" style="margin:0;">Belum ada bisnis terdaftar di <code>config/businesses/</code>.</div>
        <?php else: ?>
            <?php foreach ($staffBusinesses as $bizId => $bizName):
                $bizBgStored = $staffLoginBgSettings[$bizId] ?? null;
                $bizBgUrl = $bizBgStored ? $cl->getDisplayUrl($bizBgStored, 'uploads/backgrounds/') : null;
            ?>
                <div class="ds-biz">
                    <div class="t">
                        <?php if ($bizBgUrl): ?>
                            <img src="<?php echo htmlspecialchars($bizBgUrl); ?>" alt="">
                        <?php else: ?>
                            <i class="bi bi-image"></i>
                        <?php endif; ?>
                    </div>
                    <div class="n">
                        <?php echo htmlspecialchars($bizName); ?>
                        <small><?php echo $bizBgUrl ? 'Background khusus aktif' : 'Pakai gradasi bawaan'; ?> · 1080×1920 (potret) · maks 3MB</small>
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="staff_biz_id" value="<?php echo htmlspecialchars($bizId); ?>">
                        <label class="btn btn-outline-primary btn-sm mb-0">
                            <i class="bi bi-upload me-1"></i><?php echo $bizBgUrl ? 'Ganti' : 'Upload'; ?>
                            <input type="file" name="staff_login_bg" accept="image/*" hidden onchange="this.form.submit()">
                        </label>
                    </form>
                    <?php if ($bizBgUrl): ?>
                        <form method="POST" onsubmit="return confirm('Hapus background staff portal untuk <?php echo htmlspecialchars(addslashes($bizName)); ?>?');">
                            <input type="hidden" name="delete_staff_login_bg" value="<?php echo htmlspecialchars($bizId); ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Hapus"><i class="bi bi-trash"></i></button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <p class="ds-preview-line">
        <i class="bi bi-info-circle me-1"></i>Nama &amp; logo developer disimpan ke <code>config/config.php</code> (konstanta <code>DEVELOPER_NAME</code> / <code>DEVELOPER_LOGO</code>) — file itu harus bisa ditulis di hosting.
        Logo saat ini: <code><?php echo htmlspecialchars($currentDevLogo); ?></code> <?php echo $devLogoUrl ? '<span class="text-success">✓ ada</span>' : '<span class="text-danger">✗ tidak ditemukan (pakai bawaan)</span>'; ?>
    </p>
</div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>