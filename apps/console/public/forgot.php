<?php

/** Lupa password: kirim link reset (berlaku 30 menit) ke email akun. Jawaban selalu sama agar tidak membocorkan akun terdaftar. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$sent = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $who = trim((string)($_POST['who'] ?? ''));
    if (!csrf_ok()) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } elseif (Auth::isLocked($who)) {
        $error = 'Terlalu banyak permintaan. Coba lagi dalam 15 menit.';
    } else {
        Auth::recordFail($who); // membatasi permintaan berulang (spam email reset)
        if ($user = Auth::findUser($who)) {
            $link = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/reset.php?t=' . Auth::createResetToken((int)$user['id']);
            $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#111827;max-width:460px">'
                . '<p>Permintaan reset password ADF Console untuk akun <b>' . e($user['username']) . '</b>.</p>'
                . '<p><a href="' . e($link) . '" style="display:inline-block;padding:10px 16px;background:#b8924a;color:#fff;border-radius:8px;text-decoration:none">Buat password baru</a></p>'
                . '<p style="color:#6b7280;font-size:12px">Link berlaku 30 menit dan hanya bisa dipakai sekali. IP: ' . e(Auth::ip()) . '<br>Jika Anda tidak meminta reset, abaikan email ini.</p></div>';
            Mailer::send((string)$user['email'], 'Reset password ADF Console', $html, 'ADF Console');
            Telegram::security('🔑', 'Permintaan reset password ADF Console', ['Akun' => $user['username']]);
        }
        $sent = true;
    }
}

View::guestStart('Lupa password');
?>
<div class="card">
    <h2>Lupa password</h2>
    <?php if ($sent): ?>
        <div class="alert ok">Jika akun terdaftar, link untuk membuat password baru sudah dikirim ke email akun tersebut. Link berlaku 30 menit.</div>
    <?php else: ?>
        <?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <div class="field"><label for="w">Username atau email</label><input id="w" name="who" autocapitalize="none" required autofocus></div>
            <button class="btn" type="submit" style="width:100%">Kirim link reset</button>
        </form>
    <?php endif; ?>
    <p style="text-align:center;margin:14px 0 0;font-size:14px"><a href="/login.php">Kembali ke login</a></p>
</div>
<?php View::guestEnd();
