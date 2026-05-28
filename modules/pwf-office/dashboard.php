<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$pdo = getPwfOfficePdo();
$stats = [
    'customers' => (int)$pdo->query('SELECT COUNT(*) FROM pwf_customers')->fetchColumn(),
    'orders' => (int)$pdo->query('SELECT COUNT(*) FROM pwf_orders')->fetchColumn(),
    'in_progress' => (int)$pdo->query("SELECT COUNT(*) FROM pwf_orders WHERE status='on_progress'")->fetchColumn(),
    'ready_ship' => (int)$pdo->query("SELECT COUNT(*) FROM pwf_orders WHERE status='ready_ship'")->fetchColumn(),
];

$latest = $pdo->query("SELECT o.order_code, o.product_name, o.status, c.customer_name
    FROM pwf_orders o LEFT JOIN pwf_customers c ON c.id=o.customer_id ORDER BY o.id DESC LIMIT 8")->fetchAll();

pwfOfficeHeader('Dashboard', 'dashboard');
?>
<div class="row">
    <div class="card stat">
        <h3><?= $stats['customers'] ?></h3>
        <p>Total Customer</p>
    </div>
    <div class="card stat">
        <h3><?= $stats['orders'] ?></h3>
        <p>Total Pesanan</p>
    </div>
    <div class="card stat">
        <h3><?= $stats['in_progress'] ?></h3>
        <p>Sedang Dikerjakan</p>
    </div>
    <div class="card stat">
        <h3><?= $stats['ready_ship'] ?></h3>
        <p>Siap Kirim</p>
    </div>
</div>
<div class="mt"></div>
<div class="card">
    <h3 style="margin-top:0;">Order Terbaru</h3>
    <table>
        <thead>
            <tr>
                <th>Kode</th>
                <th>Customer</th>
                <th>Produk</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($latest as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['order_code']) ?></td>
                    <td><?= htmlspecialchars($row['customer_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($row['product_name']) ?></td>
                    <td><?= htmlspecialchars($row['status']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php pwfOfficeFooter();
