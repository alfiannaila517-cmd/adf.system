<?php

/** Halaman invoice untuk pelanggan (tanpa login): /invoice.php?t=<token>. Draft tidak bisa dibuka. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$inv = Invoice::findByToken((string)($_GET['t'] ?? ''));
if (!$inv) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Invoice tidak ditemukan</title>'
        . '<body style="font-family:system-ui,sans-serif;background:#0b0e14;color:#e6e9ef;display:grid;place-items:center;min-height:100vh;margin:0">'
        . '<div style="text-align:center"><b style="font-size:18px">Invoice tidak ditemukan</b><p style="color:#7d8696">Periksa kembali link dari kami.</p></div>';
    exit;
}
Invoice::render($inv, ['public' => true]);
