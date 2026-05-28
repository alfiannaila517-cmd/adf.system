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

        $msg = 'Data pengiriman berhasil disimpan.';
    }
}

$orders = $pdo->query("SELECT id, order_code, product_name FROM pwf_orders WHERE status IN ('ready_ship','shipped') ORDER BY id DESC")->fetchAll();
$ships = $pdo->query("SELECT s.*, o.order_code, o.product_name FROM pwf_shipments s JOIN pwf_orders o ON o.id=s.order_id ORDER BY s.id DESC LIMIT 30")->fetchAll();

pwfOfficeHeader('Finish & Pengiriman Export', 'shipping');
?>
<div class="grid2">
    <div class="card">
        <h3 style="margin-top:0;">Input Pengiriman</h3>
        <?php if ($msg): ?><p style="color:green; margin:8px 0;"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
        <form method="post">
            <div class="mt">
                <select class="select" name="order_id" required>
                    <option value="">Pilih Order Siap Kirim</option>
                    <?php foreach ($orders as $o): ?>
                        <option value="<?= (int)$o['id'] ?>"><?= htmlspecialchars($o['order_code'] . ' - ' . $o['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid2 mt">
                <input class="input" type="date" name="shipment_date" value="<?= date('Y-m-d') ?>">
                <select class="select" name="container_type">
                    <option value="20ft">20ft</option>
                    <option value="40ft">40ft</option>
                    <option value="40hc">40HC</option>
                    <option value="lcl">LCL</option>
                </select>
            </div>
            <div class="grid2 mt">
                <input class="input" name="destination_country" placeholder="Negara Tujuan">
                <input class="input" name="destination_port" placeholder="Pelabuhan Tujuan">
            </div>
            <div class="grid2 mt">
                <input class="input" name="forwarder" placeholder="Forwarder / Ekspedisi">
                <input class="input" name="tracking_no" placeholder="No Tracking / BL">
            </div>
            <div class="mt">
                <select class="select" name="status">
                    <option value="booked">Booked</option>
                    <option value="onboard">On Board</option>
                    <option value="arrived">Arrived</option>
                    <option value="closed">Closed</option>
                </select>
            </div>
            <div class="mt"><textarea name="notes" placeholder="Catatan"></textarea></div>
            <div class="mt"><button class="btn warn" type="submit">Simpan Pengiriman</button></div>
        </form>
    </div>
    <div class="card">
        <h3 style="margin-top:0;">Riwayat Pengiriman</h3>
        <table>
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Order</th>
                    <th>Container</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ships as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['shipment_date']) ?></td>
                        <td><?= htmlspecialchars($s['order_code']) ?><div class="small"><?= htmlspecialchars($s['destination_country'] ?? '-') ?></div>
                        </td>
                        <td><?= htmlspecialchars(strtoupper($s['container_type'])) ?></td>
                        <td><?= htmlspecialchars($s['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php pwfOfficeFooter();
