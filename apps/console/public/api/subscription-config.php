<?php

/** Sinkron langganan (sama dengan adfsystem.store): konfigurasi langganan untuk sistem bisnis. */

declare(strict_types=1);

define('CONSOLE_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

$sub = SubSync::auth(SubSync::payload());
// Sambil sinkron: cek pembayaran Mayar yang masih menunggu (cadangan bila webhook tidak sampai)
Pay::checkPending((int)$sub['business_id']);
SubSync::respond(200, SubSync::config($sub));
