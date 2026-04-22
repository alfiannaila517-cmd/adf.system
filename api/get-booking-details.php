<?php
// CRITICAL: Set header & prevent output FIRST
header('Content-Type: application/json');
if (ob_get_level() === 0) ob_start();
error_reporting(0);
ini_set('display_errors', 0);

try {
    // Clear any buffered output
    while (ob_get_level() > 0) ob_end_clean();
    ob_start();

    define('APP_ACCESS', true);
    require_once '../config/config.php';
    require_once '../config/database.php';

    // Re-suppress errors AFTER config.php
    error_reporting(0);
    ini_set('display_errors', 0);

    $db = Database::getInstance();
    $conn = $db->getConnection();

    $bookingId = intval($_GET['id'] ?? 0);

    if (!$bookingId) {
        echo json_encode(['success' => false, 'message' => 'Booking ID required']);
        exit;
    }

    // Fetch booking with guest and room details
    $query = "
        SELECT 
            b.id,
            b.booking_code,
            b.room_id,
            b.guest_id,
            b.group_id,
            b.check_in_date,
            b.check_out_date,
            b.total_nights,
            b.room_price,
            b.total_price,
            COALESCE(b.discount, 0) as discount,
            b.final_price,
            b.status,
            b.payment_status,
            b.booking_source,
            b.ota_source_detail,
            COALESCE(b.adults, 1) as adults,
            COALESCE(b.adults, 1) as num_guests,
            COALESCE(b.children, 0) as children,
            COALESCE(b.special_request, '') as special_requests,
            g.guest_name,
            g.phone as guest_phone,
            g.email as guest_email,
            COALESCE(g.id_card_number, '') as guest_id_number,
            r.room_number,
            rt.type_name as room_type,
            rt.base_price,
            b.paid_amount
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE b.id = ?
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    // Debug logging
    error_log("=== GET-BOOKING-DETAILS DEBUG ===");
    error_log("Booking ID: " . $bookingId);
    error_log("Booking data: " . json_encode($booking));
    if ($booking) {
        error_log("booking_source value: '" . ($booking['booking_source'] ?? 'NULL') . "'");
        error_log("booking_source type: " . gettype($booking['booking_source']));
    }

    if (!$booking) {
        echo json_encode(['success' => false, 'message' => 'Booking not found with ID: ' . $bookingId]);
        exit;
    }

    // Ensure all fields have values
    $booking['guest_phone'] = $booking['guest_phone'] ?? '-';
    $booking['guest_email'] = $booking['guest_email'] ?? '-';
    $booking['guest_id_number'] = $booking['guest_id_number'] ?? '-';

    // Determine the correct booking source
    // Priority: ota_source_detail > booking_source > default to walk_in
    if (!empty($booking['ota_source_detail'])) {
        // If OTA source detail exists (e.g., 'Traveloka', 'Booking.com'), use it
        $booking['booking_source'] = $booking['ota_source_detail'];
    } elseif (!empty($booking['booking_source'])) {
        // If booking_source exists, use it as-is (e.g., 'ota', 'direct', 'phone')
        // But if it's just 'ota' without detail, we'll keep it as 'ota' to indicate it came from OTA
        $booking['booking_source'] = $booking['booking_source'];
    } else {
        // No source info at all, default to walk_in
        $booking['booking_source'] = 'walk_in';
    }
    error_log("✅ Final booking_source in response: '" . $booking['booking_source'] . "' (ota_source_detail: '" . ($booking['ota_source_detail'] ?? 'NULL') . "')");

    // Fetch payment history
    $payments = [];
    try {
        $pStmt = $conn->prepare("SELECT amount, payment_method, payment_date, notes FROM booking_payments WHERE booking_id = ? ORDER BY payment_date DESC");
        $pStmt->execute([$bookingId]);
        $payments = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* ignore */
    }
    $booking['payments'] = $payments;

    // Fetch extras (extra bed, laundry, dll)
    $extras = [];
    $totalExtras = 0;
    try {
        $eStmt = $conn->prepare("SELECT id, item_name, quantity, unit_price, total_price, notes, created_at FROM booking_extras WHERE booking_id = ? ORDER BY created_at ASC");
        $eStmt->execute([$bookingId]);
        $extras = $eStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($extras as $ex) {
            $totalExtras += (float)$ex['total_price'];
        }
    } catch (Exception $e) { /* table might not exist yet */
    }
    $booking['extras'] = $extras;
    $booking['total_extras'] = $totalExtras;

    // Fetch created_at
    try {
        $cStmt = $conn->prepare("SELECT created_at FROM bookings WHERE id = ?");
        $cStmt->execute([$bookingId]);
        $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
        $booking['created_at'] = $cRow['created_at'] ?? null;
    } catch (Exception $e) { /* ignore */
    }

    // Fetch group bookings - AUTO-DETECT by guest_id + check_in_date + check_out_date OR by group_id
    $groupBookings = [];
    try {
        $guestId = $booking['guest_id'] ?? null;
        $groupId = $booking['group_id'] ?? null;
        $checkInDate = trim($booking['check_in_date'] ?? '');
        $checkOutDate = trim($booking['check_out_date'] ?? '');

        error_log("=== GROUP BOOKING DEBUG ===");
        error_log("Booking ID: " . $bookingId);
        error_log("guest_id: " . json_encode($guestId));
        error_log("group_id: " . json_encode($groupId));
        error_log("check_in_date (raw): " . json_encode($checkInDate));
        error_log("check_out_date (raw): " . json_encode($checkOutDate));

        if ($guestId && !empty($checkInDate) && !empty($checkOutDate)) {
            // Extract date part only (remove time component)
            $checkInDateOnly = substr($checkInDate, 0, 10);
            $checkOutDateOnly = substr($checkOutDate, 0, 10);

            error_log("Extracted dates - IN: " . $checkInDateOnly . ", OUT: " . $checkOutDateOnly);

            // Strategy 1: Try using group_id first if it exists
            if (!empty($groupId)) {
                error_log("Using group_id strategy: " . $groupId);
                $sql = "
                    SELECT 
                        b.id,
                        b.booking_code,
                        b.room_id,
                        b.room_price,
                        COALESCE(b.discount, 0) as discount,
                        b.final_price,
                        b.status,
                        r.room_number,
                        rt.type_name
                    FROM bookings b
                    LEFT JOIN rooms r ON b.room_id = r.id
                    LEFT JOIN room_types rt ON r.room_type_id = rt.id
                    WHERE b.group_id = ?
                    AND b.status NOT IN ('cancelled')
                    ORDER BY b.id ASC
                ";
                $gStmt = $conn->prepare($sql);
                $gStmt->execute([$groupId]);
                $groupBookings = $gStmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("Group ID query result count: " . count($groupBookings));
            }

            // Strategy 2: If no results from group_id, use guest_id + dates
            if (empty($groupBookings)) {
                error_log("Using guest_id + dates strategy");

                // First, count ALL bookings for this guest regardless of dates to debug
                $countAllSql = "SELECT COUNT(*) as cnt FROM bookings WHERE guest_id = ?";
                $countStmt = $conn->prepare($countAllSql);
                $countStmt->execute([$guestId]);
                $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
                error_log("Total bookings for guest_id " . $guestId . ": " . $countResult['cnt']);

                // Now count bookings matching date criteria
                $countDateSql = "
                    SELECT COUNT(*) as cnt FROM bookings 
                    WHERE guest_id = ? 
                    AND LEFT(check_in_date, 10) = ?
                    AND LEFT(check_out_date, 10) = ?
                    AND status NOT IN ('cancelled')
                ";
                $countDateStmt = $conn->prepare($countDateSql);
                $countDateStmt->execute([$guestId, $checkInDateOnly, $checkOutDateOnly]);
                $countDateResult = $countDateStmt->fetch(PDO::FETCH_ASSOC);
                error_log("Bookings matching dates: " . $countDateResult['cnt']);

                $sql = "
                    SELECT 
                        b.id,
                        b.booking_code,
                        b.room_id,
                        b.room_price,
                        COALESCE(b.discount, 0) as discount,
                        b.final_price,
                        b.status,
                        r.room_number,
                        rt.type_name
                    FROM bookings b
                    LEFT JOIN rooms r ON b.room_id = r.id
                    LEFT JOIN room_types rt ON r.room_type_id = rt.id
                    WHERE b.guest_id = ? 
                    AND LEFT(b.check_in_date, 10) = ?
                    AND LEFT(b.check_out_date, 10) = ?
                    AND b.status NOT IN ('cancelled')
                    ORDER BY b.id ASC
                ";
                error_log("SQL: " . $sql);
                error_log("Params: [" . $guestId . ", " . $checkInDateOnly . ", " . $checkOutDateOnly . "]");

                $gStmt = $conn->prepare($sql);
                $gStmt->execute([$guestId, $checkInDateOnly, $checkOutDateOnly]);
                $groupBookings = $gStmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("Date-based query result count: " . count($groupBookings));
            }

            error_log("Final result count: " . count($groupBookings));
            error_log("Result: " . json_encode($groupBookings));
        } else {
            error_log("Skipping group booking - missing values");
        }
    } catch (Exception $e) {
        error_log("Group booking query failed: " . $e->getMessage());
    }

    $booking['group_bookings'] = $groupBookings;

    // Return JSON response
    ob_clean();
    echo json_encode([
        'success' => true,
        'booking' => $booking
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

ob_end_flush();
