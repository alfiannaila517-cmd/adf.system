<?php

/**
 * Sunsea - Dashboard
 * Ocean-themed travel bureau dashboard
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';

try {
    $pdo = getSunseaConnection();

    // Stats: Quotations
    $qStats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) as draft,
            SUM(CASE WHEN status='sent'  THEN 1 ELSE 0 END) as sent,
            SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) as approved
        FROM quotations
    ")->fetch();

    // Stats: Invoices
    $iStats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status='issued'   THEN 1 ELSE 0 END) as issued,
            SUM(CASE WHEN status='partial'  THEN 1 ELSE 0 END) as partial,
            SUM(CASE WHEN status='paid'     THEN 1 ELSE 0 END) as paid,
            SUM(CASE WHEN status='overdue'  THEN 1 ELSE 0 END) as overdue,
            COALESCE(SUM(CASE WHEN status IN ('issued','partial') THEN remaining_amount ELSE 0 END), 0) as outstanding
        FROM invoices
    ")->fetch();

    // Stats: Customers
    $custCount = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE is_active=1")->fetchColumn();

    // Stats: Packages
    $pkgCount  = (int)$pdo->query("SELECT COUNT(*) FROM trip_packages WHERE is_active=1")->fetchColumn();

    // Stats: Bookings
    $bookingStats = $pdo->query("SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status IN ('draft','confirmed','ongoing') THEN 1 ELSE 0 END) as active
        FROM booking_orders")->fetch();

    // Revenue this month
    $monthRevenue = (float)$pdo->query("
        SELECT COALESCE(SUM(amount),0) FROM payments 
        WHERE YEAR(payment_date)=YEAR(NOW()) AND MONTH(payment_date)=MONTH(NOW())
    ")->fetchColumn();

    // Recent quotations (5)
    $recentQuotations = $pdo->query("
        SELECT q.quotation_no, q.status, q.total_amount, q.trip_date, q.created_at,
               c.name as customer_name
        FROM quotations q
        JOIN customers c ON c.id = q.customer_id
        ORDER BY q.created_at DESC
        LIMIT 5
    ")->fetchAll();

    // Recent invoices (5)
    $recentInvoices = $pdo->query("
        SELECT i.invoice_no, i.status, i.total_amount, i.remaining_amount, i.due_date,
               c.name as customer_name
        FROM invoices i
        JOIN customers c ON c.id = i.customer_id
        ORDER BY i.created_at DESC
        LIMIT 5
    ")->fetchAll();

    // Monthly guest reservations (last 12 months)
    $monthlyGuests = [];
    $monthLabels = [];
    $currentYear = (int)date('Y');

    for ($m = 11; $m >= 0; $m--) {
        $date = new DateTime();
        $date->modify("-{$m} months");
        $monthKey = $date->format('Y-m');
        $monthLabels[] = $date->format('M');

        $count = (int)$pdo->query("
            SELECT COALESCE(SUM(pax_count),0) FROM booking_orders
            WHERE status='confirmed' 
              AND YEAR(start_date)={$currentYear}
              AND DATE_FORMAT(start_date,'%Y-%m')='{$monthKey}'
        ")->fetchColumn();
        $monthlyGuests[] = $count;
    }
    $monthLabels = json_encode($monthLabels);
    $monthlyGuests = json_encode($monthlyGuests);

    // Yearly guest reservations (last 5 years)
    $yearlyGuests = [];
    $yearLabels = [];

    for ($y = 4; $y >= 0; $y--) {
        $year = $currentYear - $y;
        $yearLabels[] = $year;

        $count = (int)$pdo->query("
            SELECT COALESCE(SUM(pax_count),0) FROM booking_orders
            WHERE status='confirmed' AND YEAR(start_date)={$year}
        ")->fetchColumn();
        $yearlyGuests[] = $count;
    }
    $yearLabels = json_encode($yearLabels);
    $yearlyGuests = json_encode($yearlyGuests);
} catch (Exception $e) {
    $dbError = $e->getMessage();
    $qStats = ['total' => 0, 'draft' => 0, 'sent' => 0, 'approved' => 0];
    $iStats = ['total' => 0, 'issued' => 0, 'partial' => 0, 'paid' => 0, 'overdue' => 0, 'outstanding' => 0];
    $custCount = $pkgCount = 0;
    $monthRevenue = 0;
    $bookingStats = ['total' => 0, 'active' => 0];
    $recentQuotations = $recentInvoices = [];
    $monthLabels = json_encode([]);
    $monthlyGuests = json_encode([]);
    $yearLabels = json_encode([]);
    $yearlyGuests = json_encode([]);
}

include 'layout-header.php';
?>

<?php if (isset($dbError)): ?>
    <div class="ss-alert ss-alert-error">
        <i data-feather="alert-circle"></i>
        Database belum siap. Jalankan <code>database/sunsea-setup.sql</code> terlebih dahulu.
        <br><small style="opacity:.7"><?php echo htmlspecialchars($dbError); ?></small>
    </div>
<?php endif; ?>

<!-- ============================
     STAT CARDS
============================= -->
<div class="ss-stats-grid">
    <div class="ss-stat-card">
        <div class="ss-stat-icon ocean"><i data-feather="users"></i></div>
        <div>
            <div class="ss-stat-value"><?php echo $custCount; ?></div>
            <div class="ss-stat-label">Total Pelanggan</div>
        </div>
    </div>
    <div class="ss-stat-card">
        <div class="ss-stat-icon cyan"><i data-feather="file-text"></i></div>
        <div>
            <div class="ss-stat-value"><?php echo $qStats['total'] ?? 0; ?></div>
            <div class="ss-stat-label">Penawaran <span style="color:var(--ss-warning);"><?php echo (int)($qStats['sent'] ?? 0); ?> terkirim</span></div>
        </div>
    </div>
    <div class="ss-stat-card">
        <div class="ss-stat-icon success"><i data-feather="credit-card"></i></div>
        <div>
            <div class="ss-stat-value"><?php echo $iStats['total'] ?? 0; ?></div>
            <div class="ss-stat-label">Invoice <span style="color:var(--ss-danger);"><?php echo (int)($iStats['overdue'] ?? 0); ?> overdue</span></div>
        </div>
    </div>
    <div class="ss-stat-card">
        <div class="ss-stat-icon warning"><i data-feather="trending-up"></i></div>
        <div>
            <div class="ss-stat-value" style="font-size:16px;"><?php echo sunseaRupiah($monthRevenue, true); ?></div>
            <div class="ss-stat-label">Pendapatan Bulan Ini</div>
        </div>
    </div>
    <div class="ss-stat-card">
        <div class="ss-stat-icon danger"><i data-feather="alert-triangle"></i></div>
        <div>
            <div class="ss-stat-value" style="font-size:16px;"><?php echo sunseaRupiah((float)($iStats['outstanding'] ?? 0), true); ?></div>
            <div class="ss-stat-label">Piutang Belum Lunas</div>
        </div>
    </div>
    <div class="ss-stat-card">
        <div class="ss-stat-icon ocean"><i data-feather="package"></i></div>
        <div>
            <div class="ss-stat-value"><?php echo $pkgCount; ?></div>
            <div class="ss-stat-label">Paket Wisata Aktif</div>
        </div>
    </div>
    <div class="ss-stat-card">
        <div class="ss-stat-icon cyan"><i data-feather="briefcase"></i></div>
        <div>
            <div class="ss-stat-value"><?php echo (int)($bookingStats['total'] ?? 0); ?></div>
            <div class="ss-stat-label">Pemesanan <span style="color:var(--ss-ocean);"><?php echo (int)($bookingStats['active'] ?? 0); ?> aktif</span></div>
        </div>
    </div>
</div>

<!-- ============================
     QUICK ACTIONS
============================= -->
<div class="ss-card" style="margin-bottom:24px;">
    <div class="ss-card-header">
        <div>
            <div class="ss-card-title">Aksi Cepat</div>
            <div class="ss-card-sub">Buat dokumen baru</div>
        </div>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:10px;">
        <a href="bookings.php?action=add" class="ss-btn ss-btn-primary">
            <i data-feather="briefcase"></i> Input Pemesanan
        </a>
        <a href="calendar.php" class="ss-btn ss-btn-outline">
            <i data-feather="calendar"></i> Kalender Booking
        </a>
        <a href="partners.php" class="ss-btn ss-btn-outline">
            <i data-feather="building"></i> Hotel & Homestay
        </a>
        <a href="guides.php" class="ss-btn ss-btn-outline">
            <i data-feather="compass"></i> Guide Darat/Laut
        </a>
        <a href="coordinators.php" class="ss-btn ss-btn-outline">
            <i data-feather="user-check"></i> Koordinator
        </a>
        <a href="facilities.php" class="ss-btn ss-btn-outline">
            <i data-feather="tool"></i> Fasilitas
        </a>
        <a href="customers.php?action=add" class="ss-btn ss-btn-outline">
            <i data-feather="user-plus"></i> Pelanggan Baru
        </a>
        <a href="quotations.php?action=add" class="ss-btn ss-btn-primary">
            <i data-feather="file-plus"></i> Buat Penawaran
        </a>
        <a href="invoices.php?action=add" class="ss-btn ss-btn-outline">
            <i data-feather="plus-circle"></i> Buat Invoice
        </a>
        <a href="calculator.php" class="ss-btn ss-btn-outline">
            <i data-feather="calculator"></i> Kalkulator Harga
        </a>
        <a href="packages.php?action=add" class="ss-btn ss-btn-outline">
            <i data-feather="plus"></i> Paket Baru
        </a>
    </div>
</div>

<!-- ============================
     RESERVATION CHARTS
============================= -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">

    <!-- Monthly Guests Chart -->
    <div class="ss-card">
        <div class="ss-card-header">
            <div>
                <div class="ss-card-title">Tamu Reservasi Bulanan</div>
                <div class="ss-card-sub">Total tamu per bulan (12 bulan terakhir)</div>
            </div>
        </div>
        <div style="position:relative;height:300px;padding:10px;">
            <canvas id="monthlyGuestsChart"></canvas>
        </div>
    </div>

    <!-- Yearly Guests Chart -->
    <div class="ss-card">
        <div class="ss-card-header">
            <div>
                <div class="ss-card-title">Tamu Reservasi Tahunan</div>
                <div class="ss-card-sub">Total tamu per tahun (5 tahun terakhir)</div>
            </div>
        </div>
        <div style="position:relative;height:300px;padding:10px;">
            <canvas id="yearlyGuestsChart"></canvas>
        </div>
    </div>

</div>

<!-- ============================
     TWO COLUMN: Recent Quotations & Invoices
============================= -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

    <!-- Recent Quotations -->
    <div class="ss-card">
        <div class="ss-card-header">
            <div>
                <div class="ss-card-title">Penawaran Terbaru</div>
                <div class="ss-card-sub">5 penawaran terakhir</div>
            </div>
            <a href="quotations.php" class="ss-btn ss-btn-outline ss-btn-sm">Lihat Semua</a>
        </div>
        <?php if (empty($recentQuotations)): ?>
            <div class="ss-empty">
                <div class="ss-empty-icon">📋</div>
                <h3>Belum ada penawaran</h3>
                <p>Buat penawaran pertama untuk customer Anda</p>
            </div>
        <?php else: ?>
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead>
                        <tr>
                            <th>No. Penawaran</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentQuotations as $q): ?>
                            <tr>
                                <td><a href="quotations.php?view=<?php echo urlencode($q['quotation_no']); ?>"
                                        style="color:var(--ss-ocean);font-weight:600;text-decoration:none;">
                                        <?php echo htmlspecialchars($q['quotation_no']); ?>
                                    </a></td>
                                <td><?php echo htmlspecialchars($q['customer_name']); ?></td>
                                <td><span class="ss-status ss-status-<?php echo $q['status']; ?>"><?php echo ucfirst($q['status']); ?></span></td>
                                <td style="font-weight:600;"><?php echo sunseaRupiah((float)$q['total_amount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Recent Invoices -->
    <div class="ss-card">
        <div class="ss-card-header">
            <div>
                <div class="ss-card-title">Invoice Terbaru</div>
                <div class="ss-card-sub">5 invoice terakhir</div>
            </div>
            <a href="invoices.php" class="ss-btn ss-btn-outline ss-btn-sm">Lihat Semua</a>
        </div>
        <?php if (empty($recentInvoices)): ?>
            <div class="ss-empty">
                <div class="ss-empty-icon">🧾</div>
                <h3>Belum ada invoice</h3>
                <p>Invoice akan muncul setelah Anda membuat penawaran</p>
            </div>
        <?php else: ?>
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead>
                        <tr>
                            <th>No. Invoice</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th>Sisa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentInvoices as $inv): ?>
                            <tr>
                                <td><a href="invoices.php?view=<?php echo urlencode($inv['invoice_no']); ?>"
                                        style="color:var(--ss-ocean);font-weight:600;text-decoration:none;">
                                        <?php echo htmlspecialchars($inv['invoice_no']); ?>
                                    </a></td>
                                <td><?php echo htmlspecialchars($inv['customer_name']); ?></td>
                                <td><span class="ss-status ss-status-<?php echo $inv['status']; ?>"><?php echo ucfirst($inv['status']); ?></span></td>
                                <td style="font-weight:600;color:<?php echo ($inv['remaining_amount'] > 0) ? 'var(--ss-danger)' : 'var(--ss-success)'; ?>">
                                    <?php echo sunseaRupiah((float)$inv['remaining_amount']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
    // Ocean theme colors
    const oceanColors = {
        primary: '#0369a1', // Ocean blue
        secondary: '#06b6d4', // Cyan
        success: '#10b981', // Green
        warning: '#f59e0b', // Amber
        danger: '#ef4444' // Red
    };

    // Monthly Guests Chart
    const monthlyCtx = document.getElementById('monthlyGuestsChart');
    if (monthlyCtx) {
        new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: <?php echo $monthLabels; ?>,
                datasets: [{
                    label: 'Tamu Reservasi',
                    data: <?php echo $monthlyGuests; ?>,
                    borderColor: oceanColors.primary,
                    backgroundColor: oceanColors.primary + '15',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 5,
                    pointBackgroundColor: oceanColors.primary,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointHoverRadius: 7
                }],
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                font: {
                                    size: 13
                                },
                                padding: 15,
                                usePointStyle: true
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 5,
                                font: {
                                    size: 12
                                }
                            },
                            grid: {
                                color: 'rgba(0,0,0,0.05)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            }
        });
    }

    // Yearly Guests Chart
    const yearlyCtx = document.getElementById('yearlyGuestsChart');
    if (yearlyCtx) {
        new Chart(yearlyCtx, {
            type: 'bar',
            data: {
                labels: <?php echo $yearLabels; ?>,
                datasets: [{
                    label: 'Total Tamu',
                    data: <?php echo $yearlyGuests; ?>,
                    backgroundColor: [
                        oceanColors.primary,
                        oceanColors.secondary,
                        oceanColors.success,
                        oceanColors.warning,
                        oceanColors.danger
                    ],
                    borderRadius: 8,
                    borderWidth: 0
                }],
                options: {
                    indexAxis: 'x',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                font: {
                                    size: 13
                                },
                                padding: 15
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 20,
                                font: {
                                    size: 12
                                }
                            },
                            grid: {
                                color: 'rgba(0,0,0,0.05)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            }
        });
    }
</script>

<?php include 'layout-footer.php'; ?>