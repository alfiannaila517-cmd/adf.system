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
$pageTitle = 'Kas & Biaya Gudang';

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
    header('Location: finance.php?bulan=' . urlencode($_GET['bulan'] ?? (substr((string)($_POST['tanggal'] ?? ''), 0, 7) ?: date('Y-m'))));
    exit;
}

// ── POST: hapus TKBM ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_tkbm') {
    $tid = (int)($_POST['tkbm_id'] ?? 0);
    if ($tid > 0) {
        gudangNasitaTkbmDelete($tid);
        setFlash('success', 'TKBM dihapus.');
    }
    header('Location: finance.php?bulan=' . urlencode($_GET['bulan'] ?? (substr((string)($_POST['tanggal'] ?? ''), 0, 7) ?: date('Y-m'))));
    exit;
}

// ── POST: bayar tagihan barang masuk dari bisnis (mis. Narayana kirim roti/pisang ke gudang) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_supply_bill') {
    $slug = trim($_POST['slug'] ?? '');
    if ($slug !== '') {
        try {
            $msg = gudangNasitaPayIncomingSupplyBill($slug, (int)($currentUser['id'] ?? 0));
            setFlash('success', $msg);
        } catch (Throwable $e) {
            setFlash('error', $e->getMessage());
        }
    }
    header('Location: finance.php?bulan=' . urlencode($_GET['bulan'] ?? (substr((string)($_POST['tanggal'] ?? ''), 0, 7) ?: date('Y-m'))));
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

// 4) Tagihan barang masuk dari bisnis (mis. Narayana kirim roti/pisang ke gudang) —
// direkap per bisnis, bisa dibayar langsung dari Finance, uang masuk ke buku kas bisnis tsb.
$incomingSupplyBills = [];
try {
    $incomingSupplyBills = getGudangNasitaIncomingSupplyBills();
} catch (Throwable $e) {
    error_log('gudang finance incoming supply bills: ' . $e->getMessage());
}
$incomingSupplyOutstandingTotal = array_sum(array_column($incomingSupplyBills, 'outstanding'));

// 6) Buku kas Gudang bulan ini — semua uang masuk/keluar, dengan nama bisnis terkait.
// Nama bisnis diambil dari tabel pembayaran (bukan dari teks keterangan) supaya akurat.
$bizNameBySlug = array_column(gudangTrackedBizList(), 'name', 'slug');
$cashBookParty = [];
try {
    foreach ($db->fetchAll('SELECT business_slug, gudang_cash_book_id FROM gudang_nasita_tagihan_payments WHERE gudang_cash_book_id IS NOT NULL') ?: [] as $p) {
        $slug = gudangNormalizeBizSlug((string)$p['business_slug']);
        $cashBookParty[(int)$p['gudang_cash_book_id']] = $slug;
    }
} catch (Throwable $e) {
}
try {
    foreach ($db->fetchAll('SELECT source_business_slug, source_business_name, gudang_cash_book_id FROM gudang_nasita_supply_payments WHERE gudang_cash_book_id IS NOT NULL') ?: [] as $p) {
        $slug = gudangNormalizeBizSlug((string)$p['source_business_slug']);
        $cashBookParty[(int)$p['gudang_cash_book_id']] = $slug;
        $bizNameBySlug[$slug] = $bizNameBySlug[$slug] ?? ($p['source_business_name'] ?: $slug);
    }
} catch (Throwable $e) {
}

