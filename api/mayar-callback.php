<?php

/**
 * Mayar.id payment webhook.
 * Configure this exact URL in Mayar dashboard: Integration -> Webhook.
 * Mayar webhook payloads are NOT signed, so instead of trusting the raw POST body,
 * this endpoint takes the invoice/transaction id from the payload and re-fetches the
 * real status via an authenticated call to Mayar's own API before marking anything paid.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/MayarClient.php';
require_once __DIR__ . '/../includes/subscription_billing.php';

header('Content-Type: application/json');

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

$event = $payload['event'] ?? '';
$invoiceId = $payload['data']['id'] ?? null;
$invoiceNoFromExtra = $payload['data']['extraData']['invoiceNo'] ?? ($payload['data']['custom_field']['invoiceNo'] ?? null);

if ($event !== 'payment.received' || (!$invoiceId && !$invoiceNoFromExtra)) {
    // Acknowledge other event types (payment.reminder, membership.*) without action.
    echo json_encode(['success' => true, 'message' => 'Ignored']);
    exit;
}

if (!subscriptionGatewayConfigured()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Mayar not configured']);
    exit;
}

$masterDb = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Prefer the gateway invoice id; fall back to our own invoice_no (echoed back via extraData)
// if Mayar's payload for this event type doesn't carry the invoice id directly.
if ($invoiceId) {
    $synced = subscriptionVerifyAndSyncInvoice($masterDb, $invoiceId);
} else {
    $lookup = $masterDb->prepare("SELECT gateway_reference FROM subscription_invoices WHERE invoice_no = ? LIMIT 1");
    $lookup->execute([$invoiceNoFromExtra]);
    $ref = $lookup->fetchColumn();
    $synced = $ref ? subscriptionVerifyAndSyncInvoice($masterDb, $ref) : false;
}

echo json_encode(['success' => true, 'synced' => $synced]);
