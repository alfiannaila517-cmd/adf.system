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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual_stock_in') {
    $itemName = trim($_POST['item_name'] ?? '');
    $category = trim($_POST['category'] ?? 'lainnya');
    $unit = trim($_POST['unit'] ?? 'pcs');
    $quantity = (float)($_POST['quantity'] ?? 0);
    $supplierName = trim($_POST['supplier_name'] ?? '');
    $reorderLevel = isset($_POST['reorder_level']) ? (float)$_POST['reorder_level'] : null;
    $notes = trim($_POST['notes'] ?? '');

    $result = addGudangNasitaManualStock($itemName, $unit, $quantity, $currentUser['id'], [
        'category' => $category,
        'supplier_name' => $supplierName,
        'reorder_level' => $reorderLevel,
        'notes' => $notes,
    ]);

    if ($result['success']) {
        $_SESSION['success'] = $result['message'];
    } else {
        $_SESSION['error'] = $result['message'];
    }

    header('Location: gudang-nasita.php');
    exit;
}

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

$businessFilterSql = '';
$businessFilterParams = [];
$activeBusinessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;
if ($activeBusinessId > 0) {
    $businessFilterSql = ' AND (poh.business_id = ? OR poh.business_id IS NULL)';
    $businessFilterParams[] = $activeBusinessId;
}

$pendingReceipts = $db->fetchAll("\n    SELECT poh.id, poh.po_number, poh.po_date, poh.status, poh.supplier_id, s.supplier_name,\n           b.id AS source_business_id, b.business_name AS source_business_name,\n           COUNT(pod.id) AS items_count,\n           SUM(CASE WHEN COALESCE(pod.received_quantity,0) < pod.quantity THEN 1 ELSE 0 END) AS pending_items\n    FROM purchase_orders_header poh\n    LEFT JOIN suppliers s ON s.id = poh.supplier_id\n    LEFT JOIN businesses b ON b.id = poh.business_id\n    LEFT JOIN purchase_orders_detail pod ON pod.po_header_id = poh.id\n    WHERE poh.status NOT IN ('completed','cancelled','received','rejected')" . $businessFilterSql . "\n    GROUP BY poh.id\n    HAVING pending_items > 0\n    ORDER BY poh.created_at DESC\n    LIMIT 12\n", $businessFilterParams);
$pendingPoCount = count($pendingReceipts);

include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem; display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
    <div>
        <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem; display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
            Gudang Nasita
            <?php if ($pendingPoCount > 0): ?>
                <span style="background:#ef4444; color:#fff; border-radius:999px; padding:0.2rem 0.55rem; font-size:0.75rem; font-weight:800;">PO Masuk: <?php echo (int)$pendingPoCount; ?></span>
            <?php endif; ?>
        </h2>
        <p style="color: var(--text-muted); font-size: 0.875rem;">Stok pusat, penerimaan supplier, dan kontrol barang keluar</p>
    </div>
    <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
        <button type="button" class="btn btn-success" onclick="document.getElementById('manualStockModal').style.display='flex'">
            <i data-feather="plus-square" style="width: 16px; height: 16px;"></i>
            Input Stock Manual
        </button>
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

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success" style="margin-bottom:1rem;"><?php echo htmlspecialchars($_SESSION['success']);
                                                                    unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger" style="margin-bottom:1rem;"><?php echo htmlspecialchars($_SESSION['error']);
                                                                unset($_SESSION['error']); ?></div>
