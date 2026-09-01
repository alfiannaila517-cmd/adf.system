<?php

/**
 * Gudang Nasita — Finance
 * 1) Uang masuk dari bisnis yang bayar tagihan
 * 2) Pembagian biaya TKBM (dicatat sebagai pengeluaran di buku kas Gudang Nasita)
 * 3) Laporan keuangan gudang (ringkasan pemasukan/pengeluaran per bulan)
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();
if (!($auth->hasPermission('gudang_finance') || $auth->hasPermission('gudang_nasita') || $auth->hasPermission('warehouse'))) {
    http_response_code(403);
    echo 'Akses ditolak.';
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Finance Gudang';

gudangNasitaEnsureAccountingTables($db);

// Make sure cash_book accepts null division/category (same auto-fix used across the app).
try {
    $db->getConnection()->exec("ALTER TABLE `cash_book` DROP FOREIGN KEY `cash_book_ibfk_3`");
} catch (Throwable $e) {
}
try {
    $db->getConnection()->exec("ALTER TABLE `cash_book` MODIFY COLUMN `division_id` INT NULL");
    $db->getConnection()->exec("ALTER TABLE `cash_book` MODIFY COLUMN `category_id` INT NULL");
} catch (Throwable $e) {
}

// ── POST: tambah TKBM langsung dari Finance ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_tkbm') {
    $tanggal   = trim($_POST['tanggal'] ?? date('Y-m-d'));
    $biaya     = (float)($_POST['total_biaya'] ?? 0);
    $ket       = trim($_POST['keterangan'] ?? '');
    $jmlBisnis = max(1, (int)($_POST['jumlah_bisnis'] ?? 3));
    if ($biaya > 0) {
        gudangNasitaTkbmAdd($tanggal, $biaya, $ket, $jmlBisnis, (int)($currentUser['id'] ?? 0));
        setFlash('success', 'TKBM berhasil ditambahkan dan tercatat di Finance.');
    }
    header('Location: finance.php?bulan=' . urlencode($_GET['bulan'] ?? date('Y-m')));
    exit;
}

// ── POST: hapus TKBM ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_tkbm') {
    $tid = (int)($_POST['tkbm_id'] ?? 0);
    if ($tid > 0) {
        gudangNasitaTkbmDelete($tid);
        setFlash('success', 'TKBM dihapus.');
    }
    header('Location: finance.php?bulan=' . urlencode($_GET['bulan'] ?? date('Y-m')));
    exit;
}

$selectedMonth = trim((string)($_GET['bulan'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}
$monthStart = $selectedMonth . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$monthLabel = date('F Y', strtotime($monthStart));

// 1) Uang masuk dari bisnis yang bayar tagihan (income tercatat via gudangTagihanPayMonthlyBill)
$incomeRows = [];
try {
    $incomeRows = $db->fetchAll(
        "SELECT id, transaction_date, description, amount
         FROM cash_book
         WHERE source_type = 'gudang_tagihan_income' AND transaction_date BETWEEN ? AND ?
         ORDER BY transaction_date DESC, id DESC
         LIMIT 200",
        [$monthStart, $monthEnd]
    ) ?: [];
} catch (Throwable $e) {
    error_log('gudang finance income rows: ' . $e->getMessage());
}
$incomeTotal = array_sum(array_column($incomeRows, 'amount'));

// 2) TKBM (biaya bongkar muat) — sudah tercatat sebagai pengeluaran finance
$tkbmRows = [];
try {
    gudangNasitaTkbmEnsureCashBookColumn($db);
    $tkbmRows = $db->fetchAll(
        'SELECT * FROM gudang_nasita_tkbm WHERE tanggal BETWEEN ? AND ? ORDER BY tanggal DESC, id DESC LIMIT 200',
        [$monthStart, $monthEnd]
    ) ?: [];
} catch (Throwable $e) {
    error_log('gudang finance tkbm rows: ' . $e->getMessage());
}
$tkbmTotal = array_sum(array_column($tkbmRows, 'total_biaya'));

// 3) Tagihan ke Supplier (uang keluar untuk bayar supplier) — direkap per supplier,
// dihitung dari barang yang sudah diterima (received_quantity × unit_price), bukan seluruh PO.
$supplierBillsAgg = [];
try {
    $supplierBillsAgg = $db->fetchAll(
        "SELECT COALESCE(s.supplier_name, '-') AS supplier_name,
                COUNT(DISTINCT poh.id) AS po_count,
                COALESCE(SUM(pod.received_quantity * pod.unit_price), 0) AS total_amount
         FROM purchase_orders_header poh
         LEFT JOIN suppliers s ON s.id = poh.supplier_id
         LEFT JOIN purchase_orders_detail pod ON pod.po_header_id = poh.id
         WHERE poh.status NOT IN ('cancelled', 'draft')
         GROUP BY poh.supplier_id
         HAVING total_amount > 0
         ORDER BY total_amount DESC
         LIMIT 50"
    ) ?: [];
} catch (Throwable $e) {
    error_log('gudang finance supplier bills: ' . $e->getMessage());
}
$supplierBillsTotal = array_sum(array_column($supplierBillsAgg, 'total_amount'));
// Tandai supplier utama: yang namanya mengandung "jepara", atau kalau tidak ada, supplier dengan tagihan terbesar.
$mainSupplierIndex = null;
foreach ($supplierBillsAgg as $i => $sb) {
    if (stripos($sb['supplier_name'], 'jepara') !== false) {
        $mainSupplierIndex = $i;
        break;
    }
}
if ($mainSupplierIndex === null && !empty($supplierBillsAgg)) {
    $mainSupplierIndex = 0;
}

// 4) Laporan keuangan gudang — ringkasan bulan berjalan
$summary = getGudangNasitaFinanceSummary($selectedMonth);

$forceTheme = 'light';
include __DIR__ . '/../../includes/header.php';
?>

<style>
    .fin-card {
        border-radius: .75rem;
        padding: 1rem 1.1rem;
        background: var(--card-bg, #fff);
        border: 1px solid var(--border);
        box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
    }

    .fin-stat {
        border-radius: .65rem;
        padding: .8rem 1rem;
        background: var(--card-bg, #fff);
        border: 1px solid var(--border);
        border-left: 3px solid var(--fin-accent, #94a3b8);
    }

    .fin-stat-label {
        font-size: .7rem;
        color: var(--text-muted);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .03em;
    }

    .fin-stat-value {
        font-size: 1.25rem;
        font-weight: 800;
        color: var(--text-primary);
        margin-top: .3rem;
        line-height: 1.2;
    }

    .fin-stat-sub {
        font-size: .7rem;
        color: var(--text-muted);
        margin-top: .3rem;
    }

    .fin-dot {
        width: .45rem;
        height: .45rem;
        border-radius: 50%;
        display: inline-block;
        flex-shrink: 0;
    }

    .fin-badge {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .12rem .5rem;
        border-radius: 999px;
        font-size: .68rem;
        font-weight: 600;
        background: var(--bg-secondary, #f1f5f9);
        color: var(--text-muted);
        border: 1px solid var(--border);
        white-space: nowrap;
    }

    .fin-badge-highlight {
        background: #fff7ed;
        color: #c2410c;
        border-color: #fed7aa;
    }

    .fin-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .82rem;
    }

    .fin-table th {
        font-size: .66rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: var(--text-muted);
        padding: .4rem .55rem;
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
        text-align: left;
    }

    .fin-table td {
        padding: .45rem .55rem;
        border-bottom: 1px solid var(--border);
    }

    .fin-table tbody tr:hover td {
        background: var(--bg-secondary, #f8fafc);
    }

    .fin-table tr:last-child td {
        border-bottom: none;
    }

    .fin-table tfoot td {
        background: var(--bg-secondary, #f8fafc);
        font-weight: 700;
    }

    .fin-empty {
        text-align: center;
        padding: 1.25rem 1rem;
        color: var(--text-muted);
        font-size: .82rem;
    }

    .fin-section-title {
        font-size: .88rem;
        font-weight: 700;
        color: var(--text-primary);
        margin: 0;
        display: flex;
        align-items: center;
        gap: .45rem;
    }

    .fin-section-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: .5rem;
        margin-bottom: .75rem;
    }

    .fin-section-sub {
        font-size: .74rem;
        color: var(--text-muted);
        font-weight: 400;
        margin: .15rem 0 0 1.35rem;
    }

    .fin-grid-2 {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
        gap: 1rem;
    }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem;">
    <div>
        <h2 style="font-size:1.4rem;font-weight:800;margin:0;color:var(--text-primary);display:flex;align-items:center;gap:.55rem;">
            <i data-feather="dollar-sign"></i> Finance Gudang Nasita
        </h2>
        <p style="font-size:.82rem;color:var(--text-muted);margin:.2rem 0 0;">Pemasukan tagihan bisnis, biaya TKBM, tagihan supplier, dan laporan keuangan gudang.</p>
    </div>
    <form method="GET" style="display:flex;align-items:center;gap:.5rem;">
        <input type="month" name="bulan" value="<?php echo htmlspecialchars($selectedMonth); ?>" class="form-control" style="width:auto;">
        <button type="submit" class="btn btn-sm btn-primary">Tampilkan</button>
    </form>
</div>

<!-- ── Ringkasan ──────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:.75rem;margin-bottom:1.25rem;">
    <div class="fin-stat" style="--fin-accent:#0f9d6a;">
        <div class="fin-stat-label">Pemasukan &middot; <?php echo $monthLabel; ?></div>
        <div class="fin-stat-value">Rp <?php echo number_format($summary['income_total'], 0, ',', '.'); ?></div>
        <div class="fin-stat-sub">Tagihan bisnis: Rp <?php echo number_format($summary['income_tagihan'], 0, ',', '.'); ?></div>
    </div>
    <div class="fin-stat" style="--fin-accent:#e11d48;">
        <div class="fin-stat-label">Pengeluaran &middot; <?php echo $monthLabel; ?></div>
        <div class="fin-stat-value">Rp <?php echo number_format($summary['expense_total'], 0, ',', '.'); ?></div>
        <div class="fin-stat-sub">TKBM: Rp <?php echo number_format($summary['expense_tkbm'], 0, ',', '.'); ?></div>
    </div>
    <div class="fin-stat" style="--fin-accent:#d97706;">
        <div class="fin-stat-label">Tagihan Supplier</div>
        <div class="fin-stat-value">Rp <?php echo number_format($supplierBillsTotal, 0, ',', '.'); ?></div>
        <div class="fin-stat-sub">Belum dibayar &middot; semua periode</div>
    </div>
    <div class="fin-stat" style="--fin-accent:#2563eb;">
        <div class="fin-stat-label">Saldo &middot; <?php echo $monthLabel; ?></div>
        <div class="fin-stat-value">Rp <?php echo number_format($summary['saldo'], 0, ',', '.'); ?></div>
        <div class="fin-stat-sub">Pemasukan &minus; Pengeluaran</div>
    </div>
</div>

<div class="fin-card" style="margin-bottom:1rem;">
    <div class="fin-section-head">
        <h3 class="fin-section-title"><i data-feather="pie-chart" style="width:16px;height:16px;"></i> Laporan Keuangan Gudang</h3>
    </div>
    <?php if (empty($summary['by_category'])): ?>
        <div class="fin-empty">Belum ada transaksi pada bulan ini.</div>
    <?php else: ?>
        <table class="fin-table">
            <thead>
                <tr>
                    <th>Kategori</th>
                    <th>Tipe</th>
                    <th style="text-align:right;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summary['by_category'] as $cat): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($cat['category_name']); ?></td>
                        <td>
                            <span class="fin-badge">
                                <span class="fin-dot" style="background:<?php echo $cat['transaction_type'] === 'income' ? '#0f9d6a' : '#e11d48'; ?>;"></span>
                                <?php echo $cat['transaction_type'] === 'income' ? 'Pemasukan' : 'Pengeluaran'; ?>
                            </span>
                        </td>
                        <td style="text-align:right;font-weight:700;">Rp <?php echo number_format((float)$cat['total'], 0, ',', '.'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- ── Uang Masuk & Uang Keluar berdampingan ────────────────────────────── -->
<div class="fin-grid-2" style="margin-bottom:1rem;">
    <div class="fin-card">
        <div class="fin-section-head">
            <h3 class="fin-section-title"><i data-feather="arrow-down-circle" style="width:16px;height:16px;color:#0f9d6a;"></i> Uang Masuk — Tagihan Bisnis</h3>
        </div>
        <?php if (empty($incomeRows)): ?>
            <div class="fin-empty">Belum ada pembayaran tagihan dari bisnis pada bulan ini.</div>
        <?php else: ?>
            <table class="fin-table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Keterangan</th>
                        <th style="text-align:right;">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($incomeRows as $row): ?>
                        <tr>
                            <td style="white-space:nowrap;"><?php echo date('d M Y', strtotime($row['transaction_date'])); ?></td>
                            <td><?php echo htmlspecialchars($row['description']); ?></td>
                            <td style="text-align:right;font-weight:700;color:#0f9d6a;">Rp <?php echo number_format((float)$row['amount'], 0, ',', '.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2">Total Pemasukan</td>
                        <td style="text-align:right;color:#0f9d6a;">Rp <?php echo number_format($incomeTotal, 0, ',', '.'); ?></td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
    </div>

    <div class="fin-card">
        <div class="fin-section-head">
            <h3 class="fin-section-title"><i data-feather="arrow-up-circle" style="width:16px;height:16px;color:#d97706;"></i> Uang Keluar — Tagihan Supplier</h3>
            <a href="<?php echo BASE_URL; ?>/modules/procurement/gudang-tagihan.php" class="btn btn-sm btn-secondary">Detail per PO</a>
        </div>
        <?php if (empty($supplierBillsAgg)): ?>
            <div class="fin-empty">Belum ada tagihan supplier.</div>
        <?php else: ?>
            <table class="fin-table">
                <thead>
                    <tr>
                        <th>Supplier</th>
                        <th style="text-align:center;">PO</th>
                        <th style="text-align:right;">Tagihan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supplierBillsAgg as $i => $sb): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($sb['supplier_name']); ?>
                                <?php if ($i === $mainSupplierIndex): ?>
                                    <span class="fin-badge fin-badge-highlight">Supplier Utama</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;color:#64748b;"><?php echo (int)$sb['po_count']; ?></td>
                            <td style="text-align:right;font-weight:700;color:#d97706;">Rp <?php echo number_format((float)$sb['total_amount'], 0, ',', '.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2">Total Tagihan Supplier</td>
                        <td style="text-align:right;color:#d97706;">Rp <?php echo number_format($supplierBillsTotal, 0, ',', '.'); ?></td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- ── Biaya TKBM ─────────────────────────────────────────────────────── -->
<div class="fin-card">
    <div class="fin-section-head">
        <div>
            <h3 class="fin-section-title"><i data-feather="users" style="width:16px;height:16px;"></i> Biaya TKBM</h3>
            <p class="fin-section-sub">Tenaga Kerja Bongkar Muat — otomatis tercatat sebagai pengeluaran Finance</p>
        </div>
        <button type="button" class="btn btn-sm btn-primary" onclick="document.getElementById('finTkbmForm').style.display='flex'">+ Tambah TKBM</button>
    </div>

    <form id="finTkbmForm" method="POST" style="display:none;gap:.65rem;flex-wrap:wrap;align-items:flex-end;background:var(--bg-secondary,#f8fafc);padding:.85rem 1rem;border-radius:.65rem;margin-bottom:1rem;">
        <input type="hidden" name="action" value="add_tkbm">
        <div>
            <label class="form-label" style="font-size:.78rem;">Tanggal</label>
            <input type="date" name="tanggal" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div>
            <label class="form-label" style="font-size:.78rem;">Total Biaya TKBM (Rp)</label>
            <input type="number" name="total_biaya" class="form-control" min="0" step="1000" required>
        </div>
        <div>
            <label class="form-label" style="font-size:.78rem;">Jumlah Bisnis (dibagi rata)</label>
            <input type="number" name="jumlah_bisnis" class="form-control" min="1" value="3" style="width:100px;" required>
        </div>
        <div style="flex:1;min-width:180px;">
            <label class="form-label" style="font-size:.78rem;">Keterangan</label>
            <input type="text" name="keterangan" class="form-control" placeholder="Opsional">
        </div>
        <div style="display:flex;gap:.5rem;">
            <button type="submit" class="btn btn-sm btn-success">Simpan</button>
            <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('finTkbmForm').style.display='none'">Batal</button>
        </div>
    </form>

    <table class="fin-table">
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Keterangan</th>
                <th style="text-align:right;">Total Biaya</th>
                <th style="text-align:center;">Dibagi</th>
                <th style="text-align:right;">Per Bisnis</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tkbmRows)): ?>
                <tr>
                    <td colspan="6" class="fin-empty">Belum ada data TKBM bulan ini.</td>
                </tr>
            <?php else: foreach ($tkbmRows as $tkbm):
                $perBisnis = (float)$tkbm['total_biaya'] / max(1, (int)$tkbm['jumlah_bisnis']); ?>
                <tr>
                    <td style="white-space:nowrap;"><?php echo date('d M Y', strtotime($tkbm['tanggal'])); ?></td>
                    <td><?php echo htmlspecialchars($tkbm['keterangan'] ?? '-'); ?></td>
                    <td style="text-align:right;font-weight:700;">Rp <?php echo number_format((float)$tkbm['total_biaya'], 0, ',', '.'); ?></td>
                    <td style="text-align:center;color:#64748b;"><?php echo (int)$tkbm['jumlah_bisnis']; ?> bisnis</td>
                    <td style="text-align:right;">Rp <?php echo number_format($perBisnis, 0, ',', '.'); ?></td>
                    <td style="text-align:right;">
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus entri TKBM ini? Pengeluaran terkait di Finance juga akan dihapus.')">
                            <input type="hidden" name="action" value="delete_tkbm">
                            <input type="hidden" name="tkbm_id" value="<?php echo (int)$tkbm['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger" style="padding:.2rem .5rem;"><i data-feather="trash-2" style="width:14px;height:14px;"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
        <?php if ($tkbmTotal > 0): ?>
            <tfoot>
                <tr>
                    <td colspan="2">Total TKBM</td>
                    <td style="text-align:right;color:#e11d48;">Rp <?php echo number_format($tkbmTotal, 0, ',', '.'); ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        <?php endif; ?>
    </table>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

