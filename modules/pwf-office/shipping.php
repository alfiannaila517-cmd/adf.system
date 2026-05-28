<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';

$pdo = getPwfOfficePdo();

// ── PRINT SURAT JALAN ──────────────────────────────────────────────────────────
if (isset($_GET['print']) && (int)$_GET['print'] > 0) {
    $cid = (int)$_GET['print'];
    $container = $pdo->prepare("SELECT * FROM pwf_containers WHERE id=?");
    $container->execute([$cid]); $container = $container->fetch();
    if (!$container) { echo 'Container not found'; exit; }

    $items = $pdo->query("
        SELECT ci.qty_shipped, ci.notes AS item_notes,
               o.order_code, o.product_name, o.specification, o.dimensions, o.quantity,
               c.customer_name
        FROM pwf_container_items ci
        JOIN pwf_orders o ON o.id=ci.order_id
        LEFT JOIN pwf_customers c ON c.id=o.customer_id
        WHERE ci.container_id=$cid
        ORDER BY ci.id ASC
    ")->fetchAll();

    // Get company info
    $compInfo = [];
    try {
        $rs = $pdo->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('pwf_company_name','pwf_company_address','pwf_company_phone','pwf_login_logo')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $compInfo = $rs;
    } catch(Exception $e) {}
    $companyName = $compInfo['pwf_company_name'] ?? 'Prapen Wood Furniture';
    $companyAddr = $compInfo['pwf_company_address'] ?? 'Jl. Ngabul - Batealit No.KM. 5, Jepara';
    $companyPhone= $compInfo['pwf_company_phone'] ?? '';
    $logoUrl     = $compInfo['pwf_login_logo'] ?? '';

    $totalQty = array_sum(array_column($items, 'qty_shipped'));
    header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html><head><meta charset="utf-8">
<title>Surat Jalan – <?= htmlspecialchars($container['container_code']) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;font-size:12px;color:#111;background:#fff;padding:24px 30px}
.header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;padding-bottom:14px;border-bottom:2px solid #111}
.co-logo{width:60px;height:60px;object-fit:contain;margin-right:12px}
.co-name{font-size:16px;font-weight:700;margin-bottom:2px}
.co-sub{font-size:10.5px;color:#555;line-height:1.5}
.doc-title{text-align:right}
.doc-title h1{font-size:22px;font-weight:900;letter-spacing:1px;color:#111}
.doc-title .doc-no{font-size:12px;color:#555;margin-top:3px}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:20px;border:1px solid #ccc;border-radius:4px;overflow:hidden}
.info-box{padding:10px 14px;border-right:1px solid #ccc}
.info-box:last-child{border-right:none}
.info-box h4{font-size:9.5px;text-transform:uppercase;letter-spacing:.5px;color:#888;margin-bottom:6px}
.info-row{display:flex;gap:6px;margin-bottom:3px;font-size:11.5px}
.info-label{min-width:100px;color:#666}
.info-val{font-weight:600}
table{width:100%;border-collapse:collapse;margin-bottom:20px}
thead tr{background:#111;color:#fff}
th{padding:8px 10px;text-align:left;font-size:11px;font-weight:600;letter-spacing:.3px}
td{padding:8px 10px;border-bottom:1px solid #e5e5e5;font-size:11.5px;vertical-align:top}
tbody tr:nth-child(even){background:#F9F9F9}
.no-col{width:32px;text-align:center}
.qty-col{width:60px;text-align:center;font-weight:700}
tfoot td{border-top:2px solid #111;font-weight:700;padding:9px 10px}
.signatures{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-top:30px}
.sig-box{text-align:center;border:1px solid #ccc;border-radius:6px;padding:14px}
.sig-box .sig-title{font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;color:#666;margin-bottom:40px}
.sig-box .sig-name{border-top:1px solid #aaa;padding-top:6px;font-size:11px;color:#444;min-height:18px}
.footer-note{font-size:10.5px;color:#888;text-align:center;border-top:1px solid #e5e5e5;padding-top:12px;margin-top:12px}
@media print{
  body{padding:10px 16px}
  .print-btn{display:none!important}
  @page{margin:12mm 14mm}
}
</style>
</head>
<body>
<div style="text-align:right;margin-bottom:10px">
  <button class="print-btn" onclick="window.print()" style="padding:8px 20px;background:#111;color:#fff;border:none;border-radius:6px;font-size:13px;cursor:pointer;font-weight:600">
    🖨️ Print / Save PDF
  </button>
</div>

<!-- HEADER -->
<div class="header">
  <div style="display:flex;align-items:center">
    <?php if ($logoUrl): ?><img src="<?= htmlspecialchars($logoUrl) ?>" class="co-logo"><?php endif; ?>
    <div>
      <div class="co-name"><?= htmlspecialchars($companyName) ?></div>
      <div class="co-sub">
        <?= htmlspecialchars($companyAddr) ?><br>
        <?php if ($companyPhone): ?>Tel: <?= htmlspecialchars($companyPhone) ?><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="doc-title">
    <h1>SURAT JALAN</h1>
    <div class="doc-no">No: <?= htmlspecialchars($container['container_code']) ?></div>
    <div class="doc-no">Tanggal: <?= date('d F Y', strtotime($container['shipment_date'])) ?></div>
  </div>
</div>

<!-- CONTAINER INFO -->
<div class="info-grid">
  <div class="info-box">
    <h4>Informasi Pengiriman</h4>
    <div class="info-row"><span class="info-label">No. Container</span><span class="info-val"><?= htmlspecialchars($container['container_no'] ?: '—') ?></span></div>
    <div class="info-row"><span class="info-label">Tipe Container</span><span class="info-val"><?= strtoupper(htmlspecialchars($container['container_type'])) ?></span></div>
    <div class="info-row"><span class="info-label">Tanggal Kirim</span><span class="info-val"><?= date('d M Y', strtotime($container['shipment_date'])) ?></span></div>
    <div class="info-row"><span class="info-label">Forwarder</span><span class="info-val"><?= htmlspecialchars($container['forwarder'] ?: '—') ?></span></div>
  </div>
  <div class="info-box">
    <h4>Tujuan</h4>
    <div class="info-row"><span class="info-label">Negara</span><span class="info-val"><?= htmlspecialchars($container['destination_country'] ?: '—') ?></span></div>
    <div class="info-row"><span class="info-label">Port</span><span class="info-val"><?= htmlspecialchars($container['destination_port'] ?: '—') ?></span></div>
    <div class="info-row"><span class="info-label">No. BL / Tracking</span><span class="info-val"><?= htmlspecialchars($container['tracking_no'] ?: ($container['bl_no'] ?: '—')) ?></span></div>
    <div class="info-row"><span class="info-label">Status</span><span class="info-val"><?= strtoupper(htmlspecialchars($container['status'])) ?></span></div>
  </div>
</div>

<!-- ITEMS TABLE -->
<table>
  <thead>
    <tr>
      <th class="no-col">No</th>
      <th>Kode Order</th>
      <th>Nama Produk</th>
      <th>Spesifikasi</th>
      <th>Dimensi (cm)</th>
      <th class="qty-col">Qty Kirim</th>
      <th class="qty-col">Total PO</th>
      <th>Customer</th>
      <th>Keterangan</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $i => $item): ?>
    <tr>
      <td class="no-col"><?= $i + 1 ?></td>
      <td><strong><?= htmlspecialchars($item['order_code']) ?></strong></td>
      <td><?= htmlspecialchars($item['product_name']) ?></td>
      <td style="font-size:10.5px;color:#555"><?= nl2br(htmlspecialchars(mb_substr($item['specification'] ?? '', 0, 80))) ?></td>
      <td><?= htmlspecialchars($item['dimensions'] ?: '—') ?></td>
      <td class="qty-col" style="color:#000;font-size:13px"><?= rtrim(rtrim(number_format((float)$item['qty_shipped'],2),'0'),'.') ?></td>
      <td class="qty-col" style="color:#666"><?= rtrim(rtrim(number_format((float)$item['quantity'],2),'0'),'.') ?></td>
      <td><?= htmlspecialchars($item['customer_name'] ?? '—') ?></td>
      <td style="font-size:10.5px;color:#666"><?= htmlspecialchars($item['item_notes'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($items)): ?><tr><td colspan="9" style="text-align:center;padding:20px;color:#aaa">Belum ada item</td></tr><?php endif; ?>
  </tbody>
  <tfoot>
    <tr>
      <td colspan="5" style="text-align:right">TOTAL QTY DIKIRIM:</td>
      <td class="qty-col" style="font-size:14px"><?= rtrim(rtrim(number_format($totalQty,2),'0'),'.') ?></td>
      <td colspan="3"></td>
    </tr>
  </tfoot>
</table>

<?php if ($container['notes']): ?>
<div style="border:1px solid #e5e5e5;border-radius:4px;padding:10px 14px;margin-bottom:20px;font-size:11.5px">
  <strong>Catatan:</strong> <?= htmlspecialchars($container['notes']) ?>
</div>
<?php endif; ?>

<!-- SIGNATURES -->
<div class="signatures">
  <div class="sig-box"><div class="sig-title">Dibuat Oleh</div><div class="sig-name"></div></div>
  <div class="sig-box"><div class="sig-title">Mengetahui / Mandor</div><div class="sig-name"></div></div>
  <div class="sig-box"><div class="sig-title">Penerima</div><div class="sig-name"></div></div>
</div>

<div class="footer-note">Dokumen ini dicetak otomatis oleh PWF Office System · <?= date('d/m/Y H:i') ?></div>
</body></html>
<?php
    exit;
}

require_once __DIR__ . '/layout.php';
$msg = ''; $msgType = 'success';

// ── POST HANDLERS ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';

    if ($action === 'create_container') {
        $shipDate  = $_POST['shipment_date'] ?? date('Y-m-d');
        $ctype     = $_POST['container_type'] ?? '20ft';
        $country   = trim($_POST['destination_country'] ?? '');
        $port      = trim($_POST['destination_port'] ?? '');
        $forwarder = trim($_POST['forwarder'] ?? '');
        $tracking  = trim($_POST['tracking_no'] ?? '');
        $blno      = trim($_POST['bl_no'] ?? '');
        $cno       = trim($_POST['container_no'] ?? '');
        $status    = $_POST['status'] ?? 'draft';
        $notes     = trim($_POST['notes'] ?? '');

        // Auto-generate container code
        $ym   = date('Ym');
        $cnt  = (int)$pdo->query("SELECT COUNT(*) FROM pwf_containers WHERE container_code LIKE 'CTN-$ym-%'")->fetchColumn() + 1;
        $code = sprintf('CTN-%s-%03d', $ym, $cnt);

        $pdo->prepare('INSERT INTO pwf_containers (container_code,container_no,container_type,shipment_date,destination_country,destination_port,forwarder,tracking_no,bl_no,status,notes,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$code,$cno,$ctype,$shipDate,$country,$port,$forwarder,$tracking,$blno,$status,$notes,$_SESSION['user_id']??null]);

        $containerId = (int)$pdo->lastInsertId();

        // Add selected orders as items
        $orderIds = (array)($_POST['order_ids'] ?? []);
        $qtys     = (array)($_POST['qty_shipped'] ?? []);
        foreach ($orderIds as $k => $oid) {
            $oid = (int)$oid;
            $qty = max(0, (float)($qtys[$k] ?? 0));
            if ($oid > 0 && $qty > 0) {
                $pdo->prepare('INSERT INTO pwf_container_items (container_id,order_id,qty_shipped) VALUES (?,?,?)')
                    ->execute([$containerId, $oid, $qty]);
                // Mark order as shipped
                $pdo->prepare("UPDATE pwf_orders SET status='shipped', updated_at=NOW() WHERE id=?")->execute([$oid]);
            }
        }

        $msg = "Container $code created successfully.";
        header("Location: shipping.php?msg=".urlencode($msg)); exit;

    } elseif ($action === 'add_item') {
        $cid   = (int)($_POST['container_id'] ?? 0);
        $oid   = (int)($_POST['order_id'] ?? 0);
        $qty   = max(0, (float)($_POST['qty_shipped'] ?? 0));
        $notes = trim($_POST['item_notes'] ?? '');
        if ($cid > 0 && $oid > 0 && $qty > 0) {
            $pdo->prepare('INSERT INTO pwf_container_items (container_id,order_id,qty_shipped,notes) VALUES (?,?,?,?)')
                ->execute([$cid,$oid,$qty,$notes]);
            $pdo->prepare("UPDATE pwf_orders SET status='shipped', updated_at=NOW() WHERE id=?")->execute([$oid]);
            $msg = 'Item added to container.';
        }

    } elseif ($action === 'update_container_status') {
        $cid    = (int)($_POST['container_id'] ?? 0);
        $status = $_POST['status'] ?? 'booked';
        if ($cid > 0) {
            $pdo->prepare('UPDATE pwf_containers SET status=?,updated_at=NOW() WHERE id=?')->execute([$status,$cid]);
            $msg = 'Container status updated.';
        }
    }
}

if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);

// Data
$readyOrders = $pdo->query("
    SELECT o.id, o.order_code, o.product_name, o.quantity, o.qty_done,
           c.customer_name
    FROM pwf_orders o
    LEFT JOIN pwf_customers c ON c.id=o.customer_id
    WHERE o.status IN ('ready_ship','on_progress')
    ORDER BY FIELD(o.status,'ready_ship','on_progress'), o.id DESC
")->fetchAll();

$containers = $pdo->query("
    SELECT ct.*,
           COUNT(ci.id) AS item_count,
           SUM(ci.qty_shipped) AS total_qty
    FROM pwf_containers ct
    LEFT JOIN pwf_container_items ci ON ci.container_id=ct.id
    GROUP BY ct.id
    ORDER BY ct.id DESC
")->fetchAll();

pwfOfficeHeader('Shipping & Container', 'shipping');
?>

<?php if ($msg): ?>
<div class="alert alert-success" style="margin-bottom:16px"><?= $msg ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1.6fr;gap:16px;align-items:start">

<!-- NEW CONTAINER FORM -->
<div class="pwf-card">
  <div class="pwf-card-header"><i class="bi bi-box-seam me-2" style="color:var(--gold)"></i>New Container</div>
  <div class="pwf-card-body">
  <form method="post" id="containerForm">
    <input type="hidden" name="_action" value="create_container">
    <div class="pwf-form-group"><label>Container No. (fisik)</label>
      <input class="input" name="container_no" placeholder="e.g. TEMU2134567"></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <div class="pwf-form-group"><label>Tanggal Kirim</label>
        <input class="input" type="date" name="shipment_date" value="<?= date('Y-m-d') ?>"></div>
      <div class="pwf-form-group"><label>Tipe</label>
        <select class="select" name="container_type">
          <option value="20ft">20ft</option>
          <option value="40ft">40ft</option>
          <option value="40hc" selected>40HC</option>
          <option value="lcl">LCL</option>
        </select>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <div class="pwf-form-group"><label>Negara Tujuan</label>
        <input class="input" name="destination_country" placeholder="Japan"></div>
      <div class="pwf-form-group"><label>Port Tujuan</label>
        <input class="input" name="destination_port" placeholder="Osaka"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <div class="pwf-form-group"><label>Forwarder</label>
        <input class="input" name="forwarder" placeholder="Nama forwarder"></div>
      <div class="pwf-form-group"><label>No. BL / Tracking</label>
        <input class="input" name="tracking_no" placeholder="Tracking no"></div>
    </div>
    <div class="pwf-form-group"><label>Status</label>
      <select class="select" name="status">
        <option value="draft">Draft</option>
        <option value="booked" selected>Booked</option>
        <option value="onboard">On Board</option>
        <option value="arrived">Arrived</option>
        <option value="closed">Closed</option>
      </select>
    </div>
    <div class="pwf-form-group"><label>Catatan</label>
      <textarea name="notes" placeholder="Additional notes"></textarea>
    </div>

    <!-- ORDER ITEMS -->
    <div style="border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:14px">
      <div style="padding:9px 13px;background:var(--nav-hover);font-size:11px;font-weight:700;color:var(--text);display:flex;justify-content:space-between;align-items:center">
        <span><i class="bi bi-list-check me-1" style="color:var(--gold)"></i>Isi Container</span>
        <button type="button" class="btn btn-sm" onclick="addOrderRow()" style="font-size:11px;padding:3px 10px">+ Tambah Order</button>
      </div>
      <div id="orderRows" style="padding:10px">
        <div style="font-size:11.5px;color:var(--muted);text-align:center;padding:10px 0" id="emptyHint">Klik "+ Tambah Order" untuk memilih order</div>
      </div>
    </div>

    <button class="btn" type="submit" style="width:100%">
      <i class="bi bi-box-seam"></i> Buat Container & Surat Jalan
    </button>
  </form>
  </div>
</div>

<!-- CONTAINER LIST -->
<div class="pwf-card">
  <div class="pwf-card-header"><i class="bi bi-archive me-2" style="color:var(--gold)"></i>Container History</div>
  <div style="overflow-x:auto">
  <table class="pwf-table">
    <thead>
      <tr>
        <th>Kode</th>
        <th>Tgl Kirim</th>
        <th>Tipe</th>
        <th>Tujuan</th>
        <th>Items</th>
        <th>Total Qty</th>
        <th>Status</th>
        <th style="width:110px">Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($containers as $ct): ?>
      <tr>
        <td><code style="font-size:11.5px;color:var(--gold)"><?= htmlspecialchars($ct['container_code']) ?></code>
          <?php if ($ct['container_no']): ?><div style="font-size:10.5px;color:var(--muted)"><?= htmlspecialchars($ct['container_no']) ?></div><?php endif; ?>
        </td>
        <td style="font-size:12px"><?= date('d M Y', strtotime($ct['shipment_date'])) ?></td>
        <td style="font-size:12px;font-weight:600"><?= strtoupper(htmlspecialchars($ct['container_type'])) ?></td>
        <td style="font-size:11.5px">
          <?= htmlspecialchars($ct['destination_country'] ?: '—') ?>
          <?php if ($ct['destination_port']): ?><div style="color:var(--muted)"><?= htmlspecialchars($ct['destination_port']) ?></div><?php endif; ?>
        </td>
        <td style="text-align:center;font-weight:700"><?= (int)$ct['item_count'] ?></td>
        <td style="text-align:center;font-weight:700"><?= rtrim(rtrim(number_format((float)$ct['total_qty'],2),'0'),'.') ?></td>
        <td><span class="status-badge status-<?= htmlspecialchars($ct['status']) ?>"><?= htmlspecialchars(strtoupper($ct['status'])) ?></span></td>
        <td>
          <div style="display:flex;gap:5px">
            <a href="?print=<?= (int)$ct['id'] ?>" target="_blank"
               class="btn btn-sm" style="font-size:11px;padding:4px 9px;background:#FFF7ED;border:1px solid #FED7AA;color:#C2410C"
               title="Print Surat Jalan">
              <i class="bi bi-printer"></i> Print
            </a>
            <button class="btn btn-sm btn-outline-secondary" title="Tambah item"
              onclick="openAddItem(<?= (int)$ct['id'] ?>, '<?= htmlspecialchars($ct['container_code']) ?>')"
              style="font-size:11px;padding:4px 8px">
              <i class="bi bi-plus-lg"></i>
            </button>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($containers)): ?><tr><td colspan="8" style="text-align:center;color:var(--muted);padding:30px">Belum ada container.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

</div><!-- end grid -->

<!-- ADD ITEM MODAL -->
<div class="modal fade" id="addItemModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered" style="max-width:400px">
    <div class="modal-content" style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden">
      <div class="modal-header" style="padding:14px 18px;border-bottom:1px solid var(--border);background:var(--card)">
        <div style="font-weight:700;font-size:14px;color:var(--text)">
          Tambah Item ke <span id="aiContainerCode" style="color:var(--gold)"></span>
        </div>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="_action" value="add_item">
        <input type="hidden" name="container_id" id="aiContainerId">
        <div class="modal-body" style="padding:18px;background:var(--card);display:flex;flex-direction:column;gap:12px">
          <div>
            <label style="font-size:10.5px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Order</label>
            <select name="order_id" class="select" style="width:100%">
              <option value="">— Pilih Order —</option>
              <?php foreach ($readyOrders as $o): ?>
                <option value="<?= (int)$o['id'] ?>"><?= htmlspecialchars($o['order_code'].' – '.$o['product_name'].' ('.$o['customer_name'].')') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label style="font-size:10.5px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Qty Dikirim</label>
            <input type="number" name="qty_shipped" min="0.5" step="0.5" class="input" placeholder="0" required>
          </div>
          <div>
            <label style="font-size:10.5px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Keterangan</label>
            <input type="text" name="item_notes" class="input" placeholder="Optional notes">
          </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--border);padding:12px 18px;background:var(--card);gap:8px">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-sm" style="background:var(--gold);color:#fff;font-weight:600;border:none">Tambah</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Order rows data for the create form
const readyOrders = <?= json_encode(array_map(fn($o) => [
    'id'          => (int)$o['id'],
    'label'       => $o['order_code'].' – '.$o['product_name'].' ('.$o['customer_name'].')',
    'qty'         => (float)$o['quantity'],
    'qty_done'    => (float)$o['qty_done'],
], $readyOrders)) ?>;

let rowCount = 0;
function addOrderRow() {
    document.getElementById('emptyHint').style.display = 'none';
    const idx = rowCount++;
    const opts = readyOrders.map(o =>
        `<option value="${o.id}" data-qty="${o.qty_done||o.qty}">${o.label}</option>`
    ).join('');
    const div = document.createElement('div');
    div.style.cssText = 'display:grid;grid-template-columns:1fr 80px 28px;gap:6px;align-items:center;margin-bottom:7px';
    div.innerHTML = `
      <select name="order_ids[]" class="select" style="font-size:12px" onchange="setQty(this,${idx})">
        <option value="">— Pilih Order —</option>${opts}
      </select>
      <input type="number" name="qty_shipped[]" id="qtyRow${idx}" min="0.5" step="0.5" placeholder="Qty" class="input" style="padding:7px 8px;font-size:13px;font-weight:700;text-align:center">
      <button type="button" onclick="this.parentNode.remove()" style="background:none;border:none;color:var(--muted);font-size:18px;cursor:pointer;line-height:1">&times;</button>`;
    document.getElementById('orderRows').appendChild(div);
}
function setQty(sel, idx) {
    const opt = sel.selectedOptions[0];
    const qty = opt ? parseFloat(opt.dataset.qty||0) : 0;
    const inp = document.getElementById('qtyRow'+idx);
    if (inp && qty > 0) inp.value = qty;
}

function openAddItem(cid, code) {
    document.getElementById('aiContainerId').value = cid;
    document.getElementById('aiContainerCode').textContent = code;
    new bootstrap.Modal(document.getElementById('addItemModal')).show();
}
</script>
<?php pwfOfficeFooter();
