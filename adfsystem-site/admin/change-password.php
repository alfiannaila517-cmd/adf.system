<?php
require_once __DIR__ . '/../includes/admin-auth.php';
adf_admin_require_login();

$currentUser = adf_admin_current_user();
$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } else {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        $user = adf_users_find_by_id($currentUser['id']);

        if ($user === null || !password_verify($currentPassword, $user['password_hash'])) {
            $error = 'Password saat ini salah.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Password baru minimal 8 karakter.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Konfirmasi password baru tidak cocok.';
        } else {
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            if (adf_users_update_password($user['id'], $newHash)) {
                $saved = true;
            } else {
                $error = 'Gagal menyimpan password baru. Periksa izin tulis folder data/.';
            }
        }
    }
}

$csrf = adf_admin_csrf_token();
$adminPageTitle = 'Ubah Password';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container">
    <h1>Ubah Password Admin</h1>
    <p class="admin-lead">Ganti password login untuk panel admin website ini.</p>

    <?php if ($saved): ?>
        <div class="admin-alert admin-alert-success">Password berhasil diubah. Gunakan password baru saat login berikutnya.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" class="admin-form">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <label>Password Saat Ini
            <input type="password" name="current_password" required autocomplete="current-password">
        </label>
        <label>Password Baru
            <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
        </label>
        <label>Konfirmasi Password Baru
            <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
        </label>
        <button type="submit" class="btn btn-primary">Simpan Password Baru</button>
    </form>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
