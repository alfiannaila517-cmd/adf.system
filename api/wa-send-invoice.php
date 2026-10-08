<?php

/**
 * Kirim invoice booking ke tamu lewat WhatsApp: pesan template (DP 50% / 24 jam) + PDF invoice.
 * POST booking_id, mode = send | preview, phone (opsional: dipakai & disimpan bila tamu belum punya nomor)
 * Gateway belum diatur → mengembalikan tautan wa.me berisi pesan (+ link PDF) agar staf kirim manual.
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/WhatsAppHelper.php';
require_once '../includes/InvoiceWaHelper.php';

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

$respond = static function (array $data): void {
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode($data);
    exit;
};

try {
    $db = Database::getInstance();
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $d = invoiceWaCollect($db, $bookingId);
    if (!$d) $respond(['ok' => false, 'message' => 'Booking tidak ditemukan']);

    $phoneRaw = trim((string)($_POST['phone'] ?? '')) ?: trim((string)($d['main']['phone'] ?? ''));
    $phone = WhatsAppHelper::normalizeTarget($phoneRaw);
    if ($phone === '') $respond(['ok' => false, 'need_phone' => true, 'message' => 'Nomor WhatsApp tamu belum ada']);

    // Simpan nomor yang baru dimasukkan bila tamu belum punya
    if (trim((string)($_POST['phone'] ?? '')) !== '' && trim((string)($d['main']['phone'] ?? '')) === '' && ($_POST['mode'] ?? 'send') === 'send') {
        $g = $db->fetchOne("SELECT guest_id FROM bookings WHERE id = ?", [$bookingId]);
        if ($g && !empty($g['guest_id'])) $db->query("UPDATE guests SET phone = ? WHERE id = ? AND (phone IS NULL OR phone = '')", [$phone, (int)$g['guest_id']]);
    }

    // PDF → file sementara berlink acak (dihapus otomatis setelah 2 hari)
    $bytes = invoiceWaPdf($d);
    $code = (string)$d['main']['booking_code'];
    $pub = WhatsAppHelper::publishTempFile($bytes, 'pdf', 'invoice-' . $code);
    $text = invoiceWaText($d, $phone, $pub['url']);
    $waUrl = 'https://wa.me/' . preg_replace('/\D+/', '', $phone) . '?text=' . rawurlencode($text);

    $wa = new WhatsAppHelper($db);
    if (($_POST['mode'] ?? 'send') === 'preview' || !$wa->isConfigured()) {
        $respond(['ok' => false, 'fallback' => true, 'wa_url' => $waUrl, 'text' => $text, 'pdf_url' => $pub['url'],
            'message' => $wa->isConfigured() ? 'Pratinjau' : 'Gateway WhatsApp belum diatur — pesan dibuka di WhatsApp untuk dikirim manual.']);
    }
    $res = $wa->send($phone, $text, null, 'Invoice-' . $code . '.pdf', 'invoice', 'booking:' . $bookingId . ' ' . $code, $pub['url']);
    $respond([
        'ok' => (bool)$res['ok'],
        'message' => $res['ok'] ? 'Invoice terkirim ke ' . ($d['main']['guest_name'] ?: 'tamu') . ' ✅' : 'Gagal mengirim: ' . ($res['detail'] ?? 'tidak diketahui'),
        'wa_url' => $waUrl,
        'pdf_url' => $pub['url'],
    ]);
} catch (\Throwable $e) {
    error_log('wa-send-invoice: ' . $e->getMessage());
    $respond(['ok' => false, 'message' => 'Gagal membuat / mengirim invoice: ' . $e->getMessage()]);
}
