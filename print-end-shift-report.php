<?php
/**
 * End Shift Report - Print PDF
 * Laporan Akhir Shift dengan Detail Transaksi Harian
 */

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/business_helper.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit();
}

// Resolve business info (prefer selected_business_id, fallback to active business config)
$selectedBusinessId = $_SESSION['selected_business_id'] ?? null;
$business = null;
$operatorName = $_SESSION['username'] ?? 'Unknown';

// Get Master DB for user fetch
$masterDb = Database::getInstance();

if (isset($_SESSION['user_id'])) {
    $user = $masterDb->fetchOne("SELECT full_name FROM users WHERE id = ?", [$_SESSION['user_id']]);
    if ($user && !empty($user['full_name'])) {
        $operatorName = $user['full_name'];
    }
}

if ($selectedBusinessId) {
    $businessQuery = "SELECT * FROM businesses WHERE id = ?";
    $business = $masterDb->fetchOne($businessQuery, [$selectedBusinessId]);
}

if (!$business) {
    $activeConfig = getActiveBusinessConfig();
    if (!empty($activeConfig['database'])) {
        $business = [
            'business_name' => $activeConfig['name'] ?? 'Business',
            'database_name' => $activeConfig['database']
        ];
    }
}

if (!$business) {
    header('Location: ' . BASE_URL . '/select-business.php');
    exit();
}

// Switch to business database
$businessDb = Database::switchDatabase($business['database_name']);

// Get today's date
$today = date('Y-m-d');
$todayDisplay = date('d F Y');
$thisMonth = date('Y-m');
$firstDayOfMonth = date('Y-m-01');

// ============================================
// Daily Cash Calculation (Same as Dashboard)
// ============================================

// Get cash account IDs (grouped by type) from MASTER database
$capitalAccounts = [];
$pettyCashAccounts = [];

try {
    // Get business ID using proper function (same as dashboard)
    $businessId = getMasterBusinessId();
    
    // Create direct PDO connection to master database
    $masterPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get ALL owner_capital account IDs from MASTER database
    $stmt = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $capitalAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get ALL cash (Petty Cash) account IDs from MASTER database
    $stmt = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash'");
    $stmt->execute([$businessId]);
    $pettyCashAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {
    // Table might not exist
    error_log("End Shift Report - Cash Accounts Error: " . $e->getMessage());
}

$hasCashAccountIdCol = true;
try {
    $businessDb->getConnection()->query("SELECT cash_account_id FROM cash_book LIMIT 1");
} catch (\Throwable $e) {
    $hasCashAccountIdCol = false;
}

// Initialize values
$startKasHariIni = 0;
$ownerTransferThisMonth = 0;
$totalOperationalIncome = 0;
$totalOperationalExpense = 0;
$totalOperationalCash = 0;
$guestCashIncome = 0;

// Calculate Start Cash (balance at end of LAST month)
if ($hasCashAccountIdCol && (!empty($capitalAccounts) || !empty($pettyCashAccounts))) {
    $allAccIds = array_merge($capitalAccounts, $pettyCashAccounts);
    $placeholders = implode(',', array_fill(0, count($allAccIds), '?'));
    
    // Start Cash = balance before this month
    $qStart = "SELECT 
        COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
        COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
        FROM cash_book WHERE cash_account_id IN ($placeholders) AND transaction_date < ?";
    $pStart = array_merge($allAccIds, [$firstDayOfMonth]);
    $rStart = $businessDb->fetchOne($qStart, $pStart);
    $startKasHariIni = $rStart['bal'] ?? 0;
    
    // Owner Transfer THIS MONTH (income to operational accounts)
    $qOwner = "SELECT COALESCE(SUM(amount), 0) as total
        FROM cash_book WHERE cash_account_id IN ($placeholders) 
        AND transaction_type = 'income'
        AND DATE_FORMAT(transaction_date, '%Y-%m') = ?";
    $pOwner = array_merge($allAccIds, [$thisMonth]);
    $rOwner = $businessDb->fetchOne($qOwner, $pOwner);
    $ownerTransferThisMonth = $rOwner['total'] ?? 0;
    
    // Total Expense THIS MONTH
    $qExp = "SELECT COALESCE(SUM(amount), 0) as total
        FROM cash_book WHERE cash_account_id IN ($placeholders) 
        AND transaction_type = 'expense'
        AND DATE_FORMAT(transaction_date, '%Y-%m') = ?";
    $pExp = array_merge($allAccIds, [$thisMonth]);
    $rExp = $businessDb->fetchOne($qExp, $pExp);
    $totalOperationalExpense = $rExp['total'] ?? 0;
    
    // Total Income THIS MONTH from operational accounts
    $totalOperationalIncome = $ownerTransferThisMonth;
    
    // Current Balance (operational accounts)
    $qBal = "SELECT 
        COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
        COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
        FROM cash_book WHERE cash_account_id IN ($placeholders) AND DATE_FORMAT(transaction_date, '%Y-%m') = ?";
    $pBal = array_merge($allAccIds, [$thisMonth]);
    $rBal = $businessDb->fetchOne($qBal, $pBal);
    $totalOperationalCash = $startKasHariIni + ($rBal['bal'] ?? 0);
}

// Guest Cash Income (cash payments NOT from owner accounts)
$excludeAccountIds = array_merge($capitalAccounts ?? [], $pettyCashAccounts ?? []);
if (!empty($excludeAccountIds)) {
    $excludePlaceholders = implode(',', array_fill(0, count($excludeAccountIds), '?'));
    $cashIncomeResult = $businessDb->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total 
         FROM cash_book 
         WHERE transaction_type = 'income' 
         AND payment_method = 'cash'
         AND (cash_account_id IS NULL OR cash_account_id NOT IN ($excludePlaceholders))
         AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
        array_merge($excludeAccountIds, [$thisMonth])
    );
    $guestCashIncome = $cashIncomeResult['total'] ?? 0;
} else {
    $cashIncomeResult = $businessDb->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total 
         FROM cash_book 
         WHERE transaction_type = 'income' 
         AND payment_method = 'cash'
         AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
        [$thisMonth]
    );
    $guestCashIncome = $cashIncomeResult['total'] ?? 0;
}

