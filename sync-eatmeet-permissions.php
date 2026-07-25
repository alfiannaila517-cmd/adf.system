<?php
// TEMP: inspect + sync user_menu_permissions from Bens Cafe (biz 2) to Eat Meet (biz 11)
if (($_GET['token'] ?? '') !== 'adf-deploy-2025-secure') { http_response_code(403); exit('forbidden'); }
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
header('Content-Type: application/json');

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . MASTER_DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $tblCheck = $pdo->query("SHOW TABLES LIKE 'user_menu_permissions'")->fetchColumn();
    if (!$tblCheck) {
        echo json_encode(['error' => 'table user_menu_permissions does not exist']);
        exit;
    }

    // All rows for Bens Cafe (business_id=2)
    $bens = $pdo->query("
        SELECT ump.*, u.username, u.full_name
        FROM user_menu_permissions ump
        JOIN users u ON u.id = ump.user_id
        WHERE ump.business_id = 2
        ORDER BY u.username, ump.menu_code
    ")->fetchAll(PDO::FETCH_ASSOC);

    // All rows for Eat Meet (business_id=11) currently
    $eatmeet = $pdo->query("SELECT ump.*, u.username FROM user_menu_permissions ump JOIN users u ON u.id=ump.user_id WHERE ump.business_id = 11")->fetchAll(PDO::FETCH_ASSOC);

    // All developer/owner users
    $users = $pdo->query("SELECT u.id, u.username, u.full_name, r.role_code FROM users u JOIN roles r ON r.id=u.role_id WHERE r.role_code IN ('developer','owner') AND u.is_active=1")->fetchAll(PDO::FETCH_ASSOC);

    if (($_GET['apply'] ?? '') === '1') {
        $pdo->prepare("DELETE FROM user_menu_permissions WHERE business_id = 11")->execute();
        $insCols = array_diff(array_keys($bens[0] ?? []), ['id', 'username', 'full_name', 'business_id']);
        $count = 0;
        foreach ($bens as $row) {
            $cols = $insCols;
            $newRow = $row;
            $newRow['business_id'] = 11;
            $colNames = implode(',', array_merge(['business_id'], $cols));
            $placeholders = implode(',', array_fill(0, count($cols) + 1, '?'));
            $vals = [11];
            foreach ($cols as $c) $vals[] = $newRow[$c];
            $pdo->prepare("INSERT INTO user_menu_permissions ($colNames) VALUES ($placeholders)")->execute($vals);
            $count++;
        }
        echo json_encode(['applied' => true, 'rows_copied' => $count]);
    } else {
        echo json_encode([
            'preview_only' => true,
            'bens_cafe_permission_rows' => $bens,
            'eat_meet_permission_rows_current' => $eatmeet,
            'developer_owner_users' => $users
        ], JSON_PRETTY_PRINT);
    }
} catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
