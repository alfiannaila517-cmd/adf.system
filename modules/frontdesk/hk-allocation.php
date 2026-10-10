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
require_once '../../includes/HkAllocationHelper.php';

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

// Core allocation logic (ensureHkTables, buildHkTasks, autoAssignFair,
// syncHkStaffFromPayroll, getAttendanceEligibleHkStaff, hkGenerateAssignments,
// hkSyncDailyState) now lives in includes/HkAllocationHelper.php so the cron
// (api/hk-allocation-cron.php) can reuse the exact same behaviour.

ensureHkTables($db);

$workDate = $_POST['work_date'] ?? $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
    $workDate = date('Y-m-d');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_staff') {
        try {
            $synced = syncHkStaffFromPayroll($db);
            $message = 'Sinkron staff HK dari data payroll berhasil: ' . count($synced) . ' staff aktif.';
        } catch (Exception $e) {
            if (method_exists($db, 'rollback')) {
                $db->rollback();
            }
            $error = 'Gagal sinkron staff HK dari payroll: ' . $e->getMessage();
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
            syncHkStaffFromPayroll($db);
            $staffRowsNow = $db->fetchAll("SELECT staff_name FROM frontdesk_hk_staff WHERE is_active = 1 ORDER BY staff_name ASC") ?: [];
            $staffNamesNow = array_map(fn($r) => $r['staff_name'], $staffRowsNow);

            if (empty($staffNamesNow)) {
                throw new Exception('Staff HK dari payroll kosong. Pastikan data payroll_employees (Housekeeping) sudah ada.');
            }

            if ($action === 'save_plan') {
                $tasksNow = buildHkTasks($db, $workDate);
                $teamAssigneeNow = 'TEAM';
                $assigneeOptionsNow = $staffNamesNow;
                if (!in_array($teamAssigneeNow, $assigneeOptionsNow, true)) {
                    $assigneeOptionsNow[] = $teamAssigneeNow;
                }

                $assignMap = [];
                $incoming = $_POST['assigned'] ?? [];
                foreach ($tasksNow as $task) {
                    $key = $task['key'];
                    $assigned = trim((string)($incoming[$key] ?? ''));
                    if ($assigned !== '' && in_array($assigned, $assigneeOptionsNow, true)) {
                        $assignMap[$key] = $assigned;
                    }
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
                        VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
                        [
                            $workDate,
                            $task['room_id'],
                            $task['room_number'],
                            $task['task_code'],
                            $task['priority_order'],
                            $assigned,
                            $currentUser['id'] ?? null
                        ]
                    );
                }

                $db->commit();
                $message = 'Pembagian HK manual berhasil disimpan.';
            } else {
                // "Generate Ulang Otomatis" button - full regenerate using staff
                // who have already checked in (if past the 09:00 cutoff today).
                $attendanceNow = getAttendanceEligibleHkStaff($db, $staffNamesNow, $workDate, '09:00:00');
                $effectiveStaffNow = $attendanceNow['eligible_staff'];
                hkGenerateAssignments($db, $workDate, $effectiveStaffNow, $currentUser['id'] ?? null);
                $message = 'Pembagian HK otomatis berhasil dibuat ulang.';
            }
        } catch (Exception $e) {
            if (method_exists($db, 'rollback')) {
                $db->rollback();
            }
            $error = 'Gagal menyimpan pembagian: ' . $e->getMessage();
        }
    }
}

try {
    // Self-healing daily sync: generates the day's plan right after midnight
    // (using all staff, since attendance isn't relevant yet) if it hasn't been
    // generated already, and - once past the 09:00 attendance cutoff - moves
    // ONLY the rooms belonging to staff who haven't checked in over to staff
    // who have, leaving everyone else's room numbers untouched. This runs on
    // every page load AND from the api/hk-allocation-cron.php cron, so the
    // plan is always correct even if nobody opens this page.
    hkSyncDailyState($db, $workDate, $currentUser['id'] ?? null);
} catch (Exception $e) {
    if ($error === '') {
        $error = 'Gagal sinkron pembagian HK otomatis: ' . $e->getMessage();
    }
}

