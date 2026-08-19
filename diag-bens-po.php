<?php
// TEMP diagnostic: inspect recent purchase_orders_header rows created from bens-cafe
// targeting Gudang Nasita, to see why a PO isn't showing up in Gudang's "PO Masuk" list.
// Usage: diag-bens-po.php?token=diag-bens-po-2026-08-19
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

    echo "bens-cafe config database = {$dbName}\n";
    echo "resolved (production) database = {$resolvedDbName}\n\n";

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $resolvedDbName . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "=== Last 10 purchase_orders_header rows in {$resolvedDbName} ===\n";
    $rows = $pdo->query("SELECT id, po_number, supplier_id, business_id, po_date, status, total_amount, created_at FROM purchase_orders_header ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo json_encode($r) . "\n";
    }
    if (!$rows) echo "(no rows at all)\n";

    echo "\n=== Suppliers matching 'gudang nasita' in {$resolvedDbName} ===\n";
    $sup = $pdo->query("SELECT id, supplier_name FROM suppliers WHERE LOWER(supplier_name) LIKE '%gudang nasita%'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sup as $s) {
        echo json_encode($s) . "\n";
    }
    if (!$sup) echo "(no gudang nasita supplier row found!)\n";

    echo "\n=== purchase_orders_detail columns in {$resolvedDbName} ===\n";
    $cols = $pdo->query("SHOW COLUMNS FROM purchase_orders_detail")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo $c['Field'] . " (" . $c['Type'] . ")\n";
    }

    echo "\n=== purchase_orders_detail rows for po_header_id=31 ===\n";
    $items = $pdo->query("SELECT * FROM purchase_orders_detail WHERE po_header_id = 31")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $it) {
        echo json_encode($it) . "\n";
    }

    echo "\n=== gudangFetchPendingPoFromBusinessDb('{$dbName}') result (what Gudang dashboard uses) ===\n";
    try {
        $pending = gudangFetchPendingPoFromBusinessDb($dbName);
        foreach ($pending as $p) {
            unset($p['items']);
            echo json_encode($p) . "\n";
        }
        if (!$pending) echo "(empty - none with status submitted/approved/partially_received)\n";
    } catch (Throwable $e2) {
        echo "ERROR calling gudangFetchPendingPoFromBusinessDb: " . $e2->getMessage() . "\n";
    }

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
