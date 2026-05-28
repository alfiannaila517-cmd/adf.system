<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$pdo = getPwfOfficePdo();
$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? 'create';
    $name    = trim($_POST['customer_name'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $notes   = trim($_POST['notes'] ?? '');

    if ($action === 'create') {
        if ($name !== '') {
            $code = genPwfCode($pdo, 'CUS');
            $stmt = $pdo->prepare('INSERT INTO pwf_customers (customer_code, customer_name, phone, address, notes) VALUES (?,?,?,?,?)');
            $stmt->execute([$code, $name, $phone, $address, $notes]);
            $msg = 'Customer added successfully.';
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['customer_id'] ?? 0);
        if ($id && $name !== '') {
            $stmt = $pdo->prepare('UPDATE pwf_customers SET customer_name=?, phone=?, address=?, notes=?, updated_at=NOW() WHERE id=?');
            $stmt->execute([$name, $phone, $address, $notes, $id]);
            $msg = 'Customer updated successfully.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['customer_id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM pwf_customers WHERE id=?')->execute([$id]);
            $msg = 'Customer deleted.';
            $msgType = 'warning';
        }
    }
}

$rows = $pdo->query('SELECT * FROM pwf_customers ORDER BY id DESC')->fetchAll();

pwfOfficeHeader('Customers', 'customers');
?>
<style>
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;display:none;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:16px;width:min(560px,96vw);max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.modal-header{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.modal-header h5{margin:0;font-size:16px;font-weight:700}
.modal-body{padding:24px}
.modal-close{background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1}
</style>

<div class="grid2">
    <div class="pwf-card">
        <div class="pwf-card-header">Add New Customer</div>
        <div class="pwf-card-body">
        <?php if ($msg): ?><div class="alert alert-<?= $msgType === 'warning' ? 'warning' : 'success' ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="_action" value="create">
            <div class="pwf-form-group"><label>Customer Name</label><input class="input" name="customer_name" placeholder="Full name" required></div>
            <div class="pwf-form-group"><label>Phone / WhatsApp</label><input class="input" name="phone" placeholder="+62 ..."></div>
            <div class="pwf-form-group"><label>Address</label><textarea name="address" placeholder="Full address"></textarea></div>
            <div class="pwf-form-group"><label>Notes</label><textarea name="notes" placeholder="Additional notes"></textarea></div>
            <button class="btn" type="submit"><i class="bi bi-plus-circle"></i> Save Customer</button>
        </form>
        </div>
    </div>
    <div class="pwf-card">
        <div class="pwf-card-header">Customer List <span class="badge bg-secondary ms-2" style="font-size:12px"><?= count($rows) ?></span></div>
        <div style="padding:0">
        <table class="pwf-table">
            <thead><tr><th>Code</th><th>Name</th><th>Phone</th><th style="width:80px">Action</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><code style="font-size:12px;color:var(--gold)"><?= htmlspecialchars($r['customer_code']) ?></code></td>
                        <td><?= htmlspecialchars($r['customer_name']) ?></td>
                        <td><?= htmlspecialchars($r['phone']) ?></td>
                        <td>
                            <button class="btn btn-sm btn-outline-secondary" title="Edit"
                                onclick="openEdit(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if(empty($rows)): ?><tr><td colspan="4" style="text-align:center;color:var(--muted);padding:24px">No customers yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box">
    <div class="modal-header">
      <h5><i class="bi bi-pencil-square me-2" style="color:var(--gold)"></i>Edit Customer — <span id="editModalCode"></span></h5>
      <button class="modal-close" onclick="closeEdit()">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" id="editForm">
        <input type="hidden" name="_action" value="update">
        <input type="hidden" name="customer_id" id="editId">
        <div class="pwf-form-group"><label>Customer Name</label><input class="input" name="customer_name" id="editName" required></div>
        <div class="pwf-form-group"><label>Phone / WhatsApp</label><input class="input" name="phone" id="editPhone"></div>
        <div class="pwf-form-group"><label>Address</label><textarea name="address" id="editAddress"></textarea></div>
        <div class="pwf-form-group"><label>Notes</label><textarea name="notes" id="editNotes"></textarea></div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <button class="btn" type="submit"><i class="bi bi-check-circle"></i> Save Changes</button>
          <button type="button" class="btn btn-outline-secondary" onclick="closeEdit()">Cancel</button>
          <button type="button" class="btn btn-outline-danger ms-auto" onclick="confirmDelete()"><i class="bi bi-trash"></i> Delete</button>
        </div>
      </form>
      <!-- hidden delete form -->
      <form method="post" id="deleteForm" style="display:none">
        <input type="hidden" name="_action" value="delete">
        <input type="hidden" name="customer_id" id="deleteId">
      </form>
    </div>
  </div>
</div>

<script>
function openEdit(c) {
    document.getElementById('editModalCode').textContent = c.customer_code;
    document.getElementById('editId').value     = c.id;
    document.getElementById('editName').value   = c.customer_name || '';
    document.getElementById('editPhone').value  = c.phone || '';
    document.getElementById('editAddress').value = c.address || '';
    document.getElementById('editNotes').value  = c.notes || '';
    document.getElementById('deleteId').value   = c.id;
    document.getElementById('editModal').classList.add('open');
}
function closeEdit() {
    document.getElementById('editModal').classList.remove('open');
}
function confirmDelete() {
    if (confirm('Delete this customer? This cannot be undone.')) {
        document.getElementById('deleteForm').submit();
    }
}
document.getElementById('editModal').addEventListener('click', function(e){
    if (e.target === this) closeEdit();
});
</script>
<?php pwfOfficeFooter();
