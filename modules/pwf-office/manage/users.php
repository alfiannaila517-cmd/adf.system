<?php
/**
 * PWF Management - User Management
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../db-helper.php';

// Check access
$isOwner = isset($_SESSION['role']) && in_array($_SESSION['role'], ['owner', 'developer']);
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

// Get users
$users = $masterPdo->query("
    SELECT id, username, email, full_name, is_active, created_at 
    FROM users 
    ORDER BY id DESC
")->fetchAll();

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';
    
    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        
        if (empty($username) || empty($email) || empty($password)) {
            $msg = 'Username, email, dan password harus diisi';
            $msgType = 'error';
        } elseif (strlen($password) < 6) {
            $msg = 'Password minimal 6 karakter';
            $msgType = 'error';
        } else {
            $check = $masterPdo->prepare('SELECT id FROM users WHERE username=? OR email=?');
            $check->execute([$username, $email]);
            if ($check->fetch()) {
                $msg = 'Username atau email sudah terdaftar';
                $msgType = 'error';
            } else {
                $hashed = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $masterPdo->prepare('INSERT INTO users (username, email, password, full_name, is_active, created_at, updated_at) VALUES (?,?,?,?,1,NOW(),NOW())');
                if ($stmt->execute([$username, $email, $hashed, $fullName])) {
                    $msg = 'User berhasil dibuat!';
                    $msgType = 'success';
                    header('Refresh: 2');
                } else {
                    $msg = 'Gagal membuat user';
                    $msgType = 'error';
                }
            }
        }
    }
    elseif ($action === 'toggle_active') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 0);
        $stmt = $masterPdo->prepare('UPDATE users SET is_active=?, updated_at=NOW() WHERE id=?');
        if ($stmt->execute([$isActive, $userId])) {
            $msg = $isActive ? 'User diaktifkan!' : 'User dinonaktifkan!';
            $msgType = 'success';
            header('Refresh: 1');
        }
    }
    elseif ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newPassword = 'Password123'; // Default password
        $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $masterPdo->prepare('UPDATE users SET password=?, updated_at=NOW() WHERE id=?');
        if ($stmt->execute([$hashed, $userId])) {
            $msg = 'Password direset ke: Password123 (silakan minta user menggantinya)';
            $msgType = 'success';
        }
    }
}

require_once __DIR__ . '/../layout.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Kelola User - PWF Management</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f3f0; color: #1c1511; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .back-link { display: inline-block; margin-bottom: 20px; color: #B8860B; text-decoration: none; font-size: 14px; }
        .header { margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 24px; display: flex; align-items: center; gap: 12px; }
        .header h1 i { color: #B8860B; }
        .btn-new { padding: 10px 20px; background: #B8860B; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .btn-new:hover { background: #9D6F0A; }
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); backdrop-filter: blur(2px); z-index: 9000; align-items: center; justify-content: center; }
        .modal.open { display: flex; }
        .modal-box { background: white; border-radius: 12px; width: min(500px, 96vw); max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 80px rgba(0,0,0,.25); }
        .modal-header { padding: 16px 22px; border-bottom: 1px solid #E7E5E4; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h2 { font-size: 16px; }
        .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #999; }
        .modal-body { padding: 22px; }
        .form-group { margin-bottom: 16px; }
        label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: #666; text-transform: uppercase; letter-spacing: .4px; }
        input[type="text"], input[type="email"], input[type="password"] { width: 100%; padding: 10px 12px; border: 1px solid #E7E5E4; border-radius: 6px; font-size: 13px; }
        input:focus { outline: none; border-color: #B8860B; }
        .button-group { display: flex; gap: 10px; margin-top: 20px; }
        button { padding: 10px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
        .btn-primary { background: #B8860B; color: white; }
        .btn-primary:hover { background: #9D6F0A; }
        .btn-secondary { background: #F5F3F0; color: #1c1511; border: 1px solid #E7E5E4; }
        .btn-secondary:hover { background: #EAE8E5; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 13px; }
        .alert.success { background: #F0FDF4; color: #166534; border: 1px solid #DCFCE7; }
        .alert.error { background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
        th { background: #F5F3F0; padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #666; text-transform: uppercase; letter-spacing: .4px; }
        td { padding: 12px 16px; border-bottom: 1px solid #E7E5E4; font-size: 13px; }
        tr:last-child td { border-bottom: none; }
        .username { font-weight: 600; color: #1c1511; }
        .status { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .status.active { background: #F0FDF4; color: #166534; }
        .status.inactive { background: #FEF2F2; color: #991B1B; }
        .action-btn { padding: 6px 12px; border: 1px solid #E7E5E4; background: white; border-radius: 4px; cursor: pointer; font-size: 11px; margin-right: 4px; }
        .action-btn:hover { background: #F5F3F0; }
        .action-btn.danger { color: #991B1B; border-color: #FECACA; }
        .empty-state { text-align: center; padding: 40px; color: #999; }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link">← Kembali ke PWF Management</a>
        
        <div class="header">
            <h1><i class="bi bi-person-badge"></i>Kelola User</h1>
            <button class="btn-new" onclick="document.getElementById('createModal').classList.add('open')">+ Buat User Baru</button>
        </div>
        
        <?php if ($msg): ?>
            <div class="alert <?= $msgType ?>">
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>
        
        <?php if (empty($users)): ?>
            <div class="empty-state">
                <p>Belum ada user terdaftar</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Nama Lengkap</th>
                        <th>Status</th>
                        <th>Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td class="username"><?= htmlspecialchars($user['username']) ?></td>
                            <td><?= htmlspecialchars($user['email']) ?></td>
                            <td><?= htmlspecialchars($user['full_name'] ?? '—') ?></td>
                            <td>
                                <span class="status <?= $user['is_active'] ? 'active' : 'inactive' ?>">
                                    <?= $user['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                                </span>
                            </td>
                            <td><?= date('d M Y', strtotime($user['created_at'])) ?></td>
                            <td>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="_action" value="toggle_active">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <input type="hidden" name="is_active" value="<?= $user['is_active'] ? 0 : 1 ?>">
                                    <button type="submit" class="action-btn">
                                        <?= $user['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>
                                    </button>
                                </form>
                                <form method="post" style="display: inline;" onsubmit="return confirm('Reset password user ini?');">
                                    <input type="hidden" name="_action" value="reset_password">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" class="action-btn danger">Reset Pass</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <!-- Modal Buat User -->
    <div class="modal" id="createModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2>Buat User Baru</h2>
                <button class="modal-close" onclick="document.getElementById('createModal').classList.remove('open')">×</button>
            </div>
            <div class="modal-body">
                <form method="post">
                    <input type="hidden" name="_action" value="create">
                    
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" minlength="6" required>
                        <div style="font-size: 11px; color: #999; margin-top: 4px;">Minimal 6 karakter</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Nama Lengkap</label>
                        <input type="text" name="full_name">
                    </div>
                    
                    <div class="button-group">
                        <button type="submit" class="btn-primary">Buat User</button>
                        <button type="button" class="btn-secondary" onclick="document.getElementById('createModal').classList.remove('open')">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
