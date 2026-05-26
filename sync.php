<?php
// sync v9.3 - includes staff-portal, print-slip

/**
 * Diagnostic + Sync tool
 * URL: https://adfsystem.online/sync.php?token=adf-deploy-2025-secure
 * Add &action=check to check DB values
 * Add &action=sync to sync files from GitHub
 * Add &action=fix_logo&url=CLOUDINARY_URL to fix logo in DB
 */
$token = $_GET['token'] ?? '';
if (!hash_equals('adf-deploy-2025-secure', $token)) {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');
$action = $_GET['action'] ?? 'check';

if ($action === 'check') {
    echo "=== DB Diagnostic ===\n\n";

    // Connect to narayana_hotel DB
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=adfb2574_narayana_hotel;charset=utf8mb4', 'adfb2574_adfsystem', '@Nnoc2025');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo "DB Connected: adfb2574_narayana_hotel\n\n";

        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('web_logo', 'web_favicon', 'web_hero_background')");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            echo $row['setting_key'] . " = " . $row['setting_value'] . "\n";
        }
        if (empty($rows)) {
            echo "No web_logo/web_favicon settings found in DB!\n";
        }
    } catch (Exception $e) {
        echo "DB Error: " . $e->getMessage() . "\n";
    }

    echo "\n--- File Check ---\n";
    $webRoot = '/home/adfb2574/public_html/narayanakarimunjawa.com';
    $checkPaths = [
        $webRoot . '/web_logo.png',
        $webRoot . '/uploads/logo/',
        $webRoot . '/uploads/',
        $webRoot . '/includes/header.php',
    ];
    foreach ($checkPaths as $p) {
        echo $p . " : " . (file_exists($p) ? (is_dir($p) ? 'DIR EXISTS' : 'FILE EXISTS (' . filesize($p) . ' bytes)') : 'NOT FOUND') . "\n";
    }

    // List uploads dir if exists
    $uploadsDir = $webRoot . '/uploads/';
    if (is_dir($uploadsDir)) {
        echo "\nContents of $uploadsDir:\n";
        $items = scandir($uploadsDir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $uploadsDir . $item;
            echo "  $item " . (is_dir($full) ? '[DIR]' : '(' . filesize($full) . ' bytes)') . "\n";
        }
    }
    exit;
}

if ($action === 'fix_logo') {
    $url = $_GET['url'] ?? '';
    if (empty($url)) {
        die("Provide &url=CLOUDINARY_URL_HERE");
    }
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=adfb2574_narayana_hotel;charset=utf8mb4', 'adfb2574_adfsystem', '@Nnoc2025');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES ('web_logo', ?, 'text', 'Website Logo') ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$url, $url]);
        echo "Logo updated to: $url\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    exit;
}