// Jenis transaksi → [label, warna teks, warna latar]
$cashKinds = [
    'from_biz' => ['Diterima dari bisnis', '#047857', '#d1fae5'],
    'to_biz'   => ['Dibayar ke bisnis', '#6d28d9', '#ede9fe'],
    'tkbm'     => ['Biaya TKBM', '#b45309', '#fef3c7'],
    'other_in' => ['Pemasukan lain', '#0369a1', '#e0f2fe'],
    'other_out' => ['Pengeluaran lain', '#be123c', '#ffe4e6'],
];
$cashRows = [];
$cashIn = 0.0;
$cashOut = 0.0;
$outToBiz = 0.0;
$outTkbm = 0.0;
$outOther = 0.0;
$perBiz = []; // slug bisnis => ['in' => .., 'out' => ..]
try {
    $rawCash = $db->fetchAll(
        "SELECT cb.id, cb.transaction_date, cb.transaction_type, cb.amount, cb.description, cb.source_type, c.category_name
         FROM cash_book cb
         LEFT JOIN categories c ON c.id = cb.category_id
         WHERE cb.transaction_date BETWEEN ? AND ?
           AND (cb.source_type IS NULL OR cb.source_type <> 'cash_transfer')
         ORDER BY cb.transaction_date ASC, cb.id ASC",
        [$monthStart, $monthEnd]
    ) ?: [];
    $running = 0.0;
    foreach ($rawCash as $cb) {
        $amount = (float)$cb['amount'];
        $isIn = $cb['transaction_type'] === 'income';
        $source = (string)($cb['source_type'] ?? '');
        if ($source === 'gudang_tagihan_income') {
            $kind = 'from_biz';
        } elseif ($source === 'gudang_supply_payment') {
            $kind = 'to_biz';
        } elseif ($source === 'gudang_tkbm') {
            $kind = 'tkbm';
        } else {
            $kind = $isIn ? 'other_in' : 'other_out';
        }
        $partySlug = $cashBookParty[(int)$cb['id']] ?? '';
        $party = $partySlug !== '' ? ($bizNameBySlug[$partySlug] ?? $partySlug) : '';
        // Transaksi lama tanpa catatan pembayaran: ambil nama bisnis dari teks keterangan.
        if ($party === '' && preg_match('/^(?:Diterima dari (.+?) - |Bayar Barang Kiriman (.+?)(?: \(|$)|Bayar Tagihan Barang Masuk - (.+)$)/u', (string)$cb['description'], $pm)) {
            $party = trim($pm[1] ?: ($pm[2] ?? '') ?: ($pm[3] ?? ''));
            $partySlug = (string)(gudangTagihanMatchBizSlug($party) ?? '');
        }
        if ($isIn) {
            $cashIn += $amount;
            $running += $amount;
        } else {
            $cashOut += $amount;
            $running -= $amount;
            if ($kind === 'to_biz') {
                $outToBiz += $amount;
            } elseif ($kind === 'tkbm') {
                $outTkbm += $amount;
            } else {
                $outOther += $amount;
            }
        }
        if ($partySlug !== '' && in_array($kind, ['from_biz', 'to_biz'], true)) {
            $perBiz[$partySlug] = $perBiz[$partySlug] ?? ['in' => 0.0, 'out' => 0.0];
            $perBiz[$partySlug][$kind === 'from_biz' ? 'in' : 'out'] += $amount;
        }
        $cashRows[] = [
            'date'    => $cb['transaction_date'],
            'desc'    => (string)$cb['description'],
            'kind'    => $kind,
            'party'   => $party,
            'in'      => $isIn ? $amount : 0.0,
            'out'     => $isIn ? 0.0 : $amount,
            'balance' => $running,
        ];
    }
} catch (Throwable $e) {
    error_log('gudang finance cash book: ' . $e->getMessage());
}
$cashRows = array_reverse($cashRows); // terbaru di atas
$cashSaldo = $cashIn - $cashOut;
// Bisnis yang ditagih bulanan selalu tampil di ringkasan, walau belum ada transaksi.
foreach (gudangTrackedBizList() as $trackedBiz) {
    $perBiz[$trackedBiz['slug']] = $perBiz[$trackedBiz['slug']] ?? ['in' => 0.0, 'out' => 0.0];
}

// 5) Laporan keuangan gudang — ringkasan bulan berjalan
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

