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
$pdo->exec("UPDATE rental_motor_bookings SET status='overdue'
    WHERE status='active' AND end_datetime < NOW() AND business_id={$businessId}");

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
    WHERE business_id=? AND status='overdue'");
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

// Recent Returned Motors
$recent = $pdo->prepare("SELECT rb.*, rm.plate_number, rm.motor_name
    FROM rental_motor_bookings rb
    JOIN rental_motors rm ON rb.motor_id = rm.id
    WHERE rb.business_id=? AND rb.status='returned'
    ORDER BY rb.actual_return DESC LIMIT 10");
$recent->execute([$businessId]);
$recentReturns = $recent->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>
<style>
    .dashboard-page {
        padding: 1rem 1.15rem 1.25rem;
        max-width: 1400px;
        margin: 0 auto;
    }

    .dashboard-header {
        margin-bottom: 1rem;
    }

    .dashboard-header h1 {
        margin: 0 0 0.2rem;
        font-size: 1.45rem;
        font-weight: 800;
        color: var(--text-primary);
    }

    .dashboard-header .subtitle {
        font-size: 0.78rem;
        color: var(--text-secondary);
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    .stat-card {
        background: white;
        border-radius: 10px;
        padding: 0.85rem 0.9rem 0.8rem;
        box-shadow: 0 1px 6px rgba(0, 0, 0, 0.07);
        border-top: 3px solid var(--stat-color);
    }

    .stat-card .label {
        font-size: 0.72rem;
        color: var(--text-secondary);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        margin-bottom: 0.3rem;
    }

    .stat-card .value {
        font-size: 1.5rem;
        font-weight: 900;
        color: var(--stat-color);
        line-height: 1;
        margin-bottom: 0.25rem;
    }

    .stat-card .detail {
        font-size: 0.7rem;
        color: var(--text-secondary);
    }

    .stat-card .progress-bar {
        height: 5px;
        background: #e2e8f0;
        border-radius: 3px;
        margin-top: 0.6rem;
        overflow: hidden;
    }

    .stat-card .progress-fill {
        height: 100%;
        background: var(--stat-color);
        border-radius: 3px;
    }

    .section-title {
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-primary);
        margin: 1rem 0 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .section-title .icon {
        font-size: 1.3rem;
    }

    .rented-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(330px, 1fr));
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    .rental-card {
        background: white;
        border-radius: 10px;
        padding: 0.95rem 1rem;
        box-shadow: 0 1px 6px rgba(0, 0, 0, 0.07);
        border-left: 4px solid var(--card-color);
    }

    .rental-card.overdue {
        border-left-color: #ef4444;
        background: #fef2f2;
    }

    .rental-card.active {
        border-left-color: #10b981;
    }

    .rc-header {
        display: flex;
        justify-content: space-between;
        align-items: start;
        margin-bottom: 0.55rem;
    }

    .rc-plate {
        font-size: 0.98rem;
        font-weight: 800;
        color: #1e293b;
        font-family: 'Courier New', monospace;
    }

    .rc-status {
        display: inline-block;
        padding: 0.2rem 0.6rem;
        border-radius: 20px;
        font-size: 0.65rem;
        font-weight: 700;
        color: white;
    }

    .rc-status.active {
        background: #10b981;
    }

    .rc-status.overdue {
        background: #ef4444;
    }

    .rc-info {
        font-size: 0.8rem;
        margin-bottom: 0.45rem;
    }

    .rc-info-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.25rem;
    }

    .rc-info-label {
        color: var(--text-secondary);
        font-weight: 500;
    }

    .rc-info-value {
        font-weight: 600;
        color: var(--text-primary);
    }

    .rc-timeline {
        font-size: 0.75rem;
        background: #f8fafc;
        border-radius: 8px;
        padding: 0.6rem 0.7rem;
        margin: 0.55rem 0;
    }

    .rc-time {
        color: var(--text-secondary);
        margin-bottom: 0.3rem;
    }

    .rc-time strong {
        color: var(--text-primary);
    }

    .rc-price {
        background: #f0f4ff;
        border-radius: 8px;
        padding: 0.55rem 0.7rem;
        margin: 0.55rem 0;
        border-left: 3px solid #6366f1;
    }

    .rc-price .amount {
        font-size: 1rem;
        font-weight: 800;
        color: #6366f1;
    }

    .rc-price .note {
        font-size: 0.7rem;
        color: var(--text-secondary);
        margin-top: 0.2rem;
    }

    .available-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 0.65rem;
    }

    .motor-available {
        background: linear-gradient(135deg, #dcfce7, #d1fae5);
        border-radius: 10px;
        padding: 0.8rem 0.75rem;
        border: 1px solid #10b981;
        text-align: center;
    }

    .motor-available .icon {
        font-size: 2rem;
        margin-bottom: 0.5rem;
    }

    .motor-available .name {
        font-weight: 800;
        color: #047857;
        margin-bottom: 0.25rem;
        font-size: 0.82rem;
    }

    .motor-available .plate {
        font-size: 0.82rem;
        font-family: 'Courier New';
        font-weight: 700;
        color: #065f46;
    }

    .motor-available .rate {
        font-size: 0.72rem;
        color: #047857;
        margin-top: 0.5rem;
    }

    .dashboard-content {
        display: grid;
        grid-template-columns: minmax(0, 1.15fr) minmax(320px, 0.85fr);
        gap: 0.9rem;
        align-items: start;
        margin-bottom: 0.9rem;
    }

    .dashboard-panel {
        background: rgba(255, 255, 255, 0.68);
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 14px;
        padding: 0.9rem;
        box-shadow: 0 1px 10px rgba(15, 23, 42, 0.04);
        backdrop-filter: blur(6px);
    }

    .panel-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.7rem;
    }

    .panel-head h2 {
        margin: 0;
        font-size: 0.92rem;
        font-weight: 800;
        color: var(--text-primary);
    }

    .panel-head .hint {
        font-size: 0.72rem;
        color: var(--text-secondary);
    }

    .panel-stack {
        display: grid;
        gap: 0.8rem;
    }

    .recent-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .recent-table th {
        background: #f8fafc;
        padding: 1rem;
        text-align: left;
        font-weight: 700;
        font-size: 0.82rem;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.03em;
        border-bottom: 1px solid #e2e8f0;
    }

    .recent-table td {
        padding: 0.9rem 1rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }

    .recent-table tr:last-child td {
        border-bottom: none;
    }

    .recent-table tr:hover {
        background: #fafbff;
    }

    .badge {
        display: inline-block;
        padding: 0.3rem 0.75rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 700;
        color: white;
    }

    .badge-success {
        background: #10b981;
    }

    .badge-warning {
        background: #f59e0b;
    }

    .badge-danger {
        background: #ef4444;
    }

    .badge-secondary {
        background: #64748b;
    }

    .empty-state {
        text-align: center;
        padding: 2rem 1rem;
        color: var(--text-secondary);
    }

    .empty-state .icon {
        font-size: 2.5rem;
        margin-bottom: 0.75rem;
    }

    .empty-state .text {
        font-size: 0.95rem;
    }

    .action-link {
        color: #6366f1;
        text-decoration: none;
        font-weight: 600;
        font-size: 0.85rem;
    }

    .action-link:hover {
        text-decoration: underline;
    }

    @media(max-width: 768px) {
        .dashboard-content {
            grid-template-columns: 1fr;
        }

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .rented-grid {
            grid-template-columns: 1fr;
        }

        .available-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
</style>

<div class="dashboard-page">
    <!-- Header -->
    <div class="dashboard-header" style="display:flex;justify-content:space-between;align-items:center">
        <div>
            <h1>🏍️ Dashboard Rental Motor</h1>
            <div class="subtitle">Monitoring armada dan penyewaan real-time</div>
        </div>
        <a href="rental-motor.php?view=manage" class="btn-rm btn-rm-primary" style="white-space:nowrap;text-decoration:none;padding:0.5rem 1rem">⚙️ Kelola Armada</a>
    </div>

    <!-- Stats Overview -->
    <div class="stats-grid">
        <div class="stat-card" style="--stat-color:#6366f1">
            <div class="label">Total Motor</div>
            <div class="value"><?php echo $totalMotors; ?></div>
            <div class="detail">dalam sistem</div>
        </div>

        <div class="stat-card" style="--stat-color:#10b981">
            <div class="label">Siap Disewa</div>
            <div class="value"><?php echo $availableCount; ?></div>
            <div class="detail">tersedia sekarang</div>
            <?php if ($totalMotors > 0): ?>
                <div class="progress-bar">
                    <div class="progress-fill" style="width:<?php echo ($availableCount / $totalMotors) * 100; ?>%"></div>
                </div>
            <?php endif; ?>
        </div>

        <div class="stat-card" style="--stat-color:#f59e0b">
            <div class="label">Sedang Disewa</div>
            <div class="value"><?php echo $rentedCount; ?></div>
            <div class="detail"><?php echo $occupancyRate; ?>% okupansi</div>
            <?php if ($totalMotors > 0): ?>
                <div class="progress-bar">
                    <div class="progress-fill" style="width:<?php echo ($rentedCount / $totalMotors) * 100; ?>%"></div>
                </div>
            <?php endif; ?>
        </div>

        <div class="stat-card" style="--stat-color:#ef4444">
            <div class="label">Aktif Sekarang</div>
            <div class="value"><?php echo $activeCount; ?></div>
            <div class="detail"><?php echo $overdueCount > 0 ? $overdueCount . ' overdue' : 'all on time'; ?></div>
        </div>

        <div class="stat-card" style="--stat-color:#8b5cf6">
            <div class="label">Revenue Bulan Ini</div>
            <div class="value">Rp <?php echo number_format($revData['revenue'], 0, ',', '.'); ?></div>
            <div class="detail"><?php echo $revData['rentals_count']; ?> transaksi</div>
        </div>

        <div class="stat-card" style="--stat-color:#06b6d4">
            <div class="label">Maintenance</div>
            <div class="value"><?php echo $maintenanceCount; ?></div>
            <div class="detail">dalam perbaikan</div>
        </div>
    </div>

    <div class="dashboard-content">
        <div class="dashboard-panel">
            <div class="panel-head">
                <h2>Rental Aktif Sekarang</h2>
                <div class="hint"><?php echo count($rentedList); ?> unit aktif</div>
            </div>

            <?php if (!empty($rentedList)): ?>
                <div class="rented-grid" style="margin-bottom:0;">
                    <?php foreach ($rentedList as $r):
                        $now = new DateTime();
                        $endDt = new DateTime($r['end_datetime']);
                        $diff = $now->diff($endDt);
                        $isOverdue = $r['status'] === 'overdue';
                    ?>
                        <div class="rental-card <?php echo $r['status']; ?>">
                            <div class="rc-header">
                                <div class="rc-plate"><?php echo htmlspecialchars($r['plate_number']); ?></div>
                                <span class="rc-status <?php echo $r['status']; ?>">
                                    <?php echo $isOverdue ? '⚠ OVERDUE' : '✓ AKTIF'; ?>
                                </span>
                            </div>

                            <div class="rc-info">
                                <div style="font-size:0.84rem;font-weight:700;color:var(--text-primary);margin-bottom:0.35rem">
                                    <?php echo htmlspecialchars($r['motor_name']); ?>
                                </div>
                                <div style="font-size:0.75rem;color:var(--text-secondary)">
                                    <?php if ($r['color']): ?>Warna: <?php echo htmlspecialchars($r['color']); ?><br /><?php endif; ?>
                                </div>
                            </div>

                            <div style="border-top:1px solid rgba(0,0,0,0.08);padding-top:0.55rem;margin-top:0.55rem">
                                <div class="rc-info-row">
                                    <span class="rc-info-label">👤 Tamu</span>
                                    <span class="rc-info-value"><?php echo htmlspecialchars(substr($r['guest_name'], 0, 20)); ?></span>
                                </div>
                                <?php if ($r['room_number']): ?>
                                    <div class="rc-info-row">
                                        <span class="rc-info-label">🚪 Kamar</span>
                                        <span class="rc-info-value">#<?php echo htmlspecialchars($r['room_number']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="rc-timeline">
                                <div class="rc-time">🚪 Mulai: <strong><?php echo date('d M H:i', strtotime($r['start_datetime'])); ?></strong></div>
                                <div class="rc-time">🔑 Kembali: <strong><?php echo date('d M H:i', strtotime($r['end_datetime'])); ?></strong></div>
                                <div style="margin-top:0.4rem;padding-top:0.4rem;border-top:1px solid rgba(0,0,0,0.08)">
                                    <div style="font-weight:700;color:<?php echo $isOverdue ? '#ef4444' : '#10b981'; ?>">
                                        <?php
                                        if ($isOverdue) {
                                            echo '⏰ Terlambat: ' . $diff->days . 'h ' . $diff->h . 'j';
                                        } else {
                                            echo '⏳ Sisa: ' . $diff->days . 'h ' . $diff->h . 'j ' . $diff->i . 'm';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>

                            <div class="rc-price">
                                <div class="amount">
                                    <?php
                                    if ((float)$r['total_price'] == 0) {
                                        $startDt = new DateTime($r['start_datetime']);
                                        $endDt = new DateTime($r['end_datetime']);
                                        $estDays = max(1, (int)ceil($startDt->diff($endDt)->days));
                                        $estPrice = max(100000, round($estDays * (float)$r['daily_rate'], 2));
                                        echo '~Rp ' . number_format($estPrice, 0, ',', '.');
                                    } else {
                                        echo 'Rp ' . number_format($r['total_price'], 0, ',', '.');
                                    }
                                    ?>
                                </div>
                                <div class="note">
                                    <?php if ((float)$r['total_price'] == 0): ?>
                                        estimasi (hitung saat kembali)
                                    <?php else: ?>
                                        final
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div style="margin-top:0.6rem">
                                <a href="rental-motor.php?view=manage" class="action-link" style="display:block;text-align:center;padding:0.35rem">
                                    → Kelola
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state" style="padding:1.25rem 0 0.5rem">
                    <div class="icon">😊</div>
                    <div class="text">Tidak ada rental aktif saat ini</div>
                </div>
            <?php endif; ?>
        </div>

        <div class="panel-stack">
            <div class="dashboard-panel">
                <div class="panel-head">
                    <h2>Motor Siap Disewa</h2>
                    <div class="hint"><?php echo count($availableList); ?> unit ready</div>
                </div>

                <?php if (!empty($availableList)): ?>
                    <div class="available-grid">
                        <?php foreach ($availableList as $m): ?>
                            <div class="motor-available">
                                <div class="icon">🏍️</div>
                                <div class="name"><?php echo htmlspecialchars($m['motor_name']); ?></div>
                                <div class="plate"><?php echo htmlspecialchars($m['plate_number']); ?></div>
                                <div class="rate">Rp <?php echo number_format($m['daily_rate'], 0, ',', '.'); ?>/hari</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="padding:1rem 0 0.25rem">
                        <div class="icon">🅿️</div>
                        <div class="text">Semua unit sedang digunakan atau maintenance</div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($recentReturns)): ?>
                <div class="dashboard-panel">
                    <div class="panel-head">
                        <h2>Transaksi Terakhir</h2>
                        <div class="hint">10 data terbaru</div>
                    </div>
                    <table class="recent-table" style="box-shadow:none;border-radius:10px;overflow:hidden">
                        <thead>
                            <tr>
                                <th style="padding:0.7rem">Motor</th>
                                <th style="padding:0.7rem">Total</th>
                                <th style="padding:0.7rem">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentReturns as $ret): ?>
                                <tr>
                                    <td style="padding:0.7rem">
                                        <strong><?php echo htmlspecialchars($ret['plate_number']); ?></strong>
                                        <div style="font-size:0.75rem;color:var(--text-secondary)"><?php echo htmlspecialchars($ret['motor_name']); ?></div>
                                    </td>
                                    <td style="padding:0.7rem;font-weight:600">Rp <?php echo number_format($ret['total_price'], 0, ',', '.'); ?></td>
                                    <td style="padding:0.7rem"><span class="badge badge-success">Kembali</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php include '../../includes/footer.php'; ?>