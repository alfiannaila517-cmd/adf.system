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
    echo 'Akses ditolak.';
    exit;
}

$db = Database::getInstance();
$pageTitle = 'Tagihan Gudang';

// ── Tagihan ke Supplier (PO ke supplier dari Gudang Nasita) ──────────────────
$supplierBills = [];
try {
    $supplierBills = $db->fetchAll(
        "SELECT poh.id, poh.po_number, poh.po_date, poh.status,
                COALESCE(poh.total_amount, 0) AS total_amount,
                COALESCE(s.supplier_name, '-') AS supplier_name,
                COALESCE(SUM(pod.quantity), 0) AS total_items
         FROM purchase_orders_header poh
         LEFT JOIN suppliers s ON s.id = poh.supplier_id
         LEFT JOIN purchase_orders_detail pod ON pod.po_header_id = poh.id
         WHERE poh.status NOT IN ('cancelled', 'draft')
         GROUP BY poh.id
         ORDER BY poh.po_date DESC
         LIMIT 100"
    ) ?: [];
} catch (Throwable $e) {
    error_log('gudang-tagihan supplier bills: ' . $e->getMessage());
}

// ── Tagihan ke Bisnis (transfer dari Gudang ke tiap bisnis) ──────────────────
$bizBills = [];
try {
    $bizBills = $db->fetchAll(
        "SELECT gt.target_business_name,
                COUNT(DISTINCT gt.id)         AS transfer_count,
                COALESCE(SUM(gti.quantity), 0) AS total_qty,
                COALESCE(SUM(COALESCE(gti.subtotal, gti.quantity * COALESCE(gti.unit_price, 0))), 0) AS total_nilai
         FROM gudang_nasita_transfers gt
         LEFT JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id
         WHERE gt.status NOT IN ('cancelled')
         GROUP BY gt.target_business_name
         ORDER BY total_nilai DESC"
    ) ?: [];
} catch (Throwable $e) {
    error_log('gudang-tagihan biz bills: ' . $e->getMessage());
}

// ── Per-transfer detail per bisnis (untuk accordion) ─────────────────────────
$bizTransferDetail = [];
try {
    $bizTransferDetail = $db->fetchAll(
        "SELECT gt.id, gt.transfer_number, gt.target_business_name, gt.status,
                gt.created_at,
                COALESCE(SUM(gti.quantity), 0) AS total_qty,
                COALESCE(SUM(COALESCE(gti.subtotal, gti.quantity * COALESCE(gti.unit_price, 0))), 0) AS total_nilai,
                COUNT(gti.id) AS items_count
         FROM gudang_nasita_transfers gt
         LEFT JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id
         WHERE gt.status NOT IN ('cancelled')
         GROUP BY gt.id
         ORDER BY gt.target_business_name ASC, gt.created_at DESC
         LIMIT 500"
    ) ?: [];
} catch (Throwable $e) {
    error_log('gudang-tagihan detail: ' . $e->getMessage());
}

// Group transfer detail by business name
$detailByBiz = [];
foreach ($bizTransferDetail as $row) {
    $biz = $row['target_business_name'] ?? '-';
    $detailByBiz[$biz][] = $row;
}

$forceTheme = 'light';
include '../../includes/header.php';

