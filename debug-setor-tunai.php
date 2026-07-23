<?php

/**
 * One-off diagnostic: inspect Setor Tunai (cash_transfers) state for a business
 * and whether the linked cash_book rows / balances exist.
 * Usage: https://adfsystem.online/debug-setor-tunai.php?token=adf-deploy-2025-secure&business=bens-cafe
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
require_once __DIR__ . '/includes/business_helper.php';

$businessSlug = $_GET['business'] ?? 'bens-cafe';
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

echo "=== DIAGNOSTIC: Setor Tunai for business slug '{$businessSlug}' ===\n";
echo "Environment: " . ($isProduction ? 'PRODUCTION' : 'LOCAL') . "\n";
echo "Local DB name (config): {$localDbName}\n";
echo "Resolved business DB name: {$businessDbName}\n\n";

try {
    $masterDb = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "Master DB connected: " . DB_NAME . "\n";
} catch (Exception $e) {
    echo "ERROR connecting to master DB: " . $e->getMessage() . "\n";
    exit;
}

$numericBusinessId = getNumericBusinessId($businessSlug);
echo "Numeric business_id resolved: " . var_export($numericBusinessId, true) . "\n\n";

// cash_accounts for this business
try {
    $stmt = $masterDb->prepare("SELECT id, account_name, account_type, current_balance, is_active FROM cash_accounts WHERE business_id = ? ORDER BY account_type, id");
    $stmt->execute([$numericBusinessId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "--- cash_accounts (business_id={$numericBusinessId}) ---\n";
    if (!$accounts) {
        echo "  (none found!)\n";
    }
    foreach ($accounts as $a) {
        echo "  id={$a['id']} name={$a['account_name']} type={$a['account_type']} balance={$a['current_balance']} active={$a['is_active']}\n";
    }
    echo "\n";
} catch (Exception $e) {
    echo "ERROR reading cash_accounts: " . $e->getMessage() . "\n\n";
}

// cash_transfers for this business
try {
    $hasCashBookIdCol = false;
    try {
        $masterDb->query("SELECT cash_book_id FROM cash_transfers LIMIT 1");
        $hasCashBookIdCol = true;
    } catch (Exception $e) {
    }
    echo "cash_transfers.cash_book_id column exists: " . ($hasCashBookIdCol ? 'YES' : 'NO') . "\n";

    $stmt = $masterDb->prepare("SELECT * FROM cash_transfers WHERE business_id = ? ORDER BY id DESC");
    $stmt->execute([$numericBusinessId]);
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "--- cash_transfers (business_id={$numericBusinessId}) : " . count($transfers) . " rows ---\n";
    foreach ($transfers as $t) {
        echo "  id={$t['id']} amount={$t['amount']} date={$t['transfer_date']} cash_acc={$t['cash_account_id']} bank_acc={$t['bank_account_id']} cash_book_id=" . ($t['cash_book_id'] ?? 'NULL') . " archived={$t['is_archived']}\n";
    }
    echo "\n";
} catch (Exception $e) {
    echo "ERROR reading cash_transfers: " . $e->getMessage() . "\n\n";
}

// business DB cash_book rows with source_type=cash_transfer
try {
    $bizDb = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . $businessDbName . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "Business DB connected: {$businessDbName}\n";

    $hasSourceTypeCol = false;
    try {
        $bizDb->query("SELECT source_type FROM cash_book LIMIT 1");
        $hasSourceTypeCol = true;
    } catch (Exception $e) {
    }
    echo "cash_book.source_type column exists: " . ($hasSourceTypeCol ? 'YES' : 'NO') . "\n";

    $stmt = $bizDb->query("SELECT id, transaction_date, transaction_type, amount, source_type, cash_account_id, description FROM cash_book WHERE source_type = 'cash_transfer' ORDER BY id DESC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "--- cash_book rows with source_type='cash_transfer': " . count($rows) . " rows ---\n";
    foreach ($rows as $r) {
        echo "  id={$r['id']} date={$r['transaction_date']} type={$r['transaction_type']} amount={$r['amount']} cash_acc={$r['cash_account_id']} desc={$r['description']}\n";
    }
} catch (Exception $e) {
    echo "ERROR reading business DB cash_book: " . $e->getMessage() . "\n";
}
