<?php
/**
 * Tripay payment callback (webhook).
 * Configure this exact URL in your Tripay merchant dashboard.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/TripayClient.php';
require_once __DIR__ . '/../includes/subscription_billing.php';

header('Content-Type: application/json');

$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_CALLBACK_SIGNATURE'] ?? '';
$event = $_SERVER['HTTP_X_CALLBACK_EVENT'] ?? '';

if (!subscriptionTripayConfigured()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Tripay not configured']);
    exit;
}

$tripay = new TripayClient();
if (!$tripay->verifyCallbackSignature($rawBody, $signature)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid signature']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload) || $event !== 'payment_status') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unhandled event']);
    exit;
}

$merchantRef = $payload['merchant_ref'] ?? null;
$status = strtoupper($payload['status'] ?? '');
if (!$merchantRef) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing merchant_ref']);
    exit;
}

$masterDb = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$stmt = $masterDb->prepare("SELECT id FROM subscription_invoices WHERE invoice_no = ? LIMIT 1");
$stmt->execute([$merchantRef]);
$invoice = $stmt->fetch();

if (!$invoice) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Invoice not found']);
    exit;
}

if ($status === 'PAID') {
    subscriptionMarkInvoicePaid($masterDb, $invoice['id']);
} elseif (in_array($status, ['EXPIRED', 'FAILED'], true)) {
    $masterDb->prepare("UPDATE subscription_invoices SET status = ? WHERE id = ?")
        ->execute([strtolower($status), $invoice['id']]);
}

echo json_encode(['success' => true]);
