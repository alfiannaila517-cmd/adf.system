<?php
/**
 * Sunsea - Setup Tickets Table
 */
require_once 'config/config.php';
require_once 'config/database.php';

try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=adf_sunsea;charset=utf8mb4",
        'root',
        '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $sql = <<<SQL
    CREATE TABLE IF NOT EXISTS `tickets` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `ticket_code`     VARCHAR(20) UNIQUE,
        `ticket_type`     VARCHAR(50) NOT NULL COMMENT 'express_bahari, ferry, pesawat_susi',
        `ticket_name`     VARCHAR(150) NOT NULL,
        `description`     TEXT,
        `unit`            VARCHAR(30) DEFAULT 'pax',
        `price_cost`      DECIMAL(15,2) DEFAULT 0.00,
        `price_sell`      DECIMAL(15,2) DEFAULT 0.00,
        `notes`           TEXT,
        `is_active`       TINYINT(1) DEFAULT 1,
        `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_ticket_active (`is_active`),
        INDEX idx_ticket_type (`ticket_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    SQL;

    $pdo->exec($sql);
    echo "<h2 style='color:green;'>✓ Tabel tickets berhasil dibuat di database adf_sunsea</h2>";

    // Verify table exists
    $check = $pdo->query("SHOW TABLES LIKE 'tickets'")->fetchAll();
    if (!empty($check)) {
        echo "<p style='color:green;'>✓ Verifikasi: Tabel tickets sudah ada</p>";
        
        $columns = $pdo->query("DESCRIBE tickets")->fetchAll();
        echo "<h3>Struktur Tabel:</h3>";
        echo "<table border='1' cellpadding='8'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        foreach ($columns as $col) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($col['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($col['Key'] ?? '-') . "</td>";
            echo "<td>" . htmlspecialchars($col['Default'] ?? '-') . "</td>";
            echo "<td>" . htmlspecialchars($col['Extra'] ?? '-') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

} catch (Exception $e) {
    echo "<h2 style='color:red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</h2>";
    echo "<p>Silakan jalankan query SQL manual atau periksa koneksi database.</p>";
}
