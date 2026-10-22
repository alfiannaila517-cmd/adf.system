<?php

/** Sinkron langganan: tagihan manual (invoice Console yang ditagihkan lewat sistem bisnis ini, belum dibayar). */

declare(strict_types=1);

define('CONSOLE_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

$sub = SubSync::auth(SubSync::payload());
SubSync::respond(200, ['manual_invoices' => SubSync::manualInvoices($sub)]);
