<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/content-store.php';
require_once __DIR__ . '/../includes/orders-store.php';
require_once __DIR__ . '/../includes/pakasir-client.php';
adf_admin_require_login();

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh') {
    if (adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $orderId = trim($_POST['order_id'] ?? '');
        $txnId = trim($_POST['txn_id'] ?? '');
        if ($txnId !== '') {
            $status = adf_pakasir_transaction_status($txnId);
            if ($status !== null && !empty($status['status'])) {
                adf_orders_update_status($orderId, $status['status'], $status['completed_at'] ?? null);
                $message = 'Status pesanan ' . htmlspecialchars($orderId) . ' diperbarui: ' . htmlspecialchars($status['status']);
            } else {
                $message = 'Gagal mengambil status dari Pakasir.';
            }
        }
    }
}

$orders = array_reverse(adf_orders_load());
$csrf = adf_admin_csrf_token();
$adminPageTitle = 'Pesanan Langganan';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container">
    <h1>Pesanan Langganan</h1>
    <p class="admin-lead">Daftar pesanan/langganan yang dibuat pelanggan melalui halaman Harga. Status diperbarui otomatis lewat webhook Pakasir, atau bisa dicek manual dengan tombol "Cek Status".</p>

    <?php if ($message): ?>
        <div class="admin-alert admin-alert-success"><?php echo $message; ?></div>
    <?php endif; ?>

    <?php if (empty($orders)): ?>
        <p>Belum ada pesanan.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Paket</th>
                    <th>Nama</th>
                    <th>WhatsApp</th>
                    <th>Jumlah</th>
                    <th>Status</th>
                    <th>Dibuat</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                <tr>
                    <td><?php echo htmlspecialchars($order['order_id']); ?></td>
                    <td><?php echo htmlspecialchars($order['product_title']); ?></td>
                    <td><?php echo htmlspecialchars($order['name']); ?></td>
                    <td><?php echo htmlspecialchars($order['whatsapp']); ?></td>
                    <td>Rp <?php echo number_format((int) $order['amount'], 0, ',', '.'); ?></td>
                    <td><?php echo htmlspecialchars($order['status']); ?></td>
                    <td><?php echo htmlspecialchars($order['created_at']); ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                            <input type="hidden" name="action" value="refresh">
                            <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($order['order_id']); ?>">
                            <input type="hidden" name="txn_id" value="<?php echo htmlspecialchars($order['txn_id']); ?>">
                            <button type="submit" class="btn btn-outline" style="padding:4px 10px;font-size:0.75rem;">Cek Status</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
