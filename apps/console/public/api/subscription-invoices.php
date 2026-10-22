<?php

/** Sinkron langganan: buku tagihan per tamu — klien kirim pax per bulan, Console menghitung & mengembalikan invoice. */

declare(strict_types=1);

define('CONSOLE_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

$p = SubSync::payload();
$sub = SubSync::auth($p);
try {
    SubSync::respond(200, ['ledger' => true, 'invoices' => SubSync::ledgerIngest($sub, $p)]);
} catch (Throwable $e) {
    error_log('subscription-invoices: ' . $e->getMessage());
    SubSync::respond(500, ['error' => 'ledger error']);
}
