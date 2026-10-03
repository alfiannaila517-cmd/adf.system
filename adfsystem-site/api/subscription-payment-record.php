<?php

/**
 * Record-only endpoint: client sites (e.g. Karimunjawa Explore) send subscription invoices that were
 * ALREADY paid so they appear on the admin Transaksi page. Never sends email (unlike
 * subscription-payment-notify.php), so it is safe for backfilling payment history.
 *
 * POST JSON: {"client_key", "client_token", "period", "total_amount", "paid_at", "order_id", "description"}
 */

require_once __DIR__ . '/../includes/subscription-clients-store.php';
require_once __DIR__ . '/../includes/orders-store.php';

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$clientKey = trim((string) ($payload['client_key'] ?? ''));
$clientToken = trim((string) ($payload['client_token'] ?? ''));
$period = trim((string) ($payload['period'] ?? ''));

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

$orderId = adf_subscription_payment_record($client, $payload);

http_response_code(200);
echo json_encode(['ok' => true, 'recorded' => $orderId]);
