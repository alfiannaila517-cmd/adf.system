<?php
// TEMPORARY diagnostic — requires login. Delete this file after use.
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

header('Content-Type: text/plain; charset=utf-8');

$transferNumber = $_GET['no'] ?? 'GNT-202608-0005';

$gudangCfgPath = __DIR__ . '/config/businesses/gudang-nasita.php';
$gudangCfg = require $gudangCfgPath;
$gudangDbName = (string)($gudangCfg['database'] ?? '');
$db = Database::switchDatabase($gudangDbName);

echo "DB gudang: $gudangDbName\n";
echo "Transfer: $transferNumber\n\n";

$transfer = $db->fetchOne('SELECT * FROM gudang_nasita_transfers WHERE transfer_number = ? LIMIT 1', [$transferNumber]);
if (!$transfer) {
    echo "Transfer tidak ditemukan.\n";
    exit;
}
echo "Transfer ID: {$transfer['id']}\n\n";

$items = $db->fetchAll('SELECT * FROM gudang_nasita_transfer_items WHERE transfer_id = ? ORDER BY id ASC', [$transfer['id']]);

$hasBarangId = false;
$stockCols = $db->fetchAll("SHOW COLUMNS FROM gudang_nasita_stock");
foreach ($stockCols as $c) {
    if (strtolower((string)($c['Field'] ?? '')) === 'barang_id') {
        $hasBarangId = true;
        break;
    }
}
echo "gudang_nasita_stock has barang_id column: " . ($hasBarangId ? 'YES' : 'NO') . "\n\n";

foreach ($items as $it) {
    echo "--- item_id={$it['id']} stock_id={$it['stock_id']} name={$it['item_name']} qty={$it['quantity']} ---\n";
    echo "  transfer_items.unit_price = {$it['unit_price']}\n";
    echo "  transfer_items.subtotal   = {$it['subtotal']}\n";

    if (!empty($it['stock_id'])) {
        $stock = $db->fetchOne('SELECT * FROM gudang_nasita_stock WHERE id = ?', [(int)$it['stock_id']]);
        if ($stock) {
            echo "  stock.harga_beli   = " . ($stock['harga_beli'] ?? 'NULL') . "\n";
            echo "  stock.total_harga  = " . ($stock['total_harga'] ?? 'NULL') . "\n";
            echo "  stock.quantity     = " . ($stock['quantity'] ?? 'NULL') . "\n";
            echo "  stock.barang_id    = " . ($stock['barang_id'] ?? 'NULL') . "\n";

            if ($hasBarangId && !empty($stock['barang_id'])) {
                $barang = $db->fetchOne('SELECT * FROM gudang_nasita_barang WHERE id = ?', [(int)$stock['barang_id']]);
                if ($barang) {
                    echo "  barang.harga_beli  = " . ($barang['harga_beli'] ?? 'NULL') . "\n";
                    echo "  barang.nama_barang = " . ($barang['nama_barang'] ?? $barang['item_name'] ?? '?') . "\n";
                } else {
                    echo "  barang: NOT FOUND for barang_id={$stock['barang_id']}\n";
                }
            }
        } else {
            echo "  stock: NOT FOUND for stock_id={$it['stock_id']}\n";
        }
    } else {
        echo "  stock_id is empty/null on this transfer item!\n";
    }
    echo "\n";
}
