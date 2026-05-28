<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$pdo = getPwfOfficePdo();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $shipDate = $_POST['shipment_date'] ?? date('Y-m-d');
    $container = $_POST['container_type'] ?? '20ft';
    $destCountry = trim($_POST['destination_country'] ?? '');
    $destPort = trim($_POST['destination_port'] ?? '');
    $forwarder = trim($_POST['forwarder'] ?? '');
    $tracking = trim($_POST['tracking_no'] ?? '');
    $status = $_POST['status'] ?? 'booked';
    $notes = trim($_POST['notes'] ?? '');

    if ($orderId > 0) {
        $pdo->prepare('INSERT INTO pwf_shipments (order_id, shipment_date, container_type, destination_country, destination_port, forwarder, tracking_no, status, notes) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$orderId, $shipDate, $container, $destCountry, $destPort, $forwarder, $tracking, $status, $notes]);

        if (in_array($status, ['onboard', 'arrived', 'closed'], true)) {
            $pdo->prepare("UPDATE pwf_orders SET status='shipped', updated_at=NOW() WHERE id=?")->execute([$orderId]);
        } else {
            $pdo->prepare("UPDATE pwf_orders SET status='ready_ship', updated_at=NOW() WHERE id=?")->execute([$orderId]);
        }

        $msg = 'Shipment record saved successfully.';
    }
}

$orders = $pdo->query("SELECT id, order_code, product_name FROM pwf_orders WHERE status IN ('ready_ship','shipped') ORDER BY id DESC")->fetchAll();
$ships = $pdo->query("SELECT s.*, o.order_code, o.product_name FROM pwf_shipments s JOIN pwf_orders o ON o.id=s.order_id ORDER BY s.id DESC LIMIT 30")->fetchAll();

pwfOfficeHeader('Shipping & Export', 'shipping');
?>
<div class="grid2">
    <div class="pwf-card">
        <div class="pwf-card-header">New Shipment</div>
        <div class="pwf-card-body">
        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <form method="post">
            <div class="pwf-form-group">
                <label>Order</label>
                <select class="select" name="order_id" required>
                    <option value="">— Select Ready-to-Ship Order —</option>
                    <?php foreach ($orders as $o): ?>
                        <option value="<?= (int)$o['id'] ?>"><?= htmlspecialchars($o['order_code'].' – '.$o['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid2">
                <div class="pwf-form-group"><label>Shipment Date</label><input class="input" type="date" name="shipment_date" value="<?= date('Y-m-d') ?>"></div>
                <div class="pwf-form-group"><label>Container Type</label>
                    <select class="select" name="container_type">
                        <option value="20ft">20ft</option>
                        <option value="40ft">40ft</option>
                        <option value="40hc">40HC</option>
                        <option value="lcl">LCL</option>
                    </select>
                </div>
            </div>
            <div class="grid2">
                <div class="pwf-form-group"><label>Destination Country</label><input class="input" name="destination_country" placeholder="e.g. Japan"></div>
                <div class="pwf-form-group"><label>Destination Port</label><input class="input" name="destination_port" placeholder="e.g. Osaka"></div>
            </div>
            <div class="grid2">
                <div class="pwf-form-group"><label>Freight Forwarder</label><input class="input" name="forwarder" placeholder="Forwarder name"></div>
                <div class="pwf-form-group"><label>Tracking No. / BL</label><input class="input" name="tracking_no" placeholder="Tracking number"></div>
            </div>
            <div class="pwf-form-group"><label>Status</label>
                <select class="select" name="status">
                    <option value="booked">Booked</option>
                    <option value="onboard">On Board</option>
                    <option value="arrived">Arrived</option>
                    <option value="closed">Closed</option>
                </select>
            </div>
            <div class="pwf-form-group"><label>Notes</label><textarea name="notes" placeholder="Additional notes"></textarea></div>
            <button class="btn" type="submit"><i class="bi bi-box-seam"></i> Save Shipment</button>
        </form>
        </div>
    </div>
    <div class="pwf-card">
        <div class="pwf-card-header">Shipment History</div>
        <div style="padding:0">
        <table class="pwf-table">
            <thead><tr><th>Date</th><th>Order</th><th>Container</th><th>Destination</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($ships as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['shipment_date']) ?></td>
                        <td><code style="font-size:12px;color:var(--gold)"><?= htmlspecialchars($s['order_code']) ?></code><div class="small"><?= htmlspecialchars($s['product_name']) ?></div></td>
                        <td><?= htmlspecialchars(strtoupper($s['container_type'])) ?></td>
                        <td><?= htmlspecialchars($s['destination_country']??'—') ?><div class="small"><?= htmlspecialchars($s['destination_port']??'') ?></div></td>
                        <td><span class="status-badge status-<?= htmlspecialchars($s['status']) ?>"><?= htmlspecialchars($s['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if(empty($ships)): ?><tr><td colspan="5" style="text-align:center;color:var(--muted);padding:24px">No shipments yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php pwfOfficeFooter();
