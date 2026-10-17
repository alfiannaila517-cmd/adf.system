<?php

/**
 * Halaman invoice publik untuk pelanggan: dibuka lewat tautan unik (?t=<token>), hanya-baca, bisa dicetak / disimpan PDF.
 * Draft tidak bisa dilihat. Halaman tidak diindeks mesin pencari.
 */
require_once __DIR__ . '/includes/invoices-store.php';
require_once __DIR__ . '/includes/invoice-render.php';

$token = (string) ($_GET['t'] ?? '');
$inv = adf_invoice_find_by_token($token);
if (!$inv) {
    http_response_code(404);
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Invoice tidak ditemukan</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#0b0e14;color:#e6e9ef;display:grid;place-items:center;min-height:100vh;margin:0;text-align:center}div{padding:24px}h1{font-size:20px;margin:0 0 8px}p{color:#94a3b8;font-size:14px;margin:0}</style></head>'
        . '<body><div><h1>Invoice tidak ditemukan</h1><p>Tautan tidak valid atau invoice belum diterbitkan. Hubungi penerbit invoice.</p></div></body></html>';
    exit;
}
header('X-Robots-Tag: noindex, nofollow');
adf_invoice_render_document($inv, adf_invoice_settings(), ['asset_base' => '', 'public' => true]);
