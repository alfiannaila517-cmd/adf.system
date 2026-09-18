<?php

/**
 * Developer Access - Quick "Open Business" link used by developer/businesses.php.
 * Impersonates the developer as role='developer' inside the target business,
 * without needing separate staff login credentials for every business.
 */

define('APP_ACCESS', true);

// Read the developer panel's own session (separate cookie name, see developer/includes/dev_auth.php)
// BEFORE the normal app session is started below.
session_name('DEV_SESSION');
session_start();
$isDev        = isset($_SESSION['dev_logged_in']) && $_SESSION['dev_logged_in'] === true;
$devUserId    = $_SESSION['dev_user_id'] ?? null;
$devUsername  = $_SESSION['dev_username'] ?? null;
$devFullName  = $_SESSION['dev_full_name'] ?? null;
session_write_close();

if (!$isDev || !$devUserId || !$devUsername) {
    http_response_code(403);
    die('Akses ditolak. Silakan login sebagai developer terlebih dahulu.');
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/business_helper.php';

$token        = $_GET['dev_access'] ?? '';
$databaseName = base64_decode((string)$token, true);
if (!$databaseName) {
    http_response_code(400);
    die('Token tidak valid.');
}

try {
    $masterPdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stmt = $masterPdo->prepare("SELECT * FROM businesses WHERE database_name = ? LIMIT 1");
    $stmt->execute([$databaseName]);
    $biz = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('developer-access.php: ' . $e->getMessage());
    die('Terjadi kesalahan database.');
}

if (!$biz) {
    die('Bisnis tidak ditemukan.');
}

$slug = !empty($biz['slug']) ? $biz['slug'] : businessCodeToSlug($biz['business_code']);

// Impersonate: reuse the developer's own master `users` row so Auth's permission
// lookups (which query `users` by username) resolve to the same account.
$_SESSION['user_id']         = $devUserId;
$_SESSION['username']        = $devUsername;
$_SESSION['full_name']       = $devFullName ?: $devUsername;
$_SESSION['role']            = 'developer';
$_SESSION['business_access'] = 'all';
$_SESSION['logged_in']       = true;
$_SESSION['login_time']      = time();
$_SESSION['user_theme']      = $_SESSION['user_theme'] ?? 'dark';
$_SESSION['user_language']   = $_SESSION['user_language'] ?? 'id';

if (!setActiveBusinessId($slug)) {
    die('Bisnis belum siap. Selesaikan Setup terlebih dahulu di Developer Panel.');
}

$cfg    = getActiveBusinessConfig();
$target = BASE_URL . '/index.php';
if (in_array('sunsea', $cfg['enabled_modules'] ?? [], true)) {
    $target = BASE_URL . '/modules/sunsea/dashboard.php';
}

header('Location: ' . $target);
exit;
