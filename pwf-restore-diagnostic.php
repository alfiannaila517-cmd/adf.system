<?php

/**
 * One-off diagnostic: investigate the deleted PWF business record and check
 * whether related user_business_assignment / user_menu_permissions rows are
 * still intact (orphaned, i.e. NOT cascade-deleted) so we can safely restore
 * the businesses row with the SAME id and reconnect everything.
 * Usage: https://adfsystem.online/pwf-restore-diagnostic.php?token=adf-deploy-2025-secure
 */

declare(strict_types=1);

$token = $_GET['token'] ?? '';
if (!hash_equals('adf-deploy-2025-secure', (string)$token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

echo "=== 1) Current businesses table (any PWF/Prapen match) ===\n";
$rows = $pdo->query("SELECT id, business_code, business_name, business_type, slug, addon_domain, is_active FROM businesses WHERE business_name LIKE '%PWF%' OR business_name LIKE '%Prapen%' OR business_code LIKE '%pwf%'")->fetchAll();
if (!$rows) {
    echo "(none found - confirms the business row is gone)\n";
} else {
    foreach ($rows as $r) echo json_encode($r) . "\n";
}

echo "\n=== 2) All businesses (for reference) ===\n";
foreach ($pdo->query("SELECT id, business_code, business_name FROM businesses ORDER BY id")->fetchAll() as $r) {
    echo json_encode($r) . "\n";
}

echo "\n=== 3) audit_logs entries for delete_business (most recent 5) ===\n";
try {
    $stmt = $pdo->query("SELECT id, user_id, action, entity_type, entity_id, old_value, created_at FROM audit_logs WHERE action = 'delete_business' ORDER BY id DESC LIMIT 5");
    $logs = $stmt->fetchAll();
    if (!$logs) {
        echo "(no audit_logs rows found for delete_business)\n";
    } else {
        foreach ($logs as $l) echo json_encode($l) . "\n";
    }
} catch (Throwable $e) {
    echo "audit_logs query failed: " . $e->getMessage() . "\n";
}

echo "\n=== 4) Orphaned business_id values in user_business_assignment (business_id not present in businesses table) ===\n";
$orphans = $pdo->query("
    SELECT uba.business_id, COUNT(*) as cnt
    FROM user_business_assignment uba
    LEFT JOIN businesses b ON b.id = uba.business_id
    WHERE b.id IS NULL
    GROUP BY uba.business_id
")->fetchAll();
if (!$orphans) {
    echo "(none - either no orphans, or FK cascade already removed those rows)\n";
} else {
    foreach ($orphans as $o) echo json_encode($o) . "\n";
}

echo "\n=== 5) For each orphaned business_id, list affected users ===\n";
foreach ($orphans as $o) {
    $bid = $o['business_id'];
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.full_name, u.role_id
        FROM user_business_assignment uba
        INNER JOIN users u ON u.id = uba.user_id
        WHERE uba.business_id = ?
    ");
    $stmt->execute([$bid]);
    echo "-- business_id={$bid} --\n";
    foreach ($stmt->fetchAll() as $u) echo json_encode($u) . "\n";
}

echo "\n=== 6) Orphaned business_id values in user_menu_permissions ===\n";
$permOrphans = $pdo->query("
    SELECT ump.business_id, COUNT(*) as cnt
    FROM user_menu_permissions ump
    LEFT JOIN businesses b ON b.id = ump.business_id
    WHERE b.id IS NULL
    GROUP BY ump.business_id
")->fetchAll();
if (!$permOrphans) {
    echo "(none)\n";
} else {
    foreach ($permOrphans as $o) echo json_encode($o) . "\n";
}

echo "\n=== 7) Users with ZERO rows in user_business_assignment (likely PWF staff who lost their link) ===\n";
$noAssign = $pdo->query("
    SELECT u.id, u.username, u.email, u.full_name, u.role_id, u.created_by, u.created_at
    FROM users u
    LEFT JOIN user_business_assignment uba ON uba.user_id = u.id
    WHERE uba.user_id IS NULL
    ORDER BY u.id DESC
")->fetchAll();
if (!$noAssign) {
    echo "(none found)\n";
} else {
    foreach ($noAssign as $u) echo json_encode($u) . "\n";
}

echo "\nDone.\n";

echo "\n=== 8) Roles reference ===\n";
foreach ($pdo->query("SELECT id, role_name, role_code FROM roles ORDER BY id")->fetchAll() as $r) {
    echo json_encode($r) . "\n";
}

echo "\n=== 9) audit_logs mentioning these candidate user ids (create/assign actions) ===\n";
$candidateIds = [41, 40, 39, 38, 31, 27];
$in = implode(',', array_fill(0, count($candidateIds), '?'));
$stmt = $pdo->prepare("
    SELECT id, user_id, action, entity_type, entity_id, old_value, new_value, created_at
    FROM audit_logs
    WHERE entity_id IN ($in)
    ORDER BY id ASC
");
$stmt->execute($candidateIds);
foreach ($stmt->fetchAll() as $l) echo json_encode($l) . "\n";

echo "\n=== 10) Creator users (id=16) info ===\n";
$stmt = $pdo->prepare("SELECT id, username, email, full_name, role_id FROM users WHERE id IN (8, 16)");
$stmt->execute();
foreach ($stmt->fetchAll() as $u) echo json_encode($u) . "\n";

echo "\nDone2.\n";
