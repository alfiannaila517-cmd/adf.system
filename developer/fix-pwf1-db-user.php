<?php
/**
 * Emergency Fix: Reset PWF1 Database User Credentials
 * Align PWF1 with master database user permissions
 * 
 * This script will:
 * 1. Connect as MySQL root
 * 2. Grant all permissions to master user for PWF1 database
 * 3. Or reset PWF1 user password to match master
 */

// IMPORTANT: This is an emergency tool - requires manual execution
$isLocal = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || 
            strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false);

$dbHost = 'localhost';
$dbName = 'adfb2574_adfb2574_pwf1';
$masterUser = 'adfb2574_adfsystem';
$masterPass = '@Nnoc2025';
$pwf1User = 'adfb2574_adfb2574_pwf1';

// Production hosting credentials
$rootUser = 'adfb2574_adfsystem';  // Use master user as fallback
$rootPass = '@Nnoc2025';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Fix PWF1 Database User</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 4px; }
        .success { background: #d4edda; border-color: #c3e6cb; color: #155724; }
        .error { background: #f8d7da; border-color: #f5c6cb; color: #721c24; }
        .info { background: #d1ecf1; border-color: #bee5eb; color: #0c5460; }
        .warning { background: #fff3cd; border-color: #ffeaa7; color: #856404; }
        code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }
        button { padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #0056b3; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔧 Emergency Fix: PWF1 Database User</h1>
    
    <div class="section info">
        <h3>ℹ️ Problem</h3>
        <p>PWF1 database exists but has a separate MySQL user <code><?php echo $pwf1User; ?></code> with unknown password.</p>
        <p>System tries to connect with master user <code><?php echo $masterUser; ?></code> which doesn't have permissions.</p>
    </div>
    
    <div class="section warning">
        <h3>⚠️ Solution</h3>
        <p>This script will grant all permissions on the PWF1 database to the master user.</p>
        <p><strong>Required:</strong> SSH/cPanel root access or contact your hosting provider.</p>
    </div>
    
    <div class="section">
        <h3>🔐 Option 1: Manual MySQL Command (via cPanel Terminal)</h3>
        <p>Run these commands in cPanel Terminal or via SSH:</p>
        <pre style="background: #000; color: #0f0; padding: 15px; border-radius: 4px; overflow-x: auto;">
mysql -u root -p

# If prompted for password, use root password from hosting

# Then paste these commands:
GRANT ALL PRIVILEGES ON `<?php echo $dbName; ?>`.* TO '<?php echo $masterUser; ?>'@'localhost' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON `<?php echo $dbName; ?>`.* TO '<?php echo $masterUser; ?>'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;

# Verify:
SHOW GRANTS FOR '<?php echo $masterUser; ?>'@'localhost';
        </pre>
    </div>
    
    <div class="section">
        <h3>🔐 Option 2: Reset PWF1 User Password</h3>
        <p>If you have cPanel access, use MySQL Password Manager to reset:</p>
        <ul>
            <li>MySQL User: <code><?php echo $pwf1User; ?></code></li>
            <li>New Password: <code><?php echo $masterPass; ?></code></li>
        </ul>
        <p>Then run:</p>
        <pre style="background: #000; color: #0f0; padding: 15px; border-radius: 4px;">
mysql -u root -p
SET PASSWORD FOR '<?php echo $pwf1User; ?>'@'localhost' = PASSWORD('<?php echo $masterPass; ?>');
FLUSH PRIVILEGES;
        </pre>
    </div>
    
    <div class="section">
        <h3>📋 Option 3: Test Current Connection</h3>
        <p>Test if connections work:</p>
        <form method="POST">
            <input type="hidden" name="action" value="test">
            <button type="submit">🧪 Test Current Connection</button>
        </form>
    </div>
    
    <?php
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if ($_POST['action'] === 'test') {
            echo '<div class="section">';
            echo '<h3>🧪 Connection Test Results</h3>';
            
            // Test 1: Master user connection
            echo '<h4>Test 1: Master User Connection</h4>';
            try {
                $pdo = new PDO(
                    "mysql:host={$dbHost};dbname={$dbName}",
                    $masterUser,
                    $masterPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                echo '<div class="section success">✅ SUCCESS: Master user can connect to PWF1 database!</div>';
                
                // Check tables
                $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                echo '<div class="section info">📊 Database has ' . count($tables) . ' tables</div>';
                
            } catch (PDOException $e) {
                echo '<div class="section error">❌ FAIL: ' . htmlspecialchars($e->getMessage()) . '</div>';
                echo '<p>This confirms the master user cannot access PWF1 database yet.</p>';
            }
            
            // Test 2: Check if PWF1 user exists and its permissions
            echo '<h4>Test 2: Check PWF1 User Permissions</h4>';
            try {
                $pdo = new PDO(
                    "mysql:host={$dbHost};dbname=mysql",
                    $masterUser,
                    $masterPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                
                $stmt = $pdo->prepare("SELECT * FROM user WHERE User = ?");
                $stmt->execute([$pwf1User]);
                $user = $stmt->fetch();
                
                if ($user) {
                    echo '<div class="section info">✅ PWF1 user exists in MySQL</div>';
                    echo '<p>User: <code>' . htmlspecialchars($pwf1User) . '</code></p>';
                } else {
                    echo '<div class="section warning">⚠️ PWF1 user not found in MySQL - may have been deleted</div>';
                }
            } catch (PDOException $e) {
                echo '<div class="section warning">⚠️ Cannot check user grants: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            
            echo '</div>';
        }
    }
    ?>
    
    <div class="section info">
        <h3>📞 After Fix: Contact Support</h3>
        <p>Once you've run the fix, refresh the debug tool to verify:</p>
        <p><a href="debug-business-status.php?business=pwf1">📊 Check PWF1 Status</a></p>
    </div>
</div>
</body>
</html>
