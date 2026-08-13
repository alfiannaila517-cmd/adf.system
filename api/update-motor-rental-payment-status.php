<?php

/**
 * API: UPDATE MOTOR RENTAL PARTNER PAYMENT STATUS
 * POST /api/update-motor-rental-payment-status.php
 *
 * Toggle status pembayaran mitra motor (lunas/belum) untuk kebutuhan koreksi di layar tagihan.
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

if (ob_get_level()) {
    ob_end_clean();
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

try {
    $auth = new Auth();
    if (!$auth->isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $db = Database::getInstance();
    $pdo = $db->getConnection();
    $businessId = (int)($_SESSION['business_id'] ?? 1);

    $rentalId = (int)($_POST['rental_id'] ?? 0);
    $paid = (int)($_POST['paid'] ?? 0) === 1;

    if ($rentalId <= 0) {
        throw new Exception('rental_id tidak valid');
    }

    $checkStmt = $pdo->prepare('SELECT id, business_id, payment_date FROM rental_motor_bookings WHERE id = ? LIMIT 1');
    $checkStmt->execute([$rentalId]);
    $row = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || (int)$row['business_id'] !== $businessId) {
        throw new Exception('Data rental tidak ditemukan untuk bisnis ini');
    }

    if ($paid) {
        $upd = $pdo->prepare('UPDATE rental_motor_bookings SET payment_date = NOW(), updated_at = NOW() WHERE id = ? AND business_id = ?');
        $upd->execute([$rentalId, $businessId]);

        echo json_encode([
            'success' => true,
            'message' => 'Status pembayaran mitra diubah menjadi LUNAS'
        ]);
        exit;
    }

    $upd = $pdo->prepare('UPDATE rental_motor_bookings SET payment_date = NULL, updated_at = NOW() WHERE id = ? AND business_id = ?');
    $upd->execute([$rentalId, $businessId]);

    echo json_encode([
        'success' => true,
        'message' => 'Status pembayaran mitra diubah menjadi BELUM dibayar',
        'note' => 'Catatan: status saja yang diubah. Jika sebelumnya sudah tercatat di kas, mutasi kas tidak dihapus otomatis.'
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
