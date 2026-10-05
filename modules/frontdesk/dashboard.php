<?php

/**
 * FRONT DESK DASHBOARD - Occupancy & Analytics
 * Premium dashboard dengan Chart.js & glasmorphism
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// ============================================
// SECURITY & AUTHENTICATION
// ============================================
$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

// Verify permission
if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$pageTitle = 'Front Desk Dashboard - Occupancy & Analytics';

// ============================================
// HELPER: Build WhatsApp chat link from a guest phone number
// ============================================
function dashboard_wa_link($phone)
{
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if ($digits === '') {
        return null;
    }
    if (substr($digits, 0, 1) === '0') {
        $digits = '62' . substr($digits, 1);
    } elseif (substr($digits, 0, 2) !== '62') {
        $digits = '62' . $digits;
    }
    return 'https://wa.me/' . $digits;
}

// ============================================
// GET COMPREHENSIVE STATISTICS
// ============================================
try {
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $thisMonth = date('Y-m');

    // Catatan: halaman ini tidak lagi meng-checkout booking yang lewat tanggal atau menghapus baris
    // cash_book saat dibuka. Tamu overdue tetap in-house sampai di-checkout lewat alur check-out resmi
    // (cek saldo + sinkron buku kas). Pembersihan "duplikat" per kode booking dulu ikut menghapus
    // pembayaran sah (DP + pelunasan satu booking).

    // 1. Total In-House Guests (checked in, currently staying)
    // Count ALL checked_in bookings (after auto-checkout, only current ones remain)
    $inHouseResult = $db->fetchOne("
        SELECT COUNT(DISTINCT b.guest_id) as count 
        FROM bookings b
        WHERE b.status = 'checked_in'
    ");
    $stats['in_house'] = $inHouseResult['count'] ?? 0;

    // 2. Total Check-out Today
    $checkoutTodayResult = $db->fetchOne("
        SELECT COUNT(*) as count 
        FROM bookings 
        WHERE DATE(check_out_date) = ?
        AND status = 'checked_in'
    ", [$today]);
    $stats['checkout_today'] = $checkoutTodayResult['count'] ?? 0;

    // 3. Total Arrival Today
    $arrivalTodayResult = $db->fetchOne("
        SELECT COUNT(*) as count 
        FROM bookings 
        WHERE DATE(check_in_date) = ?
        AND status IN ('confirmed', 'checked_in')
    ", [$today]);
    $stats['arrival_today'] = $arrivalTodayResult['count'] ?? 0;

    // 4. Predicted Arrivals Tomorrow
    $arrivalTomorrowResult = $db->fetchOne("
        SELECT COUNT(*) as count 
        FROM bookings 
        WHERE DATE(check_in_date) = ?
        AND status = 'confirmed'
    ", [$tomorrow]);
    $stats['predicted_tomorrow'] = $arrivalTomorrowResult['count'] ?? 0;

    // 5. Occupancy Data (for Pie Chart)
    $totalRoomsResult = $db->fetchOne("SELECT COUNT(*) as count FROM rooms");
    $stats['total_rooms'] = max(1, $totalRoomsResult['count'] ?? 0);

    // Count occupied rooms - All checked_in bookings (overdue already auto-checked-out)
    $occupiedRoomsResult = $db->fetchOne("
        SELECT COUNT(DISTINCT b.room_id) as count 
        FROM bookings b
        WHERE b.status = 'checked_in'
    ");
    $stats['occupied_rooms'] = $occupiedRoomsResult['count'] ?? 0;
    $stats['available_rooms'] = max(0, $stats['total_rooms'] - $stats['occupied_rooms']);
    $stats['occupancy_rate'] = ($stats['total_rooms'] > 0)
        ? round(($stats['occupied_rooms'] / $stats['total_rooms']) * 100, 1)
        : 0;

    // 6. Today's Revenue - FROM CASH_BOOK (sudah dipotong OTA fee dll)
    // HANYA dari transaksi RESERVASI hotel, bukan modal owner atau kas manual
    // Filter: description mengandung "Reservasi" atau "Reservation"
    $revenueResult = $db->fetchOne("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM cash_book
        WHERE transaction_type = 'income'
        AND transaction_date = ?
        AND (description LIKE '%Reservasi%' OR description LIKE '%Reservation%' OR description LIKE '%BK-%')
    ", [$today]);
    $stats['revenue_today'] = $revenueResult['total'] ?? 0;

    // 7. Expected Revenue - SUM(final_price) of ALL non-cancelled bookings with check_in this month
    //    Decreases when a booking is cancelled
    $expectedResult = $db->fetchOne("
        SELECT COALESCE(SUM(final_price), 0) as total
        FROM bookings
        WHERE status NOT IN ('cancelled')
        AND DATE_FORMAT(check_in_date, '%Y-%m') = ?
    ", [$thisMonth]);
    $stats['expected_revenue'] = $expectedResult['total'] ?? 0;

    // OTA Revenue Today - Dari cash_book dengan payment_method OTA
    // HANYA dari transaksi RESERVASI hotel
    $otaRevenueResult = $db->fetchOne("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM cash_book
        WHERE transaction_type = 'income'
        AND transaction_date = ?
        AND (LOWER(payment_method) = 'ota' OR LOWER(payment_method) = 'agoda' OR LOWER(payment_method) = 'booking')
        AND (description LIKE '%Reservasi%' OR description LIKE '%Reservation%' OR description LIKE '%BK-%')
    ", [$today]);
    $stats['ota_revenue_today'] = $otaRevenueResult['total'] ?? 0;

    // 9. Room Revenue - ambil nilai TERBESAR antara paid_amount (di bookings) dan cash_book
    //    Covers semua kasus: bayar via Pay button (paid_amount), manual input buku kas, checkin sync
    $roomBookings = $db->fetchAll("
        SELECT booking_code, paid_amount
        FROM bookings
        WHERE status = 'checked_in'
           OR (status = 'checked_out' AND DATE_FORMAT(check_out_date, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m'))
    ");
    $totalRoomRevenue = 0;
    foreach ($roomBookings as $bk) {
        $paidAmount = (float)($bk['paid_amount'] ?? 0);
        $cbAmount   = 0;
        // Cari di cash_book hanya kalau booking_code tidak kosong
        if (!empty($bk['booking_code'])) {
            $cbResult = $db->fetchOne("
                SELECT COALESCE(SUM(amount), 0) as total
                FROM cash_book
                WHERE transaction_type = 'income'
                AND description LIKE ?
                AND DATE_FORMAT(transaction_date, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
            ", ['%' . $bk['booking_code'] . '%']);
            $cbAmount = (float)($cbResult['total'] ?? 0);
        }
        // Gunakan nilai terbesar: cash_book = aktual diterima, paid_amount = record sistem
        $totalRoomRevenue += max($paidAmount, $cbAmount);
    }
    $stats['inhouse_revenue'] = $totalRoomRevenue;

    // 10. Direct Booking Payments Today (alternative source if cash_book empty)
    // EXCLUDE: OTA bookings (masuk saat check-in) dan booking yang belum check-in
    $directPaymentsResult = $db->fetchOne("
        SELECT COALESCE(SUM(bp.amount), 0) as total
        FROM booking_payments bp
        JOIN bookings b ON bp.booking_id = b.id
        WHERE DATE(bp.payment_date) = ?
        AND b.status = 'checked_in'
        AND LOWER(COALESCE(b.booking_source, '')) NOT LIKE '%agoda%'
        AND LOWER(COALESCE(b.booking_source, '')) NOT LIKE '%booking%'
        AND LOWER(COALESCE(b.booking_source, '')) NOT LIKE '%tiket%'
        AND LOWER(COALESCE(b.booking_source, '')) NOT LIKE '%traveloka%'
        AND LOWER(COALESCE(b.booking_source, '')) NOT LIKE '%airbnb%'
        AND LOWER(COALESCE(b.booking_source, '')) NOT LIKE '%expedia%'
        AND LOWER(COALESCE(b.booking_source, '')) NOT LIKE '%ota%'
    ", [$today]);
    $stats['direct_payments_today'] = $directPaymentsResult['total'] ?? 0;

    // Fallback for today's payments from bookings.paid_amount (created today)
    // ONLY checked_in AND direct bookings
    if ($stats['direct_payments_today'] == 0) {
        $fallbackToday = $db->fetchOne("
            SELECT COALESCE(SUM(paid_amount), 0) as total
            FROM bookings
            WHERE DATE(created_at) = ? 
            AND status = 'checked_in'
            AND LOWER(COALESCE(booking_source, '')) NOT LIKE '%agoda%'
            AND LOWER(COALESCE(booking_source, '')) NOT LIKE '%booking%'
            AND LOWER(COALESCE(booking_source, '')) NOT LIKE '%tiket%'
            AND LOWER(COALESCE(booking_source, '')) NOT LIKE '%traveloka%'
            AND LOWER(COALESCE(booking_source, '')) NOT LIKE '%ota%'
        ", [$today]);
        $stats['direct_payments_today'] = $fallbackToday['total'] ?? 0;
    }

    // Use direct payments if cash_book revenue is 0 but booking_payments has data
    if ($stats['revenue_today'] == 0 && $stats['direct_payments_today'] > 0) {
        $stats['revenue_today'] = $stats['direct_payments_today'];
    }

    // 11. Paid This Month - actual money received: paid_amount from paid/partial bookings
    //     with check_in this month (lunas + DP direct bookings), excludes cancelled
    // 11. Paid This Month - DIRECT BOOKINGS ONLY (not OTA)
    //     OTA revenue is only recorded at check-in, NOT upfront
    //     Include: walk-in, direct, front desk, phone, website (anything not OTA)
    $monthRevenueResult = $db->fetchOne("
        SELECT COALESCE(SUM(paid_amount), 0) as total
        FROM bookings
        WHERE status NOT IN ('cancelled')
        AND payment_status IN ('paid', 'partial')
        AND DATE_FORMAT(check_in_date, '%Y-%m') = ?
        AND LOWER(COALESCE(booking_source, 'direct')) NOT LIKE '%agoda%'
        AND LOWER(COALESCE(booking_source, 'direct')) NOT LIKE '%booking%'
        AND LOWER(COALESCE(booking_source, 'direct')) NOT LIKE '%tiket%'
        AND LOWER(COALESCE(booking_source, 'direct')) NOT LIKE '%traveloka%'
        AND LOWER(COALESCE(booking_source, 'direct')) NOT LIKE '%airbnb%'
        AND LOWER(COALESCE(booking_source, 'direct')) NOT LIKE '%expedia%'
        AND LOWER(COALESCE(booking_source, 'direct')) NOT LIKE '%pegipegi%'
        AND LOWER(COALESCE(booking_source, 'direct')) NOT LIKE '%ota%'
    ", [$thisMonth]);
    $stats['month_revenue'] = $monthRevenueResult['total'] ?? 0;

    // Also check cash_book income for this month (covers all actual received payments:
    // direct, OTA-at-checkin, manually-added entries — if it's in cash_book it was received)
    $cashbookMonthResult = $db->fetchOne("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM cash_book
        WHERE transaction_type = 'income'
        AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
        AND (description LIKE '%BK-%' OR description LIKE '%Reserv%' OR description LIKE '%Room%' OR description LIKE '%Hotel%')
    ", [$thisMonth]);
    $cbTotal = $cashbookMonthResult['total'] ?? 0;
    // cash_book is the source of truth — use whichever is higher
    if ($cbTotal > $stats['month_revenue']) {
        $stats['month_revenue'] = $cbTotal;
    }

    // 12. Guest Data for Today
    // Fix: Show ALL checked_in guests regardless of dates
    $guestsTodayResult = $db->fetchAll("
        SELECT 
            b.id,
            g.guest_name,
            g.phone,
            b.room_id,
            r.room_number,
            b.check_in_date,
            b.check_out_date,
            b.status
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.status = 'checked_in'
        ORDER BY r.room_number ASC
        LIMIT 20
    ");
    $stats['guests_today'] = $guestsTodayResult;

    // 13. Upcoming Reservations (next check-ins, confirmed, limit 10)
    $upcomingReservations = $db->fetchAll("
        SELECT 
            b.id,
            g.guest_name,
            b.room_id,
            r.room_number,
            b.check_in_date,
            b.check_out_date,
            b.final_price,
            b.booking_code,
            b.booking_source,
            b.payment_status
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.status = 'confirmed'
          AND b.check_in_date >= NOW()
        ORDER BY b.check_in_date ASC
        LIMIT 10
    ");
    $stats['upcoming_reservations'] = $upcomingReservations;

    // 9. Checkout Guests Today - Detail list
    $checkoutGuestsResult = $db->fetchAll("
        SELECT 
            b.id,
            g.guest_name,
            g.phone,
            b.room_id,
            r.room_number,
            rt.type_name as room_type,
            b.check_in_date,
            b.check_out_date,
            b.final_price,
            b.status,
            b.group_id,
            GREATEST(COALESCE((SELECT SUM(amount) FROM booking_payments WHERE booking_id = b.id), 0), COALESCE(b.paid_amount, 0)) as paid_amount
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE DATE(b.check_out_date) = ?
        AND b.status = 'checked_in'
        ORDER BY r.room_number ASC
        LIMIT 10
    ", [$today]);
    // Booking grup: lunas/tidaknya dihitung dari total gabungan semua kamar grup.
    $coGroupIds = array_values(array_unique(array_filter(array_column($checkoutGuestsResult ?: [], 'group_id'))));
    $coGroupRemaining = [];
    if ($coGroupIds) {
        $ph = implode(',', array_fill(0, count($coGroupIds), '?'));
        foreach ($db->fetchAll("
            SELECT b.group_id,
                   SUM(b.final_price) - SUM(GREATEST(COALESCE((SELECT SUM(amount) FROM booking_payments WHERE booking_id = b.id), 0), COALESCE(b.paid_amount, 0))) AS remaining
            FROM bookings b WHERE b.group_id IN ({$ph}) AND b.status <> 'cancelled' GROUP BY b.group_id
        ", $coGroupIds) as $g) {
            $coGroupRemaining[$g['group_id']] = max(0, (float)$g['remaining']);
        }
    }
    foreach ($checkoutGuestsResult as &$coRow) {
        $coRow['remaining'] = !empty($coRow['group_id']) && isset($coGroupRemaining[$coRow['group_id']])
            ? $coGroupRemaining[$coRow['group_id']]
            : max(0, (float)$coRow['final_price'] - (float)$coRow['paid_amount']);
    }
    unset($coRow);
    $stats['checkout_guests'] = $checkoutGuestsResult;
} catch (\Throwable $e) {
    error_log("Dashboard Stats Error: " . $e->getMessage());
    $stats = [
        'in_house' => 0,
        'checkout_today' => 0,
        'arrival_today' => 0,
        'predicted_tomorrow' => 0,
        'total_rooms' => 0,
        'occupied_rooms' => 0,
        'available_rooms' => 0,
        'occupancy_rate' => 0,
        'revenue_today' => 0,
        'expected_revenue' => 0,
        'inhouse_revenue' => 0,
        'month_revenue' => 0,
        'direct_payments_today' => 0,
        'ota_revenue_today' => 0,
        'guests_today' => [],
        'checkout_guests' => []
    ];
}

include '../../includes/header.php';
?>

<style>
    /* ============================================
   PREMIUM 2028 VIBE - GLASSMORPHISM DESIGN
   ============================================ */

    :root {
        --primary-gradient: linear-gradient(135deg, #6366f1, #8b5cf6);
        --success-gradient: linear-gradient(135deg, #10b981, #34d399);
        --warning-gradient: linear-gradient(135deg, #f59e0b, #fbbf24);
        --info-gradient: linear-gradient(135deg, #3b82f6, #60a5fa);
        --danger-gradient: linear-gradient(135deg, #ef4444, #f87171);

        --glass-bg: rgba(255, 255, 255, 0.75);
        --glass-border: rgba(255, 255, 255, 0.45);
        --glass-blur: 16px;
    }

    [data-theme="dark"] {
        --glass-bg: rgba(30, 41, 59, 0.75);
        --glass-border: rgba(71, 85, 105, 0.45);
    }

    .dashboard-container {
        max-width: 1800px;
        margin: 0 auto;
        padding: 1.1rem 1rem;
        position: relative;
        min-height: 100vh;
    }

    .dashboard-container::before {
        display: none;
    }

    .dashboard-container>* {
        position: relative;
        z-index: 1;
    }

    /* ============================================
   PREMIUM HEADER
   ============================================ */

    .dashboard-header {
        margin-bottom: 1.1rem;
        position: relative;
        z-index: 1;
    }

    .dashboard-header::before {
        display: none;
    }

    .dashboard-header-content {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1.5rem;
    }

    .dashboard-header h1 {
        font-size: 1.6rem;
        font-weight: 800;
        color: #1e3a8a;
        -webkit-text-fill-color: #1e3a8a;
        background: none;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        line-height: 1.2;
        filter: none;
    }

    .dashboard-header h1::before {
        content: '📊';
        font-size: 1.4rem;
        -webkit-text-fill-color: initial;
        background: none;
        filter: none;
    }

    .dashboard-header .subtitle {
        color: var(--text-secondary);
        margin-top: 0.3rem;
        font-size: 0.8rem;
        font-weight: 500;
        letter-spacing: 0.2px;
    }

    .header-actions {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .btn-premium {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        background: #1e3a8a;
        padding: 0.55rem 0.9rem;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 600;
        font-size: 0.75rem;
        transition: background 0.2s ease, transform 0.15s ease;
        border: 1px solid #1e40af;
        cursor: pointer;
        box-shadow: none;
        white-space: nowrap;
    }

    body[data-theme="light"] .btn-premium,
    body[data-theme="light"] .btn-premium span,
    .btn-premium,
    .btn-premium span {
        color: #ffffff !important;
    }

    .btn-premium:hover {
        background: #1e40af;
        transform: translateY(-1px);
    }

    /* ============================================
   GLASSMORPHISM STAT CARDS - PREMIUM STYLE
   ============================================ */

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 0.6rem;
        margin-bottom: 0.85rem;
    }

    .stat-card {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 0.75rem;
        position: relative;
        overflow: hidden;
        cursor: pointer;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
    }

    .stat-card::before {
        display: none;
    }

    .stat-card:hover {
        transform: none;
        border-color: #1e3a8a;
        box-shadow: 0 2px 8px rgba(30, 58, 138, 0.1);
        background-image: none;
    }

    .stat-card:hover::before {
        display: none;
    }

    .stat-icon-wrapper {
        width: 34px;
        height: 34px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        margin-bottom: 0.4rem;
        background: #eff6ff;
        border: 1px solid #dbeafe;
        position: relative;
        overflow: hidden;
    }

    .stat-icon-wrapper::before {
        display: none;
    }

    .stat-value {
        font-size: 1.5rem;
        font-weight: 800;
        color: #1e293b;
        line-height: 1;
        margin-bottom: 0.3rem;
        letter-spacing: -0.3px;
    }

    .stat-label {
        color: #64748b;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        position: relative;
        line-height: 1.2;
    }

    /* ============================================
   PREMIUM CHART CARDS
   ============================================ */

    .charts-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    .chart-card {
        background: var(--glass-bg);
        backdrop-filter: blur(var(--glass-blur));
        -webkit-backdrop-filter: blur(var(--glass-blur));
        border: 1.5px solid transparent;
        background-image:
            linear-gradient(var(--glass-bg), var(--glass-bg)),
            linear-gradient(135deg, rgba(99, 102, 241, 0.4), rgba(139, 92, 246, 0.4));
        background-origin: border-box;
        background-clip: padding-box, border-box;
        border-radius: 14px;
        padding: 1rem;
        position: relative;
        overflow: hidden;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow:
            0 4px 20px rgba(0, 0, 0, 0.08),
            0 6px 30px rgba(99, 102, 241, 0.12),
            inset 0 1px 0 rgba(255, 255, 255, 0.2);
    }

    .chart-card::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(99, 102, 241, 0.06), transparent 60%);
        pointer-events: none;
        animation: chartGlow 6s ease-in-out infinite;
    }

    @keyframes chartGlow {

        0%,
        100% {
            opacity: 0.3;
            transform: scale(1);
        }

        50% {
            opacity: 0.6;
            transform: scale(1.1);
        }
    }

    .chart-card:hover {
        border-color: rgba(99, 102, 241, 0.5);
        box-shadow: 0 8px 28px rgba(0, 0, 0, 0.12);
        transform: translateY(-4px);
    }

    .chart-card h3 {
        font-size: 1.05rem;
        font-weight: 800;
        color: var(--text-primary);
        margin: 0 0 1rem 0;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        position: relative;
        z-index: 1;
    }

    .chart-card h3::before {
        font-size: 1.3rem;
        filter: drop-shadow(0 2px 8px rgba(99, 102, 241, 0.3));
    }

    .chart-container {
        position: relative;
        height: 280px;
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .chart-container canvas {
        max-height: 100%;
        max-width: 100%;
    }

    /* ============================================
   PREMIUM REVENUE WIDGET
   ============================================ */

    .revenue-widget {
        background: var(--glass-bg);
        backdrop-filter: blur(var(--glass-blur));
        -webkit-backdrop-filter: blur(var(--glass-blur));
        border: 2px solid transparent;
        background-image:
            linear-gradient(var(--glass-bg), var(--glass-bg)),
            linear-gradient(135deg, rgba(16, 185, 129, 0.3), rgba(59, 130, 246, 0.3));
        background-origin: border-box;
        background-clip: padding-box, border-box;
        border-radius: 20px;
        padding: 1.5rem;
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
        box-shadow:
            0 8px 32px rgba(0, 0, 0, 0.1),
            0 12px 48px rgba(16, 185, 129, 0.12),
            inset 0 1px 0 rgba(255, 255, 255, 0.25);
    }

    .revenue-item {
        padding: 1rem;
        border-radius: 12px;
        background: var(--glass-bg);
        border: 1px solid var(--glass-border);
        text-align: center;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }

    .revenue-item::before {
        content: '';
        position: absolute;
        inset: 0;
        background: radial-gradient(circle at center, rgba(255, 255, 255, 0.1), transparent 70%);
        opacity: 0;
        transition: opacity 0.4s;
    }

    .revenue-item:hover::before {
        opacity: 1;
    }

    .revenue-item:hover {
        transform: translateY(-4px) scale(1.03);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
    }

    .revenue-label {
        color: var(--text-secondary);
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        margin-bottom: 0.4rem;
    }

    .revenue-value {
        font-size: 1.35rem;
        font-weight: 950;
        color: var(--text-primary);
        font-family: 'Courier New', monospace;
        margin-bottom: 0.2rem;
    }

    .revenue-actual {
        border-left: 3px solid #22c55e;
    }

    .revenue-expected {
        border-left: 3px solid #3b82f6;
    }

    /* ============================================
   PREMIUM REVENUE STATUS - LUXURY DESIGN
   ============================================ */

    .revenue-premium-container {
        background: var(--glass-bg);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 2px solid transparent;
        background-image:
            linear-gradient(var(--glass-bg), var(--glass-bg)),
            linear-gradient(135deg, rgba(99, 102, 241, 0.4), rgba(236, 72, 153, 0.4));
        background-origin: border-box;
        background-clip: padding-box, border-box;
        border-radius: 24px;
        padding: 2rem;
        margin-bottom: 1.5rem;
        position: relative;
        overflow: hidden;
        box-shadow:
            0 10px 40px rgba(0, 0, 0, 0.12),
            0 20px 60px rgba(99, 102, 241, 0.2),
            inset 0 1px 0 rgba(255, 255, 255, 0.3);
        animation: fadeInUp 0.8s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
    }

    .revenue-premium-container::before {
        content: '';
        position: absolute;
        top: -100%;
        left: -100%;
        width: 300%;
        height: 300%;
        background: radial-gradient(circle, rgba(99, 102, 241, 0.15), transparent 40%);
        animation: revenueGlow 8s ease-in-out infinite;
        pointer-events: none;
    }

    @keyframes revenueGlow {

        0%,
        100% {
            transform: translate(0, 0) scale(1);
            opacity: 0.5;
        }

        50% {
            transform: translate(10%, 10%) scale(1.2);
            opacity: 0.8;
        }
    }

    .revenue-header {
        text-align: center;
        margin-bottom: 2rem;
        position: relative;
        z-index: 1;
    }

    .revenue-title {
        font-size: 2rem;
        font-weight: 900;
        background: linear-gradient(135deg, #6366f1 0%, #ec4899 50%, #f59e0b 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin: 0 0 0.5rem 0;
        display: inline-flex;
        align-items: center;
        gap: 0.75rem;
        filter: drop-shadow(0 4px 12px rgba(99, 102, 241, 0.3));
    }

    .revenue-icon {
        font-size: 2.5rem;
        -webkit-text-fill-color: initial;
        background: none;
        display: inline-block;
        animation: iconFloat 3s ease-in-out infinite;
    }

    @keyframes iconFloat {

        0%,
        100% {
            transform: translateY(0px);
        }

        50% {
            transform: translateY(-8px);
        }
    }

    .revenue-subtitle {
        color: var(--text-secondary);
        font-size: 0.9rem;
        font-weight: 500;
        margin: 0;
        letter-spacing: 0.3px;
    }

    .revenue-cards-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1.5rem;
        position: relative;
        z-index: 1;
    }

    .revenue-card {
        background: var(--glass-bg);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border: 2px solid var(--glass-border);
        border-radius: 20px;
        padding: 1.75rem;
        position: relative;
        overflow: hidden;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow:
            0 4px 20px rgba(0, 0, 0, 0.08),
            inset 0 1px 0 rgba(255, 255, 255, 0.2);
    }

    .revenue-card::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, currentColor, transparent);
        opacity: 0.05;
        transition: all 0.6s ease;
        pointer-events: none;
    }

    .revenue-card:hover::before {
        top: 0;
        right: 0;
        opacity: 0.1;
    }

    .revenue-card:hover {
        transform: translateY(-8px) scale(1.02);
        box-shadow:
            0 16px 48px rgba(0, 0, 0, 0.15),
            0 20px 60px currentColor,
            inset 0 1px 0 rgba(255, 255, 255, 0.4);
    }

    .revenue-card-actual {
        color: #22c55e;
        border-color: rgba(34, 197, 94, 0.3);
    }

    .revenue-card-actual:hover {
        border-color: rgba(34, 197, 94, 0.6);
        box-shadow:
            0 16px 48px rgba(34, 197, 94, 0.25),
            inset 0 1px 0 rgba(255, 255, 255, 0.4);
    }

    .revenue-card-expected {
        color: #3b82f6;
        border-color: rgba(59, 130, 246, 0.3);
    }

    .revenue-card-expected:hover {
        border-color: rgba(59, 130, 246, 0.6);
        box-shadow:
            0 16px 48px rgba(59, 130, 246, 0.25),
            inset 0 1px 0 rgba(255, 255, 255, 0.4);
    }

    .revenue-card-total {
        color: #f59e0b;
        border-color: rgba(245, 158, 11, 0.3);
    }

    .revenue-card-total:hover {
        border-color: rgba(245, 158, 11, 0.6);
        box-shadow:
            0 16px 48px rgba(245, 158, 11, 0.25),
            inset 0 1px 0 rgba(255, 255, 255, 0.4);
    }

    .revenue-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .revenue-card-icon {
        width: 60px;
        height: 60px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        background: var(--glass-bg);
        border: 2px solid currentColor;
        position: relative;
        overflow: hidden;
        box-shadow: 0 4px 16px currentColor;
        transition: all 0.4s ease;
    }

    .revenue-card:hover .revenue-card-icon {
        transform: scale(1.1) rotate(5deg);
        box-shadow: 0 8px 24px currentColor;
    }

    .revenue-card-icon::before {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg, transparent, currentColor);
        opacity: 0.1;
    }

    .revenue-card-badge {
        padding: 0.4rem 0.9rem;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: rgba(34, 197, 94, 0.15);
        color: #22c55e;
        border: 1px solid rgba(34, 197, 94, 0.3);
    }

    .revenue-badge-expected {
        background: rgba(59, 130, 246, 0.15);
        color: #3b82f6;
        border-color: rgba(59, 130, 246, 0.3);
    }

    .revenue-badge-total {
        background: rgba(245, 158, 11, 0.15);
        color: #f59e0b;
        border-color: rgba(245, 158, 11, 0.3);
    }

    .revenue-card-body {
        margin-bottom: 1.5rem;
    }

    .revenue-card-label {
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--text-secondary);
        margin: 0 0 0.75rem 0;
    }

    .revenue-card-amount {
        font-size: 1.75rem;
        font-weight: 900;
        color: var(--text-primary);
        font-family: 'Courier New', monospace;
        margin: 0 0 0.5rem 0;
        letter-spacing: -0.5px;
        line-height: 1.2;
        word-break: break-all;
    }

    .revenue-card-desc {
        font-size: 0.8rem;
        color: var(--text-secondary);
        margin: 0;
        font-weight: 500;
    }

    .revenue-card-footer {
        margin-top: auto;
    }

    .revenue-progress-bar {
        width: 100%;
        height: 8px;
        background: rgba(0, 0, 0, 0.1);
        border-radius: 20px;
        overflow: hidden;
        position: relative;
    }

    [data-theme="dark"] .revenue-progress-bar {
        background: rgba(255, 255, 255, 0.1);
    }

    .revenue-progress-fill {
        height: 100%;
        border-radius: 20px;
        transition: width 1s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }

    .revenue-progress-fill::after {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
        animation: progressShine 2s infinite;
    }

    @keyframes progressShine {
        0% {
            left: -100%;
        }

        100% {
            left: 100%;
        }
    }

    .revenue-progress-actual {
        background: linear-gradient(90deg, #22c55e, #10b981);
        box-shadow: 0 2px 8px rgba(34, 197, 94, 0.4);
    }

    .revenue-progress-expected {
        background: linear-gradient(90deg, #3b82f6, #2563eb);
        box-shadow: 0 2px 8px rgba(59, 130, 246, 0.4);
    }

    .revenue-stats-mini {
        display: flex;
        gap: 1rem;
        justify-content: space-around;
    }

    .revenue-stat-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.25rem;
    }

    .stat-mini-icon {
        font-size: 1.5rem;
    }

    .stat-mini-value {
        font-size: 1rem;
        font-weight: 800;
        color: var(--text-primary);
        font-family: 'Courier New', monospace;
    }

    /* ============================================
   GUESTS TABLE - CLEAN MODERN STYLE
   ============================================ */

    .guests-card {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 1.1rem;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    .guests-card h3 {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0 0 0.75rem 0;
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }

    .guests-table {
        width: 100%;
        border-collapse: collapse;
    }

    .guests-table thead tr {
        border-bottom: 2px solid var(--glass-border);
    }

    .guests-table th {
        padding: 0.6rem 0.65rem;
        text-align: left;
        font-weight: 700;
        color: #64748b;
        font-size: 0.68rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .guests-table td {
        padding: 0.6rem 0.65rem;
        border-bottom: 1px solid #f1f5f9;
        color: #475569;
        font-size: 0.82rem;
    }

    .guests-table tbody tr {
        transition: background 0.15s ease;
    }

    .guests-table tbody tr:hover {
        background: #f8fafc;
    }

    .room-badge {
        display: inline-flex;
        align-items: center;
        color: #1e3a8a;
        font-weight: 800;
        font-size: 0.85rem;
    }

    .wa-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 22px;
        height: 22px;
        border-radius: 50%;
        background: #ffffff;
        color: #25D366 !important;
        border: 1.5px solid #d1fae5;
        font-size: 0.75rem;
        text-decoration: none;
        margin-left: 0.5rem;
        vertical-align: middle;
        transition: all 0.2s ease;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
    }

    .wa-btn:hover {
        background: #25D366;
        color: #ffffff !important;
        border-color: #25D366;
        transform: translateY(-1px);
        box-shadow: 0 3px 8px rgba(37, 211, 102, 0.35);
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.35rem 0.75rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 700;
    }

    .status-checked-in {
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
    }

    /* ============================================
   EMPTY STATE
   ============================================ */

    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        color: var(--text-secondary);
    }

    /* ============================================
   ANIMATIONS
   ============================================ */

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateX(-20px);
        }

        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    .stat-card {
        animation: fadeInUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
    }

    .chart-card,
    .guests-card {
        animation: fadeInUp 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
    }

    .stat-card:nth-child(1) {
        animation-delay: 0.1s;
    }

    .stat-card:nth-child(2) {
        animation-delay: 0.2s;
    }

    .stat-card:nth-child(3) {
        animation-delay: 0.3s;
    }

    .stat-card:nth-child(4) {
        animation-delay: 0.4s;
    }

    .stat-card:nth-child(5) {
        animation-delay: 0.5s;
    }

    .stat-card:nth-child(6) {
        animation-delay: 0.6s;
    }

    /* ============================================
   RESPONSIVE DESIGN
   ============================================ */

    @media (max-width: 1024px) {
        .dashboard-container {
            padding: 2rem 1.5rem;
        }

        .dashboard-header h1 {
            font-size: 2.5rem;
        }

        .charts-grid {
            grid-template-columns: 1fr;
        }

        /* Dashboard Grid - Stack on tablet */
        div[style*="grid-template-columns: 320px 1fr"] {
            grid-template-columns: 1fr !important;
        }

        .revenue-widget {
            grid-template-columns: 1fr;
        }

        .stat-value {
            font-size: 2.5rem;
        }

        .revenue-cards-grid {
            grid-template-columns: 1fr !important;
            gap: 0.75rem !important;
        }

        .revenue-title {
            font-size: 1.75rem;
        }

        .revenue-card-amount {
            font-size: 1.5rem;
        }
    }

    @media (max-width: 768px) {
        .dashboard-container {
            padding: 1.5rem 1rem;
        }

        .dashboard-header h1 {
            font-size: 2rem;
        }

        .dashboard-header-content {
            flex-direction: column;
            gap: 1.5rem;
        }

        .header-actions {
            width: 100%;
            justify-content: flex-start;
        }

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        .chart-container {
            height: 280px;
        }

        .stat-card {
            padding: 1.75rem;
        }

        .stat-value {
            font-size: 2rem;
        }

        .guests-table {
            font-size: 0.85rem;
        }

        .guests-table th,
        .guests-table td {
            padding: 0.85rem;
        }

        .revenue-premium-container {
            padding: 1.5rem;
        }

        .revenue-title {
            font-size: 1.5rem;
        }

        .revenue-card {
            padding: 1.25rem;
        }

        .revenue-card-icon {
            width: 50px;
            height: 50px;
            font-size: 1.75rem;
        }

        .revenue-card-amount {
            font-size: 1.35rem;
        }
    }

    @media (max-width: 480px) {
        .dashboard-container {
            padding: 1rem;
        }

        .dashboard-header h1 {
            font-size: 1.75rem;
        }

        .stats-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .stat-card {
            padding: 1rem;
        }

        .stat-icon-wrapper {
            width: 40px;
            height: 40px;
            font-size: 1.25rem;
        }

        .stat-value {
            font-size: 1.5rem;
        }

        .revenue-widget {
            padding: 1rem;
        }

        .chart-card,
        .guests-card {
            padding: 1rem;
        }

        .chart-container {
            height: 200px;
        }

        .chart-card h3,
        .guests-card h3 {
            font-size: 0.9rem;
        }

        .revenue-premium-container {
            padding: 1rem;
            border-radius: 16px;
        }

        .revenue-header {
            margin-bottom: 1.25rem;
        }

        .revenue-title {
            font-size: 1.25rem;
        }

        .revenue-icon {
            font-size: 1.75rem;
        }

        .revenue-subtitle {
            font-size: 0.75rem;
        }

        .revenue-cards-grid {
            gap: 1rem;
        }

        .revenue-card {
            padding: 1rem;
            border-radius: 16px;
        }

        .revenue-card-icon {
            width: 45px;
            height: 45px;
            font-size: 1.5rem;
        }

        .revenue-card-badge {
            padding: 0.3rem 0.7rem;
            font-size: 0.6rem;
        }

        .revenue-card-amount {
            font-size: 1.15rem;
        }

        .revenue-card-desc {
            font-size: 0.7rem;
        }

        .stat-mini-icon {
            font-size: 1.25rem;
        }

        .stat-mini-value {
            font-size: 0.85rem;
        }
    }

    /* === Front Desk Dashboard ringkas: seragam dengan dashboard utama / in-house ===
       body[data-theme] + !important karena style.css tema terang memaksa warna teks/tabel. */
    body[data-theme] .dashboard-container {
        --fdd-glass: linear-gradient(135deg, rgba(255, 255, 255, 0.84), rgba(241, 247, 255, 0.64));
        --fdd-edge: rgba(148, 163, 184, 0.3); /* tema terang: tepi abu tipis agar panel tidak menyatu dengan latar putih */
        --fdd-line: rgba(148, 163, 184, 0.22);
        --fdd-shadow: 0 10px 28px -16px rgba(15, 23, 42, 0.22);
    }

    body[data-theme="dark"] .dashboard-container {
        --fdd-glass: linear-gradient(135deg, rgba(30, 41, 59, 0.62), rgba(15, 23, 42, 0.5));
        --fdd-edge: rgba(255, 255, 255, 0.08);
        --fdd-line: rgba(148, 163, 184, 0.16);
        --fdd-shadow: 0 14px 32px -16px rgba(0, 0, 0, 0.6);
    }

    .fdd-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        flex-wrap: wrap;
        margin-bottom: 0.75rem;
    }

    body[data-theme] .fdd-title {
        margin: 0;
        font-size: 0.95rem !important;
        font-weight: 700;
        color: var(--text-heading, var(--text-primary)) !important;
    }

    body[data-theme] .fdd-sub {
        margin: 0.1rem 0 0;
        font-size: 0.64rem !important;
        color: var(--text-muted) !important;
    }

    .fdd-actions {
        display: flex;
        gap: 0.35rem;
        flex-wrap: wrap;
    }

    body[data-theme] .fdd-btn {
        height: 28px;
        padding: 0 0.75rem;
        display: inline-flex;
        align-items: center;
        border-radius: 8px;
        font-size: 0.66rem;
        font-weight: 600;
        text-decoration: none;
        color: #fff !important;
        -webkit-text-fill-color: #fff;
        background: linear-gradient(135deg, #1e3a8a, #2563eb);
        box-shadow: 0 4px 10px -4px rgba(29, 78, 216, 0.5);
    }

    body[data-theme] .fdd-btn:hover {
        filter: brightness(1.08);
    }

    body[data-theme] .fdd-btn-ghost {
        background: rgba(148, 163, 184, 0.14);
        color: var(--text-primary) !important;
        -webkit-text-fill-color: currentColor;
        box-shadow: none;
    }

    .fdd-panel {
        display: grid;
        grid-template-columns: 190px minmax(0, 1fr);
        gap: 0.6rem;
        margin-bottom: 0.75rem;
    }

    .fdd-occ,
    .fdd-strip,
    body[data-theme] .guests-card,
    body[data-theme] .checkout-section {
        background: var(--fdd-glass) !important;
        border: 1px solid var(--fdd-edge) !important;
        box-shadow: var(--fdd-shadow) !important;
        border-radius: 14px !important;
    }

    .fdd-occ {
        padding: 0.6rem 0.7rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
    }

    .fdd-occ-ring {
        position: relative;
        width: 96px;
        height: 96px;
    }

    .fdd-occ-center {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        pointer-events: none;
    }

    body[data-theme] .fdd-occ-center b {
        font-size: 0.95rem;
        font-weight: 800;
        color: #1e3a8a !important;
        line-height: 1;
    }

    body[data-theme="dark"] .fdd-occ-center b {
        color: #93c5fd !important;
    }

    body[data-theme] .fdd-occ-center small {
        font-size: 0.5rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--text-muted) !important;
    }

    .fdd-occ-legend {
        display: flex;
        gap: 0.6rem;
    }

    body[data-theme] .fdd-occ-legend span {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        font-size: 0.6rem;
        color: var(--text-secondary) !important;
    }

    .fdd-occ-legend i {
        width: 7px;
        height: 7px;
        border-radius: 2px;
    }

    .fdd-strips {
        display: flex;
        flex-direction: column;
        gap: 0.6rem;
        min-width: 0;
    }

    .fdd-strip {
        flex: 1;
        display: grid;
        grid-auto-flow: column;
        grid-auto-columns: minmax(0, 1fr);
        align-items: center;
        padding: 0.5rem 0;
    }

    .fdd-kpi {
        min-width: 0;
        padding: 0.05rem 0.9rem;
        display: flex;
        flex-direction: column;
        gap: 0.05rem;
        text-decoration: none;
    }

    .fdd-kpi + .fdd-kpi {
        border-left: 1px solid var(--fdd-line);
    }

    body[data-theme] .fdd-kpi small {
        font-size: 0.55rem;
        font-weight: 700;
        letter-spacing: 0.07em;
        text-transform: uppercase;
        color: var(--text-muted) !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    body[data-theme] .fdd-kpi b {
        font-size: 0.86rem;
        font-weight: 700;
        color: var(--text-primary) !important;
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }

    body[data-theme] .fdd-kpi em {
        font-style: normal;
        font-size: 0.55rem;
        color: var(--text-muted) !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    body[data-theme] .fdd-kpi .fdd-warn {
        color: #b91c1c !important;
    }

    body[data-theme] .fdd-kpi-link:hover b {
        color: #2563eb !important;
    }

    /* Kartu tabel (In-House, Upcoming) */
    body[data-theme] .guests-card {
        margin-top: 0.75rem !important;
        padding: 0.75rem 0.85rem !important;
    }

    body[data-theme] .guests-card h3 {
        font-size: 0.74rem !important;
        font-weight: 700 !important;
        margin: 0 0 0.5rem !important;
        padding: 0 !important;
        border: none !important;
    }

    body[data-theme] .guests-table th {
        padding: 0.42rem 0.55rem !important;
        font-size: 0.55rem !important;
        font-weight: 700 !important;
        letter-spacing: 0.07em !important;
        text-transform: uppercase;
        color: var(--text-muted) !important;
        background: transparent !important;
        border-bottom: 1px solid var(--fdd-line) !important;
    }

    body[data-theme] .guests-table td {
        padding: 0.4rem 0.55rem !important;
        font-size: 0.7rem !important;
        color: var(--text-secondary) !important;
        border-bottom: 1px solid var(--fdd-line) !important;
    }

    body[data-theme] .guests-table td strong {
        font-weight: 600;
        color: var(--text-primary) !important;
    }

    body[data-theme] .guests-table tbody tr:hover td {
        background: rgba(37, 99, 235, 0.045) !important;
    }

    body[data-theme] .room-badge {
        display: inline-flex;
        min-width: 34px;
        justify-content: center;
        padding: 0.12rem 0.4rem !important;
        border-radius: 7px !important;
        font-size: 0.66rem !important;
        font-weight: 800 !important;
        color: #fff !important;
        background: linear-gradient(135deg, #1e3a8a, #2563eb) !important;
    }

    body[data-theme] .status-badge {
        padding: 0.12rem 0.5rem !important;
        border-radius: 999px !important;
        font-size: 0.56rem !important;
        font-weight: 700 !important;
    }

    /* Check-out hari ini */
    body[data-theme] .checkout-section {
        padding: 0.75rem 0.85rem !important;
        margin-bottom: 0.75rem !important;
        border-left: 3px solid #dc2626 !important;
    }

    body[data-theme] .checkout-section h3 {
        font-size: 0.74rem !important;
        margin-bottom: 0.5rem !important;
        color: #b91c1c !important;
    }

    body[data-theme] .checkout-section table {
        font-size: 0.7rem !important;
    }

    body[data-theme] .checkout-section th {
        padding: 0.42rem 0.55rem !important;
        font-size: 0.55rem !important;
        letter-spacing: 0.07em;
        text-transform: uppercase;
        background: transparent !important;
        color: var(--text-muted) !important;
        border-bottom: 1px solid var(--fdd-line) !important;
    }

    body[data-theme] .checkout-section td {
        padding: 0.4rem 0.55rem !important;
        border-bottom: 1px solid var(--fdd-line) !important;
    }

    body[data-theme] .checkout-section td span[style*="background: #1e3a8a"] {
        color: #fff !important;
        font-size: 0.66rem !important;
        padding: 0.12rem 0.45rem !important;
    }

    body[data-theme] .checkout-section tr {
        background: transparent !important;
    }

    @media (max-width: 860px) {
        .fdd-panel {
            grid-template-columns: 1fr;
        }

        .fdd-strip {
            grid-auto-flow: row;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            row-gap: 0.5rem;
        }

        .fdd-kpi + .fdd-kpi {
            border-left: none;
        }

        .fdd-kpi:nth-child(even) {
            border-left: 1px solid var(--fdd-line);
        }
    }

</style>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<div class="dashboard-container">
    <div class="fdd-head">
        <div>
            <h1 class="fdd-title">Front Desk Dashboard</h1>
            <p class="fdd-sub"><?php echo date('l, d F Y'); ?> · Occupancy &amp; Analytics</p>
        </div>
        <div class="fdd-actions">
            <a href="reservasi.php" class="fdd-btn">Reservations</a>
            <a href="in-house.php" class="fdd-btn">In-House</a>
            <a href="calendar.php" class="fdd-btn">Calendar</a>
            <a href="settings.php" class="fdd-btn fdd-btn-ghost">Settings</a>
        </div>
    </div>

    <!-- Ringkasan: ring okupansi + strip statistik kamar + strip pendapatan -->
    <div class="fdd-panel">
        <div class="fdd-occ">
            <div class="fdd-occ-ring">
                <canvas id="occupancyChart"></canvas>
                <div class="fdd-occ-center">
                    <b><?php echo $stats['occupancy_rate']; ?>%</b>
                    <small>Occupied</small>
                </div>
            </div>
            <div class="fdd-occ-legend">
                <span><i style="background:#1e3a8a"></i>Terisi <b><?php echo (int)$stats['occupied_rooms']; ?></b></span>
                <span><i style="background:#cbd5e1"></i>Kosong <b><?php echo (int)$stats['available_rooms']; ?></b></span>
            </div>
        </div>

        <div class="fdd-strips">
            <div class="fdd-strip">
                <div class="fdd-kpi">
                    <small>Total Rooms</small>
                    <b><?php echo (int)$stats['total_rooms']; ?></b>
                </div>
                <a href="in-house.php" class="fdd-kpi fdd-kpi-link">
                    <small>In-House</small>
                    <b><?php echo (int)$stats['in_house']; ?></b>
                </a>
                <div class="fdd-kpi">
                    <small>Check-out Today</small>
                    <b class="<?php echo (int)$stats['checkout_today'] > 0 ? 'fdd-warn' : ''; ?>"><?php echo (int)$stats['checkout_today']; ?></b>
                </div>
                <div class="fdd-kpi">
                    <small>Arrival Today</small>
                    <b><?php echo (int)$stats['arrival_today']; ?></b>
                </div>
                <div class="fdd-kpi">
                    <small>Predicted Tomorrow</small>
                    <b><?php echo (int)$stats['predicted_tomorrow']; ?></b>
                </div>
            </div>

            <div class="fdd-strip">
                <div class="fdd-kpi">
                    <small>Today Revenue</small>
                    <b>Rp <?php echo number_format($stats['revenue_today'], 0, ',', '.'); ?></b>
                </div>
                <div class="fdd-kpi">
                    <small>Paid This Month</small>
                    <b>Rp <?php echo number_format($stats['month_revenue'], 0, ',', '.'); ?></b>
                    <em>Direct booking (excl. OTA)</em>
                </div>
                <div class="fdd-kpi">
                    <small>Expected Revenue</small>
                    <b>Rp <?php echo number_format($stats['expected_revenue'], 0, ',', '.'); ?></b>
                    <em>Semua reservasi bulan ini</em>
                </div>
            </div>
        </div>
    </div>

    <!-- Checkout Guests Today - Detail Section -->
    <?php if (!empty($stats['checkout_guests'])): ?>
        <div class="checkout-section" style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 0.85rem; margin-bottom: 0.85rem;">
            <h3 style="font-size: 0.85rem; margin-bottom: 0.65rem; display: flex; align-items: center; gap: 0.5rem; color: #92400e; font-weight: 700;">
                Check-out Today
                <span style="font-size: 0.62rem; background: #fef3c7; color: #92400e; padding: 0.15rem 0.55rem; border-radius: 20px; font-weight: 600;">
                    <?php echo count($stats['checkout_guests']); ?> Guests
                </span>
            </h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.8rem;">
                    <thead>
                        <tr style="background: rgba(245, 158, 11, 0.1);">
                            <th style="padding: 0.6rem 0.75rem; text-align: left; font-weight: 600; color: #92400e; border-bottom: 1px solid rgba(245, 158, 11, 0.2);">Guest</th>
                            <th style="padding: 0.6rem 0.75rem; text-align: center; font-weight: 600; color: #92400e; border-bottom: 1px solid rgba(245, 158, 11, 0.2);">Room</th>
                            <th style="padding: 0.6rem 0.75rem; text-align: center; font-weight: 600; color: #92400e; border-bottom: 1px solid rgba(245, 158, 11, 0.2);">Type</th>
                            <th style="padding: 0.6rem 0.75rem; text-align: center; font-weight: 600; color: #92400e; border-bottom: 1px solid rgba(245, 158, 11, 0.2);">Check-out</th>
                            <th style="padding: 0.6rem 0.75rem; text-align: right; font-weight: 600; color: #92400e; border-bottom: 1px solid rgba(245, 158, 11, 0.2);">Total</th>
                            <th style="padding: 0.6rem 0.75rem; text-align: right; font-weight: 600; color: #92400e; border-bottom: 1px solid rgba(245, 158, 11, 0.2);">Paid</th>
                            <th style="padding: 0.6rem 0.75rem; text-align: center; font-weight: 600; color: #92400e; border-bottom: 1px solid rgba(245, 158, 11, 0.2);">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['checkout_guests'] as $guest):
                            $remaining = (float)($guest['remaining'] ?? ($guest['final_price'] - $guest['paid_amount']));
                            $isPaid = $remaining <= 0;
                        ?>
                            <tr style="border-bottom: 1px solid rgba(245, 158, 11, 0.1);">
                                <td style="padding: 0.6rem 0.75rem;">
                                    <div style="font-weight: 600; color: var(--text-primary);"><?php echo htmlspecialchars($guest['guest_name']); ?></div>
                                    <div style="font-size: 0.7rem; color: var(--text-secondary);"><?php echo htmlspecialchars($guest['phone'] ?? '-'); ?></div>
                                </td>
                                <td style="padding: 0.6rem 0.75rem; text-align: center;">
                                    <span style="background: #1e3a8a; color: white; padding: 0.25rem 0.6rem; border-radius: 6px; font-weight: 700; font-size: 0.75rem;">
                                        <?php echo htmlspecialchars($guest['room_number']); ?>
                                    </span>
                                </td>
                                <td style="padding: 0.6rem 0.75rem; text-align: center; font-size: 0.75rem; color: var(--text-secondary);">
                                    <?php echo htmlspecialchars($guest['room_type'] ?? '-'); ?>
                                </td>
                                <td style="padding: 0.6rem 0.75rem; text-align: center; font-size: 0.75rem;">
                                    <?php echo date('d M', strtotime($guest['check_out_date'])); ?>
                                </td>
                                <td style="padding: 0.6rem 0.75rem; text-align: right; font-weight: 600;">
                                    Rp <?php echo number_format($guest['final_price'], 0, ',', '.'); ?>
                                </td>
                                <td style="padding: 0.6rem 0.75rem; text-align: right; color: #10b981; font-weight: 500;">
                                    Rp <?php echo number_format($guest['paid_amount'], 0, ',', '.'); ?>
                                </td>
                                <td style="padding: 0.6rem 0.75rem; text-align: center;">
                                    <?php if ($isPaid): ?>
                                        <span style="background: rgba(16, 185, 129, 0.1); color: #059669; padding: 0.2rem 0.5rem; border-radius: 6px; font-size: 0.7rem; font-weight: 600;">✅ PAID</span>
                                    <?php else: ?>
                                        <span style="background: rgba(239, 68, 68, 0.1); color: #dc2626; padding: 0.2rem 0.5rem; border-radius: 6px; font-size: 0.7rem; font-weight: 600;">⚠️ Rp <?php echo number_format($remaining, 0, ',', '.'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- In-House Guests List -->
    <div class="guests-card" style="margin-top: 1.5rem;">
        <h3>🛎️ In-House Guests (<?php echo $stats['in_house']; ?>)</h3>
        <?php if (!empty($stats['guests_today'])): ?>
            <div style="overflow-x: auto;">
                <table class="guests-table">
                    <thead>
                        <tr>
                            <th>Guest Name</th>
                            <th>Room</th>
                            <th>Check-in</th>
                            <th>Check-out</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['guests_today'] as $guest):
                            $waLink = dashboard_wa_link($guest['phone'] ?? '');
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($guest['guest_name']); ?></strong>
                                </td>
                                <td>
                                    <span class="room-badge">
                                        <?php echo htmlspecialchars($guest['room_number']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('d M Y', strtotime($guest['check_in_date'])); ?></td>
                                <td><?php echo date('d M Y', strtotime($guest['check_out_date'])); ?></td>
                                <td>
                                    <span class="status-badge status-checked-in">
                                        ✓ Checked In
                                    </span>
                                    <?php if ($waLink): ?>
                                        <a href="<?php echo htmlspecialchars($waLink); ?>" class="wa-btn" target="_blank" rel="noopener" title="Chat WhatsApp <?php echo htmlspecialchars($guest['guest_name']); ?>">
                                            <svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true">
                                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.29.173-1.414-.074-.124-.272-.198-.57-.347z" />
                                                <path d="M12.001 2C6.478 2 2 6.477 2 12c0 1.94.556 3.752 1.518 5.286L2.06 22l4.86-1.44A9.94 9.94 0 0012.001 22C17.523 22 22 17.523 22 12S17.523 2 12.001 2zm0 18.2c-1.72 0-3.35-.47-4.75-1.29l-.34-.2-2.88.85.86-2.81-.22-.35A8.18 8.18 0 013.8 12c0-4.53 3.68-8.2 8.2-8.2 4.52 0 8.2 3.67 8.2 8.2 0 4.52-3.68 8.2-8.199 8.2z" />
                                            </svg>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <p>🏖️ No guests currently staying today</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Upcoming Reservations -->
    <?php if (!empty($stats['upcoming_reservations'])): ?>
        <div class="guests-card" style="margin-top: 1.5rem;">
            <h3>📅 Upcoming Check-ins (<?php echo count($stats['upcoming_reservations']); ?>)</h3>
            <div style="overflow-x: auto;">
                <table class="guests-table">
                    <thead>
                        <tr>
                            <th>Guest Name</th>
                            <th>Room</th>
                            <th>Check-in</th>
                            <th>Check-out</th>
                            <th>Source</th>
                            <th>Payment</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['upcoming_reservations'] as $res): ?>
                            <?php
                            $daysUntil = (int)floor((strtotime($res['check_in_date']) - time()) / 86400);
                            $daysLabel = $daysUntil === 0 ? 'Today' : ($daysUntil === 1 ? 'Tomorrow' : 'In ' . $daysUntil . 'd');
                            $daysColor = $daysUntil === 0 ? '#ef4444' : ($daysUntil <= 2 ? '#f59e0b' : '#6366f1');
                            $isPaid = $res['payment_status'] === 'paid';
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($res['guest_name'] ?? 'Guest'); ?></strong>
                                    <?php if ($res['booking_code']): ?>
                                        <div style="font-size:0.72rem; color:#94a3b8; margin-top:2px;"><?php echo htmlspecialchars($res['booking_code']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="room-badge"><?php echo htmlspecialchars($res['room_number']); ?></span>
                                </td>
                                <td>
                                    <div><?php echo date('d M', strtotime($res['check_in_date'])); ?></div>
                                    <div style="font-size:0.75rem; font-weight:600; color:<?php echo $daysColor; ?>"><?php echo $daysLabel; ?></div>
                                </td>
                                <td><?php echo date('d M', strtotime($res['check_out_date'])); ?></td>
                                <td>
                                    <span style="font-size:0.8rem; text-transform:capitalize; color:#64748b;">
                                        <?php echo htmlspecialchars($res['booking_source'] ?? 'direct'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($isPaid): ?>
                                        <span class="status-badge status-checked-in" style="font-size:0.75rem;">✓ Paid</span>
                                    <?php else: ?>
                                        <span class="status-badge" style="background:#fef3c7; color:#92400e; font-size:0.75rem;">⏳ Unpaid</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-weight:600; color:#10b981;">
                                    Rp <?php echo number_format($res['final_price'], 0, ',', '.'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

<script>
    // Get chart color based on theme
    function getChartColor() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark' ||
            window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        return isDark ? '#e2e8f0' : '#1e293b';
    }

    // Premium Occupancy Pie Chart - Modern 2028 Design
    const occupancyCtx = document.getElementById('occupancyChart');
    if (occupancyCtx) {
        // Create modern gradients
        const ctx = occupancyCtx.getContext('2d');
        const gradient1 = ctx.createLinearGradient(0, 0, 0, 200);
        gradient1.addColorStop(0, 'rgba(16, 185, 129, 0.95)');
        gradient1.addColorStop(1, 'rgba(5, 150, 105, 0.95)');

        const gradient2 = ctx.createLinearGradient(0, 0, 0, 200);
        gradient2.addColorStop(0, 'rgba(203, 213, 225, 0.95)');
        gradient2.addColorStop(1, 'rgba(148, 163, 184, 0.95)');

        const occupancyChart = new Chart(occupancyCtx, {
            type: 'doughnut',
            data: {
                labels: ['OCCUPIED', 'VACANT'],
                datasets: [{
                    data: [
                        <?php echo $stats['occupied_rooms']; ?>,
                        <?php echo $stats['available_rooms']; ?>
                    ],
                    backgroundColor: [gradient1, gradient2],
                    borderColor: 'rgba(255, 255, 255, 0.9)',
                    borderWidth: 2,
                    hoverOffset: 8,
                    hoverBorderWidth: 3,
                    borderRadius: 6,
                    spacing: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                cutout: '70%',
                plugins: {
                    legend: {
                        display: false // Hide default legend, using custom HTML legend
                    },
                    tooltip: {
                        enabled: true,
                        backgroundColor: 'rgba(15, 23, 42, 0.96)',
                        titleColor: '#ffffff',
                        bodyColor: '#e2e8f0',
                        borderColor: 'rgba(99, 102, 241, 0.6)',
                        borderWidth: 1,
                        padding: 10,
                        displayColors: true,
                        cornerRadius: 8,
                        titleFont: {
                            size: 12,
                            weight: '600'
                        },
                        bodyFont: {
                            size: 11,
                            weight: '500'
                        },
                        callbacks: {
                            label: function(context) {
                                let total = <?php echo $stats['total_rooms']; ?>;
                                let value = context.parsed;
                                let percentage = ((value / total) * 100).toFixed(1);
                                return ' ' + percentage + '% (' + value + ' rooms)';
                            }
                        }
                    }
                },
                animation: {
                    animateRotate: true,
                    animateScale: true,
                    duration: 1000,
                    easing: 'easeOutQuart'
                }
            }
        });
    }
</script>

<?php include '../../includes/footer.php'; ?>