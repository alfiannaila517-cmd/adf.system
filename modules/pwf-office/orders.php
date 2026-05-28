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
        $msg = 'Order saved successfully.';
    }
}

$customers = $pdo->query('SELECT id, customer_name FROM pwf_customers ORDER BY customer_name')->fetchAll();
$craftsmen = $pdo->query('SELECT id, craftsman_name FROM pwf_craftsmen WHERE is_active=1 ORDER BY craftsman_name')->fetchAll();
$orders = $pdo->query("SELECT o.*, c.customer_name, t.craftsman_name
    FROM pwf_orders o
    LEFT JOIN pwf_customers c ON c.id=o.customer_id
    LEFT JOIN pwf_craftsmen t ON t.id=o.assigned_craftsman_id
    ORDER BY o.id DESC LIMIT 30")->fetchAll();

pwfOfficeHeader('Orders', 'orders');
?>
<div class="grid2">
    <div class="pwf-card">
        <div class="pwf-card-header">New Order</div>
        <div class="pwf-card-body">
        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <form method="post" enctype="multipart/form-data">
            <div class="pwf-form-group"><label>Customer</label>
                <select class="select" name="customer_id" required>
                    <option value="">— Select Customer —</option>
                    <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['customer_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="grid2">
                <div class="pwf-form-group"><label>Order Date</label><input class="input" type="date" name="order_date" value="<?= date('Y-m-d') ?>"></div>
                <div class="pwf-form-group"><label>Deadline</label><input class="input" type="date" name="due_date"></div>
            </div>
            <div class="pwf-form-group"><label>Product Name</label><input class="input" name="product_name" placeholder="Product name" required></div>
            <div class="pwf-form-group"><label>Specification</label><textarea name="specification" placeholder="Material, color, finishing details..."></textarea></div>
            <div class="grid2">
                <div class="pwf-form-group"><label>Dimensions (L×W×H)</label><input class="input" name="dimensions" placeholder="e.g. 120×60×75 cm"></div>
                <div class="pwf-form-group"><label>Quantity</label><input class="input" type="number" step="0.01" name="quantity" value="1"></div>
            </div>
            <div class="pwf-form-group"><label>Photo / Blueprint</label><input class="input" type="file" name="order_image" accept=".jpg,.jpeg,.png,.webp"></div>
            <div class="pwf-form-group"><label>Assign Craftsman</label>
                <select class="select" name="assigned_craftsman_id">
                    <option value="">— Not assigned yet —</option>
                    <?php foreach ($craftsmen as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['craftsman_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="pwf-form-group"><label>Notes</label><textarea name="notes" placeholder="Additional notes"></textarea></div>
            <button class="btn" type="submit"><i class="bi bi-plus-circle"></i> Save Order</button>
        </form>
        </div>
    </div>
    <div class="pwf-card">
        <div class="pwf-card-header">Order List</div>
        <div style="padding:0">
        <table class="pwf-table">
            <thead><tr><th>Code</th><th>Customer</th><th>Product</th><th>Craftsman</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><code style="font-size:12px;color:var(--gold)"><?= htmlspecialchars($o['order_code']) ?></code></td>
                        <td><?= htmlspecialchars($o['customer_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($o['product_name']) ?><div class="small"><?= htmlspecialchars($o['dimensions'] ?? '') ?></div></td>
                        <td><?= htmlspecialchars($o['craftsman_name'] ?? '—') ?></td>
                        <td><span class="status-badge status-<?= htmlspecialchars($o['status']) ?>"><?= htmlspecialchars(str_replace('_',' ',$o['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if(empty($orders)): ?><tr><td colspan="5" style="text-align:center;color:var(--muted);padding:24px">No orders yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php pwfOfficeFooter();
