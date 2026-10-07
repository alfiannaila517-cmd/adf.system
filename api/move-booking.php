<?php
/**
 * API: Move Booking / Pindah Kamar (drag & drop kalender + popup Pindah Kamar)
 *
 * Aturan harga:
 *  - Pindah ke tipe kamar yang SAMA      → harga per malam tetap (harga booking).
 *  - Upgrade / downgrade (tipe berbeda)  → harga per malam mengikuti harga asli tipe kamar baru.
 *  - Diskon (Rp) booking tidak berubah; extras tetap ditagihkan.
 *  - Booking OTA (tiket, agoda, …): harga OTA tetap; upgrade menambah selisih harga asli tipe kamar
 *    yang sudah dipotong fee OTA: harga baru = harga lama + (harga tipe baru − harga tipe lama) × (1 − fee%).
 *    Downgrade booking OTA tidak mengurangi harga (sudah dibayar OTA). Kenaikan tagihan dicatat di
 *    bookings.direct_amount = dibayar tamu langsung ke hotel, tanpa potongan fee OTA.
 *  - Staf boleh mengisi harga manual (room_price) — menggantikan harga otomatis.
 *  - Tamu in-house pindah di tengah menginap: malam sebelum tanggal efektif tetap seperti tagihan lama,
 *    malam sesudahnya harga baru. room_price = harga kamar yang sedang ditempati (dipakai extend/pindah
 *    berikutnya); total_price = jumlah persis seluruh malam.
 *
 * POST: booking_id, new_check_in?, new_check_out?, new_room_id?, room_price? (manual),
 *       effective_date? (in-house), preview=1 (hitung saja, tidak menyimpan)
 */

header('Content-Type: application/json');
if (ob_get_level() === 0) ob_start();
error_reporting(0);
ini_set('display_errors', 0);

define('APP_ACCESS', true);

