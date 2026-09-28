<?php

/**
 * Read-only manual (ad-hoc) subscription invoices API for client sites.
 * Manual invoices are created only via admin/subscription-manual-invoice.php —
 * this endpoint never accepts writes.
 *
 * POST JSON: {"client_key": "...", "client_token": "..."}
 */

require_once __DIR__ . '/../includes/subscription-clients-store.php';
require_once __DIR__ . '/../includes/subscription-manual-invoices-store.php';

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

$manualInvoices = array_map(static function (array $inv): array {
    return [
        'id' => $inv['id'],
        'description' => $inv['description'],
        'amount' => (float) $inv['amount'],
        'due_date' => $inv['due_date'],
        'created_at' => $inv['created_at'],
    ];
}, adf_manual_invoices_for_client($clientKey));

http_response_code(200);
echo json_encode(['manual_invoices' => $manualInvoices]);
