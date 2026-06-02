<?php

/**
 * BREAKFAST SAVE API
 * - Drop FK on created_by (users table is in system DB, not business DB)
 * - 1 guest_name per day = max 1 order (duplicate prevention)
 * - Support multi-room per guest
 */
define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

if (!$auth->hasPermission('frontdesk')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$action = $input['action'] ?? '';
$db = Database::getInstance();
$pdo = $db->getConnection();

// Drop FK constraint on created_by if it exists (users table is in system DB, not this business DB)
try {
    $fks = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breakfast_orders' 
        AND COLUMN_NAME = 'created_by' AND REFERENCED_TABLE_NAME = 'users'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($fks as $fk) {
        $pdo->exec("ALTER TABLE breakfast_orders DROP FOREIGN KEY `$fk`");
    }
} catch (Exception $e) { /* ignore */
}

// User from session (already validated by requireLogin)
$validUserId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

try {
    // Parse & validate menu items
    $menuItemIds = $input['menu_items'] ?? [];
    $menuQty = $input['menu_qty'] ?? [];
    $menuNote = $input['menu_note'] ?? [];
    $customExtras = $input['custom_extras'] ?? [];

    if (empty($menuItemIds) && empty($customExtras)) {
        throw new Exception('Pilih minimal 1 menu item atau tambahkan extra manual');
    }
    if (!is_array($menuItemIds)) $menuItemIds = [];

    $menuItems = [];
    $totalPrice = 0;

    foreach ($menuItemIds as $menuId) {
        $menuId = (int)$menuId;
        $qty = max(1, (int)($menuQty[$menuId] ?? 1));
        $note = isset($menuNote[$menuId]) ? trim($menuNote[$menuId]) : '';

        $menu = $db->fetchOne("SELECT menu_name, price, is_free FROM breakfast_menus WHERE id = ?", [$menuId]);
        if ($menu) {
            $item = [
                'menu_id' => $menuId,
                'menu_name' => $menu['menu_name'],
                'quantity' => $qty,
                'price' => $menu['price'],
                'is_free' => $menu['is_free']
            ];
            if ($note !== '') $item['note'] = $note;
            $menuItems[] = $item;
            if (!$menu['is_free']) $totalPrice += ($menu['price'] * $qty);
        }
    }

    // Process custom extras (manual items)
    if (is_array($customExtras)) {
        foreach ($customExtras as $ce) {
            $ceName = trim($ce['name'] ?? '');
            $cePrice = max(0, (float)($ce['price'] ?? 0));
            $ceQty = max(1, (int)($ce['quantity'] ?? 1));
            $ceNote = trim($ce['note'] ?? '');
            if ($ceName === '') continue;
            $item = [
                'menu_id' => 0,
                'menu_name' => $ceName,
                'quantity' => $ceQty,
                'price' => number_format($cePrice, 2, '.', ''),
                'is_free' => 0,
                'is_custom' => 1
            ];
            if ($ceNote !== '') $item['note'] = $ceNote;
            $menuItems[] = $item;
            $totalPrice += ($cePrice * $ceQty);
        }
    }

    if (count($menuItems) === 0) {
        throw new Exception('Tidak ada menu valid yang dipilih');
    }

    // Parse common fields
    $totalPax = max(1, (int)($input['total_pax'] ?? 1));
    $breakfastTime = $input['breakfast_time'] ?? '';
    // Hotel date: before 10 AM = previous day, so overnight picks remain visible in the morning.
    $defaultDate = (int)date('H') < 10 ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
    $breakfastDate = $input['breakfast_date'] ?? $defaultDate;
    $location = $input['location'] ?? 'restaurant';
    $specialRequests = !empty($input['special_requests']) ? trim($input['special_requests']) : null;

    if (empty($breakfastTime)) throw new Exception('Waktu sarapan harus diisi');

    // Validate location
    $validLocations = ['restaurant', 'room_service', 'take_away'];
    if (!in_array($location, $validLocations)) $location = 'restaurant';

    $menuJson = json_encode($menuItems);

    if ($action === 'create_bulk') {
        // Multi-guest → 1 COMBINED order (all guests + all rooms in one record)
        $guests = $input['guests'] ?? [];
        if (empty($guests) || !is_array($guests)) throw new Exception('Pilih minimal 1 tamu');

        $guestNames = [];
        $allRooms = [];
        $firstBookingId = null;
        $skipped = [];

        foreach ($guests as $guest) {
            $gName = trim($guest['guest_name'] ?? '');
            if (empty($gName)) continue;

            $gRooms = $guest['room_number'] ?? [];
            if (!is_array($gRooms)) $gRooms = [$gRooms];
            $gRooms = array_values(array_filter(array_map('trim', $gRooms)));

            // Duplicate check by ROOM NUMBER (reliable for group bookings with same guest name)
            $already = false;
            foreach ($gRooms as $rm) {
                $ex = $db->fetchOne(
                    "SELECT id FROM breakfast_orders WHERE breakfast_date = ? AND room_number LIKE ?",
                    [$breakfastDate, '%"' . $rm . '"%']
                );
                if ($ex) {
                    $already = true;
                    break;
                }
            }
            // Fallback to name check only when guest has no room assigned
            if (!$already && count($gRooms) === 0) {
                $ex = $db->fetchOne(
                    "SELECT id FROM breakfast_orders WHERE breakfast_date = ? AND FIND_IN_SET(?, REPLACE(guest_name, ', ', ',')) > 0",
                    [$breakfastDate, $gName]
                );
                if ($ex) $already = true;
            }
            if ($already) {
                $skipped[] = $gName . (count($gRooms) ? ' (Room ' . implode(',', $gRooms) . ')' : '');
                continue;
            }

            $guestNames[] = $gName;
            if ($firstBookingId === null && !empty($guest['booking_id'])) {
                $firstBookingId = (int)$guest['booking_id'];
            }
            foreach ($gRooms as $r) {
                if (!empty($r) && !in_array($r, $allRooms)) $allRooms[] = $r;
            }
        }

        if (count($guestNames) === 0 && count($skipped) > 0) {
            echo json_encode(['success' => false, 'message' => 'Semua tamu sudah punya order hari ini: ' . implode(', ', $skipped)]);
            exit;
        }
        if (count($guestNames) === 0) {
            throw new Exception('Tidak ada tamu valid yang dipilih');
        }

        $combinedName = implode(', ', $guestNames);
        $roomJson = json_encode($allRooms);

        $stmt = $pdo->prepare("INSERT INTO breakfast_orders 
            (booking_id, guest_name, room_number, total_pax, breakfast_time, breakfast_date, 
             location, menu_items, special_requests, total_price, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $firstBookingId,
            $combinedName,
            $roomJson,
            $totalPax,
            $breakfastTime,
            $breakfastDate,
            $location,
            $menuJson,
            $specialRequests,
            $totalPrice,
            $validUserId
        ]);

        $newId = $pdo->lastInsertId();
        $msg = "Order #{$newId} tersimpan untuk: " . $combinedName;
        if (count($skipped) > 0) $msg .= ' (dilewati: ' . implode(', ', $skipped) . ')';
        echo json_encode(['success' => true, 'message' => $msg, 'id' => $newId]);
    } elseif ($action === 'create_order') {
        // Single guest order
        $guestName = trim($input['guest_name'] ?? '');
        $bookingId = !empty($input['booking_id']) ? (int)$input['booking_id'] : null;
        $roomNumbers = $input['room_number'] ?? [];
        if (!is_array($roomNumbers)) $roomNumbers = [$roomNumbers];
        $roomJson = json_encode($roomNumbers);

        if (empty($guestName)) throw new Exception('Nama tamu harus diisi');

        // DUPLICATE PREVENTION by ROOM NUMBER (handles group bookings with identical names)
        $cleanRooms = array_values(array_filter(array_map('trim', $roomNumbers)));
        $existing = null;
        foreach ($cleanRooms as $rm) {
            $existing = $db->fetchOne(
                "SELECT id FROM breakfast_orders WHERE breakfast_date = ? AND room_number LIKE ?",
                [$breakfastDate, '%"' . $rm . '"%']
            );
            if ($existing) {
                echo json_encode([
                    'success' => false,
                    'message' => "Room {$rm} sudah punya order hari ini (ID #{$existing['id']}). Edit atau hapus dulu."
                ]);
                exit;
            }
        }
        // Fallback to name check only when no room assigned
        if (count($cleanRooms) === 0) {
            $existing = $db->fetchOne(
                "SELECT id FROM breakfast_orders WHERE breakfast_date = ? AND FIND_IN_SET(?, REPLACE(guest_name, ', ', ',')) > 0",
                [$breakfastDate, $guestName]
            );
            if ($existing) {
                echo json_encode([
                    'success' => false,
                    'message' => "{$guestName} sudah punya order hari ini (ID #{$existing['id']}). Edit atau hapus dulu."
                ]);
                exit;
            }
        }

        $stmt = $pdo->prepare("INSERT INTO breakfast_orders 
            (booking_id, guest_name, room_number, total_pax, breakfast_time, breakfast_date, 
             location, menu_items, special_requests, total_price, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $bookingId,
            $guestName,
            $roomJson,
            $totalPax,
            $breakfastTime,
            $breakfastDate,
            $location,
            $menuJson,
            $specialRequests,
            $totalPrice,
            $validUserId
        ]);

        $newId = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'message' => "Order #{$newId} untuk {$guestName} tersimpan!", 'id' => $newId]);
    } elseif ($action === 'update_order') {
        $editId = (int)($input['edit_id'] ?? 0);
        if ($editId <= 0) throw new Exception('ID order tidak valid');

        $guestName = trim($input['guest_name'] ?? '');
        $bookingId = !empty($input['booking_id']) ? (int)$input['booking_id'] : null;
        $roomNumbers = $input['room_number'] ?? [];
        if (!is_array($roomNumbers)) $roomNumbers = [$roomNumbers];
        $roomJson = json_encode($roomNumbers);

        $stmt = $pdo->prepare("UPDATE breakfast_orders SET 
            booking_id=?, guest_name=?, room_number=?, total_pax=?, breakfast_time=?, 
            breakfast_date=?, location=?, menu_items=?, special_requests=?, total_price=?
            WHERE id=?");
        $stmt->execute([
            $bookingId,
            $guestName,
            $roomJson,
            $totalPax,
            $breakfastTime,
            $breakfastDate,
            $location,
            $menuJson,
            $specialRequests,
            $totalPrice,
            $editId
        ]);

        echo json_encode(['success' => true, 'message' => "Order #{$editId} berhasil diupdate!", 'id' => $editId]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
    }
} catch (Exception $e) {
    error_log("Breakfast Save Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
