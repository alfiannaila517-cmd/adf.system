<?php
/**
 * Pakasir webhook receiver. Configure this URL in the Pakasir project settings:
 * https://yourdomain.com/adfsystem-site/api/pakasir-webhook.php
 */

require_once __DIR__ . '/../includes/content-store.php';
require_once __DIR__ . '/../includes/orders-store.php';

header('Content-Type: application/json');

$cfg = adf_load_content()['payment'] ?? [];
$expectedSecret = $cfg['webhook_secret'] ?? '';

$receivedSecret = $_SERVER['HTTP_X_SECRET'] ?? '';

if ($expectedSecret === '' || !hash_equals($expectedSecret, $receivedSecret)) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid secret']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload) || empty($payload['order_id']) || empty($payload['status'])) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid payload']);
    exit;
}

$orderId = (string) $payload['order_id'];
$status = (string) $payload['status'];
$completedAt = $payload['completed_at'] ?? null;

adf_orders_update_status($orderId, $status, $completedAt);

http_response_code(200);
echo json_encode(['ok' => true]);
