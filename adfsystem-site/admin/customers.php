<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/orders-store.php';
adf_admin_require_login();

$orders = adf_orders_load();

// Group orders by WhatsApp number to build a simple customer list.
$customers = [];
foreach ($orders as $order) {
    $key = trim($order['whatsapp'] ?? '') !== '' ? $order['whatsapp'] : trim($order['name'] ?? '');
    if ($key === '') {
        continue;
    }
    if (!isset($customers[$key])) {
        $customers[$key] = [
            'name' => $order['name'] ?? '-',
            'whatsapp' => $order['whatsapp'] ?? '-',
            'order_count' => 0,
            'total_amount' => 0,
            'last_order_at' => $order['created_at'] ?? '',
            'last_status' => $order['status'] ?? '',
        ];
    }
    $customers[$key]['order_count']++;
    $customers[$key]['total_amount'] += (int) ($order['amount'] ?? 0);
    if (($order['created_at'] ?? '') >= $customers[$key]['last_order_at']) {
        $customers[$key]['last_order_at'] = $order['created_at'] ?? '';
        $customers[$key]['last_status'] = $order['status'] ?? '';
    }
}
uasort($customers, fn($a, $b) => strcmp($b['last_order_at'], $a['last_order_at']));

$adminPageTitle = 'Pelanggan';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container">
    <h1>Pelanggan</h1>
    <p class="admin-lead">Daftar pelanggan yang pernah melakukan pemesanan/langganan, dirangkum dari data Pesanan.</p>

    <?php if (empty($customers)): ?>
        <p>Belum ada pelanggan.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>WhatsApp</th>
                    <th>Jumlah Pesanan</th>
                    <th>Total Belanja</th>
                    <th>Status Terakhir</th>
                    <th>Pesanan Terakhir</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $c): ?>
                <tr>
                    <td><?php echo htmlspecialchars($c['name']); ?></td>
                    <td><?php echo htmlspecialchars($c['whatsapp']); ?></td>
                    <td><?php echo (int) $c['order_count']; ?></td>
                    <td>Rp <?php echo number_format((int) $c['total_amount'], 0, ',', '.'); ?></td>
                    <td><?php echo htmlspecialchars($c['last_status']); ?></td>
                    <td><?php echo htmlspecialchars($c['last_order_at']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
