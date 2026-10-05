<?php

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/EmailHelper.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['unread' => 0]);
    exit;
}

$isDeveloperRole = (($_SESSION['role'] ?? '') === 'developer');
if (!$isDeveloperRole && !$auth->hasPermission('email')) {
    echo json_encode(['unread' => 0]);
    exit;
}

$db = Database::getInstance();

// Endpoint ini dipolling tiap 30 detik dari header. Koneksi IMAP butuh 1-5 detik, jadi:
// 1) kunci sesi dilepas sebelum IMAP (klik menu lain tidak ikut menunggu), dan
// 2) hasilnya disimpan 3 menit per database bisnis agar IMAP tidak dibuka tiap 30 detik.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adf_unread_' . md5((string)Database::getCurrentDatabase()) . '.json';
$cacheTtl = 180;
$cached = @json_decode((string)@file_get_contents($cacheFile), true);
if (is_array($cached) && isset($cached['unread'], $cached['at']) && (time() - (int)$cached['at']) < $cacheTtl) {
    echo json_encode(['unread' => (int)$cached['unread'], 'cached' => true]);
    exit;
}

$emailConfig = EmailHelper::resolveConfig($db);
if ($emailConfig === null) {
    echo json_encode(['unread' => 0]);
    exit;
}

try {
    $emailHelper = new EmailHelper($emailConfig);
    $unread = (int)$emailHelper->countUnread();
    @file_put_contents($cacheFile, json_encode(['unread' => $unread, 'at' => time()]), LOCK_EX);
    echo json_encode(['unread' => $unread]);
} catch (Throwable $e) {
    echo json_encode(['unread' => 0]);
}
