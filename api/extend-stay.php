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
    require_once '../includes/BookingSourceHelper.php';
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
    bs_ensure_direct_amount($conn);

    $bookingId = intval($_POST['booking_id'] ?? 0);
    $extraNights = intval($_POST['extra_nights'] ?? 0);
    $manualNight = isset($_POST['night_price']) && $_POST['night_price'] !== '' ? (float)$_POST['night_price'] : null;
    $isPreview = !empty($_POST['preview']);

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

    // Harga per malam tambahan:
    //  - booking direct: harga per malam booking;
    //  - booking OTA: malam tambahan dibayar tamu langsung ke hotel = harga asli tipe kamar − fee OTA.
    $source = strtolower((string)($booking['booking_source'] ?? ''));
    $isOta = bs_is_ota($conn, $source);
    $feePct = $isOta ? bs_ota_fee_percent($conn, $source) : 0.0;
    $roomPrice = floatval($booking['room_price']);
    $autoNight = $roomPrice;
    if ($isOta) {
        $bp = $conn->prepare("SELECT rt.base_price FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id WHERE r.id = ?");
        $bp->execute([$booking['room_id']]);
        $base = (float)($bp->fetchColumn() ?: $roomPrice);
        $autoNight = round($base * (1 - $feePct / 100), 2);
    }
    $nightPrice = ($manualNight !== null && $manualNight >= 0) ? $manualNight : $autoNight;

    $newTotalNights = $booking['total_nights'] + $extraNights;
    $additionalPrice = $nightPrice * $extraNights;
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
    $oldDirect = (float)($booking['direct_amount'] ?? 0);
    $newDirect = $isOta ? round($oldDirect + $additionalPrice, 2) : $oldDirect;
    $paid = (float)($booking['paid_amount'] ?? 0);

    if ($isPreview) {
        echo json_encode(['success' => true, 'preview' => true, 'data' => [
            'new_checkout' => $newCheckoutStr, 'night_price' => $nightPrice, 'auto_night' => $autoNight,
            'room_price' => $roomPrice, 'additional_price' => $additionalPrice, 'final_price' => $finalPrice,
            'old_final' => (float)$booking['final_price'], 'paid' => $paid, 'balance' => max(0, $finalPrice - $paid),
            'is_ota' => $isOta, 'source' => $source, 'fee_percent' => $feePct, 'direct_amount' => $newDirect,
        ]]);
        exit;
    }

    // Update booking
    $stmt = $conn->prepare("
        UPDATE bookings SET 
            check_out_date = ?,
            total_nights = ?,
            total_price = ?,
            final_price = ?,
            direct_amount = ?,
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
        $finalPrice, $newDirect, $finalPrice, $bookingId
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