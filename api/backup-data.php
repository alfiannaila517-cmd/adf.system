<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/business_helper.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

// Only admin or developer can backup
if (!$auth->hasRole('admin') && !$auth->hasRole('developer')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak. Hanya admin atau developer yang bisa backup data.']);
    exit;
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Get database name from config
    $dbName = DB_NAME;

    // Identify the active business so the backup is labelled correctly and can
    // also pull in the records that live in the shared master DB (cash_accounts,
    // inter-business stock transfers, etc.) instead of only this business' own DB.
    $activeBusinessConfig = getActiveBusinessConfig();
    $activeBusinessSlug = (string)($activeBusinessConfig['business_id'] ?? '');
    $activeBusinessName = (string)($activeBusinessConfig['name'] ?? 'Business');
    $safeBusinessSlug = preg_replace('/[^a-z0-9_-]+/i', '_', $activeBusinessSlug ?: 'business');

    // Create backup filename with timestamp
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = "backup_{$safeBusinessSlug}_{$timestamp}.sql";
    $backupPath = __DIR__ . '/../backups/' . $backupFile;
    
    // Create backups directory if not exists
    if (!file_exists(__DIR__ . '/../backups')) {
        mkdir(__DIR__ . '/../backups', 0755, true);
    }
    
    // Get all tables
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    
    // Open file for writing
    $handle = fopen($backupPath, 'w');
    
    // Write SQL header
    fwrite($handle, "-- {$activeBusinessName} Database Backup\n");
    fwrite($handle, "-- Backup Date: " . date('Y-m-d H:i:s') . "\n");
    fwrite($handle, "-- Database: {$dbName}\n\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");
    
    // Export each table
    foreach ($tables as $table) {
        // Get table structure
        $result = $conn->query("SHOW CREATE TABLE `{$table}`");
        $row = $result->fetch(PDO::FETCH_NUM);
        
        fwrite($handle, "-- --------------------------------------------------------\n");
        fwrite($handle, "-- Table structure for `{$table}`\n");
        fwrite($handle, "-- --------------------------------------------------------\n\n");
        fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
        fwrite($handle, $row[1] . ";\n\n");
        
        // Get table data
        $result = $conn->query("SELECT * FROM `{$table}`");
        $rowCount = $result->rowCount();
        
        if ($rowCount > 0) {
            fwrite($handle, "-- Dumping data for table `{$table}`\n\n");
            
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                $columns = array_keys($row);
                $values = array_values($row);
                
                // Escape values
                $escapedValues = array_map(function($value) use ($conn) {
                    if ($value === null) {
                        return 'NULL';
                    }
                    return $conn->quote($value);
                }, $values);
                
                $sql = "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $escapedValues) . ");\n";
                fwrite($handle, $sql);
            }
            
            fwrite($handle, "\n");
        }
    }

    // This business' own database only holds its own tables. Data that belongs
    // to this business but is stored in the SHARED master DB (bank/cash
    // accounts, balance transactions, inter-business stock transfers, menu
    // permissions) is exported here too, scoped strictly to this business, so
    // the backup truly covers "everything" tied to it.
    try {
        $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : $dbName;
        if ($masterDbName !== $dbName) {
            $masterPdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . $masterDbName . ';charset=' . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );

            $businessNumericId = getNumericBusinessId($activeBusinessSlug);

            fwrite($handle, "-- ============================================================\n");
            fwrite($handle, "-- Shared master DB records scoped to this business ({$activeBusinessName})\n");
            fwrite($handle, "-- Master DB: {$masterDbName}\n");
            fwrite($handle, "-- ============================================================\n\n");

            $writeScopedRows = function (string $label, string $sql, array $params) use ($masterPdo, $handle) {
                try {
                    $stmt = $masterPdo->prepare($sql);
                    $stmt->execute($params);
                    $rows = $stmt->fetchAll();
                    if (!$rows) {
                        return;
                    }
                    fwrite($handle, "-- Master DB rows: {$label} ({" . count($rows) . "} rows)\n\n");
                    foreach ($rows as $row) {
                        $columns = array_keys($row);
                        $escapedValues = array_map(function ($value) use ($masterPdo) {
                            return $value === null ? 'NULL' : $masterPdo->quote($value);
                        }, array_values($row));
                        $table = $label;
                        fwrite($handle, "-- (master) INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $escapedValues) . ");\n");
                    }
                    fwrite($handle, "\n");
                } catch (Throwable $e) {
                }
            };

            if ($businessNumericId) {
                $writeScopedRows('businesses', "SELECT * FROM businesses WHERE id = ?", [$businessNumericId]);
                $writeScopedRows('cash_accounts', "SELECT * FROM cash_accounts WHERE business_id = ?", [$businessNumericId]);
                $writeScopedRows(
                    'cash_account_transactions',
                    "SELECT t.* FROM cash_account_transactions t
                     INNER JOIN cash_accounts a ON a.id = t.cash_account_id
                     WHERE a.business_id = ?",
                    [$businessNumericId]
                );
                $writeScopedRows(
                    'user_menu_permissions',
                    "SELECT * FROM user_menu_permissions WHERE business_id = ?",
                    [$businessNumericId]
                );
            }
            if ($activeBusinessSlug !== '') {
                $writeScopedRows(
                    'business_inter_stock_transfers',
                    "SELECT * FROM business_inter_stock_transfers WHERE source_business_slug = ? OR target_business_slug = ?",
                    [$activeBusinessSlug, $activeBusinessSlug]
                );
            }
        }
    } catch (Throwable $e) {
        error_log('backup-data master DB export skipped: ' . $e->getMessage());
    }

    fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($handle);
    
    // Return download URL
    echo json_encode([
        'success' => true,
        'message' => 'Backup berhasil dibuat!',
        'filename' => $backupFile,
        'download_url' => BASE_URL . '/api/download-backup.php?file=' . urlencode($backupFile),
        'file_size' => round(filesize($backupPath) / 1024, 2) . ' KB'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error saat backup: ' . $e->getMessage()
    ]);
}
