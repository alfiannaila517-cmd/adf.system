<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$pdo = getPwfOfficePdo();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['craftsman_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $specialty = trim($_POST['specialty'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($name !== '') {
        $code = genPwfCode($pdo, 'TKG');
        $stmt = $pdo->prepare('INSERT INTO pwf_craftsmen (craftsman_code, craftsman_name, phone, specialty, notes) VALUES (?,?,?,?,?)');
        $stmt->execute([$code, $name, $phone, $specialty, $notes]);
        $msg = 'Data tukang berhasil ditambahkan.';
    }
}

$rows = $pdo->query('SELECT * FROM pwf_craftsmen ORDER BY id DESC')->fetchAll();

pwfOfficeHeader('Data Tukang / Pengrajin', 'craftsmen');
?>
<div class="grid2">
    <div class="card">
        <h3 style="margin-top:0;">Input Tukang</h3>
        <?php if ($msg): ?><p style="color:green; margin:8px 0;"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
        <form method="post">
            <div class="mt"><input class="input" name="craftsman_name" placeholder="Nama Tukang" required></div>
            <div class="mt"><input class="input" name="phone" placeholder="No. HP"></div>
            <div class="mt"><input class="input" name="specialty" placeholder="Spesialisasi (ukir, finishing, dll)"></div>
            <div class="mt"><textarea name="notes" placeholder="Catatan"></textarea></div>
            <div class="mt"><button class="btn" type="submit">Simpan</button></div>
        </form>
    </div>
    <div class="card">
        <h3 style="margin-top:0;">List Tukang</h3>
        <table>
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama</th>
                    <th>Spesialisasi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['craftsman_code']) ?></td>
                        <td><?= htmlspecialchars($r['craftsman_name']) ?></td>
                        <td><?= htmlspecialchars($r['specialty']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php pwfOfficeFooter();
