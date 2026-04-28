<?php
// Quick debug: Check Rizal's OT records
$pdo = new PDO("mysql:host=localhost;dbname=adf_db_v2", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "\n=== RIZAL OT DEBUG ===\n\n";

// 1. Find Rizal
echo "1. Finding Rizal...\n";
$stmt = $pdo->query("SELECT id, employee_code, full_name, position FROM payroll_employees WHERE full_name LIKE '%Rizal%' OR full_name LIKE '%rizal%' LIMIT 5");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($employees)) {
    echo "❌ Rizal tidak ditemukan. Coba cari nama lain.\n";
    $stmt = $pdo->query("SELECT id, employee_code, full_name FROM payroll_employees LIMIT 20");
    echo "\nDaftar karyawan:\n";
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
        echo "  - {$e['full_name']} ({$e['employee_code']})\n";
    }
    exit;
}

$rizal = $employees[0];
$rizalId = $rizal['id'];
echo "✅ Ditemukan: {$rizal['full_name']} (ID: $rizalId, Kode: {$rizal['employee_code']})\n\n";

// 2. Recent overtime requests
echo "2. Overtime Requests (30 hari terakhir)...\n";
$stmt = $pdo->prepare("SELECT id, overtime_date, reason, status, approved_by, approved_at FROM overtime_requests WHERE employee_id = ? AND overtime_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY overtime_date DESC LIMIT 20");
$stmt->execute([$rizalId]);
$otReqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($otReqs)) {
    echo "  (tidak ada)\n";
} else {
    foreach ($otReqs as $ot) {
        $status = $ot['status'] === 'approved' ? '✅' : ($ot['status'] === 'pending' ? '⏳' : '❌');
        echo "  $status {$ot['overtime_date']} {$ot['status']} - {$ot['reason']}\n";
        if ($ot['status'] === 'approved') {
            echo "     → Approved by {$ot['approved_by']} at {$ot['approved_at']}\n";
        }
    }
}

// 3. Recent attendance
echo "\n3. Attendance Records (30 hari terakhir)...\n";
$stmt = $pdo->prepare("SELECT id, attendance_date, check_in_time, check_out_time, work_hours, overtime_hours, status FROM payroll_attendance WHERE employee_id = ? AND attendance_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY attendance_date DESC LIMIT 20");
$stmt->execute([$rizalId]);
$atts = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($atts)) {
    echo "  (tidak ada)\n";
} else {
    foreach ($atts as $a) {
        $wh = (float)$a['work_hours'];
        $ot = (float)($a['overtime_hours'] ?? 0);
        echo "  {$a['attendance_date']}: {$a['check_in_time']} → {$a['check_out_time']} | work={$wh}j, OT={$ot}j, status={$a['status']}\n";
    }
}

// 4. Recent payroll slips
echo "\n4. Payroll Slips (3 bulan terakhir)...\n";
$stmt = $pdo->prepare("
  SELECT ps.id, ps.period_id, ps.work_hours, ps.overtime_hours, ps.actual_base, ps.overtime_amount, ps.net_salary, ps.hours_locked, pp.period_label
  FROM payroll_slips ps
  LEFT JOIN payroll_periods pp ON ps.period_id = pp.id
  WHERE ps.employee_id = ? AND pp.period_year >= YEAR(NOW()) - 1
  ORDER BY ps.id DESC LIMIT 10
");
$stmt->execute([$rizalId]);
$slips = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($slips)) {
    echo "  (tidak ada)\n";
} else {
    foreach ($slips as $s) {
        $locked = $s['hours_locked'] ? '🔒' : '🔓';
        echo "  $locked {$s['period_label']}: work={$s['work_hours']}j, OT={$s['overtime_hours']}j, OT_amount=Rp " . number_format($s['overtime_amount'], 0) . ", net=Rp " . number_format($s['net_salary'], 0) . "\n";
    }
}

echo "\n=== END DEBUG ===\n";
?>