<style>
    .kas-head { display:flex; justify-content:space-between; align-items:flex-end; gap:.75rem; flex-wrap:wrap; margin-bottom:1rem; }
    .kas-kpis { display:grid; grid-template-columns:repeat(4, 1fr); gap:.75rem; margin-bottom:1rem; }
    .kas-kpi { background:#fff; border:1px solid #e2e8f0; border-radius:.75rem; padding:.8rem .95rem; border-top:3px solid var(--k, #2563eb); }
    .kas-kpi small { display:block; font-size:.68rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:#64748b; }
    .kas-kpi b { display:block; font-size:1.25rem; margin:.2rem 0 .15rem; color:#0f172a; }
    .kas-kpi span { display:block; font-size:.72rem; color:#64748b; line-height:1.45; }
    .kas-kpi a { color:inherit; }
    .kas-badge { display:inline-block; font-size:.66rem; font-weight:700; padding:2px 8px; border-radius:999px; white-space:nowrap; }
    .kas-filter { display:flex; gap:.35rem; }
    .kas-filter button { border:1px solid #e2e8f0; background:#fff; border-radius:999px; padding:.25rem .7rem; font-size:.74rem; font-weight:600; color:#475569; cursor:pointer; }
    .kas-filter button.on { background:#1e40af; border-color:#1e40af; color:#fff; }
    .kas-num-in { color:#047857; font-weight:700; }
    .kas-num-out { color:#be123c; font-weight:700; }
    .kas-muted { color:#94a3b8; }
    @media (max-width: 1000px) { .kas-kpis { grid-template-columns:repeat(2, 1fr); } }
</style>

<div class="kas-head">
    <div>
        <h2 style="font-size:1.3rem;font-weight:800;margin:0;color:var(--text-primary);">Kas &amp; Biaya Gudang</h2>
        <p style="font-size:.82rem;color:var(--text-muted);margin:.25rem 0 0;max-width:760px;">Catatan uang Gudang: <b>uang masuk</b> dari bisnis yang membayar tagihan, <b>uang keluar</b> saat Gudang membayar bisnis pengirim (mis. Narayana) dan biaya TKBM. Untuk menagih atau membayar, buka <a href="<?php echo BASE_URL; ?>/modules/procurement/gudang-tagihan.php">Tagihan Bisnis</a>.</p>
    </div>
    <form method="GET" style="display:flex;align-items:center;gap:.5rem;">
        <input type="month" name="bulan" value="<?php echo htmlspecialchars($selectedMonth); ?>" class="form-control" style="width:auto;" onchange="this.form.submit()">
    </form>
</div>

<!-- ── Ringkasan bulan ini ── -->
<div class="kas-kpis">
    <div class="kas-kpi" style="--k:#059669;">
        <small>Uang masuk · <?php echo $monthLabel; ?></small>
        <b>Rp <?php echo number_format($cashIn, 0, ',', '.'); ?></b>
        <span>Dari bisnis yang membayar tagihan Gudang</span>
    </div>
    <div class="kas-kpi" style="--k:#e11d48;">
        <small>Uang keluar · <?php echo $monthLabel; ?></small>
        <b>Rp <?php echo number_format($cashOut, 0, ',', '.'); ?></b>
        <span>Bayar ke bisnis Rp <?php echo number_format($outToBiz, 0, ',', '.'); ?> · TKBM Rp <?php echo number_format($outTkbm, 0, ',', '.'); ?><?php if ($outOther > 0): ?> · lain Rp <?php echo number_format($outOther, 0, ',', '.'); ?><?php endif; ?></span>
    </div>
    <div class="kas-kpi" style="--k:#2563eb;">
        <small>Saldo bulan ini</small>
        <b style="color:<?php echo $cashSaldo < 0 ? '#be123c' : '#0f172a'; ?>;">Rp <?php echo number_format($cashSaldo, 0, ',', '.'); ?></b>
        <span>Uang masuk − uang keluar</span>
    </div>
    <div class="kas-kpi" style="--k:#d97706;">
        <small>Belum dibayar Gudang</small>
        <b>Rp <?php echo number_format($supplierBillsTotal + $incomingSupplyOutstandingTotal, 0, ',', '.'); ?></b>
        <span>Supplier Rp <?php echo number_format($supplierBillsTotal, 0, ',', '.'); ?><br><a href="<?php echo BASE_URL; ?>/modules/procurement/gudang-tagihan.php#bayar-pengirim">Bisnis pengirim Rp <?php echo number_format($incomingSupplyOutstandingTotal, 0, ',', '.'); ?> →</a></span>
    </div>
</div>

<!-- ── Per bisnis ── -->
<?php $outstandingBySlug = array_column($incomingSupplyBills, 'outstanding', 'slug'); ?>
<div class="fin-card" style="margin-bottom:1rem;">
    <div class="fin-section-head">
        <div>
            <h3 class="fin-section-title"><i data-feather="briefcase" style="width:16px;height:16px;"></i> Uang dengan tiap bisnis · <?php echo $monthLabel; ?></h3>
            <p class="fin-section-sub">Berapa yang diterima dari tiap bisnis dan berapa yang dibayarkan Gudang ke bisnis tsb.</p>
        </div>
    </div>
    <table class="fin-table">
        <thead>
            <tr>
                <th>Bisnis</th>
                <th style="text-align:right;">Diterima dari bisnis</th>
                <th style="text-align:right;">Dibayar ke bisnis</th>
                <th style="text-align:right;">Masih harus dibayar Gudang</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($perBiz as $bizSlug => $pb):
                $owed = (float)($outstandingBySlug[$bizSlug] ?? 0); ?>
                <tr>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($bizNameBySlug[$bizSlug] ?? $bizSlug); ?></td>
                    <td style="text-align:right;" class="<?php echo $pb['in'] > 0 ? 'kas-num-in' : 'kas-muted'; ?>">Rp <?php echo number_format($pb['in'], 0, ',', '.'); ?></td>
                    <td style="text-align:right;" class="<?php echo $pb['out'] > 0 ? 'kas-num-out' : 'kas-muted'; ?>">Rp <?php echo number_format($pb['out'], 0, ',', '.'); ?></td>
                    <td style="text-align:right;<?php echo $owed > 0 ? 'font-weight:700;color:#6d28d9;' : 'color:#94a3b8;'; ?>">Rp <?php echo number_format($owed, 0, ',', '.'); ?></td>
                    <td style="text-align:right;">
                        <?php if ($owed > 0): ?>
                            <a class="btn btn-sm btn-secondary" style="font-size:.72rem;padding:.2rem .6rem;" href="<?php echo BASE_URL; ?>/modules/procurement/gudang-tagihan.php#rincian-<?php echo htmlspecialchars($bizSlug); ?>">Rincian &amp; bayar</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ── Buku kas ── -->
<div class="fin-card" style="margin-bottom:1rem;">
    <div class="fin-section-head" style="flex-wrap:wrap;gap:.5rem;">
        <div>
            <h3 class="fin-section-title"><i data-feather="book-open" style="width:16px;height:16px;"></i> Buku Kas Gudang · <?php echo $monthLabel; ?></h3>
            <p class="fin-section-sub">Semua uang masuk &amp; keluar, terbaru di atas. Saldo dihitung dari awal bulan.</p>
        </div>
        <div class="kas-filter" id="kasFilter">
            <button type="button" class="on" data-f="all">Semua</button>
            <button type="button" data-f="in">Masuk</button>
            <button type="button" data-f="out">Keluar</button>
        </div>
    </div>
    <?php if (empty($cashRows)): ?>
        <div class="fin-empty">Belum ada uang masuk atau keluar pada <?php echo $monthLabel; ?>.</div>
    <?php else: ?>
        <div style="max-height:460px;overflow-y:auto;">
            <table class="fin-table" id="kasTable">
                <thead style="position:sticky;top:0;background:#fff;z-index:1;">
                    <tr>
                        <th>Tanggal</th>
                        <th>Jenis</th>
                        <th>Bisnis</th>
                        <th>Keterangan</th>
                        <th style="text-align:right;">Masuk</th>
                        <th style="text-align:right;">Keluar</th>
                        <th style="text-align:right;">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cashRows as $cr):
                        [$kindLabel, $kindFg, $kindBg] = $cashKinds[$cr['kind']]; ?>
                        <tr data-dir="<?php echo $cr['in'] > 0 ? 'in' : 'out'; ?>">
                            <td style="white-space:nowrap;"><?php echo date('d M Y', strtotime($cr['date'])); ?></td>
                            <td><span class="kas-badge" style="color:<?php echo $kindFg; ?>;background:<?php echo $kindBg; ?>;"><?php echo $kindLabel; ?></span></td>
                            <td style="white-space:nowrap;"><?php echo $cr['party'] !== '' ? htmlspecialchars($cr['party']) : '<span class="kas-muted">—</span>'; ?></td>
                            <td style="font-size:.76rem;color:#475569;"><?php echo htmlspecialchars($cr['desc']); ?></td>
                            <td style="text-align:right;" class="kas-num-in"><?php echo $cr['in'] > 0 ? 'Rp ' . number_format($cr['in'], 0, ',', '.') : ''; ?></td>
                            <td style="text-align:right;" class="kas-num-out"><?php echo $cr['out'] > 0 ? 'Rp ' . number_format($cr['out'], 0, ',', '.') : ''; ?></td>
                            <td style="text-align:right;font-weight:600;white-space:nowrap;">Rp <?php echo number_format($cr['balance'], 0, ',', '.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4">Total <?php echo $monthLabel; ?></td>
                        <td style="text-align:right;" class="kas-num-in">Rp <?php echo number_format($cashIn, 0, ',', '.'); ?></td>
                        <td style="text-align:right;" class="kas-num-out">Rp <?php echo number_format($cashOut, 0, ',', '.'); ?></td>
                        <td style="text-align:right;">Rp <?php echo number_format($cashSaldo, 0, ',', '.'); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>
<script>
    // Filter buku kas: semua / masuk / keluar
    document.querySelectorAll('#kasFilter button').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#kasFilter button').forEach(function (b) { b.classList.toggle('on', b === btn); });
            var f = btn.dataset.f;
            document.querySelectorAll('#kasTable tbody tr').forEach(function (tr) {
                tr.style.display = (f === 'all' || tr.dataset.dir === f) ? '' : 'none';
            });
        });
    });
</script>

<!-- ── Tagihan supplier & TKBM ── -->
<div class="fin-grid-2" style="margin-bottom:1rem;">
    <div class="fin-card">
        <div class="fin-section-head">
            <div>
                <h3 class="fin-section-title"><i data-feather="truck" style="width:16px;height:16px;color:#d97706;"></i> Tagihan Supplier</h3>
                <p class="fin-section-sub">Nilai barang yang sudah diterima dari supplier · semua periode</p>
            </div>
            <a href="<?php echo BASE_URL; ?>/modules/procurement/gudang-po-supplier.php" class="btn btn-sm btn-secondary">Lihat PO</a>
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
                        <td colspan="2">Total</td>
                        <td style="text-align:right;color:#d97706;">Rp <?php echo number_format($supplierBillsTotal, 0, ',', '.'); ?></td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
    </div>

    <div class="fin-card">
        <div class="fin-section-head">
            <div>
                <h3 class="fin-section-title"><i data-feather="users" style="width:16px;height:16px;"></i> Biaya TKBM · <?php echo $monthLabel; ?></h3>
                <p class="fin-section-sub">Bongkar muat — tercatat sebagai uang keluar & dibagi ke tagihan 3 bisnis</p>
            </div>
            <button type="button" class="btn btn-sm btn-primary" onclick="document.getElementById('finTkbmForm').style.display='flex'">+ Tambah</button>
        </div>

        <form id="finTkbmForm" method="POST" style="display:none;gap:.6rem;flex-wrap:wrap;align-items:flex-end;background:var(--bg-secondary,#f8fafc);padding:.8rem .9rem;border-radius:.65rem;margin-bottom:.8rem;">
            <input type="hidden" name="action" value="add_tkbm">
            <div>
                <label class="form-label" style="font-size:.76rem;">Tanggal</label>
                <input type="date" name="tanggal" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div>
                <label class="form-label" style="font-size:.76rem;">Total biaya (Rp)</label>
                <input type="number" name="total_biaya" class="form-control" min="0" step="1000" required style="width:140px;">
            </div>
            <div>
                <label class="form-label" style="font-size:.76rem;">Dibagi</label>
                <input type="number" name="jumlah_bisnis" class="form-control" min="1" value="3" style="width:70px;" required>
            </div>
            <div style="flex:1;min-width:140px;">
                <label class="form-label" style="font-size:.76rem;">Keterangan</label>
                <input type="text" name="keterangan" class="form-control" placeholder="Opsional">
            </div>
            <div style="display:flex;gap:.4rem;">
                <button type="submit" class="btn btn-sm btn-success">Simpan</button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('finTkbmForm').style.display='none'">Batal</button>
            </div>
        </form>

        <table class="fin-table">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Keterangan</th>
                    <th style="text-align:right;">Biaya</th>
                    <th style="text-align:right;">Per bisnis</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tkbmRows)): ?>
                    <tr>
                        <td colspan="5" class="fin-empty">Belum ada TKBM bulan ini.</td>
                    </tr>
                <?php else: foreach ($tkbmRows as $tkbm):
                    $perBisnis = (float)$tkbm['total_biaya'] / max(1, (int)$tkbm['jumlah_bisnis']); ?>
                    <tr>
                        <td style="white-space:nowrap;"><?php echo date('d M Y', strtotime($tkbm['tanggal'])); ?></td>
                        <td><?php echo htmlspecialchars($tkbm['keterangan'] ?: '-'); ?></td>
                        <td style="text-align:right;font-weight:700;">Rp <?php echo number_format((float)$tkbm['total_biaya'], 0, ',', '.'); ?></td>
                        <td style="text-align:right;color:#64748b;">Rp <?php echo number_format($perBisnis, 0, ',', '.'); ?> <small>(<?php echo (int)$tkbm['jumlah_bisnis']; ?>)</small></td>
                        <td style="text-align:right;">
                            <form method="POST" action="finance.php?bulan=<?php echo urlencode($selectedMonth); ?>" style="display:inline;" onsubmit="return confirm('Hapus entri TKBM ini? Pengeluaran terkait di buku kas juga akan dihapus.')">
                                <input type="hidden" name="action" value="delete_tkbm">
                                <input type="hidden" name="tkbm_id" value="<?php echo (int)$tkbm['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger" style="padding:.2rem .45rem;"><i data-feather="trash-2" style="width:13px;height:13px;"></i></button>
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
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
