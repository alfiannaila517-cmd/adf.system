<?php

/**
 * One-off: create the cash_transfers table on the production master DB.
 * Usage: https://adfsystem.online/create-cash-transfers-table.php?token=adf-deploy-2025-secure
 * Safe to run multiple times (CREATE TABLE IF NOT EXISTS).
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

require_once __DIR__ . '/config/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $sql = "
    CREATE TABLE IF NOT EXISTS `cash_transfers` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `business_id` INT(11) NOT NULL,
      `cash_account_id` INT(11) NOT NULL COMMENT 'Akun kas tunai yang dikurangi',
      `bank_account_id` INT(11) NOT NULL COMMENT 'Rekening bank yang ditambah',
      `amount` DECIMAL(15,2) NOT NULL,
      `transfer_date` DATE NOT NULL,
      `transfer_time` TIME NOT NULL,
      `reference_number` VARCHAR(50) COMMENT 'Nomor referensi',
      `description` TEXT,
      `created_by` INT(11),
      `is_archived` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Status arsip',
      `archived_at` DATETIME NULL COMMENT 'Waktu arsip',
      `archived_by` INT(11) NULL COMMENT 'Siapa yang mengarsip',
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `business_id` (`business_id`),
      KEY `cash_account_id` (`cash_account_id`),
      KEY `bank_account_id` (`bank_account_id`),
      KEY `transfer_date` (`transfer_date`),
      KEY `is_archived` (`is_archived`),
      FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE RESTRICT,
      FOREIGN KEY (`bank_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $pdo->exec($sql);

    echo "OK: cash_transfers table created (or already existed) on " . DB_NAME . "\n";

    $check = $pdo->query("SHOW TABLES LIKE 'cash_transfers'")->fetch();
    echo "Verify: " . ($check ? "table exists\n" : "table NOT found!\n");
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
