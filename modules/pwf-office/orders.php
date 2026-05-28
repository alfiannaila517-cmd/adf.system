<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$pdo = getPwfOfficePdo();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $orderDate = $_POST['order_date'] ?? date('Y-m-d');
    $dueDate = $_POST['due_date'] ?? null;
    $productName = trim($_POST['product_name'] ?? '');
    $specification = trim($_POST['specification'] ?? '');
    $dimensions = trim($_POST['dimensions'] ?? '');
    $quantity = (float)($_POST['quantity'] ?? 1);
    $assignedCraftsmanId = (int)($_POST['assigned_craftsman_id'] ?? 0) ?: null;
    $notes = trim($_POST['notes'] ?? '');

    $imagePath = null;
    if (!empty($_FILES['order_image']['name']) && is_uploaded_file($_FILES['order_image']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['order_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $fileName = 'pwf-' . time() . '-' . mt_rand(100, 999) . '.' . $ext;
            $targetAbs = BASE_PATH . '/uploads/pwf-orders/' . $fileName;
            if (@move_uploaded_file($_FILES['order_image']['tmp_name'], $targetAbs)) {
                $imagePath = 'uploads/pwf-orders/' . $fileName;
            }
        }
    }

    if ($customerId > 0 && $productName !== '') {
        $code = genPwfCode($pdo, 'ORD');
        $stmt = $pdo->prepare('INSERT INTO pwf_orders (order_code, customer_id, order_date, due_date, product_name, specification, dimensions, quantity, image_path, assigned_craftsman_id, notes, created_by)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$code, $customerId, $orderDate, $dueDate ?: null, $productName, $specification, $dimensions, $quantity, $imagePath, $assignedCraftsmanId, $notes, $_SESSION['user_id'] ?? null]);
        $msg = 'Pesanan berhasil disimpan.';
    }
}

$customers = $pdo->query('SELECT id, customer_name FROM pwf_customers ORDER BY customer_name')->fetchAll();
$craftsmen = $pdo->query('SELECT id, craftsman_name FROM pwf_craftsmen WHERE is_active=1 ORDER BY craftsman_name')->fetchAll();
$orders = $pdo->query("SELECT o.*, c.customer_name, t.craftsman_name
    FROM pwf_orders o
    LEFT JOIN pwf_customers c ON c.id=o.customer_id
    LEFT JOIN pwf_craftsmen t ON t.id=o.assigned_craftsman_id
    ORDER BY o.id DESC LIMIT 30")->fetchAll();

pwfOfficeHeader('Order Customer', 'orders');
?>
<div class="grid2">
    <div class="card">
        <h3 style="margin-top:0;">Input Pesanan Customer</h3>
        <?php if ($msg): ?><p style="color:green; margin:8px 0;"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
        <form method="post" enctype="multipart/form-data">
            <div class="mt"><label>Customer</label><select class="select" name="customer_id" required>
                    <option value="">- Pilih Customer -</option>
                    <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['customer_name']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="grid2 mt">
                <div><label>Tanggal Order</label><input class="input" type="date" name="order_date" value="<?= date('Y-m-d') ?>"></div>
                <div><label>Deadline</label><input class="input" type="date" name="due_date"></div>
            </div>
            <div class="mt"><input class="input" name="product_name" placeholder="Nama Produk" required></div>
            <div class="mt"><textarea name="specification" placeholder="Detail pesanan / bahan / warna / finishing"></textarea></div>
            <div class="grid2 mt">
                <div><input class="input" name="dimensions" placeholder="Ukuran (P x L x T)"></div>
                <div><input class="input" type="number" step="0.01" name="quantity" value="1"></div>
            </div>
            <div class="mt"><label>Foto / Blueprint</label><input class="input" type="file" name="order_image" accept=".jpg,.jpeg,.png,.webp"></div>
            <div class="mt"><label>Pilih Tukang</label><select class="select" name="assigned_craftsman_id">
                    <option value="">- Belum ditentukan -</option>
                    <?php foreach ($craftsmen as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['craftsman_name']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="mt"><textarea name="notes" placeholder="Catatan tambahan"></textarea></div>
            <div class="mt"><button class="btn" type="submit">Simpan Pesanan</button></div>
        </form>
    </div>
    <div class="card">
        <h3 style="margin-top:0;">Daftar Pesanan</h3>
        <table>
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Customer</th>
                    <th>Produk</th>
                    <th>Tukang</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><?= htmlspecialchars($o['order_code']) ?></td>
                        <td><?= htmlspecialchars($o['customer_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($o['product_name']) ?> <div class="small"><?= htmlspecialchars($o['dimensions'] ?? '-') ?></div>
                        </td>
                        <td><?= htmlspecialchars($o['craftsman_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($o['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php pwfOfficeFooter();
