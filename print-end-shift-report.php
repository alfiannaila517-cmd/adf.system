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

// ── Notifikasi owner: rekap harian saat End Shift (sekali per operator per 10 menit) ──
try {
    $esKey = 'esr_notified_' . ($business['database_name'] ?? '');
    if (empty($_SESSION[$esKey]) || time() - (int)$_SESSION[$esKey] > 600) {
        $_SESSION[$esKey] = time();
        $esOcc = ['occupied' => 0, 'total' => 0];
        try {
            $esOcc['total'] = (int)($businessDb->fetchOne("SELECT COUNT(*) c FROM rooms")['c'] ?? 0);
            $esOcc['occupied'] = (int)($businessDb->fetchOne("SELECT COUNT(DISTINCT room_id) c FROM bookings WHERE status = 'checked_in'")['c'] ?? 0);
        } catch (\Throwable $e) {
        }
        $esData = [
            'db' => $business['database_name'] ?? '',
            'business_name' => $business['business_name'] ?? '',
            'cashier' => $operatorName,
            'date' => $today,
            'income' => (float)$totalIncome,
            'expense' => (float)$totalExpense,
            'net' => (float)($totalIncome - $totalExpense),
            'cash_available' => isset($cashAvailable) ? (float)$cashAvailable : null,
            'tx_count' => count($transactions),
            'occ_occupied' => $esOcc['occupied'],
            'occ_total' => $esOcc['total'],
            'url' => '/modules/owner/dashboard-2028.php',
        ];
        $esTitle = 'End Shift · ' . ($business['business_name'] ?? '');
        $esMsg = $operatorName . ' menutup shift. Masuk Rp ' . number_format($totalIncome, 0, ',', '.') . ' · Keluar Rp ' . number_format($totalExpense, 0, ',', '.')
            . ' · Net Rp ' . number_format($totalIncome - $totalExpense, 0, ',', '.')
            . ($esOcc['total'] ? ' · Okupansi ' . $esOcc['occupied'] . '/' . $esOcc['total'] : '');
        $masterDb->query("CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, type VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL, message TEXT, data JSON, is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_read (user_id, is_read), INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $owners = $masterDb->fetchAll("SELECT u.id FROM users u JOIN roles r ON u.role_id = r.id
            WHERE r.role_code IN ('owner', 'admin', 'developer') AND u.is_active = 1") ?: [];
        foreach ($owners as $ow) {
            $masterDb->query("INSERT INTO notifications (user_id, type, title, message, data, is_read, created_at) VALUES (?, 'end_shift', ?, ?, ?, 0, NOW())",
                [(int)$ow['id'], $esTitle, $esMsg, json_encode($esData, JSON_UNESCAPED_UNICODE)]);
        }
        try {
            require_once __DIR__ . '/includes/PushNotificationHelper.php';
            (new PushNotificationHelper($masterDb))->sendToAdmins($esTitle, $esMsg, ['type' => 'end_shift', 'tag' => 'end_shift-' . time(), 'url' => $esData['url']]);
        } catch (\Throwable $e) {
            error_log('End shift push: ' . $e->getMessage());
        }
    }
} catch (\Throwable $e) {
    error_log('End shift notify: ' . $e->getMessage());
}
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
        @page { size: A4 portrait; margin: 14mm 14mm 14mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root { --navy: #0f2747; --ink: #111827; --mute: #6b7280; --line: #e5e7eb; --soft: #f8fafc; --ok: #047857; --bad: #b91c1c; }
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; color: var(--ink); background: #e9edf3; font-size: 10.5px; line-height: 1.45; padding: 14px 10px 50px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .sheet { max-width: 794px; margin: 0 auto; background: #fff; padding: 30px 34px 26px; box-shadow: 0 2px 10px rgba(15, 23, 42, .12); }
        table { width: 100%; border-collapse: collapse; }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .in { color: var(--ok); } .out { color: var(--bad); }

        /* Kop */
        .head { display: flex; justify-content: space-between; align-items: center; gap: 14px; padding-bottom: 12px; border-bottom: 2px solid var(--navy); }
        .brand { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .brand img { width: 38px; height: 38px; object-fit: cover; border-radius: 50%; flex-shrink: 0; }
        .brand .mono { width: 38px; height: 38px; border-radius: 9px; display: grid; place-items: center; background: var(--navy); color: #fff; font-weight: 800; font-size: 17px; flex-shrink: 0; }
        .brand b { display: block; font-size: 15px; font-weight: 800; color: var(--navy); line-height: 1.15; }
        .brand small { display: block; font-size: 9px; color: var(--mute); margin-top: 1px; }
        .doc { text-align: right; flex-shrink: 0; }
        .doc b { display: block; font-size: 12px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: var(--navy); }
        .doc small { display: block; font-size: 9px; color: var(--mute); margin-top: 2px; }

        /* Info: baris ringkas tanpa kotak */
        .meta { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; padding: 11px 0; border-bottom: 1px solid var(--line); }
        .meta span { display: block; font-size: 8px; color: var(--mute); text-transform: uppercase; letter-spacing: .08em; font-weight: 700; }
        .meta b { display: block; font-size: 10.5px; font-weight: 700; margin-top: 1px; }

        /* Angka utama hari ini */
        .kpi { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 14px 0 4px; }
        .kpi div { padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; background: var(--soft); }
        .kpi span { display: block; font-size: 8px; color: var(--mute); text-transform: uppercase; letter-spacing: .08em; font-weight: 700; }
        .kpi b { display: block; font-size: 15px; font-weight: 800; margin-top: 2px; font-variant-numeric: tabular-nums; }
        .kpi small { font-size: 8.5px; color: var(--mute); }

        h3 { margin: 18px 0 6px; font-size: 9px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: var(--navy); }

        /* Daftar ringkas dua kolom */
        .two { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; }
        .lst td { padding: 5px 0; border-bottom: 1px solid var(--line); font-size: 10.5px; }
        .lst tr:last-child td { border-bottom: 0; }
        .lst tr.sum td { border-top: 1.5px solid var(--navy); border-bottom: 0; font-weight: 800; padding-top: 6px; }
        .lst .mute { color: var(--mute); font-size: 9.5px; }

        /* Rincian transaksi */
        .tx thead { display: table-header-group; }
        .tx th { padding: 5px 6px; font-size: 8px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--mute); text-align: left; border-bottom: 1.5px solid var(--navy); }
        .tx th.num { text-align: right; }
        .tx td { padding: 6px; border-bottom: 1px solid var(--line); font-size: 10px; vertical-align: top; }
        .tx tbody tr { page-break-inside: avoid; }
        .tx td.t { color: var(--mute); white-space: nowrap; font-variant-numeric: tabular-nums; }
        .tx td.dv b { display: block; font-size: 10px; font-weight: 700; }
        .tx td.dv small { display: block; font-size: 7.5px; color: var(--mute); text-transform: uppercase; letter-spacing: .04em; }
        .tx td.ds b { font-weight: 700; }
        .tx td.ds small { display: block; font-size: 9px; color: var(--mute); margin-top: 1px; }
        .tx td.mt { font-size: 9px; color: #475569; white-space: nowrap; }
        .tx td.amt { font-weight: 800; }
        .tx tfoot td { padding: 6px; font-weight: 800; font-size: 10.5px; border-bottom: 0; }
        .tx tfoot tr:first-child td { border-top: 1.5px solid var(--navy); }

        /* Pengesahan */
        .sign { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; margin-top: 26px; page-break-inside: avoid; }
        .sign div { text-align: center; font-size: 9px; color: var(--mute); }
        .sign i { display: block; height: 42px; border-bottom: 1px solid var(--ink); margin: 4px 10px 3px; }
        .sign b { display: block; color: var(--ink); font-size: 10px; min-height: 13px; }
        .foot { margin-top: 16px; padding-top: 6px; border-top: 1px solid var(--line); display: flex; justify-content: space-between; gap: 10px; font-size: 8px; color: var(--mute); }

        .print-button { position: fixed; top: 14px; right: 14px; z-index: 50; padding: 8px 16px; border: 0; border-radius: 8px; background: var(--navy); color: #fff; font: 600 12px 'Segoe UI', Arial, sans-serif; cursor: pointer; }
        @media print {
            body { background: #fff; padding: 0; }
            .sheet { max-width: none; box-shadow: none; padding: 0; }
            .no-print { display: none !important; }
        }
        @media (max-width: 640px) { .two { grid-template-columns: 1fr; } .meta { grid-template-columns: 1fr 1fr; } .sheet { padding: 16px 14px; } }    </style>
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

        <div class="meta">
            <div><span>Tanggal</span><b><?php echo $esc($todayId); ?></b></div>
            <div><span>Periode kas</span><b><?php echo $esc($bulanId[(int)date('n')] . ' ' . date('Y')); ?></b></div>
            <div><span>Operator</span><b><?php echo $esc($operatorName); ?></b></div>
            <div><span>Waktu cetak</span><b><?php echo date('H:i:s'); ?> WIB</b></div>
        </div>

        <div class="kpi">
            <div><span>Pemasukan hari ini</span><b class="in"><?php echo formatRupiah($totalIncome); ?></b><small><?php echo count($incomeTransactions); ?> transaksi</small></div>
            <div><span>Pengeluaran hari ini</span><b class="out"><?php echo formatRupiah($totalExpense); ?></b><small><?php echo count($expenseTransactions); ?> transaksi</small></div>
            <div><span>Selisih bersih</span><b class="<?php echo $netToday >= 0 ? 'in' : 'out'; ?>"><?php echo ($netToday < 0 ? '- ' : '') . formatRupiah(abs($netToday)); ?></b><small>pemasukan − pengeluaran</small></div>
        </div>

        <div class="two">
            <div>
                <h3>Posisi Kas Bulan Ini</h3>
                <table class="lst">
                    <tr><td>Start Cash (awal <?php echo $esc($bulanId[(int)date('n')]); ?>)</td><td class="num"><?php echo formatRupiah($startKasHariIni); ?></td></tr>
                    <tr><td>Owner Transfer</td><td class="num"><?php echo formatRupiah($ownerTransferThisMonth); ?></td></tr>
                    <tr><td>Pemasukan <span class="mute">(Owner + Guest)</span></td><td class="num in"><?php echo formatRupiah($totalOperationalIncome + $guestCashIncome); ?></td></tr>
                    <tr><td>Pengeluaran</td><td class="num out"><?php echo formatRupiah($totalOperationalExpense); ?></td></tr>
                    <tr class="sum"><td>Cash Available</td><td class="num <?php echo $cashAvailable >= 0 ? 'in' : 'out'; ?>"><?php echo formatRupiah($cashAvailable); ?></td></tr>
                </table>
            </div>
            <div>
                <h3>Pemasukan per Metode</h3>
                <table class="lst">
                    <?php if ($byMethod): foreach ($byMethod as $m => $v): ?>
                        <tr><td><?php echo $esc($methodLabel($m)); ?></td><td class="num"><?php echo formatRupiah($v); ?></td></tr>
                    <?php endforeach; else: ?>
                        <tr><td class="mute">Belum ada pemasukan</td><td></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <h3>Rincian Transaksi (<?php echo count($transactions); ?>)</h3>
        <table class="tx">
            <thead>
                <tr>
                    <th style="width:38px">Waktu</th>
                    <th style="width:62px">Divisi</th>
                    <th>Kategori / Keterangan</th>
                    <th style="width:62px">Metode</th>
                    <th class="num" style="width:92px">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($transactions): foreach ($transactions as $trans): $isIn = $trans['transaction_type'] === 'income'; ?>
                    <tr>
                        <td class="t"><?php echo $esc(substr((string)($trans['transaction_time'] ?? ''), 0, 5)); ?></td>
                        <td class="dv"><b><?php echo $esc($trans['division_name']); ?></b><?php if ($trans['division_code'] !== ''): ?><small><?php echo $esc($trans['division_code']); ?></small><?php endif; ?></td>
                        <td class="ds"><b><?php echo $esc($trans['category']); ?></b><small><?php echo $esc($trans['description']); ?><?php if (!empty($trans['created_by_name'])): ?> · <?php echo $esc($trans['created_by_name']); ?><?php endif; ?></small></td>
                        <td class="mt"><?php echo $esc($methodLabel($trans['payment_method'])); ?></td>
                        <td class="num amt <?php echo $isIn ? 'in' : 'out'; ?>"><?php echo ($isIn ? '+ ' : '− ') . formatRupiah($trans['amount']); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" style="text-align:center;color:var(--mute);padding:16px">Tidak ada transaksi pada hari ini</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr><td colspan="4" class="num">Total pemasukan</td><td class="num in"><?php echo formatRupiah($totalIncome); ?></td></tr>
                <tr><td colspan="4" class="num">Total pengeluaran</td><td class="num out"><?php echo formatRupiah($totalExpense); ?></td></tr>
            </tfoot>
        </table>        <div class="sign">
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
