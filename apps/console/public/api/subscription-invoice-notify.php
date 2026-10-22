<?php

/** Sinkron langganan: sistem bisnis membuat tagihan bulanan baru → email pemberitahuan ke notify_email. */

declare(strict_types=1);

define('CONSOLE_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

$p = SubSync::payload();
$sub = SubSync::auth($p, true);
$name = SubSync::clientName($sub);
$period = (string)$p['period'];
$due = strtotime((string)($p['due_date'] ?? '')) ? date('d M Y', strtotime((string)$p['due_date'])) : '-';
$html = '<p>Halo ' . e($name) . ',</p><p>Tagihan langganan ADF System periode <b>' . e($period) . '</b> sudah terbit:</p>'
    . '<p style="font-size:18px"><b>' . e(Invoice::rp((float)($p['total_amount'] ?? 0))) . '</b> · jatuh tempo ' . e($due) . '</p>'
    . '<p>Pembayaran bisa dilakukan dari menu <b>Langganan</b> di sistem Anda.</p><p>Terima kasih.</p>';
$res = SubSync::notify($sub, "Tagihan Langganan Baru - {$name} (Periode {$period})", $html);
SubSync::respond(200, $res === null ? ['ok' => true, 'skipped' => 'no notify_email configured for this client'] : ['ok' => true] + $res);
