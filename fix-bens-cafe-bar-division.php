<?php

/**
 * One-off fix: deactivate the "Bar" division for Bens Cafe.
 * Bens Cafe is a cafe business and should not have a "Bar" division — it was
 * added by mistake. It already has 16 linked cash_book transactions, so it is
 * deactivated (is_active = 0) instead of hard-deleted to preserve historical
 * financial records/reports.
 * Usage: https://adfsystem.online/fix-bens-cafe-bar-division.php?token=adf-deploy-2025-secure
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

$businessSlug = 'bens-cafe';
$businessConfigFile = __DIR__ . '/config/businesses/' . $businessSlug . '.php';

if (!file_exists($businessConfigFile)) {
    echo "ERROR: No config file for business slug '{$businessSlug}' at {$businessConfigFile}\n";
    exit;
}

$businessConfig = require $businessConfigFile;
$localDbName = $businessConfig['database'];

// Replicate Database class's local->hosting name mapping
$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false &&
    strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);
$dbMapping = [
    'adf_system' => 'adfb2574_adf',
    'adf_narayana_hotel' => 'adfb2574_narayana_hotel',
    'adf_benscafe' => 'adfb2574_Adf_Bens',
    'adf_demo' => 'adfb2574_demo',
    'adf_cqc' => 'adfb2574_cqc',
    'adf_sunsea' => 'adfb2574_sunsea'
];
$businessDbName = $localDbName;
if ($isProduction) {
    if (isset($dbMapping[$localDbName])) {
        $businessDbName = $dbMapping[$localDbName];
    } elseif (strpos($localDbName, 'adf_') === 0) {
        $businessDbName = 'adfb2574_' . str_replace('adf_', '', $localDbName);
    }
}

echo "=== FIX: Deactivate 'Bar' division for Bens Cafe ===\n";
echo "Environment: " . ($isProduction ? 'PRODUCTION' : 'LOCAL') . "\n";
echo "Resolved business DB name: {$businessDbName}\n\n";

try {
    $bizDb = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . $businessDbName . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    echo "ERROR connecting to business DB: " . $e->getMessage() . "\n";
    exit;
}

$stmt = $bizDb->prepare("SELECT id, division_code, division_name, is_active FROM divisions WHERE division_name = 'Bar'");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "No 'Bar' division found. Nothing to do.\n";
    exit;
}

foreach ($rows as $row) {
    echo "Found division id={$row['id']} code={$row['division_code']} is_active={$row['is_active']}\n";

    $countStmt = $bizDb->prepare("SELECT COUNT(*) FROM cash_book WHERE division_id = ?");
    $countStmt->execute([$row['id']]);
    $trxCount = (int)$countStmt->fetchColumn();
    echo "  Linked cash_book transactions: {$trxCount}\n";

    if ((int)$row['is_active'] === 0) {
        echo "  Already inactive, skipping.\n";
        continue;
    }

    $upd = $bizDb->prepare("UPDATE divisions SET is_active = 0 WHERE id = ?");
    $upd->execute([$row['id']]);
    echo "  -> Deactivated (is_active = 0). Historical transactions kept intact.\n";
}

echo "\nDone.\n";
