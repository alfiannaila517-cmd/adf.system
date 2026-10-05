<?php

/**
 * MULTI-BUSINESS MANAGEMENT SYSTEM
 * Dashboard - Main Page
 */

ob_start();

define('APP_ACCESS', true);
require_once 'config/config.php';

// Check if database exists, redirect to installer if not
try {
    $testConn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
} catch (PDOException $e) {
    // Database not exists, redirect to setup page
    header('Location: setup-required.html');
    exit;
}

require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/trial_check.php';

$auth = new Auth();
$auth->requireLogin();

// Users without Dashboard access should land on the first module they CAN
// see instead of this page (e.g. a PO-only staff account).
if (!$auth->hasPermission('dashboard')) {
    $fallbackMenus = [
        'production'      => 'modules/production/index.php',
        'cashbook'        => 'modules/cashbook/index.php',
        'divisions'       => 'modules/divisions/index.php',
        'frontdesk'       => 'modules/frontdesk/index.php',
        'sales_invoice'   => 'modules/sales/index.php',
        'bills'           => 'modules/bills/index.php',
        'cafe_invoice'    => 'modules/cafe-invoice/index.php',
        'payroll'         => 'modules/payroll/index.php',
        'procurement_po'  => 'modules/procurement/purchase-orders.php',
        'procurement_stock' => 'modules/procurement/business-stock-incoming.php',
        'gudang_view'     => 'modules/procurement/gudang-nasita.php',
        'reports'         => 'modules/reports/index.php',
        'project'         => 'modules/project/index.php',
        'finance'         => 'modules/finance/index.php',
        'database'        => 'modules/database/index.php',
        'settings'        => 'modules/settings/index.php',
    ];
    foreach ($fallbackMenus as $menuCode => $url) {
        if ($auth->hasPermission($menuCode) && file_exists(__DIR__ . '/' . $url)) {
            header('Location: ' . BASE_URL . '/' . $url);
            exit;
        }
    }
}

$db = Database::getInstance();

// Load business configuration (already loaded in config.php, use safe fallback)
$businessConfigFile = __DIR__ . '/config/businesses/' . ACTIVE_BUSINESS_ID . '.php';
$businessConfig = file_exists($businessConfigFile) ? require $businessConfigFile : $BUSINESS_CONFIG;

// Check trial status
$currentUser = $auth->getCurrentUser();
$trialStatus = checkTrialStatus($currentUser);

// Get WhatsApp number from settings
$waSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'developer_whatsapp'");
$developerWA = $waSetting['setting_value'] ?? null;

// Get company name from settings, fallback to BUSINESS_NAME
$companyNameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
$displayCompanyName = ($companyNameSetting && $companyNameSetting['setting_value'])
    ? $companyNameSetting['setting_value']
    : BUSINESS_NAME;

$pageTitle = BUSINESS_ICON . ' ' . $displayCompanyName;
$pageSubtitle = 'Dashboard & Monitoring Real-time';

// ============================================
// BUSINESS FEATURE DETECTION (CONFIG-BASED)
// Uses enabled_modules and business_type from config
// ============================================
$hasProjectModule = in_array('cqc-projects', $businessConfig['enabled_modules'] ?? []);
$isContractor = ($businessConfig['business_type'] ?? '') === 'contractor';
$isHotel = ($businessConfig['business_type'] ?? '') === 'hotel';
$isCQC = $hasProjectModule; // Legacy compatibility

// Dynamic color palette based on business config
// Primary glow/tint color (replaces purple rgba(99,102,241,...))
$cPrimaryRgb = $isContractor ? '240, 180, 41' : '99, 102, 241';
// Secondary tint (replaces secondary purple rgba(139,92,246,...))
$cSecondaryRgb = $isContractor ? '13, 31, 60' : '139, 92, 246';
// Action button color (replaces blue #0071e3)
$cAccent = $isContractor ? '#0d1f3c' : '#0071e3';
$cAccentDark = $isContractor ? '#122a4e' : '#0055b8';
// Action button rgb (replaces blue rgba(0,113,227,...))
$cAccentRgb = $isContractor ? '13, 31, 60' : '0, 113, 227';
// Kas tersedia highlight color
$cKasColor = $isContractor ? '#f0b429' : '#0071e3';

// Get date range (today, this month, this year)
$today = date('Y-m-d');
$thisMonth = date('Y-m');
$thisYear = date('Y');

// Get selected month from GET parameter for filtering all dashboard data
$selected_dashboard_month = isset($_GET['dashboard_month']) ? $_GET['dashboard_month'] : date('Y-m');
$selected_dashboard_year = date('Y', strtotime($selected_dashboard_month . '-01'));
$monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

// Initialize CQC account IDs at global scope (will be populated if CQC business)
$pettyCashAccountId = 0;
$bankAccountId = 0;

// ============================================
// EXCLUDE OWNER CAPITAL FROM OPERATIONAL STATS
// ============================================
// First check if cash_account_id column exists in cash_book (may not exist on hosting)
$hasCashAccountIdCol = false;
try {
    $colCheck = $db->getConnection()->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
    $hasCashAccountIdCol = $colCheck && $colCheck->rowCount() > 0;
} catch (\Throwable $e) {
    $hasCashAccountIdCol = false;
}

// Also check if transaction_time column exists
$hasTransactionTimeCol = true;
try {
    $db->getConnection()->query("SELECT transaction_time FROM cash_book LIMIT 1");
} catch (\Throwable $e) {
    $hasTransactionTimeCol = false;
}

// Get owner capital account IDs to exclude from operational income
$ownerCapitalAccountIds = [];
try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $businessId = getMasterBusinessId();

    // Get owner_capital account IDs (for legacy support)
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $ownerCapitalAccountIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get cash (Kas Operasional) account IDs
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash'");
    $stmt->execute([$businessId]);
    $kasOperasionalAccountIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Error fetching owner capital accounts: " . $e->getMessage());
}

// Build exclusion clause - exclude ONLY explicit owner fund
// Cash payment income to Petty Cash IS real income (from customers)
// Only source_type = 'owner_fund' should be excluded from income stats
$excludeOwnerCapital = '';
$hasSourceTypeCol = false;
try {
    $colCheck = $db->getConnection()->query("SHOW COLUMNS FROM cash_book LIKE 'source_type'");
    $hasSourceTypeCol = $colCheck && $colCheck->rowCount() > 0;
} catch (\Throwable $e) {
    $hasSourceTypeCol = false;
}

if ($hasSourceTypeCol) {
    // Use source_type to exclude owner fund AND project expenses (not hotel P&L)
    $excludeOwnerCapital = " AND (source_type IS NULL OR source_type NOT IN ('owner_fund','owner_project'))";
} elseif ($hasCashAccountIdCol && !empty($ownerCapitalAccountIds)) {
    // Fallback: only exclude owner_capital accounts (not petty cash)
    $excludeOwnerCapital = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
}

// Colors for divisions - Sharp Neon Digital Palette
$divisionColors = [
    '#00D4FF',
    '#FF3CAC',
    '#00F5A0',
    '#FFD93D',
    '#FF6B6B',
    '#6C5CE7',
    '#00CEC9',
    '#FD79A8',
    '#81ECEC',
    '#A29BFE',
    '#55EFC4'
];

// ============================================
// TODAY STATISTICS (Exclude Owner Capital)
// ============================================
$todayIncomeResult = $db->fetchAll(
    "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
     WHERE transaction_type = 'income' AND transaction_date = :date" . $excludeOwnerCapital,
    ['date' => $today]
);
$todayIncome = ['total' => $todayIncomeResult[0]['total'] ?? 0];

$todayExpenseResult = $db->fetchAll(
    "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
     WHERE transaction_type = 'expense' AND transaction_date = :date",
    ['date' => $today]
);
$todayExpense = ['total' => $todayExpenseResult[0]['total'] ?? 0];

// ============================================
// MONTHLY STATISTICS (Exclude Owner Capital)
// ============================================
$monthlyIncomeResult = $db->fetchAll(
    "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
     WHERE transaction_type = 'income' AND DATE_FORMAT(transaction_date, '%Y-%m') = :month" . $excludeOwnerCapital,
    ['month' => $selected_dashboard_month]
);
$monthlyIncome = ['total' => $monthlyIncomeResult[0]['total'] ?? 0];

$monthlyExpenseResult = $db->fetchAll(
    "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
     WHERE transaction_type = 'expense' AND DATE_FORMAT(transaction_date, '%Y-%m') = :month",
    ['month' => $selected_dashboard_month]
);
$monthlyExpense = ['total' => $monthlyExpenseResult[0]['total'] ?? 0];

// ============================================
// YEARLY STATISTICS (Exclude Owner Capital)
// ============================================
$yearlyIncomeResult = $db->fetchAll(
    "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
     WHERE transaction_type = 'income' AND YEAR(transaction_date) = :year" . $excludeOwnerCapital,
    ['year' => $thisYear]
);
$yearlyIncome = ['total' => $yearlyIncomeResult[0]['total'] ?? 0];

$yearlyExpenseResult = $db->fetchAll(
    "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
     WHERE transaction_type = 'expense' AND YEAR(transaction_date) = :year",
    ['year' => $thisYear]
);
$yearlyExpense = ['total' => $yearlyExpenseResult[0]['total'] ?? 0];

// ============================================
// CURRENT BALANCE (YEARLY)
// ============================================
$totalBalance = ($yearlyIncome['total'] ?? 0) - ($yearlyExpense['total'] ?? 0);

// ============================================
// ALL TIME CASH (REAL MONEY - Only Cash Payment Method)
// ============================================
$allTimeCashResult = $db->fetchOne(
    "SELECT SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END) as balance FROM cash_book WHERE payment_method = 'cash'" . $excludeOwnerCapital
);
$totalRealCash = $allTimeCashResult['balance'] ?? 0;

// ============================================
// KAS OPERASIONAL HARIAN (This Month) - From Master DB
// ============================================
$dashCashAvailable = 0;
$startKasHariIni = 0;
try {
    // Get owner capital account from master database
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $businessId = getMasterBusinessId();

    // Get ALL owner_capital account IDs
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $capitalAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get ALL cash (Petty Cash) account IDs
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash'");
    $stmt->execute([$businessId]);
    $pettyCashAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $allAccIds = array_merge($capitalAccounts, $pettyCashAccounts);

    $capitalStats = [
        'received' => 0,
        'used' => 0,
        'balance' => 0
    ];

    $pettyCashStats = [
        'received' => 0,
        'used' => 0,
        'balance' => 0
    ];

    // Keep expense logic consistent with dashboard charts AND Buku Kas (cashbook/index.php):
    // - project-related expenses (owner_project) are not part of operational expense.
    // - internal cash transfers (Setor Tunai / cash_transfer) are NOT a real business
    //   expense (money just moves between own accounts), so exclude them too —
    //   otherwise this widget's "Expense" figure won't match Buku Kas totals.
    $expenseCaseExpr = "CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END";
    if ($hasSourceTypeCol) {
        $expenseCaseExpr = "CASE WHEN transaction_type = 'expense' AND (source_type IS NULL OR source_type NOT IN ('owner_project', 'cash_transfer')) THEN amount ELSE 0 END";
    }

    // Query Modal Owner stats - only if cash_account_id column exists
    if ($hasCashAccountIdCol && !empty($capitalAccounts)) {
        $placeholders = implode(',', array_fill(0, count($capitalAccounts), '?'));

        $query = "
            SELECT 
                SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) as received,
                SUM($expenseCaseExpr) as used,
                (SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) - 
                 SUM($expenseCaseExpr)) as balance
            FROM cash_book 
            WHERE cash_account_id IN ($placeholders)
            AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
        ";

        $params = array_merge($capitalAccounts, [$selected_dashboard_month]);
        $result = $db->fetchOne($query, $params);

        $capitalStats['received'] = $result['received'] ?? 0;
        $capitalStats['used'] = $result['used'] ?? 0;
        $capitalStats['balance'] = $result['balance'] ?? 0;
    }

    // Query Petty Cash / Kas Operasional stats - based on cash_account_id, NOT payment_method
    if ($hasCashAccountIdCol && !empty($pettyCashAccounts)) {
        $placeholders = implode(',', array_fill(0, count($pettyCashAccounts), '?'));

        $query = "
            SELECT 
                SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) as received,
                SUM($expenseCaseExpr) as used,
                (SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) - 
                 SUM($expenseCaseExpr)) as balance
            FROM cash_book 
            WHERE cash_account_id IN ($placeholders)
            AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
        ";

        $params = array_merge($pettyCashAccounts, [$selected_dashboard_month]);
        $result = $db->fetchOne($query, $params);

        $pettyCashStats['received'] = $result['received'] ?? 0;
        $pettyCashStats['used'] = $result['used'] ?? 0;
        $pettyCashStats['balance'] = $result['balance'] ?? 0;
    }

    // TOTAL KAS OPERASIONAL = Petty Cash + Modal Owner (physical cash available)
    $totalOperationalCash = $pettyCashStats['balance'] + $capitalStats['balance'];

    // TOTAL PENGELUARAN OPERASIONAL = Petty Cash expense + Modal Owner expense
    $totalOperationalExpense = $pettyCashStats['used'] + $capitalStats['used'];

    // TOTAL UANG MASUK = Petty Cash received + Modal Owner received
    $totalOperationalIncome = $pettyCashStats['received'] + $capitalStats['received'];

    // ============================================
    // START KAS = Saldo akhir bulan sebelumnya
    // (untuk bulan baru, reset dari sisa bulan lalu)
    // ============================================
    $today = date('Y-m-d');
    $firstDayOfMonth = $selected_dashboard_month . '-01';
    $startKasOwner = 0;
    $startKasPetty = 0;
    $ownerTransferThisMonth = 0;

    // Modal Owner: all transactions before THIS MONTH (end of last month)
    if ($hasCashAccountIdCol && !empty($capitalAccounts)) {
        $placeholders = implode(',', array_fill(0, count($capitalAccounts), '?'));
        $qStart = "SELECT 
            COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
            COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
            FROM cash_book WHERE cash_account_id IN ($placeholders) AND transaction_date < ?";
        $pStart = array_merge($capitalAccounts, [$firstDayOfMonth]);
        $rStart = $db->fetchOne($qStart, $pStart);
        $startKasOwner = $rStart['bal'] ?? 0;
    }

    // Petty Cash / Kas Operasional: all transactions before THIS MONTH
    if ($hasCashAccountIdCol && !empty($pettyCashAccounts)) {
        $placeholders = implode(',', array_fill(0, count($pettyCashAccounts), '?'));
        $qStart = "SELECT 
            COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
            COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
            FROM cash_book WHERE cash_account_id IN ($placeholders) AND transaction_date < ?";
        $pStart = array_merge($pettyCashAccounts, [$firstDayOfMonth]);
        $rStart = $db->fetchOne($qStart, $pStart);
        $startKasPetty = $rStart['bal'] ?? 0;
    }

    $startKasHariIni = $startKasOwner + $startKasPetty;

    // Cash Available should follow cashbook formula for selected month:
    // saldo sebelum periode + net transaksi dalam periode.
    $dashCashAvailable = $startKasHariIni + $totalOperationalCash;
    if ($hasCashAccountIdCol && !empty($allAccIds)) {
        $placeholders = implode(',', array_fill(0, count($allAccIds), '?'));
        $periodEnd = date('Y-m-t', strtotime($firstDayOfMonth));
        $qPeriodBal = "SELECT
            COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
            COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
            FROM cash_book WHERE cash_account_id IN ($placeholders) AND transaction_date BETWEEN ? AND ?";
        $pPeriodBal = array_merge($allAccIds, [$firstDayOfMonth, $periodEnd]);
        $rPeriodBal = $db->fetchOne($qPeriodBal, $pPeriodBal);
        $periodBal = (float)($rPeriodBal['bal'] ?? 0);
        $dashCashAvailable = $startKasHariIni + $periodBal;
    }

    // Owner Transfer THIS MONTH only (source_type = 'owner_fund')
    $ownerTransferThisMonth = 0;
    if ($hasSourceTypeCol) {
        $qOwner = "SELECT COALESCE(SUM(amount), 0) as total
            FROM cash_book WHERE source_type = 'owner_fund'
            AND transaction_type = 'income'
            AND DATE_FORMAT(transaction_date, '%Y-%m') = ?";
        $rOwner = $db->fetchOne($qOwner, [$selected_dashboard_month]);
        $ownerTransferThisMonth = $rOwner['total'] ?? 0;
    } elseif ($hasCashAccountIdCol && !empty($capitalAccounts)) {
        $placeholders = implode(',', array_fill(0, count($capitalAccounts), '?'));
        $qOwner = "SELECT COALESCE(SUM(amount), 0) as total
            FROM cash_book WHERE cash_account_id IN ($placeholders) 
            AND transaction_type = 'income'
            AND DATE_FORMAT(transaction_date, '%Y-%m') = ?";
        $pOwner = array_merge($capitalAccounts, [$selected_dashboard_month]);
        $rOwner = $db->fetchOne($qOwner, $pOwner);
        $ownerTransferThisMonth = $rOwner['total'] ?? 0;
    }

    // Today's transactions
    $todayIncome = 0;
    $todayExpense = 0;
    if ($hasCashAccountIdCol && !empty($allAccIds)) {
        $placeholders = implode(',', array_fill(0, count($allAccIds), '?'));
        $qToday = "SELECT 
            COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) as inc,
            COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as exp
            FROM cash_book WHERE cash_account_id IN ($placeholders) AND transaction_date = ?";
        $pToday = array_merge($allAccIds, [$today]);
        $rToday = $db->fetchOne($qToday, $pToday);
        $todayIncome = $rToday['inc'] ?? 0;
        $todayExpense = $rToday['exp'] ?? 0;
    }
} catch (Exception $e) {
    error_log("Error fetching operational cash stats: " . $e->getMessage());
    $capitalStats = ['received' => 0, 'used' => 0, 'balance' => 0];
    $pettyCashStats = ['received' => 0, 'used' => 0, 'balance' => 0];
    $totalOperationalCash = 0;
    $totalOperationalExpense = 0;
    $totalOperationalIncome = 0;
    $startKasHariIni = 0;
    $dashCashAvailable = 0;
    $todayIncome = 0;
    $todayExpense = 0;
}

// ============================================
// GUEST CASH INCOME (cash payments from guests only, NOT owner transfers)
// payment_method = 'cash' AND source_type != 'owner_fund'
// ============================================
$guestCashIncome = 0;
try {
    if ($hasSourceTypeCol) {
        $cashIncomeResult = $db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total 
             FROM cash_book 
             WHERE transaction_type = 'income' 
             AND payment_method = 'cash'
             AND (source_type IS NULL OR source_type NOT IN ('owner_fund','owner_project'))
             AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
            [$selected_dashboard_month]
        );
    } else {
        // Fallback: cash payments excluding owner_capital accounts
        $excludeAccountIds = $capitalAccounts ?? [];
        if (!empty($excludeAccountIds)) {
            $excludePlaceholders = implode(',', array_fill(0, count($excludeAccountIds), '?'));
            $cashIncomeResult = $db->fetchOne(
                "SELECT COALESCE(SUM(amount), 0) as total 
                 FROM cash_book 
                 WHERE transaction_type = 'income' 
                 AND payment_method = 'cash'
                 AND (cash_account_id IS NULL OR cash_account_id NOT IN ($excludePlaceholders))
                 AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
                array_merge($excludeAccountIds, [$selected_dashboard_month])
            );
        } else {
            $cashIncomeResult = $db->fetchOne(
                "SELECT COALESCE(SUM(amount), 0) as total 
                 FROM cash_book 
                 WHERE transaction_type = 'income' 
                 AND payment_method = 'cash'
                 AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
                [$selected_dashboard_month]
            );
        }
    }
    $guestCashIncome = $cashIncomeResult['total'] ?? 0;
} catch (Exception $e) {
    error_log("Error fetching cash income: " . $e->getMessage());
}

// ============================================
// TOP DIVISIONS (This Month)
// ============================================
// Exclude owner capital ONLY from income, not from expense
$divisionOwnerCapitalFilter = '';
if ($hasCashAccountIdCol && !empty($ownerCapitalAccountIds)) {
    $divisionOwnerCapitalFilter = " AND (cb.transaction_type = 'expense' OR cb.cash_account_id IS NULL OR cb.cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
}

$topDivisions = $db->fetchAll(
    "SELECT 
        d.division_name,
        d.division_code,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'income' THEN cb.amount ELSE 0 END), 0) as income,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'expense' THEN cb.amount ELSE 0 END), 0) as expense,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'income' THEN cb.amount ELSE -cb.amount END), 0) as net
    FROM divisions d
    LEFT JOIN cash_book cb ON d.id = cb.division_id 
        AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = :month" . $divisionOwnerCapitalFilter . "
    WHERE d.is_active = 1
    GROUP BY d.id, d.division_name, d.division_code
    ORDER BY net DESC
    LIMIT 5",
    ['month' => $selected_dashboard_month]
);

// ============================================
// RECENT TRANSACTIONS
// ============================================
$recentTransactions = $db->fetchAll(
    "SELECT 
        cb.*,
        COALESCE(d.division_name, 'Unknown') as division_name,
        COALESCE(c.category_name, 'Unknown') as category_name,
        COALESCE(u.full_name, 'System') as created_by_name
    FROM cash_book cb
    LEFT JOIN divisions d ON cb.division_id = d.id
    LEFT JOIN categories c ON cb.category_id = c.id
    LEFT JOIN users u ON cb.created_by = u.id
    ORDER BY cb.transaction_date DESC, cb.id DESC
    LIMIT 10"
);

