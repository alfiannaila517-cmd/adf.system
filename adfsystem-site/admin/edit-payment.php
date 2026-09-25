<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/content-store.php';
adf_admin_require_login();

$content = adf_load_content();
$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } else {
        $slug = trim($_POST['project_slug'] ?? '');
        $apiKey = trim($_POST['api_key'] ?? '');
        $webhookSecret = trim($_POST['webhook_secret'] ?? '');

        $content['payment'] = [
            'provider' => 'pakasir',
            'project_slug' => $slug,
            'api_key' => $apiKey,
            'webhook_secret' => $webhookSecret,
            'sandbox' => !empty($_POST['sandbox']),
        ];
        $saved = adf_save_content($content);
        if (!$saved) {
            $error = 'Gagal menyimpan perubahan.';
        } else {
            $content = adf_load_content();
        }
    }
}

$csrf = adf_admin_csrf_token();
$payment = $content['payment'];
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$siteRoot = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$webhookUrl = $scheme . $host . $siteRoot . '/api/pakasir-webhook.php';

$adminPageTitle = 'Pengaturan Pembayaran';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container">
    <h1>Pengaturan Pembayaran (Pakasir)</h1>
    <p class="admin-lead">
        Isi <strong>Slug Proyek</strong> dan <strong>API Key</strong> dari akun Pakasir Anda (dashboard.pakasir.com) agar tombol "Langganan" di halaman Harga bisa membuat link pembayaran QRIS/Virtual Account otomatis.
        Belum punya akun? Daftar di <a href="https://app.pakasir.com" target="_blank" rel="noopener">app.pakasir.com</a>.
    </p>

    <?php if ($saved): ?>
        <div class="admin-alert admin-alert-success">Perubahan tersimpan.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" class="admin-form">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <label>Slug Proyek Pakasir
            <input type="text" name="project_slug" value="<?php echo htmlspecialchars($payment['project_slug']); ?>" placeholder="contoh: adfsystem">
        </label>
        <label>API Key
            <input type="text" name="api_key" value="<?php echo htmlspecialchars($payment['api_key']); ?>" placeholder="9ab7a651cba3daef0566...">
        </label>
        <label>Webhook Secret
            <input type="text" name="webhook_secret" value="<?php echo htmlspecialchars($payment['webhook_secret']); ?>" placeholder="Ambil dari halaman detail proyek Pakasir">
        </label>
        <label class="admin-checkbox-line">
            <input type="checkbox" name="sandbox" value="1" <?php echo !empty($payment['sandbox']) ? 'checked' : ''; ?>> Mode Sandbox (untuk testing, belum menerima uang asli)
        </label>
        <button type="submit" class="btn btn-primary">Simpan Pengaturan</button>
    </form>

    <h2 class="admin-subheading">Webhook URL</h2>
    <p>Salin URL berikut dan tempelkan di halaman detail proyek Pakasir Anda, pada kolom "Webhook URL", agar status pembayaran otomatis diperbarui:</p>
    <code style="display:block;background:var(--card-bg);border:1px solid var(--card-border);border-radius:6px;padding:10px 14px;word-break:break-all;"><?php echo htmlspecialchars($webhookUrl); ?></code>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
