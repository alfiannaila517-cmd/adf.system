<?php
// ONE-TIME REPAIR: the "kembalikan ke gudang" bug (fixed in business-stock-incoming.php)
// forced category='lainnya' on every returned item, which silently moved "Jack Daniel"
// out of the Alkohol tab even though its qty was correct. Restores category='alkohol'.
// Delete this file after running it once successfully.
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Allow either a logged-in gudang_nasita/warehouse user, OR a one-off manual token
// (so this can be triggered once without needing an interactive browser session).
$manualToken = (string)($_GET['token'] ?? '');
$isManual = ($manualToken === 'fix-jd-2026-08-19');
if (!$isManual) {
    $auth = new Auth();
    $auth->requireLogin();
    if (!($auth->hasPermission('gudang_nasita') || $auth->hasPermission('warehouse'))) {
        http_response_code(403);
        echo 'Akses ditolak.';
        exit;
    }
}

$gudangCfgPath = __DIR__ . '/config/businesses/gudang-nasita.php';
$gudangCfg = require $gudangCfgPath;
$gudangDbName = (string)($gudangCfg['database'] ?? '');
if ($gudangDbName === '') {
    die('Database Gudang Nasita tidak ditemukan.');
}
$db = Database::switchDatabase($gudangDbName);

header('Content-Type: text/plain');

$rows = $db->fetchAll("SELECT id, item_name, category, quantity FROM gudang_nasita_stock WHERE item_name LIKE '%jack daniel%'");
if (!$rows) {
    echo "Tidak ada item 'Jack Daniel' ditemukan di stok Gudang Nasita.\n";
    exit;
}

foreach ($rows as $row) {
    echo "Sebelum: id={$row['id']} item={$row['item_name']} category={$row['category']} qty={$row['quantity']}\n";
    if (strtolower((string)$row['category']) !== 'alkohol') {
        $db->update('gudang_nasita_stock', ['category' => 'alkohol'], 'id = :id', ['id' => $row['id']]);
        echo "  -> category diperbaiki jadi 'alkohol'.\n";
    } else {
        echo "  -> category sudah benar, tidak diubah.\n";
    }
}


echo "\nSelesai.\n";
