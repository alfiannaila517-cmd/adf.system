<?php

/**
 * Hotel Services: kirim invoice + pengingat tagihan ke tamu lewat WhatsApp gateway (satu klik).
 * Bila gateway belum diatur atau tamu belum punya nomor, mengembalikan wa_url (WhatsApp Web) agar staf bisa kirim manual.
 * POST id (hotel_invoices.id)
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/WhatsAppHelper.php';
require_once '../includes/InvoiceShare.php';
require_once '../includes/HotelServiceInvoiceWa.php';

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
    $id = (int)($_POST['id'] ?? 0);
    $businessId = (int)($_SESSION['business_id'] ?? 1);
    $inv = $id > 0 ? $db->fetchOne("SELECT * FROM hotel_invoices WHERE id = ? AND business_id = ?", [$id, $businessId]) : null;
    if (!$inv) {
        echo json_encode(['ok' => false, 'message' => 'Invoice tidak ditemukan']);
        exit;
    }
    if (($inv['status'] ?? '') === 'cancelled') {
        echo json_encode(['ok' => false, 'message' => 'Invoice dibatalkan']);
        exit;
    }

    // Kebangsaan tamu (bila invoice terhubung ke booking) untuk memilih bahasa pesan
    $nationality = '';
    if (!empty($inv['booking_id'])) {
        $g = $db->fetchOne("SELECT g.nationality FROM bookings b JOIN guests g ON g.id = b.guest_id WHERE b.id = ?", [(int)$inv['booking_id']]);
        $nationality = (string)($g['nationality'] ?? '');
    }
    $rawPhone = trim((string)($inv['guest_phone'] ?? ''));
    $phone = WhatsAppHelper::normalizeTarget($rawPhone);
    $lang = hsInvWaLang($nationality, $rawPhone);

    $hotel = '';
    try {
        $row = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
        $hotel = (string)($row['setting_value'] ?? '');
    } catch (\Throwable $e) {
    }
    if ($hotel === '') $hotel = defined('BUSINESS_NAME') ? (string)BUSINESS_NAME : 'Hotel';

    $total = (float)$inv['total'];
    $balance = max(0.0, $total - (float)$inv['paid_amount']);
    $link = hsInvoiceShareUrl($db, $id);
    $text = hsInvWaMessage((string)$inv['guest_name'], (string)$inv['invoice_number'], $total, $balance, $link, $lang, $hotel, (int)date('H'));
    $waUrl = 'https://web.whatsapp.com/send?' . ($phone !== '' ? 'phone=' . preg_replace('/\D+/', '', $phone) . '&' : '') . 'text=' . rawurlencode($text) . '&app_absent=0';

    $wa = new WhatsAppHelper($db);
    if ($phone === '' || !$wa->isConfigured()) {
        echo json_encode(['ok' => true, 'sent' => false, 'reason' => $phone === '' ? 'no_phone' : 'no_gateway', 'wa_url' => $waUrl, 'invoice_url' => $link]);
        exit;
    }

    // Pengaman klik ganda: pesan yang sama ke nomor yang sama dalam 1 menit tidak dikirim lagi
    $ref = mb_substr('hsinv ' . $inv['invoice_number'], 0, 80);
    $dup = false;
    try {
        $wa->recentLog(1); // memastikan tabel log ada
        $dup = (bool)$db->fetchOne("SELECT id FROM wa_message_log WHERE type = 'hs_invoice' AND ref = ? AND target = ? AND status = 'sent' AND created_at >= (NOW() - INTERVAL 1 MINUTE) LIMIT 1", [$ref, $phone]);
    } catch (\Throwable $e) {
    }
    if ($dup) {
        echo json_encode(['ok' => true, 'sent' => true, 'duplicate' => true, 'target' => $phone]);
        exit;
    }

    $res = $wa->send($phone, $text, null, null, 'hs_invoice', $ref);
    if ($res['ok']) {
        echo json_encode(['ok' => true, 'sent' => true, 'target' => $phone, 'balance' => $balance]);
    } else {
        // Gateway menolak: tawarkan kirim manual lewat WhatsApp Web
        echo json_encode(['ok' => true, 'sent' => false, 'reason' => 'gateway_error', 'detail' => $res['detail'], 'wa_url' => $waUrl, 'invoice_url' => $link]);
    }
} catch (\Throwable $e) {
    error_log('hotel-service-send-invoice: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Gagal mengirim: ' . $e->getMessage()]);
}
