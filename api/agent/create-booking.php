<?php
/**
 * Agent Tool: create_booking
 * Buat reservasi baru dari AI agent (status: confirmed, payment: unpaid).
 * Method: POST (application/json)
 * Header: X-Agent-Key: <key>
 * Body: {
 *   guest_name, phone, email?,
 *   room_id, check_in_date, check_out_date,
 *   adults?, children?, special_request?
 * }
 */

header('Content-Type: application/json');
error_reporting(0); ini_set('display_errors', 0);

define('APP_ACCESS', true);
require_once '_auth.php';

$pdo = agent_auth_check();

try {
    // Baca body JSON
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!$body) {
        // Fallback ke POST form
        $body = $_POST;
    }

    // Validasi field wajib
    $required = ['guest_name', 'phone', 'room_id', 'check_in_date', 'check_out_date'];
    foreach ($required as $f) {
        if (empty($body[$f])) {
            echo json_encode(['success' => false, 'error' => "Field '$f' wajib diisi"]);
            exit;
        }
    }

    $guestName   = trim($body['guest_name']);
    $phone       = trim($body['phone']);
    $email       = trim($body['email'] ?? '');
    $roomId      = (int)$body['room_id'];
    $checkIn     = $body['check_in_date'];
    $checkOut    = $body['check_out_date'];
    $adults      = max(1, (int)($body['adults']   ?? 1));
    $children    = max(0, (int)($body['children'] ?? 0));
    $specialReq  = trim($body['special_request'] ?? '');

    // Hitung malam
    $ciDate = new DateTime($checkIn);
    $coDate = new DateTime($checkOut);
    if ($coDate <= $ciDate) {
        echo json_encode(['success' => false, 'error' => 'check_out harus setelah check_in']);
        exit;
    }
    $totalNights = $ciDate->diff($coDate)->days;

    // Cek kamar exist & ambil harga
    $stmt = $pdo->prepare("SELECT r.id, r.room_number, rt.type_name, rt.base_price
         FROM rooms r LEFT JOIN room_types rt ON r.room_type_id = rt.id
         WHERE r.id = ? AND r.status != 'maintenance'");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$room) {
        echo json_encode(['success' => false, 'error' => 'Kamar tidak ditemukan atau sedang maintenance']);
        exit;
    }

    // Cek availability
    $stmt2 = $pdo->prepare("SELECT id FROM bookings
         WHERE room_id = ? AND check_in_date < ? AND check_out_date > ?
         AND status IN ('pending','confirmed','checked_in')");
    $stmt2->execute([$roomId, $checkOut, $checkIn]);
    $conflict = $stmt2->fetch(PDO::FETCH_ASSOC);
    if ($conflict) {
        echo json_encode(['success' => false, 'error' => 'Kamar sudah dipesan untuk tanggal tersebut']);
        exit;
    }

    $roomPrice  = (float)$room['base_price'];
    $totalPrice = $roomPrice * $totalNights;
    $finalPrice = $totalPrice;

    // Insert / temukan guest
    $stmtG = $pdo->prepare("SELECT id FROM guests WHERE phone = ? LIMIT 1");
    $stmtG->execute([$phone]);
    $existingGuest = $stmtG->fetch(PDO::FETCH_ASSOC);
    if ($existingGuest) {
        $guestId = $existingGuest['id'];
    } else {
        $pdo->prepare("INSERT INTO guests (guest_name, phone, email, id_card_number, created_at) VALUES (?, ?, ?, ?, NOW())")
            ->execute([$guestName, $phone, $email, 'AGENT-' . date('YmdHis')]);
        $guestId = $pdo->lastInsertId();
    }

    // Generate booking code
    do {
        $bookingCode = 'BK-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $chk = $pdo->prepare("SELECT id FROM bookings WHERE booking_code = ?");
        $chk->execute([$bookingCode]);
    } while ($chk->fetch());

    $pdo->prepare("INSERT INTO bookings (
            booking_code, guest_id, room_id,
            check_in_date, check_out_date, total_nights,
            adults, children,
            room_price, total_price, discount, final_price,
            booking_source, status, payment_status, paid_amount,
            special_request, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'whatsapp', 'confirmed', 'unpaid', 0, ?, NOW())")
        ->execute([
            $bookingCode, $guestId, $roomId,
            $checkIn, $checkOut, $totalNights,
            $adults, $children,
            $roomPrice, $totalPrice, $finalPrice,
            $specialReq
        ]);
    $bookingId = $pdo->lastInsertId();

    // Kirim notif ke front desk
    $pdo->prepare("INSERT INTO notifications (title, message, type, reference_id, is_read, created_at) VALUES (?, ?, 'new_booking', ?, 0, NOW())")
        ->execute([
            '📱 Booking Baru via AI Agent',
            "Tamu: $guestName | Kamar: {$room['room_number']} | Check-in: $checkIn | Kode: $bookingCode",
            $bookingId
        ]);

    echo json_encode([
        'success'      => true,
        'booking_code' => $bookingCode,
        'booking_id'   => (int)$bookingId,
        'guest_name'   => $guestName,
        'room_number'  => $room['room_number'],
        'room_type'    => $room['type_name'],
        'check_in'     => $checkIn,
        'check_out'    => $checkOut,
        'total_nights' => $totalNights,
        'final_price'  => (int)$finalPrice,
        'status'       => 'confirmed',
        'payment_status' => 'unpaid',
        'message'      => "Booking berhasil dibuat! Kode booking: $bookingCode. Silakan lakukan pembayaran saat check-in.",
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
