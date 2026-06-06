<?php
// Ultra-simple diagnostic - no dependencies whatsoever
echo "<h1>PWF Buyers Diagnostic</h1>";
echo "<p>Current time: " . date('Y-m-d H:i:s') . "</p>";
echo "<p>Error reporting: " . error_reporting() . "</p>";
echo "<p>Display errors: " . (ini_get('display_errors') ? 'ON' : 'OFF') . "</p>";

echo "<hr><h2>Step 1: Config Load</h2>";
try {
    require_once __DIR__ . '/config/config.php';
    echo "<p style='color:green'>✓ config.php loaded</p>";
    echo "<p>DB_HOST: " . DB_HOST . "</p>";
    echo "<p>DB_USER: " . DB_USER . "</p>";
    echo "<p>DB_NAME: " . DB_NAME . "</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>✗ config.php failed: " . $e->getMessage() . "</p>";
    exit;
}

echo "<hr><h2>Step 2: Auth Check</h2>";
try {
    require_once __DIR__ . '/includes/auth.php';
    $auth = new Auth();
    echo "<p style='color:green'>✓ Auth class loaded</p>";
    
    if ($auth->isLoggedIn()) {
        echo "<p style='color:green'>✓ User is logged in</p>";
        $user = $auth->getCurrentUser();
        echo "<p>User: " . ($user['username'] ?? 'Unknown') . "</p>";
    } else {
        echo "<p style='color:orange'>⚠ User is NOT logged in - would redirect</p>";
    }
} catch (Throwable $e) {
    echo "<p style='color:red'>✗ Auth failed: " . $e->getMessage() . "</p>";
}

echo "<hr><h2>Step 3: PWF Database Connection</h2>";
try {
    $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') === false);
    $pwfDb = $isProduction ? 'adfb2574_pwf' : 'adf_pwf';
    echo "<p>Using database: $pwfDb (Production: " . ($isProduction ? 'YES' : 'NO') . ")</p>";
    
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $pwfDb . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "<p style='color:green'>✓ PDO connection successful</p>";
    
    // Check if tables exist
    $tables = $pdo->query("SHOW TABLES LIKE 'pwf_buyers'")->fetchAll();
    if (count($tables) > 0) {
        echo "<p style='color:green'>✓ pwf_buyers table exists</p>";
    } else {
        echo "<p style='color:orange'>⚠ pwf_buyers table NOT FOUND</p>";
    }
    
    // Try a simple query
    $result = $pdo->query("SELECT COUNT(*) FROM pwf_buyers")->fetchColumn();
    echo "<p style='color:green'>✓ Query successful - " . $result . " buyers</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>✗ DB connection failed: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<hr><h2>Step 4: Try Loading buyers.php</h2>";
echo "<p><a href='buyers.php' target='_blank'>Open buyers.php in new tab</a></p>";
?>
