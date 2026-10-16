<?php

/**
 * Tombol "Cek harga Cloudbeds" per tamu (daftar reservasi).
 * POST action = status | adopt | push , booking_id
 *   status : bandingkan total ADF dengan total Cloudbeds + siapa patokan harga (OTA → Cloudbeds, langsung → ADF)
 *   adopt  : ADF mengikuti total Cloudbeds (Cloudbeds tidak diubah)
 *   push   : Cloudbeds mengikuti ADF (hanya kenaikan; booking langsung saja)
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
    echo json_encode(['ok' => false, 'msg' => 'Tidak punya akses']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Metode tidak valid']);
    exit;
}
session_write_close();
set_time_limit(60);

try {
    $db = Database::getInstance();
    $cb = new CloudbedsClient($db);
    if (!$cb->isConfigured()) {
        echo json_encode(['ok' => false, 'msg' => 'Cloudbeds belum terhubung']);
        exit;
    }
    $sync = new CloudbedsSync($db, $cb);
    $id = (int)($_POST['booking_id'] ?? 0);
    $action = (string)($_POST['action'] ?? 'status');
    if ($action === 'adopt') {
        $code = (string)($db->fetchOne("SELECT booking_code FROM bookings WHERE id = ?", [$id])['booking_code'] ?? '');
        $res = $code === '' ? ['ok' => false, 'msg' => 'Booking tidak ditemukan'] : $sync->adoptCloudbedsPrice($code);
        echo json_encode($res + ['status' => $sync->priceStatus($id)]);
    } elseif ($action === 'push') {
        $res = $sync->pushAdfPriceToCloudbeds($id);
        echo json_encode($res + ['status' => $sync->priceStatus($id)]);
    } else {
        echo json_encode($sync->priceStatus($id));
    }
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => 'Gagal: ' . $e->getMessage()]);
}
