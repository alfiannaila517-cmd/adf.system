<?php

/**
 * Diagnostic: check push notification setup for a given business.
 * Usage: diag-push-notifications.php?b=bens-cafe
 *        diag-push-notifications.php?b=bens-cafe&test=1&emp=5  (sends a real test push to that employee)
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain');

$bizSlug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($_GET['b'] ?? '')));
$bizFile = __DIR__ . '/config/businesses/' . $bizSlug . '.php';
if (!$bizSlug || !file_exists($bizFile)) {
    die("Invalid/missing business slug. Available: " . implode(', ', array_map(
        fn($f) => basename($f, '.php'),
        glob(__DIR__ . '/config/businesses/*.php')
    )));
}

$bizConfig = require $bizFile;
if (!defined('ACTIVE_BUSINESS_ID')) define('ACTIVE_BUSINESS_ID', $bizConfig['business_id']);
$db = Database::switchDatabase($bizConfig['database']);
$pdo = $db->getConnection();

echo "=== Push Notification Diagnostic for '$bizSlug' ===\n\n";

echo "-- Environment --\n";
echo "vendor/autoload.php exists: " . (file_exists(__DIR__ . '/vendor/autoload.php') ? 'YES' : 'NO (composer install needed!)') . "\n";
require_once __DIR__ . '/config/vapid.php';
echo "VAPID_PUBLIC_KEY defined: " . (defined('VAPID_PUBLIC_KEY') && VAPID_PUBLIC_KEY ? 'YES' : 'NO') . "\n\n";

echo "-- push_subscriptions table --\n";
$pdo->exec("CREATE TABLE IF NOT EXISTS `push_subscriptions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT DEFAULT NULL,
    `employee_id` INT DEFAULT NULL,
    `endpoint` TEXT NOT NULL,
    `public_key` VARCHAR(255) NOT NULL,
    `auth_token` VARCHAR(255) NOT NULL,
    `user_agent` VARCHAR(500) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$subs = $db->fetchAll("SELECT id, user_id, employee_id, user_agent, created_at FROM push_subscriptions ORDER BY created_at DESC") ?: [];
echo "Total subscriptions: " . count($subs) . "\n";
foreach ($subs as $s) {
    echo "  id={$s['id']} user_id=" . ($s['user_id'] ?? 'NULL') . " employee_id=" . ($s['employee_id'] ?? 'NULL') . " created_at={$s['created_at']} ua=" . substr($s['user_agent'] ?? '', 0, 60) . "\n";
}

echo "\n-- notifications table (in-app bell) --\n";
$notifCount = $db->fetchOne("SELECT COUNT(*) c FROM notifications")['c'] ?? 'table missing/err';
echo "Total rows: $notifCount\n";

echo "\n-- payroll_employees (for picking a test emp id) --\n";
$emps = $db->fetchAll("SELECT id, full_name FROM payroll_employees ORDER BY id LIMIT 20") ?: [];
foreach ($emps as $e) {
    echo "  id={$e['id']} name={$e['full_name']}\n";
}

$testEmp = (int)($_GET['emp'] ?? 0);
if (isset($_GET['test']) && $testEmp > 0) {
    echo "\n-- Sending TEST push to employee_id=$testEmp --\n";
    require_once __DIR__ . '/includes/PushNotificationHelper.php';
    $push = new PushNotificationHelper($db);
    $result = $push->sendToEmployees([$testEmp], '🔔 Test Notifikasi', 'Ini pesan test dari sistem adf_system.', ['type' => 'test']);
    echo "Result: " . json_encode($result) . "\n";
}

echo "\nDone.\n";
