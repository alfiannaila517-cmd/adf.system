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
        c.category_name AS category,
        COALESCE(d.division_name, '-') AS division_name,
        COALESCE(d.division_code, '') AS division_code,
        COALESCE(u.full_name, 'System') AS created_by_name
    FROM cash_book cb
    LEFT JOIN categories c ON cb.category_id = c.id
    LEFT JOIN divisions d ON cb.division_id = d.id
    LEFT JOIN " . DB_NAME . ".users u ON cb.created_by = u.id
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
        @page { size: A4 landscape; margin: 9mm 10mm 10mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root { --navy: #0f2747; --ink: #111827; --mute: #6b7280; --line: #d9dee7; --soft: #f5f7fa; --ok: #047857; --bad: #b91c1c; }
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; color: var(--ink); background: #e9edf3; font-size: 10.5px; line-height: 1.4; padding: 14px 10px 50px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .sheet { max-width: 1080px; margin: 0 auto; background: #fff; padding: 20px 24px 18px; box-shadow: 0 2px 10px rgba(15, 23, 42, .12); }
        table { width: 100%; border-collapse: collapse; }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }

        /* Kop */
        .head { display: flex; justify-content: space-between; align-items: flex-end; gap: 14px; padding-bottom: 8px; border-bottom: 2px solid var(--navy); }
        .brand { display: flex; align-items: center; gap: 9px; min-width: 0; }
        .brand img { width: 30px; height: 30px; object-fit: cover; border-radius: 50%; flex-shrink: 0; }
        .brand .mono { width: 30px; height: 30px; border-radius: 7px; display: grid; place-items: center; background: var(--navy); color: #fff; font-weight: 800; font-size: 14px; flex-shrink: 0; }
        .brand b { display: block; font-size: 14px; font-weight: 800; color: var(--navy); line-height: 1.15; }
        .brand small { display: block; font-size: 9px; color: var(--mute); }
        .doc { text-align: right; flex-shrink: 0; }
        .doc b { display: block; font-size: 12px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: var(--navy); }
        .doc small { display: block; font-size: 9px; color: var(--mute); }

        /* Info */
        .info td { padding: 4px 8px; font-size: 10px; border: 1px solid var(--line); }
        .info td.k { width: 14%; background: var(--soft); color: var(--mute); font-weight: 600; }
        .info td.v { width: 36%; font-weight: 600; }
        .info { margin: 10px 0 0; }

        h3 { margin: 12px 0 4px; font-size: 9.5px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--navy); }

        /* Tabel ringkas */
        .tbl th { padding: 5px 8px; background: var(--navy); color: #fff; font-size: 9px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; text-align: left; }
        .tbl th.num { text-align: right; }
        .tbl td { padding: 5px 8px; border-bottom: 1px solid var(--line); font-size: 10.5px; vertical-align: top; }
        .tbl tr:last-child td { border-bottom: 0; }
        .tbl { border: 1px solid var(--line); }
        .tbl tr.sum td { background: var(--soft); font-weight: 800; border-top: 1px solid var(--navy); }
        .tbl tr.sub td { font-size: 9.5px; color: var(--mute); padding: 3px 8px 3px 20px; }
        .in { color: var(--ok); } .out { color: var(--bad); }
        /* Tabel rincian gaya Buku Kas */
        .cb th { background: #1e3a8a; padding: 6px 7px; }
        .cb td { padding: 5px 7px; font-size: 10px; vertical-align: middle; border-bottom: 1px solid var(--line); }
        .cb .daterow td { background: var(--soft); font-size: 9.5px; font-weight: 700; color: #334155; padding: 5px 8px; }
        .cb .daterow span { font-weight: 600; color: var(--mute); margin-left: 12px; }
        .cb .div b { display: block; font-size: 10.5px; font-weight: 800; }
        .cb .div small { display: block; font-size: 7.5px; color: var(--mute); text-transform: uppercase; letter-spacing: .04em; }
        .pill { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 8.5px; font-weight: 800; letter-spacing: .03em; white-space: nowrap; }
        .pill.in { background: #dcfce7; color: var(--ok); } .pill.out { background: #fee2e2; color: var(--bad); }
        .pill.m { background: #eef2f7; color: #475569; text-transform: uppercase; }
        .pill.u { background: #f1f5f9; color: #475569; border: 1px solid var(--line); font-weight: 700; }
        .cb td.amt { font-weight: 800; }
        .cb tfoot td { font-size: 10.5px; }
        .two { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .three { display: grid; grid-template-columns: 1.1fr 1fr 1fr; gap: 12px; align-items: start; }

        /* Rincian transaksi */
        .tx thead { display: table-header-group; }
        .tx tbody tr { page-break-inside: avoid; }
        .tx td { padding: 4px 8px; line-height: 1.35; }
        .tx td.c { color: var(--mute); white-space: nowrap; }
        .tx td { border-bottom: 1px solid var(--line) !important; }
        .tx tbody tr:last-child td { border-bottom: 1px solid var(--line) !important; }
        .tx tfoot td { padding: 5px 8px; background: var(--soft); font-weight: 800; border-top: 1px solid var(--navy); }
        .dash { color: #c4cad4; }

        /* Pengesahan */
        .sign { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 16px; page-break-inside: avoid; }
        .sign div { text-align: center; font-size: 9.5px; color: var(--mute); }
        .sign i { display: block; height: 38px; border-bottom: 1px solid var(--ink); margin: 4px 14px 3px; }
        .sign b { display: block; color: var(--ink); font-size: 10px; min-height: 13px; }
        .foot { margin-top: 10px; padding-top: 6px; border-top: 1px solid var(--line); display: flex; justify-content: space-between; gap: 10px; font-size: 8.5px; color: var(--mute); }

        .print-button { position: fixed; top: 14px; right: 14px; z-index: 50; padding: 8px 16px; border: 0; border-radius: 8px; background: var(--navy); color: #fff; font: 600 12px 'Segoe UI', Arial, sans-serif; cursor: pointer; }
        @media print {
            body { background: #fff; padding: 0; }
            .sheet { max-width: none; box-shadow: none; padding: 0; }
            .no-print { display: none !important; }
        }
        @media (max-width: 640px) { .two { grid-template-columns: 1fr; } .sheet { padding: 14px 12px; } .info td.k { width: 22%; } }
    </style>
</head>
<body>
    <button onclick="window.print()" class="print-button no-print">🖨️ Cetak PDF</button>

    <div class="sheet">
        <div class="head">
            <div class="brand">
                <?php $ini = $esc(strtoupper(substr($business['business_name'], 0, 1))); ?>
                <?php if ($logoUrl !== ''): ?><img src="<?php echo $esc($logoUrl); ?>" alt="" onerror="this.outerHTML='<div class=&quot;mono&quot;><?php echo $ini; ?></div>'"><?php else: ?><div class="mono"><?php echo $ini; ?></div><?php endif; ?>
                <div><b><?php echo $esc($business['business_name']); ?></b><small>Laporan keuangan harian</small></div>
            </div>
            <div class="doc"><b>Laporan Akhir Shift</b><small>No. <?php echo $esc($reportNo); ?></small></div>
        </div>

        <table class="info">
            <tr><td class="k">Tanggal</td><td class="v"><?php echo $esc($todayId); ?></td><td class="k">Operator</td><td class="v"><?php echo $esc($operatorName); ?></td></tr>
            <tr><td class="k">Periode kas</td><td class="v"><?php echo $esc($bulanId[(int)date('n')] . ' ' . date('Y')); ?></td><td class="k">Waktu cetak</td><td class="v"><?php echo date('H:i:s'); ?> WIB</td></tr>
        </table>

        <div class="three">
            <div>
                <h3>Posisi Kas Bulan Ini</h3>
                <table class="tbl">
                    <thead><tr><th>Keterangan</th><th class="num" style="width:120px">Jumlah</th></tr></thead>
                    <tbody>
                        <tr><td>Start Cash (awal <?php echo $esc($bulanId[(int)date('n')]); ?>)</td><td class="num"><?php echo formatRupiah($startKasHariIni); ?></td></tr>
                        <tr><td>Owner Transfer</td><td class="num"><?php echo formatRupiah($ownerTransferThisMonth); ?></td></tr>
                        <tr><td>Pemasukan bulan ini <span style="color:var(--mute)">(Owner + Guest)</span></td><td class="num in"><?php echo formatRupiah($totalOperationalIncome + $guestCashIncome); ?></td></tr>
                        <tr><td>Pengeluaran bulan ini</td><td class="num out"><?php echo formatRupiah($totalOperationalExpense); ?></td></tr>
                        <tr class="sum"><td>Cash Available</td><td class="num <?php echo $cashAvailable >= 0 ? 'in' : 'out'; ?>"><?php echo formatRupiah($cashAvailable); ?></td></tr>
                    </tbody>
                </table>
            </div>
            <div>
                <h3>Ringkasan Hari Ini</h3>
                <table class="tbl">
                    <thead><tr><th>Pergerakan kas</th><th class="num" style="width:110px">Jumlah</th></tr></thead>
                    <tbody>
                        <tr><td>Pemasukan (<?php echo count($incomeTransactions); ?>)</td><td class="num in"><?php echo formatRupiah($totalIncome); ?></td></tr>
                        <tr><td>Pengeluaran (<?php echo count($expenseTransactions); ?>)</td><td class="num out"><?php echo formatRupiah($totalExpense); ?></td></tr>
                        <tr class="sum"><td>Selisih bersih</td><td class="num <?php echo $netToday >= 0 ? 'in' : 'out'; ?>"><?php echo ($netToday < 0 ? '- ' : '') . formatRupiah(abs($netToday)); ?></td></tr>
                    </tbody>
                </table>
            </div>
            <div>
                <h3>Pemasukan per Metode</h3>
                <table class="tbl">
                    <thead><tr><th>Metode</th><th class="num" style="width:110px">Jumlah</th></tr></thead>
                    <tbody>
                        <?php if ($byMethod): foreach ($byMethod as $m => $v): ?>
                            <tr><td><?php echo $esc($methodLabel($m)); ?></td><td class="num"><?php echo formatRupiah($v); ?></td></tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="2" style="color:var(--mute)">Belum ada pemasukan</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <h3>Rincian Transaksi (<?php echo count($transactions); ?>)</h3>
        <table class="tbl cb tx">
            <thead>
                <tr>
                    <th style="width:62px">Tanggal</th>
                    <th style="width:40px">Waktu</th>
                    <th style="width:62px">Divisi</th>
                    <th style="width:100px">Kategori/Nama</th>
                    <th style="width:54px">Tipe</th>
                    <th style="width:70px">Metode</th>
                    <th class="num" style="width:86px">Jumlah</th>
                    <th>Keterangan</th>
                    <th style="width:84px">Input By</th>
                </tr>
            </thead>
            <tbody>
                <tr class="daterow"><td colspan="9">Transaksi tanggal: <?php echo date('d/m/Y'); ?><span>Shift: <?php echo $esc($operatorName); ?></span><span>Cash: <?php echo formatRupiah($cashAvailable); ?></span></td></tr>
                <?php if ($transactions): foreach ($transactions as $trans): $isIn = $trans['transaction_type'] === 'income'; ?>
                    <tr>
                        <td><?php echo date('d/m/Y', strtotime($trans['transaction_date'])); ?></td>
                        <td><?php echo $esc(substr((string)($trans['transaction_time'] ?? ''), 0, 5)); ?></td>
                        <td class="div"><b><?php echo $esc($trans['division_name']); ?></b><?php if ($trans['division_code'] !== ''): ?><small><?php echo $esc($trans['division_code']); ?></small><?php endif; ?></td>
                        <td><?php echo $esc($trans['category']); ?></td>
                        <td><span class="pill <?php echo $isIn ? 'in' : 'out'; ?>"><?php echo $isIn ? 'MASUK' : 'KELUAR'; ?></span></td>
                        <td><span class="pill m"><?php echo $esc($methodLabel($trans['payment_method'])); ?></span></td>
                        <td class="num amt <?php echo $isIn ? 'in' : 'out'; ?>"><?php echo formatRupiah($trans['amount']); ?></td>
                        <td><?php echo $esc($trans['description']); ?></td>
                        <td><span class="pill u"><?php echo $esc($trans['created_by_name']); ?></span></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="9" style="text-align:center;color:var(--mute);padding:14px">Tidak ada transaksi pada hari ini</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr><td colspan="6" class="num">Total pemasukan</td><td class="num in"><?php echo formatRupiah($totalIncome); ?></td><td colspan="2"></td></tr>
                <tr><td colspan="6" class="num">Total pengeluaran</td><td class="num out"><?php echo formatRupiah($totalExpense); ?></td><td colspan="2"></td></tr>
            </tfoot>
        </table>
        <div class="sign">
            <div>Dibuat oleh<i></i><b><?php echo $esc($operatorName); ?></b>Operator shift</div>
            <div>Diperiksa oleh<i></i><b>&nbsp;</b>Supervisor / Manajer</div>
            <div>Disetujui oleh<i></i><b>&nbsp;</b>Owner</div>
        </div>
        <div class="foot">
            <span>Dokumen dihasilkan otomatis dari buku kas <?php echo $esc(APP_NAME); ?> · <?php echo $esc($reportNo); ?></span>
            <span>Dicetak <?php echo date('d/m/Y H:i:s'); ?> oleh <?php echo $esc($_SESSION['username'] ?? $operatorName); ?></span>
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
