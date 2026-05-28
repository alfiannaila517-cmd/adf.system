<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';

$pdo = getPwfOfficePdo();

// ── EXPORT CSV ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filterCid  = (int)($_GET['customer_id']  ?? 0);
    $filterTid  = (int)($_GET['craftsman_id'] ?? 0);
    $where = 'WHERE 1=1';
    if ($filterCid) $where .= ' AND o.customer_id=' . $filterCid;
    if ($filterTid) $where .= ' AND o.assigned_craftsman_id=' . $filterTid;
    $rows = $pdo->query("
        SELECT o.order_code, c.customer_name, o.order_date, o.due_date,
               o.product_name, o.specification, o.dimensions, o.quantity,
               t.craftsman_name, o.status, o.notes, o.created_at
        FROM pwf_orders o
        LEFT JOIN pwf_customers c ON c.id=o.customer_id
        LEFT JOIN pwf_craftsmen t ON t.id=o.assigned_craftsman_id
        $where ORDER BY o.id DESC")->fetchAll();
    $suffix = $filterCid ? '_cus'.$filterCid : ($filterTid ? '_tkg'.$filterTid : '_all');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="orders'.$suffix.'_'.date('Ymd_His').'.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Order Code','Customer','Order Date','Due Date','Product','Specification','Dimensions','Qty','Craftsman','Status','Notes','Created At']);
    foreach ($rows as $r) fputcsv($out, [$r['order_code'],$r['customer_name'],$r['order_date'],$r['due_date'],$r['product_name'],$r['specification'],$r['dimensions'],$r['quantity'],$r['craftsman_name'],$r['status'],$r['notes'],$r['created_at']]);
    fclose($out);
    exit;
}

require_once __DIR__ . '/layout.php';
$msg = ''; $msgType = 'success';

// ── Helper: upload image ──────────────────────────────────────────────────────
function uploadOrderImage(string $field): ?string {
    $file = $_FILES[$field] ?? null;
    if (!$file || empty($file['name']) || !is_uploaded_file($file['tmp_name'])) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'])) return null;
    if ($file['size'] > 8*1024*1024) return null;
    $dir = BASE_PATH . '/uploads/pwf-orders/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = 'pwf-'.time().'-'.mt_rand(100,999).'.'.$ext;
    return @move_uploaded_file($file['tmp_name'], $dir.$name) ? 'uploads/pwf-orders/'.$name : null;
}

// ── POST HANDLERS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? 'create';

    if ($action === 'create') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $productName = trim($_POST['product_name'] ?? '');
        if ($customerId > 0 && $productName !== '') {
            $imgPath = uploadOrderImage('order_image');
            $code = genPwfCode($pdo, 'ORD');
            $pdo->prepare('INSERT INTO pwf_orders
                (order_code,customer_id,order_date,due_date,product_name,specification,dimensions,quantity,image_path,assigned_craftsman_id,notes,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$code,
                    $customerId,
                    $_POST['order_date'] ?? date('Y-m-d'),
                    $_POST['due_date'] ?: null,
                    $productName,
                    trim($_POST['specification'] ?? ''),
                    trim($_POST['dimensions'] ?? ''),
                    (float)($_POST['quantity'] ?? 1),
                    $imgPath,
                    (int)($_POST['assigned_craftsman_id'] ?? 0) ?: null,
                    trim($_POST['notes'] ?? ''),
                    $_SESSION['user_id'] ?? null
                ]);
            $msg = 'Order saved successfully.';
        }
    }

    elseif ($action === 'update') {
        $id = (int)($_POST['order_id'] ?? 0);
        if ($id > 0) {
            // Get existing image
            $existImg = $pdo->prepare('SELECT image_path FROM pwf_orders WHERE id=?');
            $existImg->execute([$id]);
            $existImg = $existImg->fetchColumn();

            $newImg = uploadOrderImage('order_image');
            $imgPath = $newImg ?: $existImg;

            $pdo->prepare('UPDATE pwf_orders SET
                customer_id=?, order_date=?, due_date=?, product_name=?,
                specification=?, dimensions=?, quantity=?, image_path=?,
                assigned_craftsman_id=?, status=?, notes=?, updated_at=NOW()
                WHERE id=?')
                ->execute([
                    (int)($_POST['customer_id'] ?? 0),
                    $_POST['order_date'] ?? date('Y-m-d'),
                    $_POST['due_date'] ?: null,
                    trim($_POST['product_name'] ?? ''),
                    trim($_POST['specification'] ?? ''),
                    trim($_POST['dimensions'] ?? ''),
                    (float)($_POST['quantity'] ?? 1),
                    $imgPath,
                    (int)($_POST['assigned_craftsman_id'] ?? 0) ?: null,
                    $_POST['status'] ?? 'draft',
                    trim($_POST['notes'] ?? ''),
                    $id
                ]);
            $msg = 'Order updated successfully.';
        }
    }
}

