<?php
/**
 * Fix sunsea owner business_access
 * Run once then delete
 */
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // Show all owner/admin users and their business_access
    $users = $pdo->query("SELECT id, username, role_id, business_access, is_active, 
        (SELECT role_code FROM roles WHERE id = users.role_id LIMIT 1) as role_name
        FROM users 
        ORDER BY id")->fetchAll();

    echo "<h2>All Users</h2><table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Username</th><th>Role</th><th>business_access</th><th>Active</th></tr>";
    foreach ($users as $u) {
        echo "<tr><td>{$u['id']}</td><td>{$u['username']}</td><td>{$u['role_name']}</td><td>{$u['business_access']}</td><td>{$u['is_active']}</td></tr>";
    }
    echo "</table>";

    // Show businesses table
    $bizRows = $pdo->query("SELECT id, business_code, business_name, database_name FROM businesses ORDER BY id")->fetchAll();
    echo "<h2>Businesses</h2><table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Code</th><th>Name</th><th>Database</th></tr>";
    foreach ($bizRows as $b) {
        echo "<tr><td>{$b['id']}</td><td>{$b['business_code']}</td><td>{$b['business_name']}</td><td>{$b['database_name']}</td></tr>";
    }
    echo "</table>";

    // Show user_business_assignment
    $assignments = $pdo->query("SELECT uba.user_id, u.username, uba.business_id, b.business_name, b.business_code 
        FROM user_business_assignment uba 
        JOIN users u ON uba.user_id = u.id 
        JOIN businesses b ON uba.business_id = b.id 
        ORDER BY uba.user_id, uba.id")->fetchAll();
    echo "<h2>User-Business Assignments</h2><table border='1' cellpadding='5'>";
    echo "<tr><th>User ID</th><th>Username</th><th>Business ID</th><th>Business Name</th><th>Code</th></tr>";
    foreach ($assignments as $a) {
        echo "<tr><td>{$a['user_id']}</td><td>{$a['username']}</td><td>{$a['business_id']}</td><td>{$a['business_name']}</td><td>{$a['business_code']}</td></tr>";
    }
    echo "</table>";

    echo "<h2>Fix Actions (run from URL with ?fix=1)</h2>";
    
    if (isset($_GET['set_user_id']) && isset($_GET['biz'])) {
        $uid = (int)$_GET['set_user_id'];
        $biz = trim($_GET['biz']);
        $pdo->prepare("UPDATE users SET business_access = ? WHERE id = ?")->execute([$biz, $uid]);
        echo "<p style='color:green'>Updated user $uid business_access to: $biz</p>";
    }

} catch (Exception $e) {
    echo "Error: " . htmlspecialchars($e->getMessage());
}
