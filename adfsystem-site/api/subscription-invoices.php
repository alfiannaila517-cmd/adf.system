<?php

/**
 * Sinkron tagihan langganan untuk situs klien (mis. Karimunjawa Explore).
 * Klien melaporkan pax per bulan; ADF Store menghitung tagihan (biaya bulanan + pax x biaya per pax),
 * lalu mengembalikan daftar invoice klien. Admin mengelola invoice di admin/subscription-invoices.php.
 *
 * POST JSON: {"client_key", "client_token",
 *             "usage": {"2026-10": 4, ...},
 *             "local_paid": [{"ref","base_fee","guest_count","per_guest_fee","total_amount","paid_at"}],
 *             "links": ["2026-10", ...]}   // ref invoice yang link bayarnya sudah dibuat di klien
 */

require_once __DIR__ . '/../includes/subscription-clients-store.php';
require_once __DIR__ . '/../includes/subscription-ledger-store.php';

header('Content-Type: application/json');

$payload = json_decode((string) file_get_contents('php://input'), true);
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

try {
    $invoices = adf_ledger_ingest($client, $payload);
} catch (Throwable $e) {
    error_log('subscription-invoices error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'ledger error']);
    exit;
}

echo json_encode([
    'ledger' => true,
    'invoices' => array_map(static function (array $inv): array {
        return [
            'ref' => $inv['ref'],
            'kind' => $inv['kind'],
            'period' => $inv['period'],
            'base_fee' => (float) $inv['base_fee'],
            'guest_count' => (int) $inv['guest_count'],
            'per_guest_fee' => (float) $inv['per_guest_fee'],
            'adjustment' => (float) ($inv['adjustment'] ?? 0),
            'note' => (string) ($inv['note'] ?? ''),
            'total' => adf_ledger_total($inv),
            'status' => $inv['status'],
            'due_date' => $inv['due_date'] ?? null,
            'paid_at' => $inv['paid_at'] ?? null,
        ];
    }, $invoices),
]);