<?php endif; ?>

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
                        <th>Kategori</th>
                        <th>Item</th>
                        <th class="text-right">Qty</th>
                        <th>Unit</th>
                        <th>Supplier</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stockItems)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding: 2rem; color: var(--text-muted);">Belum ada stok gudang</td>
                        </tr>
                    <?php else: ?>
                        <?php $currentCategory = null; ?>
                        <?php foreach ($stockItems as $item): ?>
                            <?php $rowCategory = trim((string)($item['category'] ?? '')); ?>
                            <?php if ($rowCategory === '') {
                                $rowCategory = 'lainnya';
                            } ?>
                            <?php if ($currentCategory !== $rowCategory): ?>
                                <?php $currentCategory = $rowCategory; ?>
                                <tr>
                                    <td colspan="7" style="background:#f8fafc; font-weight:700; color:#334155; text-transform:capitalize; border-top:1px solid var(--border);">
                                        Kategori: <?php echo htmlspecialchars($currentCategory); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <td style="font-weight:600;"><?php echo htmlspecialchars($item['stock_code']); ?></td>
                                <td><span class="badge badge-info" style="text-transform:capitalize;"><?php echo htmlspecialchars($rowCategory); ?></span></td>
                                <td>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                    <?php if (!empty($item['notes'])): ?><div style="font-size:0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($item['notes']); ?></div><?php endif; ?>
                                </td>
                                <td class="text-right" style="font-weight:700; color:<?php echo ((float)$item['quantity'] <= (float)($item['reorder_level'] ?? 0) && (float)($item['reorder_level'] ?? 0) > 0) ? '#d97706' : 'var(--text-primary)'; ?>;"><?php echo number_format($item['quantity'], 2); ?></td>
                                <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                <td style="font-size:0.813rem;"><?php echo htmlspecialchars($item['supplier_name'] ?: '-'); ?></td>
                                <td>
                                    <a href="gudang-transfer.php?stock_id=<?php echo (int)$item['id']; ?>" class="btn btn-sm btn-primary">
                                        <i data-feather="send" style="width:14px; height:14px;"></i>
                                        Transfer Stock
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div style="display:grid; gap:1.25rem;">
        <div class="card">
            <h3 style="font-size:1rem; font-weight:700; margin-bottom:0.75rem; display:flex; align-items:center; justify-content:space-between; gap:0.5rem;">
                <span>PO Bisnis Menunggu Proses Gudang</span>
                <?php if ($pendingPoCount > 0): ?>
                    <span style="background:#ef4444; color:#fff; min-width:22px; height:22px; border-radius:11px; display:inline-flex; align-items:center; justify-content:center; font-size:0.7rem; font-weight:800; padding:0 6px;"><?php echo (int)$pendingPoCount; ?></span>
                <?php endif; ?>
            </h3>
            <div style="display:grid; gap:0.75rem;">
                <?php if (empty($pendingReceipts)): ?>
                    <div style="color:var(--text-muted); font-size:0.875rem;">Tidak ada PO bisnis yang perlu diproses gudang</div>
                <?php else: ?>
                    <?php foreach ($pendingReceipts as $po): ?>
                        <div style="padding:0.75rem; border:1px solid var(--border); border-radius:0.75rem; background: var(--bg-secondary);">
                            <div style="font-weight:700;"><?php echo htmlspecialchars($po['po_number']); ?></div>
                            <div style="font-size:0.812rem; color:#0f172a; font-weight:700;">
                                <?php echo htmlspecialchars(($po['source_business_name'] ?: 'Business #' . (int)($po['source_business_id'] ?? 0)) . ' PO'); ?>
                            </div>
                            <div style="font-size:0.812rem; color:var(--text-muted);">Status: <?php echo htmlspecialchars($po['status']); ?></div>
                            <div style="font-size:0.812rem; color:var(--text-muted);"><?php echo (int)$po['items_count']; ?> item | <?php echo (int)$po['pending_items']; ?> belum diproses</div>
                            <div style="display:flex; gap:0.5rem; margin-top:0.5rem; flex-wrap:wrap;">
                                <a href="view-po.php?id=<?php echo (int)$po['id']; ?>" class="btn btn-sm btn-primary">Buka PO</a>
                                <a href="gudang-transfer.php?po_id=<?php echo (int)$po['id']; ?>" class="btn btn-sm btn-success">Siapkan Transfer</a>
                            </div>
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

    document.addEventListener('click', function(e) {
        var modal = document.getElementById('manualStockModal');
        if (e.target === modal) {
            modal.style.display = 'none';
        }
    });
</script>

<div id="manualStockModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.45); z-index:2000; align-items:center; justify-content:center; padding:1rem;">
    <div class="card" style="width:min(640px, 100%); max-height:90vh; overflow:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3 style="font-size:1.05rem; margin:0;">Input Stock Manual</h3>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('manualStockModal').style.display='none'">Tutup</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="manual_stock_in">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.9rem;">
                <div>
                    <label class="form-label">Nama Item *</label>
                    <input type="text" name="item_name" class="form-control" required>
                </div>
                <div>
                    <label class="form-label">Kategori *</label>
                    <input type="text" name="category" class="form-control" list="manualStockCategoryList" placeholder="Contoh: minuman" required>
                    <datalist id="manualStockCategoryList">
                        <option value="minuman"></option>
                        <option value="frozen"></option>
                        <option value="alat"></option>
                        <option value="sayur"></option>
                        <option value="daging"></option>
                        <option value="sembako"></option>
                        <option value="bumbu"></option>
                        <option value="lainnya"></option>
                    </datalist>
                </div>
                <div>
                    <label class="form-label">Unit *</label>
                    <input type="text" name="unit" class="form-control" value="pcs" required>
                </div>
                <div>
                    <label class="form-label">Qty Masuk *</label>
                    <input type="number" name="quantity" class="form-control" step="0.01" min="0.01" required>
                </div>
                <div>
                    <label class="form-label">Reorder Level</label>
                    <input type="number" name="reorder_level" class="form-control" step="0.01" min="0" value="0">
                </div>
                <div style="grid-column:1 / span 2;">
                    <label class="form-label">Supplier (opsional)</label>
                    <input type="text" name="supplier_name" class="form-control" placeholder="Contoh: CV Sumber Jaya">
                </div>
                <div style="grid-column:1 / span 2;">
                    <label class="form-label">Catatan</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Contoh: Stok awal sebelum sistem PO aktif"></textarea>
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('manualStockModal').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-success">Simpan Stock Manual</button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>