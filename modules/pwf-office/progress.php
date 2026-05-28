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
        $msg = 'Progress berhasil diupdate.';
    }
}

$orders = $pdo->query("SELECT id, order_code, product_name, progress_percent FROM pwf_orders WHERE status NOT IN ('completed','cancelled') ORDER BY id DESC")->fetchAll();
$craftsmen = $pdo->query('SELECT id, craftsman_name FROM pwf_craftsmen WHERE is_active=1 ORDER BY craftsman_name')->fetchAll();
$logs = $pdo->query("SELECT p.progress_date, p.achievement_percent, p.work_note, o.order_code, t.craftsman_name
                    FROM pwf_order_progress p
                    JOIN pwf_orders o ON o.id=p.order_id
                    LEFT JOIN pwf_craftsmen t ON t.id=p.craftsman_id
                    ORDER BY p.id DESC LIMIT 30")->fetchAll();

pwfOfficeHeader('Pencapaian Tukang', 'progress');
?>
<div class="grid2">
    <div class="card">
        <h3 style="margin-top:0;">Update Pencapaian</h3>
        <?php if ($msg): ?><p style="color:green; margin:8px 0;"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
        <form method="post">
            <div class="mt"><label>Order</label><select class="select" name="order_id" required>
                    <option value="">- Pilih Order -</option><?php foreach ($orders as $o): ?><option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['order_code'] . ' - ' . $o['product_name']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="mt"><label>Tukang</label><select class="select" name="craftsman_id">
                    <option value="">- Pilih Tukang -</option><?php foreach ($craftsmen as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['craftsman_name']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="grid2 mt">
                <div><label>Tanggal</label><input class="input" type="date" name="progress_date" value="<?= date('Y-m-d') ?>"></div>
                <div><label>Pencapaian %</label><input class="input" type="number" name="achievement_percent" min="0" max="100" value="0"></div>
            </div>
            <div class="mt"><label>Status Order</label><select class="select" name="status">
                    <option value="on_progress">On Progress</option>
                    <option value="qc">QC</option>
                    <option value="ready_ship">Ready Ship</option>
                    <option value="completed">Completed</option>
                </select></div>
            <div class="mt"><textarea name="work_note" placeholder="Catatan pengerjaan"></textarea></div>
            <div class="mt"><button class="btn" type="submit">Simpan Progress</button></div>
        </form>
    </div>
    <div class="card">
        <h3 style="margin-top:0;">Riwayat Pencapaian</h3>
        <table>
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Order</th>
                    <th>Tukang</th>
                    <th>%</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td><?= htmlspecialchars($l['progress_date']) ?></td>
                        <td><?= htmlspecialchars($l['order_code']) ?></td>
                        <td><?= htmlspecialchars($l['craftsman_name'] ?? '-') ?></td>
                        <td><?= (int)$l['achievement_percent'] ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php pwfOfficeFooter();
