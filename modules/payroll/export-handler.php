<?php
// modules/payroll/export-handler.php - HANDLE EXPORTS
define('APP_ACCESS', true);
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new \Auth();
$auth->requireLogin();

if (!isModuleEnabled('payroll')) {
    die('Payroll module not enabled');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . BASE_URL . '/modules/payroll/export.php');
    exit;
}

$db = \Database::getInstance();
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

if (!in_array($export_type, ['employees', 'salary', 'complete', 'custom'], true)) {
    http_response_code(400);
    die('Tipe export tidak valid');
}

if (!in_array($format, ['excel', 'csv', 'pdf'], true)) {
    http_response_code(400);
    die('Format export tidak valid');
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

function renderPdfDownload($filename, $title, $bodyHtml)
{
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
        . 'body{font-family:dejavusans,Arial,sans-serif;color:#111827;font-size:10pt;}'
        . 'h1{font-size:18pt;margin:0 0 8px;color:#1f2937;}'
        . 'p{margin:0 0 10px;}'
        . 'table{width:100%;border-collapse:collapse;margin-top:12px;}'
        . 'th,td{border:1px solid #cbd5e1;padding:6px 8px;vertical-align:top;}'
        . 'th{background:#e2e8f0;text-align:left;}'
        . '.text-right{text-align:right;}'
        . '.text-center{text-align:center;}'
        . '.muted{color:#64748b;}'
        . '</style></head><body>'
        . '<h1>' . htmlspecialchars($title) . '</h1>'
        . $bodyHtml
        . '</body></html>';

    $pdf = new \Spipu\Html2Pdf\Html2Pdf('P', 'A4', 'en', true, 'UTF-8', [10, 10, 10, 10]);
    $pdf->writeHTML($html);
    $pdf->output($filename, 'D');
    exit;
}

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
    } elseif ($format === 'pdf') {
        exportCompletePDF($employees, $slips, $attendance, $period_data, $months, $month, $year);
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
    } elseif ($format === 'pdf') {
        exportCustomPDF($employees, $slips, $period_data, $months, $_POST);
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
    $body = '<p class="muted">Tanggal Export: ' . date('d-m-Y H:i') . '</p>';
    $body .= '<table><tr><th>No</th><th>Kode</th><th>Nama</th><th>Jabatan</th><th>Dept</th><th>Gaji</th><th>Bank</th></tr>';

    foreach ($employees as $i => $emp) {
        $body .= '<tr>';
        $body .= '<td class="text-center">' . ($i + 1) . '</td>';
        $body .= '<td>' . htmlspecialchars($emp['employee_code'] ?? '') . '</td>';
        $body .= '<td>' . htmlspecialchars($emp['full_name'] ?? '') . '</td>';
        $body .= '<td>' . htmlspecialchars($emp['position'] ?? '') . '</td>';
        $body .= '<td>' . htmlspecialchars($emp['department'] ?? '') . '</td>';
        $body .= '<td class="text-right">Rp ' . number_format($emp['base_salary'] ?? 0, 0, ',', '.') . '</td>';
        $body .= '<td>' . htmlspecialchars($emp['bank_name'] ?? '') . '</td>';
        $body .= '</tr>';
    }

    $body .= '</table>';
    renderPdfDownload('Data_Karyawan_' . date('Y-m-d_His') . '.pdf', 'Data Karyawan', $body);
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
    $body = '<p class="muted">Total Gaji Bersih: Rp ' . number_format($period_data['total_net'] ?? 0, 0, ',', '.') . '</p>';
    $body .= '<table><tr><th>Nama</th><th>Jabatan</th><th>Work Hours</th><th>Base</th><th>Bonus</th><th>Earning</th><th>Potongan</th><th>Bersih</th><th>Bank</th></tr>';

    foreach ($slips as $slip) {
        $body .= '<tr>';
        $body .= '<td>' . htmlspecialchars($slip['employee_name'] ?? '') . '</td>';
        $body .= '<td>' . htmlspecialchars($slip['position'] ?? '') . '</td>';
        $body .= '<td class="text-center">' . number_format((float)($slip['work_hours'] ?? 0), 2, ',', '.') . '</td>';
        $body .= '<td class="text-right">Rp ' . number_format($slip['base_salary'] ?? 0, 0, ',', '.') . '</td>';
        $body .= '<td class="text-right">Rp ' . number_format($slip['bonus'] ?? 0, 0, ',', '.') . '</td>';
        $body .= '<td class="text-right">Rp ' . number_format($slip['total_earnings'] ?? 0, 0, ',', '.') . '</td>';
        $body .= '<td class="text-right">Rp ' . number_format($slip['total_deductions'] ?? 0, 0, ',', '.') . '</td>';
        $body .= '<td class="text-right">Rp ' . number_format($slip['net_salary'] ?? 0, 0, ',', '.') . '</td>';
        $body .= '<td>' . htmlspecialchars($slip['bank_name'] ?? '') . '</td>';
        $body .= '</tr>';
    }

    $body .= '</table>';
    renderPdfDownload('Gaji_' . $months[$period_data['period_month']] . '_' . $period_data['period_year'] . '.pdf', 'Data Gaji - ' . $months[$period_data['period_month']] . ' ' . $period_data['period_year'], $body);
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

function exportCompletePDF($employees, $slips, $attendance, $period_data, $months, $month, $year)
{
    $body = '<p class="muted">Periode: ' . $months[$month] . ' ' . $year . '</p>';
    $body .= '<table><tr><th colspan="7">Data Karyawan</th></tr>';
    $body .= '<tr><th>Kode</th><th>Nama</th><th>Jabatan</th><th>Departemen</th><th>Gaji Dasar</th><th>Bank</th><th>No. Rekening</th></tr>';
    foreach ($employees as $emp) {
        $body .= '<tr>'
            . '<td>' . htmlspecialchars($emp['employee_code'] ?? '') . '</td>'
            . '<td>' . htmlspecialchars($emp['full_name'] ?? '') . '</td>'
            . '<td>' . htmlspecialchars($emp['position'] ?? '') . '</td>'
            . '<td>' . htmlspecialchars($emp['department'] ?? '') . '</td>'
            . '<td class="text-right">Rp ' . number_format($emp['base_salary'] ?? 0, 0, ',', '.') . '</td>'
            . '<td>' . htmlspecialchars($emp['bank_name'] ?? '') . '</td>'
            . '<td>' . htmlspecialchars($emp['bank_account'] ?? '') . '</td>'
            . '</tr>';
    }
    $body .= '</table><div style="height:12px"></div>';

    $body .= '<table><tr><th colspan="9">Data Gaji</th></tr>';
    $body .= '<tr><th>Nama</th><th>Jabatan</th><th>Work Hours</th><th>Base</th><th>Insentif</th><th>Tunjangan</th><th>Bonus</th><th>Bersih</th><th>Bank</th></tr>';
    foreach ($slips as $slip) {
        $body .= '<tr>'
            . '<td>' . htmlspecialchars($slip['employee_name'] ?? '') . '</td>'
            . '<td>' . htmlspecialchars($slip['position'] ?? '') . '</td>'
            . '<td class="text-center">' . number_format((float)($slip['work_hours'] ?? 0), 2, ',', '.') . '</td>'
            . '<td class="text-right">Rp ' . number_format($slip['base_salary'] ?? 0, 0, ',', '.') . '</td>'
            . '<td class="text-right">Rp ' . number_format($slip['incentive'] ?? 0, 0, ',', '.') . '</td>'
            . '<td class="text-right">Rp ' . number_format($slip['allowance'] ?? 0, 0, ',', '.') . '</td>'
            . '<td class="text-right">Rp ' . number_format($slip['bonus'] ?? 0, 0, ',', '.') . '</td>'
            . '<td class="text-right">Rp ' . number_format($slip['net_salary'] ?? 0, 0, ',', '.') . '</td>'
            . '<td>' . htmlspecialchars($slip['bank_name'] ?? '') . '</td>'
            . '</tr>';
    }
    $body .= '</table>';

    if (!empty($attendance)) {
        $body .= '<div style="height:12px"></div>';
        $body .= '<table><tr><th colspan="5">Ringkasan Absensi</th></tr>';
        $body .= '<tr><th>Employee ID</th><th>Tanggal</th><th>Status</th><th>Work Hours</th><th>Catatan</th></tr>';
        foreach ($attendance as $row) {
            $body .= '<tr>'
                . '<td>' . htmlspecialchars((string)($row['employee_id'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string)($row['attendance_date'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string)($row['status'] ?? '')) . '</td>'
                . '<td class="text-center">' . number_format((float)($row['work_hours'] ?? 0), 2, ',', '.') . '</td>'
                . '<td>' . htmlspecialchars((string)($row['notes'] ?? '')) . '</td>'
                . '</tr>';
        }
        $body .= '</table>';
    }

    renderPdfDownload('Payroll_Lengkap_' . $months[$month] . '_' . $year . '.pdf', 'Payroll Lengkap - ' . $months[$month] . ' ' . $year, $body);
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
        $headers = array_merge($headers, ['Work Hours', 'Insentif', 'Tunjangan', 'Bonus', 'Total Earning', 'Potongan', 'Gaji Bersih']);
    }
    if (isset($post['include_bank_info'])) {
        $headers = array_merge($headers, ['Bank', 'No. Rekening']);
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
        fputcsv($output, $row, ';');
    }

    fclose($output);
    exit;
}

function exportCustomPDF($employees, $slips, $period_data, $months, $post)
{
    $title = 'Payroll Custom';
    if (!empty($period_data)) {
        $title = 'Payroll Custom - ' . $months[$period_data['period_month']] . ' ' . $period_data['period_year'];
    }

    $body = '';
    if (!empty($period_data)) {
        $body .= '<p class="muted">Periode: ' . $months[$period_data['period_month']] . ' ' . $period_data['period_year'] . '</p>';
    }

    $headers = [];
    if (isset($post['include_employee_data'])) {
        $headers[] = 'Kode';
        $headers[] = 'Nama';
        $headers[] = 'Jabatan';
        $headers[] = 'Dept';
        $headers[] = 'Gaji Dasar';
    }
    if (isset($post['include_salary_details']) && !empty($slips)) {
        $headers[] = 'Work Hours';
        $headers[] = 'Insentif';
        $headers[] = 'Tunjangan';
        $headers[] = 'Bonus';
        $headers[] = 'Total Earning';
        $headers[] = 'Potongan';
        $headers[] = 'Gaji Bersih';
    }
    if (isset($post['include_bank_info'])) {
        $headers[] = 'Bank';
        $headers[] = 'No. Rekening';
    }

    $body .= '<table><tr>';
    foreach ($headers as $header) {
        $body .= '<th>' . htmlspecialchars($header) . '</th>';
    }
    $body .= '</tr>';

    $slipIndex = [];
    foreach ($slips as $slip) {
        $slipIndex[$slip['employee_id']] = $slip;
    }

    foreach ($employees as $emp) {
        $body .= '<tr>';
        if (isset($post['include_employee_data'])) {
            $body .= '<td>' . htmlspecialchars($emp['employee_code'] ?? '') . '</td>';
            $body .= '<td>' . htmlspecialchars($emp['full_name'] ?? '') . '</td>';
            $body .= '<td>' . htmlspecialchars($emp['position'] ?? '') . '</td>';
            $body .= '<td>' . htmlspecialchars($emp['department'] ?? '') . '</td>';
            $body .= '<td class="text-right">Rp ' . number_format($emp['base_salary'] ?? 0, 0, ',', '.') . '</td>';
        }

        if (isset($post['include_salary_details'])) {
            $slip = $slipIndex[$emp['id']] ?? null;
            if ($slip) {
                $body .= '<td class="text-center">' . number_format((float)($slip['work_hours'] ?? 0), 2, ',', '.') . '</td>';
                $body .= '<td class="text-right">Rp ' . number_format($slip['incentive'] ?? 0, 0, ',', '.') . '</td>';
                $body .= '<td class="text-right">Rp ' . number_format($slip['allowance'] ?? 0, 0, ',', '.') . '</td>';
                $body .= '<td class="text-right">Rp ' . number_format($slip['bonus'] ?? 0, 0, ',', '.') . '</td>';
                $body .= '<td class="text-right">Rp ' . number_format($slip['total_earnings'] ?? 0, 0, ',', '.') . '</td>';
                $body .= '<td class="text-right">Rp ' . number_format($slip['total_deductions'] ?? 0, 0, ',', '.') . '</td>';
                $body .= '<td class="text-right">Rp ' . number_format($slip['net_salary'] ?? 0, 0, ',', '.') . '</td>';
            } else {
                $body .= '<td colspan="7" class="text-center muted">Tidak ada data gaji untuk periode ini</td>';
            }
        }

        if (isset($post['include_bank_info'])) {
            $body .= '<td>' . htmlspecialchars($emp['bank_name'] ?? '') . '</td>';
            $body .= '<td>' . htmlspecialchars($emp['bank_account'] ?? '') . '</td>';
        }

        $body .= '</tr>';
    }

    $body .= '</table>';
    renderPdfDownload('Payroll_Custom_' . date('Y-m-d_His') . '.pdf', $title, $body);
}
