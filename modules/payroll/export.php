<?php
// modules/payroll/export.php - EXPORT EMPLOYEE & SALARY DATA
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new \Auth();
$auth->requireLogin();

if (!isModuleEnabled('payroll')) {
    include __DIR__ . '/../../includes/header.php';
    echo '<div class="main-content"><div class="alert alert-warning">Modul Gaji (Payroll) belum diaktifkan.</div></div>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

$db = \Database::getInstance();
$pageTitle = 'Export Data Payroll';

// Get available periods for selection
$months = [
    1 => 'Januari',
    2 => 'Februari',
    3 => 'Maret',
    4 => 'April',
    5 => 'Mei',
    6 => 'Juni',
    7 => 'Juli',
    8 => 'Agustus',
    9 => 'September',
    10 => 'Oktober',
    11 => 'November',
    12 => 'Desember'
];

$available_periods = $db->fetchAll("SELECT DISTINCT period_month, period_year FROM payroll_periods ORDER BY period_year DESC, period_month DESC LIMIT 12") ?: [];

// Get all employees
$employees = $db->fetchAll("SELECT id, employee_code, full_name, position, department, base_salary FROM payroll_employees WHERE is_active = 1 ORDER BY full_name ASC");

// Default values
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

include __DIR__ . '/../../includes/header.php';
?>

<style>
    :root {
        --exp-primary: #667eea;
        --exp-primary-dark: #5568d3;
        --exp-green: #10b981;
        --exp-green-dark: #059669;
        --exp-blue: #3b82f6;
        --exp-blue-dark: #2563eb;
        --exp-red: #ef4444;
        --exp-red-dark: #dc2626;
        --exp-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        --exp-shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.06);
        --exp-radius: 12px;
        --exp-text-primary: #1e293b;
        --exp-text-secondary: #475569;
        --exp-text-tertiary: #64748b;
        --exp-border: #e2e8f0;
        --exp-bg-light: #f8fafc;
        --exp-bg-white: #ffffff;
    }

    .exp-container {
        max-width: 1100px;
        margin: 0 auto;
        padding: 0 1rem;
    }

    .exp-header {
        background: linear-gradient(135deg, var(--exp-primary) 0%, #764ba2 100%);
        color: #fff;
        padding: 2.5rem;
        border-radius: var(--exp-radius);
        margin-bottom: 2.5rem;
        position: relative;
        overflow: hidden;
        box-shadow: var(--exp-shadow);
    }

    .exp-header::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -20%;
        width: 400px;
        height: 400px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
        border-radius: 50%;
    }

    .exp-header h1 {
        font-size: 1.75rem;
        font-weight: 800;
        margin: 0 0 0.5rem 0;
        position: relative;
        z-index: 2;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        letter-spacing: -0.3px;
    }

    .exp-header p {
        color: rgba(255, 255, 255, 0.9);
        margin: 0;
        font-size: 0.975rem;
        position: relative;
        z-index: 2;
        font-weight: 500;
    }

    /* Quick Export Options */
    .exp-options {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2.5rem;
    }

    .exp-option-card {
        background: var(--exp-bg-white);
        border: 2px solid var(--exp-border);
        border-radius: var(--exp-radius);
        padding: 1.75rem;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: var(--exp-shadow-sm);
        display: flex;
        flex-direction: column;
    }

    .exp-option-card:hover {
        transform: translateY(-6px);
        box-shadow: var(--exp-shadow);
        border-color: var(--exp-primary);
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    }

    .exp-option-icon {
        width: 56px;
        height: 56px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.75rem;
        margin-bottom: 1rem;
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.2), rgba(118, 75, 162, 0.15));
    }

    .exp-option-card h3 {
        font-size: 1.125rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
        color: var(--exp-text-primary);
        letter-spacing: -0.2px;
    }

    .exp-option-card p {
        font-size: 0.9rem;
        color: var(--exp-text-secondary);
        margin: 0 0 1.25rem 0;
        line-height: 1.6;
        flex-grow: 1;
    }

    .exp-option-btn {
        display: inline-block;
        padding: 0.85rem 1.5rem;
        background: linear-gradient(135deg, var(--exp-primary) 0%, #764ba2 100%);
        color: #fff;
        border: none;
        border-radius: 8px;
        font-weight: 700;
        font-size: 0.95rem;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.3s ease;
        width: 100%;
        text-align: center;
        letter-spacing: 0.3px;
        box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
    }

    .exp-option-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        background: linear-gradient(135deg, #5568d3 0%, #6a3e8f 100%);
    }

    .exp-option-btn:active {
        transform: translateY(0);
    }

    /* Export Form Section */
    .exp-form-section {
        background: var(--exp-bg-white);
        border: 2px solid var(--exp-border);
        border-radius: var(--exp-radius);
        padding: 2rem;
        box-shadow: var(--exp-shadow-sm);
    }

    .exp-form-title {
        font-size: 1.3rem;
        font-weight: 800;
        margin-bottom: 1.75rem;
        color: var(--exp-text-primary);
        display: flex;
        align-items: center;
        gap: 0.6rem;
        letter-spacing: -0.2px;
        padding-bottom: 1rem;
        border-bottom: 2px solid var(--exp-border);
    }

    .exp-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .exp-form-group {
        display: flex;
        flex-direction: column;
    }

    .exp-form-group label {
        font-size: 0.8rem;
        font-weight: 700;
        margin-bottom: 0.65rem;
        color: var(--exp-text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .exp-form-group select,
    .exp-form-group input[type="month"],
    .exp-form-group input[type="text"] {
        padding: 0.85rem 1rem;
        border: 1.5px solid var(--exp-border);
        border-radius: 8px;
        background: var(--exp-bg-light);
        color: var(--exp-text-primary);
        font-size: 0.95rem;
        font-weight: 500;
        transition: all 0.2s;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }

    .exp-form-group select {
        cursor: pointer;
        appearance: none;
        background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: right 0.7rem center;
        background-size: 1.2em 1.2em;
        padding-right: 2.5rem;
    }

    .exp-form-group select:hover,
    .exp-form-group input:hover {
        border-color: var(--exp-primary);
    }

    .exp-form-group select:focus,
    .exp-form-group input:focus {
        outline: none;
        border-color: var(--exp-primary);
        background-color: var(--exp-bg-white);
        box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.12);
    }

    /* Data Selection Checkboxes */
    .exp-data-section-title {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--exp-text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 1rem;
        margin-top: 0;
        padding-bottom: 0.75rem;
        border-bottom: 2px solid var(--exp-border);
    }

    .exp-checkboxes {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
        padding: 1.25rem;
        background: var(--exp-bg-light);
        border-radius: 8px;
        border: 1px solid var(--exp-border);
    }

    .exp-checkbox {
        display: flex;
        align-items: center;
        gap: 0.7rem;
    }

    .exp-checkbox input[type="checkbox"] {
        width: 20px;
        height: 20px;
        cursor: pointer;
        accent-color: var(--exp-primary);
        flex-shrink: 0;
    }

    .exp-checkbox label {
        cursor: pointer;
        font-size: 0.95rem;
        font-weight: 600;
        margin: 0;
        color: var(--exp-text-primary);
        user-select: none;
    }

    .exp-checkbox input[type="checkbox"]:hover {
        opacity: 0.8;
    }

    /* Export Buttons */
    .exp-buttons {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        justify-content: flex-start;
        margin-bottom: 2rem;
    }

    .exp-btn {
        padding: 0.95rem 2rem;
        border: none;
        border-radius: 8px;
        font-weight: 700;
        font-size: 1rem;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.6rem;
        letter-spacing: 0.3px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
        min-width: 160px;
        justify-content: center;
    }

    .exp-btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.18);
    }

    .exp-btn:active {
        transform: translateY(-1px);
    }

    .exp-btn-excel {
        background: linear-gradient(135deg, var(--exp-green) 0%, var(--exp-green-dark) 100%);
        color: #fff;
    }

    .exp-btn-excel:hover {
        background: linear-gradient(135deg, #0da972 0%, #047857 100%);
    }

    .exp-btn-pdf {
        background: linear-gradient(135deg, var(--exp-red) 0%, var(--exp-red-dark) 100%);
        color: #fff;
    }

    .exp-btn-pdf:hover {
        background: linear-gradient(135deg, #ea2d2d 0%, #c41d1d 100%);
    }

    .exp-btn-csv {
        background: linear-gradient(135deg, var(--exp-blue) 0%, var(--exp-blue-dark) 100%);
        color: #fff;
    }

    .exp-btn-csv:hover {
        background: linear-gradient(135deg, #3b7fef 0%, #2455d4 100%);
    }

    /* Features Section */
    .exp-features {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        padding: 1.5rem;
        background: linear-gradient(135deg, var(--exp-bg-light) 0%, #f1f5f9 100%);
        border-radius: 8px;
        border: 1px solid var(--exp-border);
    }

    .exp-feature-item {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        font-size: 0.9rem;
        color: var(--exp-text-secondary);
        font-weight: 600;
    }

    .exp-feature-icon {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        background: var(--exp-primary);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.9rem;
        flex-shrink: 0;
    }

    @media (max-width: 768px) {
        .exp-container {
            padding: 0 0.75rem;
        }

        .exp-header {
            padding: 1.75rem;
            margin-bottom: 1.75rem;
        }

        .exp-header h1 {
            font-size: 1.5rem;
        }

        .exp-options {
            grid-template-columns: 1fr;
            gap: 1rem;
            margin-bottom: 1.75rem;
        }

        .exp-form-section {
            padding: 1.5rem;
        }

        .exp-buttons {
            flex-direction: column;
        }

        .exp-btn {
            width: 100%;
        }

        .exp-checkboxes {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="main-content">
    <div class="exp-container">
        <!-- Header -->
        <div class="exp-header">
            <h1>📊 Export Data Payroll</h1>
            <p>Ekspor data karyawan dan gaji dalam berbagai format (Excel, CSV, PDF)</p>
        </div>

        <!-- Quick Export Options -->
        <div class="exp-options">
            <!-- Employee Master Data -->
            <div class="exp-option-card">
                <div class="exp-option-icon">👥</div>
                <h3>Data Master Karyawan</h3>
                <p>Ekspor data lengkap semua karyawan aktif termasuk informasi kontak, gaji dasar, dan rekening bank</p>
                <button type="button" class="exp-option-btn" onclick="submitQuickExport('employees')">📥 Unduh Data Karyawan</button>
            </div>

            <!-- Salary Data for Period -->
            <div class="exp-option-card">
                <div class="exp-option-icon">💰</div>
                <h3>Data Gaji Periode</h3>
                <p>Ekspor data gaji lengkap untuk bulan tertentu, termasuk earning, deduction, dan gaji bersih</p>
                <button type="button" class="exp-option-btn" onclick="submitQuickExport('salary')">📥 Unduh Data Gaji</button>
            </div>

            <!-- Complete Export -->
            <div class="exp-option-card">
                <div class="exp-option-icon">📦</div>
                <h3>Data Lengkap Periode</h3>
                <p>Ekspor semua data karyawan + gaji + absensi untuk periode tertentu dalam satu file</p>
                <button type="button" class="exp-option-btn" onclick="submitQuickExport('complete')">📥 Unduh Data Lengkap</button>
            </div>
        </div>

        <!-- Advanced Export Form -->
        <div class="exp-form-section">
            <div class="exp-form-title">⚙️ Pengaturan Export Kustom</div>

            <form id="exportForm" method="POST" action="export-handler.php">
                <!-- Period Selection -->
                <div class="exp-form-grid">
                    <div class="exp-form-group">
                        <label for="period">📅 Pilih Periode</label>
                        <select name="period" id="period" required>
                            <option value="">-- Pilih Bulan & Tahun --</option>
                            <?php foreach ($available_periods as $p): ?>
                                <option value="<?php echo $p['period_month'] . '-' . $p['period_year']; ?>"
                                    <?php echo ($p['period_month'] == $selected_month && $p['period_year'] == $selected_year) ? 'selected' : ''; ?>>
                                    <?php echo $months[$p['period_month']] . ' ' . $p['period_year']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="exp-form-group">
                        <label for="department_filter">🏢 Filter Departemen (Opsional)</label>
                        <select name="department_filter" id="department_filter">
                            <option value="">-- Semua Departemen --</option>
                            <?php
                            $depts = $db->fetchAll("SELECT DISTINCT department FROM payroll_employees WHERE is_active = 1 AND department IS NOT NULL ORDER BY department");
                            foreach ($depts as $d):
                            ?>
                                <option value="<?php echo htmlspecialchars($d['department']); ?>">
                                    <?php echo htmlspecialchars($d['department']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Data Selection -->
                <label class="exp-data-section-title">📋 Pilih Data yang Akan Diekspor</label>
                <div class="exp-checkboxes">
                    <div class="exp-checkbox">
                        <input type="checkbox" name="include_employee_data" id="inc_emp" value="1" checked>
                        <label for="inc_emp">👤 Data Karyawan</label>
                    </div>
                    <div class="exp-checkbox">
                        <input type="checkbox" name="include_salary_details" id="inc_sal" value="1" checked>
                        <label for="inc_sal">💵 Detail Gaji</label>
                    </div>
                    <div class="exp-checkbox">
                        <input type="checkbox" name="include_bank_info" id="inc_bank" value="1" checked>
                        <label for="inc_bank">🏦 Info Bank</label>
                    </div>
                </div>

                <!-- Export Format Buttons -->
                <input type="hidden" name="export_type" value="custom">
                <div style="margin-bottom: 1.5rem;">
                    <label class="exp-data-section-title" style="margin-bottom: 0.75rem;">📤 Pilih Format Export</label>
                </div>
                <div class="exp-buttons">
                    <button type="submit" name="format" value="excel" class="exp-btn exp-btn-excel">
                        <span>📊</span> Export ke Excel
                    </button>
                    <button type="submit" name="format" value="csv" class="exp-btn exp-btn-csv">
                        <span>📄</span> Export ke CSV
                    </button>
                    <button type="submit" name="format" value="pdf" class="exp-btn exp-btn-pdf">
                        <span>📕</span> Export ke PDF
                    </button>
                </div>

                <!-- Features Info -->
                <div class="exp-features">
                    <div class="exp-feature-item">
                        <div class="exp-feature-icon">✓</div>
                        <span>Format Excel (.xlsx)</span>
                    </div>
                    <div class="exp-feature-item">
                        <div class="exp-feature-icon">✓</div>
                        <span>Format CSV Terkompresi</span>
                    </div>
                    <div class="exp-feature-item">
                        <div class="exp-feature-icon">✓</div>
                        <span>Format PDF Siap Cetak</span>
                    </div>
                    <div class="exp-feature-item">
                        <div class="exp-feature-icon">✓</div>
                        <span>Filter Departemen</span>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function submitQuickExport(exportType) {
        const periodSelect = document.getElementById('period');
        const period = periodSelect ? periodSelect.value : '';

        if ((exportType === 'salary' || exportType === 'complete') && !period) {
            alert('Pilih periode terlebih dahulu sebelum export data gaji atau data lengkap.');
            if (periodSelect) {
                periodSelect.focus();
            }
            return;
        }

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'export-handler.php';

        const exportTypeInput = document.createElement('input');
        exportTypeInput.type = 'hidden';
        exportTypeInput.name = 'export_type';
        exportTypeInput.value = exportType;

        const formatInput = document.createElement('input');
        formatInput.type = 'hidden';
        formatInput.name = 'format';
        formatInput.value = 'excel';

        form.appendChild(exportTypeInput);
        form.appendChild(formatInput);

        if (period) {
            const periodInput = document.createElement('input');
            periodInput.type = 'hidden';
            periodInput.name = 'period';
            periodInput.value = period;
            form.appendChild(periodInput);
        }

        document.body.appendChild(form);
        form.submit();
    }
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>