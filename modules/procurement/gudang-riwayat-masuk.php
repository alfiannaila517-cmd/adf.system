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
$pageTitle = 'Histori Barang Masuk';

if (function_exists('ensureGudangNasitaOperationalTablesCompatibility')) {
    ensureGudangNasitaOperationalTablesCompatibility();
}

$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
$search   = trim($_GET['q'] ?? '');

// "Barang masuk" mencakup penerimaan resmi dari PO Supplier (in_supplier) DAN
// penambahan stok manual di gudang (adjustment, reference_type='manual_stock').
// reference_type='daily_stock_out' juga bisa memakai movement_type 'adjustment'
// (lihat recordGudangNasitaDailyStockOut) tapi itu barang KELUAR, jadi harus dikecualikan
// supaya histori ini tidak ikut menampilkan stock keluar.
$where  = ["gm.movement_type IN ('in_supplier','adjustment')", "(gm.reference_type IS NULL OR gm.reference_type != 'daily_stock_out')"];
$params = [];

if ($dateFrom !== '') {
    $where[] = 'gm.movement_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'gm.movement_date <= ?';
    $params[] = $dateTo;
}
if ($search !== '') {
    $where[] = '(gs.item_name LIKE ? OR gm.reference_number LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$whereClause = implode(' AND ', $where);

$rows = $db->fetchAll("
    SELECT
        gm.id, gm.movement_date, gm.quantity, gm.unit_price, gm.subtotal,
        gm.reference_id, gm.reference_number, gm.notes, gm.created_at,
        gm.movement_type, gm.reference_type,
        gs.item_name, gs.unit,
        sup.supplier_name,
        u.full_name AS received_by_name
    FROM gudang_nasita_movements gm
    LEFT JOIN gudang_nasita_stock gs ON gs.id = gm.stock_id
    LEFT JOIN purchase_orders_header po ON po.id = gm.reference_id AND gm.reference_type = 'purchase_order'
    LEFT JOIN suppliers sup ON sup.id = po.supplier_id
    LEFT JOIN users u ON u.id = gm.created_by
    WHERE {$whereClause}
    ORDER BY gm.created_at DESC, gm.id DESC
    LIMIT 500
", $params);

$totalQty = 0;
$totalValue = 0;
foreach ($rows as $r) {
    $totalQty += (float)$r['quantity'];
    $totalValue += (float)$r['subtotal'];
}

$forceTheme = 'light';
include '../../includes/header.php';
?>

<div style="margin-bottom:1rem; display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
    <div>
        <h2 style="font-size:1.5rem; font-weight:700; color:var(--text-primary); margin-bottom:0.2rem;">Histori Barang Masuk</h2>
        <p style="color:var(--text-muted); font-size:0.875rem;">Riwayat barang yang diterima dari supplier via PO Supplier Gudang</p>
    </div>
    <a href="gudang-nasita.php" class="btn btn-secondary">
        <i data-feather="arrow-left" style="width:16px;height:16px;"></i> Kembali ke Gudang
    </a>
</div>

<div class="card" style="padding:1rem; margin-bottom:1.25rem;">
    <form method="GET" style="display:flex; gap:1rem; flex-wrap:wrap; align-items:flex-end;">
        <div style="flex:1; min-width:160px;">
            <label style="display:block; font-size:0.875rem; font-weight:500; margin-bottom:0.4rem; color:var(--text-secondary);">Dari Tanggal</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" class="form-control">
        </div>
        <div style="flex:1; min-width:160px;">
            <label style="display:block; font-size:0.875rem; font-weight:500; margin-bottom:0.4rem; color:var(--text-secondary);">Sampai Tanggal</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" class="form-control">
        </div>
        <div style="flex:2; min-width:200px;">
            <label style="display:block; font-size:0.875rem; font-weight:500; margin-bottom:0.4rem; color:var(--text-secondary);">Cari Barang / No. PO</label>
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Nama barang atau nomor PO..." class="form-control">
        </div>
        <button type="submit" class="btn btn-primary">
            <i data-feather="filter" style="width:16px;height:16px;"></i> Terapkan
        </button>
    </form>
</div>

<div style="display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1.25rem;">
    <div class="card" style="flex:1; min-width:200px; padding:1rem;">
        <div style="font-size:0.8rem; color:var(--text-muted); margin-bottom:0.25rem;">Total Baris</div>
        <div style="font-size:1.4rem; font-weight:700; color:var(--text-primary);"><?php echo count($rows); ?></div>
    </div>
    <div class="card" style="flex:1; min-width:200px; padding:1rem;">
        <div style="font-size:0.8rem; color:var(--text-muted); margin-bottom:0.25rem;">Total Qty Masuk</div>
        <div style="font-size:1.4rem; font-weight:700; color:var(--text-primary);"><?php echo number_format($totalQty, 2); ?></div>
    </div>
    <div class="card" style="flex:1; min-width:200px; padding:1rem;">
        <div style="font-size:0.8rem; color:var(--text-muted); margin-bottom:0.25rem;">Total Nilai</div>
        <div style="font-size:1.4rem; font-weight:700; color:#0f9d6a;">Rp <?php echo number_format($totalValue, 0, ',', '.'); ?></div>
    </div>
</div>

<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Nama Barang</th>
                <th>Sumber</th>
                <th>Supplier</th>
                <th class="text-center">Qty</th>
                <th class="text-right">Harga Satuan</th>
                <th class="text-right">Subtotal</th>
                <th>Diterima Oleh</th>
                <th class="text-center">Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="9" style="text-align:center; padding:3rem; color:var(--text-muted);">
                        <i data-feather="inbox" style="width:48px; height:48px; opacity:0.3; margin-bottom:1rem; display:block;"></i>
                        <p>Belum ada histori barang masuk pada periode ini</p>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo date('d M Y', strtotime($r['movement_date'])); ?></td>
                        <td style="font-weight:600; color:var(--text-primary);"><?php echo htmlspecialchars($r['item_name'] ?? '-'); ?></td>
                        <td>
                            <?php if ($r['movement_type'] === 'in_supplier'): ?>
                                <span style="font-size:0.75rem;">PO <?php echo htmlspecialchars($r['reference_number'] ?? '-'); ?></span>
                            <?php else: ?>
                                <span style="background:#e0e7ff; color:#3730a3; padding:0.2rem 0.5rem; border-radius:4px; font-size:0.72rem; font-weight:600;">+ Manual</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($r['supplier_name'] ?? '-'); ?></td>
                        <td class="text-center"><?php echo number_format((float)$r['quantity'], 2); ?> <?php echo htmlspecialchars($r['unit'] ?? ''); ?></td>
                        <td class="text-right">Rp <?php echo number_format((float)$r['unit_price'], 0, ',', '.'); ?></td>
                        <td class="text-right" style="font-weight:700; color:#0f9d6a;">Rp <?php echo number_format((float)$r['subtotal'], 0, ',', '.'); ?></td>
                        <td><?php echo htmlspecialchars($r['received_by_name'] ?? 'System'); ?></td>
                        <td class="text-center">
                            <?php if (!empty($r['reference_id'])): ?>
                                <a href="gudang-po-supplier.php?view=<?php echo (int)$r['reference_id']; ?>" class="btn btn-sm btn-info" style="padding:0.375rem 0.75rem; font-size:0.75rem;">
                                    <i data-feather="eye" style="width:14px;height:14px;"></i> Lihat PO
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include '../../includes/footer.php'; ?>