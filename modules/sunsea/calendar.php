<?php

/**
 * Sunsea - Kalender Booking (Balok Reservasi Confirmed)
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();
$pdo = getSunseaConnection();
sunseaEnsureBookingSchema($pdo);

$month = $_GET['month'] ?? date('Y-m');
$startMonth = date('Y-m-01', strtotime($month . '-01'));
$endMonth = date('Y-m-t', strtotime($month . '-01'));

$bookings = [];
$errorMsg = '';

try {
    $rows = $pdo->prepare("SELECT b.id, b.booking_no, b.start_date, b.end_date, b.pax_count, b.status,
        c.name as customer_name
        FROM booking_orders b
        JOIN customers c ON c.id=b.customer_id
        WHERE b.end_date >= ? AND b.start_date <= ?
          AND b.status = 'confirmed'
        ORDER BY b.start_date, b.id");
    $rows->execute([$startMonth, $endMonth]);
    $bookings = $rows->fetchAll();
} catch (Exception $e) {
    $errorMsg = 'Error loading bookings: ' . htmlspecialchars($e->getMessage());
}

$daysInMonth = (int)date('t', strtotime($startMonth));
$pageTitle = 'Kalender Booking';
$activePage = 'calendar';
include 'layout-header.php';
?>

<?php if ($errorMsg): ?>
    <div style="padding:14px;margin-bottom:14px;border-radius:8px;background:#fee2e2;border:1px solid #fca5a5;color:#c33;">
        ⚠️ <?php echo $errorMsg; ?>
    </div>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
    <div>
        <h3 style="margin:0;font-size:18px;">Kalender Booking (Balok Reservasi)</h3>
        <div style="color:var(--ss-muted);font-size:12px;">Menampilkan reservasi tamu dengan status confirmed</div>
    </div>
    <form method="GET" style="display:flex;gap:8px;align-items:center;">
        <input type="month" name="month" class="ss-input" style="width:180px;" value="<?php echo htmlspecialchars($month); ?>">
        <button class="ss-btn ss-btn-outline" type="submit"><i data-feather="search"></i> Lihat</button>
    </form>
</div>

<div class="ss-card" style="margin-bottom:16px;">
    <div class="ss-card-title" style="margin-bottom:12px;">Timeline Reservasi Confirmed - <?php echo date('F Y', strtotime($startMonth)); ?></div>
    <div style="overflow:auto;">
        <div style="min-width:900px;">
            <div style="display:grid;grid-template-columns:260px repeat(<?php echo $daysInMonth; ?>, 24px);gap:2px;align-items:center;margin-bottom:8px;">
                <div style="font-size:11px;color:var(--ss-muted);font-weight:700;">Booking</div>
                <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                    <div style="font-size:10px;color:var(--ss-muted);text-align:center;"><?php echo $d; ?></div>
                <?php endfor; ?>
            </div>

            <?php foreach ($bookings as $b):
                $s = max(1, (int)date('j', strtotime(max($b['start_date'], $startMonth))));
                $e = min($daysInMonth, (int)date('j', strtotime(min($b['end_date'], $endMonth))));
                $span = max(1, $e - $s + 1);
                $statusColor = '#3b82f6';
            ?>
                <div style="display:grid;grid-template-columns:260px repeat(<?php echo $daysInMonth; ?>, 24px);gap:2px;align-items:center;margin-bottom:4px;">
                    <div style="font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <a href="bookings.php?view=<?php echo $b['id']; ?>" style="color:var(--ss-ocean);text-decoration:none;font-weight:600;"><?php echo htmlspecialchars($b['booking_no']); ?></a>
                        <div style="font-size:10px;color:var(--ss-muted)"><?php echo htmlspecialchars($b['customer_name']); ?> · <?php echo (int)$b['pax_count']; ?> pax · confirmed</div>
                    </div>
                    <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                        <?php if ($d >= $s && $d <= $e): ?>
                            <div style="height:16px;background:<?php echo $statusColor; ?>;border-radius:3px;"></div>
                        <?php else: ?>
                            <div style="height:16px;background:#F1F5F9;border-radius:3px;"></div>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php endforeach; ?>

            <?php if (empty($bookings)): ?>
                <div style="padding:14px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;color:#64748b;font-size:13px;">
                    Tidak ada reservasi confirmed pada bulan ini.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="ss-card">
    <div class="ss-card-title" style="margin-bottom:8px;">Daftar Reservasi Confirmed Bulan Ini</div>
    <div class="ss-table-wrap">
        <table class="ss-table">
            <thead>
                <tr>
                    <th>Booking</th>
                    <th>Customer</th>
                    <th>Tanggal</th>
                    <th>Pax</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $b): ?>
                    <tr>
                        <td><a href="bookings.php?view=<?php echo $b['id']; ?>" style="color:var(--ss-ocean);font-weight:600;text-decoration:none;"><?php echo htmlspecialchars($b['booking_no']); ?></a></td>
                        <td><?php echo htmlspecialchars($b['customer_name']); ?></td>
                        <td><?php echo date('d M Y', strtotime($b['start_date'])); ?> - <?php echo date('d M Y', strtotime($b['end_date'])); ?></td>
                        <td><?php echo (int)$b['pax_count']; ?></td>
                        <td><span class="ss-status ss-status-sent"><?php echo ucfirst($b['status']); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($bookings)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center;color:#64748b;">Belum ada data reservasi confirmed.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'layout-footer.php';