if ($action === 'verify') {
    echo "=== Verify Deployed Files ===\n\n";

    $webRoot = '/home/adfb2574/public_html/narayanakarimunjawa.com';

    // Check header.php content
    $headerFile = $webRoot . '/includes/header.php';
    echo "Header file: $headerFile\n";
    echo "Exists: " . (file_exists($headerFile) ? 'YES' : 'NO') . "\n";
    if (file_exists($headerFile)) {
        echo "Size: " . filesize($headerFile) . " bytes\n";
        echo "Modified: " . date('Y-m-d H:i:s', filemtime($headerFile)) . "\n\n";

        $content = file_get_contents($headerFile);

        // Check for assetUrl function
        echo "Contains 'function assetUrl': " . (strpos($content, 'function assetUrl') !== false ? 'YES' : 'NO') . "\n";
        echo "Contains 'preg_match.*https': " . (strpos($content, "preg_match('#^https?://#i'") !== false ? 'YES' : 'NO') . "\n";
        echo "Contains 'uploads/logo': " . (strpos($content, 'uploads/logo') !== false ? 'YES' : 'NO') . "\n";
        echo "Contains old 'BASE_URL./. htmlspecialchars(logoPath)': " . (strpos($content, 'BASE_URL ?>/<?= htmlspecialchars($logoPath)') !== false ? 'YES (OLD!)' : 'NO (GOOD)') . "\n\n";

        // Extract the img tag line
        foreach (explode("\n", $content) as $i => $line) {
            if (strpos($line, 'brand-img') !== false) {
                echo "Line " . ($i + 1) . ": " . trim($line) . "\n";
            }
            if (strpos($line, 'assetUrl') !== false) {
                echo "Line " . ($i + 1) . ": " . trim($line) . "\n";
            }
        }
    }

    // Check config.php
    echo "\n--- Config Check ---\n";
    $cfgFile = $webRoot . '/config/config.php';
    echo "Config: $cfgFile - " . (file_exists($cfgFile) ? 'EXISTS (' . filesize($cfgFile) . ' bytes)' : 'NOT FOUND') . "\n";

    // Try to load config and test the actual logo URL generation
    echo "\n--- Live Test ---\n";
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=adfb2574_narayana_hotel;charset=utf8mb4', 'adfb2574_adfsystem', '@Nnoc2025');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'web_logo'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $logoPath = $row['setting_value'] ?? '';
        echo "web_logo from DB: '$logoPath'\n";

        // Simulate assetUrl
        if (preg_match('#^https?://#i', $logoPath)) {
            echo "Result: Direct URL (Cloudinary) → $logoPath\n";
        } else {
            echo "Result: Local path → /uploads/logo/$logoPath\n";
        }
    } catch (Exception $e) {
        echo "DB Error: " . $e->getMessage() . "\n";
    }

    exit;
}

if ($action === 'debug_site') {
    echo "=== Finding Real Document Root ===\n\n";

    // Check all possible locations
    $locations = [
        '/home/adfb2574/public_html/narayanakarimunjawa.com',
        '/home/adfb2574/narayanakarimunjawa.com',
        '/home/adfb2574/narayanakarimunjawa',
        '/home/adfb2574/public_html/narayanakarimunjawa',
    ];

    foreach ($locations as $loc) {
        echo "$loc:\n";
        if (file_exists($loc)) {
            echo "  EXISTS - " . (is_dir($loc) ? 'DIRECTORY' : 'FILE') . "\n";
            if (is_link($loc)) {
                echo "  SYMLINK → " . readlink($loc) . "\n";
            }
            if (is_dir($loc)) {
                $items = @scandir($loc);
                if ($items) {
                    echo "  Contents: ";
                    $show = [];
                    foreach ($items as $item) {
                        if ($item === '.' || $item === '..') continue;
                        $full = $loc . '/' . $item;
                        $show[] = $item . (is_dir($full) ? '/' : ' (' . filesize($full) . 'b)');
                    }
                    echo implode(', ', $show) . "\n";
                }
                // Check for index.php and includes/header.php
                if (file_exists($loc . '/index.php')) {
                    echo "  index.php: EXISTS (" . filesize($loc . '/index.php') . " bytes)\n";
                }
                if (file_exists($loc . '/includes/header.php')) {
                    $hc = file_get_contents($loc . '/includes/header.php');
                    echo "  includes/header.php: EXISTS (" . strlen($hc) . " bytes)\n";
                    echo "    Has assetUrl: " . (strpos($hc, 'function assetUrl') !== false ? 'YES' : 'NO') . "\n";
                    echo "    Has old BASE_URL pattern: " . (strpos($hc, 'BASE_URL ?>/<?= htmlspecialchars($logoPath)') !== false ? 'YES (OLD!)' : 'NO (GOOD)') . "\n";
                }
                if (file_exists($loc . '/config/config.php')) {
                    echo "  config/config.php: EXISTS (" . filesize($loc . '/config/config.php') . " bytes)\n";
                }
            }
        } else {
            echo "  NOT FOUND\n";
        }
        echo "\n";
    }

    // Also create _debug.php in ALL locations that have index.php
    $debugContent = '<?php echo "SERVED FROM: " . __DIR__;';
    foreach ($locations as $loc) {
        if (file_exists($loc . '/index.php')) {
            @file_put_contents($loc . '/_debug.php', $debugContent);
            echo "Created _debug.php in $loc\n";
        }
    }

    echo "\nNow try: https://narayanakarimunjawa.com/_debug.php\n";
    exit;
}

