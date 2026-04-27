<?php

/**
 * API: Update Reservation
 * Edit reservation details (dates, room, guest info, price)
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
    error_log("=== update-reservation.php START ===");

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
    if (!$bookingId) {
        throw new Exception('Booking ID is required');
    }

    // Get current booking
    $stmt = $conn->prepare("SELECT b.*, g.id as gid FROM bookings b LEFT JOIN guests g ON b.guest_id = g.id WHERE b.id = ?");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception('Booking not found');
    }

    // Allow editing confirmed, pending, checked_in, and checked_out bookings
    if (!in_array($booking['status'], ['confirmed', 'pending', 'checked_in', 'checked_out'])) {
        throw new Exception('Cannot edit cancelled reservations');
    }

    // Update guest info in guests table
    if ($booking['gid']) {
        $guestUpdates = [];
        $guestParams = [];

        if (!empty($_POST['guest_name'])) {
            $guestUpdates[] = 'guest_name = ?';
            $guestParams[] = trim($_POST['guest_name']);
        }
        if (isset($_POST['guest_phone'])) {
            $guestUpdates[] = 'phone = ?';
            $guestParams[] = trim($_POST['guest_phone']);
        }
        if (isset($_POST['guest_email'])) {
            $guestUpdates[] = 'email = ?';
            $guestParams[] = trim($_POST['guest_email']);
        }
        if (isset($_POST['guest_nationality']) || isset($_POST['nationality'])) {
            $guestUpdates[] = 'nationality = ?';
            $guestParams[] = trim($_POST['guest_nationality'] ?? $_POST['nationality']);
        }
        if (isset($_POST['guest_id_number'])) {
            $guestUpdates[] = 'id_card_number = ?';
            $guestParams[] = trim($_POST['guest_id_number']);
        }

        if (!empty($guestUpdates)) {
            $guestParams[] = $booking['gid'];
            $sql = "UPDATE guests SET " . implode(', ', $guestUpdates) . ", updated_at = NOW() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute($guestParams);
        }
    }

    // Build booking update fields
    $updates = [];
    $params = [];
    $isGroupMode = !empty($_POST['is_group']);
    error_log("is_group mode: " . ($isGroupMode ? 'YES' : 'NO'));

    if (isset($_POST['special_requests'])) {
        $updates[] = 'special_request = ?';
        $params[] = trim($_POST['special_requests']);
    }
    if (isset($_POST['num_guests'])) {
        $updates[] = 'adults = ?';
        $params[] = intval($_POST['num_guests']);
    }
    if (isset($_POST['booking_source'])) {
        $src = trim($_POST['booking_source']);
        if (!$src) $src = $booking['booking_source']; // Keep existing if empty
        if ($src === 'other') $src = 'ota';
        $updates[] = 'booking_source = ?';
        $params[] = $src;
        error_log("booking_source will be updated to: " . $src);
    }

    // Date changes
    $checkIn = !empty($_POST['check_in_date']) ? trim($_POST['check_in_date']) : $booking['check_in_date'];
    $checkOut = !empty($_POST['check_out_date']) ? trim($_POST['check_out_date']) : $booking['check_out_date'];

    $ciDate = new DateTime($checkIn);
    $coDate = new DateTime($checkOut);
    if ($coDate <= $ciDate) {
        throw new Exception('Check-out must be after check-in');
    }
    $nights = $ciDate->diff($coDate)->days;

    // OTA fee calculation (needed for both single and group)
    $bookingSource = trim($_POST['booking_source'] ?? $booking['booking_source']);
    $otaFeePercent = 0;
    try {
        $feeStmt = $conn->prepare("SELECT fee_percent FROM booking_sources WHERE source_key = ? AND is_active = 1 LIMIT 1");
        $feeStmt->execute([$bookingSource]);
        $feeRow = $feeStmt->fetch(PDO::FETCH_ASSOC);
        if ($feeRow) {
            $otaFeePercent = (float)$feeRow['fee_percent'];
        }
    } catch (Exception $e) {
        // fallback: no fee
    }

    // For GROUP mode: only update shared fields (dates, source, guest info) on primary booking
    // Per-room fields (room_id, room_price, discount, final_price) are handled in group loop below
    if (!$isGroupMode) {
        $newRoomId = !empty($_POST['room_id']) ? intval($_POST['room_id']) : $booking['room_id'];
        $roomId = $newRoomId;

        // Check availability if dates or room changed
        if ($checkIn !== $booking['check_in_date'] || $checkOut !== $booking['check_out_date'] || $roomId !== intval($booking['room_id'])) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM bookings 
                WHERE room_id = ? AND id != ? 
                AND status NOT IN ('cancelled', 'checked_out')
                AND check_in_date < ? AND check_out_date > ?
            ");
            $stmt->execute([$roomId, $bookingId, $checkOut, $checkIn]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('Room is not available for selected dates');
            }
        }

        // Handle room change
        if ($roomId !== intval($booking['room_id'])) {
            $updates[] = 'room_id = ?';
            $params[] = $roomId;
        }

        // Get room price
        $roomPrice = floatval($_POST['room_price'] ?? 0);
        if (!$roomPrice) {
            $roomPrice = floatval($booking['room_price']);
        }
        if (!$roomPrice) {
            $stmt = $conn->prepare("SELECT rt.base_price FROM rooms r JOIN room_types rt ON r.room_type_id = rt.id WHERE r.id = ?");
            $stmt->execute([$roomId]);
            $rtRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $roomPrice = $rtRow ? $rtRow['base_price'] : 0;
        }

        $totalPrice = $roomPrice * $nights;

        // Discount handling
        $discountType = $_POST['discount_type'] ?? 'rp';
        $discountValue = floatval($_POST['discount_value'] ?? 0);
        if ($discountType === 'percent' && $discountValue > 0) {
            $discount = round($totalPrice * $discountValue / 100);
        } else {
            $discount = $discountValue;
        }

        $afterDiscount = $totalPrice - $discount;

        $otaFeeAmount = 0;
        if ($otaFeePercent > 0) {
            $otaFeeAmount = round($afterDiscount * $otaFeePercent / 100);
        }

        $roomFinalPrice = $afterDiscount - $otaFeeAmount;

        // Include extras in final price
        $extrasTotal = 0;
        try {
            $extStmt = $conn->prepare("SELECT COALESCE(SUM(total_price), 0) as total FROM booking_extras WHERE booking_id = ?");
            $extStmt->execute([$bookingId]);
            $extrasTotal = (float)$extStmt->fetch(PDO::FETCH_ASSOC)['total'];
        } catch (Exception $e) { /* table might not exist */
        }

        $finalPrice = $roomFinalPrice + $extrasTotal;

        $updates[] = 'room_price = ?';
        $params[] = $roomPrice;
        $updates[] = 'total_price = ?';
        $params[] = $totalPrice;
        $updates[] = 'discount = ?';
        $params[] = $discount;
        $updates[] = 'final_price = ?';
        $params[] = $finalPrice;
    }

    // Add date fields to booking update (shared for both single and group)
    $updates[] = 'check_in_date = ?';
    $params[] = $checkIn;
    $updates[] = 'check_out_date = ?';
    $params[] = $checkOut;
    $updates[] = 'total_nights = ?';
    $params[] = $nights;
    $updates[] = 'updated_at = NOW()';

    // Execute booking update
    $params[] = $bookingId;
    $sql = "UPDATE bookings SET " . implode(', ', $updates) . " WHERE id = ?";
    error_log("SQL: " . $sql);
    error_log("Params: " . json_encode($params));
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $mainRows = $stmt->rowCount();
    error_log("Rows affected: " . $mainRows);

    // Update booking_source with OTA detail tracking
    // booking_source = ENUM('walk_in','phone','online','ota')
    // ota_source_detail = store the actual OTA (agoda, booking, traveloka, etc)
    $formSource = trim($_POST['booking_source'] ?? $booking['booking_source']);

    // Map OTA sources (agoda, booking, ctrip, etc) -> 'ota' + store detail
    $otaSources = ['agoda', 'booking', 'booking.com', 'ctrip', 'expedia', 'airbnb', 'traveloka', 'ota'];
    $isOTA = in_array(strtolower($formSource), array_map('strtolower', $otaSources));
    $intendedSource = $isOTA ? 'ota' : $formSource;
    $otaSourceDetail = $isOTA ? strtolower($formSource) : null;

    error_log("🔍 SOURCE MAPPING: formSource='$formSource' → source='$intendedSource', detail='$otaSourceDetail'");

    $standaloneRows = -1;
    $standaloneError = '';

    if (!empty($intendedSource)) {
        try {
            // UPDATE both booking_source and ota_source_detail
            $srcSql = "UPDATE bookings SET booking_source = ?, ota_source_detail = ? WHERE id = ?";
            $srcStmt = $conn->prepare($srcSql);
            $srcStmt->execute([$intendedSource, $otaSourceDetail, $bookingId]);
            $standaloneRows = $srcStmt->rowCount();
            error_log("✅ UPDATE booking_source: rows = " . $standaloneRows);
            error_log("   SQL: " . $srcSql);
            error_log("   Params: source='" . $intendedSource . "', detail='" . $otaSourceDetail . "', id=" . $bookingId);

            // Verify in database
            $verifyStmt = $conn->prepare("SELECT booking_source, ota_source_detail FROM bookings WHERE id = ?");
            $verifyStmt->execute([$bookingId]);
            $verifyRow = $verifyStmt->fetch(PDO::FETCH_ASSOC);
            error_log("✅ VERIFICATION: source='" . $verifyRow['booking_source'] . "', detail='" . $verifyRow['ota_source_detail'] . "'");
        } catch (Exception $se) {
            $standaloneError = $se->getMessage();
            error_log("❌ UPDATE ERROR: " . $standaloneError);
        }
    } else {
        error_log("⚠️ UPDATE skipped: intendedSource is empty");
    }

    // VERIFY: Re-read FULL row from database
    $verifyStmt = $conn->prepare("
        SELECT b.id, b.booking_source, b.status, b.room_id, b.room_price, b.final_price,
               r.room_number, rt.type_name
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE b.id = ?
    ");
    $verifyStmt->execute([$bookingId]);
    $verifyRow = $verifyStmt->fetch(PDO::FETCH_ASSOC);
    $verifiedSource = $verifyRow ? $verifyRow['booking_source'] : '__ROW_NOT_FOUND__';
    error_log("=== VERIFY SECTION ===");
    error_log("Query: SELECT booking_source FROM bookings WHERE id = " . $bookingId);
    error_log("Result: booking_source = '" . $verifiedSource . "'");
    error_log("Full verify row: " . json_encode($verifyRow));

    if ($verifiedSource !== $intendedSource && !empty($intendedSource)) {
        error_log("❌❌❌ CRITICAL: Intended '" . $intendedSource . "' but database has '" . $verifiedSource . "'");
        error_log("❌❌❌ Standalone update FAILED to save to database!");
    }

    // Also check current database name
    $dbNameStmt = $conn->query("SELECT DATABASE()");
    $currentDb = $dbNameStmt->fetchColumn();

    // GROUP UPDATE: update each room in the group
    $groupUpdated = [];
    if ($isGroupMode && !empty($_POST['rooms_json'])) {
        error_log("GROUP MODE: processing rooms_json");
        $roomsData = json_decode($_POST['rooms_json'], true);
        error_log("rooms_json decoded: " . json_encode($roomsData));
        if (is_array($roomsData)) {
            foreach ($roomsData as $rdIdx => $rd) {
                $rdBookingId = intval($rd['booking_id'] ?? 0);
                if (!$rdBookingId) {
                    error_log("GROUP: skip idx=$rdIdx no booking_id");
                    continue;
                }

                $rdRoomId = intval($rd['room_id'] ?? 0);
                $rdRoomPrice = floatval($rd['room_price'] ?? 0);
                $rdDiscount = floatval($rd['discount'] ?? 0);

                error_log("GROUP room[$rdIdx]: bid=$rdBookingId rid=$rdRoomId price=$rdRoomPrice disc=$rdDiscount");

                if (!$rdRoomId) {
                    error_log("GROUP: skip idx=$rdIdx no room_id");
                    continue;
                }
                if (!$rdRoomPrice) {
                    // Fallback to room_types base_price
                    $rpStmt = $conn->prepare("SELECT rt.base_price FROM rooms r JOIN room_types rt ON r.room_type_id = rt.id WHERE r.id = ?");
                    $rpStmt->execute([$rdRoomId]);
                    $rpRow = $rpStmt->fetch(PDO::FETCH_ASSOC);
                    $rdRoomPrice = $rpRow ? (float)$rpRow['base_price'] : 0;
                }

                $rdTotalPrice = $rdRoomPrice * $nights;
                $rdAfterDiscount = $rdTotalPrice - $rdDiscount;

                // OTA fee
                $rdFee = 0;
                if ($otaFeePercent > 0) {
                    $rdFee = round($rdAfterDiscount * $otaFeePercent / 100);
                }
                $rdRoomNet = $rdAfterDiscount - $rdFee;

                // Extras for this specific booking
                $rdExtras = 0;
                try {
                    $extCheck = $conn->prepare("SELECT COALESCE(SUM(total_price), 0) FROM booking_extras WHERE booking_id = ?");
                    $extCheck->execute([$rdBookingId]);
                    $rdExtras = (float)$extCheck->fetchColumn();
                } catch (Exception $e) { /* table might not exist */
                }

                $rdFinalPrice = $rdRoomNet + $rdExtras;

                // Check room availability for this booking
                if ($rdRoomId) {
                    $avStmt = $conn->prepare("
                        SELECT COUNT(*) FROM bookings 
                        WHERE room_id = ? AND id != ? 
                        AND status NOT IN ('cancelled', 'checked_out')
                        AND check_in_date < ? AND check_out_date > ?
                    ");
                    $avStmt->execute([$rdRoomId, $rdBookingId, $checkOut, $checkIn]);
                    if ($avStmt->fetchColumn() > 0) {
                        // Skip this room - not available (don't fail entire request)
                        $groupUpdated[] = ['booking_id' => $rdBookingId, 'status' => 'skipped', 'reason' => 'room not available'];
                        continue;
                    }
                }

                // Update this booking (room fields + shared fields)
                $grpStmt = $conn->prepare("
                    UPDATE bookings SET 
                        room_id = ?, room_price = ?, total_price = ?, discount = ?, final_price = ?,
                        check_in_date = ?, check_out_date = ?, total_nights = ?,
                        booking_source = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $grpStmt->execute([
                    $rdRoomId,
                    $rdRoomPrice,
                    $rdTotalPrice,
                    $rdDiscount,
                    $rdFinalPrice,
                    $checkIn,
                    $checkOut,
                    $nights,
                    $intendedSource,
                    $rdBookingId
                ]);
                error_log("GROUP room[$rdIdx] updated: final_price=$rdFinalPrice");
                $groupUpdated[] = ['booking_id' => $rdBookingId, 'status' => 'updated', 'final_price' => $rdFinalPrice];
            }
        }
    }

    // Handle NEW ROOMS added to the group
    $newRoomsAdded = [];
    if (!empty($_POST['new_rooms_json'])) {
        $newRoomsData = json_decode($_POST['new_rooms_json'], true);
        if (is_array($newRoomsData) && count($newRoomsData) > 0) {
            error_log("NEW ROOMS: " . count($newRoomsData) . " rooms to add");

            // Ensure group_id exists
            $groupId = $booking['group_id'];
            if (empty($groupId)) {
                $groupId = 'GRP-' . date('Ymd') . '-' . substr(uniqid(), -6);
                // Update existing booking with group_id
                $conn->prepare("UPDATE bookings SET group_id = ? WHERE id = ?")->execute([$groupId, $bookingId]);
                error_log("Created new group_id: $groupId");
            }

            foreach ($newRoomsData as $nrIdx => $nr) {
                $nrRoomId = intval($nr['room_id'] ?? 0);
                $nrRoomPrice = floatval($nr['room_price'] ?? 0);
                $nrDiscount = floatval($nr['discount'] ?? 0);

                if (!$nrRoomId) continue;

                if (!$nrRoomPrice) {
                    $rpStmt = $conn->prepare("SELECT rt.base_price FROM rooms r JOIN room_types rt ON r.room_type_id = rt.id WHERE r.id = ?");
                    $rpStmt->execute([$nrRoomId]);
                    $rpRow = $rpStmt->fetch(PDO::FETCH_ASSOC);
                    $nrRoomPrice = $rpRow ? (float)$rpRow['base_price'] : 0;
                }

                // Check availability
                $avStmt = $conn->prepare("
                    SELECT COUNT(*) FROM bookings 
                    WHERE room_id = ? AND status NOT IN ('cancelled','checked_out')
                    AND check_in_date < ? AND check_out_date > ?
                ");
                $avStmt->execute([$nrRoomId, $checkOut, $checkIn]);
                if ($avStmt->fetchColumn() > 0) {
                    $newRoomsAdded[] = ['room_id' => $nrRoomId, 'status' => 'skipped', 'reason' => 'room not available'];
                    continue;
                }

                $nrTotalPrice = $nrRoomPrice * $nights;
                $nrAfterDiscount = $nrTotalPrice - $nrDiscount;
                $nrFee = $otaFeePercent > 0 ? round($nrAfterDiscount * $otaFeePercent / 100) : 0;
                $nrFinalPrice = $nrAfterDiscount - $nrFee;

                // Generate booking code
                $nrBookingCode = 'BK-' . date('Ymd') . '-' . rand(1000, 9999);

                $insStmt = $conn->prepare("
                    INSERT INTO bookings (
                        booking_code, group_id, guest_id, room_id,
                        check_in_date, check_out_date, total_nights,
                        adults, children,
                        room_price, total_price, discount, final_price,
                        booking_source, status, payment_status, paid_amount,
                        special_request, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', 'unpaid', 0, '', NOW(), NOW())
                ");
                $insStmt->execute([
                    $nrBookingCode,
                    $groupId,
                    $booking['gid'],
                    $nrRoomId,
                    $checkIn,
                    $checkOut,
                    $nights,
                    $booking['adults'] ?? 1,
                    $booking['children'] ?? 0,
                    $nrRoomPrice,
                    $nrTotalPrice,
                    $nrDiscount,
                    $nrFinalPrice,
                    $intendedSource
                ]);
                $newId = $conn->lastInsertId();
                error_log("NEW ROOM added: booking_id=$newId room_id=$nrRoomId code=$nrBookingCode");
                $newRoomsAdded[] = ['booking_id' => $newId, 'room_id' => $nrRoomId, 'status' => 'created', 'code' => $nrBookingCode];
            }

            // Also update existing booking's group_id if it was just created
            if (!$booking['group_id'] && !empty($groupId)) {
                $conn->prepare("UPDATE bookings SET group_id = ? WHERE id = ?")->execute([$groupId, $bookingId]);
            }
        }
    }

    // Calculate combined totals for response
    $respTotalPrice = $isGroupMode ? 0 : ($totalPrice ?? 0);
    $respFinalPrice = $isGroupMode ? 0 : ($finalPrice ?? 0);
    if ($isGroupMode && !empty($groupUpdated)) {
        foreach ($groupUpdated as $gu) {
            if (isset($gu['final_price'])) $respFinalPrice += $gu['final_price'];
        }
    }

    // Build descriptive success message
    $successMsg = 'Reservation updated successfully';
    if (!empty($newRoomsAdded)) {
        $createdCount = count(array_filter($newRoomsAdded, fn($r) => $r['status'] === 'created'));
        if ($createdCount > 0) {
            $successMsg .= " + $createdCount room baru ditambahkan";
        }
    }
    if (!$isGroupMode && $verifyRow) {
        $successMsg .= ' — Room ' . $verifyRow['room_number'] . ' (' . $verifyRow['type_name'] . ')';
    }

    echo json_encode([
        'success' => true,
        'message' => $successMsg,
        'data' => [
            'booking_id' => $bookingId,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'nights' => $nights,
            'total_price' => $respTotalPrice,
            'final_price' => $respFinalPrice,
            'booking_source' => $verifiedSource,
            'intended_source' => $intendedSource,
            'is_group' => $isGroupMode
        ],
        'debug' => [
            'main_update_rows' => $mainRows,
            'standalone_rows' => $standaloneRows,
            'standalone_error' => $standaloneError,
            'verified_row' => $verifyRow,
            'current_db' => $currentDb,
            'post_booking_source' => $_POST['booking_source'] ?? '__NOT_SET__',
            'original_source' => $booking['booking_source'],
            'group_updated' => $groupUpdated
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
error_log("=== update-reservation.php END ===");
ob_end_flush();
