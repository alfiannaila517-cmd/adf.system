<?php
// ONE-TIME correction: PO created via Staff Portal (createStaffPoToGudang) before the
// price-fix always stored unit_price=0/subtotal=0/total_amount=0 because staff never
// enter a price. This backfills those rows using Gudang Nasita's current catalog price
// (gudang_nasita_barang.harga_beli, fallback gudang_nasita_stock.harga_beli), matched
// case-insensitively by item name. Only touches PO detail rows whose supplier is the
// internal "Gudang Nasita" supplier and whose unit_price is currently 0.
// Usage: fix-staff-po-gudang-prices.php?token=fix-po-price-2026-09-01&business=eaat-meet&confirm=1
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain');

if (($_GET['token'] ?? '') !== 'fix-po-price-2026-09-01') {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

$slug = (string)($_GET['business'] ?? '');
$confirm = ($_GET['confirm'] ?? '') === '1';
if ($slug === '') {
    echo "Missing ?business=<slug>\n";
    exit;
}

$originDb = Database::getCurrentDatabase();

try {
    $cfgPath = __DIR__ . '/config/businesses/' . $slug . '.php';
    if (!file_exists($cfgPath)) {
        echo "Business config not found: $slug\n";
        exit;
    }
    $cfg = require $cfgPath;
    $bizDbName = (string)($cfg['database'] ?? '');
    if ($bizDbName === '') {
        echo "No database configured for: $slug\n";
        exit;
    }

    $gudangCfg = require __DIR__ . '/config/businesses/gudang-nasita.php';
    $gudangDbName = (string)($gudangCfg['database'] ?? '');

    $db = Database::switchDatabase($bizDbName);

    $gudangSupplier = $db->fetchOne("SELECT id FROM suppliers WHERE LOWER(supplier_name) LIKE '%gudang nasita%' LIMIT 1");
    if (!$gudangSupplier) {
        echo "No internal Gudang Nasita supplier found in $bizDbName.\n";
        exit;
    }

    $zeroRows = $db->fetchAll(
        "SELECT d.id, d.po_header_id, d.item_name, d.quantity
         FROM purchase_orders_detail d
         JOIN purchase_orders_header h ON h.id = d.po_header_id
         WHERE h.supplier_id = ? AND d.unit_price = 0",
        [(int)$gudangSupplier['id']]
    ) ?: [];

    echo "Zero-price PO detail rows found: " . count($zeroRows) . "\n";
    if (!$zeroRows) {
        exit;
    }

    // Build catalog price map from Gudang Nasita.
    $priceByName = [];
    $gudangDb = Database::switchDatabase($gudangDbName);
    $barangRows = $gudangDb->fetchAll("SELECT nama_barang, harga_beli FROM gudang_nasita_barang") ?: [];
    foreach ($barangRows as $r) {
        $key = strtolower(trim((string)$r['nama_barang']));
        if ($key !== '' && (float)$r['harga_beli'] > 0) {
            $priceByName[$key] = (float)$r['harga_beli'];
        }
    }
    $stockRows = $gudangDb->fetchAll("SELECT item_name, harga_beli FROM gudang_nasita_stock") ?: [];
    foreach ($stockRows as $r) {
        $key = strtolower(trim((string)$r['item_name']));
        if ($key !== '' && !isset($priceByName[$key]) && (float)$r['harga_beli'] > 0) {
            $priceByName[$key] = (float)$r['harga_beli'];
        }
    }
    $db = Database::switchDatabase($bizDbName);

    $updated = 0;
    $skippedNoPrice = [];
    $touchedHeaders = [];

    foreach ($zeroRows as $row) {
        $key = strtolower(trim((string)$row['item_name']));
        $price = $priceByName[$key] ?? 0;
        if ($price <= 0) {
            $skippedNoPrice[] = $row['item_name'];
            continue;
        }
        $subtotal = $price * (float)$row['quantity'];
        echo sprintf(
            "PO detail #%d [%s]: Rp 0 -> Rp %s (qty %s)\n",
            $row['id'],
            $row['item_name'],
            number_format($price, 0, ',', '.'),
            $row['quantity']
        );
        if ($confirm) {
            $db->update('purchase_orders_detail', ['unit_price' => $price, 'subtotal' => $subtotal], 'id = :where_id', ['where_id' => $row['id']]);
            $touchedHeaders[(int)$row['po_header_id']] = true;
            $updated++;
        }
    }

    if ($confirm && $touchedHeaders) {
        foreach (array_keys($touchedHeaders) as $headerId) {
            $sum = $db->fetchOne("SELECT COALESCE(SUM(subtotal),0) AS total FROM purchase_orders_detail WHERE po_header_id = ?", [$headerId]);
            $db->update('purchase_orders_header', ['total_amount' => (float)($sum['total'] ?? 0)], 'id = :where_id', ['where_id' => $headerId]);
        }
    }

    echo "\nItems with no catalog price (left untouched): " . count($skippedNoPrice) . "\n";
    foreach (array_unique($skippedNoPrice) as $name) {
        echo " - $name\n";
    }

    echo $confirm
        ? ("\nUpdated $updated detail row(s) and " . count($touchedHeaders) . " header(s).\n")
        : "\nDry run only. Add &confirm=1 to apply.\n";

    Database::switchDatabase($originDb);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
