<?php
// Load helper functions
require_once __DIR__ . '/functions.php';

// Load language system
require_once __DIR__ . '/language.php';

// Load motor notification system
require_once __DIR__ . '/MotorNotificationHelper.php';

// Load unpaid checked-in guest notification system
require_once __DIR__ . '/UnpaidGuestNotificationHelper.php';

// Sunsea (and any business copied from it) must use its own custom UI/module stack.
// Driven by config's enabled_modules (not the literal 'sunsea' id) so a "Copy Business"
// of Sunsea also gets this custom UI under its own id/slug.
$__isSunseaVariant = defined('ACTIVE_BUSINESS_ID') && ACTIVE_BUSINESS_ID === 'sunsea';
if (!$__isSunseaVariant && function_exists('getActiveBusinessConfig')) {
    $__activeBizCfg = getActiveBusinessConfig();
    $__isSunseaVariant = in_array('sunsea', $__activeBizCfg['enabled_modules'] ?? [], true);
}
if ($__isSunseaVariant) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $isSunseaModule = (strpos($requestUri, '/modules/sunsea/') !== false);
    $isAllowedPath =
        (strpos($requestUri, '/logout.php') !== false) ||
        (strpos($requestUri, '/select-business.php') !== false) ||
        (strpos($requestUri, '/developer/') !== false) ||
        (strpos($requestUri, '/api/') !== false) ||
        (strpos($requestUri, '/modules/email/') !== false);

    if (!$isSunseaModule && !$isAllowedPath) {
        header('Location: ' . BASE_URL . '/modules/sunsea/dashboard.php');
        exit;
    }
}

/**
 * Get relative avatar path for a user if one exists.
 */
if (!function_exists('adfGetUserAvatarRelativePath')) {
    function adfGetUserAvatarRelativePath($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return null;
        }

        $avatarDir = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__)) . '/uploads/avatars';
        $patterns = [
            $avatarDir . '/user_' . $userId . '.jpg',
            $avatarDir . '/user_' . $userId . '.jpeg',
            $avatarDir . '/user_' . $userId . '.png',
            $avatarDir . '/user_' . $userId . '.webp',
            $avatarDir . '/user_' . $userId . '.gif',
        ];

        foreach ($patterns as $candidate) {
            if (is_file($candidate)) {
                return 'uploads/avatars/' . basename($candidate);
            }
        }

        return null;
    }
}

/**
 * Build avatar URL with cache busting.
 */
if (!function_exists('adfGetUserAvatarUrl')) {
    function adfGetUserAvatarUrl($userId)
    {
        $relative = adfGetUserAvatarRelativePath($userId);
        if (!$relative) {
            return null;
        }

        $absolute = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__)) . '/' . $relative;
        $version = is_file($absolute) ? filemtime($absolute) : time();
        return BASE_URL . '/' . $relative . '?v=' . $version;
    }
}

// Handle topbar avatar upload before any HTML output.
if (isset($_POST['__upload_topbar_avatar']) && $_POST['__upload_topbar_avatar'] === '1' && isset($_SESSION['user_id'])) {
    error_log('AVATAR_UPLOAD_START: User=' . $_SESSION['user_id'] . ' POST keys=' . json_encode(array_keys($_POST)) . ' FILES keys=' . json_encode(array_keys($_FILES)));
    $redirectBack = $_SERVER['REQUEST_URI'] ?? (BASE_URL . '/index.php');

    try {
        if (!isset($_FILES['avatar_file']) || !is_array($_FILES['avatar_file'])) {
            throw new Exception('File avatar tidak ditemukan.');
        }

        $file = $_FILES['avatar_file'];
        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $errorMap = [
                UPLOAD_ERR_INI_SIZE => 'Ukuran foto melebihi batas server. Coba file lebih kecil (disarankan <= 2MB).',
                UPLOAD_ERR_FORM_SIZE => 'Ukuran foto terlalu besar untuk form upload.',
                UPLOAD_ERR_PARTIAL => 'Upload terputus. Silakan ulangi upload.',
                UPLOAD_ERR_NO_FILE => 'Belum ada file yang dipilih.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server upload error: folder sementara tidak tersedia. Debug: tmp_name=' . ($file['tmp_name'] ?? 'NONE'),
                UPLOAD_ERR_CANT_WRITE => 'Server upload error: gagal menulis file.',
                UPLOAD_ERR_EXTENSION => 'Upload diblokir oleh ekstensi server.',
            ];
            $msg = $errorMap[$uploadError] ?? ('Upload gagal (kode: ' . $uploadError . ').');
            error_log('AVATAR_UPLOAD_ERR: ' . $msg . ' | FILES: ' . json_encode($file));
            throw new Exception($msg);
        }

        $tmpPath = $file['tmp_name'] ?? '';
        if ($tmpPath === '' || (!is_uploaded_file($tmpPath) && !is_file($tmpPath))) {
            throw new Exception('File upload tidak valid.');
        }

        $maxSize = 3 * 1024 * 1024;
        if (($file['size'] ?? 0) > $maxSize) {
            throw new Exception('Ukuran foto maksimal 3MB.');
        }

        $mimeType = function_exists('mime_content_type') ? mime_content_type($tmpPath) : null;
        if (!$mimeType && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mimeType = finfo_file($finfo, $tmpPath) ?: null;
                finfo_close($finfo);
            }
        }
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        $imageInfo = @getimagesize($tmpPath);
        if (!$imageInfo) {
            throw new Exception('File bukan gambar yang valid.');
        }

        $imageType = $imageInfo[2] ?? null;
        $imageTypeToExt = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_GIF => 'gif',
        ];

        $detectedExt = $imageType !== null && isset($imageTypeToExt[$imageType])
            ? $imageTypeToExt[$imageType]
            : null;

        $finalExt = $allowed[$mimeType] ?? $detectedExt;
        if (!$finalExt) {
            throw new Exception('Format foto harus JPG, PNG, WEBP, atau GIF.');
        }

        $userId = (int)$_SESSION['user_id'];
        $avatarDir = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__)) . '/uploads/avatars';
        if (!is_dir($avatarDir)) {
            if (!@mkdir($avatarDir, 0777, true)) {
                throw new Exception('Server tidak bisa membuat folder avatar. Hubungi admin server.');
            }
            @chmod($avatarDir, 0777);
        }
        if (!is_writable($avatarDir)) {
            throw new Exception('Folder avatar tidak bisa ditulis. Hubungi admin server untuk fix permission.');
        }

        foreach (glob($avatarDir . '/user_' . $userId . '.*') ?: [] as $oldAvatar) {
            @unlink($oldAvatar);
        }

        $targetName = 'user_' . $userId . '.' . $finalExt;
        $targetPath = $avatarDir . '/' . $targetName;
        $fileRelativePath = '/uploads/avatars/' . $targetName;

        if (!move_uploaded_file($tmpPath, $targetPath)) {
            throw new Exception('Gagal menyimpan foto profil.');
        }

        @chmod($targetPath, 0644);

        // Simpan metadata avatar ke database jika tersedia
        if (function_exists('getDBConnection')) {
            try {
                $db = getDBConnection();
                $fileSize = filesize($targetPath);
                $stmt = $db->prepare('
                    INSERT INTO user_avatars (user_id, file_name, file_type, file_size, file_path)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        file_name = VALUES(file_name),
                        file_type = VALUES(file_type),
                        file_size = VALUES(file_size),
                        file_path = VALUES(file_path),
                        updated_at = CURRENT_TIMESTAMP
                ');
                if ($stmt) {
                    $stmt->bind_param('issss', $userId, $targetName, $finalExt, $fileSize, $fileRelativePath);
                    $stmt->execute();
                    $stmt->close();
                }
            } catch (Exception $dbEx) {
                error_log('AVATAR_DB_INSERT: ' . $dbEx->getMessage());
                // Tidak critical jika database insert gagal, file sudah tersimpan
            }
        }

        if (function_exists('setFlash')) {
            setFlash('success', 'Foto profil berhasil diperbarui.');
        }
    } catch (Exception $e) {
        if (function_exists('setFlash')) {
            setFlash('error', $e->getMessage());
        }
    }

    header('Location: ' . $redirectBack);
    exit;
}

