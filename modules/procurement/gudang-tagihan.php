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
$currentUser = $auth->getCurrentUser();

// ── Ensure TKBM table exists ─────────────────────────────────────────────────
try {
    $db->query("CREATE TABLE IF NOT EXISTS gudang_nasita_tkbm (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tanggal DATE NOT NULL,
        total_biaya DECIMAL(15,2) NOT NULL DEFAULT 0,
        keterangan TEXT NULL,
        jumlah_bisnis TINYINT DEFAULT 3,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
}

// ── POST: tambah TKBM ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_tkbm') {
    $tanggal    = trim($_POST['tanggal'] ?? date('Y-m-d'));
    $biaya      = (float)($_POST['total_biaya'] ?? 0);
    $ket        = trim($_POST['keterangan'] ?? '');
    $jmlBisnis  = max(1, (int)($_POST['jumlah_bisnis'] ?? 3));
    if ($biaya > 0) {
        $db->insert('gudang_nasita_tkbm', [
            'tanggal'       => $tanggal,
            'total_biaya'   => $biaya,
            'keterangan'    => $ket ?: null,
            'jumlah_bisnis' => $jmlBisnis,
            'created_by'    => (int)($currentUser['id'] ?? 0),
        ]);
        $_SESSION['success'] = 'TKBM berhasil ditambahkan.';
    }
    header('Location: gudang-tagihan.php');
    exit;
}

// ── POST: hapus TKBM ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_tkbm') {
    $tid = (int)($_POST['tkbm_id'] ?? 0);
    if ($tid > 0) {
        $db->query('DELETE FROM gudang_nasita_tkbm WHERE id = ?', [$tid]);
        $_SESSION['success'] = 'TKBM dihapus.';
    }
    header('Location: gudang-tagihan.php');
    exit;
}

// ── TKBM records ─────────────────────────────────────────────────────────────
$tkbmRows = [];
try {
    $tkbmRows = $db->fetchAll('SELECT * FROM gudang_nasita_tkbm ORDER BY tanggal DESC LIMIT 100') ?: [];
} catch (Throwable $e) {
}
$tkbmTotal = array_sum(array_column($tkbmRows, 'total_biaya'));

// ── Tagihan ke Supplier ───────────────────────────────────────────────────────
$supplierBills = [];
try {
    // Tagihan supplier dihitung dari received_quantity × unit_price (bukan total PO yang dipesan)
    $supplierBills = $db->fetchAll(
        "SELECT poh.id, poh.po_number, poh.po_date, poh.status,
                COALESCE(s.supplier_name, '-') AS supplier_name,
                COALESCE(SUM(pod.received_quantity), 0)   AS received_qty,
                COALESCE(SUM(pod.quantity), 0)            AS ordered_qty,
                COALESCE(SUM(pod.received_quantity * pod.unit_price), 0) AS total_amount
         FROM purchase_orders_header poh
         LEFT JOIN suppliers s ON s.id = poh.supplier_id
         LEFT JOIN purchase_orders_detail pod ON pod.po_header_id = poh.id
         WHERE poh.status NOT IN ('cancelled', 'draft')
         GROUP BY poh.id
         HAVING received_qty > 0
         ORDER BY poh.po_date DESC
         LIMIT 100"
    ) ?: [];
} catch (Throwable $e) {
    error_log('gudang-tagihan supplier bills: ' . $e->getMessage());
}