// Cash Available = Operational Cash + Guest Cash
$cashAvailable = $totalOperationalCash + $guestCashIncome;

// ============================================
// Today's Transactions (for detail table)
// ============================================
$transactionsQuery = "
    SELECT 
        cb.id,
        cb.transaction_date,
        cb.transaction_time,
        cb.transaction_type,
        cb.description,
        cb.amount,
        cb.payment_method,
        cb.reference_no,
        cb.created_at,
        c.category_name AS category
    FROM cash_book cb
    LEFT JOIN categories c ON cb.category_id = c.id
    WHERE cb.transaction_date = ?
    ORDER BY cb.transaction_date ASC, cb.transaction_time ASC, cb.id ASC
";

$transactions = $businessDb->fetchAll($transactionsQuery, [$today]);

// Calculate today's totals
$totalIncome = 0;
$totalExpense = 0;
$incomeTransactions = [];
$expenseTransactions = [];

foreach ($transactions as $trans) {
    if ($trans['transaction_type'] === 'income') {
        $totalIncome += $trans['amount'];
        $incomeTransactions[] = $trans;
    } else {
        $totalExpense += $trans['amount'];
        $expenseTransactions[] = $trans;
    }
}

// Currency format function
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}
// Rekap hari ini per metode pembayaran (masuk) + saldo bersih
$byMethod = [];
foreach ($incomeTransactions as $it) {
    $m = trim((string)($it['payment_method'] ?? '')) ?: '-';
    $byMethod[$m] = ($byMethod[$m] ?? 0) + (float)$it['amount'];
}
arsort($byMethod);
$netToday = $totalIncome - $totalExpense;
$reportNo = 'ESR/' . date('Ymd') . '/' . date('Hi');
$logoUrl = '';
try {
    $lg = function_exists('getBusinessLogo') ? getBusinessLogo() : '';
    if (is_string($lg) && $lg !== '') $logoUrl = $lg;
} catch (\Throwable $e) {
}
$methodLabel = function ($m) {
    $m = trim((string)$m);
    if ($m === '') return '-';
    return ucwords(str_replace('_', ' ', $m));
};
$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$bulanId = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$hariId = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
$todayId = $hariId[(int)date('w')] . ', ' . date('j') . ' ' . $bulanId[(int)date('n')] . ' ' . date('Y');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Akhir Shift - <?php echo $esc($business['business_name']); ?> - <?php echo date('d M Y'); ?></title>
    <style>
        @page { size: A4; margin: 12mm 12mm 14mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root { --navy: #0f2747; --blue: #1e3a8a; --accent: #2563eb; --ink: #0f172a; --mute: #64748b; --faint: #94a3b8; --line: #e2e8f0; --soft: #f8fafc; --ok: #047857; --bad: #b91c1c; }
        body { font-family: 'Inter', 'Segoe UI', Tahoma, Arial, sans-serif; color: var(--ink); background: #e9edf3; font-size: 11px; line-height: 1.45; padding: 18px 12px 60px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .sheet { max-width: 794px; margin: 0 auto; background: #fff; padding: 28px 32px 24px; box-shadow: 0 2px 6px rgba(15, 23, 42, .06), 0 24px 60px -24px rgba(15, 23, 42, .25); position: relative; }
        .top-rule { height: 6px; background: var(--navy); margin: -28px -32px 22px; border-bottom: 2px solid #b08d57; }

        /* Kop */
        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; }
        .brand { display: flex; align-items: center; gap: 12px; }
        .brand img { width: 52px; height: 52px; object-fit: cover; border-radius: 50%; box-shadow: 0 0 0 1px var(--line); }
        .brand .mono { width: 46px; height: 46px; border-radius: 12px; display: grid; place-items: center; background: var(--navy); color: #fff; font-weight: 800; font-size: 18px; }
        .brand h1 { font-size: 17px; font-weight: 800; letter-spacing: -.01em; color: var(--navy); }
        .brand p { font-size: 10px; color: var(--mute); margin-top: 1px; }
        .doc { text-align: right; }
        .doc .t { font-size: 15px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--blue); }
        .doc .s { font-size: 10px; color: var(--mute); margin-top: 1px; }
        .doc .no { margin-top: 5px; font-size: 10px; font-weight: 700; color: var(--ink); font-variant-numeric: tabular-nums; }

        .meta { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0; margin: 16px 0 14px; border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
        .meta > div { padding: 8px 12px; border-left: 1px solid var(--line); background: var(--soft); }
        .meta > div:first-child { border-left: 0; }
        .meta small { display: block; font-size: 8px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--faint); }
        .meta b { display: block; margin-top: 2px; font-size: 11px; font-weight: 700; color: var(--ink); }

        /* Posisi kas */
        .sec-title { display: flex; align-items: center; gap: 8px; margin: 16px 0 8px; font-size: 9.5px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: var(--blue); }
        .sec-title::after { content: ''; flex: 1; height: 1px; background: var(--line); }
        .pos { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .pos .box { padding: 12px 14px; border-radius: 12px; border: 1px solid var(--line); background: var(--soft); }
        .pos .box small { display: block; font-size: 8.5px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--mute); }
        .pos .box b { display: block; margin-top: 4px; font-size: 19px; font-weight: 800; letter-spacing: -.01em; font-variant-numeric: tabular-nums; }
        .pos .box.avail { background: #ecfdf5; border-color: #a7f3d0; }
        .pos .box.avail small { color: var(--ok); } .pos .box.avail b { color: var(--ok); }
        .pos .box.avail.neg { background: #fef2f2; border-color: #fecaca; } .pos .box.avail.neg small, .pos .box.avail.neg b { color: var(--bad); }

        .kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 10px; }
        .kpi { padding: 10px 12px; border-radius: 12px; border: 1px solid var(--line); border-top: 3px solid var(--c); }
        .kpi small { display: block; font-size: 8px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--mute); }
        .kpi b { display: block; margin-top: 3px; font-size: 13.5px; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--ink); }
        .kpi span { display: block; margin-top: 1px; font-size: 8.5px; color: var(--faint); }

        /* Rekap hari ini */
        .today { display: grid; grid-template-columns: 1.15fr 1fr; gap: 12px; align-items: start; }
        .today table, .tx { width: 100%; border-collapse: collapse; }
        .today th { padding: 6px 8px; font-size: 8px; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; text-align: left; color: var(--mute); border-bottom: 1px solid var(--line); background: var(--soft); }
        .today td { padding: 6px 8px; font-size: 10.5px; border-bottom: 1px solid #f1f5f9; }
        .today td.r, .today th.r { text-align: right; font-variant-numeric: tabular-nums; }
        .today tr.net td { border-top: 2px solid var(--navy); border-bottom: 0; font-weight: 800; font-size: 11.5px; background: var(--soft); }

        /* Tabel transaksi */
        .tx thead th { background: var(--navy); color: #fff; padding: 8px 8px; font-size: 8.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; text-align: left; }
        .tx thead th.r { text-align: right; }
        .tx thead { display: table-header-group; }
        .tx tbody tr { page-break-inside: avoid; }
        .tx td { padding: 7px 8px; border-bottom: 1px solid var(--line); vertical-align: top; font-size: 10.5px; }
        .tx tbody tr:nth-child(even) td { background: #fbfcfe; }
        .tx td.no { color: var(--faint); width: 22px; font-variant-numeric: tabular-nums; }
        .tx td.time { white-space: nowrap; color: var(--mute); font-variant-numeric: tabular-nums; }
        .tx td.desc { line-height: 1.4; }
        .tx td.r { text-align: right; white-space: nowrap; font-weight: 700; font-variant-numeric: tabular-nums; }
        .tx td.in { color: var(--ok); } .tx td.out { color: var(--bad); }
        .tx td.dash { color: #cbd5e1; font-weight: 400; }
        .pill { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 8.5px; font-weight: 800; letter-spacing: .04em; }
        .pill.in { background: #dcfce7; color: var(--ok); } .pill.out { background: #fee2e2; color: var(--bad); }
        .chip { display: inline-block; padding: 1px 7px; border-radius: 6px; background: #eef2f7; color: #334155; font-size: 9.5px; font-weight: 600; white-space: nowrap; }
        .tx tfoot td { padding: 9px 8px; border-top: 2px solid var(--navy); font-weight: 800; font-size: 11px; background: var(--soft); }
        .tx tfoot td.r { font-size: 11.5px; }
        .empty { padding: 26px; text-align: center; border: 1px dashed var(--line); border-radius: 10px; color: var(--faint); font-style: italic; }

        /* Pengesahan */
        .sign { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; margin-top: 26px; page-break-inside: avoid; }
        .sign .box { text-align: center; }
        .sign .role { font-size: 9px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--mute); }
        .sign .space { height: 54px; border-bottom: 1px solid var(--ink); margin: 6px 12px 5px; }
        .sign .who { font-size: 10.5px; font-weight: 700; }
        .sign .sub { font-size: 8.5px; color: var(--faint); }
        .note { margin-top: 16px; padding: 9px 12px; border-radius: 10px; background: var(--soft); border: 1px solid var(--line); font-size: 8.5px; color: var(--mute); line-height: 1.5; }
        .foot { margin-top: 12px; padding-top: 8px; border-top: 1px solid var(--line); display: flex; justify-content: space-between; gap: 12px; font-size: 8.5px; color: var(--faint); }

        .print-button { position: fixed; top: 16px; right: 16px; z-index: 50; padding: 10px 20px; border: 0; border-radius: 10px; background: linear-gradient(135deg, #1e3a8a, #2563eb); color: #fff; font: 700 13px 'Segoe UI', Arial, sans-serif; cursor: pointer; box-shadow: 0 10px 22px -10px rgba(37, 99, 235, .8); }

        @media print {
            body { background: #fff; padding: 0; }
            .sheet { max-width: none; box-shadow: none; padding: 0; }
            .top-rule { margin: 0 0 16px; }
            .no-print { display: none !important; }
        }
        @media (max-width: 640px) {
            .meta { grid-template-columns: 1fr 1fr; } .kpis, .today, .sign { grid-template-columns: 1fr; }
            .sheet { padding: 20px 16px; } .top-rule { margin: -20px -16px 16px; }
        }
    </style>
</head>
<body>
    <button onclick="window.print()" class="print-button no-print">🖨️ Cetak PDF</button>

    <div class="sheet">
        <div class="top-rule"></div>

        <!-- Kop laporan -->
        <div class="head">
            <div class="brand">
                <?php if ($logoUrl !== ''): ?><img src="<?php echo $esc($logoUrl); ?>" alt="" onerror="this.outerHTML='<div class=&quot;mono&quot;><?php echo $esc(strtoupper(substr($business['business_name'], 0, 1))); ?></div>'"><?php else: ?><div class="mono"><?php echo $esc(strtoupper(substr($business['business_name'], 0, 1))); ?></div><?php endif; ?>
                <div>
                    <h1><?php echo $esc($business['business_name']); ?></h1>
                    <p>Laporan keuangan harian · <?php echo $esc(APP_NAME); ?></p>
                </div>
            </div>
            <div class="doc">
                <div class="t">Laporan Akhir Shift</div>
                <div class="s">End of Shift · Daily Cash Report</div>
                <div class="no">No. <?php echo $esc($reportNo); ?></div>
            </div>
        </div>

        <div class="meta">
            <div><small>Tanggal</small><b><?php echo $esc($todayId); ?></b></div>
            <div><small>Periode kas</small><b><?php echo $esc($bulanId[(int)date('n')] . ' ' . date('Y')); ?></b></div>
            <div><small>Operator</small><b><?php echo $esc($operatorName); ?></b></div>
            <div><small>Waktu cetak</small><b><?php echo date('H:i:s'); ?> WIB</b></div>
        </div>

        <!-- Posisi kas -->
        <div class="sec-title">Posisi Kas Bulan Ini</div>
        <div class="pos">
            <div class="box"><small>Start Cash (awal <?php echo $esc($bulanId[(int)date('n')]); ?>)</small><b><?php echo formatRupiah($startKasHariIni); ?></b></div>
            <div class="box avail<?php echo $cashAvailable < 0 ? ' neg' : ''; ?>"><small>Cash Available (saat ini)</small><b><?php echo formatRupiah($cashAvailable); ?></b></div>
        </div>
        <div class="kpis">
            <div class="kpi" style="--c:#f59e0b"><small>Owner Transfer</small><b><?php echo formatRupiah($ownerTransferThisMonth); ?></b><span>Setoran ke kas operasional bulan ini</span></div>
            <div class="kpi" style="--c:#10b981"><small>Owner + Guest</small><b><?php echo formatRupiah($totalOperationalIncome + $guestCashIncome); ?></b><span>Total pemasukan kas bulan ini</span></div>
            <div class="kpi" style="--c:#ef4444"><small>Expense</small><b><?php echo formatRupiah($totalOperationalExpense); ?></b><span>Total pengeluaran bulan ini</span></div>
        </div>

        <!-- Ringkasan hari ini -->
        <div class="sec-title">Ringkasan Hari Ini</div>
        <div class="today">
            <table>
                <thead><tr><th>Pergerakan kas</th><th class="r">Jumlah</th></tr></thead>
                <tbody>
                    <tr><td>Total pemasukan (<?php echo count($incomeTransactions); ?> transaksi)</td><td class="r" style="color:var(--ok);font-weight:700"><?php echo formatRupiah($totalIncome); ?></td></tr>
                    <tr><td>Total pengeluaran (<?php echo count($expenseTransactions); ?> transaksi)</td><td class="r" style="color:var(--bad);font-weight:700"><?php echo formatRupiah($totalExpense); ?></td></tr>
                    <tr class="net"><td>Selisih bersih hari ini</td><td class="r" style="color:<?php echo $netToday >= 0 ? 'var(--ok)' : 'var(--bad)'; ?>"><?php echo ($netToday < 0 ? '- ' : '') . formatRupiah(abs($netToday)); ?></td></tr>
                </tbody>
            </table>
            <table>
                <thead><tr><th>Pemasukan per metode</th><th class="r">Jumlah</th></tr></thead>
                <tbody>
                    <?php if ($byMethod): foreach ($byMethod as $m => $v): ?>
                        <tr><td><?php echo $esc($methodLabel($m)); ?></td><td class="r"><?php echo formatRupiah($v); ?></td></tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="2" style="color:var(--faint);font-style:italic">Belum ada pemasukan</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Rincian transaksi -->
        <div class="sec-title">Rincian Transaksi (<?php echo count($transactions); ?>)</div>
        <?php if (count($transactions) > 0): ?>
            <table class="tx">
                <thead>
                    <tr>
                        <th style="width:22px">#</th>
                        <th style="width:48px">Waktu</th>
                        <th>Keterangan</th>
                        <th style="width:86px">Kategori</th>
                        <th style="width:72px">Metode</th>
                        <th class="r" style="width:84px">Masuk</th>
                        <th class="r" style="width:84px">Keluar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $i => $trans): $in = $trans['transaction_type'] === 'income'; ?>
                        <tr>
                            <td class="no"><?php echo $i + 1; ?></td>
                            <td class="time"><?php echo $esc(substr((string)($trans['transaction_time'] ?? ''), 0, 5)); ?></td>
                            <td class="desc"><?php echo $esc($trans['description']); ?></td>
                            <td><?php echo $esc($trans['category']); ?></td>
                            <td><span class="chip"><?php echo $esc($methodLabel($trans['payment_method'])); ?></span></td>
                            <?php if ($in): ?>
                                <td class="r in"><?php echo formatRupiah($trans['amount']); ?></td><td class="r dash">—</td>
                            <?php else: ?>
                                <td class="r dash">—</td><td class="r out"><?php echo formatRupiah($trans['amount']); ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" style="text-align:right;letter-spacing:.06em;text-transform:uppercase;font-size:9px;color:var(--mute)">Total hari ini</td>
                        <td class="r" style="color:var(--ok)"><?php echo formatRupiah($totalIncome); ?></td>
                        <td class="r" style="color:var(--bad)"><?php echo formatRupiah($totalExpense); ?></td>
                    </tr>
                </tfoot>
            </table>
        <?php else: ?>
            <div class="empty">Tidak ada transaksi pada hari ini</div>
        <?php endif; ?>

        <!-- Pengesahan -->
        <div class="sign">
            <div class="box"><div class="role">Dibuat oleh</div><div class="space"></div><div class="who"><?php echo $esc($operatorName); ?></div><div class="sub">Operator shift</div></div>
            <div class="box"><div class="role">Diperiksa oleh</div><div class="space"></div><div class="who">&nbsp;</div><div class="sub">Supervisor / Manajer</div></div>
            <div class="box"><div class="role">Disetujui oleh</div><div class="space"></div><div class="who">&nbsp;</div><div class="sub">Owner / Pemilik</div></div>
        </div>

        <div class="note">
            Dokumen ini adalah laporan akhir shift yang dihasilkan otomatis dari buku kas <?php echo $esc(APP_NAME); ?> pada saat dicetak, dan mencerminkan data sistem
            sampai dengan <?php echo date('d/m/Y H:i:s'); ?> WIB. Selisih antara kas fisik dan Cash Available wajib dicatat dan dilaporkan kepada atasan sebelum shift ditutup.
        </div>
        <div class="foot">
            <span><?php echo $esc($reportNo); ?> · <?php echo $esc($business['business_name']); ?></span>
            <span>Dicetak <?php echo date('d F Y, H:i:s'); ?> oleh <?php echo $esc($_SESSION['username'] ?? $operatorName); ?></span>
        </div>
    </div>

    <script>
        // Auto print dialog on load
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 500);
        });

        // After print (including cancel), notify opener and close
        window.addEventListener('afterprint', function() {
            const logoutUrl = '<?php echo BASE_URL; ?>/logout.php';

            try {
                if (window.opener && window.opener !== window) {
                    window.opener.location.href = logoutUrl;
                } else {
                    window.location.href = logoutUrl;
                    return;
                }
            } catch (e) {
                window.location.href = logoutUrl;
                return;
            }

            setTimeout(function() {
                window.close();
            }, 300);
        });
    </script>
</body>
</html>
