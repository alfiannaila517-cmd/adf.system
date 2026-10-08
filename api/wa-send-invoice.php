<?php

/**
 * Siapkan pesan WhatsApp invoice untuk tamu (bahasa Inggris, template DP 50% / 24 jam) + link invoice publik.
 * Tidak mengirim lewat gateway: mengembalikan wa_url (WhatsApp Web) yang dibuka staf, lalu staf menekan kirim.
 * POST booking_id
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/WhatsAppHelper.php';
require_once '../includes/InvoiceWaHelper.php';
require_once '../includes/InvoiceShare.php';

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
    $d = invoiceWaCollect($db, $bookingId);
    if (!$d) {
        echo json_encode(['ok' => false, 'message' => 'Booking tidak ditemukan']);
        exit;
    }
    $invoiceUrl = invoiceShareUrl($db, $bookingId);
    $text = invoiceWaText($d, $invoiceUrl);
    $phone = WhatsAppHelper::normalizeTarget(trim((string)($d['main']['phone'] ?? '')));
    // Tanpa nomor: WhatsApp Web tetap terbuka dengan pesan terisi, staf memilih kontak tamu
    $waUrl = 'https://web.whatsapp.com/send?' . ($phone !== '' ? 'phone=' . preg_replace('/\D+/', '', $phone) . '&' : '') . 'text=' . rawurlencode($text) . '&app_absent=0';
    echo json_encode(['ok' => true, 'wa_url' => $waUrl, 'has_phone' => $phone !== '', 'invoice_url' => $invoiceUrl, 'text' => $text]);
} catch (\Throwable $e) {
    error_log('wa-send-invoice: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Gagal menyiapkan pesan: ' . $e->getMessage()]);
}
