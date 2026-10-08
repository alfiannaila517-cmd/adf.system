<?php

/**
 * FRONT DESK - TAMU IN HOUSE
 * Daftar semua tamu yang sedang check-in
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

if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$pageTitle = 'Tamu In House';

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// ============================================
// GET IN-HOUSE GUESTS
// ============================================
$queryError = null;
$debugInfo = [];
try {
    $today = date('Y-m-d');

    // Test simple query first
    $testQuery = "SELECT COUNT(*) as total FROM bookings WHERE status = 'checked_in'";
    $testResult = $db->fetchOne($testQuery);
    $debugInfo['simple_count'] = $testResult['total'] ?? 0;

    // Test with direct connection
    $conn = $db->getConnection();
    $stmt = $conn->prepare("
        SELECT 
            b.id as booking_id,
            b.booking_code,
            b.check_in_date,
            b.check_out_date,
            b.actual_checkin_time,
            b.room_price,
            b.final_price,
            b.payment_status,
            b.status,
            b.booking_source,
            COALESCE(bp.total_paid, 0) as paid_amount,
            g.id as guest_id,
            g.guest_name,
            g.phone,
            g.email,
            g.id_card_number,
            g.address,
            r.id as room_id,
            r.room_number,
            r.floor_number,
            rt.type_name as type_name,
            rt.base_price,
            DATEDIFF(b.check_out_date, b.check_in_date) as total_nights,
            DATEDIFF(b.check_out_date, CURDATE()) as nights_remaining,
            DATEDIFF(CURDATE(), b.check_in_date) as nights_stayed
        FROM bookings b
        INNER JOIN guests g ON b.guest_id = g.id
        INNER JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        LEFT JOIN (
            SELECT booking_id, SUM(amount) as total_paid
            FROM booking_payments
            GROUP BY booking_id
        ) bp ON b.id = bp.booking_id
        WHERE b.status = 'checked_in'
        ORDER BY r.room_number ASC
    ");

    $stmt->execute();
    $inHouseGuests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $debugInfo['query_result'] = count($inHouseGuests);

    error_log("Direct PDO query returned: " . count($inHouseGuests) . " results");
    if (count($inHouseGuests) > 0) {
        error_log("First result: " . print_r($inHouseGuests[0], true));
    }
} catch (Exception $e) {
    $queryError = $e->getMessage();
    error_log("In House Query Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    $inHouseGuests = [];
}

// Get Checkout History (today and yesterday)
$checkoutHistory = [];
try {
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $stmt = $conn->prepare("
        SELECT 
            b.id as booking_id,
            b.booking_code,
            b.check_in_date,
            b.check_out_date,
            b.actual_checkout_time,
            b.final_price,
            b.payment_status,
            g.guest_name,
            g.phone,
            r.room_number,
            rt.type_name
        FROM bookings b
        INNER JOIN guests g ON b.guest_id = g.id
        INNER JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE b.status = 'checked_out'
        AND DATE(b.actual_checkout_time) >= :yesterday
        ORDER BY b.actual_checkout_time DESC
        LIMIT 20
    ");
    $stmt->execute(['yesterday' => $yesterday]);
    $checkoutHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Checkout History Error: " . $e->getMessage());
}

// Status bayar dihitung dari pembayaran yang benar-benar tercatat; booking grup memakai total
// gabungan semua kamar (kolom payment_status per kamar bisa masih 'unpaid' walau grup sudah lunas).
// Logika sama dengan running text (getUnpaidCheckedInGuests).
if (!empty($inHouseGuests)) {
    try {
        $ihIds = array_map('intval', array_column($inHouseGuests, 'booking_id'));
        $ph = implode(',', array_fill(0, count($ihIds), '?'));
        $paidSub = "SELECT booking_id, SUM(amount) AS total_paid FROM booking_payments GROUP BY booking_id";
        $st = $conn->prepare("SELECT b.id, b.group_id, b.final_price,
                GREATEST(COALESCE(bp.total_paid, 0), COALESCE(b.paid_amount, 0)) AS paid
            FROM bookings b LEFT JOIN ({$paidSub}) bp ON bp.booking_id = b.id WHERE b.id IN ({$ph})");
        $st->execute($ihIds);
        $ihPay = [];
        $ihGroups = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ihPay[(int)$row['id']] = $row;
            if (!empty($row['group_id'])) $ihGroups[$row['group_id']] = true;
        }
        $groupTotals = [];
        if ($ihGroups) {
            $gIds = array_keys($ihGroups);
            $gph = implode(',', array_fill(0, count($gIds), '?'));
            $gs = $conn->prepare("SELECT b.group_id, SUM(b.final_price) AS final_total,
                    SUM(GREATEST(COALESCE(bp.total_paid, 0), COALESCE(b.paid_amount, 0))) AS paid_total
                FROM bookings b LEFT JOIN ({$paidSub}) bp ON bp.booking_id = b.id
                WHERE b.group_id IN ({$gph}) AND b.status <> 'cancelled' GROUP BY b.group_id");
            $gs->execute($gIds);
            foreach ($gs->fetchAll(PDO::FETCH_ASSOC) as $g) {
                $groupTotals[$g['group_id']] = $g;
            }
        }
        foreach ($inHouseGuests as &$ihGuest) {
            $p = $ihPay[(int)$ihGuest['booking_id']] ?? null;
            if (!$p) continue;
            if (!empty($p['group_id']) && isset($groupTotals[$p['group_id']])) {
                $final = (float)$groupTotals[$p['group_id']]['final_total'];
                $paid = (float)$groupTotals[$p['group_id']]['paid_total'];
            } else {
                $final = (float)$p['final_price'];
                $paid = (float)$p['paid'];
            }
            $ihGuest['payment_status'] = ($final - $paid) <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
            $ihGuest['bill_total'] = $final;
            $ihGuest['bill_paid'] = $paid;
            $ihGuest['bill_rest'] = max(0, $final - $paid);
            $ihGuest['is_group'] = !empty($p['group_id']);
        }
        unset($ihGuest);
    } catch (Throwable $e) {
        error_log('In-house payment status: ' . $e->getMessage());
    }
}

// Calculate statistics
$totalInHouse = count($inHouseGuests);
$totalRevenue = array_sum(array_column($inHouseGuests, 'final_price'));
$paidCount = count(array_filter($inHouseGuests, fn($g) => $g['payment_status'] === 'paid'));
$unpaidCount = $totalInHouse - $paidCount;

include '../../includes/header.php';
?>

<style>
    .ih-container {
        max-width: 1400px;
        margin: 0 auto;
    }

    .ih-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .ih-header h1 {
        font-size: 1.5rem;
        font-weight: 800;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .ih-subtitle {
        color: var(--text-muted);
        font-size: 0.75rem;
        margin-top: 0.25rem;
    }

    /* Compact Stats */
    .ih-stats {
        display: flex;
        gap: 0.75rem;
        margin-bottom: 1.25rem;
        flex-wrap: wrap;
    }

    .ih-stat {
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 10px;
        padding: 0.75rem 1rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        min-width: 140px;
        flex: 1;
    }

    .ih-stat-icon {
        font-size: 1.25rem;
    }

    .ih-stat-info {
        flex: 1;
    }

    .ih-stat-value {
        font-size: 1.1rem;
        font-weight: 800;
        color: var(--text-primary);
        line-height: 1.2;
    }

    .ih-stat-label {
        font-size: 0.6rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Compact Guest Cards */
    .ih-guests {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 0.875rem;
        margin-bottom: 2rem;
    }

    .ih-card {
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 10px;
        padding: 0.875rem;
        transition: all 0.2s ease;
        position: relative;
        border-left: 3px solid #6366f1;
    }

    .ih-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }

    .ih-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.6rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px dashed var(--bg-tertiary);
    }

    .ih-booking-code {
        font-size: 0.7rem;
        font-family: 'Courier New', monospace;
        color: #6366f1;
        font-weight: 700;
    }

    .ih-room-badge {
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: white;
        padding: 0.25rem 0.5rem;
        border-radius: 5px;
        font-size: 0.7rem;
        font-weight: 700;
    }

    .ih-guest-name {
        font-size: 0.9rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.35rem;
    }

    .ih-info-row {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.72rem;
        color: var(--text-muted);
        margin-bottom: 0.25rem;
    }

    .ih-info-row span:first-child {
        font-size: 0.85rem;
    }

    .ih-payment {
        background: var(--bg-primary);
        border-radius: 6px;
        padding: 0.5rem;
        margin-top: 0.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .ih-payment-label {
        font-size: 0.65rem;
        color: var(--text-muted);
    }

    .ih-payment-value {
        font-size: 0.8rem;
        font-weight: 700;
    }

    .ih-payment-badge {
        padding: 0.15rem 0.4rem;
        border-radius: 4px;
        font-size: 0.6rem;
        font-weight: 700;
        color: white;
    }

    .ih-payment-badge.paid {
        background: #10b981;
    }

    .ih-payment-badge.partial {
        background: #f59e0b;
    }

    .ih-payment-badge.unpaid {
        background: #ef4444;
    }

    .ih-actions {
        display: flex;
        gap: 0.5rem;
        margin-top: 0.65rem;
    }

    .ih-btn {
        flex: 1;
        padding: 0.45rem 0.5rem;
        border: none;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.3rem;
        transition: all 0.2s;
        color: white;
    }

    .ih-btn-breakfast {
        background: linear-gradient(135deg, #f59e0b, #d97706);
    }

    .ih-btn-checkout {
        background: linear-gradient(135deg, #ef4444, #dc2626);
    }

    .ih-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
    }

    /* History Section */
    .ih-section-title {
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid var(--bg-tertiary);
    }

    .ih-history {
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 10px;
        overflow: hidden;
    }

    .ih-history-item {
        display: flex;
        align-items: center;
        padding: 0.6rem 0.875rem;
        border-bottom: 1px solid var(--bg-tertiary);
        gap: 0.75rem;
        font-size: 0.75rem;
    }

    .ih-history-item:last-child {
        border-bottom: none;
    }

    .ih-history-item:hover {
        background: var(--bg-primary);
    }

    .ih-history-room {
        background: var(--bg-tertiary);
        padding: 0.3rem 0.5rem;
        border-radius: 4px;
        font-weight: 700;
        font-size: 0.7rem;
        min-width: 45px;
        text-align: center;
    }

    .ih-history-guest {
        flex: 1;
        font-weight: 600;
        color: var(--text-primary);
    }

    .ih-history-time {
        color: var(--text-muted);
        font-size: 0.68rem;
    }

    .ih-history-price {
        font-weight: 700;
        color: #10b981;
    }

    .ih-empty {
        text-align: center;
        padding: 2rem;
        color: var(--text-muted);
    }

    .ih-empty-icon {
        font-size: 2.5rem;
        margin-bottom: 0.5rem;
    }

    @media (max-width: 768px) {
        .ih-guests {
            grid-template-columns: 1fr;
        }

        .ih-stats {
            flex-direction: column;
        }

        .ih-stat {
            min-width: 100%;
        }
    }

    /* === In-House ringkas: seragam dengan dashboard / buku kas (kaca, padat) ===
       Selector ber-body[data-theme] + !important karena style.css tema terang memaksa warna teks. */
    body[data-theme] .ih-container {
        --ih-glass: linear-gradient(135deg, rgba(255, 255, 255, 0.84), rgba(241, 247, 255, 0.64));
        --ih-edge: rgba(148, 163, 184, 0.3); /* tema terang: tepi abu tipis agar panel tidak menyatu dengan latar putih */
        --ih-line: rgba(148, 163, 184, 0.22);
        --ih-shadow: 0 10px 28px -16px rgba(15, 23, 42, 0.22);
    }

    body[data-theme="dark"] .ih-container {
        --ih-glass: linear-gradient(135deg, rgba(30, 41, 59, 0.62), rgba(15, 23, 42, 0.5));
        --ih-edge: rgba(255, 255, 255, 0.08);
        --ih-line: rgba(148, 163, 184, 0.16);
        --ih-shadow: 0 14px 32px -16px rgba(0, 0, 0, 0.6);
    }

    body[data-theme] .ih-header {
        margin-bottom: 0.7rem !important;
    }

    body[data-theme] .ih-header h1,
    body[data-theme] .ih-title {
        font-size: 0.95rem !important;
    }

    body[data-theme] .ih-subtitle {
        font-size: 0.66rem !important;
        margin-top: 0.1rem !important;
    }

    /* Statistik: satu strip dengan pemisah */
    body[data-theme] .ih-stats {
        display: grid !important;
        grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        gap: 0 !important;
        padding: 0.5rem 0 !important;
        margin-bottom: 0.9rem !important;
        border-radius: 14px !important;
        background: var(--ih-glass) !important;
        border: 1px solid var(--ih-edge) !important;
        box-shadow: var(--ih-shadow) !important;
    }

    body[data-theme] .ih-stat {
        padding: 0.05rem 1rem !important;
        background: transparent !important;
        border: none !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        min-height: 0 !important;
    }

    body[data-theme] .ih-stat + .ih-stat {
        border-left: 1px solid var(--ih-line) !important;
    }

    body[data-theme] .ih-stat-icon {
        display: none !important;
    }

    body[data-theme] .ih-stat-value {
        font-size: 0.86rem !important;
        font-weight: 700 !important;
        line-height: 1.25 !important;
        font-variant-numeric: tabular-nums;
    }

    body[data-theme] .ih-stat-label {
        font-size: 0.55rem !important;
        font-weight: 700 !important;
        letter-spacing: 0.07em !important;
        text-transform: uppercase;
        color: var(--text-muted) !important;
    }

    body[data-theme] .ih-section-title {
        font-size: 0.74rem !important;
        font-weight: 700 !important;
        margin: 0 0 0.55rem !important;
        padding: 0 !important;
        border: none !important;
    }

    /* Kartu tamu */
    body[data-theme] .ih-guests {
        display: grid !important;
        grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)) !important;
        gap: 0.6rem !important;
    }

    body[data-theme] .ih-card {
        display: flex !important;
        flex-direction: column;
        gap: 0.5rem;
        padding: 0.7rem 0.8rem !important;
        border-radius: 14px !important;
        background: var(--ih-glass) !important;
        border: 1px solid var(--ih-edge) !important;
        border-left: 3px solid #2563eb !important;
        box-shadow: var(--ih-shadow) !important;
        transform: none !important;
    }

    body[data-theme] .ih-card.is-co-today {
        border-left-color: #dc2626 !important;
    }

    .ih-card-top {
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }

    body[data-theme] .ih-room {
        flex-shrink: 0;
        min-width: 38px;
        height: 30px;
        padding: 0 0.4rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        font-size: 0.74rem;
        font-weight: 800;
        color: #fff !important;
        background: linear-gradient(135deg, #1e3a8a, #2563eb);
    }

    .ih-who {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        line-height: 1.25;
    }

    body[data-theme] .ih-who b {
        font-size: 0.76rem;
        font-weight: 700;
        color: var(--text-primary) !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    body[data-theme] .ih-who small {
        font-size: 0.58rem;
        color: var(--text-muted) !important;
        font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
    }

    body[data-theme] .ih-payment-badge {
        flex-shrink: 0;
        padding: 0.12rem 0.5rem !important;
        border-radius: 999px !important;
        font-size: 0.55rem !important;
        font-weight: 700 !important;
        letter-spacing: 0.05em;
    }

    .ih-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.3rem;
    }

    body[data-theme] .ih-meta span {
        font-size: 0.6rem;
        font-weight: 500;
        padding: 0.12rem 0.45rem;
        border-radius: 6px;
        background: rgba(148, 163, 184, 0.13);
        color: var(--text-secondary) !important;
        white-space: nowrap;
    }

    body[data-theme] .ih-meta .ih-co-chip {
        background: rgba(220, 38, 38, 0.1);
        color: #b91c1c !important;
        font-weight: 700;
    }

    .ih-card-foot {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        padding-top: 0.5rem;
        border-top: 1px solid var(--ih-line);
    }

    .ih-total {
        display: flex;
        flex-direction: column;
        line-height: 1.2;
    }

    body[data-theme] .ih-total small {
        font-size: 0.55rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--text-muted) !important;
    }

    body[data-theme] .ih-total b {
        font-size: 0.8rem;
        font-weight: 700;
        color: var(--text-primary) !important;
        font-variant-numeric: tabular-nums;
    }

    body[data-theme] .ih-card .ih-actions {
        display: flex !important;
        gap: 0.35rem !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
    }

    body[data-theme] .ih-card .ih-btn {
        flex: 0 0 auto !important;
        height: 28px !important;
        padding: 0 0.75rem !important;
        border-radius: 8px !important;
        font-size: 0.66rem !important;
        font-weight: 600 !important;
        box-shadow: none !important;
        transform: none !important;
    }

    /* Tombol WhatsApp tamu (ikon asli) */
    body[data-theme] .ih-card .ih-btn-wa {
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        width: 28px !important;
        padding: 0 !important;
        border-radius: 50% !important;
        border: 0 !important;
        background: #25D366 !important;
        color: #ffffff !important;
        text-decoration: none;
        box-shadow: 0 6px 14px -8px rgba(37, 211, 102, 0.9) !important;
    }
    body[data-theme] .ih-card .ih-btn-wa svg { width: 15px; height: 15px; fill: #ffffff; }
    body[data-theme] .ih-card a.ih-btn-wa:hover, body[data-theme] .ih-card button.ih-btn-wa:hover { background: #1ebe5b !important; }
    body[data-theme] .ih-card button.ih-btn-wa { cursor: pointer; }

    /* Menu WhatsApp tamu: kirim welcome / chat biasa */
    #ihWaMenu { position: fixed; z-index: 1200; width: 272px; padding: 6px; border-radius: 14px; background: #ffffff; border: 1px solid #e2e8f0; box-shadow: 0 22px 44px -16px rgba(15, 23, 42, .35); display: none; }
    #ihWaMenu.open { display: block; animation: ihWaIn .16s ease-out; }
    @keyframes ihWaIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
    #ihWaMenu .wm-head { padding: 7px 10px 8px; border-bottom: 1px solid #f1f5f9; margin-bottom: 4px; }
    body[data-theme] #ihWaMenu .wm-head b { display: block; font-size: .74rem !important; font-weight: 800; color: #0f172a !important; }
    body[data-theme] #ihWaMenu .wm-head small { display: block; font-size: .64rem !important; color: #64748b !important; margin-top: 1px; }
    #ihWaMenu .wm-item { width: 100%; display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 0; border-radius: 10px; background: transparent; cursor: pointer; text-align: left; font-family: inherit; text-decoration: none !important; }
    #ihWaMenu .wm-item:hover { background: #f0fdf4; }
    #ihWaMenu .wm-ic { width: 30px; height: 30px; border-radius: 9px; flex-shrink: 0; display: grid; place-items: center; background: #dcfce7; }
    body[data-theme] #ihWaMenu .wm-ic svg { width: 16px; height: 16px; color: #16a34a !important; fill: none; stroke: currentColor; }
    body[data-theme] #ihWaMenu .wm-txt b { display: block; font-size: .74rem !important; font-weight: 700; color: #0f172a !important; }
    body[data-theme] #ihWaMenu .wm-txt small { display: block; font-size: .64rem !important; color: #64748b !important; margin-top: 1px; line-height: 1.35; }
    #ihWaMenu .wm-item[disabled] { opacity: .55; cursor: wait; }
    body[data-theme="dark"] #ihWaMenu { background: #1e293b; border-color: rgba(148, 163, 184, .2); }
    body[data-theme="dark"] #ihWaMenu .wm-head { border-color: rgba(148, 163, 184, .14); }
    body[data-theme="dark"] #ihWaMenu .wm-head b, body[data-theme="dark"] #ihWaMenu .wm-txt b { color: #f1f5f9 !important; }
    body[data-theme="dark"] #ihWaMenu .wm-item:hover { background: rgba(34, 197, 94, .1); }
    #ihWaToast { position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%); z-index: 1300; max-width: calc(100vw - 32px); padding: 10px 16px; border-radius: 12px; background: #0f172a; box-shadow: 0 16px 36px -14px rgba(15, 23, 42, .5); display: none; }
    body[data-theme] #ihWaToast { color: #ffffff !important; font-size: .76rem !important; font-weight: 600; }
    #ihWaToast.show { display: block; }
    #ihWaToast.err { background: #b91c1c; }
    body[data-theme] .ih-card .ih-btn-wa.is-off { background: #e2e8f0 !important; box-shadow: none !important; cursor: not-allowed; }
    body[data-theme] .ih-card .ih-btn-wa.is-off svg { fill: #94a3b8; }

    body[data-theme] .ih-card .ih-btn-breakfast {
        background: rgba(217, 119, 6, 0.1) !important;
        border: 1px solid rgba(217, 119, 6, 0.35) !important;
        color: #b45309 !important;
    }

    body[data-theme] .ih-card .ih-btn-breakfast:hover {
        background: rgba(217, 119, 6, 0.18) !important;
    }

    body[data-theme] .ih-card .ih-btn-checkout {
        background: #991b1b !important;
        border: 1px solid #7f1d1d !important;
        color: #fff !important;
    }

    body[data-theme] .ih-card .ih-btn-checkout:hover {
        background: #7f1d1d !important;
    }

    body[data-theme] .ih-card .ih-btn-pay {
        background: #047857 !important;
        border: 1px solid #065f46 !important;
        color: #fff !important;
    }

    body[data-theme] .ih-card .ih-btn-pay:hover {
        background: #065f46 !important;
    }

    body[data-theme] .ih-total .ih-rest {
        font-size: 0.62rem;
        font-weight: 700;
        color: #dc2626 !important;
    }

    .ih-card.ih-focus {
        animation: ihFocus 1.6s ease-out 2;
    }

    @keyframes ihFocus {
        0% { box-shadow: 0 0 0 0 rgba(37, 99, 235, .55); }
        100% { box-shadow: 0 0 0 12px rgba(37, 99, 235, 0); }
    }

    /* Modal pembayaran */
    .pay-ov {
        --pm-card: #fff; --pm-ink: #0f172a; --pm-muted: #64748b; --pm-line: #e2e8f0; --pm-soft: #f8fafc;
        position: fixed; inset: 0; z-index: 10060; display: none; align-items: center; justify-content: center;
        padding: 16px; background: rgba(15, 23, 42, .5); backdrop-filter: blur(3px);
    }
    body[data-theme="dark"] .pay-ov { --pm-card: #111a2e; --pm-ink: #e2e8f0; --pm-muted: #94a3b8; --pm-line: rgba(255,255,255,.1); --pm-soft: rgba(255,255,255,.04); }
    .pay-ov.show { display: flex; }
    .pay-box {
        width: 100%; max-width: 420px; background: var(--pm-card); color: var(--pm-ink); border-radius: 16px;
        border: 1px solid var(--pm-line); box-shadow: 0 24px 60px -12px rgba(15, 23, 42, .45); overflow: hidden;
    }
    .pay-head { display: flex; align-items: center; gap: 10px; padding: 14px 16px; border-bottom: 1px solid var(--pm-line); }
    .pay-head .rm {
        min-width: 42px; height: 34px; padding: 0 6px; border-radius: 9px; display: grid; place-items: center;
        background: linear-gradient(135deg, #1e3a8a, #2563eb); color: #fff; font-weight: 700; font-size: .8rem;
    }
    .pay-head b { display: block; font-size: .86rem; color: var(--pm-ink); }
    .pay-head small { display: block; font-size: .7rem; color: var(--pm-muted); }
    body[data-theme] .pay-ov .pay-head button { margin-left: auto; border: 0; background: none; color: var(--pm-muted); font-size: 22px !important; cursor: pointer; line-height: 1; padding: 0 2px; }
    .pay-body { padding: 14px 16px; }
    .pay-sum { background: var(--pm-soft); border: 1px solid var(--pm-line); border-radius: 10px; padding: 8px 12px; margin-bottom: 12px; }
    .pay-sum div { display: flex; justify-content: space-between; font-size: .78rem; padding: 2px 0; color: var(--pm-muted); }
    .pay-sum div b { color: var(--pm-ink); font-weight: 600; }
    .pay-sum div.rest { border-top: 1px solid var(--pm-line); margin-top: 4px; padding-top: 6px; }
    .pay-sum div.rest b { color: #dc2626; font-weight: 700; }
    .pay-ov .pay-lbl { display: block; font-size: .68rem !important; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--pm-muted); margin: 0 0 5px; }
    body[data-theme] .pay-ov .pay-amt {
        width: 100%; height: 40px; border-radius: 10px; border: 1px solid var(--pm-line); background: var(--pm-card) !important; color: var(--pm-ink);
        padding: 0 12px; font-size: .95rem; font-weight: 700; font-variant-numeric: tabular-nums; outline: none;
    }
    .pay-amt:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, .15); }
    .pay-quick { display: flex; gap: 6px; margin: 6px 0 12px; }
    .pay-quick button { border: 1px solid var(--pm-line); background: var(--pm-soft); color: var(--pm-ink); border-radius: 999px; padding: 3px 10px; font-size: .7rem; cursor: pointer; }
    .pay-methods { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
    .pay-methods button {
        height: 34px; border-radius: 9px; border: 1px solid var(--pm-line); background: var(--pm-card); color: var(--pm-ink);
        font-size: .74rem; font-weight: 600; cursor: pointer;
    }
    .pay-methods button.on { background: #2563eb; border-color: #2563eb; color: #fff; }
    .pay-note { font-size: .7rem; color: var(--pm-muted); margin: 10px 0 0; }
    .pay-err { font-size: .74rem; color: #dc2626; margin: 8px 0 0; min-height: 1em; }
    .pay-foot { display: flex; gap: 8px; justify-content: flex-end; padding: 12px 16px; border-top: 1px solid var(--pm-line); }
    .pay-foot button { border-radius: 9px; padding: 8px 14px; font-size: .78rem; font-weight: 600; cursor: pointer; }
    .pay-foot .cancel { background: transparent; border: 1px solid var(--pm-line); color: var(--pm-muted); }
    .pay-foot .go { background: #047857; border: 1px solid #065f46; color: #fff; }
    .pay-foot .go:disabled { opacity: .6; cursor: wait; }
    /* Tema terang (style.css) memaksa warna teks dengan !important: kunci semua warna teks modal. */
    body[data-theme] .pay-ov .pay-head .rm,
    body[data-theme] .pay-ov .pay-methods button.on,
    body[data-theme] .pay-ov .pay-foot .go { color: #fff !important; -webkit-text-fill-color: #fff !important; }
    body[data-theme] .pay-ov .pay-head b,
    body[data-theme] .pay-ov .pay-sum div b,
    body[data-theme] .pay-ov .pay-amt,
    body[data-theme] .pay-ov .pay-quick button,
    body[data-theme] .pay-ov .pay-methods button:not(.on) { color: var(--pm-ink) !important; -webkit-text-fill-color: var(--pm-ink) !important; }
    body[data-theme] .pay-ov .pay-head small,
    body[data-theme] .pay-ov .pay-head button,
    body[data-theme] .pay-ov .pay-sum div span,
    body[data-theme] .pay-ov .pay-lbl,
    body[data-theme] .pay-ov .pay-note,
    body[data-theme] .pay-ov .pay-foot .cancel { color: var(--pm-muted) !important; -webkit-text-fill-color: var(--pm-muted) !important; }
    body[data-theme] .pay-ov .pay-sum div.rest b,
    body[data-theme] .pay-ov .pay-err { color: #dc2626 !important; -webkit-text-fill-color: #dc2626 !important; }
    @media (max-width: 760px) {
        body[data-theme] .ih-stats {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            row-gap: 0.5rem !important;
        }

        body[data-theme] .ih-stat:nth-child(3) {
            border-left: none !important;
        }
    }
</style>

<div class="ih-container">
    <!-- Header -->
    <div class="ih-header">
        <div>
            <h1>🏨 Tamu In House</h1>
            <p class="ih-subtitle">Daftar tamu yang sedang menginap • <?php echo date('l, d F Y'); ?></p>
        </div>
    </div>

    <!-- Compact Stats -->
    <div class="ih-stats">
        <div class="ih-stat">
            <div class="ih-stat-icon">🏨</div>
            <div class="ih-stat-info">
                <div class="ih-stat-value"><?php echo $totalInHouse; ?></div>
                <div class="ih-stat-label">Total In House</div>
            </div>
        </div>
        <div class="ih-stat">
            <div class="ih-stat-icon">✅</div>
            <div class="ih-stat-info">
                <div class="ih-stat-value"><?php echo $paidCount; ?></div>
                <div class="ih-stat-label">Lunas</div>
            </div>
        </div>
        <div class="ih-stat">
            <div class="ih-stat-icon">⚠️</div>
            <div class="ih-stat-info">
                <div class="ih-stat-value"><?php echo $unpaidCount; ?></div>
                <div class="ih-stat-label">Belum Bayar</div>
            </div>
        </div>
        <div class="ih-stat">
            <div class="ih-stat-icon">💰</div>
            <div class="ih-stat-info">
                <div class="ih-stat-value">Rp <?php echo number_format($totalRevenue / 1000000, 1, ',', '.'); ?>jt</div>
                <div class="ih-stat-label">Revenue</div>
            </div>
        </div>
    </div>

    <!-- In House Guests -->
    <?php if (count($inHouseGuests) > 0): ?>
        <h2 class="ih-section-title">👥 Tamu Menginap (<?php echo $totalInHouse; ?>)</h2>
        <div class="ih-guests">
            <?php foreach ($inHouseGuests as $guest):
                $checkIn = date('d/m', strtotime($guest['check_in_date']));
                $checkOut = date('d/m', strtotime($guest['check_out_date']));
                $totalPrice = number_format($guest['final_price'], 0, ',', '.');
                $paidRaw = $guest['paid_amount'] ?? 0;
                $source = match ($guest['booking_source'] ?? '') {
                    'walk_in' => 'Walk-in',
                    'phone' => 'Phone',
                    'online' => 'Online',
                    default => 'OTA'
                };
                $paymentClass = match ($guest['payment_status']) {
                    'paid' => 'paid',
                    'partial' => 'partial',
                    default => 'unpaid'
                };
                $paymentLabel = match ($guest['payment_status']) {
                    'paid' => 'LUNAS',
                    'partial' => 'CICIL',
                    default => 'PENDING'
                };
            ?>
                <?php $isCoToday = (date('Y-m-d', strtotime($guest['check_out_date'])) <= date('Y-m-d')); ?>
                <div class="ih-card<?php echo $isCoToday ? ' is-co-today' : ''; ?>" id="ihb-<?php echo (int)$guest['booking_id']; ?>">
                    <div class="ih-card-top">
                        <span class="ih-room"><?php echo htmlspecialchars((string)$guest['room_number']); ?></span>
                        <div class="ih-who">
                            <b title="<?php echo htmlspecialchars($guest['guest_name']); ?>"><?php echo htmlspecialchars($guest['guest_name']); ?></b>
                            <small><?php echo htmlspecialchars($guest['booking_code']); ?></small>
                        </div>
                        <span class="ih-payment-badge <?php echo $paymentClass; ?>"><?php echo $paymentLabel; ?></span>
                    </div>

                    <div class="ih-meta">
                        <span><?php echo $checkIn; ?> → <?php echo $checkOut; ?> · <?php echo (int)$guest['total_nights']; ?> mlm</span>
                        <span><?php echo $source; ?></span>
                        <?php if (!empty($guest['type_name'])): ?><span><?php echo htmlspecialchars($guest['type_name']); ?></span><?php endif; ?>
                        <?php if (!empty($guest['phone'])): ?><span><?php echo htmlspecialchars($guest['phone']); ?></span><?php endif; ?>
                        <?php if ($isCoToday): ?><span class="ih-co-chip">Check-out hari ini</span><?php endif; ?>
                    </div>

                    <div class="ih-card-foot">
                        <?php $billRest = (float)($guest['bill_rest'] ?? 0); ?>
                        <div class="ih-total">
                            <small>Total</small>
                            <b>Rp <?php echo $totalPrice; ?></b>
                            <?php if ($billRest > 0): ?><span class="ih-rest">Sisa Rp <?php echo number_format($billRest, 0, ',', '.'); ?></span><?php endif; ?>
                        </div>
                        <div class="ih-actions">
                            <?php if ($billRest > 0):
                                $payData = [
                                    'id'    => (int)$guest['booking_id'],
                                    'name'  => (string)$guest['guest_name'],
                                    'room'  => (string)$guest['room_number'],
                                    'code'  => (string)$guest['booking_code'],
                                    'total' => (float)$guest['bill_total'],
                                    'paid'  => (float)$guest['bill_paid'],
                                    'rest'  => $billRest,
                                    'group' => !empty($guest['is_group']),
                                ]; ?>
                                <button class="ih-btn ih-btn-pay" onclick='openPayModal(<?php echo htmlspecialchars(json_encode($payData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)'>
                                    Payment
                                </button>
                            <?php endif; ?>
                            <?php
                            // WhatsApp langsung ke tamu (wa.me): 08xx / +62 / 8xx -> 62xx.
                            $waDigits = preg_replace('/\D+/', '', (string)($guest['phone'] ?? ''));
                            if ($waDigits !== '' && $waDigits[0] === '0') $waDigits = '62' . substr($waDigits, 1);
                            elseif ($waDigits !== '' && $waDigits[0] === '8') $waDigits = '62' . $waDigits;
                            if (strlen($waDigits) >= 9):
                                $waMsg = 'Hello ' . $guest['guest_name'] . ', this is Front Office ' . (defined('BUSINESS_NAME') ? BUSINESS_NAME : '') . ' (Room ' . $guest['room_number'] . ').'; ?>
                                <button type="button" class="ih-btn ih-btn-wa" data-wa-url="https://wa.me/<?php echo $waDigits; ?>?text=<?php echo rawurlencode($waMsg); ?>" data-booking="<?php echo (int)$guest['booking_id']; ?>" data-guest="<?php echo htmlspecialchars((string)$guest['guest_name']); ?>" data-phone="<?php echo htmlspecialchars((string)$guest['phone']); ?>" onclick="ihWaMenu(this, event)" title="WhatsApp <?php echo htmlspecialchars((string)$guest['phone']); ?>" aria-label="WhatsApp tamu" aria-haspopup="menu"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.08-.3-.15-1.26-.46-2.39-1.48-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.07c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.7.63.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35zM12.05 21.79a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88 2.64 0 5.12 1.03 6.99 2.9a9.83 9.83 0 0 1 2.89 6.99c0 5.45-4.44 9.88-9.89 9.88m8.41-18.3A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.68 1.45h.01c6.55 0 11.89-5.34 11.89-11.89 0-3.18-1.24-6.17-3.48-8.42z"/></svg></button>
                            <?php else: ?>
                                <span class="ih-btn ih-btn-wa is-off" title="Nomor WhatsApp tamu belum ada" aria-label="Nomor WhatsApp belum ada"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.08-.3-.15-1.26-.46-2.39-1.48-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.07c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.7.63.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35zM12.05 21.79a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88 2.64 0 5.12 1.03 6.99 2.9a9.83 9.83 0 0 1 2.89 6.99c0 5.45-4.44 9.88-9.89 9.88m8.41-18.3A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.68 1.45h.01c6.55 0 11.89-5.34 11.89-11.89 0-3.18-1.24-6.17-3.48-8.42z"/></svg></span>
                            <?php endif; ?>
                            <button class="ih-btn ih-btn-breakfast" onclick="selectBreakfast(<?php echo (int)$guest['booking_id']; ?>, <?php echo htmlspecialchars(json_encode((string)$guest['guest_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)">
                                Breakfast
                            </button>
                            <button class="ih-btn ih-btn-checkout" onclick="doCheckOutGuest(<?php echo (int)$guest['booking_id']; ?>, <?php echo htmlspecialchars(json_encode((string)$guest['guest_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode((string)$guest['room_number'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)">
                                Check-out
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="ih-empty">
            <div class="ih-empty-icon">🏖️</div>
            <p>Tidak ada tamu in house saat ini</p>
        </div>
    <?php endif; ?>

    <!-- Checkout History -->
    <?php if (count($checkoutHistory) > 0): ?>
        <h2 class="ih-section-title" style="margin-top: 1.5rem;">📋 History Check-out (Hari Ini & Kemarin)</h2>
        <div class="ih-history">
            <?php foreach ($checkoutHistory as $history):
                $coTime = $history['actual_checkout_time'] ? date('d/m H:i', strtotime($history['actual_checkout_time'])) : '-';
            ?>
                <div class="ih-history-item">
                    <div class="ih-history-room"><?php echo $history['room_number']; ?></div>
                    <div class="ih-history-guest"><?php echo htmlspecialchars($history['guest_name']); ?></div>
                    <div class="ih-history-time">CO: <?php echo $coTime; ?></div>
                    <div class="ih-history-price">Rp <?php echo number_format($history['final_price'], 0, ',', '.'); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- CHECKOUT BILLS MODAL -->
<!-- ═══════════════════════════════════════════════════════════ -->
<style>
    .co-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.65);
        z-index: 99999;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .co-modal {
        background: white;
        border-radius: 16px;
        padding: 1.5rem;
        width: 100%;
        max-width: 500px;
        max-height: 88vh;
        overflow-y: auto;
        box-shadow: 0 25px 70px rgba(0, 0, 0, 0.35);
    }

    .co-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.15rem;
        padding-bottom: 0.75rem;
        border-bottom: 2px solid #f1f5f9;
    }

    .co-modal-header h3 {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 800;
        color: #1e293b;
    }

    .co-modal-close {
        background: none;
        border: none;
        font-size: 1.4rem;
        cursor: pointer;
        color: #94a3b8;
        padding: 0;
        line-height: 1;
    }

    .co-modal-close:hover {
        color: #475569;
    }

    .co-bill-section {
        background: #f8fafc;
        border-radius: 10px;
        padding: 0.8rem 0.9rem;
        margin-bottom: 0.75rem;
        border: 1px solid #e2e8f0;
    }

    .co-bill-section.co-bill-alert {
        background: #fff7ed;
        border-color: #fed7aa;
    }

    .co-bill-head {
        font-size: 0.8rem;
        font-weight: 800;
        color: #1e293b;
        margin-bottom: 0.55rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .co-bill-row {
        display: flex;
        justify-content: space-between;
        font-size: 0.82rem;
        margin-bottom: 0.3rem;
        color: #475569;
    }

    .co-bill-row span:last-child {
        font-weight: 600;
        color: #1e293b;
    }

    .co-bill-balance {
        padding-top: 0.4rem;
        margin-top: 0.4rem;
        border-top: 1px solid #e2e8f0;
        font-weight: 700 !important;
    }

    .co-bill-balance.ok span {
        color: #10b981 !important;
    }

    .co-bill-balance.bad span {
        color: #ef4444 !important;
    }

    .co-paid {
        color: #10b981 !important;
    }

    .co-warn {
        color: #f59e0b !important;
    }

    .co-badge-overdue {
        display: inline-block;
        background: #ef4444;
        color: white;
        font-size: 0.65rem;
        font-weight: 700;
        padding: 0.1rem 0.45rem;
        border-radius: 20px;
    }

    .co-modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 0.6rem;
        margin-top: 1.1rem;
        padding-top: 0.85rem;
        border-top: 1px solid #f1f5f9;
    }

    .co-btn {
        padding: 0.55rem 1.25rem;
        border: none;
        border-radius: 9px;
        font-size: 0.88rem;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
    }

    .co-btn-cancel {
        background: #f3f4f6;
        color: #374151;
        border: 1px solid #e5e7eb;
    }

    .co-btn-confirm {
        background: #10b981;
        color: white;
    }

    .co-btn-confirm:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .co-block-note {
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 8px;
        padding: 0.7rem 0.85rem;
        font-size: 0.8rem;
        color: #b91c1c;
        font-weight: 600;
        margin-top: 0.75rem;
        text-align: center;
    }
</style>

<div id="checkoutBillsModal" class="co-modal-overlay" onclick="if(event.target===this)closeCheckoutBillsModal()" style="display:none;align-items:center;justify-content:center">
    <div class="co-modal">
        <div class="co-modal-header">
            <h3>🚪 Ringkasan Tagihan Check-out</h3>
            <button class="co-modal-close" onclick="closeCheckoutBillsModal()">✕</button>
        </div>
        <div id="coBillsBody"></div>
        <div id="coBillsBlockNote" class="co-block-note" style="display:none">
            ⛔ Check-out tidak bisa dilanjutkan. Selesaikan semua tagihan & kembalikan kendaraan terlebih dahulu.
        </div>
        <div class="co-modal-footer">
            <button class="co-btn co-btn-cancel" onclick="closeCheckoutBillsModal()">Batal</button>
            <button class="co-btn co-btn-confirm" id="coBillsConfirmBtn" onclick="confirmCheckout()">✅ Konfirmasi Check-out</button>
        </div>
    </div>
</div>

<!-- Breakfast Orders Modal -->
<div id="breakfastModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: var(--bg-primary); border-radius: 20px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto; padding: 2rem; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="margin: 0; font-size: 1.5rem; background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
                🍳 Breakfast Orders
            </h2>
            <button onclick="closeBreakfastModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">✕</button>
        </div>
        <div id="breakfastContent" style="color: var(--text-primary);">
            <div style="text-align: center; padding: 2rem;">
                <div style="font-size: 3rem; margin-bottom: 1rem;">⏳</div>
                <p>Loading breakfast orders...</p>
            </div>
        </div>
        <div style="margin-top: 1.5rem; display: flex; gap: 1rem;">
            <button onclick="closeBreakfastModal()" style="flex: 1; padding: 0.75rem; border: 1px solid var(--bg-tertiary); background: var(--bg-secondary); color: var(--text-primary); border-radius: 10px; font-weight: 600; cursor: pointer;">
                Tutup
            </button>
            <button onclick="addNewBreakfast()" style="flex: 1; padding: 0.75rem; border: none; background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: white; border-radius: 10px; font-weight: 600; cursor: pointer;">
                + Tambah Order
            </button>
        </div>
    </div>
</div>

<script src="<?php echo BASE_URL; ?>/assets/js/deposit-guard.js?v=20261007"></script>
<script>
    let currentBookingId = null;
    let currentGuestName = null;

    // ── Checkout Bills Modal ────────────────────────────────────────────────────
    let _coBookingId = null,
        _coGuestName = null,
        _coRoomNumber = null,
        _coBtn = null,
        _coBtnOrigHTML = null;

    // Check-out: ingatkan dulu bila masih ada deposit (KTP / uang) yang belum dikembalikan
    function doCheckOutGuest(bookingId, guestName, roomNumber) {
        const btnEl = (window.event && window.event.target) ? window.event.target.closest('.ih-btn-checkout') : null;
        if (!btnEl) return;
        depositGuard('<?php echo BASE_URL; ?>', bookingId, guestName).then(g => {
            if (g.ok) doCheckOutGuestRaw(bookingId, guestName, roomNumber, btnEl);
        });
    }

    function doCheckOutGuestRaw(bookingId, guestName, roomNumber, btnEl) {
        const btn = btnEl;
        if (!btn) return;

        _coBookingId = bookingId;
        _coGuestName = guestName;
        _coRoomNumber = roomNumber;
        _coBtn = btn;
        _coBtnOrigHTML = btn.innerHTML;

        btn.innerHTML = '⏳ Memuat...';
        btn.disabled = true;

        fetch('<?php echo BASE_URL; ?>/api/get-checkout-bills.php?booking_id=' + bookingId, {
                credentials: 'include'
            })
            .then(r => r.json())
            .then(data => {
                btn.innerHTML = _coBtnOrigHTML;
                btn.disabled = false;
                if (!data.success) {
                    alert('❌ ' + (data.message || 'Gagal memuat tagihan'));
                    return;
                }
                showCheckoutBillsModal(data.bills);
            })
            .catch(err => {
                btn.innerHTML = _coBtnOrigHTML;
                btn.disabled = false;
                alert('❌ Network error: ' + err.message);
            });
    }

    function fmtRp(n) {
        return 'Rp ' + Number(n).toLocaleString('id-ID');
    }

    function fmtDt(s) {
        if (!s) return '-';
        const d = new Date(s);
        return d.toLocaleDateString('id-ID', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            }) +
            ' ' + d.toLocaleTimeString('id-ID', {
                hour: '2-digit',
                minute: '2-digit'
            });
    }

    function showCheckoutBillsModal(bills) {
        const modal = document.getElementById('checkoutBillsModal');
        const body = document.getElementById('coBillsBody');
        const confirmBtn = document.getElementById('coBillsConfirmBtn');

        let html = '';
        let hasBlock = false;

        // Room charge
        const r = bills.room;
        const roomOk = r.remaining <= 1000;
        html += `<div class="co-bill-section">
        <div class="co-bill-head">🏨 Tagihan Kamar #${r.room_number}</div>
        <div class="co-bill-row"><span>Total Kamar</span><span>${fmtRp(r.final_price)}</span></div>
        <div class="co-bill-row"><span>Sudah Dibayar</span><span class="co-paid">${fmtRp(r.paid_amount)}</span></div>
        <div class="co-bill-row co-bill-balance ${roomOk ? 'ok' : 'bad'}">
            <span>Sisa Tagihan</span><span>${fmtRp(r.remaining)}</span>
        </div>
    </div>`;
        if (!roomOk) hasBlock = true;

        // Rental motor
        if (bills.motor && bills.motor.length > 0) {
            hasBlock = true;
            html += `<div class="co-bill-section co-bill-alert">
            <div class="co-bill-head">🏍️ Rental Motor — Belum Dikembalikan!</div>`;
            bills.motor.forEach(m => {
                html += `<div class="co-bill-row"><span>${m.label}</span><span class="co-badge-overdue">${m.status.toUpperCase()}</span></div>
            <div class="co-bill-row"><span>Mulai</span><span>${fmtDt(m.start)}</span></div>
            <div class="co-bill-row"><span>Estimasi Tagihan</span><span class="co-warn">${fmtRp(m.est_price)}</span></div>`;
            });
            html += `<div style="font-size:0.75rem;color:#b45309;margin-top:0.4rem">⚠ Kembalikan motor di menu Rental Motor terlebih dahulu</div></div>`;
        }

        // Rental car
        if (bills.car && bills.car.length > 0) {
            hasBlock = true;
            html += `<div class="co-bill-section co-bill-alert">
            <div class="co-bill-head">🚗 Rental Mobil/Taxi — Belum Dikembalikan!</div>`;
            bills.car.forEach(c => {
                html += `<div class="co-bill-row"><span>${c.label}</span><span class="co-badge-overdue">${c.status.toUpperCase()}</span></div>
            <div class="co-bill-row"><span>Mulai</span><span>${fmtDt(c.start)}</span></div>
            <div class="co-bill-row"><span>Estimasi Tagihan</span><span class="co-warn">${fmtRp(c.est_price)}</span></div>`;
            });
            html += `<div style="font-size:0.75rem;color:#b45309;margin-top:0.4rem">⚠ Kembalikan kendaraan di menu Rental Mobil terlebih dahulu</div></div>`;
        }

        // Unpaid hotel invoices
        if (bills.invoices && bills.invoices.length > 0) {
            hasBlock = true;
            html += `<div class="co-bill-section co-bill-alert">
            <div class="co-bill-head">📄 Invoice Hotel Service Belum Lunas</div>`;
            bills.invoices.forEach(inv => {
                html += `<div style="margin:0.6rem 0;border-left:3px solid #f59e0b;padding-left:0.6rem">
                    <div class="co-bill-row" style="margin-bottom:0.3rem"><strong>${inv.invoice_number}</strong><span class="co-warn">${fmtRp(inv.remaining)} sisa</span></div>`;

                // Show invoice items if available
                if (inv.items && inv.items.length > 0) {
                    inv.items.forEach(item => {
                        let icon = '🔹';
                        if (item.service_type.includes('motor')) icon = '🏍️';
                        else if (item.service_type.includes('car')) icon = '🚗';
                        else if (item.service_type === 'laundry') icon = '👕';
                        html += `<div style="font-size:0.75rem;color:#666;margin:0.2rem 0">
                            ${icon} ${item.description || item.service_type}: <strong>${fmtRp(item.total_price)}</strong>
                        </div>`;
                    });
                }
                html += `</div>`;
            });
            html += `</div>`;
        }

        body.innerHTML = html;

        if (hasBlock) {
            confirmBtn.style.display = 'none';
            document.getElementById('coBillsBlockNote').style.display = 'block';
        } else {
            confirmBtn.style.display = 'inline-flex';
            document.getElementById('coBillsBlockNote').style.display = 'none';
        }

        modal.style.display = 'flex';
    }

    function closeCheckoutBillsModal() {
        document.getElementById('checkoutBillsModal').style.display = 'none';
    }

    function confirmCheckout() {
        const confirmBtn = document.getElementById('coBillsConfirmBtn');
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '⏳ Processing...';

        fetch('<?php echo BASE_URL; ?>/api/checkout-guest.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                credentials: 'include',
                body: 'booking_id=' + _coBookingId
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    closeCheckoutBillsModal();
                    alert('✅ ' + data.message);
                    window.location.reload();
                } else {
                    alert('❌ ' + data.message);
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = '✅ Konfirmasi Check-out';
                }
            })
            .catch(err => {
                alert('❌ Network error: ' + err.message);
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '✅ Konfirmasi Check-out';
            });
    }

    // Select Breakfast - Show Modal with Orders
    function selectBreakfast(bookingId, guestName) {
        currentBookingId = bookingId;
        currentGuestName = guestName;

        // Show modal
        document.getElementById('breakfastModal').style.display = 'flex';

        // Fetch breakfast orders
        fetch('<?php echo BASE_URL; ?>/api/get-breakfast-orders.php?booking_id=' + bookingId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayBreakfastOrders(data.orders, guestName);
                } else {
                    document.getElementById('breakfastContent').innerHTML = `
                    <div style="text-align: center; padding: 2rem;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">❌</div>
                        <p>Error: ${data.message}</p>
                    </div>
                `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('breakfastContent').innerHTML = `
                <div style="text-align: center; padding: 2rem;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">⚠️</div>
                    <p>Gagal memuat data breakfast orders</p>
                </div>
            `;
            });
    }

    function displayBreakfastOrders(orders, guestName) {
        const content = document.getElementById('breakfastContent');
        guestName = String(guestName ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        if (orders.length === 0) {
            content.innerHTML = `
            <div style="text-align: center; padding: 2rem;">
                <div style="font-size: 3rem; margin-bottom: 1rem;">🍽️</div>
                <p style="color: var(--text-secondary);">${guestName} belum memiliki breakfast order</p>
            </div>
        `;
            return;
        }

        let html = `<div style="margin-bottom: 1rem;"><strong>${guestName}</strong></div>`;

        orders.forEach(order => {
            const orderDate = new Date(order.breakfast_date + ' ' + order.breakfast_time);
            const formattedDate = orderDate.toLocaleDateString('id-ID', {
                day: 'numeric',
                month: 'short'
            });
            const formattedTime = orderDate.toLocaleTimeString('id-ID', {
                hour: '2-digit',
                minute: '2-digit'
            });

            html += `
            <div style="background: var(--bg-secondary); border-radius: 12px; padding: 1rem; margin-bottom: 1rem; border: 1px solid var(--bg-tertiary);">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.75rem;">
                    <div>
                        <div style="font-weight: 600; color: var(--primary);">${formattedDate} ${formattedTime}</div>
                        <div style="font-size: 0.9rem; color: var(--text-secondary);">Room ${(() => { try { const r = JSON.parse(order.room_number); return Array.isArray(r) ? r.join(', ') : order.room_number; } catch(e) { return order.room_number || '-'; } })()} • ${order.total_pax} pax</div>
                    </div>
                    <span style="padding: 0.25rem 0.75rem; border-radius: 8px; font-size: 0.85rem; font-weight: 600; ${order.location === 'restaurant' ? 'background: rgba(99, 102, 241, 0.2); color: #6366f1;' : 'background: rgba(139, 92, 246, 0.2); color: #8b5cf6;'}">
                        ${order.location === 'restaurant' ? '🍽️ Restaurant' : '🚪 Room'}
                    </span>
                </div>
                <div style="padding-left: 0.5rem;">
                    <strong style="font-size: 0.9rem;">Menu:</strong>
                    <ul style="margin: 0.5rem 0 0 0; padding-left: 1.5rem;">
        `;

            order.menu_items.forEach(item => {
                html += `<li style="margin: 0.25rem 0;"><span style="color: var(--primary); font-weight: 600;">x${item.quantity}</span> ${item.menu_name}</li>`;
            });

            html += `
                    </ul>
                </div>
            </div>
        `;
        });

        content.innerHTML = html;
    }

    function closeBreakfastModal() {
        document.getElementById('breakfastModal').style.display = 'none';
        currentBookingId = null;
        currentGuestName = null;
    }

    function addNewBreakfast() {
        window.location.href = 'breakfast.php?booking_id=' + currentBookingId;
    }

    // Close modal when clicking outside
    document.getElementById('breakfastModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeBreakfastModal();
        }
    });
</script>

<div class="pay-ov" id="payModal" onclick="if (event.target === this) closePayModal()">
    <div class="pay-box" role="dialog" aria-modal="true" aria-labelledby="payTitle">
        <div class="pay-head">
            <span class="rm" id="payRoom">-</span>
            <div>
                <b id="payTitle">-</b>
                <small id="payCode">-</small>
            </div>
            <button type="button" onclick="closePayModal()" aria-label="Tutup">&times;</button>
        </div>
        <div class="pay-body">
            <div class="pay-sum">
                <div><span>Total tagihan</span><b id="payTotal">-</b></div>
                <div><span>Sudah dibayar</span><b id="payPaid">-</b></div>
                <div class="rest"><span>Sisa tagihan</span><b id="payRest">-</b></div>
            </div>
            <span class="pay-lbl">Jumlah bayar</span>
            <input type="text" inputmode="numeric" class="pay-amt" id="payAmount" autocomplete="off">
            <div class="pay-quick">
                <button type="button" onclick="setPayAmount(payState.rest)">Lunasi</button>
                <button type="button" onclick="setPayAmount(Math.round(payState.rest / 2))">50%</button>
            </div>
            <span class="pay-lbl">Metode</span>
            <div class="pay-methods" id="payMethods">
                <button type="button" data-m="cash" class="on">Cash</button>
                <button type="button" data-m="transfer">Transfer</button>
                <button type="button" data-m="qris">QRIS</button>
                <button type="button" data-m="card">Card</button>
            </div>
            <p class="pay-note" id="payGroupNote" style="display:none">Booking grup: pembayaran otomatis dibagi ke kamar lain dalam grup.</p>
            <p class="pay-err" id="payErr"></p>
        </div>
        <div class="pay-foot">
            <button type="button" class="cancel" onclick="closePayModal()">Batal</button>
            <button type="button" class="go" id="payGo" onclick="submitPayment()">Simpan Pembayaran</button>
        </div>
    </div>
</div>

<div id="ihWaMenu" role="menu">
    <div class="wm-head"><b id="ihWaName">Tamu</b><small id="ihWaPhone"></small></div>
    <button type="button" class="wm-item" id="ihWaWelcome" role="menuitem">
        <span class="wm-ic"><svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21v-2a4 4 0 0 1 4-4h4"/><circle cx="9" cy="7" r="4"/><path d="m16 19 2 2 4-4"/></svg></span>
        <span class="wm-txt"><b>Kirim pesan Welcome</b><small>Template yang sama dengan WA otomatis saat check-in</small></span>
    </button>
    <a class="wm-item" id="ihWaChat" href="#" target="_blank" rel="noopener" role="menuitem">
        <span class="wm-ic"><svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span>
        <span class="wm-txt"><b>Kirim pesan biasa</b><small>Buka chat WhatsApp dengan tamu</small></span>
    </a>
</div>
<div id="ihWaToast" role="status" aria-live="polite"></div>
<script>
    // Menu WhatsApp tamu di kartu In-House: kirim welcome (gateway WA sistem) / chat biasa (wa.me)
    (function() {
        const menu = document.getElementById('ihWaMenu');
        const toast = document.getElementById('ihWaToast');
        let current = null;
        let toastTimer = null;
        function showToast(msg, isErr) {
            toast.textContent = msg;
            toast.classList.toggle('err', !!isErr);
            toast.classList.add('show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => toast.classList.remove('show'), 4000);
        }
        function close() { menu.classList.remove('open'); current = null; }
        window.ihWaMenu = function(btn, ev) {
            ev.stopPropagation();
            if (current === btn && menu.classList.contains('open')) { close(); return; }
            current = btn;
            document.getElementById('ihWaName').textContent = btn.dataset.guest || 'Tamu';
            document.getElementById('ihWaPhone').textContent = btn.dataset.phone || '';
            document.getElementById('ihWaChat').href = btn.dataset.waUrl;
            menu.classList.add('open');
            const r = btn.getBoundingClientRect();
            const w = menu.offsetWidth, h = menu.offsetHeight;
            const left = Math.min(window.innerWidth - w - 8, Math.max(8, r.right - w));
            let top = r.bottom + 8;
            if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 8);
            menu.style.left = left + 'px';
            menu.style.top = top + 'px';
        };
        document.getElementById('ihWaChat').addEventListener('click', () => setTimeout(close, 50));
        document.getElementById('ihWaWelcome').addEventListener('click', function() {
            if (!current) return;
            const item = this, btn = current, name = btn.dataset.guest || 'tamu';
            if (!confirm('Kirim pesan Welcome ke ' + name + '?')) return;
            item.disabled = true;
            const fd = new FormData();
            fd.append('booking_id', btn.dataset.booking);
            fetch('<?php echo BASE_URL; ?>/api/wa-guest-welcome.php', { method: 'POST', body: fd, credentials: 'include' })
                .then(r => r.json())
                .then(d => {
                    if (d.fallback && d.wa_url) {
                        window.open(d.wa_url, '_blank', 'noopener');
                        showToast(d.message || 'Pesan dibuka di WhatsApp');
                    } else {
                        showToast(d.message || (d.ok ? 'Terkirim' : 'Gagal mengirim'), !d.ok);
                    }
                })
                .catch(() => showToast('Gagal menghubungi server', true))
                .finally(() => { item.disabled = false; close(); });
        });
        document.addEventListener('click', e => { if (menu.classList.contains('open') && !menu.contains(e.target)) close(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
        window.addEventListener('scroll', close, true);
    })();
</script>
<script>
    // Pembayaran langsung dari kartu In-House (dipakai juga oleh popup tagihan: in-house.php?pay=<booking_id>).
    let payState = null;
    const payFmt = n => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
    const payInput = document.getElementById('payAmount');

    function setPayAmount(v) {
        payInput.value = Math.max(0, Math.round(v)).toLocaleString('id-ID');
    }

    function openPayModal(d) {
        payState = Object.assign({ method: 'cash' }, d);
        document.getElementById('payRoom').textContent = d.room;
        document.getElementById('payTitle').textContent = d.name;
        document.getElementById('payCode').textContent = d.code;
        document.getElementById('payTotal').textContent = payFmt(d.total);
        document.getElementById('payPaid').textContent = payFmt(d.paid);
        document.getElementById('payRest').textContent = payFmt(d.rest);
        document.getElementById('payGroupNote').style.display = d.group ? '' : 'none';
        document.getElementById('payErr').textContent = '';
        document.querySelectorAll('#payMethods button').forEach(b => b.classList.toggle('on', b.dataset.m === 'cash'));
        setPayAmount(d.rest);
        document.getElementById('payModal').classList.add('show');
        setTimeout(() => payInput.select(), 50);
    }

    function closePayModal() {
        document.getElementById('payModal').classList.remove('show');
        payState = null;
    }

    payInput.addEventListener('input', () => {
        const digits = payInput.value.replace(/\D/g, '');
        payInput.value = digits ? parseInt(digits, 10).toLocaleString('id-ID') : '';
    });

    document.getElementById('payMethods').addEventListener('click', e => {
        const btn = e.target.closest('button[data-m]');
        if (!btn || !payState) return;
        payState.method = btn.dataset.m;
        document.querySelectorAll('#payMethods button').forEach(b => b.classList.toggle('on', b === btn));
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && payState) closePayModal();
    });

    function submitPayment() {
        if (!payState) return;
        const amount = parseInt(payInput.value.replace(/\D/g, '') || '0', 10);
        const err = document.getElementById('payErr');
        if (amount <= 0) {
            err.textContent = 'Masukkan jumlah bayar.';
            return;
        }
        if (amount > payState.rest && !confirm('Jumlah melebihi sisa tagihan (' + payFmt(payState.rest) + '). Lanjutkan?')) return;

        const go = document.getElementById('payGo');
        go.disabled = true;
        go.textContent = 'Menyimpan...';
        err.textContent = '';

        const fd = new FormData();
        fd.append('booking_id', payState.id);
        fd.append('amount', amount);
        fd.append('payment_method', payState.method);
        fetch('<?php echo BASE_URL; ?>/api/add-booking-payment.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (!d.success) throw new Error(d.message || 'Gagal menyimpan pembayaran');
                go.textContent = '✓ Tersimpan';
                // Muat ulang tanpa ?pay agar modal tidak terbuka lagi.
                setTimeout(() => { window.location.href = 'in-house.php'; }, 600);
            })
            .catch(e => {
                err.textContent = e.message;
                go.disabled = false;
                go.textContent = 'Simpan Pembayaran';
            });
    }

    // Dari popup tagihan: buka modal pembayaran untuk booking yang diklik.
    (function() {
        const id = new URLSearchParams(location.search).get('pay');
        if (!id) return;
        const card = document.getElementById('ihb-' + parseInt(id, 10));
        if (!card) return;
        card.scrollIntoView({ block: 'center' });
        card.classList.add('ih-focus');
        const btn = card.querySelector('.ih-btn-pay');
        if (btn) btn.click();
    })();
</script>

<?php include '../../includes/footer.php'; ?>