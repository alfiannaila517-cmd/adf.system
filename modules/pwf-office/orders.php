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
        $customerId  = (int)($_POST['customer_id'] ?? 0);
        $productName = trim($_POST['product_name'] ?? '');
        if ($customerId > 0 && $productName !== '') {
            $imgPath = uploadOrderImage('order_image');
            $code = genPwfCode($pdo, 'ORD');
            $pdo->prepare('INSERT INTO pwf_orders
                (order_code,customer_id,order_date,due_date,product_name,specification,dimensions,quantity,image_path,assigned_craftsman_id,notes,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$code, $customerId,
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
            $existImg = $pdo->prepare('SELECT image_path FROM pwf_orders WHERE id=?');
            $existImg->execute([$id]);
            $existImg = $existImg->fetchColumn();
            $imgPath = uploadOrderImage('order_image') ?: $existImg;
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

    elseif ($action === 'start_work') {
        $id = (int)($_POST['order_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE pwf_orders SET status=?, updated_at=NOW() WHERE id=? AND status=?')
                ->execute(['on_progress', $id, 'draft']);
            $msg = 'Order status changed to On Progress.';
        }
    }
}

// ── DATA ──────────────────────────────────────────────────────────────────────
$customers = $pdo->query('SELECT id, customer_name FROM pwf_customers ORDER BY customer_name')->fetchAll();
$craftsmen = $pdo->query('SELECT id, craftsman_name FROM pwf_craftsmen WHERE is_active=1 ORDER BY craftsman_name')->fetchAll();

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
$baseUrl = rtrim(BASE_URL, '/');

pwfOfficeHeader('Orders', 'orders');
?>

<style>
/* ── MODALS ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(2px);z-index:9000;display:none;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:16px;width:min(720px,96vw);max-height:92vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.25)}
.modal-header{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:1}
.modal-header h5{margin:0;font-size:15px;font-weight:700}
.modal-body{padding:22px}
.modal-close{background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;padding:2px 6px;border-radius:6px}
.modal-close:hover{background:#F5F3F0;color:var(--text)}
/* ── FILTER BAR ── */
.filter-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:12px 16px;background:#FAFAF9;border-bottom:1px solid var(--border)}
/* ── ORDER CARD GRID ── */
.order-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;padding:16px}
.order-card{background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden;transition:box-shadow .2s,transform .15s;display:flex;flex-direction:column}
.order-card:hover{box-shadow:0 6px 24px rgba(0,0,0,.09);transform:translateY(-2px)}
.order-card-img{width:100%;height:140px;object-fit:cover;background:#F5F3F0;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:13px}
.order-card-img img{width:100%;height:140px;object-fit:cover}
.order-card-body{padding:12px 14px;flex:1;display:flex;flex-direction:column;gap:5px}
.order-card-code{font-size:11px;color:var(--gold);font-weight:700;font-family:monospace}
.order-card-name{font-size:13.5px;font-weight:700;color:var(--text);line-height:1.3}
.order-card-meta{font-size:11.5px;color:var(--muted);display:flex;align-items:center;gap:5px}
.order-card-footer{padding:10px 14px;border-top:1px solid var(--border);display:flex;align-items:center;gap:6px;flex-wrap:wrap}
/* toggle view */
.view-toggle{display:flex;gap:4px}
.view-btn{background:transparent;border:1px solid var(--border);color:var(--muted);padding:4px 9px;border-radius:6px;cursor:pointer;font-size:13px;transition:all .15s}
.view-btn.active{background:var(--gold-bg);border-color:var(--gold-border);color:#92600A}
/* table view */
#tableView{display:none}
#tableView.visible{display:block}
#cardView{display:block}
#cardView.hidden{display:none}
</style>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'success' ? 'success' : 'warning' ?>" style="margin-bottom:16px">
  <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- ── TOP BAR ──────────────────────────────────────────────────────────────── -->
<div class="pwf-card" style="margin-bottom:16px">
  <div class="filter-bar">
    <form method="get" style="display:contents">
      <select class="select" name="customer_id" onchange="this.form.submit()" style="width:180px;flex:0 0 auto">
        <option value="">All Customers</option>
        <?php foreach ($customers as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $filterCustomerId == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['customer_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select class="select" name="craftsman_id" onchange="this.form.submit()" style="width:180px;flex:0 0 auto">
        <option value="">All Craftsmen</option>
        <?php foreach ($craftsmen as $t): ?>
          <option value="<?= $t['id'] ?>" <?= $filterCraftsmanId == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['craftsman_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($filterCustomerId || $filterCraftsmanId): ?>
        <a href="orders.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i> Clear</a>
      <?php endif; ?>
    </form>

    <div style="margin-left:auto;display:flex;gap:6px;align-items:center">
      <div class="view-toggle">
        <button class="view-btn active" id="btnCard" onclick="setView('card')" title="Card view"><i class="bi bi-grid-3x3-gap"></i></button>
        <button class="view-btn" id="btnTable" onclick="setView('table')" title="Table view"><i class="bi bi-list-ul"></i></button>
      </div>
      <?php if ($filterCustomerId): ?>
        <a href="customer-report.php?customer_id=<?= $filterCustomerId ?>" target="_blank" class="btn btn-export btn-sm"><i class="bi bi-printer"></i> Print</a>
      <?php endif; ?>
      <a href="?export=csv&customer_id=<?= $filterCustomerId ?>&craftsman_id=<?= $filterCraftsmanId ?>"
         class="btn btn-export btn-sm"><i class="bi bi-download"></i> Export</a>
      <button class="btn btn-sm" onclick="openCreate()" style="gap:6px">
        <i class="bi bi-plus-lg"></i> New Order
      </button>
    </div>
  </div>
</div>

<!-- ── CARD VIEW ────────────────────────────────────────────────────────────── -->
<div id="cardView">
  <?php if (empty($orders)): ?>
    <div style="text-align:center;padding:60px 20px;color:var(--muted)">
      <i class="bi bi-clipboard2-x" style="font-size:40px;display:block;margin-bottom:12px;opacity:.4"></i>
      No orders found.
    </div>
  <?php else: ?>
  <div class="order-grid">
    <?php foreach ($orders as $o): ?>
    <div class="order-card">
      <?php if ($o['image_path']): ?>
        <div class="order-card-img"><img src="<?= $baseUrl ?>/<?= htmlspecialchars($o['image_path']) ?>" alt="blueprint" loading="lazy"></div>
      <?php else: ?>
        <div class="order-card-img"><i class="bi bi-image" style="font-size:32px;opacity:.3"></i></div>
      <?php endif; ?>
      <div class="order-card-body">
        <div class="order-card-code"><?= htmlspecialchars($o['order_code']) ?></div>
        <div class="order-card-name"><?= htmlspecialchars($o['product_name']) ?></div>
        <div class="order-card-meta"><i class="bi bi-person"></i><?= htmlspecialchars($o['customer_name'] ?? '—') ?></div>
        <?php if ($o['craftsman_name']): ?>
        <div class="order-card-meta"><i class="bi bi-hammer"></i><?= htmlspecialchars($o['craftsman_name']) ?></div>
        <?php endif; ?>
        <?php if ($o['dimensions']): ?>
        <div class="order-card-meta"><i class="bi bi-rulers"></i><?= htmlspecialchars($o['dimensions']) ?></div>
        <?php endif; ?>
        <?php if ($o['due_date']): ?>
        <div class="order-card-meta"><i class="bi bi-calendar-event"></i>Due: <?= date('d M Y', strtotime($o['due_date'])) ?></div>
        <?php endif; ?>
      </div>
      <div class="order-card-footer">
        <span class="status-badge status-<?= htmlspecialchars($o['status']) ?>"><?= htmlspecialchars(str_replace('_',' ',$o['status'])) ?></span>
        <div style="margin-left:auto;display:flex;gap:5px">
          <?php if ($o['status'] === 'draft'): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Start work on this order?')">
            <input type="hidden" name="_action" value="start_work">
            <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
            <button class="btn btn-sm" type="submit" title="Start Work"
              style="background:#FFF7ED;border:1px solid #FED7AA;color:#C2410C;padding:4px 9px;font-size:11px">
              <i class="bi bi-play-circle"></i> Start
            </button>
          </form>
          <?php endif; ?>
          <button class="btn btn-sm btn-outline-secondary" title="Edit"
            onclick="openEdit(<?= htmlspecialchars(json_encode($o), ENT_QUOTES) ?>)">
            <i class="bi bi-pencil"></i>
          </button>
          <a href="spk.php?id=<?= (int)$o['id'] ?>" target="_blank"
             class="btn btn-sm btn-outline-secondary" title="Print SPK">
            <i class="bi bi-file-earmark-text"></i>
          </a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ── TABLE VIEW ───────────────────────────────────────────────────────────── -->
<div id="tableView">
  <div class="pwf-card">
    <div style="overflow-x:auto">
    <table class="pwf-table">
      <thead><tr><th style="width:60px">Image</th><th>Code</th><th>Customer</th><th>Product</th><th>Craftsman</th><th>Due</th><th>Status</th><th style="width:130px">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td>
              <?php if ($o['image_path']): ?>
                <img src="<?= $baseUrl ?>/<?= htmlspecialchars($o['image_path']) ?>" style="width:44px;height:44px;object-fit:cover;border-radius:7px;border:1px solid var(--border)">
              <?php else: ?>
                <div style="width:44px;height:44px;border-radius:7px;background:#F5F3F0;display:flex;align-items:center;justify-content:center"><i class="bi bi-image" style="color:var(--muted);font-size:16px"></i></div>
              <?php endif; ?>
            </td>
            <td><code style="font-size:11px;color:var(--gold)"><?= htmlspecialchars($o['order_code']) ?></code></td>
            <td><?= htmlspecialchars($o['customer_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($o['product_name']) ?><div class="small"><?= htmlspecialchars($o['dimensions'] ?? '') ?></div></td>
            <td><?= htmlspecialchars($o['craftsman_name'] ?? '—') ?></td>
            <td><?= $o['due_date'] ? date('d M Y', strtotime($o['due_date'])) : '—' ?></td>
            <td><span class="status-badge status-<?= htmlspecialchars($o['status']) ?>"><?= htmlspecialchars(str_replace('_',' ',$o['status'])) ?></span></td>
            <td>
              <div style="display:flex;gap:4px;align-items:center">
                <?php if ($o['status'] === 'draft'): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Start work?')">
                  <input type="hidden" name="_action" value="start_work">
                  <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                  <button class="btn btn-sm" type="submit"
                    style="background:#FFF7ED;border:1px solid #FED7AA;color:#C2410C;padding:4px 8px;font-size:11px">
                    <i class="bi bi-play-circle"></i>
                  </button>
                </form>
                <?php endif; ?>
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
        <?php if(empty($orders)): ?><tr><td colspan="8" style="text-align:center;color:var(--muted);padding:24px">No orders found.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ── NEW ORDER MODAL ───────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="createModal">
  <div class="modal-box">
    <div class="modal-header">
      <h5><i class="bi bi-plus-circle me-2" style="color:var(--gold)"></i>New Order</h5>
      <button class="modal-close" onclick="document.getElementById('createModal').classList.remove('open')">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="_action" value="create">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="pwf-form-group" style="grid-column:1/-1"><label>Customer</label>
            <select class="select" name="customer_id" required>
              <option value="">— Select Customer —</option>
              <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['customer_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="pwf-form-group"><label>Order Date</label><input class="input" type="date" name="order_date" value="<?= date('Y-m-d') ?>"></div>
          <div class="pwf-form-group"><label>Deadline</label><input class="input" type="date" name="due_date"></div>
          <div class="pwf-form-group" style="grid-column:1/-1"><label>Product Name</label><input class="input" name="product_name" placeholder="Product name" required></div>
          <div class="pwf-form-group" style="grid-column:1/-1"><label>Specification</label><textarea name="specification" placeholder="Material, color, finishing details..."></textarea></div>
          <div class="pwf-form-group"><label>Dimensions (L×W×H)</label><input class="input" name="dimensions" placeholder="e.g. 120×60×75 cm"></div>
          <div class="pwf-form-group"><label>Quantity</label><input class="input" type="number" step="0.01" name="quantity" value="1"></div>
          <div class="pwf-form-group" style="grid-column:1/-1"><label>Photo / Blueprint</label><input class="input" type="file" name="order_image" accept=".jpg,.jpeg,.png,.webp"></div>
          <div class="pwf-form-group" style="grid-column:1/-1"><label>Assign Craftsman</label>
            <select class="select" name="assigned_craftsman_id">
              <option value="">— Not assigned yet —</option>
              <?php foreach ($craftsmen as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['craftsman_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="pwf-form-group" style="grid-column:1/-1"><label>Notes</label><textarea name="notes" placeholder="Additional notes"></textarea></div>
        </div>
        <div style="display:flex;gap:8px;margin-top:4px">
          <button class="btn" type="submit"><i class="bi bi-plus-circle"></i> Save Order</button>
          <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('createModal').classList.remove('open')">Cancel</button>
        </div>
      </form>
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
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
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
          <div class="pwf-form-group"><label>Order Date</label><input class="input" type="date" name="order_date" id="ef_order_date"></div>
          <div class="pwf-form-group"><label>Deadline</label><input class="input" type="date" name="due_date" id="ef_due_date"></div>
          <div class="pwf-form-group" style="grid-column:1/-1"><label>Product Name</label><input class="input" name="product_name" id="ef_product" required></div>
          <div class="pwf-form-group" style="grid-column:1/-1"><label>Specification</label><textarea name="specification" id="ef_spec"></textarea></div>
          <div class="pwf-form-group"><label>Dimensions</label><input class="input" name="dimensions" id="ef_dim"></div>
          <div class="pwf-form-group"><label>Quantity</label><input class="input" type="number" step="0.01" name="quantity" id="ef_qty"></div>
          <div class="pwf-form-group" style="grid-column:1/-1">
            <label>Blueprint / Photo</label>
            <div id="ef_img_preview" style="margin-bottom:8px"></div>
            <input class="input" type="file" name="order_image" accept=".jpg,.jpeg,.png,.webp">
            <div style="font-size:11px;color:var(--muted);margin-top:3px">Leave empty to keep current image</div>
          </div>
          <div class="pwf-form-group" style="grid-column:1/-1"><label>Assign Craftsman</label>
            <select class="select" name="assigned_craftsman_id" id="ef_craftsman">
              <option value="">— Not assigned —</option>
              <?php foreach ($craftsmen as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['craftsman_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="pwf-form-group" style="grid-column:1/-1"><label>Notes</label><textarea name="notes" id="ef_notes"></textarea></div>
        </div>
        <div style="display:flex;gap:8px;margin-top:4px">
          <button class="btn" type="submit"><i class="bi bi-check-circle"></i> Save Changes</button>
          <button type="button" class="btn btn-outline-secondary" onclick="closeEdit()">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// ── VIEW TOGGLE ───────────────────────────────────────────────────────────────
function setView(v) {
    const saved = v;
    localStorage.setItem('pwf_order_view', saved);
    document.getElementById('cardView').classList.toggle('hidden', v === 'table');
    document.getElementById('tableView').classList.toggle('visible', v === 'table');
    document.getElementById('btnCard').classList.toggle('active', v === 'card');
    document.getElementById('btnTable').classList.toggle('active', v === 'table');
}
(function(){
    const v = localStorage.getItem('pwf_order_view') || 'card';
    if (v === 'table') setView('table');
})();

// ── MODALS ────────────────────────────────────────────────────────────────────
function openCreate() {
    document.getElementById('createModal').classList.add('open');
}
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
    prev.innerHTML = o.image_path
        ? `<img src="<?= $baseUrl ?>/${o.image_path}" style="max-height:130px;border-radius:8px;border:1px solid #E7E5E4">`
        : '<span style="font-size:11.5px;color:var(--muted)">No image yet</span>';
    document.getElementById('editModal').classList.add('open');
}
function closeEdit() {
    document.getElementById('editModal').classList.remove('open');
}
// Close on backdrop click
['createModal','editModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e){
        if (e.target === this) this.classList.remove('open');
    });
});

<?php if ($msg): ?>
// Auto-open modal on success not needed — message already shown inline
<?php endif; ?>
</script>
<?php pwfOfficeFooter();
