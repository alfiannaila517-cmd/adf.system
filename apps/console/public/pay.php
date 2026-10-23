<?php

/** "Bayar sekarang" dari link invoice publik: /pay.php?t=<token> → buat / pakai ulang link Mayar lalu arahkan ke sana. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$token = (string)($_GET['t'] ?? '');
$inv = Invoice::findByToken($token);
$back = '/invoice.php?t=' . rawurlencode($token);
if (!$inv || !Mayar::configured()) {
    redirect($inv ? $back : '/');
}
try {
    header('Location: ' . Pay::forInvoice($inv)['link']);
    exit;
} catch (Throwable $e) {
    http_response_code(502);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pembayaran</title>'
        . '<body style="font-family:system-ui,sans-serif;background:#0b0e14;color:#e6e9ef;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px">'
        . '<div style="text-align:center;max-width:420px"><b style="font-size:17px">Pembayaran online belum bisa dibuat</b><p style="color:#9aa3b2">' . e($e->getMessage())
        . '</p><a href="' . e($back) . '" style="color:#ff6a1a">‹ Kembali ke invoice</a></div>';
}
