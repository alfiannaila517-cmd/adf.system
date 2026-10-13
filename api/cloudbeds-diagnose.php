<?php

/**
 * Alat "Cek reservasi" (Front Desk → Pengaturan → Cloudbeds → Cek & Perbaiki): jelaskan kenapa reservasi Cloudbeds
 * belum masuk sistem, dan (opsional) masukkan sekarang juga.
 * POST q = nama tamu / ID reservasi ; POST import_id = ID reservasi Cloudbeds yang akan diimpor sekarang
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
    echo json_encode(['ok' => false, 'detail' => 'Tidak punya akses']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'detail' => 'Metode tidak valid']);
    exit;
}
$userId = (int)($_SESSION['user_id'] ?? 0);
session_write_close();
set_time_limit(120);

try {
    $db = Database::getInstance();
    $cb = new CloudbedsClient($db);
    if (!$cb->isConfigured()) {
        echo json_encode(['ok' => false, 'detail' => 'Cloudbeds belum terhubung']);
        exit;
    }
    $sync = new CloudbedsSync($db, $cb);
    $importId = trim((string)($_POST['import_id'] ?? ''));
    if ($importId !== '') {
        // Masukkan SATU reservasi sekarang (kunci yang sama dengan sinkron lain agar tidak bersamaan)
        $lock = @fopen(sys_get_temp_dir() . '/adf-cloudbeds-sync.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            echo json_encode(['ok' => false, 'detail' => 'Sinkron lain sedang berjalan — coba lagi sebentar lagi']);
            exit;
        }
        try {
            $res = $sync->importNewOnly(date('Y-m-d', strtotime('-60 days')), date('Y-m-d', strtotime('+400 days')), $userId, [$importId]);
        } finally {
            flock($lock, LOCK_UN);
        }
        $d = $res['done'] ?? [];
        echo json_encode([
            'ok' => (bool)$res['ok'],
            'created' => (int)($d['create'] ?? 0),
            'linked' => (int)($d['link'] ?? 0),
            'errors' => $d['errors'] ?? [],
            'detail' => $res['ok'] ? '' : (string)($res['detail'] ?? ''),
        ]);
        exit;
    }
    echo json_encode($sync->diagnose((string)($_POST['q'] ?? '')));
} catch (\Throwable $e) {
    error_log('cloudbeds-diagnose: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'detail' => 'Gagal: ' . $e->getMessage()]);
}
