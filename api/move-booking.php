<?php
/**
 * API: Move Booking (Drag & Drop)
 * Update booking dates and/or room when dragged on calendar
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
    error_log("=== move-booking.php START ===");
    
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
    $newCheckIn = trim($_POST['new_check_in'] ?? '');
    $newCheckOut = trim($_POST['new_check_out'] ?? '');
    $newRoomId = intval($_POST['new_room_id'] ?? 0);

    if (!$bookingId) {
        throw new Exception('Booking ID is required');
    }

    // Get current booking
    $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ?");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception('Booking not found');
    }

    // Cannot move checked_out bookings
    if ($booking['status'] === 'checked_out') {
        throw new Exception('Cannot move checked-out bookings');
    }
    if ($booking['status'] === 'cancelled') {
        throw new Exception('Booking yang dibatalkan tidak bisa dipindah');
    }

    // Determine new dates
    $checkIn = $newCheckIn ?: $booking['check_in_date'];
    $checkOut = $newCheckOut ?: $booking['check_out_date'];
    $roomId = $newRoomId ?: (int)$booking['room_id'];
    $isInHouse = $booking['status'] === 'checked_in';
    $roomChanged = (int)$roomId !== (int)$booking['room_id'];

    // Validate dates (format Y-m-d)
    $ciDate = DateTime::createFromFormat('!Y-m-d', $checkIn);
    $coDate = DateTime::createFromFormat('!Y-m-d', $checkOut);
    if (!$ciDate || !$coDate) {
        throw new Exception('Format tanggal tidak valid');
    }
    if ($coDate <= $ciDate) {
        throw new Exception('Check-out must be after check-in');
    }

    // Tamu yang sudah check-in: tanggal check-in tidak bisa digeser (hanya pindah kamar / ubah check-out).
    if ($isInHouse && $checkIn !== $booking['check_in_date']) {
        throw new Exception('Tamu sudah check-in: tanggal check-in tidak bisa diubah. Hanya kamar atau tanggal check-out yang bisa dipindah.');
    }

    $nights = $ciDate->diff($coDate)->days;

    $conn->beginTransaction();

    // Check room availability (exclude current booking)
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM bookings
        WHERE room_id = ?
        AND id != ?
        AND status NOT IN ('cancelled', 'checked_out')
        AND check_in_date < ?
        AND check_out_date > ?
    ");
    $stmt->execute([$roomId, $bookingId, $checkOut, $checkIn]);
    $conflict = $stmt->fetchColumn();

    if ($conflict > 0) {
        throw new Exception('Room is not available for the selected dates');
    }

    // Kamar yang diblok (maintenance dll) pada rentang tanggal baru tidak boleh dipakai.
    try {
        $blk = $conn->prepare("SELECT COUNT(*) FROM room_blocks WHERE room_id = ? AND status = 'active' AND block_start_date < ? AND block_end_date > ?");
        $blk->execute([$roomId, $checkOut, $checkIn]);
        if ((int)$blk->fetchColumn() > 0) {
            throw new Exception('Kamar diblok pada rentang tanggal tersebut');
        }
    } catch (PDOException $e) {
        // tabel room_blocks belum ada di lingkungan lama
    }

    // Harga per malam booking dipertahankan (harga negosiasi / OTA); dulu pindah kamar menggantinya
    // dengan base_price tipe kamar baru. Fallback base_price hanya bila booking belum punya harga.
    $roomPrice = (float)$booking['room_price'];
    if ($roomPrice <= 0) {
        $stmt = $conn->prepare("SELECT rt.base_price FROM rooms r JOIN room_types rt ON r.room_type_id = rt.id WHERE r.id = ?");
        $stmt->execute([$roomId]);
        $roomPrice = (float)($stmt->fetchColumn() ?: 0);
    }

    $totalPrice = $roomPrice * $nights;
    $discount = floatval($booking['discount'] ?? 0);

    // Extras (booking_extras) tetap bagian dari tagihan.
    $extrasTotal = 0.0;
    try {
        $ex = $conn->prepare("SELECT COALESCE(SUM(total_price), 0) FROM booking_extras WHERE booking_id = ?");
        $ex->execute([$bookingId]);
        $extrasTotal = (float)$ex->fetchColumn();
    } catch (PDOException $e) {
        // tabel booking_extras belum ada
    }
    $finalPrice = max(0, $totalPrice - $discount) + $extrasTotal;

    // Status bayar mengikuti total baru (mis. pindah ke tanggal lebih panjang -> tidak lagi LUNAS).
    $paidAmount = (float)($booking['paid_amount'] ?? 0);
    $paymentStatus = $paidAmount <= 0 ? 'unpaid' : ($paidAmount >= $finalPrice ? 'paid' : 'partial');

    // Update booking
    $stmt = $conn->prepare("
        UPDATE bookings SET
            check_in_date = ?,
            check_out_date = ?,
            room_id = ?,
            total_nights = ?,
            room_price = ?,
            total_price = ?,
            final_price = ?,
            payment_status = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $checkIn, $checkOut, $roomId, $nights,
        $roomPrice, $totalPrice, $finalPrice, $paymentStatus, $bookingId
    ]);

    // Tamu in-house pindah kamar: kamar lama perlu dibersihkan, kamar baru terisi.
    if ($isInHouse && $roomChanged) {
        $conn->prepare("UPDATE rooms SET status = 'cleaning', current_guest_id = NULL, updated_at = NOW() WHERE id = ?")
            ->execute([(int)$booking['room_id']]);
        $conn->prepare("UPDATE rooms SET status = 'occupied', current_guest_id = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$booking['guest_id'], $roomId]);
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Booking moved successfully',
        'data' => [
            'booking_id' => $bookingId,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'room_id' => $roomId,
            'nights' => $nights,
            'total_price' => $totalPrice,
            'final_price' => $finalPrice
        ]
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("ERROR: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    error_log("Stack: " . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $t) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("FATAL: " . $t->getMessage() . " at " . $t->getFile() . ":" . $t->getLine());
    error_log("Stack: " . $t->getTraceAsString());
    echo json_encode(['success' => false, 'message' => 'Fatal error: ' . $t->getMessage()]);
}
error_log("=== move-booking.php END ===");
ob_end_flush();