// ── Tagihan ke Bisnis — switch ke Gudang DB karena tabelnya ada di sana ──────
$bizBills = [];
$bizTransferDetail = [];
$detailByBiz = [];
try {
    $gudangCfgPath = __DIR__ . '/../../config/businesses/gudang-nasita.php';
    $gudangDbName  = '';
    if (file_exists($gudangCfgPath)) {
        $gc = require $gudangCfgPath;
        $gudangDbName = (string)($gc['database'] ?? '');
    }
    $originDb = Database::getCurrentDatabase();
    if ($gudangDbName && $gudangDbName !== $originDb) {
        $gudangDb = Database::switchDatabase($gudangDbName);
    } else {
        $gudangDb = $db;
    }

    $bizBills = $gudangDb->fetchAll(
        "SELECT gt.target_business_name,
                COUNT(DISTINCT gt.id)                                                                    AS transfer_count,
                COALESCE(SUM(gti.quantity), 0)                                                           AS total_qty,
                COALESCE(SUM(COALESCE(gti.subtotal, gti.quantity * COALESCE(gti.unit_price, 0))), 0)    AS total_nilai
         FROM gudang_nasita_transfers gt
         LEFT JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id
         WHERE gt.status NOT IN ('cancelled')
         GROUP BY gt.target_business_name
         ORDER BY total_nilai DESC"
    ) ?: [];

    $bizTransferDetail = $gudangDb->fetchAll(
        "SELECT gt.id, gt.transfer_number, gt.target_business_name, gt.status,
                gt.created_at,
                COALESCE(SUM(gti.quantity), 0)                                                           AS total_qty,
                COALESCE(SUM(COALESCE(gti.subtotal, gti.quantity * COALESCE(gti.unit_price, 0))), 0)    AS total_nilai,
                COUNT(gti.id) AS items_count
         FROM gudang_nasita_transfers gt
         LEFT JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id
         WHERE gt.status NOT IN ('cancelled')
         GROUP BY gt.id
         ORDER BY gt.target_business_name ASC, gt.created_at DESC
         LIMIT 500"
    ) ?: [];

    if ($gudangDbName && $gudangDbName !== $originDb) {
        Database::switchDatabase($originDb);
    }
} catch (Throwable $e) {
    error_log('gudang-tagihan biz bills: ' . $e->getMessage());
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
    'submitted'          => ['#fef3c7', '#92400e'],
    'approved'           => ['#dbeafe', '#1e40af'],
    'received'           => ['#d1fae5', '#065f46'],
    'partially_received' => ['#ede9fe', '#5b21b6'],
    'completed'          => ['#d1fae5', '#065f46'],
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
                <div style="display:flex; gap:0.5rem;">
                    <a href="suppliers.php" class="btn btn-sm btn-secondary" style="font-size:0.78rem;">Kelola Supplier</a>
                    <a href="gudang-po-supplier.php" class="btn btn-sm btn-primary">Buat PO Baru</a>
                </div>
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
                            <th class="text-right" style="color:#94a3b8;">Dipesan</th>
                            <th class="text-right" style="color:#0f9d6a;">Diterima</th>
                            <th class="text-right">Tagihan</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($supplierBills)): ?>
                            <tr>
                                <td colspan="8" style="text-align:center; padding:2rem; color:var(--text-muted);">Belum ada barang yang diterima dari supplier</td>
                            </tr>
                            <?php else: foreach ($supplierBills as $bill):
                                $st = $bill['status'] ?? '-';
                                [$bg, $fc] = $statusColors[$st] ?? ['#f1f5f9', '#475569'];
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
                                    <td class="text-right" style="color:#94a3b8;"><?php echo number_format((float)$bill['ordered_qty'], 2); ?></td>
                                    <td class="text-right" style="font-weight:600; color:#0f9d6a;"><?php echo number_format((float)$bill['received_qty'], 2); ?></td>
                                    <td class="text-right" style="font-weight:700; color:<?php echo (float)$bill['total_amount'] > 0 ? '#0f9d6a' : '#94a3b8'; ?>;">
                                        <?php echo (float)$bill['total_amount'] > 0 ? 'Rp&nbsp;' . number_format((float)$bill['total_amount'], 0, ',', '.') : '—'; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="gudang-po-supplier.php?view=<?php echo (int)$bill['id']; ?>" class="btn btn-sm btn-secondary" style="font-size:0.73rem; padding:2px 8px;">Lihat</a>
                                    </td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                    <?php if ($totalSupplier > 0): ?>
                        <tfoot>
                            <tr style="background:#f8fafc; font-weight:700;">
                                <td colspan="6">Total Tagihan (diterima)</td>
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
                                onclick="toggleBizDetail('biz-<?php echo htmlspecialchars(preg_replace('/[^a-z0-9]/i', '_', $bizName)); ?>')">
                                <div>
                                    <div style="font-weight:700; font-size:0.9rem;"><?php echo htmlspecialchars($bizName); ?></div>
                                    <div style="font-size:0.75rem; color:var(--text-muted);"><?php echo (int)$biz['transfer_count']; ?> transfer &mdash; <?php echo number_format((float)$biz['total_qty'], 2); ?> qty total</div>
                                </div>
                                <div style="text-align:right;">
                                    <div style="font-weight:800; color:#0f9d6a; font-size:0.95rem;"><?php echo $nilai > 0 ? 'Rp&nbsp;' . number_format($nilai, 0, ',', '.') : '—'; ?></div>
                                    <div style="font-size:0.68rem; color:#94a3b8;">▼ detail</div>
                                </div>
                            </div>
                            <div id="biz-<?php echo htmlspecialchars(preg_replace('/[^a-z0-9]/i', '_', $bizName)); ?>" style="display:none; max-height:200px; overflow-y:auto;">
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
                                            [$tbg, $tfc] = $statusColors[$tst] ?? ['#f1f5f9', '#475569'];
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

