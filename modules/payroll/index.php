<?php
// modules/payroll/index.php - MODERN 2027 DESIGN
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!isModuleEnabled('payroll')) {
    include '../../includes/header.php';
    echo '<div class="main-content"><div class="alert alert-warning">Modul Gaji (Payroll) belum diaktifkan.</div></div>';
    include '../../includes/footer.php';
    exit;
}

$db = Database::getInstance();
$pageTitle = 'Payroll Dashboard';
$pageSubtitle = 'Payroll activity overview';

// Auto-create Payroll Tables
try {
    $db->query("SELECT 1 FROM payroll_employees LIMIT 1");
} catch (Exception $e) {
    $sqlFile = __DIR__ . '/../../database-payroll.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        $pdo = $db->getConnection();
        // Strip comment lines, then split and execute
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if (!empty($stmt)) {
                try { $pdo->exec($stmt); } catch (PDOException $ex) {}
            }
        }
        setFlash('success', 'Database payroll berhasil diinisialisasi.');
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Stats
$stats = [
    'employees' => $db->fetchOne("SELECT COUNT(*) as c FROM payroll_employees WHERE is_active = 1")['c'] ?? 0,
    'last_period' => $db->fetchOne("SELECT * FROM payroll_periods ORDER BY id DESC LIMIT 1"),
    'total_yearly' => $db->fetchOne("SELECT SUM(total_net) as t FROM payroll_periods WHERE period_year = YEAR(NOW())")['t'] ?? 0,
    'pending_count' => $db->fetchOne("SELECT COUNT(*) as c FROM payroll_periods WHERE status IN ('draft', 'submitted')")['c'] ?? 0,
];

$periods = $db->fetchAll("SELECT * FROM payroll_periods ORDER BY id DESC LIMIT 6");

include '../../includes/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════════════════════
   PAYROLL 2027 - LUXE DESIGN SYSTEM
   Modern, Elegant, Premium UI
   ══════════════════════════════════════════════════════════════════════════ */
   
:root {
    --pr-gradient-1: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    --pr-gradient-2: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    --pr-gradient-3: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    --pr-gradient-4: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    --pr-glass: rgba(255, 255, 255, 0.08);
    --pr-glass-border: rgba(255, 255, 255, 0.12);
    --pr-shadow-soft: 0 8px 32px rgba(0, 0, 0, 0.08);
    --pr-shadow-glow: 0 0 40px rgba(102, 126, 234, 0.15);
    --pr-radius: 20px;
    --pr-radius-sm: 12px;
}

.payroll-hero {
    background: var(--pr-gradient-1);
    border-radius: var(--pr-radius);
    padding: 2rem;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
    box-shadow: var(--pr-shadow-glow);
}

.payroll-hero::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%);
    border-radius: 50%;
}

.payroll-hero::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: -10%;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    border-radius: 50%;
}

.payroll-hero-content {
    position: relative;
    z-index: 2;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.payroll-hero h1 {
    color: #fff;
    font-size: 1.75rem;
    font-weight: 700;
    margin: 0 0 0.25rem;
    letter-spacing: -0.5px;
}

.payroll-hero p {
    color: rgba(255,255,255,0.85);
    margin: 0;
    font-size: 0.95rem;
}

.payroll-hero .btn-hero {
    background: rgba(255,255,255,0.2);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.3);
    color: #fff;
    padding: 0.75rem 1.5rem;
    border-radius: 50px;
    font-weight: 600;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.payroll-hero .btn-hero:hover {
    background: rgba(255,255,255,0.35);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.2);
}

/* Stats Ring Cards */
.pr-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.pr-stat-card {
    background: var(--bg-primary);
    border-radius: var(--pr-radius);
    padding: 1.5rem;
    position: relative;
    overflow: hidden;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
}

.pr-stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--pr-shadow-soft);
}

.pr-stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    border-radius: var(--pr-radius) var(--pr-radius) 0 0;
}

.pr-stat-card.purple::before { background: var(--pr-gradient-1); }
.pr-stat-card.green::before { background: var(--pr-gradient-2); }
.pr-stat-card.pink::before { background: var(--pr-gradient-3); }
.pr-stat-card.blue::before { background: var(--pr-gradient-4); }

.pr-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1rem;
}

