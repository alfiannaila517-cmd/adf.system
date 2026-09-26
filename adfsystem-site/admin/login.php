<?php
require_once __DIR__ . '/../includes/admin-auth.php';

adf_admin_session_start();

if (adf_admin_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        if (adf_admin_attempt_login($username, $password)) {
            header('Location: index.php');
            exit;
        }
        $error = 'Username atau password salah.';
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
<title>Login Admin — ADF System</title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-body">
<div class="admin-login-wrap">
    <form class="admin-login-card" method="post" autocomplete="off">
        <h1>Login Admin</h1>
        <p class="admin-login-sub">Masuk untuk mengelola tampilan website ADF System.</p>
        <?php if ($error): ?>
            <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <label>Username
            <input type="text" name="username" required autofocus>
        </label>
        <label>Password
            <input type="password" name="password" required>
        </label>
        <button type="submit" class="btn btn-primary admin-login-btn">Masuk</button>
        <a href="forgot-password.php" class="admin-back-link">Lupa password?</a>
        <a href="../index.php" class="admin-back-link">&larr; Kembali ke website</a>
    </form>
</div>
</body>
</html>
