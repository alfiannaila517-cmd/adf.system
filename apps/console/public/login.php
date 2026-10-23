<?php

/**
 * Masuk Console:
 *   - "Masuk dengan Google" (email Google = email user Console, tanpa kode — lihat src/Google.php), atau
 *   - username/email + password → kode verifikasi ke email (kecuali mode darurat).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

if (Auth::user()) {
    redirect('/');
}

$error = (string)($_SESSION['login_error'] ?? '');
unset($_SESSION['login_error']);
$notice = isset($_GET['expired']) ? 'Sesi verifikasi berakhir. Silakan masuk lagi.'
    : (isset($_GET['reset']) ? 'Password berhasil diganti. Silakan masuk.' : (isset($_GET['google']) ? 'Login Google belum diatur di server.' : ''));

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

$google = Google::config() !== null;
View::guestStart('Masuk');
?>
<div class="card">
    <h2 style="font-size:15px;text-transform:none;letter-spacing:0;color:var(--ink);margin:0 0 4px">Masuk ke Console</h2>
    <p class="muted" style="margin:0 0 16px;font-size:12.5px">Pusat kendali bisnis, user, dan tagihan.</p>
    <?php if ($notice): ?><div class="alert info"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>

    <?php if ($google): ?>
        <a class="gbtn" href="/google-login.php">
            <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.3 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.3 0-9.7-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
            Masuk dengan Google
        </a>
        <div class="or">atau pakai password</div>
    <?php endif; ?>

    <form method="post" autocomplete="on" onsubmit="var b=this.querySelector('button[type=submit]');if(b.disabled)return false;b.disabled=true;b.textContent='Memeriksa…'">
        <?= csrf_field() ?>
        <div class="field"><label for="u">Username atau email</label><input id="u" name="username" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required value="<?= e($_POST['username'] ?? '') ?>"></div>
        <div class="field"><label for="p">Password</label>
            <div class="pw"><input id="p" name="password" type="password" autocomplete="current-password" required>
                <button type="button" onclick="var i=document.getElementById('p');i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'Lihat':'Sembunyi'">Lihat</button></div></div>
        <button class="btn" type="submit" style="width:100%;margin-top:4px">Masuk</button>
    </form>
    <p style="text-align:center;margin:14px 0 0;font-size:12.5px"><a href="/forgot.php">Lupa password?</a></p>
</div>
<p class="muted" style="text-align:center;font-size:11.5px;margin:12px 0 0"><?= $google ? 'Google: email akun Google harus sama dengan email user Console.<br>' : '' ?>Password: kode verifikasi dikirim ke email Anda.</p>
<?php View::guestEnd();
