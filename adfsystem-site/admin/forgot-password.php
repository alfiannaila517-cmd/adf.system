<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/smtp-mailer.php';
adf_admin_session_start();

if (adf_admin_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $user = $email !== '' ? adf_users_find_by_email($email) : null;
        if ($user !== null) {
            $token = adf_admin_create_reset_token($user['id']);
            $resetUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
                . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/reset-password.php?token=' . $token;
            $subject = 'Reset Password Admin - ADF System';
            $body = "Ada permintaan reset password untuk akun admin ADF System ({$user['username']}).\n\n"
                . "Klik link berikut untuk membuat password baru (berlaku 30 menit):\n{$resetUrl}\n\n"
                . "Jika Anda tidak meminta ini, abaikan email ini.\n";
            $mailOk = adf_smtp_send($user['email'], $subject, $body);
            if (!$mailOk) {
                error_log('forgot-password mail failed: ' . adf_mail_last_error());
            }
        }
        // Always show the same message, whether or not the email matched (avoid leaking registered emails).
        $sent = true;
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
<title>Lupa Password — Admin ADF System</title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-body">
<div class="admin-login-wrap">
    <form class="admin-login-card" method="post" autocomplete="off">
        <h1>Lupa Password</h1>
        <p class="admin-login-sub">Masukkan email yang terdaftar, kami kirim link reset password ke email tersebut.</p>
        <?php if ($sent): ?>
            <div class="admin-alert admin-alert-success">Jika email terdaftar, link reset password sudah dikirim.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <label>Email
            <input type="email" name="email" required autofocus>
        </label>
        <button type="submit" class="btn btn-primary admin-login-btn">Kirim Link Reset</button>
        <a href="login.php" class="admin-back-link">&larr; Kembali ke login</a>
    </form>
</div>
</body>
</html>