// ============================================
// CHART DATA - Division Income (Pie Chart)
// ============================================
// Exclude owner fund from division income chart (not hotel profit)
$divisionIncomeFilter = '';
if ($hasSourceTypeCol) {
    // Exclude owner_fund and owner_project using source_type
    $divisionIncomeFilter = " AND (cb.source_type IS NULL OR cb.source_type NOT IN ('owner_fund','owner_project'))";
} elseif ($hasCashAccountIdCol && !empty($ownerCapitalAccountIds)) {
    // Fallback: exclude owner_capital accounts
    $divisionIncomeFilter = " AND (cb.cash_account_id IS NULL OR cb.cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
}

$divisionIncomeData = $db->fetchAll(
    "SELECT 
        d.division_name,
        d.division_code,
        COALESCE(SUM(cb.amount), 0) as total
    FROM divisions d
    LEFT JOIN cash_book cb ON d.id = cb.division_id 
        AND cb.transaction_type = 'income'
        AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = :month" . $divisionIncomeFilter . "
    WHERE d.is_active = 1
    GROUP BY d.id, d.division_name, d.division_code
    HAVING total > 0
    ORDER BY total DESC",
    ['month' => $selected_dashboard_month]
);

// ============================================
// CHART DATA - Expense per Division (for pie chart)
// ============================================
$expenseDivisionData = $db->fetchAll(
    "SELECT 
        d.division_name,
        d.division_code,
        COALESCE(SUM(cb.amount), 0) as total
    FROM divisions d
    LEFT JOIN cash_book cb ON d.id = cb.division_id 
        AND cb.transaction_type = 'expense'
        AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = :month
        AND (cb.source_type IS NULL OR cb.source_type != 'owner_project')
    WHERE d.is_active = 1
    GROUP BY d.id, d.division_name, d.division_code
    HAVING total > 0
    ORDER BY total DESC",
    ['month' => $selected_dashboard_month]
);

// ============================================
// CHART DATA - Daily Income vs Expense (Monthly View)
// ============================================
// Use dashboard_month filter for all dashboard data consistency
$selectedMonth = $selected_dashboard_month;

// Get first and last day of selected month
$firstDay = $selectedMonth . '-01';
$lastDay = date('Y-m-t', strtotime($firstDay));
$daysInMonth = date('t', strtotime($firstDay));

// Generate all dates in the month
$dates = [];
for ($i = 1; $i <= $daysInMonth; $i++) {
    $dates[] = $selectedMonth . '-' . sprintf('%02d', $i);
}

// Get actual transaction data for the month
// IMPORTANT: Exclude owner capital ONLY from income (not from expense!)
// ALL BUSINESSES: Exclude owner_fund (kas operasional top-up from owner = NOT real income)
$ownerFundFilter = " AND (source_type IS NULL OR source_type NOT IN ('owner_fund','owner_project'))";
$transData = $db->fetchAll(
    "SELECT 
        DATE(transaction_date) as date,
        SUM(CASE WHEN transaction_type = 'income'" . $excludeOwnerCapital . $ownerFundFilter . " THEN amount ELSE 0 END) as income,
        SUM(CASE WHEN transaction_type = 'expense' AND (source_type IS NULL OR source_type != 'owner_project') THEN amount ELSE 0 END) as expense
    FROM cash_book
    WHERE DATE_FORMAT(transaction_date, '%Y-%m') = :month
    GROUP BY DATE(transaction_date)
    ORDER BY date ASC",
    ['month' => $selectedMonth]
);

// Map transaction data by date
$transMap = [];
foreach ($transData as $data) {
    $transMap[$data['date']] = $data;
}

// Fill all days in month (missing dates will have 0 values)
$dailyData = [];
foreach ($dates as $date) {
    $dailyData[] = [
        'date' => $date,
        'income' => isset($transMap[$date]) ? $transMap[$date]['income'] : 0,
        'expense' => isset($transMap[$date]) ? $transMap[$date]['expense'] : 0
    ];
}

// ============================================
// CHART DATA - Top Categories This Month
// ============================================
// Exclude owner capital ONLY from income, not from expense
$ownerCapitalFilter = '';
if ($hasCashAccountIdCol && !empty($ownerCapitalAccountIds)) {
    $ownerCapitalFilter = " AND (cb.transaction_type = 'expense' OR cb.cash_account_id IS NULL OR cb.cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
}

$topCategories = $db->fetchAll(
    "SELECT 
        c.category_name,
        d.division_name,
        SUM(cb.amount) as total,
        cb.transaction_type
    FROM cash_book cb
    JOIN categories c ON cb.category_id = c.id
    JOIN divisions d ON cb.division_id = d.id
    WHERE DATE_FORMAT(cb.transaction_date, '%Y-%m') = :month" . $ownerCapitalFilter . "
    GROUP BY c.id, c.category_name, d.division_name, cb.transaction_type
    ORDER BY total DESC
    LIMIT 10",
    ['month' => $selected_dashboard_month]
);

// ============================================
// CQC PROJECT DATA (if CQC business)
// ============================================
$cqcProjects = [];
if ($isCQC) {
    try {
        require_once __DIR__ . '/modules/cqc-projects/db-helper.php';
        $cqcPdo = getCQCDatabaseConnection();

        // Get total budget and spent directly (same as dashboard-2028.php)
        $totalsStmt = $cqcPdo->query("SELECT COALESCE(SUM(spent_idr), 0) as total_spent, COALESCE(SUM(budget_idr), 0) as total_budget FROM cqc_projects WHERE status != 'completed'");
        $projTotals = $totalsStmt->fetch(PDO::FETCH_ASSOC);
        $totalCqcBudget = (float)($projTotals['total_budget'] ?? 0);
        $totalCqcSpent = (float)($projTotals['total_spent'] ?? 0);

        // Get project list
        $stmt = $cqcPdo->query("
            SELECT p.id, p.project_name, p.project_code, p.status, 
                   p.progress_percentage, p.budget_idr, p.spent_idr,
                   p.client_name, p.location, p.solar_capacity_kwp,
                   COALESCE(SUM(e.amount), 0) as actual_spent
            FROM cqc_projects p
            LEFT JOIN cqc_project_expenses e ON p.id = e.project_id
            GROUP BY p.id
            ORDER BY p.status ASC, p.progress_percentage DESC
        ");
        $cqcProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Update spent_idr with actual expense totals
        foreach ($cqcProjects as &$proj) {
            if ($proj['actual_spent'] > 0) {
                $proj['spent_idr'] = $proj['actual_spent'];
            }
        }
        unset($proj);

        // CQC: Do NOT override dailyData with budget!
        // Budget is just RAB (cost estimate), NOT income.
        // Income only comes from actual invoice payments in cash_book.
        // The dailyData from cash_book query above already has the correct data.

    } catch (Exception $e) {
        error_log('CQC project data error: ' . $e->getMessage());
    }

    // CQC: Fetch recent 10 transactions from cashbook for dashboard
    $masterDbName = DB_NAME;
    $recentCashbook = $db->fetchAll(
        "SELECT cb.*, 
                COALESCE(c.category_name, 'Umum') as category_name,
                COALESCE(d.division_name, '-') as division_name,
                COALESCE(u.full_name, 'System') as created_by_name
         FROM cash_book cb
         LEFT JOIN categories c ON cb.category_id = c.id
         LEFT JOIN divisions d ON cb.division_id = d.id
         LEFT JOIN {$masterDbName}.users u ON cb.created_by = u.id
         ORDER BY cb.transaction_date DESC, cb.transaction_time DESC, cb.id DESC
         LIMIT 10"
    );

    // CQC: Calculate Petty Cash actual balance from cash_accounts table
    $cqcPettyCashBalance = 0;
    $cqcBankBalance = 0; // Bank (Kas Besar) balance
    $cqcPettyCashTransfers = 0; // How much was transferred to petty cash this month
    try {
        // Get actual Petty Cash balance from master DB cash_accounts
        $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
        $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $businessId = getMasterBusinessId();

        // Get Petty Cash account balance (account_type = 'cash')
        $stmtPetty = $masterDb->prepare("SELECT COALESCE(current_balance, 0) as balance FROM cash_accounts WHERE business_id = ? AND account_type = 'cash' LIMIT 1");
        $stmtPetty->execute([$businessId]);
        $pettyCashAccount = $stmtPetty->fetch(PDO::FETCH_ASSOC);
        $cqcPettyCashBalance = (float)($pettyCashAccount['balance'] ?? 0);

        // Get Bank account balance (account_type = 'bank') - Kas Besar
        $stmtBank = $masterDb->prepare("SELECT COALESCE(current_balance, 0) as balance FROM cash_accounts WHERE business_id = ? AND account_type = 'bank' LIMIT 1");
        $stmtBank->execute([$businessId]);
        $bankAccount = $stmtBank->fetch(PDO::FETCH_ASSOC);
        $cqcBankBalance = (float)($bankAccount['balance'] ?? 0);

        // Get transfers to petty cash this month (from cash_book source_type = owner_fund)
        $pettyCashMonth = $db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total 
             FROM cash_book 
             WHERE transaction_type = 'income' 
             AND source_type = 'owner_fund'
             AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
            [$selected_dashboard_month]
        );
        $cqcPettyCashTransfers = (float)($pettyCashMonth['total'] ?? 0);

        // Get Petty Cash account ID for expense summary
        $stmtPettyId = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash' LIMIT 1");
        $stmtPettyId->execute([$businessId]);
        $pettyCashAccountId = (int)($stmtPettyId->fetchColumn() ?? 0);

        // Get Bank account ID for expense summary  
        $stmtBankId = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'bank' LIMIT 1");
        $stmtBankId->execute([$businessId]);
        $bankAccountId = (int)($stmtBankId->fetchColumn() ?? 0);
    } catch (Exception $e) {
        error_log('CQC Petty Cash balance error: ' . $e->getMessage());
    }

    // Get expenses from Petty Cash this month
    $cqcExpenseFromPettyCash = 0;
    $cqcExpenseFromBank = 0;

    if (isset($pettyCashAccountId) && $pettyCashAccountId > 0) {
        $expPetty = $db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total 
             FROM cash_book 
             WHERE transaction_type = 'expense' 
             AND cash_account_id = ?
             AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
            [$pettyCashAccountId, $selected_dashboard_month]
        );
        $cqcExpenseFromPettyCash = (float)($expPetty['total'] ?? 0);
    }

    if (isset($bankAccountId) && $bankAccountId > 0) {
        $expBank = $db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total 
             FROM cash_book 
             WHERE transaction_type = 'expense' 
             AND cash_account_id = ?
             AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
            [$bankAccountId, $selected_dashboard_month]
        );
        $cqcExpenseFromBank = (float)($expBank['total'] ?? 0);
    }
}

include 'includes/header.php';
?>

<?php if ($isCQC): ?>
    <style>
        /* CQC Theme - Gold accent, navy text */
        :root,
        body,
        body[data-theme="light"],
        body[data-theme="dark"] {
            --primary-color: #f0b429 !important;
            --primary-dark: #d4960d !important;
            --primary-light: #f5c842 !important;
            --secondary-color: #0d1f3c !important;
            --accent-color: #f0b429 !important;
        }
    </style>
<?php endif; ?>

<?php
// Show trial notification if applicable
if ($trialStatus) {
    echo getTrialNotificationHtml($trialStatus, $developerWA);
}
?>

<!-- PREMIUM TRADING CHART - PALING ATAS -->
<div id="tradingChartCard" style="margin-bottom: 1.5rem; overflow: hidden; border-radius: 20px; background: var(--chart-card-bg); border: 1px solid var(--chart-card-border); box-shadow: var(--chart-card-shadow);">
    <!-- Header Row -->
    <div class="chart-head-wrap">
        <div class="chart-head-row">
            <div class="chart-title-wrap">
                <div id="liveIndicator" class="chart-live-pill">
                    <span class="chart-live-dot"></span>
                    <span class="chart-live-text">LIVE</span>
                </div>
            </div>
            <div class="chart-controls-wrap">
                <div id="dailyFilter" style="display: none; align-items: center;">
                    <input type="date" id="chartDateFilter" value="<?php echo date('Y-m-d'); ?>" class="chart-filter-input" onchange="updateChartDate(this.value)">
                </div>
                <div id="monthlyFilter" style="display: flex; align-items: center;">
                    <input type="month" name="chart_month" id="chartMonthFilter" value="<?php echo $selectedMonth; ?>" class="chart-filter-input" onchange="updateChartMonth(this.value)">
                </div>
                <div id="yearlyFilter" style="display: none; align-items: center;">
                    <select id="chartYearFilter" class="chart-filter-input" onchange="updateChartYear(this.value)">
                        <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == date('Y') ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="chart-view-toggle">
                    <button id="btnDaily" onclick="switchView('daily')" class="btn-view-toggle">Harian</button>
                    <button id="btnMonthly" onclick="switchView('monthly')" class="btn-view-toggle active">Bulanan</button>
                    <button id="btnYearly" onclick="switchView('yearly')" class="btn-view-toggle">Tahunan</button>
                    <button id="btnAllTime" onclick="switchView('alltime')" class="btn-view-toggle">All</button>
                </div>
            </div>
        </div>

        <!-- Summary Numbers Row -->
        <div class="chart-summary-grid">
            <?php
            $totalIncome = array_sum(array_column($dailyData, 'income'));
            $displayIncome = $isCQC ? ($totalIncome - ($cqcPettyCashTransfers ?? 0) - ($cqcExpenseFromBank ?? 0)) : $totalIncome;
            $totalExpense = array_sum(array_column($dailyData, 'expense'));
            if ($isCQC) {
                $netBalance = ($cqcPettyCashBalance ?? 0) + ($cqcBankBalance ?? 0);
            } else {
                $netBalance = $totalIncome - $totalExpense;
            }
            ?>
            <div class="chart-metric-card">
                <div class="chart-metric-top">
                    <div class="chart-metric-dot" style="background: #2563eb;"></div>
                    <span class="chart-metric-name"><?php echo $isCQC ? 'Saldo Kas Besar' : 'Pemasukan'; ?></span>
                    <span class="chart-badge chart-badge-up">↑</span>
                </div>
                <div class="chart-metric-amount" id="summaryIncome"><?php echo formatCurrency($displayIncome); ?></div>
                <div class="chart-metric-sub" id="summaryIncomeSub">&nbsp;</div>
            </div>
            <div class="chart-metric-card">
                <div class="chart-metric-top">
                    <div class="chart-metric-dot" style="background: #f97316;"></div>
                    <span class="chart-metric-name">Pengeluaran</span>
                    <span class="chart-badge chart-badge-down">↓</span>
                </div>
                <div class="chart-metric-amount" id="summaryExpense"><?php echo formatCurrency($totalExpense); ?></div>
                <div class="chart-metric-sub" id="summaryExpenseSub">&nbsp;</div>
            </div>
            <div class="chart-metric-card">
                <div class="chart-metric-top">
                    <div class="chart-metric-dot" style="background: #10b981;"></div>
                    <span class="chart-metric-name"><?php echo $isCQC ? 'Saldo Bersih' : 'Net Balance'; ?></span>
                    <span class="chart-badge <?php echo $netBalance >= 0 ? 'chart-badge-up' : 'chart-badge-down'; ?>"><?php echo $netBalance >= 0 ? '↑' : '↓'; ?></span>
                </div>
                <div class="chart-metric-amount <?php echo $netBalance >= 0 ? 'is-pos' : 'is-neg'; ?>" id="summaryNet"><?php echo formatCurrency($netBalance); ?></div>
                <?php if (!$isCQC): ?><div class="chart-metric-sub" id="summaryNetSub">&nbsp;</div><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Chart Canvas Area with Pie Chart -->
    <div class="chart-main-container">
        <!-- Line Chart Section (Left Side) -->
        <div class="chart-canvas-wrap chart-canvas-left">
            <div class="chart-canvas-inner">
                <canvas id="tradingChart"></canvas>
            </div>
        </div>

        <!-- Panel Ringkasan Periode (kanan): ring rasio, margin, dan statistik kunci -->
        <div class="chart-pie-section">
            <div class="fin-insight">
                <div class="fin-insight-head">
                    <div>
                        <div class="fin-insight-kicker">Ringkasan periode</div>
                        <div class="fin-insight-title" id="insightTitle">&nbsp;</div>
                    </div>
                    <span class="fin-health" id="insightHealth">&nbsp;</span>
                </div>

                <div class="fin-ring-row">
                    <div class="fin-ring">
                        <canvas id="summaryPieChart"></canvas>
                        <div class="fin-ring-center">
                            <b id="ringValue">&ndash;</b>
                            <small id="ringLabel">Margin</small>
                            <small class="fin-ring-amount" id="ringAmount"></small>
                        </div>
                    </div>
                    <div class="fin-ring-legend">
                        <div class="fin-leg">
                            <span class="fin-leg-dot" style="background: #2563eb;"></span>
                            <div class="fin-leg-text"><small>Pemasukan</small><b id="pieIncomeValue">Rp 0</b></div>
                            <em id="pieIncomePct">0%</em>
                        </div>
                        <div class="fin-leg">
                            <span class="fin-leg-dot" style="background: #f97316;"></span>
                            <div class="fin-leg-text"><small>Pengeluaran</small><b id="pieExpenseValue">Rp 0</b></div>
                            <em id="pieExpensePct">0%</em>
                        </div>
                    </div>
                </div>

                <div class="fin-ratio">
                    <div class="fin-ratio-bar"><span id="ratioFill"></span></div>
                    <div class="fin-ratio-caption" id="ratioCaption">&nbsp;</div>
                </div>

                <div class="fin-stats">
                    <div class="fin-stat">
                        <small id="statAvgLabel">Rata-rata per hari</small>
                        <b id="statAvgIncome">Rp 0</b>
                        <span>pemasukan</span>
                    </div>
                    <div class="fin-stat">
                        <small>Pemasukan puncak</small>
                        <b id="statBestValue">&ndash;</b>
                        <span id="statBestWhen">&nbsp;</span>
                    </div>
                    <div class="fin-stat">
                        <small>Pengeluaran puncak</small>
                        <b id="statWorstValue">&ndash;</b>
                        <span id="statWorstWhen">&nbsp;</span>
                    </div>
                    <div class="fin-stat">
                        <small id="statSurplusLabel">Hari surplus</small>
                        <b id="statSurplus">0</b>
                        <span id="statSurplusOf">&nbsp;</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer Bar -->
    <div class="chart-footer-bar">
        <span id="periodDisplay" class="chart-period-display">1 - <?php echo date('t', strtotime($firstDay)); ?> <?php echo date('M Y', strtotime($firstDay)); ?></span>
        <div class="chart-legend-wrap">
            <div class="chart-legend-item"><span class="chart-legend-dot" style="background: #2563eb;"></span>Pemasukan</div>
            <div class="chart-legend-item"><span class="chart-legend-dot" style="background: #f97316;"></span>Pengeluaran</div>
            <div class="chart-legend-item"><span class="chart-legend-line" style="background: #10b981;"></span>Net</div>
        </div>
    </div>
</div>

