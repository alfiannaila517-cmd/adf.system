<?php

/** Buat password baru dari link email (token sekali pakai, 30 menit). */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$userId = Auth::resetTokenUser($token);
$error = '';

if ($userId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = (string)($_POST['p1'] ?? '');
    if (!csrf_ok()) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } elseif (strlen($p1) < 10) {
        $error = 'Password minimal 10 karakter.';
    } elseif ($p1 !== (string)($_POST['p2'] ?? '')) {
        $error = 'Kedua password tidak sama.';
    } else {
        Auth::setPassword($userId, $p1);
        $u = Auth::findUserById($userId);
        Telegram::security('🔑', 'Password ADF Console direset', ['Akun' => (string)($u['username'] ?? $userId)]);
        redirect('/login.php?reset=1');
    }
}

View::guestStart('Password baru');
?>
<div class="card">
    <h2>Password baru</h2>
    <?php if (!$userId): ?>
        <div class="alert err">Link tidak valid atau sudah kedaluwarsa. Silakan minta link baru.</div>
        <p style="text-align:center"><a href="/forgot.php">Minta link baru</a></p>
    <?php else: ?>
        <?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>">
            <div class="field"><label for="p1">Password baru (min. 10 karakter)</label><input id="p1" name="p1" type="password" autocomplete="new-password" required minlength="10"></div>
            <div class="field"><label for="p2">Ulangi password</label><input id="p2" name="p2" type="password" autocomplete="new-password" required minlength="10"></div>
            <button class="btn" type="submit" style="width:100%">Simpan password</button>
        </form>
    <?php endif; ?>
</div>
<?php View::guestEnd();
