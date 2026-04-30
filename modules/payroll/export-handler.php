<?php
// modules/payroll/export-handler.php - HANDLE EXPORTS
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!isModuleEnabled('payroll')) {
    die('Payroll module not enabled');
}

$db = Database::getInstance();
$export_type = $_POST['export_type'] ?? 'custom';
$format = $_POST['format'] ?? 'excel';
$period = $_POST['period'] ?? '';
$department_filter = $_POST['department_filter'] ?? '';

// Parse period (format: MM-YYYY)
$month = date('n');
$year = date('Y');
if ($period && preg_match('/^(\d{1,2})-(\d{4})$/', $period, $matches)) {
    $month = (int)$matches[1];
    $year = (int)$matches[2];
}

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

// ═══════════════════════════════════════════════════════════════
// EXPORT EMPLOYEES
// ═══════════════════════════════════════════════════════════════
if ($export_type === 'employees') {
    $employees = $db->fetchAll("SELECT * FROM payroll_employees WHERE is_active = 1 ORDER BY full_name ASC");

    if ($format === 'excel') {
        exportEmployeesExcel($employees);
    } elseif ($format === 'csv') {
        exportEmployeesCSV($employees);
    } elseif ($format === 'pdf') {
        exportEmployeesPDF($employees);
    }
}

