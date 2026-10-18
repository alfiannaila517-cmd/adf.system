<?php

/**
 * Dipanggil website narayanakarimunjawa.com (server ke server) setelah tamu mengirim reservasi.
 * POST id=<quotation id>&sig=<hmac_sha256('webres:'.id, web_reservation_secret)>
 * Mengirim notifikasi WhatsApp + email kantor satu kali (lihat WebReservations::notifyNew).
 */
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/WebReservations.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}
try {
    $db = Database::getInstance();
    Quotations::ensureTables($db);
    $id = (int)($_POST['id'] ?? 0);
    if (!WebReservations::verify($db, $id, (string)($_POST['sig'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'Invalid signature']);
        exit;
    }
    echo json_encode(['ok' => true] + WebReservations::notifyNew($db, $id));
} catch (\Throwable $e) {
    error_log('web-reservation-notify: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}