// Favicon: always use the ADF System logo (business icon must never override it)
$faviconUrl = BASE_URL . '/assets/img/developer-logo.png';
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['user_language'] ?? 'id'; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo APP_NAME; ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="500x500" href="<?php echo $faviconUrl; ?>?v=<?php echo time(); ?>">
    <link rel="shortcut icon" href="<?php echo $faviconUrl; ?>?v=<?php echo time(); ?>">
    <link rel="apple-touch-icon" sizes="500x500" href="<?php echo $faviconUrl; ?>?v=<?php echo time(); ?>">

    <!-- PWA Manifest: wajib supaya "Add to Home Screen" jadi app standalone (prasyarat App Badging API) -->
    <link rel="manifest" href="<?php echo BASE_URL; ?>/manifest.php">
    <meta name="theme-color" content="#0f172a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <!-- Main CSS with Cache Busting -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css?v=<?php echo time(); ?>">
    <?php if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/modules/frontdesk/') !== false): ?>
        <!-- Gaya bersama halaman Front Desk (seragam, dua tema) -->
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/frontdesk.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/frontdesk.css') ?: 1; ?>">
    <?php endif; ?>

    <!-- Global Loading Indicator (progress bar + overlay) -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/global-loader.css?v=<?php echo time(); ?>">

    <!-- Icons (Feather Icons) -->
    <script src="https://unpkg.com/feather-icons"></script>

    <!-- Additional CSS -->
    <?php if (isset($additionalCSS)): ?>
        <?php foreach ($additionalCSS as $css): ?>
            <link rel="stylesheet" href="<?php echo BASE_URL . '/' . $css; ?>">
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Inline Styles -->
    <?php if (isset($inlineStyles)): ?>
        <?php echo $inlineStyles; ?>
    <?php endif; ?>

    <!-- Business Theme CSS -->
    <style>
        <?php echo getBusinessThemeCSS(); ?> :root {
            --system-font-base: 0.875rem;
            --system-font-sm: 0.813rem;
            --system-font-xs: 0.75rem;
            --system-heading-lg: 1rem;
            --system-heading-md: 0.9375rem;
            --system-heading-sm: 0.875rem;
        }

        body[data-business] .main-content,
        body[data-business] .main-content .card,
        body[data-business] .main-content .top-bar,
        body[data-business] .main-content .table-container,
        body[data-business] .main-content .modal-content,
        body[data-business] .main-content .page-header,
        body[data-business] .main-content .content-grid {
            font-size: var(--system-font-base) !important;
        }

        body[data-business] .main-content :is(p,
            label,
            input,
            select,
            textarea,
            button,
            li,
            td,
            th,
            a,
            .btn,
            .form-label,
            .form-control,
            .table,
            .table td,
            .table th,
            .alert,
            .page-subtitle,
            .empty-state-text,
            .filter-chip,
            .stat-lbl) {
            font-size: var(--system-font-base) !important;
        }

        body[data-business] .main-content :is(small,
            .small,
            .text-muted,
            .card-title,
            .badge,
            .helper-text,
            .form-text,
            .table small,
            .meta-text,
            .stat-note) {
            font-size: var(--system-font-sm) !important;
        }

        body[data-business] .main-content :is(h1, .page-title) {
            font-size: var(--system-heading-lg) !important;
            line-height: 1.35;
        }

        body[data-business] .main-content :is(h2, h3, .section-title, .card h2, .card h3) {
            font-size: var(--system-heading-md) !important;
            line-height: 1.4;
        }

        body[data-business] .main-content :is(h4, h5, h6) {
            font-size: var(--system-heading-sm) !important;
            line-height: 1.4;
        }

        body[data-business] .main-content .btn-sm,
        body[data-business] .main-content .table .btn-sm {
            font-size: var(--system-font-sm) !important;
        }

        body[data-business] .main-content input::placeholder,
        body[data-business] .main-content textarea::placeholder,
        body[data-business] .main-content select,
        body[data-business] .main-content option {
            font-size: var(--system-font-base) !important;
        }

        .user-avatar-wrap {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .user-avatar-button {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            border: 2px solid #bfdbfe;
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.25);
            overflow: hidden;
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            color: #1e3a8a;
            font-weight: 700;
            font-size: 0.95rem;
            line-height: 1;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .user-avatar-button:hover {
            transform: translateY(-1px) scale(1.02);
            box-shadow: 0 6px 16px rgba(30, 58, 138, 0.32);
        }

        .user-avatar-button:focus {
            outline: 2px solid #60a5fa;
            outline-offset: 2px;
        }

        .user-avatar-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .user-avatar-edit-indicator {
            position: absolute;
            right: -2px;
            bottom: -2px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #1d4ed8;
            color: #ffffff;
            border: 2px solid #ffffff;
            font-size: 0.72rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }

        body[data-theme="dark"] .user-avatar-button {
            border-color: #1e40af;
            box-shadow: 0 5px 14px rgba(15, 23, 42, 0.45);
        }

        body[data-theme="dark"] .user-avatar-edit-indicator {
            border-color: #0f172a;
        }

        @keyframes bellShake {
            0% {
                transform: rotate(0)
            }

            15% {
                transform: rotate(14deg)
            }

            30% {
                transform: rotate(-14deg)
            }

            45% {
                transform: rotate(10deg)
            }

            60% {
                transform: rotate(-6deg)
            }

            75% {
                transform: rotate(2deg)
            }

            100% {
                transform: rotate(0)
            }
        }
    </style>
</head>
<?php
// Load user theme from database per business (reliable method)
// Warehouse/gudang businesses default to light; others default to dark
$userTheme = (defined('BUSINESS_TYPE') && BUSINESS_TYPE === 'warehouse') ? 'light' : 'dark';
$themeError = null;

if (isset($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . '/../config/database.php';
        $db = Database::getInstance();

        // Load theme for current business and user
        $themeResult = $db->fetchOne(
            "SELECT theme FROM user_preferences WHERE user_id = ? AND branch_id = ? LIMIT 1",
            [$_SESSION['user_id'], ACTIVE_BUSINESS_ID]
        );

        if ($themeResult && !empty($themeResult['theme'])) {
            $userTheme = $themeResult['theme'];
        }
        // For non-warehouse: fallback to any saved preference
        // For warehouse: keep the light default (don't inherit dark from other businesses)
        elseif (!defined('BUSINESS_TYPE') || BUSINESS_TYPE !== 'warehouse') {
            $fallbackTheme = $db->fetchOne(
                "SELECT theme FROM user_preferences WHERE user_id = ? LIMIT 1",
                [$_SESSION['user_id']]
            );

            if ($fallbackTheme && !empty($fallbackTheme['theme'])) {
                $userTheme = $fallbackTheme['theme'];
            }
        }
    } catch (Exception $e) {
        $themeError = $e->getMessage();
        // keep the default set above
    }
}

if (isset($forceTheme) && is_string($forceTheme)) {
    $forceTheme = strtolower(trim($forceTheme));
    if (in_array($forceTheme, ['light', 'dark'], true)) {
        $userTheme = $forceTheme;
    }
}
?>

<body data-theme="<?php echo htmlspecialchars($userTheme); ?>" data-business="<?php echo ACTIVE_BUSINESS_ID; ?>" data-business-type="<?php echo BUSINESS_TYPE; ?>">
    <?php if ($themeError): ?>
        <!-- Theme Load Warning: <?php echo htmlspecialchars($themeError); ?> -->
    <?php endif; ?>

    <!-- Elegant Confirm Modal (replaces native window.confirm popups) -->
    <div id="adfConfirmOverlay" class="adf-confirm-overlay">
        <div class="adf-confirm-box">
            <div class="adf-confirm-icon" id="adfConfirmIcon">
                <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M23 4v6h-6"></path>
                    <path d="M1 20v-6h6"></path>
                    <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                </svg>
            </div>
            <h3 class="adf-confirm-title" id="adfConfirmTitle">Switch Business?</h3>
            <p class="adf-confirm-message" id="adfConfirmMessage">Current page will reload.</p>
            <div class="adf-confirm-actions">
                <button type="button" class="adf-confirm-btn adf-confirm-cancel" id="adfConfirmCancelBtn">Batal</button>
                <button type="button" class="adf-confirm-btn adf-confirm-ok" id="adfConfirmOkBtn">Ya, Ganti</button>
            </div>
        </div>
    </div>
    <style>
        /* Popup konfirmasi bergaya kaca (glass): latar diburamkan, kartu transparan dengan tepi bercahaya. */
        .adf-confirm-overlay {
            position: fixed;
            inset: 0;
            z-index: 999999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: radial-gradient(circle at 50% 40%, rgba(30, 41, 59, 0.18), rgba(15, 23, 42, 0.42));
            backdrop-filter: blur(10px) saturate(140%);
            -webkit-backdrop-filter: blur(10px) saturate(140%);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s ease, visibility 0.25s ease;
        }

        .adf-confirm-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .adf-confirm-box {
            position: relative;
            width: 100%;
            max-width: 370px;
            padding: 2rem 1.6rem 1.35rem;
            text-align: center;
            border-radius: 24px;
            background: linear-gradient(160deg, rgba(255, 255, 255, 0.16), rgba(255, 255, 255, 0.05));
            border: 1px solid rgba(255, 255, 255, 0.22);
            backdrop-filter: blur(28px) saturate(170%);
            -webkit-backdrop-filter: blur(28px) saturate(170%);
            box-shadow: 0 30px 70px -20px rgba(2, 6, 23, 0.6), inset 0 1px 0 rgba(255, 255, 255, 0.28);
            transform: translateY(14px) scale(0.96);
            transition: transform 0.3s cubic-bezier(0.34, 1.4, 0.64, 1);
            overflow: hidden;
        }

        /* Kilau kaca di bagian atas kartu */
        .adf-confirm-box::before {
            content: '';
            position: absolute;
            inset: 0 0 auto 0;
            height: 55%;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.14), transparent);
            pointer-events: none;
        }

        body[data-theme="light"] .adf-confirm-box {
            background: linear-gradient(160deg, rgba(255, 255, 255, 0.82), rgba(255, 255, 255, 0.6));
            border-color: rgba(255, 255, 255, 0.9);
            box-shadow: 0 30px 70px -20px rgba(15, 23, 42, 0.35), inset 0 1px 0 #fff, 0 0 0 1px rgba(15, 23, 42, 0.05);
        }

        .adf-confirm-overlay.active .adf-confirm-box {
            transform: translateY(0) scale(1);
        }

        .adf-confirm-icon {
            position: relative;
            width: 54px;
            height: 54px;
            margin: 0 auto 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 16px;
            background: linear-gradient(145deg, rgba(99, 102, 241, 0.9), rgba(37, 99, 235, 0.9));
            color: #fff;
            box-shadow: 0 12px 24px -10px rgba(37, 99, 235, 0.7), inset 0 1px 0 rgba(255, 255, 255, 0.35);
        }

        /* Switch Business: logo bisnis tujuan di bingkai kaca. object-fit: contain supaya logo bentuk
           apa pun (bulat, kotak, lebar) tampil utuh dan presisi di tengah. */
        .adf-confirm-icon.adf-confirm-logo {
            box-sizing: border-box;
            width: 78px;
            height: 78px;
            margin-bottom: 1.1rem;
            padding: 7px;
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid rgba(255, 255, 255, 0.7);
            box-shadow: 0 16px 34px -14px rgba(2, 6, 23, 0.55), 0 0 0 6px rgba(255, 255, 255, 0.08);
            overflow: hidden;
            animation: adfLogoPop 0.45s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .adf-confirm-icon.adf-confirm-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            object-position: center;
            display: block;
        }

        .adf-confirm-icon.adf-confirm-logo .adf-confirm-logo-fallback {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1e3a8a;
            letter-spacing: 0.02em;
            line-height: 1;
        }

        @keyframes adfLogoPop {
            from {
                transform: scale(0.6);
                opacity: 0;
            }

            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        .adf-confirm-title {
            position: relative;
            margin: 0 0 0.4rem;
            font-size: 1.12rem;
            font-weight: 700;
            letter-spacing: -0.01em;
            color: var(--text-heading);
        }

        .adf-confirm-message {
            position: relative;
            margin: 0 0 1.4rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .adf-confirm-actions {
            position: relative;
            display: flex;
            gap: 0.65rem;
        }

        .adf-confirm-btn {
            flex: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            padding: 0.7rem 1rem;
            font-size: 0.86rem;
            font-weight: 600;
            border-radius: 14px;
            cursor: pointer;
            transition: transform 0.15s ease, background 0.2s ease, box-shadow 0.2s ease;
            font-family: inherit;
        }

        .adf-confirm-btn:active {
            transform: scale(0.97);
        }

        .adf-confirm-cancel {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: var(--text-primary);
        }

        body[data-theme="light"] .adf-confirm-cancel {
            background: rgba(255, 255, 255, 0.7);
            border-color: rgba(15, 23, 42, 0.1);
        }

        .adf-confirm-cancel:hover {
            background: rgba(255, 255, 255, 0.18);
        }

        body[data-theme="light"] .adf-confirm-cancel:hover {
            background: #fff;
        }

        .adf-confirm-ok {
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.95), rgba(37, 99, 235, 0.95));
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: #fff;
            box-shadow: 0 12px 26px -12px rgba(37, 99, 235, 0.8), inset 0 1px 0 rgba(255, 255, 255, 0.3);
        }

        .adf-confirm-ok:hover {
            box-shadow: 0 16px 30px -12px rgba(37, 99, 235, 0.95), inset 0 1px 0 rgba(255, 255, 255, 0.35);
        }

        .adf-confirm-btn:focus-visible {
            outline: 2px solid rgba(99, 102, 241, 0.7);
            outline-offset: 2px;
        }

    </style>
    <script>
        // opts.logo (URL logo perusahaan) / opts.fallbackText (inisial nama bisnis bila logo belum ada): ganti ikon default.
        function adfConfirm(message, title, opts) {
            opts = opts || {};
            return new Promise((resolve) => {
                const overlay = document.getElementById('adfConfirmOverlay');
                const okBtn = document.getElementById('adfConfirmOkBtn');
                const cancelBtn = document.getElementById('adfConfirmCancelBtn');
                const iconEl = document.getElementById('adfConfirmIcon');
                if (!iconEl.dataset.defaultIcon) iconEl.dataset.defaultIcon = iconEl.innerHTML;
                iconEl.classList.remove('adf-confirm-logo');
                iconEl.innerHTML = iconEl.dataset.defaultIcon;
                if (opts.logo || opts.fallbackText) {
                    iconEl.classList.add('adf-confirm-logo');
                    iconEl.innerHTML = '';
                    if (opts.logo) {
                        const img = document.createElement('img');
                        img.src = opts.logo;
                        img.alt = opts.logoAlt || '';
                        img.onerror = function() {
                            iconEl.innerHTML = '<span class="adf-confirm-logo-fallback"></span>';
                            iconEl.firstChild.textContent = opts.fallbackText || '';
                        };
                        iconEl.appendChild(img);
                    } else {
                        iconEl.innerHTML = '<span class="adf-confirm-logo-fallback"></span>';
                        iconEl.firstChild.textContent = opts.fallbackText;
                    }
                }
                document.getElementById('adfConfirmMessage').textContent = message || 'Are you sure?';
                document.getElementById('adfConfirmTitle').textContent = title || 'Confirm';
                overlay.classList.add('active');
                setTimeout(() => okBtn.focus({ preventScroll: true }), 60);

                function cleanup(result) {
                    overlay.classList.remove('active');
                    okBtn.removeEventListener('click', onOk);
                    cancelBtn.removeEventListener('click', onCancel);
                    overlay.removeEventListener('click', onOverlay);
                    document.removeEventListener('keydown', onKeydown);
                    resolve(result);
                }

                function onOk() {
                    cleanup(true);
                }

                function onCancel() {
                    cleanup(false);
                }

                function onOverlay(e) {
                    if (e.target === overlay) cleanup(false);
                }

                // Enter = setuju (mis. langsung masuk ke bisnis pilihan), Esc = batal.
                function onKeydown(e) {
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        cleanup(false);
                    } else if (e.key === 'Enter' && !e.isComposing) {
                        e.preventDefault();
                        cleanup(true);
                    }
                }

                okBtn.addEventListener('click', onOk);
                cancelBtn.addEventListener('click', onCancel);
                overlay.addEventListener('click', onOverlay);
                document.addEventListener('keydown', onKeydown);
            });
        }
    </script>

    <!-- Tagihan Langganan ADF System: banner jatuh tempo (≤7 hari) + kunci layar bila dikunci dari adfsystem.store -->
    <?php
    $adfsubState = ['connected' => false, 'locked' => false, 'reminder' => null];
    $adfsubRole = $_SESSION['role'] ?? '';
    $adfsubOnBillingPage = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/modules/subscription/') !== false;
    try {
        require_once __DIR__ . '/subscription_client.php';
        if (!empty($_SESSION['user_id']) || !empty($_SESSION['logged_in'])) {
            // Langkah jaringan (sinkron store / cek Pakasir) berjalan setelah halaman terkirim.
            $adfsubState = adfsub_tick(null, true);
        }
    } catch (Throwable $e) {
        error_log('subscription header: ' . $e->getMessage());
    }
    // Laporan Telegram developer (ringkasan 21:00 & pengingat jatuh tempo 08:00) — cadangan bila cron belum dipasang.
    try {
        require_once __DIR__ . '/adf_report.php';
        adf_report_maybe_run();
    } catch (Throwable $e) {
        error_log('adf report header: ' . $e->getMessage());
    }
    $adfsubCanManage = in_array($adfsubRole, ['developer', 'owner', 'admin', 'manager'], true);
    $adfsubBillingUrl = BASE_URL . '/modules/subscription/index.php';
    ?>
    <?php
    // Tagihan terdekat yang belum dibayar, untuk ditampilkan di popup tengah layar.
    $adfsubBill = $adfsubState['reminder']['invoice'] ?? (($adfsubState['unpaid'] ?? [])[0] ?? null);
    $adfsubDays = isset($adfsubState['reminder']['days_left']) ? (int) $adfsubState['reminder']['days_left'] : null;
    $adfsubBillLine = $adfsubBill
        ? htmlspecialchars($adfsubBill['description'] ?: $adfsubBill['period']) . ' · <strong>Rp ' . number_format((float) $adfsubBill['total_amount'], 0, ',', '.') . '</strong>'
        . (!empty($adfsubBill['due_date']) ? ' · jatuh tempo ' . date('d M Y', strtotime($adfsubBill['due_date'])) : '')
        : '';
    // "Bayar" langsung ke halaman pembayaran Pakasir untuk tagihan terdekat (tanpa mampir ke halaman tagihan).
    $adfsubPayBill = $adfsubBill ?: (($adfsubState['unpaid'] ?? [])[0] ?? null);
    $adfsubPayUrl = $adfsubPayBill ? $adfsubBillingUrl . '?pay=' . urlencode($adfsubPayBill['period']) : $adfsubBillingUrl;
    $adfsubHasBill = !empty($adfsubState['unpaid']);
    // Kunci tanpa tagihan (langganan masih aktif) tetap menutup semua halaman, termasuk halaman tagihan.
    $adfsubShowLock = $adfsubState['connected'] && $adfsubState['locked'] && (!$adfsubOnBillingPage || !$adfsubHasBill);
    $adfsubWaUrl = 'https://wa.me/628214400664?text=' . rawurlencode('Halo Developer ADF System, sistem ' . (defined('BUSINESS_NAME') ? BUSINESS_NAME : '') . ' saya terkunci. Mohon bantuannya.');
    $adfsubWaIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.62-.92-2.22-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.08c.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.69.63.71.23 1.36.2 1.87.12.57-.08 1.76-.72 2.01-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35zM12.05 21.5h-.01a9.4 9.4 0 0 1-4.8-1.31l-.34-.2-3.57.94.95-3.48-.22-.36a9.4 9.4 0 0 1-1.44-5.02c0-5.2 4.23-9.43 9.44-9.43a9.37 9.37 0 0 1 6.67 2.77 9.37 9.37 0 0 1 2.76 6.67c0 5.2-4.23 9.43-9.43 9.43zm8.03-17.46A11.27 11.27 0 0 0 12.05.72C5.8.72.7 5.8.7 12.07c0 2 .52 3.95 1.52 5.67L.6 23.65l6.04-1.59a11.3 11.3 0 0 0 5.4 1.38h.01c6.25 0 11.35-5.09 11.35-11.36 0-3.03-1.18-5.88-3.32-8.03z"/></svg>';
    $adfsubShowReminder = $adfsubState['connected'] && !$adfsubState['locked'] && $adfsubBill && !empty($adfsubState['reminder']) && $adfsubCanManage && !$adfsubOnBillingPage;
    ?>
    <?php if ($adfsubShowLock): ?>
        <!-- Dikunci dari adfsystem.store: layar ditutup penuh, hanya bisa ke halaman bayar -->
        <div class="adfsub-lock" id="adfsubLock">
            <div class="adfsub-lock-box adfsub-box-red">
                <div class="adfsub-lock-ico">🔒</div>
                <h3>Sistem Sementara Dikunci</h3>
                <?php if ($adfsubHasBill): ?>
                    <p>Akses dikunci oleh ADF System karena tagihan langganan belum diselesaikan. Selesaikan pembayaran untuk membuka kembali.</p>
                    <?php if ($adfsubBillLine): ?><div class="adfsub-bill"><?php echo $adfsubBillLine; ?></div><?php endif; ?>
                    <?php if ($adfsubCanManage): ?>
                        <a href="<?php echo $adfsubPayUrl; ?>" class="adfsub-lock-btn">Bayar Sekarang</a>
                    <?php else: ?>
                        <p style="font-size:12px;opacity:.75;">Silakan hubungi owner / admin.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p>Akses ke sistem sedang dikunci oleh ADF System. Silakan hubungi developer untuk membuka kembali.</p>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars($adfsubWaUrl); ?>" target="_blank" rel="noopener" class="adfsub-wa-btn"><?php echo $adfsubWaIcon; ?> Hubungi Developer · 08214400664</a>
                <?php if ($adfsubRole === 'developer'): ?>
                    <!-- Developer melihat layar kunci yang sama dengan user, tapi bisa lanjut untuk perbaikan -->
                    <button type="button" class="adfsub-lock-out" style="background:none;border:none;cursor:pointer;display:block;margin:12px auto 0;" onclick="document.getElementById('adfsubLock').style.display='none';">Lanjut sebagai developer</button>
                <?php else: ?>
                    <a href="<?php echo BASE_URL; ?>/logout.php" class="adfsub-lock-out">Keluar</a>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($adfsubShowReminder):
        // Developer saat dikunci, atau pengingat jatuh tempo: popup di tengah. "Nanti saja" hanya menutup sementara;
        // popup muncul lagi tiap kali halaman dibuka / di-refresh selama tagihan belum dibayar.
        $adfsubOverdue = $adfsubShowLock || ($adfsubDays !== null && $adfsubDays < 0);
    ?>
        <div class="adfsub-lock adfsub-popup" id="adfsubPopup">
            <div class="adfsub-lock-box<?php echo $adfsubOverdue ? ' adfsub-box-red' : ''; ?>">
                <div class="adfsub-lock-ico"><?php echo $adfsubShowLock ? '🔒' : ($adfsubOverdue ? '⚠️' : '🧾'); ?></div>
                <h3>
                    <?php
                    if ($adfsubShowLock) {
                        echo 'Sistem Dikunci oleh ADF System';
                    } elseif ($adfsubDays === null || $adfsubDays > 7) {
                        echo 'Tagihan Baru dari ADF System';
                    } elseif ($adfsubDays < 0) {
                        echo 'Tagihan Lewat Jatuh Tempo';
                    } elseif ($adfsubDays === 0) {
                        echo 'Tagihan Jatuh Tempo Hari Ini';
                    } else {
                        echo 'Tagihan Jatuh Tempo ' . $adfsubDays . ' Hari Lagi';
                    }
                    ?>
                </h3>
                <p><?php echo $adfsubShowLock
                        ? 'Pengguna lain tidak bisa memakai sistem sampai kunci dibuka dari adfsystem.store. Anda tetap bisa masuk karena login sebagai developer.'
                        : 'Segera selesaikan pembayaran langganan agar sistem tetap bisa digunakan tanpa gangguan.'; ?></p>
                <?php if ($adfsubBillLine): ?><div class="adfsub-bill"><?php echo $adfsubBillLine; ?></div><?php endif; ?>
                <?php if ($adfsubHasBill): ?>
                    <a href="<?php echo $adfsubPayUrl; ?>" class="adfsub-lock-btn">Bayar Sekarang</a>
                <?php endif; ?>
                <button type="button" class="adfsub-lock-out" style="background:none;border:none;cursor:pointer;" onclick="document.getElementById('adfsubPopup').style.display='none';">Nanti saja</button>
            </div>
        </div>
    <?php endif; ?>
    <style>
        .adfsub-lock {
            position: fixed;
            inset: 0;
            z-index: 100000;
            background: rgba(15, 23, 42, .82);
            backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .adfsub-lock-box {
            max-width: 380px;
            width: 100%;
            background: #fff;
            color: #1e293b;
            -webkit-text-fill-color: #1e293b;
            border-radius: 16px;
            padding: 28px 24px;
            text-align: center;
            box-shadow: 0 24px 60px rgba(0, 0, 0, .35);
        }

        .adfsub-lock-ico {
            font-size: 34px;
            margin-bottom: 6px;
        }

        .adfsub-lock-box h3 {
            margin: 0 0 8px;
            font-size: 17px;
        }

        .adfsub-lock-box p {
            margin: 0 0 16px;
            font-size: 13px;
            line-height: 1.55;
            color: #475569;
            -webkit-text-fill-color: #475569;
        }

        .adfsub-lock-btn {
            display: block;
            background: #16a34a;
            color: #fff !important;
            -webkit-text-fill-color: #fff;
            padding: 10px;
            border-radius: 10px;
            font-weight: 700;
            text-decoration: none !important;
            font-size: 13.5px;
        }

        .adfsub-wa-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            margin-top: 10px;
            background: #25d366;
            color: #fff !important;
            -webkit-text-fill-color: #fff;
            padding: 10px;
            border-radius: 10px;
            font-weight: 700;
            text-decoration: none !important;
            font-size: 13px;
        }

        .adfsub-popup {
            background: rgba(15, 23, 42, .55);
        }

        .adfsub-box-red {
            border-top: 4px solid #dc2626;
        }

        .adfsub-bill {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 9px 12px;
            margin: 0 0 16px;
            font-size: 12.5px;
            color: #334155;
            -webkit-text-fill-color: #334155;
            line-height: 1.5;
        }

        .adfsub-lock-out {
            display: inline-block;
            margin-top: 12px;
            font-size: 12px;
            color: #64748b !important;
            -webkit-text-fill-color: #64748b;
        }
    </style>

    <!-- Popup tagihan belum lunas (menggantikan running text kamar / hotel service / motor).
         Muncul di halaman pertama setelah login (semua tagihan), lalu setiap kali user MASUK ke
         bagian terkait: Front Desk -> tagihan kamar, Hotel Service -> invoice layanan, Rental Motor -> motor terlambat. -->
    <?php
    $unpaidGuestsCount = 0;
    try {
        $businessId = $_SESSION['business_id'] ?? 1;
        $overdueMotors = getOverdueMotorsForNotification($db->getConnection(), $businessId);
        $unpaidGuests = getUnpaidCheckedInGuests($db->getConnection());
        $unpaidGuestsCount = count($unpaidGuests);
        $unpaidHotelServices = getUnpaidHotelServiceInvoices($db->getConnection(), $businessId);

        // Bagian aplikasi yang sedang dibuka.
        $bpPage = basename($_SERVER['PHP_SELF'] ?? '');
        if (in_array($bpPage, ['hotel-services.php', 'hotel-service-invoice.php'], true)) {
            $bpSection = 'hs';
        } elseif ($bpPage === 'rental-motor.php') {
            $bpSection = 'motor';
        } elseif (strpos($_SERVER['PHP_SELF'] ?? '', '/modules/frontdesk/') !== false) {
            $bpSection = 'room';
        } else {
            $bpSection = 'other';
        }
        $bpPrevSection = $_SESSION['bill_popup_section'] ?? null;
        $bpFirstAfterLogin = empty($_SESSION['bill_popup_login_done']);
        $_SESSION['bill_popup_section'] = $bpSection;
        $_SESSION['bill_popup_login_done'] = true;

        if ($bpFirstAfterLogin) {
            $bpShow = ['room', 'hs', 'motor'];
        } elseif ($bpSection !== $bpPrevSection && $bpSection !== 'other') {
            $bpShow = [$bpSection];
        } else {
            $bpShow = [];
        }

        $bpRp = fn($v) => 'Rp ' . number_format((float)$v, 0, ',', '.');
        $bpSections = [];

        // Kamar: satu baris per booking/grup yang masih bersisa (baris grup lain bernilai 0 dilewati).
        if (in_array('room', $bpShow, true) && $unpaidGuests) {
            $bpGroupRooms = [];
            foreach ($unpaidGuests as $bpG) {
                if (!empty($bpG['group_id'])) $bpGroupRooms[$bpG['group_id']][] = $bpG['room_number'];
            }
            $bpItems = [];
            foreach ($unpaidGuests as $bpG) {
                if ((float)$bpG['remaining'] <= 0) continue;
                $bpRooms = !empty($bpG['group_id']) ? $bpGroupRooms[$bpG['group_id']] : [$bpG['room_number']];
                $bpOverdue = !empty($bpG['check_out_date']) && $bpG['check_out_date'] < date('Y-m-d');
                $bpItems[] = [
                    'tag'  => count($bpRooms) > 1 ? count($bpRooms) . ' kmr' : (string)$bpRooms[0],
                    'name' => $bpG['guest_name'] ?: '-',
                    'sub'  => trim(($bpG['booking_code'] ?? '') . ' · ' . (count($bpRooms) > 1 ? 'Kamar ' . implode(', ', $bpRooms) . ' · ' : '') . ($bpOverdue ? 'Lewat tanggal check-out' : 'Check-out hari ini'), ' ·'),
                    'amt'  => $bpRp($bpG['remaining']),
                    'cta'  => 'Bayar',
                    'href' => BASE_URL . '/modules/frontdesk/in-house.php?pay=' . (int)$bpG['id'],
                ];
            }
            if ($bpItems) {
                $bpSections[] = ['title' => 'Tagihan kamar', 'items' => $bpItems, 'href' => BASE_URL . '/modules/frontdesk/in-house.php', 'cta' => 'Buka Tamu In-House'];
            }
        }

        if (in_array('hs', $bpShow, true) && $unpaidHotelServices) {
            $bpItems = [];
            foreach ($unpaidHotelServices as $bpInv) {
                $bpRest = max(0, (float)$bpInv['total'] - (float)$bpInv['paid_amount']);
                if ($bpRest <= 0) continue;
                $bpItems[] = [
                    'tag'  => $bpInv['room_number'] ? (string)$bpInv['room_number'] : 'HS',
                    'name' => $bpInv['guest_name'] ?: '-',
                    'sub'  => $bpInv['invoice_number'] . ' · Hotel Service',
                    'amt'  => $bpRp($bpRest),
                    'cta'  => 'Lihat',
                    'href' => BASE_URL . '/modules/frontdesk/hotel-service-invoice.php?id=' . (int)$bpInv['id'],
                ];
            }
            if ($bpItems) {
                $bpSections[] = ['title' => 'Hotel Service', 'items' => $bpItems, 'href' => BASE_URL . '/modules/frontdesk/hotel-services.php', 'cta' => 'Buka Hotel Service'];
            }
        }

        if (in_array('motor', $bpShow, true) && $overdueMotors) {
            $bpItems = [];
            foreach ($overdueMotors as $bpM) {
                $bpH = max(0, (int)($bpM['hours_overdue'] ?? 0));
                $bpItems[] = [
                    'tag'  => 'MTR',
                    'name' => $bpM['guest_name'] ?: '-',
                    'sub'  => $bpM['motor_name'] . ' (' . $bpM['plate_number'] . ')',
                    'amt'  => (floor($bpH / 24) > 0 ? floor($bpH / 24) . ' hari ' . ($bpH % 24) . ' jam' : $bpH . ' jam'),
                    'cta'  => 'Terlambat',
                    'href' => BASE_URL . '/modules/frontdesk/rental-motor.php',
                ];
            }
            $bpSections[] = ['title' => 'Rental motor terlambat', 'items' => $bpItems, 'href' => BASE_URL . '/modules/frontdesk/rental-motor.php', 'cta' => 'Buka Rental Motor'];
        }

        if ($bpSections):
            $bpTotal = array_sum(array_map(fn($s) => count($s['items']), $bpSections));
    ?>
            <style>
                .billpop {
                    --bp-card: #ffffff; --bp-ink: #0f172a; --bp-muted: #64748b; --bp-line: #e2e8f0; --bp-row: #f8fafc;
                    position: fixed; inset: 0; z-index: 10050; display: flex; align-items: center; justify-content: center;
                    padding: 16px; background: rgba(15, 23, 42, 0.45); backdrop-filter: blur(3px);
                    opacity: 0; transition: opacity .18s ease;
                }
                body[data-theme="dark"] .billpop { --bp-card: #111a2e; --bp-ink: #e2e8f0; --bp-muted: #94a3b8; --bp-line: rgba(255,255,255,.1); --bp-row: rgba(255,255,255,.04); }
                .billpop.show { opacity: 1; }
                .billpop-card {
                    width: 100%; max-width: 480px; max-height: calc(100vh - 32px); display: flex; flex-direction: column;
                    background: var(--bp-card); color: var(--bp-ink); border-radius: 16px; overflow: hidden;
                    box-shadow: 0 24px 60px -12px rgba(15, 23, 42, .45); border: 1px solid var(--bp-line);
                    transform: translateY(8px) scale(.98); transition: transform .18s ease;
                }
                .billpop.show .billpop-card { transform: none; }
                .billpop-head { display: flex; gap: 12px; align-items: flex-start; padding: 16px 18px 12px; border-bottom: 1px solid var(--bp-line); }
                .billpop-ico {
                    width: 38px; height: 38px; border-radius: 11px; flex-shrink: 0; display: grid; place-items: center;
                    background: #fef2f2; color: #b91c1c !important; -webkit-text-fill-color: #b91c1c; font-weight: 800; font-size: 18px; box-shadow: inset 0 0 0 1px #fecaca; opacity: 1 !important;
                }
                .billpop-head h3 { margin: 0; font-size: 0.98rem; font-weight: 700; color: var(--bp-ink); }
                .billpop-head p { margin: 2px 0 0; font-size: 0.76rem; color: var(--bp-muted); }
                .billpop-x { margin-left: auto; border: 0; background: transparent; color: var(--bp-muted); font-size: 22px; line-height: 1; cursor: pointer; padding: 0 2px; }
                .billpop-body { overflow-y: auto; padding: 6px 12px 10px; }
                .billpop-sec h4 {
                    display: flex; justify-content: space-between; align-items: center; margin: 10px 6px 6px;
                    font-size: 0.66rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--bp-muted);
                }
                .billpop-sec h4 span { background: #fee2e2; color: #b91c1c !important; -webkit-text-fill-color: #b91c1c; border-radius: 999px; padding: 1px 8px; letter-spacing: 0; opacity: 1 !important; }
                a.billpop-item {
                    display: flex; align-items: center; gap: 10px; padding: 9px 10px; margin-bottom: 6px; border-radius: 11px;
                    background: var(--bp-row); border: 1px solid var(--bp-line); text-decoration: none; color: var(--bp-ink);
                    transition: border-color .15s, transform .15s;
                }
                a.billpop-item:hover { border-color: #93c5fd; transform: translateX(2px); }
                .billpop-tag {
                    min-width: 44px; height: 34px; padding: 0 6px; border-radius: 9px; display: grid; place-items: center; flex-shrink: 0;
                    background: linear-gradient(135deg, #1e3a8a, #2563eb); color: #fff; font-weight: 700; font-size: 0.78rem;
                }
                .billpop-who { flex: 1; min-width: 0; }
                .billpop-who b { display: block; font-size: 0.8rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--bp-ink); }
                .billpop-who small { display: block; font-size: 0.68rem; color: var(--bp-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
                .billpop-amt { text-align: right; flex-shrink: 0; }
                .billpop-amt b { display: block; font-size: 0.8rem; font-weight: 700; color: #b91c1c; }
                .billpop-amt small { display: block; font-size: 0.66rem; font-weight: 600; color: #2563eb; }
                .billpop-foot { display: flex; gap: 8px; justify-content: flex-end; padding: 12px 18px; border-top: 1px solid var(--bp-line); }
                .billpop-btn { border: 0; border-radius: 9px; padding: 8px 14px; font-size: 0.78rem; font-weight: 600; cursor: pointer; text-decoration: none; }
                .billpop-btn.ghost { background: transparent; color: var(--bp-muted); border: 1px solid var(--bp-line); }
                a.billpop-btn.pri { background: #2563eb; color: #fff; }
                /* Tema terang (style.css) memaksa warna teks dengan !important: kunci semua warna teks popup. */
                body[data-theme] .billpop .billpop-tag,
                body[data-theme] .billpop a.billpop-btn.pri { color: #fff !important; -webkit-text-fill-color: #fff !important; }
                body[data-theme] .billpop a.billpop-btn.pri:hover { background: #1d4ed8; }
                body[data-theme] .billpop .billpop-head h3,
                body[data-theme] .billpop .billpop-who b { color: var(--bp-ink) !important; -webkit-text-fill-color: var(--bp-ink) !important; }
                body[data-theme] .billpop .billpop-head p,
                body[data-theme] .billpop .billpop-who small,
                body[data-theme] .billpop .billpop-sec h4,
                body[data-theme] .billpop .billpop-x,
                body[data-theme] .billpop .billpop-btn.ghost { color: var(--bp-muted) !important; -webkit-text-fill-color: var(--bp-muted) !important; }
                body[data-theme] .billpop .billpop-amt b { color: #dc2626 !important; -webkit-text-fill-color: #dc2626 !important; }
                body[data-theme] .billpop .billpop-amt small { color: #2563eb !important; -webkit-text-fill-color: #2563eb !important; }
                body[data-theme="dark"] .billpop .billpop-amt b { color: #f87171 !important; -webkit-text-fill-color: #f87171 !important; }
                body[data-theme="dark"] .billpop .billpop-amt small { color: #60a5fa !important; -webkit-text-fill-color: #60a5fa !important; }
            </style>
            <div class="billpop" id="billPop" role="dialog" aria-modal="true" aria-labelledby="billPopTitle">
                <div class="billpop-card">
                    <div class="billpop-head">
                        <span class="billpop-ico">!</span>
                        <div>
                            <h3 id="billPopTitle">Tagihan belum lunas (<?php echo $bpTotal; ?>)</h3>
                            <p>Klik tagihan untuk langsung memproses pembayaran.</p>
                        </div>
                        <button type="button" class="billpop-x" data-bp-close aria-label="Tutup">&times;</button>
                    </div>
                    <div class="billpop-body">
                        <?php foreach ($bpSections as $bpSec): ?>
                            <div class="billpop-sec">
                                <h4><?php echo htmlspecialchars($bpSec['title']); ?> <span><?php echo count($bpSec['items']); ?></span></h4>
                                <?php foreach ($bpSec['items'] as $bpIt): ?>
                                    <a class="billpop-item" href="<?php echo htmlspecialchars($bpIt['href']); ?>">
                                        <span class="billpop-tag"><?php echo htmlspecialchars($bpIt['tag']); ?></span>
                                        <span class="billpop-who">
                                            <b><?php echo htmlspecialchars($bpIt['name']); ?></b>
                                            <small><?php echo htmlspecialchars($bpIt['sub']); ?></small>
                                        </span>
                                        <span class="billpop-amt">
                                            <b><?php echo htmlspecialchars($bpIt['amt']); ?></b>
                                            <small><?php echo htmlspecialchars($bpIt['cta']); ?> &rarr;</small>
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="billpop-foot">
                        <button type="button" class="billpop-btn ghost" data-bp-close>Nanti</button>
                        <a class="billpop-btn pri" href="<?php echo htmlspecialchars($bpSections[0]['href']); ?>"><?php echo htmlspecialchars($bpSections[0]['cta']); ?></a>
                    </div>
                </div>
            </div>
            <script>
                (function() {
                    const pop = document.getElementById('billPop');
                    if (!pop) return;
                    const close = () => {
                        pop.classList.remove('show');
                        setTimeout(() => pop.remove(), 200);
                        document.removeEventListener('keydown', onKey);
                    };
                    const onKey = e => { if (e.key === 'Escape') close(); };
                    pop.addEventListener('click', e => {
                        if (e.target === pop || e.target.closest('[data-bp-close]')) close();
                    });
                    document.addEventListener('keydown', onKey);
                    // Pindah ke <body> agar tidak terpotong induk ber-transform, lalu tampilkan.
                    document.addEventListener('DOMContentLoaded', () => {
                        document.body.appendChild(pop);
                        requestAnimationFrame(() => pop.classList.add('show'));
                    });
                })();
            </script>
        <?php endif; ?>
    <?php } catch (\Throwable $e) {
        // Silent fail if notification fails
    } ?>

    <!-- Unpaid Cafe Invoice Notification Banner (cafe-invoice businesses, e.g. Ben's Cafe) -->
    <?php
    $unpaidCafeInvoicesCount = 0;
    try {
        if (function_exists('isModuleEnabled') && isModuleEnabled('cafe-invoice')) {
            $unpaidCafeInvoices = getUnpaidCafeInvoices($db->getConnection());
            $unpaidCafeInvoicesCount = count($unpaidCafeInvoices);
            if (!empty($unpaidCafeInvoices)):
                $cafeMessages = array_map(fn($m) => htmlspecialchars($m), formatUnpaidCafeInvoiceMessages($unpaidCafeInvoices));
                $cafeCount = count($cafeMessages);
                $cafeNotificationText = implode('&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;', $cafeMessages);
                $cafeScrollDuration = max(4, $cafeCount * 2);
    ?>
                <style>
                    .cafe-invoice-banner {
                        background: linear-gradient(90deg, var(--primary-dark), var(--primary-color), var(--primary-dark));
                        background-size: 200% 100%;
                        animation: cib-bg 4s linear infinite;
                        color: #ffffff !important;
                        -webkit-text-fill-color: #ffffff !important;
                        text-fill-color: #ffffff !important;
                        padding: 0.5rem 0;
                        overflow: hidden;
                        position: relative;
                        font-weight: 700;
                        font-size: 0.84rem;
                        letter-spacing: 0.01em;
                        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.45);
                        box-shadow: var(--shadow-glow);
                        border-bottom: 2px solid var(--primary-dark);
                        z-index: 999;
                        cursor: pointer;
                    }

                    .cafe-invoice-banner,
                    .cafe-invoice-banner * {
                        -webkit-text-fill-color: unset;
                        text-fill-color: unset;
                        opacity: 1 !important;
                        mix-blend-mode: normal !important;
                    }

                    @keyframes cib-bg {
                        0% {
                            background-position: 0% 50%;
                        }

                        100% {
                            background-position: 200% 50%;
                        }
                    }

                    .cafe-invoice-banner .cib-label {
                        position: absolute;
                        left: 210px;
                        top: 0;
                        bottom: 0;
                        display: flex;
                        align-items: center;
                        padding: 0 0.75rem;
                        background: rgba(0, 0, 0, 0.35);
                        white-space: nowrap;
                        font-size: 0.78rem;
                        gap: 0.3rem;
                        z-index: 2;
                        border-right: 1px solid rgba(255, 255, 255, 0.2);
                        color: #ffffff;
                    }

                    @media (max-width: 768px) {
                        .cafe-invoice-banner .cib-label {
                            left: 0;
                        }
                    }

                    .cafe-invoice-banner .cib-label .notif-dot {
                        width: 9px;
                        height: 9px;
                        border-radius: 50%;
                        background: #ef4444;
                        box-shadow: 0 0 0 rgba(239, 68, 68, 0.7);
                        animation: notif-dot-pulse 1.4s ease-out infinite;
                        flex-shrink: 0;
                    }

                    .cafe-invoice-banner .cib-ticker {
                        display: block;
                        white-space: nowrap;
                        padding-left: 370px;
                        color: #ffffff;
                        animation: cib-ticker-scroll <?php echo $cafeScrollDuration; ?>s linear infinite;
                    }

                    @media (max-width: 768px) {
                        .cafe-invoice-banner .cib-ticker {
                            padding-left: 160px;
                        }
                    }

                    @keyframes cib-ticker-scroll {
                        0% {
                            transform: translateX(0);
                        }

                        100% {
                            transform: translateX(-100%);
                        }
                    }

                    .cafe-invoice-banner:hover .cib-ticker {
                        animation-play-state: paused;
                    }
                </style>
                <div class="cafe-invoice-banner" onclick="window.location.href='<?php echo BASE_URL; ?>/modules/cafe-invoice/index.php?filter=unpaid'" title="Klik untuk lihat detail">
                    <span class="cib-label">
                        <span class="notif-dot"></span>
                        PERHATIAN (<?php echo $cafeCount; ?>)
                    </span>
                    <span class="cib-ticker">
                        <?php echo $cafeNotificationText; ?>
                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                        <?php echo $cafeNotificationText; ?>
                    </span>
                </div>
    <?php endif;
        }
    } catch (\Throwable $e) {
        // Silent fail if notification fails
    } ?>

    <div class="main-wrapper">
        <!-- Sidebar Navigation -->
        <!-- Sidebar modern: rapat, ringkas, dikelompokkan. Selector ber-body[data-theme] + !important karena
             style.css (tema terang) memaksa warna/ukuran sidebar dengan !important. -->
        <style id="sidebarModern">
            body[data-theme] .sidebar {
                padding: 0.85rem 0.7rem 0.6rem !important;
                gap: 0;
            }

            body[data-theme] .sidebar-header {
                margin-bottom: 0.6rem !important;
                padding: 0 0.25rem 0.75rem !important;
            }

            body[data-theme] .sidebar-header > div:first-child {
                gap: 0.65rem !important;
            }

            /* Logo bisnis: bulat presisi, gambar utuh di tengah (tidak terpotong / bergeser) */
            body[data-theme] .sidebar-header > div:first-child > div:first-child:not(:only-child) {
                width: 56px !important;
                height: 56px !important;
                padding: 0;
                box-sizing: border-box;
                border-radius: 50% !important;
                background: transparent;
                display: flex !important;
                align-items: center;
                justify-content: center;
                overflow: hidden;
                box-shadow: 0 6px 16px -6px rgba(15, 23, 42, 0.45);
            }

            body[data-theme] .sidebar-header > div:first-child > div:first-child:not(:only-child) img {
                width: 100% !important;
                height: 100% !important;
                border-radius: 50% !important;
                object-fit: cover !important;
                object-position: center !important;
                display: block;
                /* File logo punya margin putih di sekeliling lingkaran: diperbesar sedikit agar lingkaran logo mengisi penuh */
                transform: scale(1.14);
            }

            body[data-theme] .sidebar-header .logo {
                font-size: 0.84rem !important;
                line-height: 1.25;
                letter-spacing: -0.01em;
            }

            body[data-theme] .sidebar-header > div:first-child p {
                font-size: 0.6rem !important;
                margin-top: 0.15rem !important;
                letter-spacing: 0.02em;
            }

            body[data-theme] .sidebar-header label {
                font-size: 0.55rem !important;
                letter-spacing: 0.09em !important;
                margin-bottom: 0.3rem !important;
            }

            body[data-theme] .sidebar-header > div:last-child:not(:first-child) {
                margin-top: 0.7rem !important;
                padding-top: 0.7rem !important;
            }

            body[data-theme] .sidebar-header select {
                height: 30px;
                padding: 0 0.5rem !important;
                font-size: 0.72rem !important;
                border-radius: 9px !important;
            }

            /* Label grup */
            .sidebar .nav-group-label {
                list-style: none;
                margin: 0.85rem 0 0.25rem;
                padding: 0 0.6rem;
                font-size: 0.56rem;
                font-weight: 700;
                letter-spacing: 0.1em;
                text-transform: uppercase;
                color: #94a3b8 !important;
            }

            .sidebar .nav-menu > .nav-group-label:first-child {
                margin-top: 0.1rem;
            }

            /* Item utama */
            body[data-theme] .sidebar .nav-item {
                margin-bottom: 1px !important;
            }

            body[data-theme] .sidebar .nav-link {
                gap: 0.6rem !important;
                padding: 0.44rem 0.6rem !important;
                border-radius: 9px !important;
                font-size: 0.78rem !important;
                font-weight: 500 !important;
                letter-spacing: 0;
                color: #334155 !important;
                background: transparent !important;
                box-shadow: none !important;
                transform: none !important;
                transition: background 0.15s ease, color 0.15s ease;
            }

            body[data-theme] .sidebar .nav-link span {
                color: inherit !important;
            }

            body[data-theme] .sidebar .nav-link .nav-icon,
            body[data-theme] .sidebar .nav-link svg {
                width: 16px !important;
                height: 16px !important;
                flex-shrink: 0;
                opacity: 1 !important;
                color: #64748b !important;
                stroke: currentColor !important;
                stroke-width: 1.75;
            }

            body[data-theme] .sidebar .nav-link:hover {
                background: rgba(37, 99, 235, 0.07) !important;
                color: #1e3a8a !important;
            }

            body[data-theme] .sidebar .nav-link:hover .nav-icon,
            body[data-theme] .sidebar .nav-link:hover svg {
                color: #1e3a8a !important;
            }

            body[data-theme] .sidebar .nav-link.active {
                background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%) !important;
                color: #fff !important;
                box-shadow: 0 6px 14px -8px rgba(29, 78, 216, 0.75) !important;
            }

            body[data-theme] .sidebar .nav-link.active .nav-icon,
            body[data-theme] .sidebar .nav-link.active svg,
            body[data-theme] .sidebar .nav-link.active span {
                color: #fff !important;
            }

            /* Panah dropdown: chevron tipis */
            body[data-theme] .sidebar .nav-link.dropdown-toggle::after {
                right: 0.7rem;
                width: 5px;
                height: 5px;
                border: solid currentColor;
                border-width: 0 1.5px 1.5px 0;
                transform: translateY(-70%) rotate(45deg);
                opacity: 0.55;
            }

            body[data-theme] .sidebar .nav-item.has-submenu.open > .nav-link.dropdown-toggle::after {
                transform: translateY(-30%) rotate(-135deg);
            }

            /* Submenu: garis panduan tipis, tanpa latar abu */
            body[data-theme] .sidebar .submenu {
                background: transparent !important;
                margin-left: 1.1rem !important;
                padding-left: 0.45rem !important;
                border-left: 1px solid rgba(148, 163, 184, 0.35);
            }

            body[data-theme] .sidebar .nav-item.has-submenu.open .submenu {
                margin-top: 2px !important;
                margin-bottom: 0.3rem;
            }

            body[data-theme] .sidebar .submenu-item {
                margin-bottom: 1px !important;
            }

            body[data-theme] .sidebar .submenu-link {
                gap: 0.5rem !important;
                padding: 0.34rem 0.55rem !important;
                border-radius: 7px !important;
                border-left: none !important;
                font-size: 0.73rem !important;
                font-weight: 500 !important;
                color: #64748b !important;
                transition: background 0.15s ease, color 0.15s ease;
            }

            body[data-theme] .sidebar .submenu-link span {
                color: inherit !important;
            }

            body[data-theme] .sidebar .submenu-link .submenu-icon,
            body[data-theme] .sidebar .submenu-link svg {
                width: 14px !important;
                height: 14px !important;
                flex-shrink: 0;
                opacity: 0.85 !important;
                stroke: currentColor !important;
                stroke-width: 1.75;
            }

            body[data-theme] .sidebar .submenu-link:hover {
                background: rgba(37, 99, 235, 0.06) !important;
                color: #1e3a8a !important;
                padding-left: 0.55rem !important;
            }

            body[data-theme] .sidebar .submenu-link.active {
                background: rgba(37, 99, 235, 0.1) !important;
                color: #1d4ed8 !important;
                font-weight: 600 !important;
            }

            /* Tema gelap */
            body[data-theme="dark"] .sidebar .nav-link,
            body[data-theme="dark"] .sidebar .submenu-link {
                color: #cbd5e1 !important;
            }

            body[data-theme="dark"] .sidebar .nav-link .nav-icon,
            body[data-theme="dark"] .sidebar .nav-link svg {
                color: #94a3b8 !important;
            }

            body[data-theme="dark"] .sidebar .nav-link:hover,
            body[data-theme="dark"] .sidebar .submenu-link:hover {
                background: rgba(148, 163, 184, 0.12) !important;
                color: #fff !important;
            }

            body[data-theme="dark"] .sidebar .nav-link:hover .nav-icon,
            body[data-theme="dark"] .sidebar .nav-link:hover svg {
                color: #fff !important;
            }

            body[data-theme="dark"] .sidebar .submenu-link.active {
                background: rgba(59, 130, 246, 0.18) !important;
                color: #93c5fd !important;
            }

            /* Item dengan jarak/garis inline (Pengaturan, Keluar) dirapatkan ke grupnya */
            body[data-theme] .sidebar .nav-menu > .nav-item[style] {
                margin-top: 1px !important;
                padding-top: 0 !important;
                border-top: none !important;
            }

            body[data-theme] .sidebar .nav-menu > .nav-item[style]:last-child {
                margin-top: 0.6rem !important;
                padding-top: 0.5rem !important;
                border-top: 1px solid rgba(148, 163, 184, 0.22) !important;
            }

            /* Tombol End Shift: merah tua, teks putih (tema terang memaksa warna teks lewat style.css) */
            body[data-theme] #endShiftButton {
                background: #991b1b !important;
                border-color: #7f1d1d !important;
                color: #fff !important;
            }

            body[data-theme] #endShiftButton:hover {
                background: #7f1d1d !important;
            }

            body[data-theme] #endShiftButton span,
            body[data-theme] #endShiftButton svg,
            body[data-theme] #endShiftButton i {
                color: #fff !important;
                stroke: #fff !important;
            }

            body[data-theme] .sidebar .sidebar-footer {
                padding: 0.45rem 0.6rem !important;
            }

            body[data-theme] .sidebar .sidebar-footer div,
            body[data-theme] .sidebar .sidebar-footer span {
                font-size: 0.6rem !important;
                line-height: 1.5 !important;
            }
        </style>
        <aside class="sidebar">
            <div class="sidebar-header">
                <?php
                // Get business logo
                $logoPath = getBusinessLogo();
                if (defined('ACTIVE_BUSINESS_ID') && ACTIVE_BUSINESS_ID === 'gudang-nasita') {
                    // Logo kustom dari menu Gudang > Logo Perusahaan; bawaan: SVG gudang.
                    $gnLogoSet = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_logo_gudang-nasita'");
                    if (empty($gnLogoSet['setting_value']) || !$logoPath) {
                        $logoPath = BASE_URL . '/assets/img/gudang-nasita-logo.svg';
                    }
                }

                // Get company name from settings, fallback to BUSINESS_NAME
                $companyNameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
                $displayCompanyName = ($companyNameSetting && $companyNameSetting['setting_value'])
                    ? $companyNameSetting['setting_value']
                    : BUSINESS_NAME;
                ?>
                <div style="display: flex; align-items: center; gap: 0.875rem;">
                    <?php if ($logoPath): ?>
                        <?php if (ACTIVE_BUSINESS_ID === 'cqc'): ?>
                            <!-- CQC: rectangular logo, no company name -->
                            <div style="width: 100%; border-radius: var(--radius-md); background: var(--bg-secondary, #fff); padding: 8px 10px; display: flex; align-items: center; justify-content: center;">
                                <img src="<?php echo $logoPath; ?>" alt="CQC" style="width: 100%; max-height: 48px; border-radius: 4px; object-fit: contain;">
                            </div>
                        <?php else: ?>
                            <div style="width: 76px; height: 76px; border-radius: 50%; overflow: hidden; flex-shrink: 0;">
                                <img src="<?php echo $logoPath; ?>" alt="<?php echo htmlspecialchars($displayCompanyName); ?>" style="width: 100%; height: 100%; object-fit: cover; display: block;">
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="width: 76px; height: 76px; border-radius: 50%; background: linear-gradient(135deg, <?php echo BUSINESS_COLOR; ?>, <?php echo BUSINESS_COLOR; ?>dd); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <span style="font-size: 2rem; font-weight: 800; color: white;"><?php echo BUSINESS_ICON; ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (ACTIVE_BUSINESS_ID !== 'cqc'): ?>
                        <div style="flex: 1;">
                            <h1 class="logo" style="margin: 0; font-size: 1rem;"><?php echo htmlspecialchars($displayCompanyName); ?></h1>
                            <p style="color: var(--text-muted); font-size: 0.75rem; margin: 0; margin-top: 0.25rem;"><?php echo ucfirst(BUSINESS_TYPE); ?> System</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Business Switcher Dropdown (Only show if user has multiple business access) -->
                <?php
                require_once __DIR__ . '/business_access.php';
                $userBusinesses = getUserAvailableBusinesses();
                if (count($userBusinesses) > 1):
                ?>
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--bg-tertiary);">
                        <label style="font-size: 0.7rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.5rem; display: block;">Switch Business</label>
                        <select onchange="switchBusiness(this.value)" style="width: 100%; padding: 0.5rem; background: var(--bg-tertiary); border: 1px solid var(--bg-quaternary); border-radius: var(--radius-md); color: var(--text-primary); font-size: 0.875rem; cursor: pointer;">
                            <?php
                            foreach ($userBusinesses as $bizId => $bizConfig):
                                $selected = ($bizId === ACTIVE_BUSINESS_ID) ? 'selected' : '';
                            ?>
                                <option value="<?php echo htmlspecialchars($bizId); ?>" <?php echo $selected; ?>
                                    data-name="<?php echo htmlspecialchars($bizConfig['name']); ?>"
                                    data-logo="<?php echo htmlspecialchars((string)getBusinessLogoById($bizId, $bizConfig)); ?>"
                                    data-initials="<?php echo htmlspecialchars(implode('', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice(preg_split('/\s+/', trim($bizConfig['name'])), 0, 2)))); ?>">
                                    <?php echo htmlspecialchars($bizConfig['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>

            <nav style="flex: 1; overflow-y: auto; overflow-x: hidden;">
                <ul class="nav-menu">
                    <?php $isGudangNasitaContext = (defined('ACTIVE_BUSINESS_ID') && ACTIVE_BUSINESS_ID === 'gudang-nasita'); ?>
                    <?php if ($isGudangNasitaContext): ?>
                        <?php
                        // Menu Gudang Nasita dikelompokkan mengikuti alur barang:
                        // stok → barang masuk (dari supplier) → barang keluar (ke bisnis) → keuangan.
                        $gudangNavGroups = [
                            ['label' => null, 'items' => [
                                ['modules/gudang/dashboard.php', 'grid', 'Dashboard', ['dashboard.php']],
                            ]],
                            ['label' => 'Stok', 'items' => [
                                ['modules/procurement/gudang-nasita.php', 'archive', 'Stok Gudang', ['gudang-nasita.php', 'stock.php']],
                                ['modules/procurement/gudang-produk.php', 'database', 'Daftar Barang', ['gudang-produk.php']],
                            ]],
                            ['label' => 'Barang Masuk', 'items' => [
                                ['modules/procurement/gudang-po-supplier.php', 'shopping-cart', 'Order ke Supplier', ['gudang-po-supplier.php']],
                                ['modules/procurement/gudang-ambil-outlet.php', 'download', 'Ambil dari Outlet', ['gudang-ambil-outlet.php']],
                                ['modules/procurement/gudang-riwayat-masuk.php', 'log-in', 'Riwayat Barang Masuk', ['gudang-riwayat-masuk.php']],
                            ]],
                            ['label' => 'Barang Keluar · ke bisnis', 'items' => [
                                ['modules/procurement/gudang-transfer.php', 'send', 'Kirim ke Bisnis', ['gudang-transfer.php']],
                                ['modules/procurement/purchase-orders.php', 'list', 'Riwayat Pengiriman', ['purchase-orders.php']],
                            ]],
                            ['label' => 'Keuangan', 'items' => [
                                ['modules/procurement/gudang-tagihan.php', 'file-text', 'Tagihan Bisnis', ['gudang-tagihan.php']],
                                ['modules/gudang/finance.php', 'dollar-sign', 'Kas & Biaya Gudang', ['finance.php']],
                            ]],
                            ['label' => 'Pengaturan', 'items' => [
                                ['modules/gudang/logo.php', 'image', 'Logo Perusahaan', ['logo.php']],
                            ]],
                        ];
                        $gudangCurrentPage = basename($_SERVER['PHP_SELF']);
                        ?>
                        <?php foreach ($gudangNavGroups as $navGroup): ?>
                            <?php if ($navGroup['label']): ?>
                                <li class="gudang-nav-group"><?php echo htmlspecialchars($navGroup['label']); ?></li>
                            <?php endif; ?>
                            <?php foreach ($navGroup['items'] as [$navHref, $navIcon, $navText, $navPages]): ?>
                                <li class="nav-item">
                                    <a href="<?php echo BASE_URL . '/' . $navHref; ?>" class="nav-link <?php echo in_array($gudangCurrentPage, $navPages, true) ? 'active' : ''; ?>">
                                        <i data-feather="<?php echo $navIcon; ?>" class="nav-icon"></i>
                                        <span><?php echo $navText; ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        <style>
                            .gudang-nav-group { list-style: none; margin: 0.85rem 0 0.2rem; padding: 0 0.9rem; font-size: 0.64rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--text-muted, #94a3b8); }
                        </style>
                    <?php else: ?>
                        <li class="nav-group-label">Utama</li>
                        <?php if ($auth->hasPermission('dashboard')): ?>
                            <li class="nav-item">
                                <a href="<?php echo BASE_URL; ?>/index.php" class="nav-link <?php echo activeMenu('index.php'); ?>">
                                    <i data-feather="home" class="nav-icon"></i>
                                    <span><?php echo __('dashboard.title'); ?></span>
                                </a>
                            </li>
                        <?php endif; ?>

                        <!-- CQC Projects Menu (Solar Panel) -->
                        <?php if ($auth->hasPermission('cqc-projects')): ?>
                            <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/cqc-projects/') !== false) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('cqc-projects'); ?>">
                                    <i data-feather="sun" class="nav-icon"></i>
                                    <span>CQC Projects</span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/cqc-projects/dashboard.php" class="submenu-link <?php echo activeMenu('dashboard.php'); ?>">
                                            <i data-feather="bar-chart-2" class="submenu-icon"></i>
                                            <span>Dashboard</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/cqc-projects/add.php" class="submenu-link <?php echo activeMenu('add.php'); ?>">
                                            <i data-feather="plus-circle" class="submenu-icon"></i>
                                            <span>Tambah Proyek</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <!-- PO Menu (Manufacture/PWF) -->
                        <?php if ($auth->hasPermission('production')): ?>
                            <li class="nav-item">
                                <a href="<?php echo BASE_URL; ?>/modules/production/orders.php" class="nav-link <?php echo (strpos($_SERVER['REQUEST_URI'], '/production/orders') !== false) ? 'active' : ''; ?>">
                                    <i data-feather="clipboard" class="nav-icon"></i>
                                    <span>PO</span>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php if ($auth->hasPermission('cashbook')): ?>
                            <li class="nav-item">
                                <a href="<?php echo BASE_URL; ?>/modules/cashbook/index.php" class="nav-link <?php echo activeMenu('cashbook'); ?>">
                                    <i data-feather="book" class="nav-icon"></i>
                                    <span><?php echo __('cashbook.title'); ?></span>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php if ($auth->hasPermission('divisions')): ?>
                            <li class="nav-item">
                                <a href="<?php echo BASE_URL; ?>/modules/divisions/index.php" class="nav-link <?php echo activeMenu('divisions'); ?>">
                                    <i data-feather="grid" class="nav-icon"></i>
                                    <span><?php echo __('settings.divisions'); ?></span>
                                </a>
                            </li>
                        <?php endif; ?>

                        <li class="nav-group-label">Operasional</li>
                        <?php if ($auth->hasPermission('frontdesk') && isModuleEnabled('frontdesk')): ?>
                            <style>
                                .fd-unpaid-dot {
                                    display: inline-block;
                                    width: 9px;
                                    height: 9px;
                                    margin-left: 6px;
                                    background: #ef4444;
                                    border-radius: 50%;
                                    animation: fd-unpaid-blink 1.1s ease-in-out infinite;
                                }

                                @keyframes fd-unpaid-blink {

                                    0%,
                                    100% {
                                        opacity: 1;
                                        box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.6);
                                    }

                                    50% {
                                        opacity: 0.35;
                                        box-shadow: 0 0 6px 3px rgba(239, 68, 68, 0.6);
                                    }
                                }
                            </style>
                            <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/frontdesk/') !== false && strpos($_SERVER['REQUEST_URI'], 'hotel-services.php') === false && strpos($_SERVER['REQUEST_URI'], 'rental-motor.php') === false) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo (strpos($_SERVER['REQUEST_URI'], 'hotel-services.php') === false && strpos($_SERVER['REQUEST_URI'], 'rental-motor.php') === false) ? activeMenu('frontdesk') : ''; ?>">
                                    <i data-feather="home" data-icon="concierge-bell" class="nav-icon"></i>
                                    <span><?php echo __('menu.frontdesk'); ?></span>
                                    <?php if (!empty($unpaidGuestsCount)): ?>
                                        <span class="fd-unpaid-dot" title="<?php echo $unpaidGuestsCount; ?> tamu belum lunas"></span>
                                    <?php endif; ?>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/dashboard.php" class="submenu-link <?php echo activeMenu('dashboard.php'); ?>">
                                            <i data-feather="layout" class="submenu-icon"></i>
                                            <span><?php echo __('dashboard.title'); ?></span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/reservasi.php" class="submenu-link <?php echo activeMenu('reservasi.php'); ?>">
                                            <i data-feather="calendar" class="submenu-icon"></i>
                                            <span><?php echo __('menu.reservations'); ?></span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/calendar.php" class="submenu-link <?php echo activeMenu('calendar.php'); ?>">
                                            <i data-feather="grid" class="submenu-icon"></i>
                                            <span><?php echo __('menu.calendar'); ?></span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/in-house.php" class="submenu-link <?php echo activeMenu('in-house.php'); ?>">
                                            <i data-feather="users" class="submenu-icon"></i>
                                            <span><?php echo __('menu.in_house'); ?></span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/hk-allocation.php" class="submenu-link <?php echo activeMenu('hk-allocation.php'); ?>">
                                            <i data-feather="check-square" class="submenu-icon"></i>
                                            <span>Pembagian HK</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/breakfast.php"
                                            class="submenu-link <?php echo activeMenu('breakfast.php'); ?>"
                                            onclick="console.log('Breakfast link clicked!'); return true;">
                                            <i data-feather="coffee" class="submenu-icon"></i>
                                            <span><?php echo __('menu.breakfast'); ?></span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/laporan.php" class="submenu-link <?php echo activeMenu('laporan.php'); ?>">
                                            <i data-feather="file-text" class="submenu-icon"></i>
                                            <span><?php echo __('menu.reports'); ?></span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/settings.php" class="submenu-link <?php echo activeMenu('settings.php'); ?>">
                                            <i data-feather="settings" class="submenu-icon"></i>
                                            <span><?php echo __('settings.title'); ?></span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <!-- Hotel Services Menu (hotel only) -->
                        <?php if (defined('BUSINESS_TYPE') && BUSINESS_TYPE === 'hotel' && $auth->hasPermission('frontdesk')): ?>
                            <li class="nav-item has-submenu <?php echo (activeMenu('hotel-services.php') || activeMenu('rental-motor.php') || activeMenu('rental-motor-dashboard.php')) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo (activeMenu('hotel-services.php') || activeMenu('rental-motor.php') || activeMenu('rental-motor-dashboard.php')) ? 'active' : ''; ?>">
                                    <i data-feather="briefcase" class="nav-icon"></i>
                                    <span>Hotel Services</span>
                                    <?php if (!empty($unpaidHotelServices)): ?>
                                        <span class="nav-menu-dot" title="Ada tagihan belum lunas"></span>
                                    <?php endif; ?>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/hotel-services.php" class="submenu-link <?php echo activeMenu('hotel-services.php'); ?>">
                                            <i data-feather="file-text" class="submenu-icon"></i>
                                            <span>Invoice & Layanan</span>
                                            <?php if (!empty($unpaidHotelServices)): ?>
                                                <span class="nav-menu-dot" title="Ada tagihan belum lunas"></span>
                                            <?php endif; ?>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/rental-motor-dashboard.php" class="submenu-link <?php echo (activeMenu('rental-motor.php') || activeMenu('rental-motor-dashboard.php')) ? 'active' : ''; ?>">
                                            <i data-feather="truck" class="submenu-icon"></i>
                                            <span>Rental Motor</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <!-- Sales Invoice Menu (CQC only, not for hotel) -->
                        <?php if ($auth->hasPermission('sales_invoice') && isModuleEnabled('sales') && (!defined('BUSINESS_TYPE') || BUSINESS_TYPE !== 'hotel')): ?>
                            <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/sales/') !== false) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('sales'); ?>">
                                    <i data-feather="file-text" class="nav-icon"></i>
                                    <span><?php echo __('menu.sales_invoice'); ?></span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/sales/index-cqc.php?tab=termin" class="submenu-link <?php echo (strpos($_SERVER['REQUEST_URI'], 'index-cqc') !== false && ($_GET['tab'] ?? '') === 'termin') ? 'active' : ''; ?>">
                                            <i data-feather="file" class="submenu-icon"></i>
                                            <span>Invoice Termin</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/sales/index-cqc.php?tab=general" class="submenu-link <?php echo (strpos($_SERVER['REQUEST_URI'], 'index-cqc') !== false && ($_GET['tab'] ?? '') === 'general') ? 'active' : ''; ?>">
                                            <i data-feather="file-text" class="submenu-icon"></i>
                                            <span>Invoice Umum</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/sales/index-cqc.php?tab=quotation" class="submenu-link <?php echo (strpos($_SERVER['REQUEST_URI'], 'index-cqc') !== false && ($_GET['tab'] ?? '') === 'quotation') ? 'active' : ''; ?>">
                                            <i data-feather="clipboard" class="submenu-icon"></i>
                                            <span>Quotation</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <!-- Bills / Tagihan Menu -->
                        <?php if ($auth->hasPermission('bills')): ?>
                            <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/bills/') !== false) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('bills'); ?>">
                                    <i data-feather="credit-card" class="nav-icon"></i>
                                    <span>Tagihan</span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/bills/index.php" class="submenu-link <?php echo activeMenu('bills/index'); ?>">
                                            <i data-feather="list" class="submenu-icon"></i>
                                            <span>Daftar Tagihan</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/bills/templates.php" class="submenu-link <?php echo activeMenu('templates.php'); ?>">
                                            <i data-feather="layers" class="submenu-icon"></i>
                                            <span>Template Rutin</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/bills/business-warehouse.php" class="submenu-link <?php echo activeMenu('business-warehouse'); ?>">
                                            <i data-feather="repeat" class="submenu-icon"></i>
                                            <span>Tagihan Bisnis & Gudang</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <!-- Cafe Invoice Menu (Bens Cafe only) -->
                        <?php if (isModuleEnabled('cafe-invoice') && $auth->hasPermission('cafe_invoice')): ?>
                            <li class="nav-item <?php echo (strpos($_SERVER['REQUEST_URI'], '/cafe-invoice/') !== false) ? 'open' : ''; ?>">
                                <a href="<?php echo BASE_URL; ?>/modules/cafe-invoice/index.php" class="nav-link <?php echo activeMenu('cafe-invoice'); ?>">
                                    <i data-feather="file-text" class="nav-icon"></i>
                                    <span>☕ Invoice</span>
                                </a>
                            </li>
                        <?php endif; ?>

                        <!-- Payroll Menu -->
                        <?php if ($auth->hasPermission('payroll') && isModuleEnabled('payroll')): ?>
                            <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/payroll/') !== false) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('payroll'); ?>">
                                    <i data-feather="dollar-sign" class="nav-icon"></i>
                                    <span>Payroll</span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/payroll/employees.php" class="submenu-link <?php echo activeMenu('employees.php'); ?>">
                                            <i data-feather="users" class="submenu-icon"></i>
                                            <span>Employee Data</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/payroll/process.php" class="submenu-link <?php echo activeMenu('process.php'); ?>">
                                            <i data-feather="monitor" class="submenu-icon"></i>
                                            <span>Process Salary</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/payroll/attendance.php" class="submenu-link <?php echo activeMenu('attendance.php'); ?>">
                                            <i data-feather="map-pin" class="submenu-icon"></i>
                                            <span>Absensi GPS</span>
                                        </a>
                                    </li>

                                </ul>
                            </li>
                        <?php endif; ?>

                        <?php $showPurchaseMenu = (!$isGudangNasitaContext && ($auth->hasPermission('procurement_po') || $auth->hasPermission('procurement_stock'))); ?>
                        <?php if ($showPurchaseMenu): ?>
                            <li class="nav-item has-submenu <?php echo (activeMenu('purchase-orders.php') || activeMenu('business-stock-incoming.php')) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo (activeMenu('purchase-orders.php') || activeMenu('business-stock-incoming.php')) ? 'active' : ''; ?>">
                                    <i data-feather="shopping-bag" class="nav-icon"></i>
                                    <span>Purchase</span>
                                </a>
                                <ul class="submenu">
                                    <?php if ($auth->hasPermission('procurement_po')): ?>
                                        <li class="submenu-item">
                                            <a href="<?php echo BASE_URL; ?>/modules/procurement/purchase-orders.php" class="submenu-link <?php echo activeMenu('purchase-orders.php'); ?>">
                                                <i data-feather="clipboard" class="submenu-icon"></i>
                                                <span>PO Gudang</span>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($auth->hasPermission('procurement_stock')): ?>
                                        <li class="submenu-item">
                                            <a href="<?php echo BASE_URL; ?>/modules/procurement/business-stock-incoming.php" class="submenu-link <?php echo activeMenu('business-stock-incoming.php'); ?>">
                                                <i data-feather="inbox" class="submenu-icon"></i>
                                                <span>Stock Gudang</span>
                                            </a>
                                        </li>
                                        <li class="submenu-item">
                                            <a href="<?php echo BASE_URL; ?>/modules/procurement/staff-stock-access.php" class="submenu-link <?php echo activeMenu('staff-stock-access.php'); ?>">
                                                <i data-feather="user-check" class="submenu-icon"></i>
                                                <span>Akses Stock Staff</span>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <!-- Gudang Nasita Menu (Warehouse) -->
                        <?php if (ACTIVE_BUSINESS_ID === 'gudang-nasita' && ($auth->hasPermission('gudang_view') || $auth->hasPermission('warehouse'))): ?>
                            <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/modules/gudang/') !== false) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo (strpos($_SERVER['REQUEST_URI'], '/modules/gudang/') !== false) ? 'active' : ''; ?>">
                                    <i data-feather="archive" class="nav-icon"></i>
                                    <span>Gudang Nasita</span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/gudang/dashboard.php" class="submenu-link <?php echo activeMenu('dashboard.php'); ?>">
                                            <i data-feather="home" class="submenu-icon"></i>
                                            <span>Dashboard</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/gudang/barang.php" class="submenu-link <?php echo activeMenu('barang.php'); ?>">
                                            <i data-feather="package" class="submenu-icon"></i>
                                            <span>Master Barang</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/gudang/stock.php" class="submenu-link <?php echo activeMenu('stock.php'); ?>">
                                            <i data-feather="layers" class="submenu-icon"></i>
                                            <span>Kelola Stok</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/gudang/po-supplier.php" class="submenu-link <?php echo activeMenu('po-supplier.php'); ?>">
                                            <i data-feather="file-text" class="submenu-icon"></i>
                                            <span>PO Supplier</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/gudang/transfer.php" class="submenu-link <?php echo activeMenu('transfer.php'); ?>">
                                            <i data-feather="arrow-right" class="submenu-icon"></i>
                                            <span>Transfer ke Bisnis</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/gudang/minimum-stock.php" class="submenu-link <?php echo activeMenu('minimum-stock.php'); ?>">
                                            <i data-feather="alert-circle" class="submenu-icon"></i>
                                            <span>Minimum Stock Alert</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/gudang/reports.php" class="submenu-link <?php echo activeMenu('reports.php'); ?>">
                                            <i data-feather="bar-chart-2" class="submenu-icon"></i>
                                            <span>Laporan Gudang</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <li class="nav-group-label">Keuangan &amp; Laporan</li>
                        <!-- Laporan Dropdown Menu -->
                        <?php if ($auth->hasPermission('reports') && isModuleEnabled('reports')): ?>
                            <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/reports/') !== false) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('reports'); ?>">
                                    <i data-feather="bar-chart-2" class="nav-icon"></i>
                                    <span><?php echo __('menu.reports'); ?></span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/reports/daily.php" class="submenu-link <?php echo activeMenu('daily.php'); ?>">
                                            <i data-feather="calendar" class="submenu-icon"></i>
                                            <span>Laporan Harian</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/reports/monthly.php" class="submenu-link <?php echo activeMenu('monthly.php'); ?>">
                                            <i data-feather="trending-up" class="submenu-icon"></i>
                                            <span>Laporan Bulanan</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/reports/yearly.php" class="submenu-link <?php echo activeMenu('yearly.php'); ?>">
                                            <i data-feather="activity" class="submenu-icon"></i>
                                            <span>Laporan Tahunan</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/reports/by-division.php" class="submenu-link <?php echo activeMenu('by-division.php'); ?>">
                                            <i data-feather="grid" class="submenu-icon"></i>
                                            <span>Laporan Per Divisi</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <!-- Project Menu -->
                        <?php if ($auth->hasPermission('project')): ?>
                            <li class="nav-item">
                                <a href="<?php echo BASE_URL; ?>/modules/project/" class="nav-link <?php echo activeMenu('project'); ?>">
                                    <i data-feather="folder" class="nav-icon"></i>
                                    <span>Project</span>
                                </a>
                            </li>
                        <?php endif; ?>


                        <!-- Finance Menu -->
                        <?php if ($auth->hasPermission('finance')): ?>
                            <li class="nav-item">
                                <a href="<?php echo BASE_URL; ?>/modules/finance/" class="nav-link <?php echo activeMenu('finance'); ?>">
                                    <i data-feather="trending-up" class="nav-icon"></i>
                                    <span>Manajemen Keuangan</span>
                                </a>
                            </li>
                        <?php endif; ?>

                        <!-- Owner Monitoring Menu -->
                        <?php if ($auth->hasPermission('owner')): ?>
                            <li class="nav-item">
                                <a href="<?php echo BASE_URL; ?>/modules/owner/" class="nav-link <?php echo activeMenu('owner'); ?>">
                                    <i data-feather="eye" class="nav-icon"></i>
                                    <span>Owner Monitoring</span>
                                </a>
                            </li>
                        <?php endif; ?>

                        <li class="nav-group-label">Sistem</li>
                        <!-- Database Master Menu (CQC) -->
                        <?php if ($auth->hasPermission('database')): ?>
                            <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/database/') !== false) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('database'); ?>">
                                    <i data-feather="database" class="nav-icon"></i>
                                    <span>Database</span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/database/" class="submenu-link">
                                            <i data-feather="home" class="submenu-icon"></i>
                                            <span>Dashboard</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/database/suppliers.php" class="submenu-link">
                                            <i data-feather="truck" class="submenu-icon"></i>
                                            <span>Supplier</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/database/customers.php" class="submenu-link">
                                            <i data-feather="users" class="submenu-icon"></i>
                                            <span>Customer</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/database/staff.php" class="submenu-link">
                                            <i data-feather="user-check" class="submenu-icon"></i>
                                            <span>Staf</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <?php if ($auth->hasPermission('settings')): ?>
                            <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/settings/') !== false) ? 'open' : ''; ?>" style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid var(--bg-tertiary);">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('settings'); ?>">
                                    <i data-feather="settings" class="nav-icon"></i>
                                    <span><?php echo __('settings.title'); ?></span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/settings/" class="submenu-link <?php echo activeMenu('settings-index'); ?>">
                                            <i data-feather="home" class="submenu-icon"></i>
                                            <span>Beranda Settings</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/settings/change-password.php" class="submenu-link <?php echo activeMenu('change-password.php'); ?>">
                                            <i data-feather="lock" class="submenu-icon"></i>
                                            <span>Ganti Password</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/settings/company.php" class="submenu-link <?php echo activeMenu('company.php'); ?>">
                                            <i data-feather="briefcase" class="submenu-icon"></i>
                                            <span>Setup Perusahaan</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/settings/display.php" class="submenu-link <?php echo activeMenu('display.php'); ?>">
                                            <i data-feather="eye" class="submenu-icon"></i>
                                            <span>Display & Theme</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <?php
                        $activeBizRaw = (string)($_SESSION['active_business_id'] ?? (defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : ''));
                        $activeBizNorm = strtolower((string)preg_replace('/[^a-z0-9]/', '', $activeBizRaw));
                        $menuBookBizNorm = ['narayanahotel', 'benscafe', 'eaatmeet', 'eatmeet'];
                        $isMenuBookBiz = in_array($activeBizNorm, $menuBookBizNorm, true);
                        $isDeveloperRole = (($_SESSION['role'] ?? '') === 'developer');
                        if ($isMenuBookBiz && ($isDeveloperRole || $auth->hasPermission('menu_book'))):
                        ?>
                            <li class="nav-item">
                                <a href="<?php echo BASE_URL; ?>/modules/menu-book/index.php" class="nav-link <?php echo (strpos($_SERVER['REQUEST_URI'], '/menu-book/') !== false) ? 'active' : ''; ?>">
                                    <i data-feather="book-open" class="nav-icon"></i>
                                    <span>Buku Menu</span>
                                </a>
                            </li>
                        <?php endif; ?>

                        <!-- Email Kantor (office@narayanakarimunjawa.com) -->
                        <?php if ($activeBizNorm === 'narayanahotel' && ($isDeveloperRole || $auth->hasPermission('email'))): ?>
                            <li class="nav-item">
                                <a href="<?php echo BASE_URL; ?>/modules/email/index.php" class="nav-link <?php echo (strpos($_SERVER['REQUEST_URI'], '/modules/email/') !== false) ? 'active' : ''; ?>" style="position:relative;">
                                    <i data-feather="mail" class="nav-icon"></i>
                                    <span>Email Kantor</span>
                                    <span id="emailUnreadDot" style="display:none;position:absolute;left:26px;top:10px;width:9px;height:9px;border-radius:50%;background:#ef4444;border:1.5px solid #fff;"></span>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Tagihan Langganan ADF System (bisnis ini membayar langganan bulanan ke ADF) -->
                    <?php if (in_array($_SESSION['role'] ?? '', ['developer', 'owner', 'admin', 'manager'], true) && (($adfsubState['connected'] ?? false) || ($_SESSION['role'] ?? '') === 'developer')): ?>
                        <li class="nav-item">
                            <a href="<?php echo BASE_URL; ?>/modules/subscription/index.php" class="nav-link <?php echo (strpos($_SERVER['REQUEST_URI'], '/modules/subscription/') !== false) ? 'active' : ''; ?>">
                                <i data-feather="file-text" class="nav-icon"></i>
                                <span>Tagihan Langganan</span>
                                <?php if (!empty($adfsubState['unpaid'])): ?>
                                    <span style="margin-left:auto;background:#ef4444;color:#fff;-webkit-text-fill-color:#fff;border-radius:99px;font-size:10px;padding:1px 6px;"><?php echo count($adfsubState['unpaid']); ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endif; ?>


                    <li class="nav-item" style="margin-top: 2rem;">
                        <a href="<?php echo BASE_URL; ?>/logout.php" class="nav-link">
                            <i data-feather="log-out" class="nav-icon"></i>
                            <span><?php echo __('menu.logout'); ?></span>
                        </a>
                    </li>
                </ul>
            </nav>

            <!-- Sidebar Footer -->
            <?php
            // Get custom footer version from settings
            $footerVersionSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'footer_version'");
            $sidebarFooterVersion = $footerVersionSetting['setting_value'] ?? ('Version ' . APP_VERSION);
            ?>
            <div class="sidebar-footer" style="padding: 0.5rem 0.9rem; border-top: 1px solid var(--bg-tertiary); margin-top: auto; text-align: center;">
                <div style="font-size: 0.72rem; color: var(--text-muted); line-height: 1.6;">
                    <span style="font-weight: 600; color: var(--text-primary); font-size: 0.75rem;"><?php echo DEVELOPER_NAME; ?></span><br>
                    <?php echo htmlspecialchars($sidebarFooterVersion); ?> &bull; <?php echo APP_YEAR; ?>
                </div>
            </div>
        </aside>
        <!-- Ikon sidebar memakai Lucide (penerus Feather). Ikon ditandai ulang SEBELUM feather.replace()
             berjalan, lalu digambar saat Lucide selesai dimuat (async). Bila Lucide gagal dimuat atau nama
             ikon tidak dikenal, ikon dikembalikan ke Feather supaya tidak ada ikon yang hilang. -->
        <script>
            (function() {
                var map = {
                    'home': 'house', 'grid': 'layout-grid', 'clipboard': 'clipboard-list', 'book': 'book-open-text',
                    'book-open': 'book-open', 'bar-chart-2': 'chart-column', 'file-text': 'file-text', 'file': 'file',
                    'users': 'users', 'user-check': 'user-check', 'truck': 'truck', 'trending-up': 'trending-up',
                    'settings': 'settings', 'layers': 'layers', 'eye': 'eye', 'credit-card': 'credit-card',
                    'calendar': 'calendar-days', 'briefcase': 'briefcase-business', 'sun': 'sun',
                    'shopping-bag': 'shopping-bag', 'shopping-cart': 'shopping-cart', 'repeat': 'repeat',
                    'plus-circle': 'circle-plus', 'package': 'package', 'monitor': 'monitor', 'map-pin': 'map-pin',
                    'mail': 'mail', 'log-out': 'log-out', 'log-in': 'log-in', 'lock': 'lock', 'list': 'list',
                    'layout': 'layout-dashboard', 'inbox': 'inbox', 'folder': 'folder-open', 'dollar-sign': 'wallet',
                    'database': 'database', 'coffee': 'coffee', 'check-square': 'square-check-big',
                    'arrow-right': 'arrow-right', 'archive': 'warehouse', 'alert-circle': 'circle-alert',
                    'activity': 'activity', 'download': 'download', 'send': 'send'
                };
                var icons = document.querySelectorAll('.sidebar i[data-feather]');
                icons.forEach(function(el) {
                    var name = el.getAttribute('data-icon') || map[el.getAttribute('data-feather')];
                    if (!name) return;
                    el.setAttribute('data-feather-fallback', el.getAttribute('data-feather'));
                    el.removeAttribute('data-feather');
                    el.setAttribute('data-lucide', name);
                });

                function fallbackToFeather() {
                    document.querySelectorAll('.sidebar i[data-lucide]').forEach(function(el) {
                        el.setAttribute('data-feather', el.getAttribute('data-feather-fallback') || 'circle');
                        el.removeAttribute('data-lucide');
                    });
                    if (window.feather) feather.replace();
                }

                var s = document.createElement('script');
                s.src = 'https://unpkg.com/lucide@0.469.0/dist/umd/lucide.min.js';
                s.async = true;
                s.onload = function() {
                    try {
                        lucide.createIcons({ attrs: { 'stroke-width': 1.75 } });
                    } catch (e) {}
                    // Nama ikon yang tidak dikenal Lucide masih berupa <i data-lucide> -> pakai Feather.
                    fallbackToFeather();
                };
                s.onerror = fallbackToFeather;
                document.head.appendChild(s);

                // Sembunyikan label grup yang tidak punya menu (tergantung hak akses role).
                document.querySelectorAll('.sidebar .nav-group-label').forEach(function(label) {
                    var el = label.nextElementSibling, hasItem = false;
                    while (el && !el.classList.contains('nav-group-label')) {
                        if (el.classList.contains('nav-item')) { hasItem = true; break; }
                        el = el.nextElementSibling;
                    }
                    if (!hasItem) label.style.display = 'none';
                });
            })();
        </script>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top bar ringkas: tinggi & ukuran teks diseragamkan dengan kartu dashboard.
                 !important dipakai karena elemen top bar memakai style inline. -->
            <style>
                .main-content > .top-bar {
                    position: relative;
                    padding: 0.55rem 1rem 0.55rem 1.15rem;
                    margin-bottom: 0.9rem;
                    border-radius: 12px;
                    overflow: hidden;
                    background: linear-gradient(135deg, #ffffff 0%, #f5f8ff 100%) !important;
                    border: 1px solid rgba(148, 163, 184, 0.32);
                    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 8px 22px -12px rgba(15, 23, 42, 0.22);
                }

                /* Aksen navy tipis di kiri: batas & identitas header terlihat jelas */
                .main-content > .top-bar::before {
                    content: '';
                    position: absolute;
                    left: 0;
                    top: 0;
                    bottom: 0;
                    width: 4px;
                    background: linear-gradient(180deg, #1e3a8a, #2563eb);
                }

                body[data-theme="dark"] .main-content > .top-bar {
                    background: linear-gradient(135deg, rgba(30, 41, 59, 0.92), rgba(15, 23, 42, 0.92)) !important;
                    border-color: rgba(148, 163, 184, 0.2);
                    box-shadow: 0 10px 26px -14px rgba(0, 0, 0, 0.7);
                }

                /* Tema gelap: judul & nama user (warna navy inline) dibuat terang agar terbaca */
                body[data-theme="dark"] .main-content > .top-bar .page-title,
                body[data-theme="dark"] .main-content > .top-bar .user-info > div:first-child > div:first-child {
                    color: #e2e8f0 !important;
                    -webkit-text-fill-color: #e2e8f0 !important;
                }

                body[data-theme="dark"] .main-content > .top-bar .user-info > div:first-child > div:last-child {
                    color: #93c5fd !important;
                    -webkit-text-fill-color: #93c5fd !important;
                }

                /* Kartu konten utama (grafik dashboard dll): tepi tipis agar tidak menyatu dengan latar */
                body[data-theme="light"] #tradingChartCard {
                    border: 1px solid rgba(148, 163, 184, 0.32) !important;
                    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 10px 26px -14px rgba(15, 23, 42, 0.22) !important;
                }

                .main-content > .top-bar .page-title {
                    font-size: 0.9rem !important;
                    line-height: 1.25;
                }

                .main-content > .top-bar > div:first-child > p {
                    margin-top: 0.1rem !important;
                    font-size: 0.64rem !important;
                }

                .main-content > .top-bar > div:last-child {
                    gap: 0.85rem !important;
                }

                #endShiftButton {
                    padding: 0.3rem 0.7rem !important;
                    font-size: 0.7rem !important;
                    border-radius: 8px !important;
                    gap: 0.35rem !important;
                }

                #endShiftButton svg {
                    width: 13px !important;
                    height: 13px !important;
                }


                .main-content > .top-bar .adfsub-pro-pill,
                .main-content > .top-bar .adfsub-head-pill {
                    margin-right: 0;
                    padding: 3px 8px;
                    gap: 6px;
                    border-radius: 8px;
                }

                .main-content > .top-bar .adfsub-head-text small {
                    font-size: 8.5px;
                }

                .main-content > .top-bar .adfsub-head-text strong {
                    font-size: 10.5px;
                }

                .main-content > .top-bar .adfsub-head-cta {
                    font-size: 10px;
                    padding: 3px 7px;
                }

                .main-content > .top-bar .adfsub-pro-dot,
                .main-content > .top-bar .adfsub-head-dot {
                    width: 6px;
                    height: 6px;
                }

                #currentDate {
                    font-size: 0.66rem !important;
                }

                #currentTime {
                    font-size: 0.72rem !important;
                }

                #currentDate,
                #currentTime {
                    line-height: 1.25;
                }

                .main-content > .top-bar div:has(> #currentDate) {
                    padding-right: 0.85rem !important;
                }

                .main-content > .top-bar .user-info > div:first-child {
                    margin-right: 0.6rem !important;
                    line-height: 1.25;
                }

                .main-content > .top-bar .user-info > div:first-child > div:first-child {
                    font-size: 0.74rem !important;
                }

                .main-content > .top-bar .user-info > div:first-child > div:last-child {
                    font-size: 0.64rem !important;
                }

                .main-content > .top-bar .user-avatar-button {
                    width: 32px;
                    height: 32px;
                    font-size: 0.8rem;
                }

                .main-content > .top-bar .user-avatar-edit-indicator {
                    transform: scale(0.8);
                    transform-origin: bottom right;
                }
            </style>
            <!-- Top Bar -->
            <div class="top-bar">
                <div>
                    <h1 class="page-title" style="color:#1e3a8a;-webkit-text-fill-color:#1e3a8a;background:none;"><?php echo $pageTitle ?? 'Dashboard'; ?></h1>
                    <?php if (isset($pageSubtitle)): ?>
                        <p style="color: var(--text-muted); margin-top: 0.5rem;"><?php echo $pageSubtitle; ?></p>
                    <?php endif; ?>
                </div>

                <div style="display: flex; align-items: center; gap: 1.5rem;">
                    <!-- End Shift Button -->
                    <a id="endShiftButton" href="<?php echo BASE_URL; ?>/print-end-shift-report.php" target="_blank" rel="noopener"
                        style="padding: 0.5rem 1rem; background: #991b1b; color: #ffffff; border: 1px solid #7f1d1d; border-radius: 6px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; transition: all 0.2s; text-decoration: none;">
                        <i data-feather="power" style="width: 18px; height: 18px;"></i>
                        <span>End Shift</span>
                    </a>

                    <!-- Notification Bell: tombol tersendiri; panel dipindah ke <body> oleh JS
                         (top bar ber-overflow:hidden memotong panel bila tetap di dalamnya). -->
                    <button type="button" id="adminNotifBell" class="tb-bell" onclick="toggleAdminNotif()" aria-label="Notifikasi" title="Notifikasi">
                        <i data-feather="bell"></i>
                        <span id="adminNotifBadge" class="tb-bell-badge"></span>
                    </button>

                    <!-- Notification Panel -->
                    <div id="adminNotifPanel" class="np-panel" role="dialog" aria-label="Notifikasi">
                        <div class="np-head">
                            <div>
                                <b>Notifikasi</b>
                                <small>Pengajuan staf menunggu persetujuan</small>
                            </div>
                            <span id="adminNotifCount" class="np-count">0</span>
                            <button type="button" class="np-close" onclick="toggleAdminNotif(false)" aria-label="Tutup">&times;</button>
                        </div>
                        <div id="adminNotifList" class="np-list">
                            <div class="np-empty">Memuat…</div>
                        </div>
                    </div>
                    <style>
                        .main-content > .top-bar .tb-bell {
                            position: relative;
                            width: 34px;
                            height: 34px;
                            margin: 0 0.15rem 0 0.35rem;
                            display: grid;
                            place-items: center;
                            border-radius: 10px;
                            border: 1px solid rgba(148, 163, 184, 0.35);
                            background: rgba(255, 255, 255, 0.75);
                            color: #475569;
                            cursor: pointer;
                            transition: background 0.15s, border-color 0.15s, color 0.15s;
                        }

                        .main-content > .top-bar .tb-bell:hover,
                        .main-content > .top-bar .tb-bell.open {
                            background: #eef2ff;
                            border-color: #93c5fd;
                            color: #1d4ed8;
                        }

                        body[data-theme="dark"] .main-content > .top-bar .tb-bell {
                            background: rgba(255, 255, 255, 0.06);
                            border-color: rgba(255, 255, 255, 0.14);
                            color: #cbd5e1;
                        }

                        body[data-theme="dark"] .main-content > .top-bar .tb-bell:hover,
                        body[data-theme="dark"] .main-content > .top-bar .tb-bell.open {
                            background: rgba(59, 130, 246, 0.18);
                            color: #93c5fd;
                        }

                        .main-content > .top-bar .tb-bell svg {
                            width: 17px !important;
                            height: 17px !important;
                            color: inherit !important;
                            stroke: currentColor !important;
                        }

                        .tb-bell-badge {
                            position: absolute;
                            top: -6px;
                            right: -6px;
                            display: none;
                            align-items: center;
                            justify-content: center;
                            min-width: 17px;
                            height: 17px;
                            padding: 0 4px;
                            border-radius: 9px;
                            border: 2px solid #fff;
                            background: #ef4444;
                            color: #fff !important;
                            -webkit-text-fill-color: #fff !important;
                            font-size: 0.55rem;
                            font-weight: 800;
                            line-height: 1;
                        }

                        /* Panel notifikasi (di <body>, posisi fixed di bawah lonceng) */
                        .np-panel {
                            --np-bg: #ffffff;
                            --np-ink: #0f172a;
                            --np-muted: #64748b;
                            --np-line: #e2e8f0;
                            --np-soft: #f8fafc;
                            position: fixed;
                            z-index: 10040;
                            display: none;
                            width: 380px;
                            max-width: calc(100vw - 24px);
                            max-height: min(70vh, 560px);
                            flex-direction: column;
                            border-radius: 16px;
                            background: var(--np-bg);
                            border: 1px solid var(--np-line);
                            box-shadow: 0 24px 60px -16px rgba(15, 23, 42, 0.4);
                            overflow: hidden;
                        }

                        body[data-theme="dark"] .np-panel {
                            --np-bg: #111a2e;
                            --np-ink: #e2e8f0;
                            --np-muted: #94a3b8;
                            --np-line: rgba(255, 255, 255, 0.1);
                            --np-soft: rgba(255, 255, 255, 0.04);
                        }

                        .np-panel.show {
                            display: flex;
                            animation: npIn 0.14s ease-out;
                        }

                        @keyframes npIn {
                            from { opacity: 0; transform: translateY(-6px); }
                            to { opacity: 1; transform: none; }
                        }

                        .np-panel * {
                            -webkit-text-fill-color: currentColor;
                        }

                        .np-head {
                            display: flex;
                            align-items: center;
                            gap: 10px;
                            padding: 14px 16px 12px;
                            border-bottom: 1px solid var(--np-line);
                        }

                        .np-head > div {
                            flex: 1;
                            min-width: 0;
                        }

                        .np-head b {
                            display: block;
                            font-size: 0.92rem;
                            color: var(--np-ink);
                        }

                        .np-head small {
                            display: block;
                            font-size: 0.7rem;
                            color: var(--np-muted);
                        }

                        .np-count {
                            display: none;
                            min-width: 22px;
                            padding: 2px 8px;
                            border-radius: 999px;
                            background: #fee2e2;
                            color: #b91c1c;
                            font-size: 0.68rem;
                            font-weight: 800;
                            text-align: center;
                        }

                        .np-close {
                            border: 0;
                            background: transparent;
                            color: var(--np-muted);
                            font-size: 22px;
                            line-height: 1;
                            cursor: pointer;
                            padding: 0 2px;
                        }

                        .np-list {
                            overflow-y: auto;
                            padding: 8px;
                        }

                        .np-empty {
                            padding: 34px 16px;
                            text-align: center;
                            font-size: 0.78rem;
                            color: var(--np-muted);
                        }

                        .np-empty .np-empty-ic {
                            width: 44px;
                            height: 44px;
                            margin: 0 auto 8px;
                            border-radius: 50%;
                            display: grid;
                            place-items: center;
                            background: rgba(5, 150, 105, 0.1);
                            color: #059669;
                            font-size: 1.2rem;
                        }

                        .np-item {
                            padding: 11px 12px;
                            margin-bottom: 6px;
                            border-radius: 12px;
                            background: var(--np-soft);
                            border: 1px solid var(--np-line);
                        }

                        .np-item-top {
                            display: flex;
                            align-items: center;
                            gap: 10px;
                        }

                        .np-av {
                            width: 34px;
                            height: 34px;
                            flex-shrink: 0;
                            border-radius: 50%;
                            display: grid;
                            place-items: center;
                            background: linear-gradient(135deg, #1e3a8a, #2563eb);
                            color: #fff;
                            font-size: 0.78rem;
                            font-weight: 700;
                        }

                        .np-who {
                            flex: 1;
                            min-width: 0;
                        }

                        .np-who b {
                            display: block;
                            overflow: hidden;
                            white-space: nowrap;
                            text-overflow: ellipsis;
                            font-size: 0.8rem;
                            color: var(--np-ink);
                        }

                        .np-who small {
                            display: block;
                            font-size: 0.68rem;
                            color: var(--np-muted);
                        }

                        .np-tag {
                            flex-shrink: 0;
                            padding: 2px 8px;
                            border-radius: 999px;
                            font-size: 0.6rem;
                            font-weight: 800;
                            letter-spacing: 0.03em;
                            text-transform: uppercase;
                        }

                        .np-tag.ot { background: rgba(217, 119, 6, 0.14); color: #b45309; }
                        .np-tag.leave { background: rgba(37, 99, 235, 0.12); color: #1d4ed8; }
                        .np-tag.sick { background: rgba(220, 38, 38, 0.1); color: #b91c1c; }
                        body[data-theme="dark"] .np-tag.ot { color: #fbbf24; }
                        body[data-theme="dark"] .np-tag.leave { color: #93c5fd; }
                        body[data-theme="dark"] .np-tag.sick { color: #fca5a5; }

                        .np-reason {
                            margin: 8px 0 0 44px;
                            font-size: 0.72rem;
                            line-height: 1.45;
                            color: var(--np-muted);
                        }

                        .np-actions {
                            display: grid;
                            grid-template-columns: 1fr 1fr;
                            gap: 6px;
                            margin: 10px 0 0 44px;
                        }

                        .np-btn {
                            height: 30px;
                            border-radius: 8px;
                            border: 1px solid transparent;
                            font-size: 0.72rem;
                            font-weight: 700;
                            cursor: pointer;
                        }

                        .np-btn.ok { background: #059669; color: #fff; }
                        .np-btn.ok:hover { background: #047857; }
                        .np-btn.no { background: transparent; border-color: rgba(220, 38, 38, 0.35); color: #b91c1c; }
                        .np-btn.no:hover { background: rgba(220, 38, 38, 0.08); }
                        .np-btn.ghost { background: transparent; border-color: var(--np-line); color: var(--np-muted); }
                        body[data-theme="dark"] .np-btn.no { color: #fca5a5; }
                        .np-btn:disabled { opacity: 0.6; cursor: wait; }

                        .np-decide {
                            margin: 10px 0 0 44px;
                        }

                        .np-decide textarea {
                            width: 100%;
                            min-height: 54px;
                            padding: 7px 9px;
                            border-radius: 8px;
                            border: 1px solid var(--np-line);
                            background: var(--np-bg);
                            color: var(--np-ink);
                            font: inherit;
                            font-size: 0.74rem;
                            resize: vertical;
                        }

                        .np-decide .np-actions {
                            margin-left: 0;
                        }

                        .np-done {
                            padding: 6px 0 2px 44px;
                            font-size: 0.74rem;
                            font-weight: 700;
                        }

                        .np-done.ok { color: #059669; }
                        .np-done.no { color: #b91c1c; }

                        /* style.css memaksa warna & latar elemen (kedua tema): kunci yang penting */
                        body[data-theme] .np-panel { background-color: var(--np-bg) !important; opacity: 1 !important; backdrop-filter: none !important; }
                        body[data-theme="dark"] .np-panel { background-color: #111a2e !important; }
                        body[data-theme] .np-panel .np-item { background-color: var(--np-soft) !important; }
                        body[data-theme="dark"] .np-panel .np-item { background-color: #18233a !important; }
                        body[data-theme] .np-panel .np-head b, body[data-theme] .np-panel .np-who b { color: var(--np-ink) !important; }
                        body[data-theme] .np-panel .np-head small, body[data-theme] .np-panel .np-who small, body[data-theme] .np-panel .np-reason, body[data-theme] .np-panel .np-empty { color: var(--np-muted) !important; }
                        body[data-theme] .np-panel .np-av, body[data-theme] .np-panel .np-btn.ok { color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; }
                        body[data-theme] .np-panel .np-count { color: #b91c1c !important; }
                        body[data-theme] .np-panel .np-tag.ot { color: #b45309 !important; }
                        body[data-theme] .np-panel .np-tag.leave { color: #1d4ed8 !important; }
                        body[data-theme] .np-panel .np-tag.sick, body[data-theme] .np-panel .np-btn.no { color: #b91c1c !important; }
                        body[data-theme="dark"] .np-panel .np-tag.ot { color: #fbbf24 !important; }
                        body[data-theme="dark"] .np-panel .np-tag.leave { color: #93c5fd !important; }
                        body[data-theme="dark"] .np-panel .np-tag.sick, body[data-theme="dark"] .np-panel .np-btn.no { color: #fca5a5 !important; }
                        body[data-theme] .np-panel .np-btn.ghost { color: var(--np-muted) !important; }
                        body[data-theme] .np-panel .np-decide textarea { background-color: var(--np-bg) !important; color: var(--np-ink) !important; }
                        body[data-theme] .np-panel .np-done.ok { color: #059669 !important; }
                        body[data-theme] .np-panel .np-done.no { color: #b91c1c !important; }
                    </style>

                    <?php if (!empty($adfsubState['sync_error']) && $adfsubRole === 'developer'): ?>
                        <!-- Developer: sinkron ke adfsystem.store gagal, kunci/tagihan tidak akan ter-update -->
                        <a href="<?php echo $adfsubBillingUrl; ?>" title="<?php echo htmlspecialchars($adfsubState['sync_error']); ?>" style="margin-right:1rem;padding:5px 10px;border-radius:10px;background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;-webkit-text-fill-color:#991b1b;font-size:11px;font-weight:600;text-decoration:none;white-space:nowrap;">⚠ Sinkron langganan gagal</a>
                    <?php endif; ?>
                    <!-- Tagihan langganan: tampil mulai H-7 jatuh tempo (tidak bisa ditutup) sampai lunas;
                         sebelum itu cukup label hijau "Subscribe Pro sampai ..." -->
                    <?php if (!empty($adfsubState['connected']) && empty($adfsubState['due_soon']) && !empty($adfsubState['active_until']) && $adfsubCanManage): ?>
                        <a href="<?php echo $adfsubBillingUrl; ?>" class="adfsub-pro-pill" title="Langganan ADF System aktif">
                            <span class="adfsub-pro-dot"></span>
                            <span class="adfsub-head-text">
                                <small>Subscribe Pro</small>
                                <strong>s/d <?php echo date('d M Y', strtotime($adfsubState['active_until'])); ?></strong>
                            </span>
                        </a>
                        <style>
                            .adfsub-pro-pill {
                                display: inline-flex;
                                align-items: center;
                                gap: 8px;
                                margin-right: 1rem;
                                padding: 5px 10px;
                                border-radius: 10px;
                                background: #f0fdf4;
                                border: 1px solid #86efac;
                                text-decoration: none !important;
                                white-space: nowrap;
                            }

                            .adfsub-pro-dot {
                                width: 8px;
                                height: 8px;
                                border-radius: 50%;
                                background: #16a34a;
                            }

                            .adfsub-pro-pill .adfsub-head-text {
                                display: flex;
                                flex-direction: column;
                                line-height: 1.15;
                            }

                            .adfsub-pro-pill .adfsub-head-text small {
                                font-size: 10px;
                                font-weight: 600;
                                color: #16a34a;
                                -webkit-text-fill-color: #16a34a;
                            }

                            .adfsub-pro-pill .adfsub-head-text strong {
                                font-size: 12.5px;
                                color: #15803d;
                                -webkit-text-fill-color: #15803d;
                            }
                        </style>
                    <?php elseif (!empty($adfsubState['connected']) && !empty($adfsubState['due_soon']) && $adfsubCanManage):
                        $adfsubHeadTotal = array_sum(array_map(static fn($i) => (float) $i['total_amount'], $adfsubState['due_soon']));
                        $adfsubHeadOverdue = false;
                        foreach ($adfsubState['due_soon'] as $adfsubU) {
                            if (!empty($adfsubU['due_date']) && $adfsubU['due_date'] < date('Y-m-d')) {
                                $adfsubHeadOverdue = true;
                            }
                        }
                    ?>
                        <a href="<?php echo $adfsubPayUrl; ?>" class="adfsub-head-pill<?php echo $adfsubHeadOverdue ? ' adfsub-head-overdue' : ''; ?>" title="Tagihan langganan ADF System belum dibayar">
                            <span class="adfsub-head-dot"></span>
                            <span class="adfsub-head-text">
                                <small><?php echo $adfsubHeadOverdue ? 'Tagihan lewat jatuh tempo' : 'Tagihan belum dibayar'; ?></small>
                                <strong>Rp <?php echo number_format($adfsubHeadTotal, 0, ',', '.'); ?></strong>
                            </span>
                            <span class="adfsub-head-cta">Bayar</span>
                        </a>
                        <style>
                            .adfsub-head-pill {
                                display: inline-flex;
                                align-items: center;
                                gap: 8px;
                                margin-right: 1rem;
                                padding: 5px 6px 5px 10px;
                                border-radius: 10px;
                                background: #fffbeb;
                                border: 1px solid #fcd34d;
                                text-decoration: none !important;
                                white-space: nowrap;
                            }

                            .adfsub-head-pill.adfsub-head-overdue {
                                background: #fef2f2;
                                border-color: #fca5a5;
                            }

                            .adfsub-head-dot {
                                width: 8px;
                                height: 8px;
                                border-radius: 50%;
                                background: #f59e0b;
                                animation: adfsubPulse 1.6s infinite;
                            }

                            .adfsub-head-overdue .adfsub-head-dot {
                                background: #dc2626;
                            }

                            .adfsub-head-text {
                                display: flex;
                                flex-direction: column;
                                line-height: 1.15;
                            }

                            .adfsub-head-text small {
                                font-size: 10px;
                                color: #92400e;
                                -webkit-text-fill-color: #92400e;
                            }

                            .adfsub-head-text strong {
                                font-size: 12.5px;
                                color: #78350f;
                                -webkit-text-fill-color: #78350f;
                            }

                            .adfsub-head-overdue .adfsub-head-text small,
                            .adfsub-head-overdue .adfsub-head-text strong {
                                color: #991b1b;
                                -webkit-text-fill-color: #991b1b;
                            }

                            .adfsub-head-cta {
                                background: #16a34a;
                                color: #fff;
                                -webkit-text-fill-color: #fff;
                                font-size: 11px;
                                font-weight: 700;
                                padding: 5px 9px;
                                border-radius: 7px;
                            }

                            @keyframes adfsubPulse {
                                0%, 100% { opacity: 1; }
                                50% { opacity: .35; }
                            }
                        </style>
                    <?php endif; ?>

                    <!-- Date & Time Display -->
                    <div style="text-align: right; padding-right: 1.5rem; border-right: 1px solid var(--bg-tertiary);">
                        <div style="font-size: 0.813rem; font-weight: 600; color: var(--text-primary);" id="currentDate">
                            <?php echo date('d/m/Y'); ?>
                        </div>
                        <div style="font-size: 0.875rem; font-weight: 700; color: var(--primary-color); font-variant-numeric: tabular-nums;" id="currentTime">
                            <?php echo date('H:i:s'); ?>
                        </div>
                    </div>

                    <!-- User Info -->
                    <div class="user-info">
                        <div style="text-align: right; margin-right: 1rem;">
                            <div style="font-weight: 600; color: #1e3a8a; -webkit-text-fill-color: #1e3a8a;">
                                <?php echo $_SESSION['full_name'] ?? 'User'; ?>
                            </div>
                            <div style="font-size: 0.875rem; color: #2563eb; -webkit-text-fill-color: #2563eb; opacity: 0.95;">
                                <?php echo ucfirst($_SESSION['role'] ?? 'staff'); ?>
                            </div>
                        </div>
                        <?php
                        $avatarUrl = isset($_SESSION['user_id']) ? adfGetUserAvatarUrl((int)$_SESSION['user_id']) : null;
                        $userInitial = strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1));
                        ?>
                        <div class="user-avatar-wrap" title="Klik untuk ganti foto profil">
                            <form id="topbarAvatarUploadForm" method="post" enctype="multipart/form-data" style="display:none;">
                                <input type="hidden" name="__upload_topbar_avatar" value="1">
                                <input id="topbarAvatarInput" type="file" name="avatar_file" accept="image/png,image/jpeg,image/webp,image/gif" onchange="console.log('Avatar file selected, submitting form...'); document.getElementById('topbarAvatarUploadForm').submit();">
                            </form>
                            <button type="button" class="user-avatar user-avatar-button" onclick="console.log('Avatar button clicked'); document.getElementById('topbarAvatarInput').click();" aria-label="Upload foto profil">
                                <?php if ($avatarUrl): ?>
                                    <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Foto Profil" class="user-avatar-image">
                                <?php else: ?>
                                    <?php echo $userInitial; ?>
                                <?php endif; ?>
                            </button>
                            <span class="user-avatar-edit-indicator">+</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php // Nama variabel khusus: dulu $success/$error milik halaman ikut tertimpa di sini.
            if ($flashSuccess = getFlash('success')): ?>
                <div class="alert alert-success fade-in" style="background: rgba(16, 185, 129, 0.1); border: 1px solid var(--success); color: var(--success); padding: 1rem; border-radius: var(--radius-lg); margin-bottom: 1.5rem;">
                    <i data-feather="check-circle" style="width: 20px; height: 20px; vertical-align: middle;"></i>
                    <?php echo $flashSuccess; ?>
                </div>
            <?php endif; ?>

            <?php if ($flashError = getFlash('error')): ?>
                <div class="alert alert-danger fade-in" style="background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger); color: var(--danger); padding: 1rem; border-radius: var(--radius-lg); margin-bottom: 1.5rem;">
                    <i data-feather="alert-circle" style="width: 20px; height: 20px; vertical-align: middle;"></i>
                    <?php echo $flashError; ?>
                </div>
            <?php endif; ?>

            <!-- Page Content -->
            <div class="page-content">

                <script>
                    // Business Switcher Function
                    async function switchBusiness(businessId) {
                        const bizSelect = document.querySelector('select[onchange*="switchBusiness"]');
                        const bizOpt = bizSelect ? bizSelect.querySelector('option[value="' + CSS.escape(businessId) + '"]') : null;
                        const bizName = bizOpt ? (bizOpt.dataset.name || bizOpt.textContent.trim()) : '';
                        const confirmed = await adfConfirm(
                            bizName ? 'Halaman akan dimuat ulang untuk membuka data ' + bizName + '.' : 'Current page will reload to load the selected business data.',
                            bizName ? 'Pindah ke ' + bizName + '?' : 'Switch Business?', {
                                logo: bizOpt ? bizOpt.dataset.logo : '',
                                fallbackText: bizOpt ? bizOpt.dataset.initials : '',
                                logoAlt: bizName
                            }
                        );
                        if (confirmed) {
                            // Send AJAX request to switch business
                            fetch('<?php echo BASE_URL; ?>/api/switch-business.php', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded',
                                    },
                                    body: 'business_id=' + encodeURIComponent(businessId)
                                })
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success) {
                                        // Redirect to business landing page for clearer workflow
                                        if (businessId === 'gudang-nasita') {
                                            window.location.href = '<?php echo BASE_URL; ?>/modules/gudang/dashboard.php';
                                        } else {
                                            window.location.href = '<?php echo BASE_URL; ?>/index.php';
                                        }
                                    } else {
                                        alert('Failed to switch business: ' + (data.message || 'Unknown error'));
                                    }
                                })
                                .catch(error => {
                                    console.error('Error:', error);
                                    alert('Failed to switch business. Please try again.');
                                });
                        } else {
                            // Reset select to current value
                            document.querySelector('select[onchange*="switchBusiness"]').value = '<?php echo ACTIVE_BUSINESS_ID; ?>';
                        }
                    }

                    // ═══ Admin Notification System ═══
                    let adminNotifOpen = false;
                    const NOTIF_BASE = '<?php echo BASE_URL; ?>';
                    const npEsc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

                    // Panel di <body> (top bar ber-overflow:hidden), posisi tepat di bawah lonceng.
                    function npPlace() {
                        const bell = document.getElementById('adminNotifBell');
                        const panel = document.getElementById('adminNotifPanel');
                        if (!bell || !panel) return;
                        if (panel.parentNode !== document.body) document.body.appendChild(panel);
                        const r = bell.getBoundingClientRect();
                        const w = Math.min(380, window.innerWidth - 24);
                        panel.style.top = (r.bottom + 10) + 'px';
                        panel.style.left = Math.max(12, Math.min(r.right - w + 40, window.innerWidth - w - 12)) + 'px';
                    }

                    function toggleAdminNotif(force) {
                        adminNotifOpen = typeof force === 'boolean' ? force : !adminNotifOpen;
                        const panel = document.getElementById('adminNotifPanel');
                        const bell = document.getElementById('adminNotifBell');
                        if (adminNotifOpen) {
                            npPlace();
                            panel.classList.add('show');
                            bell && bell.classList.add('open');
                            loadAdminNotifs();
                        } else {
                            panel.classList.remove('show');
                            bell && bell.classList.remove('open');
                        }
                    }

                    document.addEventListener('click', function(e) {
                        if (adminNotifOpen && !e.target.closest('#adminNotifBell') && !e.target.closest('#adminNotifPanel')) {
                            toggleAdminNotif(false);
                        }
                    });
                    document.addEventListener('keydown', e => { if (e.key === 'Escape' && adminNotifOpen) toggleAdminNotif(false); });
                    window.addEventListener('resize', () => { if (adminNotifOpen) npPlace(); });

                    function npItem(kind, id, name, tag, tagClass, line, reason) {
                        const initials = String(name || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0] || '').join('').toUpperCase();
                        return `<div class="np-item" id="notif-${kind}-${id}">
                            <div class="np-item-top">
                                <span class="np-av">${npEsc(initials)}</span>
                                <span class="np-who"><b>${npEsc(name)}</b><small>${npEsc(line)}</small></span>
                                <span class="np-tag ${tagClass}">${npEsc(tag)}</span>
                            </div>
                            ${reason ? `<div class="np-reason">${npEsc(reason)}</div>` : ''}
                            <div class="np-actions">
                                <button type="button" class="np-btn ok" onclick="npDecide('${kind}', ${id}, 'approve')">Setujui</button>
                                <button type="button" class="np-btn no" onclick="npDecide('${kind}', ${id}, 'reject')">Tolak</button>
                            </div>
                        </div>`;
                    }

                    async function loadAdminNotifs() {
                        const list = document.getElementById('adminNotifList');
                        try {
                            const res = await fetch(NOTIF_BASE + '/api/get-notifications.php?type=admin_pending');
                            const data = await res.json();
                            const leaves = data.pending_leaves || [];
                            const overtimes = data.pending_overtimes || [];

                            if (leaves.length + overtimes.length === 0) {
                                list.innerHTML = '<div class="np-empty"><div class="np-empty-ic">✓</div>Tidak ada pengajuan yang menunggu</div>';
                                return;
                            }

                            const leaveTag = { cuti: ['Cuti', 'leave'], sakit: ['Sakit', 'sick'], izin: ['Izin', 'leave'], cuti_khusus: ['Cuti Khusus', 'leave'] };
                            let html = '';
                            overtimes.forEach(o => {
                                html += npItem('ot', o.id, o.full_name, 'Lembur', 'ot', o.overtime_date, o.reason || '');
                            });
                            leaves.forEach(l => {
                                const t = leaveTag[l.leave_type] || [l.leave_type, 'leave'];
                                html += npItem('lv', l.id, l.full_name, t[0], t[1], l.start_date + (l.end_date && l.end_date !== l.start_date ? ' – ' + l.end_date : ''), l.reason || '');
                            });
                            list.innerHTML = html;
                        } catch (e) {
                            list.innerHTML = '<div class="np-empty">Gagal memuat notifikasi</div>';
                        }
                    }

                    // Setujui / Tolak: kolom catatan muncul di dalam kartu (pengganti prompt() bawaan browser).
                    function npDecide(kind, id, action) {
                        const item = document.getElementById('notif-' + kind + '-' + id);
                        if (!item) return;
                        const old = item.querySelector('.np-decide');
                        if (old) old.remove();
                        const isOk = action === 'approve';
                        const box = document.createElement('div');
                        box.className = 'np-decide';
                        box.innerHTML = `<textarea placeholder="${isOk ? 'Catatan (opsional)' : 'Alasan penolakan (opsional)'}"></textarea>
                            <div class="np-actions">
                                <button type="button" class="np-btn ghost">Batal</button>
                                <button type="button" class="np-btn ${isOk ? 'ok' : 'no'}">${isOk ? 'Konfirmasi setujui' : 'Konfirmasi tolak'}</button>
                            </div>`;
                        item.querySelector('.np-actions').style.display = 'none';
                        item.appendChild(box);
                        box.querySelector('textarea').focus();
                        box.querySelector('.ghost').onclick = () => { box.remove(); item.querySelector('.np-actions').style.display = ''; };
                        box.querySelector('.np-btn:not(.ghost)').onclick = function() {
                            this.disabled = true;
                            approveReject(kind === 'ot' ? 'overtime' : 'leave', action, id, box.querySelector('textarea').value);
                        };
                    }

                    async function approveReject(type, action, id, notes) {
                        const fd = new FormData();
                        if (type === 'overtime') {
                            fd.append('action', action === 'approve' ? 'approve_overtime' : 'reject_overtime');
                            fd.append('overtime_id', id);
                        } else {
                            fd.append('action', action === 'approve' ? 'approve_leave' : 'reject_leave');
                            fd.append('leave_id', id);
                        }
                        fd.append('admin_notes', notes || '');
                        const el = document.getElementById('notif-' + (type === 'overtime' ? 'ot' : 'lv') + '-' + id);
                        try {
                            const res = await fetch(NOTIF_BASE + '/api/get-notifications.php?type=admin_action', {
                                method: 'POST',
                                body: fd
                            });
                            const data = await res.json();
                            if (!data.success) throw new Error(data.message || 'Gagal memproses');
                            if (el) {
                                const ok = action === 'approve';
                                el.querySelectorAll('.np-actions, .np-decide, .np-reason').forEach(x => x.remove());
                                el.insertAdjacentHTML('beforeend', '<div class="np-done ' + (ok ? 'ok' : 'no') + '">' + (ok ? '✓ Disetujui' : '✕ Ditolak') + '</div>');
                                setTimeout(() => { el.remove(); checkAdminNotifs(); if (!document.querySelector('.np-item')) loadAdminNotifs(); }, 1400);
                            }
                        } catch (e) {
                            alert(e.message || 'Gagal memproses');
                            const btn = el && el.querySelector('.np-decide .np-btn:not(.ghost)');
                            if (btn) btn.disabled = false;
                        }
                    }

                    let _lastAdminCount = 0;
                    async function checkAdminNotifs() {
                        try {
                            const res = await fetch(NOTIF_BASE + '/api/get-notifications.php?type=admin_count');
                            const data = await res.json();
                            const count = data.pending_count || 0;
                            const badge = document.getElementById('adminNotifBadge');
                            const bell = document.getElementById('adminNotifBell');
                            if (badge) {
                                if (count > 0) {
                                    badge.textContent = count;
                                    badge.style.display = 'flex';
                                    if (count > _lastAdminCount && bell) {
                                        bell.style.animation = 'none';
                                        void bell.offsetWidth;
                                        bell.style.animation = 'bellShake .6s ease';
                                    }
                                } else {
                                    badge.style.display = 'none';
                                }
                            }
                            const cntEl = document.getElementById('adminNotifCount');
                            if (cntEl) {
                                if (count > 0) {
                                    cntEl.textContent = count;
                                    cntEl.style.display = 'inline-block';
                                } else {
                                    cntEl.style.display = 'none';
                                }
                            }
                            _lastAdminCount = count;
                            if (adminNotifOpen) loadAdminNotifs();
                        } catch (e) {
                            console.log('notif check err', e);
                        }
                    }
                    checkAdminNotifs();
                    setInterval(checkAdminNotifs, 15000);

                    (function() {
                        const dot = document.getElementById('emailUnreadDot');
                        if (!dot) return;
                        async function checkEmailUnread() {
                            try {
                                const res = await fetch('<?php echo BASE_URL; ?>/modules/email/unread-count.php');
                                const data = await res.json();
                                dot.style.display = (data.unread > 0) ? 'block' : 'none';
                            } catch (e) {
                                /* ignore */
                            }
                        }
                        checkEmailUnread();
                        setInterval(checkEmailUnread, 30000);
                    })();
                </script>