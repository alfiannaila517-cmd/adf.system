<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/security.php';
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
                    adf_tg_security('👤', 'User admin baru ditambahkan', ['User' => $username, 'Email' => $email, 'Role' => $role, 'Oleh' => (string) $currentUser['username']]);
                } else {
                    $error = 'Username atau email sudah dipakai user lain.';
                }
            }
        } elseif ($action === 'email') {
            // Ganti email wajib konfirmasi password admin yang sedang login.
            $id = (string) ($_POST['id'] ?? '');
            $email = trim((string) ($_POST['email'] ?? ''));
            $me = adf_users_find_by_id((string) $currentUser['id']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Email tidak valid.';
            } elseif (!$me || !password_verify((string) ($_POST['confirm_password'] ?? ''), $me['password_hash'])) {
                $error = 'Password Anda salah. Email tidak diubah.';
            } elseif (adf_users_update_email($id, $email)) {
                if ($id === (string) $currentUser['id']) {
                    $_SESSION['adf_admin']['email'] = $email;
                }
                $saved = 'Email berhasil diubah.';
                adf_tg_security('✉️', 'Email user admin diubah', ['User' => (string) ((adf_users_find_by_id($id) ?? [])['username'] ?? $id), 'Email baru' => $email, 'Oleh' => (string) $currentUser['username']]);
            } else {
                $error = 'Gagal mengubah email (mungkin sudah dipakai user lain).';
            }
        } elseif ($action === 'password') {
            // Ganti password user (termasuk user lain); wajib konfirmasi password admin yang sedang login.
            $id = (string) ($_POST['id'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $me = adf_users_find_by_id((string) $currentUser['id']);
            if (strlen($newPassword) < 8) {
                $error = 'Password baru minimal 8 karakter.';
            } elseif (!$me || !password_verify((string) ($_POST['confirm_password'] ?? ''), $me['password_hash'])) {
                $error = 'Password Anda salah. Password user tidak diubah.';
            } elseif (adf_users_update_password($id, password_hash($newPassword, PASSWORD_BCRYPT))) {
                adf_sec_revoke_devices($id); // perangkat user itu wajib verifikasi email lagi
                adf_tg_security('🔑', 'Password user admin diganti', ['User' => (string) ((adf_users_find_by_id($id) ?? [])['username'] ?? $id), 'Oleh' => (string) $currentUser['username']]);
                $saved = 'Password berhasil diganti. Berikan password baru ke user tersebut lewat jalur yang aman.';
            } else {
                $error = 'Gagal mengganti password.';
            }
        } elseif ($action === 'delete') {
            $id = (string) ($_POST['id'] ?? '');
            if ($id === (string) $currentUser['id']) {
                $error = 'Tidak bisa menghapus akun yang sedang login.';
            } elseif (($deletedUser = adf_users_find_by_id($id)) !== null && adf_users_delete($id)) {
                $saved = 'User berhasil dihapus.';
                adf_tg_security('🗑️', 'User admin dihapus', ['User' => (string) $deletedUser['username'], 'Oleh' => (string) $currentUser['username']]);
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

    <div class="payment-table-wrap"><table class="admin-table">
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
                    <td class="payment-date"><?php echo !empty($u['created_at']) ? date('d M Y', strtotime($u['created_at'])) : '-'; ?></td>
                    <td style="white-space:nowrap;">
                        <details style="display:inline-block;vertical-align:middle;margin-right:6px;">
                            <summary class="btn btn-outline btn-sm" style="list-style:none;cursor:pointer;">Ubah Email</summary>
                            <form method="post" class="admin-form" style="position:absolute;z-index:5;margin-top:6px;padding:12px;background:#151821;border:1px solid rgba(255,255,255,.12);border-radius:10px;width:280px;">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="action" value="email">
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars($u['id']); ?>">
                                <label>Email baru
                                    <input type="email" name="email" required value="<?php echo htmlspecialchars($u['email']); ?>">
                                </label>
                                <label>Password Anda (konfirmasi)
                                    <input type="password" name="confirm_password" required autocomplete="current-password">
                                </label>
                                <button type="submit" class="btn btn-primary btn-sm">Simpan Email</button>
                            </form>
                        </details>
                        <details style="display:inline-block;vertical-align:middle;margin-right:6px;">
                            <summary class="btn btn-outline btn-sm" style="list-style:none;cursor:pointer;">Ganti Password</summary>
                            <form method="post" class="admin-form" style="position:absolute;z-index:5;margin-top:6px;padding:12px;background:#151821;border:1px solid rgba(255,255,255,.12);border-radius:10px;width:300px;">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="action" value="password">
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars($u['id']); ?>">
                                <label>Password baru untuk <b><?php echo htmlspecialchars($u['username']); ?></b>
                                    <span style="display:flex;gap:4px;">
                                        <input type="password" name="new_password" required minlength="8" autocomplete="new-password" class="pw-new" style="flex:1;">
                                        <button type="button" class="btn btn-outline btn-sm" title="Lihat / sembunyikan password" onclick="var i=this.parentNode.querySelector('.pw-new');i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'Lihat':'Tutup';">Lihat</button>
                                    </span>
                                </label>
                                <span style="display:flex;gap:6px;margin:-4px 0 8px;">
                                    <button type="button" class="btn btn-outline btn-sm" onclick="adfGenPw(this)">Buat Acak</button>
                                    <button type="button" class="btn btn-outline btn-sm" onclick="var i=this.closest('form').querySelector('.pw-new');navigator.clipboard.writeText(i.value);this.textContent='Tersalin';setTimeout(()=>this.textContent='Salin',1500);">Salin</button>
                                </span>
                                <label>Password Anda (konfirmasi)
                                    <input type="password" name="confirm_password" required autocomplete="current-password">
                                </label>
                                <button type="submit" class="btn btn-primary btn-sm">Simpan Password</button>
                            </form>
                        </details>
                        <?php if ((string) $u['id'] !== (string) $currentUser['id']): ?>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Hapus user ini?');">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars($u['id']); ?>">
                                <button type="submit" class="btn btn-outline btn-sm admin-btn-danger">Hapus</button>
                            </form>
                        <?php else: ?>
                            <span class="payment-date">Akun Anda</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>

    <h2 class="admin-subheading">Tambah User Baru</h2>
    <form method="post" class="admin-form admin-form-grid">
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
<script>
// Password acak 14 karakter (huruf besar/kecil, angka, simbol) dari generator kriptografis browser
function adfGenPw(btn) {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%&*?';
    const buf = new Uint32Array(14);
    crypto.getRandomValues(buf);
    const pw = Array.from(buf, n => chars[n % chars.length]).join('');
    const input = btn.closest('form').querySelector('.pw-new');
    input.value = pw;
    input.type = 'text';
}
</script>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>