$staffRows = $db->fetchAll("SELECT staff_name FROM frontdesk_hk_staff WHERE is_active = 1 ORDER BY staff_name ASC") ?: [];
$staffNames = array_map(fn($r) => $r['staff_name'], $staffRows);
$attendanceGate = getAttendanceEligibleHkStaff($db, $staffNames, $workDate, '09:00:00');
$effectiveStaffNames = $attendanceGate['eligible_staff'];
$absentStaffNames = $attendanceGate['absent_staff'];
$attendanceEnforced = (bool)($attendanceGate['enforced'] ?? false);
$teamAssignee = 'TEAM';
$displayAssignees = $staffNames;
if (!in_array($teamAssignee, $displayAssignees, true)) {
    $displayAssignees[] = $teamAssignee;
}
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
$seedCounts = array_fill_keys($displayAssignees, 0);

foreach ($tasks as $task) {
    $k = $task['key'];
    if (!isset($savedMap[$k])) {
        continue;
    }
    $assigned = $savedMap[$k]['assigned_staff'];
    if (!in_array($assigned, $displayAssignees, true)) {
        continue;
    }

    $isManualSaved = (int)$savedMap[$k]['is_manual'] === 1;
    if ($attendanceEnforced && !$isManualSaved && in_array($assigned, $absentStaffNames, true)) {
        // Auto assignment milik staff tidak hadir harus dialihkan ulang otomatis.
        continue;
    }

    $assignmentMap[$k] = $assigned;
    $manualMap[$k] = $isManualSaved;
    if (isset($seedCounts[$assigned])) {
        $seedCounts[$assigned]++;
    }
}

$unassignedTasks = array_values(array_filter($tasks, function ($t) use ($assignmentMap) {
    return !isset($assignmentMap[$t['key']]);
}));

$maxPerStaff = null;
if (count($effectiveStaffNames) > 0 && count($tasks) >= count($effectiveStaffNames)) {
    $maxPerStaff = intdiv(count($tasks), count($effectiveStaffNames));
}
$autoResult = autoAssignFair($unassignedTasks, $effectiveStaffNames, $seedCounts, $maxPerStaff, $teamAssignee);
foreach ($autoResult['assignments'] as $key => $staffName) {
    $assignmentMap[$key] = $staffName;
    $manualMap[$key] = false;
}

$staffLoad = array_fill_keys($displayAssignees, 0);
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

$tasksByStaff = [];
foreach ($displayAssignees as $sn) {
    $tasksByStaff[$sn] = [];
}

$unassignedTaskKeys = [];
foreach ($tasks as $task) {
    $taskKey = $task['key'];
    $assigned = $assignmentMap[$taskKey] ?? '';
    if ($assigned !== '' && isset($tasksByStaff[$assigned])) {
        $tasksByStaff[$assigned][] = $task;
    } else {
        $unassignedTaskKeys[] = $taskKey;
    }
}

include '../../includes/header.php';
?>

