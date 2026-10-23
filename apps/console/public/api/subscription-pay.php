<?php

/**
 * Sistem bisnis minta link bayar online (Mayar) untuk satu tagihan: {client_key, client_token, period[, description]}
 * → {payment_url, amount, expires_at}. Jumlah ditentukan Console. Dipakai bila subscription-config mengirim
 * payment_gateway = "console".
 */

declare(strict_types=1);

define('CONSOLE_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

$p = SubSync::payload();
$sub = SubSync::auth($p, true);
if (!Mayar::configured()) {
    SubSync::respond(503, ['error' => 'Pembayaran online belum diatur di ADF.']);
}
try {
    $link = Pay::forPeriod($sub, trim((string)$p['period']), trim((string)($p['description'] ?? '')));
    SubSync::respond(200, ['payment_url' => $link['link'], 'amount' => (float)$link['amount'], 'expires_at' => $link['expires_at'], 'gateway' => 'mayar']);
} catch (Throwable $e) {
    SubSync::respond(422, ['error' => $e->getMessage()]);
}