if ($action === 'clearcache') {
    echo "=== PHP OPcache Clear ===\n\n";

    if (function_exists('opcache_reset')) {
        opcache_reset();
        echo "opcache_reset() called successfully!\n";
    } else {
        echo "opcache_reset() not available\n";
    }

    // Also invalidate specific files
    $files = [
        '/home/adfb2574/public_html/narayanakarimunjawa.com/includes/header.php',
        '/home/adfb2574/public_html/narayanakarimunjawa.com/index.php',
        '/home/adfb2574/public_html/narayanakarimunjawa.com/config/config.php',
    ];
    foreach ($files as $f) {
        if (function_exists('opcache_invalidate') && file_exists($f)) {
            $result = opcache_invalidate($f, true);
            echo "opcache_invalidate($f): " . ($result ? 'OK' : 'FAILED') . "\n";
        }
    }

    echo "\nOPcache status:\n";
    if (function_exists('opcache_get_status')) {
        $status = opcache_get_status(false);
        if ($status) {
            echo "Enabled: " . ($status['opcache_enabled'] ? 'YES' : 'NO') . "\n";
            echo "Cached scripts: " . ($status['opcache_statistics']['num_cached_scripts'] ?? 'N/A') . "\n";
            echo "Cache hits: " . ($status['opcache_statistics']['hits'] ?? 'N/A') . "\n";
        } else {
            echo "Could not get status (restricted)\n";
        }
    } else {
        echo "opcache_get_status not available\n";
    }

    echo "\nDone! Now refresh narayanakarimunjawa.com\n";
    exit;
}

// ===== RESTORE FEBRUARY DATA =====
if ($action === 'restore_feb') {
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=adfb2574_narayana_hotel;charset=utf8mb4', 'adfb2574_adfsystem', '@Nnoc2025');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Original Feb values from before the accidental recalc wipe
        $febData = [
            ['id' => 2, 'wh' => 200, 'ot' => 0],
            ['id' => 3, 'wh' => 200, 'ot' => 0],
            ['id' => 4, 'wh' => 200, 'ot' => 0],
            ['id' => 5, 'wh' => 100, 'ot' => 0],
            ['id' => 6, 'wh' => 112, 'ot' => 0],
            ['id' => 7, 'wh' => 200, 'ot' => 0],
            ['id' => 8, 'wh' => 201, 'ot' => 0],
            ['id' => 9, 'wh' => 100, 'ot' => 0],
            ['id' => 10, 'wh' => 100, 'ot' => 0],
            ['id' => 11, 'wh' => 200, 'ot' => 0],
            ['id' => 12, 'wh' => 200, 'ot' => 0],
            ['id' => 13, 'wh' => 112, 'ot' => 0],
            ['id' => 14, 'wh' => 100, 'ot' => 0],
            ['id' => 15, 'wh' => 200, 'ot' => 0],
            ['id' => 16, 'wh' => 200, 'ot' => 0],
        ];
        foreach ($febData as $d) {
            $stmt = $pdo->prepare("SELECT base_salary FROM payroll_slips WHERE id = ?");
            $stmt->execute([$d['id']]);
            $slip = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$slip) continue;
            $base = (float)$slip['base_salary'];
            $hr = $base / 200;
            $actual = ($d['wh'] >= 200) ? $base : round($d['wh'] * $hr, 2);
            $otAmt = round($d['ot'] * $hr, 2);
            $earn = $actual + $otAmt;
            $net = $earn;
            $pdo->prepare("UPDATE payroll_slips SET work_hours=?, overtime_hours=?, actual_base=?, overtime_amount=?, total_earnings=?, net_salary=? WHERE id=?")
                ->execute([$d['wh'], $d['ot'], $actual, $otAmt, $earn, $net, $d['id']]);
            echo "Restored slip {$d['id']}: wh={$d['wh']}, actual=$actual, net=$net\n";
        }
        echo "\nFeb restore complete!\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    exit;
}

