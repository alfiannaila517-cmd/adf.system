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
<div class="stat-cards">
    <div class="stat-card">
        <div class="stat-icon" style="background:#EFF6FF"><i class="bi bi-people" style="color:#1D4ED8"></i></div>
        <div><div class="stat-val"><?= $stats['customers'] ?></div><div class="stat-lbl">Total Customers</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#FFF7ED"><i class="bi bi-clipboard2-check" style="color:#C2410C"></i></div>
        <div><div class="stat-val"><?= $stats['orders'] ?></div><div class="stat-lbl">Total Orders</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#F5F3FF"><i class="bi bi-hammer" style="color:#6D28D9"></i></div>
        <div><div class="stat-val"><?= $stats['in_progress'] ?></div><div class="stat-lbl">In Progress</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#F0FDF4"><i class="bi bi-box-seam" style="color:#15803D"></i></div>
        <div><div class="stat-val"><?= $stats['ready_ship'] ?></div><div class="stat-lbl">Ready to Ship</div></div>
    </div>
</div>
<div class="pwf-card">
    <div class="pwf-card-header">Recent Orders</div>
    <div class="pwf-card-body" style="padding:0">
    <table class="pwf-table">
        <thead><tr><th>Order Code</th><th>Customer</th><th>Product</th><th>Status</th></tr></thead>
        <tbody>
            <?php foreach ($latest as $row): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['order_code']) ?></strong></td>
                    <td><?= htmlspecialchars($row['customer_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($row['product_name']) ?></td>
                    <td><span class="status-badge status-<?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars(str_replace('_',' ',$row['status'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if(empty($latest)): ?><tr><td colspan="4" style="text-align:center;color:var(--muted);padding:24px">No orders yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php pwfOfficeFooter();
