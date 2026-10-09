<?php

/**
 * OWNER DASHBOARD 2028
 * Data langsung dari PHP - Same logic as System Dashboard (index.php)
 * Multi-business aware via business_helper.php
 * @version 2.1.0 - 2026-03-01 - Pie: Income=Green, Expense=Red, Profit=Yellow
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/business_helper.php';

$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false);
$basePath = $isProduction ? '' : '/adf_system';

// Auth check
$role = $_SESSION['role'] ?? null;
if (!$role && isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
    try {
        $authDb = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
        $authDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $roleStmt = $authDb->prepare("SELECT r.role_code FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $roleStmt->execute([$_SESSION['user_id'] ?? 0]);
        $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
        if ($roleRow) {
            $role = $roleRow['role_code'];
            $_SESSION['role'] = $role;
        }
    } catch (Exception $e) {
    }
}

if (!$role || !in_array($role, ['admin', 'owner', 'manager', 'developer'])) {
    header('Location: ' . $basePath . '/login.php');
    exit;
}
$userName = $_SESSION['username'] ?? 'Owner';
$isDev = ($role === 'developer');

// BUSINESS SWITCHER - handle switch request
if (isset($_GET['business']) && !empty($_GET['business'])) {
    setActiveBusinessId($_GET['business']);
    header('Location: ' . $basePath . '/modules/owner/dashboard-2028.php');
    exit;
}

// Get all available businesses & active config
require_once __DIR__ . '/../../includes/business_access.php';
$allBusinesses = getUserAvailableBusinesses();

// Sort businesses: narayana-hotel first, then alphabetically
uksort($allBusinesses, function ($a, $b) {
    if ($a === 'narayana-hotel') return -1;
    if ($b === 'narayana-hotel') return 1;
    return strcmp($a, $b);
});

// Get active business (respects session - don't force default on every load)
$activeBusinessId = getActiveBusinessId();

// If current active business is not in user's allowed list, auto-switch to first allowed
if (!empty($allBusinesses) && !isset($allBusinesses[$activeBusinessId])) {
    $firstAllowed = array_key_first($allBusinesses);
    setActiveBusinessId($firstAllowed);
    $activeBusinessId = $firstAllowed;
}

$activeConfig = getActiveBusinessConfig();

// DATABASE CONFIG - dynamic from business config
$dbHost = DB_HOST;
$dbUser = DB_USER;
$dbPass = DB_PASS;
$masterDbName = $isProduction ? 'adfb2574_adf' : 'adf_system';
$businessDbName = getDbName($activeConfig['database'] ?? 'adf_narayana_hotel');
$businessName = $activeConfig['name'] ?? 'Unknown Business';
$businessType = $activeConfig['business_type'] ?? 'other';
$businessIcon = $activeConfig['theme']['icon'] ?? '🏢';
$enabledModules = $activeConfig['enabled_modules'] ?? [];
$hasLogo = !empty($activeConfig['logo']);
$logoFile = $activeBusinessId . '_logo.png';

// Get stats - SAME LOGIC AS SYSTEM DASHBOARD (index.php)
$stats = [
    'today_income' => 0,
    'today_expense' => 0,
    'month_income' => 0,
    'month_expense' => 0,
    'total_transactions' => 0
];

$capitalStats = ['received' => 0, 'used' => 0, 'balance' => 0];
$pettyCashStats = ['received' => 0, 'used' => 0, 'balance' => 0];
$totalOperationalCash = 0;
$totalOperationalExpense = 0;

$transactions = [];
$error = null;

try {
    // Connect to business database
    $pdo = new PDO("mysql:host=$dbHost;dbname=$businessDbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Connect to master database for cash_accounts
    $masterPdo = new PDO("mysql:host=$dbHost;dbname=$masterDbName;charset=utf8mb4", $dbUser, $dbPass);
    $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $today = date('Y-m-d');
    $thisMonth = date('Y-m');

    // Get numeric business ID from master DB
    $businessId = null;
    $stmt = $masterPdo->prepare("SELECT id FROM businesses WHERE database_name = ? LIMIT 1");
    $stmt->execute([$activeConfig['database'] ?? '']);
    $bizRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $businessId = $bizRow ? (int)$bizRow['id'] : 1;

    // Check if cash_account_id column exists in this business's cash_book
    $hasCashAccountId = false;
    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
        $hasCashAccountId = ($colCheck && $colCheck->rowCount() > 0);
    } catch (Exception $e) { /* column doesn't exist */
    }

    // Check if source_type column exists (preferred exclusion method)
    $hasSourceTypeCol = false;
    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM cash_book LIKE 'source_type'");
        $hasSourceTypeCol = ($colCheck && $colCheck->rowCount() > 0);
    } catch (Exception $e) {
    }

    // Get owner_capital account IDs from master DB
    $capitalAccounts = [];
    $pettyCashAccounts = [];
    if ($hasCashAccountId) {
        $stmt = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
        $stmt->execute([$businessId]);
        $capitalAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Get cash (Petty Cash) account IDs from master DB
        $stmt = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash'");
        $stmt->execute([$businessId]);
        $pettyCashAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Build exclude owner capital condition — SAME logic as system dashboard (index.php)
    $excludeOwnerCapital = '';
    if ($hasSourceTypeCol) {
        $excludeOwnerCapital = " AND (source_type IS NULL OR source_type NOT IN ('owner_fund','owner_project'))";
    } elseif ($hasCashAccountId && !empty($capitalAccounts)) {
        $excludeOwnerCapital = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $capitalAccounts) . "))";
    }

    // Exclude owner_project from expense stats (same as index.php)
    $excludeProjectExpense = '';
    if ($hasSourceTypeCol) {
        $excludeProjectExpense = " AND (source_type IS NULL OR source_type != 'owner_project')";
    }

    // Today Income (exclude owner capital — same as system dashboard)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE transaction_date = ? AND transaction_type = 'income'" . $excludeOwnerCapital);
    $stmt->execute([$today]);
    $stats['today_income'] = (float)$stmt->fetchColumn();

    // Today Expense (exclude owner_project — same as chart API)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE transaction_date = ? AND transaction_type = 'expense'" . $excludeProjectExpense);
    $stmt->execute([$today]);
    $stats['today_expense'] = (float)$stmt->fetchColumn();

    // Month Income (exclude owner capital — same as system dashboard)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'income'" . $excludeOwnerCapital);
    $stmt->execute([$thisMonth]);
    $stats['month_income'] = (float)$stmt->fetchColumn();

    // Month Expense (exclude owner_project — same as chart API)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'expense'" . $excludeProjectExpense);
    $stmt->execute([$thisMonth]);
    $stats['month_expense'] = (float)$stmt->fetchColumn();

    // Query Modal Owner stats (from cash_book with cash_account_id filter)
    if ($hasCashAccountId && !empty($capitalAccounts)) {
        $placeholders = implode(',', array_fill(0, count($capitalAccounts), '?'));
        $query = "
            SELECT 
                COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as received,
                COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as used,
                COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) - 
                 SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as balance
            FROM cash_book 
            WHERE cash_account_id IN ($placeholders)
            AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
        ";
        $params = array_merge($capitalAccounts, [$thisMonth]);
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $capitalStats['received'] = (float)($result['received'] ?? 0);
        $capitalStats['used'] = (float)($result['used'] ?? 0);
        $capitalStats['balance'] = (float)($result['balance'] ?? 0);
    } else {
        $capitalStats['received'] = 0;
        $capitalStats['used'] = 0;
        $capitalStats['balance'] = 0;
    }

    // Query Petty Cash stats (based on cash_account_id, NOT payment_method — same as system dashboard)
    if ($hasCashAccountId && !empty($pettyCashAccounts)) {
        $placeholders = implode(',', array_fill(0, count($pettyCashAccounts), '?'));
        $query = "
            SELECT 
                COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as received,
                COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as used,
                COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) - 
                 SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as balance
            FROM cash_book 
            WHERE cash_account_id IN ($placeholders)
            AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
        ";
        $params = array_merge($pettyCashAccounts, [$thisMonth]);
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pettyCashStats['received'] = (float)($result['received'] ?? 0);
        $pettyCashStats['used'] = (float)($result['used'] ?? 0);
        $pettyCashStats['balance'] = (float)($result['balance'] ?? 0);
    } else {
        $pettyCashStats['received'] = 0;
        $pettyCashStats['used'] = 0;
        $pettyCashStats['balance'] = 0;
    }

    // TOTAL KAS OPERASIONAL = Petty Cash balance + Modal Owner balance
    $totalOperationalCash = $pettyCashStats['balance'] + $capitalStats['balance'];

    // TOTAL PENGELUARAN OPERASIONAL = Combined expense
    $totalOperationalExpense = $pettyCashStats['used'] + $capitalStats['used'];

    // ============================================
    // CHART DATA - Expense per Division (for pie chart)
    // ============================================
    $expenseDivisionData = [];
    $stmt = $pdo->prepare("
        SELECT 
            d.division_name,
            d.division_code,
            COALESCE(SUM(cb.amount), 0) as total
        FROM divisions d
        LEFT JOIN cash_book cb ON d.id = cb.division_id 
            AND cb.transaction_type = 'expense'
            AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = ?
            " . ($hasSourceTypeCol ? "AND (cb.source_type IS NULL OR cb.source_type != 'owner_project')" : "") . "
        WHERE d.is_active = 1
        GROUP BY d.id, d.division_name, d.division_code
        HAVING total > 0
        ORDER BY total DESC
    ");
    $stmt->execute([$thisMonth]);
    $expenseDivisionData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Total transactions
    $stmt = $pdo->query("SELECT COUNT(*) FROM cash_book");
    $stats['total_transactions'] = (int)$stmt->fetchColumn();

    // ============================================
    // CASH FLOW - All transactions for current month (like Buku Kas Besar)
    // ============================================
    $stmt = $pdo->prepare("
        SELECT 
            cb.id, cb.transaction_date, cb.description, cb.transaction_type, cb.amount, cb.payment_method,
            d.division_name,
            c.category_name
        FROM cash_book cb
        LEFT JOIN divisions d ON cb.division_id = d.id
        LEFT JOIN categories c ON cb.category_id = c.id
        WHERE DATE_FORMAT(cb.transaction_date, '%Y-%m') = ?
        ORDER BY cb.transaction_date DESC, cb.id DESC
    ");
    $stmt->execute([$thisMonth]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cash flow totals
    $cfTotalIncome = 0;
    $cfTotalExpense = 0;
    foreach ($transactions as $tx) {
        if ($tx['transaction_type'] === 'income') $cfTotalIncome += $tx['amount'];
        else $cfTotalExpense += $tx['amount'];
    }
    $cfBalance = $cfTotalIncome - $cfTotalExpense;

    // ============================================
    // AI HEALTH - Smart Hotel Business Analysis
    // Focuses on hotel operations ONLY (excludes project expenses)
    // ============================================

    // Previous month income/expense for growth comparison
    // Split into separate queries: income excludes capital, expense excludes project
    $lastMonth = date('Y-m', strtotime('-1 month'));
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'income'" . $excludeOwnerCapital);
    $stmt->execute([$lastMonth]);
    $prevIncome = (float)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'expense'" . $excludeProjectExpense);
    $stmt->execute([$lastMonth]);
    $prevExpense = (float)$stmt->fetchColumn();
    $incomeGrowth = $prevIncome > 0 ? (($stats['month_income'] - $prevIncome) / $prevIncome) * 100 : 0;

    // Hotel expense only (exclude project) for AI analysis
    $aiHotelExpense = 0;
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'expense'" . $excludeProjectExpense);
        $stmt->execute([$thisMonth]);
        $aiHotelExpense = (float)$stmt->fetchColumn();
    } catch (Exception $e) {
        $aiHotelExpense = $stats['month_expense'];
    }

    // ============================================
    // FRONTDESK OCCUPANCY - Smart Analysis (using rooms + bookings tables)
    // ============================================
    $occupancyRate = 0;
    $totalRooms = 0;
    $occupiedRooms = 0;
    $monthlyOccupancyRate = 0;
    $avgStayDuration = 0;
    $bookingSourceStats = [];
    $revenuePerRoom = 0;
    $upcomingBookings = 0;
    $todayCheckins = 0;
    $todayCheckouts = 0;
    $prevMonthOccupancy = 0;
    $occupancyGrowth = 0;
    $avgRoomRate = 0;
    $revPAR = 0; // Revenue Per Available Room

    try {
        // Tidak ada auto-checkout saat halaman dibuka: tamu overdue di-checkout lewat alur check-out resmi.

        // Sync room status with bookings (bookings = source of truth)
        $pdo->exec("UPDATE rooms r SET r.status = 'available', r.current_guest_id = NULL, r.updated_at = NOW() WHERE r.status = 'occupied' AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.room_id = r.id AND b.status = 'checked_in')");
        $pdo->exec("UPDATE rooms r SET r.status = 'occupied', r.updated_at = NOW() WHERE r.status NOT IN ('occupied','maintenance','cleaning','blocked') AND EXISTS (SELECT 1 FROM bookings b WHERE b.room_id = r.id AND b.status = 'checked_in')");

        // Total rooms & occupied: use bookings as source of truth (not rooms.status)
        $roomStmt = $pdo->query("
            SELECT COUNT(*) as total,
                   COUNT(CASE WHEN EXISTS (
                       SELECT 1 FROM bookings b 
                       WHERE b.room_id = r.id 
                       AND b.status = 'checked_in'
                   ) THEN 1 END) as occupied
            FROM rooms r
        ");
        $roomData = $roomStmt->fetch(PDO::FETCH_ASSOC);
        $totalRooms = (int)($roomData['total'] ?? 0);
        $occupiedRooms = (int)($roomData['occupied'] ?? 0);
        $occupancyRate = $totalRooms > 0 ? ($occupiedRooms / $totalRooms) * 100 : 0;
    } catch (Exception $e) {
        // Fallback to legacy frontdesk_rooms
        try {
            $roomStmt = $pdo->query("SELECT COUNT(*) as total, COUNT(CASE WHEN status = 'occupied' THEN 1 END) as occupied FROM frontdesk_rooms");
            $roomData = $roomStmt->fetch(PDO::FETCH_ASSOC);
            $totalRooms = (int)($roomData['total'] ?? 0);
            $occupiedRooms = (int)($roomData['occupied'] ?? 0);
            $occupancyRate = $totalRooms > 0 ? ($occupiedRooms / $totalRooms) * 100 : 0;
        } catch (Exception $e2) {
        }
    }

    try {
        // Monthly occupancy: room-nights sold vs available this month
        $daysInMonth = (int)date('t');
        $daysSoFar = (int)date('j');
        $totalRoomNightsAvailable = $totalRooms * $daysSoFar;

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as room_nights
            FROM bookings 
            WHERE status IN ('checked_in', 'checked_out', 'confirmed')
            AND check_in_date <= CURDATE()
            AND check_out_date >= ?
            AND DATE_FORMAT(check_in_date, '%Y-%m') <= ?
        ");
        $stmt->execute([$thisMonth . '-01', $thisMonth]);
        $roomNightsSold = (int)($stmt->fetch(PDO::FETCH_ASSOC)['room_nights'] ?? 0);

        // Better calculation: count distinct room-days occupied
        // Include 'confirmed' bookings where check_in_date has passed (guest is there but not formally checked in)
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT CONCAT(room_id, '-', d.date)) as occupied_nights
            FROM bookings b
            CROSS JOIN (
                SELECT DATE_ADD(?, INTERVAL seq.seq DAY) as date
                FROM (SELECT 0 as seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 
                      UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9
                      UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14
                      UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19
                      UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23 UNION SELECT 24
                      UNION SELECT 25 UNION SELECT 26 UNION SELECT 27 UNION SELECT 28 UNION SELECT 29
                      UNION SELECT 30) seq
                WHERE DATE_ADD(?, INTERVAL seq.seq DAY) <= CURDATE()
                AND DATE_ADD(?, INTERVAL seq.seq DAY) <= LAST_DAY(?)
            ) d
            WHERE b.status IN ('checked_in', 'checked_out', 'confirmed')
            AND b.check_in_date <= d.date
            AND b.check_out_date > d.date
        ");
        $firstDay = $thisMonth . '-01';
        $stmt->execute([$firstDay, $firstDay, $firstDay, $firstDay]);
        $occupiedNights = (int)($stmt->fetch(PDO::FETCH_ASSOC)['occupied_nights'] ?? 0);
        $monthlyOccupancyRate = $totalRoomNightsAvailable > 0 ? ($occupiedNights / $totalRoomNightsAvailable) * 100 : 0;

        // Previous month occupancy for comparison
        $prevFirstDay = date('Y-m-01', strtotime('-1 month'));
        $prevLastDay = date('Y-m-t', strtotime('-1 month'));
        $prevDaysInMonth = (int)date('t', strtotime('-1 month'));
        $prevTotalNights = $totalRooms * $prevDaysInMonth;

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT CONCAT(room_id, '-', d.date)) as occupied_nights
            FROM bookings b
            CROSS JOIN (
                SELECT DATE_ADD(?, INTERVAL seq.seq DAY) as date
                FROM (SELECT 0 as seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 
                      UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9
                      UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14
                      UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19
                      UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23 UNION SELECT 24
                      UNION SELECT 25 UNION SELECT 26 UNION SELECT 27 UNION SELECT 28 UNION SELECT 29
                      UNION SELECT 30) seq
                WHERE DATE_ADD(?, INTERVAL seq.seq DAY) <= ?
            ) d
            WHERE b.status IN ('checked_in', 'checked_out', 'confirmed')
            AND b.check_in_date <= d.date
            AND b.check_out_date > d.date
        ");
        $stmt->execute([$prevFirstDay, $prevFirstDay, $prevLastDay]);
        $prevOccupiedNights = (int)($stmt->fetch(PDO::FETCH_ASSOC)['occupied_nights'] ?? 0);
        $prevMonthOccupancy = $prevTotalNights > 0 ? ($prevOccupiedNights / $prevTotalNights) * 100 : 0;
        $occupancyGrowth = $prevMonthOccupancy > 0 ? $monthlyOccupancyRate - $prevMonthOccupancy : 0;
    } catch (Exception $e) {
        // If bookings table not available, monthly = current snapshot
        $monthlyOccupancyRate = $occupancyRate;
    }

    try {
        // Average stay duration this month (include confirmed with past check-in)
        $stmt = $pdo->prepare("
            SELECT AVG(total_nights) as avg_stay
            FROM bookings 
            WHERE status IN ('checked_in', 'checked_out', 'confirmed')
            AND DATE_FORMAT(check_in_date, '%Y-%m') = ?
        ");
        $stmt->execute([$thisMonth]);
        $avgStayDuration = round((float)($stmt->fetch(PDO::FETCH_ASSOC)['avg_stay'] ?? 0), 1);
    } catch (Exception $e) {
    }

    try {
        // Booking source analysis
        $stmt = $pdo->prepare("
            SELECT booking_source, COUNT(*) as count, COALESCE(SUM(final_price), 0) as revenue
            FROM bookings 
            WHERE status IN ('checked_in', 'checked_out', 'confirmed')
            AND DATE_FORMAT(check_in_date, '%Y-%m') = ?
            GROUP BY booking_source
            ORDER BY count DESC
        ");
        $stmt->execute([$thisMonth]);
        $bookingSourceStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
    }

    try {
        // Revenue per room & RevPAR (include confirmed bookings with past check-in dates)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(final_price), 0) as total_revenue, COUNT(*) as total_bookings,
                   AVG(room_price) as avg_rate
            FROM bookings 
            WHERE status IN ('checked_in', 'checked_out', 'confirmed')
            AND check_in_date <= CURDATE()
            AND DATE_FORMAT(check_in_date, '%Y-%m') = ?
        ");
        $stmt->execute([$thisMonth]);
        $revData = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalBookingRevenue = (float)($revData['total_revenue'] ?? 0);
        $totalBookingsCount = (int)($revData['total_bookings'] ?? 0);
        $avgRoomRate = round((float)($revData['avg_rate'] ?? 0));
        $revenuePerRoom = $totalRooms > 0 ? round($totalBookingRevenue / $totalRooms) : 0;
        $daysSoFar = max(1, (int)date('j'));
        $revPAR = ($totalRooms * $daysSoFar) > 0 ? round($totalBookingRevenue / ($totalRooms * $daysSoFar)) : 0;
    } catch (Exception $e) {
    }

    try {
        // Today's check-ins and check-outs
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE check_in_date = ? AND status IN ('confirmed', 'checked_in')");
        $stmt->execute([$today]);
        $todayCheckins = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE check_out_date = ? AND status = 'checked_in'");
        $stmt->execute([$today]);
        $todayCheckouts = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
    }

    try {
        // Upcoming bookings (next 7 days)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM bookings 
            WHERE check_in_date > CURDATE() AND check_in_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            AND status IN ('pending', 'confirmed')
        ");
        $stmt->execute();
        $upcomingBookings = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
    }

    // Cash flow last 7 days (exclude project expenses)
    $avgDailyFlow = 0;
    try {
        $flowStmt = $pdo->query("
            SELECT SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END) as net_flow,
                   COUNT(DISTINCT DATE(transaction_date)) as days
            FROM cash_book 
            WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)" . $excludeProjectExpense . "
        ");
        $flowData = $flowStmt->fetch(PDO::FETCH_ASSOC);
        $days = max(1, (int)($flowData['days'] ?? 1));
        $avgDailyFlow = (float)($flowData['net_flow'] ?? 0) / $days;
    } catch (Exception $e) {
    }

    // Top expense categories this month (HOTEL ONLY - exclude project expenses)
    $topExpenseCategories = [];
    try {
        $stmt = $pdo->prepare("
            SELECT c.category_name, COALESCE(SUM(cb.amount), 0) as total
            FROM cash_book cb
            LEFT JOIN categories c ON cb.category_id = c.id
            WHERE cb.transaction_type = 'expense' AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = ?" . $excludeProjectExpense . "
            GROUP BY cb.category_id, c.category_name
            HAVING total > 0
            ORDER BY total DESC
            LIMIT 5
        ");
        $stmt->execute([$thisMonth]);
        $topExpenseCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
    }

    // ============================================
    // ATTENDANCE MONITORING DATA
    // ============================================
    $attDate = date('Y-m-d');
    $attEmployees = [];
    $attRecords = [];
    $attStats = ['total' => 0, 'present' => 0, 'late' => 0, 'leave' => 0, 'absent' => 0];
    try {
        // Get all active employees
        $stmt = $pdo->query("SELECT id, employee_code, full_name, position, department FROM payroll_employees WHERE is_active = 1 ORDER BY full_name");
        $attEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $attStats['total'] = count($attEmployees);

        // Get today's attendance records (include scan_3, scan_4, late_minutes, notes)
        $stmt = $pdo->prepare("
            SELECT a.*, e.full_name, e.employee_code, e.position, e.department
            FROM payroll_attendance a
            JOIN payroll_employees e ON e.id = a.employee_id
            WHERE a.attendance_date = ?
            ORDER BY a.check_in_time ASC, e.full_name
        ");
        $stmt->execute([$attDate]);
        $attRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate stats
        $attRecordedIds = [];
        foreach ($attRecords as $ar) {
            $attRecordedIds[] = $ar['employee_id'];
            if ($ar['status'] === 'late') $attStats['late']++;
            elseif ($ar['status'] === 'leave' || $ar['status'] === 'holiday') $attStats['leave']++;
            else $attStats['present']++;
        }
        $attStats['absent'] = $attStats['total'] - count($attRecordedIds);
    } catch (Exception $e) {
        // Attendance data optional
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Check if CQC business
$isCQC = (strtolower($activeBusinessId) === 'cqc') ||
    (stripos($activeBusinessId, 'cqc') !== false) ||
    (stripos($businessName ?? '', 'cqc') !== false);

// CQC PROJECT DATA
$cqcProjects = [];
$cqcExpenses = []; // Recent expenses per project
$cqcCategoryExpenses = []; // Expenses per category per project (for pie chart)
if ($isCQC) {
    try {
        require_once __DIR__ . '/../cqc-projects/db-helper.php';
        $cqcPdo = getCQCDatabaseConnection();

        // ====== GET CQC FINANCIAL STATS ======
        $today = date('Y-m-d');
        $thisMonth = date('Y-m');

        // Try to get income/expense from cash_book if exists
        $hasCashBook = false;
        try {
            $tableCheck = $cqcPdo->query("SHOW TABLES LIKE 'cash_book'");
            $hasCashBook = ($tableCheck && $tableCheck->rowCount() > 0);
        } catch (Exception $e) {
        }

        if ($hasCashBook) {
            // Get stats from cash_book
            try {
                $stmt = $cqcPdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE(transaction_date) = ? AND transaction_type = 'income'");
                $stmt->execute([$today]);
                $stats['today_income'] = (float)$stmt->fetchColumn();

                $stmt = $cqcPdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE(transaction_date) = ? AND transaction_type = 'expense'");
                $stmt->execute([$today]);
                $stats['today_expense'] = (float)$stmt->fetchColumn();

                $stmt = $cqcPdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'income'");
                $stmt->execute([$thisMonth]);
                $stats['month_income'] = (float)$stmt->fetchColumn();

                $stmt = $cqcPdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'expense'");
                $stmt->execute([$thisMonth]);
                $stats['month_expense'] = (float)$stmt->fetchColumn();
            } catch (Exception $e) {
                // cash_book query failed
            }
        }

        // Also add project expenses to stats - use spent_idr directly from projects
        try {
            // Get total spent from all projects for this month (using spent_idr directly)
            $stmt = $cqcPdo->query("SELECT COALESCE(SUM(spent_idr), 0) as total_spent, COALESCE(SUM(budget_idr), 0) as total_budget FROM cqc_projects WHERE status != 'completed'");
            $projTotals = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalProjectSpent = (float)($projTotals['total_spent'] ?? 0);
            $totalProjectBudget = (float)($projTotals['total_budget'] ?? 0);

            // CQC: Budget is NOT income. Income only from invoice payments.
            // Budget is just RAB (cost estimate). Don't override month_income with budget.
            // month_income stays from actual cash_book income entries (invoice payments).
            // Only add project expenses if not already counted in cash_book
            // (expenses from detail.php already sync to cash_book)

            // Also try from cqc_project_expenses table
            try {
                $stmt = $cqcPdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cqc_project_expenses WHERE expense_date = ?");
                $stmt->execute([$today]);
                $stats['today_expense'] += (float)$stmt->fetchColumn();
            } catch (Exception $e) {
            }
        } catch (Exception $e) {
            error_log('CQC stats error: ' . $e->getMessage());
        }

        // Simple query - just get projects
        $stmt = $cqcPdo->query("
            SELECT id, project_name, project_code, status, 
                   progress_percentage, budget_idr, spent_idr,
                   client_name, location, solar_capacity_kwp,
                   start_date, estimated_completion, end_date
            FROM cqc_projects 
            ORDER BY status ASC, progress_percentage DESC
        ");
        $cqcProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate actual spent from expenses for each project
        foreach ($cqcProjects as &$proj) {
            try {
                // Try amount_idr first, then amount
                $stmtSum = $cqcPdo->prepare("SELECT COALESCE(SUM(amount_idr), 0) as total FROM cqc_project_expenses WHERE project_id = ?");
                $stmtSum->execute([$proj['id']]);
                $sumResult = $stmtSum->fetch(PDO::FETCH_ASSOC);
                if ($sumResult && $sumResult['total'] > 0) {
                    $proj['spent_idr'] = $sumResult['total'];
                }
            } catch (Exception $e) {
                // Try with 'amount' column if 'amount_idr' doesn't exist
                try {
                    $stmtSum = $cqcPdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM cqc_project_expenses WHERE project_id = ?");
                    $stmtSum->execute([$proj['id']]);
                    $sumResult = $stmtSum->fetch(PDO::FETCH_ASSOC);
                    if ($sumResult && $sumResult['total'] > 0) {
                        $proj['spent_idr'] = $sumResult['total'];
                    }
                } catch (Exception $e2) {
                    // Keep original spent_idr
                }
            }
        }
        unset($proj);

        // Get recent expenses per project
        foreach ($cqcProjects as $proj) {
            $expenses = [];
            try {
                // Try amount_idr column first
                $stmt = $cqcPdo->prepare("
                    SELECT description, amount_idr as amount, expense_date
                    FROM cqc_project_expenses 
                    WHERE project_id = ? 
                    ORDER BY expense_date DESC, id DESC 
                    LIMIT 5
                ");
                $stmt->execute([$proj['id']]);
                $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Try 'amount' column
                try {
                    $stmt = $cqcPdo->prepare("
                        SELECT description, amount, expense_date
                        FROM cqc_project_expenses 
                        WHERE project_id = ? 
                        ORDER BY expense_date DESC, id DESC 
                        LIMIT 5
                    ");
                    $stmt->execute([$proj['id']]);
                    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e2) {
                    // No expenses
                }
            }

            $cqcExpenses[$proj['id']] = $expenses;

            // Get expenses grouped by category for pie chart
            $cqcCategoryExpenses[$proj['id']] = [];
            try {
                // First try with category join
                $stmtCat = $cqcPdo->prepare("
                    SELECT 
                        COALESCE(c.category_name, 'Lainnya') as category_name,
                        COALESCE(c.category_icon, '📦') as category_icon,
                        SUM(e.amount) as total_amount
                    FROM cqc_project_expenses e
                    LEFT JOIN cqc_expense_categories c ON e.category_id = c.id
                    WHERE e.project_id = ?
                    GROUP BY COALESCE(c.category_name, 'Lainnya'), COALESCE(c.category_icon, '📦')
                    ORDER BY total_amount DESC
                    LIMIT 6
                ");
                $stmtCat->execute([$proj['id']]);
                $result = $stmtCat->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($result)) {
                    $cqcCategoryExpenses[$proj['id']] = $result;
                }
            } catch (Exception $catEx) {
                // If category table doesn't exist, just group as "Lainnya"
                try {
                    $stmtSimple = $cqcPdo->prepare("
                        SELECT 'Lainnya' as category_name, '📦' as category_icon, SUM(amount) as total_amount
                        FROM cqc_project_expenses WHERE project_id = ?
                    ");
                    $stmtSimple->execute([$proj['id']]);
                    $result = $stmtSimple->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($result) && floatval($result[0]['total_amount'] ?? 0) > 0) {
                        $cqcCategoryExpenses[$proj['id']] = $result;
                    }
                } catch (Exception $e2) {
                    // Ignore
                }
            }
        }
    } catch (Exception $e) {
        error_log('CQC project data error: ' . $e->getMessage());
    }
}

// Format rupiah
function rp($num)
{
    return 'Rp ' . number_format($num, 0, ',', '.');
}

$netProfit = $stats['month_income'] - $stats['month_expense'];
$netToday = $stats['today_income'] - $stats['today_expense'];
$expenseRatio = $stats['month_income'] > 0 ? ($stats['month_expense'] / $stats['month_income']) * 100 : 0;
$profitMargin = $stats['month_income'] > 0 ? ($netProfit / $stats['month_income']) * 100 : 0;

// Hotel-only expense ratio (for AI analysis, exclude project expenses)
$aiExpenseRatio = $stats['month_income'] > 0 ? ($aiHotelExpense / $stats['month_income']) * 100 : 0;
$aiNetProfit = $stats['month_income'] - $aiHotelExpense;
$aiProfitMargin = $stats['month_income'] > 0 ? ($aiNetProfit / $stats['month_income']) * 100 : 0;

// ============================================
// AI HEALTH SCORING - Smart Hotel Analysis (7-factor, 0-100)
// Focus: hotel operations only, excludes project expenses
// ============================================
$healthScore = 0;

// Factor 1: Profit Margin - Hotel Only (20 pts)
if ($aiProfitMargin >= 40) $healthScore += 20;
elseif ($aiProfitMargin >= 30) $healthScore += 17;
elseif ($aiProfitMargin >= 20) $healthScore += 14;
elseif ($aiProfitMargin >= 10) $healthScore += 10;
elseif ($aiProfitMargin >= 0) $healthScore += 6;
else $healthScore += 2;

// Factor 2: Income Growth (15 pts)
if ($incomeGrowth >= 20) $healthScore += 15;
elseif ($incomeGrowth >= 10) $healthScore += 12;
elseif ($incomeGrowth >= 5) $healthScore += 10;
elseif ($incomeGrowth >= 0) $healthScore += 7;
elseif ($incomeGrowth >= -5) $healthScore += 4;
else $healthScore += 2;

// Factor 3: Hotel Expense Control (15 pts)
if ($aiExpenseRatio <= 40) $healthScore += 15;
elseif ($aiExpenseRatio <= 50) $healthScore += 13;
elseif ($aiExpenseRatio <= 60) $healthScore += 10;
elseif ($aiExpenseRatio <= 70) $healthScore += 7;
elseif ($aiExpenseRatio <= 80) $healthScore += 4;
else $healthScore += 2;

// Factor 4: Monthly Occupancy (20 pts) - KEY HOTEL METRIC
$occForScore = $monthlyOccupancyRate > 0 ? $monthlyOccupancyRate : $occupancyRate;
if ($occForScore >= 80) $healthScore += 20;
elseif ($occForScore >= 70) $healthScore += 17;
elseif ($occForScore >= 60) $healthScore += 14;
elseif ($occForScore >= 50) $healthScore += 10;
elseif ($occForScore >= 35) $healthScore += 6;
else $healthScore += 3;

// Factor 5: RevPAR Performance (10 pts)
if ($revPAR >= 400000) $healthScore += 10;
elseif ($revPAR >= 300000) $healthScore += 8;
elseif ($revPAR >= 200000) $healthScore += 6;
elseif ($revPAR >= 100000) $healthScore += 4;
elseif ($revPAR > 0) $healthScore += 2;

// Factor 6: Cash Flow Stability (10 pts)
if ($avgDailyFlow > 500000) $healthScore += 10;
elseif ($avgDailyFlow > 0) $healthScore += 7;
elseif ($avgDailyFlow >= -100000) $healthScore += 4;
else $healthScore += 1;

// Factor 7: Booking Pipeline (10 pts)
$pipelineScore = 0;
if ($upcomingBookings >= 5) $pipelineScore += 5;
elseif ($upcomingBookings >= 3) $pipelineScore += 4;
elseif ($upcomingBookings >= 1) $pipelineScore += 2;
if ($todayCheckins >= 2) $pipelineScore += 3;
elseif ($todayCheckins >= 1) $pipelineScore += 2;
if ($occupancyGrowth > 5) $pipelineScore += 2;
elseif ($occupancyGrowth > 0) $pipelineScore += 1;
$healthScore += min(10, $pipelineScore);

// ============================================
// AI ALERTS & RECOMMENDATIONS - Hotel Focused
// ============================================
$aiAlerts = [];
$aiStrengths = [];
$aiFrontdesk = []; // Frontdesk-specific insights

// --- FINANCIAL ALERTS (hotel only, no project) ---
if (!empty($topExpenseCategories)) {
    $topCat = $topExpenseCategories[0];
    $topPct = $aiHotelExpense > 0 ? ($topCat['total'] / $aiHotelExpense) * 100 : 0;
    if ($topPct > 30) {
        $aiAlerts[] = '⚠️ <strong>' . htmlspecialchars($topCat['category_name'] ?? 'Unknown') . '</strong> absorbs ' . number_format($topPct, 0) . '% of hotel expenses (' . rp($topCat['total']) . '). Consider optimizing.';
    }
}

if ($aiExpenseRatio > 75) {
    $aiAlerts[] = '🔴 Hotel expense ratio ' . number_format($aiExpenseRatio, 1) . '% of revenue. Reduce costs or increase revenue urgently.';
} elseif ($aiExpenseRatio > 60) {
    $aiAlerts[] = '🟠 Hotel expense ratio ' . number_format($aiExpenseRatio, 1) . '% — fairly high. Monitor closely.';
}

if ($incomeGrowth < -10) {
    $aiAlerts[] = '📉 Revenue dropped ' . number_format(abs($incomeGrowth), 1) . '% vs last month. Marketing push needed.';
} elseif ($incomeGrowth < 0) {
    $aiAlerts[] = '📉 Revenue slightly down ' . number_format(abs($incomeGrowth), 1) . '% vs last month.';
}

if ($avgDailyFlow < 0) {
    $aiAlerts[] = '💸 Negative daily cash flow (avg ' . rp(abs($avgDailyFlow)) . '/day). Watch cash position.';
}

// --- FRONTDESK INTELLIGENCE ---
// Current occupancy status
if ($totalRooms > 0) {
    $occLabel = $occupancyRate >= 80 ? '🟢 High' : ($occupancyRate >= 50 ? '🟡 Medium' : '🔴 Low');
    $aiFrontdesk[] = '🏨 <strong>Now:</strong> ' . $occupiedRooms . '/' . $totalRooms . ' rooms occupied (' . number_format($occupancyRate, 0) . '%) — ' . $occLabel;
}

// Monthly occupancy trend
if ($monthlyOccupancyRate > 0) {
    $trendIcon = $occupancyGrowth > 0 ? '📈' : ($occupancyGrowth < 0 ? '📉' : '➡️');
    $trendText = abs($occupancyGrowth) > 0 ? ' (' . ($occupancyGrowth > 0 ? '+' : '') . number_format($occupancyGrowth, 1) . '% vs last month)' : '';
    $aiFrontdesk[] = $trendIcon . ' <strong>Monthly occupancy:</strong> ' . number_format($monthlyOccupancyRate, 1) . '%' . $trendText;
}

// RevPAR analysis
if ($revPAR > 0) {
    $revparLabel = $revPAR >= 300000 ? 'Excellent' : ($revPAR >= 200000 ? 'Good' : ($revPAR >= 100000 ? 'Fair' : 'Low'));
    $aiFrontdesk[] = '💰 <strong>RevPAR:</strong> ' . rp($revPAR) . '/night — ' . $revparLabel;
}

// Average room rate
if ($avgRoomRate > 0) {
    $aiFrontdesk[] = '🏷️ <strong>Avg room rate:</strong> ' . rp($avgRoomRate) . '/night';
}

// Average stay duration
if ($avgStayDuration > 0) {
    $stayLabel = $avgStayDuration >= 3 ? '(long stay, great!)' : ($avgStayDuration >= 2 ? '(normal)' : '(short, upsell opportunity)');
    $aiFrontdesk[] = '🛏️ <strong>Avg stay:</strong> ' . $avgStayDuration . ' nights ' . $stayLabel;
}

// Today activity
if ($todayCheckins > 0 || $todayCheckouts > 0) {
    $aiFrontdesk[] = '📋 <strong>Today:</strong> ' . $todayCheckins . ' check-in, ' . $todayCheckouts . ' check-out';
}

// Upcoming bookings
if ($upcomingBookings > 0) {
    $aiFrontdesk[] = '📅 <strong>Next 7 days:</strong> ' . $upcomingBookings . ' bookings';
} else {
    $aiAlerts[] = '📅 No bookings in next 7 days. Boost OTA & social media promotions.';
}

// Booking source insights
if (!empty($bookingSourceStats)) {
    $sourceLabels = ['walk_in' => 'Walk-in', 'phone' => 'Telepon', 'online' => 'Online', 'agoda' => 'Agoda', 'booking' => 'Booking.com', 'tiket' => 'Tiket.com', 'airbnb' => 'Airbnb', 'ota' => 'OTA'];
    $topSource = $bookingSourceStats[0];
    $sourceName = $sourceLabels[$topSource['booking_source']] ?? ucfirst($topSource['booking_source'] ?? 'Lainnya');
    $aiFrontdesk[] = '🔗 <strong>Top source:</strong> ' . $sourceName . ' (' . $topSource['count'] . ' bookings, ' . rp($topSource['revenue']) . ')';

    // Check OTA dependency
    $otaSources = ['agoda', 'booking', 'tiket', 'airbnb', 'ota', 'online'];
    $otaCount = 0;
    $totalCount = 0;
    foreach ($bookingSourceStats as $src) {
        $totalCount += $src['count'];
        if (in_array($src['booking_source'], $otaSources)) $otaCount += $src['count'];
    }
    $otaPct = $totalCount > 0 ? ($otaCount / $totalCount) * 100 : 0;
    if ($otaPct > 80) {
        $aiAlerts[] = '🌐 ' . number_format($otaPct, 0) . '% bookings from OTA. Diversify to direct bookings to reduce commission.';
    } elseif ($otaPct < 30 && $totalCount > 3) {
        $aiStrengths[] = '✅ Direct booking ' . number_format(100 - $otaPct, 0) . '% — saving on OTA commissions';
    }
}

// Occupancy-based alerts
if ($totalRooms > 0 && $occForScore < 40) {
    $aiAlerts[] = '🏨 Low occupancy ' . number_format($occForScore, 0) . '%. Try: OTA flash sale, weekend promos, long stay packages.';
} elseif ($totalRooms > 0 && $occForScore < 60) {
    $aiAlerts[] = '🏨 Occupancy ' . number_format($occForScore, 0) . '% — room for improvement. Optimize OTA listings & reviews.';
}

// Revenue per room insight
if ($revenuePerRoom > 0 && $totalRooms > 0) {
    $rprLabel = $revenuePerRoom >= 5000000 ? 'Excellent' : ($revenuePerRoom >= 3000000 ? 'Good' : ($revenuePerRoom >= 1500000 ? 'Fair' : 'Needs improvement'));
    if ($revenuePerRoom < 1500000) {
        $aiAlerts[] = '💵 Revenue/room only ' . rp($revenuePerRoom) . ' this month. Increase rates or occupancy.';
    }
}

// --- STRENGTHS ---
if ($aiProfitMargin >= 30) {
    $aiStrengths[] = '✅ Excellent profit margin (' . number_format($aiProfitMargin, 1) . '%)';
} elseif ($aiProfitMargin >= 20) {
    $aiStrengths[] = '✅ Healthy profit margin (' . number_format($aiProfitMargin, 1) . '%)';
}
if ($incomeGrowth > 10) {
    $aiStrengths[] = '✅ Revenue growth +' . number_format($incomeGrowth, 1) . '%';
}
if ($aiExpenseRatio < 50) {
    $aiStrengths[] = '✅ Excellent cost control (' . number_format($aiExpenseRatio, 1) . '%)';
}
if ($totalRooms > 0 && $occForScore >= 75) {
    $aiStrengths[] = '✅ High occupancy ' . number_format($occForScore, 0) . '%';
}
if ($avgStayDuration >= 3) {
    $aiStrengths[] = '✅ Avg stay ' . $avgStayDuration . ' nights — guests love it';
}
if ($revPAR >= 300000) {
    $aiStrengths[] = '✅ RevPAR ' . rp($revPAR) . ' — strong room revenue';
}
if ($upcomingBookings >= 5) {
    $aiStrengths[] = '✅ Strong pipeline (' . $upcomingBookings . ' bookings in 7 days)';
}
if ($occupancyGrowth > 10) {
    $aiStrengths[] = '✅ Occupancy up +' . number_format($occupancyGrowth, 1) . '% vs last month';
}

// Health status text
if ($healthScore >= 80) {
    $healthStatus = 'Very Healthy';
    $healthEmoji = '🟢';
} elseif ($healthScore >= 65) {
    $healthStatus = 'Healthy';
    $healthEmoji = '🟡';
} elseif ($healthScore >= 50) {
    $healthStatus = 'Fair';
    $healthEmoji = '🟠';
} else {
    $healthStatus = 'Needs Attention';
    $healthEmoji = '🔴';
}

// ═══ OWNER OVERVIEW: grafik 7 hari, okupansi, pemasukan per divisi, notifikasi End Shift ═══
$ov = ['days' => [], 'inc' => [], 'exp' => [], 'occ7' => [], 'occToday' => ['occupied' => 0, 'vacant' => 0, 'blocked' => 0, 'arriving' => 0, 'total' => 0], 'divInc' => [], 'endShifts' => []];
$ovIsHotel = ($businessType ?? '') === 'hotel';
$ovHari = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
$ovBln = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
try {
    if (isset($pdo) && $pdo instanceof PDO && empty($error)) {
        $exInc = $excludeOwnerCapital ?? '';
        $exExp = $excludeProjectExpense ?? '';
        $d0 = date('Y-m-d', strtotime('-6 days'));
        $incMap = [];
        $expMap = [];
        $st = $pdo->prepare("SELECT transaction_date d, SUM(amount) s FROM cash_book WHERE transaction_type = 'income' AND transaction_date BETWEEN ? AND ?" . $exInc . " GROUP BY transaction_date");
        $st->execute([$d0, date('Y-m-d')]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $incMap[$r['d']] = (float)$r['s'];
        $st = $pdo->prepare("SELECT transaction_date d, SUM(amount) s FROM cash_book WHERE transaction_type = 'expense' AND transaction_date BETWEEN ? AND ?" . $exExp . " GROUP BY transaction_date");
        $st->execute([$d0, date('Y-m-d')]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $expMap[$r['d']] = (float)$r['s'];
        for ($i = 6; $i >= 0; $i--) {
            $dt = date('Y-m-d', strtotime("-$i days"));
            $ov['days'][] = $ovHari[(int)date('w', strtotime($dt))] . ' ' . date('j', strtotime($dt));
            $ov['inc'][] = $incMap[$dt] ?? 0;
            $ov['exp'][] = $expMap[$dt] ?? 0;
        }

        // Okupansi: perhitungan yang SAMA persis dengan widget Front Desk (includes/frontdesk_today.php) — hanya bisnis hotel
        try {
            if ($ovIsHotel && (int)$pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn() > 0) {
                require_once __DIR__ . '/../../includes/frontdesk_today.php';
                $ovAdapter = new class($pdo) {
                    private $p;
                    public function __construct($p) { $this->p = $p; }
                    public function fetchOne($sql, $a = []) { $st = $this->p->prepare($sql); $st->execute($a); return $st->fetch(PDO::FETCH_ASSOC) ?: null; }
                    public function fetchAll($sql, $a = []) { $st = $this->p->prepare($sql); $st->execute($a); return $st->fetchAll(PDO::FETCH_ASSOC); }
                };
                $fdd = fdt_data($ovAdapter);
                $ov['occToday'] = [
                    'occupied' => (int)$fdd['occupied_rooms'], 'vacant' => (int)$fdd['vacant_rooms'], 'blocked' => (int)$fdd['blocked_rooms'],
                    'arriving' => (int)$fdd['arrivals_tomorrow'], 'total' => (int)$fdd['total_rooms'], 'rate' => (float)$fdd['occupancy_rate'],
                ];
                foreach ($fdd['forecast'] as $i => $fc) {
                    $t = strtotime($fc['date']);
                    $ov['occ7'][] = [
                        'label' => $i === 0 ? 'Hari ini' : $ovHari[(int)date('w', $t)],
                        'sub' => date('j', $t) . ' ' . $ovBln[(int)date('n', $t)],
                        'pct' => (int)$fc['pct'], 'rooms' => (int)$fc['rooms'],
                    ];
                }
            }
        } catch (\Throwable $e) {
        }

        // Pemasukan per divisi bulan ini
        try {
            $st = $pdo->prepare("SELECT d.division_name n, SUM(cb.amount) t FROM cash_book cb JOIN divisions d ON d.id = cb.division_id
                WHERE cb.transaction_type = 'income' AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = ?" . $exInc . " GROUP BY d.id, d.division_name HAVING t > 0 ORDER BY t DESC");
            $st->execute([date('Y-m')]);
            $rowsD = $st->fetchAll(PDO::FETCH_ASSOC);
            $other = 0;
            foreach ($rowsD as $k => $r) {
                if ($k < 7) $ov['divInc'][] = ['n' => $r['n'], 't' => (float)$r['t']];
                else $other += (float)$r['t'];
            }
            if ($other > 0) $ov['divInc'][] = ['n' => 'Lainnya', 't' => $other];
        } catch (\Throwable $e) {
        }

        // Notifikasi End Shift terbaru (rekap harian dari print-end-shift-report.php)
        try {
            $uidOv = (int)($_SESSION['user_id'] ?? 0);
            if ($uidOv && isset($masterPdo) && $masterPdo instanceof PDO) {
                $stE = $masterPdo->prepare("SELECT id, data, is_read, created_at FROM notifications WHERE user_id = ? AND type = 'end_shift' ORDER BY id DESC LIMIT 40");
                $stE->execute([$uidOv]);
                $unreadIds = [];
                $dbMine = [($activeConfig['database'] ?? ''), getDbName($activeConfig['database'] ?? '')];
                foreach ($stE->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $dj = json_decode((string)$r['data'], true) ?: [];
                    if (!(in_array($dj['db'] ?? '', $dbMine, true) || ($dj['business_name'] ?? '') === $businessName)) continue;
                    if (count($ov['endShifts']) >= 4) break;
                    $ov['endShifts'][] = ['d' => $dj, 'unread' => !(int)$r['is_read'], 'at' => $r['created_at']];
                    if (!(int)$r['is_read']) $unreadIds[] = (int)$r['id'];
                }
                if ($unreadIds) $masterPdo->exec("UPDATE notifications SET is_read = 1 WHERE id IN (" . implode(',', $unreadIds) . ")");
            }
        } catch (\Throwable $e) {
        }
    }
} catch (\Throwable $e) {
    error_log('owner overview: ' . $e->getMessage());
}
if (!$ovIsHotel) {
    $totalRooms = 0;
    $aiFrontdesk = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Owner Dashboard - <?= htmlspecialchars($businessName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/global-loader.css">
    <script src="<?= BASE_URL ?>/assets/js/global-loader.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            /* ── Midnight Navy + Gold · Light Luxe palette ── */
            --bg: #eef0f5;
            --bg-deep: #e7eaf1;
            --surface: #ffffff;
            --surface-2: #f6f8fc;
            --text-primary: #0d2440;
            /* deep navy — high contrast */
            --text-secondary: #44597a;
            --text-muted: #7e8ea4;
            --border: #e3e7ef;
            --border-strong: #d2d9e5;
            --accent: #122f54;
            /* royal navy */
            --accent-light: #234e83;
            --gold: #c69a3f;
            --gold-light: #e3c372;
            --gold-dark: #9c7526;
            --success: #0f9d6a;
            --success-light: #34d399;
            --danger: #d83a5b;
            --danger-light: #f0708c;
            --warning: #d99a18;
            --shadow-sm: 0 1px 3px rgba(13, 36, 64, 0.06);
            --shadow: 0 8px 26px rgba(13, 36, 64, 0.09);
            --shadow-lg: 0 18px 48px rgba(13, 36, 64, 0.14);
            --shadow-navy: 0 6px 18px rgba(18, 47, 84, 0.30);
            --shadow-gold: 0 6px 18px rgba(198, 154, 63, 0.34);
            --radius: 16px;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background:
                radial-gradient(1200px 600px at 100% -10%, rgba(198, 154, 63, 0.10), transparent 60%),
                radial-gradient(1000px 700px at -10% 110%, rgba(18, 47, 84, 0.06), transparent 55%),
                linear-gradient(180deg, #f1f3f8 0%, #e8ebf2 100%);
            background-attachment: fixed;
            color: var(--text-primary);
            line-height: 1.5;
            min-height: 100vh;
            padding-bottom: 80px;
            -webkit-font-smoothing: antialiased;
        }

        .container {
            max-width: 100%;
            padding: 16px;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            margin-bottom: 16px;
        }

        .brand {
            font-size: 18px;
            font-weight: 700;
            color: var(--accent);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .brand-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            overflow: hidden;
            background: white;
            padding: 4px;
            border: 1px solid rgba(198, 154, 63, 0.55);
            box-shadow: 0 2px 8px rgba(13, 36, 64, 0.12);
        }

        .brand-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }

        .brand-subtext {
            font-size: 11px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .user-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 50px;
            font-size: 12px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 6px;
            padding-right: 6px;
        }

        .dev-badge {
            background-color: var(--danger);
            color: white;
            font-size: 9px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 20px;
        }

        .avatar {
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .btn-refresh {
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            border: none;
            border-radius: 10px;
            padding: 8px 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            color: white;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: var(--shadow-navy);
            white-space: nowrap;
        }

        .btn-refresh:hover {
            box-shadow: 0 8px 20px rgba(18, 47, 84, 0.45);
            transform: translateY(-1px);
        }

        .btn-refresh:active {
            transform: scale(0.95);
        }

        .btn-refresh svg {
            transition: transform 0.5s;
        }

        .btn-refresh:active svg {
            transform: rotate(-180deg);
        }

        /* Mobile Responsive */
        @media (max-width: 600px) {
            .container {
                padding: 10px;
            }

            .header {
                flex-wrap: wrap;
                gap: 8px;
            }

            .brand {
                min-width: 0;
                flex: 1;
            }

            .brand-text {
                font-size: 14px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                max-width: 180px;
            }

            .brand-subtext {
                font-size: 10px;
            }

            .header-right {
                gap: 6px;
            }

            .btn-refresh {
                padding: 7px 10px;
                font-size: 11px;
            }

            .btn-refresh .btn-refresh-text {
                display: none;
            }

            .user-badge {
                padding: 4px;
                font-size: 11px;
            }

            .user-info {
                display: none;
            }

            .info-card {
                padding: 10px 12px;
            }

            .biz-switcher {
                gap: 6px;
            }

            .biz-pill {
                padding: 4px 8px;
                min-width: 100px;
            }

            .biz-pill-name {
                font-size: 10px;
            }

            .biz-pill-type {
                font-size: 8px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }

            .stat-value {
                font-size: 16px;
            }

            .operational-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 400px) {
            .brand-text {
                max-width: 140px;
                font-size: 13px;
            }

            .brand-icon {
                width: 30px;
                height: 30px;
            }

            .btn-refresh {
                padding: 6px 8px;
                border-radius: 8px;
            }
        }

        /* Info Card → Business Switcher */
        .info-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 14px 16px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .info-card.error {
            background-color: #fff1f2;
            color: var(--danger);
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .info-card-icon {
            font-size: 24px;
        }

        .info-card-content {
            flex: 1;
        }

        .info-card-title {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .info-card-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }

        /* Business Switcher */
        .biz-switcher {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
            padding: 2px 0;
        }

        .biz-switcher::-webkit-scrollbar {
            display: none;
        }

        .biz-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 12px;
            background: var(--bg);
            border: 1.5px solid var(--border);
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s;
            flex-shrink: 0;
            cursor: pointer;
        }

        .biz-pill:active {
            transform: scale(0.97);
        }

        .biz-pill.active {
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            border-color: var(--gold);
            box-shadow: var(--shadow-navy);
        }

        .biz-pill-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            overflow: hidden;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 16px;
        }

        .biz-pill.active .biz-pill-icon {
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
        }

        .biz-pill-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .biz-pill-text {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }

        .biz-pill-name {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .biz-pill.active .biz-pill-name {
            color: white;
        }

        .biz-pill-type {
            font-size: 9px;
            color: var(--text-muted);
            text-transform: capitalize;
        }

        .biz-pill.active .biz-pill-type {
            color: rgba(255, 255, 255, 0.7);
        }
        }

        /* DB Info */
        .db-info {
            background: #f0fdf4;
            color: #166534;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 11px;
            margin-bottom: 16px;
        }

        .db-info.error {
            background: #fee2e2;
            color: #dc2626;
        }

        /* Hero Section - Royal Luxe (Navy + Gold) */
        .hero {
            background: linear-gradient(160deg, #ffffff 0%, #fafbfe 35%, #f3f6fb 70%, #ffffff 100%);
            border-radius: 20px;
            padding: 22px 18px 14px;
            margin-bottom: 16px;
            color: var(--text-primary);
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-lg), inset 0 1px 0 rgba(255, 255, 255, 0.8);
            border: 1px solid var(--border);
            border-top: 3px solid var(--gold);
        }

        .hero::before {
            content: '';
            position: absolute;
            top: -80px;
            right: -60px;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle, rgba(198, 154, 63, 0.16) 0%, transparent 70%);
            border-radius: 50%;
        }

        .hero::after {
            content: '';
            position: absolute;
            bottom: -40px;
            left: -30px;
            width: 160px;
            height: 160px;
            background: radial-gradient(circle, rgba(18, 47, 84, 0.07) 0%, transparent 70%);
            border-radius: 50%;
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .hero-title {
            font-size: 14px;
            font-weight: 700;
            letter-spacing: -0.2px;
            color: var(--text-primary);
        }

        .hero-subtitle {
            font-size: 10px;
            color: var(--text-muted);
            font-weight: 400;
            letter-spacing: 0.3px;
        }

        /* Donut Chart */
        .pie-wrapper {
            position: relative;
            width: 140px;
            height: 140px;
            flex-shrink: 0;
        }

        #pieChart {
            filter: drop-shadow(0 0 12px rgba(16, 185, 129, 0.12)) drop-shadow(0 4px 8px rgba(0, 0, 0, 0.08));
        }

        .pie-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1.5px solid rgba(0, 0, 0, 0.06);
            border-radius: 50%;
            width: 68px;
            height: 68px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.6);
        }

        .pie-center-label {
            font-size: 7px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 600;
            margin-bottom: 1px;
        }

        .pie-center-value {
            font-size: 20px;
            font-weight: 800;
            font-family: 'Inter', system-ui, sans-serif;
            letter-spacing: -1px;
        }

        .pie-center-value.positive {
            color: #10b981;
            text-shadow: none;
        }

        .pie-center-value.negative {
            color: #ef4444;
            text-shadow: none;
        }

        .pie-center-value.zero {
            color: #9ca3af;
        }

        /* Financial Stat Rows */
        .fp-stat-row {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px 10px;
            background: rgba(0, 0, 0, 0.02);
            border: 1px solid rgba(0, 0, 0, 0.05);
            border-radius: 10px;
        }

        .fp-stat-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .fp-stat-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 1px;
            min-width: 0;
        }

        .fp-stat-label {
            font-size: 9px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .fp-stat-val {
            font-size: 14px;
            font-weight: 700;
            letter-spacing: -0.3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .fp-stat-pct {
            font-size: 10px;
            font-weight: 700;
            color: #cbd5e1;
            flex-shrink: 0;
        }

        /* Kas Harian Section - Clean Light */
        .kas-harian-section {
            margin: 16px 0;
            background: linear-gradient(180deg, #ffffff 0%, #f7fbff 100%);
            border-radius: 16px;
            padding: 18px;
            border: 1px solid #d9e6f4;
            box-shadow: 0 10px 28px rgba(15, 42, 77, 0.08);
        }

        .kas-harian-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .kas-harian-title {
            font-size: 13px;
            font-weight: 800;
            color: #17365d;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .kas-harian-date {
            font-size: 10px;
            color: #3f5f86;
            font-weight: 600;
            background: #eaf3ff;
            border: 1px solid #cfe0f4;
            padding: 3px 10px;
            border-radius: 20px;
        }

        /* Cash Hero - Start Cash + Cash Available */
        .kas-hero-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 12px;
        }

        .kas-hero-card {
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid #d9e6f4;
        }

        .kas-hero-card.start {
            background: #f8fbff;
        }

        .kas-hero-card.available-pos {
            background: #ecfdf5;
            border-color: #86efac;
        }

        .kas-hero-card.available-neg {
            background: #fff1f2;
            border-color: #fecdd3;
        }

        .kas-hero-label {
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 4px;
        }

        .kas-hero-label.muted {
            color: #5f7492;
        }

        .kas-hero-label.green {
            color: #0f9d6a;
        }

        .kas-hero-label.red {
            color: #d83a5b;
        }

        .kas-hero-value {
            font-size: 15px;
            font-weight: 800;
            font-family: 'Monaco', 'Courier New', monospace;
            letter-spacing: -0.3px;
        }

        .kas-hero-value.white {
            color: #17365d;
        }

        .kas-hero-value.green {
            color: #10b981;
        }

        .kas-hero-value.red {
            color: #ef4444;
        }

        /* Summary strip - REMOVED */
        .kas-summary-strip {
            display: none;
        }

        .kas-table-wrapper {
            max-height: 260px;
            overflow-y: auto;
            border-radius: 10px;
            background: #ffffff;
            border: 1px solid #d9e6f4;
            scrollbar-width: thin;
            scrollbar-color: #c8d7ea transparent;
        }

        .kas-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .kas-table th {
            background: #f3f8ff;
            padding: 7px 10px;
            text-align: left;
            font-weight: 700;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #5f7492;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .kas-table td {
            padding: 7px 10px;
            border-bottom: 1px solid #edf2f8;
            color: #334155;
        }

        .kas-table tr:last-child td {
            border-bottom: none;
        }

        .kas-table tr:hover td {
            background: #f8fbff;
        }

        .kas-table .text-right {
            text-align: right;
        }

        .kas-badge-masuk,
        .kas-badge-keluar {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 8px;
            font-weight: 800;
            letter-spacing: 0.3px;
        }

        .kas-badge-masuk {
            background: #dcfce7;
            color: #0f9d6a;
        }

        .kas-badge-keluar {
            background: #ffe4e6;
            color: #d83a5b;
        }

        .kas-pay-badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 7px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-left: 4px;
            vertical-align: middle;
        }

        .kas-pay-badge.cash {
            background: #dcfce7;
            color: #0f9d6a;
        }

        .kas-pay-badge.tf,
        .kas-pay-badge.transfer {
            background: #dbeafe;
            color: #2563eb;
        }

        .kas-pay-badge.qr {
            background: #fef3c7;
            color: #b45309;
        }

        .kas-pay-badge.edc,
        .kas-pay-badge.debit {
            background: #ede9fe;
            color: #7c3aed;
        }

        .kas-pay-badge.other {
            background: #e2e8f0;
            color: #475569;
        }

        .kas-amount-masuk {
            color: #10b981;
            font-weight: 700;
        }

        .kas-amount-keluar {
            color: #ef4444;
            font-weight: 700;
        }

        .kas-empty {
            text-align: center;
            padding: 24px;
            color: #64748b;
            font-size: 12px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 16px;
        }

        .stat-card {
            background: var(--surface);
            border-radius: 12px;
            padding: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .stat-label {
            font-size: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .stat-value {
            font-size: 20px;
            font-weight: 700;
        }

        .stat-value.income {
            color: var(--success);
        }

        .stat-value.expense {
            color: var(--danger);
        }

        .stat-sub {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* Operational Section */
        .operational-section {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.8) 0%, rgba(240, 249, 255, 0.5) 100%);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(0, 113, 227, 0.1);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
        }

        .operational-title {
            font-size: 13px;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
            letter-spacing: -0.3px;
        }

        .operational-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }

        .op-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(0, 0, 0, 0.04);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .op-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--gradient-start), var(--gradient-end));
            opacity: 0.8;
            transition: opacity 0.3s ease;
        }

        .op-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
            border-color: rgba(0, 113, 227, 0.2);
        }

        .op-card:hover::before {
            opacity: 1;
        }

        .op-card.modal-owner {
            --gradient-start: #10b981;
            --gradient-end: #34d399;
        }

        .op-card.petty-cash {
            --gradient-start: #f59e0b;
            --gradient-end: #fbbf24;
        }

        .op-card.digunakan {
            --gradient-start: #f43f5e;
            --gradient-end: #fb7185;
        }

        .op-card.total-kas {
            --gradient-start: #0071e3;
            --gradient-end: #0055b8;
        }

        .op-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-weight: 700;
            color: #6c757d;
            margin-bottom: 6px;
        }

        .op-value {
            font-size: 16px;
            font-weight: 800;
            color: #1a1a1a;
            font-family: 'Monaco', 'Courier New', monospace;
            line-height: 1.2;
        }

        .op-detail-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 12px 16px;
            background: linear-gradient(135deg, var(--primary) 0%, #0055b8 100%);
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            text-align: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 12px rgba(0, 113, 227, 0.25);
            cursor: pointer;
            letter-spacing: -0.2px;
        }

        .op-detail-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 113, 227, 0.35);
        }

        .op-detail-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(0, 113, 227, 0.25);
        }

        /* AI Health Section */
        <?php
        // Dynamic color scheme based on health score
        if ($healthScore >= 80) {
            $aiBg = 'linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%)';
            $aiBadgeBg = '#10b981';
            $aiTitleColor = '#065f46';
            $aiContentColor = '#064e3b';
            $aiScoreLabelColor = '#065f46';
            $aiSectionTitleColor = '#065f46';
            $aiBorderTint = 'rgba(6, 95, 70, 0.1)';
            $aiTrackBg = 'rgba(6, 95, 70, 0.15)';
        } elseif ($healthScore >= 65) {
            $aiBg = 'linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%)';
            $aiBadgeBg = '#3b82f6';
            $aiTitleColor = '#1e3a5f';
            $aiContentColor = '#1e3a5f';
            $aiScoreLabelColor = '#1e3a5f';
            $aiSectionTitleColor = '#1e3a5f';
            $aiBorderTint = 'rgba(30, 58, 95, 0.1)';
            $aiTrackBg = 'rgba(30, 58, 95, 0.15)';
        } elseif ($healthScore >= 50) {
            $aiBg = 'linear-gradient(135deg, #fef3c7 0%, #fde68a 100%)';
            $aiBadgeBg = '#f59e0b';
            $aiTitleColor = '#92400e';
            $aiContentColor = '#78350f';
            $aiScoreLabelColor = '#78350f';
            $aiSectionTitleColor = '#92400e';
            $aiBorderTint = 'rgba(146, 64, 14, 0.1)';
            $aiTrackBg = 'rgba(146, 64, 14, 0.15)';
        } else {
            $aiBg = 'linear-gradient(135deg, #fee2e2 0%, #fecaca 100%)';
            $aiBadgeBg = '#ef4444';
            $aiTitleColor = '#7f1d1d';
            $aiContentColor = '#7f1d1d';
            $aiScoreLabelColor = '#7f1d1d';
            $aiSectionTitleColor = '#7f1d1d';
            $aiBorderTint = 'rgba(127, 29, 29, 0.1)';
            $aiTrackBg = 'rgba(127, 29, 29, 0.15)';
        }
        ?>.ai-card {
            background: <?= $aiBg ?>;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .ai-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .ai-title-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .ai-badge {
            background: <?= $aiBadgeBg ?>;
            color: white;
            font-size: 9px;
            padding: 3px 8px;
            border-radius: 20px;
            font-weight: 600;
        }

        .ai-title {
            font-size: 14px;
            font-weight: 600;
            color: <?= $aiTitleColor ?>;
        }

        .ai-score {
            background: white;
            padding: 8px 14px;
            border-radius: 12px;
            text-align: center;
        }

        .ai-score-value {
            font-size: 20px;
            font-weight: 700;
            color: <?= $healthScore >= 80 ? '#10b981' : ($healthScore >= 65 ? '#3b82f6' : ($healthScore >= 50 ? '#f59e0b' : '#f43f5e')) ?>;
        }

        .ai-score-label {
            font-size: 9px;
            color: <?= $aiScoreLabelColor ?>;
            text-transform: uppercase;
        }

        .ai-content {
            font-size: 13px;
            color: <?= $aiContentColor ?>;
            line-height: 1.6;
        }

        .ai-status {
            font-size: 13px;
            margin-bottom: 10px;
        }

        .ai-section-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: <?= $aiSectionTitleColor ?>;
            margin: 12px 0 6px;
            letter-spacing: 0.5px;
        }

        .ai-alert-item {
            font-size: 12px;
            line-height: 1.5;
            padding: 6px 0;
            border-bottom: 1px solid <?= $aiBorderTint ?>;
        }

        .ai-alert-item:last-child {
            border-bottom: none;
        }

        .ai-expense-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
            font-size: 11px;
        }

        .ai-expense-name {
            flex: 1;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ai-expense-amount {
            font-weight: 600;
            white-space: nowrap;
        }

        .ai-expense-track {
            flex: 0 0 60px;
            height: 4px;
            background: <?= $aiTrackBg ?>;
            border-radius: 2px;
            overflow: hidden;
        }

        .ai-expense-fill {
            height: 100%;
            border-radius: 2px;
            background: #ef4444;
        }

        /* Summary Card */
        .summary-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .summary-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .summary-title::before {
            content: '';
            width: 4px;
            height: 16px;
            background: linear-gradient(180deg, var(--accent), var(--accent-light));
            border-radius: 2px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .summary-row.total {
            font-weight: 700;
            font-size: 15px;
            padding-top: 16px;
            margin-top: 8px;
            border-top: 2px solid var(--border);
            border-bottom: none;
        }

        /* Transactions */
        .tx-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .tx-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tx-title::before {
            content: '';
            width: 4px;
            height: 16px;
            background: linear-gradient(180deg, var(--warning), #fbbf24);
            border-radius: 2px;
        }

        .tx-list {
            list-style: none;
        }

        .tx-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 11px 0;
            border-bottom: 1px solid var(--border);
            gap: 10px;
        }

        .tx-item:last-child {
            border-bottom: none;
        }

        .tx-desc {
            font-size: 12.5px;
            color: var(--text-primary);
            margin-bottom: 3px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px;
            line-height: 1.4;
        }

        .tx-date {
            font-size: 10px;
            color: var(--text-muted);
        }

        .tx-amount {
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
            letter-spacing: -0.2px;
        }

        .tx-amount.income {
            color: var(--success);
        }

        .tx-amount.expense {
            color: var(--danger);
        }

        .tx-method {
            display: inline-flex;
            align-items: center;
            font-size: 7.5px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            vertical-align: middle;
            flex-shrink: 0;
        }

        .tx-method.cash {
            background: #dcfce7;
            color: #16a34a;
        }

        .tx-method.transfer,
        .tx-method.tf {
            background: #dbeafe;
            color: #2563eb;
        }

        .tx-method.qr {
            background: #fef3c7;
            color: #d97706;
        }

        .tx-method.debit,
        .tx-method.edc {
            background: #f3e8ff;
            color: #9333ea;
        }

        .tx-method.other {
            background: #f1f5f9;
            color: #64748b;
        }

        /* Footer Nav */
        .nav-bottom {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            display: flex;
            justify-content: space-around;
            padding: 10px 0;
            border-top: 1px solid var(--border);
            box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.05);
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            font-size: 10px;
            color: var(--text-muted);
            transition: color 0.2s;
        }

        .nav-item.active {
            color: var(--accent);
        }

        .nav-icon {
            font-size: 20px;
            margin-bottom: 2px;
        }

        /* Hero Today Row - Clean */
        .hero-today-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(0, 0, 0, 0.03);
            border-radius: 12px;
            padding: 12px 14px;
            margin-top: 14px;
            gap: 8px;
        }

        .hero-today-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
        }

        .hero-today-label {
            font-size: 9px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
            font-weight: 500;
        }

        .hero-today-value {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: -0.3px;
            color: #334155;
        }

        .hero-today-value.income {
            color: #10b981;
        }

        .hero-today-value.expense {
            color: #ef4444;
        }

        .hero-today-divider {
            width: 1px;
            height: 28px;
            background: rgba(0, 0, 0, 0.08);
        }

        /* Dev Badge */
        .dev-badge {
            position: fixed;
            top: 10px;
            right: 10px;
            background: #f43f5e;
            color: white;
            font-size: 10px;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
            z-index: 1000;
        }

        /* Expense Division Pie Chart */
        .expense-division-card {
            background: var(--surface);
            border-radius: 14px;
            margin-top: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            overflow: hidden;
        }

        .expense-division-header {
            padding: 12px 14px 10px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .expense-division-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .expense-division-title .icon-circle {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }

        .expense-month-input {
            font-size: 11px;
            padding: 4px 8px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg);
            color: var(--text-primary);
            outline: none;
            height: 28px;
        }

        .expense-month-input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(18, 47, 84, 0.14);
        }

        .expense-division-body {
            position: relative;
            height: 220px;
            padding: 10px 14px 6px;
        }

        .expense-division-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--text-muted);
            font-size: 12px;
        }

        .expense-division-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 12px;
            padding: 6px 14px 12px;
            justify-content: center;
        }

        .edl-item {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 10px;
            color: var(--text-secondary);
        }

        .edl-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* Mobile Optimizations */
        @media (max-width: 380px) {
            .pie-wrapper {
                width: 110px;
                height: 110px;
            }

            .pie-center {
                width: 56px;
                height: 56px;
            }

            .pie-center-value {
                font-size: 16px;
            }

            .fp-stat-val {
                font-size: 12px;
            }

            .fp-stat-row {
                padding: 5px 8px;
            }

            .hero-today-value {
                font-size: 11px;
            }
        }

        @media (max-width: 340px) {
            .operational-grid {
                grid-template-columns: 1fr;
            }

            .expense-division-body {
                height: 200px;
            }
        }

        /* CQC Status Badges */
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

        /* ══════════════════════════════════════════ */
        /* ATTENDANCE MONITORING                      */
        /* ══════════════════════════════════════════ */
        .att-section {
            margin-top: 20px;
        }

        .att-hero {
            background: #ffffff;
            border-radius: 18px;
            padding: 18px 16px 14px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(0, 0, 0, 0.06);
        }

        .att-hero::before {
            content: '';
            position: absolute;
            top: -30px;
            right: -30px;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(198, 154, 63, 0.12) 0%, transparent 70%);
        }

        .att-hero-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            position: relative;
            z-index: 1;
        }

        .att-hero-title {
            font-size: 14px;
            font-weight: 800;
            color: var(--text-primary);
            letter-spacing: 0.3px;
        }

        .att-hero-badge {
            background: rgba(18, 47, 84, 0.07);
            border: 1px solid rgba(198, 154, 63, 0.45);
            color: var(--accent);
            font-size: 8px;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 20px;
            letter-spacing: 1px;
            text-transform: uppercase;
            animation: attPulse 2s ease-in-out infinite;
        }

        @keyframes attPulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.6;
            }
        }

        .att-date-nav {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 16px;
            position: relative;
            z-index: 1;
        }

        .att-date-btn {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 10px;
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }

        .att-date-btn:active {
            background: #e2e8f0;
            transform: scale(0.92);
        }

        .att-date-label {
            font-size: 13px;
            font-weight: 800;
            color: #1e293b;
            letter-spacing: 0.3px;
            min-width: 160px;
            text-align: center;
        }

        /* Stats row inside hero */
        .att-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
            position: relative;
            z-index: 1;
        }

        .att-stat-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 10px 6px;
            text-align: center;
            border: 1px solid rgba(0, 0, 0, 0.05);
            transition: background 0.2s;
        }

        .att-stat-card:active {
            background: #f1f5f9;
        }

        .att-stat-num {
            font-size: 22px;
            font-weight: 900;
            line-height: 1.1;
            color: #1e293b;
        }

        .att-stat-label {
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-top: 3px;
        }

        .att-stat-card.asc-present .att-stat-label {
            color: #16a34a;
        }

        .att-stat-card.asc-late .att-stat-label {
            color: #d97706;
        }

        .att-stat-card.asc-leave .att-stat-label {
            color: #2563eb;
        }

        .att-stat-card.asc-absent .att-stat-label {
            color: #dc2626;
        }

        .att-stat-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 2px;
            vertical-align: middle;
        }

        .asd-present {
            background: #22c55e;
        }

        .asd-late {
            background: #f59e0b;
        }

        .asd-leave {
            background: #3b82f6;
        }

        .asd-absent {
            background: #ef4444;
        }

        /* Staff list */
        .att-list-wrap {
            background: var(--surface);
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            margin-top: 12px;
        }

        .att-list-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
        }

        .att-list-title {
            font-size: 12px;
            font-weight: 800;
            color: var(--text-primary);
            letter-spacing: 0.2px;
        }

        .att-list-count {
            font-size: 9px;
            color: var(--accent);
            font-weight: 700;
            background: rgba(18, 47, 84, 0.07);
            padding: 3px 10px;
            border-radius: 20px;
        }

        .att-emp-row {
            display: flex;
            align-items: center;
            padding: 9px 14px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.03);
            gap: 10px;
            transition: background 0.15s;
        }

        .att-emp-row:last-child {
            border-bottom: none;
        }

        .att-emp-row:active {
            background: rgba(18, 47, 84, 0.04);
        }

        .att-emp-avatar {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 900;
            color: #fff;
            flex-shrink: 0;
        }

        .att-emp-avatar.av-present {
            background: linear-gradient(135deg, #16a34a, #4ade80);
        }

        .att-emp-avatar.av-late {
            background: linear-gradient(135deg, #d97706, #fbbf24);
        }

        .att-emp-avatar.av-leave {
            background: linear-gradient(135deg, #2563eb, #60a5fa);
        }

        .att-emp-avatar.av-absent {
            background: linear-gradient(135deg, #dc2626, #f87171);
        }

        .att-emp-info {
            flex: 1;
            min-width: 0;
        }

        .att-emp-name {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .att-emp-meta {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 2px;
            flex-wrap: wrap;
        }

        .att-emp-pos {
            font-size: 9px;
            color: var(--text-muted);
            font-weight: 600;
        }

        .att-emp-scans {
            text-align: right;
            flex-shrink: 0;
        }

        .att-scan-pills {
            display: flex;
            gap: 3px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .att-scan-pill {
            background: rgba(18, 47, 84, 0.05);
            border: 1px solid rgba(18, 47, 84, 0.1);
            border-radius: 6px;
            padding: 2px 6px;
            font-size: 9px;
            display: flex;
            align-items: center;
            gap: 2px;
        }

        .att-scan-pill .att-sp-lbl {
            color: var(--text-muted);
            font-weight: 600;
            font-size: 7px;
            text-transform: uppercase;
        }

        .att-scan-pill .att-sp-val {
            color: var(--text-primary);
            font-weight: 800;
            font-family: 'Monaco', 'Courier New', monospace;
            font-size: 9px;
        }

        .att-emp-note {
            font-size: 8px;
            color: #f59e0b;
            font-weight: 600;
            margin-top: 2px;
            text-align: right;
        }

        .att-emp-hours {
            font-size: 8px;
            color: var(--accent);
            font-weight: 800;
            background: rgba(18, 47, 84, 0.06);
            padding: 1px 6px;
            border-radius: 4px;
            display: inline-block;
            margin-top: 2px;
        }

        .att-status-badge {
            display: inline-block;
            font-size: 7px;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .asb-present {
            background: #dcfce7;
            color: #15803d;
        }

        .asb-late {
            background: #fef3c7;
            color: #92400e;
        }

        .asb-leave {
            background: #dbeafe;
            color: #1e40af;
        }

        .asb-absent {
            background: #fee2e2;
            color: #991b1b;
        }

        .asb-holiday {
            background: #f3e8ff;
            color: #6b21a8;
        }

        .asb-half_day {
            background: #ffedd5;
            color: #9a3412;
        }

        .att-late-tag {
            font-size: 7px;
            color: #f59e0b;
            font-weight: 800;
            background: rgba(245, 158, 11, 0.1);
            padding: 1px 5px;
            border-radius: 10px;
        }

        /* ═════════ Tema Narayana: navy-biru seragam dengan sistem utama ═════════ */
        :root {
            --accent: #1e3a8a; --accent-light: #2563eb;
            --gold: #2563eb; --gold-light: #60a5fa; --gold-dark: #1e3a8a;
            --shadow-gold: 0 6px 18px rgba(37, 99, 235, .28);
        }
        body { background: #f1f5f9 !important; }
        body > .dev-badge { display: none !important; }
        .ow-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; margin-bottom: 14px; border-radius: 16px; color: #fff;
            background: linear-gradient(120deg, #0f1f4d 0%, #1e3a8a 55%, #2563eb 100%); box-shadow: 0 12px 28px -16px rgba(15, 31, 77, .8); position: relative; overflow: hidden; }
        .ow-top::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: linear-gradient(180deg, #fbbf24, #f59e0b); }
        .ow-brand { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .ow-logo { width: 42px; height: 42px; border-radius: 12px; overflow: hidden; flex-shrink: 0; background: rgba(255, 255, 255, .14); border: 1px solid rgba(255, 255, 255, .25); display: grid; place-items: center; font-size: 20px; }
        .ow-logo img { width: 100%; height: 100%; object-fit: cover; }
        .ow-name { font-size: 1.02rem; font-weight: 800; letter-spacing: -.01em; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ow-sub { font-size: .7rem; color: rgba(219, 234, 254, .85); margin-top: 1px; }
        .ow-top-r { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
        .ow-btn { display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px; border-radius: 10px; border: 1px solid rgba(255, 255, 255, .25); background: rgba(255, 255, 255, .12); color: #fff; font: 700 .72rem inherit; font-family: inherit; cursor: pointer; }
        .ow-btn:hover { background: rgba(255, 255, 255, .22); }
        .ow-user { display: flex; align-items: center; gap: 8px; height: 34px; padding: 0 12px 0 4px; border-radius: 999px; border: 1px solid rgba(255, 255, 255, .25); background: rgba(255, 255, 255, .12); }
        .ow-av { width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; background: #fff; color: #1e3a8a; font-weight: 800; font-size: .72rem; }
        .ow-un { font-size: .74rem; font-weight: 700; color: #fff; }
        .ow-user em { font-style: normal; font-size: .56rem; font-weight: 800; padding: 1px 6px; border-radius: 999px; background: #fbbf24; color: #78350f; }

        .ow-sec { display: flex; flex-direction: column; gap: 12px; margin-bottom: 14px; }
        .ow-card { background: #fff; border: 1px solid #e8edf3; border-radius: 16px; padding: 14px 16px; box-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 8px 22px -16px rgba(15, 23, 42, .25); min-width: 0; }
        .ow-card-h { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .ow-card-h b { display: block; font-size: .86rem; font-weight: 800; color: #0f172a; }
        .ow-card-h small { display: block; font-size: .68rem; color: #64748b; margin-top: 1px; }
        .ow-ic { width: 32px; height: 32px; border-radius: 10px; display: grid; place-items: center; flex-shrink: 0; }
        .ow-ic svg { width: 17px; height: 17px; }
        .ic-navy { background: #e0e7ff; color: #1e3a8a; } .ic-blue { background: #dbeafe; color: #1d4ed8; } .ic-green { background: #dcfce7; color: #16a34a; } .ic-violet { background: #ede9fe; color: #6d28d9; }
        .ow-grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .ow-chart { position: relative; height: 210px; }

        .ow-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
        .ow-kpi { background: #fff; border: 1px solid #e8edf3; border-left: 4px solid var(--kc, #2563eb); border-radius: 14px; padding: 10px 13px; box-shadow: 0 8px 22px -18px rgba(15, 23, 42, .3); }
        .ow-kpi span { display: block; font-size: .62rem; font-weight: 800; text-transform: uppercase; letter-spacing: .07em; color: #64748b; }
        .ow-kpi b { display: block; font-size: 1.05rem; font-weight: 800; margin-top: 3px; color: #0f172a; letter-spacing: -.01em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ow-kpi small { display: block; font-size: .64rem; color: #94a3b8; margin-top: 1px; }
        .k-green { --kc: #10b981; } .k-green b { color: #047857; } .k-red { --kc: #ef4444; } .k-red b { color: #b91c1c; } .k-blue { --kc: #2563eb; } .k-blue b { color: #1d4ed8; }
        .k-navy { --kc: #1e3a8a; } .k-violet { --kc: #8b5cf6; }

        .ow-es-list { display: flex; flex-direction: column; gap: 8px; }
        .ow-es-item { padding: 9px 12px; border: 1px solid #e8edf3; border-radius: 12px; background: #f8fafc; }
        .ow-es-item.unread { background: #eff6ff; border-color: #bfdbfe; }
        .ow-es-top { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
        .ow-es-who { font-size: .78rem; font-weight: 700; color: #0f172a; }
        .ow-es-at { font-size: .66rem; color: #64748b; white-space: nowrap; }
        .ow-new { font-style: normal; font-size: .56rem; font-weight: 800; padding: 1px 7px; border-radius: 999px; background: #2563eb; color: #fff; margin-left: 4px; vertical-align: 1px; }
        .ow-es-chips { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 6px; }
        .ow-es-chips span { font-size: .66rem; padding: 2px 9px; border-radius: 999px; background: #fff; border: 1px solid #e2e8f0; color: #475569; }
        .ow-es-chips span b { color: #0f172a; font-weight: 800; }
        .ow-es-chips .in b { color: #047857; } .ow-es-chips .out b { color: #b91c1c; }
        .ow-empty { padding: 14px; text-align: center; font-size: .74rem; color: #64748b; background: #f8fafc; border-radius: 12px; border: 1px dashed #dbe3ee; }

        .ow-occ { display: flex; align-items: center; gap: 20px; }
        .ow-donut { position: relative; width: 150px; height: 150px; flex-shrink: 0; }
        .ow-donut-c { position: absolute; inset: 0; display: grid; place-content: center; text-align: center; pointer-events: none; }
        .ow-donut-c b { font-size: 1.35rem; font-weight: 800; color: #0f172a; line-height: 1; }
        .ow-donut-c small { font-size: .66rem; color: #64748b; }
        .ow-legend { list-style: none; flex: 1; min-width: 0; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 9px; }
        .ow-legend li { display: flex; align-items: center; gap: 8px; font-size: .78rem; color: #334155; }
        .ow-legend li i { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
        .ow-legend li b { margin-left: auto; color: #0f172a; font-weight: 800; }

        .ow-bars { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; align-items: end; height: 190px; }
        .ow-bar { display: flex; flex-direction: column; align-items: center; height: 100%; text-align: center; }
        .ow-bar em { font-style: normal; font-size: .66rem; font-weight: 800; color: #334155; margin-bottom: 4px; }
        .ow-bar-t { flex: 1; width: 100%; max-width: 34px; border-radius: 10px; background: #f1f5f9; border: 1px solid #e8edf3; display: flex; align-items: flex-end; overflow: hidden; }
        .ow-bar-t i { display: block; width: 100%; border-radius: 8px 8px 0 0; background: linear-gradient(180deg, #60a5fa, #2563eb); }
        .ow-bar.now b { color: #2563eb; }
        .ow-bar b { font-size: .66rem; font-weight: 800; color: #475569; margin-top: 5px; }
        .ow-bar small { font-size: .58rem; color: #94a3b8; }

        /* ── Financial Performance ringkas ── */
        .ow-fp { padding: 12px 14px; border-top: 3px solid #2563eb; }
        .ow-fp-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 8px; }
        .ow-fp-t b { display: block; font-size: .82rem; font-weight: 800; color: #0f172a; }
        .ow-fp-t small, .ow-fp-net small { display: block; font-size: .6rem; color: #64748b; margin-top: 1px; text-transform: uppercase; letter-spacing: .06em; font-weight: 700; }
        .ow-fp-net { text-align: right; }
        .ow-fp-net b { display: block; font-size: 1.05rem; font-weight: 800; letter-spacing: -.01em; margin-top: 1px; }
        .ow-fp .pos { color: #059669; } .ow-fp .neg { color: #dc2626; }
        .ow-fp-body { display: flex; align-items: center; gap: 14px; }
        .ow-fp-donut { position: relative; width: 92px; height: 92px; flex-shrink: 0; }
        .ow-fp-donut canvas { width: 92px !important; height: 92px !important; }
        .ow-fp-c { position: absolute; inset: 0; display: grid; place-content: center; text-align: center; pointer-events: none; }
        .ow-fp-c small { font-size: .5rem; text-transform: uppercase; letter-spacing: .08em; color: #94a3b8; font-weight: 700; }
        .ow-fp-c b { font-size: 1rem; font-weight: 800; line-height: 1.1; }
        .ow-fp-rows { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
        .ow-fp-r { display: flex; align-items: center; gap: 7px; padding: 6px 10px; border-radius: 10px; background: #f8fafc; border: 1px solid #eef2f7; }
        .ow-fp-r i { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
        .ow-fp-r span { font-size: .66rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .05em; }
        .ow-fp-r b { margin-left: auto; font-size: .82rem; font-weight: 800; white-space: nowrap; }
        .ow-fp-r em { font-style: normal; font-size: .62rem; font-weight: 700; color: #94a3b8; min-width: 28px; text-align: right; }
        .ow-fp-ratio > div:first-child { display: flex; justify-content: space-between; align-items: baseline; }
        .ow-fp-ratio span { font-size: .58rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #94a3b8; }
        .ow-fp-ratio b { font-size: .68rem; font-weight: 800; }
        .ow-fp-bar { height: 4px; border-radius: 99px; background: #e8edf3; overflow: hidden; margin-top: 3px; }
        .ow-fp-bar i { display: block; height: 100%; border-radius: 99px; }

        /* ── Daily Cash ── */
        .dc { margin-bottom: 12px; }
        .dc-head { display: flex; align-items: center; gap: 9px; margin-bottom: 10px; }
        .dc-t b { display: block; font-size: .82rem; font-weight: 800; color: #0f172a; }
        .dc-t small { display: block; font-size: .62rem; color: #64748b; margin-top: 1px; }
        .dc-date { margin-left: auto; font-size: .64rem; font-weight: 700; padding: 3px 10px; border-radius: 999px; background: #eff6ff; color: #1d4ed8; border: 1px solid #dbeafe; white-space: nowrap; }
        .dc-hero { display: grid; grid-template-columns: 1fr 1.25fr; gap: 8px; margin-bottom: 8px; }
        .dc-tile { padding: 10px 13px; border-radius: 13px; background: #f8fafc; border: 1px solid #e8edf3; min-width: 0; }
        .dc-tile span { display: block; font-size: .58rem; font-weight: 800; text-transform: uppercase; letter-spacing: .07em; color: #64748b; }
        .dc-tile b { display: block; margin-top: 2px; font-size: 1.08rem; font-weight: 800; color: #0f172a; letter-spacing: -.01em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-variant-numeric: tabular-nums; }
        .dc-tile.main { background: linear-gradient(135deg, #0f1f4d, #1e3a8a 60%, #2563eb); border-color: transparent; box-shadow: 0 10px 22px -14px rgba(30, 58, 138, .8); }
        .dc-tile.main span { color: rgba(219, 234, 254, .85); }
        .dc-tile.main b { color: #fff; font-size: 1.2rem; }
        .dc-tile.main.neg { background: linear-gradient(135deg, #7f1d1d, #b91c1c 60%, #dc2626); }
        .dc-strip { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; margin-bottom: 10px; }
        .dc-strip > div { padding: 6px 10px; border-radius: 10px; background: #fff; border: 1px solid #e8edf3; min-width: 0; }
        .dc-strip span { display: block; font-size: .54rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #94a3b8; }
        .dc-strip b { display: block; font-size: .78rem; font-weight: 800; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-variant-numeric: tabular-nums; }
        .dc-strip b.g { color: #047857; } .dc-strip b.r { color: #b91c1c; }
        .dc-warn { margin-bottom: 8px; padding: 6px 10px; border-radius: 10px; background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; font-size: .68rem; font-weight: 700; }
        .dc-list { list-style: none; margin: 0; padding: 0; max-height: 300px; overflow-y: auto; border-top: 1px solid #eef2f7; }
        .dc-row { display: grid; grid-template-columns: 38px 34px minmax(0, 1fr) auto; align-items: center; gap: 8px; padding: 7px 2px; border-bottom: 1px solid #f1f5f9; }
        .dc-row:last-child { border-bottom: 0; }
        .dc-time { font-size: .64rem; color: #94a3b8; font-variant-numeric: tabular-nums; }
        .dc-tag { font-size: .54rem; font-weight: 800; text-align: center; padding: 2px 0; border-radius: 6px; letter-spacing: .04em; }
        .dc-tag.in { background: #dcfce7; color: #15803d; } .dc-tag.out { background: #fee2e2; color: #b91c1c; }
        .dc-desc { font-size: .72rem; color: #334155; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .dc-desc em { font-style: normal; font-size: .54rem; font-weight: 800; margin-left: 6px; padding: 1px 6px; border-radius: 5px; background: #eef2ff; color: #4338ca; vertical-align: 1px; }
        .dc-amt { font-size: .76rem; font-weight: 800; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .dc-amt.in { color: #0f172a; } .dc-amt.out { color: #dc2626; } .dc-amt.op { color: #059669; }

        /* ── Launcher ikon aplikasi + panel ── */
        .ow-apps { display: grid; grid-template-columns: repeat(auto-fit, minmax(72px, 1fr)); gap: 8px; margin-bottom: 12px; }
        .ow-app { display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 9px 4px 8px; border-radius: 16px; background: #fff; border: 1px solid #e8edf3; cursor: pointer; text-decoration: none; font-family: inherit; box-shadow: 0 8px 20px -16px rgba(15, 23, 42, .35); transition: transform .12s, box-shadow .12s, border-color .12s; min-width: 0; }
        .ow-app:hover { transform: translateY(-1px); }
        .ow-app-ic { width: 38px; height: 38px; border-radius: 12px; display: grid; place-items: center; margin-bottom: 3px; }
        .ow-app-ic svg { width: 19px; height: 19px; }
        .ow-app b { font-size: .68rem; font-weight: 800; color: #0f172a; line-height: 1.1; }
        .ow-app small { font-size: .54rem; color: #94a3b8; font-weight: 600; max-width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ow-app.on { background: linear-gradient(160deg, #1e3a8a, #2563eb); border-color: transparent; box-shadow: 0 12px 24px -14px rgba(30, 58, 138, .9); }
        .ow-app.on b { color: #fff; } .ow-app.on small { color: rgba(219, 234, 254, .85); }
        .ow-app.on .ow-app-ic { background: rgba(255, 255, 255, .18) !important; color: #fff !important; }
        .ic-t-blue { background: #dbeafe; color: #1d4ed8; } .ic-t-green { background: #dcfce7; color: #15803d; } .ic-t-amber { background: #fef3c7; color: #b45309; }
        .ic-t-violet { background: #ede9fe; color: #6d28d9; } .ic-t-rose { background: #ffe4e6; color: #be123c; } .ic-t-navy { background: #e0e7ff; color: #1e3a8a; } .ic-t-cyan { background: #cffafe; color: #0e7490; }
        .ow-panel { display: none; }
        .ow-panel.on { display: block; }
        .ow-panel.ow-stack.on { display: flex; flex-direction: column; gap: 10px; }
        .ow-panel.ow-grid2.on { display: grid; }
        @media (min-width: 700px) { .ow-apps { grid-template-columns: repeat(auto-fit, minmax(96px, 1fr)); } }

        /* ── Financial Performance glossy ── */
        .ow-fp { position: relative; overflow: hidden; border: 1px solid rgba(255, 255, 255, .18) !important; border-top: 1px solid rgba(255, 255, 255, .28) !important;
            background: radial-gradient(120% 90% at 100% 0%, rgba(96, 165, 250, .55) 0%, rgba(37, 99, 235, 0) 55%), linear-gradient(135deg, #0b1740 0%, #1e3a8a 55%, #2563eb 100%) !important;
            box-shadow: 0 22px 40px -22px rgba(15, 31, 77, .95), inset 0 1px 0 rgba(255, 255, 255, .28) !important; }
        .ow-fp::before { content: ''; position: absolute; left: -20%; right: -20%; top: -60%; height: 100%; background: linear-gradient(180deg, rgba(255, 255, 255, .20), rgba(255, 255, 255, 0)); transform: rotate(-8deg); pointer-events: none; }
        .ow-fp > * { position: relative; }
        .ow-fp .ow-fp-t b { color: #fff; }
        .ow-fp .ow-fp-t small, .ow-fp .ow-fp-net small { color: rgba(219, 234, 254, .8); }
        .ow-fp .ow-fp-net b.pos, .ow-fp .ow-fp-c b.pos { color: #6ee7b7; } .ow-fp .ow-fp-net b.neg, .ow-fp .ow-fp-c b.neg { color: #fda4af; }
        .ow-fp .ow-fp-c small { color: rgba(219, 234, 254, .7); }
        .ow-fp .ow-fp-r { background: rgba(255, 255, 255, .10); border: 1px solid rgba(255, 255, 255, .18); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); }
        .ow-fp .ow-fp-r span { color: rgba(219, 234, 254, .85); }
        .ow-fp .ow-fp-r b.pos { color: #6ee7b7; } .ow-fp .ow-fp-r b.neg { color: #fda4af; }
        .ow-fp .ow-fp-r em { color: rgba(219, 234, 254, .6); }
        .ow-fp .ow-fp-ratio span { color: rgba(219, 234, 254, .7); }
        .ow-fp .ow-fp-bar { background: rgba(255, 255, 255, .16); }
        .ow-fp .ow-fp-ratio b { color: #fff !important; }
        .ow-cal { display: block; width: 100%; height: 540px; border: 0; border-radius: 12px; background: #fff; }
        .ow-user { font-family: inherit; color: #fff; cursor: pointer; }

        .ow-div-tot { margin-left: auto; font-size: .6rem; color: #64748b; text-align: right; white-space: nowrap; }
        .ow-div-tot b { display: block; font-size: .8rem; color: #0f172a; font-weight: 800; }
        .ow-div .ow-legend { gap: 6px; }
        .ow-div .ow-legend li { font-size: .7rem; gap: 6px; }
        .ow-div .ow-legend li .ln { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; }
        .ow-div .ow-legend li b { margin-left: 0; font-size: .7rem; white-space: nowrap; }
        .ow-div .ow-legend li em { font-style: normal; font-size: .6rem; color: #94a3b8; font-weight: 700; min-width: 28px; text-align: right; }

        /* ── Dock bawah (menggantikan footer) ── */
        body { padding-bottom: 92px !important; }
        .ow-apps { position: fixed; left: 0; right: 0; bottom: 0; z-index: 900; display: flex; gap: 2px; margin: 0; padding: 6px 8px calc(6px + env(safe-area-inset-bottom)); background: rgba(255, 255, 255, .94); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border-top: 1px solid #e2e8f0; box-shadow: 0 -10px 30px -18px rgba(15, 23, 42, .35); overflow-x: auto; scrollbar-width: none; justify-content: center; }
        .ow-apps::-webkit-scrollbar { display: none; }
        .ow-app { flex: 1 0 62px; max-width: 96px; background: transparent !important; border: 0 !important; box-shadow: none !important; padding: 4px 2px 2px; border-radius: 12px; gap: 1px; }
        .ow-app:hover { transform: none; }
        .ow-app-ic { width: 34px; height: 34px; border-radius: 11px; margin-bottom: 1px; transition: background .15s, transform .15s; }
        .ow-app-ic svg { width: 18px; height: 18px; }
        .ow-app b { font-size: .6rem; color: #64748b; }
        .ow-app small { display: none; }
        .ow-app.on { background: transparent !important; box-shadow: none !important; }
        .ow-app.on .ow-app-ic { background: linear-gradient(135deg, #1e3a8a, #2563eb) !important; color: #fff !important; transform: translateY(-2px); box-shadow: 0 8px 16px -8px rgba(37, 99, 235, .9); }
        .ow-app.on b { color: #1d4ed8; }
        @media (min-width: 700px) { .ow-apps { grid-template-columns: none; } }
        .ow-sheet-bg { position: fixed; inset: 0; background: rgba(15, 23, 42, .45); z-index: 950; opacity: 0; pointer-events: none; transition: opacity .18s; }
        .ow-sheet-bg.on { opacity: 1; pointer-events: auto; }
        .ow-sheet { position: fixed; left: 0; right: 0; bottom: 0; z-index: 960; background: #fff; border-radius: 22px 22px 0 0; padding: 8px 16px calc(18px + env(safe-area-inset-bottom)); transform: translateY(105%); transition: transform .22s cubic-bezier(.2, .8, .2, 1); box-shadow: 0 -20px 50px -20px rgba(15, 23, 42, .5); max-width: 640px; margin: 0 auto; }
        .ow-sheet.on { transform: none; }
        .ow-sheet-grip { width: 40px; height: 4px; border-radius: 99px; background: #cbd5e1; margin: 0 auto 10px; }
        .ow-sheet-t { font-size: .82rem; font-weight: 800; color: #0f172a; margin-bottom: 10px; }
        .ow-sheet-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(92px, 1fr)); gap: 8px; }
        .ow-sheet-i { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 12px 6px; border-radius: 14px; background: #f8fafc; border: 1px solid #e8edf3; text-decoration: none; color: #0f172a; }
        .ow-sheet-i span { font-size: 1.35rem; line-height: 1; }
        .ow-sheet-i b { font-size: .68rem; font-weight: 700; }
        .ow-sheet-i.out { background: #fef2f2; border-color: #fecaca; } .ow-sheet-i.out b { color: #b91c1c; }

        /* ── Daily Cash lebih kecil ── */
        .dc { padding: 10px 12px; }
        .dc-head { margin-bottom: 8px; }
        .dc-hero { gap: 6px; margin-bottom: 6px; }
        .dc-tile { padding: 7px 11px; border-radius: 11px; }
        .dc-tile span { font-size: .52rem; }
        .dc-tile b { font-size: .92rem; margin-top: 1px; }
        .dc-tile.main b { font-size: 1.02rem; }
        .dc-strip { gap: 5px; margin-bottom: 7px; }
        .dc-strip > div { padding: 4px 8px; border-radius: 9px; }
        .dc-strip span { font-size: .5rem; } .dc-strip b { font-size: .7rem; }
        .dc-list { max-height: 210px; }
        .dc-row { padding: 5px 2px; gap: 6px; grid-template-columns: 34px 30px minmax(0, 1fr) auto; }
        .dc-time { font-size: .58rem; } .dc-tag { font-size: .5rem; } .dc-desc { font-size: .66rem; } .dc-desc em { font-size: .5rem; } .dc-amt { font-size: .7rem; }

        /* ── Absensi staff (desain baru, kelas lama dipertahankan agar data tetap jalan) ── */
        .att-section { margin-top: 0; }
        .att-hero { background: #fff; border: 1px solid #e8edf3; border-radius: 16px; padding: 12px 14px; box-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 8px 22px -16px rgba(15, 23, 42, .25); }
        .att-hero::before { display: none; }
        .att-hero-top { margin-bottom: 8px; }
        .att-hero-title { font-size: .82rem; font-weight: 800; letter-spacing: 0; color: #0f172a; }
        .att-hero-badge { font-size: .54rem; padding: 2px 9px; background: #dcfce7; border: 1px solid #bbf7d0; color: #15803d; letter-spacing: .06em; }
        .att-date-nav { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 10px; }
        .att-date-btn { width: 28px; height: 28px; border-radius: 9px; border: 1px solid #e2e8f0; background: #f8fafc; color: #1e3a8a; font-size: .7rem; display: grid; place-items: center; cursor: pointer; padding: 0; }
        .att-date-label { font-size: .74rem; font-weight: 800; color: #0f172a; min-width: 150px; text-align: center; }
        .att-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; }
        .att-stat-card { padding: 7px 4px; border-radius: 11px; background: #f8fafc; border: 1px solid #eef2f7; text-align: center; box-shadow: none; }
        .att-stat-num { font-size: 1.1rem; font-weight: 800; color: #0f172a; line-height: 1.1; }
        .att-stat-label { font-size: .54rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #64748b !important; justify-content: center; }
        .att-list-wrap { margin-top: 10px; background: #fff; border: 1px solid #e8edf3; border-radius: 16px; padding: 10px 12px; box-shadow: 0 8px 22px -18px rgba(15, 23, 42, .3); }
        .att-list-header { margin-bottom: 4px; padding-bottom: 6px; border-bottom: 1px solid #eef2f7; }
        .att-list-title { font-size: .76rem; font-weight: 800; color: #0f172a; }
        .att-list-count { font-size: .6rem; font-weight: 700; color: #1d4ed8; background: #eff6ff; border-radius: 999px; padding: 2px 8px; }
        .att-emp-row { padding: 7px 0; gap: 9px; border-bottom: 1px solid #f1f5f9; }
        .att-emp-avatar { width: 30px; height: 30px; font-size: .74rem; border-radius: 10px; }
        .att-emp-name { font-size: .72rem; font-weight: 700; color: #0f172a; }
        .att-emp-pos { font-size: .6rem; color: #94a3b8; }
        .att-status-badge { font-size: .52rem; padding: 1px 7px; }
        .att-scan-pill { padding: 1px 5px; border-radius: 6px; }
        .att-scan-pill .att-sp-lbl { font-size: .46rem; } .att-scan-pill .att-sp-val { font-size: .6rem; }
        .att-emp-hours { font-size: .58rem; }

        /* ── Mode ringkas ── */
        .container { padding-left: 12px !important; padding-right: 12px !important; }
        .ow-top { padding: 9px 12px; margin-bottom: 10px; border-radius: 14px; }
        .ow-logo { width: 34px; height: 34px; border-radius: 10px; font-weight: 800; font-size: .95rem; color: #fff; }
        .ow-logo.has-img { background: #fff; padding: 2px; }
        .ow-logo.has-img img { object-fit: contain; border-radius: 8px; }
        .ow-name { font-size: .9rem; }
        .ow-sub { font-size: .62rem; }
        .ow-btn { height: 30px; padding: 0 10px; font-size: .68rem; border-radius: 9px; }
        .ow-user { height: 30px; }
        .ow-sec { gap: 10px; margin-bottom: 10px; }
        .ow-card { padding: 11px 13px; border-radius: 14px; }
        .ow-card-h { margin-bottom: 8px; gap: 8px; }
        .ow-card-h b { font-size: .8rem; } .ow-card-h small { font-size: .62rem; }
        .ow-ic { width: 28px; height: 28px; border-radius: 8px; } .ow-ic svg { width: 15px; height: 15px; }
        .ow-kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
        .ow-kpi { padding: 8px 10px; border-radius: 12px; border-left-width: 3px; }
        .ow-kpi span { font-size: .54rem; letter-spacing: .05em; }
        .ow-kpi b { font-size: .86rem; margin-top: 2px; }
        .ow-chart { height: 170px; }
        .ow-occ { gap: 16px; }
        .ow-donut { width: 108px; height: 108px; }
        .ow-donut canvas { width: 108px !important; height: 108px !important; }
        .ow-donut-c b { font-size: 1.15rem; } .ow-donut-c small { font-size: .6rem; }
        .ow-legend { gap: 7px; } .ow-legend li { font-size: .72rem; } .ow-legend li i { width: 9px; height: 9px; border-radius: 2px; }
        .ow-bars { height: auto; gap: 6px; align-items: end; }
        .ow-bar { gap: 5px; height: auto; }
        .ow-bar em { font-size: .6rem; margin: 0; }
        .ow-bar-t { flex: none; height: 66px; max-width: 30px; border-radius: 8px; }
        .ow-bar-t i { min-height: 3px; border-radius: 5px 5px 0 0; }
        .ow-bar-t i.hi { background: linear-gradient(180deg, #4ade80, #16a34a); }
        .ow-bar-t i.lo { background: linear-gradient(180deg, #fcd34d, #f59e0b); }
        .ow-bar b { font-size: .6rem; margin: 0; } .ow-bar small { font-size: .56rem; }
        .ow-es-item { padding: 7px 10px; border-radius: 10px; }
        .ow-es-who { font-size: .72rem; } .ow-es-at { font-size: .6rem; }
        .ow-es-chips span { font-size: .6rem; padding: 1px 8px; }
        .ow-empty { padding: 9px; font-size: .68rem; }
        /* duplikat dihilangkan: ringkasan "Today In/Out/Net" sudah ada di kartu atas */
        .hero-today-row { display: none !important; }
        /* pilihan bisnis: strip ramping tanpa kartu */
        .info-card:has(.biz-switcher) { padding: 8px 10px !important; margin-bottom: 10px !important; border-radius: 14px !important; }
        .info-card:has(.biz-switcher) > div:first-child { display: none !important; }
        .biz-switcher { gap: 6px !important; }
        .biz-pill { padding: 5px 10px 5px 6px !important; border-radius: 10px !important; }
        .biz-pill-icon { width: 24px !important; height: 24px !important; }
        .biz-pill-name { font-size: .7rem !important; } .biz-pill-type { font-size: .56rem !important; }
        @media (max-width: 600px) {
            .ow-btn span { display: none; }
            .ow-btn { width: 30px; padding: 0; justify-content: center; }
            .ow-sub { display: none; }
            .ow-un { display: none; }
        }
        @media (max-width: 760px) {
            .ow-grid2 { grid-template-columns: 1fr; }
            .ow-un { display: none; }
            .ow-occ { gap: 14px; }
        }
    </style>
</head>

<body>
    <?php
    // Tagihan Langganan ADF System: banner jatuh tempo + kunci layar (dikontrol dari adfsystem.store).
    $adfsubState = ['connected' => false, 'locked' => false, 'reminder' => null];
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            require_once __DIR__ . '/../../config/database.php';
            require_once __DIR__ . '/../../includes/subscription_client.php';
            $adfsubState = adfsub_tick($pdo, true);
        }
    } catch (Throwable $e) {
        error_log('owner subscription: ' . $e->getMessage());
    }
    $adfsubBillingUrl = $basePath . '/modules/subscription/index.php';
    // "Bayar" langsung ke halaman pembayaran Pakasir untuk tagihan terdekat yang belum dibayar.
    $adfsubPayBill = $adfsubState['reminder']['invoice'] ?? (($adfsubState['unpaid'] ?? [])[0] ?? null);
    $adfsubPayUrl = $adfsubPayBill ? $adfsubBillingUrl . '?pay=' . urlencode($adfsubPayBill['period']) : $adfsubBillingUrl;
    $adfsubHasBill = !empty($adfsubState['unpaid']);
    $adfsubWaUrl = 'https://wa.me/628214400664?text=' . rawurlencode('Halo Developer ADF System, sistem ' . (defined('BUSINESS_NAME') ? BUSINESS_NAME : '') . ' saya terkunci. Mohon bantuannya.');
    $adfsubWaBtn = '<a href="' . htmlspecialchars($adfsubWaUrl) . '" target="_blank" rel="noopener" style="display:flex;align-items:center;justify-content:center;gap:7px;margin-top:10px;background:#25d366;color:#fff;padding:10px;border-radius:10px;font-weight:700;text-decoration:none;font-size:13px;">'
        . '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.480-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.62-.92-2.22-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.08c.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.690.63.71.23 1.36.2 1.87.12.57-.08 1.76-.72 2.01-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35zM12.05 21.5h-.01a9.4 9.4 0 0 1-4.8-1.31l-.34-.2-3.57.94.95-3.48-.22-.36a9.4 9.4 0 0 1-1.44-5.02c0-5.2 4.23-9.43 9.44-9.43a9.37 9.37 0 0 1 6.67 2.77 9.37 9.37 0 0 1 2.76 6.67c0 5.2-4.23 9.43-9.43 9.43zm8.03-17.46A11.27 11.27 0 0 0 12.05.72C5.8.72.7 5.8.7 12.07c0 2 .52 3.95 1.520 5.67L.6 23.65l6.04-1.59a11.3 11.3 0 0 0 5.4 1.38h.01c6.25 0 11.35-5.09 11.35-11.36 0-3.03-1.18-5.88-3.32-8.03z"/></svg>'
        . ' Hubungi Developer · 08214400664</a>';
    ?>
    <?php if ($adfsubState['connected'] && $adfsubState['locked']): ?>
        <div id="adfsubLock" style="position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,.85);display:flex;align-items:center;justify-content:center;padding:20px;">
            <div style="max-width:340px;width:100%;background:#fff;color:#1e293b;border-radius:16px;padding:26px 22px;text-align:center;">
                <div style="font-size:32px;">🔒</div>
                <h3 style="margin:6px 0 8px;font-size:16px;">Sistem Sementara Dikunci</h3>
                <?php if ($adfsubHasBill): ?>
                    <p style="margin:0 0 16px;font-size:13px;color:#475569;line-height:1.5;">Akses dikunci oleh ADF System karena tagihan langganan belum diselesaikan.</p>
                    <a href="<?php echo $adfsubPayUrl; ?>" style="display:block;background:#16a34a;color:#fff;padding:10px;border-radius:10px;font-weight:700;text-decoration:none;font-size:13.5px;">Bayar Sekarang</a>
                <?php else: ?>
                    <p style="margin:0 0 16px;font-size:13px;color:#475569;line-height:1.5;">Akses ke sistem sedang dikunci oleh ADF System. Silakan hubungi developer untuk membuka kembali.</p>
                <?php endif; ?>
                <?php echo $adfsubWaBtn; ?>
                <?php if ($isDev): ?>
                    <button type="button" onclick="document.getElementById('adfsubLock').style.display='none';" style="margin-top:10px;background:none;border:none;color:#64748b;font-size:12px;cursor:pointer;">Lanjut sebagai developer</button>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($adfsubState['connected'] && $adfsubState['reminder']):
        // Developer saat dikunci, atau pengingat jatuh tempo: popup di tengah layar, bisa ditutup.
        $adfsubInv = $adfsubState['reminder']['invoice'] ?? (($adfsubState['unpaid'] ?? [])[0] ?? null);
        $adfsubDays = isset($adfsubState['reminder']['days_left']) ? (int) $adfsubState['reminder']['days_left'] : null;
        $adfsubTitle = $adfsubState['locked'] ? 'Sistem Dikunci oleh ADF System'
            : ($adfsubDays === null || $adfsubDays > 7 ? 'Tagihan Baru dari ADF System'
            : ($adfsubDays < 0 ? 'Tagihan Lewat Jatuh Tempo' : ($adfsubDays === 0 ? 'Tagihan Jatuh Tempo Hari Ini' : 'Tagihan Jatuh Tempo ' . $adfsubDays . ' Hari Lagi')));
    ?>
        <div id="adfsubPopup" style="display:flex;position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,.55);align-items:center;justify-content:center;padding:20px;">
            <div style="max-width:340px;width:100%;background:#fff;color:#1e293b;border-radius:16px;padding:24px 20px;text-align:center;<?php echo ($adfsubState['locked'] || $adfsubDays < 0) ? 'border-top:4px solid #dc2626;' : ''; ?>">
                <div style="font-size:30px;"><?php echo $adfsubState['locked'] ? '🔒' : ($adfsubDays < 0 ? '⚠️' : '🧾'); ?></div>
                <h3 style="margin:6px 0 8px;font-size:16px;"><?php echo $adfsubTitle; ?></h3>
                <p style="margin:0 0 12px;font-size:12.5px;color:#475569;line-height:1.5;"><?php echo $adfsubState['locked'] ? 'Pengguna lain tidak bisa memakai sistem sampai kunci dibuka. Anda masuk sebagai developer.' : 'Segera selesaikan pembayaran langganan agar sistem tetap bisa digunakan.'; ?></p>
                <?php if ($adfsubInv): ?>
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:8px 10px;margin-bottom:14px;font-size:12px;">
                        <?php echo htmlspecialchars($adfsubInv['description'] ?: $adfsubInv['period']); ?> · <strong>Rp <?php echo number_format((float) $adfsubInv['total_amount'], 0, ',', '.'); ?></strong><?php echo !empty($adfsubInv['due_date']) ? ' · ' . date('d M Y', strtotime($adfsubInv['due_date'])) : ''; ?>
                    </div>
                <?php endif; ?>
                <?php if ($adfsubHasBill): ?>
                    <a href="<?php echo $adfsubPayUrl; ?>" style="display:block;background:#16a34a;color:#fff;padding:10px;border-radius:10px;font-weight:700;text-decoration:none;font-size:13.5px;">Bayar Sekarang</a>
                <?php endif; ?>
                <button type="button" onclick="document.getElementById('adfsubPopup').style.display='none';" style="margin-top:10px;background:none;border:none;color:#64748b;font-size:12px;cursor:pointer;">Nanti saja</button>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($adfsubState['connected'] && !empty($adfsubState['due_soon'])):
        // Strip tetap di atas: tagihan belum dibayar selalu terlihat walau popup ditutup.
        $adfsubTotal = array_sum(array_map(static fn($i) => (float) $i['total_amount'], $adfsubState['due_soon']));
        $adfsubLate = count(array_filter($adfsubState['due_soon'], static fn($i) => !empty($i['due_date']) && $i['due_date'] < date('Y-m-d'))) > 0;
    ?>
        <a href="<?php echo $adfsubPayUrl; ?>" style="position:sticky;top:0;z-index:9000;display:flex;align-items:center;gap:8px;padding:8px 12px;font-size:12px;text-decoration:none;<?php echo $adfsubLate ? 'background:#fef2f2;color:#991b1b;border-bottom:1px solid #fca5a5;' : 'background:#fffbeb;color:#92400e;border-bottom:1px solid #fcd34d;'; ?>">
            <span style="width:8px;height:8px;border-radius:50%;background:<?php echo $adfsubLate ? '#dc2626' : '#f59e0b'; ?>;flex-shrink:0;"></span>
            <span style="flex:1;"><?php echo $adfsubLate ? 'Tagihan lewat jatuh tempo' : 'Tagihan belum dibayar'; ?> · <strong>Rp <?php echo number_format($adfsubTotal, 0, ',', '.'); ?></strong></span>
            <span style="background:#16a34a;color:#fff;font-weight:700;padding:4px 10px;border-radius:7px;font-size:11px;">Bayar</span>
        </a>
    <?php endif; ?>
    <?php if ($isDev): ?>
        <div class="dev-badge">DEV</div>
    <?php endif; ?>

    <div class="container">
        <!-- Header -->
        <header class="ow-top">
            <div class="ow-brand">
                <?php $ovLogo = function_exists('getBusinessLogoById') ? getBusinessLogoById($activeBusinessId, $activeConfig) : ''; ?>
                <div class="ow-logo<?= $ovLogo ? ' has-img' : '' ?>">
                    <?php if ($ovLogo): ?>
                        <img src="<?= htmlspecialchars($ovLogo) ?>" alt="Logo">
                    <?php else: ?>
                        <span><?= htmlspecialchars(mb_strtoupper(mb_substr($businessName, 0, 1))) ?></span>
                    <?php endif; ?>
                </div>                <div class="ow-brand-t">
                    <div class="ow-name"><?= htmlspecialchars($businessName) ?></div>
                    <div class="ow-sub">Owner Dashboard · <?= ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][(int)date('w')] . ', ' . date('j') . ' ' . $ovBln[(int)date('n')] . ' ' . date('Y') ?></div>
                </div>
            </div>
            <div class="ow-top-r">
                <button class="ow-btn" onclick="location.reload()" title="Refresh Data">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path d="M1 4v6h6" /><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10" /></svg>
                    <span>Refresh</span>
                </button>
                <button type="button" class="ow-user" id="owMoreBtn" aria-haspopup="dialog" title="Menu">
                    <span class="ow-av"><?= strtoupper(substr($userName, 0, 1)) ?></span>
                    <span class="ow-un"><?= htmlspecialchars($userName) ?></span>
                    <?php if ($isDev): ?><em>DEV</em><?php endif; ?>
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                </button>
            </div>
        </header>

        <?php if (count($allBusinesses) > 1): ?>
            <!-- Business Switcher -->
            <div class="info-card">
                <div style="font-size:10px;color:var(--text-muted);margin-bottom:6px;font-weight:500;text-transform:uppercase;letter-spacing:0.5px;">Switch Business</div>
                <div class="biz-switcher">
                    <?php foreach ($allBusinesses as $bizId => $biz):
                        $isActive = ($bizId === $activeBusinessId);
                        $bizLogoUrl = function_exists('getBusinessLogoById') ? getBusinessLogoById($bizId, $biz) : '';
                        $bizLogoExists = (bool)$bizLogoUrl;
                    ?>
                        <a href="<?= $basePath ?>/modules/owner/dashboard-2028.php?business=<?= urlencode($bizId) ?>" class="biz-pill <?= $isActive ? 'active' : '' ?>">
                            <div class="biz-pill-icon">
                                <?php if ($bizLogoExists): ?>
                                    <img src="<?= htmlspecialchars($bizLogoUrl) ?>" alt="">
                                <?php else: ?>
                                    <?= htmlspecialchars(mb_strtoupper(mb_substr($biz['name'], 0, 1))) ?>
                                <?php endif; ?>
                            </div>
                            <div class="biz-pill-text">
                                <span class="biz-pill-name"><?= htmlspecialchars($biz['name']) ?></span>
                                <span class="biz-pill-type"><?= $biz['business_type'] ?? 'business' ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="info-card error">
                <div class="info-card-icon">❌</div>
                <div class="info-card-content">
                    <div class="info-card-title">Connection Error</div>
                    <div class="info-card-value"><?= htmlspecialchars($error) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$error): ?>
            <!-- ═══ Ringkasan Hari Ini (tema Narayana) ═══ -->
            <?php
            $ovNetToday = $stats['today_income'] - $stats['today_expense'];
            $ovOcc = $ov['occToday'];
            $ovOccPct = $ovOcc['total'] > 0 ? ($ovOcc['rate'] ?? round($ovOcc['occupied'] / $ovOcc['total'] * 100, 1)) : 0;
            ?>
            <?php
            $fpMargin = $stats['month_income'] > 0 ? round((($stats['month_income'] - $stats['month_expense']) / $stats['month_income']) * 100) : 0;
            $fpTot = $stats['month_income'] + $stats['month_expense'];
            $fpIncPct = $fpTot > 0 ? round($stats['month_income'] / $fpTot * 100) : 0;
            $fpExpPct = $fpTot > 0 ? 100 - $fpIncPct : 0;
            ?>
            <?php
            $attOk = (!$isCQC && $attStats['total'] > 0);
            $ovHasOcc = ($ovIsHotel && $ovOcc['total'] > 0);
            $tiles = [
                ['ringkasan', 'Ringkasan', 'Keuangan & End Shift', 'ic-t-blue', '<path d="M3 12 12 3l9 9"/><path d="M5 10v10h14V10"/>'],
            ];
            if ($ovHasOcc) $tiles[] = ['okupansi', 'Okupansi', $ovOccPct . '% terisi', 'ic-t-green', '<path d="M2 20v-8a3 3 0 0 1 3-3h14a3 3 0 0 1 3 3v8"/><path d="M2 16h20"/><path d="M6 9V6a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/>'];
            $tiles[] = ['kas', 'Kas Harian', 'Daily cash', 'ic-t-amber', '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/>'];
            if ($attOk) $tiles[] = ['absensi', 'Absensi', ($attStats['present'] + $attStats['late']) . '/' . $attStats['total'] . ' hadir', 'ic-t-rose', '<path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M21 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'];
            if ($isCQC) $tiles[] = ['proyek', 'Proyek', 'CQC', 'ic-t-navy', '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'];
            ?>
            <nav class="ow-apps" id="owApps" aria-label="Menu dashboard">
                <?php foreach ($tiles as $t): ?>
                    <button type="button" class="ow-app" data-go="<?= $t[0] ?>">
                        <span class="ow-app-ic <?= $t[3] ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $t[4] ?></svg></span>
                        <b><?= $t[1] ?></b><small><?= htmlspecialchars($t[2]) ?></small>
                    </button>
                <?php endforeach; ?>
            </nav>

            <section class="ow-sec">
                <div class="ow-panel ow-stack" data-p="ringkasan">
                <div class="ow-card ow-fp">
                    <div class="ow-fp-top">
                        <div class="ow-fp-t"><b>Financial Performance</b><small><?= date('F Y') ?></small></div>
                        <div class="ow-fp-net"><small>Net Profit</small><b class="<?= $netProfit >= 0 ? 'pos' : 'neg' ?>"><?= ($netProfit >= 0 ? '+' : '') . rp($netProfit) ?></b></div>
                    </div>
                    <div class="ow-fp-body">
                        <div class="ow-fp-donut">
                            <canvas id="pieChart" width="92" height="92"></canvas>
                            <div class="ow-fp-c"><small>Margin</small><b class="<?= $fpMargin >= 0 ? 'pos' : 'neg' ?>"><?= $fpMargin ?>%</b></div>
                        </div>
                        <div class="ow-fp-rows">
                            <div class="ow-fp-r"><i style="background:#10b981"></i><span>Income</span><b class="pos"><?= rp($stats['month_income']) ?></b><em><?= $fpIncPct ?>%</em></div>
                            <div class="ow-fp-r"><i style="background:#ef4444"></i><span>Expense</span><b class="neg"><?= rp($stats['month_expense']) ?></b><em><?= $fpExpPct ?>%</em></div>
                            <div class="ow-fp-ratio">
                                <div><span>Expense ratio</span><b style="color:<?= $expenseRatio > 70 ? '#dc2626' : ($expenseRatio > 50 ? '#d97706' : '#059669') ?>"><?= number_format($expenseRatio, 1) ?>%</b></div>
                                <div class="ow-fp-bar"><i style="width:<?= min($expenseRatio, 100) ?>%;background:<?= $expenseRatio > 70 ? '#ef4444' : ($expenseRatio > 50 ? '#f59e0b' : '#10b981') ?>"></i></div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($ovHasOcc): ?>
                        <div class="ow-card">
                            <div class="ow-card-h"><div class="ow-ic ic-green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg></div><div><b>Okupansi Hari Ini</b><small><?= $ovOcc['total'] ?> kamar</small></div></div>
                            <div class="ow-occ">
                                <div class="ow-donut"><canvas id="ovOccChart" width="108" height="108"></canvas><div class="ow-donut-c"><b><?= $ovOccPct ?>%</b><small>terisi</small></div></div>
                                <ul class="ow-legend">
                                    <li><i style="background:#2563eb"></i>Terisi<b><?= $ovOcc['occupied'] ?></b></li>
                                    <li><i style="background:#cbd5e1"></i>Kosong<b><?= $ovOcc['vacant'] ?></b></li>
                                    <li><i style="background:#f59e0b"></i>Diblok<b><?= $ovOcc['blocked'] ?></b></li>
                                    <li><i style="background:#8b5cf6"></i>Datang besok<b><?= $ovOcc['arriving'] ?></b></li>
                                </ul>
                            </div>
                        </div>
                <?php endif; ?>

                <div class="ow-card ow-es">
                    <div class="ow-card-h">
                        <div class="ow-ic ic-navy"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg></div>
                        <div><b>Laporan End Shift</b><small>Rekap harian otomatis dari kasir · <?= count($ov['endShifts']) ?: 'belum ada' ?> terbaru</small></div>
                    </div>
                    <?php if ($ov['endShifts']): ?>
                        <div class="ow-es-list">
                            <?php foreach ($ov['endShifts'] as $es): $d = $es['d']; $net = (float)($d['net'] ?? 0); ?>
                                <div class="ow-es-item<?= $es['unread'] ? ' unread' : '' ?>">
                                    <div class="ow-es-top">
                                        <span class="ow-es-who"><?= htmlspecialchars((string)($d['cashier'] ?? 'Kasir')) ?> menutup shift<?php if ($es['unread']): ?> <i class="ow-new">Baru</i><?php endif; ?></span>
                                        <span class="ow-es-at"><?= date('j', strtotime($es['at'])) . ' ' . $ovBln[(int)date('n', strtotime($es['at']))] . ' · ' . date('H:i', strtotime($es['at'])) ?></span>
                                    </div>
                                    <div class="ow-es-chips">
                                        <span class="in">Masuk <b><?= rp((float)($d['income'] ?? 0)) ?></b></span>
                                        <span class="out">Keluar <b><?= rp((float)($d['expense'] ?? 0)) ?></b></span>
                                        <span class="<?= $net >= 0 ? 'in' : 'out' ?>">Net <b><?= ($net >= 0 ? '+' : '') . rp($net) ?></b></span>
                                        <?php if (!empty($d['occ_total'])): ?><span>Okupansi <b><?= (int)$d['occ_occupied'] ?>/<?= (int)$d['occ_total'] ?></b></span><?php endif; ?>
                                        <?php if (isset($d['cash_available']) && $d['cash_available'] !== null): ?><span>Kas <b><?= rp((float)$d['cash_available']) ?></b></span><?php endif; ?>
                                        <span><b><?= (int)($d['tx_count'] ?? 0) ?></b> transaksi</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="ow-empty">Belum ada laporan End Shift. Rekap muncul di sini otomatis setiap kasir menekan End Shift.</div>
                    <?php endif; ?>
                </div>

                <div class="ow-kpis">
                    <div class="ow-kpi k-green"><span>Pemasukan</span><b><?= rp($stats['today_income']) ?></b></div>
                    <div class="ow-kpi k-red"><span>Pengeluaran</span><b><?= rp($stats['today_expense']) ?></b></div>
                    <div class="ow-kpi <?= $ovNetToday >= 0 ? 'k-blue' : 'k-red' ?>"><span>Net hari ini</span><b><?= ($ovNetToday >= 0 ? '+' : '') . rp($ovNetToday) ?></b></div>
                </div>
                </div>

                <?php if ($ovHasOcc): ?>
                    <div class="ow-panel ow-stack" data-p="okupansi">
                        <?php if ($ov['occ7']): ?>
                            <div class="ow-card">
                                <div class="ow-card-h"><div class="ow-ic ic-blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 20V10M18 20V4M6 20v-4"/></svg></div><div><b>Okupansi 7 Hari</b><small>Kamar terpesan per malam</small></div></div>
                                <div class="ow-bars">
                                    <?php foreach ($ov['occ7'] as $i => $o): ?>
                                        <div class="ow-bar<?= $i === 0 ? ' now' : '' ?>">
                                            <em><?= $o['pct'] ?>%</em>
                                            <div class="ow-bar-t" title="<?= (int)($o['rooms'] ?? 0) ?> kamar terpesan"><i class="<?= $o['pct'] >= 80 ? 'hi' : ($o['pct'] < 40 ? 'lo' : '') ?>" style="height:<?= max(3, min(100, $o['pct'])) ?>%"></i></div>
                                            <b><?= htmlspecialchars($o['label']) ?></b><small><?= htmlspecialchars($o['sub']) ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="ow-card ow-calcard">
                            <div class="ow-card-h"><div class="ow-ic ic-blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></div><div><b>Kalender Booking</b><small>Geser kanan-kiri untuk tanggal lain</small></div></div>
                            <iframe class="ow-cal" src="<?= $basePath ?>/modules/owner/frontdesk-mobile.php?embed=cal" loading="lazy" title="Kalender booking"></iframe>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Daily Cash Section - SYNCED WITH index.php -->
            <?php
            // ============================================
            // DAILY CASH - SYNCED WITH index.php
            // Same logic: separate capital + petty cash stats (MONTHLY)
            // Then: startKas (carry-over) + monthly net = Cash Available
            // Plus: guestCashIncome (Cash dari Tamu - payment_method='cash')
            // ============================================
            $todayKas = [];
            $startKasHariIni = 0;
            $ownerTransferThisMonth = 0;
            $totalOperationalIncome = 0;
            $totalOperationalExpense = 0;
            $totalOperationalCash = 0;
            $guestCashIncome = 0;
            $allAccounts = [];
            $capitalStats = ['received' => 0, 'used' => 0, 'balance' => 0];
            $pettyCashStats = ['received' => 0, 'used' => 0, 'balance' => 0];

            try {
                // Connect to master DB to get cash account IDs
                $masterDb = new PDO("mysql:host=" . $dbHost . ";dbname=" . $masterDbName . ";charset=utf8mb4", $dbUser, $dbPass);
                $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $businessId = getMasterBusinessId();

                // Get owner_capital account IDs
                $stmtCap = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
                $stmtCap->execute([$businessId]);
                $capitalAccounts = $stmtCap->fetchAll(PDO::FETCH_COLUMN);

                // Get petty cash (cash) account IDs
                $stmtPetty = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash'");
                $stmtPetty->execute([$businessId]);
                $pettyCashAccounts = $stmtPetty->fetchAll(PDO::FETCH_COLUMN);

                // Merge all operational accounts
                $allAccounts = array_merge($capitalAccounts, $pettyCashAccounts);

                $kasDb = new PDO("mysql:host=" . $dbHost . ";dbname=" . $businessDbName . ";charset=utf8mb4", $dbUser, $dbPass);
                $kasDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $today = date('Y-m-d');
                $thisMonth = date('Y-m');
                $firstDayOfMonth = date('Y-m-01');

                // Check if source_type column exists (same as index.php)
                $hasSourceTypeCol = false;
                try {
                    $colCheck = $kasDb->query("SHOW COLUMNS FROM cash_book LIKE 'source_type'");
                    $hasSourceTypeCol = $colCheck && $colCheck->rowCount() > 0;
                } catch (\Throwable $e) {
                    $hasSourceTypeCol = false;
                }

                // Query Modal Owner stats THIS MONTH (same as index.php)
                if (!empty($capitalAccounts)) {
                    $placeholders = implode(',', array_fill(0, count($capitalAccounts), '?'));
                    $sqlCapital = "
                    SELECT 
                        COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) as received,
                        COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as used,
                        (COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
                         COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0)) as balance
                    FROM cash_book 
                    WHERE cash_account_id IN ($placeholders)
                    AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
                ";
                    $stmtCapital = $kasDb->prepare($sqlCapital);
                    $stmtCapital->execute(array_merge($capitalAccounts, [$thisMonth]));
                    $capResult = $stmtCapital->fetch(PDO::FETCH_ASSOC);
                    $capitalStats['received'] = (float)($capResult['received'] ?? 0);
                    $capitalStats['used'] = (float)($capResult['used'] ?? 0);
                    $capitalStats['balance'] = (float)($capResult['balance'] ?? 0);
                }

                // Query Petty Cash / Kas Operasional stats THIS MONTH (same as index.php)
                if (!empty($pettyCashAccounts)) {
                    $placeholders = implode(',', array_fill(0, count($pettyCashAccounts), '?'));
                    $sqlPetty = "
                    SELECT 
                        COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) as received,
                        COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as used,
                        (COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
                         COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0)) as balance
                    FROM cash_book 
                    WHERE cash_account_id IN ($placeholders)
                    AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
                ";
                    $stmtPetty2 = $kasDb->prepare($sqlPetty);
                    $stmtPetty2->execute(array_merge($pettyCashAccounts, [$thisMonth]));
                    $pettyResult = $stmtPetty2->fetch(PDO::FETCH_ASSOC);
                    $pettyCashStats['received'] = (float)($pettyResult['received'] ?? 0);
                    $pettyCashStats['used'] = (float)($pettyResult['used'] ?? 0);
                    $pettyCashStats['balance'] = (float)($pettyResult['balance'] ?? 0);
                }

                // TOTAL KAS OPERASIONAL = Petty Cash + Modal Owner (MONTHLY net - same as index.php)
                $totalOperationalCash = $pettyCashStats['balance'] + $capitalStats['balance'];

                // TOTAL PENGELUARAN OPERASIONAL = Petty Cash expense + Modal Owner expense
                $totalOperationalExpense = $pettyCashStats['used'] + $capitalStats['used'];

                // TOTAL UANG MASUK = Petty Cash received + Modal Owner received
                $totalOperationalIncome = $pettyCashStats['received'] + $capitalStats['received'];

                if (!empty($allAccounts)) {
                    $placeholders = implode(',', array_fill(0, count($allAccounts), '?'));

                    // START KAS = Saldo akhir bulan sebelumnya (same as index.php)
                    // Modal Owner: all transactions before THIS MONTH
                    $startKasOwner = 0;
                    $startKasPetty = 0;

                    if (!empty($capitalAccounts)) {
                        $capPh = implode(',', array_fill(0, count($capitalAccounts), '?'));
                        $sqlStartOwner = "
                        SELECT COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
                               COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
                        FROM cash_book WHERE cash_account_id IN ($capPh) AND transaction_date < ?
                    ";
                        $stmtStartOwner = $kasDb->prepare($sqlStartOwner);
                        $stmtStartOwner->execute(array_merge($capitalAccounts, [$firstDayOfMonth]));
                        $startKasOwner = (float)($stmtStartOwner->fetchColumn() ?: 0);
                    }

                    if (!empty($pettyCashAccounts)) {
                        $pettyPh = implode(',', array_fill(0, count($pettyCashAccounts), '?'));
                        $sqlStartPetty = "
                        SELECT COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
                               COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
                        FROM cash_book WHERE cash_account_id IN ($pettyPh) AND transaction_date < ?
                    ";
                        $stmtStartPetty = $kasDb->prepare($sqlStartPetty);
                        $stmtStartPetty->execute(array_merge($pettyCashAccounts, [$firstDayOfMonth]));
                        $startKasPetty = (float)($stmtStartPetty->fetchColumn() ?: 0);
                    }

                    $startKasHariIni = $startKasOwner + $startKasPetty;

                    // Owner Transfer THIS MONTH - menggunakan source_type='owner_fund'
                    // Fallback: income ke owner_capital accounts
                    if ($hasSourceTypeCol) {
                        $sqlOwnerTransfer = "
                        SELECT COALESCE(SUM(amount), 0) as total
                        FROM cash_book 
                        WHERE transaction_type = 'income'
                        AND source_type = 'owner_fund'
                        AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
                    ";
                        $stmtOwnerTransfer = $kasDb->prepare($sqlOwnerTransfer);
                        $stmtOwnerTransfer->execute([$thisMonth]);
                        $ownerTransferThisMonth = (float)($stmtOwnerTransfer->fetchColumn() ?: 0);
                    } elseif (!empty($capitalAccounts)) {
                        $ownerPh = implode(',', array_fill(0, count($capitalAccounts), '?'));
                        $sqlOwnerTransfer = "
                        SELECT COALESCE(SUM(amount), 0) as total
                        FROM cash_book 
                        WHERE cash_account_id IN ($ownerPh)
                        AND transaction_type = 'income'
                        AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
                    ";
                        $stmtOwnerTransfer = $kasDb->prepare($sqlOwnerTransfer);
                        $stmtOwnerTransfer->execute(array_merge($capitalAccounts, [$thisMonth]));
                        $ownerTransferThisMonth = (float)($stmtOwnerTransfer->fetchColumn() ?: 0);
                    } else {
                        $ownerTransferThisMonth = 0;
                    }

                    // Get ALL transactions for TODAY (no limit - show full daily detail)
                    $sqlKas = "
                    SELECT id, transaction_type, description, amount, payment_method, cash_account_id,
                           TIME_FORMAT(CONCAT(transaction_date, ' ', COALESCE(transaction_time, '00:00:00')), '%H:%i') as jam,
                           transaction_date
                    FROM cash_book 
                    WHERE transaction_date = ?
                    ORDER BY transaction_time DESC, id DESC
                ";
                    $stmtKas = $kasDb->prepare($sqlKas);
                    $stmtKas->execute([$today]);
                    $todayKas = $stmtKas->fetchAll(PDO::FETCH_ASSOC);
                }

                // Get Guest Cash Income this month - cash dari tamu saja (payment_method='cash', bukan owner_fund)
                if ($hasSourceTypeCol) {
                    $sqlCashIncome = "
                    SELECT COALESCE(SUM(amount), 0) as total 
                    FROM cash_book 
                    WHERE transaction_type = 'income' 
                    AND payment_method = 'cash'
                    AND (source_type IS NULL OR source_type != 'owner_fund')
                    AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
                ";
                    $stmtCashIncome = $kasDb->prepare($sqlCashIncome);
                    $stmtCashIncome->execute([$thisMonth]);
                    $guestCashIncome = (float)($stmtCashIncome->fetchColumn() ?: 0);
                } elseif (!empty($capitalAccounts)) {
                    $placeholders = implode(',', array_fill(0, count($capitalAccounts), '?'));
                    $sqlCashIncome = "
                    SELECT COALESCE(SUM(amount), 0) as total 
                    FROM cash_book 
                    WHERE transaction_type = 'income' 
                    AND payment_method = 'cash'
                    AND (cash_account_id IS NULL OR cash_account_id NOT IN ($placeholders))
                    AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
                ";
                    $stmtCashIncome = $kasDb->prepare($sqlCashIncome);
                    $stmtCashIncome->execute(array_merge($capitalAccounts, [$thisMonth]));
                    $guestCashIncome = (float)($stmtCashIncome->fetchColumn() ?: 0);
                } else {
                    $sqlCashIncome = "
                    SELECT COALESCE(SUM(amount), 0) as total 
                    FROM cash_book 
                    WHERE transaction_type = 'income' 
                    AND payment_method = 'cash'
                    AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
                ";
                    $stmtCashIncome = $kasDb->prepare($sqlCashIncome);
                    $stmtCashIncome->execute([$thisMonth]);
                    $guestCashIncome = (float)($stmtCashIncome->fetchColumn() ?: 0);
                }
            } catch (PDOException $e) {
                error_log("Daily Cash Error: " . $e->getMessage());
            }

            // CASH AVAILABLE = Start Cash + Monthly Net (same as index.php)
            $dashCashAvailable = $startKasHariIni + $totalOperationalCash;
            ?>
            <div class="ow-panel ow-stack" data-p="kas">
            <?php if ($ov['divInc']): $divTot = array_sum(array_column($ov['divInc'], 't')); ?>
                <div class="ow-card ow-div">
                    <div class="ow-card-h">
                        <div class="ow-ic ic-violet"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg></div>
                        <div><b>Pemasukan per Divisi</b><small><?= date('F Y') ?></small></div>
                        <span class="ow-div-tot">Total <b><?= rp($divTot) ?></b></span>
                    </div>
                    <div class="ow-occ">
                        <div class="ow-donut"><canvas id="ovDivChart" width="108" height="108"></canvas></div>
                        <ul class="ow-legend" id="ovDivLegend"></ul>
                    </div>
                </div>
            <?php endif; ?>
            <div class="ow-card dc">
                <div class="dc-head">
                    <div class="ow-ic ic-navy"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg></div>
                    <div class="dc-t"><b>Daily Cash</b><small><?= count($todayKas) ?> transaksi hari ini</small></div>
                    <span class="dc-date"><?= date('d M Y') ?></span>
                </div>

                <div class="dc-hero">
                    <div class="dc-tile">
                        <span>Start Cash · <?= date('M') ?></span>
                        <b><?= number_format($startKasHariIni, 0, ',', '.') ?></b>
                    </div>
                    <div class="dc-tile main<?= $dashCashAvailable < 0 ? ' neg' : '' ?>">
                        <span>Cash Available</span>
                        <b><?= number_format($dashCashAvailable, 0, ',', '.') ?></b>
                    </div>
                </div>

                <div class="dc-strip">
                    <div><span>Owner Transfer</span><b class="g"><?= number_format($ownerTransferThisMonth, 0, ',', '.') ?></b></div>
                    <div><span>Owner + Guest</span><b class="g"><?= number_format($ownerTransferThisMonth + $guestCashIncome, 0, ',', '.') ?></b></div>
                    <div><span>Expense</span><b class="r"><?= number_format($totalOperationalExpense, 0, ',', '.') ?></b></div>
                </div>

                <?php if ($dashCashAvailable < 0): ?>
                    <div class="dc-warn">⚠ Kas negatif — cash available di bawah nol.</div>
                <?php endif; ?>

                <?php if (empty($todayKas)): ?>
                    <div class="ow-empty">Belum ada transaksi hari ini.</div>
                <?php else: ?>
                    <ul class="dc-list">
                        <?php
                        $operationalIds = array_map('intval', $allAccounts);
                        foreach ($todayKas as $kas):
                            $isMasuk = $kas['transaction_type'] === 'income';
                            $amount = (float)$kas['amount'];
                            $payMethod = strtolower(trim($kas['payment_method'] ?? 'other'));
                            $payLabel = strtoupper($payMethod === 'transfer' ? 'TF' : $payMethod);
                            $txAccId = isset($kas['cash_account_id']) ? (int)$kas['cash_account_id'] : 0;
                            $isOperationalIn = $isMasuk && $txAccId > 0 && in_array($txAccId, $operationalIds);
                            $amtClass = $isOperationalIn ? 'op' : ($isMasuk ? 'in' : 'out');
                        ?>
                            <li class="dc-row">
                                <span class="dc-time"><?= htmlspecialchars((string)$kas['jam']) ?></span>
                                <span class="dc-tag <?= $isMasuk ? 'in' : 'out' ?>"><?= $isMasuk ? 'IN' : 'OUT' ?></span>
                                <span class="dc-desc"><?= htmlspecialchars(html_entity_decode(mb_substr((string)$kas['description'], 0, 60), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?><em><?= htmlspecialchars($payLabel) ?></em></span>
                                <b class="dc-amt <?= $amtClass ?>"><?= $isMasuk ? '+' : '−' ?><?= number_format($amount, 0, ',', '.') ?></b>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            </div>
            <?php if ($isCQC): ?>
                <div class="ow-panel" data-p="proyek">
                <!-- CQC Project Monitoring - Elegant 2026 -->
                <div style="margin: 16px 0; padding: 20px; background: linear-gradient(180deg, #ffffff 0%, #fafbfc 100%); border-radius: 20px; box-shadow: 0 4px 24px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.04);">
                    <!-- Header with Icon -->
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 44px; height: 44px; background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%); border-radius: 14px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.35);">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="5" />
                                    <path d="m12 1v2m0 18v2m4.22-18.36 1.42 1.42M4.93 19.07l1.41 1.42m12.73 0 1.41-1.42M4.93 4.93l1.42 1.42M1 12h2m18 0h2" />
                                </svg>
                            </div>
                            <div>
                                <div style="font-size: 9px; color: #f59e0b; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px;">CQC Enjiniring</div>
                                <div style="font-size: 16px; font-weight: 700; color: #1f2937; letter-spacing: -0.4px; margin-top: 1px;">Pencapaian & Keuangan Per Proyek</div>
                            </div>
                        </div>
                        <a href="<?php echo $basePath; ?>/modules/cqc-projects/" style="padding: 8px 14px; background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%); color: white; border-radius: 10px; font-size: 11px; font-weight: 600; text-decoration: none; box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3); transition: all 0.2s;">Kelola →</a>
                    </div>

                    <?php if (empty($cqcProjects)): ?>
                        <div style="text-align: center; padding: 50px 20px; color: #9ca3af; background: #f9fafb; border-radius: 16px;">
                            <div style="width: 64px; height: 64px; margin: 0 auto 16px; background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                <span style="font-size: 28px;">☀️</span>
                            </div>
                            <div style="font-size: 16px; font-weight: 600; color: #6b7280;">Belum ada proyek</div>
                            <div style="font-size: 13px; margin-top: 6px; color: #9ca3af;">Tambahkan proyek di menu CQC Projects</div>
                        </div>
                    <?php else: ?>
                        <!-- Summary Stats - Glassmorphism Style -->
                        <?php
                        $totalBudget = array_sum(array_column($cqcProjects, 'budget_idr'));
                        $totalSpent = array_sum(array_column($cqcProjects, 'spent_idr'));
                        $totalRemaining = $totalBudget - $totalSpent;
                        $avgProgress = count($cqcProjects) > 0 ? round(array_sum(array_column($cqcProjects, 'progress_percentage')) / count($cqcProjects)) : 0;
                        $budgetUsedPct = $totalBudget > 0 ? round(($totalSpent / $totalBudget) * 100) : 0;
                        ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(80px, 1fr)); gap: 10px; margin-bottom: 24px;">
                            <div style="text-align: center; padding: 16px 10px; background: linear-gradient(135deg, #122f54 0%, #234e83 100%); border-radius: 16px; box-shadow: 0 4px 14px rgba(18, 47, 84, 0.30); border-top: 2px solid #c69a3f;">
                                <div style="font-size: 28px; font-weight: 800; color: #fff; font-family: system-ui; line-height: 1;"><?php echo count($cqcProjects); ?></div>
                                <div style="font-size: 10px; color: rgba(255,255,255,0.8); font-weight: 600; margin-top: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Total Proyek</div>
                            </div>
                            <div style="text-align: center; padding: 16px 10px; background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); border-radius: 16px; box-shadow: 0 4px 14px rgba(14, 165, 233, 0.25);">
                                <div style="font-size: 14px; font-weight: 700; color: #fff; font-family: system-ui;">Rp <?php echo number_format($totalBudget / 1000000000, 2); ?>M</div>
                                <div style="font-size: 10px; color: rgba(255,255,255,0.8); font-weight: 600; margin-top: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Total Budget</div>
                            </div>
                            <div style="text-align: center; padding: 16px 10px; background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%); border-radius: 16px; box-shadow: 0 4px 14px rgba(244, 63, 94, 0.25);">
                                <div style="font-size: 14px; font-weight: 700; color: #fff; font-family: system-ui;">Rp <?php echo number_format($totalSpent / 1000000, 0); ?>jt</div>
                                <div style="font-size: 10px; color: rgba(255,255,255,0.8); font-weight: 600; margin-top: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Terpakai (<?php echo $budgetUsedPct; ?>%)</div>
                            </div>
                            <div style="text-align: center; padding: 16px 10px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 16px; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.25);">
                                <div style="font-size: 28px; font-weight: 800; color: #fff; font-family: system-ui; line-height: 1;"><?php echo $avgProgress; ?>%</div>
                                <div style="font-size: 10px; color: rgba(255,255,255,0.8); font-weight: 600; margin-top: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Avg Progress</div>
                            </div>
                        </div>

                        <!-- Project Cards Grid - Modern Design -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px;">
                            <?php foreach ($cqcProjects as $idx => $proj):
                                $budget = floatval($proj['budget_idr'] ?? 0);
                                $spent = floatval($proj['spent_idr'] ?? 0);
                                $remaining = $budget - $spent;
                                $progress = intval($proj['progress_percentage'] ?? 0);
                                $spentPct = $budget > 0 ? round(($spent / $budget) * 100, 1) : 0;
                                $statusLabels = ['planning' => 'Planning', 'procurement' => 'Procurement', 'installation' => 'Instalasi', 'testing' => 'Testing', 'completed' => 'Selesai', 'on_hold' => 'Ditunda'];
                                $statusLabel = $statusLabels[$proj['status']] ?? ucfirst($proj['status']);
                                $statusColors = ['planning' => '#6366f1', 'procurement' => '#f59e0b', 'installation' => '#3b82f6', 'testing' => '#ec4899', 'completed' => '#10b981', 'on_hold' => '#6b7280'];
                                $statusColor = $statusColors[$proj['status']] ?? '#6b7280';
                                $expenses = $cqcExpenses[$proj['id']] ?? [];
                                $kwp = floatval($proj['solar_capacity_kwp'] ?? 0);
                                $startDate = $proj['start_date'] ?? null;
                                $estCompletion = $proj['estimated_completion'] ?? null;
                                $clientName = $proj['client_name'] ?? '';
                                // Modern color palette per project
                                $projectColorPalette = [
                                    ['#10b981', '#34d399', 'rgba(16, 185, 129, 0.1)'], // Emerald
                                    ['#f59e0b', '#fbbf24', 'rgba(245, 158, 11, 0.1)'], // Amber
                                    ['#3b82f6', '#60a5fa', 'rgba(59, 130, 246, 0.1)'], // Blue
                                    ['#8b5cf6', '#a78bfa', 'rgba(139, 92, 246, 0.1)'], // Violet
                                    ['#ec4899', '#f472b6', 'rgba(236, 72, 153, 0.1)'], // Pink
                                    ['#06b6d4', '#22d3ee', 'rgba(6, 182, 212, 0.1)'], // Cyan
                                    ['#84cc16', '#a3e635', 'rgba(132, 204, 22, 0.1)'], // Lime
                                    ['#f97316', '#fb923c', 'rgba(249, 115, 22, 0.1)'], // Orange
                                ];
                                $projColorIdx = $idx % count($projectColorPalette);
                                $projColor = $projectColorPalette[$projColorIdx][0];
                                $projColorLight = $projectColorPalette[$projColorIdx][1];
                                $projColorBg = $projectColorPalette[$projColorIdx][2];
                            ?>
                                <div class="cqc-project-card" onclick="toggleExpenseDetail(<?php echo $idx; ?>)" style="background: #fff; border-radius: 20px; padding: 20px; border: 1px solid #e5e7eb; box-shadow: 0 4px 20px rgba(0,0,0,0.04); cursor: pointer; transition: all 0.3s cubic-bezier(0.4,0,0.2,1); position: relative; overflow: hidden;" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 12px 32px rgba(0,0,0,0.1)'; this.style.borderColor='<?php echo $projColor; ?>';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 20px rgba(0,0,0,0.04)'; this.style.borderColor='#e5e7eb';">

                                    <!-- Accent Line Top -->
                                    <div style="position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, <?php echo $projColor; ?>, <?php echo $projColorLight; ?>);"></div>

                                    <!-- Header -->
                                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 16px;">
                                        <div style="flex: 1; min-width: 0;">
                                            <div style="font-size: 10px; color: <?php echo $projColor; ?>; font-weight: 700; letter-spacing: 1.2px; font-family: system-ui; text-transform: uppercase;"><?php echo htmlspecialchars($proj['project_code']); ?></div>
                                            <div style="font-size: 15px; font-weight: 700; color: #111827; margin-top: 4px; line-height: 1.3; letter-spacing: -0.3px;"><?php echo htmlspecialchars($proj['project_name']); ?></div>
                                            <?php if ($clientName): ?>
                                                <div style="font-size: 11px; color: #6b7280; margin-top: 3px; display: flex; align-items: center; gap: 4px;">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                                        <circle cx="12" cy="7" r="4" />
                                                    </svg>
                                                    <?php echo htmlspecialchars($clientName); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <span style="padding: 6px 12px; border-radius: 20px; font-size: 10px; font-weight: 700; background: <?php echo $statusColor; ?>15; color: <?php echo $statusColor; ?>; letter-spacing: 0.3px; text-transform: uppercase; white-space: nowrap;"><?php echo $statusLabel; ?></span>
                                    </div>

                                    <!-- Main Content: Progress Chart + Financial -->
                                    <div style="display: flex; gap: 20px; align-items: center; margin-bottom: 16px;">

                                        <!-- Progress Donut Chart -->
                                        <div style="flex-shrink: 0; text-align: center;">
                                            <div style="position: relative; width: 100px; height: 100px;">
                                                <canvas id="cqcPie<?php echo $idx; ?>" style="filter: drop-shadow(0 4px 12px <?php echo $projColor; ?>30);"></canvas>
                                                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                                                    <div style="font-size: 22px; font-weight: 800; color: <?php echo $projColor; ?>; line-height: 1; font-family: system-ui;"><?php echo $progress; ?>%</div>
                                                    <div style="font-size: 8px; color: #9ca3af; text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;">Progress</div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Financial Details -->
                                        <div style="flex: 1; min-width: 0;">
                                            <!-- Budget -->
                                            <div style="margin-bottom: 10px;">
                                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                                    <span style="font-size: 10px; color: #6b7280; font-weight: 600;">💰 Budget</span>
                                                    <span style="font-size: 12px; font-weight: 700; color: #374151; font-family: system-ui;"><?php echo number_format($budget / 1000000, 0); ?>jt</span>
                                                </div>
                                                <div style="height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">
                                                    <div style="width: <?php echo min($spentPct, 100); ?>%; height: 100%; background: linear-gradient(90deg, <?php echo $spentPct > 90 ? '#ef4444' : $projColor; ?>, <?php echo $spentPct > 90 ? '#f87171' : $projColorLight; ?>); border-radius: 3px; transition: width 0.5s ease;"></div>
                                                </div>
                                            </div>

                                            <!-- Spent & Remaining -->
                                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                                <div style="background: #fef2f2; padding: 8px 10px; border-radius: 10px;">
                                                    <div style="font-size: 8px; color: #9ca3af; text-transform: uppercase; font-weight: 600;">Terpakai</div>
                                                    <div style="font-size: 13px; font-weight: 700; color: #ef4444; font-family: system-ui; margin-top: 2px;"><?php echo number_format($spent / 1000000, 1); ?>jt</div>
                                                </div>
                                                <div style="background: <?php echo $remaining >= 0 ? '#f0fdf4' : '#fef2f2'; ?>; padding: 8px 10px; border-radius: 10px;">
                                                    <div style="font-size: 8px; color: #9ca3af; text-transform: uppercase; font-weight: 600;">Sisa</div>
                                                    <div style="font-size: 13px; font-weight: 700; color: <?php echo $remaining >= 0 ? '#10b981' : '#ef4444'; ?>; font-family: system-ui; margin-top: 2px;"><?php echo number_format($remaining / 1000000, 1); ?>jt</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Tags Row: KWP + Timeline -->
                                    <div style="display: flex; flex-wrap: wrap; gap: 8px; padding-top: 12px; border-top: 1px solid #f3f4f6;">
                                        <?php if ($kwp > 0): ?>
                                            <span style="padding: 5px 10px; background: linear-gradient(135deg, #fef3c7, #fde68a); border-radius: 8px; font-size: 10px; font-weight: 700; color: #92400e; display: flex; align-items: center; gap: 4px;">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                                    <path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z" />
                                                </svg>
                                                <?php echo number_format($kwp, 1); ?> kWp
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($startDate): ?>
                                            <span style="padding: 5px 10px; background: #f0fdf4; border-radius: 8px; font-size: 10px; font-weight: 600; color: #166534; display: flex; align-items: center; gap: 4px;">
                                                🚀 <?php echo date('d M Y', strtotime($startDate)); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($estCompletion): ?>
                                            <span style="padding: 5px 10px; background: #eff6ff; border-radius: 8px; font-size: 10px; font-weight: 600; color: #1e40af; display: flex; align-items: center; gap: 4px;">
                                                🎯 <?php echo date('d M Y', strtotime($estCompletion)); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Expense Detail (hidden by default) -->
                                    <div id="expenseDetail<?php echo $idx; ?>" style="display: none; margin-top: 16px; padding-top: 16px; border-top: 1px dashed #e5e7eb;">
                                        <div style="font-size: 11px; font-weight: 700; color: #374151; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                                            </svg>
                                            Pengeluaran Terbaru
                                        </div>
                                        <?php if (empty($expenses)): ?>
                                            <div style="text-align: center; padding: 16px; color: #9ca3af; font-size: 12px; background: #f9fafb; border-radius: 12px;">
                                                <div style="font-size: 24px; margin-bottom: 6px;">📭</div>
                                                Belum ada pengeluaran
                                            </div>
                                        <?php else: ?>
                                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                                <?php foreach ($expenses as $exp): ?>
                                                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background: #f9fafb; border-radius: 10px; border: 1px solid #f3f4f6;">
                                                        <div style="flex: 1; min-width: 0;">
                                                            <div style="font-size: 12px; font-weight: 600; color: #374151; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($exp['description'] ?? 'Pengeluaran'); ?></div>
                                                            <div style="font-size: 10px; color: #9ca3af; margin-top: 2px;"><?php echo $exp['expense_date'] ? date('d M Y', strtotime($exp['expense_date'])) : '-'; ?></div>
                                                        </div>
                                                        <div style="font-size: 12px; font-weight: 700; color: #ef4444; font-family: system-ui; white-space: nowrap; margin-left: 12px; background: #fef2f2; padding: 4px 8px; border-radius: 6px;">-Rp <?php echo number_format(floatval($exp['amount'] ?? 0), 0, ',', '.'); ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Click indicator -->
                                    <div style="text-align: center; margin-top: 12px;">
                                        <span id="clickHint<?php echo $idx; ?>" style="font-size: 10px; color: #c0c0c0; font-weight: 500; display: flex; align-items: center; justify-content: center; gap: 4px;">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <polyline points="6 9 12 15 18 9" />
                                            </svg>
                                            tap untuk detail
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; // end of else (has projects) 
                    ?>
                </div>
                </div>
            <?php endif; // end of isCQC 
            ?>

            <?php if (!$isCQC && $attStats['total'] > 0): ?>
                <!-- ═══════════════════════════════════════════ -->
                <!-- ATTENDANCE MONITORING                      -->
                <!-- ═══════════════════════════════════════════ -->
                <div class="ow-panel" data-p="absensi">
                <div class="att-section">
                    <!-- Hero Card with Stats -->
                    <div class="att-hero">
                        <div class="att-hero-top">
                            <div class="att-hero-title">👥 Staff Attendance</div>
                            <span class="att-hero-badge">● Live</span>
                        </div>

                        <!-- Date Navigation -->
                        <div class="att-date-nav">
                            <button class="att-date-btn" onclick="attNavDate(-1)">◀</button>
                            <span class="att-date-label" id="attDateLabel"><?php
                                                                            $hariIndo = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                                                                            $bulanIndoShort = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                                                                            $dt = new DateTime($attDate);
                                                                            echo $hariIndo[(int)$dt->format('w')] . ', ' . $dt->format('d') . ' ' . $bulanIndoShort[(int)$dt->format('n') - 1] . ' ' . $dt->format('Y');
                                                                            ?></span>
                            <button class="att-date-btn" onclick="attNavDate(1)">▶</button>
                        </div>

                        <!-- Stats Cards -->
                        <div class="att-stats">
                            <div class="att-stat-card asc-present">
                                <div class="att-stat-num" id="attStatPresent"><?= $attStats['present'] ?></div>
                                <div class="att-stat-label"><span class="att-stat-dot asd-present"></span> Hadir</div>
                            </div>
                            <div class="att-stat-card asc-late">
                                <div class="att-stat-num" id="attStatLate"><?= $attStats['late'] ?></div>
                                <div class="att-stat-label"><span class="att-stat-dot asd-late"></span> Terlambat</div>
                            </div>
                            <div class="att-stat-card asc-leave">
                                <div class="att-stat-num" id="attStatLeave"><?= $attStats['leave'] ?></div>
                                <div class="att-stat-label"><span class="att-stat-dot asd-leave"></span> Izin</div>
                            </div>
                            <div class="att-stat-card asc-absent">
                                <div class="att-stat-num" id="attStatAbsent"><?= $attStats['absent'] ?></div>
                                <div class="att-stat-label"><span class="att-stat-dot asd-absent"></span> Alpha</div>
                            </div>
                        </div>
                    </div>

                    <!-- Staff List -->
                    <div class="att-list-wrap">
                        <div class="att-list-header">
                            <div class="att-list-title">Daftar Kehadiran</div>
                            <div class="att-list-count" id="attListCount"><?= count($attRecords) ?>/<?= $attStats['total'] ?> staff</div>
                        </div>
                        <div id="attStaffList">
                            <?php
                            foreach ($attRecords as $ar):
                                $statusCls = 'av-present';
                                $badgeCls = 'asb-present';
                                $badgeText = 'Hadir';
                                $initial = mb_strtoupper(mb_substr($ar['full_name'], 0, 1));
                                if ($ar['status'] === 'late') {
                                    $statusCls = 'av-late';
                                    $badgeCls = 'asb-late';
                                    $badgeText = 'Terlambat';
                                } elseif ($ar['status'] === 'leave') {
                                    $statusCls = 'av-leave';
                                    $badgeCls = 'asb-leave';
                                    $badgeText = 'Izin';
                                } elseif ($ar['status'] === 'holiday') {
                                    $statusCls = 'av-leave';
                                    $badgeCls = 'asb-holiday';
                                    $badgeText = 'Libur';
                                } elseif ($ar['status'] === 'half_day') {
                                    $statusCls = 'av-late';
                                    $badgeCls = 'asb-half_day';
                                    $badgeText = 'Half Day';
                                }
                                $s1 = $ar['check_in_time'] ? substr($ar['check_in_time'], 0, 5) : '-';
                                $s2 = $ar['check_out_time'] ? substr($ar['check_out_time'], 0, 5) : '-';
                                $s3 = !empty($ar['scan_3']) ? substr($ar['scan_3'], 0, 5) : '';
                                $s4 = !empty($ar['scan_4']) ? substr($ar['scan_4'], 0, 5) : '';
                                $wh = $ar['work_hours'] ? number_format((float)$ar['work_hours'], 1) . 'h' : '';
                                $lateMins = (int)($ar['late_minutes'] ?? 0);
                                $noteText = trim($ar['notes'] ?? '');
                            ?>
                                <div class="att-emp-row">
                                    <div class="att-emp-avatar <?= $statusCls ?>"><?= $initial ?></div>
                                    <div class="att-emp-info">
                                        <div class="att-emp-name"><?= htmlspecialchars($ar['full_name']) ?></div>
                                        <div class="att-emp-meta">
                                            <span class="att-emp-pos"><?= htmlspecialchars($ar['position'] ?? '-') ?></span>
                                            <span class="att-status-badge <?= $badgeCls ?>"><?= $badgeText ?></span>
                                            <?php if ($lateMins > 0): ?><span class="att-late-tag">+<?= $lateMins ?>m</span><?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="att-emp-scans">
                                        <div class="att-scan-pills">
                                            <div class="att-scan-pill"><span class="att-sp-lbl">S1</span><span class="att-sp-val"><?= $s1 ?></span></div>
                                            <div class="att-scan-pill"><span class="att-sp-lbl">S2</span><span class="att-sp-val"><?= $s2 ?></span></div>
                                            <?php if ($s3): ?><div class="att-scan-pill"><span class="att-sp-lbl">S3</span><span class="att-sp-val"><?= $s3 ?></span></div><?php endif; ?>
                                            <?php if ($s4): ?><div class="att-scan-pill"><span class="att-sp-lbl">S4</span><span class="att-sp-val"><?= $s4 ?></span></div><?php endif; ?>
                                        </div>
                                        <?php if ($wh): ?><div class="att-emp-hours"><?= $wh ?></div><?php endif; ?>
                                        <?php if ($noteText): ?><div class="att-emp-note"><?= htmlspecialchars(mb_substr($noteText, 0, 20)) ?></div><?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <?php
                            $recordedIds = array_column($attRecords, 'employee_id');
                            foreach ($attEmployees as $emp):
                                if (in_array($emp['id'], $recordedIds)) continue;
                                $initial = mb_strtoupper(mb_substr($emp['full_name'], 0, 1));
                            ?>
                                <div class="att-emp-row">
                                    <div class="att-emp-avatar av-absent"><?= $initial ?></div>
                                    <div class="att-emp-info">
                                        <div class="att-emp-name"><?= htmlspecialchars($emp['full_name']) ?></div>
                                        <div class="att-emp-meta">
                                            <span class="att-emp-pos"><?= htmlspecialchars($emp['position'] ?? '-') ?></span>
                                            <span class="att-status-badge asb-absent">Alpha</span>
                                        </div>
                                    </div>
                                    <div class="att-emp-scans">
                                        <div style="font-size:9px;color:#dc2626;font-weight:700;">Tidak hadir</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                </div>
            <?php endif; ?>

        <?php endif; // end if (!$error) 
        ?>

    </div><!-- end .container -->

    <!-- Footer Nav -->
    <?php
    require_once __DIR__ . '/../../includes/owner_footer_nav.php';
    // Tautan halaman lain (dulu di footer) kini ada di sheet "Menu" pada dock bawah
    $owLinks = [];
    $owDefs = getOwnerFooterMenuDefinitions();
    foreach (getUserFooterMenus() as $k) {
        if (!isset($owDefs[$k]) || $k === 'home') continue;
        $m = $owDefs[$k];
        if (isset($m['requires_module']) && !isset($m['always_show'])) {
            $okMod = false;
            foreach ((array)$m['requires_module'] as $mod) if (in_array($mod, $enabledModules)) { $okMod = true; break; }
            if (!$okMod) continue;
        }
        $owLinks[] = ['label' => $m['label'], 'icon' => $m['icon'], 'url' => $k === 'logout' ? $basePath . '/logout.php' : $basePath . '/modules/owner/' . $m['url_key'], 'logout' => $k === 'logout'];
    }
    ?>
    <div class="ow-sheet-bg" id="owSheetBg"></div>
    <div class="ow-sheet" id="owSheet" role="dialog" aria-label="Menu">
        <div class="ow-sheet-grip"></div>
        <div class="ow-sheet-t">Menu</div>
        <div class="ow-sheet-grid">
            <?php foreach ($owLinks as $lk): ?>
                <a href="<?= htmlspecialchars($lk['url']) ?>" class="ow-sheet-i<?= $lk['logout'] ? ' out' : '' ?>">
                    <span style="font-family:'Segoe UI Emoji','Apple Color Emoji',sans-serif;"><?= $lk['icon'] ?></span>
                    <b><?= htmlspecialchars($lk['label']) ?></b>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    ?>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script>
        (function() {
            var b = document.getElementById('owMoreBtn'), sh = document.getElementById('owSheet'), bg = document.getElementById('owSheetBg');
            if (!b || !sh) return;
            function t(on) { sh.classList.toggle('on', on); bg.classList.toggle('on', on); }
            b.addEventListener('click', function() { t(!sh.classList.contains('on')); });
            bg.addEventListener('click', function() { t(false); });
        })();
    </script>
    <script>
        // Launcher ikon: satu bagian tampil per kali, halaman tidak memanjang
        (function() {
            var apps = document.querySelectorAll('#owApps [data-go]');
            if (!apps.length) return;
            function show(name) {
                var found = false;
                document.querySelectorAll('.ow-panel').forEach(function(p) { var on = p.dataset.p === name; p.classList.toggle('on', on); if (on) found = true; });
                if (!found) { name = 'ringkasan'; document.querySelectorAll('.ow-panel').forEach(function(p) { p.classList.toggle('on', p.dataset.p === name); }); }
                apps.forEach(function(a) { a.classList.toggle('on', a.dataset.go === name); });
                try { localStorage.setItem('owPanel', name); } catch (e) {}
                window.dispatchEvent(new Event('resize'));
            }
            apps.forEach(function(a) { a.addEventListener('click', function() { show(a.dataset.go); }); });
            var start = 'ringkasan';
            try { start = localStorage.getItem('owPanel') || 'ringkasan'; } catch (e) {}
            show(start);
        })();
    </script>
    <script>
        // Grafik ringkasan owner (7 hari, okupansi, pemasukan per divisi)
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Chart === 'undefined') return;
            var OV = <?= json_encode(['days' => $ov['days'], 'inc' => $ov['inc'], 'exp' => $ov['exp'], 'occ' => $ov['occToday'], 'div' => $ov['divInc']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
            function short(n) {
                var a = Math.abs(n);
                if (a >= 1e6) return (n / 1e6).toFixed(1).replace('.0', '') + ' jt';
                if (a >= 1e3) return Math.round(n / 1e3) + ' rb';
                return String(Math.round(n));
            }
            function rp(n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); }
            Chart.defaults.font.family = "'Inter', sans-serif";
            Chart.defaults.color = '#64748b';

            var flow = document.getElementById('ovFlowChart');
            if (flow) {
                new Chart(flow, {
                    type: 'bar',
                    data: { labels: OV.days, datasets: [
                        { label: 'Pemasukan', data: OV.inc, backgroundColor: '#10b981', borderRadius: 6, maxBarThickness: 18 },
                        { label: 'Pengeluaran', data: OV.exp, backgroundColor: '#ef4444', borderRadius: 6, maxBarThickness: 18 }
                    ] },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, font: { size: 11 } } },
                            tooltip: { callbacks: { label: function(c) { return ' ' + c.dataset.label + ': ' + rp(c.parsed.y); } } } },
                        scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                            y: { beginAtZero: true, grid: { color: '#eef2f7' }, ticks: { font: { size: 10 }, callback: short } } }
                    }
                });
            }
            var occ = document.getElementById('ovOccChart');
            if (occ && OV.occ.total > 0) {
                new Chart(occ, {
                    type: 'doughnut',
                    data: { labels: ['Terisi', 'Kosong', 'Diblok'], datasets: [{ data: [OV.occ.occupied, OV.occ.vacant, OV.occ.blocked], backgroundColor: ['#2563eb', '#e2e8f0', '#f59e0b'], borderWidth: 0, borderRadius: 4, spacing: 2 }] },
                    options: { responsive: false, cutout: '76%', plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(15,23,42,.95)', padding: 10, cornerRadius: 8, callbacks: { label: function(c) { return ' ' + c.label + ': ' + c.parsed + ' kamar'; } } } }, animation: { duration: 700, easing: 'easeOutQuart' } }
                });
            }            var dv = document.getElementById('ovDivChart');
            if (dv && OV.div.length) {
                var cols = ['#2563eb', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#ec4899', '#94a3b8'];
                var tot = OV.div.reduce(function(a, d) { return a + d.t; }, 0) || 1;
                new Chart(dv, {
                    type: 'doughnut',
                    data: { labels: OV.div.map(function(d) { return d.n; }), datasets: [{ data: OV.div.map(function(d) { return d.t; }), backgroundColor: cols, borderWidth: 0 }] },
                    options: { responsive: false, cutout: '70%', plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(c) { return ' ' + c.label + ': ' + rp(c.parsed); } } } } }
                });
                var lg = document.getElementById('ovDivLegend');
                lg.innerHTML = OV.div.map(function(d, i) {
                    return '<li><i style="background:' + cols[i % cols.length] + '"></i><span class="ln">' + String(d.n).replace(/[<>&]/g, '') + '</span><b>' + rp(d.t) + '</b><em>' + Math.round(d.t / tot * 100) + '%</em></li>';
                }).join('');
            }
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // ============================================
            // DOUGHNUT CHART - Expense per Division
            // ============================================
            var expDivCanvas = document.getElementById('expenseDivisionChart');
            var expenseDivisionChart = null;
            var divChartColors = [
                'rgba(239,68,68,0.85)', 'rgba(251,146,60,0.85)', 'rgba(245,158,11,0.85)',
                'rgba(234,179,8,0.85)', 'rgba(132,204,22,0.85)', 'rgba(34,197,94,0.85)',
                'rgba(20,184,166,0.85)', 'rgba(6,182,212,0.85)', 'rgba(59,130,246,0.85)',
                'rgba(99,102,241,0.85)', 'rgba(139,92,246,0.85)', 'rgba(168,85,247,0.85)'
            ];

            if (expDivCanvas) {
                var expDivCtx = expDivCanvas.getContext('2d');
                expenseDivisionChart = new Chart(expDivCtx, {
                    type: 'doughnut',
                    data: {
                        labels: [<?php foreach ($expenseDivisionData as $div): ?> '<?= addslashes($div['division_name']) ?>', <?php endforeach; ?>],
                        datasets: [{
                            data: [<?php foreach ($expenseDivisionData as $div): ?><?= $div['total'] ?>, <?php endforeach; ?>],
                            backgroundColor: divChartColors.slice(0, <?= count($expenseDivisionData) ?>),
                            borderWidth: 0,
                            hoverOffset: 14
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '58%',
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                backgroundColor: 'rgba(15,23,42,0.92)',
                                padding: 10,
                                titleFont: {
                                    size: 11,
                                    weight: '600'
                                },
                                bodyFont: {
                                    size: 10
                                },
                                cornerRadius: 8,
                                callbacks: {
                                    label: function(ctx) {
                                        var val = ctx.parsed || 0;
                                        var total = ctx.dataset.data.reduce(function(a, b) {
                                            return a + b;
                                        }, 0);
                                        var pct = ((val / total) * 100).toFixed(1);
                                        return ctx.label + ': Rp ' + val.toLocaleString('id-ID') + ' (' + pct + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // AJAX update for month change
            window.updateExpenseDivisionChart = function(month) {
                var basePath = '<?= $basePath ?>';
                var activeBiz = '<?= $activeBusinessId ?>';
                fetch(basePath + '/api/expense-division-data.php?month=' + month + '&business=' + activeBiz)
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        if (data.success && data.divisions.length > 0) {
                            // Show canvas, hide empty
                            var body = document.querySelector('.expense-division-body');
                            body.innerHTML = '<canvas id="expenseDivisionChart"></canvas>';
                            var newCtx = document.getElementById('expenseDivisionChart').getContext('2d');
                            expenseDivisionChart = new Chart(newCtx, {
                                type: 'doughnut',
                                data: {
                                    labels: data.divisions,
                                    datasets: [{
                                        data: data.amounts,
                                        backgroundColor: divChartColors.slice(0, data.divisions.length),
                                        borderWidth: 0,
                                        hoverOffset: 14
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    cutout: '58%',
                                    plugins: {
                                        legend: {
                                            display: false
                                        },
                                        tooltip: {
                                            backgroundColor: 'rgba(15,23,42,0.92)',
                                            padding: 10,
                                            titleFont: {
                                                size: 11,
                                                weight: '600'
                                            },
                                            bodyFont: {
                                                size: 10
                                            },
                                            cornerRadius: 8,
                                            callbacks: {
                                                label: function(ctx) {
                                                    var val = ctx.parsed || 0;
                                                    var total = ctx.dataset.data.reduce(function(a, b) {
                                                        return a + b;
                                                    }, 0);
                                                    var pct = ((val / total) * 100).toFixed(1);
                                                    return ctx.label + ': Rp ' + val.toLocaleString('id-ID') + ' (' + pct + '%)';
                                                }
                                            }
                                        }
                                    }
                                }
                            });
                            // Update legend
                            var legendEl = document.getElementById('expenseDivisionLegend');
                            if (legendEl) {
                                var html = '';
                                data.divisions.forEach(function(name, i) {
                                    html += '<div class="edl-item"><span class="edl-dot" style="background:' + divChartColors[i % divChartColors.length] + '"></span>' + name + '</div>';
                                });
                                legendEl.innerHTML = html;
                                legendEl.style.display = 'flex';
                            }
                        } else {
                            // Show empty state
                            var body = document.querySelector('.expense-division-body');
                            body.innerHTML = '<div class="expense-division-empty"><span style="font-size:28px;margin-bottom:6px;">📭</span>No expense data available</div>';
                            var legendEl = document.getElementById('expenseDivisionLegend');
                            if (legendEl) legendEl.style.display = 'none';
                        }
                    })
                    .catch(function(err) {
                        console.error('Error:', err);
                    });
            };

            // ============================================
            // MAIN DONUT CHART - Income vs Expense (Premium 2028)
            // ============================================
            var canvas = document.getElementById('pieChart');
            if (!canvas) return;

            var income = <?= (float)$stats['month_income'] ?>;
            var expense = <?= (float)$stats['month_expense'] ?>;
            if (income === 0 && expense === 0) {
                income = 1;
                expense = 1;
            }

            var pieCtx = canvas.getContext('2d');
            var incomeGrad = pieCtx.createLinearGradient(0, 0, 140, 140);
            incomeGrad.addColorStop(0, '#10b981');
            incomeGrad.addColorStop(1, '#34d399');
            var expenseGrad = pieCtx.createLinearGradient(0, 0, 140, 140);
            expenseGrad.addColorStop(0, '#ef4444');
            expenseGrad.addColorStop(1, '#fb7185');

            new Chart(pieCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Income', 'Expense'],
                    datasets: [{
                        data: [income, expense],
                        backgroundColor: [incomeGrad, expenseGrad],
                        borderWidth: 0,
                        borderRadius: 6,
                        hoverOffset: 6,
                        spacing: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '68%',
                    rotation: -90,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15,23,42,0.95)',
                            titleColor: '#e5e7eb',
                            bodyColor: '#e5e7eb',
                            cornerRadius: 10,
                            padding: 12,
                            displayColors: true,
                            titleFont: {
                                size: 12,
                                weight: '700'
                            },
                            bodyFont: {
                                size: 11
                            },
                            callbacks: {
                                label: function(ctx) {
                                    var val = ctx.parsed || 0;
                                    var total = ctx.dataset.data.reduce(function(a, b) {
                                        return a + b;
                                    }, 0);
                                    var pct = total > 0 ? ((val / total) * 100).toFixed(1) : 0;
                                    return ctx.label + ': Rp ' + val.toLocaleString('id-ID') + ' (' + pct + '%)';
                                }
                            }
                        }
                    },
                    animation: {
                        animateRotate: true,
                        duration: 800,
                        easing: 'easeOutQuart'
                    }
                }
            });
        });

        <?php if ($isCQC && !empty($cqcProjects)): ?>
            // CQC PROJECT PIE CHARTS - Modern Elegant with Shadows
            <?php
            $projectColors = [
                ['#10b981', '#34d399'], // Emerald
                ['#f59e0b', '#fbbf24'], // Amber
                ['#3b82f6', '#60a5fa'], // Blue
                ['#8b5cf6', '#a78bfa'], // Violet
                ['#ec4899', '#f472b6'], // Pink
                ['#06b6d4', '#22d3ee'], // Cyan
                ['#84cc16', '#a3e635'], // Lime
                ['#f97316', '#fb923c'], // Orange
            ];
            foreach ($cqcProjects as $idx => $proj):
                $progress = intval($proj['progress_percentage'] ?? 0);
                $colorIdx = $idx % count($projectColors);
                $color1 = $projectColors[$colorIdx][0];
                $color2 = $projectColors[$colorIdx][1];
            ?>
                    (function() {
                        const ctx = document.getElementById('cqcPie<?php echo $idx; ?>');
                        if (!ctx) return;

                        // Create elegant gradient
                        const chartCtx = ctx.getContext('2d');
                        const gradient = chartCtx.createLinearGradient(0, 0, 100, 100);
                        gradient.addColorStop(0, '<?php echo $color1; ?>');
                        gradient.addColorStop(1, '<?php echo $color2; ?>');

                        new Chart(chartCtx, {
                            type: 'doughnut',
                            data: {
                                labels: ['Progress', 'Remaining'],
                                datasets: [{
                                    data: [<?php echo $progress; ?>, <?php echo 100 - $progress; ?>],
                                    backgroundColor: [gradient, '#f3f4f6'],
                                    borderWidth: 0,
                                    borderRadius: 8,
                                    hoverBackgroundColor: ['<?php echo $color1; ?>', '#e5e7eb'],
                                    hoverOffset: 4
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
                                        backgroundColor: 'rgba(17, 24, 39, 0.95)',
                                        titleColor: '<?php echo $color2; ?>',
                                        bodyColor: '#e5e7eb',
                                        cornerRadius: 12,
                                        padding: 14,
                                        displayColors: false,
                                        titleFont: {
                                            size: 13,
                                            weight: '700'
                                        },
                                        bodyFont: {
                                            size: 12
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
                                    duration: 1000,
                                    easing: 'easeOutQuart'
                                }
                            }
                        });

                        // Category Expense Pie Chart
                        <?php
                        $catExpenses = $cqcCategoryExpenses[$proj['id']] ?? [];
                        if (!empty($catExpenses)):
                            $catColors = ['#6366f1', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4'];
                            $catLabels = array_map(function ($c) {
                                return $c['category_name'];
                            }, $catExpenses);
                            $catValues = array_map(function ($c) {
                                return floatval($c['total_amount']);
                            }, $catExpenses);
                            $catColorsJson = array_slice($catColors, 0, count($catExpenses));
                        ?>
                            const catCtx<?php echo $idx; ?> = document.getElementById('cqcCatPie<?php echo $idx; ?>');
                            if (catCtx<?php echo $idx; ?>) {
                                new Chart(catCtx<?php echo $idx; ?>.getContext('2d'), {
                                    type: 'doughnut',
                                    data: {
                                        labels: <?php echo json_encode($catLabels); ?>,
                                        datasets: [{
                                            data: <?php echo json_encode($catValues); ?>,
                                            backgroundColor: <?php echo json_encode($catColorsJson); ?>,
                                            borderWidth: 0
                                        }]
                                    },
                                    options: {
                                        responsive: true,
                                        maintainAspectRatio: true,
                                        cutout: '55%',
                                        plugins: {
                                            legend: {
                                                display: false
                                            },
                                            tooltip: {
                                                backgroundColor: '#374151',
                                                bodyColor: '#e5e7eb',
                                                cornerRadius: 8,
                                                padding: 8,
                                                displayColors: true,
                                                callbacks: {
                                                    label: function(ctx) {
                                                        return ctx.label + ': Rp ' + ctx.parsed.toLocaleString();
                                                    }
                                                }
                                            }
                                        },
                                        animation: {
                                            animateRotate: true,
                                            duration: 600
                                        }
                                    }
                                });
                            }
                        <?php endif; ?>
                    })();
            <?php endforeach; ?>
        <?php endif; ?>

        // Toggle expense detail with smooth animation
        function toggleExpenseDetail(idx) {
            const detail = document.getElementById('expenseDetail' + idx);
            const hint = document.getElementById('clickHint' + idx);
            if (detail.style.display === 'none') {
                detail.style.display = 'block';
                detail.style.animation = 'fadeIn 0.3s ease';
                hint.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg> tutup detail';
            } else {
                detail.style.display = 'none';
                hint.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg> tap untuk detail';
            }
        }

        // ═══════════════════════════════════════════
        // AI HEALTH - Toggle Expand/Collapse
        // ═══════════════════════════════════════════
        function toggleAiHealth() {
            const detail = document.getElementById('aiHealthDetail');
            const chevron = document.getElementById('aiChevron');
            if (detail.style.display === 'none') {
                detail.style.display = 'block';
                detail.style.animation = 'fadeIn 0.3s ease';
                chevron.style.transform = 'rotate(180deg)';
            } else {
                detail.style.display = 'none';
                chevron.style.transform = 'rotate(0deg)';
            }
        }

        // ═══════════════════════════════════════════
        // ATTENDANCE MONITORING - Date Navigation
        // ═══════════════════════════════════════════
        <?php if (!$isCQC && $attStats['total'] > 0): ?>
                (function() {
                    let attCurrentDate = '<?= $attDate ?>';
                    const businessId = <?= (int)$_SESSION['business_id'] ?>;
                    const hariIndo = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                    const bulanShort = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

                    function formatDateIndo(dateStr) {
                        const d = new Date(dateStr + 'T00:00:00');
                        return hariIndo[d.getDay()] + ', ' + String(d.getDate()).padStart(2, '0') + ' ' + bulanShort[d.getMonth()] + ' ' + d.getFullYear();
                    }

                    window.attNavDate = function(offset) {
                        const d = new Date(attCurrentDate + 'T00:00:00');
                        d.setDate(d.getDate() + offset);
                        // Don't go beyond today
                        const today = new Date();
                        today.setHours(0, 0, 0, 0);
                        if (d > today) return;

                        attCurrentDate = d.toISOString().split('T')[0];
                        document.getElementById('attDateLabel').textContent = formatDateIndo(attCurrentDate);
                        loadAttendance(attCurrentDate);
                    };

                    function loadAttendance(dateStr) {
                        const list = document.getElementById('attStaffList');
                        list.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted);font-size:12px;">Memuat data...</div>';

                        fetch(basePath + '/api/owner-attendance.php?date=' + encodeURIComponent(dateStr) + '&business_id=' + businessId)
                            .then(r => r.json())
                            .then(data => {
                                if (!data.success) {
                                    list.innerHTML = '<div style="text-align:center;padding:20px;color:#dc2626;font-size:12px;">Gagal memuat data</div>';
                                    return;
                                }
                                // Update stats
                                document.getElementById('attStatPresent').textContent = data.stats.present;
                                document.getElementById('attStatLate').textContent = data.stats.late;
                                document.getElementById('attStatLeave').textContent = data.stats.leave;
                                document.getElementById('attStatAbsent').textContent = data.stats.absent;
                                document.getElementById('attListCount').textContent = data.stats.recorded + '/' + data.stats.total + ' staff';

                                let html = '';
                                // Present/late employees
                                data.records.forEach(ar => {
                                    let statusCls = 'av-present',
                                        badgeCls = 'asb-present',
                                        badgeText = 'Hadir';
                                    if (ar.status === 'late') {
                                        statusCls = 'av-late';
                                        badgeCls = 'asb-late';
                                        badgeText = 'Terlambat';
                                    } else if (ar.status === 'leave') {
                                        statusCls = 'av-leave';
                                        badgeCls = 'asb-leave';
                                        badgeText = 'Izin';
                                    } else if (ar.status === 'holiday') {
                                        statusCls = 'av-leave';
                                        badgeCls = 'asb-holiday';
                                        badgeText = 'Libur';
                                    } else if (ar.status === 'half_day') {
                                        statusCls = 'av-late';
                                        badgeCls = 'asb-half_day';
                                        badgeText = 'Half Day';
                                    }
                                    const s1 = ar.check_in_time ? ar.check_in_time.substring(0, 5) : '-';
                                    const s2 = ar.check_out_time ? ar.check_out_time.substring(0, 5) : '-';
                                    const s3 = ar.scan_3 ? ar.scan_3.substring(0, 5) : '';
                                    const s4 = ar.scan_4 ? ar.scan_4.substring(0, 5) : '';
                                    const wh = ar.work_hours ? parseFloat(ar.work_hours).toFixed(1) + 'h' : '';
                                    const lateMins = parseInt(ar.late_minutes || 0);
                                    const noteText = (ar.notes || '').trim();
                                    const initial = (ar.full_name || '?')[0].toUpperCase();

                                    html += '<div class="att-emp-row">';
                                    html += '<div class="att-emp-avatar ' + statusCls + '">' + initial + '</div>';
                                    html += '<div class="att-emp-info"><div class="att-emp-name">' + (ar.full_name || '-') + '</div>';
                                    html += '<div class="att-emp-meta"><span class="att-emp-pos">' + (ar.position || '-') + '</span>';
                                    html += '<span class="att-status-badge ' + badgeCls + '">' + badgeText + '</span>';
                                    if (lateMins > 0) html += '<span class="att-late-tag">+' + lateMins + 'm</span>';
                                    html += '</div></div>';
                                    html += '<div class="att-emp-scans"><div class="att-scan-pills">';
                                    html += '<div class="att-scan-pill"><span class="att-sp-lbl">S1</span><span class="att-sp-val">' + s1 + '</span></div>';
                                    html += '<div class="att-scan-pill"><span class="att-sp-lbl">S2</span><span class="att-sp-val">' + s2 + '</span></div>';
                                    if (s3) html += '<div class="att-scan-pill"><span class="att-sp-lbl">S3</span><span class="att-sp-val">' + s3 + '</span></div>';
                                    if (s4) html += '<div class="att-scan-pill"><span class="att-sp-lbl">S4</span><span class="att-sp-val">' + s4 + '</span></div>';
                                    html += '</div>';
                                    if (wh) html += '<div class="att-emp-hours">' + wh + '</div>';
                                    if (noteText) html += '<div class="att-emp-note">' + noteText.substring(0, 20) + '</div>';
                                    html += '</div></div>';
                                });

                                // Absent employees
                                data.absent.forEach(emp => {
                                    const initial = (emp.full_name || '?')[0].toUpperCase();
                                    html += '<div class="att-emp-row">';
                                    html += '<div class="att-emp-avatar av-absent">' + initial + '</div>';
                                    html += '<div class="att-emp-info"><div class="att-emp-name">' + (emp.full_name || '-') + '</div>';
                                    html += '<div class="att-emp-meta"><span class="att-emp-pos">' + (emp.position || '-') + '</span>';
                                    html += '<span class="att-status-badge asb-absent">Alpha</span></div></div>';
                                    html += '<div class="att-emp-scans"><div style="font-size:9px;color:#dc2626;font-weight:700;">Tidak hadir</div></div>';
                                    html += '</div>';
                                });

                                if (!html) html = '<div style="text-align:center;padding:20px;color:var(--text-muted);font-size:12px;">Tidak ada data kehadiran</div>';
                                list.innerHTML = html;
                            })
                            .catch(() => {
                                list.innerHTML = '<div style="text-align:center;padding:20px;color:#dc2626;font-size:12px;">Error koneksi</div>';
                            });
                    }
                })();
        <?php endif; ?>
    </script>
    <style>
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</body>

</html>