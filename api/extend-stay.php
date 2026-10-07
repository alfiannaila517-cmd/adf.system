<?php
/**
 * API: Extend Stay
 * Extend check-out date for checked-in guests
 */

// LOG ALL ERRORS
$logFile = __DIR__ . '/../api_debug.log';
ini_set('log_errors', 1);
ini_set('error_log', $logFile);

header('Content-Type: application/json');
if (ob_get_level() === 0) ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

define('APP_ACCESS', true);

try {
    error_log("=== extend-stay.php START ===");
    
    require_once '../config/config.php';
    error_log("config.php loaded");
    
    require_once '../config/database.php';
    error_log("database.php loaded");
    
    require_once '../includes/auth.php';
    error_log("auth.php loaded");
    
    error_reporting(0);
    ini_set('display_errors', 0);
    
    $auth = new Auth();
    error_log("Auth instantiated");
    
    if (!$auth->isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    if (!$auth->hasPermission('frontdesk')) {
        echo json_encode(['success' => false, 'message' => 'No permission']);
        exit;
    }

    $db = Database::getInstance();
    error_log("Database instance obtained");
    $conn = $db->getConnection();

    $bookingId = intval($_POST['booking_id'] ?? 0);
    $extraNights = intval($_POST['extra_nights'] ?? 0);

    if (!$bookingId || $extraNights < 1) {
        throw new Exception('Booking ID and extra nights (min 1) are required');
    }

    // Get current booking
    // In-house maupun reservasi yang belum datang bisa diperpanjang
    $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ? AND status IN ('checked_in', 'confirmed', 'pending')");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception('Booking tidak ditemukan atau sudah check-out/dibatalkan');
    }

    // Calculate new checkout
    $currentCheckout = new DateTime($booking['check_out_date']);
    $newCheckout = clone $currentCheckout;
    $newCheckout->modify("+{$extraNights} days");
    $newCheckoutStr = $newCheckout->format('Y-m-d');

    // Check room availability for extended period
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM bookings 
        WHERE room_id = ? 
        AND id != ? 
        AND status NOT IN ('cancelled', 'checked_out')
        AND check_in_date < ? 
        AND check_out_date > ?
    ");
    $stmt->execute([$booking['room_id'], $bookingId, $newCheckoutStr, $currentCheckout->format('Y-m-d')]);
    $conflict = $stmt->fetchColumn();

    if ($conflict > 0) {
        throw new Exception('Kamar sudah dipesan tamu lain pada tanggal perpanjangan.');
    }

    // Kamar yang diblok (maintenance dll) pada tanggal perpanjangan tidak boleh dipakai
    try {
        $blk = $conn->prepare("SELECT COUNT(*) FROM room_blocks WHERE room_id = ? AND status = 'active' AND block_start_date < ? AND block_end_date > ?");
        $blk->execute([$booking['room_id'], $newCheckoutStr, $currentCheckout->format('Y-m-d')]);
        if ((int)$blk->fetchColumn() > 0) {
            throw new Exception('Kamar sedang diblok pada tanggal perpanjangan.');
        }
    } catch (PDOException $e) {
        // tabel room_blocks belum ada
    }

    // Use room_price from booking
    $roomPrice = floatval($booking['room_price']);

    $newTotalNights = $booking['total_nights'] + $extraNights;
    $additionalPrice = $roomPrice * $extraNights;
    $newTotalPrice = floatval($booking['total_price']) + $additionalPrice;
    $discount = floatval($booking['discount'] ?? 0);

    // Extras (extra bed, breakfast, dll) tetap bagian dari tagihan
    $extrasTotal = 0.0;
    try {
        $ex = $conn->prepare("SELECT COALESCE(SUM(total_price), 0) FROM booking_extras WHERE booking_id = ?");
        $ex->execute([$bookingId]);
        $extrasTotal = (float)$ex->fetchColumn();
    } catch (PDOException $e) {
        // tabel booking_extras belum ada
    }
    $finalPrice = max(0, $newTotalPrice - $discount) + $extrasTotal;

    // Update booking
    $stmt = $conn->prepare("
        UPDATE bookings SET 
            check_out_date = ?,
            total_nights = ?,
            total_price = ?,
            final_price = ?,
            payment_status = CASE 
                WHEN paid_amount >= ? THEN 'paid'
                WHEN paid_amount > 0 THEN 'partial'
                ELSE 'unpaid'
            END,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $newCheckoutStr, $newTotalNights, $newTotalPrice,
        $finalPrice, $finalPrice, $bookingId
    ]);

    echo json_encode([
        'success' => true,
        'message' => "Menginap diperpanjang {$extraNights} malam. Check-out baru: " . $newCheckout->format('d M Y'),
        'data' => [
            'booking_id' => $bookingId,
            'new_checkout' => $newCheckoutStr,
            'total_nights' => $newTotalNights,
            'additional_price' => $additionalPrice,
            'new_total_price' => $newTotalPrice,
            'final_price' => $finalPrice
        ]
    ]);

} catch (Exception $e) {
    error_log("ERROR: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    error_log("Stack: " . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $t) {
    error_log("FATAL: " . $t->getMessage() . " at " . $t->getFile() . ":" . $t->getLine());
    error_log("Stack: " . $t->getTraceAsString());
    echo json_encode(['success' => false, 'message' => 'Fatal error: ' . $t->getMessage()]);
}
error_log("=== extend-stay.php END ===");
ob_end_flush();