// ── DATA ──────────────────────────────────────────────────────────────────────
$customers = $pdo->query('SELECT id, customer_name FROM pwf_customers ORDER BY customer_name')->fetchAll();
$craftsmen = $pdo->query('SELECT id, craftsman_name FROM pwf_craftsmen WHERE is_active=1 ORDER BY craftsman_name')->fetchAll();

// Filter by customer / craftsman
$filterCustomerId  = (int)($_GET['customer_id']  ?? 0);
$filterCraftsmanId = (int)($_GET['craftsman_id'] ?? 0);
$whereParts = [];
if ($filterCustomerId)  $whereParts[] = 'o.customer_id='  . $filterCustomerId;
if ($filterCraftsmanId) $whereParts[] = 'o.assigned_craftsman_id=' . $filterCraftsmanId;
$whereClause = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$orders = $pdo->query("
    SELECT o.*, c.customer_name, t.craftsman_name
    FROM pwf_orders o
    LEFT JOIN pwf_customers c ON c.id=o.customer_id
    LEFT JOIN pwf_craftsmen t ON t.id=o.assigned_craftsman_id
    $whereClause
    ORDER BY o.id DESC
")->fetchAll();

$statusOptions = ['draft'=>'Draft','on_progress'=>'On Progress','qc'=>'QC','ready_ship'=>'Ready to Ship','shipped'=>'Shipped','completed'=>'Completed','cancelled'=>'Cancelled'];

pwfOfficeHeader('Orders', 'orders');
?>

<style>
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;display:none;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:16px;width:min(700px,96vw);max-height:90vh;overflow-y:auto;
  box-shadow:0 24px 60px rgba(0,0,0,.3)}
