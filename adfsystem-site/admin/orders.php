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

$allOrders = adf_orders_load();
$completedOrders = array_filter($allOrders, static function (array $order): bool {
    return strtolower((string) ($order['status'] ?? '')) === 'completed';
});
$completedTotal = array_sum(array_map(static function (array $order): int {
    return (int) ($order['amount'] ?? 0);
}, $completedOrders));
$pendingTotal = array_sum(array_map(static function (array $order): int {
    return (int) ($order['amount'] ?? 0);
}, array_filter($allOrders, static function (array $order): bool {
    return strtolower((string) ($order['status'] ?? '')) === 'pending';
})));
$orders = array_reverse($allOrders);
$csrf = adf_admin_csrf_token();
$adminPageTitle = 'Transaksi Pembayaran';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container">
    <h1>Transaksi Pembayaran</h1>
    <p class="admin-lead">Pantau pembayaran langganan dari Pakasir. Status <strong>completed</strong> berarti pembayaran sudah berhasil diterima.</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin:24px 0;">
        <div class="admin-card" style="padding:18px;">
            <div style="color:var(--text-muted);font-size:.85rem;">Uang Terkumpul</div>
            <strong style="display:block;font-size:1.5rem;margin-top:6px;color:#22c55e;">Rp <?php echo number_format($completedTotal, 0, ',', '.'); ?></strong>
            <small><?php echo count($completedOrders); ?> transaksi selesai</small>
        </div>
        <div class="admin-card" style="padding:18px;">
            <div style="color:var(--text-muted);font-size:.85rem;">Menunggu Pembayaran</div>
            <strong style="display:block;font-size:1.5rem;margin-top:6px;">Rp <?php echo number_format($pendingTotal, 0, ',', '.'); ?></strong>
            <small>Belum berstatus completed</small>
        </div>
        <div class="admin-card" style="padding:18px;">
            <div style="color:var(--text-muted);font-size:.85rem;">Total Pesanan</div>
            <strong style="display:block;font-size:1.5rem;margin-top:6px;"><?php echo count($allOrders); ?></strong>
            <small>Data calon pelanggan tersimpan</small>
        </div>
    </div>

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
                    <th>Dibayar</th>
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
                    <td><?php echo htmlspecialchars($order['completed_at'] ?: '-'); ?></td>
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
