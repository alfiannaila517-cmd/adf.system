<?php

/** Langkah 1 login: username/email + password → kirim kode verifikasi ke email (kecuali mode darurat). */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

if (Auth::user()) {
    redirect('/');
}

$error = '';
$notice = isset($_GET['expired']) ? 'Sesi verifikasi berakhir. Silakan login ulang.' : (isset($_GET['reset']) ? 'Password berhasil diganti. Silakan login.' : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (!csrf_ok()) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } elseif (Auth::isLocked($username)) {
        $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam 15 menit.';
    } elseif (!($user = Auth::checkPassword($username, $password))) {
        Auth::recordFail($username);
        $error = 'Username atau password salah.';
    } elseif (Auth::otpBypassed()) {
        // Mode darurat (file secrets/console-otp-disabled) — hanya pemilik akses server yang bisa mengaktifkan
        Auth::complete($user, 'password (mode darurat tanpa kode)');
        Telegram::security('⚠️', 'Login ADF Console TANPA kode (mode darurat aktif)', ['Akun' => $user['username'], 'Catatan' => 'hapus secrets/console-otp-disabled bila email sudah normal']);
        redirect('/');
    } else {
        Auth::rotateSession();
        if (Auth::otpSend($user)) {
            redirect('/verify.php');
        }
        unset($_SESSION['console_pending']);
        $error = 'Password benar, tapi kode verifikasi gagal dikirim ke email. Coba lagi beberapa saat.';
        error_log('Console OTP mail: ' . Mailer::lastError());
    }
}

View::guestStart('Masuk');
?>
<div class="card">
    <?php if ($notice): ?><div class="alert info"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="on" onsubmit="var b=this.querySelector('button');if(b.disabled)return false;b.disabled=true;b.textContent='Memeriksa…'">
        <?= csrf_field() ?>
        <div class="field"><label for="u">Username atau email</label><input id="u" name="username" autocomplete="username" autocapitalize="none" required autofocus value="<?= e($_POST['username'] ?? '') ?>"></div>
        <div class="field"><label for="p">Password</label><input id="p" name="password" type="password" autocomplete="current-password" required></div>
        <button class="btn" type="submit" style="width:100%">Masuk</button>
    </form>
    <p style="text-align:center;margin:14px 0 0;font-size:14px"><a href="/forgot.php">Lupa password?</a></p>
</div>
<p class="muted" style="text-align:center;font-size:12px">Setelah password benar, kode verifikasi dikirim ke email Anda.</p>
<?php View::guestEnd();