try {
    require_once '../config/config.php';
    require_once '../config/database.php';
    require_once '../includes/auth.php';
    require_once '../includes/BookingSourceHelper.php';

    $auth = new Auth();
    if (!$auth->isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    if (!$auth->hasPermission('frontdesk')) {
        echo json_encode(['success' => false, 'message' => 'No permission']);
        exit;
    }

    $conn = Database::getInstance()->getConnection();
    bs_ensure_direct_amount($conn);

    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $newCheckIn = trim($_POST['new_check_in'] ?? '');
    $newCheckOut = trim($_POST['new_check_out'] ?? '');
    $newRoomId = (int)($_POST['new_room_id'] ?? 0);
    $manualPrice = isset($_POST['room_price']) && $_POST['room_price'] !== '' ? (float)$_POST['room_price'] : null;
    $effectiveIn = trim($_POST['effective_date'] ?? '');
    $isPreview = !empty($_POST['preview']);

    if (!$bookingId) {
        throw new Exception('Booking ID wajib diisi');
    }

    $stmt = $conn->prepare("SELECT b.*, r.room_number, r.room_type_id, rt.type_name, rt.base_price
        FROM bookings b
        LEFT JOIN rooms r ON r.id = b.room_id
        LEFT JOIN room_types rt ON rt.id = r.room_type_id
        WHERE b.id = ?");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        throw new Exception('Booking tidak ditemukan');
    }
    if ($booking['status'] === 'checked_out') {
        throw new Exception('Booking yang sudah check-out tidak bisa dipindah');
    }
    if ($booking['status'] === 'cancelled') {
        throw new Exception('Booking yang dibatalkan tidak bisa dipindah');
    }

    $isInHouse = $booking['status'] === 'checked_in';
    $today = date('Y-m-d');
    $checkIn = $newCheckIn ?: $booking['check_in_date'];
    $checkOut = $newCheckOut ?: $booking['check_out_date'];
    $roomId = $newRoomId ?: (int)$booking['room_id'];
    $roomChanged = $roomId !== (int)$booking['room_id'];

    $ciDate = DateTime::createFromFormat('!Y-m-d', $checkIn);
    $coDate = DateTime::createFromFormat('!Y-m-d', $checkOut);
    if (!$ciDate || !$coDate) {
        throw new Exception('Format tanggal tidak valid');
    }
    if ($coDate <= $ciDate) {
        throw new Exception('Tanggal check-out harus setelah check-in');
    }
    if ($isInHouse && $checkIn !== $booking['check_in_date']) {
        throw new Exception('Tamu sudah check-in: tanggal check-in tidak bisa diubah. Hanya kamar atau tanggal check-out yang bisa dipindah.');
    }
    if (!$isInHouse && $checkIn < $today && $checkIn !== $booking['check_in_date']) {
        throw new Exception('Reservasi tidak bisa dipindah ke tanggal yang sudah lewat');
    }
    $nights = (int)$ciDate->diff($coDate)->days;

    // Kamar tujuan + tipe
    $stmt = $conn->prepare("SELECT r.id, r.room_number, r.room_type_id, rt.type_name, rt.base_price
        FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id WHERE r.id = ?");
    $stmt->execute([$roomId]);
    $newRoom = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$newRoom) {
        throw new Exception('Kamar tujuan tidak ditemukan');
    }

    // Tanggal efektif pindah (hanya tamu in-house yang pindah kamar): default hari ini
    $effective = $checkIn;
    if ($isInHouse && $roomChanged) {
        $effective = $effectiveIn ?: $today;
        if ($effective < $checkIn) $effective = $checkIn;
        if ($effective >= $checkOut) {
            throw new Exception('Tanggal pindah harus sebelum tanggal check-out');
        }
    }
    $nightsBefore = (int)$ciDate->diff(new DateTime($effective))->days;
    $nightsAfter = $nights - $nightsBefore;

    // Ketersediaan kamar tujuan mulai tanggal efektif
    $stmt = $conn->prepare("SELECT b.booking_code, g.guest_name FROM bookings b LEFT JOIN guests g ON g.id = b.guest_id
        WHERE b.room_id = ? AND b.id != ? AND b.status NOT IN ('cancelled', 'checked_out')
          AND b.check_in_date < ? AND b.check_out_date > ? LIMIT 1");
    $stmt->execute([$roomId, $bookingId, $checkOut, $effective]);
    if ($clash = $stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('Kamar ' . $newRoom['room_number'] . ' sudah terisi pada tanggal tersebut (' . trim(($clash['guest_name'] ?? '') . ' ' . $clash['booking_code']) . ')');
    }
    try {
        $blk = $conn->prepare("SELECT COUNT(*) FROM room_blocks WHERE room_id = ? AND status = 'active' AND block_start_date < ? AND block_end_date > ?");
        $blk->execute([$roomId, $checkOut, $effective]);
        if ((int)$blk->fetchColumn() > 0) {
            throw new Exception('Kamar ' . $newRoom['room_number'] . ' sedang diblok pada rentang tanggal tersebut');
        }
    } catch (PDOException $e) {
        // tabel room_blocks belum ada di lingkungan lama
    }

    // ── Harga ──
    $oldPrice = (float)$booking['room_price'];
    if ($oldPrice <= 0) $oldPrice = (float)($booking['base_price'] ?? 0);
    $typeChanged = $roomChanged && (int)$newRoom['room_type_id'] !== (int)$booking['room_type_id'];
    $oldBase = (float)($booking['base_price'] ?? 0);
    $newBase = (float)($newRoom['base_price'] ?? 0);
    $changeKind = !$roomChanged ? 'none' : (!$typeChanged ? 'same' : ($newBase > $oldBase ? 'upgrade' : ($newBase < $oldBase ? 'downgrade' : 'same')));

    // Sumber booking: OTA → selisih upgrade sudah dipotong fee, downgrade tanpa pengurangan
    $source = strtolower((string)($booking['booking_source'] ?? ''));
    $isOta = bs_is_ota($conn, $source);
    $feePct = $isOta ? bs_ota_fee_percent($conn, $source) : 0.0;
    $surcharge = 0.0;
    if ($isOta) {
        $surcharge = $typeChanged ? round(max(0, $newBase - $oldBase) * (1 - $feePct / 100), 2) : 0.0;
        $autoPrice = $oldPrice + $surcharge;
    } else {
        $autoPrice = $typeChanged ? $newBase : $oldPrice;
    }
    if ($autoPrice <= 0) $autoPrice = $oldPrice ?: $newBase;
    $newPrice = ($manualPrice !== null && $manualPrice >= 0) ? $manualPrice : $autoPrice;

    // Malam sebelum pindah tetap sesuai tagihan lama. total_price lama = (malam sebelumnya) + room_price × sisa malam,
    // jadi bagian sebelumnya = total lama − room_price × sisa malam lama (tepat juga setelah beberapa kali pindah).
    $beforeTotal = 0.0;
    if ($nightsBefore > 0) {
        $oldNights = (int)$booking['total_nights'] ?: $nights;
        $beforeTotal = max(0, round((float)$booking['total_price'] - $oldPrice * max(0, $oldNights - $nightsBefore), 2));
    }
    $totalPrice = round($beforeTotal + $newPrice * $nightsAfter, 2);
    // Jumlah malam tetap: total lama + selisih harga untuk malam yang terdampak. Menjaga total campuran
    // (mis. malam extend dengan harga berbeda, atau pindah kamar sebelumnya) tetap tepat.
    if ($nights === (int)$booking['total_nights'] && (float)$booking['total_price'] > 0) {
        $totalPrice = round((float)$booking['total_price'] + ($newPrice - $oldPrice) * $nightsAfter, 2);
    }
    $storedPrice = $newPrice;
    $discount = (float)($booking['discount'] ?? 0);

    $extrasTotal = 0.0;
    try {
        $ex = $conn->prepare("SELECT COALESCE(SUM(total_price), 0) FROM booking_extras WHERE booking_id = ?");
        $ex->execute([$bookingId]);
        $extrasTotal = (float)$ex->fetchColumn();
    } catch (PDOException $e) {
    }
    $finalPrice = max(0, $totalPrice - $discount) + $extrasTotal;
    // Bagian dibayar langsung ke hotel (booking OTA): bertambah/berkurang mengikuti perubahan tagihan
    $oldDirect = (float)($booking['direct_amount'] ?? 0);
    $newDirect = $isOta ? max(0, round($oldDirect + ($finalPrice - (float)$booking['final_price']), 2)) : $oldDirect;
    $paidAmount = (float)($booking['paid_amount'] ?? 0);
    $paymentStatus = $paidAmount <= 0 ? 'unpaid' : ($paidAmount >= $finalPrice ? 'paid' : 'partial');

    $result = [
        'booking_id' => $bookingId,
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'nights' => $nights,
        'effective_date' => $effective,
        'nights_before' => $nightsBefore,
        'nights_after' => $nightsAfter,
        'before_total' => $beforeTotal,
        'old_room' => ['id' => (int)$booking['room_id'], 'number' => $booking['room_number'], 'type' => $booking['type_name'], 'base_price' => $oldBase],
        'new_room' => ['id' => (int)$newRoom['id'], 'number' => $newRoom['room_number'], 'type' => $newRoom['type_name'], 'base_price' => $newBase],
        'change_kind' => $changeKind,
        'old_price' => $oldPrice,
        'auto_price' => $autoPrice,
        'new_price' => $newPrice,
        'room_price' => $storedPrice,
        'old_final' => (float)$booking['final_price'],
        'total_price' => $totalPrice,
        'discount' => $discount,
        'extras' => $extrasTotal,
        'final_price' => $finalPrice,
        'paid' => $paidAmount,
        'balance' => max(0, $finalPrice - $paidAmount),
        'is_in_house' => $isInHouse,
        'is_ota' => $isOta,
        'source' => $source,
        'fee_percent' => $feePct,
        'surcharge' => $surcharge,
        'direct_amount' => $newDirect,
        'ota_amount' => max(0, $finalPrice - $newDirect),
    ];

    if ($isPreview) {
        echo json_encode(['success' => true, 'preview' => true, 'data' => $result]);
        exit;
    }

    $conn->beginTransaction();

    // Catatan riwayat pindah kamar pada booking
    $noteSql = '';
    $noteParams = [];
    if ($roomChanged) {
        $kindLabel = ['upgrade' => 'Upgrade', 'downgrade' => 'Downgrade', 'same' => 'Pindah kamar'][$changeKind] ?? 'Pindah kamar';
        $note = '[' . date('d/m/Y H:i') . '] ' . $kindLabel . ': ' . $booking['room_number'] . ' (' . $booking['type_name'] . ') -> '
            . $newRoom['room_number'] . ' (' . $newRoom['type_name'] . ')'
            . ($isInHouse ? ' mulai ' . date('d/m/Y', strtotime($effective)) : '')
            . ', harga/malam Rp ' . number_format($oldPrice, 0, ',', '.') . ' -> Rp ' . number_format($newPrice, 0, ',', '.')
            . ($isOta && $newDirect > $oldDirect ? ' (OTA ' . $source . ': +Rp ' . number_format($newDirect - $oldDirect, 0, ',', '.') . ' dibayar langsung, fee ' . rtrim(rtrim(number_format($feePct, 2, '.', ''), '0'), '.') . '%)' : '');
        $noteSql = ", notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE '\n' END, ?))";
        $noteParams[] = $note;
    }

    $stmt = $conn->prepare("UPDATE bookings SET
            check_in_date = ?, check_out_date = ?, room_id = ?, total_nights = ?,
            room_price = ?, total_price = ?, final_price = ?, payment_status = ?, direct_amount = ?, updated_at = NOW() $noteSql
        WHERE id = ?");
    $stmt->execute(array_merge([$checkIn, $checkOut, $roomId, $nights, $storedPrice, $totalPrice, $finalPrice, $paymentStatus, $newDirect], $noteParams, [$bookingId]));

    // Tamu in-house pindah kamar: kamar lama dibersihkan, kamar baru terisi.
    if ($isInHouse && $roomChanged) {
        $conn->prepare("UPDATE rooms SET status = 'cleaning', current_guest_id = NULL, updated_at = NOW() WHERE id = ?")
            ->execute([(int)$booking['room_id']]);
        $conn->prepare("UPDATE rooms SET status = 'occupied', current_guest_id = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$booking['guest_id'], $roomId]);
    }

    $conn->commit();

    $msg = $roomChanged
        ? (['upgrade' => 'Upgrade', 'downgrade' => 'Downgrade'][$changeKind] ?? 'Pindah kamar') . ' ke kamar ' . $newRoom['room_number'] . ' berhasil'
        : 'Tanggal booking berhasil dipindah';
    $cbEditBookings = [(int)$bookingId];
    echo json_encode(['success' => true, 'message' => $msg, 'data' => $result]);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('move-booking: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $t) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('move-booking fatal: ' . $t->getMessage() . ' @' . $t->getLine());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan server']);
}

// ═══ CLOUDBEDS: edit reservasi ikut disamakan (setelah respons dikirim) ═══
if (!empty($cbEditBookings)) {
    require_once dirname(__DIR__) . '/includes/cloudbeds_push_hook.php';
    cloudbedsPushAfterResponse(Database::getInstance(), $cbEditBookings, [], true);
}
