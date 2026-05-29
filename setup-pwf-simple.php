<?php
/**
 * PWF PO SYSTEM - SIMPLE SETUP (Direct DB creation, no API)
 */

ob_start();
define('APP_ACCESS', true);
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/config.php';

$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

$dbHost = 'localhost';
$dbUser = $isProduction ? 'adfb2574_adfsystem' : 'root';
$dbPass = $isProduction ? '@Nnoc2025' : '';
$pwfDbName = $isProduction ? 'adfb2574_pwf_po_system' : 'pwf_po_system';

echo "<!DOCTYPE html>
<html><head>
    <title>PWF System Setup</title>
    <style>
        body { font-family: Segoe UI, Arial; margin: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 3px solid #4CAF50; padding-bottom: 10px; }
        .step { margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #2196F3; }
        .step h3 { margin-top: 0; color: #2196F3; }
        .ok { color: #4CAF50; font-weight: bold; }
        .error { color: #f44336; font-weight: bold; }
        .info { color: #2196F3; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        table td { padding: 8px; border-bottom: 1px solid #ddd; }
        .success-box { background: #c8e6c9; border: 2px solid #4CAF50; padding: 15px; margin: 20px 0; border-radius: 4px; }
        pre { background: #f0f0f0; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
    </style>
</head><body>
<div class='container'>
<h1>🚀 PWF PO System - Simple Setup</h1>";

echo "<div class='step'><h3>Environment</h3>";
echo "<table>";
echo "<tr><td><strong>Mode:</strong></td><td><span class='info'>" . ($isProduction ? 'PRODUCTION' : 'LOCAL') . "</span></td></tr>";
echo "<tr><td><strong>Database:</strong></td><td><code>$pwfDbName</code></td></tr>";
echo "<tr><td><strong>User:</strong></td><td><code>$dbUser</code></td></tr>";
echo "</table></div>";

// STEP 1: Create Database
echo "<div class='step'><h3>STEP 1: Creating PWF Database</h3>";
try {
    $pdo = new PDO("mysql:host=$dbHost", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS \`$pwfDbName\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "<p><span class='ok'>✅ Database created: $pwfDbName</span></p>";
} catch (Exception $e) {
    echo "<p><span class='error'>❌ Failed: " . $e->getMessage() . "</span></p>";
}
echo "</div>";

// STEP 2: Create Tables
echo "<div class='step'><h3>STEP 2: Creating Tables</h3>";
try {
    $pwfDb = new PDO("mysql:host=$dbHost;dbname=$pwfDbName;charset=utf8mb4", $dbUser, $dbPass);
    $pwfDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $tables = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(150),
            email VARCHAR(100),
            role VARCHAR(50) DEFAULT 'staff',
            is_active TINYINT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            contact_person VARCHAR(100),
            email VARCHAR(100),
            phone VARCHAR(20),
            address TEXT,
            is_active TINYINT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_code VARCHAR(50) UNIQUE NOT NULL,
            product_name VARCHAR(150) NOT NULL,
            unit_price DECIMAL(12,2),
            unit VARCHAR(20),
            is_active TINYINT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS purchase_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            po_number VARCHAR(50) UNIQUE NOT NULL,
            supplier_id INT NOT NULL,
            po_date DATE NOT NULL,
            delivery_date DATE,
            status ENUM('draft','pending','approved','received','cancelled') DEFAULT 'draft',
            total_amount DECIMAL(15,2),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS po_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            po_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL,
            unit_price DECIMAL(12,2),
            subtotal DECIMAL(15,2),
            FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(50) UNIQUE NOT NULL,
            po_id INT NOT NULL,
            invoice_date DATE,
            due_date DATE,
            total_amount DECIMAL(15,2),
            paid_amount DECIMAL(15,2) DEFAULT 0,
            status ENUM('draft','sent','paid','cancelled') DEFAULT 'draft',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (po_id) REFERENCES purchase_orders(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];
    
    $tableNames = [];
    foreach ($tables as $sql) {
        $pwfDb->exec($sql);
        if (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $matches)) {
            $tableNames[] = $matches[1];
        }
    }
    
    echo "<p><span class='ok'>✅ Tables created: " . implode(', ', $tableNames) . "</span></p>";
} catch (Exception $e) {
    echo "<p><span class='error'>❌ Failed: " . $e->getMessage() . "</span></p>";
}
echo "</div>";

// STEP 3: Copy Config
echo "<div class='step'><h3>STEP 3: Setting Config File</h3>";
try {
    $srcConfig = __DIR__ . '/config-pwf-clean.php';
    $dstDir = __DIR__ . '/pwf-po-system/config';
    $dstConfig = $dstDir . '/config.php';
    
    if (!is_dir($dstDir)) {
        mkdir($dstDir, 0755, true);
    }
    
    if (file_exists($srcConfig)) {
        copy($srcConfig, $dstConfig);
        echo "<p><span class='ok'>✅ Config copied to pwf-po-system/config/config.php</span></p>";
    } else {
        echo "<p><span class='error'>⚠️ config-pwf-clean.php not found</span></p>";
    }
} catch (Exception $e) {
    echo "<p><span class='error'>❌ Failed: " . $e->getMessage() . "</span></p>";
}
echo "</div>";

// STEP 4: Test Connection
echo "<div class='step'><h3>STEP 4: Test Database Connection</h3>";
try {
    $test = new PDO("mysql:host=$dbHost;dbname=$pwfDbName;charset=utf8mb4", $dbUser, $dbPass);
    $result = $test->query("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema='$pwfDbName'")->fetch();
    $tableCount = $result['cnt'];
    echo "<p><span class='ok'>✅ Connection OK! Tables: $tableCount</span></p>";
} catch (Exception $e) {
    echo "<p><span class='error'>❌ Connection failed: " . $e->getMessage() . "</span></p>";
}
echo "</div>";

// Summary
echo "<div class='success-box'>";
echo "<h2>✅ PWF System Ready!</h2>";
echo "<p>Database: <code>$pwfDbName</code></p>";
echo "<p>Location: <code>" . (__DIR__ . '/pwf-po-system') . "</code></p>";
echo "<p>Tinggal customize modules & add addon domain nanti!</p>";
echo "</div>";

echo "</div></body></html>";
ob_end_flush();
?>
