<?php
/**
 * PWF Management - Password Change
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../db-helper.php';

// Check access
$isOwner = isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['owner', 'developer']);
if (!$isOwner) {
    http_response_code(403);
    echo 'Access Denied';
    exit;
}

$masterPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$msg = '';
$msgType = 'success';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $msg = 'Semua field harus diisi';
        $msgType = 'error';
    } elseif (strlen($newPassword) < 6) {
        $msg = 'Password minimal 6 karakter';
        $msgType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
        $msg = 'Password baru tidak cocok';
        $msgType = 'error';
    } else {
        // Get current user
        $userStmt = $masterPdo->prepare('SELECT password FROM users WHERE id=?');
        $userStmt->execute([$_SESSION['user_id']]);
        $user = $userStmt->fetch();
        
        if (!$user) {
            $msg = 'User tidak ditemukan';
            $msgType = 'error';
        } elseif (!password_verify($currentPassword, $user['password'])) {
            $msg = 'Password lama tidak sesuai';
            $msgType = 'error';
        } else {
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            $updateStmt = $masterPdo->prepare('UPDATE users SET password=?, updated_at=NOW() WHERE id=?');
            if ($updateStmt->execute([$hashedPassword, $_SESSION['user_id']])) {
                $msg = 'Password berhasil diubah!';
                $msgType = 'success';
            } else {
                $msg = 'Gagal mengubah password';
                $msgType = 'error';
            }
        }
    }
}

require_once __DIR__ . '/../layout.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Ganti Password - PWF Management</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f3f0; color: #1c1511; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; }
        .back-link { display: inline-block; margin-bottom: 20px; color: #B8860B; text-decoration: none; font-size: 14px; }
        .back-link:hover { text-decoration: underline; }
        .card { background: white; border-radius: 12px; padding: 30px; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
        .card h1 { font-size: 24px; margin-bottom: 8px; display: flex; align-items: center; gap: 12px; }
        .card h1 i { color: #B8860B; font-size: 28px; }
        .card p { color: #666; margin-bottom: 24px; font-size: 14px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #666; text-transform: uppercase; letter-spacing: .4px; }
        input[type="password"] { width: 100%; padding: 10px 12px; border: 1px solid #E7E5E4; border-radius: 6px; font-size: 14px; }
        input[type="password"]:focus { outline: none; border-color: #B8860B; }
        .form-hint { font-size: 12px; color: #999; margin-top: 4px; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 13px; }
        .alert.success { background: #F0FDF4; color: #166534; border: 1px solid #DCFCE7; }
        .alert.error { background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; }
        .button-group { display: flex; gap: 10px; margin-top: 30px; }
        button { padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; }
        .btn-primary { background: #B8860B; color: white; }
        .btn-primary:hover { background: #9D6F0A; }
        .btn-secondary { background: #F5F3F0; color: #1c1511; border: 1px solid #E7E5E4; }
        .btn-secondary:hover { background: #EAE8E5; }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link">← Kembali ke PWF Management</a>
        
        <div class="card">
            <h1><i class="bi bi-key-fill"></i>Ganti Password</h1>
            <p>Ubah password login Anda untuk keamanan yang lebih baik</p>
            
            <?php if ($msg): ?>
                <div class="alert <?= $msgType ?>">
                    <?= htmlspecialchars($msg) ?>
                </div>
            <?php endif; ?>
            
            <form method="post">
                <div class="form-group">
                    <label>Password Lama</label>
                    <input type="password" name="current_password" required>
                    <div class="form-hint">Masukkan password Anda saat ini</div>
                </div>
                
                <div class="form-group">
                    <label>Password Baru</label>
                    <input type="password" name="new_password" required minlength="6">
                    <div class="form-hint">Minimal 6 karakter</div>
                </div>
                
                <div class="form-group">
                    <label>Konfirmasi Password Baru</label>
                    <input type="password" name="confirm_password" required minlength="6">
                    <div class="form-hint">Ketik ulang password baru</div>
                </div>
                
                <div class="button-group">
                    <button type="submit" class="btn-primary">Ubah Password</button>
                    <a href="index.php" class="btn-secondary" style="text-decoration: none; display: flex; align-items: center;">Batal</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
