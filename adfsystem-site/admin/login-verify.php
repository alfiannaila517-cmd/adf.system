<?php
/**
 * Langkah 2 login: kode verifikasi 6 digit yang dikirim ke email admin.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/security.php';

adf_admin_session_start();

if (adf_admin_is_logged_in()) {
    header('Location: index.php');
    exit;
}
$pending = $_SESSION['adf_pending_login'] ?? null;
$pendingUser = $pending ? adf_users_find_by_id((string) $pending['user_id']) : null;
if (!$pending || !$pendingUser) {
    unset($_SESSION['adf_pending_login']);
    header('Location: login.php?expired=1');
    exit;
}

$error = '';
$info = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } elseif (($_POST['action'] ?? '') === 'resend') {
        $wait = ADF_SEC_OTP_RESEND_GAP - (time() - (int) $pending['sent_at']);
        if ($wait > 0) {
            $error = "Tunggu {$wait} detik sebelum kirim ulang.";
        } elseif ((int) $pending['resends'] >= 3) {
            unset($_SESSION['adf_pending_login']);
            header('Location: login.php?expired=1');
            exit;
        } elseif (adf_sec_otp_send($pendingUser)) {
            $info = 'Kode baru sudah dikirim.';
        } else {
            $error = 'Gagal mengirim email. Coba lagi beberapa saat.';
        }
    } else {
        [$ok, $msg] = adf_sec_otp_verify(preg_replace('/\D/', '', (string) ($_POST['code'] ?? '')));
        if ($ok) {
            adf_sec_clear_fails((string) $pendingUser['username']);
            adf_admin_complete_login($pendingUser);
            adf_sec_login_alert($pendingUser, 'verifikasi email');
            header('Location: index.php');
            exit;
        }
        // Kode salah ikut dihitung di batas percobaan login (5x / 15 menit).
        adf_sec_record_fail((string) $pendingUser['username']);
        if (adf_sec_is_locked((string) $pendingUser['username'])) {
            unset($_SESSION['adf_pending_login']);
        }
        if (empty($_SESSION['adf_pending_login'])) {
            header('Location: login.php?expired=1');
            exit;
        }
        $error = $msg;
    }
}

$csrf = adf_admin_csrf_token();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require __DIR__ . '/../includes/pwa-head.php'; ?>
    <meta name="robots" content="noindex, nofollow">
    <title>Verifikasi Login — ADF System</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .otp-input { font-size: 24px !important; letter-spacing: 10px; text-align: center; font-weight: 700; }
        .otp-row { display: flex; align-items: center; gap: 8px; font-size: 12.5px; margin: 4px 0 2px; }
        .otp-row input { width: auto !important; margin: 0; }
        .otp-resend { background: none; border: 0; color: inherit; opacity: .75; text-decoration: underline; cursor: pointer; font-size: 12.5px; padding: 0; }
    </style>
</head>

<body class="admin-body">
    <div class="admin-login-wrap">
        <div class="admin-login-card">
            <h1>Verifikasi Email</h1>
            <p class="admin-login-sub">Kode 6 digit sudah dikirim ke <strong><?php echo htmlspecialchars(adf_sec_mask_email((string) $pendingUser['email'])); ?></strong>. Berlaku 10 menit.</p>
            <?php if ($error): ?>
                <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php elseif ($info): ?>
                <div class="admin-alert admin-alert-success"><?php echo htmlspecialchars($info); ?></div>
            <?php endif; ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <label>Kode Verifikasi
                    <input type="text" name="code" class="otp-input" inputmode="numeric" pattern="\d{6}" maxlength="6" required autofocus autocomplete="one-time-code">
                </label>
                <button type="submit" class="btn btn-primary admin-login-btn">Verifikasi &amp; Masuk</button>
            </form>
            <form method="post" style="margin-top:10px;text-align:center;">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="resend">
                <button type="submit" class="otp-resend">Tidak menerima kode? Kirim ulang</button>
            </form>
            <a href="login.php" class="admin-back-link">&larr; Ganti akun</a>
        </div>
    </div>
</body>

</html>