<style>
    /* Pembagian HK — ringkas, mengikuti tema terang & gelap sistem */
    .hk-wrap {
        --ink: #0f172a; --mute: #64748b; --faint: #94a3b8; --line: #e8edf3; --soft: #f8fafc; --card: #ffffff;
        --acc: #2563eb; --shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 8px 22px -16px rgba(15, 23, 42, .25);
        max-width: 1500px; margin: 0 auto; padding: .7rem .8rem 5rem; color: var(--ink); font-size: .78rem;
    }
    body[data-theme="dark"] .hk-wrap {
        --ink: #f1f5f9; --mute: #94a3b8; --faint: #64748b; --line: rgba(148, 163, 184, .2); --soft: rgba(255, 255, 255, .05); --card: rgba(30, 41, 59, .72);
        --acc: #60a5fa; --shadow: 0 12px 28px -16px rgba(0, 0, 0, .7);
    }
    .hk-wrap *, .hk-wrap *::before, .hk-wrap *::after { box-sizing: border-box; }
    .hk-wrap :is(span, div, p, b, small, strong, label, h1, h2, h3, em) { color: inherit !important; -webkit-text-fill-color: currentColor; }

    .hk-bar { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; padding: .6rem .8rem; margin-bottom: .6rem; border-radius: 14px; background: var(--card); border: 1px solid var(--line); box-shadow: var(--shadow); }
    .hk-bar-t { flex: 1 1 220px; min-width: 0; }
    .hk-bar-t b { display: block; font-size: .95rem; font-weight: 800; color: var(--ink) !important; }
    .hk-bar-t small { display: block; font-size: .64rem; color: var(--mute) !important; margin-top: 1px; }
    .hk-bar form { margin: 0; display: flex; gap: .4rem; align-items: center; }
    .hk-date { height: 30px; padding: 0 .6rem; border-radius: 9px; border: 1px solid var(--line); background: var(--soft) !important; color: var(--ink) !important; font: inherit; font-size: .74rem; color-scheme: light; }
    body[data-theme="dark"] .hk-date { color-scheme: dark; }
    .hk-btn { height: 30px; padding: 0 .85rem; border-radius: 9px; font: inherit; font-size: .72rem; font-weight: 700; cursor: pointer; border: 1px solid var(--line); background: var(--soft) !important; color: var(--ink) !important; -webkit-text-fill-color: var(--ink) !important; }
    .hk-btn:hover { border-color: var(--acc); }
    .hk-btn.pri { background: linear-gradient(135deg, #1e3a8a, #2563eb) !important; border-color: transparent; color: #fff !important; -webkit-text-fill-color: #fff !important; box-shadow: 0 8px 16px -10px rgba(37, 99, 235, .8); }
    .hk-btn.sm { height: 24px; padding: 0 .6rem; font-size: .64rem; border-radius: 8px; }

    .hk-alert { padding: .5rem .8rem; border-radius: 10px; font-size: .72rem; font-weight: 600; margin-bottom: .6rem; border: 1px solid; }
    .hk-alert.ok { background: rgba(22, 163, 74, .1); border-color: rgba(22, 163, 74, .35); color: #15803d !important; }
    .hk-alert.err { background: rgba(220, 38, 38, .1); border-color: rgba(220, 38, 38, .35); color: #b91c1c !important; }
    .hk-alert.warn { background: rgba(245, 158, 11, .1); border-color: rgba(245, 158, 11, .4); color: #b45309 !important; font-weight: 500; }
    body[data-theme="dark"] .hk-alert.ok { color: #86efac !important; } body[data-theme="dark"] .hk-alert.err { color: #fca5a5 !important; } body[data-theme="dark"] .hk-alert.warn { color: #fcd34d !important; }

    .hk-sum { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; padding: .5rem .8rem; margin-bottom: .6rem; border-radius: 14px; background: var(--card); border: 1px solid var(--line); box-shadow: var(--shadow); }
    .hk-chip { display: inline-flex; align-items: center; gap: .35rem; height: 26px; padding: 0 .65rem; border-radius: 999px; font-size: .68rem; font-weight: 700; background: var(--soft); border: 1px solid var(--line); color: var(--mute) !important; }
    .hk-chip b { font-size: .8rem; font-weight: 800; color: var(--ink) !important; }
    .hk-chip.b2b { background: rgba(22, 163, 74, .12); border-color: rgba(22, 163, 74, .35); } .hk-chip.b2b b { color: #16a34a !important; }
    .hk-chip.od { background: rgba(37, 99, 235, .12); border-color: rgba(37, 99, 235, .35); } .hk-chip.od b { color: #2563eb !important; }
    .hk-chip.vd { background: rgba(245, 158, 11, .14); border-color: rgba(245, 158, 11, .4); } .hk-chip.vd b { color: #d97706 !important; }
    .hk-chip.vc b { color: var(--mute) !important; }
    .hk-sep { width: 1px; height: 18px; background: var(--line); margin: 0 .2rem; }
    .hk-staff { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; margin: 0; margin-left: auto; }
    .hk-staff .lbl { font-size: .62rem; text-transform: uppercase; letter-spacing: .07em; font-weight: 800; color: var(--faint) !important; }

    .hk-board { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: .6rem; align-items: start; }
    .hk-col { background: var(--card); border: 1px solid var(--line); border-radius: 14px; box-shadow: var(--shadow); overflow: hidden; }
    .hk-col-h { display: flex; align-items: center; gap: .5rem; padding: .55rem .7rem; border-bottom: 1px solid var(--line); }
    .hk-av { width: 26px; height: 26px; border-radius: 9px; display: grid; place-items: center; font-size: .7rem; font-weight: 800; background: linear-gradient(135deg, #1e3a8a, #2563eb); color: #fff !important; flex-shrink: 0; }
    .hk-col.team .hk-av { background: linear-gradient(135deg, #475569, #64748b); }
    .hk-col-n { flex: 1; min-width: 0; font-size: .78rem; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hk-col-c { font-size: .62rem; font-weight: 800; padding: 2px 9px; border-radius: 999px; background: var(--soft); border: 1px solid var(--line); color: var(--mute) !important; white-space: nowrap; }
    .hk-abs { font-size: .56rem; font-weight: 800; padding: 1px 7px; border-radius: 999px; background: rgba(220, 38, 38, .12); color: #dc2626 !important; border: 1px solid rgba(220, 38, 38, .35); white-space: nowrap; }
    .hk-col-b { padding: .45rem; display: flex; flex-direction: column; gap: .4rem; }
    .hk-empty { padding: .8rem .4rem; text-align: center; font-size: .68rem; color: var(--faint) !important; border: 1px dashed var(--line); border-radius: 10px; }

    .hk-task { border: 1px solid var(--line); border-left: 3px solid var(--tc, #64748b); border-radius: 10px; padding: .4rem .5rem; background: var(--soft); }
    .hk-task.b2b { --tc: #16a34a; } .hk-task.od { --tc: #2563eb; } .hk-task.vd { --tc: #d97706; } .hk-task.vc { --tc: #64748b; }
    .hk-task-r { display: flex; align-items: baseline; gap: .35rem; }
    .hk-task-r b { font-size: .78rem; font-weight: 800; }
    .hk-task-r small { font-size: .62rem; color: var(--mute) !important; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .hk-code { font-size: .56rem; font-weight: 800; padding: 1px 7px; border-radius: 6px; color: #fff !important; background: var(--tc, #64748b); }
    .hk-task-c { font-size: .62rem; color: var(--mute) !important; margin: 2px 0 5px; line-height: 1.35; }
    .hk-task-f { display: flex; align-items: center; gap: .35rem; }
    .hk-select { flex: 1; min-width: 0; height: 26px; padding: 0 .5rem; border-radius: 8px; border: 1px solid var(--line); background: var(--card) !important; color: var(--ink) !important; -webkit-text-fill-color: var(--ink) !important; font: inherit; font-size: .7rem; font-weight: 600; }
    .hk-select option { color: #0f172a; background: #fff; }
    .hk-pill { font-size: .56rem; font-weight: 800; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
    .hk-pill.auto { background: rgba(37, 99, 235, .12); color: #2563eb !important; } .hk-pill.manual { background: rgba(124, 58, 237, .14); color: #7c3aed !important; }
    body[data-theme="dark"] .hk-pill.auto { color: #93c5fd !important; } body[data-theme="dark"] .hk-pill.manual { color: #c4b5fd !important; }

    .hk-save { position: sticky; bottom: 0; z-index: 20; display: flex; justify-content: space-between; align-items: center; gap: .6rem; margin-top: .7rem; padding: .55rem .8rem; border-radius: 14px; background: var(--card); border: 1px solid var(--line); box-shadow: 0 -10px 30px -20px rgba(15, 23, 42, .5); backdrop-filter: blur(8px); }
    .hk-save small { font-size: .66rem; color: var(--mute) !important; }
    @media (max-width: 640px) { .hk-board { grid-template-columns: 1fr; } .hk-staff { margin-left: 0; } }
</style>

<div class="hk-wrap">
    <div class="hk-bar">
        <div class="hk-bar-t">
            <b>Pembagian HK Room</b>
            <small>Prioritas B2B → OD → VD → VC · staff dari Payroll · cutoff absensi 09:00 · sisa kamar ke TEAM</small>
        </div>
        <form method="get">
            <input class="hk-date" type="date" name="date" value="<?php echo htmlspecialchars($workDate); ?>">
            <button class="hk-btn" type="submit">Muat</button>
        </form>
        <form method="post">
            <input type="hidden" name="action" value="generate_auto">
            <input type="hidden" name="work_date" value="<?php echo htmlspecialchars($workDate); ?>">
            <button class="hk-btn pri" type="submit">Generate Ulang</button>
        </form>
        <form method="post" onsubmit="return confirm('Reset manual assignment untuk tanggal ini?');">
            <input type="hidden" name="action" value="reset_auto">
            <input type="hidden" name="work_date" value="<?php echo htmlspecialchars($workDate); ?>">
            <button class="hk-btn" type="submit">Reset ke Auto</button>
        </form>
    </div>

    <?php if ($message): ?><div class="hk-alert ok"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="hk-alert err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="hk-sum">
        <span class="hk-chip b2b"><b><?php echo (int)$categoryCount['B2B']; ?></b> B2B</span>
        <span class="hk-chip od"><b><?php echo (int)$categoryCount['OD']; ?></b> OD</span>
        <span class="hk-chip vd"><b><?php echo (int)$categoryCount['VD']; ?></b> VD</span>
        <span class="hk-chip vc"><b><?php echo (int)$categoryCount['VC']; ?></b> VC</span>
        <span class="hk-chip"><b><?php echo count($tasks); ?></b> kamar</span>
        <?php if (!empty($staffNames)): ?>
            <span class="hk-sep"></span>
            <?php foreach ($displayAssignees as $sn): ?>
                <span class="hk-chip"><?php echo htmlspecialchars($sn); ?> <b><?php echo (int)($staffLoad[$sn] ?? 0); ?></b></span>
            <?php endforeach; ?>
        <?php endif; ?>
        <form method="post" class="hk-staff">
            <input type="hidden" name="action" value="save_staff">
            <input type="hidden" name="work_date" value="<?php echo htmlspecialchars($workDate); ?>">
            <input type="hidden" name="staff_names" value="<?php echo htmlspecialchars($staffText); ?>">
            <span class="lbl"><?php echo count($staffNames); ?> staff HK</span>
            <button class="hk-btn sm" type="submit" title="Ambil ulang daftar staff Housekeeping dari Payroll">Sinkron Payroll</button>
        </form>
    </div>

    <?php if ($attendanceEnforced): ?>
        <div class="hk-alert warn">
            Cutoff absensi 09:00 aktif: staff yang belum check-in dianggap tidak berangkat dan jatahnya dibagi ulang.
            <?php if (!empty($absentStaffNames)): ?><b>Tidak hadir:</b> <?php echo htmlspecialchars(implode(', ', $absentStaffNames)); ?>.<?php else: ?>Semua staff HK hadir sebelum cutoff.<?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($tasks)): ?>
        <div class="hk-alert warn">Tidak ada task kamar untuk tanggal ini.</div>
    <?php elseif (empty($staffNames)): ?>
        <div class="hk-alert err">Isi nama staff HK dulu agar pembagian otomatis bisa dihitung.</div>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="action" value="save_plan">
            <input type="hidden" name="work_date" value="<?php echo htmlspecialchars($workDate); ?>">

            <?php if (!empty($unassignedTaskKeys)): ?>
                <div class="hk-alert err">Ada <?php echo count($unassignedTaskKeys); ?> kamar belum ter-assign. Pilih staff pada kartu kamar, lalu simpan.</div>
            <?php endif; ?>

            <div class="hk-board">
                <?php foreach ($displayAssignees as $sn):
                    $staffTasks = $tasksByStaff[$sn] ?? [];
                    $isTeamCard = ($sn === $teamAssignee);
                    $isAbsentByCutoff = (!$isTeamCard && $attendanceEnforced && in_array($sn, $absentStaffNames, true));
                ?>
                    <div class="hk-col<?php echo $isTeamCard ? ' team' : ''; ?>">
                        <div class="hk-col-h">
                            <span class="hk-av"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($sn, 0, 1))); ?></span>
                            <span class="hk-col-n" title="<?php echo htmlspecialchars($sn); ?>"><?php echo htmlspecialchars($sn); ?></span>
                            <?php if ($isAbsentByCutoff): ?><span class="hk-abs">Belum absen</span><?php endif; ?>
                            <span class="hk-col-c"><?php echo count($staffTasks); ?> kamar</span>
                        </div>
                        <div class="hk-col-b">
                            <?php if (empty($staffTasks)): ?>
                                <div class="hk-empty">Belum ada jatah kamar</div>
                            <?php else: ?>
                                <?php foreach ($staffTasks as $task):
                                    $key = $task['key'];
                                    $assigned = $assignmentMap[$key] ?? '';
                                    $isManual = (bool)($manualMap[$key] ?? false);
                                    $cls = strtolower($task['task_code']);
                                ?>
                                    <div class="hk-task <?php echo htmlspecialchars($cls); ?>">
                                        <div class="hk-task-r">
                                            <b>Room <?php echo htmlspecialchars($task['room_number']); ?></b>
                                            <small><?php echo htmlspecialchars($task['room_type']); ?></small>
                                            <span class="hk-code"><?php echo htmlspecialchars($task['task_code']); ?></span>
                                        </div>
                                        <div class="hk-task-c">
                                            <?php if ($task['task_code'] === 'B2B'): ?>
                                                In-house: <?php echo htmlspecialchars($task['inhouse_guest'] ?: '-'); ?> · Next: <?php echo htmlspecialchars($task['next_guest'] ?: '-'); ?>
                                            <?php elseif ($task['task_code'] === 'OD'): ?>
                                                Tamu in-house: <?php echo htmlspecialchars($task['inhouse_guest'] ?: '-'); ?>
                                            <?php elseif ($task['task_code'] === 'VC' && !empty($task['next_guest'])): ?>
                                                Datang hari ini: <?php echo htmlspecialchars($task['next_guest']); ?> · <?php echo htmlspecialchars(strtoupper($task['room_status'])); ?>
                                            <?php else: ?>
                                                Status: <?php echo htmlspecialchars(strtoupper($task['room_status'])); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="hk-task-f">
                                            <select class="hk-select" name="assigned[<?php echo htmlspecialchars($key); ?>]">
                                                <option value="">- Pilih Staff -</option>
                                                <?php foreach ($displayAssignees as $snOption): ?>
                                                    <option value="<?php echo htmlspecialchars($snOption); ?>" <?php echo $assigned === $snOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($snOption); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <span class="hk-pill <?php echo $isManual ? 'manual' : 'auto'; ?>"><?php echo $isManual ? 'Manual' : 'Auto'; ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="hk-save">
                <small>Ubah staff pada kartu kamar bila perlu, lalu simpan.</small>
                <button class="hk-btn pri" type="submit">Simpan Pembagian</button>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php include '../../includes/footer.php'; ?>