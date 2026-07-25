<?php
// TEMP diagnostic - check Bens Cafe business config for replicating to "Eat Meet"
// Remove after use.
if (($_GET['token'] ?? '') !== 'adf-deploy-2025-secure') { http_response_code(403); exit('forbidden'); }
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';

header('Content-Type: application/json');

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . MASTER_DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $biz = $pdo->query("SELECT * FROM businesses WHERE business_name LIKE '%Bens%' OR slug LIKE '%bens%'")->fetch(PDO::FETCH_ASSOC);
    $out = ['business' => $biz];

    if ($biz) {
        $stmt = $pdo->prepare("SELECT m.id, m.menu_code, m.menu_name, bmc.is_enabled
            FROM business_menu_config bmc
            JOIN menu_items m ON m.id = bmc.menu_id
            WHERE bmc.business_id = ? ORDER BY m.id");
        $stmt->execute([$biz['id']]);
        $out['menus'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $accStmt = $pdo->prepare("SELECT account_name, account_type, is_default_account FROM cash_accounts WHERE business_id = ?");
        $accStmt->execute([$biz['id']]);
        $out['cash_accounts'] = $accStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Check businesses.business_type ENUM definition
    $col = $pdo->query("SHOW COLUMNS FROM businesses LIKE 'business_type'")->fetch(PDO::FETCH_ASSOC);
    $out['business_type_column'] = $col;

    echo json_encode($out, JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
