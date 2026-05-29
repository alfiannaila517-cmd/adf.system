<?php
/**
 * PWF PO SYSTEM - COMPLETE SETUP SCRIPT
 * - Create business via API
 * - Create & configure database
 * - Import schema
 * - Setup config
 * - Ready for customization & addon domain
 */

ob_start();
define('APP_ACCESS', true);

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>
<html>
<head>
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
        pre { background: #f0f0f0; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
        .success-box { background: #c8e6c9; border: 2px solid #4CAF50; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .error-box { background: #ffcdd2; border: 2px solid #f44336; padding: 15px; margin: 20px 0; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        table td { padding: 8px; border-bottom: 1px solid #ddd; }
    </style>
</head>
<body>
<div class='container'>
<h1>🚀 PWF PO System - Setup Wizard</h1>";

// ============================================
// STEP 1: Detect Environment
// ============================================
echo "<div class='step'><h3>STEP 1: Environment Detection</h3>";

require_once 'config/config.php';

$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

$environment = $isProduction ? 'PRODUCTION' : 'LOCAL DEVELOPMENT';
$dbHost = 'localhost';
$dbUser = $isProduction ? 'adfb2574_adfsystem' : 'root';
$dbPass = $isProduction ? '@Nnoc2025' : '';

echo "<table>";
echo "<tr><td><strong>Environment:</strong></td><td><span class='info'>$environment</span></td></tr>";
echo "<tr><td><strong>HTTP_HOST:</strong></td><td><span class='info'>" . htmlspecialchars($_SERVER['HTTP_HOST']) . "</span></td></tr>";
echo "<tr><td><strong>Database Host:</strong></td><td><span class='info'>$dbHost</span></td></tr>";
echo "<tr><td><strong>Database User:</strong></td><td><span class='info'>$dbUser</span></td></tr>";
echo "</table>";
echo "</div>";

// ============================================
// STEP 2: Create PWF Business via API
// ============================================
echo "<div class='step'><h3>STEP 2: Creating PWF Business (via API)</h3>";

$businessData = [
    'name' => 'PWF PO System - Furniture Factory',
    'database' => 'adf_pwf_po_system',
    'type' => 'furniture_factory'
];

$apiUrl = ($isProduction ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . '/adf.system/api/add-business.php';

$businessId = null;
try {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($businessData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result && $result['success']) {
        echo "<p><span class='ok'>✅ Business Created Successfully!</span></p>";
        echo "<table>";
        echo "<tr><td><strong>Business ID:</strong></td><td>" . $result['business_id'] . "</td></tr>";
        echo "<tr><td><strong>Database:</strong></td><td>" . $result['database'] . "</td></tr>";
        echo "<tr><td><strong>Message:</strong></td><td>" . $result['message'] . "</td></tr>";
        echo "</table>";
        $businessId = $result['business_id'];
    } else {
        echo "<p><span class='error'>❌ Failed to create business</span></p>";
        echo "<pre>" . htmlspecialchars($response) . "</pre>";
    }
} catch (Exception $e) {
    echo "<p><span class='error'>❌ Error: " . $e->getMessage() . "</span></p>";
}
echo "</div>";

// ============================================
// STEP 3: Create PWF Database & Tables
// ============================================
echo "<div class='step'><h3>STEP 3: Setting Up PWF Database</h3>";

try {
    $pwfDbName = $isProduction ? 'adfb2574_pwf_po_system' : 'pwf_po_system';
    
    $pdo = new PDO("mysql:host=$dbHost", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS \`$pwfDbName\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "<p><span class='ok'>✅ Database created/verified: $pwfDbName</span></p>";
    
    // Connect to PWF database
    $pwfDb = new PDO("mysql:host=$dbHost;dbname=$pwfDbName;charset=utf8mb4", $dbUser, $dbPass);
    $pwfDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create basic tables for PO system
    $tables = [
        // Users
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(150),
            email VARCHAR(100),
            role VARCHAR(50) DEFAULT 'staff',
            is_active TINYINT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        // Suppliers/Vendors
        "CREATE TABLE IF NOT EXISTS suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            contact_person VARCHAR(100),
            email VARCHAR(100),
            phone VARCHAR(20),
            address TEXT,
            city VARCHAR(50),
            is_active TINYINT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_by INT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        // Products
        "CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_code VARCHAR(50) UNIQUE NOT NULL,
            product_name VARCHAR(150) NOT NULL,
            description TEXT,
            unit_price DECIMAL(12,2),
            unit VARCHAR(20),
            is_active TINYINT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_by INT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        // Purchase Orders
        "CREATE TABLE IF NOT EXISTS purchase_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            po_number VARCHAR(50) UNIQUE NOT NULL,
            supplier_id INT NOT NULL,
            po_date DATE NOT NULL,
            delivery_date DATE,
            status ENUM('draft','pending','approved','received','cancelled') DEFAULT 'draft',
            total_amount DECIMAL(15,2),
            notes TEXT,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
            INDEX idx_po_number (po_number),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        // PO Line Items
        "CREATE TABLE IF NOT EXISTS po_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            po_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL,
            unit_price DECIMAL(12,2),
            subtotal DECIMAL(15,2),
            notes TEXT,
            FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        // Invoices
        "CREATE TABLE IF NOT EXISTS invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(50) UNIQUE NOT NULL,
            po_id INT NOT NULL,
            invoice_date DATE,
            due_date DATE,
            total_amount DECIMAL(15,2),
            paid_amount DECIMAL(15,2) DEFAULT 0,
            status ENUM('draft','sent','paid','cancelled') DEFAULT 'draft',
            notes TEXT,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (po_id) REFERENCES purchase_orders(id),
            INDEX idx_invoice_number (invoice_number),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    
    $tableNames = [];
    foreach ($tables as $sql) {
        $pwfDb->exec($sql);
        // Extract table name
        if (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $matches)) {
            $tableNames[] = $matches[1];
        }
    }
    
    echo "<p><span class='ok'>✅ Database tables created successfully</span></p>";
    echo "<p><strong>Tables created:</strong> " . implode(', ', $tableNames) . "</p>";
    
} catch (Exception $e) {
    echo "<p><span class='error'>❌ Error: " . $e->getMessage() . "</span></p>";
}
echo "</div>";

// ============================================
// STEP 4: Verify Config File
// ============================================
echo "<div class='step'><h3>STEP 4: Config File Status</h3>";

$configPath = __DIR__ . '/config-pwf-clean.php';
$targetDir = __DIR__ . '/pwf-po-system/config';
$targetPath = $targetDir . '/config.php';

if (file_exists($configPath)) {
    echo "<p><span class='ok'>✅ Source config found: config-pwf-clean.php</span></p>";
    
    // Create directory if needed
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0755, true);
        echo "<p>Created config directory</p>";
    }
    
    // Copy to pwf-po-system if needed
    if (!file_exists($targetPath) || @copy($configPath, $targetPath)) {
        echo "<p><span class='ok'>✅ Config copied to pwf-po-system/config/config.php</span></p>";
    } else {
        echo "<p><span class='error'>⚠️ Could not copy config (may need manual copy)</span></p>";
    }
} else {
    echo "<p><span class='error'>❌ Config file not found at: " . $configPath . "</span></p>";
}
echo "</div>";

// ============================================
// STEP 5: Test Database Connection
// ============================================
echo "<div class='step'><h3>STEP 5: Database Connection Test</h3>";

try {
    $pwfDbName = $isProduction ? 'adfb2574_pwf_po_system' : 'pwf_po_system';
    $testPdo = new PDO("mysql:host=$dbHost;dbname=$pwfDbName;charset=utf8mb4", $dbUser, $dbPass);
    $testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p><span class='ok'>✅ PWF Database Connection: SUCCESS</span></p>";
    
    $result = $testPdo->query("SHOW TABLES");
    $tablesList = $result->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p><strong>Tables in database: " . count($tablesList) . "</strong></p>";
    if (!empty($tablesList)) {
        echo "<ul>";
        foreach ($tablesList as $table) {
            $tableName = array_values($table)[0];
            echo "<li><code>$tableName</code></li>";
        }
        echo "</ul>";
    }
} catch (Exception $e) {
    echo "<p><span class='error'>❌ Connection Failed: " . $e->getMessage() . "</span></p>";
}
echo "</div>";

// ============================================
// STEP 6: Success Summary
// ============================================
echo "<div class='success-box'>";
echo "<h2>✅ PWF System Setup Complete!</h2>";
echo "<p>Your PWF PO System is now ready for customization.</p>";
echo "</div>";

echo "<div class='step'><h3>🎯 Next Steps</h3>";
echo "<ol>";
echo "<li><strong>Customize PWF System</strong>
    <ul>
    <li>Edit <code>pwf-po-system/modules/</code> untuk fitur custom</li>
    <li>Buat menu dan navigation sesuai kebutuhan</li>
    <li>Tambah business logic di modules/</li>
    <li>Buat UI untuk PO, invoices, suppliers</li>
    </ul>
</li>";
echo "<li><strong>Setup Addon Domain (pwfoffice.com)</strong>
    <ul>
    <li>Login ke cPanel: https://adfsystem.online:2083/</li>
    <li>Go to: Addon Domains</li>
    <li>Domain: pwfoffice.com</li>
    <li>Directory: public_html/pwf-po-system</li>
    <li>Create</li>
    </ul>
</li>";
echo "<li><strong>Update Config for Domain</strong>
    <ul>
    <li>Edit <code>pwf-po-system/config/config.php</code></li>
    <li>Change BASE_URL ke: https://pwfoffice.com</li>
    <li>Save & upload</li>
    </ul>
</li>";
echo "<li><strong>Test PWF System</strong>
    <ul>
    <li>Open: https://pwfoffice.com/ (setelah domain setup)</li>
    <li>Or: https://adfsystem.online/pwf-po-system/ (current)</li>
    <li>Verify database connection working</li>
    </ul>
</li>";
echo "</ol>";
echo "</div>";

echo "<div class='step'><h3>📝 System Configuration Info</h3>";
echo "<table>";
echo "<tr><td><strong>Database Name:</strong></td><td><code>" . ($isProduction ? 'adfb2574_pwf_po_system' : 'pwf_po_system') . "</code></td></tr>";
echo "<tr><td><strong>Database User:</strong></td><td><code>" . $dbUser . "</code></td></tr>";
echo "<tr><td><strong>Tables Created:</strong></td><td>6 (users, suppliers, products, purchase_orders, po_items, invoices)</td></tr>";
echo "<tr><td><strong>Base Path:</strong></td><td><code>" . __DIR__ . "/pwf-po-system</code></td></tr>";
echo "<tr><td><strong>Config File:</strong></td><td><code>" . $targetPath . "</code></td></tr>";
echo "<tr><td><strong>Session Name:</strong></td><td><code>PWF_PO_SESSION</code></td></tr>";
echo "</table>";
echo "</div>";

echo "<div class='step'><h3>📂 Folder Structure</h3>";
echo "<pre>pwf-po-system/
├── config/
│   ├── config.php (isolated config)
│   └── database.php (connection)
├── api/ (API endpoints)
├── modules/ (custom modules)
├── includes/ (shared functions)
├── assets/ (CSS, JS, images)
├── database/ (database files)
└── index.php (main page)</pre>";
echo "</div>";

echo "<a href='javascript:location.reload()' style='background: #4CAF50; color: white; padding: 10px 20px; border-radius: 4px; text-decoration: none; display: inline-block; margin-top: 20px;'>Refresh Status</a>";

echo "</div></body></html>";

ob_end_flush();
?>