.pr-stat-card.purple .pr-stat-icon { background: linear-gradient(135deg, rgba(102,126,234,0.15), rgba(118,75,162,0.15)); color: #667eea; }
.pr-stat-card.green .pr-stat-icon { background: linear-gradient(135deg, rgba(17,153,142,0.15), rgba(56,239,125,0.15)); color: #11998e; }
.pr-stat-card.pink .pr-stat-icon { background: linear-gradient(135deg, rgba(240,147,251,0.15), rgba(245,87,108,0.15)); color: #f5576c; }
.pr-stat-card.blue .pr-stat-icon { background: linear-gradient(135deg, rgba(79,172,254,0.15), rgba(0,242,254,0.15)); color: #4facfe; }

.pr-stat-label {
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-tertiary);
    margin-bottom: 0.25rem;
    font-weight: 500;
}

.pr-stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
    line-height: 1.2;
}

.pr-stat-sub {
    font-size: 0.75rem;
    color: var(--text-tertiary);
    margin-top: 0.25rem;
}

/* Quick Actions - Luxe Cards */
.pr-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.pr-action-card {
    background: var(--bg-primary);
    border-radius: var(--pr-radius);
    padding: 1.75rem;
    text-decoration: none !important;
    border: 1px solid var(--border-color);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    position: relative;
    overflow: hidden;
}

.pr-action-card::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, transparent 50%, rgba(102,126,234,0.03) 100%);
    opacity: 0;
    transition: opacity 0.3s;
}

.pr-action-card:hover {
    transform: translateY(-6px) scale(1.01);
    box-shadow: 0 20px 40px rgba(0,0,0,0.08);
    border-color: transparent;
}

.pr-action-card:hover::after { opacity: 1; }

