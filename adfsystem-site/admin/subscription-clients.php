<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/subscription-clients-store.php';
adf_admin_require_role('admin');

$error = '';
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } elseif (($_POST['action'] ?? '') === 'delete') {
        adf_subscription_client_delete((string) ($_POST['client_key'] ?? ''));
        header('Location: subscription-clients.php');
        exit;
    } else {
        $clientKey = trim($_POST['client_key'] ?? '');
        $clientName = trim($_POST['client_name'] ?? '');
        $baseFee = (float) str_replace(['.', ','], ['', '.'], $_POST['base_fee'] ?? '0');
        $perGuestFee = (float) str_replace(['.', ','], ['', '.'], $_POST['per_guest_fee'] ?? '0');
        $subscriptionStartDate = trim($_POST['subscription_start_date'] ?? '');
        $pakasirSlug = trim($_POST['pakasir_slug'] ?? '');
        $pakasirApiKey = trim($_POST['pakasir_api_key'] ?? '');
        $pakasirWebhookSecret = trim($_POST['pakasir_webhook_secret'] ?? '');
        $existingToken = trim($_POST['existing_token'] ?? '');
        $regenerateToken = isset($_POST['regenerate_token']);

        if ($clientKey === '' || $clientName === '') {
            $error = 'Client Key dan Nama Klien wajib diisi.';
        } else {
            $token = $existingToken;
            if ($regenerateToken || $token === '') {
                $token = bin2hex(random_bytes(24));
            }
            $ok = adf_subscription_client_upsert([
                'client_key' => $clientKey,
                'client_name' => $clientName,
                'base_fee' => $baseFee,
                'per_guest_fee' => $perGuestFee,
                'subscription_start_date' => $subscriptionStartDate,
                'pakasir_slug' => $pakasirSlug,
                'pakasir_api_key' => $pakasirApiKey,
                'pakasir_webhook_secret' => $pakasirWebhookSecret,
                'client_token' => $token,
            ]);
            if ($ok) {
                $saved = true;
            } else {
                $error = 'Gagal menyimpan data klien.';
            }
        }
    }
}

$clients = adf_subscription_clients_load();
$csrf = adf_admin_csrf_token();

$editClient = null;
if (!empty($_GET['edit'])) {
    $editClient = adf_subscription_client_find((string) $_GET['edit']);
}

