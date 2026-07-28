<?php
/**
 * Owner Capital Monitoring Dashboard
 * Track dan monitor Kas Modal Owner per bulan
 */

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

// Get business DB instance (for cash_book queries if needed)
$db = Database::getInstance();

// Get MASTER DB instance (for cash_accounts and cash_account_transactions)
$masterDb = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . MASTER_DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

// Check authorization
$userRole = $_SESSION['role'] ?? '';
if (!in_array($userRole, ['admin', 'owner', 'manager', 'developer'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// Get business ID
$businessId = getMasterBusinessId();

// Get period filter from GET or default to current month
$selectedPeriod = $_GET['period'] ?? date('Y-m');
$currentMonth = date('Y-m-01', strtotime($selectedPeriod . '-01'));
$nextMonth = date('Y-m-t', strtotime($currentMonth));

// Get Kas Modal Owner account from MASTER DB
$stmt = $masterDb->prepare(
    "SELECT * FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'"
);
$stmt->execute([$businessId]);
$ownerCapitalAccount = $stmt->fetch();

if (!$ownerCapitalAccount) {
    die('❌ Kas Modal Owner account not found. Please run accounting setup first.');
}

// Determine business DB name for cross-DB join
// Detect environment: production if not localhost
$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                 strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

$businessDbName = '';
if ($isProduction) {
    if ($businessId == 1) {
        $businessDbName = 'adfb2574_narayana';
    } else {
        $businessDbName = 'adfb2574_benscafe';
    }
} else {
    if ($businessId == 1) {
        $businessDbName = 'adf_narayana_hotel';
    } else {
        $businessDbName = 'adf_benscafe';
    }
}

// Get ALL transactions for this account in selected period
// Query from MASTER DB - NO cross-DB join to avoid permission issues in production
$stmt = $masterDb->prepare(
    "SELECT * FROM cash_account_transactions
     WHERE cash_account_id = ?
     AND transaction_date >= ? AND transaction_date <= ?
     ORDER BY transaction_date DESC, id DESC"
);
$stmt->execute([$ownerCapitalAccount['id'], $currentMonth, $nextMonth]);
$allTransactions = $stmt->fetchAll();

// Get descriptions from cash_book using business DB connection (separate query)
$transactionIds = [];
foreach ($allTransactions as $txn) {
    if (!empty($txn['transaction_id'])) {
        $transactionIds[] = $txn['transaction_id'];
    }
}
$cashBookDescs = [];
if (!empty($transactionIds)) {
    $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
    $descStmt = $db->getConnection()->prepare("SELECT id, description FROM cash_book WHERE id IN ($placeholders)");
    $descStmt->execute($transactionIds);
    $cashBookDescs = $descStmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Merge descriptions into transactions
foreach ($allTransactions as &$txn) {
    $txnId = $txn['transaction_id'] ?? null;
    $txn['cash_book_desc'] = ($txnId && isset($cashBookDescs[$txnId])) ? $cashBookDescs[$txnId] : null;
}
unset($txn);

// Calculate totals
$totalCapitalInjected = 0;  // Total setoran dari owner (income/capital_injection)
$totalCapitalUsed = 0;      // Total digunakan untuk operasional (expense)

foreach ($allTransactions as $txn) {
    // Income or capital_injection = Uang masuk ke Kas Modal Owner
    if (in_array($txn['transaction_type'], ['income', 'capital_injection'])) {
        $totalCapitalInjected += $txn['amount'];
    }
    // Expense = Uang keluar dari Kas Modal Owner
    if ($txn['transaction_type'] == 'expense') {
        $totalCapitalUsed += $txn['amount'];
    }
}

$currentBalance = $ownerCapitalAccount['current_balance'];
$remainingCapital = $currentBalance;

// =====================================================
// PETTY CASH SECTION - Uang Cash dari Hotel/Tamu
// =====================================================

// Get Petty Cash account from MASTER DB
$stmt = $masterDb->prepare(
    "SELECT * FROM cash_accounts WHERE business_id = ? AND account_type = 'cash'"
);
$stmt->execute([$businessId]);
$pettyCashAccount = $stmt->fetch();

$pettyCashTransactions = [];
$totalPettyCashReceived = 0;  // Total uang masuk dari tamu
$totalPettyCashUsed = 0;      // Total digunakan untuk operasional
$pettyCashBalance = 0;

if ($pettyCashAccount) {
    // Get ALL Petty Cash transactions in selected period from cash_book (only CASH payment_method)
    $pettyCashStmt = $db->getConnection()->prepare(
        "SELECT cb.id, cb.transaction_date, cb.description, cb.transaction_type, cb.amount, cb.payment_method
         FROM cash_book cb 
         WHERE cb.payment_method = 'cash'
         AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = ?
         ORDER BY cb.transaction_date DESC, cb.id DESC"
    );
    $pettyCashStmt->execute([$selectedPeriod]);
    $pettyCashTransactions = $pettyCashStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals
    foreach ($pettyCashTransactions as $txn) {
        if ($txn['transaction_type'] === 'income') {
            $totalPettyCashReceived += $txn['amount'];
        } else {
            $totalPettyCashUsed += $txn['amount'];
        }
    }
    
    $pettyCashBalance = $pettyCashAccount['current_balance'];
}

// =====================================================
// START CASH DAILY - Saldo awal hari ini
// =====================================================
$today = date('Y-m-d');

// Modal Owner: sum all transactions BEFORE today
$startCashOwner = 0;
$todayOwnerIncome = 0;
$todayOwnerExpense = 0;
if ($ownerCapitalAccount) {
    // All transactions before today
    $stmt = $masterDb->prepare(
        "SELECT 
            COALESCE(SUM(CASE WHEN transaction_type IN ('income','capital_injection') THEN amount ELSE 0 END), 0) as total_in,
            COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as total_out
         FROM cash_account_transactions 
         WHERE cash_account_id = ? AND transaction_date < ?"
    );
    $stmt->execute([$ownerCapitalAccount['id'], $today]);
    $row = $stmt->fetch();
    $startCashOwner = ($row['total_in'] ?? 0) - ($row['total_out'] ?? 0);
    
    // Today's transactions
    $stmt = $masterDb->prepare(
        "SELECT 
            COALESCE(SUM(CASE WHEN transaction_type IN ('income','capital_injection') THEN amount ELSE 0 END), 0) as today_in,
            COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as today_out
         FROM cash_account_transactions 
         WHERE cash_account_id = ? AND transaction_date = ?"
    );
    $stmt->execute([$ownerCapitalAccount['id'], $today]);
    $row = $stmt->fetch();
    $todayOwnerIncome = $row['today_in'] ?? 0;
    $todayOwnerExpense = $row['today_out'] ?? 0;
}

// Petty Cash: sum all cash_book CASH transactions BEFORE today
$startCashPetty = 0;
$todayPettyIncome = 0;
$todayPettyExpense = 0;
$pettyCashStmtStart = $db->getConnection()->prepare(
    "SELECT 
        COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as total_in,
        COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as total_out
     FROM cash_book WHERE payment_method = 'cash' AND transaction_date < ?"
);
$pettyCashStmtStart->execute([$today]);
$row = $pettyCashStmtStart->fetch();
$startCashPetty = ($row['total_in'] ?? 0) - ($row['total_out'] ?? 0);

// Today's petty cash
$pettyCashStmtToday = $db->getConnection()->prepare(
    "SELECT 
        COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as today_in,
        COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as today_out
     FROM cash_book WHERE payment_method = 'cash' AND transaction_date = ?"
);
$pettyCashStmtToday->execute([$today]);
$row = $pettyCashStmtToday->fetch();
$todayPettyIncome = $row['today_in'] ?? 0;
$todayPettyExpense = $row['today_out'] ?? 0;

$totalStartCash = $startCashOwner + $startCashPetty;
$totalTodayIncome = $todayOwnerIncome + $todayPettyIncome;
$totalTodayExpense = $todayOwnerExpense + $todayPettyExpense;
$totalCurrentCash = $totalStartCash + $totalTodayIncome - $totalTodayExpense;

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor Daily Expenses - Narayana</title>
    <!-- Note: Tidak pakai style.css global karena halaman ini punya style sendiri untuk light theme -->
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            padding: 1.5rem;
            color: #1e293b; /* Default text color - dark */
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .page-header {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.35rem;
        }
        
        .page-subtitle {
            color: #64748b;
            font-size: 0.85rem;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .card {
            background: white;
            border-radius: 10px;
            padding: 1.25rem;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.07);
        }
        
        .stat-card {
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 80px;
            height: 80px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.1), transparent);
            border-radius: 50%;
        }
        
        .stat-label {
            color: #64748b;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        
        .stat-value {
            font-size: 1.6rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.4rem;
        }
        
        .stat-change {
            font-size: 0.72rem;
            padding: 0.3rem 0.6rem;
            border-radius: 6px;
            display: inline-block;
        }
        
        .stat-change.positive {
            background: #d1fae5;
            color: #047857;
        }
        
        .stat-change.negative {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .stat-change.neutral {
            background: #dbeafe;
            color: #1e40af;
        }
        
        /* Color variations */
        .card-inject {
            border-left: 4px solid #10b981;
        }
        
        .card-inject .stat-label {
            color: #10b981;
        }
        
        .card-use {
            border-left: 4px solid #ef4444;
        }
        
        .card-use .stat-label {
            color: #ef4444;
        }
        
        .card-balance {
            border-left: 4px solid #3b82f6;
        }
        
        .card-balance .stat-label {
            color: #3b82f6;
        }
        
        /* Full width card */
        .card-full {
            grid-column: 1 / -1;
        }
        
        .section-title {
            font-size: 1rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .transaction-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .transaction-table th {
            background: #f1f5f9;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            color: #475569;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .transaction-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .transaction-table tr:hover {
            background: #f8fafc;
        }
        
        .badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-injection {
            background: #d1fae5;
            color: #047857;
        }
        
        .badge-expense {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .badge-transfer {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #94a3b8;
        }
        
        .empty-state i {
            width: 48px;
            height: 48px;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin-top: 1rem;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: #3b82f6;
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        /* ============================================
           MOBILE RESPONSIVE - CLEAN, COMPACT, ELEGANT
           ============================================ */
        
        @media (max-width: 768px) {
            body {
                padding: 0.5rem;
                background: #f8fafc;
            }
            
            .container {
                max-width: 100%;
                padding: 0;
            }
            
            /* Header - Mobile Optimized */
            .page-header {
                flex-direction: column;
                gap: 0.75rem;
                padding: 0.9rem;
                margin-bottom: 0.9rem;
            }
            
            .page-title {
                font-size: 1.05rem;
                line-height: 1.3;
            }
            
            .page-subtitle {
                font-size: 0.75rem;
            }
            
            .page-header > div:last-child {
                width: 100%;
                flex-direction: column;
                gap: 0.6rem;
            }
            
            #periodSelector,
            .page-header button {
                width: 100%;
                padding: 0.7rem 0.85rem;
                font-size: 0.82rem;
            }
            
            /* Grid - Stack on Mobile */
            .grid-2 {
                grid-template-columns: 1fr;
                gap: 0.8rem;
                margin-bottom: 1rem;
            }
            
            /* Cards - Ultra Compact Mobile */
            .card {
                padding: 0.9rem;
                border-radius: 8px;
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            }
            
            .stat-card::before {
                width: 60px;
                height: 60px;
            }
            
            .stat-label {
                font-size: 0.68rem;
                margin-bottom: 0.4rem;
            }
            
            .stat-label i {
                width: 12px !important;
                height: 12px !important;
            }
            
            .stat-value {
                font-size: 1.2rem;
                margin-bottom: 0.4rem;
            }
            
            .stat-change {
                font-size: 0.65rem;
                padding: 0.3rem 0.5rem;
            }
            
            /* Section Headings */
            h2 {
                font-size: 0.95rem !important;
                margin-bottom: 0.8rem !important;
                gap: 0.4rem !important;
            }
            
            /* Inline Stats Summary */
            .card > div[style*="display: grid"] {
                gap: 0.6rem !important;
                font-size: 0.8rem !important;
            }
            
            .card > div[style*="display: grid"] small {
                font-size: 0.7rem !important;
            }
            
            .card > div[style*="display: grid"] div[style*="font-size: 1.25rem"] {
                font-size: 1rem !important;
            }
            
            /* Tables - Horizontal Scroll */
            .transaction-table-wrapper {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin: 0 -0.9rem;
                padding: 0 0.9rem;
            }
            
            .transaction-table {
                min-width: 550px;
                font-size: 0.75rem;
            }
            
            .transaction-table th,
            .transaction-table td {
                padding: 0.55rem 0.4rem;
            }
            
            .transaction-table th {
                font-size: 0.65rem;
            }
            
            .transaction-table .transaction-date {
                font-size: 0.65rem;
            }
            
            .transaction-table .transaction-description {
                font-size: 0.75rem;
            }
            
            .transaction-table .transaction-amount {
                font-size: 0.78rem;
                white-space: nowrap;
            }
            
            /* Badges in Table */
            .badge {
                font-size: 0.6rem !important;
                padding: 0.2rem 0.4rem !important;
            }
            
            /* Charts - Mobile Height */
            .chart-container {
                height: 180px;
                margin-top: 0.6rem;
            }
            
            /* Back Link */
            .back-link {
                font-size: 0.85rem;
                margin-bottom: 1rem;
            }
            
            .back-link i {
                width: 14px !important;
                height: 14px !important;
            }
            
            /* Empty State */
            .empty-state {
                padding: 2rem 1rem;
                font-size: 0.85rem;
            }
            
            .empty-state i {
                width: 40px !important;
                height: 40px !important;
            }
        }
        
        /* Extra Small Devices (< 480px) */
        @media (max-width: 480px) {
            body {
                padding: 0.4rem;
            }
            
            .page-header {
                padding: 0.8rem;
            }
            
            .page-title {
                font-size: 0.95rem;
            }
            
            .page-subtitle {
                font-size: 0.7rem;
            }
            
            .card {
                padding: 0.75rem;
                border-radius: 6px;
            }
            
            .stat-card::before {
                width: 50px;
                height: 50px;
                opacity: 0.5;
            }
            
            .stat-label {
                font-size: 0.65rem;
            }
            
            .stat-value {
                font-size: 1.05rem;
            }
            
            .stat-change {
                font-size: 0.6rem;
                padding: 0.25rem 0.4rem;
            }
            
            h2 {
                font-size: 0.85rem !important;
                margin-bottom: 0.7rem !important;
            }
            
            .transaction-table {
                min-width: 500px;
                font-size: 0.7rem;
            }
            
            .transaction-table th,
            .transaction-table td {
                padding: 0.5rem 0.35rem;
            }
            
            .transaction-table th {
                font-size: 0.6rem;
            }
            
            .chart-container {
                height: 160px;
            }
            
            .badge {
                font-size: 0.55rem !important;
                padding: 0.15rem 0.35rem !important;
            }
            
            .back-link {
                font-size: 0.8rem;
            }
            
            .back-link i {
                width: 12px !important;
                height: 12px !important;
            }
        }
            }
            
            .transaction-table {
                min-width: 550px;
                font-size: 0.78rem;
            }
            
            .transaction-table th,
            .transaction-table td {
                padding: 0.6rem 0.4rem;
            }
        }
        
        /* Landscape Mobile Optimization */
        @media (max-width: 900px) and (orientation: landscape) {
            .grid-2 {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
            
            .chart-container {
                height: 200px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="<?php echo BASE_URL; ?>/index.php" class="back-link">
            <i data-feather="arrow-left" style="width: 16px; height: 16px;"></i>
            Kembali ke Dashboard
        </a>
        
        <div class="page-header">
            <div>
                <h1 class="page-title">💰 Monitor Daily Expenses</h1>
                <p class="page-subtitle">Tracking Modal Owner & Petty Cash bulan <?php echo date('F Y', strtotime($currentMonth)); ?></p>
            </div>
            <div style="display: flex; gap: 1rem; align-items: center;">
                <!-- Period Selector -->
                <select id="periodSelector" onchange="changePeriod(this.value)" style="padding: 0.75rem 1.5rem; background: white; border: 2px solid #e2e8f0; border-radius: 8px; cursor: pointer; font-weight: 600; color: #334155;">
                    <?php
                    // Generate last 12 months
                    for ($i = 0; $i < 12; $i++) {
                        $month = date('Y-m', strtotime("-$i months"));
                        $monthName = date('F Y', strtotime($month . '-01'));
                        $selected = ($month === $selectedPeriod) ? 'selected' : '';
                        echo "<option value='$month' $selected>$monthName</option>";
                    }
                    ?>
                </select>
                <button onclick="refreshData()" style="padding: 0.75rem 1.5rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                    <i data-feather="refresh-cw" style="width: 16px; height: 16px;"></i>
                    Refresh
                </button>
            </div>
        </div>
        
        <!-- ======================================== -->
        <!-- START CASH DAILY - Saldo Awal Hari Ini -->
        <!-- ======================================== -->
        <div style="background: linear-gradient(135deg, #1e293b 0%, #334155 100%); border-radius: 12px; padding: 1.25rem; margin-bottom: 1.5rem; box-shadow: 0 4px 15px rgba(0,0,0,0.15);">
            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem;">
                <span style="font-size: 1.2rem;">☀️</span>
                <div style="font-size: 0.85rem; font-weight: 700; color: #fbbf24; text-transform: uppercase; letter-spacing: 0.5px;">Start Cash Hari Ini — <?php echo date('d M Y'); ?></div>
            </div>
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem;">
                <!-- Saldo Awal -->
                <div style="background: rgba(255,255,255,0.08); padding: 0.75rem; border-radius: 8px; border-left: 3px solid #94a3b8;">
                    <div style="font-size: 0.65rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Saldo Awal</div>
                    <div style="font-size: 1.15rem; font-weight: 800; color: #e2e8f0;">Rp <?php echo number_format($totalStartCash, 0, ',', '.'); ?></div>
                    <div style="font-size: 0.55rem; color: #64748b; margin-top: 0.15rem;">Sisa saldo kemarin</div>
                </div>
                <!-- Masuk Hari Ini -->
                <div style="background: rgba(16,185,129,0.1); padding: 0.75rem; border-radius: 8px; border-left: 3px solid #10b981;">
                    <div style="font-size: 0.65rem; color: #10b981; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">+ Masuk Hari Ini</div>
                    <div style="font-size: 1.15rem; font-weight: 800; color: #10b981;">Rp <?php echo number_format($totalTodayIncome, 0, ',', '.'); ?></div>
                    <div style="font-size: 0.55rem; color: #64748b; margin-top: 0.15rem;">Owner + Petty Cash</div>
                </div>
                <!-- Keluar Hari Ini -->
                <div style="background: rgba(239,68,68,0.1); padding: 0.75rem; border-radius: 8px; border-left: 3px solid #ef4444;">
                    <div style="font-size: 0.65rem; color: #ef4444; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">- Keluar Hari Ini</div>
                    <div style="font-size: 1.15rem; font-weight: 800; color: #ef4444;">Rp <?php echo number_format($totalTodayExpense, 0, ',', '.'); ?></div>
                    <div style="font-size: 0.55rem; color: #64748b; margin-top: 0.15rem;">Pengeluaran hari ini</div>
                </div>
                <!-- Kas Sekarang -->
                <div style="background: rgba(59,130,246,0.12); padding: 0.75rem; border-radius: 8px; border-left: 3px solid #3b82f6;">
                    <div style="font-size: 0.65rem; color: #3b82f6; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">= Kas Sekarang</div>
                    <div style="font-size: 1.15rem; font-weight: 800; color: <?php echo $totalCurrentCash >= 0 ? '#3b82f6' : '#ef4444'; ?>;">Rp <?php echo number_format($totalCurrentCash, 0, ',', '.'); ?></div>
                    <div style="font-size: 0.55rem; color: #64748b; margin-top: 0.15rem;">Real-time saat ini</div>
                </div>
            </div>
        </div>
        
        <!-- Key Statistics - MODAL OWNER -->
        <h2 style="margin-bottom: 1rem; font-size: 1.15rem; font-weight: 700; color: #10b981; display: flex; align-items: center; gap: 0.5rem;">
            💰 Modal Owner - Setoran Dari Owner
        </h2>
        
        <div class="grid-2">
            <!-- Total Uang Masuk dari Owner (NEW) -->
            <div class="card stat-card" style="border-left: 4px solid #10b981; background: linear-gradient(135deg, rgba(16,185,129,0.05) 0%, rgba(16,185,129,0.02) 100%);">
                <div class="stat-label" style="color: #10b981;">
                    <i data-feather="trending-up" style="width: 16px; height: 16px;"></i>
                    Total Uang Masuk dari Owner
                </div>
                <div class="stat-value" style="color: #10b981;">Rp <?php echo number_format($totalCapitalInjected, 0, ',', '.'); ?></div>
                <div class="stat-change positive">
                    💰 Setoran modal periode ini
                </div>
            </div>
            
            <!-- Capital Used -->
            <div class="card stat-card card-use">
                <div class="stat-label">
                    <i data-feather="arrow-up-circle" style="width: 16px; height: 16px;"></i>
                    Operating Capital Used
                </div>
                <div class="stat-value">Rp <?php echo number_format($totalCapitalUsed, 0, ',', '.'); ?></div>
                <div class="stat-change negative">
                    📤 Capital Fund Expenses
                </div>
            </div>
            
            <!-- Current Balance -->
            <div class="card stat-card card-balance">
                <div class="stat-label">
                    <i data-feather="credit-card" style="width: 16px; height: 16px;"></i>
                    Current Capital Balance
                </div>
                <div class="stat-value">Rp <?php echo number_format($remainingCapital, 0, ',', '.'); ?></div>
                <div class="stat-change neutral">
                    💳 Real-time Current Balance
                </div>
            </div>
            
            <!-- Efficiency -->
            <div class="card stat-card" style="border-left: 4px solid #f59e0b;">
                <div class="stat-label" style="color: #f59e0b;">
                    <i data-feather="percent" style="width: 16px; height: 16px;"></i>
                    Efisiensi Modal
                </div>
                <div class="stat-value">
                    <?php 
                    if ($totalCapitalInjected > 0) {
                        $efficiency = ($totalCapitalUsed / $totalCapitalInjected) * 100;
                        echo number_format($efficiency, 1);
                    } else {
                        echo '0';
                    }
                    ?>%
                </div>
                <div class="stat-change" style="background: #fef3c7; color: #b45309;">
                    📈 Rasio penggunaan
                </div>
            </div>
        </div>
        
        <!-- Transactions History -->
        <div class="card card-full">
            <div class="section-title">
                <i data-feather="history" style="width: 20px; height: 20px; color: #3b82f6;"></i>
                Riwayat Transaksi Kas Modal Owner - <?php echo date('F Y', strtotime($currentMonth)); ?>
            </div>
            
            <?php if (!empty($allTransactions)): ?>
                <div style="margin-bottom: 0.75rem; padding: 0.75rem; background: #f8fafc; border-radius: 8px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <small style="color: #64748b; font-weight: 600; font-size: 0.7rem;">Total Transaksi</small>
                        <div style="font-size: 1rem; font-weight: 700; color: #1e293b;"><?php echo count($allTransactions); ?> transaksi</div>
                    </div>
                    <div>
                        <small style="color: #10b981; font-weight: 600; font-size: 0.7rem;">Uang Masuk</small>
                        <div style="font-size: 1rem; font-weight: 700; color: #10b981;">Rp <?php echo number_format($totalCapitalInjected, 0, ',', '.'); ?></div>
                    </div>
                    <div>
                        <small style="color: #ef4444; font-weight: 600; font-size: 0.7rem;">Uang Keluar</small>
                        <div style="font-size: 1rem; font-weight: 700; color: #ef4444;">Rp <?php echo number_format($totalCapitalUsed, 0, ',', '.'); ?></div>
                    </div>
                    <div>
                        <small style="color: #3b82f6; font-weight: 600; font-size: 0.7rem;">Selisih</small>
                        <div style="font-size: 1rem; font-weight: 700; color: <?php echo ($totalCapitalInjected - $totalCapitalUsed) >= 0 ? '#10b981' : '#ef4444'; ?>;">
                            Rp <?php echo number_format($totalCapitalInjected - $totalCapitalUsed, 0, ',', '.'); ?>
                        </div>
                    </div>
                </div>
                
                <div class="transaction-table-wrapper">
                <table class="transaction-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Deskripsi</th>
                            <th>Referensi</th>
                            <th>Tipe</th>
                            <th>Uang Masuk</th>
                            <th>Uang Keluar</th>
                            <th>Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Calculate running balance from oldest to newest first
                        $balances = [];
                        $runningBalance = 0;
                        
                        // Reverse to calculate from oldest
                        $reversedTxns = array_reverse($allTransactions);
                        foreach ($reversedTxns as $txn) {
                            // Income/capital_injection adds, expense subtracts
                            if (in_array($txn['transaction_type'], ['income', 'capital_injection'])) {
                                $runningBalance += $txn['amount'];
                            } else if ($txn['transaction_type'] == 'expense') {
                                $runningBalance -= $txn['amount'];
                            }
                            $balances[$txn['id']] = $runningBalance;
                        }
                        
                        // Now display in original order (newest first)
                        foreach ($allTransactions as $txn): 
                            // Get description
                            $description = $txn['description'] ?: ($txn['cash_book_desc'] ?: '-');
                        ?>
                            <tr>
                                <td style="white-space: nowrap;"><?php echo date('d M Y', strtotime($txn['transaction_date'])); ?></td>
                                <td><?php echo htmlspecialchars($description); ?></td>
                                <td style="font-size: 0.813rem; color: #64748b;">
                                    <?php 
                                    if (!empty($txn['reference_number'])) {
                                        echo '<span style="background: #e0e7ff; color: #4338ca; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem;">Ref: ' . htmlspecialchars($txn['reference_number']) . '</span>';
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $badgeClass = 'badge';
                                    $badgeText = ucfirst($txn['transaction_type']);
                                    
                                    if (in_array($txn['transaction_type'], ['capital_injection', 'income'])) {
                                        $badgeClass = 'badge badge-injection';
                                        $badgeText = ($txn['transaction_type'] == 'capital_injection') ? 'Capital Injection' : 'Income';
                                    } elseif ($txn['transaction_type'] == 'expense') {
                                        $badgeClass = 'badge badge-expense';
                                        $badgeText = 'Expense';
                                    } elseif ($txn['transaction_type'] == 'transfer') {
                                        $badgeClass = 'badge badge-transfer';
                                        $badgeText = 'Transfer';
                                    }
                                    
                                    echo '<span class="' . $badgeClass . '">' . $badgeText . '</span>';
                                    ?>
                                </td>
                                <td style="text-align: right; font-weight: 600;">
                                    <?php
                                    if (in_array($txn['transaction_type'], ['income', 'capital_injection'])) {
                                        echo '<span style="color: #10b981;">Rp ' . number_format($txn['amount'], 0, ',', '.') . '</span>';
                                    } else {
                                        echo '<span style="color: #cbd5e1;">-</span>';
                                    }
                                    ?>
                                </td>
                                <td style="text-align: right; font-weight: 600;">
                                    <?php
                                    if ($txn['transaction_type'] == 'expense') {
                                        echo '<span style="color: #ef4444;">Rp ' . number_format($txn['amount'], 0, ',', '.') . '</span>';
                                    } else {
                                        echo '<span style="color: #cbd5e1;">-</span>';
                                    }
                                    ?>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: #1e293b;">
                                    Rp <?php echo number_format($balances[$txn['id']], 0, ',', '.'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i data-feather="inbox"></i>
                    <p>Belum ada transaksi modal pada periode ini</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- ======================================================== -->
        <!-- PETTY CASH SECTION - Uang Cash dari Hotel/Tamu -->
        <!-- ======================================================== -->
        
        <h2 style="margin-top: 1.5rem; margin-bottom: 1rem; font-size: 1.15rem; font-weight: 700; color: #f59e0b; display: flex; align-items: center; gap: 0.5rem;">
            💵 Petty Cash - Uang Cash Dari Tamu
        </h2>
        
        <!-- Petty Cash Statistics -->
        <div class="grid-2">
            <!-- Total Uang Masuk Cash dari Tamu -->
            <div class="card stat-card" style="border-left: 4px solid #f59e0b; background: linear-gradient(135deg, rgba(245,158,11,0.05) 0%, rgba(245,158,11,0.02) 100%);">
                <div class="stat-label" style="color: #f59e0b;">
                    <i data-feather="trending-up" style="width: 16px; height: 16px;"></i>
                    Total Uang Masuk Cash
                </div>
                <div class="stat-value" style="color: #f59e0b;">Rp <?php echo number_format($totalPettyCashReceived, 0, ',', '.'); ?></div>
                <div class="stat-change" style="background: #fef3c7; color: #b45309;">
                    💵 Pembayaran CASH dari tamu
                </div>
            </div>
            
            <!-- Petty Cash Used -->
            <div class="card stat-card card-use">
                <div class="stat-label">
                    <i data-feather="arrow-up-circle" style="width: 16px; height: 16px;"></i>
                    Petty Cash Used
                </div>
                <div class="stat-value">Rp <?php echo number_format($totalPettyCashUsed, 0, ',', '.'); ?></div>
                <div class="stat-change negative">
                    📤 Cash Expenses
                </div>
            </div>
            
            <!-- Petty Cash Current Balance -->
            <div class="card stat-card card-balance">
                <div class="stat-label">
                    <i data-feather="credit-card" style="width: 16px; height: 16px;"></i>
                    Current Petty Cash Balance
                </div>
                <div class="stat-value">Rp <?php echo number_format($pettyCashBalance, 0, ',', '.'); ?></div>
                <div class="stat-change neutral">
                    💳 Saldo aktual real-time
                </div>
            </div>
            
            <!-- Net Cash Flow -->
            <div class="card stat-card" style="border-left: 4px solid #6366f1;">
                <div class="stat-label" style="color: #6366f1;">
                    <i data-feather="activity" style="width: 16px; height: 16px;"></i>
                    Net Cash Flow Bulan Ini
                </div>
                <div class="stat-value" style="color: <?php echo ($totalPettyCashReceived - $totalPettyCashUsed) >= 0 ? '#10b981' : '#ef4444'; ?>;">
                    Rp <?php echo number_format($totalPettyCashReceived - $totalPettyCashUsed, 0, ',', '.'); ?>
                </div>
                <div class="stat-change" style="background: #e0e7ff; color: #4338ca;">
                    📊 Selisih uang masuk - keluar
                </div>
            </div>
        </div>
        
        <!-- Petty Cash Transactions History -->
        <div class="card card-full">
            <div class="section-title">
                <i data-feather="list" style="width: 20px; height: 20px; color: #f59e0b;"></i>
                Riwayat Transaksi Petty Cash - <?php echo date('F Y', strtotime($currentMonth)); ?>
            </div>
            
            <?php if (!empty($pettyCashTransactions)): ?>
                <div style="margin-bottom: 0.75rem; padding: 0.75rem; background: #fffbeb; border-radius: 8px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <small style="color: #64748b; font-weight: 600; font-size: 0.7rem;">Total Transaksi</small>
                        <div style="font-size: 1rem; font-weight: 700; color: #1e293b;"><?php echo count($pettyCashTransactions); ?> transaksi</div>
                    </div>
                    <div>
                        <small style="color: #10b981; font-weight: 600; font-size: 0.7rem;">Uang Masuk</small>
                        <div style="font-size: 1rem; font-weight: 700; color: #10b981;">Rp <?php echo number_format($totalPettyCashReceived, 0, ',', '.'); ?></div>
                    </div>
                    <div>
                        <small style="color: #ef4444; font-weight: 600; font-size: 0.7rem;">Uang Keluar</small>
                        <div style="font-size: 1rem; font-weight: 700; color: #ef4444;">Rp <?php echo number_format($totalPettyCashUsed, 0, ',', '.'); ?></div>
                    </div>
                    <div>
                        <small style="color: #6366f1; font-weight: 600; font-size: 0.7rem;">Balance</small>
                        <div style="font-size: 1rem; font-weight: 700; color: <?php echo ($totalPettyCashReceived - $totalPettyCashUsed) >= 0 ? '#10b981' : '#ef4444'; ?>;">
                            Rp <?php echo number_format($totalPettyCashReceived - $totalPettyCashUsed, 0, ',', '.'); ?>
                        </div>
                    </div>
                </div>
                
                <div class="transaction-table-wrapper">
                <table class="transaction-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Deskripsi</th>
                            <th>Tipe</th>
                            <th>Uang Masuk</th>
                            <th>Uang Keluar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pettyCashTransactions as $txn): ?>
                            <tr>
                                <td style="white-space: nowrap;"><?php echo date('d M Y', strtotime($txn['transaction_date'])); ?></td>
                                <td><?php echo htmlspecialchars($txn['description'] ?: '-'); ?></td>
                                <td>
                                    <?php if ($txn['transaction_type'] === 'income'): ?>
                                        <span class="badge badge-injection">Pemasukan</span>
                                    <?php else: ?>
                                        <span class="badge badge-expense">Pengeluaran</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; font-weight: 600;">
                                    <?php if ($txn['transaction_type'] === 'income'): ?>
                                        <span style="color: #10b981;">Rp <?php echo number_format($txn['amount'], 0, ',', '.'); ?></span>
                                    <?php else: ?>
                                        <span style="color: #cbd5e1;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; font-weight: 600;">
                                    <?php if ($txn['transaction_type'] === 'expense'): ?>
                                        <span style="color: #ef4444;">Rp <?php echo number_format($txn['amount'], 0, ',', '.'); ?></span>
                                    <?php else: ?>
                                        <span style="color: #cbd5e1;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i data-feather="inbox"></i>
                    <p>Belum ada transaksi petty cash pada periode ini</p>
                </div>
            <?php endif; ?>
        </div>
        </div>
        
        <!-- Chart -->
        <div class="card card-full" style="margin-top: 2rem;">
            <div class="section-title">
                <i data-feather="bar-chart-2" style="width: 20px; height: 20px; color: #8b5cf6;"></i>
                Trend Modal Bulanan
            </div>
            <div class="chart-container">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>
    
    <script>
        feather.replace();
        
        function refreshData() {
            location.reload();
        }
        
        function changePeriod(period) {
            window.location.href = '?period=' + period;
        }
        
        // Initialize chart
        const ctx = document.getElementById('trendChart').getContext('2d');
        const trendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                datasets: [
                    {
                        label: 'Modal Diterima',
                        data: [<?php echo $totalCapitalInjected; ?>, 0, 0, 0],
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        tension: 0.4,
                        borderWidth: 2,
                        fill: true,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    },
                    {
                        label: 'Modal Digunakan',
                        data: [0, <?php echo $totalCapitalUsed / 4; ?>, <?php echo $totalCapitalUsed / 4; ?>, <?php echo $totalCapitalUsed / 2; ?>],
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        tension: 0.4,
                        borderWidth: 2,
                        fill: true,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                            padding: 15,
                            usePointStyle: true
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'Rp ' + (value / 1000000).toFixed(1) + 'M';
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
