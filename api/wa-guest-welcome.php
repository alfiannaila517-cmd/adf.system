<?php

/**
 * Kirim ulang pesan WhatsApp "welcome" (template check-in) ke tamu secara manual dari halaman In-House.
 * Lewat gateway WhatsApp sistem bila sudah diatur; bila belum, mengembalikan tautan wa.me berisi pesan yang sama.
 * POST booking_id, mode = send | preview
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/WhatsAppHelper.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn() || !$auth->hasPermission('frontdesk')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Tidak punya akses']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Metode tidak valid']);
    exit;
}

try {
    $db = Database::getInstance();
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $booking = $db->fetchOne("SELECT b.id, b.booking_code, b.room_id, b.check_in_date, b.check_out_date, g.guest_name, g.phone AS guest_phone, r.room_number
        FROM bookings b LEFT JOIN guests g ON g.id = b.guest_id LEFT JOIN rooms r ON r.id = b.room_id WHERE b.id = ?", [$bookingId]);
    if (!$booking) {
        echo json_encode(['ok' => false, 'message' => 'Booking tidak ditemukan']);
        exit;
    }
    $wa = new WhatsAppHelper($db);
    $message = $wa->checkinMessage($booking);
    $phone = WhatsAppHelper::normalizeTarget((string)($booking['guest_phone'] ?? ''));
    $waUrl = $phone !== '' ? 'https://wa.me/' . preg_replace('/\D+/', '', $phone) . '?text=' . rawurlencode($message) : '';

    if (($_POST['mode'] ?? 'send') === 'preview' || !$wa->isConfigured()) {
        // Gateway belum diatur → staf mengirim sendiri lewat WhatsApp dengan pesan yang sudah terisi
        echo json_encode(['ok' => false, 'fallback' => true, 'wa_url' => $waUrl, 'text' => $message,
            'message' => $wa->isConfigured() ? 'Pratinjau' : 'Gateway WhatsApp belum diatur — pesan dibuka di WhatsApp untuk dikirim manual.']);
        exit;
    }
    if ($phone === '') {
        echo json_encode(['ok' => false, 'message' => 'Nomor WhatsApp tamu belum ada']);
        exit;
    }
    $res = $wa->send($phone, $message, null, null, 'checkin_manual', 'booking:' . $booking['id'] . ' ' . $booking['booking_code']);
    echo json_encode([
        'ok' => (bool)$res['ok'],
        'message' => $res['ok'] ? 'Pesan welcome terkirim ke ' . ($booking['guest_name'] ?: 'tamu') . ' ✅' : 'Gagal mengirim: ' . ($res['detail'] ?? 'tidak diketahui'),
        'wa_url' => $waUrl,
    ]);
} catch (\Throwable $e) {
    error_log('wa-guest-welcome: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Gagal mengirim pesan']);
}
