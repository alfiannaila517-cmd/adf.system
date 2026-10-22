<?php

/** Sinkron langganan: tagihan dibayar (Pakasir di sistem bisnis) → catat pembayaran + email tanda terima. */

declare(strict_types=1);

define('CONSOLE_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

$p = SubSync::payload();
$sub = SubSync::auth($p, true);
$orderId = SubSync::recordPayment($sub, $p);
$name = SubSync::clientName($sub);
$what = trim((string)($p['description'] ?? '')) ?: (string)$p['period'];
$paid = strtotime((string)($p['paid_at'] ?? '')) ? date('d M Y H:i', strtotime((string)$p['paid_at'])) : date('d M Y H:i');
$html = '<p>Halo ' . e($name) . ',</p><p>Terima kasih, pembayaran <b>' . e($what) . '</b> sudah kami terima:</p>'
    . '<p style="font-size:18px"><b>' . e(Invoice::rp((float)($p['total_amount'] ?? 0))) . '</b> · ' . e($paid) . ' WIB</p>'
    . '<p>No. transaksi: ' . e($orderId) . '</p><p>Salam, ' . e((string)Invoice::settings()['company_name']) . '</p>';
$res = SubSync::notify($sub, "Pembayaran Langganan Diterima - {$name} ({$what})", $html);
SubSync::respond(200, $res === null ? ['ok' => true, 'recorded' => $orderId, 'skipped' => 'no notify_email configured for this client'] : ['ok' => true] + $res);
