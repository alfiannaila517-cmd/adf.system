<?php

/**
 * API: GET AVAILABLE ROOMS
 * Returns list of rooms that are NOT booked for the specified date range
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance();

    // Get date range from query params
    $checkIn = $_GET['check_in'] ?? '';
    $checkOut = $_GET['check_out'] ?? '';

    if (empty($checkIn) || empty($checkOut)) {
        echo json_encode([
            'success' => false,
            'message' => 'Check-in and check-out dates are required'
        ]);
        exit;
    }

    // Validate dates
    $checkInDate = new DateTime($checkIn);
    $checkOutDate = new DateTime($checkOut);

    if ($checkOutDate <= $checkInDate) {
        echo json_encode([
            'success' => false,
            'message' => 'Check-out must be after check-in'
        ]);
        exit;
    }

    // Get ALL rooms
    $allRooms = $db->fetchAll("
        SELECT r.id, r.room_number, r.floor_number, r.status, 
               rt.type_name, rt.base_price, rt.color_code
        FROM rooms r
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE r.status != 'maintenance'
        ORDER BY rt.type_name ASC, r.floor_number ASC, r.room_number ASC
    ", []);

    // Get rooms that are BOOKED during this date range
    // A room is booked if there's an OVERLAP:
    // Booking overlaps if: booking.check_in < our_check_out AND booking.check_out > our_check_in
    $bookedRooms = $db->fetchAll("
        SELECT DISTINCT room_id
        FROM bookings
        WHERE check_in_date < ?
        AND check_out_date > ?
        AND status IN ('pending', 'confirmed', 'checked_in')
    ", [$checkOut, $checkIn]);

    // Get rooms that are BLOCKED during this date range
    // Overlap rule: block_start < check_out AND block_end > check_in
    $blockedRooms = [];
    try {
        $blockedRooms = $db->fetchAll(" 
            SELECT DISTINCT room_id
            FROM room_blocks
            WHERE status = 'active'
            AND block_start_date < ?
            AND block_end_date > ?
        ", [$checkOut, $checkIn]);
    } catch (Exception $e) {
        // Table may not exist yet in old environments; treat as no blocks.
        $blockedRooms = [];
    }

    // Create array of booked room IDs
    $bookedRoomIds = array_column($bookedRooms, 'room_id');
    $blockedRoomIds = array_column($blockedRooms, 'room_id');
    $unavailableRoomIds = array_values(array_unique(array_merge($bookedRoomIds, $blockedRoomIds)));

    // Filter: only return rooms that are NOT in booked list
    $availableRooms = array_filter($allRooms, function ($room) use ($unavailableRoomIds) {
        return !in_array($room['id'], $unavailableRoomIds);
    });

    // Re-index array (remove gaps)
    $availableRooms = array_values($availableRooms);

    // Harga dari Cloudbeds (bila terhubung & harga semua malam sudah tersimpan): base_price = rata-rata
    // harga per malam, sehingga total di form = jumlah harga Cloudbeds. Selain itu tetap harga tipe kamar.
    try {
        $cbKey = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_api_key'");
        if (!empty($cbKey['setting_value']) && $db->fetchOne("SHOW TABLES LIKE 'cloudbeds_rates'")) {
            require_once '../includes/CloudbedsRates.php';
            $cbRates = new CloudbedsRates($db, new CloudbedsClient($db));
            $nightsCount = (int)$checkInDate->diff($checkOutDate)->days;
            $byType = [];
            foreach ($availableRooms as &$ar) {
                $t = (string)$ar['type_name'];
                if (!array_key_exists($t, $byType)) {
                    $byType[$t] = $cbRates->nightly($t, $checkInDate->format('Y-m-d'), $checkOutDate->format('Y-m-d'));
                }
                if ($byType[$t] && $nightsCount > 0) {
                    $ar['base_price_default'] = $ar['base_price'];
                    $ar['base_price'] = round($byType[$t]['total'] / $nightsCount);
                    $ar['price_source'] = 'cloudbeds';
                    $ar['cb_nights'] = $byType[$t]['nights'];
                }
            }
            unset($ar);
        }
    } catch (\Throwable $e) {
        error_log('get-available-rooms Cloudbeds rate: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'total_rooms' => count($allRooms),
        'available_rooms' => count($availableRooms),
        'booked_rooms' => count($bookedRoomIds),
        'blocked_rooms' => count($blockedRoomIds),
        'rooms' => $availableRooms
    ]);
} catch (Exception $e) {
    error_log("Get Available Rooms Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
