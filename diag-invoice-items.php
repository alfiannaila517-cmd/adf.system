<?php
// TEMP diagnostic: dump hotel_invoices + hotel_invoice_items raw timestamps for one invoice id.
// Usage: diag-invoice-items.php?token=diag-inv-2026-08-19&id=269
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain');

if (($_GET['token'] ?? '') !== 'diag-inv-2026-08-19') {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

$id = (int)($_GET['id'] ?? 269);

try {
    $cfgPath = __DIR__ . '/config/businesses/narayana-hotel.php';
    $cfg = require $cfgPath;
    $dbName = (string)($cfg['database'] ?? '');
    $originDb = Database::getCurrentDatabase();
    $db = ($dbName && $dbName !== $originDb) ? Database::switchDatabase($dbName) : Database::getInstance();

    $inv = $db->fetchOne("SELECT id, invoice_number, created_at, last_service_at, updated_at FROM hotel_invoices WHERE id = ?", [$id]);
    echo "INVOICE:\n";
    print_r($inv);

    $items = $db->fetchAll("SELECT id, invoice_id, service_type, description, start_datetime, end_datetime, created_at FROM hotel_invoice_items WHERE invoice_id = ? ORDER BY id ASC", [$id]);
    echo "\nITEMS:\n";
    print_r($items);

    if ($dbName && $dbName !== $originDb) {
        Database::switchDatabase($originDb);
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
