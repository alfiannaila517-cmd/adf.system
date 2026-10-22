<?php

/** Sinkron langganan: catat pembayaran yang dilaporkan sistem bisnis (tanpa email). */

declare(strict_types=1);

define('CONSOLE_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

$p = SubSync::payload();
$sub = SubSync::auth($p, true);
SubSync::respond(200, ['ok' => true, 'recorded' => SubSync::recordPayment($sub, $p)]);
