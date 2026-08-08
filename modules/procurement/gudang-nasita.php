<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!($auth->hasPermission('gudang_nasita') || $auth->hasPermission('warehouse'))) {
    http_response_code(403);
    echo 'Akses Gudang Nasita ditolak.';
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Gudang Nasita';

$stockItems = getGudangNasitaStock(300);
$recentTransfers = getGudangNasitaTransfers(15);

$summary = [
    'items' => count($stockItems),
    'qty' => 0,
    'low' => 0,
    'incoming_today' => 0,
    'outgoing_today' => 0,
];

foreach ($stockItems as $item) {
    $summary['qty'] += (float)$item['quantity'];
    if ((float)$item['quantity'] <= (float)($item['reorder_level'] ?? 0) && (float)($item['reorder_level'] ?? 0) > 0) {
        $summary['low']++;
    }
}

$movementSummary = $db->fetchAll("\n    SELECT movement_type, COALESCE(SUM(quantity), 0) AS total_qty\n    FROM gudang_nasita_movements\n    WHERE movement_date = CURDATE()\n    GROUP BY movement_type\n");
foreach ($movementSummary as $row) {
    if ($row['movement_type'] === 'in_supplier') {
        $summary['incoming_today'] = (float)$row['total_qty'];
    }
    if ($row['movement_type'] === 'out_transfer') {
        $summary['outgoing_today'] = (float)$row['total_qty'];
    }
}

$pendingReceipts = $db->fetchAll("\n    SELECT poh.id, poh.po_number, poh.po_date, poh.status, poh.supplier_id, s.supplier_name,\n           COUNT(pod.id) AS items_count,\n           SUM(CASE WHEN COALESCE(pod.received_quantity,0) < pod.quantity THEN 1 ELSE 0 END) AS pending_items\n    FROM purchase_orders_header poh\n    LEFT JOIN suppliers s ON s.id = poh.supplier_id\n    LEFT JOIN purchase_orders_detail pod ON pod.po_header_id = poh.id\n    WHERE poh.status IN ('approved','partially_received','completed')\n    GROUP BY poh.id\n    ORDER BY poh.created_at DESC\n    LIMIT 12\n");

include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem; display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
    <div>
        <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">Gudang Nasita</h2>
        <p style="color: var(--text-muted); font-size: 0.875rem;">Stok pusat, penerimaan supplier, dan kontrol barang keluar</p>
    </div>
    <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
        <a href="purchase-orders.php" class="btn btn-primary">
            <i data-feather="file-plus" style="width: 16px; height: 16px;"></i>
            PO Supplier
        </a>
        <a href="gudang-transfer.php" class="btn btn-secondary">
            <i data-feather="shuffle" style="width: 16px; height: 16px;"></i>
            Transfer ke Bisnis
        </a>
    </div>
</div>

<div style="display:grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.25rem;">
    <div class="card" style="padding:1rem;">
        <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:0.35rem;">Total Item</div>
        <div style="font-size:1.75rem; font-weight:800; color:var(--text-primary);"><?php echo $summary['items']; ?></div>
    </div>
    <div class="card" style="padding:1rem;">
        <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:0.35rem;">Total Qty</div>
        <div style="font-size:1.75rem; font-weight:800; color:var(--text-primary);"><?php echo number_format($summary['qty'], 2); ?></div>
    </div>
    <div class="card" style="padding:1rem;">
        <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:0.35rem;">Masuk Hari Ini</div>
        <div style="font-size:1.75rem; font-weight:800; color:#0f9d6a;"><?php echo number_format($summary['incoming_today'], 2); ?></div>
    </div>
    <div class="card" style="padding:1rem;">
        <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:0.35rem;">Keluar Hari Ini</div>
        <div style="font-size:1.75rem; font-weight:800; color:#d83a5b;"><?php echo number_format($summary['outgoing_today'], 2); ?></div>
    </div>
</div>

<div style="display:grid; grid-template-columns: 2fr 1fr; gap: 1.25rem; align-items:start;">
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; gap:1rem; flex-wrap:wrap;">
            <h3 style="font-size:1rem; font-weight:700; margin:0;">Stok Gudang</h3>
            <?php if ($summary['low'] > 0): ?>
                <span class="badge badge-warning"><?php echo $summary['low']; ?> item di bawah reorder</span>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Item</th>
                        <th class="text-right">Qty</th>
                        <th>Unit</th>
                        <th>Supplier</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stockItems)): ?>
                        <tr><td colspan="5" style="text-align:center; padding: 2rem; color: var(--text-muted);">Belum ada stok gudang</td></tr>
                    <?php else: ?>
                        <?php foreach ($stockItems as $item): ?>
                            <tr>
                                <td style="font-weight:600;"><?php echo htmlspecialchars($item['stock_code']); ?></td>
                                <td>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                    <?php if (!empty($item['notes'])): ?><div style="font-size:0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($item['notes']); ?></div><?php endif; ?>
                                </td>
                                <td class="text-right" style="font-weight:700; color:<?php echo ((float)$item['quantity'] <= (float)($item['reorder_level'] ?? 0) && (float)($item['reorder_level'] ?? 0) > 0) ? '#d97706' : 'var(--text-primary)'; ?>;"><?php echo number_format($item['quantity'], 2); ?></td>
                                <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                <td style="font-size:0.813rem;"><?php echo htmlspecialchars($item['supplier_name'] ?: '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div style="display:grid; gap:1.25rem;">
        <div class="card">
            <h3 style="font-size:1rem; font-weight:700; margin-bottom:0.75rem;">Penerimaan PO Pending</h3>
            <div style="display:grid; gap:0.75rem;">
                <?php if (empty($pendingReceipts)): ?>
                    <div style="color:var(--text-muted); font-size:0.875rem;">Tidak ada PO pending penerimaan</div>
                <?php else: ?>
                    <?php foreach ($pendingReceipts as $po): ?>
                        <div style="padding:0.75rem; border:1px solid var(--border); border-radius:0.75rem; background: var(--bg-secondary);">
                            <div style="font-weight:700;"><?php echo htmlspecialchars($po['po_number']); ?></div>
                            <div style="font-size:0.812rem; color:var(--text-muted);"><?php echo htmlspecialchars($po['supplier_name']); ?></div>
                            <div style="font-size:0.812rem; color:var(--text-muted);"><?php echo (int)$po['items_count']; ?> item | <?php echo (int)$po['pending_items']; ?> pending</div>
                            <a href="view-po.php?id=<?php echo (int)$po['id']; ?>" class="btn btn-sm btn-success" style="margin-top:0.5rem;">Terima Barang</a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <h3 style="font-size:1rem; font-weight:700; margin-bottom:0.75rem;">Transfer Terakhir</h3>
            <div style="display:grid; gap:0.75rem;">
                <?php if (empty($recentTransfers)): ?>
                    <div style="color:var(--text-muted); font-size:0.875rem;">Belum ada transfer keluar</div>
                <?php else: ?>
                    <?php foreach ($recentTransfers as $transfer): ?>
                        <div style="padding:0.75rem; border:1px solid var(--border); border-radius:0.75rem; background: var(--bg-secondary);">
                            <div style="font-weight:700;"><?php echo htmlspecialchars($transfer['transfer_number']); ?></div>
                            <div style="font-size:0.812rem; color:var(--text-muted);"><?php echo htmlspecialchars($transfer['target_business_name']); ?></div>
                            <div style="font-size:0.812rem; color:var(--text-muted);"><?php echo (int)$transfer['items_count']; ?> item | <?php echo number_format((float)$transfer['total_qty'], 2); ?> qty</div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>
