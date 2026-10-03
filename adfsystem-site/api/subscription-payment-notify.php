<?php

/**
 * Client sites (e.g. Karimunjawa Explore) call this right after a subscription
 * invoice is confirmed paid, so ADF System can email the client's configured
 * notify_email automatically. Auth is the same client_key/client_token pair
 * used by subscription-config.php — no other input is trusted.
 *
 * POST JSON: {"client_key", "client_token", "period", "total_amount", "paid_at"}
 */

require_once __DIR__ . '/../includes/subscription-clients-store.php';
require_once __DIR__ . '/../includes/orders-store.php';
require_once __DIR__ . '/../includes/site-config.php';
require_once __DIR__ . '/../includes/smtp-mailer.php';
require_once __DIR__ . '/../includes/subscription-invoice-pdf.php';

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$clientKey = trim((string) ($payload['client_key'] ?? ''));
$clientToken = trim((string) ($payload['client_token'] ?? ''));
$period = trim((string) ($payload['period'] ?? ''));
$totalAmount = (float) ($payload['total_amount'] ?? 0);
$paidAt = trim((string) ($payload['paid_at'] ?? '')) ?: date('c');

if ($clientKey === '' || $clientToken === '' || $period === '') {
    http_response_code(400);
    echo json_encode(['error' => 'client_key, client_token and period required']);
    exit;
}

$client = adf_subscription_client_find($clientKey);
if (!$client || !hash_equals((string) $client['client_token'], $clientToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid client_key or client_token']);
    exit;
}

$clientName = (string) ($client['client_name'] ?? $clientKey);

// Catat pembayaran ini di halaman Transaksi admin, supaya uang langganan klien
// yang masuk lewat Pakasir ikut terlihat (bukan cuma checkout website).
$paymentOrderId = adf_subscription_payment_record($client, $payload);

$notifyEmail = trim((string) ($client['notify_email'] ?? ''));
if ($notifyEmail === '' || !filter_var($notifyEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'recorded' => $paymentOrderId, 'skipped' => 'no notify_email configured for this client']);
    exit;
}
$formattedAmount = 'Rp ' . number_format($totalAmount, 0, ',', '.');
$paidAtDisplay = date('d M Y H:i', strtotime($paidAt)) . ' WIB';
$invoiceNumber = 'INV-' . strtoupper($clientKey) . '-' . str_replace('-', '', $period);

$subject = 'Pembayaran Langganan Diterima - ' . $clientName . ' (Periode ' . $period . ')';

$textBody = "Halo {$clientName},\n\n"
    . "Pembayaran tagihan langganan ADF System Anda telah kami terima.\n\n"
    . "Periode      : {$period}\n"
    . "Total Dibayar: {$formattedAmount}\n"
    . "Waktu Bayar  : {$paidAtDisplay}\n\n"
    . "Langganan Anda kembali aktif secara penuh. Invoice terlampir dalam bentuk PDF.\n\n"
    . "Terima kasih atas pembayaran tepat waktu.\n\n"
    . "Salam,\n" . SITE_NAME;

$htmlBody = '<div style="font-family:sans-serif;max-width:520px;margin:0 auto;color:#1e293b;">'
    . '<div style="background:#0f172a;padding:20px 24px;border-radius:8px 8px 0 0;">'
    . '<h2 style="color:#fff;margin:0;font-size:18px;">' . htmlspecialchars(SITE_NAME) . '</h2>'
    . '</div>'
    . '<div style="border:1px solid #e2e8f0;border-top:none;padding:24px;border-radius:0 0 8px 8px;">'
    . '<p style="margin:0 0 12px;">Halo <strong>' . htmlspecialchars($clientName) . '</strong>,</p>'
    . '<p style="margin:0 0 16px;">Pembayaran tagihan langganan ADF System Anda telah kami terima. Berikut rinciannya:</p>'
    . '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">'
    . '<tr><td style="padding:6px 0;color:#64748b;">Periode</td><td style="padding:6px 0;text-align:right;font-weight:bold;">' . htmlspecialchars($period) . '</td></tr>'
    . '<tr><td style="padding:6px 0;color:#64748b;">Total Dibayar</td><td style="padding:6px 0;text-align:right;font-weight:bold;">' . htmlspecialchars($formattedAmount) . '</td></tr>'
    . '<tr><td style="padding:6px 0;color:#64748b;">Waktu Bayar</td><td style="padding:6px 0;text-align:right;font-weight:bold;">' . htmlspecialchars($paidAtDisplay) . '</td></tr>'
    . '</table>'
    . '<p style="margin:0 0 16px;">Langganan Anda kembali aktif secara penuh. Invoice resmi (PDF) kami lampirkan pada email ini untuk keperluan pembukuan Anda.</p>'
    . '<p style="margin:0;">Terima kasih atas pembayaran tepat waktu.</p>'
    . '<p style="margin:20px 0 0;color:#64748b;font-size:12px;">Salam,<br>' . htmlspecialchars(SITE_NAME) . '</p>'
    . '</div></div>';

$attachments = [];
try {
    $pdfContent = adf_subscription_invoice_pdf($clientName, $period, $totalAmount, $paidAtDisplay, $invoiceNumber);
    $attachments[] = [
        'filename' => $invoiceNumber . '.pdf',
        'content' => $pdfContent,
        'mime' => 'application/pdf',
    ];
} catch (\Throwable $e) {
    error_log('subscription-payment-notify PDF generation failed: ' . $e->getMessage());
}

$sentOk = adf_smtp_send_html($notifyEmail, $subject, $htmlBody, $textBody, $attachments);
if (!$sentOk) {
    error_log('subscription-payment-notify mail failed: ' . adf_mail_last_error());
}

http_response_code(200);
echo json_encode(['ok' => true, 'emailed' => $sentOk, 'error' => $sentOk ? null : adf_mail_last_error()]);
