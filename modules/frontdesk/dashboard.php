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
require_once '../../includes/frontdesk_today.php';

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

    // 3b. Daftar tamu yang check-in hari ini (untuk kartu Arrival Today + cetak registration card)
    try {
        $stats['arrival_guests'] = $db->fetchAll("
        SELECT b.id, b.booking_code, b.status, b.booking_source, b.total_nights, b.adults, b.children,
               g.guest_name, g.phone, r.room_number, rt.type_name AS room_type,
               bs.source_name, bs.source_type
        FROM bookings b
        LEFT JOIN guests g ON g.id = b.guest_id
        LEFT JOIN rooms r ON r.id = b.room_id
        LEFT JOIN room_types rt ON rt.id = r.room_type_id
        LEFT JOIN booking_sources bs ON bs.source_key = b.booking_source
        WHERE DATE(b.check_in_date) = ? AND b.status IN ('confirmed', 'pending', 'checked_in')
        ORDER BY (b.status = 'checked_in'), r.room_number + 0, r.room_number
        ", [$today]) ?: [];
    } catch (\Throwable $e) {
        // tabel booking_sources belum ada: tanpa nama sumber
        $stats['arrival_guests'] = $db->fetchAll("
        SELECT b.id, b.booking_code, b.status, b.booking_source, b.total_nights, b.adults, b.children,
               g.guest_name, g.phone, r.room_number, rt.type_name AS room_type,
               NULL AS source_name, NULL AS source_type
        FROM bookings b
        LEFT JOIN guests g ON g.id = b.guest_id
        LEFT JOIN rooms r ON r.id = b.room_id
        LEFT JOIN room_types rt ON rt.id = r.room_type_id
        WHERE DATE(b.check_in_date) = ? AND b.status IN ('confirmed', 'pending', 'checked_in')
        ORDER BY (b.status = 'checked_in'), r.room_number + 0, r.room_number
        ", [$today]) ?: [];
    }

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
            b.status,
            b.booking_code,
            rt.type_name AS room_type
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON rt.id = r.room_type_id
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.status = 'checked_in'
        ORDER BY r.room_number ASC
        LIMIT 100
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
        WHERE b.status IN ('confirmed', 'pending')
          AND DATE(b.check_in_date) > CURDATE()
        ORDER BY b.check_in_date ASC
        LIMIT 15
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
    // Deposit yang masih dipegang FO (KTP / uang) → tampil sebagai pengingat
    $coIds = array_values(array_filter(array_map('intval', array_column($checkoutGuestsResult ?: [], 'id'))));
    if ($coIds) {
        try {
            $ph = implode(',', array_fill(0, count($coIds), '?'));
            $depRows = $db->fetchAll("SELECT booking_id, deposit_type, amount, id_type FROM booking_deposits WHERE booking_id IN ({$ph})", $coIds) ?: [];
            $depBy = [];
            foreach ($depRows as $dr) {
                $depBy[(int)$dr['booking_id']][] = $dr['deposit_type'] === 'cash' ? 'Cash Rp ' . number_format((float)$dr['amount'], 0, ',', '.') : ($dr['id_type'] ?: 'ID');
            }
            foreach ($checkoutGuestsResult as &$coRow) {
                $coRow['deposits'] = $depBy[(int)$coRow['id']] ?? [];
            }
            unset($coRow);
        } catch (\Throwable $e) {
            // tabel booking_deposits belum ada
        }
    }
    $stats['checkout_guests'] = $checkoutGuestsResult;

    // 14. Keberangkatan hari ini: total & yang sudah check-out (untuk progres)
    $depRow = $db->fetchOne("SELECT COUNT(*) total, SUM(status = 'checked_out') done FROM bookings
        WHERE DATE(check_out_date) = ? AND status IN ('checked_in','checked_out')", [$today]);
    $stats['dep_total'] = (int)($depRow['total'] ?? 0);
    $stats['dep_done'] = (int)($depRow['done'] ?? 0);
    $stats['departed_today'] = $db->fetchAll("
        SELECT b.id, b.booking_code, g.guest_name, r.room_number, rt.type_name AS room_type, b.final_price, b.actual_checkout_time
        FROM bookings b JOIN rooms r ON r.id = b.room_id LEFT JOIN room_types rt ON rt.id = r.room_type_id LEFT JOIN guests g ON g.id = b.guest_id
        WHERE DATE(b.check_out_date) = ? AND b.status = 'checked_out'
        ORDER BY b.actual_checkout_time DESC LIMIT 30", [$today]) ?: [];

    // 15–17. Reservasi masuk hari ini, kamar diblok, okupansi 7 hari: komponen bersama (juga di dashboard utama)
    $fdt = fdt_data($db);
    foreach (['new_today', 'new_today_count', 'new_today_value', 'new_today_nights', 'cancelled_today', 'blocked_rooms', 'vacant_rooms', 'forecast'] as $k) {
        $stats[$k] = $fdt[$k];
    }
} catch (\Throwable $e) {
    error_log("Dashboard Stats Error: " . $e->getMessage());
    $stats = [
        'in_house' => 0,
        'checkout_today' => 0,
        'arrival_today' => 0,
        'arrival_guests' => [],
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
        'checkout_guests' => [],
        'upcoming_reservations' => [],
        'dep_total' => 0,
        'dep_done' => 0,
        'departed_today' => [],
        'new_today' => [],
        'new_today_count' => 0,
        'new_today_value' => 0,
        'new_today_nights' => 0,
        'cancelled_today' => 0,
        'blocked_rooms' => 0,
        'vacant_rooms' => 0,
        'forecast' => []
    ];
}

// ============================================
// TAMPILAN — helper kecil
// ============================================
$fdHari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
$fdBulan = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
$fdBulanPanjang = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$fdDate = function ($d) use ($fdBulan) {
    $t = strtotime((string)$d);
    return $t ? date('j', $t) . ' ' . $fdBulan[(int)date('n', $t)] : '-';
};
$fdRp = fn($n) => 'Rp ' . number_format((float)$n, 0, ',', '.');
$fdOta = function ($sourceType, $source) {
    return $sourceType ? $sourceType !== 'direct' : (bool)preg_match('/agoda|booking|tiket|traveloka|airbnb|expedia|pegipegi|ota/i', (string)$source);
};
$fdSrc = fn($name, $key) => $name ?: ucwords(str_replace('_', ' ', (string)($key ?: 'Direct')));
$fdInitials = function ($name) {
    $w = preg_split('/\s+/', trim((string)$name)) ?: [];
    $i = strtoupper(substr($w[0] ?? '?', 0, 1) . substr($w[1] ?? '', 0, 1));
    return $i ?: '?';
};
$hour = (int)date('G');
$fdGreet = $hour < 11 ? 'Selamat pagi' : ($hour < 15 ? 'Selamat siang' : ($hour < 18 ? 'Selamat sore' : 'Selamat malam'));
$fdUser = trim((string)($currentUser['full_name'] ?? $currentUser['name'] ?? $currentUser['username'] ?? ''));
$fdUser = $fdUser !== '' ? explode(' ', $fdUser)[0] : '';

$arrTotal = count($stats['arrival_guests'] ?? []);
$arrDone = count(array_filter($stats['arrival_guests'] ?? [], fn($a) => $a['status'] === 'checked_in'));
$depTotal = (int)($stats['dep_total'] ?? 0);
$depDone = (int)($stats['dep_done'] ?? 0);
$fdPct = fn($a, $b) => $b > 0 ? (int)round($a / $b * 100) : 0;

include '../../includes/header.php';
?>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<style>
    /* Front Desk Dashboard. #fd2 + !important: style.css tema terang memaksa warna/ukuran teks.
       Skala huruf mengikuti menu Front Desk lain (judul 0.95rem, teks 0.64–0.74rem). */
    #fd2 {
        --ink: #0f172a; --mute: #64748b; --faint: #94a3b8; --line: #e8edf3; --soft: #f8fafc;
        --card: #ffffff; --brand: #1e3a8a; --accent: #2563eb;
        --ok: #16a34a; --ok-bg: #dcfce7; --warn: #b45309; --warn-bg: #fef3c7; --bad: #dc2626; --bad-bg: #fee2e2;
        --ota: #6d28d9; --ota-bg: #f5f3ff; --shadow: 0 1px 2px rgba(15,23,42,.04), 0 6px 18px -12px rgba(15,23,42,.16);
        max-width: 1600px; margin: 0 auto; padding: 1rem 1rem 1.5rem; color: var(--ink);
        font-size: 0.792rem;
    }
    body[data-theme="dark"] #fd2 {
        --ink: #f1f5f9; --mute: #94a3b8; --faint: #64748b; --line: rgba(148,163,184,.16); --soft: rgba(255,255,255,.03);
        --card: rgba(30,41,59,.72); --brand: #93c5fd; --accent: #60a5fa;
        --ok: #4ade80; --ok-bg: rgba(34,197,94,.14); --warn: #fbbf24; --warn-bg: rgba(245,158,11,.14); --bad: #f87171; --bad-bg: rgba(239,68,68,.14);
        --ota: #c4b5fd; --ota-bg: rgba(139,92,246,.14); --shadow: 0 12px 28px -16px rgba(0,0,0,.7);
    }
    #fd2 :is(span, div, td, th, p, li, a, b, small, strong, em, label, h1, h2, h3) { color: inherit !important; -webkit-text-fill-color: currentColor; }
    #fd2 *, #fd2 *::before, #fd2 *::after { box-sizing: border-box; }
    #fd2 a { text-decoration: none; }

    /* Header */
    #fd2 .fd-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
    #fd2 .fd-eyebrow { font-size: 0.638rem !important; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: var(--accent) !important; }
    #fd2 h1.fd-title { margin: 1px 0 1px; font-size: 1.045rem !important; line-height: 1.25; font-weight: 800 !important; color: var(--ink) !important; }
    #fd2 .fd-date { font-size: 0.704rem !important; color: var(--mute) !important; }
    #fd2 .fd-actions { display: flex; gap: 6px; flex-wrap: wrap; }
    #fd2 .fd-btn { display: inline-flex; align-items: center; gap: 5px; height: 31px; padding: 0 12px; border-radius: 9px; font-size: 0.726rem !important; font-weight: 700; border: 1px solid var(--line); background: var(--card); color: var(--ink) !important; transition: border-color .15s, transform .15s; }
    #fd2 .fd-btn:hover { border-color: var(--accent); transform: translateY(-1px); }
    #fd2 .fd-btn.primary { background: var(--brand); border-color: var(--brand); color: #fff !important; }
    body[data-theme="dark"] #fd2 .fd-btn.primary { background: #2563eb; border-color: #2563eb; }
    #fd2 .fd-btn svg { width: 12px; height: 12px; }

    /* Kartu */
    #fd2 .fd-card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; box-shadow: var(--shadow); }
    #fd2 .fd-card-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 12px 8px; flex-wrap: wrap; }
    #fd2 .fd-card-title { display: flex; align-items: center; gap: 8px; }
    #fd2 .fd-card-title b { display: block; font-size: 0.836rem !important; font-weight: 800 !important; color: var(--ink) !important; }
    #fd2 .fd-card-title small { display: block; font-size: 0.66rem !important; color: var(--mute) !important; margin-top: 1px; }
    #fd2 .fd-ic { width: 30px; height: 30px; border-radius: 8px; display: grid; place-items: center; flex-shrink: 0; }
    #fd2 .fd-ic svg { width: 15px; height: 15px; }
    #fd2 .ic-blue { background: #dbeafe; color: #1d4ed8 !important; }
    #fd2 .ic-green { background: var(--ok-bg); color: var(--ok) !important; }
    #fd2 .ic-amber { background: var(--warn-bg); color: var(--warn) !important; }
    #fd2 .ic-violet { background: var(--ota-bg); color: var(--ota) !important; }
    body[data-theme="dark"] #fd2 .ic-blue { background: rgba(59,130,246,.16); color: #93c5fd !important; }

    /* KPI aktivitas hari ini */
    #fd2 .fd-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 10px; }
    #fd2 .fd-kpi { padding: 12px 14px; display: flex; flex-direction: column; gap: 7px; min-width: 0; }
    #fd2 .fd-kpi-top { display: flex; align-items: center; justify-content: space-between; gap: 6px; }
    #fd2 .fd-kpi-label { font-size: 0.638rem !important; font-weight: 800; color: var(--mute) !important; text-transform: uppercase; letter-spacing: .07em; }
    #fd2 .fd-kpi-val { font-size: 1.265rem !important; font-weight: 800 !important; line-height: 1; color: var(--ink) !important; }
    #fd2 .fd-kpi-val small { font-size: 0.704rem !important; font-weight: 700; color: var(--faint) !important; }
    #fd2 .fd-kpi-sub { font-size: 0.682rem !important; color: var(--mute) !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    #fd2 .fd-kpi-sub b { color: var(--ink) !important; font-weight: 700; }
    #fd2 .fd-bar { height: 4px; border-radius: 99px; background: var(--line); overflow: hidden; }
    #fd2 .fd-bar i { display: block; height: 100%; border-radius: 99px; background: var(--accent); }
    #fd2 .fd-bar.green i { background: #22c55e; }
    #fd2 .fd-bar.amber i { background: #f59e0b; }
    #fd2 .fd-bar.violet i { background: #8b5cf6; }

    #fd2 .fd-room { display: inline-flex; align-items: center; padding: 1px 6px; border-radius: 5px; background: var(--brand); color: #fff !important; font-size: 0.66rem !important; font-weight: 800; }
    body[data-theme="dark"] #fd2 .fd-room { background: #1d4ed8; }
    #fd2 .fd-chip { display: inline-flex; align-items: center; gap: 3px; padding: 1px 7px; border-radius: 999px; font-size: 0.638rem !important; font-weight: 700; white-space: nowrap; border: 1px solid transparent; }
    #fd2 .fd-chip.ota { background: var(--ota-bg); color: var(--ota) !important; border-color: rgba(139,92,246,.25); }
    #fd2 .fd-chip.dir { background: var(--soft); color: var(--mute) !important; border-color: var(--line); }
    #fd2 .fd-chip.ok { background: var(--ok-bg); color: var(--ok) !important; }
    #fd2 .fd-chip.warn { background: var(--warn-bg); color: var(--warn) !important; }
    #fd2 .fd-chip.bad { background: var(--bad-bg); color: var(--bad) !important; }
    #fd2 .fd-chip.cb { background: #ecfeff; color: #0e7490 !important; border-color: #a5f3fc; }
    body[data-theme="dark"] #fd2 .fd-chip.cb { background: rgba(6,182,212,.12); color: #67e8f9 !important; border-color: rgba(6,182,212,.3); }
    #fd2 .fd-dot { width: 3px; height: 3px; border-radius: 50%; background: var(--faint); display: inline-block; }
    #fd2 .fd-empty { padding: 22px 12px 26px; text-align: center; color: var(--mute) !important; font-size: 0.748rem !important; }
    #fd2 .fd-empty div { font-size: 0.748rem !important; }
    #fd2 .fd-empty svg { width: 26px; height: 26px; color: var(--faint) !important; margin-bottom: 6px; }
    /* Pendapatan */
    #fd2 .fd-rev { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); margin-bottom: 10px; }
    #fd2 .fd-rev > div { padding: 10px 14px; min-width: 0; }
    #fd2 .fd-rev > div + div { border-left: 1px solid var(--line); }
    #fd2 .fd-rev-label { font-size: 0.638rem !important; font-weight: 800; color: var(--mute) !important; text-transform: uppercase; letter-spacing: .07em; }
    #fd2 .fd-rev-val { font-size: 1.012rem !important; font-weight: 800 !important; color: var(--ink) !important; margin: 4px 0 1px; font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    #fd2 .fd-rev-sub { font-size: 0.66rem !important; color: var(--faint) !important; }

    /* Tab aktivitas */
    #fd2 .fd-tabs { display: flex; gap: 3px; padding: 4px; margin: 0 10px; background: var(--soft); border: 1px solid var(--line); border-radius: 10px; overflow-x: auto; scrollbar-width: none; }
    #fd2 .fd-tab { flex: 1 0 auto; border: 0; background: transparent; padding: 5px 10px; border-radius: 7px; font-size: 0.726rem !important; font-weight: 700; color: var(--mute) !important; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; white-space: nowrap; font-family: inherit; }
    #fd2 .fd-tab .n { min-width: 18px; height: 16px; padding: 0 5px; border-radius: 99px; background: var(--line); font-size: 0.616rem !important; display: inline-grid; place-items: center; color: var(--mute) !important; }
    #fd2 .fd-tab.on { background: var(--card); color: var(--ink) !important; box-shadow: 0 1px 3px rgba(15,23,42,.12); }
    #fd2 .fd-tab.on .n { background: var(--brand); color: #fff !important; }
    body[data-theme="dark"] #fd2 .fd-tab.on { background: rgba(255,255,255,.08); }
    body[data-theme="dark"] #fd2 .fd-tab.on .n { background: #2563eb; }
    #fd2 .fd-pane { display: none; padding: 6px 2px 4px; }
    #fd2 .fd-pane.on { display: block; }
    #fd2 .fd-pane-bar { display: flex; justify-content: flex-end; padding: 2px 10px 2px; }
    #fd2 .fd-tbl-wrap { overflow-x: auto; }
    #fd2 table.fd-tbl { width: 100%; border-collapse: collapse; }
    #fd2 .fd-tbl th { padding: 7px 10px; text-align: left; font-size: 0.616rem !important; font-weight: 800 !important; letter-spacing: .07em; text-transform: uppercase; color: var(--faint) !important; border-bottom: 1px solid var(--line); white-space: nowrap; background: transparent !important; }
    #fd2 .fd-tbl td { padding: 7px 10px; border-bottom: 1px solid var(--line); vertical-align: middle; color: var(--ink) !important; font-size: 0.77rem !important; }
    #fd2 .fd-tbl td div { font-size: inherit; }
    #fd2 .fd-tbl tbody tr:last-child td { border-bottom: 0; }
    #fd2 .fd-tbl tbody tr:hover td { background: var(--soft); }
    #fd2 .fd-tbl .r { text-align: right; }
    #fd2 .fd-tbl .num { font-variant-numeric: tabular-nums; white-space: nowrap; }
    #fd2 .fd-g-name { font-weight: 700; font-size: 0.77rem !important; }
    #fd2 .fd-g-sub { font-size: 0.66rem !important; color: var(--mute) !important; margin-top: 1px; }
    #fd2 .fd-muted { color: var(--mute) !important; }
    #fd2 .fd-ico-btn { width: 29px; height: 29px; display: inline-grid; place-items: center; border-radius: 7px; border: 1px solid var(--line); background: var(--card); color: var(--accent) !important; }
    #fd2 .fd-ico-btn:hover { border-color: var(--accent); }
    #fd2 .fd-ico-btn.wa { color: #16a34a !important; }
    #fd2 .fd-ico-btn svg { width: 12px; height: 12px; }
    #fd2 .fd-acts { display: inline-flex; gap: 5px; }

    @media (max-width: 860px) {
        #fd2 .fd-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        #fd2 .fd-rev { grid-template-columns: minmax(0, 1fr); }
        #fd2 .fd-rev > div + div { border-left: 0; border-top: 1px solid var(--line); }
    }
    @media (max-width: 560px) {
        #fd2 { padding: .75rem .65rem 1.25rem; }
    }
</style>

<div id="fd2">
    <!-- Header -->
    <div class="fd-head">
        <div>
            <div class="fd-eyebrow">Front Desk</div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap"><h1 class="fd-title"><?php echo $fdGreet . ($fdUser !== '' ? ', ' . htmlspecialchars($fdUser) : ''); ?></h1><span data-cbs-slot></span></div>
            <div class="fd-date"><?php echo $fdHari[(int)date('w')] . ', ' . date('j') . ' ' . $fdBulanPanjang[(int)date('n')] . ' ' . date('Y'); ?></div>
        </div>
        <div class="fd-actions">
            <a href="reservasi.php" class="fd-btn primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                Reservasi
            </a>
            <a href="calendar.php" class="fd-btn">Kalender</a>
            <a href="in-house.php" class="fd-btn">In-House</a>
            <a href="settings.php" class="fd-btn">Pengaturan</a>
        </div>
    </div>

    <!-- Aktivitas hari ini -->
    <div class="fd-kpis">
        <div class="fd-card fd-kpi">
            <div class="fd-kpi-top">
                <span class="fd-kpi-label">Kedatangan</span>
                <span class="fd-ic ic-blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/></svg></span>
            </div>
            <div class="fd-kpi-val"><?php echo $arrDone; ?><small> / <?php echo $arrTotal; ?></small></div>
            <div class="fd-bar"><i style="width:<?php echo $fdPct($arrDone, $arrTotal); ?>%"></i></div>
            <div class="fd-kpi-sub"><?php echo $arrTotal - $arrDone > 0 ? '<b>' . ($arrTotal - $arrDone) . '</b> menunggu check-in' : ($arrTotal ? 'Semua sudah check-in' : 'Tidak ada kedatangan'); ?></div>
        </div>
        <div class="fd-card fd-kpi">
            <div class="fd-kpi-top">
                <span class="fd-kpi-label">Keberangkatan</span>
                <span class="fd-ic ic-amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg></span>
            </div>
            <div class="fd-kpi-val"><?php echo $depDone; ?><small> / <?php echo $depTotal; ?></small></div>
            <div class="fd-bar amber"><i style="width:<?php echo $fdPct($depDone, $depTotal); ?>%"></i></div>
            <div class="fd-kpi-sub"><?php echo $depTotal - $depDone > 0 ? '<b>' . ($depTotal - $depDone) . '</b> belum check-out' : ($depTotal ? 'Semua sudah check-out' : 'Tidak ada keberangkatan'); ?></div>
        </div>
        <a href="in-house.php" class="fd-card fd-kpi">
            <div class="fd-kpi-top">
                <span class="fd-kpi-label">Menginap</span>
                <span class="fd-ic ic-green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21V8l9-5 9 5v13"/><path d="M9 21v-6h6v6"/></svg></span>
            </div>
            <div class="fd-kpi-val"><?php echo (int)$stats['occupied_rooms']; ?><small> / <?php echo (int)$stats['total_rooms']; ?> kamar</small></div>
            <div class="fd-bar green"><i style="width:<?php echo min(100, (float)$stats['occupancy_rate']); ?>%"></i></div>
            <div class="fd-kpi-sub">Okupansi <b><?php echo $stats['occupancy_rate']; ?>%</b> · <?php echo (int)$stats['in_house']; ?> tamu</div>
        </a>
        <div class="fd-card fd-kpi">
            <div class="fd-kpi-top">
                <span class="fd-kpi-label">Reservasi Baru</span>
                <span class="fd-ic ic-violet"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4"/></svg></span>
            </div>
            <div class="fd-kpi-val"><?php echo (int)$stats['new_today_count']; ?><small> hari ini</small></div>
            <div class="fd-bar violet"><i style="width:<?php echo $stats['new_today_count'] ? 100 : 0; ?>%"></i></div>
            <div class="fd-kpi-sub"><b><?php echo $fdRp($stats['new_today_value']); ?></b> · <?php echo (int)$stats['new_today_nights']; ?> malam<?php echo $stats['cancelled_today'] ? ' · ' . (int)$stats['cancelled_today'] . ' batal' : ''; ?></div>
        </div>
    </div>

    <?php if (isset($fdt)) fdt_render($fdt); ?>

    <!-- Pendapatan -->
    <div class="fd-card fd-rev">
        <div>
            <div class="fd-rev-label">Pendapatan Hari Ini</div>
            <div class="fd-rev-val"><?php echo $fdRp($stats['revenue_today']); ?></div>
            <div class="fd-rev-sub">Masuk buku kas dari reservasi</div>
        </div>
        <div>
            <div class="fd-rev-label">Dibayar Bulan Ini</div>
            <div class="fd-rev-val"><?php echo $fdRp($stats['month_revenue']); ?></div>
            <div class="fd-rev-sub">Uang diterima bulan <?php echo $fdBulanPanjang[(int)date('n')]; ?></div>
        </div>
        <div>
            <div class="fd-rev-label">Proyeksi Bulan Ini</div>
            <div class="fd-rev-val"><?php echo $fdRp($stats['expected_revenue']); ?></div>
            <div class="fd-rev-sub">Semua reservasi check-in bulan ini</div>
        </div>
    </div>

    <!-- Aktivitas: kedatangan / keberangkatan / menginap / akan datang -->
    <?php
    $tabDefault = $arrTotal - $arrDone > 0 ? 'arr' : ($depTotal - $depDone > 0 ? 'dep' : 'inh');
    $waIcon = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.29.173-1.414-.074-.124-.272-.198-.57-.347z"/><path d="M12.001 2C6.478 2 2 6.477 2 12c0 1.94.556 3.752 1.518 5.286L2.06 22l4.86-1.44A9.94 9.94 0 0012.001 22C17.523 22 22 17.523 22 12S17.523 2 12.001 2zm0 18.2c-1.72 0-3.35-.47-4.75-1.29l-.34-.2-2.88.85.86-2.81-.22-.35A8.18 8.18 0 013.8 12c0-4.53 3.68-8.2 8.2-8.2 4.52 0 8.2 3.67 8.2 8.2 0 4.52-3.68 8.2-8.199 8.2z"/></svg>';
    $printIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8" rx="1"/></svg>';
    ?>
    <div class="fd-card" id="fdActivity">
        <div class="fd-card-head">
            <div class="fd-card-title">
                <span class="fd-ic ic-blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg></span>
                <div>
                    <b>Aktivitas Tamu</b>
                    <small>Kedatangan, keberangkatan, tamu menginap, dan reservasi berikutnya</small>
                </div>
            </div>
        </div>
        <div class="fd-tabs" role="tablist">
            <button type="button" class="fd-tab" data-tab="arr">Kedatangan <span class="n"><?php echo $arrTotal; ?></span></button>
            <button type="button" class="fd-tab" data-tab="dep">Keberangkatan <span class="n"><?php echo $depTotal; ?></span></button>
            <button type="button" class="fd-tab" data-tab="inh">Menginap <span class="n"><?php echo count($stats['guests_today'] ?? []); ?></span></button>
            <button type="button" class="fd-tab" data-tab="up">Akan Datang <span class="n"><?php echo count($stats['upcoming_reservations'] ?? []); ?></span></button>
        </div>

        <!-- Kedatangan -->
        <div class="fd-pane" data-pane="arr">
            <?php if (!empty($stats['arrival_guests'])): ?>
                <div class="fd-pane-bar">
                    <a class="fd-btn" href="registration-card.php?arrivals=<?php echo $today; ?>&amp;autoprint=1" target="_blank"><?php echo $printIcon; ?> Cetak semua registration card</a>
                </div>
                <div class="fd-tbl-wrap">
                    <table class="fd-tbl">
                        <thead><tr><th>Tamu</th><th>Kamar</th><th>Tipe</th><th>Malam</th><th>Sumber</th><th>Status</th><th class="r"></th></tr></thead>
                        <tbody>
                            <?php foreach ($stats['arrival_guests'] as $ag):
                                $agOta = $fdOta($ag['source_type'], $ag['booking_source']);
                                $agIn = $ag['status'] === 'checked_in';
                                $agWa = dashboard_wa_link($ag['phone'] ?? '');
                            ?>
                                <tr>
                                    <td><div class="fd-g-name"><?php echo htmlspecialchars($ag['guest_name'] ?: '-'); ?></div><div class="fd-g-sub"><?php echo htmlspecialchars($ag['booking_code']); ?><?php echo $ag['phone'] ? ' · ' . htmlspecialchars($ag['phone']) : ''; ?></div></td>
                                    <td><span class="fd-room"><?php echo htmlspecialchars($ag['room_number'] ?: '-'); ?></span></td>
                                    <td class="fd-muted"><?php echo htmlspecialchars($ag['room_type'] ?: '-'); ?></td>
                                    <td class="num"><?php echo (int)$ag['total_nights']; ?></td>
                                    <td><span class="fd-chip <?php echo $agOta ? 'ota' : 'dir'; ?>"><?php echo htmlspecialchars($fdSrc($ag['source_name'], $ag['booking_source'])); ?></span></td>
                                    <td><span class="fd-chip <?php echo $agIn ? 'ok' : 'warn'; ?>"><?php echo $agIn ? 'Sudah check-in' : 'Menunggu'; ?></span></td>
                                    <td class="r"><span class="fd-acts">
                                        <?php if ($agWa): ?><a class="fd-ico-btn wa" href="<?php echo htmlspecialchars($agWa); ?>" target="_blank" rel="noopener" title="WhatsApp"><?php echo $waIcon; ?></a><?php endif; ?>
                                        <a class="fd-ico-btn" href="registration-card.php?booking_id=<?php echo (int)$ag['id']; ?>&amp;autoprint=1" target="_blank" title="Cetak registration card"><?php echo $printIcon; ?></a>
                                    </span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="fd-empty">Tidak ada kedatangan hari ini.</div>
            <?php endif; ?>
        </div>

        <!-- Keberangkatan -->
        <div class="fd-pane" data-pane="dep">
            <?php if (!empty($stats['checkout_guests']) || !empty($stats['departed_today'])): ?>
                <div class="fd-tbl-wrap">
                    <table class="fd-tbl">
                        <thead><tr><th>Tamu</th><th>Kamar</th><th>Tipe</th><th class="r">Total</th><th class="r">Dibayar</th><th>Status</th><th class="r"></th></tr></thead>
                        <tbody>
                            <?php foreach ($stats['checkout_guests'] ?? [] as $guest):
                                $remaining = (float)($guest['remaining'] ?? ($guest['final_price'] - $guest['paid_amount']));
                                $coWa = dashboard_wa_link($guest['phone'] ?? '');
                            ?>
                                <tr>
                                    <td>
                                        <div class="fd-g-name"><?php echo htmlspecialchars($guest['guest_name'] ?: '-'); ?></div>
                                        <div class="fd-g-sub"><?php echo htmlspecialchars($guest['phone'] ?? '-'); ?></div>
                                        <?php if (!empty($guest['deposits'])): ?>
                                            <span class="fd-chip warn" style="margin-top:4px" title="Kembalikan deposit saat check-out">Deposit: <?php echo htmlspecialchars(implode(' · ', $guest['deposits'])); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="fd-room"><?php echo htmlspecialchars($guest['room_number']); ?></span></td>
                                    <td class="fd-muted"><?php echo htmlspecialchars($guest['room_type'] ?? '-'); ?></td>
                                    <td class="r num"><?php echo $fdRp($guest['final_price']); ?></td>
                                    <td class="r num"><?php echo $fdRp($guest['paid_amount']); ?></td>
                                    <td><?php if ($remaining <= 0): ?><span class="fd-chip ok">Lunas · siap check-out</span><?php else: ?><span class="fd-chip bad">Sisa <?php echo $fdRp($remaining); ?></span><?php endif; ?></td>
                                    <td class="r"><?php if ($coWa): ?><a class="fd-ico-btn wa" href="<?php echo htmlspecialchars($coWa); ?>" target="_blank" rel="noopener" title="WhatsApp"><?php echo $waIcon; ?></a><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php foreach ($stats['departed_today'] ?? [] as $dg): ?>
                                <tr>
                                    <td><div class="fd-g-name fd-muted"><?php echo htmlspecialchars($dg['guest_name'] ?: '-'); ?></div><div class="fd-g-sub"><?php echo htmlspecialchars($dg['booking_code']); ?></div></td>
                                    <td><span class="fd-room" style="opacity:.55"><?php echo htmlspecialchars($dg['room_number']); ?></span></td>
                                    <td class="fd-muted"><?php echo htmlspecialchars($dg['room_type'] ?? '-'); ?></td>
                                    <td class="r num fd-muted"><?php echo $fdRp($dg['final_price']); ?></td>
                                    <td class="r num fd-muted">—</td>
                                    <td><span class="fd-chip dir">Sudah check-out<?php echo $dg['actual_checkout_time'] ? ' · ' . date('H:i', strtotime($dg['actual_checkout_time'])) : ''; ?></span></td>
                                    <td></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="fd-empty">Tidak ada keberangkatan hari ini.</div>
            <?php endif; ?>
        </div>

        <!-- Menginap -->
        <div class="fd-pane" data-pane="inh">
            <?php if (!empty($stats['guests_today'])): ?>
                <div class="fd-tbl-wrap">
                    <table class="fd-tbl">
                        <thead><tr><th>Tamu</th><th>Kamar</th><th>Check-in</th><th>Check-out</th><th>Sisa</th><th class="r"></th></tr></thead>
                        <tbody>
                            <?php foreach ($stats['guests_today'] as $guest):
                                $gWa = dashboard_wa_link($guest['phone'] ?? '');
                                $coDate = date('Y-m-d', strtotime($guest['check_out_date']));
                                $left = (int)round((strtotime($coDate) - strtotime($today)) / 86400);
                            ?>
                                <tr>
                                    <td><div class="fd-g-name"><?php echo htmlspecialchars($guest['guest_name'] ?: '-'); ?></div><div class="fd-g-sub"><?php echo htmlspecialchars(($guest['booking_code'] ?? '') . (!empty($guest['room_type']) ? ' · ' . $guest['room_type'] : '')); ?></div></td>
                                    <td><span class="fd-room"><?php echo htmlspecialchars($guest['room_number']); ?></span></td>
                                    <td class="num"><?php echo $fdDate($guest['check_in_date']); ?></td>
                                    <td class="num"><?php echo $fdDate($guest['check_out_date']); ?></td>
                                    <td>
                                        <?php if ($left < 0): ?><span class="fd-chip bad">Lewat <?php echo -$left; ?> hari</span>
                                        <?php elseif ($left === 0): ?><span class="fd-chip warn">Check-out hari ini</span>
                                        <?php else: ?><span class="fd-chip dir"><?php echo $left; ?> malam lagi</span><?php endif; ?>
                                    </td>
                                    <td class="r"><?php if ($gWa): ?><a class="fd-ico-btn wa" href="<?php echo htmlspecialchars($gWa); ?>" target="_blank" rel="noopener" title="WhatsApp <?php echo htmlspecialchars($guest['guest_name']); ?>"><?php echo $waIcon; ?></a><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="fd-empty">Belum ada tamu menginap.</div>
            <?php endif; ?>
        </div>

        <!-- Akan datang -->
        <div class="fd-pane" data-pane="up">
            <?php if (!empty($stats['upcoming_reservations'])): ?>
                <div class="fd-tbl-wrap">
                    <table class="fd-tbl">
                        <thead><tr><th>Tamu</th><th>Kamar</th><th>Check-in</th><th>Check-out</th><th>Sumber</th><th>Pembayaran</th><th class="r">Total</th></tr></thead>
                        <tbody>
                            <?php foreach ($stats['upcoming_reservations'] as $res):
                                $daysUntil = (int)round((strtotime(date('Y-m-d', strtotime($res['check_in_date']))) - strtotime($today)) / 86400);
                                $resOta = $fdOta(null, $res['booking_source']);
                            ?>
                                <tr>
                                    <td><div class="fd-g-name"><?php echo htmlspecialchars($res['guest_name'] ?? 'Tamu'); ?></div><div class="fd-g-sub"><?php echo htmlspecialchars($res['booking_code'] ?? ''); ?></div></td>
                                    <td><span class="fd-room"><?php echo htmlspecialchars($res['room_number']); ?></span></td>
                                    <td class="num"><?php echo $fdDate($res['check_in_date']); ?><div class="fd-g-sub"><?php echo $daysUntil === 1 ? 'Besok' : $daysUntil . ' hari lagi'; ?></div></td>
                                    <td class="num"><?php echo $fdDate($res['check_out_date']); ?></td>
                                    <td><span class="fd-chip <?php echo $resOta ? 'ota' : 'dir'; ?>"><?php echo htmlspecialchars($fdSrc(null, $res['booking_source'])); ?></span></td>
                                    <td><?php if ($res['payment_status'] === 'paid'): ?><span class="fd-chip ok">Lunas</span><?php elseif ($res['payment_status'] === 'partial'): ?><span class="fd-chip warn">DP</span><?php else: ?><span class="fd-chip dir">Belum bayar</span><?php endif; ?></td>
                                    <td class="r num"><?php echo $fdRp($res['final_price']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="fd-empty">Belum ada reservasi berikutnya.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    (function() {
        // Tab aktivitas (pilihan terakhir diingat per perangkat)
        var tabs = document.querySelectorAll('#fdActivity .fd-tab');
        var panes = document.querySelectorAll('#fdActivity .fd-pane');
        function show(name) {
            tabs.forEach(function(t) { t.classList.toggle('on', t.getAttribute('data-tab') === name); });
            panes.forEach(function(p) { p.classList.toggle('on', p.getAttribute('data-pane') === name); });
        }
        tabs.forEach(function(t) {
            t.addEventListener('click', function() {
                var n = t.getAttribute('data-tab');
                show(n);
                try { sessionStorage.setItem('fdTab', n); } catch (e) {}
            });
        });
        var start = <?php echo json_encode($tabDefault); ?>;
        try { start = sessionStorage.getItem('fdTab') || start; } catch (e) {}
        show(start);

    })();
</script>


<?php include __DIR__ . '/cloudbeds-autosync-include.php'; ?>
<?php include '../../includes/footer.php'; ?>