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
        $msg = 'Customer berhasil ditambahkan.';
    }
}

$rows = $pdo->query('SELECT * FROM pwf_customers ORDER BY id DESC')->fetchAll();

pwfOfficeHeader('Data Customer', 'customers');
?>
<div class="grid2">
    <div class="card">
        <h3 style="margin-top:0;">Input Customer</h3>
        <?php if ($msg): ?><p style="color:green; margin:8px 0;"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
        <form method="post">
            <div class="mt"><input class="input" name="customer_name" placeholder="Nama Customer" required></div>
            <div class="mt"><input class="input" name="phone" placeholder="No. HP"></div>
            <div class="mt"><textarea name="address" placeholder="Alamat"></textarea></div>
            <div class="mt"><textarea name="notes" placeholder="Catatan"></textarea></div>
            <div class="mt"><button class="btn" type="submit">Simpan</button></div>
        </form>
    </div>
    <div class="card">
        <h3 style="margin-top:0;">List Customer</h3>
        <table>
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama</th>
                    <th>HP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['customer_code']) ?></td>
                        <td><?= htmlspecialchars($r['customer_name']) ?></td>
                        <td><?= htmlspecialchars($r['phone']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php pwfOfficeFooter();
