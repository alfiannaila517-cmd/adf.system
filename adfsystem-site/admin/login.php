<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/google-oauth.php';

adf_admin_session_start();

if (adf_admin_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = (string) ($_SESSION['adf_login_error'] ?? '');
unset($_SESSION['adf_login_error']);
$googleOn = adf_google_config() !== null;
$notice = isset($_GET['expired']) ? 'Sesi verifikasi berakhir. Silakan login ulang.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } elseif (adf_sec_is_locked($username)) {
        $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam 15 menit.';
    } else {
        $user = adf_admin_check_password($username, $password);
        if ($user === null) {
            adf_sec_record_fail($username);
            $error = 'Username atau password salah.';
        } else {
            // Penghitung gagal baru di-reset setelah login benar-benar selesai (termasuk kode email),
            // supaya orang yang tahu password tetap tidak bisa menebak kode berulang-ulang.
            // Login dengan password (username ATAU email) SELALU minta kode verifikasi email,
            // kecuali mode darurat (file adf-otp-disabled.txt di folder home) sedang aktif.
            if (adf_sec_otp_bypassed()) {
                adf_sec_clear_fails($username);
                adf_admin_complete_login($user);
                adf_tg_security('⚠️', 'Login ADF Store TANPA kode (mode darurat aktif)', ['Akun' => (string) $user['username'], 'Catatan' => 'hapus adf-otp-disabled.txt bila sudah normal']);
                header('Location: index.php');
                exit;
            }
            session_regenerate_id(true);
            unset($_SESSION['adf_pending_login']);
            if (adf_sec_otp_send($user)) {
                header('Location: login-verify.php');
                exit;
            }
            unset($_SESSION['adf_pending_login']);
            $error = 'Password benar, tapi kode verifikasi gagal dikirim ke email. Coba lagi beberapa saat.';
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
    <title>Login Admin — ADF System</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/global-loader.css">
    <script src="../assets/js/global-loader.js"></script>
</head>

<body class="admin-body">
    <div class="admin-login-wrap">
        <form class="admin-login-card" method="post" autocomplete="off">
            <h1>Login Admin</h1>
            <p class="admin-login-sub">Pintu masuk ADF Store &amp; Developer Panel. Setelah password, kode verifikasi dikirim ke email Anda.</p>
            <?php if ($error): ?>
                <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php elseif ($notice): ?>
                <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($notice); ?></div>
            <?php endif; ?>
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <label>Username atau Email
                <input type="text" name="username" required autofocus autocomplete="username">
            </label>
            <label>Password
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <button type="submit" class="btn btn-primary admin-login-btn">Lanjut</button>
            <?php if ($googleOn): ?>
                <div style="display:flex;align-items:center;gap:8px;margin:12px 0 2px;font-size:11.5px;opacity:.55;"><span style="flex:1;height:1px;background:currentColor;opacity:.3;"></span>atau<span style="flex:1;height:1px;background:currentColor;opacity:.3;"></span></div>
                <a href="google-login.php" class="btn admin-login-btn" style="display:flex;align-items:center;justify-content:center;gap:9px;background:#fff !important;color:#1f1f1f !important;-webkit-text-fill-color:#1f1f1f;border:1px solid #dadce0;text-decoration:none;font-weight:600;">
                    <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
                    Masuk dengan Google
                </a>
            <?php endif; ?>
            <a href="forgot-password.php" class="admin-back-link">Lupa password?</a>
            <a href="../index.php" class="admin-back-link">&larr; Kembali ke website</a>
        </form>
    </div>
</body>

</html>
