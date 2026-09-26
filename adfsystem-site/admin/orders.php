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
$formatDate = static function (?string $date): string {
    if (empty($date)) {
        return '-';
    }
    try {
        return (new DateTime($date))->format('d M Y, H:i');
    } catch (Exception $exception) {
        return $date;
    }
};
$orders = array_reverse($allOrders);
$csrf = adf_admin_csrf_token();
$adminPageTitle = 'Transaksi Pembayaran';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container">
    <h1>Transaksi Pembayaran</h1>
    <p class="admin-lead">Pantau pembayaran langganan dari Pakasir. Status <strong>completed</strong> berarti pembayaran sudah berhasil diterima.</p>

    <div class="payment-summary-grid">
        <div class="payment-summary-card payment-summary-card-completed">
            <span class="payment-summary-label">Uang Terkumpul</span>
            <strong>Rp <?php echo number_format($completedTotal, 0, ',', '.'); ?></strong>
            <small><?php echo count($completedOrders); ?> pembayaran berhasil</small>
        </div>
        <div class="payment-summary-card">
            <span class="payment-summary-label">Menunggu Pembayaran</span>
            <strong>Rp <?php echo number_format($pendingTotal, 0, ',', '.'); ?></strong>
            <small>Transaksi belum selesai</small>
        </div>
        <div class="payment-summary-card">
            <span class="payment-summary-label">Total Pesanan</span>
            <strong><?php echo count($allOrders); ?></strong>
            <small>Data pelanggan tersimpan</small>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="admin-alert admin-alert-success"><?php echo $message; ?></div>
    <?php endif; ?>

    <?php if (empty($orders)): ?>
        <p>Belum ada pesanan.</p>
    <?php else: ?>
        <div class="payment-table-wrap">
        <table class="admin-table payment-table">
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
                    <td class="payment-amount">Rp <?php echo number_format((int) $order['amount'], 0, ',', '.'); ?></td>
                    <td><span class="payment-status payment-status-<?php echo htmlspecialchars(strtolower((string) $order['status'])); ?>"><?php echo htmlspecialchars($order['status']); ?></span></td>
                    <td class="payment-date"><?php echo htmlspecialchars($formatDate($order['completed_at'] ?? null)); ?></td>
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
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
