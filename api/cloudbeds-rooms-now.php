<?php

/**
 * Pemeriksaan kamar kilat saat halaman Front Desk dibuka: kamar yang dipindah di Cloudbeds langsung ikut di sistem
 * (hanya reservasi yang menginap sekarang / 14 hari ke depan; dibaca paralel). Maks. sekali per 8 detik.
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/CloudbedsSync.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn() || !$auth->hasPermission('frontdesk')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'skipped' => 'auth']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'skipped' => 'method']);
    exit;
}
session_write_close();

try {
    $db = Database::getInstance();
    $cb = new CloudbedsClient($db);
    if (!$cb->isConfigured()) {
        echo json_encode(['ok' => true, 'skipped' => 'not_configured']);
        exit;
    }
    $auto = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_auto_sync'");
    if (($auto['setting_value'] ?? '0') !== '1') {
        echo json_encode(['ok' => true, 'skipped' => 'auto_off']);
        exit;
    }
    $lastQ = (int)($db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_room_quick_at'")['setting_value'] ?? 0);
    if (time() - $lastQ < 8) {
        echo json_encode(['ok' => true, 'skipped' => 'recent']);
        exit;
    }
    $cb->saveSetting('cloudbeds_room_quick_at', (string)time());
    // Jangan bersamaan dengan sinkron penuh / cron (kunci yang sama): bila sedang berjalan, lewati
    $lock = fopen(sys_get_temp_dir() . '/adf-cloudbeds-sync.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        echo json_encode(['ok' => true, 'skipped' => 'busy']);
        exit;
    }
    set_time_limit(40);
    $res = (new CloudbedsSync($db, $cb))->quickRoomSync(14);
    flock($lock, LOCK_UN);
    echo json_encode(['ok' => true, 'checked' => $res['checked'], 'moved' => $res['moved'], 'warns' => array_slice($res['warns'], 0, 3)]);
} catch (\Throwable $e) {
    error_log('cloudbeds-rooms-now: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'gagal']);
}
