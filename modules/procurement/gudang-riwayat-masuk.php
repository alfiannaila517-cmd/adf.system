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
$pageTitle = 'Riwayat Barang Masuk';

if (function_exists('ensureGudangNasitaOperationalTablesCompatibility')) {
    ensureGudangNasitaOperationalTablesCompatibility();
}

$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
$search   = trim($_GET['q'] ?? '');
$source   = in_array($_GET['sumber'] ?? '', ['supplier', 'bisnis'], true) ? $_GET['sumber'] : '';

// Barang masuk gudang berasal dari dua sumber:
//  1) Supplier — penerimaan resmi PO Supplier (movement in_supplier / purchase_order).
//  2) Bisnis   — kiriman/suplai bisnis ke Gudang (business_inter_stock_transfers, tujuan gudang-nasita),
//                mis. Narayana kirim pisang. Nilainya dibayar Gudang lewat Tagihan Bisnis.
// Input stok manual (adjustment) sengaja tidak dimasukkan.
$rows = [];

if ($source !== 'bisnis') {
    $where  = ["gm.movement_type = 'in_supplier'", "gm.reference_type = 'purchase_order'"];
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
    $supplierRows = $db->fetchAll("
        SELECT gm.movement_date, gm.quantity, gm.unit_price, gm.subtotal, gm.reference_id,
               gm.reference_number, gm.created_at, gs.item_name, gs.unit,
               sup.supplier_name, u.full_name AS received_by_name
        FROM gudang_nasita_movements gm
        LEFT JOIN gudang_nasita_stock gs ON gs.id = gm.stock_id
        LEFT JOIN purchase_orders_header po ON po.id = gm.reference_id AND gm.reference_type = 'purchase_order'
        LEFT JOIN suppliers sup ON sup.id = po.supplier_id
        LEFT JOIN users u ON u.id = gm.created_by
        WHERE " . implode(' AND ', $where) . "
        ORDER BY gm.created_at DESC, gm.id DESC
        LIMIT 500
    ", $params) ?: [];
    foreach ($supplierRows as $r) {
        $rows[] = [
            'date'        => $r['movement_date'],
            'sort'        => $r['created_at'] ?: $r['movement_date'],
            'item_name'   => $r['item_name'],
            'unit'        => $r['unit'],
            'quantity'    => (float)$r['quantity'],
            'unit_price'  => (float)$r['unit_price'],
            'subtotal'    => (float)$r['subtotal'],
            'kind'        => 'supplier',
            'ref'         => 'PO ' . ($r['reference_number'] ?? '-'),
            'from'        => $r['supplier_name'] ?? '-',
            'received_by' => $r['received_by_name'] ?? 'System',
            'po_id'       => (int)($r['reference_id'] ?? 0),
            'estimated'   => false,
        ];
    }
}

if ($source !== 'supplier') {
    try {
        $userNames = [];
        foreach ($db->fetchAll('SELECT id, full_name FROM users') ?: [] as $u) {
            $userNames[(int)$u['id']] = $u['full_name'];
        }
        foreach (gudangInterTransferValuedRows(gudangMasterPdo()) as $r) {
            if ($r['target_slug'] !== 'gudang-nasita' || $r['source_slug'] === 'gudang-nasita') {
                continue;
            }
            $day = substr((string)$r['created_at'], 0, 10);
            if (($dateFrom !== '' && $day < $dateFrom) || ($dateTo !== '' && $day > $dateTo)) {
                continue;
            }
            if ($search !== '' && stripos((string)$r['item_name'], $search) === false && stripos((string)$r['transfer_number'], $search) === false) {
                continue;
            }
            $qty = (float)$r['quantity'];
            $rows[] = [
                'date'        => $day,
                'sort'        => $r['created_at'],
                'item_name'   => $r['item_name'],
                'unit'        => $r['unit'],
                'quantity'    => $qty,
                'unit_price'  => $qty > 0 ? $r['nilai'] / $qty : 0,
                'subtotal'    => $r['nilai'],
                'kind'        => 'bisnis',
                'slug'        => $r['source_slug'],
                'ref'         => $r['transfer_number'] ?: 'Kiriman bisnis',
                'from'        => $r['source_business_name'] ?: $r['source_business_slug'],
                'received_by' => $userNames[(int)$r['created_by']] ?? 'System',
                'po_id'       => 0,
                'estimated'   => $r['is_estimated'],
            ];
        }
    } catch (Throwable $e) {
        error_log('gudang-riwayat-masuk kiriman bisnis: ' . $e->getMessage());
    }
}

usort($rows, function ($x, $y) {
    return strcmp((string)$y['sort'], (string)$x['sort']);
});

$totalQty = 0;
$totalValue = 0;
$totalFromBusiness = 0;
foreach ($rows as $r) {
    $totalQty += $r['quantity'];
    $totalValue += $r['subtotal'];
    if ($r['kind'] === 'bisnis') {
        $totalFromBusiness += $r['subtotal'];
    }
}

$forceTheme = 'light';
include '../../includes/header.php';
?>

<div style="margin-bottom:1rem; display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
    <div>
        <h2 style="font-size:1.5rem; font-weight:700; color:var(--text-primary); margin-bottom:0.2rem;">Riwayat Barang Masuk</h2>
        <p style="color:var(--text-muted); font-size:0.875rem;">Semua barang yang masuk ke gudang: dari <b>supplier</b> (Order ke Supplier) dan dari <b>bisnis</b> (mis. Narayana kirim pisang).</p>
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
        <div style="flex:1; min-width:150px;">
            <label style="display:block; font-size:0.875rem; font-weight:500; margin-bottom:0.4rem; color:var(--text-secondary);">Sumber</label>
            <select name="sumber" class="form-control">
                <option value="">Semua</option>
                <option value="supplier" <?php echo $source === 'supplier' ? 'selected' : ''; ?>>Dari supplier</option>
                <option value="bisnis" <?php echo $source === 'bisnis' ? 'selected' : ''; ?>>Dari bisnis</option>
            </select>
        </div>
        <div style="flex:2; min-width:200px;">
            <label style="display:block; font-size:0.875rem; font-weight:500; margin-bottom:0.4rem; color:var(--text-secondary);">Cari Barang / No. PO / No. Kiriman</label>
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
        <?php if ($totalFromBusiness > 0): ?><div style="font-size:0.72rem; color:var(--text-muted);">termasuk dari bisnis Rp <?php echo number_format($totalFromBusiness, 0, ',', '.'); ?></div><?php endif; ?>
    </div>
</div>

<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Nama Barang</th>
                <th>Sumber</th>
                <th>Dari</th>
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
                        <td><?php echo date('d M Y', strtotime($r['date'])); ?></td>
                        <td style="font-weight:600; color:var(--text-primary);"><?php echo htmlspecialchars($r['item_name'] ?? '-'); ?></td>
                        <td>
                            <?php if ($r['kind'] === 'supplier'): ?>
                                <span style="font-size:0.75rem;"><?php echo htmlspecialchars($r['ref']); ?></span>
                            <?php else: ?>
                                <span style="background:#ede9fe; color:#5b21b6; padding:0.15rem 0.45rem; border-radius:4px; font-size:0.7rem; font-weight:700;">Kiriman bisnis</span>
                                <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;"><?php echo htmlspecialchars($r['ref']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars((string)$r['from']); ?></td>
                        <td class="text-center"><?php echo number_format($r['quantity'], 2); ?> <?php echo htmlspecialchars($r['unit'] ?? ''); ?></td>
                        <td class="text-right">Rp <?php echo number_format($r['unit_price'], 0, ',', '.'); ?><?php if ($r['estimated']): ?><div style="font-size:0.66rem; color:#d97706;" title="Harga belum diisi saat dikirim — memakai harga stok/katalog gudang">estimasi</div><?php endif; ?></td>
                        <td class="text-right" style="font-weight:700; color:#0f9d6a;">Rp <?php echo number_format($r['subtotal'], 0, ',', '.'); ?></td>
                        <td><?php echo htmlspecialchars((string)$r['received_by']); ?></td>
                        <td class="text-center">
                            <?php if ($r['po_id']): ?>
                                <a href="gudang-po-supplier.php?view=<?php echo $r['po_id']; ?>" class="btn btn-sm btn-info" style="padding:0.375rem 0.75rem; font-size:0.75rem;">
                                    <i data-feather="eye" style="width:14px;height:14px;"></i> Lihat PO
                                </a>
                            <?php elseif ($r['kind'] === 'bisnis'): ?>
                                <a href="gudang-tagihan.php#rincian-<?php echo htmlspecialchars(preg_replace('/[^a-z0-9-]/', '', (string)$r['slug'])); ?>" class="btn btn-sm btn-secondary" style="padding:0.375rem 0.75rem; font-size:0.75rem;">Tagihan</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include '../../includes/footer.php'; ?>