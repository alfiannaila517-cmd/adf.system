<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/subscription-clients-store.php';
require_once __DIR__ . '/../includes/subscription-manual-invoices-store.php';
adf_admin_require_role('admin');

$clientKey = trim($_GET['client'] ?? $_POST['client_key'] ?? '');
$client = $clientKey !== '' ? adf_subscription_client_find($clientKey) : null;

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } elseif (($_POST['action'] ?? '') === 'delete') {
        adf_manual_invoice_delete((string) ($_POST['id'] ?? ''));
        header('Location: subscription-manual-invoice.php?client=' . urlencode($clientKey));
        exit;
    } elseif (!$client) {
        $error = 'Klien tidak ditemukan.';
    } else {
        $description = trim($_POST['description'] ?? '');
        $amount = (float) str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');
        $dueDate = trim($_POST['due_date'] ?? '');

        if ($description === '' || $amount <= 0 || $dueDate === '') {
            $error = 'Deskripsi, jumlah, dan jatuh tempo wajib diisi.';
        } else {
            adf_manual_invoice_create($clientKey, $description, $amount, $dueDate);
            header('Location: subscription-manual-invoice.php?client=' . urlencode($clientKey) . '&saved=1');
            exit;
        }
    }
}

$manualInvoices = $clientKey !== '' ? adf_manual_invoices_for_client($clientKey) : [];
usort($manualInvoices, static fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
$csrf = adf_admin_csrf_token();
$saved = isset($_GET['saved']);

$adminPageTitle = 'Tagih Manual';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container admin-container-wide">
    <h1>Tagih Manual</h1>
    <p class="admin-lead">
        Buat tagihan tambahan di luar tagihan langganan bulanan rutin (mis. biaya tambah modul/fitur).
        Tagihan ini akan otomatis muncul di sistem klien seperti tagihan rutin — bisa dilihat, dikunci jika lewat jatuh tempo, dan dibayar via Pakasir.
    </p>

    <?php if (!$client): ?>
        <div class="admin-alert admin-alert-error">Pilih klien terlebih dahulu dari halaman <a href="subscription-clients.php">Klien Langganan</a>.</div>
    <?php else: ?>
        <h2 class="admin-subheading">Klien: <?php echo htmlspecialchars($client['client_name'] ?? $clientKey); ?></h2>

        <?php if ($error): ?>
            <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($saved): ?>
            <div class="admin-alert admin-alert-success">Tagihan manual berhasil dibuat.</div>
        <?php endif; ?>

        <form method="post" class="admin-form">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="client_key" value="<?php echo htmlspecialchars($clientKey); ?>">
            <label>Deskripsi Tagihan
                <input type="text" name="description" required placeholder="Mis. Biaya Tambah Modul Laporan">
            </label>
            <label>Jumlah (Rp)
                <input type="text" name="amount" required placeholder="500000">
            </label>
            <label>Jatuh Tempo
                <input type="date" name="due_date" required value="<?php echo htmlspecialchars(date('Y-m-d')); ?>">
            </label>
            <button type="submit" class="btn btn-primary">Buat Tagihan</button>
        </form>

        <h2 class="admin-subheading">Riwayat Tagihan Manual</h2>
        <div class="payment-table-wrap">
            <table class="payment-table">
                <thead>
                    <tr>
                        <th>Deskripsi</th>
                        <th>Jumlah</th>
                        <th>Jatuh Tempo</th>
                        <th>Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($manualInvoices as $inv): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($inv['description'] ?? '-'); ?></td>
                            <td class="payment-amount">Rp <?php echo number_format((float) ($inv['amount'] ?? 0), 0, ',', '.'); ?></td>
                            <td><?php echo htmlspecialchars(!empty($inv['due_date']) ? date('d M Y', strtotime($inv['due_date'])) : '-'); ?></td>
                            <td><?php echo htmlspecialchars(!empty($inv['created_at']) ? date('d M Y H:i', strtotime($inv['created_at'])) : '-'); ?></td>
                            <td class="payment-actions">
                                <form method="post" onsubmit="return confirm('Hapus tagihan manual ini? Jika sudah tersinkron ke klien, tagihan yang sudah ada di sistem klien tidak akan ikut terhapus.');" style="display:inline;">
                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="client_key" value="<?php echo htmlspecialchars($clientKey); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($inv['id']); ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($manualInvoices)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center;">Belum ada tagihan manual.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>