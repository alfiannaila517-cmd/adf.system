<?php

/**
 * Gudang Nasita — Dashboard
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();
if (!($auth->hasPermission('gudang_nasita') || $auth->hasPermission('warehouse') || $auth->hasPermission('gudang_view'))) {
    http_response_code(403);
    echo 'Akses ditolak.';
    exit;
}

$db = Database::getInstance();
$pageTitle = 'Dashboard Gudang';

// ── Summary stats ─────────────────────────────────────────────────────────────
$totalItems    = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM gudang_nasita_stock WHERE COALESCE(is_active,1)=1")['c'] ?? 0);
$totalQty      = (float)($db->fetchOne("SELECT COALESCE(SUM(quantity),0) AS q FROM gudang_nasita_stock WHERE COALESCE(is_active,1)=1")['q'] ?? 0);
$lowStockCount = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM gudang_nasita_stock WHERE COALESCE(is_active,1)=1 AND reorder_level>0 AND quantity<=reorder_level")['c'] ?? 0);
$transfersToday = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM gudang_nasita_transfers WHERE DATE(COALESCE(tanggal_transfer,transfer_date,created_at))=CURDATE()")['c'] ?? 0);

// ── Low stock items ────────────────────────────────────────────────────────────
$lowStockItems = $db->fetchAll(
    "SELECT item_name, quantity, reorder_level, unit, COALESCE(category,'lainnya') AS category
     FROM gudang_nasita_stock
     WHERE COALESCE(is_active,1)=1 AND reorder_level>0 AND quantity<=reorder_level
     ORDER BY (quantity/reorder_level) ASC LIMIT 8"
) ?: [];

// ── Barang masuk terbaru ───────────────────────────────────────────────────────
$masukTerbaru = $db->fetchAll(
    "SELECT gm.quantity, gm.movement_type, gm.notes,
            COALESCE(gm.movement_date, gm.created_at) AS tgl,
            gs.item_name, gs.unit
     FROM gudang_nasita_movements gm
     JOIN gudang_nasita_stock gs ON gm.stock_id = gs.id
     WHERE gm.movement_type IN ('in_supplier','in_manual','manual_in')
     ORDER BY COALESCE(gm.movement_date, gm.created_at) DESC LIMIT 8"
) ?: [];

// ── Barang keluar / transfer terbaru ─────────────────────────────────────────
$keluarTerbaru = $db->fetchAll(
    "SELECT gm.quantity, gm.notes,
            COALESCE(gm.movement_date, gm.created_at) AS tgl,
            gs.item_name, gs.unit
     FROM gudang_nasita_movements gm
     JOIN gudang_nasita_stock gs ON gm.stock_id = gs.id
     WHERE gm.movement_type IN ('out_transfer','transfer_out')
     ORDER BY COALESCE(gm.movement_date, gm.created_at) DESC LIMIT 8"
) ?: [];

// ── Permintaan terbaru (transfers) ────────────────────────────────────────────
$permintaanTerbaru = $db->fetchAll(
    "SELECT COALESCE(transfer_number, no_transfer) AS no_transfer,
            COALESCE(target_business_name, bisnis_tujuan) AS bisnis_tujuan,
            COALESCE(tanggal_transfer, transfer_date, created_at) AS tgl,
            total_qty, items_count,
            COALESCE(status,'completed') AS status
     FROM gudang_nasita_transfers
     ORDER BY COALESCE(tanggal_transfer, transfer_date, created_at) DESC LIMIT 8"
) ?: [];

// ── Transfer per bisnis per minggu dalam 1 bulan terakhir (untuk bar chart) ────────────
// Label: Minggu 1..4 | Dataset: tiap bisnis
$chartWeekLabels = ['Minggu 1', 'Minggu 2', 'Minggu 3', 'Minggu 4'];
$allBizRows = $db->fetchAll(
    "SELECT COALESCE(target_business_name, bisnis_tujuan, 'Lainnya') AS bisnis,
            CEIL(DAY(COALESCE(tanggal_transfer,transfer_date,created_at))/7) AS minggu,
            COALESCE(SUM(total_qty),0) AS total
     FROM gudang_nasita_transfers
     WHERE COALESCE(tanggal_transfer,transfer_date,created_at) >= DATE_FORMAT(CURDATE(),'%Y-%m-01')
     GROUP BY bisnis, minggu
     ORDER BY bisnis, minggu"
) ?: [];

// Build dataset: [bisnis => [w1,w2,w3,w4]]
$bizDatasets = [];
foreach ($allBizRows as $r) {
    $b = (string)$r['bisnis'];
    if (!isset($bizDatasets[$b])) $bizDatasets[$b] = [0, 0, 0, 0];
    $w = max(1, min(4, (int)$r['minggu'])) - 1;
    $bizDatasets[$b][$w] += (float)$r['total'];
}
// Month label for chart title
$chartMonthLabel = date('F Y');

// ── Transfer qty per bisnis for pie chart ────────────────────────────────────
$bizTransferRows = $db->fetchAll(
    "SELECT COALESCE(target_business_name, bisnis_tujuan, 'Lainnya') AS bisnis,
            COALESCE(SUM(total_qty),0) AS total
     FROM gudang_nasita_transfers
     GROUP BY COALESCE(target_business_name, bisnis_tujuan, 'Lainnya')
     ORDER BY total DESC LIMIT 6"
) ?: [];

// ── Histori PO ke Supplier (PO gudang sendiri ke supplier) ────────────────────
$poSupplierHistory = $db->fetchAll(
    "SELECT poh.id, poh.po_number, poh.po_date, poh.status, poh.created_at,
            COUNT(pod.id) AS items_count
     FROM purchase_orders_header poh
     LEFT JOIN purchase_orders_detail pod ON pod.po_header_id = poh.id
     WHERE poh.business_id IS NULL AND poh.po_number LIKE 'GDN-%'
     GROUP BY poh.id
     ORDER BY COALESCE(poh.po_date, poh.created_at) DESC LIMIT 8"
) ?: [];

// ── Histori Barang Datang (penerimaan dari PO supplier) ───────────────────────
$barangDatangHistory = $db->fetchAll(
    "SELECT gm.quantity, gm.reference_number AS po_number, gm.notes,
            COALESCE(gm.movement_date, gm.created_at) AS tgl,
            gs.item_name, gs.unit
     FROM gudang_nasita_movements gm
     JOIN gudang_nasita_stock gs ON gm.stock_id = gs.id
     WHERE gm.movement_type = 'in_supplier'
     ORDER BY COALESCE(gm.movement_date, gm.created_at) DESC LIMIT 8"
) ?: [];

// ── Histori terkirim per bisnis (ringkasan) ───────────────────────────────────
$terkirimPerBisnis = $db->fetchAll(
    "SELECT COALESCE(target_business_name, bisnis_tujuan, 'Lainnya') AS bisnis,
            COUNT(*) AS total_transfer,
            COALESCE(SUM(total_qty),0) AS total_qty,
            MAX(COALESCE(tanggal_transfer, transfer_date, created_at)) AS terakhir
     FROM gudang_nasita_transfers
     GROUP BY COALESCE(target_business_name, bisnis_tujuan, 'Lainnya')
     ORDER BY total_qty DESC"
) ?: [];

// ── Lonceng PO: PO masuk dari bisnis yang belum diproses gudang ───────────────
$pendingBusinessPo = [];
try {
    $pendingBusinessPo = getGudangNasitaPendingBusinessPo();
} catch (Throwable $e) {
    error_log('Dashboard Gudang pending PO error: ' . $e->getMessage());
}
$pendingPoCount = count($pendingBusinessPo);

$forceTheme = 'light';
include __DIR__ . '/../../includes/header.php';
?>

<style>
    .gd-stat {
        border-radius: 1rem;
        padding: 1.2rem 1.4rem;
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .gd-stat-icon {
        width: 52px;
        height: 52px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .gd-stat-icon svg {
        width: 24px;
        height: 24px;
    }

    .gd-card-title {
        font-size: .78rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: var(--text-muted);
        margin-bottom: .2rem;
    }

    .gd-card-val {
        font-size: 2rem;
        font-weight: 800;
        line-height: 1;
        color: var(--text-primary);
    }

    .gd-card-sub {
        font-size: .76rem;
        color: var(--text-muted);
        margin-top: .2rem;
    }

    .gd-section-title {
        font-size: .95rem;
        font-weight: 700;
        color: var(--text-primary);
        margin: 0 0 .85rem;
    }

    .gd-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .83rem;
    }

    .gd-table th {
        font-size: .7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: var(--text-muted);
        padding: .45rem .7rem;
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
    }

    .gd-table td {
        padding: .55rem .7rem;
        border-bottom: 1px solid var(--border);
    }

    .gd-table tr:last-child td {
        border-bottom: none;
    }

    .gd-badge {
        display: inline-block;
        padding: .2rem .6rem;
        border-radius: 999px;
        font-size: .7rem;
        font-weight: 700;
    }

    .prog-bar-wrap {
        height: 5px;
        background: var(--bg-tertiary, #e2e8f0);
        border-radius: 3px;
        overflow: hidden;
        min-width: 50px;
    }

    .prog-bar-fill {
        height: 100%;
        border-radius: 3px;
    }
</style>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.4rem;flex-wrap:wrap;gap:.75rem;">
    <div>
        <h2 style="font-size:1.45rem;font-weight:800;margin:0;color:var(--text-primary);display:flex;align-items:center;gap:.6rem;">
            Dashboard Gudang Nasita
            <?php if ($pendingPoCount > 0): ?>
                <span style="background:#ef4444;color:#fff;border-radius:999px;padding:.2rem .55rem;font-size:.75rem;font-weight:800;">🔔 PO Masuk: <?php echo (int)$pendingPoCount; ?></span>
            <?php endif; ?>
        </h2>
        <p style="font-size:.85rem;color:var(--text-muted);margin:.2rem 0 0;">Ringkasan operasional gudang hari ini &mdash; <?php echo date('l, d F Y'); ?></p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a href="<?php echo BASE_URL; ?>/modules/procurement/gudang-nasita.php" class="btn btn-primary" style="font-size:.82rem;"><i data-feather="archive" style="width:14px;height:14px;"></i> Stock Gudang</a>
        <a href="<?php echo BASE_URL; ?>/modules/procurement/gudang-transfer.php" class="btn btn-success" style="font-size:.82rem;"><i data-feather="send" style="width:14px;height:14px;"></i> Transfer</a>
    </div>
</div>

<!-- Stat cards -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.25rem;">
    <div class="card gd-stat">
        <div class="gd-stat-icon" style="background:#ede9fe;color:#7c3aed;"><i data-feather="package"></i></div>
        <div>
            <div class="gd-card-title">Total Item</div>
            <div class="gd-card-val"><?php echo $totalItems; ?></div>
            <div class="gd-card-sub">Produk aktif</div>
        </div>
    </div>
    <div class="card gd-stat">
        <div class="gd-stat-icon" style="background:#dcfce7;color:#16a34a;"><i data-feather="layers"></i></div>
        <div>
            <div class="gd-card-title">Total Qty</div>
            <div class="gd-card-val"><?php echo number_format($totalQty, 0, ',', '.'); ?></div>
            <div class="gd-card-sub">Unit keseluruhan</div>
        </div>
    </div>
    <div class="card gd-stat" style="<?php echo $lowStockCount > 0 ? 'border-left:3px solid #f59e0b;' : ''; ?>">
        <div class="gd-stat-icon" style="background:#fef3c7;color:#d97706;"><i data-feather="alert-triangle"></i></div>
        <div>
            <div class="gd-card-title">Stok Menipis</div>
            <div class="gd-card-val" style="color:<?php echo $lowStockCount > 0 ? '#d97706' : 'var(--text-primary)'; ?>"><?php echo $lowStockCount; ?></div>
            <div class="gd-card-sub">Di bawah reorder</div>
        </div>
    </div>
    <div class="card gd-stat">
        <div class="gd-stat-icon" style="background:#dbeafe;color:#2563eb;"><i data-feather="send"></i></div>
        <div>
            <div class="gd-card-title">Transfer Hari Ini</div>
            <div class="gd-card-val"><?php echo $transfersToday; ?></div>
            <div class="gd-card-sub">Pengiriman ke bisnis</div>
        </div>
    </div>
</div>

<!-- Charts -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:1rem;margin-bottom:1.25rem;">
    <div class="card" style="padding:1.25rem;">
        <div class="gd-section-title">📊 Barang Terkirim per Bisnis &mdash; <?php echo $chartMonthLabel; ?></div>
        <?php if (empty($bizDatasets)): ?>
            <div style="display:flex;align-items:center;justify-content:center;height:120px;color:var(--text-muted);font-size:.875rem;">Belum ada data transfer bulan ini</div>
        <?php else: ?>
            <canvas id="movementChart" height="120"></canvas>
        <?php endif; ?>
    </div>
    <div class="card" style="padding:1.25rem;display:flex;flex-direction:column;">
        <div class="gd-section-title">🏢 Distribusi Barang Terkirim</div>
        <?php if (empty($bizTransferRows)): ?>
            <div style="flex:1;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:.875rem;">Belum ada data transfer</div>
        <?php else: ?>
            <div style="flex:1;display:flex;align-items:center;justify-content:center;">
                <canvas id="bizPieChart" style="max-width:180px;max-height:180px;"></canvas>
            </div>
            <!-- Legend below chart -->
            <div id="bizPieLegend" style="display:flex;flex-wrap:wrap;gap:.4rem .75rem;justify-content:center;margin-top:.85rem;"></div>
        <?php endif; ?>
    </div>
</div>

<!-- 3 table panels -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.25rem;">

    <!-- Stok menipis -->
    <div class="card" style="padding:1.1rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;">
            <div class="gd-section-title" style="margin:0;color:#d97706;">⚠️ Stok Menipis</div>
            <?php if ($lowStockCount > 0): ?><span class="gd-badge" style="background:#fef3c7;color:#92400e;"><?php echo $lowStockCount; ?></span><?php endif; ?>
        </div>
        <?php if (empty($lowStockItems)): ?>
            <div style="text-align:center;padding:1.5rem;color:#16a34a;font-size:.85rem;">✅ Semua stok aman</div>
        <?php else: ?>
            <table class="gd-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Sisa</th>
                        <th>%</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lowStockItems as $it):
                        $pct = min(100, $it['reorder_level'] > 0 ? round($it['quantity'] / $it['reorder_level'] * 100) : 0);
                        $bc = $pct <= 25 ? '#ef4444' : ($pct <= 60 ? '#f59e0b' : '#22c55e');
                    ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;font-size:.81rem;"><?php echo htmlspecialchars($it['item_name']); ?></div>
                                <div style="font-size:.7rem;color:var(--text-muted);"><?php echo htmlspecialchars($it['category']); ?></div>
                            </td>
                            <td style="font-weight:700;color:#ef4444;white-space:nowrap;"><?php echo number_format($it['quantity'], 0) . ' ' . $it['unit']; ?></td>
                            <td>
                                <div class="prog-bar-wrap">
                                    <div class="prog-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $bc; ?>;"></div>
                                </div>
                                <div style="font-size:.67rem;color:var(--text-muted);margin-top:1px;"><?php echo $pct; ?>%</div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Barang masuk terbaru -->
    <div class="card" style="padding:1.1rem;">
        <div class="gd-section-title" style="color:#16a34a;">📦 Barang Masuk Terbaru</div>
        <?php if (empty($masukTerbaru)): ?>
            <div style="text-align:center;padding:1.5rem;color:var(--text-muted);font-size:.85rem;">Belum ada data</div>
        <?php else: ?>
            <table class="gd-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Tgl</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($masukTerbaru as $m): ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;font-size:.81rem;"><?php echo htmlspecialchars($m['item_name']); ?></div>
                                <div style="font-size:.7rem;color:var(--text-muted);"><?php echo htmlspecialchars($m['notes'] ?? ''); ?></div>
                            </td>
                            <td><span style="font-weight:700;color:#16a34a;">+<?php echo number_format($m['quantity'], 0); ?></span> <span style="font-size:.7rem;color:var(--text-muted);"><?php echo $m['unit']; ?></span></td>
                            <td style="font-size:.71rem;color:var(--text-muted);white-space:nowrap;"><?php echo date('d M', strtotime($m['tgl'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Barang keluar terbaru -->
    <div class="card" style="padding:1.1rem;">
        <div class="gd-section-title" style="color:#2563eb;">🚚 Barang Keluar Terbaru</div>
        <?php if (empty($keluarTerbaru)): ?>
            <div style="text-align:center;padding:1.5rem;color:var(--text-muted);font-size:.85rem;">Belum ada data</div>
        <?php else: ?>
            <table class="gd-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Tgl</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($keluarTerbaru as $k): ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;font-size:.81rem;"><?php echo htmlspecialchars($k['item_name']); ?></div>
                                <div style="font-size:.7rem;color:var(--text-muted);"><?php echo htmlspecialchars($k['notes'] ?? ''); ?></div>
                            </td>
                            <td><span style="font-weight:700;color:#2563eb;"><?php echo number_format($k['quantity'], 0); ?></span> <span style="font-size:.7rem;color:var(--text-muted);"><?php echo $k['unit']; ?></span></td>
                            <td style="font-size:.71rem;color:var(--text-muted);white-space:nowrap;"><?php echo date('d M', strtotime($k['tgl'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Permintaan / transfer terbaru -->
<div class="card" style="padding:1.1rem 1.25rem;margin-bottom:.5rem;">
    <div class="gd-section-title">📋 Riwayat Transfer Terbaru</div>
    <?php if (empty($permintaanTerbaru)): ?>
        <div style="text-align:center;padding:1.5rem;color:var(--text-muted);font-size:.875rem;">Belum ada data transfer</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="gd-table">
                <thead>
                    <tr>
                        <th>No Transfer</th>
                        <th>Bisnis Tujuan</th>
                        <th>Tanggal</th>
                        <th class="text-right">Qty</th>
                        <th>Item</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($permintaanTerbaru as $tr):
                        $sc = match (strtolower($tr['status'] ?? 'completed')) {
                            'completed', 'received' => ['#dcfce7', '#166534'],
                            'submitted', 'pending' => ['#fef3c7', '#92400e'],
                            'cancelled' => ['#fee2e2', '#991b1b'],
                            default => ['#e0e7ff', '#3730a3']
                        };
                    ?>
                        <tr>
                            <td style="font-weight:700;font-size:.81rem;"><?php echo htmlspecialchars($tr['no_transfer'] ?? '-'); ?></td>
                            <td style="font-weight:600;"><?php echo htmlspecialchars($tr['bisnis_tujuan'] ?? '-'); ?></td>
                            <td style="font-size:.76rem;color:var(--text-muted);"><?php echo $tr['tgl'] ? date('d M Y H:i', strtotime($tr['tgl'])) : '-'; ?></td>
                            <td class="text-right" style="font-weight:700;"><?php echo number_format((float)($tr['total_qty'] ?? 0), 0, ',', '.'); ?></td>
                            <td><?php echo (int)($tr['items_count'] ?? 0); ?> item</td>
                            <td><span class="gd-badge" style="background:<?php echo $sc[0]; ?>;color:<?php echo $sc[1]; ?>;"><?php echo strtoupper($tr['status'] ?? 'DONE'); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- 3 panel: Histori PO ke Supplier / Histori Barang Datang / PO Masuk dari Bisnis -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.25rem;">

    <!-- Histori PO ke Supplier -->
    <div class="card" style="padding:1.1rem;">
        <div class="gd-section-title" style="color:#7c3aed;">🧾 Histori PO ke Supplier</div>
        <?php if (empty($poSupplierHistory)): ?>
            <div style="text-align:center;padding:1.5rem;color:var(--text-muted);font-size:.85rem;">Belum ada PO ke supplier</div>
        <?php else: ?>
            <table class="gd-table">
                <thead>
                    <tr>
                        <th>No PO</th>
                        <th>Item</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($poSupplierHistory as $po):
                        $psc = match (strtolower($po['status'] ?? '')) {
                            'completed', 'received' => ['#dcfce7', '#166534'],
                            'submitted', 'pending', 'waiting' => ['#fef3c7', '#92400e'],
                            'approved', 'partially_received' => ['#dbeafe', '#1e40af'],
                            'cancelled', 'rejected' => ['#fee2e2', '#991b1b'],
                            default => ['#e5e7eb', '#374151']
                        };
                    ?>
                        <tr>
                            <td>
                                <div style="font-weight:700;font-size:.81rem;"><?php echo htmlspecialchars($po['po_number']); ?></div>
                                <div style="font-size:.7rem;color:var(--text-muted);"><?php echo $po['po_date'] ? date('d M Y', strtotime($po['po_date'])) : '-'; ?></div>
                            </td>
                            <td style="font-size:.78rem;"><?php echo (int)$po['items_count']; ?> item</td>
                            <td><span class="gd-badge" style="background:<?php echo $psc[0]; ?>;color:<?php echo $psc[1]; ?>;white-space:nowrap;"><?php echo strtoupper(str_replace('_', ' ', $po['status'] ?? '-')); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Histori Barang Datang -->
    <div class="card" style="padding:1.1rem;">
        <div class="gd-section-title" style="color:#16a34a;">📥 Histori Barang Datang</div>
        <?php if (empty($barangDatangHistory)): ?>
            <div style="text-align:center;padding:1.5rem;color:var(--text-muted);font-size:.85rem;">Belum ada barang datang dari supplier</div>
        <?php else: ?>
            <table class="gd-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>No PO</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($barangDatangHistory as $bd): ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;font-size:.81rem;"><?php echo htmlspecialchars($bd['item_name']); ?></div>
                                <div style="font-size:.7rem;color:var(--text-muted);"><?php echo $bd['tgl'] ? date('d M Y', strtotime($bd['tgl'])) : '-'; ?></div>
                            </td>
                            <td><span style="font-weight:700;color:#16a34a;">+<?php echo number_format($bd['quantity'], 0); ?></span> <span style="font-size:.7rem;color:var(--text-muted);"><?php echo $bd['unit']; ?></span></td>
                            <td style="font-size:.76rem;color:var(--text-muted);white-space:nowrap;"><?php echo htmlspecialchars($bd['po_number'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- PO Masuk dari Bisnis (lonceng) -->
    <div class="card" style="padding:1.1rem;<?php echo $pendingPoCount > 0 ? 'border-left:3px solid #ef4444;' : ''; ?>">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;">
            <div class="gd-section-title" style="margin:0;color:#ef4444;">🔔 PO Masuk dari Bisnis</div>
            <?php if ($pendingPoCount > 0): ?><span class="gd-badge" style="background:#fee2e2;color:#991b1b;"><?php echo $pendingPoCount; ?></span><?php endif; ?>
        </div>
        <?php if (empty($pendingBusinessPo)): ?>
            <div style="text-align:center;padding:1.5rem;color:var(--text-muted);font-size:.85rem;">Tidak ada PO menunggu</div>
        <?php else: ?>
            <div style="max-height:260px;overflow-y:auto;">
                <table class="gd-table">
                    <thead>
                        <tr>
                            <th>No PO</th>
                            <th>Bisnis</th>
                            <th>Item</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingBusinessPo as $bp): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:700;font-size:.81rem;"><?php echo htmlspecialchars($bp['po_number']); ?></div>
                                    <div style="font-size:.7rem;color:var(--text-muted);"><?php echo $bp['po_date'] ? date('d M Y', strtotime($bp['po_date'])) : '-'; ?></div>
                                </td>
                                <td style="font-size:.78rem;font-weight:600;"><?php echo htmlspecialchars($bp['source_business_name'] ?? '-'); ?></td>
                                <td style="font-size:.78rem;"><?php echo (int)($bp['items_count'] ?? 0); ?> item</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Histori terkirim per bisnis -->
<div class="card" style="padding:1.1rem 1.25rem;margin-bottom:.5rem;">
    <div class="gd-section-title">🚚 Histori Terkirim / Bisnis</div>
    <?php if (empty($terkirimPerBisnis)): ?>
        <div style="text-align:center;padding:1.5rem;color:var(--text-muted);font-size:.875rem;">Belum ada data transfer</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="gd-table">
                <thead>
                    <tr>
                        <th>Bisnis</th>
                        <th class="text-right">Jumlah Transfer</th>
                        <th class="text-right">Total Qty</th>
                        <th>Terakhir Kirim</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($terkirimPerBisnis as $tb): ?>
                        <tr>
                            <td style="font-weight:600;"><?php echo htmlspecialchars($tb['bisnis']); ?></td>
                            <td class="text-right"><?php echo (int)$tb['total_transfer']; ?></td>
                            <td class="text-right" style="font-weight:700;"><?php echo number_format((float)$tb['total_qty'], 0, ',', '.'); ?></td>
                            <td style="font-size:.76rem;color:var(--text-muted);"><?php echo $tb['terakhir'] ? date('d M Y H:i', strtotime($tb['terakhir'])) : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    (function() {
        const dark = document.body.getAttribute('data-theme') === 'dark';
        const gc = dark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.06)';
        const lc = dark ? '#94a3b8' : '#64748b';
        const mCtx = document.getElementById('movementChart');
        const bizPalette = ['#7c3aed', '#0ea5e9', '#f59e0b', '#ef4444', '#10b981', '#e11d48'];
        const bizDatasetsRaw = <?php
                                $out = [];
                                $i = 0;
                                foreach ($bizDatasets as $name => $vals) {
                                    $out[] = ['label' => $name, 'data' => array_values($vals), 'idx' => $i++];
                                }
                                echo json_encode($out);
                                ?>;
        if (mCtx && bizDatasetsRaw.length) {
            new Chart(mCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($chartWeekLabels); ?>,
                    datasets: bizDatasetsRaw.map((d, i) => ({
                        label: d.label,
                        data: d.data,
                        backgroundColor: bizPalette[i % bizPalette.length] + 'bb',
                        borderColor: bizPalette[i % bizPalette.length],
                        borderWidth: 1.5,
                        borderRadius: 6,
                    }))
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            labels: {
                                color: lc,
                                boxWidth: 11,
                                font: {
                                    size: 11
                                }
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: c => ` ${c.dataset.label}: ${c.parsed.y.toLocaleString('id-ID')} qty`
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: gc
                            },
                            ticks: {
                                color: lc
                            }
                        },
                        y: {
                            grid: {
                                color: gc
                            },
                            ticks: {
                                color: lc
                            },
                            beginAtZero: true
                        }
                    }
                }
            });
        }
        const cCtx = document.getElementById('bizPieChart');
        const bizLabels = <?php echo json_encode(array_column($bizTransferRows, 'bisnis')); ?>;
        const bizVals = <?php echo json_encode(array_map(fn($r) => (float)$r['total'], $bizTransferRows)); ?>;
        const palette = ['#7c3aed', '#0ea5e9', '#f59e0b', '#ef4444', '#10b981', '#e11d48'];
        if (cCtx && bizLabels.length) {
            const chart = new Chart(cCtx, {
                type: 'pie',
                data: {
                    labels: bizLabels,
                    datasets: [{
                        data: bizVals,
                        backgroundColor: palette.slice(0, bizLabels.length),
                        borderWidth: 3,
                        borderColor: dark ? '#1e293b' : '#fff',
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: c => ' ' + c.label + ': ' + c.parsed.toLocaleString('id-ID') + ' qty'
                            }
                        }
                    }
                }
            });
            // Custom legend below
            const leg = document.getElementById('bizPieLegend');
            if (leg) {
                const total = bizVals.reduce((a, b) => a + b, 0);
                bizLabels.forEach((l, i) => {
                    const pct = total > 0 ? Math.round(bizVals[i] / total * 100) : 0;
                    leg.innerHTML += `<div style="display:flex;align-items:center;gap:.35rem;font-size:.75rem;"><span style="width:10px;height:10px;border-radius:50%;background:${palette[i]};flex-shrink:0;"></span><span style="font-weight:700;color:${palette[i]}">${l}</span><span style="color:var(--text-muted);">${pct}%</span></div>`;
                });
            }
        }
    })();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>