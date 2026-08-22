<?php

/**
 * API: EDIT DRIVER TRIP AMOUNT
 * POST /api/edit-driver-trip-amount.php
 *
 * Updates total_price and owner_amount for a driver trip.
 * source='trip'   → rental_car_bookings (also recalculates hotel_commission)
 * source='legacy' → hotel_invoice_items (total_price only; Moyong gets 100%)
 */

if (ob_get_level()) ob_end_clean();
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

try {
    define('APP_ACCESS', true);
    require_once '../config/config.php';
    require_once '../config/database.php';
    require_once '../includes/auth.php';

    $auth = new Auth();
    if (!$auth->isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $db   = Database::getInstance();
    $pdo  = $db->getConnection();
    $bizId = (int)($_SESSION['business_id'] ?? 1);

    $tripId     = (int)($_POST['trip_id'] ?? 0);
    $source     = trim($_POST['source'] ?? '');
    $totalPrice = (float)($_POST['total_price'] ?? -1);
    $ownerAmount = (float)($_POST['owner_amount'] ?? -1);

    if ($tripId <= 0) throw new Exception('trip_id tidak valid');
    if (!in_array($source, ['trip', 'legacy'], true)) throw new Exception('source tidak valid');
    if ($totalPrice < 0) throw new Exception('Total tarif tidak valid');
    if ($ownerAmount < 0) throw new Exception('Bagian pemilik tidak valid');
    if ($ownerAmount > $totalPrice) throw new Exception('Bagian pemilik tidak boleh melebihi total tarif');

    if ($source === 'trip') {
        // Check existence/ownership separately from the UPDATE — rowCount() on UPDATE only
        // counts rows actually CHANGED (not matched), so resaving identical values would
        // otherwise falsely report "trip not found" even though it belongs to this business.
        $check = $pdo->prepare('SELECT id FROM rental_car_bookings WHERE id = ? AND business_id = ? LIMIT 1');
        $check->execute([$tripId, $bizId]);
        if (!$check->fetch()) throw new Exception('Trip tidak ditemukan atau bukan milik bisnis ini');

        $hotelCommission = $totalPrice - $ownerAmount;
        $stmt = $pdo->prepare(
            "UPDATE rental_car_bookings
             SET total_price = ?, owner_amount = ?, hotel_commission = ?
             WHERE id = ? AND business_id = ?"
        );
        $stmt->execute([$totalPrice, $ownerAmount, $hotelCommission, $tripId, $bizId]);
    } else {
        // legacy: hotel_invoice_items — verify business via hotel_invoices join
        $check = $pdo->prepare(
            "SELECT hii.id FROM hotel_invoice_items hii
             JOIN hotel_invoices hi ON hii.invoice_id = hi.id
             WHERE hii.id = ? AND hi.business_id = ? LIMIT 1"
        );
        $check->execute([$tripId, $bizId]);
        if (!$check->fetch()) throw new Exception('Trip tidak ditemukan atau bukan milik bisnis ini');

        $hotelCommission = $totalPrice - $ownerAmount;
        $stmt = $pdo->prepare(
            "UPDATE hotel_invoice_items
             SET total_price = ?, owner_amount = ?, hotel_commission = ?
             WHERE id = ?"
        );
        $stmt->execute([$totalPrice, $ownerAmount, $hotelCommission, $tripId]);
    }

    echo json_encode(['success' => true, 'message' => 'Nominal berhasil diperbarui']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
