<?php

/**
 * API: ringkasan booking untuk popup konfirmasi check-in (hanya membaca, tidak mengubah data).
 * GET booking_id → nama & nomor tamu, kamar, tanggal, tagihan, dan status pesan welcome WhatsApp.
 */
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/WhatsAppHelper.php';

ob_clean();
header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn() || !$auth->hasPermission('frontdesk')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $db = Database::getInstance();
    $id = (int)($_GET['booking_id'] ?? 0);
    $b = $db->fetchOne("
        SELECT b.id, b.booking_code, b.group_id, b.check_in_date, b.check_out_date, b.total_nights, b.final_price, b.paid_amount,
               b.booking_source, b.status, g.guest_name, g.phone AS guest_phone, g.nationality,
               r.room_number, rt.type_name
        FROM bookings b
        LEFT JOIN guests g ON g.id = b.guest_id
        LEFT JOIN rooms r ON r.id = b.room_id
        LEFT JOIN room_types rt ON rt.id = r.room_type_id
        WHERE b.id = ?", [$id]);
    if (!$b) {
        echo json_encode(['success' => false, 'message' => 'Booking tidak ditemukan']);
        exit;
    }

    $groupCount = 1;
    if (!empty($b['group_id'])) {
        $groupCount = (int)($db->fetchOne("SELECT COUNT(*) c FROM bookings WHERE group_id = ? AND status <> 'cancelled'", [$b['group_id']])['c'] ?? 1);
    }

    // Status pesan welcome WhatsApp
    $wa = new WhatsAppHelper($db);
    $set = $wa->settings();
    $phoneNorm = WhatsAppHelper::normalizeTarget((string)$b['guest_phone']);
    $wcState = 'ok';
    if ($set['wa_checkin_enabled'] !== '1') $wcState = 'disabled';
    elseif (!$wa->isConfigured()) $wcState = 'no_token';
    elseif ($phoneNorm === '') $wcState = 'no_phone';
    elseif ($wa->recentlySent('checkin', $phoneNorm)) $wcState = 'already_sent';

    $total = (float)$b['final_price'];
    $paid = (float)$b['paid_amount'];
    echo json_encode([
        'success' => true,
        'booking' => [
            'id' => (int)$b['id'],
            'code' => $b['booking_code'],
            'guest_name' => (string)$b['guest_name'],
            'phone' => (string)$b['guest_phone'],
            'room' => (string)$b['room_number'],
            'room_type' => (string)$b['type_name'],
            'check_in' => $b['check_in_date'],
            'check_out' => $b['check_out_date'],
            'nights' => (int)$b['total_nights'],
            'source' => (string)$b['booking_source'],
            'total' => $total,
            'paid' => $paid,
            'remaining' => max(0, $total - $paid),
            'group_count' => $groupCount,
        ],
        'welcome' => [
            'state' => $wcState,
            'lang' => GuestServices::langFor((string)$b['nationality'], (string)$b['guest_phone']),
        ],
    ]);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Gagal memuat ringkasan']);
}
