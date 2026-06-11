<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$pdo = getPwfOfficePdo();
$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? 'create';

    if ($action === 'delete') {
        $id = (int)($_POST['craftsman_id'] ?? 0);
        if ($id > 0) {
            $stmtOrders = $pdo->prepare('SELECT COUNT(*) FROM pwf_orders WHERE assigned_craftsman_id = ?');
            $stmtOrders->execute([$id]);
            $usedInOrders = (int)$stmtOrders->fetchColumn();

            $stmtProgress = $pdo->prepare('SELECT COUNT(*) FROM pwf_order_progress WHERE craftsman_id = ?');
            $stmtProgress->execute([$id]);
            $usedInProgress = (int)$stmtProgress->fetchColumn();

            if ($usedInOrders > 0 || $usedInProgress > 0) {
                $msgType = 'warning';
                $msg = 'Craftsman cannot be deleted because it is already used in orders/progress.';
            } else {
                $stmtDelete = $pdo->prepare('DELETE FROM pwf_craftsmen WHERE id = ?');
                $stmtDelete->execute([$id]);
                $msg = 'Craftsman deleted successfully.';
            }
        }
    } else {
        $name = trim($_POST['craftsman_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $specialty = trim($_POST['specialty'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($name !== '') {
            $code = genPwfCode($pdo, 'TKG');
            $stmt = $pdo->prepare('INSERT INTO pwf_craftsmen (craftsman_code, craftsman_name, phone, specialty, notes) VALUES (?,?,?,?,?)');
            $stmt->execute([$code, $name, $phone, $specialty, $notes]);
            $msg = 'Craftsman added successfully.';
        }
    }
}

$rows = $pdo->query('SELECT * FROM pwf_craftsmen ORDER BY id DESC')->fetchAll();

pwfOfficeHeader('Craftsmen', 'craftsmen');
?>
<div class="grid2">
    <div class="pwf-card">
        <div class="pwf-card-header">Add Craftsman</div>
        <div class="pwf-card-body">
        <?php if ($msg): ?><div class="alert alert-<?= $msgType === 'warning' ? 'warning' : 'success' ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="_action" value="create">
            <div class="pwf-form-group"><label>Full Name</label><input class="input" name="craftsman_name" placeholder="Craftsman name" required></div>
            <div class="pwf-form-group"><label>Phone / WhatsApp</label><input class="input" name="phone" placeholder="+62 ..."></div>
            <div class="pwf-form-group"><label>Specialty</label><input class="input" name="specialty" placeholder="e.g. Carving, Finishing, Assembly"></div>
            <div class="pwf-form-group"><label>Notes</label><textarea name="notes" placeholder="Additional notes"></textarea></div>
            <button class="btn" type="submit"><i class="bi bi-plus-circle"></i> Save Craftsman</button>
        </form>
        </div>
    </div>
    <div class="pwf-card">
        <div class="pwf-card-header">Craftsmen List</div>
        <div style="padding:0">
        <table class="pwf-table">
            <thead><tr><th>Code</th><th>Name</th><th>Specialty</th><th style="width:110px">Action</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><code style="font-size:12px;color:var(--gold)"><?= htmlspecialchars($r['craftsman_code']) ?></code></td>
                        <td><?= htmlspecialchars($r['craftsman_name']) ?></td>
                        <td><?= htmlspecialchars($r['specialty']) ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Delete this craftsman?');" style="margin:0">
                                <input type="hidden" name="_action" value="delete">
                                <input type="hidden" name="craftsman_id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" style="padding:5px 10px;font-size:11px">
                                    <i class="bi bi-trash"></i> Hapus
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if(empty($rows)): ?><tr><td colspan="4" style="text-align:center;color:var(--muted);padding:24px">No craftsmen yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php pwfOfficeFooter();
