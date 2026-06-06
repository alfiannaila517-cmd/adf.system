<?php
// Simple diagnostic - no dependencies
echo "<!DOCTYPE html><html><head><title>Test</title></head><body>";
echo "<h1>Server Test</h1>";
echo "<p>PHP is working: " . phpversion() . "</p>";
echo "<p>Time: " . date('Y-m-d H:i:s') . "</p>";

// Test error reporting
echo "<p>display_errors: " . (ini_get('display_errors') ? 'ON' : 'OFF') . "</p>";
echo "<p>error_reporting: " . error_reporting() . "</p>";

// Test bootstrap
echo "<hr><h2>Testing Bootstrap...</h2>";
try {
    require_once __DIR__ . '/modules/pwf-office/_bootstrap.php';
    echo "<p style='color: green;'>✓ Bootstrap loaded successfully</p>";
    echo "<p>Current user: " . ($currentUser['username'] ?? 'Unknown') . "</p>";
} catch (Throwable $e) {
    echo "<p style='color: red;'>✗ Bootstrap failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

// Test db-helper
echo "<hr><h2>Testing DB Helper...</h2>";
try {
    require_once __DIR__ . '/modules/pwf-office/db-helper.php';
    echo "<p style='color: green;'>✓ DB Helper loaded</p>";
    $pdo = getPwfOfficePdo();
    echo "<p style='color: green;'>✓ PDO connection successful</p>";
} catch (Throwable $e) {
    echo "<p style='color: red;'>✗ DB Helper failed: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</body></html>";
?>
