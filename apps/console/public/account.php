<?php

/** Akun saya: ganti password sendiri (butuh password lama). */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $row = Auth::findUserById((int)$me['id']);
    $new = (string)($_POST['new_password'] ?? '');
    if (!csrf_ok()) {
        flash('Sesi form kedaluwarsa, silakan coba lagi.', 'err');
    } elseif (!$row || !password_verify((string)($_POST['current_password'] ?? ''), $row['password_hash'])) {
        flash('Password lama salah.', 'err');
    } elseif (strlen($new) < 10) {
        flash('Password baru minimal 10 karakter.', 'err');
    } elseif ($new !== (string)($_POST['new_password2'] ?? '')) {
        flash('Kedua password baru tidak sama.', 'err');
    } else {
        Auth::setPassword((int)$me['id'], $new);
        Auth::audit('account.password');
        Telegram::security('🔑', 'Password ADF Console diganti', ['Akun' => $me['username']]);
        flash('Password berhasil diganti.');
    }
    redirect('/account.php');
}

View::start('Akun saya', 'account', $me);
echo '<div class="crumb"><a href="/settings.php">Pengaturan</a> ›</div>';
?>
<h1>Akun saya</h1>
<p class="muted" style="margin:0 0 12px"><?= e($me['username']) ?> · <?= e($me['email']) ?> · <?= e($me['role']) ?></p>
<div class="card" style="max-width:460px">
    <h2>Ganti password</h2>
    <form method="post">
        <?= csrf_field() ?>
        <div class="field"><label>Password lama</label><input name="current_password" type="password" autocomplete="current-password" required></div>
        <div class="field"><label>Password baru (min. 10)</label><input name="new_password" type="password" autocomplete="new-password" minlength="10" required></div>
        <div class="field"><label>Ulangi password baru</label><input name="new_password2" type="password" autocomplete="new-password" minlength="10" required></div>
        <button class="btn">Simpan</button>
    </form>
</div>
<?php View::end();
