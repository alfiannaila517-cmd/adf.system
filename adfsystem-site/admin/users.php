<?php
require_once __DIR__ . '/../includes/admin-auth.php';
adf_admin_require_role('admin');

$currentUser = adf_admin_current_user();
$saved = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'staff';

            if ($username === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Username dan email valid wajib diisi.';
            } elseif (strlen($password) < 8) {
                $error = 'Password minimal 8 karakter.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                if (adf_users_add($username, $email, $hash, $role)) {
                    $saved = 'User baru berhasil ditambahkan.';
                } else {
                    $error = 'Username atau email sudah dipakai user lain.';
                }
            }
        } elseif ($action === 'delete') {
            $id = (string) ($_POST['id'] ?? '');
            if ($id === (string) $currentUser['id']) {
                $error = 'Tidak bisa menghapus akun yang sedang login.';
            } elseif (adf_users_delete($id)) {
                $saved = 'User berhasil dihapus.';
            } else {
                $error = 'Gagal menghapus user (minimal harus ada 1 admin).';
            }
        }
    }
}

$users = adf_users_load();
$csrf = adf_admin_csrf_token();
$adminPageTitle = 'Pengguna';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container">
    <h1>Pengguna Admin</h1>
    <p class="admin-lead">Kelola user yang bisa login ke panel admin ini. Role <strong>Admin</strong> punya akses penuh, role <strong>Staff</strong> tidak bisa membuka menu Pengguna dan Pembayaran.</p>

    <?php if ($saved): ?>
        <div class="admin-alert admin-alert-success"><?php echo htmlspecialchars($saved); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <table class="admin-table">
        <thead>
            <tr>
                <th>Username</th>
                <th>Email</th>
                <th>Role</th>
                <th>Dibuat</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><?php echo htmlspecialchars($u['username']); ?></td>
                <td><?php echo htmlspecialchars($u['email']); ?></td>
                <td><?php echo htmlspecialchars($u['role']); ?></td>
                <td><?php echo htmlspecialchars($u['created_at']); ?></td>
                <td>
                    <?php if ((string) $u['id'] !== (string) $currentUser['id']): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Hapus user ini?');">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($u['id']); ?>">
                        <button type="submit" class="btn btn-outline admin-btn-danger" style="padding:4px 10px;font-size:0.75rem;">Hapus</button>
                    </form>
                    <?php else: ?>
                    <span style="font-size:0.75rem;color:var(--text-muted);">Akun Anda</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2 class="admin-subheading">Tambah User Baru</h2>
    <form method="post" class="admin-form">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="action" value="add">
        <label>Username
            <input type="text" name="username" required>
        </label>
        <label>Email
            <input type="email" name="email" required>
        </label>
        <label>Password
            <input type="password" name="password" required minlength="8" autocomplete="new-password">
        </label>
        <label>Role
            <select name="role">
                <option value="staff">Staff (akses terbatas)</option>
                <option value="admin">Admin (akses penuh)</option>
            </select>
        </label>
        <button type="submit" class="btn btn-primary">Tambah User</button>
    </form>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
