<?php
/**
 * Debug script untuk investigate user creation issue
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../_bootstrap.php';

$masterPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

echo "<pre style='background:#f5f5f5;padding:20px;border-radius:8px;font-family:monospace;'>";

// 1. Check PWF Business
echo "=== 1. PWF Business ===\n";
$pwfBiz = $masterPdo->query("SELECT id, business_name FROM businesses WHERE business_name LIKE '%PWF%' OR business_name LIKE '%Prapen%' LIMIT 1")->fetch();
if ($pwfBiz) {
    echo "✓ PWF Business found:\n";
    echo "  ID: {$pwfBiz['id']}\n";
    echo "  Name: {$pwfBiz['business_name']}\n";
} else {
    echo "✗ PWF Business NOT found! Check LIKE patterns\n";
    echo "Available businesses:\n";
    $all = $masterPdo->query("SELECT id, business_name FROM businesses")->fetchAll();
    foreach ($all as $b) {
        echo "  - {$b['id']}: {$b['business_name']}\n";
    }
}

// 2. Check users table structure
echo "\n=== 2. Users Table Structure ===\n";
$columns = $masterPdo->query("DESCRIBE users")->fetchAll();
echo "Columns:\n";
foreach ($columns as $col) {
    echo "  - {$col['Field']} ({$col['Type']}) " . ($col['Null'] === 'NO' ? 'NOT NULL' : 'NULLABLE') . "\n";
}

// 3. Check user_business_assignment table structure
echo "\n=== 3. User Business Assignment Table Structure ===\n";
$columns = $masterPdo->query("DESCRIBE user_business_assignment")->fetchAll();
echo "Columns:\n";
foreach ($columns as $col) {
    echo "  - {$col['Field']} ({$col['Type']}) " . ($col['Null'] === 'NO' ? 'NOT NULL' : 'NULLABLE') . "\n";
}

// 4. Check existing users
echo "\n=== 4. Existing Users in PWF ===\n";
if ($pwfBiz) {
    $pwfUsers = $masterPdo->query("
        SELECT u.id, u.username, u.email, u.full_name 
        FROM users u 
        INNER JOIN user_business_assignment uba ON u.id = uba.user_id 
        WHERE uba.business_id = {$pwfBiz['id']}
    ")->fetchAll();
    echo "Total: " . count($pwfUsers) . "\n";
    foreach ($pwfUsers as $u) {
        echo "  - {$u['username']} ({$u['email']}) - {$u['full_name']}\n";
    }
}

// 5. Test INSERT without assignment
echo "\n=== 5. Testing INSERT ===\n";
$testUser = 'testuser_' . time();
$testEmail = 'test_' . time() . '@test.com';
$testPass = password_hash('Test123', PASSWORD_BCRYPT);

$stmt = $masterPdo->prepare('
    INSERT INTO users (username, email, password, full_name, is_active, created_at, updated_at) 
    VALUES (?,?,?,?,1,NOW(),NOW())
');

if ($stmt->execute([$testUser, $testEmail, $testPass, 'Test User'])) {
    $userId = $masterPdo->lastInsertId();
    echo "✓ INSERT successful!\n";
    echo "  User ID: {$userId}\n";
    echo "  Username: {$testUser}\n";
    
    // Try to assign
    echo "\n=== 6. Testing ASSIGNMENT ===\n";
    if ($pwfBiz) {
        $assign = $masterPdo->prepare('
            INSERT INTO user_business_assignment (user_id, business_id, created_at) 
            VALUES (?,?,NOW())
        ');
        if ($assign->execute([$userId, $pwfBiz['id']])) {
            echo "✓ ASSIGNMENT successful!\n";
            
            // Cleanup
            $masterPdo->prepare('DELETE FROM user_business_assignment WHERE user_id=?')->execute([$userId]);
            $masterPdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
            echo "\n✓ Test data cleaned up\n";
        } else {
            $error = $assign->errorInfo();
            echo "✗ ASSIGNMENT failed:\n";
            echo "  Error: {$error[2]}\n";
            
            // Cleanup
            $masterPdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
        }
    }
} else {
    $error = $stmt->errorInfo();
    echo "✗ INSERT failed:\n";
    echo "  Error: {$error[2]}\n";
}

echo "</pre>";
