<?php
/**
 * FRONT DESK - HK ROOM ALLOCATION
 * Prioritas otomatis: B2B -> OD -> VD -> VC
 * Bisa input nama staff HK manual + override pembagian manual.
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$pageTitle = 'Pembagian HK Room';
$message = '';
$error = '';

function ensureHkTables($db)
{
    $db->query("CREATE TABLE IF NOT EXISTS frontdesk_hk_staff (
        id INT AUTO_INCREMENT PRIMARY KEY,
        staff_name VARCHAR(100) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_staff_name (staff_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS frontdesk_hk_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        assignment_date DATE NOT NULL,
        room_id INT NOT NULL,
        room_number VARCHAR(30) NOT NULL,
        task_code ENUM('B2B','OD','VD','VC') NOT NULL,
        priority_order TINYINT NOT NULL,
        assigned_staff VARCHAR(100) NOT NULL,
        is_manual TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_daily_room_task (assignment_date, room_id, task_code),
        KEY idx_daily (assignment_date),
        KEY idx_staff (assigned_staff)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function parseStaffNames($raw)
{
    $parts = preg_split('/[\r\n,;]+/', (string)$raw);
    $names = [];
    foreach ($parts as $name) {
        $name = trim($name);
        if ($name === '') {
            continue;
        }
        $name = preg_replace('/\s+/', ' ', $name);
        $name = mb_substr($name, 0, 100);
        $names[$name] = true;
    }
    return array_keys($names);
}

function buildHkTasks($db, $workDate)
{
    $nextDate = date('Y-m-d', strtotime($workDate . ' +1 day'));

    $rows = $db->fetchAll("SELECT
            r.id,
            r.room_number,
            r.status,
            COALESCE(rt.type_name, 'Standard') as room_type,
            g.guest_name as inhouse_guest,
            (
                SELECT g2.guest_name
                FROM bookings b2
                LEFT JOIN guests g2 ON b2.guest_id = g2.id
                WHERE b2.room_id = r.id
                  AND DATE(b2.check_in_date) = ?
                  AND b2.status IN ('confirmed','pending')
                LIMIT 1
            ) as next_guest,
            b.id as checked_in_booking_id
        FROM rooms r
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        LEFT JOIN bookings b ON b.room_id = r.id AND b.status = 'checked_in'
        LEFT JOIN guests g ON b.guest_id = g.id
        ORDER BY r.room_number ASC", [$nextDate]) ?: [];

    $tasks = [];
    foreach ($rows as $r) {
        $status = (string)($r['status'] ?? '');
        $hasCheckedIn = !empty($r['checked_in_booking_id']);
        $hasNextGuest = !empty($r['next_guest']);

        if ($status === 'maintenance' || $status === 'blocked') {
            continue;
        }

        $taskCode = null;
        $priority = 99;
        $label = '';

        if ($hasCheckedIn && $hasNextGuest) {
            $taskCode = 'B2B';
            $priority = 1;
            $label = 'Back to Back';
        } elseif ($hasCheckedIn) {
            $taskCode = 'OD';
            $priority = 2;
            $label = 'Occupied / In-House';
        } elseif ($status === 'cleaning') {
            $taskCode = 'VD';
            $priority = 3;
            $label = 'Vacant Dirty';
        } elseif ($status === 'available') {
            $taskCode = 'VC';
            $priority = 4;
            $label = 'Vacant Clean';
        }

        if ($taskCode === null) {
            continue;
        }

        $tasks[] = [
            'key' => (int)$r['id'] . '|' . $taskCode,
            'room_id' => (int)$r['id'],
            'room_number' => (string)$r['room_number'],
            'room_type' => (string)$r['room_type'],
            'task_code' => $taskCode,
            'task_label' => $label,
            'priority_order' => $priority,
            'inhouse_guest' => (string)($r['inhouse_guest'] ?? ''),
            'next_guest' => (string)($r['next_guest'] ?? ''),
            'room_status' => $status
        ];
    }

    usort($tasks, function ($a, $b) {
        if ($a['priority_order'] !== $b['priority_order']) {
            return $a['priority_order'] <=> $b['priority_order'];
        }
        return strnatcmp($a['room_number'], $b['room_number']);
    });

    return $tasks;
}

function autoAssignFair($tasks, $staffNames, $seedCounts = [])
{
    $result = [];
    $counts = [];

    foreach ($staffNames as $name) {
        $counts[$name] = (int)($seedCounts[$name] ?? 0);
    }

    if (empty($staffNames)) {
        return ['assignments' => $result, 'counts' => $counts];
    }

    $staffIndex = array_values($staffNames);
    $cursor = 0;

    foreach ($tasks as $task) {
        $minCount = min($counts);
        $candidateIndexes = [];
        foreach ($staffIndex as $idx => $name) {
            if ($counts[$name] === $minCount) {
                $candidateIndexes[] = $idx;
            }
        }

        $pickIdx = $candidateIndexes[0];
        foreach ($candidateIndexes as $ci) {
            if ($ci >= $cursor) {
                $pickIdx = $ci;
                break;
            }
        }

        $pickedStaff = $staffIndex[$pickIdx];
        $result[$task['key']] = $pickedStaff;
        $counts[$pickedStaff]++;
        $cursor = ($pickIdx + 1) % count($staffIndex);
    }

    return ['assignments' => $result, 'counts' => $counts];
}

ensureHkTables($db);

$workDate = $_POST['work_date'] ?? $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
    $workDate = date('Y-m-d');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_staff') {
        $names = parseStaffNames($_POST['staff_names'] ?? '');
        if (empty($names)) {
            $error = 'Nama staff HK belum diisi.';
        } else {
            try {
                $db->beginTransaction();
                $db->query("UPDATE frontdesk_hk_staff SET is_active = 0");
                foreach ($names as $name) {
                    $db->query(
                        "INSERT INTO frontdesk_hk_staff (staff_name, is_active) VALUES (?, 1)
                         ON DUPLICATE KEY UPDATE is_active = 1, updated_at = NOW()",
                        [$name]
                    );
                }
                $db->commit();
                $message = 'Daftar staff HK berhasil disimpan.';
            } catch (Exception $e) {
                $db->rollback();
                $error = 'Gagal menyimpan staff HK: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'reset_auto') {
        try {
            $db->query("DELETE FROM frontdesk_hk_assignments WHERE assignment_date = ?", [$workDate]);
            $message = 'Pembagian manual di-reset. Sistem kembali ke pembagian otomatis.';
        } catch (Exception $e) {
            $error = 'Gagal reset pembagian: ' . $e->getMessage();
        }
    }

    if ($action === 'save_plan' || $action === 'generate_auto') {
        try {
            $tasksNow = buildHkTasks($db, $workDate);
            $staffRowsNow = $db->fetchAll("SELECT staff_name FROM frontdesk_hk_staff WHERE is_active = 1 ORDER BY staff_name ASC") ?: [];
            $staffNamesNow = array_map(fn($r) => $r['staff_name'], $staffRowsNow);

            if (empty($staffNamesNow)) {
                throw new Exception('Daftar staff HK kosong. Simpan nama staff dulu.');
            }

            $assignMap = [];
            if ($action === 'save_plan') {
                $incoming = $_POST['assigned'] ?? [];
                foreach ($tasksNow as $task) {
                    $key = $task['key'];
                    $assigned = trim((string)($incoming[$key] ?? ''));
                    if ($assigned !== '' && in_array($assigned, $staffNamesNow, true)) {
                        $assignMap[$key] = $assigned;
                    }
                }
            } else {
                $auto = autoAssignFair($tasksNow, $staffNamesNow);
                $assignMap = $auto['assignments'];
            }

            $db->beginTransaction();
            $db->query("DELETE FROM frontdesk_hk_assignments WHERE assignment_date = ?", [$workDate]);

            foreach ($tasksNow as $task) {
                $key = $task['key'];
                $assigned = $assignMap[$key] ?? '';
                if ($assigned === '') {
                    continue;
                }
                $db->query(
                    "INSERT INTO frontdesk_hk_assignments
                    (assignment_date, room_id, room_number, task_code, priority_order, assigned_staff, is_manual, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $workDate,
                        $task['room_id'],
                        $task['room_number'],
                        $task['task_code'],
                        $task['priority_order'],
                        $assigned,
                        $action === 'save_plan' ? 1 : 0,
                        $currentUser['id'] ?? null
                    ]
                );
            }

            $db->commit();
            $message = $action === 'save_plan'
                ? 'Pembagian HK manual berhasil disimpan.'
                : 'Pembagian HK otomatis berhasil dibuat ulang.';
        } catch (Exception $e) {
            if (method_exists($db, 'rollback')) {
                $db->rollback();
            }
            $error = 'Gagal menyimpan pembagian: ' . $e->getMessage();
        }
    }
}

$staffRows = $db->fetchAll("SELECT staff_name FROM frontdesk_hk_staff WHERE is_active = 1 ORDER BY staff_name ASC") ?: [];
$staffNames = array_map(fn($r) => $r['staff_name'], $staffRows);
$staffText = implode("\n", $staffNames);

$tasks = buildHkTasks($db, $workDate);

$savedRows = $db->fetchAll(
    "SELECT room_id, task_code, assigned_staff, is_manual
     FROM frontdesk_hk_assignments
     WHERE assignment_date = ?",
    [$workDate]
) ?: [];

$savedMap = [];
foreach ($savedRows as $row) {
    $savedMap[(int)$row['room_id'] . '|' . $row['task_code']] = [
        'assigned_staff' => $row['assigned_staff'],
        'is_manual' => (int)$row['is_manual'] === 1
    ];
}

$assignmentMap = [];
$manualMap = [];
$seedCounts = array_fill_keys($staffNames, 0);

foreach ($tasks as $task) {
    $k = $task['key'];
    if (!isset($savedMap[$k])) {
        continue;
    }
    $assigned = $savedMap[$k]['assigned_staff'];
    if (!in_array($assigned, $staffNames, true)) {
        continue;
    }
    $assignmentMap[$k] = $assigned;
    $manualMap[$k] = $savedMap[$k]['is_manual'];
    if (isset($seedCounts[$assigned])) {
        $seedCounts[$assigned]++;
    }
}

$unassignedTasks = array_values(array_filter($tasks, function ($t) use ($assignmentMap) {
    return !isset($assignmentMap[$t['key']]);
}));

$autoResult = autoAssignFair($unassignedTasks, $staffNames, $seedCounts);
foreach ($autoResult['assignments'] as $key => $staffName) {
    $assignmentMap[$key] = $staffName;
    $manualMap[$key] = false;
}

$staffLoad = array_fill_keys($staffNames, 0);
foreach ($tasks as $task) {
    $assigned = $assignmentMap[$task['key']] ?? '';
    if ($assigned !== '' && isset($staffLoad[$assigned])) {
        $staffLoad[$assigned]++;
    }
}

$categoryCount = ['B2B' => 0, 'OD' => 0, 'VD' => 0, 'VC' => 0];
foreach ($tasks as $task) {
    if (isset($categoryCount[$task['task_code']])) {
        $categoryCount[$task['task_code']]++;
    }
}

include '../../includes/header.php';
?>

<style>
    .hk-wrap {
        max-width: 1300px;
        margin: 0 auto;
    }

    .hk-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
        margin-bottom: 1rem;
    }

    .hk-title {
        margin: 0;
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--text-primary);
    }

    .hk-sub {
        font-size: 0.82rem;
        color: var(--text-muted);
        margin-top: 0.2rem;
    }

    .hk-grid {
        display: grid;
        grid-template-columns: 340px 1fr;
        gap: 1rem;
    }

    .hk-card {
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 12px;
        padding: 1rem;
    }

    .hk-card h3 {
        margin: 0 0 0.75rem 0;
        font-size: 0.95rem;
    }

    .hk-input,
    .hk-select,
    .hk-date {
        width: 100%;
        border: 1px solid var(--bg-tertiary);
        border-radius: 8px;
        padding: 0.55rem 0.65rem;
        background: var(--bg-primary);
        color: var(--text-primary);
    }

    .hk-input {
        min-height: 140px;
        resize: vertical;
    }

    .hk-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        margin-top: 0.75rem;
    }

    .hk-btn {
        border: none;
        border-radius: 8px;
        padding: 0.5rem 0.8rem;
        font-weight: 700;
        font-size: 0.8rem;
        cursor: pointer;
    }

    .hk-btn-primary {
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        color: #fff;
    }

    .hk-btn-secondary {
        background: #e2e8f0;
        color: #1e293b;
    }

    .hk-badges {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.55rem;
    }

    .hk-badge {
        border-radius: 10px;
        padding: 0.65rem;
        text-align: center;
        border: 1px solid transparent;
    }

    .b-b2b {
        background: #dcfce7;
        color: #166534;
        border-color: #86efac;
    }

    .b-od {
        background: #dbeafe;
        color: #1d4ed8;
        border-color: #93c5fd;
    }

    .b-vd {
        background: #fef3c7;
        color: #92400e;
        border-color: #fcd34d;
    }

    .b-vc {
        background: #e2e8f0;
        color: #334155;
        border-color: #cbd5e1;
    }

    .hk-badge .n {
        font-size: 1.3rem;
        font-weight: 900;
        line-height: 1;
    }

    .hk-badge .l {
        font-size: 0.72rem;
        font-weight: 700;
        margin-top: 0.2rem;
    }

    .hk-table-wrap {
        overflow-x: auto;
    }

    .hk-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.82rem;
    }

    .hk-table th,
    .hk-table td {
        border-bottom: 1px solid var(--bg-tertiary);
        padding: 0.55rem 0.5rem;
        text-align: left;
        vertical-align: middle;
    }

    .hk-table th {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--text-muted);
    }

    .hk-pill {
        display: inline-block;
        font-size: 0.68rem;
        font-weight: 800;
        border-radius: 20px;
        padding: 0.15rem 0.55rem;
    }

    .hk-pill.manual {
        background: #fee2e2;
        color: #b91c1c;
    }

    .hk-pill.auto {
        background: #e0e7ff;
        color: #3730a3;
    }

    .hk-room {
        font-weight: 800;
        color: #1e293b;
    }

    .hk-guest {
        font-size: 0.72rem;
        color: var(--text-muted);
        margin-top: 0.15rem;
    }

    .hk-staff-load {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.45rem;
        margin-top: 0.7rem;
    }

    .hk-load-item {
        border: 1px solid var(--bg-tertiary);
        border-radius: 8px;
        padding: 0.45rem;
        text-align: center;
    }

    .hk-load-item .name {
        font-size: 0.72rem;
        color: var(--text-muted);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .hk-load-item .num {
        font-size: 1.1rem;
        font-weight: 900;
        color: #0f172a;
        line-height: 1.1;
    }

    .alert {
        margin-bottom: 0.8rem;
        border-radius: 9px;
        padding: 0.65rem 0.8rem;
        font-size: 0.82rem;
        font-weight: 600;
    }

    .alert-ok {
        background: #ecfdf5;
        border: 1px solid #86efac;
        color: #166534;
    }

    .alert-err {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #b91c1c;
    }

    @media (max-width: 980px) {
        .hk-grid {
            grid-template-columns: 1fr;
        }

        .hk-staff-load {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
</style>

<div class="hk-wrap">
    <div class="hk-head">
        <div>
            <h1 class="hk-title">Pembagian Pembersihan Room HK</h1>
            <div class="hk-sub">Prioritas: B2B -> OD (In-House) -> VD -> VC. Sistem bagi otomatis adil, lalu bisa override manual dari Frontdesk.</div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-ok"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-err"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="hk-grid">
        <div>
            <div class="hk-card">
                <h3>Staff HK Aktif</h3>
                <form method="post">
                    <input type="hidden" name="action" value="save_staff">
                    <input type="hidden" name="work_date" value="<?php echo htmlspecialchars($workDate); ?>">
                    <label style="font-size:0.74rem;color:var(--text-muted);font-weight:700;display:block;margin-bottom:0.35rem;">Nama staff (satu baris satu nama)</label>
                    <textarea class="hk-input" name="staff_names" placeholder="Contoh:
HK Sinta
HK Dita
HK Wawan"><?php echo htmlspecialchars($staffText); ?></textarea>
                    <div class="hk-actions">
                        <button class="hk-btn hk-btn-primary" type="submit">Simpan Staff</button>
                    </div>
                </form>
            </div>

            <div class="hk-card" style="margin-top:1rem;">
                <h3>Filter Tanggal Kerja</h3>
                <form method="get" class="hk-actions" style="margin-top:0;">
                    <input class="hk-date" type="date" name="date" value="<?php echo htmlspecialchars($workDate); ?>">
                    <button class="hk-btn hk-btn-secondary" type="submit">Muat Data</button>
                </form>
                <div class="hk-actions">
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="generate_auto">
                        <input type="hidden" name="work_date" value="<?php echo htmlspecialchars($workDate); ?>">
                        <button class="hk-btn hk-btn-primary" type="submit">Generate Ulang Otomatis</button>
                    </form>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Reset manual assignment untuk tanggal ini?');">
                        <input type="hidden" name="action" value="reset_auto">
                        <input type="hidden" name="work_date" value="<?php echo htmlspecialchars($workDate); ?>">
                        <button class="hk-btn hk-btn-secondary" type="submit">Reset ke Auto</button>
                    </form>
                </div>
            </div>

            <div class="hk-card" style="margin-top:1rem;">
                <h3>Ringkasan Prioritas</h3>
                <div class="hk-badges">
                    <div class="hk-badge b-b2b"><div class="n"><?php echo (int)$categoryCount['B2B']; ?></div><div class="l">B2B</div></div>
                    <div class="hk-badge b-od"><div class="n"><?php echo (int)$categoryCount['OD']; ?></div><div class="l">OD</div></div>
                    <div class="hk-badge b-vd"><div class="n"><?php echo (int)$categoryCount['VD']; ?></div><div class="l">VD</div></div>
                    <div class="hk-badge b-vc"><div class="n"><?php echo (int)$categoryCount['VC']; ?></div><div class="l">VC</div></div>
                </div>

                <?php if (!empty($staffNames)): ?>
                    <div class="hk-staff-load">
                        <?php foreach ($staffNames as $sn): ?>
                            <div class="hk-load-item">
                                <div class="name"><?php echo htmlspecialchars($sn); ?></div>
                                <div class="num"><?php echo (int)($staffLoad[$sn] ?? 0); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="hk-card">
            <h3>Daftar Pembagian Kamar</h3>

            <?php if (empty($tasks)): ?>
                <div style="padding:1rem;color:var(--text-muted);">Tidak ada task kamar untuk tanggal ini.</div>
            <?php elseif (empty($staffNames)): ?>
                <div style="padding:1rem;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;">Isi nama staff HK dulu agar pembagian otomatis bisa dihitung.</div>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="action" value="save_plan">
                    <input type="hidden" name="work_date" value="<?php echo htmlspecialchars($workDate); ?>">

                    <div class="hk-table-wrap">
                        <table class="hk-table">
                            <thead>
                                <tr>
                                    <th>Prioritas</th>
                                    <th>Kamar</th>
                                    <th>Konteks</th>
                                    <th>Assigned HK</th>
                                    <th>Sumber</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tasks as $task):
                                    $key = $task['key'];
                                    $assigned = $assignmentMap[$key] ?? '';
                                    $isManual = (bool)($manualMap[$key] ?? false);
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($task['task_code']); ?></strong>
                                            <div style="font-size:0.7rem;color:var(--text-muted);">#<?php echo (int)$task['priority_order']; ?> <?php echo htmlspecialchars($task['task_label']); ?></div>
                                        </td>
                                        <td>
                                            <div class="hk-room">Room <?php echo htmlspecialchars($task['room_number']); ?></div>
                                            <div style="font-size:0.7rem;color:var(--text-muted);"><?php echo htmlspecialchars($task['room_type']); ?></div>
                                        </td>
                                        <td>
                                            <?php if ($task['task_code'] === 'B2B'): ?>
                                                <div class="hk-guest">In-house: <?php echo htmlspecialchars($task['inhouse_guest'] ?: '-'); ?></div>
                                                <div class="hk-guest">Next guest: <?php echo htmlspecialchars($task['next_guest'] ?: '-'); ?></div>
                                            <?php elseif ($task['task_code'] === 'OD'): ?>
                                                <div class="hk-guest">Tamu in-house: <?php echo htmlspecialchars($task['inhouse_guest'] ?: '-'); ?></div>
                                            <?php else: ?>
                                                <div class="hk-guest">Status room: <?php echo htmlspecialchars(strtoupper($task['room_status'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <select class="hk-select" name="assigned[<?php echo htmlspecialchars($key); ?>]">
                                                <option value="">- Pilih Staff -</option>
                                                <?php foreach ($staffNames as $sn): ?>
                                                    <option value="<?php echo htmlspecialchars($sn); ?>" <?php echo $assigned === $sn ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($sn); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <?php if ($isManual): ?>
                                                <span class="hk-pill manual">Manual</span>
                                            <?php else: ?>
                                                <span class="hk-pill auto">Auto</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="hk-actions" style="margin-top:1rem;justify-content:flex-end;">
                        <button class="hk-btn hk-btn-primary" type="submit">Simpan Pembagian Manual</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