.pr-action-icon {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.pr-action-card.employees .pr-action-icon { background: var(--pr-gradient-1); color: #fff; }
.pr-action-card.process .pr-action-icon { background: var(--pr-gradient-2); color: #fff; }
.pr-action-card.reports .pr-action-icon { background: var(--pr-gradient-3); color: #fff; }

.pr-action-content h3 {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 0.35rem;
}

.pr-action-content p {
    font-size: 0.85rem;
    color: var(--text-tertiary);
    margin: 0;
    line-height: 1.5;
}

.pr-action-arrow {
    position: absolute;
    right: 1.5rem;
    top: 50%;
    transform: translateY(-50%) translateX(-10px);
    opacity: 0;
    transition: all 0.3s;
    color: var(--primary-color);
}

.pr-action-card:hover .pr-action-arrow {
    opacity: 1;
    transform: translateY(-50%) translateX(0);
}

/* History Table - Clean & Modern */
.pr-history-card {
    background: var(--bg-primary);
    border-radius: var(--pr-radius);
    border: 1px solid var(--border-color);
    overflow: hidden;
}

.pr-history-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.pr-history-header h3 {
    font-size: 1rem;
    font-weight: 600;
    margin: 0;
    color: var(--text-primary);
}

.pr-history-table {
    width: 100%;
    border-collapse: collapse;
}

.pr-history-table th {
    padding: 0.875rem 1rem;
    text-align: left;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.75px;
    color: var(--text-tertiary);
    background: var(--bg-secondary);
}

.pr-history-table td {
    padding: 1rem;
    border-bottom: 1px solid var(--border-light);
    font-size: 0.9rem;
    vertical-align: middle;
}

.pr-history-table tr:last-child td { border-bottom: none; }

.pr-history-table tr:hover td {
    background: var(--bg-secondary);
}

.pr-period-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 500;
    color: var(--text-primary);
}

.pr-period-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: var(--pr-gradient-1);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
}

.pr-status {
    padding: 0.35rem 0.75rem;
    border-radius: 50px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.pr-status.draft { background: rgba(108,117,125,0.1); color: #6c757d; }
.pr-status.submitted { background: rgba(255,193,7,0.15); color: #d39e00; }
.pr-status.approved { background: rgba(23,162,184,0.15); color: #138496; }
.pr-status.paid { background: rgba(40,167,69,0.15); color: #28a745; }

.pr-amount {
    font-weight: 700;
    font-size: 1rem;
    background: var(--pr-gradient-2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.pr-btn-detail {
    padding: 0.5rem 1rem;
    border-radius: 8px;
    font-size: 0.8rem;
    font-weight: 500;
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    color: var(--text-primary);
    transition: all 0.2s;
    text-decoration: none;
}

.pr-btn-detail:hover {
    background: var(--primary-color);
    border-color: var(--primary-color);
    color: #fff;
}

/* Empty State */
.pr-empty {
    text-align: center;
    padding: 3rem 2rem;
}

.pr-empty-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: var(--bg-secondary);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1rem;
    color: var(--text-tertiary);
}

.pr-empty h4 {
    color: var(--text-secondary);
    margin: 0 0 0.5rem;
}

.pr-empty p {
    color: var(--text-tertiary);
    margin: 0;
}

/* Responsive */
@media (max-width: 768px) {
    .payroll-hero { padding: 1.5rem; }
    .payroll-hero h1 { font-size: 1.35rem; }
    .pr-actions-grid { grid-template-columns: 1fr; }
}

/* Page Wrapper - Compact & Centered */
.pr-page-wrapper {
    max-width: 1100px;
    margin: 0 auto;
    padding: 0;
}
</style>

<div class="pr-page-wrapper">
    
    <!-- Hero Header -->
    <div class="payroll-hero fade-in-up">
        <div class="payroll-hero-content">
            <div>
                <h1>Payroll Dashboard</h1>
                <p>Manage employee payroll easily and efficiently</p>
            </div>
            <a href="process.php" class="btn-hero">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                Process New Payroll
            </a>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="pr-stats-grid fade-in-up" style="animation-delay: 0.1s">
        <div class="pr-stat-card purple">
            <div class="pr-stat-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            </div>
            <p class="pr-stat-label">Total Employees</p>
            <h3 class="pr-stat-value"><?php echo $stats['employees']; ?> <small style="font-size: 0.75rem; font-weight: 400;">staff</small></h3>
        </div>
        
        <div class="pr-stat-card green">
            <div class="pr-stat-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
            </div>
            <p class="pr-stat-label">Last Payout</p>
            <h3 class="pr-stat-value"><?php echo $stats['last_period'] ? 'Rp ' . number_format($stats['last_period']['total_net'], 0, ',', '.') : '-'; ?></h3>
            <p class="pr-stat-sub"><?php echo $stats['last_period']['period_label'] ?? 'No period yet'; ?></p>
        </div>
        
        <div class="pr-stat-card pink">
            <div class="pr-stat-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
            </div>
            <p class="pr-stat-label">Total This Year</p>
            <h3 class="pr-stat-value">Rp <?php echo number_format($stats['total_yearly'], 0, ',', '.'); ?></h3>
            <p class="pr-stat-sub"><?php echo date('Y'); ?></p>
        </div>
        
        <div class="pr-stat-card blue">
            <div class="pr-stat-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            </div>
            <p class="pr-stat-label">Pending</p>
            <h3 class="pr-stat-value"><?php echo $stats['pending_count']; ?> <small style="font-size: 0.75rem; font-weight: 400;">periods</small></h3>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="pr-actions-grid fade-in-up" style="animation-delay: 0.2s">
        <a href="employees.php" class="pr-action-card employees">
            <div class="pr-action-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            </div>
            <div class="pr-action-content">
                <h3>Employee Data</h3>
                <p>Manage employee database, positions, base salaries, and bank account information</p>
            </div>
            <span class="pr-action-arrow">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </span>
        </a>
        
        <a href="process.php" class="pr-action-card process">
            <div class="pr-action-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
            </div>
            <div class="pr-action-content">
                <h3>Process Salary</h3>
                <p>Input overtime hours, bonuses, deductions and calculate monthly employee salaries</p>
            </div>
            <span class="pr-action-arrow">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </span>
        </a>
        
        <!-- Payroll Reports menu removed -->

        <a href="attendance.php" class="pr-action-card" style="border-left: 4px solid #f0b429;">
            <div class="pr-action-icon" style="background: linear-gradient(135deg, rgba(240,180,41,0.15), rgba(13,31,60,0.15)); color: #f0b429;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
            </div>
            <div class="pr-action-content">
                <h3>Absensi GPS</h3>
                <p>Kelola absensi karyawan berbasis lokasi GPS dari mobile, lihat rekap harian & bulanan</p>
            </div>
            <span class="pr-action-arrow">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </span>
        </a>
    </div>

    <!-- History -->
    <div class="pr-history-card fade-in-up" style="animation-delay: 0.3s">
        <div class="pr-history-header">
            <h3>Payroll History</h3>
            <!-- View All link removed -->
        </div>
        
        <?php if (empty($periods)): ?>
        <div class="pr-empty">
            <div class="pr-empty-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            </div>
            <h4>No Data Yet</h4>
            <p>Start by creating your first payroll period</p>
        </div>
        <?php else: ?>
        <table class="pr-history-table">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Status</th>
                    <th>Employees</th>
                    <th style="text-align: right;">Total Net Pay</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($periods as $p): ?>
                <tr>
                    <td>
                        <div class="pr-period-badge">
                            <span class="pr-period-icon">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            </span>
                            <?php echo htmlspecialchars($p['period_label']); ?>
                        </div>
                    </td>
                    <td>
                        <span class="pr-status <?php echo $p['status']; ?>"><?php echo ucfirst($p['status']); ?></span>
                    </td>
                    <td><?php echo $p['total_employees']; ?> people</td>
                    <td style="text-align: right;">
                        <span class="pr-amount">Rp <?php echo number_format($p['total_net'], 0, ',', '.'); ?></span>
                    </td>
                    <td style="text-align: right;">
                        <a href="process.php?month=<?php echo $p['period_month']; ?>&year=<?php echo $p['period_year']; ?>" class="pr-btn-detail">Details</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