// ═══════════════════════════════════════════════════════════════
// EXPORT SALARY DATA FOR PERIOD
// ═══════════════════════════════════════════════════════════════
elseif ($export_type === 'salary') {
    $period_data = $db->fetchOne("SELECT * FROM payroll_periods WHERE period_month = ? AND period_year = ?", [$month, $year]);

    if (!$period_data) {
        die('Period tidak ditemukan');
    }

    $slips = $db->fetchAll("SELECT ps.*, pe.bank_name, pe.bank_account, pe.department 
                           FROM payroll_slips ps 
                           LEFT JOIN payroll_employees pe ON ps.employee_id = pe.id 
                           WHERE ps.period_id = ? ORDER BY ps.employee_name ASC", [$period_data['id']]);

    if ($format === 'excel') {
        exportSalaryExcel($slips, $period_data, $months);
    } elseif ($format === 'csv') {
        exportSalaryCSV($slips, $period_data, $months);
    } elseif ($format === 'pdf') {
        exportSalaryPDF($slips, $period_data, $months);
    }
}

// ═══════════════════════════════════════════════════════════════
// EXPORT COMPLETE DATA
// ═══════════════════════════════════════════════════════════════
elseif ($export_type === 'complete') {
    $employees = $db->fetchAll("SELECT * FROM payroll_employees WHERE is_active = 1 ORDER BY full_name ASC");
    $period_data = $db->fetchOne("SELECT * FROM payroll_periods WHERE period_month = ? AND period_year = ?", [$month, $year]);
    $slips = [];
    $attendance = [];

    if ($period_data) {
        $slips = $db->fetchAll("SELECT ps.*, pe.bank_name, pe.bank_account, pe.department 
                               FROM payroll_slips ps 
                               LEFT JOIN payroll_employees pe ON ps.employee_id = pe.id 
                               WHERE ps.period_id = ? ORDER BY ps.employee_name ASC", [$period_data['id']]);

        $monthStr = sprintf('%04d-%02d', $year, $month);
        $attendance = $db->fetchAll("SELECT * FROM payroll_attendance 
                                   WHERE DATE_FORMAT(attendance_date, '%Y-%m') = ? 
                                   ORDER BY employee_id, attendance_date ASC", [$monthStr]);
    }

    if ($format === 'excel') {
        exportCompleteExcel($employees, $slips, $attendance, $period_data, $months, $month, $year);
    }
}

// ═══════════════════════════════════════════════════════════════
// EXPORT CUSTOM
// ═══════════════════════════════════════════════════════════════
elseif ($export_type === 'custom') {
    $query = "SELECT pe.* FROM payroll_employees pe WHERE pe.is_active = 1";

    if (!empty($department_filter)) {
        $query .= " AND pe.department = " . $db->getConnection()->quote($department_filter);
    }

    $query .= " ORDER BY pe.full_name ASC";

    $employees = $db->fetchAll($query);

    // Get salary data for period if checked
    $include_salary = isset($_POST['include_salary_details']) && $_POST['include_salary_details'] == '1';
    $slips = [];
    $period_data = null;

    if ($include_salary) {
        $period_data = $db->fetchOne("SELECT * FROM payroll_periods WHERE period_month = ? AND period_year = ?", [$month, $year]);
        if ($period_data) {
            $emp_ids = array_column($employees, 'id');
            if (!empty($emp_ids)) {
                $placeholders = implode(',', array_fill(0, count($emp_ids), '?'));
                $slips = $db->fetchAll(
                    "SELECT ps.* FROM payroll_slips ps 
                                       WHERE ps.period_id = ? AND ps.employee_id IN ($placeholders) 
                                       ORDER BY ps.employee_name ASC",
                    array_merge([$period_data['id']], $emp_ids)
                );
            }
        }
    }

    if ($format === 'excel') {
        exportCustomExcel($employees, $slips, $period_data, $months, $_POST);
    } elseif ($format === 'csv') {
        exportCustomCSV($employees, $slips, $_POST);
    }
}

// ═══════════════════════════════════════════════════════════════
// EXPORT FUNCTIONS
// ═══════════════════════════════════════════════════════════════

function exportEmployeesExcel($employees)
{
    // Simple CSV-to-Excel using CSV format (Excel can open CSV)
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="Data_Karyawan_' . date('Y-m-d_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // BOM for UTF-8
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Headers
    $headers = ['Kode Karyawan', 'Nama Lengkap', 'Jabatan', 'Departemen', 'No. HP', 'Tgl Bergabung', 'Gaji Dasar', 'Bank', 'No. Rekening', 'Finger ID'];
    fputcsv($output, $headers, ',', '"');

    // Data
    foreach ($employees as $emp) {
        fputcsv($output, [
            $emp['employee_code'] ?? '',
            $emp['full_name'] ?? '',
            $emp['position'] ?? '',
            $emp['department'] ?? '',
            $emp['phone'] ?? '',
            $emp['join_date'] ?? '',
            $emp['base_salary'] ?? 0,
            $emp['bank_name'] ?? '',
            $emp['bank_account'] ?? '',
            $emp['finger_id'] ?? ''
        ], ',', '"');
    }

    fclose($output);
    exit;
}

function exportEmployeesCSV($employees)
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="Data_Karyawan_' . date('Y-m-d_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $headers = ['Kode Karyawan', 'Nama Lengkap', 'Jabatan', 'Departemen', 'No. HP', 'Tgl Bergabung', 'Gaji Dasar', 'Bank', 'No. Rekening'];
    fputcsv($output, $headers, ';');

    foreach ($employees as $emp) {
        fputcsv($output, [
            $emp['employee_code'] ?? '',
            $emp['full_name'] ?? '',
            $emp['position'] ?? '',
            $emp['department'] ?? '',
            $emp['phone'] ?? '',
            $emp['join_date'] ?? '',
            $emp['base_salary'] ?? 0,
            $emp['bank_name'] ?? '',
            $emp['bank_account'] ?? ''
        ], ';');
    }

    fclose($output);
    exit;
}

function exportEmployeesPDF($employees)
{
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Data_Karyawan_' . date('Y-m-d_His') . '.pdf"');

    $html = '<h1>Data Karyawan</h1>';
    $html .= '<p>Tanggal Export: ' . date('d-m-Y H:i') . '</p>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width:100%; font-size:10pt; border-collapse:collapse;">';
    $html .= '<tr style="background:#f0f0f0;"><th>No</th><th>Kode</th><th>Nama</th><th>Jabatan</th><th>Dept</th><th>Gaji</th><th>Bank</th></tr>';

    foreach ($employees as $i => $emp) {
        $html .= '<tr>';
        $html .= '<td>' . ($i + 1) . '</td>';
        $html .= '<td>' . htmlspecialchars($emp['employee_code'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($emp['full_name'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($emp['position'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($emp['department'] ?? '') . '</td>';
        $html .= '<td style="text-align:right;">Rp ' . number_format($emp['base_salary'] ?? 0, 0, ',', '.') . '</td>';
        $html .= '<td>' . htmlspecialchars($emp['bank_name'] ?? '') . '</td>';
        $html .= '</tr>';
    }

    $html .= '</table>';

    // Simple PDF generation using TCPDF or similar
    // For now, we'll use HTML2PDF approach
    echo $html;
    exit;
}

function exportSalaryExcel($slips, $period_data, $months)
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="Gaji_' . $months[$period_data['period_month']] . '_' . $period_data['period_year'] . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $headers = ['Nama Karyawan', 'Jabatan', 'Dept', 'Work Hours', 'Gaji Dasar', 'Insentif', 'Tunjangan', 'Bonus', 'Total Earning', 'Potongan', 'Gaji Bersih', 'Bank', 'No. Rekening'];
    fputcsv($output, $headers, ',', '"');

    foreach ($slips as $slip) {
        fputcsv($output, [
            $slip['employee_name'] ?? '',
            $slip['position'] ?? '',
            $slip['department'] ?? '',
            $slip['work_hours'] ?? 0,
            $slip['base_salary'] ?? 0,
            $slip['incentive'] ?? 0,
            $slip['allowance'] ?? 0,
            $slip['bonus'] ?? 0,
            $slip['total_earnings'] ?? 0,
            $slip['total_deductions'] ?? 0,
            $slip['net_salary'] ?? 0,
            $slip['bank_name'] ?? '',
            $slip['bank_account'] ?? ''
        ], ',', '"');
    }

    fclose($output);
    exit;
}

function exportSalaryCSV($slips, $period_data, $months)
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="Gaji_' . $months[$period_data['period_month']] . '_' . $period_data['period_year'] . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $headers = ['Nama Karyawan', 'Jabatan', 'Dept', 'Work Hours', 'Gaji Dasar', 'Insentif', 'Tunjangan', 'Bonus', 'Total Earning', 'Potongan', 'Gaji Bersih'];
    fputcsv($output, $headers, ';');

    foreach ($slips as $slip) {
        fputcsv($output, [
            $slip['employee_name'] ?? '',
            $slip['position'] ?? '',
            $slip['department'] ?? '',
            $slip['work_hours'] ?? 0,
            $slip['base_salary'] ?? 0,
            $slip['incentive'] ?? 0,
            $slip['allowance'] ?? 0,
            $slip['bonus'] ?? 0,
            $slip['total_earnings'] ?? 0,
            $slip['total_deductions'] ?? 0,
            $slip['net_salary'] ?? 0
        ], ';');
    }

    fclose($output);
    exit;
}

function exportSalaryPDF($slips, $period_data, $months)
{
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Gaji_' . $months[$period_data['period_month']] . '_' . $period_data['period_year'] . '.pdf"');

    $html = '<h1>Data Gaji - ' . $months[$period_data['period_month']] . ' ' . $period_data['period_year'] . '</h1>';
    $html .= '<p>Total Gaji Bersih: Rp ' . number_format($period_data['total_net'], 0, ',', '.') . '</p>';
    $html .= '<table border="1" cellpadding="3" cellspacing="0" style="width:100%; font-size:9pt; border-collapse:collapse;">';
    $html .= '<tr style="background:#f0f0f0;"><th>Nama</th><th>Jabatan</th><th>Work Hours</th><th>Base</th><th>Bonus</th><th>Earning</th><th>Potongan</th><th>Bersih</th><th>Bank</th></tr>';

    foreach ($slips as $slip) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($slip['employee_name'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($slip['position'] ?? '') . '</td>';
        $html .= '<td style="text-align:center;">' . $slip['work_hours'] . '</td>';
        $html .= '<td style="text-align:right;">Rp ' . number_format($slip['base_salary'] ?? 0, 0, ',', '.') . '</td>';
        $html .= '<td style="text-align:right;">Rp ' . number_format($slip['bonus'] ?? 0, 0, ',', '.') . '</td>';
        $html .= '<td style="text-align:right;">Rp ' . number_format($slip['total_earnings'] ?? 0, 0, ',', '.') . '</td>';
        $html .= '<td style="text-align:right;">Rp ' . number_format($slip['total_deductions'] ?? 0, 0, ',', '.') . '</td>';
        $html .= '<td style="text-align:right; font-weight:bold;">Rp ' . number_format($slip['net_salary'] ?? 0, 0, ',', '.') . '</td>';
        $html .= '<td>' . htmlspecialchars($slip['bank_name'] ?? '') . '</td>';
        $html .= '</tr>';
    }

    $html .= '</table>';

    echo $html;
    exit;
}

function exportCompleteExcel($employees, $slips, $attendance, $period_data, $months, $month, $year)
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="Payroll_Lengkap_' . $months[$month] . '_' . $year . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Sheet 1: Employees
    $headers = ['Kode Karyawan', 'Nama Lengkap', 'Jabatan', 'Departemen', 'Gaji Dasar', 'Bank', 'No. Rekening'];
    fputcsv($output, $headers, ',', '"');

    foreach ($employees as $emp) {
        fputcsv($output, [
            $emp['employee_code'] ?? '',
            $emp['full_name'] ?? '',
            $emp['position'] ?? '',
            $emp['department'] ?? '',
            $emp['base_salary'] ?? 0,
            $emp['bank_name'] ?? '',
            $emp['bank_account'] ?? ''
        ], ',', '"');
    }

    // Separator
    fputcsv($output, []);
    fputcsv($output, ['=== DATA GAJI ===']);
    fputcsv($output, []);

    // Sheet 2: Salary Data
    $headers2 = ['Nama', 'Jabatan', 'Work Hours', 'Gaji Dasar', 'Insentif', 'Tunjangan', 'Bonus', 'Total', 'Potongan', 'Bersih'];
    fputcsv($output, $headers2, ',', '"');

    foreach ($slips as $slip) {
        fputcsv($output, [
            $slip['employee_name'] ?? '',
            $slip['position'] ?? '',
            $slip['work_hours'] ?? 0,
            $slip['base_salary'] ?? 0,
            $slip['incentive'] ?? 0,
            $slip['allowance'] ?? 0,
            $slip['bonus'] ?? 0,
            $slip['total_earnings'] ?? 0,
            $slip['total_deductions'] ?? 0,
            $slip['net_salary'] ?? 0
        ], ',', '"');
    }

    fclose($output);
    exit;
}

function exportCustomExcel($employees, $slips, $period_data, $months, $post)
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="Payroll_Custom_' . date('Y-m-d_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $headers = [];
    if (isset($post['include_employee_data'])) {
        $headers = array_merge($headers, ['Kode', 'Nama', 'Jabatan', 'Dept', 'Gaji Dasar']);
    }
    if (isset($post['include_salary_details']) && !empty($slips)) {
        $headers = array_merge($headers, ['Work Hours', 'Insentif', 'Tunjangan', 'Bonus', 'Total Earning', 'Potongan', 'Gaji Bersih']);
    }
    if (isset($post['include_bank_info'])) {
        $headers = array_merge($headers, ['Bank', 'No. Rekening']);
    }

    fputcsv($output, $headers, ',', '"');

    // Build slip index for quick lookup
    $slipIndex = [];
    foreach ($slips as $slip) {
        $slipIndex[$slip['employee_id']] = $slip;
    }

    foreach ($employees as $emp) {
        $row = [];
        if (isset($post['include_employee_data'])) {
            $row[] = $emp['employee_code'] ?? '';
            $row[] = $emp['full_name'] ?? '';
            $row[] = $emp['position'] ?? '';
            $row[] = $emp['department'] ?? '';
            $row[] = $emp['base_salary'] ?? 0;
        }
        if (isset($post['include_salary_details'])) {
            $slip = $slipIndex[$emp['id']] ?? null;
            if ($slip) {
                $row[] = $slip['work_hours'] ?? 0;
                $row[] = $slip['incentive'] ?? 0;
                $row[] = $slip['allowance'] ?? 0;
                $row[] = $slip['bonus'] ?? 0;
                $row[] = $slip['total_earnings'] ?? 0;
                $row[] = $slip['total_deductions'] ?? 0;
                $row[] = $slip['net_salary'] ?? 0;
            } else {
                $row = array_merge($row, ['', '', '', '', '', '', '']);
            }
        }
        if (isset($post['include_bank_info'])) {
            $row[] = $emp['bank_name'] ?? '';
            $row[] = $emp['bank_account'] ?? '';
        }
        fputcsv($output, $row, ',', '"');
    }

    fclose($output);
    exit;
}

function exportCustomCSV($employees, $slips, $post)
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="Payroll_Custom_' . date('Y-m-d_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $headers = [];
    if (isset($post['include_employee_data'])) {
        $headers = array_merge($headers, ['Kode', 'Nama', 'Jabatan', 'Dept', 'Gaji Dasar']);
    }
    if (isset($post['include_salary_details']) && !empty($slips)) {
        $headers = array_merge($headers, ['Work Hours', 'Insentif', 'Tunjangan', 'Bonus', 'Total Earning']);
    }

    fputcsv($output, $headers, ';');

    $slipIndex = [];
    foreach ($slips as $slip) {
        $slipIndex[$slip['employee_id']] = $slip;
    }

    foreach ($employees as $emp) {
        $row = [];
        if (isset($post['include_employee_data'])) {
            $row[] = $emp['employee_code'] ?? '';
            $row[] = $emp['full_name'] ?? '';
            $row[] = $emp['position'] ?? '';
            $row[] = $emp['department'] ?? '';
            $row[] = $emp['base_salary'] ?? 0;
        }
        if (isset($post['include_salary_details'])) {
            $slip = $slipIndex[$emp['id']] ?? null;
            if ($slip) {
                $row[] = $slip['work_hours'] ?? 0;
                $row[] = $slip['incentive'] ?? 0;
                $row[] = $slip['allowance'] ?? 0;
                $row[] = $slip['bonus'] ?? 0;
                $row[] = $slip['total_earnings'] ?? 0;
            } else {
                $row = array_merge($row, ['', '', '', '', '']);
            }
        }
        fputcsv($output, $row, ';');
    }

    fclose($output);
    exit;
}
