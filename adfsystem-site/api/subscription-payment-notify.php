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
require_once __DIR__ . '/../includes/site-config.php';

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

$notifyEmail = trim((string) ($client['notify_email'] ?? ''));
if ($notifyEmail === '' || !filter_var($notifyEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => 'no notify_email configured for this client']);
    exit;
}

$clientName = (string) ($client['client_name'] ?? $clientKey);
$formattedAmount = 'Rp ' . number_format($totalAmount, 0, ',', '.');
$paidAtDisplay = date('d M Y H:i', strtotime($paidAt)) . ' WIB';

$subject = 'Pembayaran Langganan Diterima - ' . $clientName . ' (Periode ' . $period . ')';
$body = "Halo {$clientName},\n\n"
    . "Pembayaran tagihan langganan ADF System Anda telah kami terima.\n\n"
    . "Periode      : {$period}\n"
    . "Total Dibayar: {$formattedAmount}\n"
    . "Waktu Bayar  : {$paidAtDisplay}\n\n"
    . "Langganan Anda kembali aktif secara penuh. Terima kasih atas pembayaran tepat waktu.\n\n"
    . "Salam,\n" . SITE_NAME;

$headers = "From: no-reply@adfsystem.id\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$sentOk = @mail($notifyEmail, $subject, $body, $headers);

http_response_code(200);
echo json_encode(['ok' => true, 'emailed' => $sentOk]);