// ===== PAYROLL DEBUG =====
if ($action === 'payroll_debug') {
    echo "=== PAYROLL DEBUG ===\n\n";
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=adfb2574_narayana_hotel;charset=utf8mb4', 'adfb2574_adfsystem', '@Nnoc2025');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $m = isset($_GET['m']) ? (int)$_GET['m'] : (int)date('n');
        $y = isset($_GET['y']) ? (int)$_GET['y'] : (int)date('Y');
        $monthStr = sprintf('%04d-%02d', $y, $m);
        echo "Month: $monthStr\n\n";

        // 1. Attendance data per employee
        echo "--- Attendance Data (payroll_attendance) ---\n";
        $stmt = $pdo->prepare("SELECT pa.employee_id, pe.full_name, COUNT(*) as days, SUM(pa.work_hours) as total_wh, GROUP_CONCAT(CONCAT(pa.attendance_date,'=',COALESCE(pa.work_hours,0),'h') ORDER BY pa.attendance_date SEPARATOR ', ') as detail FROM payroll_attendance pa LEFT JOIN payroll_employees pe ON pe.id = pa.employee_id WHERE DATE_FORMAT(pa.attendance_date, '%Y-%m') = ? GROUP BY pa.employee_id ORDER BY pe.full_name");
        $stmt->execute([$monthStr]);
        $attData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($attData as $a) {
            echo "[{$a['employee_id']}] {$a['full_name']}: {$a['days']} days, total={$a['total_wh']}h\n";
            echo "  {$a['detail']}\n\n";
        }

        // 2. Payroll slips
        echo "\n--- Payroll Slips (payroll_slips) ---\n";
        $stmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE period_month = ? AND period_year = ?");
        $stmt->execute([$m, $y]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($period) {
            echo "Period: {$period['period_label']} ID={$period['id']} Status={$period['status']}\n\n";
            $stmt = $pdo->prepare("SELECT * FROM payroll_slips WHERE period_id = ? ORDER BY employee_name");
            $stmt->execute([$period['id']]);
            $slips = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($slips as $s) {
                $otAmt = $s['overtime_amount'] ?? 0;
                $inc = $s['incentive'] ?? 0;
                $alw = $s['allowance'] ?? 0;
                $um = $s['uang_makan'] ?? 0;
                $bon = $s['bonus'] ?? 0;
                $oth = $s['other_income'] ?? 0;
                $earn = $s['total_earnings'] ?? 0;
                $ded = $s['total_deductions'] ?? 0;
                echo "[Slip {$s['id']}] {$s['employee_name']} (emp:{$s['employee_id']}): wh={$s['work_hours']}, ot={$s['overtime_hours']}, locked={$s['hours_locked']}, base={$s['base_salary']}, actual={$s['actual_base']}, ot_amt=$otAmt, inc=$inc, alw=$alw, um=$um, bon=$bon, oth=$oth, earn=$earn, ded=$ded, net={$s['net_salary']}\n";
            }
        } else {
            echo "No period for $m/$y\n";
        }

        // 3. Version check
        echo "\n\n--- process.php on server ---\n";
        $pf = '/home/adfb2574/public_html/modules/payroll/process.php';
        if (file_exists($pf)) {
            echo "Size: " . filesize($pf) . " bytes\n";
            echo "Modified: " . date('Y-m-d H:i:s', filemtime($pf)) . "\n";
            $first5 = implode("\n", array_slice(file($pf), 0, 5));
            echo "First 5 lines:\n$first5\n";
        } else {
            echo "NOT FOUND\n";
        }

        // 4. Test getAttendanceHours manually
        echo "\n\n--- Manual getAttendanceHours test ---\n";
        $testEmp = isset($_GET['emp']) ? (int)$_GET['emp'] : 10; // Default Dela
        echo "Testing employee ID: $testEmp\n";
        $stmt = $pdo->prepare("SELECT work_hours, shift_1_hours, shift_2_hours, check_in_time, check_out_time, scan_3, scan_4, attendance_date FROM payroll_attendance WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ? AND (work_hours > 0 OR check_in_time IS NOT NULL)");
        $stmt->execute([$testEmp, $monthStr]);
        $attRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Found " . count($attRows) . " attendance rows\n";
        $totalH = 0;
        $totalOT = 0;
        $daysW = 0;
        foreach ($attRows as $r) {
            $wh = (float)$r['work_hours'];
            if ($wh <= 0) {
                $s1 = 0;
                $s2 = 0;
                if (!empty($r['shift_1_hours']) && (float)$r['shift_1_hours'] > 0) $s1 = (float)$r['shift_1_hours'];
                elseif (!empty($r['check_in_time']) && !empty($r['check_out_time'])) {
                    $t1 = strtotime($r['check_in_time']);
                    $t2 = strtotime($r['check_out_time']);
                    if ($t2 > $t1) $s1 = round(($t2 - $t1) / 3600, 2);
                }
                if (!empty($r['scan_3']) && !empty($r['scan_4'])) {
                    $t3 = strtotime($r['scan_3']);
                    $t4 = strtotime($r['scan_4']);
                    if ($t4 > $t3) $s2 = round(($t4 - $t3) / 3600, 2);
                }
                $wh = round($s1 + $s2, 2);
                if ($wh <= 0) {
                    echo "  {$r['attendance_date']}: SKIP (no hours)\n";
                    continue;
                }
            }
            $daysW++;
            $totalH += $wh;
            if ($wh > 8) {
                $ot = $wh - 8;
                $totalOT += floor($ot / 0.75) * 0.75;
            }
            echo "  {$r['attendance_date']}: wh=$wh (running total=$totalH)\n";
        }
        echo "RESULT: work_hours=" . round($totalH, 2) . ", overtime=" . round($totalOT, 2) . ", days=$daysW\n";

        // 5. Test syncSlipsWithAttendance manually
        if ($period && isset($_GET['do_sync'])) {
            echo "\n\n--- RUNNING FULL RECALC ---\n";
            $stmt = $pdo->prepare("SELECT s.* FROM payroll_slips s WHERE s.period_id = ?");
            $stmt->execute([$period['id']]);
            $allSlips = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($allSlips as $slip) {
                $eid = $slip['employee_id'];
                $incentive = isset($slip['incentive']) ? (float)$slip['incentive'] : 0;
                $allowance = isset($slip['allowance']) ? (float)$slip['allowance'] : 0;
                $uang_makan = isset($slip['uang_makan']) ? (float)$slip['uang_makan'] : 0;
                $bonus = isset($slip['bonus']) ? (float)$slip['bonus'] : 0;
                $other = isset($slip['other_income']) ? (float)$slip['other_income'] : 0;
                $loan = isset($slip['deduction_loan']) ? (float)$slip['deduction_loan'] : 0;
                $absence = isset($slip['deduction_absence']) ? (float)$slip['deduction_absence'] : 0;
                $tax = isset($slip['deduction_tax']) ? (float)$slip['deduction_tax'] : 0;
                $bpjs = isset($slip['deduction_bpjs']) ? (float)$slip['deduction_bpjs'] : 0;
                $dedOther = isset($slip['deduction_other']) ? (float)$slip['deduction_other'] : 0;
                // Get attendance total
                $stmt2 = $pdo->prepare("SELECT work_hours, shift_1_hours, shift_2_hours, check_in_time, check_out_time, scan_3, scan_4 FROM payroll_attendance WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?");
                $stmt2->execute([$eid, $monthStr]);
                $rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                $twh = 0;
                $tot = 0;
                foreach ($rows2 as $r2) {
                    $wh = (float)$r2['work_hours'];
                    if ($wh <= 0) {
                        $s1 = 0;
                        $s2 = 0;
                        if (!empty($r2['shift_1_hours']) && (float)$r2['shift_1_hours'] > 0) $s1 = (float)$r2['shift_1_hours'];
                        elseif (!empty($r2['check_in_time']) && !empty($r2['check_out_time'])) {
                            $t1 = strtotime($r2['check_in_time']);
                            $t2 = strtotime($r2['check_out_time']);
                            if ($t2 > $t1) $s1 = round(($t2 - $t1) / 3600, 2);
                        }
                        if (!empty($r2['scan_3']) && !empty($r2['scan_4'])) {
                            $t3 = strtotime($r2['scan_3']);
                            $t4 = strtotime($r2['scan_4']);
                            if ($t4 > $t3) $s2 = round(($t4 - $t3) / 3600, 2);
                        }
                        $wh = round($s1 + $s2, 2);
                    }
                    if ($wh > 0) {
                        $twh += $wh;
                        if ($wh > 8) $tot += floor(($wh - 8) / 0.75) * 0.75;
                    }
                }
                $twh = round($twh, 2);
                $tot = round($tot, 2);

                // Skip if no attendance data exists (don't overwrite manual entries)
                if (count($rows2) === 0 || ($twh <= 0 && (float)$slip['work_hours'] > 0)) {
                    echo "Slip {$slip['id']} {$slip['employee_name']}: SKIP (no attendance, keeping wh={$slip['work_hours']})\n";
                    // Still recalc net from existing values
                    $twh = (float)$slip['work_hours'];
                    $tot = (float)$slip['overtime_hours'];
                    $baseSalary = (float)$slip['base_salary'];
                    $hourlyRate = $baseSalary / 200;
                    $actualBase = ($twh >= 200) ? $baseSalary : round($twh * $hourlyRate, 2);
                    $otRate = $hourlyRate;
                    $otAmount = round($tot * $otRate, 2);
                    $totalEarn = $actualBase + $otAmount + $incentive + $allowance + $uang_makan + $bonus + $other;
                    $totalDed = $loan + $absence + $tax + $bpjs + $dedOther;
                    $netSalary = $totalEarn - $totalDed;
                    $stmt3 = $pdo->prepare("UPDATE payroll_slips SET actual_base=?, overtime_rate=?, overtime_amount=?, total_earnings=?, total_deductions=?, net_salary=? WHERE id=?");
                    $stmt3->execute([$actualBase, $otRate, $otAmount, $totalEarn, $totalDed, $netSalary, $slip['id']]);
                    echo "  -> recalc net=$netSalary (from existing wh=$twh)\n";
                    continue;
                }

                $baseSalary = (float)$slip['base_salary'];
                $hourlyRate = $baseSalary / 200;
                $actualBase = ($twh >= 200) ? $baseSalary : round($twh * $hourlyRate, 2);
                $otRate = $hourlyRate;
                $otAmount = round($tot * $otRate, 2);
                $totalEarn = $actualBase + $otAmount + $incentive + $allowance + $uang_makan + $bonus + $other;
                $totalDed = $loan + $absence + $tax + $bpjs + $dedOther;
                $netSalary = $totalEarn - $totalDed;

                $stmt3 = $pdo->prepare("UPDATE payroll_slips SET work_hours=?, overtime_hours=?, actual_base=?, overtime_rate=?, overtime_amount=?, total_earnings=?, total_deductions=?, net_salary=? WHERE id=?");
                $stmt3->execute([$twh, $tot, $actualBase, $otRate, $otAmount, $totalEarn, $totalDed, $netSalary, $slip['id']]);

                echo "Slip {$slip['id']} {$slip['employee_name']}: wh=$twh, ot=$tot, actual=$actualBase, earn=$totalEarn, net=$netSalary\n";
            }
            // Update period totals
            $stmt4 = $pdo->prepare("UPDATE payroll_periods p LEFT JOIN (SELECT period_id, SUM(total_earnings) as gross, SUM(total_deductions) as ded, SUM(net_salary) as net, COUNT(id) as cnt FROM payroll_slips WHERE period_id = ?) s ON p.id = s.period_id SET p.total_gross = s.gross, p.total_deductions = s.ded, p.total_net = s.net, p.total_employees = s.cnt WHERE p.id = ?");
            $stmt4->execute([$period['id'], $period['id']]);
            echo "\nFULL RECALC DONE!\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    exit;
}

// ===== SYNC ACTION =====
$dir = dirname(__FILE__);

echo "=== ADF Sync (GitHub API) ===\n";
echo "Dir: $dir\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

$head = @file_get_contents($dir . '/.git/refs/heads/main');
echo "Current commit: " . ($head ? trim($head) : 'unknown') . "\n\n";

// Files to sync from GitHub
$repo = 'ratn050-oss/adf_system';
$branch = 'main';
$filesToSync = [
    'sync.php',
    '.htaccess',
    'modules/payroll/process.php',
    'modules/payroll/staff-portal.php',
    'modules/payroll/staff-api.php',
    'modules/payroll/print-slip.php',
    'modules/payroll/attendance.php',
    'modules/payroll/sw.js',
    'api/fingerprint-webhook.php',
    'includes/header.php',
    'includes/footer.php',
    'assets/icons/favicon.svg',
    'modules/frontdesk/rental-motor.php',
    'modules/frontdesk/reservasi.php',
    'modules/frontdesk/calendar.php',
    'modules/frontdesk/invoice.php',
    'api/create-reservation.php',
    'api/update-reservation.php',
    'api/get-notifications.php',
    'modules/frontdesk/edit-booking.php',
    'developer/staff-accounts.php',
    'modules/cashbook/index.php',
    'modules/cashbook/export-excel.php',
    'config/vapid.php',
    'includes/PushNotificationHelper.php',
    'api/push-subscription.php',
    'api/send-notification.php',
    'api/checkin-guest.php',
    'api/checkout-guest.php',
    'assets/js/notifications.js',
    'sw.js',
    'webhook-deploy.php',
    'composer.json',
    'composer.lock',
    'tools/generate-vapid-keys.php',
    'emergency-deploy.php',
    'install-vendor.php',
    'website/public/index.php',
    'website/public/rooms.php',
    'website/public/activities.php',
    'website/public/destinations.php',
    'website/public/includes/header.php',
    'website/public/assets/css/style.css',
    '.cpanel.yml',
];

// Also deploy website files directly to narayanakarimunjawa.com
// The REAL website runs from public/ subdirectory!
$webBase = '/home/adfb2574/public_html/narayanakarimunjawa.com';
$websiteDeploy = [
    // Deploy to public/ (where website actually runs from)
    ['src' => 'website/public/index.php', 'dest' => $webBase . '/public/index.php'],
    ['src' => 'website/public/rooms.php', 'dest' => $webBase . '/public/rooms.php'],
    ['src' => 'website/public/activities.php', 'dest' => $webBase . '/public/activities.php'],
    ['src' => 'website/public/destinations.php', 'dest' => $webBase . '/public/destinations.php'],
    ['src' => 'website/public/includes/header.php', 'dest' => $webBase . '/public/includes/header.php'],
    ['src' => 'website/public/assets/css/style.css', 'dest' => $webBase . '/public/assets/css/style.css'],
    // Also deploy to root level (for .cpanel.yml compatibility)
    ['src' => 'website/public/index.php', 'dest' => $webBase . '/index.php'],
    ['src' => 'website/public/rooms.php', 'dest' => $webBase . '/rooms.php'],
    ['src' => 'website/public/activities.php', 'dest' => $webBase . '/activities.php'],
    ['src' => 'website/public/destinations.php', 'dest' => $webBase . '/destinations.php'],
    ['src' => 'website/public/includes/header.php', 'dest' => $webBase . '/includes/header.php'],
    ['src' => 'website/public/assets/css/style.css', 'dest' => $webBase . '/assets/css/style.css'],
];

$success = 0;
$failed = 0;
$ctx = stream_context_create(['http' => [
    'timeout' => 30,
    'user_agent' => 'ADF-Sync/1.0',
]]);

// Get latest commit SHA to bypass CDN cache
$shaApiUrl = "https://api.github.com/repos/$repo/git/ref/heads/$branch";
$shaCtx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'ADF-Sync/1.0', 'header' => "Accept: application/vnd.github.v3+json\r\n"]]);
$shaData = @file_get_contents($shaApiUrl, false, $shaCtx);
$latestSha = $branch; // fallback to branch name
if ($shaData) {
    $shaRef = json_decode($shaData, true);
    if (!empty($shaRef['object']['sha'])) {
        $latestSha = $shaRef['object']['sha'];
        echo "Using SHA: " . substr($latestSha, 0, 7) . " (bypassing CDN cache)\n\n";
    }
}