<style>
    @keyframes livePulse {

        0%,
        100% {
            opacity: 1;
            transform: scale(1);
        }

        50% {
            opacity: 0.4;
            transform: scale(1.3);
        }
    }

    @keyframes pulse {

        0%,
        100% {
            opacity: 1;
            transform: scale(1);
        }

        50% {
            opacity: 0.6;
            transform: scale(1.1);
        }
    }

    /* === CHART CARD - Digital Elegant === */
    :root {
        --chart-card-bg: linear-gradient(135deg, #ffffff 0%, #f7fbff 58%, #f2f8ff 100%);
        --chart-card-border: rgba(30, 41, 59, 0.08);
        --chart-card-shadow: 0 8px 28px rgba(15, 23, 42, 0.08), 0 1px 2px rgba(15, 23, 42, 0.05);
        --chart-wrap-bg: radial-gradient(circle at 12% 0%, rgba(56, 189, 248, 0.08), transparent 42%), #f8fbff;
        --chart-wrap-border: rgba(30, 41, 59, 0.10);
        --chart-tick-color: rgba(71, 85, 105, 0.68);
        --chart-grid-color: rgba(148, 163, 184, 0.26);
        --chart-metric-bg: linear-gradient(145deg, rgba(255, 255, 255, 0.95), rgba(241, 245, 249, 0.82));
        --chart-metric-border: rgba(148, 163, 184, 0.26);
    }

    body[data-theme="dark"] {
        --chart-card-bg: linear-gradient(135deg, #0f172a 0%, #111827 58%, #0b1220 100%);
        --chart-card-border: rgba(148, 163, 184, 0.2);
        --chart-card-shadow: 0 8px 30px rgba(0, 0, 0, 0.35), 0 1px 2px rgba(0, 0, 0, 0.25);
        --chart-wrap-bg: radial-gradient(circle at 12% 0%, rgba(56, 189, 248, 0.16), transparent 45%), rgba(15, 23, 42, 0.95);
        --chart-wrap-border: rgba(148, 163, 184, 0.2);
        --chart-tick-color: rgba(203, 213, 225, 0.72);
        --chart-grid-color: rgba(148, 163, 184, 0.20);
        --chart-metric-bg: linear-gradient(145deg, rgba(30, 41, 59, 0.78), rgba(15, 23, 42, 0.78));
        --chart-metric-border: rgba(148, 163, 184, 0.25);
    }

    #tradingChartCard {
        background: var(--chart-card-bg);
        transition: box-shadow 0.3s, transform 0.3s;
        position: relative;
        backdrop-filter: blur(6px);
    }

    #tradingChartCard:hover {
        box-shadow: 0 8px 40px rgba(0, 0, 0, 0.18), 0 2px 6px rgba(0, 0, 0, 0.1);
        transform: translateY(-1px);
    }

    #tradingChartCard::before {
        content: '';
        position: absolute;
        inset: 0;
        background:
            radial-gradient(circle at 100% 0%, rgba(56, 189, 248, 0.14), transparent 36%),
            radial-gradient(circle at 0% 100%, rgba(16, 185, 129, 0.08), transparent 38%);
        pointer-events: none;
    }

    .chart-head-wrap {
        padding: 1.1rem 1.25rem 0;
        position: relative;
        z-index: 2;
    }

    .chart-head-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
        flex-wrap: wrap;
    }

    .chart-title-wrap {
        display: flex;
        align-items: center;
        gap: 0.7rem;
    }

    .chart-title-accent {
        width: 4px;
        height: 34px;
        border-radius: 999px;
        background: linear-gradient(180deg, #0ea5e9 0%, #14b8a6 100%);
        box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.1);
    }

    .chart-kicker {
        font-size: 0.56rem;
        color: var(--text-muted);
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.14em;
        line-height: 1;
    }

    .chart-main-title {
        font-size: 0.98rem;
        font-weight: 800;
        color: var(--text-primary);
        margin-top: 0.16rem;
        line-height: 1.15;
    }

    .chart-sub-title {
        font-size: 0.67rem;
        color: var(--text-muted);
        margin-top: 0.24rem;
        line-height: 1.3;
        max-width: 420px;
    }

    .chart-controls-wrap {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    /* Live indicator */
    .chart-live-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.18rem 0.55rem;
        border-radius: 999px;
        background: rgba(16, 185, 129, 0.1);
        border: 1px solid rgba(16, 185, 129, 0.18);
    }

    .chart-live-dot {
        width: 5px;
        height: 5px;
        background: #10b981;
        border-radius: 50%;
        animation: livePulse 2s infinite;
        box-shadow: 0 0 6px rgba(16, 185, 129, 0.4);
    }

    .chart-live-text {
        font-size: 0.57rem;
        font-weight: 700;
        color: #10b981;
        letter-spacing: 0.1em;
    }

    /* Filter inputs */
    .chart-filter-input {
        max-width: 125px;
        height: 30px;
        font-size: 0.66rem;
        font-weight: 700;
        border: 1px solid var(--chart-metric-border);
        border-radius: 9px;
        background: var(--chart-wrap-bg);
        color: var(--text-primary);
        padding: 0 0.55rem;
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    .chart-filter-input:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.14);
    }

    /* View toggle pill bar */
    .chart-view-toggle {
        display: flex;
        align-items: center;
        gap: 2px;
        background: var(--chart-wrap-bg);
        padding: 3px;
        border-radius: 10px;
        border: 1px solid var(--chart-metric-border);
    }

    .btn-view-toggle {
        padding: 0.3rem 0.56rem;
        border: none;
        background: transparent;
        color: var(--text-muted);
        border-radius: 7px;
        font-size: 0.63rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
        white-space: nowrap;
    }

    .btn-view-toggle.active {
        background: linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%);
        color: #fff;
        box-shadow: 0 6px 14px rgba(37, 99, 235, 0.25);
    }

    .btn-view-toggle:not(.active):hover {
        background: var(--chart-metric-bg);
        color: var(--text-primary);
    }

    /* Metric cards */
    .chart-summary-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.5rem;
        margin-bottom: 0.7rem;
    }

    .chart-metric-card {
        padding: 0.5rem 0.65rem;
        border-radius: 10px;
        background: var(--chart-metric-bg);
        border: 1px solid var(--chart-metric-border);
        transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
        position: relative;
        overflow: hidden;
    }

    .chart-metric-card::after {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        height: 2px;
        background: linear-gradient(90deg, rgba(14, 165, 233, 0.9), rgba(20, 184, 166, 0.4));
        opacity: 0.75;
    }

    .chart-metric-card:hover {
        transform: translateY(-1px);
        box-shadow: 0 10px 20px rgba(15, 23, 42, 0.08);
        border-color: rgba(14, 165, 233, 0.28);
    }

    .chart-metric-top {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        margin-bottom: 0.35rem;
    }

    .chart-metric-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .chart-metric-name {
        font-size: 0.6rem;
        color: var(--text-muted);
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        flex: 1;
    }

    .chart-metric-amount {
        font-size: 0.98rem;
        font-weight: 800;
        color: var(--text-primary);
        line-height: 1.25;
        font-variant-numeric: tabular-nums;
    }

    /* Chart badge */
    .chart-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.08rem 0.35rem;
        border-radius: 4px;
        font-size: 0.55rem;
        font-weight: 700;
        flex-shrink: 0;
    }

    .chart-badge-up {
        background: rgba(16, 185, 129, 0.1);
        color: #10b981;
    }

    .chart-badge-down {
        background: rgba(239, 68, 68, 0.1);
        color: #ef4444;
    }

    /* Canvas area - transparent gray */
    .chart-canvas-wrap {
        background: var(--chart-wrap-bg);
        margin: 0 0.7rem;
        border-radius: 12px;
        border: 1px solid var(--chart-wrap-border);
        position: relative;
        overflow: hidden;
    }

    .chart-canvas-wrap::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image: linear-gradient(to right, rgba(148, 163, 184, 0.08) 1px, transparent 1px);
        background-size: 42px 100%;
        opacity: 0.24;
        pointer-events: none;
    }

    .chart-canvas-inner {
        position: relative;
        height: 220px;
        padding: 0.5rem 0.82rem 0.3rem;
        z-index: 1;
    }

    /* Main container for chart and pie charts */
    .chart-main-container {
        display: flex;
        gap: 1rem;
        margin: 0 0.7rem;
        padding: 0;
    }

    /* Left side - line chart */
    .chart-canvas-left {
        flex: 0 0 56%;
        margin: 0;
    }

    /* Right side - pie chart */
    .chart-pie-section {
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    /* Individual pie chart card */
    .chart-pie-card {
        background: var(--chart-wrap-bg);
        border-radius: 12px;
        border: 1px solid var(--chart-wrap-border);
        padding: 0.75rem;
        display: flex;
        flex-direction: column;
        height: 100%;
    }

    .chart-pie-header {
        display: flex;
        flex-direction: column;
        gap: 0.2rem;
        margin-bottom: 0.6rem;
        padding-bottom: 0.6rem;
        border-bottom: 1px solid var(--chart-wrap-border);
    }

    /* Pie chart canvas container */
    .chart-pie-container {
        flex: 1;
        position: relative;
        min-height: 130px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 0.6rem;
    }

    .chart-pie-container canvas {
        max-width: 115px;
        max-height: 115px;
    }

    /* Pie chart legend */
    .chart-pie-legend {
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
        padding-top: 0.55rem;
        border-top: 1px solid var(--chart-wrap-border);
    }

    .chart-pie-legend-item {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        font-size: 0.875rem;
    }

    .chart-pie-legend-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .chart-pie-legend-label {
        flex: 1;
        color: var(--text-secondary);
        font-weight: 500;
    }

    .chart-pie-legend-value {
        color: var(--text-primary);
        font-weight: 700;
        font-size: 0.9rem;
        text-align: right;
    }

    /* === Redesign grafik keuangan: panel insight, ring rasio, sub-info KPI === */
    :root {
        --chart-zero-line: rgba(71, 85, 105, 0.45);
        --chart-hover-band: rgba(99, 102, 241, 0.07);
        --chart-ring-track: rgba(148, 163, 184, 0.18);
        --fin-panel-bg: linear-gradient(160deg, rgba(255, 255, 255, 0.96), rgba(241, 245, 249, 0.85));
        --fin-stat-bg: rgba(255, 255, 255, 0.75);
    }

    body[data-theme="dark"] {
        --chart-zero-line: rgba(203, 213, 225, 0.45);
        --chart-hover-band: rgba(148, 163, 184, 0.10);
        --chart-ring-track: rgba(148, 163, 184, 0.16);
        --fin-panel-bg: linear-gradient(160deg, rgba(30, 41, 59, 0.75), rgba(15, 23, 42, 0.75));
        --fin-stat-bg: rgba(15, 23, 42, 0.55);
    }

    .chart-canvas-left {
        flex: 1 1 auto;
        min-width: 0;
    }

    .chart-canvas-inner {
        height: 300px;
    }

    .chart-canvas-wrap::before {
        display: none;
    }

    .chart-pie-section {
        flex: 0 0 340px;
    }

    .chart-metric-sub {
        margin-top: 0.3rem;
        font-size: 0.68rem;
        font-weight: 600;
        color: var(--text-muted);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .chart-metric-sub b {
        color: var(--text-secondary);
    }

    .fin-insight {
        height: 100%;
        display: flex;
        flex-direction: column;
        gap: 0.85rem;
        padding: 1rem;
        border-radius: 14px;
        background: var(--fin-panel-bg);
        border: 1px solid var(--chart-wrap-border);
    }

    .fin-insight-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.5rem;
    }

    .fin-insight-kicker {
        font-size: 0.62rem;
        font-weight: 700;
        letter-spacing: 0.09em;
        text-transform: uppercase;
        color: var(--text-muted);
    }

    .fin-insight-title {
        margin-top: 0.15rem;
        font-size: 0.98rem;
        font-weight: 700;
        color: var(--text-heading, var(--text-primary));
        letter-spacing: -0.01em;
    }

    .fin-health {
        flex-shrink: 0;
        padding: 0.25rem 0.6rem;
        border-radius: 999px;
        font-size: 0.66rem;
        font-weight: 700;
        border: 1px solid transparent;
    }

    .fin-health.is-good { color: #059669; background: rgba(16, 185, 129, 0.12); border-color: rgba(16, 185, 129, 0.25); }
    .fin-health.is-thin { color: #b45309; background: rgba(245, 158, 11, 0.13); border-color: rgba(245, 158, 11, 0.28); }
    .fin-health.is-bad { color: #dc2626; background: rgba(239, 68, 68, 0.11); border-color: rgba(239, 68, 68, 0.25); }
    .fin-health.is-empty { color: var(--text-muted); background: rgba(148, 163, 184, 0.12); }

    body[data-theme="dark"] .fin-health.is-good { color: #34d399; }
    body[data-theme="dark"] .fin-health.is-thin { color: #fbbf24; }
    body[data-theme="dark"] .fin-health.is-bad { color: #f87171; }

    .fin-ring-row {
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .fin-ring {
        position: relative;
        width: 118px;
        height: 118px;
        flex-shrink: 0;
    }

    .fin-ring-center {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        pointer-events: none;
    }

    .fin-ring-center b {
        font-size: 1.15rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        color: var(--text-primary);
        font-variant-numeric: tabular-nums;
    }

    .fin-ring-center small {
        font-size: 0.6rem;
        font-weight: 600;
        color: var(--text-muted);
    }

    .fin-ring-legend {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 0.7rem;
    }

    .fin-leg {
        display: flex;
        align-items: center;
        gap: 0.55rem;
    }

    .fin-leg-dot {
        width: 9px;
        height: 9px;
        border-radius: 3px;
        flex-shrink: 0;
    }

    .fin-leg-text {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
    }

    .fin-leg-text small {
        font-size: 0.66rem;
        font-weight: 600;
        color: var(--text-muted);
    }

    .fin-leg-text b {
        font-size: 0.86rem;
        font-weight: 700;
        color: var(--text-primary);
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .fin-leg em {
        font-style: normal;
        font-size: 0.68rem;
        font-weight: 700;
        color: var(--text-secondary);
        font-variant-numeric: tabular-nums;
    }

    .fin-ratio-bar {
        height: 6px;
        border-radius: 999px;
        background: rgba(249, 115, 22, 0.85);
        overflow: hidden;
    }

    .fin-ratio-bar span {
        display: block;
        height: 100%;
        width: 50%;
        border-radius: 999px;
        background: #10b981;
        transition: width 0.6s cubic-bezier(0.22, 1, 0.36, 1);
    }

    .fin-ratio-bar.is-empty {
        background: var(--chart-ring-track);
    }

    .fin-ratio-bar.is-empty span {
        width: 0 !important;
    }

    .fin-ratio-caption {
        margin-top: 0.4rem;
        font-size: 0.7rem;
        color: var(--text-secondary);
    }

    .fin-ratio-caption b {
        color: var(--text-primary);
    }

    .fin-stats {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
        margin-top: auto;
    }

    .fin-stat {
        min-width: 0;
        padding: 0.55rem 0.65rem;
        border-radius: 10px;
        background: var(--fin-stat-bg);
        border: 1px solid var(--chart-wrap-border);
        display: flex;
        flex-direction: column;
        gap: 0.1rem;
    }

    .fin-stat small {
        font-size: 0.6rem;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .fin-stat b {
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--text-primary);
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .fin-stat span {
        font-size: 0.64rem;
        color: var(--text-muted);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .chart-legend-line {
        width: 14px;
        height: 3px;
        border-radius: 2px;
        flex-shrink: 0;
    }

    @media (max-width: 1100px) {
        .chart-pie-section {
            flex-basis: 300px;
        }
    }

    @media (max-width: 860px) {
        .chart-canvas-inner {
            height: 260px;
        }

        .chart-pie-section {
            flex-basis: auto;
        }
    }

    /* === Tata letak kartu grafik: jarak seragam 1.25rem di semua sisi === */
    #tradingChartCard .chart-head-wrap {
        padding: 1.25rem 1.25rem 0;
    }

    #tradingChartCard .chart-head-row {
        margin-bottom: 1.1rem;
    }

    #tradingChartCard .chart-summary-grid {
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    #tradingChartCard .chart-metric-card {
        padding: 0.75rem 0.95rem;
        border-radius: 12px;
    }

    #tradingChartCard .chart-main-container {
        margin: 0 1.25rem 1.25rem;
        gap: 0.75rem;
        align-items: stretch;
    }

    #tradingChartCard .chart-canvas-wrap {
        margin: 0;
        border-radius: 14px;
    }

    #tradingChartCard .chart-canvas-inner {
        padding: 0.9rem 1rem 0.6rem 0.5rem;
    }

    #tradingChartCard .chart-footer-bar {
        padding: 0.8rem 1.25rem;
    }

    /* Tombol periode: segmented control biru elegan, teks aktif putih.
       Selector ber-ID + !important karena style.css tema terang memaksa warna teks. */
    #tradingChartCard .chart-controls-wrap {
        gap: 0.6rem;
    }

    #tradingChartCard .chart-filter-input {
        height: 34px;
        font-size: 0.72rem;
        border-radius: 10px;
    }

    #tradingChartCard .chart-view-toggle {
        gap: 2px;
        padding: 3px;
        border-radius: 11px;
        background: rgba(148, 163, 184, 0.12);
        border: 1px solid var(--chart-metric-border);
    }

    #tradingChartCard .btn-view-toggle {
        height: 28px;
        padding: 0 0.85rem;
        border-radius: 8px;
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0.01em;
        color: var(--text-secondary) !important;
        background: transparent;
    }

    #tradingChartCard .btn-view-toggle:not(.active):hover {
        color: var(--text-primary) !important;
        background: rgba(148, 163, 184, 0.16);
    }

    #tradingChartCard .btn-view-toggle.active {
        color: #fff !important;
        background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%) !important;
        box-shadow: 0 4px 12px -2px rgba(29, 78, 216, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.22);
    }

    #tradingChartCard .btn-view-toggle:focus-visible {
        outline: 2px solid rgba(59, 130, 246, 0.6);
        outline-offset: 1px;
    }

    /* Indikator LIVE: hanya hijau berdenyut saat mode Bulanan (auto refresh aktif) */
    #tradingChartCard .chart-live-pill.is-paused {
        background: rgba(148, 163, 184, 0.12);
        border-color: rgba(148, 163, 184, 0.25);
    }

    #tradingChartCard .chart-live-pill.is-paused .chart-live-dot {
        background: #94a3b8;
        box-shadow: none;
        animation: none;
    }

    #tradingChartCard .chart-live-pill.is-paused .chart-live-text {
        color: #64748b !important;
    }

    #tradingChartCard .chart-live-text {
        color: #10b981 !important;
    }

    /* Warna status tetap tampil di tema terang (style.css menimpa warna span/div) */
    #tradingChartCard .is-pos { color: #10b981 !important; }
    #tradingChartCard .is-neg { color: #ef4444 !important; }
    #tradingChartCard .fin-health.is-good { color: #059669 !important; }
    #tradingChartCard .fin-health.is-thin { color: #b45309 !important; }
    #tradingChartCard .fin-health.is-bad { color: #dc2626 !important; }
    body[data-theme="dark"] #tradingChartCard .fin-health.is-good { color: #34d399 !important; }
    body[data-theme="dark"] #tradingChartCard .fin-health.is-thin { color: #fbbf24 !important; }
    body[data-theme="dark"] #tradingChartCard .fin-health.is-bad { color: #f87171 !important; }

    @media (max-width: 860px) {
        #tradingChartCard .chart-head-wrap {
            padding: 1rem 0.9rem 0;
        }

        #tradingChartCard .chart-main-container {
            margin: 0 0.9rem 0.9rem;
        }

        #tradingChartCard .chart-footer-bar {
            padding: 0.75rem 0.9rem;
        }

        #tradingChartCard .chart-controls-wrap {
            width: 100%;
            justify-content: space-between;
        }

        #tradingChartCard .btn-view-toggle {
            padding: 0 0.6rem;
        }
    }

    /* === Gaya kaca (glass) + ukuran ringkas untuk kartu grafik === */
    :root {
        --glass-card: linear-gradient(135deg, rgba(255, 255, 255, 0.78), rgba(241, 247, 255, 0.6));
        --glass-panel: rgba(255, 255, 255, 0.55);
        --glass-tile: rgba(255, 255, 255, 0.62);
        --glass-edge: rgba(255, 255, 255, 0.85);
        --glass-line: rgba(148, 163, 184, 0.22);
        --glass-shadow: 0 10px 30px -12px rgba(15, 23, 42, 0.14);
        --glass-glow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
    }

    body[data-theme="dark"] {
        --glass-card: linear-gradient(135deg, rgba(30, 41, 59, 0.62), rgba(15, 23, 42, 0.5));
        --glass-panel: rgba(30, 41, 59, 0.42);
        --glass-tile: rgba(30, 41, 59, 0.5);
        --glass-edge: rgba(255, 255, 255, 0.08);
        --glass-line: rgba(148, 163, 184, 0.14);
        --glass-shadow: 0 14px 34px -14px rgba(0, 0, 0, 0.55);
        --glass-glow: inset 0 1px 0 rgba(255, 255, 255, 0.06);
    }

    #tradingChartCard {
        background: var(--glass-card) !important;
        border: 1px solid var(--glass-edge) !important;
        box-shadow: var(--glass-shadow), var(--glass-glow) !important;
        backdrop-filter: blur(20px) saturate(160%);
        -webkit-backdrop-filter: blur(20px) saturate(160%);
    }

    #tradingChartCard:hover {
        transform: none;
    }

    /* Kartu KPI: lebih kecil dan ringan */
    #tradingChartCard .chart-summary-grid {
        gap: 0.6rem;
        margin-bottom: 0.85rem;
    }

    #tradingChartCard .chart-metric-card {
        padding: 0.5rem 0.75rem;
        border-radius: 11px;
        background: var(--glass-tile);
        border: 1px solid var(--glass-edge);
        box-shadow: var(--glass-glow);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
    }

    #tradingChartCard .chart-metric-card::after {
        height: 1.5px;
        opacity: 0.45;
    }

    #tradingChartCard .chart-metric-top {
        margin-bottom: 0.2rem;
    }

    #tradingChartCard .chart-metric-name {
        font-size: 0.55rem;
        letter-spacing: 0.07em;
    }

    #tradingChartCard .chart-badge {
        font-size: 0.5rem;
        padding: 0.05rem 0.3rem;
    }

    #tradingChartCard .chart-metric-amount {
        font-size: 0.86rem;
        font-weight: 700;
        line-height: 1.2;
    }

    #tradingChartCard .chart-metric-sub {
        margin-top: 0.15rem;
        font-size: 0.6rem;
    }

    /* Area grafik dan panel ringkasan: panel kaca, lebih pendek */
    #tradingChartCard .chart-canvas-wrap,
    #tradingChartCard .fin-insight {
        background: var(--glass-panel);
        border: 1px solid var(--glass-edge);
        box-shadow: var(--glass-glow);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
    }

    #tradingChartCard .chart-canvas-wrap {
        display: flex;
        flex-direction: column;
    }

    #tradingChartCard .chart-canvas-inner {
        flex: 1 1 auto;
        height: auto;
        min-height: 240px;
        padding: 0.75rem 0.85rem 0.45rem 0.4rem;
    }

    #tradingChartCard .chart-pie-section {
        flex: 0 0 300px;
    }

    #tradingChartCard .fin-insight {
        gap: 0.65rem;
        padding: 0.8rem 0.85rem;
    }

    #tradingChartCard .fin-insight-kicker {
        font-size: 0.55rem;
    }

    #tradingChartCard .fin-insight-title {
        font-size: 0.86rem;
    }

    #tradingChartCard .fin-health {
        font-size: 0.6rem;
        padding: 0.18rem 0.5rem;
    }

    #tradingChartCard .fin-ring-row {
        gap: 0.8rem;
    }

    #tradingChartCard .fin-ring {
        width: 104px;
        height: 104px;
    }

    #tradingChartCard .fin-ring-center b {
        font-size: 0.92rem;
    }

    #tradingChartCard .fin-ring-center small {
        font-size: 0.52rem;
    }

    #tradingChartCard .fin-ring-legend {
        gap: 0.5rem;
    }

    #tradingChartCard .fin-leg-text small {
        font-size: 0.6rem;
    }

    #tradingChartCard .fin-leg-text b {
        font-size: 0.76rem;
    }

    #tradingChartCard .fin-leg em {
        font-size: 0.62rem;
    }

    #tradingChartCard .fin-ratio-bar {
        height: 5px;
        background: rgba(249, 115, 22, 0.55);
    }

    #tradingChartCard .fin-ratio-bar span {
        background: linear-gradient(90deg, rgba(16, 185, 129, 0.75), #10b981);
    }

    #tradingChartCard .fin-ratio-caption {
        margin-top: 0.3rem;
        font-size: 0.62rem;
    }

    #tradingChartCard .fin-stats {
        gap: 0.4rem;
    }

    #tradingChartCard .fin-stat {
        padding: 0.42rem 0.55rem;
        border-radius: 9px;
        background: var(--glass-tile);
        border: 1px solid var(--glass-edge);
        box-shadow: var(--glass-glow);
    }

    #tradingChartCard .fin-stat small {
        font-size: 0.6rem;
        letter-spacing: 0;
        text-transform: none;
    }

    #tradingChartCard .fin-stat b {
        font-size: 0.74rem;
    }

    #tradingChartCard .fin-stat span {
        font-size: 0.58rem;
    }

    #tradingChartCard .chart-footer-bar {
        border-top-color: var(--glass-line);
        background: transparent;
        padding: 0.6rem 1.25rem;
    }

    #tradingChartCard .chart-legend-item {
        background: var(--glass-tile);
        border-color: var(--glass-edge);
        font-size: 0.6rem;
        padding: 0.26rem 0.55rem;
    }

    @media (max-width: 1100px) {
        #tradingChartCard .chart-pie-section {
            flex-basis: 270px;
        }
    }

    @media (max-width: 860px) {
        #tradingChartCard .chart-pie-section {
            flex-basis: auto;
        }

        #tradingChartCard .chart-canvas-inner {
            min-height: 220px;
            height: 220px;
        }

        #tradingChartCard .chart-summary-grid {
            grid-template-columns: 1fr;
        }
    }

    /* === Header ringkas: judul kecil, kontrol mungil, KPI jadi satu strip kaca === */
    #tradingChartCard .chart-head-wrap {
        padding: 0.9rem 1.25rem 0;
    }

    #tradingChartCard .chart-head-row {
        margin-bottom: 0.75rem;
        gap: 0.75rem;
    }

    #tradingChartCard .chart-title-accent {
        height: 26px;
        width: 3px;
    }

    #tradingChartCard .chart-kicker {
        font-size: 0.5rem;
        letter-spacing: 0.1em;
    }

    #tradingChartCard .chart-main-title {
        font-size: 0.82rem;
        line-height: 1.25;
    }

    #tradingChartCard .chart-sub-title {
        font-size: 0.58rem;
    }

    #tradingChartCard .chart-live-pill {
        padding: 0.1rem 0.45rem;
    }

    #tradingChartCard .chart-live-text {
        font-size: 0.5rem;
    }

    #tradingChartCard .chart-controls-wrap {
        gap: 0.45rem;
    }

    #tradingChartCard .chart-filter-input {
        height: 28px;
        max-width: 118px;
        font-size: 0.64rem;
        border-radius: 8px;
        padding: 0 0.45rem;
        background: var(--glass-tile);
        border-color: var(--glass-edge);
    }

    #tradingChartCard .chart-view-toggle {
        padding: 2px;
        border-radius: 9px;
        background: var(--glass-tile);
        border-color: var(--glass-edge);
    }

    #tradingChartCard .btn-view-toggle {
        height: 24px;
        padding: 0 0.62rem;
        border-radius: 7px;
        font-size: 0.64rem;
    }

    /* KPI: tiga angka dalam satu strip dengan pemisah tipis, bukan tiga kartu besar */
    #tradingChartCard .chart-summary-grid {
        gap: 0;
        margin-bottom: 0.75rem;
        padding: 0.5rem 0;
        border-radius: 12px;
        background: var(--glass-tile);
        border: 1px solid var(--glass-edge);
        box-shadow: var(--glass-glow);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
    }

    #tradingChartCard .chart-metric-card {
        padding: 0.05rem 1rem;
        background: transparent;
        border: none;
        border-radius: 0;
        box-shadow: none;
        backdrop-filter: none;
        -webkit-backdrop-filter: none;
    }

    #tradingChartCard .chart-metric-card + .chart-metric-card {
        border-left: 1px solid var(--glass-line);
    }

    #tradingChartCard .chart-metric-card::after,
    #tradingChartCard .chart-badge {
        display: none;
    }

    #tradingChartCard .chart-metric-card:hover {
        transform: none;
        box-shadow: none;
    }

    #tradingChartCard .chart-metric-top {
        margin-bottom: 0.1rem;
        gap: 0.3rem;
    }

    #tradingChartCard .chart-metric-dot {
        width: 5px;
        height: 5px;
    }

    #tradingChartCard .chart-metric-name {
        font-size: 0.52rem;
    }

    #tradingChartCard .chart-metric-amount {
        font-size: 0.8rem;
        line-height: 1.2;
    }

    #tradingChartCard .chart-metric-sub {
        margin-top: 0.05rem;
        font-size: 0.56rem;
    }

    @media (max-width: 860px) {
        #tradingChartCard .chart-head-wrap {
            padding: 0.8rem 0.9rem 0;
        }

        #tradingChartCard .chart-summary-grid {
            grid-template-columns: 1fr;
            padding: 0.2rem 0;
        }

        #tradingChartCard .chart-metric-card {
            padding: 0.4rem 0.8rem;
        }

        #tradingChartCard .chart-metric-card + .chart-metric-card {
            border-left: none;
            border-top: 1px solid var(--glass-line);
        }
    }

    /* Ring: info segmen tampil di tengah saat disorot, legenda lain meredup */
    #tradingChartCard .fin-ring canvas {
        cursor: pointer;
    }

    #tradingChartCard .fin-ring-center b {
        transition: color 0.2s ease;
    }

    #tradingChartCard .fin-ring-center .is-inc { color: #2563eb !important; }
    #tradingChartCard .fin-ring-center .is-exp { color: #f97316 !important; }

    #tradingChartCard .fin-ring-amount {
        font-size: 0.55rem !important;
        font-weight: 700;
        color: var(--text-secondary) !important;
        line-height: 1.1;
        min-height: 0.6rem;
    }

    #tradingChartCard .fin-leg {
        transition: opacity 0.2s ease;
    }

    #tradingChartCard .fin-leg.is-dim {
        opacity: 0.35;
    }

    /* Panel ringkasan: baris lebih rapat + warna lebih tegas */
    #tradingChartCard .fin-insight {
        gap: 0.5rem;
    }

    #tradingChartCard .fin-ring-legend {
        gap: 0.35rem;
    }

    #tradingChartCard .fin-leg-text {
        line-height: 1.2;
    }

    #tradingChartCard .fin-leg-text b,
    #tradingChartCard .fin-stat b,
    #tradingChartCard .fin-insight-title {
        color: #0f172a !important;
    }

    body[data-theme="dark"] #tradingChartCard .fin-leg-text b,
    body[data-theme="dark"] #tradingChartCard .fin-stat b,
    body[data-theme="dark"] #tradingChartCard .fin-insight-title {
        color: #f8fafc !important;
    }

    #tradingChartCard .fin-ratio-bar {
        height: 6px;
        background: #f97316;
    }

    #tradingChartCard .fin-ratio-bar span {
        background: linear-gradient(90deg, #3b82f6, #2563eb);
    }

    #tradingChartCard .fin-ratio-caption {
        margin-top: 0.25rem;
    }

    #tradingChartCard .fin-stats {
        gap: 0.35rem;
    }

    #tradingChartCard .fin-stat {
        padding: 0.32rem 0.55rem;
        gap: 0;
        line-height: 1.2;
    }

    #tradingChartCard .fin-stat small {
        line-height: 1.25;
    }

    /* Proporsi: grafik lebih pendek (lebar), panel ringkasan lebih lebar */
    #tradingChartCard .chart-pie-section {
        flex: 0 0 clamp(340px, 40%, 500px);
    }

    #tradingChartCard .fin-insight {
        padding: 0.9rem 1rem;
    }

    #tradingChartCard .fin-ring-row {
        gap: 1.25rem;
        padding: 0.15rem 0.25rem;
    }

    #tradingChartCard .fin-ring {
        width: 116px;
        height: 116px;
    }

    #tradingChartCard .fin-ring-center b {
        font-size: 1rem;
    }

    #tradingChartCard .fin-leg-text b {
        font-size: 0.82rem;
    }

    #tradingChartCard .fin-stats {
        gap: 0.45rem;
    }

    @media (max-width: 1100px) {
        #tradingChartCard .chart-pie-section {
            flex-basis: clamp(300px, 42%, 400px);
        }
    }

    @media (max-width: 860px) {
        #tradingChartCard .chart-pie-section {
            flex-basis: auto;
        }
    }

    /* Ring + legenda sebagai satu grup di tengah panel (tanpa celah kosong) */
    #tradingChartCard .fin-ring-row {
        justify-content: center;
        gap: 1.6rem;
        padding: 0.25rem 0;
    }

    #tradingChartCard .fin-ring-legend {
        flex: 0 1 auto;
        min-width: 170px;
        gap: 0.6rem;
    }

    #tradingChartCard .fin-leg {
        gap: 0.6rem;
    }

    #tradingChartCard .fin-leg em {
        margin-left: 0.9rem;
        min-width: 2.6rem;
        text-align: right;
    }

    /* === Kartu mini (divisi, aktivitas, daily cash): kaca, ringkas, seragam dengan grafik utama === */
    .dash-mini-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .dash-mini {
        min-width: 0;
        padding: 0.75rem 0.9rem;
        border-radius: 16px;
        background: var(--glass-card);
        border: 1px solid var(--glass-edge);
        box-shadow: var(--glass-shadow), var(--glass-glow);
        backdrop-filter: blur(18px) saturate(160%);
        -webkit-backdrop-filter: blur(18px) saturate(160%);
    }

    .dash-mini-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        margin-bottom: 0.6rem;
    }

    .dash-mini-title {
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.72rem !important;
        font-weight: 700;
        letter-spacing: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .dash-mini-dot {
        display: inline-block;
        width: 7px;
        height: 7px;
        border-radius: 2px;
        flex-shrink: 0;
    }

    .dash-mini-tag {
        font-size: 0.6rem;
        font-weight: 600;
        color: var(--text-muted);
        white-space: nowrap;
    }

    .dash-mini-month {
        height: 26px;
        max-width: 128px;
        padding: 0 0.45rem;
        font-size: 0.62rem;
        font-weight: 600;
        font-family: inherit;
        border-radius: 8px;
        border: 1px solid var(--glass-edge);
        background: var(--glass-tile);
        color: var(--text-primary);
        outline: none;
    }

    .dash-mini-link {
        font-size: 0.64rem;
        font-weight: 700;
        padding: 0.25rem 0.6rem;
        border-radius: 7px;
        background: #1e3a8a;
        color: #fff !important;
        -webkit-text-fill-color: #fff;
        text-decoration: none;
        white-space: nowrap;
    }

    .dash-mini-link:hover {
        background: #1d4ed8;
    }

    .mini-pos { color: #059669 !important; }
    .mini-neg { color: #dc2626 !important; }

    /* Ring divisi + legenda */
    .dv-body {
        display: flex;
        align-items: center;
        gap: 0.9rem;
        min-height: 128px;
    }

    .dv-ring {
        position: relative;
        width: 112px;
        height: 112px;
        flex-shrink: 0;
    }

    .dv-ring-center {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        pointer-events: none;
        text-align: center;
    }

    .dv-ring-center b {
        font-size: 0.8rem;
        font-weight: 800;
        color: var(--text-primary);
        font-variant-numeric: tabular-nums;
        letter-spacing: -0.01em;
    }

    .dv-ring-center small {
        max-width: 72px;
        font-size: 0.54rem;
        font-weight: 600;
        color: var(--text-muted);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .dv-legend {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 0.3rem;
    }

    .dv-row {
        display: grid;
        grid-template-columns: 8px minmax(0, 1fr) auto auto;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.64rem;
        transition: opacity 0.2s ease;
    }

    .dv-row.is-dim {
        opacity: 0.35;
    }

    .dv-row i {
        width: 7px;
        height: 7px;
        border-radius: 2px;
    }

    .dv-row small {
        font-size: 0.64rem;
        font-weight: 600;
        color: var(--text-secondary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .dv-row em {
        font-style: normal;
        font-size: 0.6rem;
        font-weight: 700;
        color: var(--text-muted);
        font-variant-numeric: tabular-nums;
    }

    .dv-row b {
        min-width: 3.6rem;
        text-align: right;
        font-size: 0.64rem;
        font-weight: 700;
        color: var(--text-primary);
        font-variant-numeric: tabular-nums;
    }

    .dv-empty {
        font-size: 0.66rem;
        color: var(--text-muted);
    }

    /* Ringkasan aktivitas */
    .act-list {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
        min-height: 128px;
        justify-content: center;
    }

    .act-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        padding: 0.42rem 0.6rem;
        border-radius: 9px;
        background: var(--glass-tile);
        border: 1px solid var(--glass-edge);
    }

    .act-row small {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.62rem;
        font-weight: 600;
        color: var(--text-secondary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .act-row b {
        font-size: 0.74rem;
        font-weight: 700;
        color: var(--text-primary);
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }

    /* Daily cash */
    .dc-card {
        margin-bottom: 0.75rem;
    }

    .dc-strip {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        border-radius: 11px;
        background: var(--glass-tile);
        border: 1px solid var(--glass-edge);
        padding: 0.45rem 0;
    }

    .dc-item {
        min-width: 0;
        padding: 0.05rem 0.85rem;
        display: flex;
        flex-direction: column;
        gap: 0.1rem;
    }

    .dc-item + .dc-item {
        border-left: 1px solid var(--glass-line);
    }

    .dc-item small {
        font-size: 0.55rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--text-muted);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .dc-item b {
        font-size: 0.8rem;
        font-weight: 700;
        color: var(--text-primary);
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }

    .dc-key b {
        color: #1e3a8a;
    }

    body[data-theme="dark"] .dc-key b {
        color: #93c5fd;
    }

    .dc-note {
        margin-top: 0.4rem;
        font-size: 0.56rem;
        color: var(--text-muted);
    }

    @media (max-width: 1100px) {
        .dash-mini-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 760px) {
        .dash-mini-grid {
            grid-template-columns: 1fr;
        }

        .dc-strip {
            grid-template-columns: 1fr 1fr;
            row-gap: 0.45rem;
        }

        .dc-item + .dc-item {
            border-left: none;
        }

        .dc-item:nth-child(even) {
            border-left: 1px solid var(--glass-line);
        }
    }

    /* Footer bar */
    .chart-footer-bar {
        padding: 0.75rem 1.1rem 0.95rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-top: 1px solid var(--chart-wrap-border);
        background: rgba(148, 163, 184, 0.02);
        gap: 0.6rem;
        flex-wrap: wrap;
    }

    .chart-period-display {
        font-size: 0.65rem;
        color: var(--text-muted);
        font-weight: 700;
        letter-spacing: 0.02em;
    }

    .chart-legend-wrap {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .chart-legend-item {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.65rem;
        color: var(--text-muted);
        font-weight: 700;
        padding: 0.32rem 0.58rem;
        border-radius: 999px;
        background: rgba(148, 163, 184, 0.1);
        border: 1px solid rgba(148, 163, 184, 0.18);
    }

    .chart-legend-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    @media (max-width: 860px) {
        .chart-head-wrap {
            padding: 1rem 1rem 0;
        }

        .chart-main-title {
            font-size: 0.9rem;
        }

        .chart-sub-title {
            display: none;
        }

        .chart-summary-grid {
            grid-template-columns: 1fr;
            gap: 0.5rem;
        }

        .chart-canvas-inner {
            height: 250px;
            padding: 0.45rem 0.5rem 0.2rem;
        }

        .chart-main-container {
            flex-direction: column;
            margin: 0 0.7rem;
        }

        .chart-canvas-left {
            flex: 0 0 auto;
        }

        .chart-pie-section {
            flex-direction: column;
        }

        .chart-pie-container {
            min-height: 150px;
        }

        .chart-pie-container canvas {
            max-width: 120px;
            max-height: 120px;
        }

        .chart-filter-input {
            max-width: 110px;
        }
    }

    /* Card hover effects for operational section */
    div[style*="grid-template-columns: repeat(4"]>div {
        cursor: pointer;
    }

    div[style*="grid-template-columns: repeat(4"]>div:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08) !important;
        border-color: rgba(<?php echo $cAccentRgb; ?>, 0.15) !important;
    }

    div[style*="grid-template-columns: repeat(4"]>div:hover .card-top-bar {
        opacity: 1 !important;
    }

    /* Dashboard Month Selector */
    .dashboard-month-selector {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
        padding: 1rem 1.25rem;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    .dashboard-month-selector label {
        font-size: 0.85rem;
        font-weight: 600;
        color: #374151;
        margin: 0;
    }

    .dashboard-month-selector select {
        background: #fff;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        padding: 0.5rem 0.75rem;
        font-size: 0.85rem;
        color: #1f2937;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .dashboard-month-selector select:hover {
        border-color: var(--primary-color);
        box-shadow: 0 2px 6px rgba(<?php echo $cAccentRgb; ?>, 0.1);
    }

    .dashboard-month-selector select:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(<?php echo $cAccentRgb; ?>, 0.1);
    }

    .dashboard-month-selector button {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 0.5rem 1rem;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        box-shadow: 0 2px 6px rgba(<?php echo $cAccentRgb; ?>, 0.25);
    }

    .dashboard-month-selector button:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(<?php echo $cAccentRgb; ?>, 0.35);
    }
</style>

<?php if (!$isCQC): ?>
    <!-- DASHBOARD MONTH SELECTOR -->
    <div class="dashboard-month-selector">
        <form method="GET" style="display: flex; align-items: center; gap: 0.75rem; margin: 0;">
            <label for="dashboardMonthSelect">Filter Period:</label>
            <select name="dashboard_month" id="dashboardMonthSelect" onchange="this.form.submit();">
                <?php
                $currentMonth = $selected_dashboard_month;
                for ($m = 1; $m <= 12; $m++) {
                    $monthVal = sprintf('%04d-%02d', $selected_dashboard_year, $m);
                    $selected = ($monthVal == $selected_dashboard_month) ? 'selected' : '';
                    echo "<option value=\"$monthVal\" $selected>" . $monthNames[$m - 1] . " " . $selected_dashboard_year . "</option>";
                }
                ?>
            </select>
            <select name="dashboard_year" id="dashboardYearSelect" onchange="updateDashboardYear(this.value);">
                <?php
                $currentYear = date('Y');
                for ($y = $currentYear; $y >= $currentYear - 5; $y--) {
                    $selected = ($y == $selected_dashboard_year) ? 'selected' : '';
                    echo "<option value=\"$y\" $selected>$y</option>";
                }
                ?>
            </select>
        </form>
        <span style="font-size: 0.75rem; color: #9ca3af; margin-left: 0.5rem;">
            Showing data for: <strong><?php echo $monthNames[(int)date('m', strtotime($selected_dashboard_month . '-01')) - 1] . ' ' . $selected_dashboard_year; ?></strong>
        </span>
    </div>

    <!-- Charts & Data - 3 Pie Charts -->
<?php endif; // !$isCQC - end kas operasional + charts hide 
?>

<?php if ($isCQC): ?>
    <!-- CQC Transaction Summary Title -->
    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
        <div style="width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, #f0b429, #d4960d); display: flex; align-items: center; justify-content: center;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
            </svg>
        </div>
        <div>
            <div style="font-size: 1.1rem; font-weight: 800; color: #0d1f3c;">CQC Transaction Summary</div>
            <div style="font-size: 0.75rem; color: #6b7280;">Main Bank & Petty Cash Overview</div>
        </div>
    </div>

    <!-- CQC Main Bank Summary -->
    <div style="background: #fff; border-radius: 16px; border: 1px solid #e5e7eb; padding: 1.25rem; margin-bottom: 1.5rem; border-left: 4px solid #3b82f6; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
            <div style="width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, #3b82f6, #2563eb); display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">🏦</div>
            <div>
                <div style="font-size: 1rem; font-weight: 700; color: #0d1f3c;">Main Bank Account</div>
                <div style="font-size: 0.75rem; color: #6b7280;">Primary fund from invoice payments • Source for operations</div>
            </div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem;">
            <div style="background: linear-gradient(135deg, #ecfdf5, #d1fae5); padding: 1rem; border-radius: 12px; border: 1px solid #a7f3d0;">
                <div style="font-size: 0.7rem; font-weight: 700; color: #047857; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.35rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="7 13 12 8 17 13" />
                        <line x1="12" y1="8" x2="12" y2="20" />
                    </svg>
                    Invoice Income
                </div>
                <div style="font-size: 1.35rem; font-weight: 800; color: #065f46;"><?php echo formatCurrency($totalIncome ?? 0); ?></div>
                <div style="font-size: 0.7rem; color: #059669; margin-top: 0.25rem;">Total invoice payments this month</div>
            </div>
            <div style="background: linear-gradient(135deg, #fef2f2, #fee2e2); padding: 1rem; border-radius: 12px; border: 1px solid #fecaca;">
                <div style="font-size: 0.7rem; font-weight: 700; color: #dc2626; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.35rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="17 11 12 16 7 11" />
                        <line x1="12" y1="16" x2="12" y2="4" />
                    </svg>
                    Bank Expenses
                </div>
                <div id="expenseFromBank" style="font-size: 1.35rem; font-weight: 800; color: #991b1b;"><?php echo formatCurrency($cqcExpenseFromBank ?? 0); ?></div>
                <div style="font-size: 0.7rem; color: #dc2626; margin-top: 0.25rem; display: flex; align-items: center; gap: 0.25rem;">
                    <span style="background: #fef3c7; color: #d97706; padding: 0.1rem 0.35rem; border-radius: 4px; font-weight: 600;">+ <?php echo formatCurrency($cqcPettyCashTransfers ?? 0); ?></span>
                    <span>to Petty Cash</span>
                </div>
            </div>
            <div style="background: linear-gradient(135deg, #eff6ff, #dbeafe); padding: 1rem; border-radius: 12px; border: 1px solid #bfdbfe;">
                <div style="font-size: 0.7rem; font-weight: 700; color: #1d4ed8; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.35rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="1" y="4" width="22" height="16" rx="2" />
                        <line x1="1" y1="10" x2="23" y2="10" />
                    </svg>
                    Bank Balance
                </div>
                <div id="dashboardBankBalance" style="font-size: 1.35rem; font-weight: 800; color: <?php echo ($cqcBankBalance ?? 0) >= 0 ? '#1e40af' : '#dc2626'; ?>;"><?php echo formatCurrency($cqcBankBalance ?? 0); ?></div>
                <div style="font-size: 0.7rem; color: #3b82f6; margin-top: 0.25rem;">Current bank balance</div>
            </div>
        </div>

        <!-- Recent Bank Expenses + Transfers -->
        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #f3f4f6;">
            <div style="font-size: 0.9rem; font-weight: 700; color: #6b7280; margin-bottom: 0.75rem;">📋 Recent Bank Transactions</div>
            <?php
            // Get recent expenses from Bank + Transfers to Petty Cash
            $recentBankExpenses = [];
            if ($bankAccountId > 0 || $pettyCashAccountId > 0) {
                // Query expenses from bank + transfers to petty cash
                $recentBankExpenses = $db->fetchAll(
                    "(SELECT cb.description, cb.amount, cb.transaction_date, c.category_name as category, 'expense' as record_type
                 FROM cash_book cb
                 LEFT JOIN categories c ON cb.category_id = c.id
                 WHERE cb.transaction_type = 'expense' 
                 AND cb.cash_account_id = ?)
                UNION ALL
                (SELECT cb.description, cb.amount, cb.transaction_date, 'Transfer' as category, 'transfer' as record_type
                 FROM cash_book cb
                 WHERE cb.transaction_type = 'income' 
                 AND cb.cash_account_id = ?
                 AND cb.description LIKE '%Transfer%')
                ORDER BY transaction_date DESC
                LIMIT 5",
                    [$bankAccountId, $pettyCashAccountId]
                );
            }
            ?>
            <?php if (!empty($recentBankExpenses)): ?>
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <?php foreach ($recentBankExpenses as $exp):
                        $isTransfer = ($exp['record_type'] ?? '') === 'transfer';
                        $bgColor = $isTransfer ? '#eff6ff' : '#fef2f2';
                        $textColor = $isTransfer ? '#2563eb' : '#dc2626';
                        $icon = $isTransfer ? '➡️' : '-';
                    ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.65rem 0.85rem; background: <?php echo $bgColor; ?>; border-radius: 8px;">
                            <div>
                                <div style="font-size: 0.95rem; font-weight: 600; color: #374151;">
                                    <?php if ($isTransfer): ?><span style="background: #dbeafe; padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.75rem; color: #1d4ed8; margin-right: 0.4rem;">TRANSFER</span><?php endif; ?>
                                    <?php echo htmlspecialchars(preg_replace('/\[.*?\]\s*/', '', $exp['description'])); ?>
                                </div>
                                <div style="font-size: 0.8rem; color: #9ca3af;"><?php echo date('d M', strtotime($exp['transaction_date'])); ?> • <?php echo htmlspecialchars($exp['category'] ?? 'Uncategorized'); ?></div>
                            </div>
                            <div style="font-size: 1rem; font-weight: 700; color: <?php echo $textColor; ?>;"><?php echo $icon; ?><?php echo formatCurrency($exp['amount']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="padding: 1rem; text-align: center; color: #9ca3af; font-size: 0.8rem;">No bank transactions yet</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- CQC Petty Cash Summary -->
    <div style="background: #fff; border-radius: 16px; border: 1px solid #e5e7eb; padding: 1.25rem; margin-bottom: 1.5rem; border-left: 4px solid #f0b429; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
            <div style="width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, #fbbf24, #f59e0b); display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">💰</div>
            <div>
                <div style="font-size: 1rem; font-weight: 700; color: #0d1f3c;">Petty Cash</div>
                <div style="font-size: 0.75rem; color: #6b7280;">Operational cash for office & projects • Separate wallet from invoice</div>
            </div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem;">
            <div style="background: linear-gradient(135deg, #fffbeb, #fef3c7); padding: 1rem; border-radius: 12px; border: 1px solid #fde68a;">
                <div style="font-size: 0.7rem; font-weight: 700; color: #92400e; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.35rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="7 13 12 8 17 13" />
                        <line x1="12" y1="8" x2="12" y2="20" />
                    </svg>
                    Transfer In
                </div>
                <div style="font-size: 1.35rem; font-weight: 800; color: #78350f;"><?php echo formatCurrency($cqcPettyCashTransfers ?? 0); ?></div>
                <div style="font-size: 0.7rem; color: #a16207; margin-top: 0.25rem;">From main bank to petty cash</div>
            </div>
            <div style="background: linear-gradient(135deg, #fef2f2, #fee2e2); padding: 1rem; border-radius: 12px; border: 1px solid #fecaca;">
                <div style="font-size: 0.7rem; font-weight: 700; color: #dc2626; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.35rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="17 11 12 16 7 11" />
                        <line x1="12" y1="16" x2="12" y2="4" />
                    </svg>
                    Petty Cash Spent
                </div>
                <div style="font-size: 1.35rem; font-weight: 800; color: #991b1b;"><?php echo formatCurrency(($cqcPettyCashTransfers ?? 0) - ($cqcPettyCashBalance ?? 0)); ?></div>
                <div style="font-size: 0.7rem; color: #dc2626; margin-top: 0.25rem;">Office & project expenses</div>
            </div>
            <div style="background: linear-gradient(135deg, #eff6ff, #dbeafe); padding: 1rem; border-radius: 12px; border: 1px solid #bfdbfe;">
                <div style="font-size: 0.7rem; font-weight: 700; color: #1d4ed8; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.35rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="1" y="4" width="22" height="16" rx="2" />
                        <line x1="1" y1="10" x2="23" y2="10" />
                    </svg>
                    Petty Cash Balance
                </div>
                <div id="dashboardPettyCashBalance" style="font-size: 1.35rem; font-weight: 800; color: <?php echo ($cqcPettyCashBalance ?? 0) >= 0 ? '#1e40af' : '#dc2626'; ?>;"><?php echo formatCurrency($cqcPettyCashBalance ?? 0); ?></div>
                <div style="font-size: 0.7rem; color: #3b82f6; margin-top: 0.25rem;">Current petty cash balance</div>
            </div>
        </div>

        <!-- Recent Petty Cash Expenses -->
        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #f3f4f6;">
            <div style="font-size: 0.9rem; font-weight: 700; color: #6b7280; margin-bottom: 0.75rem;">📋 Recent Petty Cash Expenses</div>
            <?php
            // Get recent expenses from Petty Cash
            $recentPettyExpenses = [];
            if ($pettyCashAccountId > 0) {
                $recentPettyExpenses = $db->fetchAll(
                    "SELECT cb.description, cb.amount, cb.transaction_date, c.category_name as category
                 FROM cash_book cb
                 LEFT JOIN categories c ON cb.category_id = c.id
                 WHERE cb.transaction_type = 'expense' 
                 AND cb.cash_account_id = ?
                 ORDER BY cb.transaction_date DESC, cb.id DESC
                 LIMIT 5",
                    [$pettyCashAccountId]
                );
            }
            ?>
            <?php if (!empty($recentPettyExpenses)): ?>
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <?php foreach ($recentPettyExpenses as $exp): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.65rem 0.85rem; background: #fef2f2; border-radius: 8px;">
                            <div>
                                <div style="font-size: 0.95rem; font-weight: 600; color: #374151;"><?php echo htmlspecialchars($exp['description']); ?></div>
                                <div style="font-size: 0.8rem; color: #9ca3af;"><?php echo date('d M', strtotime($exp['transaction_date'])); ?> • <?php echo htmlspecialchars($exp['category'] ?? 'Uncategorized'); ?></div>
                            </div>
                            <div style="font-size: 1rem; font-weight: 700; color: #dc2626;">-<?php echo formatCurrency($exp['amount']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="padding: 1rem; text-align: center; color: #9ca3af; font-size: 0.8rem;">No petty cash expenses yet</div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($isCQC): ?>
    <!-- ============================================ -->
    <!-- CQC PROJECT OVERVIEW - PIE CHARTS -->
    <!-- ============================================ -->
    <style>
        .cqc-project-card {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e5e7eb;
            padding: 1.25rem;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .cqc-project-card:hover {
            box-shadow: 0 8px 24px rgba(13, 31, 60, 0.12);
            transform: translateY(-2px);
        }

        .cqc-chart-container {
            position: relative;
            width: 160px;
            height: 160px;
            margin: 0 auto 1rem;
        }

        .cqc-center-pct {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }

        .cqc-center-pct .pct-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: #0d1f3c;
        }

        .cqc-center-pct .pct-label {
            font-size: 0.65rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .cqc-stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .cqc-stat-row:last-child {
            border-bottom: none;
        }

        .cqc-stat-label {
            font-size: 0.75rem;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        .cqc-stat-value {
            font-size: 0.85rem;
            font-weight: 700;
            font-family: 'Monaco', 'Courier New', monospace;
        }

        .cqc-status-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .cqc-status-planning {
            background: #eef2ff;
            color: #4a6cf7;
        }

        .cqc-status-procurement {
            background: #fef3c7;
            color: #d97706;
        }

        .cqc-status-installation {
            background: #dbeafe;
            color: #2563eb;
        }

        .cqc-status-testing {
            background: #fce7f3;
            color: #db2777;
        }

        .cqc-status-completed {
            background: #d1fae5;
            color: #059669;
        }

        .cqc-status-on_hold {
            background: #f3f4f6;
            color: #6b7280;
        }
    </style>

    <?php if (!empty($cqcProjects)): ?>
        <!-- CQC Project Monitoring - Clean 2027 Style -->
        <div class="fade-in" style="margin: 12px 0; padding: 18px; background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); border: 1px solid #e2e8f0;">
            <!-- Header -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 32px; height: 32px; background: #f8fafc; border-radius: 8px; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="5" />
                            <path d="m12 1v2m0 18v2m4.22-18.36 1.42 1.42M4.93 19.07l1.41 1.42m12.73 0 1.41-1.42M4.93 4.93l1.42 1.42M1 12h2m18 0h2" />
                        </svg>
                    </div>
                    <div>
                        <div style="font-size: 9px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px;">CQC Enjiniring</div>
                        <div style="font-size: 14px; font-weight: 600; color: #1e293b; letter-spacing: -0.2px;">Pencapaian & Keuangan</div>
                    </div>
                </div>
                <a href="modules/cqc-projects/" style="padding: 6px 12px; background: #1e293b; color: white; border-radius: 6px; font-size: 11px; font-weight: 600; text-decoration: none; transition: all 0.15s;" onmouseover="this.style.background='#0f172a';" onmouseout="this.style.background='#1e293b';">
                    Kelola →
                </a>
            </div>

            <!-- Summary Stats - Clean -->
            <?php
            $totalBudget = array_sum(array_column($cqcProjects, 'budget_idr'));
            $totalSpent = array_sum(array_column($cqcProjects, 'spent_idr'));
            $totalRemaining = $totalBudget - $totalSpent;
            $avgProgress = count($cqcProjects) > 0 ? round(array_sum(array_column($cqcProjects, 'progress_percentage')) / count($cqcProjects)) : 0;
            $budgetUsedPct = $totalBudget > 0 ? round(($totalSpent / $totalBudget) * 100) : 0;
            ?>
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 16px;">
                <div style="text-align: center; padding: 12px 8px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <div style="font-size: 20px; font-weight: 700; color: #1e293b; line-height: 1;"><?php echo count($cqcProjects); ?></div>
                    <div style="font-size: 10px; color: #64748b; font-weight: 600; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.3px;">Proyek</div>
                </div>
                <div style="text-align: center; padding: 12px 8px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <div style="font-size: 13px; font-weight: 700; color: #1e293b;">Rp <?php echo number_format($totalBudget / 1000000000, 2); ?>M</div>
                    <div style="font-size: 10px; color: #64748b; font-weight: 600; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.3px;">Budget</div>
                </div>
                <div style="text-align: center; padding: 12px 8px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <div style="font-size: 13px; font-weight: 700; color: #ef4444;">Rp <?php echo number_format($totalSpent / 1000000, 0); ?>jt</div>
                    <div style="font-size: 10px; color: #64748b; font-weight: 600; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.3px;">Terpakai <?php echo $budgetUsedPct; ?>%</div>
                </div>
                <div style="text-align: center; padding: 12px 8px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <div style="font-size: 20px; font-weight: 700; color: #10b981; line-height: 1;"><?php echo $avgProgress; ?>%</div>
                    <div style="font-size: 10px; color: #64748b; font-weight: 600; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.3px;">Progress</div>
                </div>
            </div>

            <!-- Project Cards Grid - Clean -->
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; margin-bottom: 16px;">
                <?php foreach ($cqcProjects as $idx => $proj):
                    $budget = floatval($proj['budget_idr'] ?? 0);
                    $spent = floatval($proj['spent_idr'] ?? 0);
                    $remaining = $budget - $spent;
                    $progress = intval($proj['progress_percentage'] ?? 0);
                    $spentPct = $budget > 0 ? round(($spent / $budget) * 100, 1) : 0;
                    $statusLabels = ['planning' => 'Planning', 'procurement' => 'Procurement', 'installation' => 'Installation', 'testing' => 'Testing', 'completed' => 'Completed', 'on_hold' => 'On Hold'];
                    $statusLabel = $statusLabels[$proj['status']] ?? ucfirst($proj['status']);
                    $statusColors = ['planning' => ['#f1f5f9', '#475569'], 'procurement' => ['#fef3c7', '#92400e'], 'installation' => ['#d1fae5', '#065f46'], 'testing' => ['#e0f2fe', '#0369a1'], 'completed' => ['#dcfce7', '#166534'], 'on_hold' => ['#fee2e2', '#991b1b']];
                    $statusBg = $statusColors[$proj['status']][0] ?? '#f1f5f9';
                    $statusText = $statusColors[$proj['status']][1] ?? '#475569';
                    $kwp = floatval($proj['solar_capacity_kwp'] ?? 0);
                    $clientName = $proj['client_name'] ?? '';
                ?>
                    <div class="cqc-project-card" style="background: #fff; border-radius: 10px; padding: 14px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04); cursor: pointer; transition: all 0.15s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)'; this.style.borderColor='#cbd5e1';" onmouseout="this.style.boxShadow='0 1px 3px rgba(0,0,0,0.04)'; this.style.borderColor='#e2e8f0';">

                        <!-- Header -->
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-size: 10px; color: #64748b; font-weight: 600; letter-spacing: 0.5px;"><?php echo htmlspecialchars($proj['project_code']); ?></div>
                                <div style="font-size: 13px; font-weight: 600; color: #1e293b; margin-top: 2px; line-height: 1.3;"><?php echo htmlspecialchars($proj['project_name']); ?></div>
                                <?php if ($clientName): ?>
                                    <div style="font-size: 11px; color: #94a3b8; margin-top: 2px;"><?php echo htmlspecialchars($clientName); ?></div>
                                <?php endif; ?>
                            </div>
                            <span style="padding: 4px 8px; border-radius: 5px; font-size: 10px; font-weight: 600; background: <?php echo $statusBg; ?>; color: <?php echo $statusText; ?>; white-space: nowrap;"><?php echo $statusLabel; ?></span>
                        </div>

                        <!-- Main Content: Progress + Financial -->
                        <div style="display: flex; gap: 12px; align-items: center; margin-bottom: 10px;">

                            <!-- Progress Circle - Simple -->
                            <div style="flex-shrink: 0; text-align: center;">
                                <div style="position: relative; width: 60px; height: 60px;">
                                    <canvas id="cqcPie<?php echo $idx; ?>"></canvas>
                                    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                                        <div style="font-size: 14px; font-weight: 700; color: #1e293b; line-height: 1;"><?php echo $progress; ?>%</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Financial - Clean -->
                            <div style="flex: 1; min-width: 0;">
                                <!-- Budget -->
                                <div style="margin-bottom: 6px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 3px;">
                                        <span style="font-size: 10px; color: #64748b; font-weight: 500;">Budget</span>
                                        <span style="font-size: 12px; font-weight: 600; color: #1e293b;"><?php echo number_format($budget / 1000000, 0); ?>jt</span>
                                    </div>
                                    <div style="height: 4px; background: #e2e8f0; border-radius: 2px; overflow: hidden;">
                                        <div style="width: <?php echo min($spentPct, 100); ?>%; height: 100%; background: <?php echo $spentPct > 90 ? '#ef4444' : '#0ea5e9'; ?>; border-radius: 2px;"></div>
                                    </div>
                                </div>

                                <!-- Spent & Sisa -->
                                <div style="display: flex; gap: 12px; font-size: 11px;">
                                    <div>
                                        <span style="color: #94a3b8;">Terpakai:</span>
                                        <span style="color: #ef4444; font-weight: 600; margin-left: 2px;"><?php echo number_format($spent / 1000000, 1); ?>jt</span>
                                    </div>
                                    <div>
                                        <span style="color: #94a3b8;">Sisa:</span>
                                        <span style="color: <?php echo $remaining >= 0 ? '#10b981' : '#ef4444'; ?>; font-weight: 600; margin-left: 2px;"><?php echo number_format($remaining / 1000000, 1); ?>jt</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Footer Row -->
                        <div style="display: flex; align-items: center; justify-content: space-between; padding-top: 10px; border-top: 1px solid #f1f5f9;">
                            <div style="display: flex; gap: 6px;">
                                <?php if ($kwp > 0): ?>
                                    <span style="padding: 3px 8px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 10px; font-weight: 500; color: #64748b;">
                                        ⚡ <?php echo number_format($kwp, 1); ?> kWp
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- View Button -->
                            <div style="display: flex; gap: 6px;">
                                <a href="modules/cqc-projects/detail.php?id=<?php echo $proj['id']; ?>"
                                    style="display: flex; align-items: center; gap: 4px; padding: 5px 12px; background: #fff; color: #475569; border-radius: 6px; text-decoration: none; font-size: 11px; font-weight: 600; border: 1px solid #e2e8f0; transition: all 0.15s;"
                                    onmouseover="this.style.background='#f8fafc'; this.style.borderColor='#cbd5e1';"
                                    onmouseout="this.style.background='#fff'; this.style.borderColor='#e2e8f0';">
                                    View
                                </a>
                                <?php if ($proj['status'] !== 'completed' && $proj['status'] !== 'planning'): ?>
                                    <a href="modules/cqc-projects/dashboard.php?action=finish&id=<?php echo $proj['id']; ?>"
                                        style="display: flex; align-items: center; gap: 4px; padding: 5px 12px; background: #059669; color: #fff; border-radius: 6px; text-decoration: none; font-size: 11px; font-weight: 600; border: 1px solid #059669; transition: all 0.15s;"
                                        onmouseover="this.style.background='#047857';"
                                        onmouseout="this.style.background='#059669';"
                                        onclick="return confirm('Selesaikan proyek <?php echo htmlspecialchars($proj['project_name']); ?>?')">
                                        ✓ Finish
                                    </a>
                                <?php elseif ($proj['status'] === 'completed'): ?>
                                    <a href="modules/cqc-projects/report.php?id=<?php echo $proj['id']; ?>"
                                        style="display: flex; align-items: center; gap: 4px; padding: 5px 12px; background: #2563eb; color: #fff; border-radius: 6px; text-decoration: none; font-size: 11px; font-weight: 600; border: 1px solid #2563eb; transition: all 0.15s;"
                                        onmouseover="this.style.background='#1d4ed8';"
                                        onmouseout="this.style.background='#2563eb';">
                                        📊 Report
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Overall Charts - Clean -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div style="background: #fff; border-radius: 10px; padding: 14px; border: 1px solid #e2e8f0;">
                    <h3 style="font-size: 12px; font-weight: 600; color: #1e293b; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                        <span style="width: 24px; height: 24px; background: #f8fafc; border-radius: 6px; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2">
                                <path d="M21.21 15.89A10 10 0 1 1 8 2.83" />
                                <path d="M22 12A10 10 0 0 0 12 2v10z" />
                            </svg>
                        </span>
                        Distribusi Budget
                    </h3>
                    <div style="position: relative; height: 180px;">
                        <canvas id="cqcBudgetPie"></canvas>
                    </div>
                </div>
                <div style="background: #fff; border-radius: 10px; padding: 14px; border: 1px solid #e2e8f0;">
                    <h3 style="font-size: 12px; font-weight: 600; color: #1e293b; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                        <span style="width: 24px; height: 24px; background: #f8fafc; border-radius: 6px; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                                <line x1="16" y1="2" x2="16" y2="6" />
                                <line x1="8" y1="2" x2="8" y2="6" />
                                <line x1="3" y1="10" x2="21" y2="10" />
                            </svg>
                        </span>
                        Budget vs Spent
                    </h3>
                    <div style="position: relative; height: 180px;">
                        <canvas id="cqcBudgetVsSpentChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Recent Transactions from Buku Kas -->
            <div style="background: #fff; border-radius: 10px; padding: 16px; border: 1px solid #e2e8f0; margin-top: 12px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                    <h3 style="font-size: 13px; font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 8px; margin: 0;">
                        <span style="width: 28px; height: 28px; background: linear-gradient(135deg, #f0b429, #d4960d); border-radius: 7px; display: flex; align-items: center; justify-content: center; font-size: 14px;">📋</span>
                        10 Transaksi Terakhir
                    </h3>
                    <a href="modules/cashbook/" style="font-size: 11px; color: #f0b429; text-decoration: none; font-weight: 600; padding: 4px 10px; border: 1px solid #f0b429; border-radius: 6px; transition: all 0.15s;"
                        onmouseover="this.style.background='#f0b429'; this.style.color='#fff';" onmouseout="this.style.background='transparent'; this.style.color='#f0b429';">
                        Lihat Semua →
                    </a>
                </div>
                <?php if (!empty($recentCashbook)): ?>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                            <thead>
                                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                                    <th style="padding: 8px 10px; text-align: left; color: #64748b; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px;">Tanggal</th>
                                    <th style="padding: 8px 10px; text-align: left; color: #64748b; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px;">Keterangan</th>
                                    <th style="padding: 8px 10px; text-align: left; color: #64748b; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px;">Kategori</th>
                                    <th style="padding: 8px 10px; text-align: left; color: #64748b; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px;">Tipe</th>
                                    <th style="padding: 8px 10px; text-align: right; color: #64748b; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px;">Jumlah</th>
                                    <th style="padding: 8px 10px; text-align: left; color: #64748b; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px;">Oleh</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentCashbook as $i => $tx):
                                    $isIncome = $tx['transaction_type'] === 'income';
                                    $rowBg = $i % 2 === 0 ? '#fff' : '#fafbfc';
                                    // Clean description: remove [CQC_PROJECT:X] and [OPERATIONAL_OFFICE] tags for display
                                    $cleanDesc = preg_replace('/\[CQC_PROJECT:\d+\]\s*/', '', $tx['description'] ?? '');
                                    $cleanDesc = preg_replace('/\[OPERATIONAL_OFFICE\]\s*/', '', $cleanDesc);
                                    // Detect if project expense
                                    $isProject = preg_match('/\[CQC_PROJECT:(\d+)\]/', $tx['description'] ?? '', $projMatch);
                                    $sourceLabel = '';
                                    if ($isIncome && isset($tx['source_type'])) {
                                        if ($tx['source_type'] === 'owner_fund') $sourceLabel = 'Owner Fund';
                                        elseif ($tx['source_type'] === 'invoice_payment') $sourceLabel = 'Invoice';
                                    }
                                    if (!$isIncome && isset($tx['source_type']) && $tx['source_type'] === 'owner_project') $sourceLabel = 'Proyek';
                                    elseif (!$isIncome && $isProject) $sourceLabel = 'Proyek';
                                    elseif (!$isIncome && !$isProject) $sourceLabel = 'Office';
                                ?>
                                    <tr style="background: <?php echo $rowBg; ?>; border-bottom: 1px solid #f1f5f9; transition: background 0.1s;" onmouseover="this.style.background='#fffbeb'" onmouseout="this.style.background='<?php echo $rowBg; ?>'">
                                        <td style="padding: 9px 10px; white-space: nowrap;">
                                            <div style="font-weight: 500; color: #1e293b;"><?php echo date('d M Y', strtotime($tx['transaction_date'])); ?></div>
                                            <?php if (!empty($tx['transaction_time'])): ?>
                                                <div style="font-size: 10px; color: #94a3b8;"><?php echo date('H:i', strtotime($tx['transaction_time'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 9px 10px; max-width: 250px;">
                                            <div style="color: #1e293b; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($cleanDesc ?: '-'); ?></div>
                                            <?php if ($sourceLabel): ?>
                                                <span style="font-size: 10px; padding: 1px 6px; border-radius: 3px; font-weight: 500;
                                <?php if ($sourceLabel === 'Owner Fund'): ?>background: #fef3c7; color: #92400e;
                                <?php elseif ($sourceLabel === 'Invoice'): ?>background: #d1fae5; color: #065f46;
                                <?php elseif ($sourceLabel === 'Proyek'): ?>background: #e0e7ff; color: #3730a3;
                                <?php else: ?>background: #f1f5f9; color: #475569;
                                <?php endif; ?>">
                                                    <?php echo $sourceLabel; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 9px 10px; color: #64748b;"><?php echo htmlspecialchars($tx['category_name']); ?></td>
                                        <td style="padding: 9px 10px;">
                                            <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;
                                <?php echo $isIncome ? 'background: #ecfdf5; color: #059669;' : 'background: #fef2f2; color: #dc2626;'; ?>">
                                                <?php echo $isIncome ? '↓ Masuk' : '↑ Keluar'; ?>
                                            </span>
                                        </td>
                                        <td style="padding: 9px 10px; text-align: right; font-weight: 600; font-family: 'JetBrains Mono', monospace; <?php echo $isIncome ? 'color: #059669;' : 'color: #dc2626;'; ?>">
                                            <?php echo ($isIncome ? '+' : '-') . ' Rp ' . number_format($tx['amount'], 0, ',', '.'); ?>
                                        </td>
                                        <td style="padding: 9px 10px; color: #64748b; font-size: 11px;"><?php echo htmlspecialchars($tx['created_by_name']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 24px; color: #94a3b8;">
                        <div style="font-size: 28px; margin-bottom: 6px;">📭</div>
                        <div style="font-size: 12px;">Belum ada transaksi</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="card fade-in" style="padding: 2rem; text-align: center;">
            <div style="font-size: 3rem; margin-bottom: 0.75rem;">☀️</div>
            <h3 style="font-size: 1rem; color: #0d1f3c; font-weight: 700; margin-bottom: 0.5rem;">Belum Ada Proyek</h3>
            <p style="color: #6b7280; font-size: 0.85rem; margin-bottom: 1rem;">Tambahkan proyek pertama Anda untuk melihat grafik pencapaian.</p>
            <a href="modules/cqc-projects/add.php" style="padding: 0.6rem 1.5rem; background: linear-gradient(135deg, #0d1f3c, #1a3a5c); color: #f0b429; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 0.85rem;">+ Tambah Proyek</a>
        </div>
    <?php endif; ?>
<?php else: // not CQC 
?>

    <?php
    // Ringkasan aktivitas bulan ini (dipakai kartu ke-3)
    $miniActiveDays = count(array_filter($dailyData, function ($d) {
        return $d['income'] > 0 || $d['expense'] > 0;
    }));
    $miniAvgIncome = $miniActiveDays > 0 ? array_sum(array_column($dailyData, 'income')) / $miniActiveDays : 0;
    $miniAvgExpense = $miniActiveDays > 0 ? array_sum(array_column($dailyData, 'expense')) / $miniActiveDays : 0;
    $miniMonthLabel = $monthNames[(int)date('m', strtotime($selected_dashboard_month . '-01')) - 1] . ' ' . $selected_dashboard_year;
    ?>
    <!-- Kartu divisi + aktivitas: gaya kaca seragam dengan grafik utama -->
    <div class="dash-mini-grid">

        <!-- Pemasukan per Divisi -->
        <div class="dash-mini">
            <div class="dash-mini-head">
                <h3 class="dash-mini-title"><span class="dash-mini-dot" style="background: #2563eb;"></span>Pemasukan per Divisi</h3>
                <input type="month" id="divisionIncomeMonth" value="<?php echo $selected_dashboard_month; ?>" class="dash-mini-month" onchange="updateDivisionIncomeChart(this.value)">
            </div>
            <div class="dv-body">
                <div class="dv-ring">
                    <canvas id="divisionPieChart"></canvas>
                    <div class="dv-ring-center"><b id="divisionIncomeCenter">–</b><small id="divisionIncomeCenterLabel">Total</small></div>
                </div>
                <div class="dv-legend" id="divisionIncomeLegend"></div>
            </div>
        </div>

        <!-- Pengeluaran per Divisi -->
        <div class="dash-mini">
            <div class="dash-mini-head">
                <h3 class="dash-mini-title"><span class="dash-mini-dot" style="background: #f97316;"></span>Pengeluaran per Divisi</h3>
                <input type="month" id="expenseCategoryMonth" value="<?php echo $selected_dashboard_month; ?>" class="dash-mini-month" onchange="updateExpenseCategoryChart(this.value)">
            </div>
            <div class="dv-body">
                <div class="dv-ring">
                    <canvas id="expenseCategoryChart"></canvas>
                    <div class="dv-ring-center"><b id="expenseDivisionCenter">–</b><small id="expenseDivisionCenterLabel">Total</small></div>
                </div>
                <div class="dv-legend" id="expenseDivisionLegend"></div>
            </div>
        </div>

        <!-- Ringkasan Aktivitas -->
        <div class="dash-mini">
            <div class="dash-mini-head">
                <h3 class="dash-mini-title"><span class="dash-mini-dot" style="background: #10b981;"></span>Aktivitas Bulan Ini</h3>
                <span class="dash-mini-tag"><?php echo $miniMonthLabel; ?></span>
            </div>
            <div class="act-list">
                <div class="act-row">
                    <small>Hari ada transaksi</small>
                    <b><?php echo $miniActiveDays; ?> hari</b>
                </div>
                <div class="act-row">
                    <small><span class="dash-mini-dot" style="background: #2563eb;"></span>Rata-rata pemasukan / hari</small>
                    <b><?php echo formatCurrency($miniAvgIncome); ?></b>
                </div>
                <div class="act-row">
                    <small><span class="dash-mini-dot" style="background: #f97316;"></span>Rata-rata pengeluaran / hari</small>
                    <b><?php echo formatCurrency($miniAvgExpense); ?></b>
                </div>
                <div class="act-row">
                    <small>Net rata-rata / hari</small>
                    <b class="<?php echo $miniAvgIncome - $miniAvgExpense < 0 ? 'mini-neg' : 'mini-pos'; ?>"><?php echo formatCurrency($miniAvgIncome - $miniAvgExpense); ?></b>
                </div>
            </div>
        </div>
    </div>

    <!-- Daily Cash: satu strip ringkas di bawah kartu divisi -->
    <div class="dash-mini dc-card">
        <div class="dash-mini-head">
            <h3 class="dash-mini-title"><span class="dash-mini-dot" style="background: #1e3a8a;"></span>Daily Cash <span class="dash-mini-tag"><?php echo date('M Y', strtotime($selected_dashboard_month . '-01')); ?></span></h3>
            <a href="modules/owner/owner-capital-monitor.php" class="dash-mini-link">Detail &rarr;</a>
        </div>
        <div class="dc-strip">
            <div class="dc-item">
                <small>Start cash (<?php echo date('M', strtotime($selected_dashboard_month . '-01')); ?>)</small>
                <b><?php echo formatCurrency($startKasHariIni); ?></b>
            </div>
            <div class="dc-item dc-key">
                <small>Cash available</small>
                <b class="<?php echo $dashCashAvailable < 0 ? 'mini-neg' : ''; ?>"><?php echo formatCurrency($dashCashAvailable); ?></b>
            </div>
            <?php if ($guestCashIncome > 0): ?>
                <div class="dc-item">
                    <small>Cash income (tamu)</small>
                    <b class="mini-pos">+<?php echo formatCurrency($guestCashIncome); ?></b>
                </div>
            <?php endif; ?>
            <div class="dc-item">
                <small>Owner transfer</small>
                <b><?php echo formatCurrency($ownerTransferThisMonth); ?></b>
            </div>
            <div class="dc-item">
                <small>Owner + guest</small>
                <b><?php echo formatCurrency($ownerTransferThisMonth + $guestCashIncome); ?></b>
            </div>
        </div>
        <div class="dc-note">
            * Hanya kas fisik (Petty Cash + Modal Owner), tidak termasuk Rekening Bank. Total pengeluaran seluruh akun ada di Buku Kas.
            <?php if ($dashCashAvailable < 0): ?><b class="mini-neg">&nbsp;· Kas minus!</b><?php endif; ?>
        </div>
    </div>
<?php endif; // else not CQC - end charts section 
?>

<?php if (!$isCQC): ?>
    <!-- Top Categories & Top Divisions - Compact -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; margin-bottom: 1rem;">

        <!-- Top Categories Chart -->
        <div class="card">
            <div style="padding: 0.65rem 0 0.4rem 0; border-bottom: 1px solid var(--bg-tertiary);">
                <h3 style="font-size: 0.875rem; color: var(--text-primary); font-weight: 600; display: flex; align-items: center; gap: 0.4rem;">
                    <i data-feather="trending-up" style="width: 16px; height: 16px; color: var(--primary-color);"></i>
                    Top 10 Kategori Transaksi
                </h3>
            </div>
            <div style="position: relative; height: 240px; padding: 0.75rem 0.5rem;">
                <?php if (empty($topCategories)): ?>
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: var(--text-muted);">
                        <i data-feather="inbox" style="width: 40px; height: 40px; margin-bottom: 0.5rem;"></i>
                        <p style="margin: 0; font-size: 0.813rem;">Belum ada data transaksi</p>
                    </div>
                <?php else: ?>
                    <canvas id="topCategoriesChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">

            <!-- Top 5 Divisions -->
            <div class="card">
                <div style="padding: 0.65rem 0 0.4rem 0; border-bottom: 1px solid var(--bg-tertiary);">
                    <h3 style="font-size: 0.875rem; color: var(--text-primary); font-weight: 600; display: flex; align-items: center; gap: 0.4rem;">
                        <i data-feather="award" style="width: 16px; height: 16px; color: var(--primary-color);"></i>
                        Top 5 Divisi
                    </h3>
                </div>

                <?php if (empty($topDivisions)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 1.25rem; font-size: 0.813rem;">Belum ada data</p>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem; padding: 0.5rem 0;">
                        <?php foreach ($topDivisions as $index => $division): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.6rem 0.75rem; background: var(--bg-tertiary); border-radius: var(--radius-md);">
                                <div style="flex: 1;">
                                    <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.15rem; font-size: 0.813rem;">
                                        #<?php echo $index + 1; ?> <?php echo $division['division_name']; ?>
                                    </div>
                                    <div style="font-size: 0.688rem; color: var(--text-muted);">
                                        <span class="text-success">+<?php echo formatCurrency($division['income']); ?></span>
                                        <span style="margin: 0 0.25rem;">•</span>
                                        <span class="text-danger">-<?php echo formatCurrency($division['expense']); ?></span>
                                    </div>
                                </div>
                                <div style="font-weight: 800; font-size: 0.938rem; color: <?php echo $division['net'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                                    <?php echo formatCurrency($division['net']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; // !$isCQC top categories 
    ?>

    <?php if (!$isCQC): ?>
        <!-- Recent Transactions - Full Width -->
        <div class="card">
            <div style="padding: 0.65rem 0 0.4rem 0; border-bottom: 1px solid var(--bg-tertiary); margin-bottom: 0.5rem;">
                <h3 style="font-size: 0.875rem; color: var(--text-primary); font-weight: 600; display: flex; align-items: center; gap: 0.4rem;">
                    <i data-feather="clock" style="width: 16px; height: 16px; color: var(--primary-color);"></i>
                    Transaksi Terakhir
                </h3>
            </div>

            <?php if (empty($recentTransactions)): ?>
                <p style="color: var(--text-muted); text-align: center; padding: 1.25rem; font-size: 0.813rem;">Belum ada transaksi</p>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.4rem; padding: 0.5rem 0;">
                    <?php foreach ($recentTransactions as $trans): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0.65rem; border-bottom: 1px solid var(--bg-tertiary);">
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.1rem; font-size: 0.75rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    <?php echo $trans['division_name']; ?> - <?php echo $trans['category_name']; ?>
                                </div>
                                <div style="font-size: 0.688rem; color: var(--text-muted);">
                                    <?php echo formatDate($trans['transaction_date']); ?>
                                </div>
                            </div>
                            <div style="text-align: right; margin-left: 0.5rem;">
                                <div style="font-weight: 700; font-size: 0.875rem; color: <?php echo $trans['transaction_type'] === 'income' ? 'var(--success)' : 'var(--danger)'; ?>; white-space: nowrap;">
                                    <?php echo $trans['transaction_type'] === 'income' ? '+' : '-'; ?><?php echo formatCurrency($trans['amount']); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div style="margin-top: 0.65rem; text-align: center;">
                <a href="<?php echo BASE_URL; ?>/modules/cashbook/index.php" class="btn btn-secondary btn-sm">
                    Lihat Semua →
                </a>
            </div>
        </div>

    <?php endif; // !$isCQC recent transactions 
    ?>

    <!-- Chart.js Library -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

    <script>
        // Initialize Feather Icons
        feather.replace();

        // ============================================
        // CHART CONFIGURATION
        // ============================================
        Chart.defaults.font.family = "'Inter', sans-serif";

        // Dynamic chart colors based on theme
        function getChartTextColor() {
            const isLight = document.body.getAttribute('data-theme') === 'light';
            // Light theme: dark text, Dark theme: light text
            return isLight ? '#475569' : '#94a3b8';
        }

        function getLegendTextColor() {
            const isLight = document.body.getAttribute('data-theme') === 'light';
            // Light theme: dark text, Dark theme: light text
            return isLight ? '#1e293b' : '#e2e8f0';
        }

        Chart.defaults.color = getChartTextColor();

        // Update chart colors when theme changes
        function updateChartColors() {
            Chart.defaults.color = getChartTextColor();
            // Update all chart instances
            Chart.instances.forEach(chart => {
                if (chart.options.plugins && chart.options.plugins.legend) {
                    chart.options.plugins.legend.labels.color = getLegendTextColor();
                }
                if (chart.options.scales) {
                    if (chart.options.scales.x && chart.options.scales.x.ticks) {
                        chart.options.scales.x.ticks.color = getChartTextColor();
                    }
                    if (chart.options.scales.y && chart.options.scales.y.ticks) {
                        chart.options.scales.y.ticks.color = getChartTextColor();
                    }
                }
                chart.update();
            });
        }

        // Listen for theme changes
        const themeObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'data-theme') {
                    updateChartColors();
                }
            });
        });
        themeObserver.observe(document.body, {
            attributes: true
        });

        // ============================================
        // RING DIVISI - Pemasukan & Pengeluaran per Divisi
        // Ring tipis + legenda (5 teratas, sisanya "Lainnya"); info segmen tampil di tengah saat disorot.
        // ============================================
        <?php if (!$isCQC): ?>
            const DIVISION_PALETTE = ['#2563eb', '#10b981', '#f59e0b', '#8b5cf6', '#06b6d4', '#ec4899', '#f97316', '#84cc16', '#64748b', '#0ea5e9', '#a855f7', '#14b8a6'];

            const divShort = v => {
                const n = Number(v) || 0, a = Math.abs(n), s = n < 0 ? '-' : '';
                const fix = x => (x >= 10 ? x.toFixed(0) : x.toFixed(1).replace(/\.0$/, '')).replace('.', ',');
                if (a >= 1e9) return s + 'Rp ' + fix(a / 1e9) + ' M';
                if (a >= 1e6) return s + 'Rp ' + fix(a / 1e6) + ' jt';
                if (a >= 1e3) return s + 'Rp ' + fix(a / 1e3) + ' rb';
                return s + 'Rp ' + a;
            };
            const divPct = v => (v >= 10 ? Math.round(v) : v.toFixed(1).replace(/\.0$/, '')).toString().replace('.', ',') + '%';

            function makeDivisionRing(canvasId, legendId, centerId, labelId, labels, amounts) {
                const canvas = document.getElementById(canvasId);
                if (!canvas) return null;
                const ring = {
                    chart: null,
                    labels: [],
                    amounts: []
                };
                const centerEl = document.getElementById(centerId);
                const labelEl = document.getElementById(labelId);
                const legendEl = document.getElementById(legendId);

                const total = () => ring.amounts.reduce((a, b) => a + b, 0);

                function focus(index) {
                    const has = ring.amounts.length > 0;
                    if (legendEl) {
                        legendEl.querySelectorAll('.dv-row').forEach(row => {
                            const i = Number(row.dataset.index);
                            row.classList.toggle('is-dim', index !== null && i !== index && !(index >= 5 && i === 5));
                        });
                    }
                    if (!has || index === null) {
                        centerEl.textContent = has ? divShort(total()) : '–';
                        labelEl.textContent = has ? 'Total' : 'Belum ada data';
                        return;
                    }
                    centerEl.textContent = divPct(ring.amounts[index] / (total() || 1) * 100);
                    labelEl.textContent = ring.labels[index];
                }

                function renderLegend() {
                    if (!legendEl) return;
                    if (!ring.amounts.length) {
                        legendEl.innerHTML = '<div class="dv-empty">Belum ada data di bulan ini</div>';
                        return;
                    }
                    const sum = total() || 1;
                    const order = ring.amounts.map((v, i) => i).sort((a, b) => ring.amounts[b] - ring.amounts[a]);
                    const top = order.slice(0, 5);
                    const rest = order.slice(5);
                    const row = (idx, color, name, value) =>
                        '<div class="dv-row" data-index="' + idx + '"><i style="background:' + color + '"></i>' +
                        '<small title="' + name.replace(/"/g, '&quot;') + '">' + name.replace(/</g, '&lt;') + '</small>' +
                        '<em>' + divPct(value / sum * 100) + '</em><b>' + divShort(value) + '</b></div>';
                    let html = top.map(i => row(i, DIVISION_PALETTE[i % DIVISION_PALETTE.length], ring.labels[i], ring.amounts[i])).join('');
                    if (rest.length) {
                        const restSum = rest.reduce((a, i) => a + ring.amounts[i], 0);
                        html += row(5, '#cbd5e1', 'Lainnya (' + rest.length + ')', restSum);
                    }
                    legendEl.innerHTML = html;
                }

                ring.set = function(newLabels, newAmounts) {
                    ring.labels = (newLabels || []).map(String);
                    ring.amounts = (newAmounts || []).map(v => Number(v) || 0);
                    const has = ring.amounts.length > 0;
                    const ds = ring.chart.data.datasets[0];
                    ring.chart.data.labels = has ? ring.labels : ['Kosong'];
                    ds.data = has ? ring.amounts : [1];
                    ds.backgroundColor = has ? ring.labels.map((_, i) => DIVISION_PALETTE[i % DIVISION_PALETTE.length]) : ['rgba(148,163,184,0.2)'];
                    ring.chart.update();
                    renderLegend();
                    focus(null);
                };

                ring.chart = new Chart(canvas.getContext('2d'), {
                    type: 'doughnut',
                    data: { labels: [], datasets: [{ data: [], backgroundColor: [], borderWidth: 0, borderRadius: 4, spacing: 2, hoverOffset: 4 }] },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '72%',
                        animation: { duration: 700, easing: 'easeOutQuart' },
                        onHover: (event, elements) => focus(elements.length && ring.amounts.length ? elements[0].index : null),
                        plugins: { legend: { display: false }, tooltip: { enabled: false } }
                    }
                });
                canvas.addEventListener('mouseleave', () => focus(null));
                ring.set(labels, amounts);
                return ring;
            }

            const divisionIncomeRing = makeDivisionRing('divisionPieChart', 'divisionIncomeLegend', 'divisionIncomeCenter', 'divisionIncomeCenterLabel',
                <?php echo json_encode(array_map(fn($d) => (string)$d['division_name'], $divisionIncomeData ?? []), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>,
                <?php echo json_encode(array_map(fn($d) => (float)$d['total'], $divisionIncomeData ?? [])); ?>);
            const expenseDivisionRing = makeDivisionRing('expenseCategoryChart', 'expenseDivisionLegend', 'expenseDivisionCenter', 'expenseDivisionCenterLabel',
                <?php echo json_encode(array_map(fn($d) => (string)$d['division_name'], $expenseDivisionData ?? []), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>,
                <?php echo json_encode(array_map(fn($d) => (float)$d['total'], $expenseDivisionData ?? [])); ?>);

            // Ganti bulan pada kartu divisi
            function updateDivisionIncomeChart(month) {
                fetch(`api/division-income-data.php?month=${month}`)
                    .then(response => response.json())
                    .then(data => {
                        if (!divisionIncomeRing) return;
                        const ok = data.success && data.divisions && data.divisions.length > 0;
                        divisionIncomeRing.set(ok ? data.divisions : [], ok ? data.amounts : []);
                    })
                    .catch(error => console.error('Error updating division income chart:', error));
            }

            function updateExpenseCategoryChart(month) {
                fetch(`api/expense-category-data.php?month=${month}`)
                    .then(response => response.json())
                    .then(data => {
                        if (!expenseDivisionRing) return;
                        const ok = data.success && data.categories && data.categories.length > 0;
                        expenseDivisionRing.set(ok ? data.categories : [], ok ? data.amounts : []);
                    })
                    .catch(error => console.error('Error updating expense category chart:', error));
            }
        <?php endif; // !$isCQC ring divisi
        ?>

        // ============================================
        // CLEAN FINANCIAL FLOW CHART
        // ============================================
        <?php if (!empty($dailyData)): ?>
            const tradingCtx = document.getElementById('tradingChart').getContext('2d');

            // Garis = net per titik waktu (pemasukan - pengeluaran); akumulasinya ditampilkan di tooltip.
            // Titik setelah "sekarang" (sisa bulan/jam berjalan) dikosongkan agar garis tidak memanjang ke masa depan.
            const buildNetSeries = (incomeSeries, expenseSeries) => {
                const now = new Date();
                const pad = n => String(n).padStart(2, '0');
                const mode = chartViewMode();
                let lastIndex = incomeSeries.length - 1;
                if (mode === 'monthly' && document.getElementById('chartMonthFilter').value === now.getFullYear() + '-' + pad(now.getMonth() + 1)) {
                    lastIndex = now.getDate() - 1;
                } else if (mode === 'daily' && document.getElementById('chartDateFilter').value === now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate())) {
                    lastIndex = now.getHours();
                } else if (mode === 'yearly' && document.getElementById('chartYearFilter').value === String(now.getFullYear())) {
                    lastIndex = now.getMonth();
                }
                return incomeSeries.map((value, index) =>
                    index <= lastIndex ? (Number(value) || 0) - (Number(expenseSeries[index]) || 0) : null);
            };

            // Variabel tema dibaca dari <body> karena mode gelap menimpa variabel di body[data-theme="dark"].
            const cssVar = (name, fallback) => getComputedStyle(document.body).getPropertyValue(name).trim() || fallback;
            const MONTHS_ID = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            const UNIT_WORD = { daily: 'jam', monthly: 'hari', yearly: 'bulan', alltime: 'tahun' };

            // Format ringkas sumbu/angka besar: Rp 1,2 M · Rp 35 jt · Rp 800 rb (nilai negatif didukung).
            function fmtCompact(v) {
                const n = Number(v) || 0, a = Math.abs(n), s = n < 0 ? '-' : '';
                const short = x => (x >= 10 ? x.toFixed(0) : x.toFixed(1).replace(/\.0$/, '')).replace('.', ',');
                if (a >= 1e9) return s + 'Rp ' + short(a / 1e9) + ' M';
                if (a >= 1e6) return s + 'Rp ' + short(a / 1e6) + ' jt';
                if (a >= 1e3) return s + 'Rp ' + short(a / 1e3) + ' rb';
                return s + 'Rp ' + a;
            }
            const fmtFull = v => (v < 0 ? '-' : '') + 'Rp ' + Math.abs(Math.round(Number(v) || 0)).toLocaleString('id-ID');
            const fmtPct = v => (Math.abs(v) >= 100 ? Math.round(v) : v.toFixed(1).replace(/\.0$/, '')).toString().replace('.', ',') + '%';

            function chartViewMode() {
                const btn = document.querySelector('.chart-view-toggle .btn-view-toggle.active');
                return ({ btnDaily: 'daily', btnYearly: 'yearly', btnAllTime: 'alltime' })[btn && btn.id] || 'monthly';
            }

            // Nama titik waktu sesuai mode: tanggal (bulanan), jam (harian), bulan (tahunan), tahun (all).
            function pointLabel(label, long) {
                const mode = chartViewMode();
                if (mode === 'daily') return (long ? 'Pukul ' : 'pukul ') + label;
                if (mode === 'yearly') return label + (long ? ' ' + document.getElementById('chartYearFilter').value : '');
                if (mode === 'alltime') return (long ? 'Tahun ' : 'th ') + label;
                const [y, m] = (document.getElementById('chartMonthFilter').value || '').split('-');
                return long ? label + ' ' + (MONTHS_ID[(+m || 1) - 1] || '') + ' ' + (y || '') : 'tgl ' + label;
            }

            function periodTitle() {
                const mode = chartViewMode();
                if (mode === 'daily') {
                    const d = new Date(document.getElementById('chartDateFilter').value + 'T00:00:00');
                    return isNaN(d) ? 'Hari ini' : d.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
                }
                if (mode === 'yearly') return 'Tahun ' + document.getElementById('chartYearFilter').value;
                if (mode === 'alltime') return 'Semua periode';
                const [y, m] = (document.getElementById('chartMonthFilter').value || '').split('-');
                return (MONTHS_ID[(+m || 1) - 1] || '') + ' ' + (y || '');
            }

            const setText = (id, text) => {
                const el = document.getElementById(id);
                if (el) el.textContent = text;
            };
            const setHtml = (id, html) => {
                const el = document.getElementById(id);
                if (el) el.innerHTML = html;
            };

            // Ring rasio pemasukan vs pengeluaran (dibuat sekali, lalu hanya datanya yang diganti).
            let summaryPie = null;

            function ensureSummaryPie() {
                if (summaryPie) return summaryPie;
                const canvas = document.getElementById('summaryPieChart');
                if (!canvas) return null;
                summaryPie = new Chart(canvas.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: ['Pemasukan', 'Pengeluaran'],
                        datasets: [{
                            data: [0, 0],
                            backgroundColor: ['#2563eb', '#f97316'],
                            borderWidth: 0,
                            borderRadius: 8,
                            spacing: 3,
                            hoverOffset: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '70%',
                        animation: { duration: 700, easing: 'easeOutQuart' },
                        // Tooltip bawaan terpotong di kanvas kecil; info segmen ditampilkan di tengah ring.
                        onHover: (event, elements, chart) => ringFocus(chart, elements.length ? elements[0].index : null),
                        plugins: {
                            legend: { display: false },
                            tooltip: { enabled: false }
                        }
                    }
                });
                canvas.addEventListener('mouseleave', () => ringFocus(summaryPie, null));
                return summaryPie;
            }

            // Isi tengah ring: default = margin; saat segmen disorot = nama, persen, dan nominal segmen itu.
            let ringDefault = { value: '–', label: 'Margin', cls: '' };

            function ringFocus(chart, index) {
                const valueEl = document.getElementById('ringValue');
                const labelEl = document.getElementById('ringLabel');
                const amountEl = document.getElementById('ringAmount');
                const legends = document.querySelectorAll('#tradingChartCard .fin-leg');
                if (!valueEl || !labelEl) return;

                const hasData = chart && chart.data.labels.length > 1;
                const focus = hasData && index !== null && index !== undefined;
                legends.forEach((el, i) => el.classList.toggle('is-dim', focus && i !== index));

                valueEl.classList.remove('is-pos', 'is-neg', 'is-inc', 'is-exp');
                if (!focus) {
                    valueEl.textContent = ringDefault.value;
                    labelEl.textContent = ringDefault.label;
                    if (ringDefault.cls) valueEl.classList.add(ringDefault.cls);
                    if (amountEl) amountEl.textContent = '';
                    return;
                }
                const data = chart.data.datasets[0].data;
                const total = data.reduce((a, b) => a + b, 0) || 1;
                valueEl.textContent = fmtPct(data[index] / total * 100);
                valueEl.classList.add(index === 0 ? 'is-inc' : 'is-exp');
                labelEl.textContent = chart.data.labels[index];
                if (amountEl) amountEl.textContent = fmtCompact(data[index]);
            }

            // Hitung ulang panel ringkasan + sub-info KPI dari data yang sedang tampil di grafik utama.
            function renderInsights(chart) {
                const labels = chart.data.labels || [];
                const inc = (chart.data.datasets[0].data || []).map(v => Number(v) || 0);
                const exp = (chart.data.datasets[1].data || []).map(v => Number(v) || 0);
                const totalInc = inc.reduce((a, b) => a + b, 0);
                const totalExp = exp.reduce((a, b) => a + b, 0);
                const net = totalInc - totalExp;
                const unit = UNIT_WORD[chartViewMode()];

                let active = 0, surplus = 0, best = -1, worst = -1;
                labels.forEach((_, i) => {
                    if (inc[i] || exp[i]) {
                        active++;
                        if (inc[i] - exp[i] > 0) surplus++;
                    }
                    if (inc[i] > 0 && (best < 0 || inc[i] > inc[best])) best = i;
                    if (exp[i] > 0 && (worst < 0 || exp[i] > exp[worst])) worst = i;
                });

                const margin = totalInc > 0 ? (net / totalInc) * 100 : null;
                const flow = totalInc + totalExp;

                setText('insightTitle', periodTitle());

                const health = document.getElementById('insightHealth');
                if (health) {
                    let cls = 'is-empty', text = 'Belum ada data';
                    if (flow > 0) {
                        if (net < 0) { cls = 'is-bad'; text = 'Defisit'; }
                        else if (margin !== null && margin < 20) { cls = 'is-thin'; text = 'Margin tipis'; }
                        else { cls = 'is-good'; text = 'Sehat'; }
                    }
                    health.className = 'fin-health ' + cls;
                    health.textContent = text;
                }

                const ring = ensureSummaryPie();
                if (ring) {
                    const ds = ring.data.datasets[0];
                    if (flow > 0) {
                        ring.data.labels = ['Pemasukan', 'Pengeluaran'];
                        ds.data = [totalInc, totalExp];
                        ds.backgroundColor = ['#2563eb', '#f97316'];
                    } else {
                        ring.data.labels = ['Kosong'];
                        ds.data = [1];
                        ds.backgroundColor = [cssVar('--chart-ring-track', 'rgba(148,163,184,0.18)')];
                    }
                    ring.update();
                }

                const ringValue = document.getElementById('ringValue');
                if (ringValue) {
                    ringDefault = {
                        value: margin === null ? '–' : fmtPct(margin),
                        label: 'Margin',
                        cls: margin === null ? '' : (margin < 0 ? 'is-neg' : 'is-pos')
                    };
                    ringFocus(ring, null);
                }

                setText('pieIncomeValue', fmtFull(totalInc));
                setText('pieExpenseValue', fmtFull(totalExp));
                setText('pieIncomePct', flow > 0 ? fmtPct(totalInc / flow * 100) : '0%');
                setText('pieExpensePct', flow > 0 ? fmtPct(totalExp / flow * 100) : '0%');

                const ratioFill = document.getElementById('ratioFill');
                if (ratioFill) {
                    ratioFill.parentElement.classList.toggle('is-empty', flow === 0);
                    ratioFill.style.width = flow > 0 ? (totalInc / flow * 100) + '%' : '0%';
                }
                if (totalInc > 0) {
                    setHtml('ratioCaption', 'Pengeluaran <b>' + fmtPct(totalExp / totalInc * 100) + '</b> dari pemasukan · net <b class="' + (net < 0 ? 'is-neg' : 'is-pos') + '">' + fmtCompact(net) + '</b>');
                } else {
                    setHtml('ratioCaption', totalExp > 0 ? 'Belum ada pemasukan, pengeluaran <b>' + fmtCompact(totalExp) + '</b>' : 'Belum ada transaksi di periode ini');
                }

                setText('statAvgLabel', 'Rata-rata per ' + unit);
                setText('statAvgIncome', active ? fmtCompact(totalInc / active) : 'Rp 0');
                setText('statBestValue', best >= 0 ? fmtCompact(inc[best]) : '–');
                setText('statBestWhen', best >= 0 ? pointLabel(labels[best], false) : 'belum ada');
                setText('statWorstValue', worst >= 0 ? fmtCompact(exp[worst]) : '–');
                setText('statWorstWhen', worst >= 0 ? pointLabel(labels[worst], false) : 'belum ada');
                setText('statSurplusLabel', unit.charAt(0).toUpperCase() + unit.slice(1) + ' surplus');
                setText('statSurplus', surplus + ' ' + unit);
                setText('statSurplusOf', 'dari ' + active + ' ' + unit + ' aktif');

                setHtml('summaryIncomeSub', '<b>' + active + '</b> ' + unit + ' ada transaksi');
                setHtml('summaryExpenseSub', totalInc > 0 ? '<b>' + fmtPct(totalExp / totalInc * 100) + '</b> dari pemasukan' : 'Belum ada pemasukan');
                setHtml('summaryNetSub', margin === null ? 'Margin –' : 'Margin <b>' + fmtPct(margin) + '</b>');
            }

            // Sorot kolom yang sedang di-hover agar pembacaan per tanggal lebih mudah.
            const hoverBandPlugin = {
                id: 'hoverBand',
                beforeDatasetsDraw(chart) {
                    const active = chart.tooltip && chart.tooltip.getActiveElements ? chart.tooltip.getActiveElements() : [];
                    if (!active.length) return;
                    const { ctx, chartArea, scales } = chart;
                    const step = scales.x.width / Math.max(chart.data.labels.length, 1);
                    const cx = scales.x.getPixelForValue(active[0].index);
                    ctx.save();
                    ctx.fillStyle = cssVar('--chart-hover-band', 'rgba(99,102,241,0.07)');
                    ctx.beginPath();
                    if (ctx.roundRect) ctx.roundRect(cx - step / 2, chartArea.top, step, chartArea.bottom - chartArea.top, 6);
                    else ctx.rect(cx - step / 2, chartArea.top, step, chartArea.bottom - chartArea.top);
                    ctx.fill();
                    ctx.restore();
                }
            };
            const insightsPlugin = {
                id: 'finInsights',
                afterUpdate: chart => renderInsights(chart)
            };

            const dailyIncomeSeries = [
                <?php foreach ($dailyData as $data): ?>
                    <?php echo $data['income']; ?>,
                <?php endforeach; ?>
            ];
            const dailyExpenseSeries = [
                <?php foreach ($dailyData as $data): ?>
                    <?php echo $data['expense']; ?>,
                <?php endforeach; ?>
            ];
            const dailyNetSeries = buildNetSeries(dailyIncomeSeries, dailyExpenseSeries);

            let tradingChart = new Chart(tradingCtx, {
                type: 'bar',
                data: {
                    labels: [
                        <?php foreach ($dailyData as $data): ?>
                            <?php echo (int)date('d', strtotime($data['date'])); ?>,
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                            label: 'Pemasukan',
                            data: dailyIncomeSeries,
                            backgroundColor: '#2563eb',
                            hoverBackgroundColor: '#1d4ed8',
                            borderWidth: 0,
                            borderRadius: { topLeft: 3, topRight: 3 },
                            borderSkipped: 'start',
                            barPercentage: 0.9,
                            categoryPercentage: 0.72,
                            maxBarThickness: 26,
                            order: 2
                        },
                        {
                            label: 'Pengeluaran',
                            data: dailyExpenseSeries,
                            backgroundColor: '#f97316',
                            hoverBackgroundColor: '#ea580c',
                            borderWidth: 0,
                            borderRadius: { topLeft: 3, topRight: 3 },
                            borderSkipped: 'start',
                            barPercentage: 0.9,
                            categoryPercentage: 0.72,
                            maxBarThickness: 26,
                            order: 3
                        },
                        {
                            type: 'line',
                            label: 'Net',
                            data: dailyNetSeries,
                            borderColor: '#10b981',
                            borderWidth: 2,
                            fill: false,
                            tension: 0.3,
                            cubicInterpolationMode: 'monotone',
                            pointRadius: 2.5,
                            pointBorderWidth: 0,
                            pointHoverRadius: 5,
                            pointHitRadius: 10,
                            pointBackgroundColor: '#10b981',
                            pointHoverBorderColor: '#fff',
                            pointHoverBorderWidth: 2,
                            borderCapStyle: 'round',
                            order: 1
                        }
                    ]
                },
                plugins: [hoverBandPlugin, insightsPlugin],
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: { top: 6, right: 4 } },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    animation: {
                        duration: 700,
                        easing: 'easeOutQuart'
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            enabled: true,
                            backgroundColor: 'rgba(15, 23, 42, 0.94)',
                            titleColor: '#fff',
                            bodyColor: 'rgba(226, 232, 240, 0.95)',
                            footerColor: 'rgba(203, 213, 225, 0.85)',
                            borderColor: 'rgba(148, 163, 184, 0.18)',
                            borderWidth: 1,
                            padding: 12,
                            caretSize: 0,
                            cornerRadius: 12,
                            titleFont: { size: 11.5, weight: '700', family: "'Inter', sans-serif" },
                            bodyFont: { size: 11, weight: '500', family: "'Inter', sans-serif" },
                            footerFont: { size: 11, weight: '700', family: "'Inter', sans-serif" },
                            titleMarginBottom: 8,
                            footerMarginTop: 8,
                            bodySpacing: 5,
                            displayColors: true,
                            boxWidth: 8,
                            boxHeight: 8,
                            boxPadding: 6,
                            usePointStyle: true,
                            callbacks: {
                                title: items => pointLabel(items[0].label, true),
                                label: ctx => ' ' + ctx.dataset.label + '   ' + fmtFull(ctx.parsed.y || 0),
                                footer: items => {
                                    const i = items[0].dataIndex;
                                    const ds = items[0].chart.data.datasets;
                                    let total = 0;
                                    for (let k = 0; k <= i; k++) total += (Number(ds[0].data[k]) || 0) - (Number(ds[1].data[k]) || 0);
                                    return 'Akumulasi s/d ' + pointLabel(items[0].label, false) + ': ' + (total > 0 ? '+' : '') + fmtFull(total);
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grace: '8%',
                            grid: {
                                color: ctx => ctx.tick && ctx.tick.value === 0 ? cssVar('--chart-zero-line', 'rgba(71,85,105,0.45)') : cssVar('--chart-grid-color', 'rgba(148,163,184,0.2)'),
                                lineWidth: ctx => ctx.tick && ctx.tick.value === 0 ? 1.2 : 1
                            },
                            border: {
                                display: false
                            },
                            ticks: {
                                padding: 10,
                                font: { size: 10, weight: '500', family: "'Inter', sans-serif" },
                                color: cssVar('--chart-tick-color', 'rgba(100,116,139,0.8)'),
                                maxTicksLimit: 6,
                                callback: value => fmtCompact(value)
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            border: {
                                display: false
                            },
                            ticks: {
                                padding: 6,
                                font: { size: 10, weight: '500', family: "'Inter', sans-serif" },
                                color: cssVar('--chart-tick-color', 'rgba(100,116,139,0.8)'),
                                maxRotation: 0,
                                minRotation: 0,
                                autoSkipPadding: 10
                            }
                        }
                    }
                }
            });

            // ============================================
            // LIVE UPDATE - Auto refresh every 30 seconds
            // ============================================
            function updateLiveChart() {
                const selectedMonth = document.getElementById('chartMonthFilter').value;
                fetch(`api/live-chart-data.php?month=${selectedMonth}`)
                    .then(response => response.json())
                    .then(data => {
                        // Abaikan hasil refresh yang datang setelah user pindah ke mode lain.
                        if (currentView !== 'monthly') return;
                        if (data.success) {
                            // Calculate daily net series
                            const netSeries = buildNetSeries(data.income, data.expense);

                            // Update chart data
                            tradingChart.data.labels = data.labels;
                            tradingChart.data.datasets[0].data = data.income;
                            tradingChart.data.datasets[1].data = data.expense;
                            tradingChart.data.datasets[2].data = netSeries;
                            tradingChart.update('none'); // Update without animation

                            // Update summary cards
                            const totalIncome = data.income.reduce((a, b) => a + b, 0);
                            const totalExpense = data.expense.reduce((a, b) => a + b, 0);

                            // Income from API already excludes owner_fund (petty cash transfers)
                            const isCQC = data.cqc !== null && data.cqc !== undefined;
                            let netBalance = totalIncome - totalExpense;

                            // CQC: displayIncome = invoice - petty cash transfers
                            let displayIncome = totalIncome;
                            if (isCQC) {
                                const pettyCashTransfers = data.cqc.petty_cash_transfers || 0;
                                const pettyCashBalance = data.cqc.petty_cash_balance || 0;
                                const bankBalance = data.cqc.bank_balance || 0;
                                const expenseFromPettyCash = data.cqc.expense_from_petty_cash || 0;
                                const expenseFromBank = data.cqc.expense_from_bank || 0;
                                displayIncome = totalIncome - pettyCashTransfers;
                                // CQC: Saldo Bersih = Petty Cash + Bank (actual cash position)
                                netBalance = pettyCashBalance + bankBalance;

                                // Update chart summary containers
                                const pettyCashEl = document.getElementById('totalPettyCash');
                                if (pettyCashEl) pettyCashEl.textContent = formatRupiah(pettyCashBalance);

                                const kasBesarEl = document.getElementById('totalKasBesar');
                                if (kasBesarEl) kasBesarEl.textContent = formatRupiah(bankBalance);

                                // Update widget containers
                                const dashPettyEl = document.getElementById('dashboardPettyCashBalance');
                                if (dashPettyEl) dashPettyEl.textContent = formatRupiah(pettyCashBalance);

                                const dashBankEl = document.getElementById('dashboardBankBalance');
                                if (dashBankEl) dashBankEl.textContent = formatRupiah(bankBalance);

                                const expBankEl = document.getElementById('expenseFromBank');
                                if (expBankEl) expBankEl.textContent = formatRupiah(expenseFromBank);
                            }

                            updateSummaryCards(displayIncome, totalExpense, netBalance);

                            console.log('Chart updated at:', data.timestamp);
                        }
                    })
                    .catch(error => console.error('Error updating chart:', error));
            }

            // Update chart when month filter changes
            function updateChartMonth(month) {
                fetch(`api/live-chart-data.php?month=${month}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Calculate daily net series
                            const netSeries = buildNetSeries(data.income, data.expense);

                            // Update chart with animation
                            tradingChart.data.labels = data.labels;
                            tradingChart.data.datasets[0].data = data.income;
                            tradingChart.data.datasets[1].data = data.expense;
                            tradingChart.data.datasets[2].data = netSeries;
                            tradingChart.update();

                            // Update summary cards
                            const totalIncome = data.income.reduce((a, b) => a + b, 0);
                            const totalExpense = data.expense.reduce((a, b) => a + b, 0);

                            // Income from API already excludes owner_fund (petty cash transfers)
                            const isCQC = data.cqc !== null && data.cqc !== undefined;
                            let netBalance = totalIncome - totalExpense;

                            // CQC: displayIncome = invoice - petty cash transfers
                            let displayIncome = totalIncome;
                            if (isCQC) {
                                const pettyCashTransfers = data.cqc.petty_cash_transfers || 0;
                                const pettyCashBalance = data.cqc.petty_cash_balance || 0;
                                const bankBalance = data.cqc.bank_balance || 0;
                                const expenseFromPettyCash = data.cqc.expense_from_petty_cash || 0;
                                const expenseFromBank = data.cqc.expense_from_bank || 0;
                                displayIncome = totalIncome - pettyCashTransfers;
                                // CQC: Saldo Bersih = Petty Cash + Bank (actual cash position)
                                netBalance = pettyCashBalance + bankBalance;

                                // Update chart summary containers
                                const pettyCashEl = document.getElementById('totalPettyCash');
                                if (pettyCashEl) pettyCashEl.textContent = formatRupiah(pettyCashBalance);

                                const kasBesarEl = document.getElementById('totalKasBesar');
                                if (kasBesarEl) kasBesarEl.textContent = formatRupiah(bankBalance);

                                // Update widget containers
                                const dashPettyEl = document.getElementById('dashboardPettyCashBalance');
                                if (dashPettyEl) dashPettyEl.textContent = formatRupiah(pettyCashBalance);

                                const dashBankEl = document.getElementById('dashboardBankBalance');
                                if (dashBankEl) dashBankEl.textContent = formatRupiah(bankBalance);

                                const expBankEl = document.getElementById('expenseFromBank');
                                if (expBankEl) expBankEl.textContent = formatRupiah(expenseFromBank);
                            }

                            updateSummaryCards(displayIncome, totalExpense, netBalance);

                            // Update period display
                            const monthObj = new Date(month + '-01');
                            const monthStr = monthObj.toLocaleDateString('id-ID', {
                                month: 'long',
                                year: 'numeric'
                            });
                            const daysInMonth = new Date(monthObj.getFullYear(), monthObj.getMonth() + 1, 0).getDate();
                            document.getElementById('periodDisplay').textContent = '1 - ' + daysInMonth + ' ' + monthStr;

                            // Update URL without reload
                            const url = new URL(window.location);
                            url.searchParams.set('chart_month', month);
                            window.history.pushState({}, '', url);
                        }
                    })
                    .catch(error => console.error('Error updating chart:', error));
            }

            // Helper function to format currency
            function formatRupiah(amount) {
                return 'Rp ' + amount.toLocaleString('id-ID');
            }

            // Helper function to update summary cards
            function updateSummaryCards(income, expense, net) {
                const incEl = document.getElementById('summaryIncome');
                const expEl = document.getElementById('summaryExpense');
                const netEl = document.getElementById('summaryNet');
                if (incEl) incEl.textContent = formatRupiah(income);
                if (expEl) expEl.textContent = formatRupiah(expense);
                if (netEl) {
                    netEl.textContent = formatRupiah(net);
                    netEl.classList.toggle('is-pos', net >= 0);
                    netEl.classList.toggle('is-neg', net < 0);
                }
            }

            // Auto refresh every 30 seconds
            // Hanya mode Bulanan yang live; Harian/Tahunan/All tetap diam di pilihan user.
            setInterval(() => {
                if (currentView === 'monthly' && !document.hidden) updateLiveChart();
            }, 30000);

            function setLiveIndicator(isLive) {
                const pill = document.getElementById('liveIndicator');
                if (!pill) return;
                pill.classList.toggle('is-paused', !isLive);
                const text = pill.querySelector('.chart-live-text');
                if (text) text.textContent = isLive ? 'LIVE' : 'STATIS';
                pill.title = isLive ? 'Diperbarui otomatis tiap 30 detik' : 'Auto refresh berhenti di mode ini';
            }

            // ============================================
            // SWITCH VIEW - Daily, Monthly, Yearly, All-Time
            // ============================================
            let currentView = 'monthly';

            function switchView(view) {
                currentView = view;
                setLiveIndicator(view === 'monthly');

                // Update button styles
                const btnDaily = document.getElementById('btnDaily');
                const btnMonthly = document.getElementById('btnMonthly');
                const btnYearly = document.getElementById('btnYearly');
                const btnAllTime = document.getElementById('btnAllTime');
                const dailyFilter = document.getElementById('dailyFilter');
                const monthlyFilter = document.getElementById('monthlyFilter');
                const yearlyFilter = document.getElementById('yearlyFilter');

                // Reset all buttons
                [btnDaily, btnMonthly, btnYearly, btnAllTime].forEach(btn => {
                    btn.classList.remove('active');
                    btn.style.background = '';
                    btn.style.color = '';
                });

                // Hide all filters
                dailyFilter.style.display = 'none';
                monthlyFilter.style.display = 'none';
                yearlyFilter.style.display = 'none';

                if (view === 'daily') {
                    btnDaily.classList.add('active');
                    dailyFilter.style.display = 'flex';

                    // Load daily data (hourly breakdown)
                    const selectedDate = document.getElementById('chartDateFilter').value;
                    updateChartDate(selectedDate);
                } else if (view === 'monthly') {
                    btnMonthly.classList.add('active');
                    monthlyFilter.style.display = 'flex';

                    // Load monthly data (daily breakdown)
                    const selectedMonth = document.getElementById('chartMonthFilter').value;
                    updateChartMonth(selectedMonth);
                } else if (view === 'yearly') {
                    btnYearly.classList.add('active');
                    yearlyFilter.style.display = 'flex';

                    // Load yearly data (monthly breakdown)
                    const selectedYear = document.getElementById('chartYearFilter').value;
                    updateChartYear(selectedYear);
                } else if (view === 'alltime') {
                    btnAllTime.classList.add('active');

                    // Load all-time data (yearly breakdown)
                    updateChartAllTime();
                }
            }

            function updateChartDate(date) {
                fetch(`api/daily-chart-data.php?date=${date}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Calculate daily net series
                            const netSeries = buildNetSeries(data.income, data.expense);

                            // Update chart with animation
                            tradingChart.data.labels = data.labels;
                            tradingChart.data.datasets[0].data = data.income;
                            tradingChart.data.datasets[1].data = data.expense;
                            tradingChart.data.datasets[2].data = netSeries;
                            tradingChart.update();

                            // Update summary cards
                            const totalIncome = data.income.reduce((a, b) => a + b, 0);
                            const totalExpense = data.expense.reduce((a, b) => a + b, 0);
                            const netBalance = totalIncome - totalExpense;

                            updateSummaryCards(totalIncome, totalExpense, netBalance);

                            // Update period display
                            const dateObj = new Date(date);
                            const dateStr = dateObj.toLocaleDateString('id-ID', {
                                day: 'numeric',
                                month: 'short',
                                year: 'numeric'
                            });
                            document.getElementById('periodDisplay').textContent = dateStr + ' (24 jam)';
                        }
                    })
                    .catch(error => console.error('Error updating chart:', error));
            }

            function updateChartAllTime() {
                fetch(`api/alltime-chart-data.php`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Calculate daily net series
                            const netSeries = buildNetSeries(data.income, data.expense);

                            // Update chart with animation
                            tradingChart.data.labels = data.labels;
                            tradingChart.data.datasets[0].data = data.income;
                            tradingChart.data.datasets[1].data = data.expense;
                            tradingChart.data.datasets[2].data = netSeries;
                            tradingChart.update();

                            // Update summary cards
                            const totalIncome = data.income.reduce((a, b) => a + b, 0);
                            const totalExpense = data.expense.reduce((a, b) => a + b, 0);
                            const netBalance = totalIncome - totalExpense;

                            updateSummaryCards(totalIncome, totalExpense, netBalance);

                            // Update period display
                            if (data.labels.length > 0) {
                                const firstYear = data.labels[0];
                                const lastYear = data.labels[data.labels.length - 1];
                                document.getElementById('periodDisplay').textContent = firstYear + ' - ' + lastYear + ' (' + data.labels.length + ' tahun)';
                            } else {
                                document.getElementById('periodDisplay').textContent = 'Tidak ada data';
                            }
                        }
                    })
                    .catch(error => console.error('Error updating chart:', error));
            }

            function updateChartYear(year) {
                fetch(`api/yearly-chart-data.php?year=${year}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Calculate daily net series
                            const netSeries = buildNetSeries(data.income, data.expense);

                            // Update chart with animation
                            tradingChart.data.labels = data.labels;
                            tradingChart.data.datasets[0].data = data.income;
                            tradingChart.data.datasets[1].data = data.expense;
                            tradingChart.data.datasets[2].data = netSeries;
                            tradingChart.update();

                            // Update summary cards
                            const totalIncome = data.income.reduce((a, b) => a + b, 0);
                            const totalExpense = data.expense.reduce((a, b) => a + b, 0);
                            const netBalance = totalIncome - totalExpense;

                            updateSummaryCards(totalIncome, totalExpense, netBalance);

                            // Update period display
                            document.getElementById('periodDisplay').textContent = 'Jan - Des ' + year + ' (12 bulan)';
                        }
                    })
                    .catch(error => console.error('Error updating chart:', error));
            }

            // Update dashboard month from year selector
            function updateDashboardYear(year) {
                const monthSelect = document.getElementById('dashboardMonthSelect');
                const currentMonth = monthSelect.value.split('-')[1];
                const newMonth = year + '-' + currentMonth;
                monthSelect.value = newMonth;
                monthSelect.form.submit();
            }
        <?php endif; ?>
        // ============================================
        // HORIZONTAL BAR CHART - Top Categories
        // ============================================
        // ============================================
        // CQC PROJECT PIE CHARTS - Modern Elegant 2026
        // ============================================
        <?php if ($isCQC && !empty($cqcProjects)): ?>
            const cqcColors = ['#10b981', '#f59e0b', '#3b82f6', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16', '#f97316'];
            const cqcColorsLight = ['#34d399', '#fbbf24', '#60a5fa', '#a78bfa', '#f472b6', '#22d3ee', '#a3e635', '#fb923c'];

            // Individual project doughnut charts with gradient
            <?php
            // Clean 2027 style - simple colors
            foreach ($cqcProjects as $idx => $proj):
                $progress = intval($proj['progress_percentage'] ?? 0);
            ?>
                    (function() {
                        const ctx = document.getElementById('cqcPie<?php echo $idx; ?>');
                        if (!ctx) return;

                        new Chart(ctx, {
                            type: 'doughnut',
                            data: {
                                labels: ['Progress', 'Remaining'],
                                datasets: [{
                                    data: [<?php echo $progress; ?>, <?php echo 100 - $progress; ?>],
                                    backgroundColor: ['#0ea5e9', '#e2e8f0'],
                                    borderWidth: 0,
                                    borderRadius: 4,
                                    hoverBackgroundColor: ['#0284c7', '#cbd5e1'],
                                    hoverOffset: 2
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: true,
                                cutout: '70%',
                                rotation: -90,
                                circumference: 360,
                                plugins: {
                                    legend: {
                                        display: false
                                    },
                                    tooltip: {
                                        enabled: true,
                                        backgroundColor: '#1e293b',
                                        titleColor: '#fff',
                                        bodyColor: '#e2e8f0',
                                        cornerRadius: 6,
                                        padding: 10,
                                        displayColors: false,
                                        titleFont: {
                                            size: 11,
                                            weight: '600'
                                        },
                                        bodyFont: {
                                            size: 11
                                        },
                                        callbacks: {
                                            label: function(ctx) {
                                                return ctx.label + ': ' + ctx.parsed + '%';
                                            }
                                        }
                                    }
                                },
                                animation: {
                                    animateRotate: true,
                                    duration: 600,
                                    easing: 'easeOutQuart'
                                }
                            }
                        });
                    })();
            <?php endforeach; ?>

                // Budget Distribution Doughnut - Modern Style
                (function() {
                    const ctx = document.getElementById('cqcBudgetPie');
                    if (!ctx) return;
                    new Chart(ctx.getContext('2d'), {
                        type: 'doughnut',
                        data: {
                            labels: [<?php echo implode(',', array_map(function ($p) {
                                            return "'" . addslashes($p['project_name']) . "'";
                                        }, $cqcProjects)); ?>],
                            datasets: [{
                                data: [<?php echo implode(',', array_column($cqcProjects, 'budget_idr')); ?>],
                                backgroundColor: cqcColors.slice(0, <?php echo count($cqcProjects); ?>),
                                borderWidth: 3,
                                borderColor: '#fff',
                                hoverOffset: 18,
                                borderRadius: 4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            cutout: '55%',
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 16,
                                        font: {
                                            size: 12,
                                            weight: '600'
                                        },
                                        usePointStyle: true,
                                        pointStyle: 'circle',
                                        boxWidth: 10,
                                        generateLabels: function(chart) {
                                            const data = chart.data;
                                            return data.labels.map((label, i) => ({
                                                text: label.length > 15 ? label.substring(0, 15) + '...' : label,
                                                fillStyle: data.datasets[0].backgroundColor[i],
                                                strokeStyle: '#fff',
                                                lineWidth: 0,
                                                hidden: false,
                                                index: i,
                                                pointStyle: 'circle'
                                            }));
                                        }
                                    }
                                },
                                tooltip: {
                                    backgroundColor: 'rgba(17, 24, 39, 0.95)',
                                    titleColor: '#fbbf24',
                                    bodyColor: '#e5e7eb',
                                    cornerRadius: 12,
                                    padding: 16,
                                    titleFont: {
                                        size: 13,
                                        weight: '700'
                                    },
                                    bodyFont: {
                                        size: 12
                                    },
                                    callbacks: {
                                        label: function(ctx) {
                                            let total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                            let pct = ((ctx.parsed / total) * 100).toFixed(1);
                                            return ctx.label + ': Rp ' + ctx.parsed.toLocaleString('id-ID') + ' (' + pct + '%)';
                                        }
                                    }
                                }
                            },
                            animation: {
                                animateRotate: true,
                                duration: 1000,
                                easing: 'easeOutQuart'
                            }
                        }
                    });
                })();

            // Budget vs Spent Bar Chart - Modern Style
            (function() {
                const ctx = document.getElementById('cqcBudgetVsSpentChart');
                if (!ctx) return;
                new Chart(ctx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: [<?php echo implode(',', array_map(function ($p) {
                                        return "'" . addslashes($p['project_code']) . "'";
                                    }, $cqcProjects)); ?>],
                        datasets: [{
                                label: 'Budget',
                                data: [<?php echo implode(',', array_column($cqcProjects, 'budget_idr')); ?>],
                                backgroundColor: 'rgba(16, 185, 129, 0.85)',
                                borderColor: '#10b981',
                                borderWidth: 0,
                                borderRadius: 8,
                                borderSkipped: false
                            },
                            {
                                label: 'Pengeluaran',
                                data: [<?php echo implode(',', array_column($cqcProjects, 'spent_idr')); ?>],
                                backgroundColor: 'rgba(239, 68, 68, 0.85)',
                                borderColor: '#ef4444',
                                borderWidth: 0,
                                borderRadius: 8,
                                borderSkipped: false
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    padding: 16,
                                    font: {
                                        size: 12,
                                        weight: '600'
                                    },
                                    usePointStyle: true,
                                    pointStyle: 'rect',
                                    boxWidth: 12
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(17, 24, 39, 0.95)',
                                titleColor: '#fbbf24',
                                bodyColor: '#e5e7eb',
                                cornerRadius: 12,
                                padding: 14,
                                titleFont: {
                                    size: 13,
                                    weight: '700'
                                },
                                bodyFont: {
                                    size: 12
                                },
                                callbacks: {
                                    label: function(ctx) {
                                        return ctx.dataset.label + ': Rp ' + ctx.parsed.y.toLocaleString('id-ID');
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(148,163,184,0.08)'
                                },
                                ticks: {
                                    callback: function(v) {
                                        return v >= 1000000 ? 'Rp ' + (v / 1000000).toFixed(1) + 'jt' : 'Rp ' + (v / 1000).toFixed(0) + 'rb';
                                    },
                                    font: {
                                        size: 10
                                    }
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    font: {
                                        size: 10,
                                        weight: '600'
                                    }
                                }
                            }
                        }
                    }
                });
            })();
        <?php endif; ?>

        <?php if (!empty($topCategories)): ?>
            const topCategoriesCtx = document.getElementById('topCategoriesChart').getContext('2d');
            new Chart(topCategoriesCtx, {
                type: 'bar',
                data: {
                    labels: [
                        <?php foreach ($topCategories as $cat): ?> <?php echo json_encode((string)$cat['category_name'] . ' (' . (string)$cat['division_name'] . ')', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>,
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                        label: 'Total Transaksi',
                        data: [
                            <?php foreach ($topCategories as $cat): ?>
                                <?php echo $cat['total']; ?>,
                            <?php endforeach; ?>
                        ],
                        backgroundColor: [
                            <?php foreach ($topCategories as $index => $cat): ?> '<?php echo $cat['transaction_type'] === 'income' ? 'rgba(16, 185, 129, 0.8)' : 'rgba(239, 68, 68, 0.8)'; ?>',
                            <?php endforeach; ?>
                        ],
                        borderColor: [
                            <?php foreach ($topCategories as $index => $cat): ?> '<?php echo $cat['transaction_type'] === 'income' ? 'rgb(16, 185, 129)' : 'rgb(239, 68, 68)'; ?>',
                            <?php endforeach; ?>
                        ],
                        borderWidth: 2,
                        borderRadius: 8,
                        borderSkipped: false,
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            padding: 12,
                            titleFont: {
                                size: 14,
                                weight: '700'
                            },
                            bodyFont: {
                                size: 13
                            },
                            cornerRadius: 8,
                            callbacks: {
                                label: function(context) {
                                    let value = context.parsed.x || 0;
                                    return 'Total: Rp ' + value.toLocaleString('id-ID');
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(148, 163, 184, 0.1)',
                                drawBorder: false
                            },
                            ticks: {
                                callback: function(value) {
                                    return 'Rp ' + (value / 1000000).toFixed(1) + 'jt';
                                },
                                font: {
                                    size: 12,
                                    weight: '600'
                                },
                                color: getChartTextColor()
                            }
                        },
                        y: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 12,
                                    weight: '600'
                                },
                                color: getLegendTextColor()
                            }
                        }
                    }
                }
            });
        <?php endif; ?>
    </script>

    <?php include 'includes/footer.php'; ?>