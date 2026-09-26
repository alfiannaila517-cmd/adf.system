<?php
require_once __DIR__ . '/../includes/admin-auth.php';
adf_admin_session_start();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$saved = false;
$error = '';

if ($token === '' || !adf_admin_verify_reset_token($token)) {
    $error = 'Link reset password tidak valid atau sudah kedaluwarsa. Silakan minta link baru.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } else {
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        if (strlen($newPassword) < 8) {
            $error = 'Password baru minimal 8 karakter.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Konfirmasi password baru tidak cocok.';
        } else {
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            if (adf_admin_update_password_hash($newHash)) {
                adf_admin_clear_reset_token();
                $saved = true;
            } else {
                $error = 'Gagal menyimpan password baru. Periksa izin tulis file includes/admin-config.php.';
            }
        }
    }
}

$csrf = adf_admin_csrf_token();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Reset Password — Admin ADF System</title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-body">
<div class="admin-login-wrap">
    <form class="admin-login-card" method="post" autocomplete="off">
        <h1>Reset Password</h1>
        <p class="admin-login-sub">Buat password baru untuk login admin.</p>
        <?php if ($saved): ?>
            <div class="admin-alert admin-alert-success">Password berhasil diubah. Silakan login dengan password baru.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (!$saved && $token !== '' && adf_admin_verify_reset_token($token)): ?>
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <label>Password Baru
            <input type="password" name="new_password" required minlength="8" autocomplete="new-password" autofocus>
        </label>
        <label>Konfirmasi Password Baru
            <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
        </label>
        <button type="submit" class="btn btn-primary admin-login-btn">Simpan Password Baru</button>
        <?php endif; ?>
        <a href="login.php" class="admin-back-link">&larr; Kembali ke login</a>
    </form>
</div>
</body>
</html>
