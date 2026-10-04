<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/security.php';

adf_admin_session_start();

if (adf_admin_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
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
            if (adf_sec_device_is_trusted($user['id']) || adf_sec_otp_bypassed()) {
                // Perangkat ini sudah pernah diverifikasi lewat email (≤ 30 hari), atau mode darurat aktif.
                adf_sec_clear_fails($username);
                adf_admin_complete_login($user);
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
            <a href="forgot-password.php" class="admin-back-link">Lupa password?</a>
            <a href="../index.php" class="admin-back-link">&larr; Kembali ke website</a>
        </form>
    </div>
</body>

</html>
