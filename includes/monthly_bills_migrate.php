<?php

/**
 * Lazily creates monthly_bills + bill_payments tables for businesses whose DB
 * doesn't have them yet (e.g. bens-cafe, eaat-meet - only narayana-hotel had
 * them set up manually via sql/setup-monthly-bills.sql).
 */
if (!defined('APP_ACCESS')) exit;

function ensureMonthlyBillsTables($db)
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = $db->getConnection();

    $check = $pdo->query("SHOW TABLES LIKE 'monthly_bills'");
    if ($check->rowCount() === 0) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `monthly_bills` (
              `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `bill_code` varchar(20) NOT NULL UNIQUE,
              `division_id` int(11) DEFAULT NULL,
              `category_id` int(11) DEFAULT NULL,
              `bill_name` varchar(100) NOT NULL,
              `customer_name` varchar(150) DEFAULT NULL,
              `bill_month` date NOT NULL,
              `amount` decimal(12,2) NOT NULL,
              `due_date` date DEFAULT NULL,
              `status` enum('pending','partial','paid','cancelled') DEFAULT 'pending',
              `paid_amount` decimal(12,2) DEFAULT 0.00,
              `payment_method` varchar(50) DEFAULT NULL,
              `cash_account_id_source` int(11) DEFAULT NULL,
              `notes` text,
              `is_recurring` tinyint(1) DEFAULT 0,
              `created_by` int(11),
              `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_bill_month` (`bill_month`),
              KEY `idx_status` (`status`),
              KEY `idx_division_id` (`division_id`),
              KEY `idx_category_id` (`category_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // monthly_bills created before customer_name existed (e.g. narayana-hotel) needs it added separately
    $col = $pdo->query("SHOW COLUMNS FROM monthly_bills LIKE 'customer_name'");
    if ($col->rowCount() === 0) {
        $pdo->exec("ALTER TABLE monthly_bills ADD COLUMN `customer_name` varchar(150) DEFAULT NULL AFTER `bill_name`");
    }

    $check2 = $pdo->query("SHOW TABLES LIKE 'bill_payments'");
    if ($check2->rowCount() === 0) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `bill_payments` (
              `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `bill_id` int(11) NOT NULL,
              `payment_date` datetime NOT NULL,
              `amount` decimal(12,2) NOT NULL,
              `payment_method` varchar(50) DEFAULT NULL,
              `cash_account_id` int(11) DEFAULT NULL,
              `reference_number` varchar(50) DEFAULT NULL,
              `synced_to_cashbook` tinyint(1) DEFAULT 0,
              `cashbook_id` int(11) DEFAULT NULL,
              `notes` text,
              `created_by` int(11),
              `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (`bill_id`) REFERENCES `monthly_bills`(`id`) ON DELETE CASCADE,
              KEY `idx_bill_id` (`bill_id`),
              KEY `idx_synced_to_cashbook` (`synced_to_cashbook`),
              KEY `idx_payment_date` (`payment_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