$adminPageTitle = 'Klien Langganan';
require __DIR__ . '/../includes/admin-header.php';
?>
<style>
.subscription-clients-page .admin-lead,
.subscription-clients-page .admin-subheading,
.subscription-clients-page .admin-form label,
.subscription-clients-page .admin-form input,
.subscription-clients-page .admin-form select,
.subscription-clients-page .payment-table,
.subscription-clients-page .payment-table th,
.subscription-clients-page .payment-table td,
.subscription-clients-page .btn {
    font-size: 9px !important;
}
.subscription-clients-page .admin-form input {
    padding: 5px 7px;
}
.subscription-clients-page .admin-form label {
    margin-bottom: 8px;
}
</style>
<div class="container admin-container admin-container-wide subscription-clients-page">
    <h1>Klien Langganan</h1>
    <p class="admin-lead">
        Kelola biaya bulanan klien yang berlangganan ke ADF System (mis. Karimunjawa Explore).
        Klien hanya bisa <strong>melihat tagihan</strong>, tidak bisa mengubah biaya dasar/per-tamu di sini —
        semua angka dikontrol dari panel ini.
    </p>

    <?php if ($error): ?>
        <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($saved): ?>
        <div class="admin-alert admin-alert-success">Data klien tersimpan.</div>
    <?php endif; ?>

    <h2 class="admin-subheading"><?php echo $editClient ? 'Edit Klien: ' . htmlspecialchars($editClient['client_name'] ?? '') : 'Tambah Klien'; ?></h2>
    <form method="post" class="admin-form">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="existing_token" value="<?php echo htmlspecialchars($editClient['client_token'] ?? ''); ?>">
        <label>Client Key (slug unik, mis. <code>karimunjawa-explore</code>)
            <input type="text" name="client_key" required placeholder="karimunjawa-explore" value="<?php echo htmlspecialchars($editClient['client_key'] ?? ''); ?>"<?php echo $editClient ? ' readonly' : ''; ?>>
        </label>
        <label>Nama Klien
            <input type="text" name="client_name" required placeholder="Karimunjawa Explore" value="<?php echo htmlspecialchars($editClient['client_name'] ?? ''); ?>">
        </label>
        <label>Biaya Dasar / Bulan (Rp)
            <input type="text" name="base_fee" value="<?php echo htmlspecialchars((string) ($editClient['base_fee'] ?? 150000)); ?>">
        </label>
        <label>Biaya per Tamu Confirmed (Rp)
            <input type="text" name="per_guest_fee" value="<?php echo htmlspecialchars((string) ($editClient['per_guest_fee'] ?? 5000)); ?>">
        </label>
        <label>Mulai Langganan (menentukan tanggal jatuh tempo setiap bulan)
            <input type="date" name="subscription_start_date" value="<?php echo htmlspecialchars($editClient['subscription_start_date'] ?? ($editClient ? '' : date('Y-m-d'))); ?>">
        </label>
        <label>Slug Proyek Pakasir (penerima pembayaran)
            <input type="text" name="pakasir_slug" value="<?php echo htmlspecialchars($editClient['pakasir_slug'] ?? ''); ?>">
        </label>
        <label>API Key Pakasir
            <input type="text" name="pakasir_api_key" value="<?php echo htmlspecialchars($editClient['pakasir_api_key'] ?? ''); ?>">
        </label>
        <label>Webhook Secret Pakasir
            <input type="text" name="pakasir_webhook_secret" value="<?php echo htmlspecialchars($editClient['pakasir_webhook_secret'] ?? ''); ?>">
        </label>
        <label style="display:flex;align-items:center;gap:6px;flex-direction:row;">
            <input type="checkbox" name="regenerate_token" value="1" style="width:auto;"> Buat ulang Client Token
        </label>
        <button type="submit" class="btn btn-primary"><?php echo $editClient ? 'Simpan Perubahan' : 'Simpan Klien'; ?></button>
        <?php if ($editClient): ?>
            <a href="subscription-clients.php" class="btn" style="margin-left:8px;">Batal</a>
        <?php endif; ?>
    </form>

    <h2 class="admin-subheading">Daftar Klien</h2>
        <div class="payment-table-wrap">
            <table class="payment-table">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Client Key</th>
                        <th>Biaya Dasar</th>
                        <th>Biaya/Tamu</th>
                        <th>Jatuh Tempo</th>
                        <th>Client Token</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $c): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($c['client_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($c['client_key'] ?? '-'); ?></td>
                        <td class="payment-amount">Rp <?php echo number_format((float) ($c['base_fee'] ?? 0), 0, ',', '.'); ?></td>
                        <td class="payment-amount">Rp <?php echo number_format((float) ($c['per_guest_fee'] ?? 0), 0, ',', '.'); ?></td>
                        <td><?php echo !empty($c['subscription_start_date']) ? 'Tgl ' . (int) date('j', strtotime($c['subscription_start_date'])) . ' tiap bulan' : '-'; ?></td>
                        <td><code style="font-size:0.72rem;"><?php echo htmlspecialchars($c['client_token'] ?? '-'); ?></code></td>
                        <td class="payment-actions">
                            <a href="subscription-clients.php?edit=<?php echo urlencode($c['client_key'] ?? ''); ?>" class="payment-btn-sm">Edit</a>
                            <form method="POST" onsubmit="return confirm('Hapus klien <?php echo htmlspecialchars(addslashes($c['client_name'] ?? '')); ?>?');">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="client_key" value="<?php echo htmlspecialchars($c['client_key'] ?? ''); ?>">
                                <button type="submit" class="payment-btn-sm payment-btn-danger">Hapus</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($clients)): ?>
                    <tr><td colspan="7" style="text-align:center;">Belum ada klien.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="admin-lead" style="margin-top:14px;">
            API endpoint untuk klien: <code><?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/') . '/api/subscription-config.php'); ?></code>
            — berikan <strong>Client Key</strong> dan <strong>Client Token</strong> di atas ke pemilik situs klien untuk diisi di halaman Tagihan Langganan mereka.
        </p>
    </div>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>

