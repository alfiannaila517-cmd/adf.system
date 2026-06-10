<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
echo '<pre>';
echo 'PHP: ' . PHP_VERSION . "\n";
echo 'File: ' . __FILE__ . "\n";
echo 'Modified: ' . date('Y-m-d H:i:s', filemtime(__FILE__)) . "\n";
echo 'Host: ' . ($_SERVER['HTTP_HOST'] ?? 'n/a') . "\n";
echo 'Document root: ' . ($_SERVER['DOCUMENT_ROOT'] ?? 'n/a') . "\n";
echo "\n--- Trying bootstrap ---\n";
try {
    define('APP_ACCESS', true);
    require_once __DIR__ . '/../../config/config.php';
    echo "config.php OK\n";
    echo 'DB_HOST=' . DB_HOST . "\n";
    echo 'DB_NAME=' . DB_NAME . "\n";
    require_once __DIR__ . '/db-helper.php';
    echo "db-helper.php OK\n";
    $pdo = getPwfOfficePdo();
    echo "getPwfOfficePdo() OK\n";
    $r = $pdo->query('SELECT COUNT(*) FROM pwf_warehouse_stock')->fetchColumn();
    echo "pwf_warehouse_stock rows: $r\n";
} catch (\Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
echo '</pre>';
