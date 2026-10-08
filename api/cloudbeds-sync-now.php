<?php

/**
 * Sinkron Cloudbeds → sistem saat halaman Front Desk dibuka (dipanggil di latar belakang lewat fetch).
 * Aturan sama dengan cron/cloudbeds-sync.php; hanya bila sinkron otomatis aktif, maksimal sekali per
 * menit per bisnis, dan tidak pernah bersamaan dengan cron (lock yang sama).
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
$userId = (int)($_SESSION['user_id'] ?? 0);
// Lepas kunci sesi: sinkron bisa beberapa detik, halaman lain tidak boleh ikut menunggu
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
    $last = json_decode((string)($db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_last_auto_sync'")['setting_value'] ?? ''), true);
    if (!empty($last['at']) && time() - strtotime($last['at']) < 55) {
        echo json_encode(['ok' => true, 'skipped' => 'recent', 'last' => $last['at'], 'last_ok' => !empty($last['ok']), 'summary' => $last['summary'] ?? '']);
        exit;
    }
    $lock = fopen(sys_get_temp_dir() . '/adf-cloudbeds-sync.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        echo json_encode(['ok' => true, 'skipped' => 'busy', 'last' => $last['at'] ?? null]);
        exit;
    }
    // Tandai mulai dulu agar halaman lain yang dibuka bersamaan tidak ikut memicu sinkron
    $cb->saveSetting('cloudbeds_last_auto_sync', json_encode(['at' => date('Y-m-d H:i:s'), 'ok' => true, 'summary' => 'sedang berjalan…'], JSON_UNESCAPED_UNICODE));
    set_time_limit(120);

    $res = (new CloudbedsSync($db, $cb))->apply(date('Y-m-d', strtotime('-14 days')), date('Y-m-d', strtotime('+120 days')), $userId);
    $d = $res['done'] ?? [];
    $summary = $res['ok']
        ? sprintf('baru %d, taut %d, batal %d, blok %d, cabut %d, kirim status %d, kirim baru %d, kirim blok %d, kirim bayar %d, dicek %d%s',
            $d['create'] ?? 0, $d['link'] ?? 0, $d['cancel'] ?? 0, $d['block'] ?? 0, $d['unblock'] ?? 0, $d['push_status'] ?? 0, $d['push_create'] ?? 0, $d['push_block'] ?? 0, $d['push_pay'] ?? 0,
            $res['counts']['warn'] ?? 0, !empty($d['errors']) ? ', GAGAL ' . count($d['errors']) : '')
        : 'GAGAL: ' . $res['detail'];
    $cb->saveSetting('cloudbeds_last_auto_sync', json_encode([
        'at' => date('Y-m-d H:i:s'),
        'ok' => (bool)$res['ok'] && empty($d['errors']),
        'summary' => $summary . ' (halaman dibuka)',
    ], JSON_UNESCAPED_UNICODE));
    flock($lock, LOCK_UN);

    echo json_encode([
        'ok' => (bool)$res['ok'],
        'created' => (int)($d['create'] ?? 0),
        'cancelled' => (int)($d['cancel'] ?? 0),
        'blocked' => (int)($d['block'] ?? 0),
        'unblocked' => (int)($d['unblock'] ?? 0),
        'summary' => $summary,
        'last' => date('Y-m-d H:i:s'),
        'last_ok' => (bool)$res['ok'] && empty($d['errors']),
    ]);
} catch (\Throwable $e) {
    error_log('cloudbeds-sync-now: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Sinkron Cloudbeds gagal']);
}
