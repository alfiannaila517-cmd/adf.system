<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$pdo = getPwfOfficePdo();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $craftsmanId = (int)($_POST['craftsman_id'] ?? 0) ?: null;
    $date = $_POST['progress_date'] ?? date('Y-m-d');
    $pct = max(0, min(100, (int)($_POST['achievement_percent'] ?? 0)));
    $note = trim($_POST['work_note'] ?? '');
    $status = trim($_POST['status'] ?? 'on_progress');

    if ($orderId > 0) {
        $stmt = $pdo->prepare('INSERT INTO pwf_order_progress (order_id, craftsman_id, progress_date, achievement_percent, work_note) VALUES (?,?,?,?,?)');
        $stmt->execute([$orderId, $craftsmanId, $date, $pct, $note]);

        $up = $pdo->prepare('UPDATE pwf_orders SET progress_percent=?, status=?, assigned_craftsman_id=COALESCE(?, assigned_craftsman_id) WHERE id=?');
        $up->execute([$pct, $status, $craftsmanId, $orderId]);
        $msg = 'Progress updated successfully.';
    }
}

$orders = $pdo->query("SELECT id, order_code, product_name, progress_percent FROM pwf_orders WHERE status NOT IN ('completed','cancelled') ORDER BY id DESC")->fetchAll();
$craftsmen = $pdo->query('SELECT id, craftsman_name FROM pwf_craftsmen WHERE is_active=1 ORDER BY craftsman_name')->fetchAll();
$logs = $pdo->query("SELECT p.progress_date, p.achievement_percent, p.work_note, o.order_code, t.craftsman_name
                    FROM pwf_order_progress p
                    JOIN pwf_orders o ON o.id=p.order_id
                    LEFT JOIN pwf_craftsmen t ON t.id=p.craftsman_id
                    ORDER BY p.id DESC LIMIT 30")->fetchAll();

pwfOfficeHeader('Progress Tracking', 'progress');
?>
<div class="grid2">
    <div class="pwf-card">
        <div class="pwf-card-header">Update Progress</div>
        <div class="pwf-card-body">
        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <form method="post">
            <div class="pwf-form-group"><label>Order</label>
                <select class="select" name="order_id" required>
                    <option value="">— Select Order —</option>
                    <?php foreach ($orders as $o): ?><option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['order_code'].' – '.$o['product_name']) ?> (<?= (int)$o['progress_percent'] ?>%)</option><?php endforeach; ?>
                </select>
            </div>
            <div class="pwf-form-group"><label>Craftsman</label>
                <select class="select" name="craftsman_id">
                    <option value="">— Select Craftsman —</option>
                    <?php foreach ($craftsmen as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['craftsman_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="grid2">
                <div class="pwf-form-group"><label>Date</label><input class="input" type="date" name="progress_date" value="<?= date('Y-m-d') ?>"></div>
                <div class="pwf-form-group"><label>Achievement %</label><input class="input" type="number" name="achievement_percent" min="0" max="100" value="0"></div>
            </div>
            <div class="pwf-form-group"><label>Order Status</label>
                <select class="select" name="status">
                    <option value="on_progress">On Progress</option>
                    <option value="qc">QC / Quality Check</option>
                    <option value="ready_ship">Ready to Ship</option>
                    <option value="completed">Completed</option>
                </select>
            </div>
            <div class="pwf-form-group"><label>Work Notes</label><textarea name="work_note" placeholder="Describe work done today..."></textarea></div>
            <button class="btn" type="submit"><i class="bi bi-arrow-up-circle"></i> Save Progress</button>
        </form>
        </div>
    </div>
    <div class="pwf-card">
        <div class="pwf-card-header">Progress History</div>
        <div style="padding:0">
        <table class="pwf-table">
            <thead><tr><th>Date</th><th>Order</th><th>Craftsman</th><th>%</th></tr></thead>
            <tbody>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td><?= htmlspecialchars($l['progress_date']) ?></td>
                        <td><code style="font-size:12px;color:var(--gold)"><?= htmlspecialchars($l['order_code']) ?></code></td>
                        <td><?= htmlspecialchars($l['craftsman_name'] ?? '—') ?></td>
                        <td><strong><?= (int)$l['achievement_percent'] ?>%</strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if(empty($logs)): ?><tr><td colspan="4" style="text-align:center;color:var(--muted);padding:24px">No progress logs yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php pwfOfficeFooter();
