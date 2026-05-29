<?php
/**
 * PWF Database Connection Test
 * Simple script to test if PWF database is accessible
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

echo "<h2>PWF Database Connection Test</h2>";
echo "<p><strong>Environment:</strong> " . ($isProduction ? "PRODUCTION" : "LOCAL") . "</p>";

if ($isProduction) {
    echo "<p><strong>Host:</strong> localhost</p>";
    echo "<p><strong>Database:</strong> adfb2574_pwf_po_system</p>";
    echo "<p><strong>User:</strong> adfb2574_adfsystem</p>";
    
    try {
        $pdo = new PDO(
            'mysql:host=localhost;dbname=adfb2574_pwf_po_system;charset=utf8mb4',
            'adfb2574_adfsystem',
            '@Nnoc2025',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        
        echo "<p style='color: green;'><strong>✅ Database Connection SUCCESS!</strong></p>";
        
        // Try to get tables
        $result = $pdo->query("SHOW TABLES");
        $tables = $result->fetchAll();
        
        echo "<p><strong>Tables found:</strong> " . count($tables) . "</p>";
        if (!empty($tables)) {
            echo "<ul>";
            foreach ($tables as $table) {
                $tableName = array_values($table)[0];
                echo "<li>$tableName</li>";
            }
            echo "</ul>";
        } else {
            echo "<p style='color: orange;'>⚠️ Database is empty (no tables). Need to import schema.</p>";
        }
        
    } catch (PDOException $e) {
        echo "<p style='color: red;'><strong>❌ Database Connection FAILED:</strong></p>";
        echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    }
} else {
    echo "<p style='color: blue;'><strong>Local Mode - Test not applicable</strong></p>";
}

echo "<hr>";
echo "<p><a href='javascript:history.back()'>← Back</a></p>";
?>