foreach ($filesToSync as $file) {
    $url = "https://raw.githubusercontent.com/$repo/$latestSha/$file";
    echo "Syncing: $file ... ";

    $content = @file_get_contents($url, false, $ctx);
    if ($content === false) {
        echo "FAILED (download error)\n";
        $failed++;
        continue;
    }

    $localPath = $dir . '/' . $file;
    $localDir = dirname($localPath);
    if (!is_dir($localDir)) {
        @mkdir($localDir, 0755, true);
    }

    if (@file_put_contents($localPath, $content) !== false) {
        echo "OK (" . strlen($content) . " bytes)\n";
        $success++;
    } else {
        echo "FAILED (write error)\n";
        $failed++;
    }
}

// Deploy website files directly to narayanakarimunjawa.com
echo "\n--- Deploying to narayanakarimunjawa.com ---\n";
foreach ($websiteDeploy as $entry) {
    $srcFile = $entry['src'];
    $destPath = $entry['dest'];
    $srcPath = $dir . '/' . $srcFile;
    if (!file_exists($srcPath)) {
        echo "Skip $srcFile (not downloaded)\n";
        continue;
    }
    $destDir = dirname($destPath);
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0755, true);
    }
    $content = file_get_contents($srcPath);
    if (@file_put_contents($destPath, $content) !== false) {
        echo "Deployed: $destPath (" . strlen($content) . " bytes)\n";
    } else {
        echo "FAILED to deploy: $destPath\n";
    }
    // Clear PHP OPcache for this file
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($destPath, true);
        echo "  → OPcache cleared for $destPath\n";
    }
}

// Also clear opcache for all PHP files in website
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "\n✅ PHP OPcache fully reset!\n";
}

// Fix git state so cPanel can deploy again
echo "\n--- Fixing git state ---\n";
$gitDir = $dir . '/.git';

// Update git ref to latest
$apiUrl = "https://api.github.com/repos/$repo/git/ref/heads/$branch";
$apiCtx = stream_context_create(['http' => [
    'timeout' => 10,
    'user_agent' => 'ADF-Sync/1.0',
    'header' => "Accept: application/vnd.github.v3+json\r\n",
]]);
$refData = @file_get_contents($apiUrl, false, $apiCtx);
if ($refData) {
    $ref = json_decode($refData, true);
    $latestSha = $ref['object']['sha'] ?? '';
    if ($latestSha) {
        @file_put_contents($gitDir . '/refs/heads/main', $latestSha . "\n");
        echo "Updated git ref: " . substr($latestSha, 0, 7) . "\n";
    }
}

echo "\n=== Result: $success OK, $failed failed ===\n";
echo ($failed === 0) ? "✅ SYNC SUCCESS\n" : "⚠️ SOME FILES FAILED\n";
echo "\n⚠️ HAPUS FILE sync.php SETELAH SELESAI!";
