<?php

/**
 * API: PAY DRIVER TRIP
 * POST /api/pay-driver-trip.php
 *
 * Marks a single trip (car rental / airport drop / harbor drop) as paid to
 * the driver/partner, and auto-syncs the expense to the cashbook - mirrors
 * the flow in pay-monthly-bill.php but operates on individual trip rows
 * (rental_car_bookings / hotel_invoice_items) instead of monthly_bills.
 * Used by the "Tagihan Driver" tab in modules/bills/index.php.
 *
 * POST data:
 * - trip_id: ID of the rental_car_bookings row OR hotel_invoice_items row
 * - source_type: car_rental | airport_drop | harbor_drop
 * - payment_method: cash, transfer, card, other
 * - cash_account_id: Dari rekening mana (FK cash_accounts.id)
 * - driver_name: label used in the cashbook description
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/CashbookHelper.php';
require_once '../includes/DriverPaymentHelper.php';

ob_start();
error_reporting(0);
ini_set('display_errors', '0');

while (ob_get_level()) ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn() || !$auth->hasPermission('finance')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();
$currentUser = $auth->getCurrentUser();
$businessId = $_SESSION['business_id'] ?? 1;

try {
    ensureDriverTripPaymentColumns($pdo);

    $db->beginTransaction();

    $tripId = (int)($_POST['trip_id'] ?? 0);
    $sourceType = trim($_POST['source_type'] ?? '');
    $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
    $cashAccountId = (int)($_POST['cash_account_id'] ?? 0);
    $driverName = trim($_POST['driver_name'] ?? 'Driver');

    if (!$tripId || !in_array($sourceType, ['car_rental', 'airport_drop', 'harbor_drop'], true)) {
        throw new Exception('Data trip tidak valid');
    }

    if ($sourceType === 'car_rental') {
        $trip = $db->fetchOne(
            "SELECT cb.id, cb.business_id, cb.owner_amount, cb.guest_name, cb.driver_paid,
                    rc.car_name, rc.plate_number
             FROM rental_car_bookings cb
             JOIN rental_cars rc ON cb.car_id = rc.id
             WHERE cb.id = ? LIMIT 1",
            [$tripId]
        );
        if (!$trip) throw new Exception('Trip rental mobil tidak ditemukan');
        if ((int)$trip['business_id'] !== (int)$businessId) throw new Exception('Trip tidak ditemukan untuk bisnis ini');
        if ((int)$trip['driver_paid'] === 1) throw new Exception('Trip ini sudah dibayar sebelumnya');

        $amount = (float)$trip['owner_amount'];
        $label = trim('Rental Mobil ' . ($trip['car_name'] ?? '') . ' (' . ($trip['plate_number'] ?? '') . ')');
        $guestLabel = $trip['guest_name'] ? " - {$trip['guest_name']}" : '';
        $updateSql = "UPDATE rental_car_bookings SET driver_paid = 1, driver_paid_at = NOW(), driver_paid_cashbook_id = ? WHERE id = ?";
    } else {
        $trip = $db->fetchOne(
            "SELECT hii.id, hi.business_id, hii.total_price, hii.description, hii.service_type, hii.driver_paid,
                    hi.guest_name
             FROM hotel_invoice_items hii
             JOIN hotel_invoices hi ON hii.invoice_id = hi.id
             WHERE hii.id = ? AND hii.service_type = ? LIMIT 1",
            [$tripId, $sourceType]
        );
        if (!$trip) throw new Exception('Trip drop tidak ditemukan');
        if ((int)$trip['business_id'] !== (int)$businessId) throw new Exception('Trip tidak ditemukan untuk bisnis ini');
        if ((int)$trip['driver_paid'] === 1) throw new Exception('Trip ini sudah dibayar sebelumnya');

        $amount = (float)$trip['total_price'];
        $label = ($sourceType === 'airport_drop' ? 'Airport Drop' : 'Harbor Drop') . ($trip['description'] ? " - {$trip['description']}" : '');
        $guestLabel = $trip['guest_name'] ? " - {$trip['guest_name']}" : '';
        $updateSql = "UPDATE hotel_invoice_items SET driver_paid = 1, driver_paid_at = NOW(), driver_paid_cashbook_id = ? WHERE id = ?";
    }

    if ($amount <= 0) {
        throw new Exception('Jumlah pembayaran tidak valid');
    }

    // ======================================
    // AUTO-SYNC TO CASHBOOK (same pattern as pay-monthly-bill.php)
    // ======================================
    $cbHelper = new CashbookHelper($db, $currentUser['id']);

    $divisionId = $cbHelper->getDivisionId();
    $categoryId = $cbHelper->getCategoryId();

    $accountId = $cashAccountId;
    if (!$accountId) {
        $account = $cbHelper->getCashAccount($paymentMethod);
        $accountId = $account['id'] ?? 1;
    }

    $cbDescription = "Bayar Driver {$driverName} - {$label}{$guestLabel} [LUNAS]";

    $cbResult = $db->query(
        "INSERT INTO cash_book
        (division_id, category_id, transaction_type, transaction_date, transaction_time, amount, description, payment_method, cash_account_id, is_editable, created_by)
        VALUES (?, ?, 'expense', DATE(NOW()), TIME(NOW()), ?, ?, ?, ?, 1, ?)",
        [
            $divisionId,
            $categoryId,
            $amount,
            $cbDescription,
            $paymentMethod,
            $accountId,
            $currentUser['id']
        ]
    );

    if (!$cbResult) {
        throw new Exception('Failed to sync to cashbook');
    }

    $cashbookId = $db->getConnection()->lastInsertId();

    $db->query($updateSql, [$cashbookId, $tripId]);

    // ======================================
    // SYNC TO MASTER CASH ACCOUNT LEDGER
    // Ensure operational account mutasi + balance move together with expense record
    // ======================================
    $masterDb = null;
    try {
        $masterBusinessId = getMasterBusinessId();
        $masterDb = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $masterDb->beginTransaction();

        $accStmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE id = ? AND business_id = ? AND is_active = 1 LIMIT 1");
        $accStmt->execute([$accountId, $masterBusinessId]);
        $account = $accStmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            throw new Exception('Rekening operasional tidak valid untuk bisnis ini');
        }

        $trxStmt = $masterDb->prepare("
            INSERT INTO cash_account_transactions
            (cash_account_id, transaction_id, transaction_date, description, amount, transaction_type, reference_number, created_by, created_at)
            VALUES (?, ?, DATE(NOW()), ?, ?, 'expense', ?, ?, NOW())
        ");
        $trxStmt->execute([
            $accountId,
            $cashbookId,
            $cbDescription,
            $amount,
            'DRV-' . $sourceType . '-' . $tripId,
            $currentUser['id']
        ]);

        $balStmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance - ? WHERE id = ?");
        $balStmt->execute([$amount, $accountId]);

        $masterDb->commit();
    } catch (Exception $masterEx) {
        if ($masterDb instanceof PDO && $masterDb->inTransaction()) {
            $masterDb->rollBack();
        }
        throw $masterEx;
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => "Pembayaran Rp " . number_format($amount, 0, ',', '.') . " ke {$driverName} berhasil dicatat",
        'amount' => $amount,
        'cashbook_id' => $cashbookId
    ]);
} catch (Exception $e) {
    try {
        $db->rollBack();
    } catch (Exception $ignore) {
    }

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
