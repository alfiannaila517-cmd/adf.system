<?php
/**
 * Reset Developer Password - Run once then delete
 */

define('APP_ACCESS', true);
require_once 'config/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "=== Developer Password Reset ===\n\n";
    
    // Get developer role ID
    $roleStmt = $pdo->query("SELECT id FROM roles WHERE role_code = 'developer' LIMIT 1");
    if ($roleStmt->rowCount() === 0) {
        echo "❌ Developer role tidak ditemukan. Buat dulu di database.\n";
        exit;
    }
    $devRoleId = $roleStmt->fetchColumn();
    
    // Find all developers
    $devStmt = $pdo->query("
        SELECT id, username, full_name 
        FROM users 
        WHERE role_id = $devRoleId 
        ORDER BY id
    ");
    $developers = $devStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($developers)) {
        echo "❌ Tidak ada developer user yang ditemukan.\n\n";
        echo "📝 Buat developer user baru:\n";
        echo "Username: developer\n";
        echo "Password: (akan di-set ke: dev123456)\n\n";
        
        // Create default developer
        $hashedPassword = password_hash('dev123456', PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            INSERT INTO users (username, password, full_name, email, role_id, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute(['developer', $hashedPassword, 'System Developer', 'dev@localhost', $devRoleId]);
        
        echo "✅ Developer user berhasil dibuat!\n";
        echo "   Username: developer\n";
        echo "   Password: dev123456\n\n";
    } else {
        echo "Developer users ditemukan:\n\n";
        foreach ($developers as $i => $dev) {
            echo ($i + 1) . ". ID: {$dev['id']}, Username: {$dev['username']}, Nama: {$dev['full_name']}\n";
        }
        echo "\n";
        
        // Reset all developer passwords
        $newPassword = 'dev123456';
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        
        foreach ($developers as $dev) {
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashedPassword, $dev['id']]);
            echo "✅ Password reset untuk: {$dev['username']}\n";
        }
        
        echo "\n";
        echo "📝 Credentials untuk developer:\n";
        echo "   Password baru: dev123456\n";
    }
    
    echo "\n🔐 Setelah login, silakan ubah password di Developer Panel > Settings\n";
    echo "⚠️  File ini bisa dihapus setelah selesai.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