$statusColors = [
    'submitted'          => ['#fef3c7','#92400e'],
    'approved'           => ['#dbeafe','#1e40af'],
    'received'           => ['#d1fae5','#065f46'],
    'partially_received' => ['#ede9fe','#5b21b6'],
    'completed'          => ['#d1fae5','#065f46'],
];
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:1rem;">
    <div>
        <h2 style="font-size:1.4rem; font-weight:700; margin:0; color:var(--text-primary);">Tagihan Gudang Nasita</h2>
        <p style="color:var(--text-muted); font-size:0.875rem; margin:0.25rem 0 0;">Rekap tagihan ke supplier dan tagihan ke bisnis berdasarkan PO / transfer</p>
    </div>
    <a href="gudang-nasita.php" class="btn btn-secondary" style="font-size:0.85rem;">← Kembali ke Stock Gudang</a>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; align-items:start;">

    <!-- ── KIRI: Tagihan ke Supplier ───────────────────────────────────────── -->
    <div>
        <div class="card" style="margin-bottom:1rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.5rem;">
                <div>
                    <h3 style="font-size:1rem; font-weight:700; margin:0;">Tagihan ke Supplier</h3>
                    <p style="font-size:0.78rem; color:var(--text-muted); margin:0.15rem 0 0;">PO yang dibuat ke supplier Gudang Nasita</p>
                </div>
                <a href="gudang-po-supplier.php" class="btn btn-sm btn-primary">Buat PO Baru</a>
            </div>
            <?php
            $totalSupplier = array_sum(array_column($supplierBills, 'total_amount'));
            ?>
            <div class="table-responsive" style="max-height:480px; overflow-y:auto;">
                <table class="table" style="font-size:0.82rem;">
                    <thead>
                        <tr>
                            <th>No PO</th>
                            <th>Tanggal</th>
                            <th>Supplier</th>
                            <th>Status</th>
                            <th class="text-right">Total</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($supplierBills)): ?>
                            <tr><td colspan="6" style="text-align:center; padding:2rem; color:var(--text-muted);">Belum ada PO ke supplier</td></tr>
                        <?php else: foreach ($supplierBills as $bill):
                            $st = $bill['status'] ?? '-';
                            [$bg, $fc] = $statusColors[$st] ?? ['#f1f5f9','#475569'];
                        ?>
                        <tr>
                            <td style="font-weight:700; color:#4f46e5;"><?php echo htmlspecialchars($bill['po_number']); ?></td>
                            <td><?php echo !empty($bill['po_date']) ? date('d M Y', strtotime($bill['po_date'])) : '-'; ?></td>
                            <td style="font-weight:600;"><?php echo htmlspecialchars($bill['supplier_name']); ?></td>
                            <td>
                                <span style="background:<?php echo $bg; ?>; color:<?php echo $fc; ?>; padding:2px 8px; border-radius:999px; font-size:0.73rem; font-weight:600; white-space:nowrap;">
                                    <?php echo ucfirst(str_replace('_', ' ', $st)); ?>
                                </span>
                            </td>
                            <td class="text-right" style="font-weight:700; color:<?php echo (float)$bill['total_amount'] > 0 ? '#0f9d6a' : '#94a3b8'; ?>;">
                                <?php echo (float)$bill['total_amount'] > 0 ? 'Rp&nbsp;' . number_format((float)$bill['total_amount'], 0, ',', '.') : '—'; ?>
                            </td>
                            <td class="text-center">
                                <a href="gudang-po-supplier.php?view=<?php echo (int)$bill['id']; ?>" class="btn btn-sm btn-secondary" style="font-size:0.73rem; padding:2px 8px;">Lihat</a>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <?php if ($totalSupplier > 0): ?>
                    <tfoot>
                        <tr style="background:#f8fafc; font-weight:700;">
                            <td colspan="4">Total</td>
                            <td class="text-right" style="color:#0f9d6a;">Rp&nbsp;<?php echo number_format($totalSupplier, 0, ',', '.'); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- ── KANAN: Tagihan ke Bisnis ────────────────────────────────────────── -->
    <div>
        <div class="card" style="margin-bottom:1rem;">
            <div style="margin-bottom:1rem;">
                <h3 style="font-size:1rem; font-weight:700; margin:0;">Tagihan ke Bisnis</h3>
                <p style="font-size:0.78rem; color:var(--text-muted); margin:0.15rem 0 0;">Berdasarkan transfer barang dari Gudang Nasita ke tiap bisnis</p>
            </div>
            <?php if (empty($bizBills)): ?>
                <div style="text-align:center; padding:2rem; color:var(--text-muted);">Belum ada transfer ke bisnis</div>
            <?php else: ?>
                <div style="display:grid; gap:0.75rem;">
                    <?php
                    $totalBizAll = 0;
                    foreach ($bizBills as $biz):
                        $bizName = $biz['target_business_name'] ?? '-';
                        $nilai = (float)$biz['total_nilai'];
                        $totalBizAll += $nilai;
                        $transfers = $detailByBiz[$bizName] ?? [];
                    ?>
                    <div style="border:1px solid var(--border); border-radius:0.75rem; overflow:hidden;">
                        <div style="display:flex; justify-content:space-between; align-items:center; padding:0.7rem 1rem; background:#f8fafc; cursor:pointer;"
                            onclick="toggleBizDetail('biz-<?php echo htmlspecialchars(preg_replace('/[^a-z0-9]/i','_',$bizName)); ?>')">
                            <div>
                                <div style="font-weight:700; font-size:0.9rem;"><?php echo htmlspecialchars($bizName); ?></div>
                                <div style="font-size:0.75rem; color:var(--text-muted);"><?php echo (int)$biz['transfer_count']; ?> transfer &mdash; <?php echo number_format((float)$biz['total_qty'], 2); ?> qty total</div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-weight:800; color:#0f9d6a; font-size:0.95rem;"><?php echo $nilai > 0 ? 'Rp&nbsp;' . number_format($nilai, 0, ',', '.') : '—'; ?></div>
                                <div style="font-size:0.68rem; color:#94a3b8;">▼ detail</div>
                            </div>
                        </div>
                        <div id="biz-<?php echo htmlspecialchars(preg_replace('/[^a-z0-9]/i','_',$bizName)); ?>" style="display:none; max-height:200px; overflow-y:auto;">
                            <table class="table" style="font-size:0.78rem; margin:0;">
                                <thead>
                                    <tr>
                                        <th>No Transfer</th>
                                        <th>Tanggal</th>
                                        <th class="text-right">Qty</th>
                                        <th class="text-right">Nilai</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transfers as $tr):
                                        $tst = $tr['status'] ?? '-';
                                        [$tbg, $tfc] = $statusColors[$tst] ?? ['#f1f5f9','#475569'];
                                    ?>
                                    <tr>
                                        <td style="font-weight:600; color:#4f46e5;"><?php echo htmlspecialchars($tr['transfer_number']); ?></td>
                                        <td><?php echo date('d M Y', strtotime($tr['created_at'])); ?></td>
                                        <td class="text-right"><?php echo number_format((float)$tr['total_qty'], 2); ?></td>
                                        <td class="text-right" style="font-weight:700; color:#0f9d6a;"><?php echo (float)$tr['total_nilai'] > 0 ? 'Rp&nbsp;' . number_format((float)$tr['total_nilai'], 0, ',', '.') : '—'; ?></td>
                                        <td><span style="background:<?php echo $tbg; ?>; color:<?php echo $tfc; ?>; padding:1px 6px; border-radius:999px; font-size:0.7rem; font-weight:600;"><?php echo ucfirst($tst); ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:0.85rem; padding:0.65rem 1rem; background:#f0fdf4; border-radius:0.6rem; display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:0.85rem; font-weight:700; color:#065f46;">Total Tagihan ke Semua Bisnis</span>
                    <span style="font-size:1rem; font-weight:800; color:#0f9d6a;">Rp&nbsp;<?php echo number_format($totalBizAll, 0, ',', '.'); ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
    function toggleBizDetail(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.style.display = el.style.display === 'none' ? 'block' : 'none';
    }
    if (typeof feather !== 'undefined') feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>