<!-- ── TKBM Section ──────────────────────────────────────────────────────── -->
<div class="card" style="margin-top:1.5rem;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.75rem;">
        <div>
            <h3 style="font-size:1rem; font-weight:700; margin:0;">Tagihan TKBM <span style="font-size:0.78rem; color:var(--text-muted); font-weight:400;">(Tenaga Kerja Bongkar Muat)</span></h3>
            <p style="font-size:0.78rem; color:var(--text-muted); margin:0.15rem 0 0;">Biaya jasa angkut dari pelabuhan ke Gudang Nasita — dibagi rata ke semua bisnis</p>
        </div>
        <button type="button" class="btn btn-sm btn-primary" onclick="document.getElementById('tkbmAddForm').style.display='flex'">+ Tambah TKBM</button>
    </div>

    <!-- Add TKBM form -->
    <form id="tkbmAddForm" method="POST" style="display:none; gap:0.65rem; flex-wrap:wrap; align-items:flex-end; background:#f8fafc; padding:0.85rem 1rem; border-radius:0.65rem; margin-bottom:1rem;">
        <input type="hidden" name="action" value="add_tkbm">
        <div>
            <label class="form-label" style="font-size:0.78rem;">Tanggal</label>
            <input type="date" name="tanggal" class="form-control" style="width:140px;" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div>
            <label class="form-label" style="font-size:0.78rem;">Total Biaya TKBM (Rp)</label>
            <input type="number" name="total_biaya" class="form-control" style="width:160px;" placeholder="0" min="1" step="1" required>
        </div>
        <div>
            <label class="form-label" style="font-size:0.78rem;">Dibagi ke (bisnis)</label>
            <input type="number" name="jumlah_bisnis" class="form-control" style="width:80px;" value="3" min="1" max="10">
        </div>
        <div style="flex:1; min-width:180px;">
            <label class="form-label" style="font-size:0.78rem;">Keterangan</label>
            <input type="text" name="keterangan" class="form-control" placeholder="Mis: pengiriman Jepara 17 Agt">
        </div>
        <div style="display:flex; gap:0.5rem;">
            <button type="submit" class="btn btn-sm btn-success">Simpan</button>
            <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('tkbmAddForm').style.display='none'">Batal</button>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table" style="font-size:0.83rem;">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Keterangan</th>
                    <th class="text-right">Total Biaya</th>
                    <th class="text-center">Dibagi</th>
                    <th class="text-right" style="color:#0f9d6a;">Per Bisnis</th>
                    <th class="text-center">Hapus</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tkbmRows)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:1.5rem; color:var(--text-muted);">Belum ada data TKBM</td>
                    </tr>
                    <?php else: foreach ($tkbmRows as $tkbm):
                        $perBisnis = (float)$tkbm['total_biaya'] / max(1, (int)$tkbm['jumlah_bisnis']);
                    ?>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($tkbm['tanggal'])); ?></td>
                            <td><?php echo htmlspecialchars($tkbm['keterangan'] ?? '-'); ?></td>
                            <td class="text-right" style="font-weight:700;">Rp&nbsp;<?php echo number_format((float)$tkbm['total_biaya'], 0, ',', '.'); ?></td>
                            <td class="text-center" style="color:#64748b;"><?php echo (int)$tkbm['jumlah_bisnis']; ?> bisnis</td>
                            <td class="text-right" style="font-weight:700; color:#0f9d6a;">Rp&nbsp;<?php echo number_format($perBisnis, 0, ',', '.'); ?></td>
                            <td class="text-center">
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus entri TKBM ini?')">
                                    <input type="hidden" name="action" value="delete_tkbm">
                                    <input type="hidden" name="tkbm_id" value="<?php echo (int)$tkbm['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" style="padding:2px 8px; font-size:0.73rem;">Hapus</button>
                                </form>
                            </td>
                        </tr>
                <?php endforeach;
                endif; ?>
            </tbody>
            <?php if ($tkbmTotal > 0): ?>
                <tfoot>
                    <tr style="background:#f8fafc; font-weight:700;">
                        <td colspan="2">Total TKBM</td>
                        <td class="text-right" style="color:#0f9d6a;">Rp&nbsp;<?php echo number_format($tkbmTotal, 0, ',', '.'); ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>