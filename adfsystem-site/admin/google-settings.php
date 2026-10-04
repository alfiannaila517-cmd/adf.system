<?php
/**
 * Pengaturan "Masuk dengan Google" untuk Admin ADF Store (khusus admin).
 * Client Secret disimpan di folder home hosting (di luar public_html) dan tidak pernah ditampilkan ulang.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/google-oauth.php';
adf_admin_require_role('admin');

$saved = false;
$error = '';
$cfg = adf_google_config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } elseif (($_POST['action'] ?? '') === 'disable') {
        @unlink(adf_google_config_path());
        $cfg = null;
        $saved = true;
        adf_tg_security('⚙️', 'Login Google DIMATIKAN', ['Oleh' => (string) (adf_admin_current_user()['username'] ?? '-')]);
    } else {
        $clientId = trim((string) ($_POST['client_id'] ?? ''));
        $clientSecret = trim((string) ($_POST['client_secret'] ?? ''));
        if ($clientSecret === '' && $cfg) {
            $clientSecret = $cfg['client_secret']; // kosong = pakai secret lama
        }
        if (!preg_match('/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/', $clientId)) {
            $error = 'Client ID tidak valid (formatnya: angka-xxxx.apps.googleusercontent.com).';
        } elseif (strlen($clientSecret) < 10) {
            $error = 'Client Secret wajib diisi.';
        } elseif (!adf_google_save_config($clientId, $clientSecret)) {
            $error = 'Gagal menyimpan. Folder home hosting tidak bisa ditulis.';
        } else {
            $cfg = adf_google_config();
            $saved = true;
            adf_tg_security('⚙️', 'Pengaturan Login Google diubah', ['Oleh' => (string) (adf_admin_current_user()['username'] ?? '-')]);
        }
    }
}

$csrf = adf_admin_csrf_token();
$redirectUri = adf_google_redirect_uri();
$me = adf_admin_current_user();

$adminPageTitle = 'Login Google';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container">
    <h1>Login dengan Google</h1>
    <p class="admin-lead">
        Masuk ke Admin ADF Store (dan Developer Panel) cukup dengan akun Google yang sudah login di browser.
        Hanya akun Google yang emailnya <strong>sama dengan email user admin</strong> yang bisa masuk.
        Email Anda saat ini: <code><?php echo htmlspecialchars((string) $me['email']); ?></code>.
    </p>

    <?php if ($error): ?>
        <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php elseif ($saved): ?>
        <div class="admin-alert admin-alert-success">Tersimpan. <?php echo $cfg ? 'Tombol "Masuk dengan Google" sudah aktif di halaman login.' : 'Login Google dimatikan.'; ?></div>
    <?php endif; ?>

    <p class="admin-lead">
        Status: <?php echo $cfg ? '<strong style="color:#4ade80;">Aktif</strong>' : '<strong>Belum aktif</strong>'; ?>
    </p>

    <form method="post" class="admin-form">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <label>Authorized redirect URI (salin ke Google Cloud)
            <span class="adm-token"><code title="<?php echo htmlspecialchars($redirectUri); ?>"><?php echo htmlspecialchars($redirectUri); ?></code><button type="button" class="adm-copy-btn" onclick="navigator.clipboard.writeText(this.previousElementSibling.title);this.textContent='Tersalin';setTimeout(()=>this.textContent='Salin',1500);">Salin</button></span>
        </label>
        <label>Client ID
            <input type="text" name="client_id" required placeholder="1234567890-xxxx.apps.googleusercontent.com" value="<?php echo htmlspecialchars($cfg['client_id'] ?? ''); ?>">
        </label>
        <label>Client Secret <?php echo $cfg ? '<small>(kosongkan untuk tetap memakai yang lama)</small>' : ''; ?>
            <input type="password" name="client_secret" autocomplete="new-password" <?php echo $cfg ? '' : 'required'; ?> placeholder="<?php echo $cfg ? '•••••••• tersimpan' : 'GOCSPX-…'; ?>">
        </label>
        <div class="admin-form-actions">
            <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
    </form>

    <?php if ($cfg): ?>
        <form method="post" onsubmit="return confirm('Matikan login Google?');" style="margin-top:10px;">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="disable">
            <button type="submit" class="btn payment-btn-danger">Matikan Login Google</button>
        </form>
    <?php endif; ?>
</div>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
