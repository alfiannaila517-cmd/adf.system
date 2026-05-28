<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$pdo = getPwfOfficePdo();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['customer_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($name !== '') {
        $code = genPwfCode($pdo, 'CUS');
        $stmt = $pdo->prepare('INSERT INTO pwf_customers (customer_code, customer_name, phone, address, notes) VALUES (?,?,?,?,?)');
        $stmt->execute([$code, $name, $phone, $address, $notes]);
        $msg = 'Customer added successfully.';
    }
}

$rows = $pdo->query('SELECT * FROM pwf_customers ORDER BY id DESC')->fetchAll();

pwfOfficeHeader('Customers', 'customers');
?>
<div class="grid2">
    <div class="pwf-card">
        <div class="pwf-card-header">Add New Customer</div>
        <div class="pwf-card-body">
        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <form method="post">
            <div class="pwf-form-group"><label>Customer Name</label><input class="input" name="customer_name" placeholder="Full name" required></div>
            <div class="pwf-form-group"><label>Phone / WhatsApp</label><input class="input" name="phone" placeholder="+62 ..."></div>
            <div class="pwf-form-group"><label>Address</label><textarea name="address" placeholder="Full address"></textarea></div>
            <div class="pwf-form-group"><label>Notes</label><textarea name="notes" placeholder="Additional notes"></textarea></div>
            <button class="btn" type="submit"><i class="bi bi-plus-circle"></i> Save Customer</button>
        </form>
        </div>
    </div>
    <div class="pwf-card">
        <div class="pwf-card-header">Customer List</div>
        <div style="padding:0">
        <table class="pwf-table">
            <thead><tr><th>Code</th><th>Name</th><th>Phone</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><code style="font-size:12px;color:var(--gold)"><?= htmlspecialchars($r['customer_code']) ?></code></td>
                        <td><?= htmlspecialchars($r['customer_name']) ?></td>
                        <td><?= htmlspecialchars($r['phone']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if(empty($rows)): ?><tr><td colspan="3" style="text-align:center;color:var(--muted);padding:24px">No customers yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php pwfOfficeFooter();
