<?php

/**
 * FRONT DESK - CALENDAR BOOKING VIEW
 * Interactive Calendar like CloudBeds
 * Horizontal: Dates | Vertical: Room Numbers + Guest Names
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
require_once __DIR__ . '/../../includes/BookingSourceHelper.php';
bs_ensure_schema($db->getConnection());
$currentUser = $auth->getCurrentUser();

if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

function ensureRoomBlocksTable($db)
{
    $db->query("CREATE TABLE IF NOT EXISTS room_blocks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        block_code VARCHAR(40) NULL,
        room_id INT NOT NULL,
        block_start_date DATE NOT NULL,
        block_end_date DATE NOT NULL,
        block_reason VARCHAR(50) NOT NULL DEFAULT 'maintenance',
        notes TEXT NULL,
        status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_room_dates (room_id, block_start_date, block_end_date),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

ensureRoomBlocksTable($db);

$pageTitle = 'Calendar Booking';
$isStaffView = isset($_GET['staff_view']) && $_GET['staff_view'] === '1';

// ============================================
// GET OTA FEES (For Frontend Logic) - Load from booking_sources table
// ============================================
$otaFees = [
    'direct' => 0,
    'walk_in' => 0,
    'phone' => 0,
    'online' => 0,
    'agoda' => 15,
    'booking' => 12,
    'tiket' => 10,
    'traveloka' => 15,
    'airbnb' => 3,
    'ota' => 10
];
try {
    // Read directly from booking_sources table (single source of truth)
    $feesFromDb = $db->fetchAll("SELECT source_key, source_name, source_type, fee_percent, icon FROM booking_sources WHERE is_active = 1 ORDER BY sort_order ASC");
    $bookingSources = $feesFromDb ?: [];
    if ($feesFromDb) {
        foreach ($feesFromDb as $fee) {
            $otaFees[$fee['source_key']] = (float)$fee['fee_percent'];
        }
    }
} catch (Exception $e) {
    $bookingSources = [];
    // Keep defaults
}

// Build dynamic OTA source keys from booking_sources table (source_type != 'direct')
$otaSourceKeys = array_values(array_map(fn($s) => $s['source_key'], array_filter($bookingSources, fn($s) => ($s['source_type'] ?? '') !== 'direct')));
// Fallback if empty
if (empty($otaSourceKeys)) {
    $otaSourceKeys = ['agoda', 'booking', 'tiket', 'traveloka', 'airbnb', 'expedia', 'pegipegi', 'ota'];
}

// Lencana kecil OTA di bar booking kalender (inisial + warna khas platform); booking langsung tanpa lencana
$calSourceNames = [];
foreach ($bookingSources as $bsRow) {
    $calSourceNames[strtolower((string)($bsRow['source_key'] ?? ''))] = [(string)($bsRow['source_name'] ?? ''), (string)($bsRow['source_type'] ?? '')];
}
function calendar_ota_badge(?string $source, array $names): string
{
    $key = strtolower(trim((string)$source));
    [$name, $type] = $names[$key] ?? ['', ''];
    $hay = strtolower($key . ' ' . $name);
    // Booking langsung (telepon, walk-in, website, direct): logo kalender centang + jam
    $isDirect = $type === 'direct' || ($type === '' && ($key === '' || preg_match('/phone|telp|walk|direct|langsung|website|whatsapp|\bwa\b|offline|call/', $hay)));
    if ($isDirect) {
        $directArt = '<rect x=".5" y=".5" width="15" height="15" rx="3.4" fill="#fff" stroke="#d4d4d8"/>'
            . '<rect x="2.6" y="4" width="8.8" height="8.6" rx="1.5" fill="none" stroke="#1e3a5f" stroke-width="1.1"/>'
            . '<path d="M2.6 6.5h8.8M5 2.9v2.2M9 2.9v2.2" fill="none" stroke="#1e3a5f" stroke-width="1.1" stroke-linecap="round"/>'
            . '<path d="M4.5 9.3l1.7 1.7 3.1-3.5" fill="none" stroke="#16a34a" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
            . '<circle cx="11.7" cy="11.7" r="2.4" fill="#fff" stroke="#f59e0b" stroke-width="1.1"/>'
            . '<path d="M11.7 10.5v1.3l.9.5" fill="none" stroke="#f59e0b" stroke-width=".8" stroke-linecap="round"/>';
        return '<i class="cal-ota-logo" title="Booking Langsung' . ($name ? ' · ' . htmlspecialchars($name) : '') . '"><svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">' . $directArt . '</svg></i>';
    }
    // Logo mini (kotak 16×16) bergaya ikon OTA di Cloudbeds
    $txt = fn($bg, $t, $fg = '#fff', $size = 10) => '<rect width="16" height="16" rx="3.5" fill="' . $bg . '"/><text x="8" y="11.6" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-weight="800" font-size="' . $size . '" fill="' . $fg . '">' . $t . '</text>';
    $logos = [
        'traveloka' => ['Traveloka', '<rect width="16" height="16" rx="3.5" fill="#1ba0e2"/><path d="M3.2 10.4c1.6-3.9 5.6-6.1 9.6-5.3-2.6.5-4.5 2.2-5.4 4.7l-1.6-1.2-2.6 1.8z" fill="#fff"/><circle cx="11.3" cy="10.6" r="1.5" fill="#fff"/>'],
        'tiket' => ['tiket.com', '<rect width="16" height="16" rx="3.5" fill="#0064d2"/><circle cx="8" cy="5.8" r="2.4" fill="#ffd200"/><rect x="3.4" y="9.7" width="9.2" height="2.3" rx="1.15" fill="#fff"/>'],
        // Agoda: kotak abu muda, huruf "a" abu besar, lima titik warna di bawahnya (logo aplikasi Agoda)
        'agoda' => ['Agoda', '<rect x=".5" y=".5" width="15" height="15" rx="3.4" fill="#f2f2f2" stroke="#d4d4d8"/><text x="8" y="10" text-anchor="middle" font-family="Verdana,Tahoma,Arial,sans-serif" font-style="normal" font-weight="700" font-size="12.5" fill="#808080">a</text><circle cx="3.3" cy="13" r="1" fill="#ff0000"/><circle cx="5.9" cy="13" r="1" fill="#f39c12"/><circle cx="8" cy="13" r="1" fill="#16a34a"/><circle cx="10.1" cy="13" r="1" fill="#8e3fd6"/><circle cx="12.7" cy="13" r="1" fill="#1e88e5"/>'],
        'booking' => ['Booking.com', '<rect width="16" height="16" rx="3.5" fill="#003580"/><text x="7" y="12" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-weight="800" font-size="11" fill="#fff">B</text><circle cx="12.4" cy="11" r="1.5" fill="#00a2ff"/>'],
        'expedia' => ['Expedia', '<rect width="16" height="16" rx="3.5" fill="#1e243a"/><path d="M4 11.8 12.2 4l-1.4 7.2-2.1-2.1-2.4 2.4z" fill="#fddb32"/>'],
        'airbnb' => ['Airbnb', $txt('#ff5a5f', 'a', '#fff', 11)],
        'pegipegi' => ['Pegipegi', $txt('#f37021', 'P')],
        'trip' => ['Trip.com', $txt('#287dfa', 'T')],
        'hotels' => ['Hotels.com', $txt('#d32f2f', 'H')],
    ];
    $svg = null;
    $label = '';
    foreach ($logos as $needle => [$lbl, $art]) {
        if (strpos($hay, $needle) !== false) { $svg = $art; $label = $lbl; break; }
    }
    // OTA lain yang belum dikenal: kotak ungu berinisial
    if ($svg === null) {
        if ($type === '' || $type === 'direct') return '';
        $abbr = strtoupper(substr(preg_replace('/[^a-z]/i', '', $name ?: $key), 0, 2)) ?: 'OT';
        $svg = $txt('#6d28d9', htmlspecialchars($abbr), '#fff', 7);
        $label = $name ?: $key;
    }
    return '<i class="cal-ota-logo" title="' . htmlspecialchars($name ?: $label) . '"><svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">' . $svg . '</svg></i>';
}

// ============================================
// GET CALENDAR DATE RANGE (Include Past Dates for History)
// ============================================
// Hanya terima tanggal valid YYYY-MM-DD: nilai ini dicetak ke HTML dan dipakai di strtotime().
$startDate = (string)($_GET['start'] ?? '');
$startDateObj = DateTime::createFromFormat('!Y-m-d', $startDate);
if (!$startDateObj || $startDateObj->format('Y-m-d') !== $startDate) {
    $startDate = date('Y-m-d');
}
$daysBefore = 60; // Show 60 days before for history/checkout bookings
$daysAfter = 365; // Show 365 days after for future bookings
$dates = [];

// Add past dates (for history view)
for ($i = $daysBefore; $i > 0; $i--) {
    $dates[] = date('Y-m-d', strtotime($startDate . " -{$i} days"));
}

// Add current and future dates
for ($i = 0; $i < $daysAfter; $i++) {
    $dates[] = date('Y-m-d', strtotime($startDate . " +{$i} days"));
}

// ============================================
// GET ALL ROOMS WITH TYPES
// ============================================
try {
    $rooms = $db->fetchAll("
        SELECT r.id, r.room_number, r.floor_number, r.status, rt.type_name, rt.base_price, rt.color_code
        FROM rooms r
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE r.status != 'maintenance'
        ORDER BY FIELD(rt.type_name, 'Queen Chambers', 'Queen', 'Twin Chambers', 'Twin', 'King Quarters', 'King', 'Deluxe Queen', 'Deluxe King'), rt.type_name ASC, r.floor_number ASC, r.room_number ASC
    ", []);
} catch (Exception $e) {
    error_log("Rooms Error: " . $e->getMessage());
    $rooms = [];
}

// ============================================
// GET BOOKINGS FOR DATE RANGE
// ============================================
try {
    // Calculate actual date range (including past dates)
    $actualStartDate = date('Y-m-d', strtotime($startDate . " -{$daysBefore} days"));
    $actualEndDate = date('Y-m-d', strtotime($startDate . " +{$daysAfter} days"));

    // Fetch all bookings that overlap with date range (including history)
    $bookings = $db->fetchAll("
        SELECT 
            b.id, 
            b.booking_code, 
            b.room_id, 
            b.check_in_date, 
            b.check_out_date,
            b.status, 
            b.room_price, 
            b.booking_source,
            b.payment_status,
            b.special_request,
            b.group_id,
            b.final_price,
            b.paid_amount,
            (SELECT COUNT(*) FROM booking_extras be WHERE be.booking_id = b.id) as extras_count,
            (SELECT COALESCE(SUM(bpx.amount), 0) FROM booking_payments bpx WHERE bpx.booking_id = b.id) as bp_paid,
            g.guest_name, 
            g.phone
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.check_in_date < ? 
        AND b.check_out_date > ?
        AND b.status IN ('pending', 'confirmed', 'checked_in', 'checked_out')
        ORDER BY b.check_in_date ASC, b.room_id ASC
    ", [$actualEndDate, $actualStartDate]);

    echo "<!-- DEBUG: Found " . count($bookings) . " bookings -->\n";
} catch (Exception $e) {
    error_log("Bookings Error: " . $e->getMessage());
    $bookings = [];
}

// Group-booking (multi-room) reservations share ONE combined balance, exactly like
// Reservasi's _combined_final_price/_combined_total_paid — a room can still carry its
// OWN payment_status = 'unpaid' in the DB even after the group's total bill is fully
// settled (payment gets applied to whichever rooms had remaining balance first). Without
// this, the calendar's red dot only reflects that one room's leftover DB flag instead of
// the group's real payment status.
$groupPaymentStatus = [];
try {
    $groupIds = array_values(array_unique(array_filter(array_column($bookings, 'group_id'))));
    if (!empty($groupIds)) {
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $groupTotals = $db->fetchAll(
            "SELECT b.group_id, SUM(b.final_price) AS total_final,
                    SUM(GREATEST(COALESCE(b.paid_amount, 0), COALESCE((SELECT SUM(bpx.amount) FROM booking_payments bpx WHERE bpx.booking_id = b.id), 0))) AS total_paid
             FROM bookings b
             WHERE b.group_id IN ({$placeholders}) AND b.status <> 'cancelled'
             GROUP BY b.group_id",
            $groupIds
        );
        foreach ($groupTotals as $gt) {
            $groupPaymentStatus[$gt['group_id']] = ((float)$gt['total_paid'] + 0.01) >= (float)$gt['total_final'];
        }
    }
} catch (Exception $e) {
    error_log("Group payment status error: " . $e->getMessage());
    $groupPaymentStatus = [];
}

// ============================================
// GET ROOM BLOCKS FOR DATE RANGE
// ============================================
$roomBlocks = [];
try {
    $roomBlocks = $db->fetchAll(" 
        SELECT rb.id, rb.block_code, rb.room_id, rb.block_start_date, rb.block_end_date,
               rb.block_reason, rb.notes, rb.status,
               r.room_number
        FROM room_blocks rb
        JOIN rooms r ON r.id = rb.room_id
        WHERE rb.status = 'active'
          AND rb.block_start_date < ?
          AND rb.block_end_date > ?
        ORDER BY rb.block_start_date ASC, rb.room_id ASC
    ", [$actualEndDate, $actualStartDate]) ?: [];
} catch (Exception $e) {
    $roomBlocks = [];
}

// ============================================
// BUILD BOOKING MATRIX
// ============================================
$bookingMatrix = [];
foreach ($bookings as $booking) {
    $roomId = $booking['room_id'];
    if (!isset($bookingMatrix[$roomId])) {
        $bookingMatrix[$roomId] = [];
    }
    $bookingMatrix[$roomId][$booking['booking_code']] = $booking;
}

$roomBlockMatrix = [];
foreach ($roomBlocks as $block) {
    $roomId = (int)$block['room_id'];
    if (!isset($roomBlockMatrix[$roomId])) {
        $roomBlockMatrix[$roomId] = [];
    }
    $roomBlockMatrix[$roomId][] = $block;
}

// ============================================
// CALCULATE AVAILABILITY PER DATE
// ============================================
$totalRoomCount = count($rooms);
$availPerDate = [];
$availPerTypeDate = []; // [typeName][date] => available count
$roomCountPerType = [];
foreach ($rooms as $room) {
    $tn = $room['type_name'];
    $roomCountPerType[$tn] = ($roomCountPerType[$tn] ?? 0) + 1;
}
foreach ($dates as $date) {
    $bookedCount = 0;
    $blockedCount = 0;
    $bookedPerType = [];
    $blockedPerType = [];
    $dt = strtotime($date);
    foreach ($bookingMatrix as $roomId => $roomBookings) {
        foreach ($roomBookings as $bk) {
            if ($bk['status'] === 'checked_out') continue;
            $ci = strtotime($bk['check_in_date']);
            $co = strtotime($bk['check_out_date']);
            if ($dt >= $ci && $dt < $co) {
                $bookedCount++;
                // Find room type
                foreach ($rooms as $rm) {
                    if ($rm['id'] == $roomId) {
                        $tn = $rm['type_name'];
                        $bookedPerType[$tn] = ($bookedPerType[$tn] ?? 0) + 1;
                        break;
                    }
                }
                break;
            }
        }
    }

    foreach ($roomBlockMatrix as $roomId => $blocks) {
        foreach ($blocks as $bl) {
            $bs = strtotime((string)$bl['block_start_date']);
            $be = strtotime((string)$bl['block_end_date']);
            if ($dt >= $bs && $dt < $be) {
                $blockedCount++;
                foreach ($rooms as $rm) {
                    if ((int)$rm['id'] === (int)$roomId) {
                        $tn = $rm['type_name'];
                        $blockedPerType[$tn] = ($blockedPerType[$tn] ?? 0) + 1;
                        break;
                    }
                }
                break;
            }
        }
    }

    $availPerDate[$date] = $totalRoomCount - $bookedCount - $blockedCount;
    foreach ($roomCountPerType as $tn => $cnt) {
        $availPerTypeDate[$tn][$date] = $cnt - ($bookedPerType[$tn] ?? 0) - ($blockedPerType[$tn] ?? 0);
    }
}

// ============================================
// BOOKING COLORS - SIMPLE: Default vs Checked-In vs Checked-Out
// ============================================
$defaultColor = ['bg' => '#2a3552', 'text' => 'white'];        // Muted navy for pending/confirmed bookings
$checkedInColor = ['bg' => '#0f8a65', 'text' => 'white'];      // Darker green for checked-in guests (active)
$checkedOutColor = ['bg' => '#9ca3af', 'text' => '#6b7280'];   // Gray transparent for checked-out (history)

include '../../includes/header.php';
?>

<style>
    /* ============================================
   CLOUDBEDS STYLE CALENDAR - SYSTEM THEME
   ============================================ */

    .calendar-container {
        width: 100%;
        padding: 0.3rem 0.1rem;
        overflow: visible;
        box-sizing: border-box;
        max-width: 100%;
        position: relative;
        z-index: 1;
    }

    /* Scroll Container - MUST BE CONSTRAINED */
    .calendar-scroll-wrapper {
        width: 100%;
        overflow-x: auto;
        overflow-y: hidden;
        position: relative;
        box-sizing: border-box;
        display: block;
        white-space: nowrap;
        cursor: grab !important;
        background: transparent;
        user-select: none;
        -webkit-user-select: none;
        padding-bottom: 5px;
        /* Space for scrollbar */
    }

    .calendar-scroll-wrapper::-webkit-scrollbar {
        height: 8px;
    }

    .calendar-scroll-wrapper::-webkit-scrollbar-thumb {
        background: rgba(99, 102, 241, 0.3);
        border-radius: 4px;
    }

    .calendar-scroll-wrapper:active {
        cursor: grabbing !important;
    }

    .calendar-scroll-wrapper.dragging {
        cursor: grabbing !important;
    }

    .calendar-header {
        display: flex;
        flex-direction: column;
        margin-bottom: 0.5rem;
        gap: 0.5rem;
    }

    .calendar-header h1 {
        font-size: 1rem;
        font-weight: 800;
        background: linear-gradient(135deg, #1e3a8a, #1d4ed8);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin: 0;
    }

    .calendar-header h1 .icon {
        -webkit-text-fill-color: #1e3a8a;
        background: none;
    }

    .calendar-controls {
        display: flex;
        gap: 0.5rem;
        align-items: center;
        flex-wrap: wrap;
    }

    .btn-nav {
        background: linear-gradient(135deg, #1e3a8a, #1d4ed8);
        color: #ffffff !important;
        border: none;
        padding: 0.4rem 0.7rem;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        font-size: 0.75rem;
        transition: all 0.3s ease;
        white-space: nowrap;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        line-height: 1.2;
        text-decoration: none;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    }

    .btn-nav:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(30, 58, 138, 0.4);
        color: #ffffff !important;
    }

    .btn-nav:visited,
    .btn-nav:active,
    .btn-nav:focus {
        color: #ffffff !important;
    }

    .date-display {
        font-size: 0.8rem;
        font-weight: 700;
        color: var(--text-primary);
    }

    .nav-date-input {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid var(--border-color);
        color: var(--text-primary);
        padding: 0.5rem 0.8rem;
        border-radius: 6px;
        font-weight: 600;
        font-size: 0.8rem;
    }

    /* Navigation Bar */
    .calendar-nav {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.4rem;
        background: var(--card-bg);
        backdrop-filter: blur(30px);
        border: 0.5px solid var(--border-color);
        border-radius: 8px;
        padding: 0.4rem;
    }

    .nav-btn {
        background: linear-gradient(135deg, #1e3a8a, #1d4ed8);
        color: #ffffff !important;
        border: 1px solid #1e40af;
        padding: 0.4rem 0.6rem;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        font-size: 0.75rem;
        transition: all 0.3s ease;
        white-space: nowrap;
    }

    #prevMonthBtn,
    #nextMonthBtn {
        width: 30px;
        height: 30px;
        padding: 0;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        font-weight: 700;
    }

    .nav-btn:hover {
        background: linear-gradient(135deg, #1e40af, #2563eb);
        border-color: #1e40af;
        color: #ffffff !important;
    }

    .today-btn {
        background: rgba(255, 255, 255, 0.15);
        color: var(--text-primary);
        border: 1.5px solid var(--border-color);
        font-weight: 700;
        font-size: 0.7rem;
        letter-spacing: 0.5px;
        padding: 0.4rem 0.8rem;
    }

    .today-btn:hover {
        background: #1e3a8a;
        color: #fff;
        border-color: #1e3a8a;
    }

    body[data-theme="light"] .today-btn {
        background: #fff;
        color: #334155;
        border: 1.5px solid #cbd5e1;
    }

    body[data-theme="light"] .today-btn:hover {
        background: #1e3a8a;
        color: #fff;
        border-color: #1e3a8a;
    }

    .nav-date-input {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: var(--text-primary);
        padding: 0.6rem 1rem;
        border-radius: 8px;
        font-weight: 600;
    }

    /* Calendar Wrapper */
    .calendar-wrapper {
        background: var(--card-bg);
        backdrop-filter: blur(30px);
        border: 0.5px solid var(--border-color);
        border-radius: 8px;
        overflow: visible !important;
        padding: 0.3rem;
        user-select: none;
        -webkit-user-select: none;
        -webkit-touch-callout: none;
        cursor: grab !important;
        width: fit-content;
        min-width: 100%;
        display: inline-block;
        box-sizing: border-box;
        position: relative;
        scroll-behavior: smooth;
        overscroll-behavior: contain;
    }

    .calendar-wrapper.dragging {
        cursor: grabbing !important;
    }

    .calendar-wrapper.dragging,
    .calendar-wrapper.dragging * {
        user-select: none !important;
    }

    body.calendar-dragging {
        user-select: none;
        cursor: grabbing !important;
    }

    /* Light Theme - Make borders more visible */
    body[data-theme="light"] .calendar-wrapper,
    body[data-theme="light"] .calendar-nav,
    body[data-theme="light"] .legend {
        border: 1px solid rgba(51, 65, 85, 0.2);
    }

    body[data-theme="light"] .calendar-wrapper,
    body[data-theme="light"] .calendar-nav,
    body[data-theme="light"] .legend {
        border: 1px solid rgba(51, 65, 85, 0.2);
    }

    body[data-theme="light"] .grid-header-date,
    body[data-theme="light"] .grid-date-cell,
    body[data-theme="light"] .grid-room-label,
    body[data-theme="light"] .grid-room-type-header,
    body[data-theme="light"] .grid-header-room {
        border-color: rgba(51, 65, 85, 0.15);
    }

    body[data-theme="light"] .grid-header-date,
    body[data-theme="light"] .grid-date-cell {
        border-right-width: 1px;
        border-bottom-width: 1px;
    }

    body[data-theme="light"] .grid-room-label,
    body[data-theme="light"] .grid-room-type-header {
        border-right-width: 1px;
        border-bottom-width: 1px;
    }

    .calendar-wrapper::-webkit-scrollbar {
        height: 12px;
    }

    .calendar-wrapper::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .calendar-wrapper::-webkit-scrollbar-thumb {
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.7), rgba(139, 92, 246, 0.7));
        border-radius: 10px;
        border: 2px solid rgba(255, 255, 255, 0.2);
    }

    .calendar-wrapper::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.9), rgba(139, 92, 246, 0.9));
    }

    .calendar-grid {
        display: grid;
        gap: 0;
        grid-template-columns: 84px repeat(<?php echo count($dates); ?>, 110px);
        width: fit-content;
        min-width: fit-content;
        max-width: none;
    }

    /* Header Row */
    .calendar-grid-header {
        display: contents;
    }

    /* Month Header Row */
    .calendar-month-header {
        display: contents;
    }

    .grid-month-room {
        background: #f8fafc;
        border-right: 2px solid #e2e8f0;
        border-bottom: 1px solid #e2e8f0;
        position: sticky;
        left: 0;
        z-index: 41;
        min-width: 84px;
        max-width: 84px;
    }

    .grid-month-label {
        background: #f8fafc;
        color: #334155;
        font-weight: 700;
        font-size: 0.85rem;
        letter-spacing: 1px;
        padding: 0.25rem 0;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        align-items: center;
        min-height: 26px;
        overflow: visible;
    }

    .grid-month-label span {
        position: sticky;
        left: 91px;
        z-index: 2;
        background: #f8fafc;
        padding: 0 0.5rem;
    }

    body[data-theme="dark"] .grid-month-room {
        background: #1e293b;
        border-color: #334155;
    }

    body[data-theme="dark"] .grid-month-label {
        background: #1e293b;
        border-color: #334155;
        color: #cbd5e1;
    }

    body[data-theme="dark"] .grid-month-label span {
        background: #1e293b;
    }

    .grid-header-room {
        background: linear-gradient(135deg, #f1f5f9 0%, #ffffff 100%);
        border-right: 2px solid #e2e8f0;
        backdrop-filter: none;
        border-bottom: 2px solid #cbd5e1;
        padding: 0.3rem 0.5rem;
        font-weight: 800;
        text-align: center;
        position: sticky;
        left: 0;
        z-index: 40;
        font-size: 0.82rem;
        color: #475569;
        box-shadow: 2px 0 6px rgba(0, 0, 0, 0.04);
        letter-spacing: 1px;
        text-transform: uppercase;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 50px;
        min-width: 84px;
        max-width: 84px;
    }

    /* Light theme - better header visibility */
    body[data-theme="light"] .grid-header-room {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        font-weight: 900;
        border-right: 2px solid #cbd5e1;
        border-bottom: 2px solid #94a3b8;
        color: #1e293b;
    }

    /* Dark theme - header room */
    body[data-theme="dark"] .grid-header-room {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        border-right: 2px solid #334155;
        border-bottom: 2px solid #475569;
        color: #e2e8f0;
        box-shadow: 2px 0 8px rgba(0, 0, 0, 0.3);
    }

    .grid-header-date {
        background: linear-gradient(180deg, #f8fafc, #f1f5f9);
        border-right: 1px solid #e2e8f0;
        border-bottom: 2px solid #cbd5e1;
        padding: 0.25rem 0.15rem;
        text-align: center;
        font-weight: 700;
        font-size: 0.75rem;
        color: #334155;
        position: relative;
        min-height: 50px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 2px;
    }

    /* Light theme - visible borders */
    body[data-theme="light"] .grid-header-date {
        border-right: 1px solid #cbd5e1;
        border-bottom: 2px solid #94a3b8;
        background: linear-gradient(180deg, #ffffff, #f8fafc);
        color: #1e293b;
    }

    /* Dark theme - header date */
    body[data-theme="dark"] .grid-header-date {
        background: linear-gradient(180deg, #1e293b, #0f172a);
        border-right: 1px solid #334155;
        border-bottom: 2px solid #475569;
        color: #e2e8f0;
    }

    /* Dark theme - date cells */
    body[data-theme="dark"] .grid-date-cell {
        border-right: 0.5px solid rgba(71, 85, 105, 0.3);
        border-bottom: 0.5px solid rgba(71, 85, 105, 0.3);
    }

    body[data-theme="dark"] .grid-date-cell:hover {
        background: rgba(99, 102, 241, 0.08);
    }

    /* TODAY HIGHLIGHT - SIMPLE & ELEGANT */
    .grid-header-date.today {
        background: rgba(99, 102, 241, 0.1) !important;
    }

    .grid-header-date.today .grid-header-date-num {
        color: #6366f1;
        font-weight: 900;
    }

    .grid-date-cell.today {
        background: rgba(99, 102, 241, 0.05) !important;
    }

    /* Light theme - more visible today highlight */
    body[data-theme="light"] .grid-header-date.today {
        background: rgba(99, 102, 241, 0.15) !important;
    }

    body[data-theme="light"] .grid-date-cell.today {
        background: rgba(99, 102, 241, 0.08) !important;
    }

    .grid-header-date-day {
        display: block;
        font-size: 0.75rem;
        text-transform: uppercase;
        font-weight: 700;
        letter-spacing: 0.3px;
        color: #334155;
        line-height: 1.1;
    }

    .grid-header-date-occ {
        display: block;
        font-size: 0.65rem;
        font-weight: 600;
        color: #64748b;
        line-height: 1;
    }

    .grid-header-date-avail {
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.68rem;
        font-weight: 800;
        color: #10b981;
        background: rgba(16, 185, 129, 0.1);
        border-radius: 50%;
        width: 20px;
        height: 20px;
        line-height: 1;
    }

    .grid-header-date-avail.full {
        color: #ef4444;
        background: rgba(239, 68, 68, 0.1);
    }

    .grid-header-date-num {
        display: inline;
        font-size: 0.95rem;
        font-weight: 900;
        line-height: 1;
        color: #1e293b;
        margin-left: 0.15rem;
    }

    .grid-header-price {
        display: none;
    }

    /* ===== Header tanggal kalender (redesign) ===== */
    body[data-theme] .calendar-grid .grid-month-room,
    body[data-theme] .calendar-grid .grid-month-label {
        background: #1e3a8a !important;
        border-color: #1e3a8a !important;
        border-bottom: 0 !important;
        min-height: 30px;
    }
    body[data-theme] .calendar-grid .grid-month-label {
        border-right: 1px solid rgba(255, 255, 255, 0.18) !important;
    }
    body[data-theme] .calendar-grid .grid-month-label span {
        background: #1e3a8a !important;
        padding: 0 12px !important;
        font-size: 0.78rem !important;
        font-weight: 800 !important;
        letter-spacing: 0.12em;
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
    }
    body[data-theme] .calendar-grid .grid-header-room,
    body[data-theme] .calendar-grid .grid-footer-room {
        background: #f1f5f9 !important;
        border-right: 1px solid #cbd5e1 !important;
        font-size: 0.72rem !important;
        font-weight: 800 !important;
        letter-spacing: 0.12em;
        color: #1e3a8a !important;
        -webkit-text-fill-color: #1e3a8a !important;
    }
    body[data-theme] .calendar-grid .cal-h {
        gap: 1px !important;
        min-height: 64px !important;
        padding: 6px 4px !important;
        background: #ffffff !important;
        border-right: 1px solid #e2e8f0 !important;
        color: #0f172a !important;
    }
    body[data-theme] .calendar-grid .grid-header-date.cal-h { border-bottom: 2px solid #cbd5e1 !important; }
    body[data-theme] .calendar-grid .grid-footer-date.cal-h { border-top: 2px solid #cbd5e1 !important; }
    body[data-theme] .calendar-grid .cal-h.weekend { background: #fff7f7 !important; }
    body[data-theme] .calendar-grid .cal-h-dow {
        font-size: 0.62rem !important;
        font-weight: 800 !important;
        letter-spacing: 0.1em;
        color: #64748b !important;
        line-height: 1.1;
    }
    body[data-theme] .calendar-grid .cal-h.weekend .cal-h-dow { color: #dc2626 !important; }
    body[data-theme] .calendar-grid .cal-h-day {
        font-size: 1.05rem !important;
        font-weight: 800 !important;
        line-height: 1.15;
        color: #0f172a !important;
        font-variant-numeric: tabular-nums;
    }
    body[data-theme] .calendar-grid .cal-h-meta {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin-top: 2px;
    }
    body[data-theme] .calendar-grid .cal-h-occ,
    body[data-theme] .calendar-grid .cal-h-avail {
        padding: 1px 6px;
        border-radius: 999px;
        font-size: 0.6rem !important;
        font-weight: 800 !important;
        line-height: 1.5;
        white-space: nowrap;
    }
    body[data-theme] .calendar-grid .cal-h-occ { background: #eef2ff; color: #3730a3 !important; }
    body[data-theme] .calendar-grid .cal-h-avail { background: #dcfce7; color: #15803d !important; }
    body[data-theme] .calendar-grid .cal-h-avail.full { background: #fee2e2; color: #b91c1c !important; }
    /* Hari ini */
    body[data-theme] .calendar-grid .cal-h.today {
        background: #eff6ff !important;
        box-shadow: inset 0 3px 0 #ffffff;
        border-top-color: #ffffff !important;
    }
    body[data-theme] .calendar-grid .cal-h.today .cal-h-dow { color: #2563eb !important; }
    body[data-theme] .calendar-grid .cal-h.today .cal-h-day {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 28px;
        height: 24px;
        padding: 0 4px;
        border-radius: 8px;
        background: #2563eb;
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
    }

    /* Baris kamar lebih ringkas (hanya nomor kamar) */
    body[data-theme] .calendar-grid .grid-room-label { min-height: 26px !important; padding-top: 0 !important; padding-bottom: 0 !important; }
    body[data-theme] .calendar-grid .grid-date-cell { min-height: 26px !important; }
    body[data-theme] .calendar-grid .grid-room-number { font-size: 0.82rem !important; }
    body[data-theme] .calendar-grid .grid-room-type-header { min-height: 30px !important; padding-top: 1px !important; padding-bottom: 1px !important; }
    body[data-theme] .calendar-grid .booking-bar-container { top: 1px !important; }

    /* Teks kalender sedikit lebih besar (angka tanggal tetap) */
    body[data-theme] .calendar-grid .grid-month-label span { font-size: 0.86rem !important; }
    body[data-theme] .calendar-grid .grid-header-room,
    body[data-theme] .calendar-grid .grid-footer-room { font-size: 0.8rem !important; }
    body[data-theme] .calendar-grid .cal-h-dow { font-size: 0.7rem !important; }
    body[data-theme] .calendar-grid .cal-h-occ,
    body[data-theme] .calendar-grid .cal-h-avail { font-size: 0.68rem !important; padding: 1px 7px; }
    body[data-theme] .calendar-grid .grid-room-number { font-size: 0.9rem !important; }
    body[data-theme] .calendar-grid .grid-room-type-header { font-size: 0.86rem !important; }
    body[data-theme] .calendar-grid .type-avail-count { font-size: 0.9rem !important; }
    body[data-theme] .calendar-grid .type-price-text { font-size: 0.8rem !important; font-weight: 700 !important; }
    /* Kolom nomor kamar lebih ramping */
    body[data-theme] .calendar-grid .grid-room-type-header { gap: 0.25rem; padding: 0 0.3rem !important; font-size: 0.8rem !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    body[data-theme] .calendar-grid .grid-header-room, body[data-theme] .calendar-grid .grid-footer-room { padding: 0 0.2rem !important; font-size: 0.72rem !important; letter-spacing: 0.08em; }
    /* Teks reservasi di balok: lebih besar, tidak pernah keluar dari balok */
    body[data-theme] .calendar-grid .booking-bar { min-width: 0; }
    /* Lencana OTA: logo mini di awal bar (seperti Cloudbeds), jauh dari dot status di kanan atas; ukuran bar tetap */
    body[data-theme] .calendar-grid .booking-bar.has-ota { padding-left: 24px !important; }
    body[data-theme] .calendar-grid .booking-bar > i.cal-ota-logo {
        position: absolute !important;
        left: 4px !important;
        top: 50% !important;
        margin-top: -8px !important;
        transform: none !important;
        width: 16px !important;
        height: 16px !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
        margin-bottom: 0 !important;
        padding: 0 !important;
        background: none !important;
        border: 0 !important;
        display: block !important;
        line-height: 0;
        font-style: normal;
        border-radius: 4px;
        overflow: hidden;
        box-shadow: 0 0 0 1.5px rgba(255, 255, 255, .95), 0 1px 3px rgba(15, 23, 42, .25);
        pointer-events: auto;
    }
    body[data-theme] .calendar-grid .booking-bar > i.cal-ota-logo svg { display: block; width: 16px !important; height: 16px !important; }
    body[data-theme] .calendar-grid .booking-bar > span {
        flex: 0 1 auto;
        min-width: 0;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 0.7rem !important;
        font-weight: 600 !important;
        letter-spacing: 0.01em;
    }
    /* Dark mode */
    body[data-theme="dark"] .calendar-grid .grid-month-room,
    body[data-theme="dark"] .calendar-grid .grid-month-label,
    body[data-theme="dark"] .calendar-grid .grid-month-label span { background: #172554 !important; border-color: #172554 !important; }
    body[data-theme="dark"] .calendar-grid .grid-header-room,
    body[data-theme="dark"] .calendar-grid .grid-footer-room {
        background: #0f172a !important;
        border-right-color: #334155 !important;
        color: #93c5fd !important;
        -webkit-text-fill-color: #93c5fd !important;
    }
    body[data-theme="dark"] .calendar-grid .cal-h {
        background: #111a2e !important;
        border-right-color: #1e293b !important;
        border-color: #1e293b !important;
    }
    body[data-theme="dark"] .calendar-grid .cal-h.weekend { background: #1a1625 !important; }
    body[data-theme="dark"] .calendar-grid .cal-h-dow { color: #94a3b8 !important; }
    body[data-theme="dark"] .calendar-grid .cal-h.weekend .cal-h-dow { color: #f87171 !important; }
    body[data-theme="dark"] .calendar-grid .cal-h-day { color: #f1f5f9 !important; }
    body[data-theme="dark"] .calendar-grid .cal-h-occ { background: rgba(99, 102, 241, 0.18); color: #c7d2fe !important; }
    body[data-theme="dark"] .calendar-grid .cal-h-avail { background: rgba(16, 185, 129, 0.15); color: #6ee7b7 !important; }
    body[data-theme="dark"] .calendar-grid .cal-h-avail.full { background: rgba(239, 68, 68, 0.18); color: #fca5a5 !important; }
    body[data-theme="dark"] .calendar-grid .cal-h.today { background: rgba(37, 99, 235, 0.16) !important; box-shadow: none; }
    body[data-theme="dark"] .calendar-grid .cal-h.today .cal-h-dow { color: #93c5fd !important; }

    /* FROZEN header & footer (like Cloudbeds): the calendar scrolls inside its own viewport-sized box, so the month/date
       header sticks to the top and the date footer to the bottom while rooms scroll; the ROOMS column stays frozen left. */
    body[data-theme] #drag-container.calendar-scroll-wrapper {
        overflow-x: auto !important;
        overflow-y: auto !important;
        max-height: var(--cal-max-h, calc(100vh - 240px));
        overscroll-behavior: contain;
    }
    body[data-theme] .calendar-grid { --cal-month-h: 28px; }
    /* Slimmer frozen header/footer rows = more room rows visible between them */
    body[data-theme] .calendar-grid .cal-h { min-height: 52px !important; padding: 3px 4px !important; }
    body[data-theme] .calendar-grid .grid-header-room,
    body[data-theme] .calendar-grid .grid-footer-room { min-height: 52px; }
    body[data-theme] .calendar-grid .grid-month-room,
    body[data-theme] .calendar-grid .grid-month-label {
        position: sticky;
        top: 0;
        height: var(--cal-month-h);
        min-height: var(--cal-month-h);
        box-sizing: border-box;
        padding-top: 0;
        padding-bottom: 0;
    }
    body[data-theme] .calendar-grid .grid-month-room { left: 0; z-index: 64; }
    body[data-theme] .calendar-grid .grid-month-label { z-index: 60; }
    body[data-theme] .calendar-grid .grid-header-room { position: sticky; top: var(--cal-month-h); left: 0; z-index: 63; }
    body[data-theme] .calendar-grid .grid-header-date { position: sticky; top: var(--cal-month-h); z-index: 58; }
    body[data-theme] .calendar-grid .grid-footer-room { position: sticky; bottom: 0; left: 0; z-index: 63; }
    body[data-theme] .calendar-grid .grid-footer-date { position: sticky; bottom: 0; z-index: 58; }

    /* Dark MATTE calendar: flat, softer surfaces — no gradients, glow or glossy shadows */
    body[data-theme="dark"] .calendar-container,
    body[data-theme="dark"] .calendar-wrapper { background: #12161d !important; box-shadow: none !important; border-color: rgba(148, 163, 184, .10) !important; }
    body[data-theme="dark"] .calendar-grid { background: #12161d !important; }
    body[data-theme="dark"] .calendar-grid .grid-month-room,
    body[data-theme="dark"] .calendar-grid .grid-month-label,
    body[data-theme="dark"] .calendar-grid .grid-month-label span { background: #1b2230 !important; border-color: rgba(148, 163, 184, .10) !important; background-image: none !important; color: #cbd5e1 !important; }
    body[data-theme="dark"] .calendar-grid .grid-header-room,
    body[data-theme="dark"] .calendar-grid .grid-footer-room,
    body[data-theme="dark"] .calendar-grid .grid-room-label,
    body[data-theme="dark"] .calendar-grid .grid-footer-date,
    body[data-theme="dark"] .calendar-grid .grid-header-date {
        background: #181d27 !important;
        background-image: none !important;
        box-shadow: none !important;
        border-color: rgba(148, 163, 184, .10) !important;
    }
    body[data-theme="dark"] .calendar-grid .grid-room-label:hover { background: #202736 !important; background-image: none !important; }
    body[data-theme="dark"] .calendar-grid .cal-h { background: #181d27 !important; border-color: rgba(148, 163, 184, .10) !important; }
    body[data-theme="dark"] .calendar-grid .cal-h.weekend { background: #1a1b24 !important; }
    body[data-theme="dark"] .calendar-grid .cal-h.today,
    body[data-theme="dark"] .calendar-grid .grid-footer-date.today { background: #1d2636 !important; }
    body[data-theme="dark"] .calendar-grid .cal-h.today .cal-h-day { background: #3b5fb0; }
    body[data-theme="dark"] .calendar-grid .grid-room-type-header,
    body[data-theme="dark"] .calendar-grid .grid-type-price-cell {
        background: #1b2130 !important;
        background-image: none !important;
        box-shadow: none !important;
        border-color: rgba(148, 163, 184, .10) !important;
    }
    body[data-theme="dark"] .calendar-grid .grid-date-cell {
        background: #14181f;
        background-image: none;
        border-right: 1px solid rgba(148, 163, 184, .07);
        border-bottom: 1px solid rgba(148, 163, 184, .07);
        box-shadow: none;
    }
    body[data-theme="dark"] .calendar-grid .grid-date-cell:hover { background: #1a202b; }
    body[data-theme="dark"] .calendar-grid .cal-h-occ { background: rgba(148, 163, 184, .12); color: #cbd5e1 !important; }
    body[data-theme="dark"] .calendar-grid .cal-h-avail { background: rgba(52, 211, 153, .10); color: #86d4b4 !important; }
    /* Booking bars: flat muted fills, no glow */
    body[data-theme="dark"] .calendar-grid .booking-bar { box-shadow: none !important; text-shadow: none !important; }
    body[data-theme="dark"] .calendar-grid .booking-bar > span { text-shadow: none !important; }
    body[data-theme="dark"] .calendar-grid .booking-bar:hover { box-shadow: none !important; filter: brightness(1.12); }
    body[data-theme="dark"] .calendar-grid .booking-confirmed,
    body[data-theme="dark"] .calendar-grid .booking-pending { background: #3e5a9e !important; background-image: none !important; border-right-color: #3e5a9e; border-left-color: #3e5a9e; }
    body[data-theme="dark"] .calendar-grid .booking-checked-in { background: #2f7a63 !important; background-image: none !important; border-right-color: #2f7a63; border-left-color: #2f7a63; }
    body[data-theme="dark"] .calendar-grid .booking-blocked { background: #3a4151 !important; background-image: none !important; border-right-color: #3a4151; border-left-color: #3a4151; }
    body[data-theme="dark"] .calendar-grid .booking-bar.booking-past { background: #2b313d !important; background-image: none !important; opacity: .6 !important; border-right-color: #2b313d !important; border-left-color: #2b313d !important; }
    body[data-theme="dark"] .calendar-grid .booking-bar.booking-past > span { color: #94a3b8 !important; }
    body[data-theme="dark"] .calendar-grid .booking-bar > i.cal-ota-logo { box-shadow: 0 0 0 1px rgba(255, 255, 255, .55) !important; }
    body[data-theme="dark"] .calendar-grid .booking-bar .status-dot { box-shadow: 0 0 0 1.5px #12161d !important; }
    /* Toolbar: flat too */
    body[data-theme="dark"] .cal-toolbar { background: #171c26 !important; box-shadow: none !important; border-color: rgba(148, 163, 184, .10) !important; }
    body[data-theme="dark"] .cal-toolbar #newReservationBtn { background: #3e5a9e !important; background-image: none !important; box-shadow: none !important; }

    /* Footer Row - Bottom Date Reference */
    .calendar-grid-footer {
        display: contents;
    }

    .grid-footer-room {
        background: linear-gradient(135deg, #f1f5f9 0%, #ffffff 100%);
        border-right: 2px solid #e2e8f0;
        border-top: 2px solid #cbd5e1;
        padding: 0.3rem 0.5rem;
        font-weight: 800;
        text-align: center;
        position: sticky;
        left: 0;
        z-index: 40;
        font-size: 0.82rem;
        color: #475569;
        letter-spacing: 1px;
        text-transform: uppercase;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 50px;
        min-width: 84px;
        max-width: 84px;
        box-shadow: 2px 0 6px rgba(0, 0, 0, 0.04);
    }

    body[data-theme="light"] .grid-footer-room {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        font-weight: 900;
        border-right: 2px solid #cbd5e1;
        border-top: 2px solid #94a3b8;
        color: #1e293b;
    }

    body[data-theme="dark"] .grid-footer-room {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        border-right: 2px solid #334155;
        border-top: 2px solid #475569;
        color: #e2e8f0;
        box-shadow: 2px 0 8px rgba(0, 0, 0, 0.3);
    }

    .grid-footer-date {
        background: linear-gradient(180deg, #f8fafc, #f1f5f9);
        border-right: 1px solid #e2e8f0;
        border-top: 2px solid #cbd5e1;
        padding: 0.25rem 0.2rem;
        text-align: center;
        font-weight: 700;
        font-size: 0.82rem;
        color: #334155;
        min-height: 50px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 2px;
    }

    body[data-theme="light"] .grid-footer-date {
        border-right: 1px solid #cbd5e1;
        border-top: 2px solid #94a3b8;
        background: linear-gradient(180deg, #ffffff, #f8fafc);
        color: #1e293b;
    }

    body[data-theme="dark"] .grid-footer-date {
        background: linear-gradient(180deg, #1e293b, #0f172a);
        border-right: 1px solid #334155;
        border-top: 2px solid #475569;
        color: #e2e8f0;
    }

    .grid-footer-date.today {
        background: rgba(99, 102, 241, 0.15) !important;
    }

    .grid-footer-date-day {
        display: block;
        font-size: 0.75rem;
        text-transform: uppercase;
        font-weight: 700;
        letter-spacing: 0.3px;
        color: #334155;
    }

    .grid-footer-date-num {
        display: block;
        font-size: 0.65rem;
        font-weight: 600;
        color: #64748b;
        line-height: 1;
    }

    /* Room Row */
    .grid-room-label {
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        border-right: 2px solid #e2e8f0;
        border-bottom: 1px solid #f1f5f9;
        padding: 0.2rem 0.4rem;
        font-weight: 700;
        color: #334155;
        position: sticky;
        left: 0;
        z-index: 30;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        text-align: center;
        gap: 0.1rem;
        min-width: 84px;
        max-width: 84px;
        cursor: grab;
        font-size: 0.85rem;
        min-height: 28px;
        box-shadow: 2px 0 6px rgba(0, 0, 0, 0.04);
        white-space: nowrap;
        transition: all 0.2s ease;
    }

    .grid-room-label:hover {
        background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
        border-right-color: #a5b4fc;
    }

    /* Light theme - better room label contrast */
    body[data-theme="light"] .grid-room-label {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        color: #1e293b;
        font-weight: 800;
        border-right: 2px solid #cbd5e1;
        border-bottom: 1px solid #e2e8f0;
    }

    body[data-theme="light"] .grid-room-label:hover {
        background: linear-gradient(135deg, #eef2ff 0%, #dbeafe 100%);
        border-right-color: #818cf8;
    }

    /* Dark theme - room label */
    body[data-theme="dark"] .grid-room-label {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        color: #f1f5f9;
        border-right: 2px solid #334155;
        border-bottom: 1px solid #1e293b;
        box-shadow: 2px 0 8px rgba(0, 0, 0, 0.3);
    }

    body[data-theme="dark"] .grid-room-label:hover {
        background: linear-gradient(135deg, #312e81 0%, #1e1b4b 100%);
        color: #e0e7ff;
        border-right-color: #6366f1;
    }

    /* DIRTY room (kamar habis check-out, perlu dibersihkan):
       sel nama kamar tetap berukuran & berwarna normal; yang diwarnai baris tanggalnya. */
    body[data-theme] .grid-date-cell.dirty-row {
        background-color: rgba(251, 191, 36, 0.13) !important;
        background-image: repeating-linear-gradient(135deg, rgba(217, 119, 6, 0.07) 0 6px, transparent 6px 12px) !important;
    }

    body[data-theme] .grid-date-cell.dirty-row.today {
        background-color: rgba(251, 191, 36, 0.2) !important;
    }

    body[data-theme="dark"] .grid-date-cell.dirty-row,
    body[data-theme="dark"] .grid-date-cell.dirty-row.today {
        background-color: rgba(251, 191, 36, 0.07) !important;
        background-image: repeating-linear-gradient(135deg, rgba(251, 191, 36, 0.05) 0 6px, transparent 6px 12px) !important;
    }

    /* Tombol sapu kecil di pojok sel kamar: tanda kotor + klik untuk tandai bersih */
    .room-clean-btn {
        position: absolute;
        top: 3px;
        right: 3px;
        width: 18px;
        height: 18px;
        padding: 0;
        display: grid;
        place-items: center;
        border: 1px solid rgba(217, 119, 6, 0.45);
        border-radius: 6px;
        background: #fef3c7;
        font-size: 0.62rem;
        line-height: 1;
        cursor: pointer;
        transition: background 0.12s, border-color 0.12s, transform 0.12s;
    }

    .room-clean-btn:hover {
        background: #dcfce7;
        border-color: #16a34a;
        transform: scale(1.08);
    }

    body[data-theme="dark"] .room-clean-btn {
        background: rgba(251, 191, 36, 0.18);
        border-color: rgba(251, 191, 36, 0.45);
    }

    .grid-room-type-label {
        font-size: 0.65rem;
        font-weight: 600;
        color: #6366f1;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        line-height: 1;
    }

    body[data-theme="dark"] .grid-room-type-label {
        color: #a5b4fc;
    }

    .grid-room-number {
        font-size: 0.88rem;
        color: #1e293b;
        font-weight: 900;
        line-height: 1;
        letter-spacing: 0.3px;
    }

    body[data-theme="dark"] .grid-room-number {
        color: #f1f5f9;
    }

    .grid-room-type-header {
        background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
        border-right: 2px solid #a5b4fc;
        border-bottom: 1px solid #c7d2fe;
        padding: 0.15rem 0.4rem;
        font-weight: 800;
        color: #4338ca;
        position: sticky;
        left: 0;
        z-index: 30;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        font-size: 0.78rem;
        gap: 0.2rem;
        min-width: 84px;
        max-width: 84px;
        min-height: 26px;
        box-shadow: 2px 0 6px rgba(0, 0, 0, 0.04);
        letter-spacing: 0.3px;
    }

    /* Light theme - better type header visibility */
    body[data-theme="light"] .grid-room-type-header {
        background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
        color: #4338ca;
        font-weight: 900;
        border-right: 2px solid #a5b4fc;
        border-bottom: 1px solid #c7d2fe;
    }

    /* Dark theme - type header */
    body[data-theme="dark"] .grid-room-type-header {
        background: linear-gradient(135deg, #312e81 0%, #1e1b4b 100%);
        color: #a5b4fc;
        border-right: 2px solid #6366f1;
        border-bottom: 1px solid #4338ca;
        box-shadow: 3px 0 8px rgba(0, 0, 0, 0.3);
    }

    /* Type Price Cell (date columns in type header row) */
    .grid-type-price-cell {
        background: linear-gradient(135deg, #eef2ff, #e0e7ff);
        border-right: 1px solid #c7d2fe;
        border-bottom: 1px solid #a5b4fc;
        min-height: 30px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0;
        font-size: 0.7rem;
        font-weight: 700;
        color: #4338ca;
        letter-spacing: 0.2px;
    }

    .type-avail-count {
        font-size: 0.78rem;
        font-weight: 800;
        color: #4338ca;
        line-height: 1.2;
    }

    .type-price-text {
        font-size: 0.62rem;
        font-weight: 600;
        color: #6366f1;
        line-height: 1.1;
        white-space: nowrap;
    }

    body[data-theme="dark"] .grid-type-price-cell {
        background: linear-gradient(135deg, #312e81, #1e1b4b);
        border-right: 1px solid #4338ca;
        border-bottom: 1px solid #3730a3;
        color: #c7d2fe;
    }

    body[data-theme="light"] .grid-type-price-cell {
        background: linear-gradient(135deg, #eef2ff, #e0e7ff);
        border-right: 1px solid #a5b4fc;
        border-bottom: 1px solid #818cf8;
        color: #3730a3;
    }

    /* Ensure booking bar text stays white in light theme */
    body[data-theme="light"] .booking-bar,
    body[data-theme="light"] .booking-bar span,
    body[data-theme="light"] .booking-bar * {
        color: #ffffff !important;
    }

    /* Maximum specificity - force white text in all scenarios */
    .booking-bar,
    .booking-bar span,
    .booking-bar>span,
    body .booking-bar,
    body .booking-bar span,
    body .booking-bar>span {
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
    }

    .grid-room-number {
        font-size: 0.85rem;
        color: var(--text-primary);
        font-weight: 900;
        line-height: 1;
        letter-spacing: 0.3px;
    }

    .grid-room-type {
        font-size: 0.72rem;
        color: var(--text-secondary);
        font-weight: 600;
        line-height: 1;
        opacity: 0.7;
    }

    .grid-room-price {
        display: none;
    }

    /* Date Cells */
    .grid-date-cell {
        border-right: 0.5px solid var(--border-color);
        border-bottom: 0.5px solid var(--border-color);
        padding: 0.05rem 0.03rem;
        min-height: 28px;
        position: relative;
        background: transparent;
        cursor: pointer;
        transition: background 0.15s ease;
    }

    /* Same-day turnover divider - HIDDEN */
    .grid-date-cell.has-turnover::before {
        display: none;
    }

    /* Light theme - visible cell borders */
    body[data-theme="light"] .grid-date-cell {
        border-right: 1px solid rgba(51, 65, 85, 0.15);
        border-bottom: 1px solid rgba(51, 65, 85, 0.15);
    }

    .grid-date-cell:last-child {
        border-right: none;
    }

    .grid-date-cell:hover {
        background: rgba(99, 102, 241, 0.05);
    }

    .grid-date-cell.click-selected {
        background: rgba(99, 102, 241, 0.25) !important;
        outline: 2px solid #6366f1;
        outline-offset: -2px;
    }

    /* Booking Bars - CLOUDBED STYLE (Noon to Noon) */
    .booking-bar-container {
        position: absolute;
        top: 2px;
        left: 1px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: flex-start;
        overflow: visible;
        pointer-events: auto;
        z-index: 10;
        margin-left: 0;
    }

    .booking-bar {
        width: 100%;
        height: 22px;
        padding: 0 0.4rem;
        cursor: pointer;
        overflow: visible;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        transition: all 0.2s ease;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12), 0 1px 2px rgba(0, 0, 0, 0.08);
        font-weight: 400;
        font-size: 0.66rem;
        line-height: 1;
        position: relative;
        pointer-events: auto;
        border-radius: 3px;
        white-space: nowrap;
        transform: skewX(-20deg);
        background: linear-gradient(135deg, #3d5a99, #5b82d1) !important;
        color: #ffffff !important;
    }

    .booking-bar>span {
        transform: skewX(20deg);
        color: #ffffff !important;
        text-shadow: 0 1px 1px rgba(0, 0, 0, 0.25);
        font-weight: 400;
        font-size: 0.62rem;
        display: block;
    }

    .booking-bar *,
    .booking-bar>* {
        color: #ffffff !important;
    }

    .booking-bar::before {
        content: '';
        position: absolute;
        left: -6px;
        top: 50%;
        transform: translateY(-50%);
        width: 0;
        height: 0;
        border-top: 8px solid transparent;
        border-bottom: 8px solid transparent;
        border-right: 5px solid;
        border-right-color: inherit;
    }

    .booking-bar::after {
        content: '';
        position: absolute;
        right: -6px;
        top: 50%;
        transform: translateY(-50%);
        width: 0;
        height: 0;
        border-top: 8px solid transparent;
        border-bottom: 8px solid transparent;
        border-left: 5px solid;
        border-left-color: inherit;
    }

    .booking-bar:hover {
        transform: skewX(-20deg) scaleY(1.15);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
        z-index: 20;
    }

    /* Past Booking Styling - Samar-samar Abu-abu Transparan */
    .booking-bar.booking-past {
        opacity: 0.4 !important;
        background: linear-gradient(135deg, #9ca3af, #d1d5db) !important;
        border-right-color: #9ca3af !important;
        border-left-color: #d1d5db !important;
    }

    .booking-bar.booking-past>span {
        color: #6b7280 !important;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1) !important;
    }

    .booking-bar.booking-past:hover {
        opacity: 0.6 !important;
        transform: skewX(-20deg) scaleY(1.1);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
    }

    /* Status specific bars */
    .booking-confirmed {
        background: linear-gradient(135deg, #3d5a99, #5b82d1) !important;
        border-right-color: #3d5a99;
        border-left-color: #5b82d1;
    }

    .booking-pending {
        background: linear-gradient(135deg, #3d5a99, #5b82d1) !important;
        border-right-color: #3d5a99;
        border-left-color: #5b82d1;
    }

    .booking-checked-in {
        background: linear-gradient(135deg, #0f8a65, #0b6b4e) !important;
        border-right-color: #0f8a65;
        border-left-color: #0b6b4e;
    }

    .booking-blocked {
        background: linear-gradient(135deg, #334155, #475569) !important;
        border-right-color: #334155;
        border-left-color: #475569;
    }

    .booking-bar-guest,
    .booking-bar-code,
    .booking-bar-status {
        color: #ffffff !important;
        text-shadow: 0 1px 1px rgba(0, 0, 0, 0.2);
        font-weight: 400;
    }

    /* Action buttons on booking bars */
    .bar-action-btn {
        position: absolute;
        right: 4px;
        top: 50%;
        transform: skewX(20deg) translateY(-50%);
        width: 18px;
        height: 18px;
        border-radius: 50%;
        border: 1.5px solid rgba(255, 255, 255, 0.7);
        background: rgba(255, 255, 255, 0.2);
        color: #fff;
        font-size: 0.65rem;
        font-weight: 900;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: all 0.2s ease;
        z-index: 15;
        line-height: 1;
        padding: 0;
    }

    .booking-bar:hover .bar-action-btn {
        opacity: 1;
    }

    /* Cloudbeds-style payment/request status dots on booking bars */
    .booking-status-dots {
        position: absolute;
        top: -7px;
        right: -5px;
        transform: skewX(20deg);
        display: flex;
        gap: 3px;
        z-index: 16;
        pointer-events: none;
    }

    .status-dot {
        display: inline-block;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        border: 2px solid #ffffff;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
    }

    .status-dot.dot-red {
        background: #ef4444;
    }

    .status-dot.dot-green {
        background: #22c55e;
    }

    .status-dot.dot-yellow {
        background: #f59e0b;
    }

    .bar-action-btn:hover {
        background: rgba(255, 255, 255, 0.5);
        transform: skewX(20deg) translateY(-50%) scale(1.15);
        border-color: #fff;
    }

    .bar-extend-btn {
        background: rgba(16, 185, 129, 0.5);
        border-color: rgba(255, 255, 255, 0.8);
    }

    .bar-edit-btn {
        background: rgba(99, 102, 241, 0.5);
        border-color: rgba(255, 255, 255, 0.8);
    }

    .bar-delete-btn {
        background: rgba(239, 68, 68, 0.75);
        border-color: rgba(255, 255, 255, 0.9);
    }

    /* Drag & Drop Styles */
    .booking-bar-container[draggable="true"] {
        cursor: grab;
    }

    .booking-bar-container[draggable="true"]:active {
        cursor: grabbing;
    }

    .booking-bar-container.dragging {
        opacity: 0.35;
        z-index: 1;
    }

    /* Selama drag: balok tidak menghalangi sel di bawahnya, sel tidak dianimasi */
    body.bar-dragging .booking-bar-container,
    body.bar-dragging .booking-bar-container *,
    .dnd-float, .dnd-float * { pointer-events: none !important; }
    body.bar-dragging .grid-date-cell { transition: none !important; }

    /* Balok melayang mengikuti mouse saat digeser */
    .booking-bar-container.dnd-float {
        position: fixed !important;
        z-index: 100000 !important;
        pointer-events: none !important;
        will-change: transform;
        opacity: .92;
        filter: drop-shadow(0 10px 18px rgba(15, 23, 42, .35));
        transition: none !important;
        cursor: grabbing;
    }
    .booking-bar-container.dnd-float .booking-bar { cursor: grabbing; transform: rotate(-1deg); }
    body.bar-dragging, body.bar-dragging * { cursor: grabbing !important; user-select: none !important; }
    /* Bayangan tujuan: posisi & panjang sama seperti balok (mulai tengah sel check-in) */
    .dnd-ghost {
        position: absolute;
        top: 2px;
        height: 24px;
        z-index: 60;
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 0 10px;
        box-sizing: border-box;
        border-radius: 7px;
        border: 2px dashed #2563eb;
        background: rgba(37, 99, 235, .14);
        pointer-events: none;
        white-space: nowrap;
        overflow: hidden;
        box-shadow: 0 6px 16px -6px rgba(37, 99, 235, .55);
    }
    .dnd-ghost-t { font-size: .72rem; font-weight: 800; color: #1e3a8a; overflow: hidden; text-overflow: ellipsis; }
    .dnd-ghost-d { margin-left: auto; font-size: .66rem; font-weight: 700; color: #1d4ed8; background: rgba(255, 255, 255, .85); padding: 1px 6px; border-radius: 5px; flex-shrink: 0; }
    .dnd-ghost.bad { border-color: #dc2626; background: rgba(220, 38, 38, .12); box-shadow: 0 6px 16px -6px rgba(220, 38, 38, .5); }
    .dnd-ghost.bad .dnd-ghost-t { color: #991b1b; }
    .dnd-ghost.bad .dnd-ghost-d { color: #dc2626; }
    .dnd-ghost.same { border-color: #94a3b8; background: rgba(148, 163, 184, .12); box-shadow: none; }
    [data-theme="dark"] .dnd-ghost-t { color: #bfdbfe; }
    [data-theme="dark"] .dnd-ghost-d { background: rgba(15, 23, 42, .8); color: #93c5fd; }

    /* Pilihan tanggal (klik 1 = check-in): pill putus-putus mulai TENGAH sel, seperti bar booking;
       memanjang mengikuti kursor sampai tengah sel check-out. */
    .fc-preview {
        position: absolute;
        top: 2px;
        left: 50%;
        height: 24px;
        z-index: 4;
        display: flex;
        align-items: center;
        padding: 0 8px;
        border: 2px dashed #6366f1;
        border-radius: 999px;
        background: rgba(99, 102, 241, 0.12);
        color: #4338ca;
        font-size: 0.6rem;
        font-weight: 700;
        white-space: nowrap;
        overflow: hidden;
        pointer-events: none;
        transition: width 0.08s ease-out;
    }

    body[data-theme="dark"] .fc-preview {
        background: rgba(129, 140, 248, 0.16);
        border-color: #818cf8;
        color: #c7d2fe;
    }

    .grid-date-cell.drag-over {
        background: rgba(99, 102, 241, 0.15) !important;
        outline: 2px dashed #6366f1;
        outline-offset: -2px;
    }

    .grid-date-cell.drag-over-valid {
        background: rgba(16, 185, 129, 0.15) !important;
        outline: 2px dashed #10b981;
    }

    .grid-date-cell.drag-over-invalid {
        background: rgba(239, 68, 68, 0.15) !important;
        outline: 2px dashed #ef4444;
    }

    /* Extend Stay Modal */
    .extend-modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 10000;
        justify-content: center;
        align-items: center;
    }

    .extend-modal-overlay.active {
        display: flex;
    }

    .extend-modal {
        background: var(--card-bg, #fff);
        border-radius: 12px;
        padding: 1.5rem;
        width: 360px;
        max-width: 90vw;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    }

    .extend-modal h3 {
        margin: 0 0 1rem;
        font-size: 1rem;
        color: var(--text-primary);
    }

    .extend-modal .form-group {
        margin-bottom: 0.75rem;
    }

    .extend-modal label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-secondary);
        margin-bottom: 0.25rem;
    }

    .extend-modal input,
    .extend-modal select {
        width: 100%;
        padding: 0.5rem 0.75rem;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        font-size: 0.85rem;
        background: var(--card-bg, #fff);
        color: var(--text-primary);
    }

    .extend-modal .modal-actions {
        display: flex;
        gap: 0.5rem;
        justify-content: flex-end;
        margin-top: 1rem;
    }

    .extend-modal .btn-cancel {
        padding: 0.45rem 1rem;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        background: transparent;
        color: var(--text-secondary);
        cursor: pointer;
        font-size: 0.8rem;
    }

    .extend-modal .btn-confirm {
        padding: 0.45rem 1rem;
        border: none;
        border-radius: 6px;
        background: #10b981;
        color: #fff;
        cursor: pointer;
        font-weight: 700;
        font-size: 0.8rem;
    }

    .extend-modal .btn-confirm:hover {
        background: #059669;
    }

    /* Edit Reservation Modal */
    .edit-res-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 10000;
        justify-content: center;
        align-items: center;
    }

    .edit-res-overlay.active {
        display: flex;
    }

    .edit-res-modal {
        background: var(--card-bg, #fff);
        border-radius: 12px;
        padding: 1.5rem;
        width: 480px;
        max-width: 95vw;
        max-height: 85vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    }

    .edit-res-modal h3 {
        margin: 0 0 1rem;
        font-size: 1rem;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }

    .edit-res-modal .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .edit-res-modal .form-group {
        margin-bottom: 0.75rem;
    }

    .edit-res-modal label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-secondary);
        margin-bottom: 0.25rem;
    }

    .edit-res-modal input,
    .edit-res-modal select,
    .edit-res-modal textarea {
        width: 100%;
        padding: 0.5rem 0.75rem;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        font-size: 0.85rem;
        background: var(--card-bg, #fff);
        color: var(--text-primary);
        box-sizing: border-box;
    }

    .edit-res-modal textarea {
        resize: vertical;
        min-height: 60px;
    }

    .edit-res-modal .modal-actions {
        display: flex;
        gap: 0.5rem;
        justify-content: flex-end;
        margin-top: 1rem;
    }

    .edit-res-modal .btn-cancel {
        padding: 0.45rem 1rem;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        background: transparent;
        color: var(--text-secondary);
        cursor: pointer;
        font-size: 0.8rem;
    }

    .edit-res-modal .btn-save {
        padding: 0.45rem 1rem;
        border: none;
        border-radius: 6px;
        background: #6366f1;
        color: #fff;
        cursor: pointer;
        font-weight: 700;
        font-size: 0.8rem;
    }

    .edit-res-modal .btn-save:hover {
        background: #4f46e5;
    }

    /* Legend */
    .legend {
        display: flex;
        flex-wrap: wrap;
        gap: 0.8rem;
        margin-top: 0.4rem;
        padding: 0.4rem 0.5rem;
        background: var(--card-bg);
        backdrop-filter: blur(30px);
        border: 0.5px solid var(--border-color);
        border-radius: 8px;
    }

    .legend-item {
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }

    .legend-color {
        width: 20px;
        height: 20px;
        border-radius: 3px;
        border: 0.5px solid var(--border-color);
    }

    .legend-label {
        font-weight: 600;
        font-size: 0.65rem;
        color: var(--text-primary);
    }

    /* Responsive */
    @media (max-width: 768px) {
        .calendar-container {
            padding: 0.3rem 0.15rem;
        }

        .calendar-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .calendar-header h1 {
            font-size: 1.2rem;
        }

        .grid-header-date {
            padding: 0.1rem;
            font-size: 0.58rem;
        }

        .grid-header-date-num {
            font-size: 0.68rem;
        }

        .grid-room-label {
            padding: 0.1rem 0.25rem;
            min-width: 90px;
            font-size: 0.7rem;
        }

        .grid-room-type-header {
            min-width: 90px;
            font-size: 0.62rem;
        }

        .grid-date-cell {
            min-height: 24px;
        }

        .booking-bar {
            height: 18px;
            font-size: 0.56rem;
        }

        .calendar-nav {
            flex-direction: column;
            gap: 0.4rem;
        }

        .form-row-3 {
            grid-template-columns: 1fr 1fr;
        }

        .form-row-3 .form-group:last-child {
            grid-column: 1 / -1;
        }
    }

    @media (max-width: 480px) {
        .calendar-wrapper {
            padding: 0.2rem;
        }

        .grid-room-label {
            padding: 0.1rem 0.2rem;
            font-size: 0.62rem;
            min-width: 70px;
        }

        .grid-room-type-header {
            min-width: 70px;
            font-size: 0.58rem;
        }

        .grid-date-cell {
            min-height: 22px;
            padding: 0.05rem;
        }

        .booking-bar {
            height: 16px;
            font-size: 0.52rem;
            padding: 0.1rem;
            color: #ffffff !important;
        }

        .form-row-3 {
            grid-template-columns: 1fr;
        }

        .form-row-3 .form-group:last-child {
            grid-column: 1;
        }

        .grid-header-date-num {
            font-size: 0.8rem;
        }

        .legend {
            flex-direction: column;
            gap: 0.5rem;
        }
    }

    /* ============================================
   MODAL POPUP STYLES
   ============================================ */
    .modal-overlay {
        display: none !important;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(5px);
        z-index: 9999;
        align-items: center;
        justify-content: center;
    }

    .modal-overlay.active {
        display: flex !important;
    }

    .modal-content {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 1.5rem;
        max-width: 500px;
        width: 90%;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
        animation: slideUp 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        z-index: 10000;
    }

    body[data-theme="light"] .modal-content {
        background: white;
        border: 1px solid rgba(51, 65, 85, 0.15);
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
    }

    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .modal-header {
        margin-bottom: 1rem;
        text-align: center;
    }

    .modal-header h2 {
        color: var(--text-primary);
        font-size: 1.25rem;
        font-weight: 700;
        margin-bottom: 0.25rem;
    }

    .modal-header p {
        color: var(--text-secondary);
        font-size: 0.8rem;
    }

    .modal-close {
        position: absolute;
        top: 0.75rem;
        right: 0.75rem;
        background: rgba(239, 68, 68, 0.15);
        border: 2px solid rgba(239, 68, 68, 0.3);
        color: #ef4444;
        width: 42px;
        height: 42px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 1.5rem;
        font-weight: 700;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
        z-index: 1000;
    }

    .modal-close:hover {
        background: rgba(239, 68, 68, 0.25);
        border-color: rgba(239, 68, 68, 0.5);
        color: #dc2626;
        transform: rotate(90deg) scale(1.1);
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    }

    .modal-actions {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .modal-btn {
        padding: 1rem;
        border: none;
        border-radius: 10px;
        font-weight: 600;
        cursor: pointer;
        font-size: 1rem;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .modal-btn-primary {
        background: linear-gradient(135deg, #10b981, #34d399);
        color: white;
    }

    .modal-btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 24px rgba(16, 185, 129, 0.3);
    }

    .modal-btn-secondary {
        background: linear-gradient(135deg, #1e3a8a, #1d4ed8);
        color: white;
    }

    .modal-btn-secondary:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 24px rgba(30, 58, 138, 0.3);
    }

    .modal-date-info {
        background: rgba(30, 58, 138, 0.12);
        border: 1px solid rgba(30, 58, 138, 0.3);
        border-radius: 10px;
        padding: 1rem;
        margin-bottom: 1rem;
        text-align: center;
        color: var(--text-secondary);
        font-size: 0.9rem;
    }

    /* RESERVATION FORM STYLES */
    .modal-content-large {
        max-width: 650px;
        height: 90vh;
        /* Fixed height for flex container */
        max-height: 90vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        /* Header/Footer static, body triggers scroll */
    }

    #reservationForm {
        display: flex;
        flex-direction: column;
        height: 100%;
        min-height: 0;
        overflow: hidden;
    }

    .modal-content-medium {
        max-width: 500px;
        max-height: 80vh;
        overflow-y: auto;
    }

    /* Booking Details Modal Styles */
    .booking-details-content {
        display: flex;
        flex-direction: column;
        gap: 1rem;
        margin: 1rem 0;
    }

    .detail-section {
        background: rgba(99, 102, 241, 0.05);
        border: 1px solid rgba(99, 102, 241, 0.15);
        border-radius: 8px;
        padding: 0.75rem;
    }

    body[data-theme="light"] .detail-section {
        background: rgba(248, 250, 252, 0.8);
        border: 1px solid rgba(51, 65, 85, 0.15);
    }

    .detail-section h3 {
        color: var(--text-primary);
        font-size: 0.8rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }

    .detail-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.4rem;
    }

    .detail-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.3rem 0;
    }

    .detail-label {
        color: var(--text-secondary);
        font-size: 0.75rem;
        font-weight: 600;
    }

    .detail-value {
        color: var(--text-primary);
        font-size: 0.8rem;
        font-weight: 700;
        text-align: right;
    }

    .status-badge {
        padding: 0.25rem 0.6rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .status-badge.status-confirmed {
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
    }

    .status-badge.status-pending {
        background: rgba(245, 158, 11, 0.2);
        color: #f59e0b;
    }

    .status-badge.status-checked_in {
        background: rgba(59, 130, 246, 0.2);
        color: #3b82f6;
    }

    .status-badge.status-paid {
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
    }

    .status-badge.status-unpaid {
        background: rgba(239, 68, 68, 0.2);
        color: #ef4444;
    }

    .status-badge.status-partial {
        background: rgba(245, 158, 11, 0.2);
        color: #f59e0b;
    }

    /* Booking Action Buttons */
    .booking-actions {
        display: flex;
        gap: 0.75rem;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid var(--border-color);
    }

    .btn-action {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.85rem 1rem;
        border: none;
        border-radius: 8px;
        font-weight: 700;
        font-size: 0.85rem;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .btn-checkin {
        background: linear-gradient(135deg, #10b981, #34d399);
        color: white;
    }

    .btn-checkin:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
    }

    .btn-checkout {
        background: linear-gradient(135deg, #3b82f6, #60a5fa);
        color: white;
    }

    .btn-checkout:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
    }

    .btn-move {
        background: linear-gradient(135deg, #f59e0b, #fbbf24);
        color: white;
    }

    .btn-move:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
    }

    .form-grid {
        display: grid;
        gap: 1rem;
    }

    .form-section {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 0.85rem;
    }

    body[data-theme="light"] .form-section {
        background: rgba(248, 250, 252, 0.5);
        border: 1px solid rgba(51, 65, 85, 0.15);
    }

    .form-section h3 {
        color: var(--text-primary);
        font-size: 0.8rem;
        font-weight: 700;
        margin-bottom: 0.65rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .form-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 0.75rem;
    }

    .form-row-3 {
        grid-template-columns: repeat(3, 1fr);
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
    }

    .form-group label {
        color: var(--text-secondary);
        font-size: 0.75rem;
        font-weight: 600;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        background: var(--sidebar-bg);
        border: 1px solid var(--border-color);
        border-radius: 6px;
        padding: 0.6rem 0.75rem;
        color: var(--text-primary);
        font-size: 0.85rem;
        transition: all 0.2s ease;
    }

    .readonly-input {
        background: rgba(99, 102, 241, 0.1) !important;
        cursor: not-allowed;
        font-weight: 600;
        color: #6366f1 !important;
    }

    body[data-theme="light"] .readonly-input {
        background: rgba(99, 102, 241, 0.08) !important;
    }

    body[data-theme="light"] .form-group input,
    body[data-theme="light"] .form-group select,
    body[data-theme="light"] .form-group textarea {
        background: white;
        border: 1px solid rgba(51, 65, 85, 0.2);
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.15);
    }

    .form-group textarea {
        resize: vertical;
        font-family: inherit;
        min-height: 70px;
    }

    .payment-method-group,
    .dp-percent-group {
        display: flex;
        gap: 0.4rem;
        flex-wrap: wrap;
    }

    .payment-method-btn,
    .dp-percent-btn {
        border: 1px solid var(--border-color);
        background: var(--sidebar-bg);
        color: var(--text-primary);
        padding: 0.35rem 0.6rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .payment-method-btn:hover,
    .dp-percent-btn:hover {
        border-color: #6366f1;
        color: #6366f1;
    }

    .payment-method-btn.active,
    .dp-percent-btn.active {
        background: #6366f1;
        color: white;
        border-color: #6366f1;
    }

    .price-summary {
        background: var(--sidebar-bg);
        border: 1px solid var(--border-color);
        border-radius: 6px;
        padding: 0.75rem;
        margin-top: 0.5rem;
    }

    body[data-theme="light"] .price-summary {
        background: rgba(16, 185, 129, 0.05);
        border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .price-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.4rem 0;
        color: var(--text-secondary);
        font-size: 0.8rem;
    }

    .price-row strong {
        color: var(--text-primary);
        font-size: 0.85rem;
        font-weight: 600;
    }

    .price-row-total {
        border-top: 1px solid var(--border-color);
        margin-top: 0.4rem;
        padding-top: 0.6rem;
        font-size: 0.9rem;
        font-weight: 700;
    }

    body[data-theme="light"] .price-row-total {
        border-top-color: rgba(16, 185, 129, 0.3);
    }

    .price-row-total strong {
        color: #10b981;
        font-size: 1rem;
    }

    .modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid var(--border-color);
    }

    .btn-primary,
    .btn-secondary {
        padding: 0.6rem 1.25rem;
        border: none;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        font-size: 0.85rem;
        transition: all 0.2s ease;
    }

    .btn-primary {
        background: linear-gradient(135deg, #10b981, #34d399);
        color: white !important;
    }

    .btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }

    .btn-secondary {
        background: var(--sidebar-bg);
        color: var(--text-primary);
        border: 1px solid var(--border-color);
    }

    body[data-theme="light"] .btn-secondary {
        background: white;
        border: 1px solid rgba(51, 65, 85, 0.2);
    }

    .btn-secondary:hover {
        background: rgba(30, 58, 138, 0.1);
        border-color: #1e3a8a;
    }

    .modal-date-info strong {
        color: var(--text-primary);
        font-size: 1.1rem;
        display: block;
        margin-top: 0.5rem;
    }

    /* Dashboard Stats Grid - Elegant Modern Design */
    .stats-dashboard-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .stats-card {
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.95), rgba(249, 250, 251, 0.9));
        border: 1px solid rgba(99, 102, 241, 0.15);
        border-radius: 10px;
        padding: 1rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        transition: all 0.3s ease;
    }

    .stats-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 16px rgba(99, 102, 241, 0.15);
    }

    body[data-theme="light"] .stats-card {
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.95), rgba(249, 250, 251, 0.9));
        border-color: rgba(99, 102, 241, 0.15);
    }

    body[data-theme="dark"] .stats-card {
        background: linear-gradient(135deg, rgba(30, 41, 59, 0.95), rgba(15, 23, 42, 0.9));
        border-color: rgba(99, 102, 241, 0.2);
    }

    .stats-card h3 {
        font-size: 0.75rem;
        font-weight: 700;
        margin: 0 0 0.75rem 0;
        color: #6366f1;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid rgba(99, 102, 241, 0.15);
    }

    .stats-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .stats-list li {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.6rem 0;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    }

    body[data-theme="dark"] .stats-list li {
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }

    .stats-list li:last-child {
        border-bottom: none;
    }

    .stat-info {
        display: flex;
        flex-direction: column;
        gap: 3px;
        flex: 1;
    }

    .stat-name {
        font-weight: 600;
        color: var(--text-primary);
        font-size: 0.85rem;
    }

    .stat-meta {
        font-size: 0.7rem;
        color: #64748b;
    }

    .stat-tag {
        font-size: 0.7rem;
        padding: 3px 8px;
        border-radius: 4px;
        font-weight: 600;
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
        text-shadow: none !important;
        opacity: 1 !important;
        white-space: nowrap;
    }

    /* Hard lock white text on all calendar button-like elements */
    .calendar-container .btn-nav,
    .calendar-container .btn-nav *,
    .calendar-container .nav-btn,
    .calendar-container .nav-btn *,
    .calendar-container .today-btn,
    .calendar-container .today-btn *,
    .calendar-container #newReservationBtn,
    .calendar-container #newReservationBtn *,
    .calendar-container .stat-tag,
    .calendar-container .stat-tag *,
    .calendar-container .status-badge,
    .calendar-container .status-badge * {
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
        opacity: 1 !important;
    }

    @media (max-width: 1024px) {
        .stats-dashboard-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="calendar-container">
    <?php
    // DASHBOARD STATS FETCH
    try {
        // 1. RECENT RESERVATIONS (Newest ID)
        $recentBookings = $db->fetchAll("
            SELECT b.booking_code, b.status, b.check_in_date, g.guest_name 
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            ORDER BY b.id DESC LIMIT 5
        ");

        // 2. RECENT CHECK-INS
        $recentCheckins = $db->fetchAll("
            SELECT b.booking_code, b.status, b.room_id, b.check_in_date, g.guest_name, r.room_number 
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE b.status = 'checked_in'
            ORDER BY b.check_in_date DESC, b.id DESC LIMIT 5
        ");

        // 3. RECENT CHECK-OUTS
        $recentCheckouts = $db->fetchAll("
            SELECT b.booking_code, b.status, b.room_id, b.check_out_date, g.guest_name, r.room_number 
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE b.status = 'checked_out'
            ORDER BY b.check_out_date DESC, b.id DESC LIMIT 5
        ");
    } catch (Exception $e) {
        $recentBookings = [];
        $recentCheckins = [];
        $recentCheckouts = [];
    }
    ?>



    <!-- Toolbar kalender: judul + menu, lalu navigasi tanggal, pencarian, reservasi baru -->
    <div class="cal-toolbar">
        <div class="cal-tb-top">
            <div class="cal-tb-title">
                <div class="cal-tb-h1row" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap"><h1>Calendar Booking</h1><span data-cbs-slot></span></div>
                <small><?php echo date('d M', strtotime($startDate)); ?> – <?php echo date('d M Y', strtotime($startDate . ' +29 days')); ?></small>
            </div>
            <div class="cal-tb-links">
                <a href="<?php echo BASE_URL; ?>/modules/frontdesk/reservasi.php">List View</a>
                <a href="<?php echo BASE_URL; ?>/modules/frontdesk/breakfast.php">Breakfast</a>
                <a href="<?php echo BASE_URL; ?>/modules/frontdesk/dashboard.php">Dashboard</a>
                <a href="<?php echo BASE_URL; ?>/modules/frontdesk/settings.php">Settings</a>
            </div>
        </div>
        <div class="cal-tb-bar">
            <div class="cal-tb-nav">
                <button class="nav-btn cal-arrow" id="prevMonthBtn" type="button" aria-label="Sebelumnya">‹</button>
                <button class="nav-btn today-btn" id="todayBtn" type="button" onclick="goToToday()">Today</button>
                <button class="nav-btn cal-arrow" id="nextMonthBtn" type="button" aria-label="Berikutnya">›</button>
                <input type="date" class="nav-date-input" id="dateInput" value="<?php echo $startDate; ?>" onchange="changeDate()">
            </div>
            <div class="search-reservation-bar">
                <div class="search-input-wrapper">
                    <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8" />
                        <line x1="21" y1="21" x2="16.65" y2="16.65" />
                    </svg>
                    <input type="text" id="searchReservation" class="search-input" placeholder="Cari tamu, kode booking, kamar…" autocomplete="off">
                    <button class="search-clear-btn" id="searchClearBtn" onclick="clearSearch()" style="display:none;">×</button>
                </div>
                <div class="search-results-dropdown" id="searchResults" style="display:none;"></div>
            </div>
            <button class="nav-btn" id="newReservationBtn" type="button" onclick="openNewReservationForm()">+ New Reservation</button>
        </div>
    </div>
    <style>
        body[data-theme] .cal-toolbar {
            margin-bottom: 0.65rem;
            padding: 0.6rem 0.75rem;
            border-radius: 14px;
            background: var(--fd-card);
            border: 1px solid var(--fd-edge);
            box-shadow: var(--fd-shadow);
        }

        body[data-theme] .cal-tb-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
            padding-bottom: 0.55rem;
            margin-bottom: 0.55rem;
            border-bottom: 1px solid var(--fd-line);
        }

        body[data-theme] .main-content .cal-tb-title h1 {
            margin: 0;
            font-size: 0.95rem !important;
            font-weight: 700 !important;
            color: var(--fd-text) !important;
            -webkit-text-fill-color: var(--fd-text) !important;
            background: none !important;
        }

        body[data-theme] .main-content .cal-tb-title small {
            display: block;
            font-size: 0.66rem !important;
            color: var(--fd-muted) !important;
        }

        body[data-theme] .cal-tb-links {
            display: inline-flex;
            padding: 3px;
            gap: 2px;
            border-radius: 10px;
            background: var(--fd-tile);
            border: 1px solid var(--fd-line);
        }

        body[data-theme] .main-content .cal-tb-links a {
            height: 26px;
            padding: 0 0.7rem;
            display: inline-flex;
            align-items: center;
            border-radius: 7px;
            font-size: 0.68rem !important;
            font-weight: 600;
            text-decoration: none;
            color: var(--fd-text-2) !important;
            -webkit-text-fill-color: var(--fd-text-2) !important;
        }

        body[data-theme] .main-content .cal-tb-links a:hover {
            background: var(--fd-accent-soft);
            color: var(--fd-accent-text) !important;
            -webkit-text-fill-color: var(--fd-accent-text) !important;
        }

        body[data-theme] .cal-tb-bar {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        body[data-theme] .cal-tb-nav {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-shrink: 0;
        }

        body[data-theme] .main-content .cal-tb-nav .cal-arrow {
            width: 30px !important;
            height: 30px !important;
            padding: 0 !important;
            border-radius: 8px !important;
            border: 1px solid var(--fd-input-border) !important;
            background: var(--fd-input-bg) !important;
            color: var(--fd-text) !important;
            -webkit-text-fill-color: var(--fd-text) !important;
            font-size: 1rem !important;
            line-height: 1 !important;
            box-shadow: none !important;
        }

        body[data-theme] .main-content .cal-tb-nav .today-btn {
            height: 30px !important;
            padding: 0 0.75rem !important;
            border-radius: 8px !important;
            font-size: 0.68rem !important;
            font-weight: 700 !important;
            letter-spacing: 0.02em !important;
            background: var(--fd-accent-soft) !important;
            border: 1px solid rgba(37, 99, 235, 0.3) !important;
            color: var(--fd-accent-text) !important;
            -webkit-text-fill-color: var(--fd-accent-text) !important;
            box-shadow: none !important;
        }

        body[data-theme] .main-content .cal-tb-nav .nav-date-input {
            height: 30px !important;
            margin-left: 4px;
            padding: 0 0.55rem !important;
            border-radius: 8px !important;
            font-size: 0.72rem !important;
            background: var(--fd-input-bg) !important;
            border: 1px solid var(--fd-input-border) !important;
            color: var(--fd-text) !important;
        }

        body[data-theme] .main-content .cal-tb-bar .search-reservation-bar {
            flex: 1;
            min-width: 0;
            margin: 0 !important;
            padding: 0 !important;
            background: none !important;
            border: 0 !important;
            box-shadow: none !important;
        }

        body[data-theme] .main-content .cal-tb-bar .search-input-wrapper {
            height: 30px;
            padding: 0 0.6rem !important;
            border-radius: 8px !important;
        }

        body[data-theme] .main-content .cal-tb-bar .search-input {
            height: 28px !important;
            font-size: 0.74rem !important;
        }

        body[data-theme] .main-content .cal-tb-bar #newReservationBtn {
            flex-shrink: 0;
            height: 30px !important;
            padding: 0 0.9rem !important;
            border-radius: 8px !important;
            border: 0 !important;
            background: linear-gradient(135deg, #1e3a8a, #2563eb) !important;
            color: #fff !important;
            -webkit-text-fill-color: #fff !important;
            font-size: 0.7rem !important;
            font-weight: 700 !important;
            box-shadow: 0 6px 14px -8px rgba(29, 78, 216, 0.7) !important;
        }

        @media (max-width: 860px) {
            body[data-theme] .cal-tb-bar {
                flex-wrap: wrap;
            }

            body[data-theme] .main-content .cal-tb-bar .search-reservation-bar {
                order: 3;
                flex-basis: 100%;
            }
        }
    </style>

    <!-- Calendar Grid - WRAPPED IN SCROLL CONTAINER -->
    <div class="calendar-scroll-wrapper" id="drag-container" style="overflow-x: auto; cursor: grab; user-select: none;">
        <div class="calendar-wrapper">
            <div class="calendar-grid">
                <!-- Month Header Row -->
                <div class="calendar-month-header">
                    <div class="grid-month-room"></div>
                    <?php
                    // Calculate month spans
                    $monthSpans = [];
                    $currentMonth = null;
                    $spanCount = 0;
                    foreach ($dates as $i => $date) {
                        $monthKey = date('F Y', strtotime($date));
                        if ($monthKey !== $currentMonth) {
                            if ($currentMonth !== null) {
                                $monthSpans[] = ['label' => strtoupper($currentMonth), 'span' => $spanCount];
                            }
                            $currentMonth = $monthKey;
                            $spanCount = 1;
                        } else {
                            $spanCount++;
                        }
                    }
                    if ($currentMonth !== null) {
                        $monthSpans[] = ['label' => strtoupper($currentMonth), 'span' => $spanCount];
                    }
                    foreach ($monthSpans as $ms):
                    ?>
                        <div class="grid-month-label" style="grid-column: span <?php echo $ms['span']; ?>;">
                            <span><?php echo $ms['label']; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Header Row -->
                <div class="calendar-grid-header">
                    <div class="grid-header-room">ROOMS</div>
                    <?php foreach ($dates as $date):
                        $avail = $availPerDate[$date] ?? 0;
                        $occPct = $totalRoomCount > 0 ? round((($totalRoomCount - $avail) / $totalRoomCount) * 100, 1) : 0;
                    ?>
                        <?php $isWeekend = in_array(date('N', strtotime($date)), ['6', '7'], true); ?>
                        <div class="grid-header-date cal-h<?php echo ($date === date('Y-m-d')) ? ' today' : ''; ?><?php echo $isWeekend ? ' weekend' : ''; ?>">
                            <span class="cal-h-dow"><?php echo strtoupper(date('D', strtotime($date))); ?></span>
                            <span class="cal-h-day"><?php echo date('d', strtotime($date)); ?></span>
                            <span class="cal-h-meta">
                                <span class="cal-h-occ" title="Okupansi"><?php echo number_format($occPct, 0); ?>%</span>
                                <span class="cal-h-avail<?php echo $avail === 0 ? ' full' : ''; ?>" title="Kamar tersedia"><?php echo $avail; ?> free</span>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Room Rows -->
                <?php
                // Group rooms by type
                $roomsByType = [];
                foreach ($rooms as $room) {
                    $typeKey = $room['type_name'];
                    if (!isset($roomsByType[$typeKey])) {
                        $roomsByType[$typeKey] = [];
                    }
                    $roomsByType[$typeKey][] = $room;
                }

                // Display rooms grouped by type with type headers
                foreach ($roomsByType as $typeName => $typeRooms):
                    // Get base price from first room of this type
                    $typePrice = $typeRooms[0]['base_price'] ?? 0;
                ?>
                    <!-- Type Header Row -->
                    <div class="grid-room-type-header">
                        📂 <?php echo htmlspecialchars($typeName); ?>
                    </div>
                    <?php foreach ($dates as $date):
                        $typeAvail = $availPerTypeDate[$typeName][$date] ?? 0;
                    ?>
                        <div class="grid-type-price-cell">
                            <span class="type-avail-count"><?php echo $typeAvail; ?></span>
                            <?php if (!$isStaffView): ?>
                                <span class="type-price-text">Rp<?php echo number_format($typePrice, 0, ',', '.'); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <!-- Individual Rooms of This Type -->
                    <?php foreach ($typeRooms as $room): ?>
                        <?php $isDirty = (($room['status'] ?? '') === 'cleaning'); ?>
                        <div class="grid-room-label<?php echo $isDirty ? ' dirty' : ''; ?>" data-room-id="<?php echo (int)$room['id']; ?>">
                            <span class="grid-room-number"><?php echo htmlspecialchars($room['room_number']); ?></span>
                            <?php if ($isDirty): ?>
                                <button type="button" class="room-clean-btn" title="Kamar kotor — klik untuk tandai sudah bersih" aria-label="Tandai kamar <?php echo htmlspecialchars($room['room_number'], ENT_QUOTES); ?> bersih" onclick="event.stopPropagation(); markRoomClean(<?php echo (int)$room['id']; ?>, '<?php echo htmlspecialchars($room['room_number'], ENT_QUOTES); ?>');">🧹</button>
                            <?php endif; ?>
                        </div>

                        <?php foreach ($dates as $date): ?>
                            <?php
                            // Check for same-day turnover (checkout + checkin on same day)
                            $hasTurnover = false;
                            if (isset($bookingMatrix[$room['id']])) {
                                $checkouts = 0;
                                $checkins = 0;
                                foreach ($bookingMatrix[$room['id']] as $booking) {
                                    $checkinDate = date('Y-m-d', strtotime($booking['check_in_date']));
                                    $checkoutDate = date('Y-m-d', strtotime($booking['check_out_date']));
                                    if ($checkoutDate === $date) $checkouts++;
                                    if ($checkinDate === $date) $checkins++;
                                }
                                $hasTurnover = ($checkouts > 0 && $checkins > 0);
                            }
                            ?>
                            <div class="grid-date-cell<?php echo ($date === date('Y-m-d')) ? ' today' : ''; ?><?php echo $hasTurnover ? ' has-turnover' : ''; ?><?php echo $isDirty ? ' dirty-row' : ''; ?>"
                                data-date="<?php echo $date; ?>"
                                data-room-number="<?php echo htmlspecialchars($room['room_number']); ?>"
                                data-room-id="<?php echo $room['id']; ?>"
                                title="<?php echo htmlspecialchars($room['room_number']); ?> - <?php echo date('d M Y', strtotime($date)); ?><?php echo $hasTurnover ? ' (Turnover: CO + CI)' : ''; ?>"
                                onclick="openCellReservation(this)">
                                <?php
                                // Render room block bars (maintenance/out of order/etc)
                                if (isset($roomBlockMatrix[$room['id']])) {
                                    foreach ($roomBlockMatrix[$room['id']] as $block) {
                                        $blockStart = strtotime((string)$block['block_start_date']);
                                        $blockEnd = strtotime((string)$block['block_end_date']);
                                        $currentDate = strtotime($date);

                                        if ($currentDate === $blockStart) {
                                            $blockNights = max(1, (int)ceil(($blockEnd - $blockStart) / 86400));
                                            $barWidth = ($blockNights * 110) - 6;
                                            $reasonRaw = (string)($block['block_reason'] ?? 'maintenance');
                                            $reasonMap = [
                                                'maintenance' => 'Maintenance',
                                                'deep_cleaning' => 'Deep Cleaning',
                                                'owner_use' => 'Owner Use',
                                                'out_of_order' => 'Out of Order',
                                                'event_setup' => 'Event Setup',
                                                'other' => 'Block Room'
                                            ];
                                            $reasonText = $reasonMap[$reasonRaw] ?? ucfirst(str_replace('_', ' ', $reasonRaw));
                                            $notesText = trim((string)($block['notes'] ?? ''));
                                            $notesSuffix = $notesText !== '' ? ' • ' . $notesText : '';
                                            // Label: "Block · <untuk siapa / alasan>" — catatan blok (tanpa awalan "Cloudbeds:"), alasan bila bukan "other"
                                            $who = trim(preg_replace(['/^Cloudbeds:\s*/i', '/\s*\[Dicabut via Cloudbeds\]\s*/i'], '', $notesText));
                                            $blockParts = array_values(array_filter([$reasonRaw !== 'other' ? $reasonText : '', $who], fn($x) => $x !== ''));
                                            $blockLabel = 'Block' . ($blockParts ? ' · ' . implode(' · ', $blockParts) : '');
                                ?>
                                            <div class="booking-bar-container" style="left: 50%; width: <?php echo $barWidth; ?>px; z-index: 5;"
                                                data-block-id="<?php echo (int)$block['id']; ?>"
                                                data-room-id="<?php echo (int)$block['room_id']; ?>"
                                                data-block-start="<?php echo htmlspecialchars($block['block_start_date']); ?>"
                                                data-block-end="<?php echo htmlspecialchars($block['block_end_date']); ?>"
                                                data-block-reason="<?php echo htmlspecialchars($reasonRaw); ?>"
                                                data-block-notes="<?php echo htmlspecialchars($notesText); ?>">
                                                <div class="booking-bar booking-blocked"
                                                    style="background: repeating-linear-gradient(135deg, #334155 0 7px, #3e4c61 7px 14px) !important; border-right-color: #334155; border-left-color: #475569;"
                                                    onclick="event.stopPropagation();"
                                                    title="<?php echo htmlspecialchars($blockLabel); ?>">
                                                    <span><?php echo htmlspecialchars($blockLabel); ?></span>
                                                    <?php if (!$isStaffView): ?>
                                                        <button class="bar-action-btn bar-delete-btn" onclick="event.stopPropagation(); removeRoomBlock(<?php echo (int)$block['id']; ?>, '<?php echo htmlspecialchars($room['room_number'], ENT_QUOTES); ?>')" title="Batalkan Block">✕</button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php
                                        }
                                    }
                                }

                                // Find bookings for this room and date - CLOUDBED STYLE (bar from noon to noon)
                                if (isset($bookingMatrix[$room['id']])) {
                                    foreach ($bookingMatrix[$room['id']] as $booking) {
                                        $checkinDate = strtotime($booking['check_in_date']);
                                        $checkoutDate = strtotime($booking['check_out_date']);
                                        $currentDate = strtotime($date);

                                        // Only render bar on check-in date
                                        if ($currentDate === $checkinDate) {
                                            // Calculate nights (days between check-in and check-out)
                                            $totalNights = ceil(($checkoutDate - $checkinDate) / 86400);

                                            // Calculate width: start from 50% of check-in cell, end at 50% of check-out cell
                                            // Width = (nights × 110px) - 6px gap = span from noon to noon with spacing
                                            $barWidth = ($totalNights * 110) - 6; // 110px per column minus 6px gap

                                            $statusClass = 'booking-' . str_replace('_', '-', $booking['status']);

                                            // Check if booking is past or checked out
                                            $today = strtotime(date('Y-m-d'));
                                            $isPastBooking = ($checkoutDate < $today);
                                            $isCheckedOut = ($booking['status'] === 'checked_out');

                                            if ($isPastBooking || $isCheckedOut) {
                                                $statusClass .= ' booking-past';
                                            }

                                            // Determine color based on status
                                            $isCheckedIn = ($booking['status'] === 'checked_in');
                                            if ($isCheckedOut || $isPastBooking) {
                                                $bookingColor = $checkedOutColor;
                                            } elseif ($isCheckedIn) {
                                                $bookingColor = $checkedInColor;
                                            } else {
                                                $bookingColor = $defaultColor;
                                            }

                                            // Add status icons
                                            $statusIcon = $isCheckedIn ? '✓ ' : ($isCheckedOut ? '📭 ' : '');

                                            $guestNameRaw = mb_substr((string)($booking['guest_name'] ?? 'Guest'), 0, 12);
                                            $guestName = htmlspecialchars($guestNameRaw);
                                            $guestNameJs = htmlspecialchars(json_encode($guestNameRaw, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                                            $bookingCode = htmlspecialchars($booking['booking_code']);
                                            $shortCode = substr($bookingCode, 0, 8); // Show first 8 chars
                                            $statusText = ucfirst(str_replace('_', ' ', $booking['status']));

                                            // Cloudbeds-style status dots: red=belum lunas, green=lunas, yellow=ada request tamu
                                            // Group bookings (multi-room) share one combined balance — use the group's
                                            // total paid/final price so every room shows green once the group is settled,
                                            // instead of relying on this one room's own possibly-stale payment_status.
                                            $bookingGroupId = $booking['group_id'] ?? null;
                                            $isPaidFull = $bookingGroupId && isset($groupPaymentStatus[$bookingGroupId])
                                                ? $groupPaymentStatus[$bookingGroupId]
                                                : (max((float)($booking['paid_amount'] ?? 0), (float)($booking['bp_paid'] ?? 0)) + 0.01 >= (float)($booking['final_price'] ?? 0));
                                            $hasGuestRequest = trim((string)($booking['special_request'] ?? '')) !== '' || (int)($booking['extras_count'] ?? 0) > 0;
                                        ?>
                                            <div class="booking-bar-container" style="left: 50%; width: <?php echo $barWidth; ?>px;"
                                                data-booking-id="<?php echo $booking['id']; ?>"
                                                data-room-id="<?php echo $booking['room_id']; ?>"
                                                data-check-in="<?php echo $booking['check_in_date']; ?>"
                                                data-check-out="<?php echo $booking['check_out_date']; ?>"
                                                data-status="<?php echo $booking['status']; ?>"
                                                data-nights="<?php echo $totalNights; ?>"
                                                data-guest="<?php echo $guestName; ?>"
                                                <?php if (!$isPastBooking && !$isCheckedOut): ?>draggable="true" <?php endif; ?>>
                                                <?php $otaBadge = calendar_ota_badge($booking['booking_source'] ?? '', $calSourceNames); ?>
                                                <div class="booking-bar <?php echo $statusClass; ?><?php echo $otaBadge !== '' ? ' has-ota' : ''; ?>"
                                                    onclick="event.stopPropagation(); viewBooking(<?php echo $booking['id']; ?>, event);"
                                                    title="<?php echo $statusIcon . $guestName; ?> (<?php echo $bookingCode; ?>) - <?php echo $statusText; ?><?php echo $isPastBooking ? ' [PAST]' : ''; ?><?php echo $isPaidFull ? ' - LUNAS' : ' - BELUM LUNAS'; ?><?php echo $hasGuestRequest ? ' - Ada Request Tamu' : ''; ?>">
                                                    <?php if (!$isPastBooking): ?>
                                                        <span class="booking-status-dots">
                                                            <span class="status-dot <?php echo $isPaidFull ? 'dot-green' : 'dot-red'; ?>" title="<?php echo $isPaidFull ? 'Lunas' : 'Belum Lunas'; ?>"></span>
                                                            <?php if ($hasGuestRequest): ?>
                                                                <span class="status-dot dot-yellow" title="Ada request tamu (extra bed/trip/dll)"></span>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php echo $otaBadge; ?><span><?php echo $statusIcon . $guestName; ?> • <?php echo $shortCode; ?></span>
                                                    <?php if ($isCheckedIn && !$isPastBooking): ?>
                                                        <button class="bar-action-btn bar-extend-btn" onclick="event.stopPropagation(); openExtendModal(<?php echo (int)$booking['id']; ?>, <?php echo $guestNameJs; ?>, '<?php echo htmlspecialchars($booking['check_out_date']); ?>', <?php echo (int)$totalNights; ?>)" title="Extend Stay">+</button>
                                                    <?php elseif (!$isCheckedIn): ?>
                                                        <button class="bar-action-btn bar-edit-btn" onclick="event.stopPropagation(); openEditReservationModal(<?php echo $booking['id']; ?>)" title="Edit Reservasi">✎</button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                <?php
                                            break; // Only one bar per booking
                                        }
                                    }
                                }
                                ?>
                            </div>
                        <?php endforeach; // End dates loop for each room 
                        ?>
                    <?php endforeach; // End individual rooms loop 
                    ?>
                <?php endforeach; // End room types loop
                ?>

                <!-- FOOTER DATE ROW - Same as header for easy reference when scrolling -->
                <div class="calendar-grid-footer">
                    <div class="grid-footer-room">ROOMS</div>
                    <?php foreach ($dates as $date):
                        $avail = $availPerDate[$date] ?? 0;
                        $occPct = $totalRoomCount > 0 ? round((($totalRoomCount - $avail) / $totalRoomCount) * 100, 0) : 0;
                    ?>
                        <?php $isWeekend = in_array(date('N', strtotime($date)), ['6', '7'], true); ?>
                        <div class="grid-footer-date cal-h<?php echo ($date === date('Y-m-d')) ? ' today' : ''; ?><?php echo $isWeekend ? ' weekend' : ''; ?>">
                            <span class="cal-h-dow"><?php echo strtoupper(date('D', strtotime($date))); ?></span>
                            <span class="cal-h-day"><?php echo date('d', strtotime($date)); ?></span>
                            <span class="cal-h-meta">
                                <span class="cal-h-occ" title="Okupansi"><?php echo $occPct; ?>%</span>
                                <span class="cal-h-avail<?php echo $avail === 0 ? ' full' : ''; ?>" title="Kamar tersedia"><?php echo $avail; ?> free</span>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Legend -->
    <div class="legend">
        <div class="legend-item">
            <div class="legend-color" style="background: linear-gradient(135deg, #3d5a99, #5b82d1);"></div>
            <span class="legend-label">📋 Booking (Confirmed/Pending)</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: linear-gradient(135deg, #334155, #475569);"></div>
            <span class="legend-label">Block kamar (tamu / maintenance / dll)</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: linear-gradient(135deg, #10b981, #34d399);"></div>
            <span class="legend-label">✓ Checked In (Active)</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: linear-gradient(135deg, #9ca3af, #d1d5db); opacity: 0.4;"></div>
            <span class="legend-label">📭 Past Booking (History)</span>
        </div>
        <div class="legend-item">
            <span class="status-dot dot-red" style="position:static; animation:none;"></span>
            <span class="legend-label">Belum Lunas</span>
        </div>
        <div class="legend-item">
            <span class="status-dot dot-green" style="position:static;"></span>
            <span class="legend-label">Lunas</span>
        </div>
        <div class="legend-item">
            <span class="status-dot dot-yellow" style="position:static;"></span>
            <span class="legend-label">Ada Request Tamu (Extra Bed/Trip/dll)</span>
        </div>
    </div>



</div>

<script>
    // Tanggal lokal YYYY-MM-DD (toISOString() memakai UTC: jam 00:00-07:00 WIB menghasilkan tanggal kemarin).
    window.fdLocalYmd = function(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    };
    // Initialize OTA Fees from PHP - Create global variable (not just window property)
    var OTA_FEES = <?php echo json_encode($otaFees); ?>;
    var OTA_SOURCE_KEYS = <?php echo json_encode($otaSourceKeys); ?>;

    // Dynamic source name map from booking_sources table
    var SOURCE_NAMES = <?php
                        $sourceNames = [];
                        foreach ($bookingSources as $bs) {
                            $sourceNames[$bs['source_key']] = ($bs['icon'] ?? '') . ' ' . ($bs['source_name'] ?? ucfirst($bs['source_key']));
                        }
                        echo json_encode($sourceNames, JSON_UNESCAPED_UNICODE);
                        ?>;
    const IS_STAFF_VIEW = <?php echo $isStaffView ? 'true' : 'false'; ?>;

    // Global variables for reservation form (used across multiple functions)
    var currentSource = '';
    var currentFees = OTA_FEES;
    var reservationMode = 'reservation';

    window.setReservationMode = function setReservationMode(mode) {
        reservationMode = mode === 'block_room' ? 'block_room' : 'reservation';

        const guestInfoSection = document.getElementById('guestInfoSection');
        const guestCountSection = document.getElementById('guestCountSection');
        const sourcePaymentSection = document.getElementById('sourcePaymentSection');
        const priceSummarySection = document.getElementById('priceSummarySection');
        const paymentSection = document.getElementById('paymentSection');
        const blockInfoSection = document.getElementById('blockInfoSection');
        const submitBtn = document.getElementById('reservationSubmitBtn');
        const title = document.querySelector('#reservationModal .modal-header-compact h2');
        const guestNameInput = document.getElementById('guestName');
        const bookingSourceInput = document.getElementById('bookingSource');

        const isBlock = reservationMode === 'block_room';

        if (guestInfoSection) guestInfoSection.style.display = isBlock ? 'none' : 'grid';
        if (guestCountSection) guestCountSection.style.display = isBlock ? 'none' : 'flex';
        if (sourcePaymentSection) sourcePaymentSection.style.display = isBlock ? 'none' : 'grid';
        if (priceSummarySection) priceSummarySection.style.display = isBlock ? 'none' : 'block';
        if (paymentSection) paymentSection.style.display = isBlock ? 'none' : 'flex';
        if (blockInfoSection) blockInfoSection.style.display = isBlock ? 'block' : 'none';

        if (guestNameInput) guestNameInput.required = !isBlock;
        if (bookingSourceInput) bookingSourceInput.required = !isBlock;

        if (title) title.textContent = isBlock ? 'Block Room' : 'New Reservation';
        if (submitBtn) submitBtn.textContent = isBlock ? 'Save Block Room' : 'Save Reservation';

        calculateMultiRoomTotalCalendar();
    }

    window.removeRoomBlock = async function removeRoomBlock(blockId, roomNumber) {
        const ok = confirm(`Batalkan block room ${roomNumber || ''}?`);
        if (!ok) return;

        // Optimistic update: the block bar disappears right away (no page reload);
        // the request runs in the background and the bar is restored if it fails.
        const bar = document.querySelector('.booking-bar-container[data-block-id="' + blockId + '"]');
        const restore = () => {
            if (!bar) return;
            bar.style.pointerEvents = '';
            bar.style.transition = '';
            bar.style.opacity = '';
            bar.style.transform = '';
        };
        if (bar) {
            bar.style.pointerEvents = 'none';
            bar.style.transition = 'opacity .18s ease, transform .18s ease';
            bar.style.opacity = '0';
            bar.style.transform = 'scaleY(.6)';
        }

        try {
            const fd = new FormData();
            fd.append('block_id', blockId);
            const res = await fetch('<?php echo BASE_URL; ?>/api/delete-room-block.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (data && data.success) {
                if (bar) bar.remove();
                spToast(data.message || 'Block room dibatalkan', true);
            } else {
                restore();
                spToast(data && data.message ? data.message : 'Gagal membatalkan block', false);
            }
        } catch (e) {
            restore();
            spToast('Gagal menghubungi server — block dikembalikan', false);
        }
    }

    window.viewBooking = function viewBooking(id, event) {
        event.preventDefault();
        event.stopPropagation();

        console.log('📋 Loading booking details:', id);

        // Fetch booking details via AJAX - use relative path from modules/frontdesk/
        fetch('../../api/get-booking-details.php?id=' + id)
            .then(response => {
                console.log('📡 API Response status:', response.status);
                return response.text();
            })
            .then(text => {
                console.log('📥 API Response text:', text);
                try {
                    const data = JSON.parse(text);
                    console.log('✅ Parsed JSON:', data);
                    if (data.success) {
                        console.log('🎯 Showing booking:', data.booking);
                        showBookingQuickView(data.booking);
                    } else {
                        console.error('❌ API Error:', data.message);
                        alert('Error: ' + data.message);
                    }
                } catch (e) {
                    console.error('❌ JSON Parse Error:', e);
                    console.error('Raw text:', text);
                    alert('Failed to parse response');
                }
            })
            .catch(error => {
                console.error('❌ Fetch Error:', error);
                alert('Failed to load booking details: ' + error.message);
            });
    }

    let currentPaymentBooking = null;
    let currentGroupRoomsMap = {};

    // Expand / collapse the item details of a Hotel Service invoice row in the folio
    function toggleSvcDetail(idx, row) {
        const det = document.getElementById('sp-svc-detail-' + idx);
        if (!det) return;
        const open = det.style.display === 'none';
        det.style.display = open ? 'table-row' : 'none';
        const caret = row.querySelector('.sp-svc-caret');
        if (caret) caret.textContent = open ? '▾' : '▸';
    }

    function escHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function(ch) {
            return ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            })[ch];
        });
    }

    window.openRoomNoteEditor = function openRoomNoteEditor(bookingId) {
        const gb = currentGroupRoomsMap[bookingId];
        let roomLabel = '#' + bookingId;
        let existingNote = '';
        if (gb) {
            roomLabel = gb.room_number;
            existingNote = gb.special_request || '';
        } else if (currentPaymentBooking && currentPaymentBooking.id === bookingId) {
            roomLabel = currentPaymentBooking.room_number || roomLabel;
            existingNote = currentPaymentBooking.special_requests || '';
        }
        const note = prompt('Catatan / Request Tamu untuk Room ' + roomLabel + ':', existingNote);
        if (note === null) return;

        fetch('<?php echo BASE_URL; ?>/api/update-booking-note.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                credentials: 'include',
                body: 'booking_id=' + encodeURIComponent(bookingId) + '&note=' + encodeURIComponent(note)
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    alert('Error: ' + (data.message || 'Gagal menyimpan catatan'));
                    return;
                }
                if (!currentPaymentBooking) return;
                return fetch('../../api/get-booking-details.php?id=' + currentPaymentBooking.id)
                    .then(res => res.json())
                    .then(updated => {
                        if (updated.success) showBookingQuickView(updated.booking);
                    });
            })
            .catch(err => {
                console.error(err);
                alert('Gagal menyimpan catatan');
            });
    }

    // Side Panel - populate and show (Cloudbed-style)
    function showBookingQuickView(booking) {
        console.log('🎯 showBookingQuickView (side panel) called with:', booking);

        // DEBUG: Log group booking params
        console.log('📊 GROUP BOOKING PARAMS:');
        console.log('  - guest_id:', booking.guest_id, typeof booking.guest_id);
        console.log('  - check_in_date:', booking.check_in_date, typeof booking.check_in_date);
        console.log('  - check_out_date:', booking.check_out_date, typeof booking.check_out_date);
        console.log('  - group_bookings:', booking.group_bookings, 'count:', booking.group_bookings ? booking.group_bookings.length : 0);

        currentPaymentBooking = booking;
        const panel = document.getElementById('bookingQuickView');
        if (!panel) {
            alert('Side panel not found');
            return;
        }

        // Guest avatar initials
        const initials = (booking.guest_name || 'G').split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
        document.getElementById('sp-avatar').textContent = initials;

        // Guest name & phone
        document.getElementById('sp-guest-name').textContent = booking.guest_name || '-';
        const phoneEl = document.getElementById('sp-guest-phone');
        phoneEl.textContent = booking.guest_phone || '';
        phoneEl.style.display = booking.guest_phone ? '' : 'none';
        document.getElementById('sp-sub').textContent = [booking.booking_code, booking.room_number ? 'Room ' + booking.room_number : '', booking.room_type || ''].filter(Boolean).join(' · ');

        // WhatsApp link
        // Ikon WhatsApp selalu tampil: menu kirim invoice (nomor bisa diisi saat mengirim bila belum ada)
        const waEl = document.getElementById('sp-wa-link');
        if (waEl) waEl.style.display = 'flex';
        const waWrap = document.getElementById('spWa');
        if (waWrap) waWrap.classList.remove('open');

        // Status badge
        const statusEl = document.getElementById('sp-status');
        const statusMap = {
            checked_in: 'Checked In',
            confirmed: 'Confirmed',
            pending: 'Pending',
            checked_out: 'Checked Out',
            cancelled: 'Cancelled'
        };
        const statusColorMap = {
            checked_in: '#dcfce7;color:#16a34a',
            confirmed: '#dbeafe;color:#2563eb',
            pending: '#fef3c7;color:#d97706',
            checked_out: '#f1f5f9;color:#64748b',
            cancelled: '#fce4ec;color:#e53935'
        };
        statusEl.textContent = statusMap[booking.status] || booking.status;
        statusEl.style.cssText = '';
        statusEl.className = 'sp-status-badge st-' + (booking.status || 'pending');

        // Source badge - SIMPLIFIED & FIXED
        let bkSrc = (booking.booking_source || 'walk_in').trim().toLowerCase();

        // Hardcoded source name mapping
        const sourceNameMap = {
            'walk_in': 'Walk-In',
            'phone': 'Phone Booking',
            'online': 'Direct Online',
            'direct': 'Direct',
            'agoda': '🏨 OTA Agoda',
            'booking': '📱 OTA Booking.com',
            'tiket': '✈️ OTA Tiket.com',
            'traveloka': '🎫 OTA Traveloka',
            'airbnb': '🏠 OTA Airbnb',
            'expedia': '🗺️ OTA Expedia',
            'pegipegi': '🧳 OTA Pegipegi',
            'ota': '🌐 OTA Lainnya'
        };

        // Determine display source
        let displaySource = sourceNameMap[bkSrc] || bkSrc.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

        // Update element
        const sourceEl = document.getElementById('sp-source');
        if (sourceEl) {
            sourceEl.textContent = displaySource;
        }

        // Timeline
        const fmtD = (d) => d ? new Date(d).toLocaleDateString('id-ID', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        }) : '-';
        document.getElementById('sp-booked-date').textContent = fmtD(booking.created_at);
        document.getElementById('sp-checkin-date').textContent = fmtD(booking.check_in_date);
        document.getElementById('sp-checkout-date').textContent = fmtD(booking.check_out_date);
        // Timeline progress
        let progress = 0;
        if (booking.status === 'checked_out' || booking.status === 'cancelled') progress = 100;
        else if (booking.status === 'checked_in') progress = 66;
        else progress = 33;
        document.getElementById('sp-timeline-progress').style.width = progress + '%';

        // Guest counts
        document.getElementById('sp-adults').textContent = booking.adults || 1;
        document.getElementById('sp-children').textContent = booking.children || 0;
        document.getElementById('sp-nights').textContent = booking.total_nights || 1;

        // Multi-room group booking (e.g. Mrs Hilda 10 rooms) -> show ONE consolidated tagihan
        // regardless of which room's bar was clicked.
        const isGroup = !!(booking.is_group_booking && booking.group_bookings && booking.group_bookings.length > 1);

        // Balance
        const roomBalance = Math.max(0, isGroup ? (booking.combined_balance || 0) : ((booking.final_price || 0) - (booking.paid_amount || 0)));
        // Hotel Service invoices (laundry, tour, rental...) that are still unpaid are part of the guest's tagihan
        const svcInvoices = booking.service_invoices || [];
        const svcOutstanding = parseFloat(booking.service_outstanding || 0);
        const balance = roomBalance;
        const fmtR = (v) => 'Rp' + new Intl.NumberFormat('id-ID').format(v || 0);
        // Two separate bills: room (synced with Cloudbeds) and Hotel Service (not in Cloudbeds)
        document.getElementById('sp-balance').textContent = fmtR(roomBalance);
        document.getElementById('sp-balance-box').classList.toggle('paid', roomBalance <= 0);
        document.getElementById('sp-balance-label').textContent = roomBalance <= 0 ? 'Kamar lunas' : 'Balance due · Kamar';
        const svcBox = document.getElementById('sp-svc-box');
        if (svcBox) {
            svcBox.style.display = svcOutstanding > 0 ? 'flex' : 'none';
            document.getElementById('sp-svc-amount').textContent = fmtR(svcOutstanding);
            document.getElementById('sp-svc-total').textContent = 'Total ' + fmtR(roomBalance + svcOutstanding);
        }

        // Folio table
        let folioRows = '';
        let totalDebit = 0,
            totalCredit = 0;

        const pmLabel = (p) => (p.payment_method || 'cash').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        const pdLabel = (p) => new Date(p.payment_date).toLocaleDateString('id-ID', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        }) + ' ' + new Date(p.payment_date).toLocaleTimeString('id-ID', {
            hour: '2-digit',
            minute: '2-digit'
        });

        if (isGroup) {
            // Room charge per room in the group
            booking.group_bookings.forEach(function(gb) {
                const roomExtras = (booking.group_extras || []).filter(ex => String(ex.room_number || '') === String(gb.room_number || ''))
                    .reduce((t, ex) => t + parseFloat(ex.total_price || 0), 0);
                const gross = Math.max(0, parseFloat(gb.final_price || 0) + parseFloat(gb.discount || 0) - roomExtras);
                totalDebit += gross;
                folioRows += '<tr><td><div class="folio-desc-title">Room Charge - ' + escHtml(gb.type_name || '') + ' (' + escHtml(gb.room_number || '') + ')</div><div class="folio-desc-sub">' + fmtD(booking.check_in_date) + ' → ' + fmtD(booking.check_out_date) + ' • ' + (booking.total_nights || 1) + ' night(s)</div></td><td class="text-right">' + fmtR(gross) + '</td><td class="text-right">-</td></tr>';
                if (parseFloat(gb.discount) > 0) {
                    totalCredit += parseFloat(gb.discount);
                    folioRows += '<tr><td><div class="folio-desc-title">Promo Discount (' + (gb.room_number || '') + ')</div></td><td class="text-right">-</td><td class="text-right">' + fmtR(gb.discount) + '</td></tr>';
                }
            });

            // Extras across ALL rooms in the group
            (booking.group_extras || []).forEach(function(ex) {
                totalDebit += parseFloat(ex.total_price || 0);
                folioRows += '<tr><td><div class="folio-desc-title">' + ex.item_name + ' (' + ex.quantity + 'x) - Room ' + (ex.room_number || '') + '</div><div class="folio-desc-sub">' + (ex.notes || '') + '</div></td><td class="text-right">' + fmtR(ex.total_price) + '</td><td class="text-right">-</td></tr>';
            });

            // Payments recorded against ANY room in the group
            (booking.group_payments || []).forEach(function(p) {
                totalCredit += parseFloat(p.amount || 0);
                folioRows += '<tr><td><div class="folio-desc-title">' + pmLabel(p) + ' - Payment Recorded (Room ' + (p.room_number || '') + ')</div><div class="folio-desc-sub">' + pdLabel(p) + '</div></td><td class="text-right">-</td><td class="text-right">' + fmtR(p.amount) + '</td></tr>';
            });
        } else {
            // Room charge as debit
            const extrasSum = (booking.extras || []).reduce((t, ex) => t + parseFloat(ex.total_price || 0), 0);
            const roomTotal = booking.final_price != null ?
                Math.max(0, parseFloat(booking.final_price || 0) + parseFloat(booking.discount || 0) - extrasSum) :
                (booking.room_price || 0) * (booking.total_nights || 1);
            totalDebit += roomTotal;
            folioRows += '<tr><td><div class="folio-desc-title">Room Charge - ' + escHtml(booking.room_type || '') + ' (' + escHtml(booking.room_number || '') + ')</div><div class="folio-desc-sub">' + fmtD(booking.check_in_date) + ' → ' + fmtD(booking.check_out_date) + ' • ' + (booking.total_nights || 1) + ' night(s) × ' + fmtR(booking.room_price) + '</div></td><td class="text-right">' + fmtR(roomTotal) + '</td><td class="text-right">-</td></tr>';

            // Extras as debit
            if (booking.extras && booking.extras.length > 0) {
                booking.extras.forEach(function(ex) {
                    totalDebit += parseFloat(ex.total_price || 0);
                    folioRows += '<tr><td><div class="folio-desc-title">' + ex.item_name + ' (' + ex.quantity + 'x)</div><div class="folio-desc-sub">' + (ex.notes || '') + '</div></td><td class="text-right">' + fmtR(ex.total_price) + '</td><td class="text-right">-</td></tr>';
                });
            }

            // Discount as credit if any
            if (parseFloat(booking.discount) > 0) {
                totalCredit += parseFloat(booking.discount);
                folioRows += '<tr><td><div class="folio-desc-title">Promo Discount</div></td><td class="text-right">-</td><td class="text-right">' + fmtR(booking.discount) + '</td></tr>';
            }

            // Payments as credit
            if (booking.payments && booking.payments.length > 0) {
                booking.payments.forEach(function(p) {
                    totalCredit += parseFloat(p.amount || 0);
                    folioRows += '<tr><td><div class="folio-desc-title">' + pmLabel(p) + ' - Payment Recorded</div><div class="folio-desc-sub">' + pdLabel(p) + '</div></td><td class="text-right">-</td><td class="text-right">' + fmtR(p.amount) + '</td></tr>';
                });
            }
        }

        // Hotel Service charges (per invoice: item rows as debit, paid amount as credit)
        if (svcInvoices.length > 0) {
            // One compact row per invoice (invoice id + total); click to expand the item details
            svcInvoices.forEach(function(inv, idx) {
                const itemLines = (inv.items || []).map(function(it) {
                    const title = it.description || String(it.service_type || 'Service').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
                    const qty = parseFloat(it.quantity || 1);
                    return '<div class="sp-svc-line"><span>' + escHtml(title) + (qty > 1 ? ' (' + qty + 'x)' : '') + '</span><b>' + fmtR(it.total_price) + '</b></div>';
                }).join('');
                totalDebit += parseFloat(inv.total || 0);
                folioRows += '<tr class="sp-svc-inv" onclick="toggleSvcDetail(' + idx + ', this)"><td><div class="folio-desc-title"><span class="sp-svc-caret">▸</span> Hotel Service · ' + escHtml(inv.invoice_number || '') + '</div><div class="folio-desc-sub">' + (inv.items || []).length + ' item • ' + fmtD(inv.created_at) + '</div></td><td class="text-right">' + fmtR(inv.total) + '</td><td class="text-right">-</td></tr>' +
                    '<tr class="sp-svc-detail" id="sp-svc-detail-' + idx + '" style="display:none;"><td colspan="3">' + itemLines + '</td></tr>';
                if (parseFloat(inv.paid_amount) > 0) {
                    totalCredit += parseFloat(inv.paid_amount);
                    folioRows += '<tr><td><div class="folio-desc-title">Pembayaran Hotel Service</div><div class="folio-desc-sub">' + escHtml(inv.invoice_number || '') + '</div></td><td class="text-right">-</td><td class="text-right">' + fmtR(inv.paid_amount) + '</td></tr>';
                }
            });
        }

        document.getElementById('sp-folio-body').innerHTML = folioRows;
        document.getElementById('sp-total-debit').textContent = fmtR(totalDebit);
        document.getElementById('sp-total-credit').textContent = fmtR(totalCredit);

        // Details tab
        document.getElementById('sp-booking-code').textContent = booking.booking_code || '-';
        document.getElementById('sp-detail-source').textContent = displaySource;
        document.getElementById('sp-detail-checkin').textContent = fmtD(booking.check_in_date);
        document.getElementById('sp-detail-checkout').textContent = fmtD(booking.check_out_date);
        document.getElementById('sp-detail-nights').textContent = (booking.total_nights || '-') + ' night(s)';
        document.getElementById('sp-detail-guests').textContent = (booking.adults || 1) + ' adult(s)' + (booking.children > 0 ? ', ' + booking.children + ' child(ren)' : '');
        // Notes: for a group booking, show requests from ALL rooms (not just the one clicked)
        const noteDisplay = (isGroup && booking.combined_notes && booking.combined_notes.length > 0) ?
            booking.combined_notes.join(' | ') : (booking.special_requests || '');
        document.getElementById('sp-detail-notes').textContent = noteDisplay || '-';

        // Folio tab: surface the note right where it's first seen (one click = you know there's a note)
        const folioNoteBanner = document.getElementById('sp-folio-note-banner');
        const folioNoteText = document.getElementById('sp-folio-note-text');
        if (noteDisplay) {
            folioNoteBanner.style.display = 'flex';
            folioNoteText.textContent = noteDisplay;
        } else {
            folioNoteBanner.style.display = 'none';
        }

        // Extras in details
        const extSec = document.getElementById('sp-extras-section');
        const extrasForDisplay = isGroup ? (booking.group_extras || []) : (booking.extras || []);
        if (extrasForDisplay.length > 0) {
            extSec.style.display = '';
            document.getElementById('sp-extras-list').innerHTML = extrasForDisplay.map(function(ex) {
                const roomLabel = (isGroup && ex.room_number) ? ' - Room ' + ex.room_number : '';
                return '<div class="sp-detail-row"><span>' + ex.item_name + ' (' + ex.quantity + 'x)' + roomLabel + '</span><strong>Rp' + new Intl.NumberFormat('id-ID').format(ex.total_price) + '</strong></div>';
            }).join('');
        } else {
            extSec.style.display = 'none';
        }

        // Room tab
        document.getElementById('sp-room-type').textContent = booking.room_type || '-';
        document.getElementById('sp-room-number').textContent = 'Room ' + (booking.room_number || '-');
        document.getElementById('sp-room-price-val').textContent = fmtR(booking.room_price || booking.base_price || 0);

        // Display group bookings / related rooms if multiple
        console.log('📦 Group bookings check:', {
            hasGroupBookings: !!booking.group_bookings,
            count: booking.group_bookings ? booking.group_bookings.length : 0,
            data: booking.group_bookings,
            willDisplay: booking.group_bookings && booking.group_bookings.length > 1
        });

        const groupRoomsSection = document.getElementById('sp-group-rooms-section');
        const groupRoomsList = document.getElementById('sp-group-rooms-list');

        // Show group section ONLY if there are multiple bookings (group bookings > 1)
        if (booking.group_bookings && booking.group_bookings.length > 1) {
            console.log('✅ Showing group bookings section with ' + booking.group_bookings.length + ' rooms');
            let html = '';
            currentGroupRoomsMap = {};
            booking.group_bookings.forEach(function(gb, idx) {
                console.log('  Room ' + (idx + 1) + ':', gb);
                currentGroupRoomsMap[gb.id] = gb;
                const isActive = gb.id === booking.id;
                const hasNote = !!(gb.special_request && gb.special_request.trim() !== '');
                html += `<div class="sp-group-room${isActive ? ' on' : ''}" onclick="if (${gb.id} !== ${booking.id}) { closeBookingQuickView(); setTimeout(() => viewBooking(${gb.id}, event), 100); }">`;
                html += `<div class="sp-gr-top"><div><b>Room ${escHtml(gb.room_number)}</b> <small>${escHtml(gb.type_name)}</small>`;
                if (isActive) html += ` <span class="sp-gr-on">Aktif</span>`;
                if (hasNote) html += ` <span class="status-dot dot-yellow" style="position:static;margin-left:4px;" title="${escHtml(gb.special_request)}"></span>`;
                html += `</div>`;
                html += `<button type="button" class="sp-gr-note" onclick="event.stopPropagation(); openRoomNoteEditor(${gb.id})" title="Masukkan catatan/request tamu">Catatan</button>`;
                html += `</div>`;
                html += `<div class="sp-gr-price">${fmtR(gb.room_price)}${parseFloat(gb.discount) > 0 ? ' · disc ' + fmtR(gb.discount) : ''} · <b>${fmtR(gb.final_price)}</b></div>`;
                if (hasNote) html += `<div class="sp-gr-noteline">${escHtml(gb.special_request)}</div>`;
                html += `</div>`;
            });
            groupRoomsList.innerHTML = html;
            groupRoomsSection.style.display = '';
            console.log('✅ Group section rendered with HTML:', html);
        } else {
            console.log('⚠️ Not showing group section - count:', booking.group_bookings ? booking.group_bookings.length : 0);
            currentGroupRoomsMap = {};
            groupRoomsSection.style.display = 'none';
        }

        // Action buttons
        const spIco = {
            pay: '<path d="M2 7h20v12H2z"/><path d="M2 11h20"/>',
            in: '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/>',
            out: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
            move: '<path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
            edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/>',
            del: '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/>'
        };
        const spBtn = (cls, ico, label, onclick) => '<button class="sp-action-btn ' + cls + '" onclick="' + onclick + '"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + spIco[ico] + '</svg><span>' + label + '</span></button>';
        const editCall = 'closeBookingQuickView(); openEditReservationModal(' + booking.id + ')';
        let actions = '';
        const outstandingBalance = isGroup ? (booking.combined_balance || 0) : Math.max(0, (booking.final_price || 0) - (booking.paid_amount || 0));
        if (booking.payment_status !== 'paid' || outstandingBalance > 0) {
            actions += spBtn('success', 'pay', 'Payment', 'openBookingPaymentModal()');
        }
        if (booking.status === 'confirmed' || booking.status === 'pending') {
            actions += spBtn('primary', 'in', 'Check-in', 'quickViewCheckIn()');
            actions += spBtn('', 'move', 'Pindah', 'quickViewMoveRoom()');
            actions += spBtn('', 'edit', 'Edit', editCall);
            actions += spBtn('danger', 'del', 'Delete', 'quickViewDeleteBooking()');
        } else if (booking.status === 'checked_in') {
            actions += spBtn('danger', 'out', 'Check-out', 'quickViewCheckOut()');
            actions += spBtn('', 'move', 'Pindah', 'quickViewMoveRoom()');
            actions += spBtn('', 'in', 'Extend', 'closeBookingQuickView(); openExtendModal(' + booking.id + ', ' + JSON.stringify(booking.guest_name || '').replace(/"/g, '&quot;') + ", '" + String(booking.check_out_date).slice(0, 10) + "', 0)");
            actions += spBtn('', 'edit', 'Edit', editCall);
        } else if (booking.status === 'checked_out') {
            actions += spBtn('', 'edit', 'Edit', editCall);
        }
        document.getElementById('sp-actions').innerHTML = actions;

        // Reset to folio tab
        switchSPTab('folio');
        loadSpDeposits(booking.id);

        // Show panel
        panel.classList.add('active');
    }

    // Print dari panel detail: invoice / registration card booking yang sedang dibuka
    window.toggleSpPrint = function(ev) {
        ev.stopPropagation();
        document.getElementById('spPrint').classList.toggle('open');
    };
    document.addEventListener('click', function(ev) {
        const el = document.getElementById('spPrint');
        if (el && !el.contains(ev.target)) el.classList.remove('open');
    });
    // ===== WhatsApp: kirim invoice + PDF dengan template =====
    window.toggleSpWa = function(ev) {
        ev.stopPropagation();
        document.getElementById('spWa').classList.toggle('open');
    };
    document.addEventListener('click', function(ev) {
        const el = document.getElementById('spWa');
        if (el && !el.contains(ev.target)) el.classList.remove('open');
    });

    function spToast(msg, ok) {
        let t = document.getElementById('spToast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'spToast';
            t.style.cssText = 'position:fixed;left:50%;bottom:28px;transform:translateX(-50%);z-index:100003;max-width:90vw;padding:11px 18px;border-radius:12px;font:700 13px/1.4 Inter,Arial,sans-serif;box-shadow:0 14px 34px -10px rgba(15,23,42,.5);color:#fff;transition:opacity .25s';
            document.body.appendChild(t);
        }
        t.style.background = ok === false ? '#b91c1c' : '#065f46';
        t.textContent = msg;
        t.style.opacity = '1';
        t.style.display = 'block';
        clearTimeout(t._h);
        t._h = setTimeout(() => { t.style.opacity = '0'; setTimeout(() => { t.style.display = 'none'; }, 260); }, 4200);
    }

    // Siapkan pesan (template Inggris + link invoice) lalu buka WhatsApp Web; staf tinggal menekan kirim
    function spWaSend() {
        const b = currentPaymentBooking;
        if (!b || !b.id) return;
        const win = window.open('', '_blank'); // dibuka saat klik agar tidak diblokir popup blocker
        const fd = new FormData();
        fd.append('booking_id', b.id);
        spToast('Menyiapkan pesan invoice…', true);
        fetch('../../api/wa-send-invoice.php', { method: 'POST', body: fd, credentials: 'include' })
            .then(r => r.json())
            .then(res => {
                if (!res.ok || !res.wa_url) {
                    if (win) win.close();
                    spToast(res.message || 'Gagal menyiapkan pesan', false);
                    return;
                }
                if (win) win.location.href = res.wa_url; else window.open(res.wa_url, '_blank');
                spToast(res.has_phone ? 'WhatsApp Web dibuka — tekan kirim' : 'WhatsApp Web dibuka — pilih kontak tamu lalu kirim', true);
            })
            .catch(() => { if (win) win.close(); spToast('Gagal menghubungi server', false); });
    }
    window.spWaInvoice = function() {
        document.getElementById('spWa').classList.remove('open');
        spWaSend();
    };
    window.spWaChat = function() {
        document.getElementById('spWa').classList.remove('open');
        const b = currentPaymentBooking;
        if (!b) return;
        let phone = (b.guest_phone || '').replace(/[^0-9+]/g, '');
        if (!phone) {
            const p = window.prompt('Nomor WhatsApp tamu belum ada.\nMasukkan nomor:', '');
            if (!p) return;
            phone = p.replace(/[^0-9+]/g, '');
        }
        phone = phone.replace(/^\+/, '').replace(/^0/, '62');
        window.open('https://wa.me/' + phone, '_blank');
    };

    window.spPrint = function(type) {
        document.getElementById('spPrint').classList.remove('open');
        if (!currentPaymentBooking || !currentPaymentBooking.id) return;
        if (type === 'deposit') return openDepositModal();
        const id = encodeURIComponent(currentPaymentBooking.id);
        window.open(type === 'invoice' ? 'invoice.php?booking_id=' + id : 'registration-card.php?booking_id=' + id + '&autoprint=1', '_blank');
    };

    // ===== DEPOSIT (uang / kartu identitas) & TANDA TERIMA =====
    let depType = 'cash';
    const DEP_ID_LABEL = { KTP: 'KTP', Passport: 'Passport', SIM: 'SIM', Lainnya: 'ID lain' };

    function depText(d) {
        return d.deposit_type === 'cash' ? 'Cash ' + mvRp(d.amount) : (DEP_ID_LABEL[d.id_type] || d.id_type || 'ID') + (d.id_number ? ' · ' + d.id_number : '');
    }

    window.loadSpDeposits = function(bookingId) {
        const box = document.getElementById('sp-deposit');
        if (!box) return;
        box.style.display = 'none';
        fetch('../../api/booking-deposit.php?action=list&booking_id=' + encodeURIComponent(bookingId))
            .then(r => r.json())
            .then(res => {
                const rows = (res && res.data) || [];
                if (!currentPaymentBooking || String(currentPaymentBooking.id) !== String(bookingId)) return;
                if (!rows.length) return;
                box.innerHTML = '<div class="sp-dep-h"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/></svg>Deposit diterima</div>' +
                    rows.map(d => '<div class="sp-dep-row"><span>' + escHtml(depText(d)) + '</span><div class="sp-dep-act">' +
                        '<button type="button" onclick="printDeposit(' + d.id + ')">Print</button>' +
                        '<button type="button" onclick="editDeposit(' + d.id + ')">Edit</button>' +
                        '<button type="button" class="del" onclick="deleteDeposit(' + d.id + ')">Hapus</button></div></div>').join('');
                window._spDeps = {};
                rows.forEach(d => { window._spDeps[d.id] = d; });
                box.style.display = '';
            })
            .catch(() => {});
    };

    window.printDeposit = function(id) {
        window.open('deposit-receipt.php?id=' + encodeURIComponent(id) + '&autoprint=1', '_blank');
    };

    window.setDepType = function(t) {
        depType = t;
        document.querySelectorAll('#depositModal .dep-seg button').forEach(b => b.classList.toggle('on', b.dataset.t === t));
        document.getElementById('depCashWrap').style.display = t === 'cash' ? '' : 'none';
        document.getElementById('depIdWrap').style.display = t === 'id_card' ? '' : 'none';
    };

    function renderDepList(rows) {
        const list = document.getElementById('depList');
        if (!rows.length) {
            list.style.display = 'none';
            return;
        }
        list.innerHTML = '<div class="mv-lbl">Deposit tersimpan</div>' + rows.map(d =>
            '<div class="dep-item"><div><b>' + escHtml(depText(d)) + '</b><small>' + escHtml(d.created_at.slice(0, 16)) + (d.received_by ? ' · ' + escHtml(d.received_by) : '') + '</small></div>' +
            '<button type="button" class="dep-print" onclick="printDeposit(' + d.id + ')">Print</button>' +
            '<button type="button" class="dep-del" onclick="deleteDeposit(' + d.id + ')" title="Hapus">&times;</button></div>').join('');
        list.style.display = '';
    }

    function reloadDepList() {
        if (!currentPaymentBooking) return;
        fetch('../../api/booking-deposit.php?action=list&booking_id=' + encodeURIComponent(currentPaymentBooking.id))
            .then(r => r.json()).then(res => renderDepList((res && res.data) || [])).catch(() => {});
    }

    let depEditId = 0;
    window.editDeposit = function(id) {
        const d = (window._spDeps || {})[id];
        if (d) openDepositModal(d);
    };

    window.openDepositModal = function(edit) {
        const b = currentPaymentBooking;
        if (!b) return;
        depEditId = edit && edit.id ? edit.id : 0;
        document.getElementById('depSub').textContent = (b.guest_name || '-') + ' · ' + (b.booking_code || '') + (b.room_number ? ' · Room ' + b.room_number : '');
        document.getElementById('depAmount').value = '500.000';
        document.getElementById('depIdType').value = 'KTP';
        document.getElementById('depIdNo').value = '';
        document.getElementById('depNotes').value = '';
        document.getElementById('depErr').style.display = 'none';
        document.getElementById('depList').style.display = 'none';
        setDepType('cash');
        if (depEditId) {
            setDepType(edit.deposit_type === 'id_card' ? 'id_card' : 'cash');
            document.getElementById('depAmount').value = edit.deposit_type === 'cash' ? mvMoneyFmt(edit.amount) : '500.000';
            document.getElementById('depIdType').value = edit.id_type || 'KTP';
            document.getElementById('depIdNo').value = edit.id_number || '';
            document.getElementById('depNotes').value = edit.notes || '';
        }
        document.querySelector('#depositModal .mv-title').textContent = depEditId ? 'Edit Deposit' : 'Tanda Terima Deposit';
        document.getElementById('depSave').textContent = depEditId ? 'Simpan Perubahan' : 'Simpan';
        document.getElementById('depositModal').classList.add('active');
        if (depEditId) document.getElementById('depList').style.display = 'none'; else reloadDepList();
    };

    window.closeDepositModal = function() {
        document.getElementById('depositModal').classList.remove('active');
    };

    // Simpan saja (tanpa cetak); print=true → langsung buka tanda terima untuk dicetak
    window.saveDeposit = function(print) {
        print = print === true;
        const b = currentPaymentBooking;
        if (!b) return;
        const btn = document.getElementById('depSave');
        const err = document.getElementById('depErr');
        const fd = new FormData();
        fd.append('action', depEditId ? 'update' : 'save');
        fd.append('booking_id', b.id);
        if (depEditId) fd.append('id', depEditId);
        fd.append('deposit_type', depType);
        fd.append('amount', mvMoneyVal(document.getElementById('depAmount')));
        fd.append('id_type', document.getElementById('depIdType').value);
        fd.append('id_number', document.getElementById('depIdNo').value.trim());
        fd.append('notes', document.getElementById('depNotes').value.trim());
        btn.disabled = true;
        document.getElementById('depSavePrint').disabled = true;
        // Tab cetak dibuka saat klik (agar tidak diblokir popup blocker), diarahkan setelah tersimpan
        const win = print ? window.open('', '_blank') : null;
        fetch('../../api/booking-deposit.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                btn.disabled = false;
                document.getElementById('depSavePrint').disabled = false;
                if (!res.success) {
                    if (win) win.close();
                    err.textContent = res.message || 'Gagal menyimpan';
                    err.style.display = '';
                    return;
                }
                closeDepositModal();
                if (print) {
                    const url = 'deposit-receipt.php?id=' + encodeURIComponent(res.id) + '&autoprint=1';
                    if (win) win.location.href = url; else printDeposit(res.id);
                }
                loadSpDeposits(b.id);
                if (typeof showToast === 'function') showToast(print ? 'Deposit tersimpan, membuka tanda terima' : 'Deposit tersimpan. Cetak kapan saja lewat tombol Print di bagian Deposit diterima.', 'success');
            })
            .catch(() => {
                btn.disabled = false;
                document.getElementById('depSavePrint').disabled = false;
                if (win) win.close();
                err.textContent = 'Gagal menghubungi server';
                err.style.display = '';
            });
    };

    window.deleteDeposit = async function(id) {
        if (typeof dgConfirm === 'function' && !(await dgConfirm('Hapus data deposit ini? Pastikan deposit memang sudah dikembalikan atau salah input.', 'Hapus', true))) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);
        fetch('../../api/booking-deposit.php', { method: 'POST', body: fd })
            .then(() => { reloadDepList(); if (currentPaymentBooking) loadSpDeposits(currentPaymentBooking.id); });
    };

    window.switchSPTab = function switchSPTab(tab) {
        document.querySelectorAll('.sp-tab').forEach(function(t) {
            t.classList.remove('active');
        });
        document.querySelectorAll('.sp-tab-content').forEach(function(c) {
            c.classList.remove('active');
        });
        document.querySelector('.sp-tab[onclick*="' + tab + '"]').classList.add('active');
        document.getElementById('sp-tab-' + tab).classList.add('active');
    }

    window.closeBookingQuickView = function closeBookingQuickView() {
        const modal = document.getElementById('bookingQuickView');
        modal.classList.remove('active');
    }

    window.showBookingDetailsModal = function showBookingDetailsModal(booking) {
        const modal = document.getElementById('bookingDetailsModal');
        currentPaymentBooking = booking;

        // Populate modal with booking data
        document.getElementById('detailGuestName').textContent = booking.guest_name;
        document.getElementById('detailGuestPhone').textContent = booking.guest_phone || '-';
        document.getElementById('detailGuestEmail').textContent = booking.guest_email || '-';
        document.getElementById('detailRoomNumber').textContent = booking.room_number;
        document.getElementById('detailRoomType').textContent = booking.room_type;
        document.getElementById('detailCheckIn').textContent = formatDateFull(booking.check_in_date);
        document.getElementById('detailCheckOut').textContent = formatDateFull(booking.check_out_date);
        document.getElementById('detailNights').textContent = booking.total_nights + ' night(s)';
        document.getElementById('detailBookingCode').textContent = booking.booking_code;
        document.getElementById('detailPaymentStatus').textContent = booking.payment_status.toUpperCase();
        document.getElementById('detailPaymentStatus').className = 'status-badge status-' + booking.payment_status;
        document.getElementById('detailBookingStatus').textContent = booking.status.toUpperCase().replace('_', ' ');
        document.getElementById('detailBookingStatus').className = 'status-badge status-' + booking.status;
        document.getElementById('detailTotalPrice').textContent = IS_STAFF_VIEW ? '-' : ('Rp ' + formatNumberIDR(booking.final_price));

        // Set booking ID for action buttons
        modal.dataset.bookingId = booking.id;
        modal.dataset.bookingStatus = booking.status;
        modal.dataset.paymentStatus = booking.payment_status;

        // Show/hide action buttons based on status
        updateActionButtons(booking.status, booking.payment_status);

        modal.classList.add('active');
    }

    function closeBookingDetailsModal() {
        document.getElementById('bookingDetailsModal').classList.remove('active');
    }

    function formatDateFull(dateStr) {
        const date = new Date(dateStr);
        return date.toLocaleDateString('id-ID', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    }

    function formatNumberIDR(num) {
        return new Intl.NumberFormat('id-ID').format(num);
    }

    function updateActionButtons(status, paymentStatus) {
        const checkInBtn = document.getElementById('btnCheckIn');
        const checkOutBtn = document.getElementById('btnCheckOut');
        const moveBtn = document.getElementById('btnMove');
        const payBtn = document.getElementById('btnPay');

        // Show/hide buttons based on status
        if (status === 'confirmed' || status === 'pending') {
            checkInBtn.style.display = 'flex';
            checkOutBtn.style.display = 'none';
            moveBtn.style.display = 'flex';
        } else if (status === 'checked_in') {
            checkInBtn.style.display = 'none';
            checkOutBtn.style.display = 'flex';
            moveBtn.style.display = 'flex';
        } else {
            checkInBtn.style.display = 'none';
            checkOutBtn.style.display = 'none';
            moveBtn.style.display = 'none';
        }

        // Show Pay button if unpaid or partial
        if (paymentStatus === 'unpaid' || paymentStatus === 'partial') {
            payBtn.style.display = 'flex';
        } else {
            payBtn.style.display = 'none';
        }
    }

    function doCheckIn() {
        const modal = document.getElementById('bookingDetailsModal');
        const bookingId = modal.dataset.bookingId;
        const guestName = document.getElementById('detailGuestName').textContent;
        const roomNumber = document.getElementById('detailRoomNumber').textContent;
        const paymentStatus = modal.dataset.paymentStatus;

        // Detect OTA booking - skip payment, langsung check-in
        const b = currentPaymentBooking;
        if (b) {
            const otaSources = (typeof OTA_SOURCE_KEYS !== 'undefined' && OTA_SOURCE_KEYS.length > 0) ?
                OTA_SOURCE_KEYS : ['ota', 'agoda', 'booking', 'tiket', 'traveloka', 'airbnb', 'expedia', 'pegipegi'];
            const rawSource = (b.booking_source || '').trim();
            const bookingSource = rawSource.toLowerCase().replace(/\.com|\.co\.id|\.id/g, '').replace(/[^a-z0-9]/g, '');
            const isOTA = rawSource && (
                otaSources.includes(rawSource) ||
                otaSources.includes(rawSource.toLowerCase()) ||
                otaSources.some(s => bookingSource.includes(s) || s.includes(bookingSource))
            );

            if (isOTA) {
                const total = parseFloat(b.final_price) || 0;
                const sourceLabel = rawSource.charAt(0).toUpperCase() + rawSource.slice(1);
                const feePercent = (typeof OTA_FEES !== 'undefined' && OTA_FEES[rawSource]) ? OTA_FEES[rawSource] : 0;
                const feeAmount = Math.round(total * feePercent / 100);
                const netAmount = total - feeAmount;
                let feeInfo = '';
                if (feePercent > 0) {
                    feeInfo = `\n\n💰 Total: Rp ${total.toLocaleString('id-ID')}\n📉 Fee OTA ${sourceLabel} (${feePercent}%): -Rp ${feeAmount.toLocaleString('id-ID')}\n✅ Masuk Kas: Rp ${netAmount.toLocaleString('id-ID')}`;
                } else {
                    feeInfo = `\n\nRp ${total.toLocaleString('id-ID')} akan otomatis masuk ke Kas.`;
                }
                if (!confirm(`🏨 Booking via OTA ${sourceLabel}\n\nTamu: ${guestName}\nRoom: ${roomNumber}${feeInfo}\n\nLanjutkan Check-in?`)) return;

                // OTA: langsung check-in tanpa payment, cashbook otomatis di backend
                const checkInBtn = document.getElementById('btnCheckIn');
                const originalText = checkInBtn.innerHTML;
                checkInBtn.innerHTML = '<span>⏳</span><span>Processing...</span>';
                checkInBtn.disabled = true;

                fetch('<?php echo BASE_URL; ?>/api/checkin-guest.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        credentials: 'include',
                        body: 'booking_id=' + bookingId + '&pay_now=0&create_invoice=0'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('✅ ' + data.message);
                            saveScrollAndReload();
                        } else {
                            alert('❌ Error: ' + data.message);
                            checkInBtn.innerHTML = originalText;
                            checkInBtn.disabled = false;
                        }
                    })
                    .catch(error => {
                        alert('❌ Terjadi kesalahan: ' + error.message);
                        checkInBtn.innerHTML = originalText;
                        checkInBtn.disabled = false;
                    });
                return;
            }
        }

        if (paymentStatus !== 'paid') {
            const proceed = confirm('Pembayaran belum lunas. Lanjut check-in dan buat invoice sisa?');
            if (!proceed) {
                openBookingPaymentModal();
                return;
            }
        }

        if (confirm(`Check-in ${guestName} ke Room ${roomNumber} sekarang?`)) {
            // Show loading state
            const checkInBtn = document.getElementById('btnCheckIn');
            const originalText = checkInBtn.innerHTML;
            checkInBtn.innerHTML = '<span>⏳</span><span>Processing...</span>';
            checkInBtn.disabled = true;

            // Call check-in API
            const createInvoice = paymentStatus !== 'paid' ? 1 : 0;
            fetch('<?php echo BASE_URL; ?>/api/checkin-guest.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    credentials: 'include', // Important: Send session cookies
                    body: 'booking_id=' + bookingId + '&create_invoice=' + createInvoice
                })
                .then(response => {
                    // Check if response is JSON
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        return response.text().then(text => {
                            console.error('Non-JSON response:', text);
                            throw new Error('Server mengembalikan response non-JSON');
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        if (data.invoice_number) {
                            alert('✅ ' + data.message + '\nInvoice: ' + data.invoice_number);
                        } else {
                            alert('✅ ' + data.message);
                        }
                        // Reload page to reflect changes
                        saveScrollAndReload();
                    } else {
                        alert('❌ Error: ' + data.message);
                        checkInBtn.innerHTML = originalText;
                        checkInBtn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('❌ Terjadi kesalahan sistem: ' + error.message);
                    checkInBtn.innerHTML = originalText;
                    checkInBtn.disabled = false;
                });
        }
    }

    function doCheckOut() {
        const modal = document.getElementById('bookingDetailsModal');
        depositGuard('<?php echo BASE_URL; ?>', modal.dataset.bookingId, document.getElementById('detailGuestName').textContent)
            .then(g => { if (g.ok) doCheckOutRaw(g.shown); });
    }

    function doCheckOutRaw(skipConfirm) {
        const modal = document.getElementById('bookingDetailsModal');
        const bookingId = modal.dataset.bookingId;
        const guestName = document.getElementById('detailGuestName').textContent;
        const roomNumber = document.getElementById('detailRoomNumber').textContent;

        if (skipConfirm || confirm(`Check-out ${guestName} dari Room ${roomNumber} sekarang?`)) {
            // Show loading state
            const checkOutBtn = document.getElementById('btnCheckOut');
            const originalText = checkOutBtn.innerHTML;
            checkOutBtn.innerHTML = '<span>⏳</span><span>Processing...</span>';
            checkOutBtn.disabled = true;

            // Call check-out API
            fetch('<?php echo BASE_URL; ?>/api/checkout-guest.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    credentials: 'include', // Important: Send session cookies
                    body: 'booking_id=' + bookingId
                })
                .then(response => {
                    // Check if response is JSON
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        return response.text().then(text => {
                            console.error('Non-JSON response:', text);
                            throw new Error('Server mengembalikan response non-JSON');
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        alert('✅ ' + data.message);
                        // Reload page to reflect changes
                        saveScrollAndReload();
                    } else {
                        alert('❌ Error: ' + data.message);
                        checkOutBtn.innerHTML = originalText;
                        checkOutBtn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('❌ Terjadi kesalahan sistem: ' + error.message);
                    checkOutBtn.innerHTML = originalText;
                    checkOutBtn.disabled = false;
                });
        }
    }

    function doMoveRoom() {
        const modal = document.getElementById('bookingDetailsModal');
        const bookingId = modal.dataset.bookingId;

        // TODO: Implement move room modal
        alert('Move room feature coming soon for booking #' + bookingId);
    }

    window.doPayment = function doPayment() {
        openBookingPaymentModal();
    }

    window.quickViewCheckIn = function quickViewCheckIn() {
        if (!currentPaymentBooking) {
            alert('Booking data not found');
            return;
        }

        const b = currentPaymentBooking;
        const total = parseFloat(b.final_price) || 0;
        const paid = parseFloat(b.paid_amount) || 0;
        const remaining = Math.max(0, total - paid);

        // Detect OTA booking - use dynamic list from booking_sources table
        const otaSources = (typeof OTA_SOURCE_KEYS !== 'undefined' && OTA_SOURCE_KEYS.length > 0) ?
            OTA_SOURCE_KEYS : ['ota', 'agoda', 'booking', 'tiket', 'traveloka', 'airbnb', 'expedia', 'pegipegi'];
        const rawSource = (b.booking_source || '').trim();
        const bookingSource = rawSource.toLowerCase().replace(/\.com|\.co\.id|\.id/g, '').replace(/[^a-z0-9]/g, '');
        // Check: exact match in OTA list OR fuzzy match (source contains OTA keyword or vice versa)
        // Hotel Collect (seluruh tagihan dibayar langsung ke hotel) diperlakukan seperti booking langsung
        const hotelCollect = total > 0 && (parseFloat(b.direct_amount) || 0) + 0.01 >= total;
        const isOTA = !hotelCollect && rawSource && (
            otaSources.includes(rawSource) ||
            otaSources.includes(rawSource.toLowerCase()) ||
            otaSources.some(s => bookingSource.includes(s) || s.includes(bookingSource))
        );

        console.log('OTA Detection:', {
            rawSource,
            bookingSource,
            otaSources,
            isOTA
        });

        // OTA booking: sudah dibayar via OTA, langsung check-in (uang masuk kas bank otomatis)
        if (isOTA) {
            const sourceLabel = rawSource.charAt(0).toUpperCase() + rawSource.slice(1);
            // Get OTA fee percentage for display
            const feePercent = (typeof OTA_FEES !== 'undefined' && OTA_FEES[rawSource]) ? OTA_FEES[rawSource] : 0;
            const feeAmount = Math.round(total * feePercent / 100);
            const netAmount = total - feeAmount;
            let feeInfo = '';
            if (feePercent > 0) {
                feeInfo = `\n\n💰 Total: Rp ${total.toLocaleString('id-ID')}\n📉 Fee OTA ${sourceLabel} (${feePercent}%): -Rp ${feeAmount.toLocaleString('id-ID')}\n✅ Masuk Kas Bank: Rp ${netAmount.toLocaleString('id-ID')}`;
            } else {
                feeInfo = `\n\nRp ${total.toLocaleString('id-ID')} akan otomatis masuk ke Kas Bank.`;
            }
            if (!confirm(`🏨 Booking via OTA ${sourceLabel}\n\nTamu: ${b.guest_name}\nRoom: ${b.room_number}${feeInfo}\n\nLanjutkan Check-in?`)) return;
            performCheckin(0, null, false);
            return;
        }

        // Jika sudah lunas, langsung konfirmasi check-in
        if (remaining <= 0) {
            if (!confirm(`💳 Tagihan LUNAS\n\nCheck-in ${b.guest_name} ke Room ${b.room_number}?`)) return;
            performCheckin(0, null, false);
            return;
        }

        // Belum lunas tetap boleh check-in langsung, tanpa modal/reminder pembayaran di sini —
        // status belum lunas otomatis muncul di banner notifikasi & badge sidebar Front Desk.
        if (!confirm(`Check-in ${b.guest_name} ke Room ${b.room_number}?`)) return;
        performCheckin(0, null, false);
    }

    function performCheckin(payAmount, payMethod, payNow) {
        const booking = currentPaymentBooking;
        const btn = document.querySelector('.qv-checkin-btn');
        if (btn) {
            btn.innerHTML = '⏳ Processing...';
            btn.disabled = true;
        }

        let body = 'booking_id=' + booking.id + '&create_invoice=0';
        if (payNow && payAmount > 0) {
            body += '&pay_now=1&pay_amount=' + payAmount + '&pay_method=' + encodeURIComponent(payMethod);
        } else {
            body += '&pay_now=0';
        }

        fetch('<?php echo BASE_URL; ?>/api/checkin-guest.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                credentials: 'include',
                body: body
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ' + data.message);
                    closeBookingQuickView();
                    saveScrollAndReload();
                } else {
                    alert('❌ Error: ' + data.message);
                    if (btn) {
                        btn.innerHTML = 'Check-in';
                        btn.disabled = false;
                document.getElementById('depSavePrint').disabled = false;
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('❌ Terjadi kesalahan: ' + error.message);
                if (btn) {
                    btn.innerHTML = 'Check-in';
                    btn.disabled = false;
                document.getElementById('depSavePrint').disabled = false;
                }
            });
    }

    window.quickViewDeleteBooking = function quickViewDeleteBooking() {
        if (!currentPaymentBooking) return;
        const b = currentPaymentBooking;

        if (!confirm(`⚠️ HAPUS RESERVASI\n\nTamu: ${b.guest_name}\nRoom: ${b.room_number}\nBooking: ${b.booking_code}\n\nReservasi ini akan dihapus permanen.\nLanjutkan?`)) return;

        fetch('<?php echo BASE_URL; ?>/api/delete-booking.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'include',
                body: JSON.stringify({
                    booking_id: b.id
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Reservasi berhasil dihapus');
                    closeBookingQuickView();
                    saveScrollAndReload();
                } else {
                    alert('❌ ' + data.message);
                }
            })
            .catch(err => {
                alert('❌ Error: ' + err.message);
            });
    }

    // Check-out: ingatkan bila masih ada deposit (KTP / uang) yang belum dikembalikan
    window.quickViewCheckOut = function quickViewCheckOut() {
        if (!currentPaymentBooking) return;
        const b = currentPaymentBooking;
        depositGuard('<?php echo BASE_URL; ?>', b.id, b.guest_name).then(g => { if (g.ok) quickViewCheckOutRaw(g.shown); });
    };

    window.quickViewCheckOutRaw = function quickViewCheckOutRaw(skipConfirm) {
        if (!currentPaymentBooking) {
            alert('Booking data not found');
            return;
        }

        const booking = currentPaymentBooking;
        const guestName = booking.guest_name;
        const roomNumber = booking.room_number;

        // Belum lunas tetap boleh check-out; status belum lunas sudah tampil di banner
        // notifikasi & badge sidebar Front Desk, tidak perlu reminder tambahan di sini.
        if (skipConfirm || confirm(`Check-out ${guestName} dari Room ${roomNumber} sekarang?`)) {
            // Show loading state
            const checkOutBtn = document.querySelector('.qv-checkout-btn');
            let originalText = 'Check-out';

            if (checkOutBtn) {
                originalText = checkOutBtn.innerHTML;
                checkOutBtn.innerHTML = 'Processing...';
                checkOutBtn.disabled = true;
            }

            // Call check-out API
            fetch('<?php echo BASE_URL; ?>/api/checkout-guest.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    credentials: 'include',
                    body: 'booking_id=' + booking.id
                })
                .then(response => {
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        return response.text().then(text => {
                            console.error('Non-JSON response:', text);
                            throw new Error('Server mengembalikan response non-JSON');
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        alert('✅ ' + data.message);
                        closeBookingQuickView();
                        saveScrollAndReload();
                    } else {
                        alert('❌ Error: ' + data.message);
                        if (checkOutBtn) {
                            checkOutBtn.innerHTML = originalText;
                            checkOutBtn.disabled = false;
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('❌ Terjadi kesalahan sistem: ' + error.message);
                    if (checkOutBtn) {
                        checkOutBtn.innerHTML = originalText;
                        checkOutBtn.disabled = false;
                    }
                });
        }
    }

    window.quickViewMoveRoom = function quickViewMoveRoom() {
        if (!currentPaymentBooking) {
            alert('Booking data not found');
            return;
        }

        const b = currentPaymentBooking;
        closeBookingQuickView();
        openMoveModal({ bookingId: b.id, guest: b.guest_name, code: b.booking_code, status: b.status, roomId: b.room_id, checkIn: String(b.check_in_date).slice(0, 10), checkOut: String(b.check_out_date).slice(0, 10) });
    }

    window.openBookingPaymentModal = function openBookingPaymentModal() {
        if (!currentPaymentBooking) {
            alert('Booking data tidak ditemukan. Silakan buka detail booking lagi.');
            return;
        }

        const isGroup = !!(currentPaymentBooking.is_group_booking && currentPaymentBooking.group_bookings && currentPaymentBooking.group_bookings.length > 1);
        const total = isGroup ? (parseFloat(currentPaymentBooking.combined_final_price) || 0) : (parseFloat(currentPaymentBooking.final_price) || 0);
        const paid = isGroup ? (parseFloat(currentPaymentBooking.combined_paid_amount) || 0) : (parseFloat(currentPaymentBooking.paid_amount) || 0);
        const remaining = Math.max(0, total - paid);

        document.getElementById('paymentTotal').textContent = 'Rp ' + total.toLocaleString('id-ID');
        document.getElementById('paymentPaid').textContent = 'Rp ' + paid.toLocaleString('id-ID');
        document.getElementById('paymentRemaining').textContent = 'Rp ' + remaining.toLocaleString('id-ID');
        document.getElementById('paymentAmount').value = remaining;
        document.getElementById('paymentModalSubtitle').textContent = currentPaymentBooking.booking_code + ' • ' + (currentPaymentBooking.guest_name || '-');

        // Metode bayar mengikuti sumber booking: OTA hanya untuk booking OTA, booking direct tanpa OTA.
        const src = String(currentPaymentBooking.booking_source || '').toLowerCase();
        const isOta = (typeof OTA_SOURCE_KEYS !== 'undefined' && OTA_SOURCE_KEYS.indexOf(src) > -1) || (parseFloat((OTA_FEES || {})[src]) > 0);
        const srcName = ((typeof SOURCE_NAMES !== 'undefined' && SOURCE_NAMES[src]) || src || 'Direct').trim();
        const methodInput = document.getElementById('paymentMethodPay');
        const methodButtons = document.querySelectorAll('#bookingPaymentModal .payment-method-btn');
        // Booking OTA dengan selisih upgrade / extend: bagian itu dibayar tamu langsung (cash/transfer/QRIS, tanpa fee)
        const directDue = isGroup ? 0 : (parseFloat(currentPaymentBooking.direct_amount) || 0);
        const allowDirect = isOta && directDue > 0;
        methodButtons.forEach(btn => {
            const ota = btn.dataset.value === 'ota';
            btn.style.display = (allowDirect || (isOta ? ota : !ota)) ? '' : 'none';
            btn.classList.toggle('active', isOta ? ota : btn.dataset.value === 'cash');
        });
        if (methodInput) methodInput.value = isOta ? 'ota' : 'cash';
        payOtaCtx = isOta ? { pct: parseFloat((OTA_FEES || {})[src]) || 0, name: srcName, status: currentPaymentBooking.status, direct: directDue, remaining: remaining } : null;
        document.getElementById('paySrcInfo').innerHTML = isOta ?
            '<b>Booking via ' + escHtml(srcName) + '</b> · dibayar oleh OTA' + (payOtaCtx.pct ? ' · fee ' + payOtaCtx.pct + '%' : '') +
            (allowDirect ? '<br><b>Selisih upgrade/extend Rp ' + Math.round(directDue).toLocaleString('id-ID') + '</b> dibayar langsung oleh tamu (Cash/Transfer/QRIS, tanpa fee)' : '') :
            '<b>Booking ' + escHtml(srcName) + '</b> · pembayaran langsung dari tamu';
        document.getElementById('paySrcInfo').className = 'pay-src ' + (isOta ? 'ota' : 'direct');
        updatePayOtaNet();

        const modal = document.getElementById('bookingPaymentModal');
        modal.classList.add('active');
        modal.style.display = 'flex';
        modal.style.position = 'fixed';
        modal.style.zIndex = '99999';
        modal.style.top = '0';
        modal.style.left = '0';
        modal.style.right = '0';
        modal.style.bottom = '0';
        modal.style.alignItems = 'center';
        modal.style.justifyContent = 'center';
    }

    var payOtaCtx = null;
    var payNeedsReload = false;

    // Ringkasan uang masuk untuk booking OTA: bruto (dibayar OTA) − fee = NET masuk buku kas.
    window.updatePayOtaNet = function updatePayOtaNet() {
        const box = document.getElementById('payOtaNet');
        if (!box) return;
        const mth = (document.getElementById('paymentMethodPay') || {}).value || '';
        if (!payOtaCtx || (mth !== 'ota' && mth.indexOf('ota_') !== 0)) { box.style.display = 'none'; return; }
        const gross = parseFloat(document.getElementById('paymentAmount').value) || 0;
        const fee = Math.round(gross * payOtaCtx.pct / 100);
        const f = v => 'Rp ' + Math.round(v).toLocaleString('id-ID');
        const when = (payOtaCtx.status === 'checked_in' || payOtaCtx.status === 'checked_out') ? 'masuk buku kas sekarang' : 'masuk buku kas saat check-in';
        box.innerHTML = '<div><span>Dibayar OTA (bruto)</span><b>' + f(gross) + '</b></div>' +
            '<div><span>Fee ' + escHtml(payOtaCtx.name) + ' (' + payOtaCtx.pct + '%)</span><b class="neg">- ' + f(fee) + '</b></div>' +
            '<div class="net"><span>Net ' + when + '</span><b>' + f(gross - fee) + '</b></div>';
        box.style.display = '';
    };

    window.closeBookingPaymentModal = function closeBookingPaymentModal() {
        const modal = document.getElementById('bookingPaymentModal');
        modal.classList.remove('active');
        modal.style.display = '';
        modal.style.position = '';
        modal.style.zIndex = '';
    }

    window.submitBookingPayment = function submitBookingPayment() {
        if (!currentPaymentBooking) return;

        const amount = parseFloat(document.getElementById('paymentAmount').value) || 0;
        const method = document.getElementById('paymentMethodPay').value || 'cash';

        if (amount <= 0) {
            alert('Jumlah bayar harus lebih dari 0');
            return;
        }

        // Cegah klik ganda: abaikan klik berikutnya selama request pertama belum selesai.
        if (submitBookingPayment.busy) return;
        submitBookingPayment.busy = true;

        fetch('<?php echo BASE_URL; ?>/api/add-booking-payment.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                credentials: 'include',
                body: 'booking_id=' + encodeURIComponent(currentPaymentBooking.id) +
                    '&amount=' + encodeURIComponent(amount) +
                    '&payment_method=' + encodeURIComponent(method)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (typeof editResOk === 'function') {
                        editResOk(data.payment_status === 'paid' ? 'Pembayaran lunas' : 'Pembayaran tersimpan');
                    } else {
                        alert(data.message || 'Payment saved');
                    }
                    payNeedsReload = true;

                    closeBookingPaymentModal();
                    // Refresh booking details
                    return fetch('../../api/get-booking-details.php?id=' + currentPaymentBooking.id)
                        .then(res => res.json())
                        .then(updated => {
                            if (updated.success) {
                                showBookingQuickView(updated.booking);
                                const detailsModal = document.getElementById('bookingDetailsModal');
                                if (detailsModal && detailsModal.classList.contains('active')) {
                                    showBookingDetailsModal(updated.booking);
                                }
                            }
                        });
                } else {
                    alert('Error: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(err => {
                console.error(err);
                alert('Gagal menyimpan pembayaran');
            })
            .finally(() => {
                submitBookingPayment.busy = false;
                if (payNeedsReload) {
                    payNeedsReload = false;
                    setTimeout(() => saveScrollAndReload(), 1400);
                }
            });
    }

    window.changeDate = function changeDate() {
        const dateInput = document.getElementById('dateInput');
        if (!dateInput) return;
        window.location.search = '?start=' + dateInput.value;
    }

    window.openNewReservationForm = function openNewReservationForm() {
        // Open reservation modal with today's date
        const modal = document.getElementById('reservationModal');

        // Reset Form First
        const form = document.getElementById('reservationForm');
        if (form) form.reset();

        const checkInInput = document.getElementById('checkInDate');
        const checkOutInput = document.getElementById('checkOutDate');

        // Set default dates (today and tomorrow)
        const today = new Date();
        const tomorrow = new Date(today);
        tomorrow.setDate(tomorrow.getDate() + 1);

        if (checkInInput) checkInInput.value = window.fdLocalYmd(today);
        if (checkOutInput) checkOutInput.value = window.fdLocalYmd(tomorrow);

        const modeEl = document.getElementById('reservationMode');
        if (modeEl) modeEl.value = 'reservation';
        setReservationMode('reservation');

        // Load available rooms for default dates
        loadAvailableRoomsCalendar();

        // Reset payment method class
        document.querySelectorAll('#reservationModal .pm-item').forEach(d => d.classList.remove('active'));
        // Set cash active
        const cashBtn = document.querySelector('#reservationModal .pm-item:first-child');
        if (cashBtn) {
            cashBtn.classList.add('active');
            document.getElementById('paymentMethod').value = 'cash';
        }

        // Show Modal
        if (modal) {
            modal.classList.add('active');
        }
    }

    // Store clicked roomId for auto-selection after rooms load
    let pendingRoomSelection = null;

    // ========================================
    // CLOUDBED-STYLE TWO-CLICK BOOKING
    // Click 1: set check-in date + room (highlight cell)
    // Click 2: set check-out date → open reservation form
    // ========================================
    let firstClick = null; // {date, roomId, element}

    // Tandai kamar dirty → bersih (housekeeping)
    window.markRoomClean = async function markRoomClean(roomId, roomNumber) {
        if (!confirm(`Tandai Room ${roomNumber || roomId} sudah BERSIH?`)) return;
        try {
            const fd = new FormData();
            fd.append('room_id', roomId);
            const res = await fetch('<?php echo BASE_URL; ?>/api/mark-room-clean.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (data && data.success) {
                location.reload();
            } else {
                alert(data && data.message ? data.message : 'Gagal menandai bersih');
            }
        } catch (e) {
            alert('Gagal menghubungi server');
        }
    };

    window.openCellReservation = function openCellReservation(element) {
        const date = element.getAttribute('data-date');
        const roomId = element.getAttribute('data-room-id');
        const roomNumber = element.getAttribute('data-room-number');

        // If first click exists and same room → set checkout
        if (firstClick && firstClick.roomId === roomId) {
            let checkInDate = firstClick.date;
            let checkOutDate = date;

            // If clicked same date or earlier, reset
            if (checkOutDate <= checkInDate) {
                clearFirstClick();
                return;
            }

            // Remove highlight
            clearFirstClick();

            // Open reservation form with both dates
            pendingRoomSelection = roomId;
            const modal = document.getElementById('reservationModal');
            const form = document.getElementById('reservationForm');
            if (form) form.reset();

            const checkInInput = document.getElementById('checkInDate');
            const checkOutInput = document.getElementById('checkOutDate');
            if (checkInInput) checkInInput.value = checkInDate;
            if (checkOutInput) checkOutInput.value = checkOutDate;

            const modeEl = document.getElementById('reservationMode');
            if (modeEl) modeEl.value = 'reservation';
            setReservationMode('reservation');

            loadAvailableRoomsCalendar();
            if (typeof updateSourceDetails === 'function') updateSourceDetails();

            document.querySelectorAll('#reservationModal .pm-item').forEach(d => d.classList.remove('active'));
            const cashBtn = document.querySelector('#reservationModal .pm-item:first-child');
            if (cashBtn) {
                cashBtn.classList.add('active');
                document.getElementById('paymentMethod').value = 'cash';
            }

            if (modal) modal.classList.add('active');
            return;
        }

        // If clicking different room or no first click → set as first click
        clearFirstClick();
        firstClick = {
            date,
            roomId,
            element
        };

        // Tanda check-in: pill putus-putus dari tengah sel (bukan kotak penuh).
        const pill = document.createElement('div');
        pill.className = 'fc-preview';
        pill.textContent = 'Check-in';
        element.appendChild(pill);
        firstClick.pill = pill;
        fcResize(element);

        // Show tooltip
        showClickHint(element, roomNumber, date);
    }

    // Lebar pill: dari tengah sel check-in sampai tengah sel yang disorot (minimal setengah sel).
    function fcResize(target) {
        if (!firstClick || !firstClick.pill) return;
        const a = firstClick.element.getBoundingClientRect();
        let w = a.width / 2 - 3;
        let nights = 0;
        if (target && target !== firstClick.element && target.getAttribute('data-room-id') === firstClick.roomId &&
            target.getAttribute('data-date') > firstClick.date) {
            const b = target.getBoundingClientRect();
            w = (b.left + b.width / 2) - (a.left + a.width / 2) - 3;
            nights = Math.round((new Date(target.getAttribute('data-date')) - new Date(firstClick.date)) / 86400000);
        }
        firstClick.pill.style.width = Math.max(18, w) + 'px';
        firstClick.pill.textContent = nights > 0 ? nights + ' malam' : 'Check-in';
    }

    document.addEventListener('mouseover', e => {
        if (!firstClick) return;
        const cell = e.target.closest && e.target.closest('.grid-date-cell');
        if (cell) fcResize(cell);
    });

    function clearFirstClick() {
        if (firstClick && firstClick.pill) firstClick.pill.remove();
        firstClick = null;
        // Remove hint
        const hint = document.getElementById('clickBookingHint');
        if (hint) hint.remove();
    }

    function showClickHint(element, roomNumber, date) {
        // Remove old hint
        const old = document.getElementById('clickBookingHint');
        if (old) old.remove();

        const hint = document.createElement('div');
        hint.id = 'clickBookingHint';
        hint.innerHTML = '<span class="cbh-ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span>' +
            '<div><b class="cbh-title">Check-in ' + formatDateShort(date) + ' · Room ' + roomNumber + '</b>' +
            '<small>Klik tanggal check-out untuk membuat reservasi · <kbd>Esc</kbd> batal</small></div>';
        document.body.appendChild(hint);

        // Auto-dismiss after 8s
        setTimeout(() => {
            if (hint.parentNode) hint.remove();
        }, 8000);
    }

    function formatDateShort(dateStr) {
        const d = new Date(dateStr);
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];
        return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
    }

    window.closeReservationModal = function() {
        const modal = document.getElementById('reservationModal');
        if (modal) modal.classList.remove('active');
        clearFirstClick();
    }

    // Escape key clears first click selection
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && firstClick) {
            clearFirstClick();
        }
    });

    window.updateRoomPrice = function() {
        const select = document.getElementById('roomSelect');
        const priceInput = document.getElementById('roomPrice');
        if (select && priceInput) {
            const option = select.options[select.selectedIndex];
            if (option) {
                priceInput.value = option.getAttribute('data-price') || 0;
                calculateFinalPrice();
            }
        }
    }

    window.updateStayDetails = function() {
        const checkInEl = document.getElementById('checkInDate');
        const checkOutEl = document.getElementById('checkOutDate');
        if (!checkInEl || !checkOutEl) return;

        const checkIn = new Date(checkInEl.value);
        const checkOut = new Date(checkOutEl.value);

        if (checkIn && checkOut && checkOut > checkIn) {
            const diffTime = Math.abs(checkOut - checkIn);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            document.getElementById('totalNights').value = diffDays;

            const display = document.getElementById('displayNights');
            if (display) display.innerText = diffDays;

            calculateFinalPrice();
        } else {
            document.getElementById('totalNights').value = 0;
            const display = document.getElementById('displayNights');
            if (display) display.innerText = 0;
        }
    }

    window.updateSourceDetails = function() {
        const sourceSelect = document.getElementById('bookingSource');
        const feeDisplay = document.getElementById('otaFeeDisplay');
        const feeRow = document.getElementById('feeRow');
        currentSource = sourceSelect ? sourceSelect.value : '';

        // Default Fees Map (Fallback)
        // Matches the values in the HTML optgroup
        currentFees = (typeof OTA_FEES !== 'undefined') ? OTA_FEES : {
            'agoda': 15,
            'booking': 12,
            'tiket': 10,
            'traveloka': 15,
            'airbnb': 3,
            'ota': 10
        };

        let feePercent = currentFees[currentSource] || 0;

        // AUTO-SELECT PAYMENT METHOD LOGIC
        const pmOtaBtn = document.getElementById('pm-ota'); // The new hidden OTA button
        const isOtaSource = (typeof OTA_SOURCE_KEYS !== 'undefined' && OTA_SOURCE_KEYS.length > 0) ?
            OTA_SOURCE_KEYS.includes(currentSource) :
            (!['walk_in', 'phone', 'online'].includes(currentSource) && feePercent > 0);

        const paidAmountInput = document.getElementById('paidAmount');
        const payAllBtn = document.querySelector('.btn-pay-all');
        const pmSelect = document.getElementById('paymentMethod');

        if (isOtaSource) {
            // Source is an OTA: set payment method to ota_<source>
            const otaValue = 'ota_' + currentSource;
            const otaNames = {
                'agoda': 'Agoda',
                'booking': 'Booking.com',
                'tiket': 'Tiket.com',
                'traveloka': 'Traveloka',
                'airbnb': 'Airbnb',
                'ota': 'OTA Lainnya'
            };
            const otaLabel = otaNames[currentSource] || currentSource;

            if (pmSelect) {
                pmSelect.innerHTML = '<option value="' + otaValue + '" selected>OTA ' + otaLabel + '</option>';
                pmSelect.disabled = true;
                pmSelect.style.opacity = '0.7';
            }
            if (pmOtaBtn) {
                pmOtaBtn.style.display = 'flex';
                pmOtaBtn.click();
            }

            // OTA: disable Pay All & paid amount (OTA pays later at check-in)
            if (paidAmountInput) {
                paidAmountInput.value = '';
                paidAmountInput.disabled = true;
                paidAmountInput.style.opacity = '0.5';
            }
            if (payAllBtn) {
                payAllBtn.disabled = true;
                payAllBtn.style.opacity = '0.5';
                payAllBtn.style.cursor = 'not-allowed';
                payAllBtn.title = 'OTA: pembayaran masuk saat check-in';
            }
        } else {
            // Source is NOT an OTA (Direct/Walk-in): restore normal payment options
            if (pmSelect) {
                pmSelect.innerHTML =
                    '<option value="cash">Cash</option>' +
                    '<option value="transfer">Transfer</option>' +
                    '<option value="qris">QRIS</option>';
                pmSelect.disabled = false;
                pmSelect.style.opacity = '1';
            }
            if (pmOtaBtn) {
                pmOtaBtn.style.display = 'none';
            }

            // Direct: enable Pay All & paid amount
            if (paidAmountInput) {
                paidAmountInput.disabled = false;
                paidAmountInput.style.opacity = '1';
            }
            if (payAllBtn) {
                payAllBtn.disabled = false;
                payAllBtn.style.opacity = '1';
                payAllBtn.style.cursor = 'pointer';
                payAllBtn.title = 'Pay Full Amount';
            }
        }

        if (feePercent > 0) {
            if (feeDisplay) {
                feeDisplay.style.display = 'inline-block';
                const pctEl = document.getElementById('otaFeePercent');
                if (pctEl) pctEl.innerText = feePercent;
            }
            if (feeRow) feeRow.style.display = 'flex';
        } else {
            if (feeDisplay) feeDisplay.style.display = 'none';
            if (feeRow) feeRow.style.display = 'none';
        }

        calculateFinalPrice();

        // Also recalculate multi-room total if that modal is open
        if (typeof calculateMultiRoomTotalCalendar === 'function') {
            calculateMultiRoomTotalCalendar();
        }
    }

    window.calculateFinalPrice = function() {
        const nightsEl = document.getElementById('totalNights');
        const priceEl = document.getElementById('roomPrice');
        const discountEl = document.getElementById('discount');

        const nights = parseInt(nightsEl ? nightsEl.value : 0) || 0;
        const price = parseFloat(priceEl ? priceEl.value : 0) || 0;
        const discount = parseFloat(discountEl ? discountEl.value : 0) || 0;

        const total = (nights * price) - discount;
        const final = total > 0 ? total : 0;

        // Update both total_price and final_price hidden fields
        const totalPriceEl = document.getElementById('hiddenTotalPrice');
        if (totalPriceEl) totalPriceEl.value = (nights * price);

        const finalEl = document.getElementById('finalPriceDisplay');
        if (finalEl) finalEl.innerText = 'Rp ' + final.toLocaleString('id-ID');

        const hiddenEl = document.getElementById('hiddenFinalPrice');
        if (hiddenEl) hiddenEl.value = final;

        // OTA Source Logic - Updated to auto-set payment method and full payment
        const feeRow = document.getElementById('feeRow');
        const pmOta = document.getElementById('pm-ota');
        const paymentMethodInput = document.getElementById('paymentMethod');
        const paidAmountInput = document.getElementById('paidAmount');

        if (currentFees[currentSource] && currentFees[currentSource] > 0) {
            if (feeRow) feeRow.style.display = 'flex';
            // Auto select OTA payment for OTA sources
            if (pmOta) {
                pmOta.style.display = 'flex';
                // Trigger click to activate
                if (currentSource !== 'walk_in' && currentSource !== 'phone') {
                    pmOta.click();
                }
            }

            // Auto-fill paid amount with final price for OTA (Assume prepaid to OTA)
            // Check if it IS an OTA source (has fee > 0 and not a direct source)
            const directSrcs = ['walk_in', 'phone', 'online'];
            if (!directSrcs.includes(currentSource)) {
                if (paidAmountInput) paidAmountInput.value = final;

                // Update payment status dropdown logic locally if function exists
                if (typeof updatePaymentStatusFromAmount === 'function') {
                    updatePaymentStatusFromAmount();
                }

                const feeInfo = document.getElementById('otaFeeInfo');
                if (feeInfo) feeInfo.style.display = 'block';
            }

        } else {
            if (feeRow) feeRow.style.display = 'none';
            if (pmOta) pmOta.style.display = 'none';
            const feeInfo = document.getElementById('otaFeeInfo');
            if (feeInfo) feeInfo.style.display = 'none';

            // Revert to cash if OTA was selected but source changed to non-OTA
            if (paymentMethodInput && paymentMethodInput.value === 'ota') {
                const cashBtn = document.querySelector('.pm-item[onclick*="cash"]');
                if (cashBtn) cashBtn.click();
            }
        }
    }

    window.setPaymentMethod = function(method, btn) {
        document.getElementById('paymentMethod').value = method;
        // Handle both old button style and new pm-item style
        document.querySelectorAll('#reservationModal .payment-method-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('#reservationModal .pm-item').forEach(b => b.classList.remove('active'));

        if (btn) btn.classList.add('active');
    }

    window.payFullAmount = function() {
        const hiddenFinalPrice = document.getElementById('hiddenFinalPrice');
        const paidAmount = document.getElementById('paidAmount');

        if (hiddenFinalPrice && paidAmount) {
            const totalAmount = parseFloat(hiddenFinalPrice.value) || 0;
            paidAmount.value = totalAmount;

            // Optional: Show confirmation
            if (totalAmount > 0) {
                const formattedAmount = 'Rp ' + totalAmount.toLocaleString('id-ID');
                console.log('Pay All clicked - Amount set to:', formattedAmount);
            }
        }
    }

    // ============================================
    // MULTI-ROOM BOOKING FUNCTIONS FOR CALENDAR
    // ============================================

    function updateCheckOutMinDateCalendar() {
        const checkInInput = document.getElementById('checkInDate');
        const checkOutInput = document.getElementById('checkOutDate');

        if (checkInInput && checkOutInput && checkInInput.value) {
            // Set min check-out to day after check-in
            const checkInDate = new Date(checkInInput.value);
            checkInDate.setDate(checkInDate.getDate() + 1);
            const minCheckOut = checkInDate.toISOString().split('T')[0];
            checkOutInput.min = minCheckOut;

            // If current check-out is before min, auto-update it
            if (!checkOutInput.value || checkOutInput.value <= checkInInput.value) {
                checkOutInput.value = minCheckOut;
            }
        }
    }

    async function loadAvailableRoomsCalendar() {
        const checkIn = document.getElementById('checkInDate').value;
        const checkOut = document.getElementById('checkOutDate').value;

        // Update min date for check-out
        updateCheckOutMinDateCalendar();

        if (!checkIn || !checkOut) {
            document.getElementById('roomsChecklistCalendar').innerHTML = '<em style="color: #ef4444;">Pilih tanggal check-in dan check-out terlebih dahulu</em>';
            return;
        }

        // Validate dates
        if (new Date(checkOut) <= new Date(checkIn)) {
            document.getElementById('roomsChecklistCalendar').innerHTML = '<em style="color: #ef4444;">❌ Check-out harus minimal 1 hari setelah check-in</em>';
            document.getElementById('availabilityInfoCalendar').innerHTML = '<small style="color: #ef4444;">Invalid dates</small>';
            return;
        }

        // Show loading
        document.getElementById('roomsChecklistCalendar').innerHTML = '<div style="text-align:center; padding: 20px;"><em>Loading available rooms...</em></div>';

        try {
            const mode = document.getElementById('reservationMode')?.value || 'reservation';
            const response = await fetch(`../../api/get-available-rooms.php?check_in=${checkIn}&check_out=${checkOut}&mode=${encodeURIComponent(mode)}`);

            // Check if response is OK
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (result.success && result.rooms.length > 0) {
                let html = '';
                result.rooms.forEach(room => {
                    const roomRateBadge = IS_STAFF_VIEW ?
                        '' :
                        `<span class="nr-price">Rp ${parseInt(room.base_price).toLocaleString('id-ID')}<small style="font-weight:500">/mlm</small>${room.price_source === 'cloudbeds' ? '<small style="display:block;font-size:9px;font-weight:700;color:#0e7490">harga Cloudbeds</small>' : ''}</span>`;
                    html += `
                    <label class="room-checkbox-item">
                        <input type="checkbox" name="rooms[]" value="${room.id}"
                               data-price="${room.base_price}"
                               data-room="${room.room_number}"
                               data-type="${room.type_name}"
                               onchange="calculateMultiRoomTotalCalendar()">
                        <span class="nr-room"><b>Room ${room.room_number}</b><small>${room.type_name}</small></span>
                        ${roomRateBadge}
                    </label>
                `;
                });
                document.getElementById('roomsChecklistCalendar').innerHTML = html;
                const blockedRooms = parseInt(result.blocked_rooms || 0, 10);
                document.getElementById('availabilityInfoCalendar').innerHTML = `<small style="color: #10b981;">✅ ${result.available_rooms} room(s) available (${result.booked_rooms} booked${blockedRooms > 0 ? `, ${blockedRooms} blocked` : ''})</small>`;

                // Auto-select room if clicked from calendar cell
                if (pendingRoomSelection) {
                    const roomCheckbox = document.querySelector(`input[name="rooms[]"][value="${pendingRoomSelection}"]`);
                    if (roomCheckbox) {
                        roomCheckbox.checked = true;
                        roomCheckbox.closest('.room-checkbox-item').style.background = '#dcfce7';
                    }
                    pendingRoomSelection = null; // Clear after use
                    calculateMultiRoomTotalCalendar(); // Update totals
                }
            } else if (result.success && result.rooms.length === 0) {
                document.getElementById('roomsChecklistCalendar').innerHTML = '<em style="color: #ef4444;">❌ Tidak ada room yang tersedia untuk tanggal ini (sudah ter-booking atau sedang diblok)</em>';
                const blockedRooms = parseInt(result.blocked_rooms || 0, 10);
                document.getElementById('availabilityInfoCalendar').innerHTML = `<small style="color: #ef4444;">0 rooms available (${result.booked_rooms} booked${blockedRooms > 0 ? `, ${blockedRooms} blocked` : ''})</small>`;
            } else {
                document.getElementById('roomsChecklistCalendar').innerHTML = '<em style="color: #ef4444;">Error loading rooms: ' + (result.message || 'Unknown error') + '</em>';
            }

            // Recalculate totals
            calculateMultiRoomTotalCalendar();

        } catch (error) {
            console.error('Error loading rooms:', error);
            document.getElementById('roomsChecklistCalendar').innerHTML = '<em style="color: #ef4444;">Error loading rooms. Please try again.</em>';
        }
    }

    function setDiscountTypeCalendar(type) {
        const discountTypeInput = document.getElementById('discountType');
        const discountLabel = document.getElementById('discountTypeLabel');
        const discountInput = document.getElementById('discount');
        const buttons = document.querySelectorAll('.disc-type-btn-cal');

        buttons.forEach(btn => btn.classList.toggle('active', btn.dataset.type === type));

        discountTypeInput.value = type;
        discountLabel.textContent = type === 'percent' ? '%' : 'Rp';

        if (type === 'percent') {
            discountInput.max = 100;
            discountInput.placeholder = '0-100';
        } else {
            discountInput.removeAttribute('max');
            discountInput.placeholder = '0';
        }

        calculateMultiRoomTotalCalendar();
    }

    function calculateMultiRoomTotalCalendar() {
        const checkInStr = document.getElementById('checkInDate').value;
        const checkOutStr = document.getElementById('checkOutDate').value;
        const discountValue = parseFloat(document.getElementById('discount').value) || 0;
        const discountType = document.getElementById('discountType').value;

        if (!checkInStr || !checkOutStr) {
            return;
        }

        const checkIn = new Date(checkInStr);
        const checkOut = new Date(checkOutStr);
        const nights = Math.ceil((checkOut - checkIn) / (1000 * 60 * 60 * 24));

        if (nights <= 0) {
            return;
        }

        const mode = document.getElementById('reservationMode')?.value || 'reservation';

        // Get all checked rooms
        const checkedRooms = document.querySelectorAll('input[name="rooms[]"]:checked');
        const totalRooms = checkedRooms.length;

        if (mode === 'block_room') {
            document.getElementById('totalRoomsDisplayCalendar').textContent = totalRooms + ' room' + (totalRooms !== 1 ? 's' : '');
            document.getElementById('displayNights').textContent = nights + ' night' + (nights !== 1 ? 's' : '');
            document.getElementById('subtotalDisplayCalendar').textContent = '-';
            document.getElementById('grandTotalDisplayCalendar').textContent = '-';
            const otaFeeRow = document.getElementById('otaFeeRow');
            if (otaFeeRow) otaFeeRow.style.display = 'none';
            if (totalRooms > 0) {
                document.getElementById('selectedRoomsSummaryCalendar').innerHTML =
                    '<strong>Diblok:</strong> ' + totalRooms + ' kamar × ' + nights + ' malam';
            } else {
                document.getElementById('selectedRoomsSummaryCalendar').innerHTML = '<em style="color:#b91c1c">Belum ada kamar dipilih</em>';
            }
            return;
        }

        let subtotal = 0;
        let roomDetails = [];

        checkedRooms.forEach(checkbox => {
            const price = parseFloat(checkbox.dataset.price) || 0;
            const roomNumber = checkbox.dataset.room;
            const roomType = checkbox.dataset.type;
            const roomTotal = price * nights;
            subtotal += roomTotal;
            roomDetails.push(`Room ${roomNumber} (${roomType}): Rp ${roomTotal.toLocaleString('id-ID')}`);
        });

        // Calculate discount based on type
        let discountAmount = 0;
        const discountPreview = document.getElementById('discountPreview');

        if (discountType === 'percent') {
            discountAmount = Math.round(subtotal * (discountValue / 100));
            if (discountValue > 0 && subtotal > 0) {
                discountPreview.textContent = `= Rp ${discountAmount.toLocaleString('id-ID')} (${discountValue}% dari ${subtotal.toLocaleString('id-ID')})`;
            } else {
                discountPreview.textContent = '';
            }
        } else {
            discountAmount = discountValue;
            discountPreview.textContent = '';
        }

        // Calculate OTA Fee based on booking source
        const bookingSource = document.getElementById('bookingSource').value;
        const otaFeeRow = document.getElementById('otaFeeRow');
        const otaFeePercentDisplay = document.getElementById('otaFeePercentDisplay');
        const otaFeeAmountDisplay = document.getElementById('otaFeeAmountDisplay');
        const otaFeeAmountInput = document.getElementById('otaFeeAmount');

        let otaFeePercent = 0;
        let otaFeeAmount = 0;

        // Get OTA fee from settings
        if (typeof OTA_FEES !== 'undefined' && OTA_FEES[bookingSource]) {
            otaFeePercent = OTA_FEES[bookingSource];
        }

        if (otaFeePercent > 0 && subtotal > 0) {
            otaFeeAmount = Math.round(subtotal * (otaFeePercent / 100));
            otaFeeRow.style.display = 'flex';
            otaFeePercentDisplay.textContent = otaFeePercent;
            otaFeeAmountDisplay.textContent = '- Rp ' + otaFeeAmount.toLocaleString('id-ID');
            otaFeeAmountInput.value = otaFeeAmount;
        } else {
            otaFeeRow.style.display = 'none';
            otaFeeAmountInput.value = 0;
        }

        const grandTotal = subtotal - discountAmount - otaFeeAmount;

        // Update display
        document.getElementById('totalRoomsDisplayCalendar').textContent = totalRooms + ' room' + (totalRooms !== 1 ? 's' : '');
        document.getElementById('displayNights').textContent = nights + ' night' + (nights !== 1 ? 's' : '');
        document.getElementById('subtotalDisplayCalendar').textContent = 'Rp ' + subtotal.toLocaleString('id-ID');
        document.getElementById('grandTotalDisplayCalendar').textContent = 'Rp ' + grandTotal.toLocaleString('id-ID');

        // Update summary
        if (totalRooms > 0) {
            document.getElementById('selectedRoomsSummaryCalendar').innerHTML =
                '<strong>Dipilih:</strong> ' + totalRooms + ' kamar × ' + nights + ' malam = Rp ' + subtotal.toLocaleString('id-ID');
        } else {
            document.getElementById('selectedRoomsSummaryCalendar').innerHTML = '<em style="color:#b91c1c">Belum ada kamar dipilih</em>';
        }
    }

    function payFullMultiRoomCalendar() {
        const grandTotalText = document.getElementById('grandTotalDisplayCalendar').textContent;
        const grandTotal = parseFloat(grandTotalText.replace(/[^\d]/g, ''));
        document.getElementById('paidAmount').value = grandTotal;
    }

    window.submitReservation = async function(event) {
        event.preventDefault();

        const mode = document.getElementById('reservationMode')?.value || 'reservation';

        // Validate room selection
        const checkedRooms = document.querySelectorAll('input[name="rooms[]"]:checked');
        if (checkedRooms.length === 0) {
            alert('Silakan pilih minimal 1 room!');
            return;
        }

        // DEBUG: Log total rooms selected
        console.log(`[RESERVATION] Total rooms selected: ${checkedRooms.length}`);
        checkedRooms.forEach((cb, idx) => {
            console.log(`  [${idx+1}] Room ${cb.dataset.room} (ID: ${cb.value}, Price: ${cb.dataset.price})`);
        });

        const form = event.target;
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerText;

        if (mode === 'block_room') {
            const checkIn = document.getElementById('checkInDate').value;
            const checkOut = document.getElementById('checkOutDate').value;
            const blockReason = document.getElementById('blockReason')?.value || 'maintenance';
            const blockNotes = document.getElementById('blockNotes')?.value || '';

            if (!checkIn || !checkOut) {
                alert('Tanggal block wajib diisi');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving blocks...';

            let successCount = 0;
            const failed = [];

            for (const checkbox of checkedRooms) {
                const roomId = checkbox.value;
                const roomNumber = checkbox.dataset.room;
                const fd = new FormData();
                fd.append('room_id', roomId);
                fd.append('block_start_date', checkIn);
                fd.append('block_end_date', checkOut);
                fd.append('block_reason', blockReason);
                fd.append('block_notes', blockNotes);

                try {
                    const response = await fetch('<?php echo BASE_URL; ?>/api/create-room-block.php', {
                        method: 'POST',
                        body: fd
                    });
                    const result = await response.json();
                    if (result.success) {
                        successCount++;
                    } else {
                        failed.push(`Room ${roomNumber}: ${result.message || 'Gagal block'}`);
                    }
                } catch (err) {
                    failed.push(`Room ${roomNumber}: Network error`);
                }
            }

            submitBtn.disabled = false;
            submitBtn.textContent = originalText;

            if (successCount > 0) {
                const failText = failed.length ? `\n\n${failed.join('\n')}` : '';
                alert(`✅ ${successCount} room berhasil diblok.${failText}`);
                closeReservationModal();
                const ciDate = document.getElementById('checkInDate')?.value;
                if (ciDate) {
                    sessionStorage.setItem('calendarScrollToDate', ciDate); sessionStorage.setItem('calendarScrollTs', String(Date.now()));
                    location.reload();
                } else {
                    saveScrollAndReload();
                }
            } else {
                alert('❌ Gagal membuat block room.\n' + failed.join('\n'));
            }
            return;
        }

        // Get form data
        const guestName = document.getElementById('guestName').value;
        const guestPhone = document.getElementById('guestPhone').value || '';
        const checkIn = document.getElementById('checkInDate').value;
        const checkOut = document.getElementById('checkOutDate').value;
        let bookingSource = document.getElementById('bookingSource').value;
        const paymentMethod = document.getElementById('paymentMethod').value;
        const discountValue = parseFloat(document.getElementById('discount').value) || 0;
        const discountType = document.getElementById('discountType').value;
        const paidAmount = parseFloat(document.getElementById('paidAmount').value) || 0;
        const adultCount = parseInt(document.getElementById('adultCount').value) || 1;

        // DEBUG: Log form data
        console.log('[RESERVATION] Form Data:', {
            guestName,
            checkIn,
            checkOut,
            bookingSource,
            paymentMethod,
            discountValue,
            discountType,
            paidAmount,
            adultCount
        });

        // VALIDATE: Booking Source MUST be selected
        if (!bookingSource || bookingSource.trim() === '') {
            alert('❌ Silakan pilih Booking Source (Direct/OTA)!');
            return;
        }

        // Calculate nights
        const nights = Math.ceil((new Date(checkOut) - new Date(checkIn)) / (1000 * 60 * 60 * 24));

        // Calculate subtotal first for percentage discount
        let subtotal = 0;
        checkedRooms.forEach(checkbox => {
            const price = parseFloat(checkbox.dataset.price) * nights;
            subtotal += price;
        });

        // Calculate actual discount amount in Rp
        let discount = 0;
        if (discountType === 'percent') {
            discount = Math.round(subtotal * (discountValue / 100));
        } else {
            discount = discountValue;
        }

        // Calculate OTA fee
        let otaFeePercent = 0;
        let otaFeeAmount = 0;
        if (typeof OTA_FEES !== 'undefined' && OTA_FEES[bookingSource]) {
            otaFeePercent = OTA_FEES[bookingSource];
            otaFeeAmount = Math.round(subtotal * (otaFeePercent / 100));
        }

        // Calculate discount per room (distribute equally)
        const discountPerRoom = discount / checkedRooms.length;

        // Calculate payment per room (distribute proportionally)
        // OTA fee is NOT subtracted from final_price - CashbookHelper handles OTA fee deduction
        let totalPrice = 0;
        const roomPrices = [];
        checkedRooms.forEach(checkbox => {
            const price = parseFloat(checkbox.dataset.price) * nights - discountPerRoom;
            roomPrices.push(price);
            totalPrice += price;
        });

        // Disable submit button
        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating bookings...';

        let successCount = 0;
        let errorCount = 0;
        const bookingCodes = [];
        const errorMessages = [];

        // Generate group_id for multi-room bookings
        const groupId = checkedRooms.length > 1 ? 'GRP-' + new Date().toISOString().slice(0, 10).replace(/-/g, '') + '-' + Math.random().toString(36).substr(2, 6).toUpperCase() : '';

        // Create booking for each room
        for (let i = 0; i < checkedRooms.length; i++) {
            const checkbox = checkedRooms[i];
            const roomId = checkbox.value;
            const roomNumber = checkbox.dataset.room;
            const roomPrice = roomPrices[i];

            // Calculate proportional payment
            const proportionalPayment = totalPrice > 0 ? (paidAmount * (roomPrice / totalPrice)) : 0;

            // Create FormData for API
            const roomBasePrice = parseFloat(checkbox.dataset.price);
            const roomTotalPrice = roomBasePrice * nights;
            const roomFinalPrice = roomTotalPrice - discountPerRoom;

            const formData = new FormData();
            if (groupId) formData.append('group_id', groupId);
            formData.append('guest_name', guestName);
            formData.append('guest_phone', guestPhone);
            formData.append('room_id', roomId);
            formData.append('check_in_date', checkIn); // API expects check_in_date
            formData.append('check_out_date', checkOut); // API expects check_out_date
            formData.append('total_nights', nights);
            formData.append('adult_count', adultCount);
            formData.append('children_count', 0);
            formData.append('room_price', roomBasePrice); // API expects room_price (per night)
            formData.append('total_price', roomTotalPrice); // API expects total_price
            formData.append('discount', discountPerRoom);
            formData.append('final_price', roomFinalPrice);
            formData.append('booking_source', bookingSource);
            formData.append('payment_method', paymentMethod);
            formData.append('paid_amount', proportionalPayment);

            // DEBUG: Log what we're sending
            console.log(`[MULTI-ROOM] Room ${i+1}/${checkedRooms.length} - Room ${roomNumber}:`, {
                groupId,
                roomId,
                bookingSource,
                checkIn,
                checkOut,
                roomPrice: roomBasePrice,
                totalPrice: roomTotalPrice,
                finalPrice: roomFinalPrice
            });

            try {
                const apiUrl = '<?php echo BASE_URL; ?>/api/create-reservation.php';
                const response = await fetch(apiUrl, {
                    method: 'POST',
                    body: formData
                });

                // Get raw text first for debugging
                const responseText = await response.text();
                let result;

                console.log(`[MULTI-ROOM] Room ${roomNumber} - Raw response:`, responseText);

                try {
                    result = JSON.parse(responseText);
                } catch (parseErr) {
                    console.error(`[MULTI-ROOM] Room ${roomNumber} - Invalid JSON response:`, responseText);
                    errorMessages.push(`Room ${roomNumber}: Server error`);
                    errorCount++;
                    continue;
                }

                if (result.success) {
                    successCount++;
                    bookingCodes.push(result.booking_code);
                    console.log(`[MULTI-ROOM] Room ${roomNumber} - SUCCESS:`, result);
                } else {
                    errorCount++;
                    const errMsg = result.message || 'Unknown error';
                    errorMessages.push(`Room ${roomNumber}: ${errMsg}`);
                    console.error(`[MULTI-ROOM] Room ${roomNumber} - ERROR:`, result);
                }
            } catch (error) {
                errorCount++;
                errorMessages.push(`Room ${roomNumber}: Network error`);
                console.error(`[MULTI-ROOM] Room ${roomNumber} - NETWORK ERROR:`, error);
            }
        }

        // Re-enable submit button
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;

        // Show results
        if (successCount > 0) {
            alert(`✅ Berhasil membuat ${successCount} booking!\n\nBooking Codes: ${bookingCodes.join(', ')}\n\n${errorCount > 0 ? `⚠️ ${errorCount} booking gagal:\n${errorMessages.join('\n')}` : ''}`);
            closeReservationModal();
            // Navigate to show the new booking's check-in date
            const ciDate = document.getElementById('checkInDate')?.value;
            if (ciDate) {
                // Check if checkin date is within current grid range
                const scroller = document.getElementById('drag-container') || document.querySelector('.calendar-scroll-wrapper');
                const dateCell = scroller ? scroller.querySelector(`.grid-date-cell[data-date="${ciDate}"]`) : null;
                if (dateCell) {
                    // Date is in current range — save target date and reload
                    sessionStorage.setItem('calendarScrollToDate', ciDate); sessionStorage.setItem('calendarScrollTs', String(Date.now()));
                    location.reload();
                } else {
                    // Date is outside range — reload with start= so it's visible
                    sessionStorage.setItem('calendarScrollToDate', ciDate); sessionStorage.setItem('calendarScrollTs', String(Date.now()));
                    window.location.search = '?start=' + ciDate;
                }
            } else {
                saveScrollAndReload();
            }
        } else {
            const errDetail = errorMessages.length > 0 ? `\n\nDetail error:\n${errorMessages.join('\n')}` : '';
            alert('❌ Gagal membuat booking. Silakan coba lagi.' + errDetail);
        }
    }

    const shiftCalendarDays = (days) => {
        const dateInput = document.getElementById('dateInput');
        if (!dateInput) return;
        const currentDate = new Date(dateInput.value);
        currentDate.setDate(currentDate.getDate() + days);
        dateInput.value = currentDate.toISOString().split('T')[0];
        changeDate();
    };

    window.prevMonth = function prevMonth() {
        shiftCalendarDays(-30);
    }

    window.nextMonth = function nextMonth() {
        shiftCalendarDays(30);
    }
    // ========================================
    // COMMENTED OUT - Reservation Form Code
    // Will rebuild from scratch
    // ========================================

    // Store form pre-fill data
    let formPreFillData = {

        date: null,
        roomId: null
    };

    // Expose functions to global scope for onclick handlers
    window.showReservationForm = function showReservationForm() {
        // Close any other open modals first
        const bookingPaymentModal = document.getElementById('bookingPaymentModal');
        if (bookingPaymentModal) {
            bookingPaymentModal.classList.remove('active');
        }
        const bookingDetailsModal = document.getElementById('bookingDetailsModal');
        if (bookingDetailsModal) {
            bookingDetailsModal.classList.remove('active');
        }
        const bookingQuickView = document.getElementById('bookingQuickView');
        if (bookingQuickView) {
            bookingQuickView.classList.remove('active');
        }

        // Use pre-fill data if available
        const savedDate = formPreFillData.date;
        const savedRoomId = formPreFillData.roomId;

        console.log('📅 Opening reservation form with date:', savedDate, 'room:', savedRoomId);

        const modal = document.getElementById('reservationModal');

        // FIRST: Reset form completely to avoid stale data
        document.getElementById('reservationForm').reset();
        const guestName = document.getElementById('guestName');
        if (guestName) guestName.value = '';

        // Pre-fill form with SAVED data (not selectedDate which is now null)
        if (savedDate) {
            console.log('✅ Setting check-in date:', savedDate);

            // Set check-in date from selected date
            const checkInInput = document.getElementById('checkInDate');
            checkInInput.value = savedDate;

            // Set check-out date to next day
            const checkOut = new Date(savedDate);
            checkOut.setDate(checkOut.getDate() + 1);
            const checkOutDate = checkOut.toISOString().split('T')[0];

            const checkOutInput = document.getElementById('checkOutDate');
            checkOutInput.value = checkOutDate;
            checkOutInput.min = checkOutDate;

            console.log('Check-in:', checkInInput.value, 'Check-out:', checkOutInput.value);
        } else {
            console.error('❌ No savedDate available!');
        }

        if (savedRoomId) {
            console.log('✅ Setting room:', savedRoomId);
            document.getElementById('roomSelect').value = savedRoomId;
            // Trigger change to update price
            document.getElementById('roomSelect').dispatchEvent(new Event('change'));
        }

        // Calculate initial nights
        calculateNights();

        // Show modal AFTER setting all values
        modal.classList.add('active');
    }

    window.closeReservationModal = function closeReservationModal() {
        const modal = document.getElementById('reservationModal');
        if (modal) modal.classList.remove('active');
        // Definisi ini menimpa versi sebelumnya (yang juga membersihkan pilihan klik pertama di grid).
        if (typeof clearFirstClick === 'function') clearFirstClick();

        // Also close other modals if open
        const bookingPaymentModal = document.getElementById('bookingPaymentModal');
        if (bookingPaymentModal) {
            bookingPaymentModal.classList.remove('active');
        }
        const bookingDetailsModal = document.getElementById('bookingDetailsModal');
        if (bookingDetailsModal) {
            bookingDetailsModal.classList.remove('active');
        }
        const bookingQuickView = document.getElementById('bookingQuickView');
        if (bookingQuickView) {
            bookingQuickView.classList.remove('active');
        }

        // Clear pre-fill data
        formPreFillData.date = null;
        formPreFillData.roomId = null;

        // Remove inline styles to allow CSS to take over
        modal.style.display = '';
        modal.style.position = '';
        modal.style.top = '';
        modal.style.left = '';
        modal.style.right = '';
        modal.style.bottom = '';
        modal.style.zIndex = '';
        modal.style.backgroundColor = '';
        modal.style.alignItems = '';
        modal.style.justifyContent = '';

        // Completely reset form
        document.getElementById('reservationForm').reset();

        // Explicitly clear guest information fields
        const guestName = document.getElementById('guestName');
        const guestPhone = document.getElementById('guestPhone');
        const guestEmail = document.getElementById('guestEmail');
        const guestId = document.getElementById('guestId');
        if (guestName) guestName.value = '';
        if (guestPhone) guestPhone.value = '';
        if (guestEmail) guestEmail.value = '';
        if (guestId) guestId.value = '';

        // Reset dates
        const checkInDate = document.getElementById('checkInDate');
        const checkOutDate = document.getElementById('checkOutDate');
        if (checkInDate) checkInDate.value = '';
        if (checkOutDate) checkOutDate.value = '';

        // Reset room and price
        const roomSelect = document.getElementById('roomSelect');
        const roomPrice = document.getElementById('roomPrice');
        if (roomSelect) roomSelect.value = '';
        if (roomPrice) roomPrice.value = '';

        // Reset discount
        const discount = document.getElementById('discount');
        if (discount) discount.value = '0';

        // Reset special request
        const specialRequest = document.getElementById('specialRequest');
        if (specialRequest) specialRequest.value = '';

        // Reset DP/Paid Amount
        const paidAmount = document.getElementById('paidAmount');
        if (paidAmount) {
            paidAmount.value = '0';
            delete paidAmount.dataset.dpPercent;
        }

        // Reset payment method to Cash
        const paymentMethod = document.getElementById('paymentMethod');
        if (paymentMethod) paymentMethod.value = 'cash';

        // Reset payment status to unpaid
        const paymentStatus = document.getElementById('paymentStatus');
        if (paymentStatus) paymentStatus.value = 'unpaid';

        // Reset button states
        const paymentButtons = document.querySelectorAll('#reservationModal .payment-method-btn');
        paymentButtons.forEach((btn, idx) => {
            if (idx === 1) { // Cash is second button (index 1)
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        const dpButtons = document.querySelectorAll('.dp-percent-btn');
        dpButtons.forEach(btn => btn.classList.remove('active'));

        // Reset Total Pax to default values
        const adultInput = document.getElementById('adultCount');
        const childrenInput = document.getElementById('childrenCount');
        if (adultInput) adultInput.value = 1;
        if (childrenInput) childrenInput.value = 0;
        calculateTotalPax();

        // Reset price display
        const totalPriceEl = document.getElementById('totalPrice');
        const discountAmountEl = document.getElementById('discountAmount');
        const finalPriceEl = document.getElementById('finalPrice');
        if (totalPriceEl) totalPriceEl.textContent = 'Rp 0';
        if (discountAmountEl) discountAmountEl.textContent = '- Rp 0';
        if (finalPriceEl) finalPriceEl.textContent = 'Rp 0';

        selectedDate = null;
        selectedRoom = null;
    }

    window.calculateNights = function calculateNights() {
        const checkInEl = document.getElementById('checkInDate');
        const checkOutEl = document.getElementById('checkOutDate');
        const checkIn = checkInEl ? checkInEl.value : null;
        const checkOut = checkOutEl ? checkOutEl.value : null;

        if (checkIn && checkOut) {
            const start = new Date(checkIn);
            const end = new Date(checkOut);
            const nights = Math.ceil((end - start) / (1000 * 60 * 60 * 24));

            const totalNightsEl = document.getElementById('totalNights');
            if (totalNightsEl) totalNightsEl.value = nights > 0 ? nights : 0;
            calculatePrice();
        }
    }

    window.calculatePrice = function calculatePrice() {
        const roomPriceEl = document.getElementById('roomPrice');
        const totalNightsEl = document.getElementById('totalNights');
        const discountEl = document.getElementById('discount');

        const roomPrice = parseFloat(roomPriceEl ? roomPriceEl.value : 0) || 0;
        const nights = parseInt(totalNightsEl ? totalNightsEl.value : 0) || 0;
        const discount = parseFloat(discountEl ? discountEl.value : 0) || 0;

        const total = roomPrice * nights;
        const final = total - discount;

        const totalPriceEl = document.getElementById('totalPrice');
        const discountAmountEl = document.getElementById('discountAmount');
        const finalPriceDisplayEl = document.getElementById('finalPrice');

        if (totalPriceEl) totalPriceEl.textContent = 'Rp ' + total.toLocaleString('id-ID');
        if (discountAmountEl) discountAmountEl.textContent = '- Rp ' + discount.toLocaleString('id-ID');
        if (finalPriceDisplayEl) finalPriceDisplayEl.textContent = 'Rp ' + final.toLocaleString('id-ID');

        // Run Calculate Final Price for the modernized form too
        if (typeof calculateFinalPrice === 'function') calculateFinalPrice();

        // Recalculate DP amount if a percent is selected
        const paidInput = document.getElementById('paidAmount');
        if (paidInput && paidInput.dataset.dpPercent) {
            applyDpPercent(parseFloat(paidInput.dataset.dpPercent));
        }
    }

    window.getFinalPriceNumber = function getFinalPriceNumber() {
        const roomPriceEl = document.getElementById('roomPrice');
        const totalNightsEl = document.getElementById('totalNights');
        const discountEl = document.getElementById('discount');

        const roomPrice = parseFloat(roomPriceEl ? roomPriceEl.value : 0) || 0;
        const nights = parseInt(totalNightsEl ? totalNightsEl.value : 0) || 0;
        const discount = parseFloat(discountEl ? discountEl.value : 0) || 0;
        return (roomPrice * nights) - discount;
    }

    window.applyDpPercent = function applyDpPercent(percent) {
        const paidInput = document.getElementById('paidAmount');
        if (!paidInput) return;

        const finalPrice = getFinalPriceNumber();
        const amount = Math.round(finalPrice * (percent / 100));
        paidInput.value = amount;
        paidInput.dataset.dpPercent = percent;

        updatePaymentStatusFromAmount();
    }

    window.updatePaymentStatusFromAmount = function updatePaymentStatusFromAmount() {
        const paidInput = document.getElementById('paidAmount');
        const statusSelect = document.getElementById('paymentStatus');
        if (!paidInput || !statusSelect) return;

        const paid = parseFloat(paidInput.value) || 0;
        const finalPrice = getFinalPriceNumber();

        if (paid <= 0) {
            statusSelect.value = 'unpaid';
        } else if (paid >= finalPrice) {
            statusSelect.value = 'paid';
        } else {
            statusSelect.value = 'partial';
        }
    }

    // Calculate Total Pax (Adult + Children)
    window.calculateTotalPax = function calculateTotalPax() {
        const adultEl = document.getElementById('adultCount');
        const childrenEl = document.getElementById('childrenCount');
        const totalPaxEl = document.getElementById('totalPax');

        const adults = parseInt(adultEl ? adultEl.value : 0) || 0;
        const children = parseInt(childrenEl ? childrenEl.value : 0) || 0;
        const totalPax = adults + children;

        if (totalPaxEl) totalPaxEl.value = totalPax;
    }

    // Old submitReservation removed to avoid duplication and syntax error
    // The new submitReservation is defined earlier in the file

    // Setup form event listeners (removed click-outside-to-close functionality)

    // Save scroll position before reload so we return to same spot
    // Size the calendar box to the viewport so header/footer rows stay frozen while the rooms scroll inside it
    (function() {
        function fitCalendarHeight() {
            const w = document.getElementById('drag-container');
            if (!w) return;
            // Full-screen calendar: no site footer and only a thin page padding under the grid
            const mc0 = w.closest('.main-content');
            if (mc0) {
                const f = mc0.querySelector('footer');
                if (f) f.style.display = 'none';
                mc0.style.paddingBottom = '6px';
            }
            const y = window.pageYOffset || 0;
            const wr = w.getBoundingClientRect();
            const top = wr.top + y;
            // Whatever sits under the grid (legend, container padding, page bottom padding) is subtracted so the grid
            // reaches the bottom edge of the screen
            const box = w.closest('.calendar-container');
            const below = box ? Math.max(0, box.getBoundingClientRect().bottom + y - (wr.bottom + y)) : 0;
            const mc = w.closest('.main-content');
            const mcPad = mc ? (parseFloat(getComputedStyle(mc).paddingBottom) || 0) : 0;
            const h = Math.max(320, window.innerHeight - top - below - mcPad - 4);
            w.style.setProperty('--cal-max-h', h + 'px');
        }
        fitCalendarHeight();
        window.addEventListener('load', fitCalendarHeight);
        window.addEventListener('resize', fitCalendarHeight);
        // Layout above the calendar (toolbar, sync pill) can settle a moment after load
        setTimeout(fitCalendarHeight, 400);
        setTimeout(fitCalendarHeight, 1500);
        try {
            const ts = parseInt(sessionStorage.getItem('calendarScrollTs') || '0', 10);
            const st = parseInt(sessionStorage.getItem('calendarScrollTop') || '0', 10);
            const w = document.getElementById('drag-container');
            if (w && st > 0 && ts > 0 && (Date.now() - ts) < 20000) setTimeout(function() { w.scrollTop = st; }, 60);
        } catch (e) {}
    })();

    function saveScrollAndReload() {
        const scroller = document.getElementById('drag-container') || document.querySelector('.calendar-scroll-wrapper');
        if (scroller) {
            sessionStorage.setItem('calendarScrollLeft', scroller.scrollLeft); sessionStorage.setItem('calendarScrollTop', scroller.scrollTop); sessionStorage.setItem('calendarScrollTs', String(Date.now()));
        }
        location.reload();
    }

    // Scroll calendar grid to a specific date
    function scrollCalendarToDate(dateStr, scroller) {
        if (!scroller) scroller = document.getElementById('drag-container') || document.querySelector('.calendar-scroll-wrapper');
        if (!scroller) return;
        const cell = scroller.querySelector(`.grid-date-cell[data-date="${dateStr}"]`);
        if (cell) {
            // Kolom tanggal tepat di kanan kolom kamar (posisi kolom tanggal pertama diukur)
            const firstCell = scroller.querySelector('.grid-date-cell[data-date]');
            const scrollPos = cell.offsetLeft - (firstCell ? firstCell.offsetLeft : 84);
            scroller.scrollLeft = Math.max(0, scrollPos);
        }
    }

    // Go to today: if today is within the current 30-day range, just scroll; otherwise reload with today's start date
    window.goToToday = function() {
        const todayStr = window.fdLocalYmd(new Date());
        const scroller = document.getElementById('drag-container') || document.querySelector('.calendar-scroll-wrapper');
        const todayCell = scroller ? scroller.querySelector(`.grid-date-cell[data-date="${todayStr}"]`) : null;
        if (todayCell) {
            // Hari ini di kolom ke-3, sama seperti saat halaman pertama dibuka
            const back = new Date();
            back.setDate(back.getDate() - 2);
            const backStr = window.fdLocalYmd(back);
            scrollCalendarToDate(scroller.querySelector(`.grid-date-cell[data-date="${backStr}"]`) ? backStr : todayStr, scroller);
        } else {
            // Today is outside the current range — reload with today as start
            window.location.search = '?start=' + todayStr;
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        console.log('🚀 DOMContentLoaded fired for calendar.php');

        // ========================================
        // SEARCH RESERVATION FUNCTIONALITY
        // ========================================
        const searchInput = document.getElementById('searchReservation');
        const searchResults = document.getElementById('searchResults');
        const searchClearBtn = document.getElementById('searchClearBtn');
        let searchTimeout = null;

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const q = this.value.trim();
                searchClearBtn.style.display = q.length > 0 ? '' : 'none';

                if (searchTimeout) clearTimeout(searchTimeout);
                if (q.length < 2) {
                    searchResults.style.display = 'none';
                    return;
                }

                searchTimeout = setTimeout(function() {
                    fetch('../../api/search-bookings.php?q=' + encodeURIComponent(q))
                        .then(r => r.json())
                        .then(function(data) {
                            if (!data.success || !data.results.length) {
                                searchResults.innerHTML = '<div class="search-no-result">No results found</div>';
                                searchResults.style.display = 'block';
                                return;
                            }
                            let html = '';
                            data.results.forEach(function(r) {
                                const initials = (r.guest_name || 'G').split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
                                const checkin = new Date(r.check_in_date).toLocaleDateString('id-ID', {
                                    day: 'numeric',
                                    month: 'short'
                                });
                                const checkout = new Date(r.check_out_date).toLocaleDateString('id-ID', {
                                    day: 'numeric',
                                    month: 'short',
                                    year: 'numeric'
                                });
                                html += '<div class="search-result-item" onclick="openBookingFromSearch(' + r.id + ')">' +
                                    '<div class="sr-avatar">' + initials + '</div>' +
                                    '<div class="sr-info"><div class="sr-name">' + r.guest_name + '</div>' +
                                    '<div class="sr-meta">Room ' + (r.room_number || '-') + ' • ' + checkin + ' - ' + checkout + ' • ' + (r.booking_code || '') + '</div></div>' +
                                    '<span class="sr-status ' + r.status + '">' + r.status.replace('_', ' ') + '</span>' +
                                    '</div>';
                            });
                            searchResults.innerHTML = html;
                            searchResults.style.display = 'block';
                        })
                        .catch(function() {
                            searchResults.style.display = 'none';
                        });
                }, 300);
            });

            // Close dropdown on outside click
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.search-reservation-bar')) {
                    searchResults.style.display = 'none';
                }
            });
        }

        window.openBookingFromSearch = function(bookingId) {
            searchResults.style.display = 'none';
            searchInput.value = '';
            searchClearBtn.style.display = 'none';
            viewBooking(bookingId, new Event('click'));
        };

        window.clearSearch = function() {
            searchInput.value = '';
            searchClearBtn.style.display = 'none';
            searchResults.style.display = 'none';
        };

        try {
            // ========================================
            // 1. DRAG SCROLL IMPLEMENTATION (PRIORITY)
            // ========================================
            const scroller = document.getElementById('drag-container') || document.querySelector('.calendar-scroll-wrapper');

            if (!scroller) {
                console.error('❌ Drag container not found');
            } else {
                console.log('✅ Drag initialized on #drag-container');

                let isDown = false;
                let startX;
                let scrollLeft;

                scroller.addEventListener('mousedown', (e) => {
                    // Ignore if clicking on interactive elements
                    if (e.target.closest('.booking-bar') ||
                        e.target.closest('.nav-btn') ||
                        e.target.closest('input') ||
                        e.target.closest('button')) return;

                    isDown = true;
                    scroller.classList.add('dragging');
                    startX = e.pageX - scroller.offsetLeft;
                    scrollLeft = scroller.scrollLeft;

                    console.log('MouseDown: StartX', startX, 'ScrollLeft', scrollLeft);
                });

                // Use window for mousemove/mouseup to handle drags that leave the container
                window.addEventListener('mouseleave', () => {
                    isDown = false;
                    if (scroller) scroller.classList.remove('dragging');
                });

                window.addEventListener('mouseup', () => {
                    isDown = false;
                    if (scroller) scroller.classList.remove('dragging');
                });

                window.addEventListener('mousemove', (e) => {
                    if (!isDown) return;
                    e.preventDefault(); // Prevent selection/dragging artifacts

                    const x = e.pageX - scroller.offsetLeft;
                    const walk = (x - startX); // Scroll 1:1
                    scroller.scrollLeft = scrollLeft - walk;

                    // console.log('MouseMove:', { x, walk, newScroll: scroller.scrollLeft }); // Uncomment for debug
                });

                // Touch support
                scroller.addEventListener('touchstart', (e) => {
                    if (e.target.closest('.booking-bar')) return;
                    const touch = e.touches[0];
                    isDown = true;
                    startX = touch.pageX - scroller.offsetLeft;
                    scrollLeft = scroller.scrollLeft;
                }, {
                    passive: true
                });

                scroller.addEventListener('touchmove', (e) => {
                    if (!isDown) return;
                    const touch = e.touches[0];
                    const x = touch.pageX - scroller.offsetLeft;
                    const walk = (x - startX);
                    scroller.scrollLeft = scrollLeft - walk;
                }, {
                    passive: false
                });

                scroller.addEventListener('touchend', () => {
                    isDown = false;
                });

                // ========================================
                // AUTO-SCROLL: restore saved position or scroll to today
                // ========================================
                // Posisi tersimpan hanya dipakai bila baru saja disimpan (reload setelah aksi). Refresh biasa /
                // nilai lama yang tertinggal -> selalu kembali ke hari ini di kolom ke-3.
                try { if ('scrollRestoration' in history) history.scrollRestoration = 'manual'; } catch (e) {}
                const scrollTs = parseInt(sessionStorage.getItem('calendarScrollTs') || '0', 10);
                const scrollFresh = scrollTs > 0 && (Date.now() - scrollTs) < 20000;
                if (!scrollFresh) {
                    sessionStorage.removeItem('calendarScrollToDate');
                    sessionStorage.removeItem('calendarScrollLeft');
                }
                sessionStorage.removeItem('calendarScrollTs');
                const applyCalendarScroll = () => {
                    const scrollToDate = sessionStorage.getItem('calendarScrollToDate');
                    const savedScroll = sessionStorage.getItem('calendarScrollLeft');

                    if (scrollToDate) {
                        // Scroll to specific date (after creating reservation)
                        sessionStorage.removeItem('calendarScrollToDate');
                        sessionStorage.removeItem('calendarScrollLeft');
                        scrollCalendarToDate(scrollToDate, scroller);
                        console.log('✅ Scrolled to new booking date:', scrollToDate);
                    } else if (savedScroll !== null) {
                        scroller.scrollLeft = parseInt(savedScroll);
                        sessionStorage.removeItem('calendarScrollLeft');
                        console.log('✅ Restored scroll position:', savedScroll);
                    } else {
                        // Pertama dibuka: hari ini di kolom ke-3 (2 hari sebelumnya tetap terlihat di kiri)
                        const twoDaysAgo = new Date();
                        twoDaysAgo.setDate(twoDaysAgo.getDate() - 2);
                        const todayStr = window.fdLocalYmd(new Date());
                        scrollCalendarToDate(window.fdLocalYmd(twoDaysAgo), scroller);
                        console.log('✅ Auto-scrolled to today:', todayStr);
                    }
                };
                // Ingat target sekali, lalu terapkan lagi setelah halaman selesai dimuat (browser bisa
                // memulihkan posisi scroll lama sesudah skrip ini berjalan).
                let calTarget = null;
                setTimeout(() => { applyCalendarScroll(); calTarget = scroller.scrollLeft; }, 60);
                window.addEventListener('load', () => setTimeout(() => {
                    if (calTarget !== null && Math.abs(scroller.scrollLeft - calTarget) > 2) scroller.scrollLeft = calTarget;
                }, 150), { once: true });
            }
        } catch (e) {
            console.error('❌ Error in Drag Scroll setup:', e);
        }

        try {
            // ========================================
            // 2. NAVIGATION BUTTONS (PRIORITY)
            // ========================================
            const prevBtn = document.getElementById('prevMonthBtn');
            const nextBtn = document.getElementById('nextMonthBtn');

            if (prevBtn) {
                prevBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    console.log('Prev Month Clicked');
                    if (typeof window.prevMonth === 'function') {
                        window.prevMonth();
                    } else {
                        console.error('window.prevMonth is not defined');
                    }
                });
            }
            if (nextBtn) {
                nextBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    console.log('Next Month Clicked');
                    if (typeof window.nextMonth === 'function') {
                        window.nextMonth();
                    } else {
                        console.error('window.nextMonth is not defined');
                    }
                });
            }
            console.log('✅ Navigation buttons initialized');
        } catch (e) {
            console.error('❌ Error in Navigation setup:', e);
        }

        try {
            // Form event listeners
            const checkInDate = document.getElementById('checkInDate');
            const checkOutDate = document.getElementById('checkOutDate');
            const roomSelect = document.getElementById('roomSelect');
            const roomPriceInput = document.getElementById('roomPrice');
            const discountInput = document.getElementById('discount');
            const adultInput = document.getElementById('adultCount');
            const childrenInput = document.getElementById('childrenCount');
            const paidAmountInput = document.getElementById('paidAmount');
            const paymentMethodInput = document.getElementById('paymentMethod');

            if (checkInDate) checkInDate.addEventListener('change', calculateNights);
            if (checkOutDate) checkOutDate.addEventListener('change', calculateNights);
            if (roomPriceInput) roomPriceInput.addEventListener('input', calculatePrice);
            if (discountInput) discountInput.addEventListener('input', calculatePrice);
            if (adultInput) adultInput.addEventListener('change', calculateTotalPax);
            if (childrenInput) childrenInput.addEventListener('change', calculateTotalPax);

            if (paidAmountInput) {
                paidAmountInput.addEventListener('input', function() {
                    const dpButtons = document.querySelectorAll('.dp-percent-btn');
                    dpButtons.forEach(btn => btn.classList.remove('active'));
                    delete paidAmountInput.dataset.dpPercent;
                    if (typeof updatePaymentStatusFromAmount === 'function') {
                        updatePaymentStatusFromAmount();
                    }
                });
            }

            const paymentButtons = document.querySelectorAll('#reservationModal .payment-method-btn');
            paymentButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    paymentButtons.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    if (paymentMethodInput) {
                        paymentMethodInput.value = this.dataset.value;
                    }
                });
            });

            const payModalButtons = document.querySelectorAll('#bookingPaymentModal .payment-method-btn');
            payModalButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    payModalButtons.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    const payMethodInput = document.getElementById('paymentMethodPay');
                    if (payMethodInput) {
                        payMethodInput.value = this.dataset.value;
                    }
                    // Booking OTA: bayar langsung = selisih upgrade/extend; OTA = sisa tagihan
                    if (typeof payOtaCtx !== 'undefined' && payOtaCtx && payOtaCtx.direct > 0) {
                        const amt = document.getElementById('paymentAmount');
                        amt.value = this.dataset.value === 'ota' ? payOtaCtx.remaining : Math.min(payOtaCtx.remaining, Math.round(payOtaCtx.direct));
                    }
                    if (typeof updatePayOtaNet === 'function') updatePayOtaNet();
                });
            });

            // Setup Booking Source Logic
            const sourceSelect = document.getElementById('bookingSource');
            if (sourceSelect) {
                sourceSelect.addEventListener('change', function() {
                    // Trigger the logic to show/hide OTA options
                    if (typeof calculateFinalPrice === 'function') calculateFinalPrice();
                });
            }

            const dpButtons = document.querySelectorAll('.dp-percent-btn');
            dpButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    dpButtons.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    const percent = parseFloat(this.dataset.percent);
                    if (typeof applyDpPercent === 'function') {
                        applyDpPercent(percent);
                    }
                });
            });

            if (roomSelect) {
                roomSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    const price = selectedOption.getAttribute('data-price');
                    if (price) {
                        const roomPriceEl = document.getElementById('roomPrice');
                        if (roomPriceEl) roomPriceEl.value = price;
                        if (typeof calculatePrice === 'function') calculatePrice();
                    }
                });
            }
        } catch (e) {
            console.error('❌ Error in Form Listener setup:', e);
        }
    });
</script>

<!-- RESERVATION MODAL - POPUP SYSTEM 2028 -->
<div id="reservationModal" class="modal-overlay">
    <div class="modal-content modal-compact modal-compact-booking">
        <div class="modal-header-compact">
            <div>
                <h2>New Reservation</h2>
                <small>Bisa memilih lebih dari 1 kamar sekaligus</small>
            </div>
            <button type="button" class="close-btn" onclick="closeReservationModal()" aria-label="Tutup">&times;</button>
        </div>

        <form id="reservationForm" onsubmit="submitReservation(event)">
            <input type="hidden" name="action" value="create_reservation">
            <input type="hidden" id="totalNights" name="total_nights" value="1">
            <input type="hidden" id="hiddenTotalPrice" name="total_price" value="0">
            <input type="hidden" id="hiddenFinalPrice" name="final_price" value="0">

            <div class="form-compact">
                <!-- MODE -->
                <div class="form-row-2col">
                    <div class="input-compact">
                        <label>Mode*</label>
                        <select id="reservationMode" name="reservation_mode" onchange="setReservationMode(this.value)">
                            <option value="reservation" selected>Reservasi</option>
                            <option value="block_room">Block Room</option>
                        </select>
                    </div>
                    <div class="input-compact" id="blockHintWrap">
                        <label>&nbsp;</label>
                        <small class="nr-hint">Block Room: untuk maintenance, deep cleaning, owner use, dll.</small>
                    </div>
                </div>

                <!-- GUEST INFO -->
                <div id="guestInfoSection" class="form-row-2col">
                    <div class="input-compact">
                        <label>Nama tamu *</label>
                        <input type="text" id="guestName" name="guest_name" required placeholder="Nama lengkap">
                    </div>
                    <div class="input-compact">
                        <label>Telepon / WA</label>
                        <input type="text" id="guestPhone" name="guest_phone" placeholder="08xx">
                    </div>
                </div>

                <!-- DATES -->
                <div class="form-row-2col">
                    <div class="input-compact">
                        <label>Check-in *</label>
                        <input type="date" id="checkInDate" name="check_in_date" required onchange="loadAvailableRoomsCalendar()">
                    </div>
                    <div class="input-compact">
                        <label>Check-out *</label>
                        <input type="date" id="checkOutDate" name="check_out_date" required onchange="loadAvailableRoomsCalendar()">
                    </div>
                </div>

                <!-- BLOCK ROOM INFO -->
                <div id="blockInfoSection" style="display:none;">
                    <div class="form-row-2col">
                        <div class="input-compact">
                            <label>Alasan Block*</label>
                            <select id="blockReason" name="block_reason">
                                <option value="maintenance">Maintenance</option>
                                <option value="deep_cleaning">Deep Cleaning</option>
                                <option value="out_of_order">Out of Order</option>
                                <option value="owner_use">Owner Use</option>
                                <option value="event_setup">Event Setup</option>
                                <option value="other">Lainnya</option>
                            </select>
                        </div>
                        <div class="input-compact">
                            <label>Keterangan Tambahan</label>
                            <input type="text" id="blockNotes" name="block_notes" placeholder="Contoh: AC rusak, renovasi, pipa bocor">
                        </div>
                    </div>
                </div>

                <!-- ROOMS SELECTION (MULTI SELECT) -->
                <div class="input-compact">
                    <div class="nr-label-row">
                        <label>Pilih kamar *</label>
                        <span id="availabilityInfoCalendar"></span>
                    </div>
                    <div id="roomsChecklistCalendar" class="rooms-checklist">
                        <em>Pilih tanggal untuk melihat kamar yang tersedia…</em>
                    </div>
                    <div id="selectedRoomsSummaryCalendar" class="nr-selected"></div>
                </div>

                <!-- GUESTS -->
                <div class="input-compact" id="guestCountSection">
                    <label>Jumlah tamu (dewasa)</label>
                    <input type="number" id="adultCount" name="adult_count" value="1" min="1">
                </div>

                <!-- SOURCE & PAYMENT METHOD -->
                <div class="form-row-2col" id="sourcePaymentSection">
                    <div class="input-compact">
                        <label>Sumber booking *</label>
                        <select id="bookingSource" name="booking_source" onchange="updateSourceDetails()" required>
                            <option value="">-- Pilih Booking Source --</option>
                            <?php
                            $srcDirect = array_filter($bookingSources, fn($s) => ($s['source_type'] ?? '') === 'direct');
                            $srcOta = array_filter($bookingSources, fn($s) => ($s['source_type'] ?? '') !== 'direct');
                            if (!empty($srcDirect) || !empty($srcOta)):
                            ?>
                                <optgroup label="Direct">
                                    <?php foreach ($srcDirect as $src): ?>
                                        <option value="<?php echo htmlspecialchars($src['source_key']); ?>"><?php echo $src['icon'] . ' ' . htmlspecialchars($src['source_name']); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="OTA">
                                    <?php foreach ($srcOta as $src): ?>
                                        <option value="<?php echo htmlspecialchars($src['source_key']); ?>"><?php echo $src['icon'] . ' ' . htmlspecialchars($src['source_name']) . ' (fee ' . $src['fee_percent'] . '%)'; ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php else: ?>
                                <option value="walk_in">🚶 Direct (Walk-in)</option>
                                <option value="phone">📞 Direct (Phone)</option>
                                <option value="agoda">🏨 Agoda</option>
                                <option value="booking">📱 Booking.com</option>
                                <option value="tiket">✈️ Tiket.com</option>
                                <option value="ota">🌐 OTA Lainnya</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="input-compact">
                        <label>Metode bayar</label>
                        <select name="payment_method" id="paymentMethod" onchange="calculateFinalPrice()">
                            <option value="cash">Cash</option>
                            <option value="transfer">Transfer</option>
                            <option value="qris">QRIS</option>
                            <option value="ota">OTA</option>
                        </select>
                    </div>
                </div>

                <!-- PRICE SUMMARY -->
                <div class="price-summary-compact" id="priceSummarySection">
                    <div class="price-line">
                        <span>Kamar</span>
                        <strong id="totalRoomsDisplayCalendar">0 rooms</strong>
                    </div>
                    <div class="price-line">
                        <span>Malam</span>
                        <strong id="displayNights">0</strong>
                    </div>
                    <div class="price-line">
                        <span>Subtotal</span>
                        <strong id="subtotalDisplayCalendar">Rp 0</strong>
                    </div>
                    <div class="price-line nr-discount">
                        <span>Diskon</span>
                        <div class="nr-discount-ctl">
                            <div class="discount-type-toggle">
                                <button type="button" class="disc-type-btn disc-type-btn-cal active" data-type="rp" onclick="setDiscountTypeCalendar('rp')">Rp</button>
                                <button type="button" class="disc-type-btn disc-type-btn-cal" data-type="percent" onclick="setDiscountTypeCalendar('percent')">%</button>
                            </div>
                            <input type="number" id="discount" name="discount" value="" min="0" inputmode="decimal" oninput="calculateMultiRoomTotalCalendar()" onchange="calculateMultiRoomTotalCalendar()" placeholder="0">
                            <input type="hidden" id="discountType" name="discount_type" value="rp">
                            <span id="discountTypeLabel">Rp</span>
                        </div>
                    </div>
                    <div id="discountPreview" class="nr-disc-preview"></div>
                    <div class="price-line" id="otaFeeRow" style="display: none;">
                        <span>OTA fee (<span id="otaFeePercentDisplay">0</span>%)</span>
                        <strong id="otaFeeAmountDisplay">- Rp 0</strong>
                        <input type="hidden" id="otaFeeAmount" name="ota_fee_amount" value="0">
                    </div>
                    <div class="price-line-total">
                        <span>Grand total</span>
                        <strong id="grandTotalDisplayCalendar">Rp 0</strong>
                    </div>
                </div>

                <!-- PAYMENT -->
                <div class="input-compact" id="paymentSection">
                    <label>Pembayaran awal (DP) · Rp</label>
                    <div class="nr-pay-row">
                        <input type="number" id="paidAmount" name="paid_amount" value="" min="0" inputmode="numeric" placeholder="0">
                        <button type="button" onclick="payFullMultiRoomCalendar()" class="btn-pay-all" title="Bayar penuh">Bayar penuh</button>
                    </div>
                </div>
            </div>

            <div class="modal-footer-compact">
                <button type="button" class="btn-cancel" onclick="closeReservationModal()">Batal</button>
                <button type="submit" class="btn-save" id="reservationSubmitBtn">Save Reservation</button>
            </div>
        </form>
    </div>
</div>

<style>
    /* COMPACT RESERVATION MODAL STYLES */
    .modal-compact {
        width: 90%;
        max-width: 600px;
        max-height: 85vh;
        display: flex;
        flex-direction: column;
        background: white;
        border-radius: 12px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        padding: 0;
        overflow: hidden;
    }

    body[data-theme="dark"] .modal-compact {
        background: #1e293b;
    }

    .modal-header-compact {
        padding: 1rem 1.5rem;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: white;
        border-bottom: none;
    }

    .modal-header-compact h2 {
        margin: 0;
        font-size: 1.2rem;
        font-weight: 700;
    }

    .form-compact {
        padding: 1.5rem;
        overflow-y: auto;
        flex: 1;
    }

    .form-row-2col {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .input-compact {
        display: flex;
        flex-direction: column;
    }

    .input-compact label {
        font-size: 0.85rem;
        font-weight: 600;
        margin-bottom: 0.25rem;
        color: #475569;
    }

    body[data-theme="dark"] .input-compact label {
        color: #cbd5e1;
    }

    .input-compact input,
    .input-compact select {
        padding: 0.5rem;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.9rem;
        font-family: inherit;
    }

    body[data-theme="dark"] .input-compact input,
    body[data-theme="dark"] .input-compact select {
        background: #334155;
        border-color: #475569;
        color: white;
    }

    .input-compact input:focus,
    .input-compact select:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }

    .guest-inputs {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .guest-inputs input {
        padding: 0.4rem;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        font-size: 0.85rem;
    }

    .price-summary-compact {
        background: #f1f5f9;
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
        font-size: 0.9rem;
    }

    body[data-theme="dark"] .price-summary-compact {
        background: #334155;
    }

    .price-line {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.5rem;
        align-items: center;
    }

    .price-line input {
        width: 150px;
        padding: 0.25rem;
        border: 1px solid #cbd5e1;
        border-radius: 4px;
        font-size: 0.85rem;
        text-align: right;
    }

    body[data-theme="dark"] .price-line input {
        background: #1e293b;
        border-color: #475569;
        color: white;
    }

    .price-line-total {
        display: flex;
        justify-content: space-between;
        font-weight: 700;
        font-size: 1rem;
        padding-top: 0.5rem;
        border-top: 2px solid #cbd5e1;
    }

    body[data-theme="dark"] .price-line-total {
        border-color: #475569;
    }

    .modal-footer-compact {
        padding: 1rem 1.5rem;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
    }

    body[data-theme="dark"] .modal-footer-compact {
        background: #0f172a;
        border-color: #334155;
    }

    .btn-cancel {
        padding: 0.6rem 1.5rem;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        font-size: 0.9rem;
        transition: all 0.2s;
    }

    body[data-theme="dark"] .btn-cancel {
        background: #334155;
        border-color: #475569;
        color: white;
    }

    .btn-pay-all {
        padding: 0.5rem 1rem;
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        font-size: 0.85rem;
        transition: all 0.2s;
        white-space: nowrap;
    }

    .btn-pay-all:hover {
        background: linear-gradient(135deg, #059669, #047857);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }

    body[data-theme="dark"] .btn-pay-all {
        background: linear-gradient(135deg, #10b981, #059669);
    }

    .btn-cancel:hover {
        background: #f1f5f9;
    }

    .btn-save {
        padding: 0.6rem 2rem;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 700;
        font-size: 0.9rem;
        transition: all 0.3s;
    }

    .btn-save:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(99, 102, 241, 0.3);
    }

    .btn-save:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    /* Scrollbar styling */
    .form-compact::-webkit-scrollbar {
        width: 6px;
    }

    .form-compact::-webkit-scrollbar-track {
        background: transparent;
    }

    .form-compact::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 3px;
    }

    .form-compact::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    /* ===== New Reservation (redesign) — selector #reservationModal agar menang atas gaya lama & global ===== */
    #reservationModal {
        --nr-bg: #ffffff;
        --nr-soft: #f8fafc;
        --nr-line: #e2e8f0;
        --nr-ink: #0f172a;
        --nr-muted: #64748b;
        --nr-input: #ffffff;
        --nr-input-line: #cbd5e1;
        background: rgba(15, 23, 42, 0.5) !important;
        backdrop-filter: blur(3px);
    }

    body[data-theme="dark"] #reservationModal {
        --nr-bg: #0f172a;
        --nr-soft: rgba(255, 255, 255, 0.04);
        --nr-line: rgba(255, 255, 255, 0.1);
        --nr-ink: #e2e8f0;
        --nr-muted: #94a3b8;
        --nr-input: rgba(255, 255, 255, 0.05);
        --nr-input-line: rgba(148, 163, 184, 0.35);
    }

    #reservationModal .modal-compact-booking {
        width: 100% !important;
        max-width: 640px !important;
        max-height: calc(100vh - 32px) !important;
        display: flex !important;
        flex-direction: column !important;
        padding: 0 !important;
        border-radius: 16px !important;
        background: var(--nr-bg) !important;
        border: 1px solid var(--nr-line) !important;
        box-shadow: 0 24px 60px -16px rgba(15, 23, 42, 0.45) !important;
        overflow: hidden !important;
    }

    #reservationModal .modal-header-compact {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 12px;
        margin: 0 !important;
        padding: 14px 18px !important;
        background: linear-gradient(135deg, #1e3a8a, #2563eb) !important;
        border: 0 !important;
        border-radius: 0 !important;
    }

    #reservationModal .modal-header-compact h2 {
        margin: 0 !important;
        font-size: 0.98rem !important;
        font-weight: 700 !important;
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
    }

    #reservationModal .modal-header-compact small {
        display: block;
        font-size: 0.68rem !important;
        color: rgba(255, 255, 255, 0.78) !important;
        -webkit-text-fill-color: rgba(255, 255, 255, 0.78) !important;
    }

    #reservationModal .close-btn {
        width: 32px !important;
        height: 32px !important;
        border-radius: 10px !important;
        border: 1px solid rgba(255, 255, 255, 0.3) !important;
        background: rgba(255, 255, 255, 0.12) !important;
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
        font-size: 20px !important;
        line-height: 1 !important;
    }

    #reservationModal form {
        display: flex;
        flex-direction: column;
        min-height: 0;
        flex: 1;
    }

    #reservationModal .form-compact {
        overflow-y: auto !important;
        padding: 6px 18px 14px !important;
        display: block !important;
    }

    #reservationModal .nr-sec {
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 12px 0 8px;
        font-size: 0.62rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--nr-muted) !important;
    }

    #reservationModal .nr-sec::after {
        content: '';
        flex: 1;
        height: 1px;
        background: var(--nr-line);
    }

    #reservationModal .form-row-2col {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 10px !important;
        margin-bottom: 10px !important;
    }

    #reservationModal .input-compact {
        margin-bottom: 10px !important;
    }

    #reservationModal .form-row-2col .input-compact {
        margin-bottom: 0 !important;
    }

    body[data-theme] #reservationModal .input-compact label {
        display: block;
        margin: 0 0 4px !important;
        font-size: 0.62rem !important;
        font-weight: 700 !important;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: var(--nr-muted) !important;
    }

    body[data-theme] #reservationModal .input-compact input,
    body[data-theme] #reservationModal .input-compact select,
    body[data-theme] #reservationModal .input-compact textarea,
    body[data-theme] #reservationModal .nr-discount-ctl input[type="number"] {
        width: 100%;
        height: 36px;
        padding: 0 10px !important;
        border-radius: 9px !important;
        border: 1px solid var(--nr-input-line) !important;
        background: var(--nr-input) !important;
        color: var(--nr-ink) !important;
        font-size: 0.8rem !important;
        box-shadow: none !important;
    }

    body[data-theme] #reservationModal .input-compact textarea {
        height: auto;
        min-height: 58px;
        padding: 8px 10px !important;
        line-height: 1.45;
        resize: vertical;
    }

    body[data-theme] #reservationModal .input-compact input:focus,
    body[data-theme] #reservationModal .input-compact select:focus,
    body[data-theme] #reservationModal .input-compact textarea:focus,
    body[data-theme] #reservationModal .nr-discount-ctl input:focus {
        outline: none !important;
        border-color: #2563eb !important;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15) !important;
    }

    /* Pilih kamar: kartu 2 kolom */
    #reservationModal .nr-label-row {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 8px;
    }

    #reservationModal #availabilityInfo small,
    #reservationModal #availabilityInfo {
        font-size: 0.66rem !important;
    }

    #reservationModal .rooms-checklist {
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 6px !important;
        max-height: 220px !important;
        overflow-y: auto !important;
        padding: 6px !important;
        border-radius: 10px !important;
        border: 1px solid var(--nr-line) !important;
        background: var(--nr-soft) !important;
    }

    #reservationModal .rooms-checklist > em,
    #reservationModal .rooms-checklist > div {
        grid-column: 1 / -1;
        padding: 14px;
        font-size: 0.74rem;
        text-align: center;
    }

    body[data-theme] #reservationModal .room-checkbox-item {
        display: flex !important;
        align-items: center;
        gap: 9px;
        margin: 0 !important;
        padding: 8px 10px !important;
        border-radius: 9px !important;
        border: 1px solid var(--nr-line) !important;
        background: var(--nr-bg) !important;
        cursor: pointer;
        font-size: 0.76rem !important;
        text-transform: none !important;
        letter-spacing: 0 !important;
        transition: border-color 0.15s, background 0.15s;
    }

    #reservationModal .room-checkbox-item:hover {
        border-color: #93c5fd !important;
    }

    #reservationModal .room-checkbox-item:has(input:checked) {
        border-color: #2563eb !important;
        background: rgba(37, 99, 235, 0.08) !important;
        box-shadow: inset 0 0 0 1px #2563eb;
    }

    body[data-theme] #reservationModal .room-checkbox-item input[type="checkbox"] {
        width: 16px !important;
        height: 16px !important;
        margin: 0 !important;
        flex-shrink: 0;
        accent-color: #2563eb;
    }

    #reservationModal .room-checkbox-item .nr-room {
        display: flex;
        flex-direction: column;
        min-width: 0;
        line-height: 1.25;
    }

    #reservationModal .room-checkbox-item .nr-room b {
        font-size: 0.8rem;
        color: var(--nr-ink) !important;
    }

    #reservationModal .room-checkbox-item .nr-room small {
        font-size: 0.66rem;
        color: var(--nr-muted) !important;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    #reservationModal .room-checkbox-item .nr-price {
        margin-left: auto;
        font-size: 0.7rem;
        font-weight: 700;
        color: #047857 !important;
        white-space: nowrap;
    }

    body[data-theme="dark"] #reservationModal .room-checkbox-item .nr-price {
        color: #6ee7b7 !important;
    }

    #reservationModal .nr-selected {
        margin-top: 6px;
        font-size: 0.72rem !important;
        color: #1d4ed8 !important;
    }

    body[data-theme="dark"] #reservationModal .nr-selected {
        color: #93c5fd !important;
    }

    /* Ringkasan harga */
    #reservationModal .price-summary-compact {
        margin: 4px 0 12px !important;
        padding: 10px 14px !important;
        border-radius: 12px !important;
        background: var(--nr-soft) !important;
        border: 1px solid var(--nr-line) !important;
    }

    #reservationModal .price-line {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 10px;
        padding: 4px 0 !important;
        margin: 0 !important;
        font-size: 0.78rem !important;
        color: var(--nr-muted) !important;
        background: none !important;
    }

    #reservationModal .price-line strong {
        color: var(--nr-ink) !important;
        font-size: 0.8rem !important;
    }

    #reservationModal .nr-discount-ctl {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    #reservationModal .discount-type-toggle {
        display: inline-flex;
        padding: 2px;
        border-radius: 8px;
        background: var(--nr-bg);
        border: 1px solid var(--nr-input-line);
    }

    body[data-theme] #reservationModal .disc-type-btn {
        height: 26px;
        min-width: 32px;
        padding: 0 8px !important;
        border: 0 !important;
        border-radius: 6px !important;
        background: transparent !important;
        color: var(--nr-muted) !important;
        -webkit-text-fill-color: var(--nr-muted) !important;
        font-size: 0.72rem !important;
        font-weight: 700;
        cursor: pointer;
    }

    body[data-theme] #reservationModal .disc-type-btn.active {
        background: #2563eb !important;
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
    }

    body[data-theme] #reservationModal .nr-discount-ctl input[type="number"] {
        width: 130px;
        height: 32px;
        text-align: right;
    }

    #reservationModal #discountTypeLabel {
        min-width: 22px;
        font-size: 0.72rem;
        color: var(--nr-muted) !important;
    }

    #reservationModal .nr-disc-preview {
        text-align: right;
        font-size: 0.68rem !important;
        color: #047857 !important;
    }

    #reservationModal #otaFeeRow {
        padding: 6px 8px !important;
        margin: 4px 0 !important;
        border-radius: 8px;
        background: rgba(217, 119, 6, 0.1) !important;
    }

    #reservationModal #otaFeeRow span {
        color: #b45309 !important;
    }

    #reservationModal #otaFeeAmountDisplay {
        color: #b91c1c !important;
    }

    #reservationModal .price-line-total {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        margin-top: 6px !important;
        padding: 10px 0 2px !important;
        border-top: 1px solid var(--nr-line) !important;
        font-size: 0.72rem !important;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--nr-ink) !important;
        background: none !important;
    }

    #reservationModal #grandTotalDisplay {
        font-size: 1.15rem !important;
        letter-spacing: 0;
        color: #047857 !important;
    }

    body[data-theme="dark"] #reservationModal #grandTotalDisplay,
    body[data-theme="dark"] #reservationModal .nr-disc-preview {
        color: #6ee7b7 !important;
    }

    #reservationModal .nr-pay-row {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    body[data-theme] #reservationModal .btn-pay-all {
        flex-shrink: 0;
        height: 36px;
        padding: 0 14px !important;
        border-radius: 9px !important;
        border: 1px solid #059669 !important;
        background: rgba(5, 150, 105, 0.08) !important;
        color: #047857 !important;
        -webkit-text-fill-color: #047857 !important;
        font-size: 0.74rem !important;
        font-weight: 700;
        cursor: pointer;
    }

    body[data-theme] #reservationModal .btn-pay-all:hover {
        background: #059669 !important;
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
    }

    /* Footer */
    #reservationModal .modal-footer-compact {
        display: flex !important;
        justify-content: flex-end !important;
        gap: 8px !important;
        margin: 0 !important;
        padding: 12px 18px !important;
        border-top: 1px solid var(--nr-line) !important;
        background: var(--nr-soft) !important;
        position: static !important;
    }

    body[data-theme] #reservationModal .btn-cancel,
    body[data-theme] #reservationModal .btn-save {
        height: 38px;
        padding: 0 18px !important;
        border-radius: 10px !important;
        font-size: 0.8rem !important;
        font-weight: 700;
        cursor: pointer;
    }

    body[data-theme] #reservationModal .btn-cancel {
        border: 1px solid var(--nr-line) !important;
        background: var(--nr-bg) !important;
        color: var(--nr-muted) !important;
        -webkit-text-fill-color: var(--nr-muted) !important;
    }

    body[data-theme] #reservationModal .btn-save {
        border: 0 !important;
        background: linear-gradient(135deg, #1e3a8a, #2563eb) !important;
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
        box-shadow: 0 8px 18px -10px rgba(37, 99, 235, 0.8) !important;
    }

    @media (max-width: 600px) {
        #reservationModal .form-row-2col,
        #reservationModal .rooms-checklist {
            grid-template-columns: 1fr !important;
        }
    }

    #reservationModal #grandTotalDisplayCalendar {
        font-size: 1.15rem !important;
        letter-spacing: 0;
        color: #047857 !important;
    }

    body[data-theme="dark"] #reservationModal #grandTotalDisplayCalendar {
        color: #6ee7b7 !important;
    }

    #reservationModal .nr-hint {
        display: flex;
        align-items: center;
        min-height: 36px;
        padding: 6px 10px;
        border-radius: 9px;
        background: rgba(217, 119, 6, 0.08);
        border: 1px solid rgba(217, 119, 6, 0.25);
        color: #b45309 !important;
        font-size: 0.68rem !important;
        line-height: 1.35;
    }

    body[data-theme="dark"] #reservationModal .nr-hint {
        color: #fbbf24 !important;
    }

    #reservationModal [style*="display: none"],
    #reservationModal [style*="display:none"] {
        display: none !important;
    }

    #reservationModal .input-compact#paymentSection,
    #reservationModal .input-compact#guestCountSection {
        flex-direction: column;
    }
    /* ===== Edit Reservation: gaya sama dengan New Reservation ===== */
    #editResModal {
        --nr-bg: #ffffff;
        --nr-soft: #f8fafc;
        --nr-line: #e2e8f0;
        --nr-ink: #0f172a;
        --nr-muted: #64748b;
        --nr-input: #ffffff;
        --nr-input-line: #cbd5e1;
        background: rgba(15, 23, 42, 0.5) !important;
        backdrop-filter: blur(3px);
    }

    body[data-theme="dark"] #editResModal {
        --nr-bg: #0f172a;
        --nr-soft: rgba(255, 255, 255, 0.04);
        --nr-line: rgba(255, 255, 255, 0.1);
        --nr-ink: #e2e8f0;
        --nr-muted: #94a3b8;
        --nr-input: rgba(255, 255, 255, 0.05);
        --nr-input-line: rgba(148, 163, 184, 0.35);
    }

    #editResModal .modal-compact-booking {
        width: 100% !important;
        max-width: 640px !important;
        max-height: calc(100vh - 32px) !important;
        display: flex !important;
        flex-direction: column !important;
        padding: 0 !important;
        border-radius: 16px !important;
        background: var(--nr-bg) !important;
        border: 1px solid var(--nr-line) !important;
        box-shadow: 0 24px 60px -16px rgba(15, 23, 42, 0.45) !important;
        overflow: hidden !important;
    }

    #editResModal .modal-header-compact {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 12px;
        margin: 0 !important;
        padding: 14px 18px !important;
        background: linear-gradient(135deg, #1e3a8a, #2563eb) !important;
        border: 0 !important;
        border-radius: 0 !important;
    }

    #editResModal .modal-header-compact h2 {
        margin: 0 !important;
        font-size: 0.98rem !important;
        font-weight: 700 !important;
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
    }

    #editResModal .modal-header-compact small {
        display: block;
        font-size: 0.68rem !important;
        color: rgba(255, 255, 255, 0.78) !important;
        -webkit-text-fill-color: rgba(255, 255, 255, 0.78) !important;
    }

    #editResModal .close-btn {
        width: 32px !important;
        height: 32px !important;
        border-radius: 10px !important;
        border: 1px solid rgba(255, 255, 255, 0.3) !important;
        background: rgba(255, 255, 255, 0.12) !important;
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
        font-size: 20px !important;
        line-height: 1 !important;
    }

    #editResModal form {
        display: flex;
        flex-direction: column;
        min-height: 0;
        flex: 1;
    }

    #editResModal .form-compact {
        overflow-y: auto !important;
        padding: 6px 18px 14px !important;
        display: block !important;
    }

    #editResModal .nr-sec {
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 12px 0 8px;
        font-size: 0.62rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--nr-muted) !important;
    }

    #editResModal .nr-sec::after {
        content: '';
        flex: 1;
        height: 1px;
        background: var(--nr-line);
    }

    #editResModal .form-row-2col {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 10px !important;
        margin-bottom: 10px !important;
    }

    #editResModal .input-compact {
        margin-bottom: 10px !important;
    }

    #editResModal .form-row-2col .input-compact {
        margin-bottom: 0 !important;
    }

    body[data-theme] #editResModal .input-compact label {
        display: block;
        margin: 0 0 4px !important;
        font-size: 0.62rem !important;
        font-weight: 700 !important;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: var(--nr-muted) !important;
    }

    body[data-theme] #editResModal .input-compact input,
    body[data-theme] #editResModal .input-compact select,
    body[data-theme] #editResModal .input-compact textarea,
    body[data-theme] #editResModal .nr-discount-ctl input[type="number"] {
        width: 100%;
        height: 36px;
        padding: 0 10px !important;
        border-radius: 9px !important;
        border: 1px solid var(--nr-input-line) !important;
        background: var(--nr-input) !important;
        color: var(--nr-ink) !important;
        font-size: 0.8rem !important;
        box-shadow: none !important;
    }

    body[data-theme] #editResModal .input-compact textarea {
        height: auto;
        min-height: 58px;
        padding: 8px 10px !important;
        line-height: 1.45;
        resize: vertical;
    }

    body[data-theme] #editResModal .input-compact input:focus,
    body[data-theme] #editResModal .input-compact select:focus,
    body[data-theme] #editResModal .input-compact textarea:focus,
    body[data-theme] #editResModal .nr-discount-ctl input:focus {
        outline: none !important;
        border-color: #2563eb !important;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15) !important;
    }

    /* Pilih kamar: kartu 2 kolom */
    #editResModal .nr-label-row {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 8px;
    }

    #editResModal #availabilityInfo small,
    #editResModal #availabilityInfo {
        font-size: 0.66rem !important;
    }

    #editResModal .rooms-checklist {
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 6px !important;
        max-height: 220px !important;
        overflow-y: auto !important;
        padding: 6px !important;
        border-radius: 10px !important;
        border: 1px solid var(--nr-line) !important;
        background: var(--nr-soft) !important;
    }

    #editResModal .rooms-checklist > em,
    #editResModal .rooms-checklist > div {
        grid-column: 1 / -1;
        padding: 14px;
        font-size: 0.74rem;
        text-align: center;
    }

    body[data-theme] #editResModal .room-checkbox-item {
        display: flex !important;
        align-items: center;
        gap: 9px;
        margin: 0 !important;
        padding: 8px 10px !important;
        border-radius: 9px !important;
        border: 1px solid var(--nr-line) !important;
        background: var(--nr-bg) !important;
        cursor: pointer;
        font-size: 0.76rem !important;
        text-transform: none !important;
        letter-spacing: 0 !important;
        transition: border-color 0.15s, background 0.15s;
    }

    #editResModal .room-checkbox-item:hover {
        border-color: #93c5fd !important;
    }

    #editResModal .room-checkbox-item:has(input:checked) {
        border-color: #2563eb !important;
        background: rgba(37, 99, 235, 0.08) !important;
        box-shadow: inset 0 0 0 1px #2563eb;
    }

    body[data-theme] #editResModal .room-checkbox-item input[type="checkbox"] {
        width: 16px !important;
        height: 16px !important;
        margin: 0 !important;
        flex-shrink: 0;
        accent-color: #2563eb;
    }

    #editResModal .room-checkbox-item .nr-room {
        display: flex;
        flex-direction: column;
        min-width: 0;
        line-height: 1.25;
    }

    #editResModal .room-checkbox-item .nr-room b {
        font-size: 0.8rem;
        color: var(--nr-ink) !important;
    }

    #editResModal .room-checkbox-item .nr-room small {
        font-size: 0.66rem;
        color: var(--nr-muted) !important;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    #editResModal .room-checkbox-item .nr-price {
        margin-left: auto;
        font-size: 0.7rem;
        font-weight: 700;
        color: #047857 !important;
        white-space: nowrap;
    }

    body[data-theme="dark"] #editResModal .room-checkbox-item .nr-price {
        color: #6ee7b7 !important;
    }

    #editResModal .nr-selected {
        margin-top: 6px;
        font-size: 0.72rem !important;
        color: #1d4ed8 !important;
    }

    body[data-theme="dark"] #editResModal .nr-selected {
        color: #93c5fd !important;
    }

    /* Ringkasan harga */
    #editResModal .price-summary-compact {
        margin: 4px 0 12px !important;
        padding: 10px 14px !important;
        border-radius: 12px !important;
        background: var(--nr-soft) !important;
        border: 1px solid var(--nr-line) !important;
    }

    #editResModal .price-line {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 10px;
        padding: 4px 0 !important;
        margin: 0 !important;
        font-size: 0.78rem !important;
        color: var(--nr-muted) !important;
        background: none !important;
    }

    #editResModal .price-line strong {
        color: var(--nr-ink) !important;
        font-size: 0.8rem !important;
    }

    #editResModal .nr-discount-ctl {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    #editResModal .discount-type-toggle {
        display: inline-flex;
        padding: 2px;
        border-radius: 8px;
        background: var(--nr-bg);
        border: 1px solid var(--nr-input-line);
    }

    body[data-theme] #editResModal .disc-type-btn {
        height: 26px;
        min-width: 32px;
        padding: 0 8px !important;
        border: 0 !important;
        border-radius: 6px !important;
        background: transparent !important;
        color: var(--nr-muted) !important;
        -webkit-text-fill-color: var(--nr-muted) !important;
        font-size: 0.72rem !important;
        font-weight: 700;
        cursor: pointer;
    }

    body[data-theme] #editResModal .disc-type-btn.active {
        background: #2563eb !important;
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
    }

    body[data-theme] #editResModal .nr-discount-ctl input[type="number"] {
        width: 130px;
        height: 32px;
        text-align: right;
    }

    #editResModal #discountTypeLabel {
        min-width: 22px;
        font-size: 0.72rem;
        color: var(--nr-muted) !important;
    }

    #editResModal .nr-disc-preview {
        text-align: right;
        font-size: 0.68rem !important;
        color: #047857 !important;
    }

    #editResModal #otaFeeRow {
        padding: 6px 8px !important;
        margin: 4px 0 !important;
        border-radius: 8px;
        background: rgba(217, 119, 6, 0.1) !important;
    }

    #editResModal #otaFeeRow span {
        color: #b45309 !important;
    }

    #editResModal #otaFeeAmountDisplay {
        color: #b91c1c !important;
    }

    #editResModal .price-line-total {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        margin-top: 6px !important;
        padding: 10px 0 2px !important;
        border-top: 1px solid var(--nr-line) !important;
        font-size: 0.72rem !important;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--nr-ink) !important;
        background: none !important;
    }

    #editResModal #grandTotalDisplay {
        font-size: 1.15rem !important;
        letter-spacing: 0;
        color: #047857 !important;
    }

    body[data-theme="dark"] #editResModal #grandTotalDisplay,
    body[data-theme="dark"] #editResModal .nr-disc-preview {
        color: #6ee7b7 !important;
    }

    #editResModal .nr-pay-row {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    body[data-theme] #editResModal .btn-pay-all {
        flex-shrink: 0;
        height: 36px;
        padding: 0 14px !important;
        border-radius: 9px !important;
        border: 1px solid #059669 !important;
        background: rgba(5, 150, 105, 0.08) !important;
        color: #047857 !important;
        -webkit-text-fill-color: #047857 !important;
        font-size: 0.74rem !important;
        font-weight: 700;
        cursor: pointer;
    }

    body[data-theme] #editResModal .btn-pay-all:hover {
        background: #059669 !important;
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
    }

    /* Footer */
    #editResModal .modal-footer-compact {
        display: flex !important;
        justify-content: flex-end !important;
        gap: 8px !important;
        margin: 0 !important;
        padding: 12px 18px !important;
        border-top: 1px solid var(--nr-line) !important;
        background: var(--nr-soft) !important;
        position: static !important;
    }

    body[data-theme] #editResModal .btn-cancel,
    body[data-theme] #editResModal .btn-save {
        height: 38px;
        padding: 0 18px !important;
        border-radius: 10px !important;
        font-size: 0.8rem !important;
        font-weight: 700;
        cursor: pointer;
    }

    body[data-theme] #editResModal .btn-cancel {
        border: 1px solid var(--nr-line) !important;
        background: var(--nr-bg) !important;
        color: var(--nr-muted) !important;
        -webkit-text-fill-color: var(--nr-muted) !important;
    }

    body[data-theme] #editResModal .btn-save {
        border: 0 !important;
        background: linear-gradient(135deg, #1e3a8a, #2563eb) !important;
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
        box-shadow: 0 8px 18px -10px rgba(37, 99, 235, 0.8) !important;
    }

    @media (max-width: 600px) {
        #editResModal .form-row-2col,
        #editResModal .rooms-checklist {
            grid-template-columns: 1fr !important;
        }
    }

    #editResModal #grandTotalDisplayCalendar {
        font-size: 1.15rem !important;
        letter-spacing: 0;
        color: #047857 !important;
    }

    body[data-theme="dark"] #editResModal #grandTotalDisplayCalendar {
        color: #6ee7b7 !important;
    }

    #editResModal .nr-hint {
        display: flex;
        align-items: center;
        min-height: 36px;
        padding: 6px 10px;
        border-radius: 9px;
        background: rgba(217, 119, 6, 0.08);
        border: 1px solid rgba(217, 119, 6, 0.25);
        color: #b45309 !important;
        font-size: 0.68rem !important;
        line-height: 1.35;
    }

    body[data-theme="dark"] #editResModal .nr-hint {
        color: #fbbf24 !important;
    }

    #editResModal [style*="display: none"],
    #editResModal [style*="display:none"] {
        display: none !important;
    }

    #editResModal .input-compact#paymentSection,
    #editResModal .input-compact#guestCountSection {
        flex-direction: column;
    }

    #editResModal {
        padding: 16px;
    }

    body[data-theme] #editResModal textarea {
        font-family: inherit !important;
    }

    #editResModal .er-ota {
        padding: 6px 8px !important;
        margin: 4px 0 !important;
        border-radius: 8px;
        background: rgba(217, 119, 6, 0.1) !important;
    }

    #editResModal .er-ota span,
    #editResModal .er-ota small {
        color: #b45309 !important;
    }

    #editResModal .er-ota strong {
        color: #b91c1c !important;
    }

    #editResModal #editResTotal {
        font-size: 1.15rem !important;
        letter-spacing: 0;
        color: #047857 !important;
    }

    body[data-theme="dark"] #editResModal #editResTotal {
        color: #6ee7b7 !important;
    }

    #editResModal .er-group {
        margin: 0 0 10px;
        padding: 10px 12px;
        border-radius: 10px;
        background: var(--nr-soft);
        border: 1px solid var(--nr-line);
    }

    #editResModal .er-group-title {
        margin-bottom: 6px;
        font-size: 0.62rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--nr-muted) !important;
    }

    #editResModal .er-room {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        padding: 6px 0;
        border-top: 1px dashed var(--nr-line);
        font-size: 0.74rem;
        color: var(--nr-ink) !important;
    }

    #editResModal .er-room:first-of-type {
        border-top: 0;
    }

    #editResModal .er-room small {
        color: var(--nr-muted) !important;
    }

    #editResModal .er-room .on {
        margin-left: 4px;
        padding: 1px 6px;
        border-radius: 999px;
        background: rgba(5, 150, 105, 0.12);
        color: #047857 !important;
        font-size: 0.6rem;
        font-weight: 700;
    }

    /* Hint pilih tanggal di kalender */
    #clickBookingHint {
        position: fixed;
        left: 50%;
        bottom: 22px;
        z-index: 9999;
        display: flex;
        align-items: center;
        gap: 12px;
        max-width: calc(100vw - 32px);
        padding: 10px 16px 10px 10px;
        border-radius: 14px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-left: 4px solid #2563eb;
        box-shadow: 0 18px 40px -14px rgba(15, 23, 42, 0.45);
        transform: translateX(-50%);
        pointer-events: none;
        animation: cbhIn 0.22s ease-out;
    }

    body[data-theme="dark"] #clickBookingHint {
        background: #111a2e;
        border-color: rgba(255, 255, 255, 0.12);
        border-left-color: #60a5fa;
    }

    #clickBookingHint .cbh-ic {
        flex-shrink: 0;
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(37, 99, 235, 0.1);
        color: #2563eb;
    }

    body[data-theme] #clickBookingHint b.cbh-title {
        display: block;
        font-size: 0.8rem;
        font-weight: 700;
        color: #0f172a !important;
        -webkit-text-fill-color: #0f172a !important;
    }

    body[data-theme] #clickBookingHint small {
        display: block;
        margin-top: 1px;
        font-size: 0.7rem;
        color: #64748b !important;
        -webkit-text-fill-color: #64748b !important;
    }

    body[data-theme="dark"] #clickBookingHint b.cbh-title {
        color: #e2e8f0 !important;
        -webkit-text-fill-color: #e2e8f0 !important;
    }

    body[data-theme="dark"] #clickBookingHint small {
        color: #94a3b8 !important;
        -webkit-text-fill-color: #94a3b8 !important;
    }

    #clickBookingHint kbd {
        padding: 1px 5px;
        border-radius: 4px;
        border: 1px solid #cbd5e1;
        font-family: inherit;
        font-size: 0.62rem;
    }

    @keyframes cbhIn {
        from { opacity: 0; transform: translate(-50%, 10px); }
    }

    /* Popup sukses edit */
    .er-ok {
        position: fixed;
        inset: 0;
        z-index: 10090;
        display: flex;
        align-items: center;
        justify-content: center;
        pointer-events: none;
        background: rgba(15, 23, 42, 0.18);
        animation: erOkFade 1.5s ease forwards;
    }

    .er-ok-card {
        min-width: 240px;
        padding: 22px 26px 20px;
        border-radius: 18px;
        background: #fff;
        box-shadow: 0 24px 60px -16px rgba(15, 23, 42, 0.45);
        text-align: center;
        animation: erOkIn 0.28s cubic-bezier(.2, 1.3, .5, 1) both;
    }

    body[data-theme="dark"] .er-ok-card {
        background: #111a2e;
    }

    .er-ok-card svg {
        display: block;
        width: 58px;
        height: 58px;
        margin: 0 auto 10px;
    }

    .er-ok-card circle {
        fill: #059669;
    }

    .er-ok-card path {
        fill: none;
        stroke: #fff;
        stroke-width: 4.5;
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-dasharray: 40;
        stroke-dashoffset: 40;
        animation: erOkDraw 0.35s 0.15s ease-out forwards;
    }

    body[data-theme] .er-ok-card b {
        display: block;
        font-size: 0.95rem;
        color: #0f172a !important;
        -webkit-text-fill-color: #0f172a !important;
    }

    body[data-theme="dark"] .er-ok-card b {
        color: #e2e8f0 !important;
        -webkit-text-fill-color: #e2e8f0 !important;
    }

    @keyframes erOkIn { from { opacity: 0; transform: scale(0.85); } }
    @keyframes erOkDraw { to { stroke-dashoffset: 0; } }
    @keyframes erOkFade { 0%, 80% { opacity: 1; } 100% { opacity: 0; visibility: hidden; } }
</style>

<style>
    /* SYSTEM 2028 STYLES */
    .glass-panel {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        border-radius: 20px;
        padding: 0 !important;
        overflow: hidden;
    }

    body[data-theme="dark"] .glass-panel {
        background: rgba(30, 41, 59, 0.95);
        border: 1px solid rgba(255, 255, 255, 0.05);
    }

    .modal-header {
        padding: 1.5rem 2rem;
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    }

    .modal-header h2 {
        margin: 0;
        font-size: 1.5rem;
        background: linear-gradient(to right, #6366f1, #8b5cf6);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    .form-grid-2028 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 2rem;
        padding: 2rem;
        overflow-y: auto;
        /* Enable scroll here */
        flex: 1;
        /* Take remaining space */
    }

    /* Scrollbar for form grid */
    .form-grid-2028::-webkit-scrollbar {
        width: 6px;
    }

    .form-grid-2028::-webkit-scrollbar-track {
        background: rgba(99, 102, 241, 0.05);
    }

    .form-grid-2028::-webkit-scrollbar-thumb {
        background: rgba(99, 102, 241, 0.2);
        border-radius: 3px;
    }

    .form-grid-2028::-webkit-scrollbar-thumb:hover {
        background: rgba(99, 102, 241, 0.4);
    }

    @media (max-width: 768px) {
        .form-grid-2028 {
            grid-template-columns: 1fr;
            gap: 1rem;
            padding: 1rem;
        }
    }

    .form-section-modern {
        margin-bottom: 2rem;
    }

    .form-section-modern h3 {
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #64748b;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .input-group-modern {
        margin-bottom: 1rem;
    }

    .input-group-modern label {
        display: block;
        font-size: 0.8rem;
        font-weight: 600;
        margin-bottom: 0.4rem;
        color: var(--text-secondary);
    }

    .input-group-modern input,
    .input-group-modern select {
        width: 100%;
        padding: 0.75rem 1rem;
        border-radius: 10px;
        border: 1px solid var(--border-color);
        background: var(--input-bg);
        color: var(--text-primary);
        transition: all 0.2s;
    }

    .input-group-modern input:focus,
    .input-group-modern select:focus {
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        outline: none;
    }

    .date-range-modern {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }

    .price-card-2028 {
        background: var(--bg-secondary);
        border-radius: 16px;
        padding: 1.5rem;
        border: 1px solid var(--border-color);
    }

    .price-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
        font-size: 0.9rem;
    }

    .price-row input {
        width: 100px;
        text-align: right;
        background: transparent;
        border: 1px solid transparent;
        color: var(--text-primary);
        font-weight: 600;
    }

    .price-row input:hover {
        border-color: var(--border-color);
        background: var(--input-bg);
        border-radius: 4px;
    }

    .total-display {
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px dashed var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .total-display strong {
        font-size: 1.5rem;
        color: #6366f1;
    }

    .payment-methods-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr 1fr;
        gap: 0.5rem;
        margin-top: 1rem;
    }

    .pm-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 0.75rem 0.25rem;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.2s;
        background: var(--card-bg);
    }

    .pm-item.active {
        background: rgba(99, 102, 241, 0.1);
        border-color: #6366f1;
        color: #6366f1;
    }

    .pm-icon {
        font-size: 1.25rem;
        margin-bottom: 0.25rem;
    }

    .pm-name {
        font-size: 0.7rem;
        font-weight: 600;
    }

    .modal-footer-modern {
        padding: 1rem 2rem;
        display: flex;
        justify-content: flex-end;
        gap: 1rem;
        background: white;
        /* Ensure opaque background */
        border-top: 1px solid var(--border-color);
        flex-shrink: 0;
        z-index: 10;
    }

    body[data-theme="dark"] .modal-footer-modern {
        background: #1e293b;
    }

    .modal-header {
        flex-shrink: 0;
    }

    .btn-glow {
        background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
        color: white;
        border: none;
        padding: 0.75rem 2rem;
        border-radius: 12px;
        font-weight: 600;
        box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-glow:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
    }

    .btn-ghost {
        background: transparent;
        border: none;
        color: var(--text-secondary);
        font-weight: 600;
        cursor: pointer;
        padding: 0.75rem 1.5rem;
    }

    .pax-inputs {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .pax-inputs input {
        width: 60px;
        text-align: center;
        padding: 0.5rem;
        border-radius: 8px;
        border: 1px solid var(--border-color);
        background: var(--input-bg);
        color: var(--text-primary);
    }

    .fee-badge {
        display: inline-block;
        background: rgba(244, 63, 94, 0.1);
        color: #f43f5e;
        padding: 0.25rem 0.5rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 700;
        margin-top: 0.5rem;
    }
</style>
<!-- END RESERVATION MODAL -->




<div id="bookingDetailsModal" class="modal-overlay" style="display: none !important;">
    <div class="modal-content modal-content-medium"></div>
</div>

<!-- Guest Detail Side Panel (Cloudbed-style) -->
<div id="bookingQuickView" class="guest-side-panel-overlay" onclick="if(event.target===this)closeBookingQuickView()">
    <div class="guest-side-panel">
        <div class="side-panel-header">
            <div class="side-panel-header-left">
                <div class="guest-avatar" id="sp-avatar">MS</div>
                <div class="guest-header-info">
                    <h2 id="sp-guest-name">Guest Name</h2>
                    <p id="sp-sub" class="sp-sub">-</p>
                    <p id="sp-guest-phone" class="guest-phone-text">-</p>
                </div>
            </div>
            <div class="side-panel-header-right">
                <div class="sp-print" id="spPrint">
                    <button type="button" class="sp-icon-btn" onclick="toggleSpPrint(event)" title="Print">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8" rx="1"/></svg>
                    </button>
                    <div class="sp-print-menu">
                        <button type="button" onclick="spPrint('invoice')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg><span><b>Print Invoice</b><small>Tagihan &amp; pembayaran</small></span></button>
                        <button type="button" onclick="spPrint('regcard')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M15 8h2M15 12h2M7 16h10"/></svg><span><b>Print Registration Card</b><small>Untuk check-in · house rules &amp; tanda tangan</small></span></button>
                        <button type="button" onclick="spPrint('deposit')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/><path d="M9 12l2 2 4-4"/></svg><span><b>Tanda Terima Deposit</b><small>Catat jaminan uang / kartu identitas · cetak bila perlu</small></span></button>
                    </div>
                </div>
                <div class="sp-print" id="spWa">
                    <button type="button" id="sp-wa-link" class="sp-icon-btn" title="WhatsApp" onclick="toggleSpWa(event)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="#25D366">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z" />
                        <path d="M12 0C5.373 0 0 5.373 0 12c0 2.625.846 5.059 2.284 7.034L.789 23.468l4.584-1.454A11.935 11.935 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.75c-2.115 0-4.09-.654-5.712-1.77l-.41-.262-2.717.862.724-2.632-.287-.446A9.714 9.714 0 012.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75z" />
                    </svg>
                    </button>
                    <div class="sp-print-menu">
                        <button type="button" onclick="spWaInvoice()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg><span><b>Kirim Invoice + PDF</b><small>Pesan template English + link invoice · WhatsApp Web</small></span></button>
                        <button type="button" onclick="spWaChat()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span><b>Chat WhatsApp biasa</b><small>Buka percakapan dengan tamu</small></span></button>
                    </div>
                </div>
                <button class="sp-icon-btn" onclick="closeBookingQuickView()" title="Close">×</button>
            </div>
        </div>

        <!-- Status Badge -->
        <div class="sp-status-row">
            <span class="sp-status-badge" id="sp-status">● Confirmed</span>
            <span class="sp-source-badge" id="sp-source">Walk-In</span>
        </div>

        <!-- Booking Timeline -->
        <div class="sp-timeline">
            <div class="sp-timeline-track">
                <div class="sp-timeline-progress" id="sp-timeline-progress"></div>
            </div>
            <div class="sp-timeline-labels">
                <div class="sp-timeline-point">
                    <span class="sp-timeline-label">Booked</span>
                    <span class="sp-timeline-date" id="sp-booked-date">-</span>
                </div>
                <div class="sp-timeline-point">
                    <span class="sp-timeline-label">Check-in</span>
                    <span class="sp-timeline-date" id="sp-checkin-date">-</span>
                </div>
                <div class="sp-timeline-point">
                    <span class="sp-timeline-label">Check-out</span>
                    <span class="sp-timeline-date" id="sp-checkout-date">-</span>
                </div>
            </div>
        </div>

        <!-- Guest Info Row -->
        <div class="sp-guest-info-row">
            <div class="sp-info-icon" title="Dewasa"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                    <circle cx="12" cy="7" r="4" />
                </svg> <b id="sp-adults">1</b> dewasa</div>
            <div class="sp-info-icon" title="Anak"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="7" r="4" />
                    <path d="M5.5 21v-2a4 4 0 0 1 3-3.87M18.5 21v-2a4 4 0 0 0-3-3.87" />
                </svg> <b id="sp-children">0</b> anak</div>
            <div class="sp-info-icon" title="Malam"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
                </svg> <b id="sp-nights">1</b> malam</div>
        </div>

        <!-- Tabs -->
        <div class="sp-tabs">
            <button class="sp-tab active" onclick="switchSPTab('folio')">Folio</button>
            <button class="sp-tab" onclick="switchSPTab('details')">Details</button>
            <button class="sp-tab" onclick="switchSPTab('room')">Room</button>
        </div>

        <!-- Tab Content: Folio -->
        <div class="sp-tab-content active" id="sp-tab-folio">
            <div class="sp-dep" id="sp-deposit" style="display:none;"></div>
            <div class="sp-balance-box" id="sp-balance-box">
                <div>
                    <div class="sp-balance-label" id="sp-balance-label">Balance due</div>
                    <div class="sp-balance-amount" id="sp-balance">Rp0</div>
                </div>
                <button type="button" class="sp-note-btn" onclick="openRoomNoteEditor(currentPaymentBooking.id)">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                    Catatan
                </button>
            </div>
            <!-- Hotel Service bill: separate from the room balance (not recorded in Cloudbeds) -->
            <div class="sp-svc-box" id="sp-svc-box" style="display:none;">
                <div>
                    <div class="sp-svc-label">Hotel Service · belum dibayar</div>
                    <div class="sp-svc-amount" id="sp-svc-amount">Rp0</div>
                </div>
                <div class="sp-svc-total" id="sp-svc-total"></div>
            </div>
            <div id="sp-folio-note-banner" class="sp-note-banner" style="display:none;">
                <span class="status-dot dot-yellow" style="position:static;margin-top:3px;flex-shrink:0;"></span>
                <span id="sp-folio-note-text"></span>
            </div>
            <table class="sp-folio-table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th class="text-right">Debit</th>
                        <th class="text-right">Credit</th>
                    </tr>
                </thead>
                <tbody id="sp-folio-body">
                    <!-- Populated by JS -->
                </tbody>
                <tfoot>
                    <tr class="sp-folio-total">
                        <td>Total</td>
                        <td class="text-right" id="sp-total-debit">-</td>
                        <td class="text-right" id="sp-total-credit">-</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Tab Content: Details -->
        <div class="sp-tab-content" id="sp-tab-details">
            <div class="sp-detail-section">
                <h4>Reservation Details</h4>
                <div class="sp-detail-row"><span>Booking Code</span><strong id="sp-booking-code">-</strong></div>
                <div class="sp-detail-row"><span>Booking Source</span><strong id="sp-detail-source">-</strong></div>
                <div class="sp-detail-row"><span>Check-in</span><strong id="sp-detail-checkin">-</strong></div>
                <div class="sp-detail-row"><span>Check-out</span><strong id="sp-detail-checkout">-</strong></div>
                <div class="sp-detail-row"><span>Nights</span><strong id="sp-detail-nights">-</strong></div>
                <div class="sp-detail-row"><span>Guests</span><strong id="sp-detail-guests">-</strong></div>
                <div class="sp-detail-row"><span>Special Request</span><strong id="sp-detail-notes" style="font-style:italic;font-weight:400;">-</strong></div>
            </div>
            <div class="sp-detail-section" id="sp-extras-section" style="display:none;">
                <h4>Extras</h4>
                <div id="sp-extras-list"></div>
            </div>
        </div>

        <!-- Tab Content: Room -->
        <div class="sp-tab-content" id="sp-tab-room">
            <div class="sp-room-card">
                <div class="sp-room-type" id="sp-room-type">-</div>
                <div class="sp-room-number" id="sp-room-number">Room -</div>
                <div class="sp-room-price">
                    <span>Price/night:</span>
                    <strong id="sp-room-price-val">-</strong>
                </div>
            </div>

            <!-- Group Bookings / Related Rooms -->
            <div id="sp-group-rooms-section" class="sp-detail-section" style="display:none;">
                <h4>Kamar dalam grup</h4>
                <div id="sp-group-rooms-list" class="sp-group-list"></div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="sp-actions" id="sp-actions">
            <!-- Populated by JS -->
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div id="bookingPaymentModal" class="modal-overlay">
    <div class="payment-modal">
        <button class="payment-modal-close" onclick="closeBookingPaymentModal()">×</button>
        <div class="payment-modal-header">
            <h3>Payment</h3>
            <p id="paymentModalSubtitle">Pembayaran booking</p>
        </div>
        <div class="payment-modal-body">
            <div class="payment-info">
                <div><span>Total:</span> <strong id="paymentTotal">Rp 0</strong></div>
                <div><span>Sudah Bayar:</span> <strong id="paymentPaid">Rp 0</strong></div>
                <div><span>Sisa:</span> <strong id="paymentRemaining">Rp 0</strong></div>
            </div>
            <div class="pay-src" id="paySrcInfo"></div>
            <div class="form-group">
                <label>Metode Pembayaran</label>
                <input type="hidden" id="paymentMethodPay" value="cash">
                <div class="payment-method-group">
                    <button type="button" class="payment-method-btn" data-value="ota">OTA</button>
                    <button type="button" class="payment-method-btn active" data-value="cash">Cash</button>
                    <button type="button" class="payment-method-btn" data-value="transfer">Transfer</button>
                    <button type="button" class="payment-method-btn" data-value="qris">QR</button>
                </div>
            </div>
            <div class="form-group">
                <label>Jumlah Bayar (Rp)</label>
                <input type="number" id="paymentAmount" min="0" value="0" oninput="updatePayOtaNet()">
            </div>
            <div class="pay-ota-net" id="payOtaNet" style="display:none;"></div>
            <div class="payment-modal-actions">
                <button type="button" class="btn-secondary" onclick="closeBookingPaymentModal()">Cancel</button>
                <button type="button" class="btn-primary" onclick="submitBookingPayment()">Pay</button>
            </div>
        </div>
    </div>
</div>

<style>
    /* RESERVATION MODAL STYLES */
    #reservationModal {
        display: none;
        /* Changed from none!important to allow flex via JS */
        align-items: center;
        justify-content: center;
        z-index: 9999;
    }

    #reservationModal.active {
        display: flex !important;
    }

    .booking-summary-box {
        background: #f8fafc;
        padding: 1rem;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
        font-size: 0.9rem;
    }

    .summary-input {
        width: 120px !important;
        text-align: right;
        padding: 0.25rem 0.5rem !important;
    }

    .total-row {
        margin-top: 0.75rem;
        padding-top: 0.75rem;
        border-top: 1px dashed #cbd5e1;
        font-size: 1.1rem;
        color: #6366f1;
    }

    /* Modal Overlay Base Styles */
    .modal-overlay {
        display: none;
        /* Hidden by default */
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(4px);
        z-index: 99999;
        /* High Z-index */
        align-items: center;
        justify-content: center;
        transition: opacity 0.3s ease;
        opacity: 0;
        pointer-events: none;
    }

    /* Active State for Modals */
    .modal-overlay.active {
        display: flex !important;
        opacity: 1;
        pointer-events: auto;
    }

    /* ========== SEARCH BAR ========== */
    .search-reservation-bar {
        position: relative;
        margin-bottom: 0.4rem;
    }

    .search-input-wrapper {
        display: flex;
        align-items: center;
        background: #fff;
        border: 1.5px solid #cbd5e1;
        border-radius: 10px;
        padding: 0.5rem 0.85rem;
        gap: 0.5rem;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    .search-input-wrapper:focus-within {
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
    }

    .search-icon {
        color: #94a3b8;
        flex-shrink: 0;
    }

    .search-input {
        border: none;
        outline: none;
        font-size: 0.9rem;
        color: #334155;
        flex: 1;
        background: transparent;
        font-weight: 500;
    }

    .search-input::placeholder {
        color: #94a3b8;
        font-weight: 400;
    }

    .search-clear-btn {
        background: none;
        border: none;
        font-size: 1.3rem;
        color: #94a3b8;
        cursor: pointer;
        line-height: 1;
        padding: 0 2px;
    }

    .search-clear-btn:hover {
        color: #ef4444;
    }

    .search-results-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
        max-height: 380px;
        overflow-y: auto;
        z-index: 999;
        margin-top: 4px;
    }

    .search-result-item {
        display: flex;
        align-items: center;
        padding: 0.65rem 0.85rem;
        cursor: pointer;
        border-bottom: 1px solid #f1f5f9;
        gap: 0.65rem;
        transition: background 0.15s;
    }

    .search-result-item:last-child {
        border-bottom: none;
    }

    .search-result-item:hover {
        background: #f8fafc;
    }

    .sr-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.7rem;
        font-weight: 800;
        flex-shrink: 0;
        letter-spacing: 0.5px;
    }

    .sr-info {
        flex: 1;
        min-width: 0;
    }

    .sr-name {
        font-weight: 700;
        font-size: 0.85rem;
        color: #1e293b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .sr-meta {
        font-size: 0.72rem;
        color: #64748b;
        margin-top: 1px;
    }

    .sr-status {
        font-size: 0.65rem;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 4px;
        text-transform: uppercase;
        flex-shrink: 0;
    }

    .sr-status.checked_in {
        background: #dcfce7;
        color: #16a34a;
    }

    .sr-status.confirmed {
        background: #dbeafe;
        color: #2563eb;
    }

    .sr-status.pending {
        background: #fef3c7;
        color: #d97706;
    }

    .sr-status.checked_out {
        background: #f1f5f9;
        color: #64748b;
    }

    .sr-status.cancelled {
        background: #fce4ec;
        color: #e53935;
    }

    .search-no-result {
        text-align: center;
        padding: 1.5rem;
        color: #94a3b8;
        font-size: 0.85rem;
    }

    /* ========== GUEST SIDE PANEL (Cloudbed-style) ========== */
    .guest-side-panel-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.35);
        backdrop-filter: blur(2px);
        z-index: 10000;
        justify-content: flex-end;
    }

    .guest-side-panel-overlay.active {
        display: flex !important;
    }

    .guest-side-panel {
        width: 480px;
        max-width: 95vw;
        height: 100vh;
        background: #fff;
        box-shadow: -8px 0 40px rgba(0, 0, 0, 0.15);
        overflow-y: auto;
        padding: 1.5rem;
        animation: slidePanelIn 0.25s ease-out;
        display: flex;
        flex-direction: column;
    }

    @keyframes slidePanelIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }

        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    .side-panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 0.75rem;
    }

    .side-panel-header-left {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .guest-avatar {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        font-weight: 800;
        letter-spacing: 0.5px;
        flex-shrink: 0;
    }

    .guest-header-info h2 {
        margin: 0;
        font-size: 1.15rem;
        font-weight: 800;
        color: #1e293b;
        line-height: 1.2;
    }

    .guest-phone-text {
        margin: 2px 0 0;
        font-size: 0.8rem;
        color: #64748b;
    }

    .side-panel-header-right {
        display: flex;
        align-items: center;
        gap: 0.35rem;
    }

    .sp-icon-btn {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        border: 1px solid #e2e8f0;
        background: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 1.3rem;
        color: #64748b;
        transition: all 0.2s;
        text-decoration: none;
    }

    .sp-icon-btn:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
    }

    .sp-status-row {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }

    .sp-status-badge {
        font-size: 0.78rem;
        font-weight: 700;
        padding: 4px 12px;
        border-radius: 20px;
        background: #dbeafe;
        color: #2563eb;
    }

    .sp-source-badge {
        font-size: 0.72rem;
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 20px;
        background: #f1f5f9;
        color: #475569;
    }

    /* Timeline */
    .sp-timeline {
        margin-bottom: 1rem;
        padding: 0.75rem;
        background: #f8fafc;
        border-radius: 10px;
    }

    .sp-timeline-track {
        height: 4px;
        background: #e2e8f0;
        border-radius: 2px;
        margin-bottom: 0.5rem;
        position: relative;
    }

    .sp-timeline-progress {
        height: 100%;
        background: linear-gradient(90deg, #6366f1, #8b5cf6);
        border-radius: 2px;
        transition: width 0.3s;
    }

    .sp-timeline-labels {
        display: flex;
        justify-content: space-between;
    }

    .sp-timeline-point {
        text-align: center;
    }

    .sp-timeline-label {
        display: block;
        font-size: 0.68rem;
        color: #94a3b8;
        font-weight: 600;
        text-transform: uppercase;
    }

    .sp-timeline-date {
        display: block;
        font-size: 0.75rem;
        color: #334155;
        font-weight: 700;
    }

    /* Guest info row */
    .sp-guest-info-row {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
        padding: 0.5rem 0.75rem;
        background: #f8fafc;
        border-radius: 8px;
    }

    .sp-info-icon {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 0.82rem;
        font-weight: 700;
        color: #475569;
    }

    .sp-info-icon svg {
        color: #6366f1;
    }

    /* Tabs */
    .sp-tabs {
        display: flex;
        border-bottom: 2px solid #e2e8f0;
        margin-bottom: 0;
    }

    .sp-tab {
        padding: 0.5rem 1rem;
        border: none;
        background: none;
        font-size: 0.82rem;
        font-weight: 600;
        color: #94a3b8;
        cursor: pointer;
        border-bottom: 2px solid transparent;
        margin-bottom: -2px;
        transition: all 0.2s;
    }

    .sp-tab:hover {
        color: #475569;
    }

    .sp-tab.active {
        color: #1e3a5f;
        border-bottom-color: #1e3a5f;
    }

    /* Tab content */
    .sp-tab-content {
        display: none;
        padding: 1rem 0;
        flex: 1;
        overflow-y: auto;
        min-height: 0;
    }

    .sp-tab-content.active {
        display: block;
        overflow-y: auto;
    }

    /* Balance box */
    .sp-balance-box {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.65rem 0.85rem;
        background: #f8fafc;
        border-radius: 8px;
        margin-bottom: 0.75rem;
        border: 1px solid #e2e8f0;
    }

    .sp-balance-label {
        font-size: 0.82rem;
        color: #64748b;
        font-weight: 600;
    }

    .sp-balance-amount {
        font-size: 1.05rem;
        font-weight: 800;
        color: #1e293b;
    }

    /* Folio table */
    .sp-folio-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8rem;
    }

    .sp-folio-table th {
        text-align: left;
        padding: 0.5rem 0.4rem;
        font-size: 0.72rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        border-bottom: 2px solid #e2e8f0;
    }

    .sp-folio-table td {
        padding: 0.55rem 0.4rem;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
        vertical-align: top;
    }

    .sp-folio-table .text-right {
        text-align: right;
    }

    .sp-folio-table .folio-desc-title {
        font-weight: 600;
        font-size: 0.78rem;
    }

    .sp-folio-table .folio-desc-sub {
        font-size: 0.7rem;
        color: #94a3b8;
        margin-top: 1px;
    }

    .sp-folio-total td {
        font-weight: 700;
        border-top: 2px solid #cbd5e1;
        padding-top: 0.6rem;
        color: #1e293b;
    }

    /* Detail section */
    .sp-detail-section {
        margin-bottom: 1rem;
    }

    .sp-detail-section h4 {
        font-size: 0.82rem;
        font-weight: 700;
        color: #475569;
        margin: 0 0 0.5rem;
        padding-bottom: 0.35rem;
        border-bottom: 1px solid #e2e8f0;
    }

    .sp-detail-row {
        display: flex;
        justify-content: space-between;
        padding: 0.35rem 0;
        font-size: 0.8rem;
    }

    .sp-detail-row span {
        color: #64748b;
    }

    .sp-detail-row strong {
        color: #1e293b;
    }

    /* Room card */
    .sp-room-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 1rem;
        text-align: center;
    }

    .sp-room-type {
        font-size: 0.78rem;
        color: #64748b;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .sp-room-number {
        font-size: 1.3rem;
        font-weight: 900;
        color: #1e3a5f;
        margin: 0.25rem 0;
    }

    .sp-room-price {
        font-size: 0.82rem;
        color: #64748b;
    }

    .sp-room-price strong {
        color: #6366f1;
    }

    /* Action buttons */
    .sp-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        padding-top: 0.75rem;
        border-top: 1px solid #e2e8f0;
        margin-top: auto;
    }

    .sp-action-btn {
        flex: 1;
        min-width: 80px;
        padding: 0.55rem 0.5rem;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 0.78rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
        background: #fff;
        color: #334155;
        text-align: center;
    }

    .sp-action-btn:hover {
        background: #f1f5f9;
    }

    .sp-action-btn.primary {
        background: #6366f1;
        color: #fff;
        border-color: #6366f1;
    }

    .sp-action-btn.primary:hover {
        background: #4f46e5;
    }

    .sp-action-btn.success {
        background: #10b981;
        color: #fff;
        border-color: #10b981;
    }

    .sp-action-btn.success:hover {
        background: #059669;
    }

    .sp-action-btn.danger {
        background: #ef4444;
        color: #fff;
        border-color: #ef4444;
    }

    .sp-action-btn.danger:hover {
        background: #dc2626;
    }

    .sp-action-btn.warning {
        background: #f59e0b;
        color: #fff;
        border-color: #f59e0b;
    }

    .sp-action-btn.warning:hover {
        background: #d97706;
    }

    .guest-side-panel::-webkit-scrollbar {
        width: 6px;
    }

    .guest-side-panel::-webkit-scrollbar-track {
        background: rgba(99, 102, 241, 0.05);
    }

    .guest-side-panel::-webkit-scrollbar-thumb {
        background: rgba(99, 102, 241, 0.2);
        border-radius: 3px;
    }

    .guest-side-panel::-webkit-scrollbar-thumb:hover {
        background: rgba(99, 102, 241, 0.4);
    }

    .payment-modal {
        background: white;
        border-radius: 12px;
        width: 90%;
        max-width: 420px;
        padding: 1.25rem;
        position: relative;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(99, 102, 241, 0.1);
    }

    .payment-modal-close {
        position: absolute;
        top: 0.75rem;
        right: 0.75rem;
        background: rgba(239, 68, 68, 0.1);
        border: none;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        font-size: 1.5rem;
        cursor: pointer;
        color: #ef4444;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
        line-height: 1;
        font-weight: 300;
    }

    .payment-modal-header {
        text-align: center;
        margin-bottom: 0.75rem;
    }

    .payment-modal-header h3 {
        margin: 0;
        font-size: 1.1rem;
        font-weight: 700;
    }

    .payment-modal-header p {
        margin: 0.25rem 0 0;
        font-size: 0.75rem;
        color: var(--text-secondary);
    }

    .payment-info {
        background: rgba(99, 102, 241, 0.05);
        border-radius: 8px;
        padding: 0.75rem;
        font-size: 0.8rem;
        line-height: 1.5;
        margin-bottom: 0.75rem;
    }

    .payment-info span {
        color: var(--text-secondary);
    }

    .payment-modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.5rem;
        margin-top: 0.75rem;
    }

    /* Scrollbar styling (side panel handled above) */

    /* Payment modal: info sumber booking & ringkasan OTA */
    .pay-src {
        margin: 0 0 10px;
        padding: 8px 12px;
        border-radius: 10px;
        font-size: 0.74rem;
        line-height: 1.4;
    }

    body[data-theme] .pay-src.direct {
        background: rgba(37, 99, 235, 0.07);
        border: 1px solid rgba(37, 99, 235, 0.2);
        color: #1e3a8a !important;
        -webkit-text-fill-color: #1e3a8a !important;
    }

    body[data-theme] .pay-src.ota {
        background: rgba(217, 119, 6, 0.08);
        border: 1px solid rgba(217, 119, 6, 0.28);
        color: #92400e !important;
        -webkit-text-fill-color: #92400e !important;
    }

    .pay-ota-net {
        margin: -2px 0 10px;
        padding: 8px 12px;
        border-radius: 10px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
    }

    .pay-ota-net > div {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        padding: 2px 0;
    }

    body[data-theme] .pay-ota-net span {
        font-size: 0.74rem;
        color: #64748b !important;
        -webkit-text-fill-color: #64748b !important;
    }

    body[data-theme] .pay-ota-net b {
        font-size: 0.78rem;
        color: #0f172a !important;
        -webkit-text-fill-color: #0f172a !important;
    }

    body[data-theme] .pay-ota-net b.neg {
        color: #b91c1c !important;
        -webkit-text-fill-color: #b91c1c !important;
    }

    .pay-ota-net .net {
        margin-top: 4px;
        padding-top: 6px !important;
        border-top: 1px dashed #cbd5e1;
    }

    body[data-theme] .pay-ota-net .net b {
        font-size: 0.9rem;
        color: #047857 !important;
        -webkit-text-fill-color: #047857 !important;
    }

    /* ===== Side panel reservasi — redesign ===== */
    #bookingQuickView {
        --sp-bg: #ffffff;
        --sp-soft: #f8fafc;
        --sp-line: #e2e8f0;
        --sp-ink: #0f172a;
        --sp-muted: #64748b;
        background: rgba(15, 23, 42, 0.42);
        backdrop-filter: blur(3px);
    }

    body[data-theme="dark"] #bookingQuickView {
        --sp-bg: #0f172a;
        --sp-soft: rgba(255, 255, 255, 0.04);
        --sp-line: rgba(255, 255, 255, 0.1);
        --sp-ink: #e2e8f0;
        --sp-muted: #94a3b8;
    }

    #bookingQuickView .guest-side-panel {
        width: 440px;
        padding: 0;
        background: var(--sp-bg);
        border-left: 1px solid var(--sp-line);
        box-shadow: -24px 0 60px -20px rgba(15, 23, 42, 0.45);
    }

    #bookingQuickView .guest-side-panel > *:not(.side-panel-header):not(.sp-actions) {
        margin-left: 20px;
        margin-right: 20px;
    }

    #bookingQuickView .side-panel-header {
        margin: 0 0 12px;
        padding: 18px 20px 16px;
        background: linear-gradient(135deg, #1e3a8a, #2563eb);
    }

    #bookingQuickView .guest-avatar {
        width: 46px;
        height: 46px;
        border-radius: 14px;
        background: rgba(255, 255, 255, 0.16) !important;
        border: 1px solid rgba(255, 255, 255, 0.3);
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
        font-size: 0.95rem;
        font-weight: 700;
        letter-spacing: 0.02em;
    }

    body[data-theme] #bookingQuickView .guest-header-info h2 {
        margin: 0 !important;
        font-size: 1rem !important;
        font-weight: 700 !important;
        line-height: 1.3;
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
    }

    body[data-theme] #bookingQuickView .sp-sub,
    body[data-theme] #bookingQuickView .guest-phone-text {
        margin: 2px 0 0 !important;
        font-size: 0.72rem !important;
        color: rgba(255, 255, 255, 0.82) !important;
        -webkit-text-fill-color: rgba(255, 255, 255, 0.82) !important;
    }

    body[data-theme] #bookingQuickView .sp-icon-btn {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        border: 1px solid rgba(255, 255, 255, 0.3) !important;
        background: rgba(255, 255, 255, 0.14) !important;
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
        font-size: 1.2rem !important;
    }

    #bookingQuickView .sp-status-row {
        gap: 6px;
        margin-bottom: 12px;
    }

    body[data-theme] #bookingQuickView .sp-status-badge,
    body[data-theme] #bookingQuickView .sp-source-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px !important;
        border-radius: 999px !important;
        font-size: 0.7rem !important;
        font-weight: 700 !important;
        border: 1px solid transparent;
    }

    #bookingQuickView .sp-status-badge::before {
        content: '';
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
    }

    body[data-theme] #bookingQuickView .sp-status-badge.st-checked_in { background: #dcfce7 !important; color: #15803d !important; -webkit-text-fill-color: #15803d !important; }
    body[data-theme] #bookingQuickView .sp-status-badge.st-confirmed { background: #dbeafe !important; color: #1d4ed8 !important; -webkit-text-fill-color: #1d4ed8 !important; }
    body[data-theme] #bookingQuickView .sp-status-badge.st-pending { background: #fef3c7 !important; color: #b45309 !important; -webkit-text-fill-color: #b45309 !important; }
    body[data-theme] #bookingQuickView .sp-status-badge.st-checked_out { background: #f1f5f9 !important; color: #475569 !important; -webkit-text-fill-color: #475569 !important; }
    body[data-theme] #bookingQuickView .sp-status-badge.st-cancelled { background: #fee2e2 !important; color: #b91c1c !important; -webkit-text-fill-color: #b91c1c !important; }

    body[data-theme] #bookingQuickView .sp-source-badge {
        background: var(--sp-soft) !important;
        border-color: var(--sp-line);
        color: var(--sp-muted) !important;
        -webkit-text-fill-color: var(--sp-muted) !important;
    }

    /* Timeline */
    #bookingQuickView .sp-timeline {
        margin-bottom: 10px;
        padding: 12px 14px;
        border-radius: 12px;
        background: var(--sp-soft);
        border: 1px solid var(--sp-line);
    }

    #bookingQuickView .sp-timeline-track {
        height: 4px;
        border-radius: 999px;
        background: var(--sp-line);
    }

    #bookingQuickView .sp-timeline-progress {
        border-radius: 999px;
        background: linear-gradient(90deg, #2563eb, #10b981);
    }

    body[data-theme] #bookingQuickView .sp-timeline-label {
        font-size: 0.6rem !important;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--sp-muted) !important;
        -webkit-text-fill-color: var(--sp-muted) !important;
    }

    body[data-theme] #bookingQuickView .sp-timeline-date {
        font-size: 0.78rem !important;
        font-weight: 700;
        color: var(--sp-ink) !important;
        -webkit-text-fill-color: var(--sp-ink) !important;
    }

    /* Tamu & malam */
    #bookingQuickView .sp-guest-info-row {
        display: flex;
        gap: 6px;
        margin-bottom: 14px;
        padding: 0;
        background: none;
        border: 0;
    }

    body[data-theme] #bookingQuickView .sp-info-icon {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 10px;
        border-radius: 999px;
        background: var(--sp-soft);
        border: 1px solid var(--sp-line);
        font-size: 0.72rem !important;
        color: var(--sp-muted) !important;
        -webkit-text-fill-color: var(--sp-muted) !important;
    }

    body[data-theme] #bookingQuickView .sp-info-icon b {
        color: var(--sp-ink) !important;
        -webkit-text-fill-color: var(--sp-ink) !important;
    }

    /* Tabs: segmented */
    #bookingQuickView .sp-tabs {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 4px;
        margin-bottom: 12px;
        padding: 4px;
        border: 0;
        border-radius: 12px;
        background: var(--sp-soft);
        border: 1px solid var(--sp-line);
    }

    body[data-theme] #bookingQuickView .sp-tab {
        height: 32px;
        padding: 0 !important;
        border: 0 !important;
        border-radius: 9px !important;
        background: transparent !important;
        font-size: 0.76rem !important;
        font-weight: 700;
        color: var(--sp-muted) !important;
        -webkit-text-fill-color: var(--sp-muted) !important;
    }

    body[data-theme] #bookingQuickView .sp-tab.active {
        background: var(--sp-bg) !important;
        color: #1d4ed8 !important;
        -webkit-text-fill-color: #1d4ed8 !important;
        box-shadow: 0 2px 8px -2px rgba(15, 23, 42, 0.18);
    }

    /* Saldo */
    #bookingQuickView .sp-balance-box {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 8px;
        padding: 6px 12px;
        border-radius: 10px;
        background: rgba(220, 38, 38, 0.06);
        border: 1px solid rgba(220, 38, 38, 0.2);
    }

    /* Hotel Service bill: separate amber box, amount larger than the room balance */
    #bookingQuickView .sp-svc-box {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 8px;
        padding: 6px 12px;
        border-radius: 10px;
        background: rgba(245, 158, 11, 0.09);
        border: 1px solid rgba(245, 158, 11, 0.32);
    }

    body[data-theme] #bookingQuickView .sp-svc-label {
        font-size: 0.6rem !important;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #b45309 !important;
        -webkit-text-fill-color: #b45309 !important;
    }

    body[data-theme] #bookingQuickView .sp-svc-amount {
        font-size: 0.88rem !important;
        font-weight: 800;
        line-height: 1.2;
        color: #b45309 !important;
        -webkit-text-fill-color: #b45309 !important;
    }

    body[data-theme] #bookingQuickView .sp-svc-total {
        font-size: 0.66rem !important;
        font-weight: 700;
        text-align: right;
        color: #64748b !important;
        -webkit-text-fill-color: #64748b !important;
    }

    body[data-theme="dark"] #bookingQuickView .sp-svc-label,
    body[data-theme="dark"] #bookingQuickView .sp-svc-amount {
        color: #fcd34d !important;
        -webkit-text-fill-color: #fcd34d !important;
    }

    body[data-theme="dark"] #bookingQuickView .sp-svc-total {
        color: #94a3b8 !important;
        -webkit-text-fill-color: #94a3b8 !important;
    }

    /* Hotel Service invoice row: click to expand its items */
    #bookingQuickView .sp-folio-table .sp-svc-inv {
        cursor: pointer;
    }

    #bookingQuickView .sp-folio-table .sp-svc-inv:hover td {
        background: rgba(148, 163, 184, 0.08);
    }

    #bookingQuickView .sp-svc-caret {
        display: inline-block;
        width: 10px;
        color: #f59e0b;
    }

    #bookingQuickView .sp-folio-table .sp-svc-detail td {
        padding: 4px 12px 8px 26px;
        background: rgba(148, 163, 184, 0.06);
    }

    #bookingQuickView .sp-svc-line {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        padding: 2px 0;
        font-size: 0.7rem;
        color: #64748b;
    }

    body[data-theme="dark"] #bookingQuickView .sp-svc-line {
        color: #94a3b8;
    }

    body[data-theme="dark"] #bookingQuickView .sp-svc-line b {
        color: #e2e8f0;
    }

    #bookingQuickView .sp-balance-box.paid {
        background: rgba(5, 150, 105, 0.07);
        border-color: rgba(5, 150, 105, 0.25);
    }

    body[data-theme] #bookingQuickView .sp-balance-label {
        font-size: 0.62rem !important;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #b91c1c !important;
        -webkit-text-fill-color: #b91c1c !important;
    }

    body[data-theme] #bookingQuickView .sp-balance-amount {
        font-size: 0.88rem !important;
        font-weight: 800;
        line-height: 1.2;
        color: #b91c1c !important;
        -webkit-text-fill-color: #b91c1c !important;
    }

    body[data-theme] #bookingQuickView .paid .sp-balance-label,
    body[data-theme] #bookingQuickView .paid .sp-balance-amount {
        color: #047857 !important;
        -webkit-text-fill-color: #047857 !important;
    }

    body[data-theme] #bookingQuickView .sp-note-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        height: 28px;
        padding: 0 10px !important;
        border-radius: 8px !important;
        border: 1px solid var(--sp-line) !important;
        background: var(--sp-bg) !important;
        font-size: 0.74rem !important;
        font-weight: 700;
        color: var(--sp-ink) !important;
        -webkit-text-fill-color: var(--sp-ink) !important;
        cursor: pointer;
    }

    #bookingQuickView .sp-note-banner {
        align-items: flex-start;
        gap: 8px;
        margin-bottom: 10px;
        padding: 8px 12px;
        border-radius: 10px;
        background: rgba(245, 158, 11, 0.1);
        border: 1px solid rgba(245, 158, 11, 0.3);
    }

    body[data-theme] #bookingQuickView #sp-folio-note-text {
        font-size: 0.76rem !important;
        font-style: italic;
        color: #92400e !important;
        -webkit-text-fill-color: #92400e !important;
    }

    /* Folio */
    #bookingQuickView .sp-folio-table {
        border-collapse: separate;
        border-spacing: 0;
        border: 1px solid var(--sp-line);
        border-radius: 12px;
        overflow: hidden;
    }

    body[data-theme] #bookingQuickView .sp-folio-table th {
        padding: 8px 12px !important;
        background: var(--sp-soft) !important;
        border-bottom: 1px solid var(--sp-line) !important;
        font-size: 0.6rem !important;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--sp-muted) !important;
        -webkit-text-fill-color: var(--sp-muted) !important;
    }

    body[data-theme] #bookingQuickView .sp-folio-table td {
        padding: 9px 12px !important;
        border-bottom: 1px solid var(--sp-line) !important;
        font-size: 0.76rem !important;
        color: var(--sp-ink) !important;
        -webkit-text-fill-color: var(--sp-ink) !important;
        vertical-align: top;
    }

    body[data-theme] #bookingQuickView .sp-folio-table .folio-desc-title {
        font-size: 0.76rem !important;
        font-weight: 600;
    }

    body[data-theme] #bookingQuickView .sp-folio-table .folio-desc-sub {
        font-size: 0.66rem !important;
        color: var(--sp-muted) !important;
        -webkit-text-fill-color: var(--sp-muted) !important;
    }

    body[data-theme] #bookingQuickView .sp-folio-total td {
        border-bottom: 0 !important;
        background: var(--sp-soft) !important;
        font-weight: 800;
    }

    /* Details & Room */
    body[data-theme] #bookingQuickView .sp-detail-section h4 {
        margin: 0 0 6px !important;
        font-size: 0.62rem !important;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--sp-muted) !important;
        -webkit-text-fill-color: var(--sp-muted) !important;
    }

    #bookingQuickView .sp-detail-section {
        margin-bottom: 12px;
        padding: 4px 14px;
        border-radius: 12px;
        border: 1px solid var(--sp-line);
    }

    #bookingQuickView .sp-detail-section h4 {
        padding-top: 10px;
    }

    body[data-theme] #bookingQuickView .sp-detail-row {
        padding: 8px 0 !important;
        border-bottom: 1px dashed var(--sp-line) !important;
        font-size: 0.78rem !important;
    }

    #bookingQuickView .sp-detail-row:last-child {
        border-bottom: 0 !important;
    }

    body[data-theme] #bookingQuickView .sp-detail-row span {
        color: var(--sp-muted) !important;
        -webkit-text-fill-color: var(--sp-muted) !important;
    }

    body[data-theme] #bookingQuickView .sp-detail-row strong {
        color: var(--sp-ink) !important;
        -webkit-text-fill-color: var(--sp-ink) !important;
        text-align: right;
    }

    body[data-theme] #bookingQuickView .sp-room-card {
        margin-bottom: 12px;
        padding: 14px !important;
        border-radius: 12px !important;
        background: var(--sp-soft) !important;
        border: 1px solid var(--sp-line) !important;
    }

    body[data-theme] #bookingQuickView .sp-room-type {
        font-size: 0.62rem !important;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--sp-muted) !important;
        -webkit-text-fill-color: var(--sp-muted) !important;
    }

    body[data-theme] #bookingQuickView .sp-room-number {
        font-size: 1.15rem !important;
        font-weight: 800;
        color: var(--sp-ink) !important;
        -webkit-text-fill-color: var(--sp-ink) !important;
    }

    #bookingQuickView .sp-group-list {
        display: grid;
        gap: 6px;
        padding-bottom: 10px;
    }

    #bookingQuickView .sp-group-room {
        padding: 8px 10px;
        border-radius: 10px;
        border: 1px solid var(--sp-line);
        background: var(--sp-bg);
        cursor: pointer;
    }

    #bookingQuickView .sp-group-room.on {
        border-color: rgba(5, 150, 105, 0.4);
        background: rgba(5, 150, 105, 0.06);
    }

    #bookingQuickView .sp-gr-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }

    body[data-theme] #bookingQuickView .sp-gr-top b {
        font-size: 0.8rem !important;
        color: var(--sp-ink) !important;
        -webkit-text-fill-color: var(--sp-ink) !important;
    }

    body[data-theme] #bookingQuickView .sp-gr-top small,
    body[data-theme] #bookingQuickView .sp-gr-price {
        font-size: 0.7rem !important;
        color: var(--sp-muted) !important;
        -webkit-text-fill-color: var(--sp-muted) !important;
    }

    body[data-theme] #bookingQuickView .sp-gr-on {
        margin-left: 4px;
        padding: 1px 7px;
        border-radius: 999px;
        background: rgba(5, 150, 105, 0.12);
        font-size: 0.6rem !important;
        font-weight: 700;
        color: #047857 !important;
        -webkit-text-fill-color: #047857 !important;
    }

    body[data-theme] #bookingQuickView .sp-gr-note {
        padding: 3px 9px !important;
        border-radius: 999px !important;
        border: 1px solid var(--sp-line) !important;
        background: var(--sp-soft) !important;
        font-size: 0.66rem !important;
        font-weight: 700;
        color: #1d4ed8 !important;
        -webkit-text-fill-color: #1d4ed8 !important;
        cursor: pointer;
    }

    body[data-theme] #bookingQuickView .sp-gr-noteline {
        margin-top: 4px;
        font-size: 0.7rem !important;
        font-style: italic;
        color: #b45309 !important;
        -webkit-text-fill-color: #b45309 !important;
    }

    /* Tombol aksi: menempel di bawah */
    #bookingQuickView .sp-actions {
        position: sticky;
        bottom: 0;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(0, 1fr));
        grid-auto-flow: column;
        gap: 8px;
        margin-top: auto;
        padding: 12px 20px 16px;
        background: var(--sp-bg);
        border-top: 1px solid var(--sp-line);
    }

    body[data-theme] #bookingQuickView .sp-action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        min-width: 0;
        height: 40px;
        padding: 0 8px !important;
        border-radius: 10px !important;
        border: 1px solid var(--sp-line) !important;
        background: var(--sp-bg) !important;
        font-size: 0.76rem !important;
        font-weight: 700;
        color: var(--sp-ink) !important;
        -webkit-text-fill-color: var(--sp-ink) !important;
        white-space: nowrap;
        transition: transform 0.15s, box-shadow 0.15s;
    }

    #bookingQuickView .sp-action-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 18px -12px rgba(15, 23, 42, 0.5);
    }

    body[data-theme] #bookingQuickView .sp-action-btn.success {
        border: 0 !important;
        background: linear-gradient(135deg, #047857, #10b981) !important;
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
    }

    body[data-theme] #bookingQuickView .sp-action-btn.primary {
        border: 0 !important;
        background: linear-gradient(135deg, #1e3a8a, #2563eb) !important;
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
    }

    body[data-theme] #bookingQuickView .sp-action-btn.danger {
        border-color: rgba(220, 38, 38, 0.35) !important;
        background: rgba(220, 38, 38, 0.06) !important;
        color: #b91c1c !important;
        -webkit-text-fill-color: #b91c1c !important;
    }

    body[data-theme="dark"] #bookingQuickView .sp-balance-label,
    body[data-theme="dark"] #bookingQuickView .sp-balance-amount,
    body[data-theme="dark"] #bookingQuickView .sp-action-btn.danger {
        color: #fca5a5 !important;
        -webkit-text-fill-color: #fca5a5 !important;
    }

    body[data-theme="dark"] #bookingQuickView .paid .sp-balance-label,
    body[data-theme="dark"] #bookingQuickView .paid .sp-balance-amount {
        color: #6ee7b7 !important;
        -webkit-text-fill-color: #6ee7b7 !important;
    }

    body[data-theme="dark"] #bookingQuickView #sp-folio-note-text,
    body[data-theme="dark"] #bookingQuickView .sp-gr-noteline {
        color: #fcd34d !important;
        -webkit-text-fill-color: #fcd34d !important;
    }

    body[data-theme="dark"] #bookingQuickView .sp-tab.active,
    body[data-theme="dark"] #bookingQuickView .sp-gr-note {
        color: #93c5fd !important;
        -webkit-text-fill-color: #93c5fd !important;
    }

    body[data-theme="dark"] #bookingQuickView .sp-tab.active {
        background: rgba(255, 255, 255, 0.08) !important;
    }

    @media (max-width: 480px) {
        #bookingQuickView .guest-side-panel {
            width: 100vw;
            max-width: 100vw;
        }
    }
</style>

<style>
    .sp-print { position: relative; }
    .sp-print .sp-print-menu { position: absolute; top: calc(100% + 8px); right: 0; z-index: 50; width: 270px; padding: 6px; border-radius: 14px; background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 18px 40px -12px rgba(15, 23, 42, .35); display: none; }
    .sp-print.open .sp-print-menu { display: block; animation: mvIn .15s ease-out; }
    .sp-print-menu button { width: 100%; display: flex; align-items: center; gap: 10px; padding: 9px 10px; border: 0; border-radius: 10px; background: none; text-align: left; cursor: pointer; font-family: inherit; }
    .sp-print-menu button:hover { background: #eff6ff; }
    body .sp-print-menu svg { width: 30px; height: 30px; padding: 6px; border-radius: 9px; background: #eff6ff; color: #1d4ed8 !important; flex-shrink: 0; box-sizing: border-box; }
    .sp-print-menu span { display: flex; flex-direction: column; min-width: 0; }
    body .sp-print-menu b { font-size: .84rem !important; font-weight: 800 !important; color: #0f172a !important; -webkit-text-fill-color: #0f172a !important; }
    body .sp-print-menu small { font-size: .7rem !important; color: #64748b !important; -webkit-text-fill-color: #64748b !important; }
    [data-theme="dark"] .sp-print .sp-print-menu { background: #111a2e; border-color: rgba(255, 255, 255, .12); }
    [data-theme="dark"] .sp-print-menu button:hover { background: rgba(37, 99, 235, .15); }
    body[data-theme="dark"] .sp-print-menu b { color: #f1f5f9 !important; -webkit-text-fill-color: #f1f5f9 !important; }
</style>
<!-- PINDAH KAMAR / UPGRADE / DOWNGRADE -->
<div id="moveRoomModal" class="mv-overlay" onclick="if(event.target===this)closeMoveModal()">
    <div class="mv-modal">
        <div class="mv-head">
            <div class="mv-head-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg></div>
            <div class="mv-head-t"><div class="mv-title">Pindah Kamar</div><div class="mv-sub" id="mvSub">-</div></div>
            <button type="button" class="mv-x" onclick="closeMoveModal()" aria-label="Tutup">&times;</button>
        </div>
        <div class="mv-body">
            <div class="mv-sec">
                <div class="mv-lbl">Kamar</div>
                <div class="mv-rooms">
                    <div class="mv-from" id="mvFrom"></div>
                    <div class="mv-arrow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></div>
                    <select id="mvRoom" class="mv-in" onchange="mvChanged('room')"></select>
                </div>
            </div>
            <div class="mv-sec mv-2col">
                <div><div class="mv-lbl">Check-in</div><input type="date" id="mvCheckIn" class="mv-in" onchange="mvChanged('checkin')"></div>
                <div><div class="mv-lbl">Check-out</div><input type="date" id="mvCheckOut" class="mv-in" onchange="mvChanged('dates')"></div>
            </div>
            <div class="mv-sec mv-eff" id="mvEffWrap">
                <div class="mv-lbl">Pindah mulai tanggal</div>
                <input type="date" id="mvEff" class="mv-in" onchange="mvChanged('eff')">
                <div class="mv-hint">Malam sebelum tanggal ini tetap dihitung seperti tagihan lama.</div>
            </div>
            <div class="mv-sec mv-price">
                <div class="mv-price-top"><div class="mv-lbl">Harga per malam</div><span class="mv-kind same" id="mvKind">-</span></div>
                <div class="mv-price-in"><span>Rp</span><input type="text" inputmode="numeric" id="mvPrice" class="mv-in" autocomplete="off" oninput="mvMoneyInput(this);mvChanged('price')"></div>
                <div class="mv-hint" id="mvPriceHint"></div>
                <button type="button" class="mv-link" id="mvPriceReset" onclick="mvResetPrice()" style="display:none;">Pakai harga otomatis</button>
            </div>
            <div class="mv-ota" id="mvOta" style="display:none;"></div>
            <div class="mv-err" id="mvErr" style="display:none;"></div>
            <div class="mv-sum" id="mvSummary" style="display:none;"></div>
        </div>
        <div class="mv-foot">
            <button type="button" class="mv-btn mv-btn-ghost" onclick="closeMoveModal()">Batal</button>
            <button type="button" class="mv-btn mv-btn-primary" id="mvSave" onclick="mvSubmit()" disabled>Simpan</button>
        </div>
    </div>
</div>

<script src="<?php echo BASE_URL; ?>/assets/js/deposit-guard.js?v=20261007"></script>
<!-- DEPOSIT / TANDA TERIMA -->
<div id="depositModal" class="mv-overlay" onclick="if(event.target===this)closeDepositModal()">
    <div class="mv-modal">
        <div class="mv-head mv-head-gold">
            <div class="mv-head-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/><path d="M9 12l2 2 4-4"/></svg></div>
            <div class="mv-head-t"><div class="mv-title">Tanda Terima Deposit</div><div class="mv-sub" id="depSub">-</div></div>
            <button type="button" class="mv-x" onclick="closeDepositModal()" aria-label="Tutup">&times;</button>
        </div>
        <div class="mv-body">
            <div class="mv-sec">
                <div class="mv-lbl">Jenis deposit</div>
                <div class="dep-seg">
                    <button type="button" data-t="cash" class="on" onclick="setDepType('cash')">Uang Tunai</button>
                    <button type="button" data-t="id_card" onclick="setDepType('id_card')">Kartu Identitas</button>
                </div>
            </div>
            <div class="mv-sec mv-price" id="depCashWrap">
                <div class="mv-lbl">Jumlah deposit</div>
                <div class="mv-price-in"><span>Rp</span><input type="text" inputmode="numeric" id="depAmount" class="mv-in" autocomplete="off" oninput="mvMoneyInput(this)"></div>
                <div class="mv-hint">Terbilang otomatis tercetak di tanda terima.</div>
            </div>
            <div class="mv-sec mv-2col" id="depIdWrap" style="display:none;">
                <div><div class="mv-lbl">Jenis kartu</div>
                    <select id="depIdType" class="mv-in">
                        <option value="KTP">KTP</option>
                        <option value="Passport">Passport / Paspor</option>
                        <option value="SIM">SIM</option>
                        <option value="Lainnya">Lainnya</option>
                    </select>
                </div>
                <div><div class="mv-lbl">Nomor kartu (opsional)</div><input type="text" id="depIdNo" class="mv-in" maxlength="60" autocomplete="off"></div>
            </div>
            <div class="mv-sec"><div class="mv-lbl">Keterangan (opsional)</div><input type="text" id="depNotes" class="mv-in" maxlength="255" placeholder="Contoh: dititipkan di brankas FO" autocomplete="off"></div>
            <div class="mv-err" id="depErr" style="display:none;"></div>
            <div class="dep-list" id="depList" style="display:none;"></div>
            <div class="mv-hint" style="margin-top:-4px;">Data deposit otomatis terhapus saat tamu check-out atau ganti bulan.</div>
        </div>
        <div class="mv-foot">
            <button type="button" class="mv-btn mv-btn-ghost" onclick="closeDepositModal()">Batal</button>
            <button type="button" class="mv-btn mv-btn-ghost" id="depSavePrint" onclick="saveDeposit(true)">Simpan &amp; Cetak</button>
            <button type="button" class="mv-btn mv-btn-primary mv-btn-gold" id="depSave" onclick="saveDeposit()">Simpan</button>
        </div>
    </div>
</div>
<style>
    #depositModal .mv-modal { width: min(440px, 100%); max-height: calc(100vh - 32px); display: flex; flex-direction: column; border-radius: 18px; overflow: hidden; background: #fff; box-shadow: 0 30px 70px rgba(0, 0, 0, .35); animation: mvIn .18s ease-out; }
    #depositModal .mv-head { display: flex; align-items: center; gap: 12px; padding: 14px 16px; flex-shrink: 0; }
    #depositModal .mv-head-gold { background: linear-gradient(135deg, #0f2747, #1e3a8a 60%, #b8913a); }
    #depositModal .mv-head-ic { width: 38px; height: 38px; border-radius: 11px; display: grid; place-items: center; background: rgba(255, 255, 255, .18); border: 1px solid rgba(255, 255, 255, .35); flex-shrink: 0; }
    body #depositModal .mv-head-ic svg { width: 19px; height: 19px; color: #fff !important; stroke: #fff !important; }
    body #depositModal .mv-head-ic svg * { stroke: #fff !important; }
    #depositModal .mv-head-t { flex: 1; min-width: 0; }
    body #depositModal .mv-title { font-size: 1.02rem !important; font-weight: 800 !important; color: #fff !important; -webkit-text-fill-color: #fff !important; }
    body #depositModal .mv-sub { margin-top: 2px; font-size: .8rem !important; font-weight: 600 !important; color: rgba(255, 255, 255, .92) !important; -webkit-text-fill-color: rgba(255, 255, 255, .92) !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    #depositModal .mv-x { width: 32px; height: 32px; border: 0; border-radius: 9px; background: rgba(255, 255, 255, .18); color: #fff !important; font-size: 1.3rem; cursor: pointer; }
    #depositModal .mv-body { padding: 14px 16px; display: flex; flex-direction: column; gap: 12px; overflow-y: auto; }
    body #depositModal .mv-lbl { margin-bottom: 5px; font-size: .68rem !important; font-weight: 800 !important; letter-spacing: .06em; text-transform: uppercase; color: #475569 !important; -webkit-text-fill-color: #475569 !important; }
    #depositModal .mv-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    body #depositModal .mv-in { width: 100%; height: 40px; padding: 0 12px; box-sizing: border-box; border: 1px solid #cbd5e1 !important; border-radius: 10px !important; background: #fff !important; font-size: .9rem !important; font-weight: 700 !important; color: #0f172a !important; -webkit-text-fill-color: #0f172a !important; font-family: inherit; }
    body #depositModal .mv-in::placeholder, body #moveRoomModal .mv-in::placeholder, body #extendModal .mv-in::placeholder { color: #94a3b8 !important; -webkit-text-fill-color: #94a3b8 !important; font-weight: 500 !important; }
    body #depositModal .mv-in:focus { outline: none; border-color: #2563eb !important; box-shadow: 0 0 0 3px rgba(37, 99, 235, .15); }
    #depositModal .mv-price { padding: 12px; border-radius: 12px; background: #fffaf0; border: 1px solid #ecd9ab; }
    #depositModal .mv-price-in { display: flex; align-items: center; gap: 8px; }
    body #depositModal .mv-price-in span { font-size: .9rem !important; font-weight: 800 !important; color: #7a5a17 !important; -webkit-text-fill-color: #7a5a17 !important; }
    body #depositModal .mv-price-in .mv-in { font-size: 1.15rem !important; font-weight: 800 !important; }
    body #depositModal .mv-hint { margin-top: 5px; font-size: .74rem !important; color: #475569 !important; -webkit-text-fill-color: #475569 !important; }
    #depositModal .dep-seg { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; padding: 4px; border-radius: 12px; background: #f1f5f9; border: 1px solid #e2e8f0; }
    body #depositModal .dep-seg button { height: 36px; border: 0; border-radius: 9px; background: transparent; font-size: .84rem !important; font-weight: 800 !important; color: #64748b !important; -webkit-text-fill-color: #64748b !important; cursor: pointer; font-family: inherit; }
    body #depositModal .dep-seg button.on { background: #fff; color: #1e3a8a !important; -webkit-text-fill-color: #1e3a8a !important; box-shadow: 0 2px 8px -2px rgba(15, 23, 42, .2); }
    body #depositModal .mv-err { padding: 10px 12px; border-radius: 10px; background: #fef2f2; border: 1px solid #fecaca; font-size: .8rem !important; font-weight: 700 !important; color: #b91c1c !important; -webkit-text-fill-color: #b91c1c !important; }
    #depositModal .dep-list { display: flex; flex-direction: column; gap: 6px; }
    #depositModal .dep-item { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 10px; border: 1px solid #e2e8f0; background: #f8fafc; }
    #depositModal .dep-item > div { flex: 1; min-width: 0; display: flex; flex-direction: column; }
    body #depositModal .dep-item b { font-size: .84rem !important; font-weight: 800 !important; color: #0f172a !important; -webkit-text-fill-color: #0f172a !important; }
    body #depositModal .dep-item small { font-size: .7rem !important; color: #64748b !important; -webkit-text-fill-color: #64748b !important; }
    body #depositModal .dep-print { height: 30px; padding: 0 12px; border: 0; border-radius: 8px; background: #1e3a8a; color: #fff !important; -webkit-text-fill-color: #fff !important; font-size: .74rem !important; font-weight: 800 !important; cursor: pointer; }
    body #depositModal .dep-del { width: 30px; height: 30px; border: 1px solid #fecaca; border-radius: 8px; background: #fff; color: #dc2626 !important; -webkit-text-fill-color: #dc2626 !important; font-size: 1.1rem; cursor: pointer; }
    #depositModal .mv-foot { display: grid; grid-template-columns: 1fr 1.1fr 1.1fr; gap: 10px; padding: 12px 16px; border-top: 1px solid #e2e8f0; background: #fff; }
    body #depositModal .mv-btn { height: 42px; border-radius: 11px; font-size: .9rem !important; font-weight: 800 !important; cursor: pointer; font-family: inherit; }
    body #depositModal .mv-btn-ghost { border: 1px solid #cbd5e1; background: #fff; color: #334155 !important; -webkit-text-fill-color: #334155 !important; }
    body #depositModal .mv-btn-gold { border: 0; background: linear-gradient(135deg, #0f2747, #1e3a8a); color: #fff !important; -webkit-text-fill-color: #fff !important; box-shadow: 0 8px 18px -8px rgba(15, 39, 71, .7); }
    body #depositModal .mv-btn:disabled { background: #94a3b8; box-shadow: none; }
    /* Strip deposit di panel detail */
    .sp-dep { margin-bottom: 10px; padding: 8px 10px; border-radius: 12px; background: #fffaf0; border: 1px solid #ecd9ab; }
    body .sp-dep-h { display: flex; align-items: center; gap: 6px; font-size: .66rem !important; font-weight: 800 !important; letter-spacing: .06em; text-transform: uppercase; color: #7a5a17 !important; -webkit-text-fill-color: #7a5a17 !important; margin-bottom: 4px; }
    .sp-dep-h svg { width: 13px; height: 13px; color: #b8913a; }
    .sp-dep-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 4px 0; border-top: 1px dashed #ecd9ab; }
    .sp-dep-row:first-of-type { border-top: 0; }
    .sp-dep-act { display: flex; gap: 4px; flex-shrink: 0; }
    body .sp-dep-row button.del { border-color: #fecaca; color: #dc2626 !important; -webkit-text-fill-color: #dc2626 !important; }
    body .sp-dep-row span { font-size: .8rem !important; font-weight: 700 !important; color: #0f172a !important; -webkit-text-fill-color: #0f172a !important; }
    body .sp-dep-row button { height: 26px; padding: 0 10px; border: 1px solid #ecd9ab; border-radius: 7px; background: #fff; font-size: .7rem !important; font-weight: 800 !important; color: #7a5a17 !important; -webkit-text-fill-color: #7a5a17 !important; cursor: pointer; }
    [data-theme="dark"] #depositModal .mv-modal, [data-theme="dark"] #depositModal .mv-foot { background: #111a2e; border-color: rgba(255, 255, 255, .1); }
    body[data-theme="dark"] #depositModal .mv-in { background: rgba(255, 255, 255, .06) !important; border-color: rgba(255, 255, 255, .16) !important; color: #f1f5f9 !important; -webkit-text-fill-color: #f1f5f9 !important; }
    body[data-theme="dark"] #depositModal .mv-lbl, body[data-theme="dark"] #depositModal .mv-hint { color: #cbd5e1 !important; -webkit-text-fill-color: #cbd5e1 !important; }
    [data-theme="dark"] #depositModal .mv-price { background: rgba(184, 145, 58, .08); border-color: rgba(184, 145, 58, .35); }
    [data-theme="dark"] #depositModal .dep-seg, [data-theme="dark"] #depositModal .dep-item { background: rgba(255, 255, 255, .04); border-color: rgba(255, 255, 255, .1); }
    body[data-theme="dark"] #depositModal .dep-item b, body[data-theme="dark"] .sp-dep-row span { color: #f1f5f9 !important; -webkit-text-fill-color: #f1f5f9 !important; }
    [data-theme="dark"] .sp-dep { background: rgba(184, 145, 58, .08); border-color: rgba(184, 145, 58, .35); }
</style>

<!-- EXTEND STAY MODAL -->
<div id="extendModal" class="mv-overlay" onclick="if(event.target===this)closeExtendModal()">
    <div class="mv-modal">
        <div class="mv-head mv-head-green">
            <div class="mv-head-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4"/></svg></div>
            <div class="mv-head-t"><div class="mv-title">Extend Stay</div><div class="mv-sub" id="extendGuestName">-</div></div>
            <button type="button" class="mv-x" onclick="closeExtendModal()" aria-label="Tutup">&times;</button>
        </div>
        <input type="hidden" id="extendBookingId">
        <div class="mv-body">
            <div class="mv-sec mv-2col">
                <div><div class="mv-lbl">Check-out sekarang</div><div class="mv-static" id="extendCurrentCO">-</div></div>
                <div><div class="mv-lbl">Check-out baru</div><div class="mv-static mv-static-hi" id="extendNewCO">-</div></div>
            </div>
            <div class="mv-sec">
                <div class="mv-lbl">Tambah malam</div>
                <div class="mv-step">
                    <button type="button" onclick="adjustExtendNights(-1)">−</button>
                    <input type="number" id="extendNights" class="mv-in" value="1" min="1" max="30">
                    <button type="button" onclick="adjustExtendNights(1)">+</button>
                </div>
            </div>
            <div class="mv-sec mv-price">
                <div class="mv-lbl">Harga per malam tambahan</div>
                <div class="mv-price-in"><span>Rp</span><input type="text" inputmode="numeric" id="extendPrice" class="mv-in" autocomplete="off" oninput="mvMoneyInput(this);extPriceEdited=true;extPreview()"></div>
                <div class="mv-hint" id="extendPriceHint"></div>
            </div>
            <div class="mv-ota" id="extendOta" style="display:none;"></div>
            <div class="mv-err" id="extendErr" style="display:none;"></div>
            <div class="mv-sum" id="extendSummary" style="display:none;"></div>
        </div>
        <div class="mv-foot">
            <button type="button" class="mv-btn mv-btn-ghost" onclick="closeExtendModal()">Batal</button>
            <button type="button" class="mv-btn mv-btn-primary mv-btn-green" id="extendSave" onclick="submitExtendStay()">Extend Stay</button>
        </div>
    </div>
</div>
<style>
    /* Popup Pindah Kamar & Extend — selector ber-ID agar tidak tertimpa aturan teks global */
    .mv-overlay { position: fixed; inset: 0; z-index: 100000; display: none; align-items: center; justify-content: center; padding: 16px; background: rgba(15, 23, 42, .55); backdrop-filter: blur(3px); }
    .mv-overlay.active { display: flex; }
    #moveRoomModal .mv-modal, #extendModal .mv-modal { width: min(460px, 100%); max-height: calc(100vh - 32px); display: flex; flex-direction: column; border-radius: 18px; overflow: hidden; background: #fff; box-shadow: 0 30px 70px rgba(0, 0, 0, .35); animation: mvIn .18s ease-out; font-family: inherit; }
    @keyframes mvIn { from { transform: translateY(8px) scale(.98); opacity: 0; } }
    #moveRoomModal .mv-head, #extendModal .mv-head { display: flex; align-items: center; gap: 12px; padding: 14px 16px; background: linear-gradient(135deg, #1e3a8a, #2563eb); flex-shrink: 0; }
    #extendModal .mv-head-green { background: linear-gradient(135deg, #065f46, #059669); }
    #moveRoomModal .mv-head-ic, #extendModal .mv-head-ic { width: 38px; height: 38px; border-radius: 11px; display: grid; place-items: center; background: rgba(255, 255, 255, .18); border: 1px solid rgba(255, 255, 255, .35); color: #fff; flex-shrink: 0; }
    body #moveRoomModal .mv-head-ic svg, body #extendModal .mv-head-ic svg { width: 19px; height: 19px; color: #fff !important; stroke: #fff !important; fill: none !important; }
    body #moveRoomModal .mv-head-ic svg *, body #extendModal .mv-head-ic svg * { stroke: #fff !important; }
    #moveRoomModal .mv-head-t, #extendModal .mv-head-t { flex: 1; min-width: 0; }
    body #moveRoomModal .mv-title, body #extendModal .mv-title { font-size: 1.02rem !important; font-weight: 800 !important; color: #fff !important; -webkit-text-fill-color: #fff !important; line-height: 1.25; }
    body #moveRoomModal .mv-sub, body #extendModal .mv-sub { margin-top: 2px; font-size: .8rem !important; font-weight: 600 !important; color: rgba(255, 255, 255, .92) !important; -webkit-text-fill-color: rgba(255, 255, 255, .92) !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    #moveRoomModal .mv-x, #extendModal .mv-x { width: 32px; height: 32px; border: 0; border-radius: 9px; background: rgba(255, 255, 255, .18); color: #fff !important; font-size: 1.3rem; line-height: 1; cursor: pointer; flex-shrink: 0; }
    #moveRoomModal .mv-x:hover, #extendModal .mv-x:hover { background: rgba(255, 255, 255, .3); }
    #moveRoomModal .mv-body, #extendModal .mv-body { padding: 14px 16px; display: flex; flex-direction: column; gap: 12px; overflow-y: auto; }
    body #moveRoomModal .mv-lbl, body #extendModal .mv-lbl { margin-bottom: 5px; font-size: .68rem !important; font-weight: 800 !important; letter-spacing: .06em; text-transform: uppercase; color: #475569 !important; -webkit-text-fill-color: #475569 !important; }
    #moveRoomModal .mv-2col, #extendModal .mv-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    body #moveRoomModal .mv-in, body #extendModal .mv-in { width: 100%; height: 40px; padding: 0 12px; box-sizing: border-box; border: 1px solid #cbd5e1 !important; border-radius: 10px !important; background: #fff !important; font-size: .9rem !important; font-weight: 700 !important; color: #0f172a !important; -webkit-text-fill-color: #0f172a !important; font-family: inherit; }
    body #moveRoomModal .mv-in:focus, body #extendModal .mv-in:focus { outline: none; border-color: #2563eb !important; box-shadow: 0 0 0 3px rgba(37, 99, 235, .15); }
    body #moveRoomModal .mv-in:disabled { background: #f1f5f9 !important; color: #64748b !important; -webkit-text-fill-color: #64748b !important; }
    #moveRoomModal .mv-rooms { display: grid; grid-template-columns: 1fr 26px 1.25fr; gap: 8px; align-items: center; }
    #moveRoomModal .mv-from { min-height: 40px; display: flex; flex-direction: column; justify-content: center; padding: 4px 12px; border-radius: 10px; background: #eff6ff; border: 1px solid #bfdbfe; box-sizing: border-box; }
    body #moveRoomModal .mv-from b { font-size: 1rem !important; font-weight: 800 !important; color: #1e3a8a !important; -webkit-text-fill-color: #1e3a8a !important; line-height: 1.2; }
    body #moveRoomModal .mv-from span { font-size: .72rem !important; font-weight: 600 !important; color: #334155 !important; -webkit-text-fill-color: #334155 !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    #moveRoomModal .mv-arrow { color: #64748b; display: grid; place-items: center; }
    #moveRoomModal .mv-arrow svg { width: 18px; height: 18px; }
    #moveRoomModal .mv-eff { padding: 10px 12px; border-radius: 12px; background: #fffbeb; border: 1px solid #fde68a; }
    body #moveRoomModal .mv-hint, body #extendModal .mv-hint { margin-top: 5px; font-size: .74rem !important; font-weight: 500 !important; color: #475569 !important; -webkit-text-fill-color: #475569 !important; line-height: 1.45; }
    #moveRoomModal .mv-price, #extendModal .mv-price { padding: 12px; border-radius: 12px; background: #f8fafc; border: 1px solid #e2e8f0; }
    #moveRoomModal .mv-price-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 5px; }
    #moveRoomModal .mv-price-top .mv-lbl { margin: 0; }
    #moveRoomModal .mv-price-in, #extendModal .mv-price-in { display: flex; align-items: center; gap: 8px; }
    body #moveRoomModal .mv-price-in span, body #extendModal .mv-price-in span { font-size: .9rem !important; font-weight: 800 !important; color: #475569 !important; -webkit-text-fill-color: #475569 !important; }
    body #moveRoomModal .mv-price-in .mv-in, body #extendModal .mv-price-in .mv-in { font-size: 1.1rem !important; font-weight: 800 !important; }
    body #moveRoomModal .mv-kind { padding: 3px 10px; border-radius: 999px; font-size: .66rem !important; font-weight: 800 !important; letter-spacing: .04em; text-transform: uppercase; }
    body #moveRoomModal .mv-kind.up { background: #dcfce7; color: #166534 !important; -webkit-text-fill-color: #166534 !important; }
    body #moveRoomModal .mv-kind.down { background: #fef3c7; color: #92400e !important; -webkit-text-fill-color: #92400e !important; }
    body #moveRoomModal .mv-kind.same { background: #dbeafe; color: #1e40af !important; -webkit-text-fill-color: #1e40af !important; }
    body #moveRoomModal .mv-link { margin-top: 6px; padding: 0; border: 0; background: none; font-size: .76rem !important; font-weight: 700 !important; color: #2563eb !important; cursor: pointer; }
    body #moveRoomModal .mv-ota, body #extendModal .mv-ota { display: flex; gap: 10px; padding: 10px 12px; border-radius: 12px; background: #f5f3ff; border: 1px solid #ddd6fe; font-size: .78rem !important; color: #3b0764 !important; -webkit-text-fill-color: #3b0764 !important; line-height: 1.5; }
    body #moveRoomModal .mv-ota b, body #extendModal .mv-ota b { color: #5b21b6 !important; -webkit-text-fill-color: #5b21b6 !important; }
    #moveRoomModal .mv-ota svg, #extendModal .mv-ota svg { width: 18px; height: 18px; flex-shrink: 0; color: #7c3aed; margin-top: 1px; }
    body #moveRoomModal .mv-err, body #extendModal .mv-err { padding: 10px 12px; border-radius: 10px; background: #fef2f2; border: 1px solid #fecaca; font-size: .8rem !important; font-weight: 700 !important; color: #b91c1c !important; -webkit-text-fill-color: #b91c1c !important; }
    #moveRoomModal .mv-sum, #extendModal .mv-sum { border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
    #moveRoomModal .mv-sum > div, #extendModal .mv-sum > div { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 8px 12px; border-bottom: 1px solid #f1f5f9; }
    #moveRoomModal .mv-sum > div:last-child, #extendModal .mv-sum > div:last-child { border-bottom: 0; }
    body #moveRoomModal .mv-sum span, body #extendModal .mv-sum span { font-size: .8rem !important; font-weight: 600 !important; color: #475569 !important; -webkit-text-fill-color: #475569 !important; }
    body #moveRoomModal .mv-sum b, body #extendModal .mv-sum b { font-size: .84rem !important; font-weight: 800 !important; color: #0f172a !important; -webkit-text-fill-color: #0f172a !important; text-align: right; }
    #moveRoomModal .mv-sum .tot, #extendModal .mv-sum .tot { background: #eff6ff; }
    body #moveRoomModal .mv-sum .tot span, body #extendModal .mv-sum .tot span { color: #1e3a8a !important; -webkit-text-fill-color: #1e3a8a !important; font-weight: 800 !important; }
    body #moveRoomModal .mv-sum .tot b, body #extendModal .mv-sum .tot b { font-size: .98rem !important; color: #1e3a8a !important; -webkit-text-fill-color: #1e3a8a !important; }
    #moveRoomModal .mv-sum .sub, #extendModal .mv-sum .sub { background: #faf5ff; }
    body #moveRoomModal .mv-sum em, body #extendModal .mv-sum em { margin-left: 6px; padding: 1px 7px; border-radius: 6px; font-style: normal; font-size: .72rem !important; font-weight: 800 !important; }
    body #moveRoomModal .mv-sum em.plus, body #extendModal .mv-sum em.plus { background: #fee2e2; color: #b91c1c !important; -webkit-text-fill-color: #b91c1c !important; }
    body #moveRoomModal .mv-sum em.minus { background: #dcfce7; color: #166534 !important; -webkit-text-fill-color: #166534 !important; }
    body #moveRoomModal .mv-sum .neg { color: #059669 !important; -webkit-text-fill-color: #059669 !important; }
    body #moveRoomModal .mv-sum .due, body #extendModal .mv-sum .due { color: #dc2626 !important; -webkit-text-fill-color: #dc2626 !important; }
    body #moveRoomModal .mv-sum .paid, body #extendModal .mv-sum .paid { color: #059669 !important; -webkit-text-fill-color: #059669 !important; }
    body #moveRoomModal .mv-sum .vio, body #extendModal .mv-sum .vio { color: #6d28d9 !important; -webkit-text-fill-color: #6d28d9 !important; }
    #extendModal .mv-static { height: 40px; display: flex; align-items: center; padding: 0 12px; border-radius: 10px; background: #f1f5f9; border: 1px solid #e2e8f0; font-size: .9rem; font-weight: 800; color: #0f172a; box-sizing: border-box; }
    #extendModal .mv-static-hi { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
    #extendModal .mv-step { display: grid; grid-template-columns: 40px 1fr 40px; gap: 8px; }
    #extendModal .mv-step button { height: 40px; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff; font-size: 1.2rem; font-weight: 800; color: #0f172a; cursor: pointer; }
    #extendModal .mv-step .mv-in { text-align: center; }
    #moveRoomModal .mv-foot, #extendModal .mv-foot { display: grid; grid-template-columns: 1fr 1.4fr; gap: 10px; padding: 12px 16px; border-top: 1px solid #e2e8f0; background: #fff; flex-shrink: 0; }
    body #moveRoomModal .mv-btn, body #extendModal .mv-btn, body .mv-notice .mv-btn { height: 42px; border-radius: 11px; font-size: .9rem !important; font-weight: 800 !important; cursor: pointer; font-family: inherit; }
    body #moveRoomModal .mv-btn-ghost, body #extendModal .mv-btn-ghost { border: 1px solid #cbd5e1; background: #fff; color: #334155 !important; -webkit-text-fill-color: #334155 !important; }
    body #moveRoomModal .mv-btn-primary, body #extendModal .mv-btn-primary, body .mv-notice .mv-btn-primary { border: 0; background: linear-gradient(135deg, #1e3a8a, #2563eb); color: #fff !important; -webkit-text-fill-color: #fff !important; box-shadow: 0 8px 18px -8px rgba(37, 99, 235, .7); }
    body #extendModal .mv-btn-green { background: linear-gradient(135deg, #065f46, #059669); box-shadow: 0 8px 18px -8px rgba(5, 150, 105, .7); }
    body #moveRoomModal .mv-btn:disabled, body #extendModal .mv-btn:disabled { background: #94a3b8; box-shadow: none; cursor: not-allowed; }
    .mv-up { position: fixed; inset: 0; z-index: 100002; display: none; align-items: center; justify-content: center; padding: 16px; background: rgba(15, 23, 42, .55); backdrop-filter: blur(3px); }
    .mv-up.open { display: flex; }
    .mv-up-box { width: min(400px, 100%); max-height: 92vh; overflow-y: auto; border-radius: 18px; background: #fff; box-shadow: 0 28px 70px rgba(0, 0, 0, .35); color: #0f172a; animation: mvIn .18s ease-out; }
    .mv-up-head { display: flex; gap: 12px; align-items: center; padding: 16px 18px; border-radius: 18px 18px 0 0; }
    .mv-up-head.up { background: linear-gradient(135deg, #fff7ed, #ffedd5); } .mv-up-head.down { background: linear-gradient(135deg, #eff6ff, #dbeafe); }
    .mv-up-ic { width: 40px; height: 40px; flex: none; border-radius: 12px; display: grid; place-items: center; color: #fff; }
    .mv-up-head.up .mv-up-ic { background: #ea580c; } .mv-up-head.down .mv-up-ic { background: #2563eb; }
    .mv-up-ic svg { width: 22px; height: 22px; }
    .mv-up-ttl { font-weight: 800; font-size: .95rem; color: #0f172a; } .mv-up-who { font-size: .78rem; color: #64748b; margin-top: 2px; }
    .mv-up-route { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 16px 18px 4px; }
    .mv-up-room { flex: 1; text-align: center; padding: 10px 8px; border-radius: 12px; background: #f1f5f9; border: 1px solid #e2e8f0; }
    .mv-up-room.new { background: #eff6ff; border-color: #93c5fd; }
    .mv-up-room b { display: block; font-size: 1.35rem; font-weight: 800; color: #0f172a; } .mv-up-room span { font-size: .72rem; color: #64748b; }
    .mv-up-go { font-size: 1.3rem; color: #94a3b8; }
    .mv-up-date { text-align: center; font-size: .75rem; color: #64748b; padding: 4px 18px 10px; }
    .mv-up-rows { margin: 0 18px; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
    .mv-up-rows > div { display: flex; justify-content: space-between; align-items: center; padding: 9px 14px; font-size: .82rem; border-top: 1px solid #f1f5f9; } .mv-up-rows > div:first-child { border-top: 0; }
    .mv-up-rows span { color: #475569; } .mv-up-rows b { color: #0f172a; font-weight: 800; }
    .mv-up-rows .chg.up b { color: #ea580c; } .mv-up-rows .chg.down b { color: #2563eb; }
    .mv-up-rows .tot { background: #f8fafc; } .mv-up-rows .tot b { font-size: 1.15rem; }
    .mv-up-rows b.due { color: #dc2626; } .mv-up-rows b.paid { color: #16a34a; }
    .mv-up-note { margin: 12px 18px 0; font-size: .76rem; color: #64748b; line-height: 1.5; text-align: center; }
    .mv-up-btns { display: flex; flex-direction: column; gap: 8px; padding: 14px 18px 18px; }
    .mv-up-btn { height: 46px; border-radius: 12px; font-weight: 800; font-size: .9rem; cursor: pointer; font-family: inherit; border: 1px solid transparent; }
    body .mv-up-btn.main { background: linear-gradient(135deg, #1e3a8a, #2563eb); color: #fff !important; -webkit-text-fill-color: #fff !important; box-shadow: 0 8px 18px -8px rgba(37, 99, 235, .7); }
    body .mv-up-btn.alt { background: #fff; border-color: #cbd5e1; color: #1e3a8a !important; -webkit-text-fill-color: #1e3a8a !important; }
    body .mv-up-btn.no { background: transparent; height: 38px; color: #64748b !important; -webkit-text-fill-color: #64748b !important; }    .mv-notice { position: fixed; inset: 0; z-index: 100001; display: none; align-items: center; justify-content: center; padding: 16px; background: rgba(15, 23, 42, .45); }
    .mv-notice.open { display: flex; }
    .mv-notice-box { width: min(340px, 100%); padding: 22px 20px 16px; border-radius: 16px; background: #fff; text-align: center; box-shadow: 0 24px 60px rgba(0, 0, 0, .3); animation: mvIn .18s ease-out; }
    .mv-notice-ic { width: 54px; height: 54px; margin: 0 auto 10px; border-radius: 50%; display: grid; place-items: center; }
    .mv-notice-ic svg { width: 28px; height: 28px; }
    .mv-notice-ic.ok { background: #dcfce7; color: #16a34a; }
    .mv-notice-ic.warn { background: #fef3c7; color: #b45309; }
    .mv-notice-ic.err { background: #fee2e2; color: #dc2626; }
    body .mv-notice-msg { font-size: .88rem !important; font-weight: 700 !important; color: #0f172a !important; -webkit-text-fill-color: #0f172a !important; line-height: 1.5; margin-bottom: 14px; }
    .mv-notice-box .mv-btn { width: 100%; }
    /* Mode gelap */
    [data-theme="dark"] #moveRoomModal .mv-modal, [data-theme="dark"] #extendModal .mv-modal, [data-theme="dark"] .mv-notice-box,
    [data-theme="dark"] #moveRoomModal .mv-foot, [data-theme="dark"] #extendModal .mv-foot { background: #111a2e; border-color: rgba(255, 255, 255, .1); }
    body[data-theme="dark"] #moveRoomModal .mv-in, body[data-theme="dark"] #extendModal .mv-in { background: rgba(255, 255, 255, .06) !important; border-color: rgba(255, 255, 255, .16) !important; color: #f1f5f9 !important; -webkit-text-fill-color: #f1f5f9 !important; color-scheme: dark; }
    body[data-theme="dark"] #moveRoomModal .mv-lbl, body[data-theme="dark"] #extendModal .mv-lbl, body[data-theme="dark"] #moveRoomModal .mv-hint, body[data-theme="dark"] #extendModal .mv-hint,
    body[data-theme="dark"] #moveRoomModal .mv-sum span, body[data-theme="dark"] #extendModal .mv-sum span, body[data-theme="dark"] #moveRoomModal .mv-price-in span, body[data-theme="dark"] #extendModal .mv-price-in span { color: #cbd5e1 !important; -webkit-text-fill-color: #cbd5e1 !important; }
    body[data-theme="dark"] #moveRoomModal .mv-sum b, body[data-theme="dark"] #extendModal .mv-sum b, body[data-theme="dark"] .mv-notice-msg { color: #f1f5f9 !important; -webkit-text-fill-color: #f1f5f9 !important; }
    [data-theme="dark"] #moveRoomModal .mv-price, [data-theme="dark"] #extendModal .mv-price, [data-theme="dark"] #moveRoomModal .mv-sum, [data-theme="dark"] #extendModal .mv-sum { background: rgba(255, 255, 255, .03); border-color: rgba(255, 255, 255, .1); }
    [data-theme="dark"] #moveRoomModal .mv-sum > div, [data-theme="dark"] #extendModal .mv-sum > div { border-color: rgba(255, 255, 255, .06); }
    [data-theme="dark"] #moveRoomModal .mv-sum .tot, [data-theme="dark"] #extendModal .mv-sum .tot { background: rgba(37, 99, 235, .14); }
    body[data-theme="dark"] #moveRoomModal .mv-sum .tot span, body[data-theme="dark"] #moveRoomModal .mv-sum .tot b, body[data-theme="dark"] #extendModal .mv-sum .tot span, body[data-theme="dark"] #extendModal .mv-sum .tot b { color: #bfdbfe !important; -webkit-text-fill-color: #bfdbfe !important; }
    [data-theme="dark"] #moveRoomModal .mv-sum .sub, [data-theme="dark"] #extendModal .mv-sum .sub { background: rgba(124, 58, 237, .1); }
    [data-theme="dark"] #moveRoomModal .mv-from { background: rgba(37, 99, 235, .14); border-color: rgba(37, 99, 235, .4); }
    body[data-theme="dark"] #moveRoomModal .mv-from b { color: #bfdbfe !important; -webkit-text-fill-color: #bfdbfe !important; }
    body[data-theme="dark"] #moveRoomModal .mv-from span { color: #cbd5e1 !important; -webkit-text-fill-color: #cbd5e1 !important; }
    [data-theme="dark"] #moveRoomModal .mv-eff { background: rgba(245, 158, 11, .08); border-color: rgba(245, 158, 11, .3); }
    body[data-theme="dark"] #moveRoomModal .mv-ota, body[data-theme="dark"] #extendModal .mv-ota { background: rgba(124, 58, 237, .1); border-color: rgba(124, 58, 237, .35); color: #e9d5ff !important; -webkit-text-fill-color: #e9d5ff !important; }
    body[data-theme="dark"] #moveRoomModal .mv-ota b, body[data-theme="dark"] #extendModal .mv-ota b { color: #ddd6fe !important; -webkit-text-fill-color: #ddd6fe !important; }
    body[data-theme="dark"] #moveRoomModal .mv-btn-ghost, body[data-theme="dark"] #extendModal .mv-btn-ghost { background: rgba(255, 255, 255, .06); border-color: rgba(255, 255, 255, .16); color: #e2e8f0 !important; -webkit-text-fill-color: #e2e8f0 !important; }
    [data-theme="dark"] #extendModal .mv-static { background: rgba(255, 255, 255, .05); border-color: rgba(255, 255, 255, .12); color: #f1f5f9; }
    [data-theme="dark"] #extendModal .mv-static-hi { background: rgba(16, 185, 129, .12); border-color: rgba(16, 185, 129, .4); color: #a7f3d0; }
    [data-theme="dark"] #extendModal .mv-step button { background: rgba(255, 255, 255, .06); border-color: rgba(255, 255, 255, .16); color: #f1f5f9; }
    @media (max-width: 480px) { #moveRoomModal .mv-rooms { grid-template-columns: 1fr; } #moveRoomModal .mv-arrow { display: none; } }
</style>
<!-- EDIT RESERVATION MODAL (desain sama dengan New Reservation) -->
<div id="editResModal" class="edit-res-overlay" onclick="if(event.target===this)closeEditResModal()">
    <div class="modal-content modal-compact modal-compact-booking">
        <div class="modal-header-compact">
            <div>
                <h2>Edit Reservation</h2>
                <small id="editResSub">Ubah data tamu, tanggal &amp; harga</small>
            </div>
            <button type="button" class="close-btn" onclick="closeEditResModal()" aria-label="Tutup">&times;</button>
        </div>

        <form onsubmit="event.preventDefault(); submitEditReservation();">
            <input type="hidden" id="editResBookingId">
            <div class="form-compact">
                <div class="nr-sec">Tamu</div>
                <div class="form-row-2col">
                    <div class="input-compact">
                        <label>Nama tamu *</label>
                        <input type="text" id="editResGuestName" required placeholder="Nama lengkap">
                    </div>
                    <div class="input-compact">
                        <label>Telepon / WA</label>
                        <input type="text" id="editResGuestPhone" placeholder="08xx">
                    </div>
                </div>
                <div class="form-row-2col">
                    <div class="input-compact">
                        <label>Email</label>
                        <input type="email" id="editResEmail" placeholder="email@contoh.com">
                    </div>
                    <div class="input-compact">
                        <label>No. KTP / Paspor</label>
                        <input type="text" id="editResIdNumber">
                    </div>
                </div>

                <div class="nr-sec">Menginap</div>
                <div class="form-row-2col">
                    <div class="input-compact">
                        <label>Check-in *</label>
                        <input type="date" id="editResCheckIn" required onchange="updateEditResInfo()">
                    </div>
                    <div class="input-compact">
                        <label>Check-out *</label>
                        <input type="date" id="editResCheckOut" required onchange="updateEditResInfo()">
                    </div>
                </div>
                <div class="form-row-2col">
                    <div class="input-compact">
                        <label>Jumlah tamu (dewasa)</label>
                        <input type="number" id="editResNumGuests" min="1" max="30" value="1">
                    </div>
                    <div class="input-compact">
                        <label>Harga / malam · Rp</label>
                        <input type="number" id="editResRoomPrice" min="0" step="1000" onchange="updateEditResInfo()">
                    </div>
                </div>

                <div id="editResGroupBookings" class="er-group" style="display:none;">
                    <div class="er-group-title">Kamar dalam reservasi grup</div>
                    <div id="editResGroupList"></div>
                </div>

                <div class="nr-sec">Sumber &amp; Harga</div>
                <div class="input-compact">
                    <label>Sumber booking</label>
                    <select id="editResSource" onchange="updateEditResInfo()">
                    <?php
                    $directSrc = array_filter($bookingSources ?? [], fn($s) => ($s['source_type'] ?? '') === 'direct');
                    $otaSrcList = array_filter($bookingSources ?? [], fn($s) => ($s['source_type'] ?? '') !== 'direct');
                    if (!empty($directSrc) || !empty($otaSrcList)): ?>
                        <optgroup label="Direct">
                            <?php foreach ($directSrc as $src): ?>
                                <option value="<?php echo $src['source_key']; ?>"><?php echo $src['icon'] . ' ' . $src['source_name']; ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="OTA">
                            <?php foreach ($otaSrcList as $src): ?>
                                <option value="<?php echo $src['source_key']; ?>"><?php echo $src['icon'] . ' ' . $src['source_name'] . ' (fee ' . $src['fee_percent'] . '%)'; ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php else: ?>
                        <option value="walk_in">Walk-in</option>
                        <option value="phone">Phone</option>
                        <option value="agoda">Agoda</option>
                        <option value="booking">Booking.com</option>
                        <option value="tiket">Tiket.com</option>
                        <option value="ota">OTA Lainnya</option>
                    <?php endif; ?>
                </select>
                </div>

                <div class="price-summary-compact">
                    <div class="price-line">
                        <span>Malam</span>
                        <strong id="editResNights">0</strong>
                    </div>
                    <div class="price-line">
                        <span>Subtotal</span>
                        <strong id="editResSubtotal">Rp 0</strong>
                    </div>
                    <div class="price-line nr-discount">
                        <span>Diskon</span>
                        <div class="nr-discount-ctl">
                            <div class="discount-type-toggle">
                                <button type="button" class="disc-type-btn edit-disc-type-btn active" data-type="rp" onclick="setEditDiscType('rp')">Rp</button>
                                <button type="button" class="disc-type-btn edit-disc-type-btn" data-type="percent" onclick="setEditDiscType('percent')">%</button>
                            </div>
                            <input type="number" id="editResDiscount" min="0" value="" placeholder="0" inputmode="decimal" onchange="updateEditResInfo()">
                            <input type="hidden" id="editResDiscountType" value="rp">
                        </div>
                    </div>
                    <div id="editResDiscPreview" class="nr-disc-preview"></div>
                    <div class="price-line er-ota" id="editResOtaRow" style="display:none;">
                        <span>OTA fee (<span id="editResOtaPct">0</span>%) <small>· dipotong saat masuk buku kas</small></span>
                        <strong id="editResOtaAmt">- Rp 0</strong>
                    </div>
                    <div class="price-line-total">
                        <span>Grand total</span>
                        <strong id="editResTotal">Rp 0</strong>
                    </div>
                </div>

                <div class="input-compact">
                    <label>Permintaan khusus</label>
                    <textarea id="editResSpecialRequests" placeholder="Catatan untuk tamu / kamar"></textarea>
                </div>
            </div>

            <div class="modal-footer-compact">
                <button type="button" class="btn-cancel" onclick="closeEditResModal()">Batal</button>
                <button type="submit" class="btn-save" id="editResSaveBtn">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>
<script>
    // ===== DRAG & DROP BOOKING BARS =====
    // Drag berbasis pointer (bukan HTML5 drag): balok melayang mengikuti mouse tiap frame lewat transform
    // (tanpa layout ulang), sedangkan bayangan tujuan menempel ke grid — mulai tengah sel check-in, panjang
    // = jumlah malam — dan hanya diperbarui saat sel tujuan berganti. Klik biasa tetap membuka detail booking.
    (function() {
        const DRAG_THRESHOLD = 5;
        let pending = null; // pointerdown, belum bergerak cukup jauh
        let drag = null;
        let ghost = null;
        let raf = 0;
        let lastX = 0, lastY = 0;

        // Matikan drag bawaan browser pada balok
        document.addEventListener('dragstart', e => {
            if (e.target.closest && e.target.closest('.booking-bar-container')) e.preventDefault();
        }, true);

        function ensureGhost() {
            if (!ghost) {
                ghost = document.createElement('div');
                ghost.className = 'dnd-ghost';
                ghost.innerHTML = '<span class="dnd-ghost-t"></span><span class="dnd-ghost-d"></span>';
            }
            return ghost;
        }
        const hideGhost = () => { if (ghost && ghost.parentNode) ghost.parentNode.removeChild(ghost); };
        const hasClash = (roomId, ci, co) => drag.bars.some(x => x.roomId === roomId && x.ci < co && x.co > ci);
        const fmtShort = ymd => {
            const p = ymd.split('-').map(Number);
            return new Date(p[0], p[1] - 1, p[2]).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
        };
        const cellAt = (x, y) => {
            const el = document.elementFromPoint(x, y);
            const c = el && el.closest ? el.closest('.grid-date-cell') : null;
            return c && c.dataset.date && c.dataset.roomId ? c : null;
        };

        function targetOf(cell) {
            const roomId = String(cell.dataset.roomId);
            if (drag.inHouse) return { roomId, ci: drag.checkIn, co: drag.checkOut };
            const ci = mvAddDays(cell.dataset.date, -drag.grabOffset);
            return { roomId, ci, co: mvAddDays(ci, drag.nights) };
        }

        function placeGhost(cell) {
            const t = targetOf(cell);
            drag.target = t;
            const g = ensureGhost();
            const w = drag.cellW;
            const ciCell = document.querySelector('.grid-date-cell[data-room-id="' + t.roomId + '"][data-date="' + t.ci + '"]');
            const host = ciCell || cell;
            const shift = ciCell ? 0 : mvDaysBetween(cell.dataset.date, t.ci);
            const same = t.roomId === drag.roomId && t.ci === drag.checkIn;
            const past = !drag.inHouse && t.ci < MV_TODAY;
            const bad = !same && (past || hasClash(t.roomId, t.ci, t.co));
            g.style.left = 'calc(50% + ' + (shift * w) + 'px)';
            g.style.width = (drag.nights * w - 6) + 'px';
            g.className = 'dnd-ghost' + (bad ? ' bad' : '') + (same ? ' same' : '');
            g.firstChild.textContent = drag.guest;
            g.lastChild.textContent = bad ? (past ? 'Tanggal lewat' : 'Bentrok') : fmtShort(t.ci) + ' – ' + fmtShort(t.co);
            if (g.parentNode !== host) host.appendChild(g);
            drag.bad = bad;
            drag.same = same;
        }

        function frame() {
            raf = 0;
            if (!drag) return;
            drag.float.style.transform = 'translate3d(' + (lastX - drag.dx) + 'px,' + (lastY - drag.dy) + 'px,0)';
            // Geser otomatis di tepi area kalender
            const r = drag.scRect;
            let scrolled = false;
            if (drag.sc && r) {
                if (lastX > r.right - 50) { drag.sc.scrollLeft += 14; scrolled = true; }
                else if (lastX < r.left + 130 && drag.sc.scrollLeft > 0) { drag.sc.scrollLeft -= 14; scrolled = true; }
            }
            const cell = cellAt(lastX, lastY);
            if (cell) {
                const key = cell.dataset.roomId + '|' + cell.dataset.date;
                if (key !== drag.key) {
                    drag.key = key;
                    placeGhost(cell);
                }
            }
            if (scrolled) raf = requestAnimationFrame(frame); // terus bergulir selama kursor di tepi
        }

        function begin(p) {
            const c = p.container;
            const r = c.getBoundingClientRect();
            const nights = parseInt(c.dataset.nights, 10) || 1;
            const float = c.cloneNode(true);
            float.removeAttribute('draggable');
            float.removeAttribute('data-booking-id');
            float.removeAttribute('id');
            float.classList.add('dnd-float');
            float.style.width = r.width + 'px';
            float.style.left = '0px';
            float.style.top = '0px';
            document.body.appendChild(float);
            const sc = document.getElementById('drag-container');
            const anyCell = document.querySelector('.grid-date-cell');
            drag = {
                el: c,
                float,
                dx: p.x - r.left,
                dy: p.y - r.top,
                bookingId: c.dataset.bookingId,
                roomId: String(c.dataset.roomId),
                checkIn: c.dataset.checkIn,
                checkOut: c.dataset.checkOut,
                status: c.dataset.status,
                inHouse: c.dataset.status === 'checked_in',
                nights,
                guest: c.dataset.guest,
                grabOffset: Math.min(nights - 1, Math.max(0, p.grabOffset)),
                cellW: anyCell ? anyCell.offsetWidth : 110,
                sc,
                scRect: sc ? sc.getBoundingClientRect() : null,
                key: '',
                bad: false,
                same: true,
                target: null,
                bars: Array.from(document.querySelectorAll('.booking-bar-container[data-booking-id]'))
                    .filter(x => x !== c && x.dataset.status !== 'cancelled' && x.dataset.status !== 'checked_out')
                    .map(x => ({ roomId: String(x.dataset.roomId), ci: x.dataset.checkIn, co: x.dataset.checkOut }))
            };
            c.classList.add('dragging');
            document.body.classList.add('bar-dragging');
        }

        function end(commit) {
            const d = drag;
            drag = null;
            pending = null;
            if (raf) cancelAnimationFrame(raf), raf = 0;
            document.body.classList.remove('bar-dragging');
            hideGhost();
            if (!d) return;
            d.el.classList.remove('dragging');
            d.float.remove();
            // Klik yang muncul setelah drag jangan membuka detail booking
            const swallow = ev => { ev.stopPropagation(); ev.preventDefault(); };
            window.addEventListener('click', swallow, true);
            setTimeout(() => window.removeEventListener('click', swallow, true), 0);
            if (!commit || !d.target || d.same) {
                if (commit && d.inHouse && d.target && d.target.roomId === d.roomId && d.key && d.key.split('|')[1] !== d.checkIn) {
                    mvNotice('Tamu sudah check-in: tanggal tidak bisa digeser. Seret ke baris kamar lain untuk pindah kamar, atau pakai Extend untuk menambah malam.', 'warn');
                }
                return;
            }
            const t = d.target;
            if (!d.inHouse && t.ci < MV_TODAY) return mvNotice('Reservasi tidak bisa dipindah ke tanggal yang sudah lewat.', 'warn');
            if (hasClashFor(d, t)) return mvNotice('Kamar tujuan sudah terisi pada tanggal tersebut.', 'warn');
            openMoveModal({
                bookingId: d.bookingId,
                guest: d.guest,
                status: d.status,
                roomId: d.roomId,
                checkIn: d.checkIn,
                checkOut: d.checkOut,
                newRoomId: t.roomId,
                newCheckIn: t.ci,
                newCheckOut: t.co,
                direct: true
            });
        }
        const hasClashFor = (d, t) => d.bars.some(x => x.roomId === t.roomId && x.ci < t.co && x.co > t.ci);

        document.addEventListener('pointerdown', function(e) {
            if (e.button !== 0 || e.pointerType === 'touch') return; // sentuh: tetap geser kalender
            const c = e.target.closest('.booking-bar-container[draggable="true"]');
            if (!c || e.target.closest('button')) return;
            let grabOffset = 0;
            const under = document.elementsFromPoint(e.clientX, e.clientY).find(el => el.classList && el.classList.contains('grid-date-cell') && el.dataset.date);
            if (under) grabOffset = mvDaysBetween(c.dataset.checkIn, under.dataset.date);
            pending = { container: c, x: e.clientX, y: e.clientY, grabOffset };
        });

        window.addEventListener('pointermove', function(e) {
            if (pending && !drag) {
                if (Math.abs(e.clientX - pending.x) + Math.abs(e.clientY - pending.y) < DRAG_THRESHOLD) return;
                begin(pending);
            }
            if (!drag) return;
            e.preventDefault();
            lastX = e.clientX;
            lastY = e.clientY;
            if (!raf) raf = requestAnimationFrame(frame);
        }, { passive: false });

        window.addEventListener('pointerup', function() {
            if (drag) end(true);
            pending = null;
        });
        window.addEventListener('pointercancel', () => end(false));
        window.addEventListener('keydown', e => { if (e.key === 'Escape' && drag) end(false); });
        window.addEventListener('blur', () => { if (drag) end(false); });
    })();
    // ===== PINDAH KAMAR / UPGRADE / DOWNGRADE =====
    const MV_TODAY = '<?php echo date('Y-m-d'); ?>';
    const MV_ROOMS = <?php echo json_encode(array_map(fn($r) => ['id' => (int)$r['id'], 'no' => (string)$r['room_number'], 'type' => (string)($r['type_name'] ?? ''), 'price' => (float)($r['base_price'] ?? 0)], $rooms ?? [])); ?>;
    let mvCtx = null;
    let mvTimer = null;

    function mvAddDays(ymd, n) {
        const p = String(ymd).split('-').map(Number);
        const dt = new Date(Date.UTC(p[0], p[1] - 1, p[2] + n));
        return dt.toISOString().slice(0, 10);
    }

    function mvDaysBetween(a, b) {
        const pa = String(a).split('-').map(Number), pb = String(b).split('-').map(Number);
        return Math.round((Date.UTC(pb[0], pb[1] - 1, pb[2]) - Date.UTC(pa[0], pa[1] - 1, pa[2])) / 86400000);
    }
    const mvRp = n => 'Rp ' + Math.round(parseFloat(n) || 0).toLocaleString('id-ID');
    // Input harga dengan pemisah ribuan (688.750); nilai dikirim sebagai angka polos
    const mvMoneyFmt = n => Math.round(parseFloat(n) || 0).toLocaleString('id-ID');
    const mvMoneyVal = el => (String(el.value).replace(/\D/g, '') || '0');
    window.mvMoneyInput = function(el) {
        const caret = el.selectionStart || 0;
        const digitsBefore = String(el.value).slice(0, caret).replace(/\D/g, '').length;
        const digits = String(el.value).replace(/\D/g, '').replace(/^0+(?=\d)/, '');
        el.value = digits ? Number(digits).toLocaleString('id-ID') : '';
        // Kursor tetap setelah digit yang sama
        let pos = 0, seen = 0;
        while (pos < el.value.length && seen < digitsBefore) { if (/\d/.test(el.value[pos])) seen++; pos++; }
        try { el.setSelectionRange(pos, pos); } catch (e) {}
    };
    const mvDate = ymd => {
        const p = String(ymd).split('-').map(Number);
        return new Date(p[0], p[1] - 1, p[2]).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
    };

    // Popup info kecil (pengganti alert browser)
    window.mvNotice = function(msg, type, onClose) {
        let el = document.getElementById('mvNotice');
        if (!el) {
            el = document.createElement('div');
            el.id = 'mvNotice';
            el.className = 'mv-notice';
            el.innerHTML = '<div class="mv-notice-box"><div class="mv-notice-ic"></div><div class="mv-notice-msg"></div><button type="button" class="mv-btn mv-btn-primary">OK</button></div>';
            document.body.appendChild(el);
        }
        const ok = type === 'ok';
        el.querySelector('.mv-notice-ic').className = 'mv-notice-ic ' + (ok ? 'ok' : (type === 'err' ? 'err' : 'warn'));
        el.querySelector('.mv-notice-ic').innerHTML = ok ?
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M20 6 9 17l-5-5"/></svg>' :
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 9v4M12 17h.01"/><circle cx="12" cy="12" r="10"/></svg>';
        el.querySelector('.mv-notice-msg').textContent = msg;
        const btn = el.querySelector('button');
        btn.style.display = ok ? 'none' : '';
        const close = () => { el.classList.remove('open'); if (onClose) onClose(); };
        btn.onclick = close;
        el.onclick = ev => { if (ev.target === el) close(); };
        el.classList.add('open');
        if (ok) setTimeout(close, 1500);
    };

    window.openMoveModal = function(o) {
        mvCtx = Object.assign({ priceEdited: false }, o);
        const inHouse = o.status === 'checked_in';
        const cur = MV_ROOMS.find(r => String(r.id) === String(o.roomId));
        document.getElementById('mvSub').textContent = (o.guest || '-') + (o.code ? ' · ' + o.code : '');
        document.getElementById('mvFrom').innerHTML = '<b>' + (cur ? cur.no : '-') + '</b><span>' + (cur ? cur.type + ' · ' + mvRp(cur.price) : '') + '</span>';
        // Pilihan kamar dikelompokkan per tipe beserta harga asli
        const sel = document.getElementById('mvRoom');
        const groups = {};
        MV_ROOMS.forEach(r => { (groups[r.type] = groups[r.type] || []).push(r); });
        sel.innerHTML = Object.keys(groups).map(t => '<optgroup label="' + t + ' · ' + mvRp(groups[t][0].price) + '">' +
            groups[t].map(r => '<option value="' + r.id + '">' + r.no + (String(r.id) === String(o.roomId) ? ' (kamar sekarang)' : '') + '</option>').join('') + '</optgroup>').join('');
        sel.value = String(o.newRoomId || o.roomId);
        document.getElementById('mvCheckIn').value = o.newCheckIn || o.checkIn;
        document.getElementById('mvCheckOut').value = o.newCheckOut || o.checkOut;
        document.getElementById('mvCheckIn').disabled = inHouse;
        document.getElementById('mvCheckIn').min = inHouse ? '' : MV_TODAY;
        const effWrap = document.getElementById('mvEffWrap');
        effWrap.style.display = inHouse ? '' : 'none';
        const eff = document.getElementById('mvEff');
        eff.min = o.checkIn;
        eff.max = mvAddDays(o.checkOut, -1);
        eff.value = MV_TODAY < o.checkIn ? o.checkIn : (MV_TODAY >= o.checkOut ? mvAddDays(o.checkOut, -1) : MV_TODAY);
        document.getElementById('mvPrice').value = '';
        document.getElementById('mvErr').style.display = 'none';
        document.getElementById('mvSave').disabled = true;
        if (!o.direct) document.getElementById('moveRoomModal').classList.add('active');
        mvPreview();
    };

    window.closeMoveModal = function() {
        document.getElementById('moveRoomModal').classList.remove('active');
        mvCtx = null;
    };

    function mvPayload(preview) {
        const fd = new FormData();
        fd.append('booking_id', mvCtx.bookingId);
        fd.append('new_room_id', document.getElementById('mvRoom').value);
        fd.append('new_check_in', document.getElementById('mvCheckIn').value);
        fd.append('new_check_out', document.getElementById('mvCheckOut').value);
        if (mvCtx.status === 'checked_in') fd.append('effective_date', document.getElementById('mvEff').value);
        if (mvCtx.priceEdited) fd.append('room_price', mvMoneyVal(document.getElementById('mvPrice')));
        if (preview) fd.append('preview', '1');
        return fd;
    }

    window.mvChanged = function(field) {
        if (!mvCtx) return;
        if (field === 'price') mvCtx.priceEdited = true;
        if (field === 'room') mvCtx.priceEdited = false; // ganti kamar → harga otomatis lagi
        if (field === 'checkin' && mvCtx.status !== 'checked_in') {
            // Geser check-out ikut menjaga jumlah malam
            const nights = mvDaysBetween(mvCtx.checkIn, mvCtx.checkOut);
            document.getElementById('mvCheckOut').value = mvAddDays(document.getElementById('mvCheckIn').value, nights);
        }
        clearTimeout(mvTimer);
        mvTimer = setTimeout(mvPreview, 250);
    };

    window.mvResetPrice = function() {
        if (!mvCtx) return;
        mvCtx.priceEdited = false;
        mvPreview();
    };

    function mvPreview() {
        if (!mvCtx) return;
        const ctx = mvCtx;
        document.getElementById('mvSave').disabled = true;
        fetch('../../api/move-booking.php', { method: 'POST', body: mvPayload(true) })
            .then(r => r.json())
            .then(res => {
                if (mvCtx !== ctx) return;
                const err = document.getElementById('mvErr');
                if (!res.success) {
                    if (ctx.direct) { ctx.direct = false; document.getElementById('moveRoomModal').classList.add('active'); }
                    err.textContent = res.message || 'Tidak bisa dipindah';
                    err.style.display = '';
                    document.getElementById('mvSummary').style.display = 'none';
                    return;
                }
                err.style.display = 'none';
                const d = res.data;
                ctx.last = d;
                const kind = { upgrade: ['Upgrade', 'up'], downgrade: ['Downgrade', 'down'], same: ['Pindah kamar · tipe sama', 'same'], none: ['Ubah tanggal', 'same'] }[d.change_kind] || ['Pindah', 'same'];
                const badge = document.getElementById('mvKind');
                badge.textContent = kind[0];
                badge.className = 'mv-kind ' + kind[1];
                if (!ctx.priceEdited) document.getElementById('mvPrice').value = mvMoneyFmt(d.new_price);
                const isUpDown = d.change_kind === 'upgrade' || d.change_kind === 'downgrade';
                document.getElementById('mvPriceHint').innerHTML = d.is_ota ?
                    (d.change_kind === 'upgrade' ? 'Harga OTA ' + mvRp(d.old_price) + ' + selisih ' + mvRp(d.surcharge) + ' per malam' :
                        (d.change_kind === 'downgrade' ? 'Booking OTA: harga tetap ' + mvRp(d.old_price) + ' (tidak ada pengembalian)' : 'Harga OTA tetap ' + mvRp(d.old_price))) :
                    (isUpDown ? 'Harga asli ' + d.new_room.type + ' ' + mvRp(d.new_room.base_price) + ' · sebelumnya ' + mvRp(d.old_price) : 'Harga booking tetap ' + mvRp(d.old_price));
                // Info booking OTA: selisih upgrade dihitung setelah fee dan dibayar tamu langsung ke hotel
                const otaBox = document.getElementById('mvOta');
                if (d.is_ota) {
                    const src = (typeof SOURCE_NAMES !== 'undefined' && SOURCE_NAMES[d.source]) || d.source;
                    const fp = Math.round(d.fee_percent * 100) / 100;
                    otaBox.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg><div>' +
                        'Booking via <b>' + escHtml(src) + '</b> · fee ' + fp + '%' +
                        (d.change_kind === 'upgrade' ? '<br>Selisih = (' + mvRp(d.new_room.base_price) + ' − ' + mvRp(d.old_room.base_price) + ') × ' + (100 - fp) + '% = <b>' + mvRp(d.surcharge) + '</b>/malam, dibayar tamu langsung ke hotel (tanpa fee).' : '') +
                        '</div>';
                    otaBox.style.display = '';
                } else {
                    otaBox.style.display = 'none';
                }
                document.getElementById('mvPriceReset').style.display = ctx.priceEdited && Math.round(d.new_price) !== Math.round(d.auto_price) ? '' : 'none';
                const nightsRow = d.nights_before > 0 ?
                    d.nights_before + ' mlm sebelumnya ' + mvRp(d.before_total) + ' + ' + d.nights_after + ' mlm × ' + mvRp(d.new_price) :
                    d.nights + ' mlm × ' + mvRp(d.new_price);
                const diff = d.final_price - d.old_final;
                document.getElementById('mvSummary').innerHTML =
                    '<div><span>Malam</span><b>' + nightsRow + '</b></div>' +
                    '<div><span>Subtotal kamar</span><b>' + mvRp(d.total_price) + '</b></div>' +
                    (d.discount > 0 ? '<div><span>Diskon (tetap)</span><b class="neg">− ' + mvRp(d.discount) + '</b></div>' : '') +
                    (d.extras > 0 ? '<div><span>Extras</span><b>' + mvRp(d.extras) + '</b></div>' : '') +
                    '<div class="tot"><span>Total baru</span><b>' + mvRp(d.final_price) + (Math.round(diff) !== 0 ? ' <em class="' + (diff > 0 ? 'plus' : 'minus') + '">' + (diff > 0 ? '+' : '−') + mvRp(Math.abs(diff)) + '</em>' : '') + '</b></div>' +
                    (d.is_ota && d.direct_amount > 0 ? '<div class="sub"><span>Via OTA (dipotong fee)</span><b>' + mvRp(d.ota_amount) + '</b></div><div class="sub"><span>Bayar langsung ke hotel</span><b class="vio">' + mvRp(d.direct_amount) + '</b></div>' : '') +
                    '<div><span>Sudah dibayar</span><b>' + mvRp(d.paid) + '</b></div>' +
                    '<div><span>Sisa tagihan</span><b class="' + (d.balance > 0 ? 'due' : 'paid') + '">' + (d.balance > 0 ? mvRp(d.balance) : 'Lunas') + '</b></div>';
                document.getElementById('mvSummary').style.display = '';
                document.getElementById('mvSave').disabled = false;
                // Geser kamar di kalender: langsung ke konfirmasi / simpan, tanpa membuka popup Pindah Kamar
                if (ctx.direct && !ctx.directDone) { ctx.directDone = true; mvSubmit(); }
            })
            .catch(() => {
                const err = document.getElementById('mvErr');
                err.textContent = 'Gagal menghubungi server';
                err.style.display = '';
            });
    }

    // Popup konfirmasi up-charge / penurunan harga (seperti Cloudbeds) — muncul sebelum pindah kamar yang mengubah total tersimpan
    window.mvSubmit = function() {
        if (!mvCtx) return;
        const d = mvCtx.last;
        const diff = d ? Math.round(d.final_price - d.old_final) : 0;
        if (!d || d.old_room.id === d.new_room.id || diff === 0) { mvDoSave(null); return; }
        const up = diff > 0;
        const ctx = mvCtx;
        let el = document.getElementById('mvUp');
        if (!el) {
            el = document.createElement('div');
            el.id = 'mvUp';
            el.className = 'mv-up';
            document.body.appendChild(el);
        }
        const arrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="' + (up ? 'M12 19V5M5 12l7-7 7 7' : 'M12 5v14M5 12l7 7 7-7') + '"/></svg>';
        el.innerHTML = '<div class="mv-up-box">' +
            '<div class="mv-up-head ' + (up ? 'up' : 'down') + '"><span class="mv-up-ic">' + arrow + '</span><div><div class="mv-up-ttl">' + (up ? 'Upgrade kamar · ada tambahan biaya' : 'Downgrade kamar · harga turun') + '</div>' +
            '<div class="mv-up-who">' + escHtml(ctx.guest || '-') + (ctx.code ? ' · ' + escHtml(ctx.code) : '') + '</div></div></div>' +
            '<div class="mv-up-route"><div class="mv-up-room"><b>' + escHtml(d.old_room.number) + '</b><span>' + escHtml(d.old_room.type || '') + '</span></div>' +
            '<span class="mv-up-go">→</span>' +
            '<div class="mv-up-room new"><b>' + escHtml(d.new_room.number) + '</b><span>' + escHtml(d.new_room.type || '') + '</span></div></div>' +
            '<div class="mv-up-date">' + mvDate(d.check_in) + ' – ' + mvDate(d.check_out) + ' · ' + d.nights + ' malam</div>' +
            '<div class="mv-up-rows">' +
            '<div><span>Total sebelumnya</span><b>' + mvRp(d.old_final) + '</b></div>' +
            '<div class="chg ' + (up ? 'up' : 'down') + '"><span>' + (up ? 'Tambahan biaya' : 'Pengurangan') + '</span><b>' + (up ? '+ ' : '− ') + mvRp(Math.abs(diff)) + '</b></div>' +
            '<div class="tot"><span>Total baru</span><b>' + mvRp(d.final_price) + '</b></div>' +
            (d.paid > 0 ? '<div><span>Sudah dibayar</span><b>' + mvRp(d.paid) + '</b></div>' : '') +
            '<div><span>Sisa tagihan</span><b class="' + (d.balance > 0 ? 'due' : 'paid') + '">' + (d.balance > 0 ? mvRp(d.balance) : 'Lunas') + '</b></div></div>' +
            '<p class="mv-up-note">' + (up ? 'Tambahan ' + mvRp(diff) + ' akan ditagihkan ke tamu dan otomatis dikirim ke Cloudbeds.' : 'Cloudbeds tidak bisa dikurangi otomatis — kurangi manual di folio Cloudbeds.') + '</p>' +
            '<div class="mv-up-btns"><button type="button" class="mv-up-btn main" id="mvUpOk">' + (up ? 'Konfirmasi Up-charge' : 'Konfirmasi Pindah') + '</button>' +
            '<button type="button" class="mv-up-btn alt" id="mvUpKeep">Pindah tanpa ubah harga</button>' +
            '<button type="button" class="mv-up-btn no" id="mvUpNo">Batal</button></div></div>';
        const close = () => el.classList.remove('open');
        el.querySelector('#mvUpOk').onclick = () => { close(); mvDoSave(null); };
        el.querySelector('#mvUpKeep').onclick = () => { close(); mvDoSave(String(d.old_price)); }; // "override": harga per malam tetap
        el.querySelector('#mvUpNo').onclick = () => { close(); if (ctx.direct) closeMoveModal(); };
        el.classList.add('open');
    };

    function mvDoSave(forcePrice) {
        if (!mvCtx) return;
        const btn = document.getElementById('mvSave');
        const ctx = mvCtx;
        const payload = mvPayload(false);
        if (forcePrice !== null) payload.set('room_price', forcePrice);

        // Optimistic path: a pure room swap (same dates, same room type, not in-house) only changes
        // which row the bar sits in, so move the bar right now and confirm with the server in the
        // background. Anything that changes dates / price / availability counts falls back to a
        // reload (immediately after the save, without the old 1.5s notice).
        const d = ctx.last;
        const newRoomId = document.getElementById('mvRoom').value;
        const day = v => String(v || '').slice(0, 10);
        const optimistic = ctx.status !== 'checked_in' && d && d.old_room && d.new_room &&
            String(newRoomId) !== String(ctx.roomId) &&
            (d.old_room.type || '') === (d.new_room.type || '') &&
            day(document.getElementById('mvCheckIn').value) === day(ctx.checkIn) &&
            day(document.getElementById('mvCheckOut').value) === day(ctx.checkOut);
        let undoMove = null;
        if (optimistic) {
            const bar = document.querySelector('.booking-bar-container[data-booking-id="' + ctx.bookingId + '"]');
            const target = document.querySelector('.grid-date-cell[data-room-id="' + newRoomId + '"][data-date="' + day(ctx.checkIn) + '"]');
            if (bar && target && bar.parentNode && target !== bar.parentNode) {
                const oldParent = bar.parentNode, oldNext = bar.nextSibling, oldRoom = bar.dataset.roomId;
                target.appendChild(bar);
                bar.dataset.roomId = String(newRoomId);
                undoMove = () => {
                    oldParent.insertBefore(bar, oldNext && oldNext.parentNode === oldParent ? oldNext : null);
                    bar.dataset.roomId = oldRoom;
                };
            }
        }

        if (undoMove) {
            closeMoveModal();
            spToast('Memindahkan kamar…', true);
        } else {
            btn.disabled = true;
            btn.textContent = 'Menyimpan…';
        }

        fetch('../../api/move-booking.php', { method: 'POST', body: payload })
            .then(r => r.json())
            .then(res => {
                btn.textContent = 'Simpan';
                if (res.success) {
                    if (mvCtx === ctx) closeMoveModal();
                    if (undoMove) spToast(res.message || 'Kamar dipindahkan', true);
                    else saveScrollAndReload();
                } else if (undoMove) {
                    undoMove();
                    spToast(res.message || 'Gagal memindahkan — dikembalikan', false);
                } else {
                    btn.disabled = false;
                    document.getElementById('moveRoomModal').classList.add('active'); // mode langsung: tampilkan popup agar pesan terlihat
                    const err = document.getElementById('mvErr');
                    err.textContent = res.message || 'Gagal menyimpan';
                    err.style.display = '';
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.textContent = 'Simpan';
                if (undoMove) {
                    undoMove();
                    spToast('Gagal menghubungi server — kamar dikembalikan', false);
                } else {
                    mvNotice('Gagal menghubungi server', 'err');
                }
            });
    }
    // ===== EXTEND STAY FUNCTIONS =====
    let extendCurrentCO = '';
    let extPriceEdited = false;
    let extTimer = null;

    window.openExtendModal = function(bookingId, guestName, checkoutDate) {
        document.getElementById('extendBookingId').value = bookingId;
        document.getElementById('extendGuestName').textContent = guestName || '-';
        extendCurrentCO = String(checkoutDate).slice(0, 10);
        document.getElementById('extendCurrentCO').textContent = mvDate(extendCurrentCO);
        document.getElementById('extendNights').value = 1;
        document.getElementById('extendPrice').value = '';
        extPriceEdited = false;
        updateExtendPreview();
        document.getElementById('extendModal').classList.add('active');
    };

    window.closeExtendModal = function() {
        document.getElementById('extendModal').classList.remove('active');
    };

    window.adjustExtendNights = function(delta) {
        const input = document.getElementById('extendNights');
        input.value = Math.min(30, Math.max(1, (parseInt(input.value, 10) || 1) + delta));
        updateExtendPreview();
    };

    document.addEventListener('input', function(e) {
        if (e.target.id === 'extendNights') updateExtendPreview();
    });

    function updateExtendPreview() {
        const nights = Math.max(1, parseInt(document.getElementById('extendNights').value, 10) || 1);
        document.getElementById('extendNewCO').textContent = mvDate(mvAddDays(extendCurrentCO, nights));
        extPreview();
    }

    function extPayload(preview) {
        const fd = new FormData();
        fd.append('booking_id', document.getElementById('extendBookingId').value);
        fd.append('extra_nights', Math.max(1, parseInt(document.getElementById('extendNights').value, 10) || 1));
        if (extPriceEdited) fd.append('night_price', mvMoneyVal(document.getElementById('extendPrice')));
        if (preview) fd.append('preview', '1');
        return fd;
    }

    window.extPreview = function() {
        clearTimeout(extTimer);
        extTimer = setTimeout(function() {
            const save = document.getElementById('extendSave');
            save.disabled = true;
            fetch('../../api/extend-stay.php', { method: 'POST', body: extPayload(true) })
                .then(r => r.json())
                .then(res => {
                    const err = document.getElementById('extendErr');
                    if (!res.success) {
                        err.textContent = res.message || 'Tidak bisa diperpanjang';
                        err.style.display = '';
                        document.getElementById('extendSummary').style.display = 'none';
                        return;
                    }
                    err.style.display = 'none';
                    const d = res.data;
                    if (!extPriceEdited) document.getElementById('extendPrice').value = mvMoneyFmt(d.night_price);
                    const fp = Math.round(d.fee_percent * 100) / 100;
                    document.getElementById('extendPriceHint').textContent = d.is_ota ?
                        'Harga asli kamar − fee ' + fp + '% = ' + mvRp(d.auto_night) + ' per malam' :
                        'Harga per malam booking ' + mvRp(d.room_price);
                    const ota = document.getElementById('extendOta');
                    if (d.is_ota) {
                        const src = (typeof SOURCE_NAMES !== 'undefined' && SOURCE_NAMES[d.source]) || d.source;
                        ota.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg><div>Booking via <b>' + escHtml(src) + '</b>: malam tambahan dibayar tamu <b>langsung ke hotel</b> (Cash/Transfer/QRIS) dan tidak dipotong fee OTA.</div>';
                        ota.style.display = '';
                    } else {
                        ota.style.display = 'none';
                    }
                    const nights = Math.max(1, parseInt(document.getElementById('extendNights').value, 10) || 1);
                    document.getElementById('extendSummary').innerHTML =
                        '<div><span>Tambahan</span><b>' + nights + ' mlm × ' + mvRp(d.night_price) + ' = ' + mvRp(d.additional_price) + '</b></div>' +
                        '<div class="tot"><span>Total baru</span><b>' + mvRp(d.final_price) + '<em class="plus">+' + mvRp(d.final_price - d.old_final) + '</em></b></div>' +
                        (d.is_ota ? '<div class="sub"><span>Bayar langsung ke hotel</span><b class="vio">' + mvRp(d.direct_amount) + '</b></div>' : '') +
                        '<div><span>Sudah dibayar</span><b>' + mvRp(d.paid) + '</b></div>' +
                        '<div><span>Sisa tagihan</span><b class="' + (d.balance > 0 ? 'due' : 'paid') + '">' + (d.balance > 0 ? mvRp(d.balance) : 'Lunas') + '</b></div>';
                    document.getElementById('extendSummary').style.display = '';
                    save.disabled = false;
                })
                .catch(() => {
                    const err = document.getElementById('extendErr');
                    err.textContent = 'Gagal menghubungi server';
                    err.style.display = '';
                });
        }, 200);
    };

    window.submitExtendStay = function() {
        const save = document.getElementById('extendSave');
        save.disabled = true;
        fetch('../../api/extend-stay.php', { method: 'POST', body: extPayload(false) })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    closeExtendModal();
                    mvNotice(data.message + ' · tambahan ' + mvRp(data.data.additional_price), 'ok', () => saveScrollAndReload());
                } else {
                    save.disabled = false;
                    const err = document.getElementById('extendErr');
                    err.textContent = data.message || 'Gagal';
                    err.style.display = '';
                }
            })
            .catch(() => {
                save.disabled = false;
                mvNotice('Gagal menghubungi server', 'err');
            });
    };
    // ===== EDIT RESERVATION FUNCTIONS =====
    window.openEditReservationModal = function(bookingId) {
        // Fetch booking details
        fetch('../../api/get-booking-details.php?id=' + bookingId)
            .then(r => {
                // Check status code
                if (!r.ok) {
                    return r.text().then(text => {
                        throw {
                            status: r.status,
                            statusText: r.statusText,
                            body: text
                        };
                    });
                }
                return r.text();
            })
            .then(text => {
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('❌ JSON Parse Error:', e);
                    console.error('Raw response:', text);

                    // Try to extract error message from HTML error response
                    let errorMsg = 'Server Error: Respons bukan JSON';
                    if (text.includes('Fatal error') || text.includes('Error')) {
                        const match = text.match(/Fatal error.*?:<\/b>\s*(.+?)(?:<br|<\/|$)/i);
                        if (match) errorMsg = 'Server Error: ' + match[1];
                    }

                    alert('❌ ' + errorMsg + '\n\nSilakan cek console browser untuk detail lengkap.');
                    console.error('Full error response:', text);
                    return;
                }
                if (!data.success) {
                    alert('❌ ' + (data.message || 'Gagal load data'));
                    return;
                }
                const b = data.booking;
                document.getElementById('editResBookingId').value = b.id;
                document.getElementById('editResGuestName').value = b.guest_name || '';
                document.getElementById('editResGuestPhone').value = b.guest_phone || '';
                document.getElementById('editResEmail').value = b.guest_email || '';
                document.getElementById('editResIdNumber').value = b.guest_id_number || '';
                document.getElementById('editResCheckIn').value = b.check_in_date;
                document.getElementById('editResCheckOut').value = b.check_out_date;
                document.getElementById('editResNumGuests').value = b.num_guests || b.adults || 1;
                document.getElementById('editResRoomPrice').value = b.room_price || '';
                document.getElementById('editResSpecialRequests').value = b.special_requests || '';

                // Set booking source
                const srcSelect = document.getElementById('editResSource');
                if (srcSelect && b.booking_source) {
                    srcSelect.value = b.booking_source;
                    // If value didn't match any option, try adding it dynamically
                    if (srcSelect.value !== b.booking_source) {
                        const opt = document.createElement('option');
                        opt.value = b.booking_source;
                        opt.text = (typeof SOURCE_NAMES !== 'undefined' && SOURCE_NAMES[b.booking_source]) ?
                            SOURCE_NAMES[b.booking_source] :
                            b.booking_source.charAt(0).toUpperCase() + b.booking_source.slice(1);
                        srcSelect.appendChild(opt);
                        srcSelect.value = b.booking_source;
                    }
                }

                // Set discount (stored as Rp in DB, reset toggle to Rp)
                setEditDiscType('rp');
                const discInput = document.getElementById('editResDiscount');
                if (discInput) {
                    discInput.value = parseFloat(b.discount) > 0 ? parseFloat(b.discount) : '';
                }
                document.getElementById('editResSub').textContent = [b.booking_code, b.room_number ? 'Room ' + b.room_number : '', b.room_type || b.type_name || ''].filter(Boolean).join(' · ') || 'Ubah data tamu, tanggal & harga';

                // Display group bookings if multiple rooms
                const groupSection = document.getElementById('editResGroupBookings');
                const groupList = document.getElementById('editResGroupList');
                if (b.group_bookings && b.group_bookings.length > 1) {
                    const fmtR = (v) => 'Rp ' + new Intl.NumberFormat('id-ID').format(v || 0);
                    const escH = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
                    let html = '';
                    b.group_bookings.forEach(function(gb) {
                        html += '<div class="er-room"><div><b>Room ' + escH(gb.room_number) + '</b> <small>' + escH(gb.type_name) + '</small>' +
                            (gb.id === b.id ? '<span class="on">DIEDIT</span>' : '') + '</div>' +
                            '<small>' + fmtR(gb.room_price) + (parseFloat(gb.discount) > 0 ? ' · disc ' + fmtR(gb.discount) : '') + ' · <b>' + fmtR(gb.final_price) + '</b></small></div>';
                    });
                    groupList.innerHTML = html;
                    groupSection.style.display = 'block';
                } else {
                    groupSection.style.display = 'none';
                }

                updateEditResInfo();
                document.getElementById('editResModal').classList.add('active');
            })
            .catch(err => {
                if (err.status) {
                    // HTTP Error
                    let errorMsg = '❌ Error ' + err.status + ' (' + err.statusText + '):\n' + err.body.substring(0, 200);
                    console.error('HTTP Error Response:', err);
                    alert(errorMsg);
                } else {
                    // Network error
                    alert('❌ Network Error: ' + err.message);
                }
            });
    };

    window.closeEditResModal = function() {
        document.getElementById('editResModal').classList.remove('active');
    };

    function setEditDiscType(type) {
        const discInput = document.getElementById('editResDiscount');
        document.getElementById('editResDiscountType').value = type;
        document.querySelectorAll('.edit-disc-type-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.type === type));
        if (type === 'percent') {
            discInput.max = 100;
            discInput.placeholder = '0-100';
        } else {
            discInput.removeAttribute('max');
            discInput.placeholder = '0';
        }
        updateEditResInfo();
    }

    function updateEditResInfo() {
        const fmt = v => 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(v || 0));
        const ci = document.getElementById('editResCheckIn').value;
        const co = document.getElementById('editResCheckOut').value;
        const price = parseFloat(document.getElementById('editResRoomPrice').value) || 0;
        const discVal = parseFloat(document.getElementById('editResDiscount').value) || 0;
        const discType = document.getElementById('editResDiscountType').value;
        const source = document.getElementById('editResSource').value;
        const feePercent = (typeof OTA_FEES !== 'undefined' && OTA_FEES[source]) ? parseFloat(OTA_FEES[source]) : 0;

        const nights = (ci && co) ? Math.max(0, Math.ceil((new Date(co) - new Date(ci)) / 86400000)) : 0;
        const subtotal = price * nights;
        const discount = discType === 'percent' ? Math.round(subtotal * discVal / 100) : discVal;
        const afterDiscount = Math.max(0, subtotal - discount);
        const feeAmount = feePercent > 0 ? Math.round(afterDiscount * feePercent / 100) : 0;

        document.getElementById('editResNights').textContent = nights + (nights ? ' × ' + fmt(price) : '');
        document.getElementById('editResSubtotal').textContent = fmt(subtotal);
        document.getElementById('editResDiscPreview').textContent = discount > 0 ? '- ' + fmt(discount) + (discType === 'percent' ? ' (' + discVal + '%)' : '') : '';
        document.getElementById('editResOtaRow').style.display = feePercent > 0 ? '' : 'none';
        document.getElementById('editResOtaPct').textContent = feePercent;
        document.getElementById('editResOtaAmt').textContent = '- ' + fmt(feeAmount);
        // Total tagihan tetap bruto; fee OTA dipotong saat tercatat di buku kas (check-in).
        document.getElementById('editResTotal').textContent = fmt(afterDiscount);
    }

    function editResOk(text) {
        const p = document.createElement('div');
        p.className = 'er-ok';
        p.innerHTML = '<div class="er-ok-card"><svg viewBox="0 0 52 52" aria-hidden="true"><circle cx="26" cy="26" r="24"/><path d="M15 27.5l7.5 7.5L37.5 19"/></svg><b></b></div>';
        p.querySelector('b').textContent = text;
        document.body.appendChild(p);
        setTimeout(() => p.remove(), 1500);
    }

    // Live update edit form info
    ['editResCheckIn', 'editResCheckOut', 'editResRoomPrice', 'editResDiscount', 'editResSource'].forEach(id => {
        document.addEventListener('input', function(e) {
            if (e.target.id === id) updateEditResInfo();
        });
        document.addEventListener('change', function(e) {
            if (e.target.id === id) updateEditResInfo();
        });
    });

    window.submitEditReservation = function() {
        const bookingId = document.getElementById('editResBookingId').value;
        if (!bookingId) return;

        const sourceVal = document.getElementById('editResSource').value;
        console.log('🔍 SUBMIT DEBUG - booking_source value:', sourceVal);
        console.log('🔍 SUBMIT DEBUG - select selectedIndex:', document.getElementById('editResSource').selectedIndex);
        console.log('🔍 SUBMIT DEBUG - select options:', Array.from(document.getElementById('editResSource').options).map(o => o.value + '=' + o.text));

        const formData = new FormData();
        formData.append('booking_id', bookingId);
        formData.append('guest_name', document.getElementById('editResGuestName').value);
        formData.append('guest_phone', document.getElementById('editResGuestPhone').value);
        formData.append('guest_email', document.getElementById('editResEmail').value);
        formData.append('guest_id_number', document.getElementById('editResIdNumber').value);
        formData.append('check_in_date', document.getElementById('editResCheckIn').value);
        formData.append('check_out_date', document.getElementById('editResCheckOut').value);
        formData.append('num_guests', document.getElementById('editResNumGuests').value);
        formData.append('room_price', document.getElementById('editResRoomPrice').value);
        formData.append('special_requests', document.getElementById('editResSpecialRequests').value);
        formData.append('booking_source', document.getElementById('editResSource').value);
        formData.append('discount_value', document.getElementById('editResDiscount').value);
        formData.append('discount_type', document.getElementById('editResDiscountType').value);

        fetch('../../api/update-reservation.php', {
                method: 'POST',
                body: formData
            })
            .then(r => {
                if (!r.ok) {
                    return r.text().then(text => {
                        throw {
                            status: r.status,
                            statusText: r.statusText,
                            body: text
                        };
                    });
                }
                return r.json().catch(err => {
                    throw {
                        parseError: true,
                        message: 'Response bukan JSON'
                    };
                });
            })
            .then(data => {
                if (data.success) {
                    editResOk('Reservasi berhasil diperbarui');
                    setTimeout(() => saveScrollAndReload(), 1300);

                    // ✅ FIX: Refresh data booking di side panel
                    const bookingId = document.getElementById('editResBookingId').value;
                    const intendedSource = document.getElementById('editResSource').value;
                    console.log(`🔄 REFRESH: Fetching booking ${bookingId} after edit (source was: ${intendedSource})`);

                    if (bookingId && currentPaymentBooking) {
                        fetch('../../api/get-booking-details.php?id=' + bookingId)
                            .then(r => r.json())
                            .then(result => {
                                if (result.success) {
                                    console.log(`✅ Booking ${bookingId} refreshed successfully:`, result.booking);
                                    console.log(`   booking_source from API: "${result.booking.booking_source}"`);
                                    currentPaymentBooking = result.booking;
                                    showBookingQuickView(result.booking);
                                    console.log(`✅ Side panel updated with refreshed data`);
                                } else {
                                    console.error(`❌ Refresh failed:`, result.message);
                                }
                            })
                            .catch(e => {
                                console.error(`❌ Refresh error:`, e);
                            });
                    } else {
                        console.warn(`⚠️ Refresh skipped: bookingId=${bookingId}, hasCurrentPaymentBooking=${!!currentPaymentBooking}`);
                    }

                    closeEditResModal();
                } else {
                    alert('❌ ' + data.message);
                }
            })
            .catch(err => {
                if (err.status) {
                    console.error('API Error:', err);
                    alert('❌ Error ' + err.status + ':\n' + err.body.substring(0, 300));
                } else if (err.parseError) {
                    console.error('Parse Error:', err);
                    alert('❌ Respons server tidak valid');
                } else {
                    alert('❌ Error: ' + err.message);
                }
            });
    };
</script>

<?php include __DIR__ . '/cloudbeds-autosync-include.php'; ?>
<?php include '../../includes/footer.php'; ?>