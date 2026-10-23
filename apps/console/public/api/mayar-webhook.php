<?php

/**
 * Webhook Mayar (daftarkan di Payment Gateway Console → "Daftarkan webhook", atau di dashboard Mayar → Integrasi → Webhook).
 * Payload Mayar tidak bertanda tangan → isi payload TIDAK dipercaya; Pay::webhook() mengecek ulang status tiap link ke API Mayar.
 */

declare(strict_types=1);

define('CONSOLE_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

header('Content-Type: application/json');
$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}
$event = (string)($payload['event'] ?? $payload['event.received'] ?? '');
if ($event !== '' && $event !== 'payment.received') {
    echo json_encode(['ok' => true, 'ignored' => $event]); // payment.reminder, membership.*, …
    exit;
}
try {
    $n = Mayar::configured() ? Pay::webhook($payload) : 0;
    echo json_encode(['ok' => true, 'settled' => $n]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
