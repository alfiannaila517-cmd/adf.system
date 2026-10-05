<?php
/**
 * DELETE BOOKING API
 * Permanently delete booking from database
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

if (!$auth->hasPermission('frontdesk')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();
$currentUser = $auth->getCurrentUser();

$input = json_decode(file_get_contents('php://input'), true);
$bookingId = $input['booking_id'] ?? null;

if (!$bookingId) {
    echo json_encode(['success' => false, 'message' => 'Booking ID required']);
    exit;
}

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // Get booking info first
    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit;
    }
    
    // Cannot delete if checked in (except developer role)
    if ($booking['status'] === 'checked_in' && $currentUser['role'] !== 'developer') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Cannot delete booking that is checked in']);
        exit;
    }
    
    // Booking yang sudah check-out berisi riwayat pendapatan: hanya admin/owner/manager/developer.
    if ($booking['status'] === 'checked_out' && !in_array($currentUser['role'] ?? '', ['admin', 'owner', 'manager', 'developer'], true)) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Booking yang sudah check-out hanya bisa dihapus oleh admin/owner']);
        exit;
    }

    // Delete breakfast orders first (foreign key constraint)
    $stmt = $pdo->prepare("DELETE FROM breakfast_orders WHERE booking_id = ?");
    $stmt->execute([$bookingId]);
    
    // Clean up cashbook entries linked to this booking's payments
    $stmtPayments = $pdo->prepare("SELECT id, cashbook_id FROM booking_payments WHERE booking_id = ?");
    $stmtPayments->execute([$bookingId]);
    $payments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);
    
    // cash_book.id hanya unik per database bisnis, sedangkan cash_account_transactions (master) dipakai
    // bersama semua bisnis. Baris master hanya dihapus bila akun kasnya cocok dengan akun milik baris
    // cash_book ini, lalu saldo akun dikoreksi sebesar nominal yang dulu ditambahkan.
    // Koreksi master dijalankan setelah commit, supaya saldo tidak berubah bila hapus booking gagal.
    $hasCbAccount = (bool)$pdo->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'")->fetch();
    $masterCleanup = [];
    foreach ($payments as $payment) {
        $cashbookId = (int)($payment['cashbook_id'] ?? 0);
        if ($cashbookId <= 0) {
            continue;
        }
        $cbStmt = $pdo->prepare("SELECT id" . ($hasCbAccount ? ", cash_account_id" : "") . " FROM cash_book WHERE id = ?");
        $cbStmt->execute([$cashbookId]);
        $cbRow = $cbStmt->fetch(PDO::FETCH_ASSOC);
        if (!$cbRow) {
            continue;
        }
        $pdo->prepare("DELETE FROM cash_book WHERE id = ?")->execute([$cashbookId]);

        $accountId = (int)($cbRow['cash_account_id'] ?? 0);
        if ($accountId <= 0) {
            // Tanpa akun kas tidak bisa dipastikan baris master milik bisnis ini: master tidak disentuh.
            error_log("Delete Booking - cash_book #{$cashbookId} tanpa cash_account_id, master tidak disentuh");
            continue;
        }
        $masterCleanup[] = [$cashbookId, $accountId];
    }

    // Delete booking payments
    $stmt = $pdo->prepare("DELETE FROM booking_payments WHERE booking_id = ?");
    $stmt->execute([$bookingId]);

    // Delete booking
    $stmt = $pdo->prepare("DELETE FROM bookings WHERE id = ?");
    $stmt->execute([$bookingId]);

    // Commit transaction
    $pdo->commit();

    if ($masterCleanup) {
        try {
            $masterPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . (defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME) . ";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            foreach ($masterCleanup as [$cashbookId, $accountId]) {
                $mt = $masterPdo->prepare("SELECT id, amount, transaction_type FROM cash_account_transactions WHERE transaction_id = ? AND cash_account_id = ?");
                $mt->execute([$cashbookId, $accountId]);
                foreach ($mt->fetchAll(PDO::FETCH_ASSOC) as $masterRow) {
                    $masterPdo->prepare("DELETE FROM cash_account_transactions WHERE id = ?")->execute([$masterRow['id']]);
                    // Pemasukan dulu menambah saldo -> sekarang dikurangi; pengeluaran sebaliknya.
                    $delta = ($masterRow['transaction_type'] === 'income' ? -1 : 1) * (float)$masterRow['amount'];
                    $masterPdo->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$delta, $accountId]);
                }
            }
        } catch (Exception $e) {
            error_log("Delete Booking - cleanup master cashbook error: " . $e->getMessage());
        }
    }

    echo json_encode(['success' => true, 'message' => 'Booking deleted successfully']);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Delete Booking Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}
?>
