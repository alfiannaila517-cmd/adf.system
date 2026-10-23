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
// Bayar online (Mayar): cek dulu link yang masih menunggu (maks. 1×/menit), lalu tampilkan tombol bila belum lunas
$actions = '';
if (Mayar::configured() && $inv['status'] === 'unpaid') {
    $st = Biz::db()->prepare("SELECT * FROM payment_links WHERE ref_type = 'invoice' AND ref = ? AND status = 'pending' AND (checked_at IS NULL OR checked_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE)) ORDER BY id DESC LIMIT 1");
    $st->execute([(string)$inv['id']]);
    if (($pl = $st->fetch()) && (function () use ($pl) { try { return Pay::check($pl); } catch (Throwable) { return false; } })()) {
        $inv = Invoice::find((int)$inv['id']);
    }
    if ($inv['status'] === 'unpaid') {
        $actions = '<a class="pri" href="/pay.php?t=' . e(rawurlencode((string)$inv['token'])) . '" style="text-decoration:none;display:inline-flex;align-items:center">Bayar sekarang (QRIS / VA / e-wallet)</a>';
    }
}
Invoice::render($inv, ['public' => true, 'actions' => $actions]);
