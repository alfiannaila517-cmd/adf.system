<?php

/**
 * Rental Motor Dashboard — Enhanced monitoring with elegant UI
 * Track rented motors, available units, revenue, and status
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();
if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db          = Database::getInstance();
$pdo         = $db->getConnection();
$businessId  = $_SESSION['business_id'] ?? 1;

// Auto-update overdue status
$normalizeStatus = $pdo->prepare("UPDATE rental_motor_bookings SET status='active'
    WHERE business_id=? AND status='overdue'
    AND end_datetime > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$normalizeStatus->execute([$businessId]);

$markOverdue = $pdo->prepare("UPDATE rental_motor_bookings SET status='overdue'
    WHERE business_id=? AND status='active'
    AND end_datetime <= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$markOverdue->execute([$businessId]);

// ── Fetch Statistics ──────────────────────────────────────────────────────────
// Total motors by status
$motorStats = $pdo->prepare("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status='available' THEN 1 ELSE 0 END) as available,
    SUM(CASE WHEN status='rented' THEN 1 ELSE 0 END) as rented,
    SUM(CASE WHEN status='maintenance' THEN 1 ELSE 0 END) as maintenance
    FROM rental_motors WHERE business_id=?");
$motorStats->execute([$businessId]);
$motorData = $motorStats->fetch(PDO::FETCH_ASSOC);

$totalMotors = (int)$motorData['total'];
$availableCount = (int)$motorData['available'];
$rentedCount = (int)$motorData['rented'];
$maintenanceCount = (int)$motorData['maintenance'];
$occupancyRate = $totalMotors > 0 ? round(($rentedCount / $totalMotors) * 100, 1) : 0;

// Active & Overdue Rentals
$activeRentals = $pdo->prepare("SELECT COUNT(*) FROM rental_motor_bookings 
    WHERE business_id=? AND status IN ('active','overdue')");
$activeRentals->execute([$businessId]);
$activeCount = (int)$activeRentals->fetchColumn();

$overdueRentals = $pdo->prepare("SELECT COUNT(*) FROM rental_motor_bookings 
    WHERE business_id=?
    AND status IN ('active','overdue')
    AND end_datetime <= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$overdueRentals->execute([$businessId]);
$overdueCount = (int)$overdueRentals->fetchColumn();

// Monthly Revenue
$currentMonth = date('Y-m');
$revenueStat = $pdo->prepare("SELECT 
    COALESCE(SUM(total_price),0) as revenue,
    COUNT(*) as rentals_count
    FROM rental_motor_bookings 
    WHERE business_id=? AND status IN ('active','returned','overdue')
    AND DATE_FORMAT(created_at,'%Y-%m')=?");
$revenueStat->execute([$businessId, $currentMonth]);
$revData = $revenueStat->fetch(PDO::FETCH_ASSOC);

// Currently Rented Motors (with guest info)
$rented = $pdo->prepare("SELECT rb.*, rm.plate_number, rm.motor_name, rm.color, rm.daily_rate
    FROM rental_motor_bookings rb
    JOIN rental_motors rm ON rb.motor_id = rm.id
    WHERE rb.business_id=? AND rb.status IN ('active','overdue')
    ORDER BY rb.status DESC, rb.end_datetime ASC");
$rented->execute([$businessId]);
$rentedList = $rented->fetchAll(PDO::FETCH_ASSOC);

// Ready/Available Motors
$available = $pdo->prepare("SELECT * FROM rental_motors 
    WHERE business_id=? AND status='available'
    ORDER BY motor_name ASC");
$available->execute([$businessId]);
$availableList = $available->fetchAll(PDO::FETCH_ASSOC);

// All motors + current renter info (for the color-coded container grid)
$allMotorsStmt = $pdo->prepare("SELECT
        rm.id AS motor_id, rm.plate_number, rm.motor_name, rm.color AS motor_color, rm.daily_rate, rm.status AS motor_status,
        rm.partner_owner, rm.owner_phone, rm.owner_commission_pct,
        rb.id AS booking_id, rb.guest_name, rb.room_number, rb.start_datetime, rb.end_datetime,
        rb.total_price, rb.status AS booking_status
    FROM rental_motors rm
    LEFT JOIN rental_motor_bookings rb ON rb.motor_id = rm.id AND rb.status IN ('active','overdue')
    WHERE rm.business_id=?
    ORDER BY rm.motor_name ASC");
$allMotorsStmt->execute([$businessId]);
$allMotorsList = $allMotorsStmt->fetchAll(PDO::FETCH_ASSOC);

// Split into hotel-owned and partner-owned
$hotelMotors  = array_filter($allMotorsList, fn($m) => empty($m['partner_owner']));
$mitraMotors  = array_filter($allMotorsList, fn($m) => !empty($m['partner_owner']));

// Count active partner motors
$mitraActiveCount = count(array_filter($mitraMotors, fn($m) => $m['booking_id']));
$hotelActiveCount = count(array_filter($hotelMotors, fn($m) => $m['booking_id']));

// Recent Returned Motors
$recent = $pdo->prepare("SELECT rb.*, rm.plate_number, rm.motor_name
    FROM rental_motor_bookings rb
    JOIN rental_motors rm ON rb.motor_id = rm.id
    WHERE rb.business_id=? AND rb.status='returned'
    ORDER BY rb.actual_return DESC LIMIT 10");
$recent->execute([$businessId]);
$recentReturns = $recent->fetchAll(PDO::FETCH_ASSOC);

// ── Tampilan ─────────────────────────────────────────────────────────────────
$bulan = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
$hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
$rmDt = function ($v) use ($bulan) {
    $t = strtotime((string)$v);
    return $t ? date('j', $t) . ' ' . $bulan[(int)date('n', $t)] . ' ' . date('H:i', $t) : '-';
};
$rmRp = fn($n) => 'Rp ' . number_format((float)$n, 0, ',', '.');
// Sisa waktu / keterlambatan dari tanggal kembali
$rmLeft = function ($end) {
    $t = strtotime((string)$end);
    if (!$t) return ['', false];
    $diff = $t - time();
    $abs = abs($diff);
    $d = intdiv($abs, 86400);
    $h = intdiv($abs % 86400, 3600);
    $m = intdiv($abs % 3600, 60);
    $txt = ($d ? $d . ' hari ' : '') . ($h ? $h . ' jam' : ($d ? '' : $m . ' menit'));
    return [$diff >= 0 ? 'sisa ' . trim($txt) : 'lewat ' . trim($txt), $diff < 0];
};
$motorIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="5.5" cy="17" r="3"/><circle cx="18.5" cy="17" r="3"/><path d="M8.5 17h5l3-6h-4l-2-3H7"/><path d="M15 6h2.5l1.5 5"/></svg>';

include '../../includes/header.php';
?>
<style>
    /* Rental Motor — gaya seragam dengan Dashboard Front Desk. #rmd + !important melawan warna paksa tema terang. */
    #rmd {
        --ink: #0f172a; --mute: #64748b; --faint: #94a3b8; --line: #e8edf3; --soft: #f8fafc; --card: #ffffff;
        --brand: #1e3a8a; --accent: #2563eb; --ok: #16a34a; --ok-bg: #dcfce7; --warn: #b45309; --warn-bg: #fef3c7;
        --bad: #dc2626; --bad-bg: #fee2e2; --vio: #6d28d9; --vio-bg: #f5f3ff; --gray-bg: #f1f5f9;
        --shadow: 0 1px 2px rgba(15,23,42,.04), 0 6px 18px -12px rgba(15,23,42,.16);
        max-width: 1600px; margin: 0 auto; padding: 1rem 1rem 1.5rem; color: var(--ink); font-size: 0.8rem;
    }
    body[data-theme="dark"] #rmd {
        --ink: #f1f5f9; --mute: #94a3b8; --faint: #64748b; --line: rgba(148,163,184,.16); --soft: rgba(255,255,255,.03); --card: rgba(30,41,59,.72);
        --brand: #93c5fd; --accent: #60a5fa; --ok: #4ade80; --ok-bg: rgba(34,197,94,.14); --warn: #fbbf24; --warn-bg: rgba(245,158,11,.14);
        --bad: #f87171; --bad-bg: rgba(239,68,68,.14); --vio: #c4b5fd; --vio-bg: rgba(139,92,246,.14); --gray-bg: rgba(148,163,184,.12);
        --shadow: 0 12px 28px -16px rgba(0,0,0,.7);
    }
    #rmd :is(span, div, td, th, p, li, a, b, small, strong, em, label, h1, h2, h3) { color: inherit !important; -webkit-text-fill-color: currentColor; }
    #rmd *, #rmd *::before, #rmd *::after { box-sizing: border-box; }
    #rmd a { text-decoration: none; }

    #rmd .r-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
    #rmd .r-eyebrow { font-size: 0.64rem !important; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: var(--accent) !important; }
    #rmd h1.r-title { margin: 1px 0; font-size: 1.05rem !important; font-weight: 800 !important; color: var(--ink) !important; }
    #rmd .r-sub { font-size: 0.7rem !important; color: var(--mute) !important; }
    #rmd .r-actions { display: flex; gap: 6px; flex-wrap: wrap; }
    #rmd .r-btn { display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 13px; border-radius: 9px; font-size: 0.72rem !important; font-weight: 700; border: 1px solid var(--line); background: var(--card); color: var(--ink) !important; cursor: pointer; transition: border-color .15s, transform .15s; font-family: inherit; }
    #rmd .r-btn:hover { border-color: var(--accent); transform: translateY(-1px); }
    #rmd .r-btn.primary { background: var(--brand); border-color: var(--brand); color: #fff !important; }
    body[data-theme="dark"] #rmd .r-btn.primary { background: #2563eb; border-color: #2563eb; }
    #rmd .r-btn svg { width: 14px; height: 14px; }

    #rmd .r-card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; box-shadow: var(--shadow); margin-bottom: 12px; }
    #rmd .r-card-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 12px 14px 10px; flex-wrap: wrap; }
    #rmd .r-card-title { display: flex; align-items: center; gap: 9px; }
    #rmd .r-card-title b { display: block; font-size: 0.84rem !important; font-weight: 800 !important; color: var(--ink) !important; }
    #rmd .r-card-title small { display: block; font-size: 0.66rem !important; color: var(--mute) !important; margin-top: 1px; }
    #rmd .r-ic { width: 30px; height: 30px; border-radius: 9px; display: grid; place-items: center; flex-shrink: 0; }
    #rmd .r-ic svg { width: 16px; height: 16px; }
    #rmd .ic-blue { background: #dbeafe; color: #1d4ed8 !important; }
    #rmd .ic-green { background: var(--ok-bg); color: var(--ok) !important; }
    #rmd .ic-amber { background: var(--warn-bg); color: var(--warn) !important; }
    #rmd .ic-red { background: var(--bad-bg); color: var(--bad) !important; }
    #rmd .ic-violet { background: var(--vio-bg); color: var(--vio) !important; }
    body[data-theme="dark"] #rmd .ic-blue { background: rgba(59,130,246,.16); color: #93c5fd !important; }

    #rmd .r-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 12px; }
    #rmd .r-kpi { padding: 12px 14px; display: flex; flex-direction: column; gap: 7px; min-width: 0; margin: 0; }
    #rmd .r-kpi-top { display: flex; align-items: center; justify-content: space-between; gap: 6px; }
    #rmd .r-kpi-label { font-size: 0.64rem !important; font-weight: 800; color: var(--mute) !important; text-transform: uppercase; letter-spacing: .07em; }
    #rmd .r-kpi-val { font-size: 1.3rem !important; font-weight: 800 !important; line-height: 1; color: var(--ink) !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    #rmd .r-kpi-val small { font-size: 0.72rem !important; font-weight: 700; color: var(--faint) !important; }
    #rmd .r-kpi-val.bad { color: var(--bad) !important; }
    #rmd .r-kpi-sub { font-size: 0.68rem !important; color: var(--mute) !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    #rmd .r-kpi-sub b { color: var(--ink) !important; }
    #rmd .r-bar { height: 4px; border-radius: 99px; background: var(--line); overflow: hidden; }
    #rmd .r-bar i { display: block; height: 100%; border-radius: 99px; background: #22c55e; }
    #rmd .r-bar.amber i { background: #f59e0b; }
    #rmd .r-bar.red i { background: #ef4444; }
    #rmd .r-bar.violet i { background: #8b5cf6; }

    #rmd .r-chip { display: inline-flex; align-items: center; gap: 4px; padding: 2px 9px; border-radius: 999px; font-size: 0.66rem !important; font-weight: 700; white-space: nowrap; border: 1px solid transparent; }
    #rmd .r-chip.ok { background: var(--ok-bg); color: var(--ok) !important; }
    #rmd .r-chip.warn { background: var(--warn-bg); color: var(--warn) !important; }
    #rmd .r-chip.bad { background: var(--bad-bg); color: var(--bad) !important; }
    #rmd .r-chip.gray { background: var(--gray-bg); color: var(--mute) !important; }
    #rmd .r-chip.vio { background: var(--vio-bg); color: var(--vio) !important; }
    #rmd .r-chip i { width: 6px; height: 6px; border-radius: 50%; background: currentColor; display: inline-block; }
    #rmd .r-legend { display: flex; gap: 6px; flex-wrap: wrap; }

    /* Sedang disewa */
    #rmd .r-rent-list { list-style: none; margin: 0; padding: 0 8px 8px; }
    #rmd .r-rent { display: grid; grid-template-columns: 40px minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) 210px; align-items: center; gap: 12px; padding: 10px 8px; border-top: 1px solid var(--line); }
    #rmd .r-rent:first-child { border-top: 0; }
    #rmd .r-rent:hover { background: var(--soft); border-radius: 10px; }
    #rmd .r-rent.late { background: linear-gradient(90deg, rgba(239,68,68,.06), transparent 60%); border-radius: 10px; }
    #rmd .r-moto { width: 40px; height: 40px; border-radius: 11px; display: grid; place-items: center; background: var(--warn-bg); color: var(--warn) !important; }
    #rmd .r-rent.late .r-moto { background: var(--bad-bg); color: var(--bad) !important; }
    #rmd .r-moto svg { width: 22px; height: 22px; }
    #rmd .r-plate { font-family: ui-monospace, 'SFMono-Regular', Consolas, monospace; font-size: 0.8rem !important; font-weight: 800; letter-spacing: .02em; color: var(--ink) !important; }
    #rmd .r-mute { font-size: 0.68rem !important; color: var(--mute) !important; margin-top: 2px; }
    #rmd .r-strong { font-size: 0.8rem !important; font-weight: 700; color: var(--ink) !important; }
    #rmd .r-room { display: inline-flex; padding: 1px 7px; border-radius: 5px; background: var(--brand); color: #fff !important; font-size: 0.64rem !important; font-weight: 800; margin-left: 4px; vertical-align: 1px; }
    body[data-theme="dark"] #rmd .r-room { background: #1d4ed8; }
    #rmd .r-right { text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
    #rmd .r-amt { font-size: 0.82rem !important; font-weight: 800; color: var(--ink) !important; white-space: nowrap; }

    /* Armada */
    #rmd .r-group { padding: 0 14px 14px; }
    #rmd .r-group + .r-group { border-top: 1px dashed var(--line); padding-top: 12px; }
    #rmd .r-group-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; font-size: 0.74rem !important; font-weight: 800; color: var(--ink) !important; }
    #rmd .r-group-head small { font-weight: 600; font-size: 0.68rem !important; color: var(--mute) !important; }
    #rmd .r-fleet { display: grid; grid-template-columns: repeat(auto-fill, minmax(168px, 1fr)); gap: 10px; }
    #rmd .r-tile { position: relative; display: flex; flex-direction: column; gap: 6px; padding: 12px 12px 11px 15px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); cursor: pointer; text-align: left; transition: transform .15s, box-shadow .15s, border-color .15s; overflow: hidden; font-family: inherit; color: var(--ink); }
    #rmd .r-tile::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--tc, #22c55e); }
    #rmd .r-tile:hover { transform: translateY(-2px); box-shadow: 0 10px 22px -14px rgba(15,23,42,.35); border-color: var(--tc, #22c55e); }
    #rmd .r-tile.rented { --tc: #f59e0b; }
    #rmd .r-tile.late { --tc: #ef4444; animation: rmdPulse 1.8s ease-in-out infinite; }
    #rmd .r-tile.maintenance { --tc: #94a3b8; }
    @keyframes rmdPulse { 50% { box-shadow: 0 0 0 4px rgba(239,68,68,.12); } }
    #rmd .r-tile-top { display: flex; align-items: center; justify-content: space-between; gap: 6px; }
    #rmd .r-tile-ic { width: 30px; height: 30px; border-radius: 9px; display: grid; place-items: center; background: var(--ok-bg); color: var(--ok) !important; }
    #rmd .r-tile.rented .r-tile-ic { background: var(--warn-bg); color: var(--warn) !important; }
    #rmd .r-tile.late .r-tile-ic { background: var(--bad-bg); color: var(--bad) !important; }
    #rmd .r-tile.maintenance .r-tile-ic { background: var(--gray-bg); color: var(--mute) !important; }
    #rmd .r-tile-ic svg { width: 18px; height: 18px; }
    #rmd .r-tile-name { font-size: 0.7rem !important; font-weight: 600; color: var(--mute) !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    #rmd .r-tile-foot { font-size: 0.66rem !important; color: var(--mute) !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding-top: 6px; border-top: 1px solid var(--line); }
    #rmd .r-tile-foot b { color: var(--ink) !important; font-weight: 700; }

    /* Tabel */
    #rmd .r-tbl-wrap { overflow-x: auto; padding: 0 4px 6px; }
    #rmd table.r-tbl { width: 100%; border-collapse: collapse; }
    #rmd .r-tbl th { padding: 8px 12px; text-align: left; font-size: 0.6rem !important; font-weight: 800 !important; letter-spacing: .07em; text-transform: uppercase; color: var(--faint) !important; border-bottom: 1px solid var(--line); white-space: nowrap; background: transparent !important; }
    #rmd .r-tbl td { padding: 9px 12px; border-bottom: 1px solid var(--line); vertical-align: middle; font-size: 0.76rem !important; color: var(--ink) !important; }
    #rmd .r-tbl tbody tr:last-child td { border-bottom: 0; }
    #rmd .r-tbl tbody tr:hover td { background: var(--soft); }
    #rmd .r-tbl .r { text-align: right; white-space: nowrap; }

    #rmd .r-empty { padding: 26px 14px 30px; text-align: center; color: var(--mute) !important; font-size: 0.76rem !important; }
    #rmd .r-empty svg { width: 30px; height: 30px; color: var(--faint) !important; margin-bottom: 6px; }

    /* Modal detail motor */
    .rmd-ov { display: none; position: fixed; inset: 0; z-index: 1050; background: rgba(15,23,42,.45); backdrop-filter: blur(3px); align-items: center; justify-content: center; padding: 16px; }
    .rmd-ov.open { display: flex; }
    #rmdModal { width: 100%; max-width: 400px; background: #fff; border-radius: 18px; box-shadow: 0 30px 60px -20px rgba(15,23,42,.45); overflow: hidden; animation: rmdIn .22s cubic-bezier(.2,.8,.2,1); color: #0f172a; }
    @keyframes rmdIn { from { opacity: 0; transform: translateY(10px) scale(.98); } to { opacity: 1; transform: none; } }
    #rmdModal :is(span, div, b, small, a, button) { color: inherit !important; }
    #rmdModal .m-head { display: flex; align-items: center; gap: 12px; padding: 16px 18px 14px; border-bottom: 1px solid #f1f5f9; }
    #rmdModal .m-ic { width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; background: #dcfce7; color: #16a34a !important; flex-shrink: 0; }
    #rmdModal .m-ic.rented { background: #fef3c7; color: #b45309 !important; }
    #rmdModal .m-ic.late { background: #fee2e2; color: #dc2626 !important; }
    #rmdModal .m-ic.maintenance { background: #f1f5f9; color: #64748b !important; }
    #rmdModal .m-ic svg { width: 22px; height: 22px; }
    #rmdModal .m-ttl { flex: 1; min-width: 0; }
    #rmdModal .m-ttl b { display: block; font-family: ui-monospace, Consolas, monospace; font-size: 1rem; font-weight: 800; color: #0f172a !important; }
    #rmdModal .m-ttl small { display: block; font-size: 0.74rem; color: #64748b !important; margin-top: 1px; }
    #rmdModal .m-x { width: 30px; height: 30px; border-radius: 9px; border: 0; background: #f1f5f9; color: #64748b !important; cursor: pointer; font-size: 1rem; line-height: 1; }
    #rmdModal .m-x:hover { background: #e2e8f0; }
    #rmdModal .m-body { padding: 10px 18px 4px; }
    #rmdModal .m-row { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px dashed #eef2f7; font-size: 0.8rem; }
    #rmdModal .m-row:last-child { border-bottom: 0; }
    #rmdModal .m-row span:first-child { color: #64748b !important; }
    #rmdModal .m-row span:last-child { font-weight: 700; color: #0f172a !important; text-align: right; }
    #rmdModal .m-st { display: inline-flex; align-items: center; gap: 5px; padding: 2px 9px; border-radius: 999px; font-size: 0.7rem; font-weight: 800; }
    #rmdModal .m-st.ok { background: #dcfce7; color: #15803d !important; }
    #rmdModal .m-st.warn { background: #fef3c7; color: #b45309 !important; }
    #rmdModal .m-st.bad { background: #fee2e2; color: #b91c1c !important; }
    #rmdModal .m-st.gray { background: #f1f5f9; color: #475569 !important; }
    #rmdModal .m-foot { display: flex; gap: 8px; padding: 12px 18px 16px; }
    #rmdModal .m-btn { flex: 1; display: inline-flex; align-items: center; justify-content: center; height: 36px; border-radius: 10px; font-size: 0.78rem; font-weight: 700; border: 1px solid #e2e8f0; background: #fff; color: #0f172a !important; text-decoration: none; }
    #rmdModal .m-btn.primary { background: #1e3a8a; border-color: #1e3a8a; color: #fff !important; }
    #rmdModal .m-btn:hover { filter: brightness(.97); }
    body[data-theme="dark"] #rmdModal { background: #1e293b; color: #f1f5f9; }
    body[data-theme="dark"] #rmdModal .m-head { border-color: rgba(148,163,184,.14); }
    body[data-theme="dark"] #rmdModal .m-ttl b, body[data-theme="dark"] #rmdModal .m-row span:last-child { color: #f1f5f9 !important; }
    body[data-theme="dark"] #rmdModal .m-row { border-color: rgba(148,163,184,.14); }
    body[data-theme="dark"] #rmdModal .m-x, body[data-theme="dark"] #rmdModal .m-btn:not(.primary) { background: rgba(255,255,255,.06); border-color: rgba(148,163,184,.2); color: #e2e8f0 !important; }

    /* ── Ringkas: semua elemen dikecilkan & diseragamkan ── */
    #rmd { padding: .6rem .75rem 1rem; font-size: .76rem; }
    #rmd .r-head { margin-bottom: 8px; }
    #rmd .r-eyebrow { font-size: .56rem !important; }
    #rmd h1.r-title { font-size: .95rem !important; }
    #rmd .r-sub { font-size: .64rem !important; }
    #rmd .r-btn { height: 28px; padding: 0 11px; border-radius: 8px; font-size: .68rem !important; }
    #rmd .r-card { border-radius: 12px; margin-bottom: 8px; }
    #rmd .r-card-head { padding: 8px 11px 6px; }
    #rmd .r-card-title b { font-size: .76rem !important; }
    #rmd .r-card-title small { font-size: .6rem !important; }
    #rmd .r-ic { width: 24px; height: 24px; border-radius: 7px; }
    #rmd .r-ic svg { width: 13px; height: 13px; }
    #rmd .r-kpis { gap: 8px; margin-bottom: 8px; }
    #rmd .r-kpi { padding: 8px 11px; gap: 4px; }
    #rmd .r-kpi-label { font-size: .56rem !important; }
    #rmd .r-kpi-val { font-size: 1.05rem !important; }
    #rmd .r-kpi-val small { font-size: .62rem !important; }
    #rmd .r-kpi-sub { font-size: .6rem !important; }
    #rmd .r-bar { height: 3px; }
    #rmd .r-chip { padding: 1px 8px; font-size: .6rem !important; }
    #rmd .r-rent { padding: 6px 6px; gap: 10px; grid-template-columns: 32px minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) 190px; }
    #rmd .r-moto { width: 32px; height: 32px; border-radius: 9px; }
    #rmd .r-moto svg { width: 18px; height: 18px; }
    #rmd .r-plate { font-size: .74rem !important; }
    #rmd .r-strong { font-size: .74rem !important; }
    #rmd .r-mute { font-size: .62rem !important; margin-top: 1px; }
    #rmd .r-amt { font-size: .76rem !important; }
    #rmd .r-group { padding: 0 11px 10px; }
    #rmd .r-group + .r-group { padding-top: 8px; }
    #rmd .r-group-head { margin-bottom: 7px; font-size: .68rem !important; }
    #rmd .r-fleet { grid-template-columns: repeat(auto-fill, minmax(138px, 1fr)); gap: 7px; }
    #rmd .r-tile { padding: 8px 9px 7px 12px; gap: 4px; border-radius: 10px; }
    #rmd .r-tile-ic { width: 24px; height: 24px; border-radius: 7px; }
    #rmd .r-tile-ic svg { width: 14px; height: 14px; }
    #rmd .r-tile-name { font-size: .64rem !important; }
    #rmd .r-tile-foot { font-size: .6rem !important; padding-top: 4px; }
    #rmd .r-tbl th { padding: 6px 10px; font-size: .56rem !important; }
    #rmd .r-tbl td { padding: 6px 10px; font-size: .7rem !important; }
    #rmd .r-empty { padding: 16px 12px 18px; font-size: .7rem !important; }
    #rmd .r-empty svg { width: 24px; height: 24px; }
    @media (max-width: 1100px) {
        #rmd .r-rent { grid-template-columns: 40px minmax(0, 1fr) minmax(0, 1fr) 190px; }
        #rmd .r-rent .r-when { display: none; }
    }
    @media (max-width: 860px) {
        #rmd .r-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 560px) {
        #rmd { padding: .75rem .65rem 1.25rem; }
        #rmd .r-rent { grid-template-columns: 36px minmax(0, 1fr); }
        #rmd .r-rent .r-guest-col { grid-column: 2; }
        #rmd .r-rent .r-right { grid-column: 2; flex-direction: row; align-items: center; justify-content: flex-start; }
        #rmd .r-fleet { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
</style>

<div id="rmd">
    <div class="r-head">
        <div>
            <div class="r-eyebrow">Hotel Services</div>
            <h1 class="r-title">Monitoring Rental</h1>
            <div class="r-sub"><?php echo $hari[(int)date('w')] . ', ' . date('j') . ' ' . $bulan[(int)date('n')] . ' ' . date('Y'); ?> · status armada motor &amp; penyewaan berjalan</div>
        </div>
        <div class="r-actions">
            <a class="r-btn primary" href="hotel-services.php?new=motor">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                Sewa Motor
            </a>
            <a class="r-btn" href="rental-motor.php?view=manage&amp;tab=fleet">Kelola Armada</a>
            <a class="r-btn" href="rental-motor.php?view=manage&amp;tab=history">Riwayat</a>
        </div>
    </div>

    <!-- Ringkasan -->
    <div class="r-kpis">
        <div class="r-card r-kpi">
            <div class="r-kpi-top"><span class="r-kpi-label">Siap Disewa</span><span class="r-ic ic-green"><?php echo $motorIcon; ?></span></div>
            <div class="r-kpi-val"><?php echo $availableCount; ?><small> / <?php echo $totalMotors; ?> motor</small></div>
            <div class="r-bar"><i style="width:<?php echo $totalMotors ? round($availableCount / $totalMotors * 100) : 0; ?>%"></i></div>
            <div class="r-kpi-sub"><?php echo $maintenanceCount ? '<b>' . $maintenanceCount . '</b> dalam perbaikan' : 'Tidak ada yang dalam perbaikan'; ?></div>
        </div>
        <div class="r-card r-kpi">
            <div class="r-kpi-top"><span class="r-kpi-label">Sedang Disewa</span><span class="r-ic ic-amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span></div>
            <div class="r-kpi-val"><?php echo $activeCount; ?><small> rental aktif</small></div>
            <div class="r-bar amber"><i style="width:<?php echo min(100, (float)$occupancyRate); ?>%"></i></div>
            <div class="r-kpi-sub">Okupansi armada <b><?php echo $occupancyRate; ?>%</b></div>
        </div>
        <div class="r-card r-kpi">
            <div class="r-kpi-top"><span class="r-kpi-label">Terlambat</span><span class="r-ic <?php echo $overdueCount ? 'ic-red' : 'ic-blue'; ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg></span></div>
            <div class="r-kpi-val<?php echo $overdueCount ? ' bad' : ''; ?>"><?php echo $overdueCount; ?><small> motor</small></div>
            <div class="r-bar red"><i style="width:<?php echo $activeCount ? round($overdueCount / $activeCount * 100) : 0; ?>%"></i></div>
            <div class="r-kpi-sub"><?php echo $overdueCount ? 'Lewat lebih dari 24 jam dari jadwal kembali' : 'Semua tepat waktu'; ?></div>
        </div>
        <div class="r-card r-kpi">
            <div class="r-kpi-top"><span class="r-kpi-label">Pendapatan Bulan Ini</span><span class="r-ic ic-violet"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="13" rx="2"/><circle cx="12" cy="12.5" r="2.5"/><path d="M6 10v5M18 10v5"/></svg></span></div>
            <div class="r-kpi-val"><?php echo $rmRp($revData['revenue']); ?></div>
            <div class="r-bar violet"><i style="width:<?php echo $revData['rentals_count'] ? 100 : 0; ?>%"></i></div>
            <div class="r-kpi-sub"><b><?php echo (int)$revData['rentals_count']; ?></b> transaksi · <?php echo $bulan[(int)date('n')] . ' ' . date('Y'); ?></div>
        </div>
    </div>

    <!-- Sedang disewa -->
    <div class="r-card">
        <div class="r-card-head">
            <div class="r-card-title">
                <span class="r-ic ic-amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
                <div><b>Sedang Disewa</b><small>Urut dari yang paling dekat jadwal kembalinya</small></div>
            </div>
            <div class="r-legend">
                <span class="r-chip warn"><?php echo count($rentedList); ?> aktif</span>
                <?php if ($overdueCount): ?><span class="r-chip bad"><?php echo $overdueCount; ?> terlambat</span><?php endif; ?>
            </div>
        </div>
        <?php if (!empty($rentedList)): ?>
            <ul class="r-rent-list">
                <?php foreach ($rentedList as $r):
                    [$leftTxt, $isPast] = $rmLeft($r['end_datetime']);
                    $late = $r['status'] === 'overdue';
                ?>
                    <li class="r-rent<?php echo $late ? ' late' : ''; ?>">
                        <span class="r-moto"><?php echo $motorIcon; ?></span>
                        <div>
                            <div class="r-plate"><?php echo htmlspecialchars($r['plate_number']); ?></div>
                            <div class="r-mute"><?php echo htmlspecialchars($r['motor_name']); ?><?php echo !empty($r['motor_count']) && (int)$r['motor_count'] > 1 ? ' · ' . (int)$r['motor_count'] . ' unit' : ''; ?></div>
                        </div>
                        <div class="r-guest-col">
                            <div class="r-strong"><?php echo htmlspecialchars($r['guest_name'] ?: '-'); ?><?php if (!empty($r['room_number'])): ?><span class="r-room"><?php echo htmlspecialchars($r['room_number']); ?></span><?php endif; ?></div>
                            <div class="r-mute"><?php echo htmlspecialchars($r['guest_phone'] ?? '') ?: '&nbsp;'; ?></div>
                        </div>
                        <div class="r-when">
                            <div class="r-strong" style="font-weight:600"><?php echo $rmDt($r['start_datetime']); ?> → <?php echo $rmDt($r['end_datetime']); ?></div>
                            <div class="r-mute">Jadwal sewa</div>
                        </div>
                        <div class="r-right">
                            <span class="r-chip <?php echo $late ? 'bad' : ($isPast ? 'warn' : 'ok'); ?>"><i></i><?php echo $late ? 'Terlambat · ' . $leftTxt : ucfirst($leftTxt); ?></span>
                            <span class="r-amt"><?php echo (float)$r['total_price'] > 0 ? $rmRp($r['total_price']) : '<span style="font-weight:600;font-size:.7rem">dihitung saat kembali</span>'; ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="r-empty">
                <?php echo $motorIcon; ?>
                <div>Tidak ada motor yang sedang disewa.</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Armada -->
    <?php
    $renderTiles = function (array $list) use ($motorIcon, $rmDt) {
        echo '<div class="r-fleet">';
        foreach ($list as $m) {
            $state = $m['motor_status'];
            $isRented = $state === 'rented' && $m['booking_id'];
            $isLate = $isRented && $m['booking_status'] === 'overdue';
            $cls = $isRented ? 'rented' . ($isLate ? ' late' : '') : ($state === 'maintenance' ? 'maintenance' : 'available');
            $detail = [
                'id' => (int)$m['motor_id'], 'plate' => $m['plate_number'], 'name' => $m['motor_name'], 'color' => $m['motor_color'],
                'rate' => (float)$m['daily_rate'], 'status' => $state, 'late' => $isLate ? 1 : 0,
                'guest' => $m['guest_name'], 'room' => $m['room_number'], 'start' => $m['start_datetime'], 'end' => $m['end_datetime'],
                'total' => (float)$m['total_price'], 'partner' => $m['partner_owner'] ?? '', 'partner_phone' => $m['owner_phone'] ?? '',
            ];
            $chip = $isRented ? ($isLate ? '<span class="r-chip bad">Terlambat</span>' : '<span class="r-chip warn">Disewa</span>')
                : ($state === 'maintenance' ? '<span class="r-chip gray">Perbaikan</span>' : '<span class="r-chip ok">Tersedia</span>');
            echo '<button type="button" class="r-tile ' . $cls . '" onclick="rmdShow(this)" data-motor="' . htmlspecialchars(json_encode($detail), ENT_QUOTES) . '">';
            echo '<span class="r-tile-top"><span class="r-tile-ic">' . $motorIcon . '</span>' . $chip . '</span>';
            echo '<span class="r-plate">' . htmlspecialchars($m['plate_number']) . '</span>';
            echo '<span class="r-tile-name">' . htmlspecialchars($m['motor_name']) . ($m['motor_color'] ? ' · ' . htmlspecialchars($m['motor_color']) : '') . '</span>';
            if ($isRented) {
                echo '<span class="r-tile-foot"><b>' . htmlspecialchars(mb_strimwidth((string)$m['guest_name'], 0, 18, '…')) . '</b> · kembali ' . htmlspecialchars($rmDt($m['end_datetime'])) . '</span>';
            } else {
                echo '<span class="r-tile-foot">Rp ' . number_format((float)$m['daily_rate'], 0, ',', '.') . ' / hari</span>';
            }
            echo '</button>';
        }
        echo '</div>';
    };
    ?>
    <div class="r-card">
        <div class="r-card-head">
            <div class="r-card-title">
                <span class="r-ic ic-blue"><?php echo $motorIcon; ?></span>
                <div><b>Armada Motor</b><small>Klik motor untuk melihat detail atau menyewakan</small></div>
            </div>
            <div class="r-legend">
                <span class="r-chip ok"><i></i>Tersedia <?php echo $availableCount; ?></span>
                <span class="r-chip warn"><i></i>Disewa <?php echo $rentedCount; ?></span>
                <span class="r-chip gray"><i></i>Perbaikan <?php echo $maintenanceCount; ?></span>
            </div>
        </div>
        <?php if (empty($allMotorsList)): ?>
            <div class="r-empty">
                <?php echo $motorIcon; ?>
                <div>Belum ada motor. Tambahkan lewat <a href="rental-motor.php?view=manage&amp;tab=fleet" style="color:var(--accent)!important;font-weight:700">Kelola Armada</a>.</div>
            </div>
        <?php else: ?>
            <?php if (!empty($hotelMotors)): ?>
                <div class="r-group">
                    <div class="r-group-head">Motor Hotel <small><?php echo $hotelActiveCount; ?> dari <?php echo count($hotelMotors); ?> sedang disewa</small></div>
                    <?php $renderTiles(array_values($hotelMotors)); ?>
                </div>
            <?php endif; ?>
            <?php
            $grouped = [];
            foreach ($mitraMotors as $m) {
                $key = $m['partner_owner'];
                if (!isset($grouped[$key])) $grouped[$key] = ['owner' => $m['partner_owner'], 'phone' => $m['owner_phone'], 'pct' => $m['owner_commission_pct'], 'motors' => []];
                $grouped[$key]['motors'][] = $m;
            }
            foreach ($grouped as $g): ?>
                <div class="r-group">
                    <div class="r-group-head">
                        Mitra · <?php echo htmlspecialchars($g['owner']); ?>
                        <?php if ($g['phone']): ?><small><?php echo htmlspecialchars($g['phone']); ?></small><?php endif; ?>
                        <?php if ((float)$g['pct'] > 0): ?><span class="r-chip vio"><?php echo rtrim(rtrim(number_format((float)$g['pct'], 2, ',', ''), '0'), ','); ?>% komisi mitra</span><?php endif; ?>
                    </div>
                    <?php $renderTiles($g['motors']); ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Riwayat pengembalian -->
    <div class="r-card">
        <div class="r-card-head">
            <div class="r-card-title">
                <span class="r-ic ic-green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/></svg></span>
                <div><b>Pengembalian Terakhir</b><small>10 rental terakhir yang sudah kembali</small></div>
            </div>
            <a class="r-btn" href="rental-motor.php?view=manage&amp;tab=history">Lihat semua riwayat →</a>
        </div>
        <?php if (!empty($recentReturns)): ?>
            <div class="r-tbl-wrap">
                <table class="r-tbl">
                    <thead><tr><th>Motor</th><th>Tamu</th><th>Disewa</th><th>Kembali</th><th class="r">Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentReturns as $ret): ?>
                            <tr>
                                <td><div class="r-plate"><?php echo htmlspecialchars($ret['plate_number']); ?></div><div class="r-mute"><?php echo htmlspecialchars($ret['motor_name']); ?></div></td>
                                <td><div class="r-strong" style="font-weight:600"><?php echo htmlspecialchars($ret['guest_name'] ?: '-'); ?><?php if (!empty($ret['room_number'])): ?><span class="r-room"><?php echo htmlspecialchars($ret['room_number']); ?></span><?php endif; ?></div></td>
                                <td class="r-mute" style="font-size:.72rem!important"><?php echo $rmDt($ret['start_datetime']); ?></td>
                                <td><span class="r-chip ok"><i></i><?php echo $ret['actual_return'] ? $rmDt($ret['actual_return']) : 'Kembali'; ?></span></td>
                                <td class="r" style="font-weight:800"><?php echo $rmRp($ret['total_price']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="r-empty">Belum ada motor yang dikembalikan.</div>
        <?php endif; ?>
    </div>
</div>

<!-- Detail motor -->
<div class="rmd-ov" id="rmdOverlay" onclick="if (event.target === this) rmdClose()">
    <div id="rmdModal" role="dialog" aria-modal="true" aria-labelledby="rmdPlate">
        <div class="m-head">
            <span class="m-ic" id="rmdIc"><?php echo $motorIcon; ?></span>
            <div class="m-ttl"><b id="rmdPlate">-</b><small id="rmdName">-</small></div>
            <button type="button" class="m-x" onclick="rmdClose()" aria-label="Tutup">&times;</button>
        </div>
        <div class="m-body" id="rmdBody"></div>
        <div class="m-foot" id="rmdFoot"></div>
    </div>
</div>

<script>
    (function() {
        var BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        function esc(t) { return String(t == null ? '' : t).replace(/[&<>"]/g, function(c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
        function dt(s) {
            var d = new Date(String(s || '').replace(' ', 'T'));
            if (isNaN(d)) return esc(s || '-');
            return d.getDate() + ' ' + BULAN[d.getMonth()] + ' ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
        }
        function rp(n) { return 'Rp ' + Math.round(+n || 0).toLocaleString('id-ID'); }
        function row(k, v) { return '<div class="m-row"><span>' + k + '</span><span>' + v + '</span></div>'; }

        window.rmdShow = function(el) {
            var d = JSON.parse(el.getAttribute('data-motor'));
            var rented = d.status === 'rented' && d.guest;
            var cls = rented ? (d.late ? 'late' : 'rented') : (d.status === 'maintenance' ? 'maintenance' : '');
            document.getElementById('rmdIc').className = 'm-ic ' + cls;
            document.getElementById('rmdPlate').textContent = d.plate;
            document.getElementById('rmdName').textContent = d.name + (d.color ? ' · ' + d.color : '');
            var st = rented ? (d.late ? '<span class="m-st bad">Terlambat</span>' : '<span class="m-st warn">Sedang disewa</span>')
                : (d.status === 'maintenance' ? '<span class="m-st gray">Dalam perbaikan</span>' : '<span class="m-st ok">Siap disewa</span>');
            var h = row('Status', st) + row('Tarif', rp(d.rate) + ' / hari');
            if (d.partner) h += row('Mitra', esc(d.partner) + (d.partner_phone ? ' · ' + esc(d.partner_phone) : ''));
            if (rented) {
                h += row('Tamu', esc(d.guest) + (d.room ? ' · Kamar ' + esc(d.room) : ''));
                if (d.start) h += row('Mulai', dt(d.start));
                if (d.end) h += row('Jadwal kembali', dt(d.end));
                h += row('Total', +d.total > 0 ? rp(d.total) : 'dihitung saat kembali');
            }
            document.getElementById('rmdBody').innerHTML = h;
            var f = '<a class="m-btn" href="rental-motor.php?view=manage&tab=' + (rented ? 'monitoring' : 'fleet') + '">' + (rented ? 'Proses pengembalian' : 'Kelola armada') + '</a>';
            if (!rented && d.status !== 'maintenance') f += '<a class="m-btn primary" href="hotel-services.php?new=motor&motor=' + d.id + '">Sewakan motor ini</a>';
            document.getElementById('rmdFoot').innerHTML = f;
            document.getElementById('rmdOverlay').classList.add('open');
        };
        window.rmdClose = function() { document.getElementById('rmdOverlay').classList.remove('open'); };
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') rmdClose(); });
    })();
</script>

<?php include '../../includes/footer.php'; ?>
