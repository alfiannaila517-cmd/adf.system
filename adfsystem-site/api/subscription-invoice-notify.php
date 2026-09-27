<?php

/**
 * Client sites (e.g. Karimunjawa Explore) call this right after a NEW
 * subscription invoice/tagihan is generated, so ADF System can email the
 * client's configured notify_email a billing notice. Auth is the same
 * client_key/client_token pair used by subscription-config.php.
 *
 * POST JSON: {"client_key", "client_token", "period", "total_amount", "due_date"}
 */

require_once __DIR__ . '/../includes/subscription-clients-store.php';
require_once __DIR__ . '/../includes/site-config.php';
require_once __DIR__ . '/../includes/smtp-mailer.php';

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
$dueDate = trim((string) ($payload['due_date'] ?? ''));

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
$dueDateDisplay = $dueDate !== '' ? date('d M Y', strtotime($dueDate)) : '-';

$subject = 'Tagihan Langganan Baru - ' . $clientName . ' (Periode ' . $period . ')';
$body = "Halo {$clientName},\n\n"
    . "Tagihan langganan ADF System baru telah dibuat.\n\n"
    . "Periode      : {$period}\n"
    . "Total Tagihan: {$formattedAmount}\n"
    . "Jatuh Tempo  : {$dueDateDisplay}\n\n"
    . "Silakan lakukan pembayaran sebelum tanggal jatuh tempo agar langganan tetap aktif.\n\n"
    . "Salam,\n" . SITE_NAME;

$sentOk = adf_smtp_send($notifyEmail, $subject, $body);
if (!$sentOk) {
    error_log('subscription-invoice-notify mail failed: ' . adf_mail_last_error());
}

http_response_code(200);
echo json_encode(['ok' => true, 'emailed' => $sentOk, 'error' => $sentOk ? null : adf_mail_last_error()]);
