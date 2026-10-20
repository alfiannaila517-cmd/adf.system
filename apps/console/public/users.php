<?php

/** Tim ADF (khusus admin): tambah akun, ganti email / password, hapus. Perubahan sensitif butuh password admin sendiri. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireAdmin();
$db = Auth::db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $myRow = Auth::findUserById((int)$me['id']);
    $confirmOk = $myRow && password_verify((string)($_POST['confirm_password'] ?? ''), $myRow['password_hash']);
    try {
        if (!csrf_ok()) {
            flash('Sesi form kedaluwarsa, silakan coba lagi.', 'err');
        } elseif ($action === 'add') {
            $username = trim((string)($_POST['username'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'staff';
            if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('Username (3–60 huruf/angka/titik/minus) dan email harus valid.', 'err');
            } elseif (strlen($password) < 10) {
                flash('Password minimal 10 karakter.', 'err');
            } elseif (!$confirmOk) {
                flash('Password Anda (konfirmasi) salah.', 'err');
            } else {
                $db->prepare('INSERT INTO console_users (username, email, password_hash, role) VALUES (?, ?, ?, ?)')->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
                Auth::audit('user.add', "{$username} ({$role})");
                flash("Akun {$username} ditambahkan.");
            }
        } elseif ($action === 'email') {
            $email = trim((string)($_POST['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('Email tidak valid.', 'err');
            } elseif (!$confirmOk) {
                flash('Password Anda (konfirmasi) salah.', 'err');
            } else {
                $db->prepare('UPDATE console_users SET email = ? WHERE id = ?')->execute([$email, $id]);
                Auth::audit('user.email', "#{$id} → {$email}");
                flash('Email diperbarui.');
            }
        } elseif ($action === 'password') {
            $password = (string)($_POST['new_password'] ?? '');
            if (strlen($password) < 10) {
                flash('Password minimal 10 karakter.', 'err');
            } elseif (!$confirmOk) {
                flash('Password Anda (konfirmasi) salah.', 'err');
            } else {
                Auth::setPassword($id, $password);
                Auth::audit('user.password', "#{$id}");
                flash('Password diperbarui.');
            }
        } elseif ($action === 'delete') {
            if ($id === (int)$me['id']) {
                flash('Tidak bisa menghapus akun sendiri.', 'err');
            } elseif (!$confirmOk) {
                flash('Password Anda (konfirmasi) salah.', 'err');
            } else {
                $target = Auth::findUserById($id);
                $db->prepare('DELETE FROM console_users WHERE id = ?')->execute([$id]);
                Auth::audit('user.delete', (string)($target['username'] ?? "#{$id}"));
                flash('Akun dihapus.');
            }
        }
    } catch (PDOException $e) {
        flash(str_contains($e->getMessage(), 'Duplicate') ? 'Username atau email sudah dipakai.' : 'Gagal menyimpan.', 'err');
    }
    redirect('/users.php');
}

$users = $db->query('SELECT id, username, email, role, created_at FROM console_users ORDER BY role, username')->fetchAll();

View::start('Tim ADF', 'users', $me);
?>
<h1>Tim ADF</h1>
<p class="muted" style="margin:0 0 18px">Akun yang bisa masuk ke Console. Setiap perubahan meminta password Anda sebagai konfirmasi.</p>

<div class="card">
    <table class="stack">
        <thead><tr><th>Akun</th><th>Email</th><th>Peran</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><b><?= e($u['username']) ?></b><?= (int)$u['id'] === (int)$me['id'] ? ' <span class="pill gold">Anda</span>' : '' ?></td>
                    <td class="muted"><?= e($u['email']) ?></td>
                    <td><span class="pill<?= $u['role'] === 'admin' ? ' gold' : '' ?>"><?= e($u['role']) ?></span></td>
                    <td style="text-align:right">
                        <details>
                            <summary class="btn ghost sm" style="list-style:none">Kelola</summary>
                            <div style="display:grid;gap:10px;margin-top:10px;text-align:left">
                                <form method="post" style="display:grid;gap:6px"><?= csrf_field() ?><input type="hidden" name="action" value="email"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                    <input name="email" type="email" value="<?= e($u['email']) ?>" required><input name="confirm_password" type="password" placeholder="Password Anda" required><button class="btn sm">Simpan email</button></form>
                                <form method="post" style="display:grid;gap:6px"><?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                    <input name="new_password" type="password" placeholder="Password baru (min. 10)" minlength="10" required autocomplete="new-password"><input name="confirm_password" type="password" placeholder="Password Anda" required><button class="btn sm">Ganti password</button></form>
                                <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                                    <form method="post" style="display:grid;gap:6px" onsubmit="return confirm('Hapus akun <?= e($u['username']) ?>?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                        <input name="confirm_password" type="password" placeholder="Password Anda" required><button class="btn danger sm">Hapus akun</button></form>
                                <?php endif; ?>
                            </div>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Tambah akun</h2>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;align-items:end">
        <?= csrf_field() ?><input type="hidden" name="action" value="add">
        <div><label>Username</label><input name="username" required autocapitalize="none"></div>
        <div><label>Email</label><input name="email" type="email" required></div>
        <div><label>Password (min. 10)</label><input name="password" type="password" minlength="10" required autocomplete="new-password"></div>
        <div><label>Peran</label><select name="role"><option value="staff">staff</option><option value="admin">admin</option></select></div>
        <div><label>Password Anda</label><input name="confirm_password" type="password" required></div>
        <div><button class="btn" style="width:100%">Tambah</button></div>
    </form>
</div>
<?php View::end();
