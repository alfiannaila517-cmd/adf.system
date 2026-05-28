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
        $msg = 'Craftsman added successfully.';
    }
}

$rows = $pdo->query('SELECT * FROM pwf_craftsmen ORDER BY id DESC')->fetchAll();

pwfOfficeHeader('Craftsmen', 'craftsmen');
?>
<div class="grid2">
    <div class="pwf-card">
        <div class="pwf-card-header">Add Craftsman</div>
        <div class="pwf-card-body">
        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <form method="post">
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
            <thead><tr><th>Code</th><th>Name</th><th>Specialty</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><code style="font-size:12px;color:var(--gold)"><?= htmlspecialchars($r['craftsman_code']) ?></code></td>
                        <td><?= htmlspecialchars($r['craftsman_name']) ?></td>
                        <td><?= htmlspecialchars($r['specialty']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if(empty($rows)): ?><tr><td colspan="3" style="text-align:center;color:var(--muted);padding:24px">No craftsmen yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php pwfOfficeFooter();
