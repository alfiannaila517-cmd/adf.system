<?php

/** Langkah 2 login: kode 6 digit dari email (kirim ulang tiap 60 detik). */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

if (Auth::user()) {
    redirect('/');
}
$pending = $_SESSION['console_pending'] ?? null;
if (!$pending) {
    redirect('/login.php?expired=1');
}
$user = Auth::findUserById((int)$pending['user_id']);
if (!$user) {
    unset($_SESSION['console_pending']);
    redirect('/login.php?expired=1');
}

$error = '';
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } elseif (($_POST['action'] ?? '') === 'resend') {
        $wait = Auth::OTP_RESEND_GAP - (time() - (int)$pending['sent_at']);
        if ($wait > 0) {
            $error = "Tunggu {$wait} detik sebelum meminta kode baru.";
        } elseif (Auth::otpSend($user)) {
            $notice = 'Kode baru sudah dikirim.';
        } else {
            $error = 'Gagal mengirim kode. Coba lagi beberapa saat.';
        }
    } else {
        [$ok, $msg] = Auth::otpVerify(preg_replace('/\D/', '', (string)($_POST['code'] ?? '')));
        if ($ok) {
            Auth::complete($user, 'password + kode email');
            redirect('/');
        }
        if (empty($_SESSION['console_pending'])) {
            Auth::recordFail((string)$user['username']);
            redirect('/login.php?expired=1');
        }
        $error = $msg;
    }
}

View::guestStart('Verifikasi');
?>
<div class="card">
    <h2>Kode verifikasi</h2>
    <p class="muted" style="margin:0 0 14px;font-size:14px">Kode 6 digit sudah dikirim ke <b><?= e(Auth::maskEmail((string)$user['email'])) ?></b>. Berlaku 10 menit.</p>
    <?php if ($notice): ?><div class="alert ok"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <div class="field"><input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="123456" required autofocus style="font-size:24px;letter-spacing:8px;text-align:center"></div>
        <button class="btn" type="submit" style="width:100%">Verifikasi</button>
    </form>
    <form method="post" style="margin-top:10px;text-align:center">
        <?= csrf_field() ?><input type="hidden" name="action" value="resend">
        <button class="btn ghost sm" type="submit">Kirim ulang kode</button> <a href="/login.php" style="font-size:14px;margin-left:8px">Batal</a>
    </form>
</div>
<?php View::guestEnd();