.modal-header{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.modal-header h5{margin:0;font-size:16px;font-weight:700}
.modal-body{padding:24px}
.modal-close{background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1}
.filter-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:14px 16px;background:#FAFAF9;border-bottom:1px solid var(--border)}
.filter-bar select,.filter-bar input{flex:1;min-width:160px;max-width:260px}
</style>

<div class="grid2" style="align-items:start">

  <!-- LEFT: NEW ORDER FORM -->
  <div class="pwf-card">
    <div class="pwf-card-header"><i class="bi bi-plus-circle me-2" style="color:var(--gold)"></i>New Order</div>
    <div class="pwf-card-body">
    <?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_action" value="create">
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

  <!-- RIGHT: ORDER LIST -->
  <div class="pwf-card">
    <!-- Filter bar -->
    <div class="filter-bar">
      <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;width:100%;align-items:center">
        <select class="select" name="customer_id" onchange="this.form.submit()" style="flex:1;min-width:160px">
          <option value="">All Customers</option>
          <?php foreach ($customers as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $filterCustomerId == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['customer_name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select class="select" name="craftsman_id" onchange="this.form.submit()" style="flex:1;min-width:160px">
          <option value="">All Craftsmen</option>
          <?php foreach ($craftsmen as $t): ?>
            <option value="<?= $t['id'] ?>" <?= ($filterCraftsmanId??0) == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['craftsman_name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div style="display:flex;gap:6px;margin-left:auto;align-items:center">
          <?php if ($filterCustomerId): ?>
            <a href="customer-report.php?customer_id=<?= $filterCustomerId ?>" target="_blank"
               class="btn btn-export btn-sm"><i class="bi bi-printer"></i> Print</a>
          <?php endif; ?>
          <a href="?export=csv&customer_id=<?= $filterCustomerId ?>&craftsman_id=<?= $filterCraftsmanId??0 ?>"
             class="btn btn-export btn-sm"><i class="bi bi-download"></i> Export CSV</a>
          <?php if ($filterCustomerId || ($filterCraftsmanId??0)): ?>
            <a href="orders.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i></a>
          <?php endif; ?>
        </div>
      </form>
    </div>
    <div style="padding:0;overflow-x:auto">
    <table class="pwf-table">
      <thead><tr><th>Code</th><th>Customer</th><th>Product</th><th>Craftsman</th><th>Status</th><th style="width:110px">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td><code style="font-size:12px;color:var(--gold)"><?= htmlspecialchars($o['order_code']) ?></code></td>
            <td><?= htmlspecialchars($o['customer_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($o['product_name']) ?><div class="small"><?= htmlspecialchars($o['dimensions'] ?? '') ?></div></td>
            <td><?= htmlspecialchars($o['craftsman_name'] ?? '—') ?></td>
            <td><span class="status-badge status-<?= htmlspecialchars($o['status']) ?>"><?= htmlspecialchars(str_replace('_',' ',$o['status'])) ?></span></td>
            <td>
              <div style="display:flex;gap:4px">
                <button class="btn btn-sm btn-outline-secondary" title="Edit"
                  onclick="openEdit(<?= htmlspecialchars(json_encode($o), ENT_QUOTES) ?>)">
                  <i class="bi bi-pencil"></i>
                </button>
                <a href="spk.php?id=<?= (int)$o['id'] ?>" target="_blank"
                   class="btn btn-sm btn-outline-secondary" title="Print SPK">
                  <i class="bi bi-file-earmark-text"></i>
                </a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if(empty($orders)): ?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px">No orders found.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ── EDIT MODAL ──────────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box">
    <div class="modal-header">
      <h5><i class="bi bi-pencil-square me-2" style="color:var(--gold)"></i>Edit Order — <span id="editModalCode"></span></h5>
      <button class="modal-close" onclick="closeEdit()">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" enctype="multipart/form-data" id="editForm">
        <input type="hidden" name="_action" value="update">
        <input type="hidden" name="order_id" id="ef_id">
        <div class="grid2">
          <div class="pwf-form-group"><label>Customer</label>
            <select class="select" name="customer_id" id="ef_customer" required>
              <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['customer_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="pwf-form-group"><label>Status</label>
            <select class="select" name="status" id="ef_status">
              <?php foreach ($statusOptions as $val => $lbl): ?><option value="<?= $val ?>"><?= $lbl ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="grid2">
          <div class="pwf-form-group"><label>Order Date</label><input class="input" type="date" name="order_date" id="ef_order_date"></div>
          <div class="pwf-form-group"><label>Deadline</label><input class="input" type="date" name="due_date" id="ef_due_date"></div>
        </div>
        <div class="pwf-form-group"><label>Product Name</label><input class="input" name="product_name" id="ef_product" required></div>
        <div class="pwf-form-group"><label>Specification</label><textarea name="specification" id="ef_spec" placeholder="Material, color, finishing..."></textarea></div>
        <div class="grid2">
          <div class="pwf-form-group"><label>Dimensions</label><input class="input" name="dimensions" id="ef_dim"></div>
          <div class="pwf-form-group"><label>Quantity</label><input class="input" type="number" step="0.01" name="quantity" id="ef_qty"></div>
        </div>
        <div class="pwf-form-group">
          <label>Blueprint / Photo</label>
          <div id="ef_img_preview" style="margin-bottom:8px"></div>
          <input class="input" type="file" name="order_image" accept=".jpg,.jpeg,.png,.webp">
          <div class="small" style="margin-top:4px">Leave empty to keep current image</div>
        </div>
        <div class="pwf-form-group"><label>Assign Craftsman</label>
          <select class="select" name="assigned_craftsman_id" id="ef_craftsman">
            <option value="">— Not assigned —</option>
            <?php foreach ($craftsmen as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['craftsman_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="pwf-form-group"><label>Notes</label><textarea name="notes" id="ef_notes"></textarea></div>
        <div style="display:flex;gap:8px">
          <button class="btn" type="submit"><i class="bi bi-check-circle"></i> Save Changes</button>
          <button type="button" class="btn btn-outline-secondary" onclick="closeEdit()">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openEdit(o) {
    document.getElementById('ef_id').value = o.id;
    document.getElementById('editModalCode').textContent = o.order_code;
    document.getElementById('ef_customer').value = o.customer_id;
    document.getElementById('ef_status').value = o.status;
    document.getElementById('ef_order_date').value = o.order_date;
    document.getElementById('ef_due_date').value = o.due_date || '';
    document.getElementById('ef_product').value = o.product_name;
    document.getElementById('ef_spec').value = o.specification || '';
    document.getElementById('ef_dim').value = o.dimensions || '';
    document.getElementById('ef_qty').value = o.quantity;
    document.getElementById('ef_craftsman').value = o.assigned_craftsman_id || '';
    document.getElementById('ef_notes').value = o.notes || '';
    const prev = document.getElementById('ef_img_preview');
    if (o.image_path) {
        const base = '<?= rtrim(BASE_URL, '/') ?>';
        prev.innerHTML = `<img src="${base}/${o.image_path}" style="max-height:120px;border-radius:8px;border:1px solid #E7E5E4">`;
    } else {
        prev.innerHTML = '<span style="font-size:12px;color:var(--muted)">No image yet</span>';
    }
    document.getElementById('editModal').classList.add('open');
}
function closeEdit() {
    document.getElementById('editModal').classList.remove('open');
}
document.getElementById('editModal').addEventListener('click', function(e){
    if (e.target === this) closeEdit();
});
</script>

<?php pwfOfficeFooter();
