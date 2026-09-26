<?php
/**
 * Read-only subscription pricing config API for client sites (e.g. Karimunjawa
 * Explore). Pricing is managed only via admin/subscription-clients.php — this
 * endpoint never accepts writes.
 *
 * POST JSON: {"client_key": "...", "client_token": "..."}
 */

require_once __DIR__ . '/../includes/subscription-clients-store.php';
require_once __DIR__ . '/../includes/content-store.php';

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

require_once __DIR__ . '/../includes/site-config.php';
$content = adf_load_content();
$logoPath = $content['branding']['logo'] ?? '';

http_response_code(200);
echo json_encode([
    'client_name' => $client['client_name'] ?? '',
    'base_fee' => (float) ($client['base_fee'] ?? 0),
    'per_guest_fee' => (float) ($client['per_guest_fee'] ?? 0),
    'subscription_start_date' => $client['subscription_start_date'] ?? '',
    'pakasir_slug' => $client['pakasir_slug'] ?? '',
    'pakasir_api_key' => $client['pakasir_api_key'] ?? '',
    'pakasir_webhook_secret' => $client['pakasir_webhook_secret'] ?? '',
    'provider_name' => SITE_NAME,
    'provider_logo' => $logoPath !== '' ? 'https://adfsystem.store/' . ltrim($logoPath, '/') : '',
    'provider_address' => CONTACT_ADDRESS,
    'provider_email' => CONTACT_EMAIL,
    'updated_at' => $client['updated_at'] ?? null,
]);
