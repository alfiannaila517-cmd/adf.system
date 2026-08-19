<?php
// TEMP diagnostic: inspect role + granular permissions for a specific user/business.
// Usage: diag-perm-check.php?token=diag-perm-2026-08-19&user_id=31&business_id=1
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain');

if (($_GET['token'] ?? '') !== 'diag-perm-2026-08-19') {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

$userId = (int)($_GET['user_id'] ?? 31);
$businessId = (int)($_GET['business_id'] ?? 1);

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "=== User row (id={$userId}) ===\n";
    $u = $pdo->prepare("SELECT u.id, u.username, u.full_name, u.role_id, r.role_code, r.role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?");
    $u->execute([$userId]);
    print_r($u->fetch(PDO::FETCH_ASSOC));

    echo "\n=== user_menu_permissions rows for user_id={$userId}, business_id={$businessId} ===\n";
    $p = $pdo->prepare("SELECT * FROM user_menu_permissions WHERE user_id = ? AND business_id = ? ORDER BY menu_code");
    $p->execute([$userId, $businessId]);
    $rows = $p->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) echo "(no rows at all - would count as 'unconfigured' and grant full access under the developer/admin/owner bypass!)\n";
    foreach ($rows as $r) {
        echo json_encode($r) . "\n";
    }

    echo "\n=== business row (id={$businessId}) ===\n";
    $b = $pdo->prepare("SELECT id, name, slug, business_code FROM businesses WHERE id = ?");
    $b->execute([$businessId]);
    print_r($b->fetch(PDO::FETCH_ASSOC));

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
