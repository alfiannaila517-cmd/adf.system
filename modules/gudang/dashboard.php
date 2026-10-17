<?php

/**
 * Gudang Nasita — Dashboard
 * Tampilan: assets/css/gudang.css (komponen gx-*), helper: includes/gudang_ui.php
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/procurement_functions.php';
require_once __DIR__ . '/../../includes/gudang_ui.php';

$auth = new Auth();
$auth->requireLogin();
if (!($auth->hasPermission('gudang_nasita') || $auth->hasPermission('warehouse') || $auth->hasPermission('gudang_view'))) {
    http_response_code(403);
    echo 'Akses ditolak.';
    exit;
}

$db = Database::getInstance();
$pageTitle = 'Dashboard Gudang';

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
// LEFT JOIN (bukan INNER) supaya baris penerimaan tidak hilang dari histori
// walau stock_id-nya sempat ter-relink/berubah oleh proses self-heal stok.
$barangDatangHistory = $db->fetchAll(
    "SELECT gm.quantity, gm.reference_number AS po_number, gm.notes,
            COALESCE(gm.movement_date, gm.created_at) AS tgl,
            COALESCE(gs.item_name, gm.notes, '-') AS item_name, COALESCE(gs.unit, '') AS unit
     FROM gudang_nasita_movements gm
     LEFT JOIN gudang_nasita_stock gs ON gm.stock_id = gs.id
     WHERE gm.movement_type = 'in_supplier'
     ORDER BY COALESCE(gm.movement_date, gm.created_at) DESC, gm.id DESC LIMIT 8"
) ?: [];

// ── Histori terkirim per bisnis (ringkasan) ───────────────────────────────────
$terkirimPerBisnis = $db->fetchAll(
    "SELECT COALESCE(NULLIF(gt.target_business_name, ''), 'Lainnya') AS bisnis,
            COUNT(DISTINCT gt.id) AS total_transfer,
            COALESCE(SUM(gti.quantity), 0) AS total_qty,
            MAX(gt.created_at) AS terakhir
     FROM gudang_nasita_transfers gt
     LEFT JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id
     WHERE COALESCE(gt.status, '') <> 'cancelled'
     GROUP BY COALESCE(NULLIF(gt.target_business_name, ''), 'Lainnya')
     ORDER BY total_qty DESC"
) ?: [];

// ── Top 10 barang paling sering keluar (fast moving) — 30 hari terakhir ───────
$fastMovingItems = $db->fetchAll(
    "SELECT gs.item_name, gs.unit,
            SUM(gm.quantity) AS total_out,
            COUNT(*) AS total_transaksi
     FROM gudang_nasita_movements gm
     JOIN gudang_nasita_stock gs ON gm.stock_id = gs.id
     WHERE gm.movement_type = 'out_transfer'
       AND COALESCE(gm.movement_date, gm.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY gs.id, gs.item_name, gs.unit
     ORDER BY total_out DESC
     LIMIT 10"
) ?: [];

// ── Top 3 bisnis dengan barang terkirim terbanyak (share 100% dari 3 bisnis) ──
$topBiz = array_slice($terkirimPerBisnis, 0, 3);
$topBizColors = ['#0ea5b7', '#8b5cf6', '#e0a020'];
$topBizLabels = array_map(fn($r) => $r['bisnis'], $topBiz);
$topBizValues = array_map(fn($r) => (float)$r['total_qty'], $topBiz);
$topBizColorsUsed = array_slice($topBizColors, 0, count($topBiz));
$topBizGrandTotal = array_sum($topBizValues);
$topBizPercents = array_map(fn($v) => $topBizGrandTotal > 0 ? round($v / $topBizGrandTotal * 100, 1) : 0, $topBizValues);

// ── Lonceng PO: PO masuk dari bisnis yang belum diproses gudang ───────────────
$pendingBusinessPo = [];
try {
    $pendingBusinessPo = getGudangNasitaPendingBusinessPo();
} catch (Throwable $e) {
    error_log('Dashboard Gudang pending PO error: ' . $e->getMessage());
}
$pendingPoCount = count($pendingBusinessPo);

// ── Angka ringkas (KPI) ───────────────────────────────────────────────────────
$kpiLowStock = 0;
$kpiActiveItems = 0;
$kpiSentMonth = 0.0;
$kpiInMonth = 0.0;
try {
    $r = $db->fetchOne("SELECT COUNT(*) AS items,
                               SUM(CASE WHEN COALESCE(reorder_level, 0) > 0 AND COALESCE(quantity, 0) <= reorder_level THEN 1 ELSE 0 END) AS low
                        FROM gudang_nasita_stock WHERE COALESCE(is_active, 1) = 1");
    $kpiActiveItems = (int)($r['items'] ?? 0);
    $kpiLowStock = (int)($r['low'] ?? 0);
    $r = $db->fetchOne("SELECT COALESCE(SUM(gti.quantity), 0) AS q
                        FROM gudang_nasita_transfer_items gti
                        JOIN gudang_nasita_transfers gt ON gt.id = gti.transfer_id
                        WHERE COALESCE(gt.status, '') <> 'cancelled' AND gt.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
    $kpiSentMonth = (float)($r['q'] ?? 0);
    $r = $db->fetchOne("SELECT COALESCE(SUM(quantity), 0) AS q FROM gudang_nasita_movements
                        WHERE movement_type = 'in_supplier' AND COALESCE(movement_date, created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $kpiInMonth = (float)($r['q'] ?? 0);
} catch (Throwable $e) {
    error_log('Dashboard Gudang KPI error: ' . $e->getMessage());
}

include __DIR__ . '/../../includes/header.php';
$B = BASE_URL . '/modules/procurement/';
?>

<div class="gx-page">

    <div class="gx-head">
        <div>
            <h1>Dashboard Gudang Nasita
                <?php if ($pendingPoCount > 0): ?><span class="gx-chip bad">🔔 PO Masuk: <?php echo (int)$pendingPoCount; ?></span><?php endif; ?>
            </h1>
            <p>Ringkasan operasional gudang &middot; <?php echo gxE(gxDateLong()); ?></p>
        </div>
        <div class="gx-actions">
            <a href="<?php echo $B; ?>gudang-nasita.php" class="gx-btn"><i data-feather="archive"></i> Stok Gudang</a>
            <a href="<?php echo $B; ?>gudang-transfer.php" class="gx-btn pri"><i data-feather="send"></i> Kirim ke Bisnis</a>
        </div>
    </div>

    <!-- Alur kerja gudang: membantu memahami urutan menu -->
    <div class="gx-flow">
        <a href="<?php echo $B; ?>gudang-po-supplier.php"><b><i>1</i> Order ke Supplier</b><span>Pesan barang. Saat datang, terima barangnya → stok bertambah.</span></a>
        <a href="<?php echo $B; ?>gudang-nasita.php"><b><i>2</i> Stok Gudang</b><span>Cek stok, catat pemakaian harian, lihat barang menipis.</span></a>
        <a href="<?php echo $B; ?>gudang-transfer.php"><b><i>3</i> Kirim ke Bisnis</b><span>Proses PO dari Narayana, Bens Cafe, Eat Meet → stok berkurang.</span></a>
        <a href="<?php echo $B; ?>gudang-tagihan.php"><b><i>4</i> Tagihan Bisnis</b><span>Bisnis bayar barang ke Gudang; Gudang bayar bisnis pengirim.</span></a>
    </div>

    <!-- Angka ringkas -->
    <div class="gx-kpis">
        <div class="gx-kpi <?php echo $pendingPoCount > 0 ? 'bad' : 'ok'; ?>"><small>PO menunggu</small><b><?php echo (int)$pendingPoCount; ?></b><span>dari bisnis, belum diproses</span></div>
        <div class="gx-kpi <?php echo $kpiLowStock > 0 ? 'warn' : 'ok'; ?>"><small>Stok menipis</small><b><?php echo (int)$kpiLowStock; ?></b><span>dari <?php echo (int)$kpiActiveItems; ?> barang aktif</span></div>
        <div class="gx-kpi vio"><small>Terkirim bulan ini</small><b><?php echo gxQty($kpiSentMonth, 0); ?></b><span>total qty ke bisnis</span></div>
        <div class="gx-kpi"><small>Barang datang</small><b><?php echo gxQty($kpiInMonth, 0); ?></b><span>qty dari supplier, 30 hari</span></div>
    </div>

    <!-- PO Masuk dari Bisnis (lonceng) -->
    <div class="gx-card">
        <div class="gx-card-h">
            <h2>🔔 PO Masuk dari Bisnis</h2>
            <?php if ($pendingPoCount > 0): ?><span class="gx-chip bad"><?php echo (int)$pendingPoCount; ?> menunggu</span><?php endif; ?>
        </div>
        <?php if (empty($pendingBusinessPo)): ?>
            <div class="gx-empty">Tidak ada PO menunggu diproses</div>
        <?php else: ?>
            <div class="gx-table-wrap">
                <table class="gx-table">
                    <thead>
                        <tr>
                            <th>No PO</th>
                            <th>Bisnis</th>
                            <th>Tanggal</th>
                            <th>Item</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingBusinessPo as $bp):
                            $bpDetail = [
                                'title' => (string)($bp['po_number'] ?? '-'),
                                'subtitle' => trim(($bp['source_business_name'] ?? '-') . ' • ' . gxDate($bp['po_date'] ?? '')),
                                'columns' => [['label' => 'Item'], ['label' => 'Qty', 'right' => true], ['label' => 'Harga', 'right' => true], ['label' => 'Subtotal', 'right' => true]],
                                'rows' => array_map(function ($it) {
                                    $qty = (float)($it['quantity'] ?? 0);
                                    $price = (float)($it['unit_price'] ?? 0);
                                    $subtotal = (float)($it['total_price'] ?? 0);
                                    if ($subtotal <= 0) {
                                        $subtotal = $qty * $price;
                                    }
                                    return [(string)($it['item_name'] ?? '-'), gxQty($qty) . ' ' . (string)($it['unit'] ?? ''), gxRp($price), gxRp($subtotal)];
                                }, $bp['items'] ?? []),
                            ];
                        ?>
                            <tr class="clickable" data-detail="<?php echo gxE(json_encode($bpDetail)); ?>" onclick="gxOpenDetail(this)">
                                <td><b><?php echo gxE($bp['po_number']); ?></b></td>
                                <td><?php echo gxE($bp['source_business_name'] ?? '-'); ?></td>
                                <td><?php echo gxE(gxDate($bp['po_date'] ?? '') ?: '-'); ?></td>
                                <td><?php echo (int)($bp['items_count'] ?? 0); ?> item</td>
                                <td><?php echo gxStatusChip($bp['status'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Barang Fast Moving (10 teratas keluar 30 hari terakhir) -->
    <div class="gx-card">
        <div class="gx-card-h">
            <h2>📊 Barang Paling Sering Keluar</h2>
            <small>30 hari terakhir &middot; Top 10</small>
        </div>
        <div class="gx-card-b">
            <?php if (empty($fastMovingItems)): ?>
                <div class="gx-empty">Belum ada data barang keluar dalam 30 hari terakhir</div>
            <?php else: ?>
                <?php
                $fmColors = ['#0ea5b7', '#8b5cf6', '#34a373', '#e0a020', '#e2634f', '#4a7fd6', '#d0568c', '#2f9e7a', '#a855c7', '#94a3b8'];
                $fmLabels = array_map(fn($r) => $r['item_name'], $fastMovingItems);
                $fmValues = array_map(fn($r) => (float)$r['total_out'], $fastMovingItems);
                $fmColorsUsed = array_slice($fmColors, 0, count($fastMovingItems));
                ?>
                <div class="gx-charts">
                    <div class="gx-chart-box"><canvas id="fastMovingPieChart"></canvas></div>
                    <div class="gx-chart-box"><canvas id="fastMovingBarChart"></canvas></div>
                    <div>
                        <div class="gx-chart-cap">Top 3 Bisnis &middot; Barang Terkirim</div>
                        <?php if (empty($topBiz)): ?>
                            <div class="gx-empty" style="padding:1.2rem .5rem;">Belum ada data</div>
                        <?php else: ?>
                            <div class="gx-chart-box" style="height:140px;"><canvas id="topBizPieChart"></canvas></div>
                        <?php endif; ?>
                    </div>
                </div>
                <ul class="gx-legend">
                    <?php foreach ($fastMovingItems as $i => $fm): ?>
                        <li><i style="background:<?php echo gxE($fmColorsUsed[$i]); ?>;"></i><span><?php echo gxE($fm['item_name']); ?></span><b><?php echo gxE(gxQty($fm['total_out'], 0) . ' ' . ($fm['unit'] ?? '')); ?></b></li>
                    <?php endforeach; ?>
                </ul>
                <?php if (!empty($topBiz)): ?>
                    <ul class="gx-legend sep">
                        <?php foreach ($topBiz as $i => $tb): ?>
                            <li><i style="background:<?php echo gxE($topBizColorsUsed[$i]); ?>;"></i><span><?php echo gxE($tb['bisnis']); ?></span><b><?php echo $topBizPercents[$i]; ?>% &middot; <?php echo gxE(gxQty($tb['total_qty'], 0)); ?> qty</b></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Histori PO ke Supplier / Histori Barang Datang -->
    <div class="gx-grid-2" style="margin-bottom:.8rem;">

        <div class="gx-card">
            <div class="gx-card-h">
                <h2>🧾 Histori PO ke Supplier</h2>
                <a href="<?php echo $B; ?>gudang-po-supplier.php" class="gx-btn sm">Lihat semua</a>
            </div>
            <?php if (empty($poSupplierHistory)): ?>
                <div class="gx-empty">Belum ada PO ke supplier</div>
            <?php else: ?>
                <div class="gx-table-wrap">
                    <table class="gx-table">
                        <thead>
                            <tr>
                                <th>No PO</th>
                                <th>Item</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($poSupplierHistory as $po):
                                $poItems = $db->fetchAll("SELECT item_name, quantity, unit_of_measure AS unit, unit_price, subtotal AS total_price FROM purchase_orders_detail WHERE po_header_id = ? ORDER BY id ASC", [(int)$po['id']]) ?: [];
                                $poDetail = [
                                    'title' => (string)($po['po_number'] ?? '-'),
                                    'subtitle' => gxDate($po['po_date'] ?? '') ?: '-',
                                    'columns' => [['label' => 'Item'], ['label' => 'Qty', 'right' => true], ['label' => 'Harga', 'right' => true], ['label' => 'Subtotal', 'right' => true]],
                                    'rows' => array_map(function ($it) {
                                        $qty = (float)($it['quantity'] ?? 0);
                                        $price = (float)($it['unit_price'] ?? 0);
                                        $subtotal = (float)($it['total_price'] ?? 0);
                                        if ($subtotal <= 0) {
                                            $subtotal = $qty * $price;
                                        }
                                        return [(string)($it['item_name'] ?? '-'), gxQty($qty) . ' ' . (string)($it['unit'] ?? ''), gxRp($price), gxRp($subtotal)];
                                    }, $poItems),
                                ];
                            ?>
                                <tr class="clickable" data-detail="<?php echo gxE(json_encode($poDetail)); ?>" onclick="gxOpenDetail(this)">
                                    <td><b><?php echo gxE($po['po_number']); ?></b><span class="sub"><?php echo gxE(gxDate($po['po_date'] ?? '') ?: '-'); ?></span></td>
                                    <td><?php echo (int)$po['items_count']; ?> item</td>
                                    <td><?php echo gxStatusChip($po['status'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="gx-card">
            <div class="gx-card-h">
                <h2>📥 Histori Barang Datang</h2>
                <a href="<?php echo $B; ?>gudang-riwayat-masuk.php" class="gx-btn sm">Lihat semua</a>
            </div>
            <?php if (empty($barangDatangHistory)): ?>
                <div class="gx-empty">Belum ada barang datang dari supplier</div>
            <?php else: ?>
                <div class="gx-table-wrap">
                    <table class="gx-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th class="r">Qty</th>
                                <th>No PO</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($barangDatangHistory as $bd):
                                $bdDetail = [
                                    'title' => (string)($bd['item_name'] ?? '-'),
                                    'subtitle' => gxDate($bd['tgl'] ?? '', true) ?: '-',
                                    'columns' => [['label' => 'Keterangan'], ['label' => 'Nilai']],
                                    'rows' => [
                                        ['Qty Masuk', '+' . gxQty($bd['quantity'] ?? 0) . ' ' . (string)($bd['unit'] ?? '')],
                                        ['No PO', (string)($bd['po_number'] ?? '-')],
                                        ['Tanggal', gxDate($bd['tgl'] ?? '', true) ?: '-'],
                                        ['Catatan', $bd['notes'] !== null && $bd['notes'] !== '' ? (string)$bd['notes'] : '-'],
                                    ],
                                ];
                            ?>
                                <tr class="clickable" data-detail="<?php echo gxE(json_encode($bdDetail)); ?>" onclick="gxOpenDetail(this)">
                                    <td><b><?php echo gxE($bd['item_name']); ?></b><span class="sub"><?php echo gxE(gxDate($bd['tgl'] ?? '') ?: '-'); ?></span></td>
                                    <td class="r"><span class="num-pos">+<?php echo gxE(gxQty($bd['quantity'], 0)); ?></span> <span class="sub" style="display:inline;"><?php echo gxE($bd['unit']); ?></span></td>
                                    <td><?php echo gxE($bd['po_number'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Histori terkirim per bisnis -->
    <div class="gx-card">
        <div class="gx-card-h">
            <h2>🚚 Histori Terkirim per Bisnis</h2>
            <a href="<?php echo $B; ?>gudang-nasita.php" class="gx-btn sm">Riwayat pengiriman</a>
        </div>
        <?php if (empty($terkirimPerBisnis)): ?>
            <div class="gx-empty">Belum ada data transfer</div>
        <?php else: ?>
            <div class="gx-table-wrap">
                <table class="gx-table">
                    <thead>
                        <tr>
                            <th>Bisnis</th>
                            <th class="r">Jumlah Transfer</th>
                            <th class="r">Total Qty</th>
                            <th>Terakhir Kirim</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($terkirimPerBisnis as $tb):
                            $tbItems = $db->fetchAll(
                                "SELECT gt.transfer_number, gt.created_at, gti.item_name, gti.unit, gti.quantity
                                 FROM gudang_nasita_transfers gt
                                 JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id
                                 WHERE COALESCE(NULLIF(gt.target_business_name, ''), 'Lainnya') = ?
                                   AND COALESCE(gt.status, '') <> 'cancelled'
                                 ORDER BY gt.created_at DESC, gti.id ASC LIMIT 50",
                                [$tb['bisnis']]
                            ) ?: [];
                            $tbDetail = [
                                'title' => (string)($tb['bisnis'] ?? '-'),
                                'subtitle' => (int)$tb['total_transfer'] . ' transfer • total qty ' . gxQty($tb['total_qty'], 0),
                                'columns' => [['label' => 'Item'], ['label' => 'Qty', 'right' => true], ['label' => 'No Transfer'], ['label' => 'Tanggal']],
                                'rows' => array_map(function ($it) {
                                    return [(string)($it['item_name'] ?? '-'), gxQty($it['quantity'] ?? 0) . ' ' . (string)($it['unit'] ?? ''), (string)($it['transfer_number'] ?? '-'), gxDate($it['created_at'] ?? '') ?: '-'];
                                }, $tbItems),
                            ];
                        ?>
                            <tr class="clickable" data-detail="<?php echo gxE(json_encode($tbDetail)); ?>" onclick="gxOpenDetail(this)">
                                <td><b><?php echo gxE($tb['bisnis']); ?></b></td>
                                <td class="r"><?php echo (int)$tb['total_transfer']; ?></td>
                                <td class="r"><b><?php echo gxE(gxQty($tb['total_qty'], 0)); ?></b></td>
                                <td><?php echo gxE(gxDate($tb['terakhir'] ?? '', true) ?: '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Detail modal (dipakai semua baris yang bisa diklik) -->
<div class="gx-ov" id="gxDetailOv" onclick="if (event.target === this) gxCloseDetail()">
    <div class="gx-modal" role="dialog" aria-modal="true">
        <div class="gx-modal-h">
            <div><b id="gxDetailTitle">-</b><small id="gxDetailSub">-</small></div>
            <button type="button" class="gx-x" onclick="gxCloseDetail()" aria-label="Tutup">&times;</button>
        </div>
        <div class="gx-modal-b">
            <table class="gx-table">
                <thead id="gxDetailHead"></thead>
                <tbody id="gxDetailBody"></tbody>
            </table>
        </div>
    </div>
</div>

<style>
    body[data-business="gudang-nasita"] .gx-charts { display: grid; grid-template-columns: 170px minmax(0, 440px) 170px; gap: 1.5rem; align-items: center; }
    body[data-business="gudang-nasita"] .gx-chart-box { position: relative; height: 170px; }
    body[data-business="gudang-nasita"] .gx-chart-cap { font-size: .64rem; font-weight: 700; color: var(--gx-mute); text-align: center; margin-bottom: .3rem; }
    body[data-business="gudang-nasita"] .gx-legend { list-style: none; margin: .85rem 0 0; padding: .65rem 0 0; border-top: 1px solid var(--gx-line); display: flex; flex-wrap: wrap; gap: .35rem .9rem; }
    body[data-business="gudang-nasita"] .gx-legend.sep { margin-top: .4rem; }
    body[data-business="gudang-nasita"] .gx-legend li { display: flex; align-items: center; gap: .35rem; font-size: .68rem; }
    body[data-business="gudang-nasita"] .gx-legend i { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    body[data-business="gudang-nasita"] .gx-legend span { color: var(--gx-mute); font-weight: 600; white-space: nowrap; }
    body[data-business="gudang-nasita"] .gx-legend b { color: var(--gx-ink); white-space: nowrap; }
    @media (max-width: 900px) { body[data-business="gudang-nasita"] .gx-charts { grid-template-columns: 1fr; } }
</style>

<script>
    function gxEsc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function gxOpenDetail(rowEl) {
        var detail;
        try { detail = JSON.parse(rowEl.getAttribute('data-detail') || '{}'); } catch (e) { detail = {}; }
        document.getElementById('gxDetailTitle').textContent = detail.title || '-';
        document.getElementById('gxDetailSub').textContent = detail.subtitle || '';
        var cols = detail.columns || [];
        document.getElementById('gxDetailHead').innerHTML = '<tr>' + cols.map(function(c) {
            return '<th' + (c.right ? ' class="r"' : '') + '>' + gxEsc(c.label || '') + '</th>';
        }).join('') + '</tr>';
        var rows = detail.rows || [];
        document.getElementById('gxDetailBody').innerHTML = rows.length ? rows.map(function(r) {
            return '<tr>' + r.map(function(v, i) {
                return '<td' + (cols[i] && cols[i].right ? ' class="r"' : '') + '>' + gxEsc(v) + '</td>';
            }).join('') + '</tr>';
        }).join('') : '<tr><td colspan="' + (cols.length || 1) + '" class="gx-empty">Tidak ada detail.</td></tr>';
        document.getElementById('gxDetailOv').classList.add('open');
    }

    function gxCloseDetail() {
        document.getElementById('gxDetailOv').classList.remove('open');
    }
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') gxCloseDetail(); });
</script>

<?php if (!empty($fastMovingItems) || !empty($topBiz)): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Warna grafik mengikuti tema (garis pemisah irisan = warna kartu)
        var gxCss = getComputedStyle(document.body);
        var gxCardBg = (gxCss.getPropertyValue('--gx-card') || '#fff').trim();
        var gxTick = (gxCss.getPropertyValue('--gx-faint') || '#94a3b8').trim();
        var gxGrid = 'rgba(148,163,184,0.15)';
        var gxTip = { backgroundColor: '#0f172a', padding: 8, bodyFont: { size: 11 } };
    </script>
<?php endif; ?>

<?php if (!empty($fastMovingItems)): ?>
    <script>
        var fmLabels = <?php echo json_encode($fmLabels); ?>;
        var fmValues = <?php echo json_encode($fmValues); ?>;
        var fmColors = <?php echo json_encode($fmColorsUsed); ?>;
        new Chart(document.getElementById('fastMovingPieChart').getContext('2d'), {
            type: 'doughnut',
            data: { labels: fmLabels, datasets: [{ data: fmValues, backgroundColor: fmColors, borderColor: gxCardBg, borderWidth: 2, borderRadius: 6, spacing: 3, hoverOffset: 6 }] },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '52%',
                plugins: { legend: { display: false }, tooltip: Object.assign({}, gxTip, { callbacks: { label: function(ctx) { return ctx.label + ': ' + ctx.parsed.toLocaleString('id-ID'); } } }) }
            }
        });
        new Chart(document.getElementById('fastMovingBarChart').getContext('2d'), {
            type: 'bar',
            data: { labels: fmLabels, datasets: [{ label: 'Qty Keluar', data: fmValues, backgroundColor: fmColors, borderRadius: 4, maxBarThickness: 22, categoryPercentage: 0.55, barPercentage: 0.9 }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: gxTip },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 9 }, maxRotation: 0, autoSkip: true, color: gxTick } },
                    y: { beginAtZero: true, grid: { color: gxGrid }, ticks: { font: { size: 9 }, color: gxTick } }
                }
            }
        });
    </script>
<?php endif; ?>

<?php if (!empty($topBiz)): ?>
    <script>
        new Chart(document.getElementById('topBizPieChart').getContext('2d'), {
            type: 'doughnut',
            data: { labels: <?php echo json_encode($topBizLabels); ?>, datasets: [{ data: <?php echo json_encode($topBizValues); ?>, backgroundColor: <?php echo json_encode($topBizColorsUsed); ?>, borderColor: gxCardBg, borderWidth: 2, borderRadius: 6, spacing: 3, hoverOffset: 6 }] },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '52%',
                plugins: {
                    legend: { display: false },
                    tooltip: Object.assign({}, gxTip, { callbacks: { label: function(ctx) {
                        var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                        var pct = total > 0 ? (ctx.parsed / total * 100).toFixed(1) : 0;
                        return ctx.label + ': ' + pct + '% (' + ctx.parsed.toLocaleString('id-ID') + ' qty)';
                    } } })
                }
            }
        });
    </script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
