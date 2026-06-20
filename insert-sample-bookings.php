<?php

/**
 * Insert sample confirmed bookings for calendar testing
 */
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'modules/sunsea/db-helper.php';

$pdo = getSunseaConnection();

try {
    // Ensure customers table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `customers` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `code` VARCHAR(20) NOT NULL UNIQUE,
        `name` VARCHAR(150) NOT NULL,
        `type` ENUM('individual','group','corporate','travel_agent') DEFAULT 'individual',
        `email` VARCHAR(150),
        `phone` VARCHAR(30),
        `is_active` TINYINT(1) DEFAULT 1,
        INDEX idx_code (`code`),
        INDEX idx_name (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ensure booking_orders table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `booking_orders` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `booking_no` VARCHAR(30) NOT NULL UNIQUE,
        `customer_id` INT NOT NULL,
        `booking_mode` ENUM('paket','ecer') DEFAULT 'paket',
        `start_date` DATE NOT NULL,
        `end_date` DATE NOT NULL,
        `pax_count` SMALLINT DEFAULT 1,
        `status` ENUM('draft','confirmed','ongoing','completed','cancelled') DEFAULT 'draft',
        `cost_total` DECIMAL(15,2) DEFAULT 0.00,
        `sell_total` DECIMAL(15,2) DEFAULT 0.00,
        `notes` TEXT,
        `created_by` VARCHAR(100),
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE RESTRICT,
        INDEX idx_booking_dates (`start_date`, `end_date`),
        INDEX idx_booking_status (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Insert sample customer
    $pdo->prepare("INSERT IGNORE INTO customers (code, name, type, email, phone, is_active)
        VALUES (?, ?, ?, ?, ?, ?)")
        ->execute(['SS-CUST-001', 'Budi Santoso', 'individual', 'budi@email.com', '081234567890', 1]);

    // Get customer id
    $custId = $pdo->query("SELECT id FROM customers WHERE code='SS-CUST-001' LIMIT 1")->fetchColumn();

    if ($custId) {
        // Insert sample confirmed bookings
        $bookings = [
            ['SS-BOOK-2026-001', $custId, 'paket', '2026-06-22', '2026-06-25', 4, 'confirmed', 2000000, 3000000],
            ['SS-BOOK-2026-002', $custId, 'ecer', '2026-06-25', '2026-06-28', 2, 'confirmed', 1000000, 1500000],
            ['SS-BOOK-2026-003', $custId, 'paket', '2026-07-05', '2026-07-08', 3, 'confirmed', 1500000, 2250000],
        ];

        $stmt = $pdo->prepare("INSERT IGNORE INTO booking_orders 
            (booking_no, customer_id, booking_mode, start_date, end_date, pax_count, status, cost_total, sell_total, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        foreach ($bookings as $b) {
            $stmt->execute(array_merge($b, ['system']));
        }

        echo '<div style="padding:20px;background:#d1fae5;border:1px solid #6ee7b7;border-radius:8px;color:#047857;font-weight:600;">';
        echo '✓ Sample confirmed bookings inserted successfully!';
        echo '<br><a href="modules/sunsea/calendar.php" style="color:#047857;text-decoration:underline;">Buka Kalender Booking</a>';
        echo '</div>';
    } else {
        throw new Exception('Failed to get customer ID');
    }
} catch (Exception $e) {
    echo '<div style="padding:20px;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;color:#c33;font-weight:600;">';
    echo '⚠️ Error: ' . htmlspecialchars($e->getMessage());
    echo '</div>';
}
