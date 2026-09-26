<?php
/**
 * Read-only subscription pricing config API for client sites (e.g. Karimunjawa
 * Explore). Pricing is managed only via admin/subscription-clients.php — this
 * endpoint never accepts writes.
 *
 * POST JSON: {"client_key": "...", "client_token": "..."}
 */

require_once __DIR__ . '/../includes/subscription-clients-store.php';

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$clientKey = trim((string) ($payload['client_key'] ?? ''));
$clientToken = trim((string) ($payload['client_token'] ?? ''));

if ($clientKey === '' || $clientToken === '') {
    http_response_code(400);
    echo json_encode(['error' => 'client_key and client_token required']);
    exit;
}

$client = adf_subscription_client_find($clientKey);

if (!$client || !hash_equals((string) $client['client_token'], $clientToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid client_key or client_token']);
    exit;
}

http_response_code(200);
echo json_encode([
    'client_name' => $client['client_name'] ?? '',
    'base_fee' => (float) ($client['base_fee'] ?? 0),
    'per_guest_fee' => (float) ($client['per_guest_fee'] ?? 0),
    'pakasir_slug' => $client['pakasir_slug'] ?? '',
    'pakasir_api_key' => $client['pakasir_api_key'] ?? '',
    'pakasir_webhook_secret' => $client['pakasir_webhook_secret'] ?? '',
    'updated_at' => $client['updated_at'] ?? null,
]);
