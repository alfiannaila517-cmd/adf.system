<?php
// TEMP diagnostic v2: inspect purchase_orders_detail schema + PO detail rows for bens-cafe.
// Usage: diag-bens-po-v2.php?token=diag-bens-po-2026-08-19
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/procurement_functions.php';

header('Content-Type: text/plain');

if (($_GET['token'] ?? '') !== 'diag-bens-po-2026-08-19') {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

try {
    $cfg = require __DIR__ . '/config/businesses/bens-cafe.php';
    $dbName = (string)($cfg['database'] ?? '');
    $resolvedDbName = gudangResolveBusinessDbName($dbName);

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $resolvedDbName . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "=== purchase_orders_detail columns in {$resolvedDbName} ===\n";
    $cols = $pdo->query("SHOW COLUMNS FROM purchase_orders_detail")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo $c['Field'] . " (" . $c['Type'] . ")\n";
    }

    echo "\n=== purchase_orders_detail rows for po_header_id=31 ===\n";
    $items = $pdo->query("SELECT * FROM purchase_orders_detail WHERE po_header_id = 31")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $it) {
        echo json_encode($it) . "\n";
    }
    if (!$items) echo "(no item rows for po_header_id=31)\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
