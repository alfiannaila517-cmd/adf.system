<?php
// ONE-TIME DIAGNOSTIC: inspect saved user_menu_permissions for a given user+business.
// Delete this file after use.
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$manualToken = (string)($_GET['token'] ?? '');
if ($manualToken !== 'diag-perms-2026-08-19') {
    http_response_code(403);
    echo 'Akses ditolak.';
    exit;
}

$userId = (int)($_GET['user_id'] ?? 48);
$businessId = (int)($_GET['business_id'] ?? 2);

header('Content-Type: text/plain');

$db = Database::getInstance();

$user = $db->fetchOne("SELECT id, username, full_name, role_id FROM users WHERE id = ?", [$userId]);
echo "User: " . print_r($user, true) . "\n";

$role = $db->fetchOne("SELECT role_code, role_name FROM roles WHERE id = ?", [$user['role_id'] ?? 0]);
echo "Role: " . print_r($role, true) . "\n";

$biz = $db->fetchOne("SELECT id, business_code, business_name FROM businesses WHERE id = ?", [$businessId]);
echo "Business: " . print_r($biz, true) . "\n";

$rows = $db->fetchAll("SELECT * FROM user_menu_permissions WHERE user_id = ? AND business_id = ? ORDER BY menu_code", [$userId, $businessId]);
echo "\nSaved permission rows (" . count($rows) . "):\n";
foreach ($rows as $r) {
    echo "  menu_id={$r['menu_id']} menu_code={$r['menu_code']} view={$r['can_view']} create={$r['can_create']} edit={$r['can_edit']} delete={$r['can_delete']}\n";
}

$menus = $db->fetchAll("
    SELECT m.id, m.menu_code, m.menu_name
    FROM menu_items m
    JOIN business_menu_config bmc ON m.id = bmc.menu_id
    WHERE bmc.business_id = ? AND bmc.is_enabled = 1 AND m.is_active = 1
    ORDER BY m.menu_order
", [$businessId]);
echo "\nMenus enabled for this business (" . count($menus) . "):\n";
foreach ($menus as $m) {
    echo "  id={$m['id']} code={$m['menu_code']} name={$m['menu_name']}\n";
}

echo "\nDone.\n";
