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

        $ym   = date('Ym');
        $cnt  = (int)$pdo->query("SELECT COUNT(*) FROM pwf_containers WHERE container_code LIKE 'CTN-$ym-%'")->fetchColumn() + 1;
        $code = sprintf('CTN-%s-%03d', $ym, $cnt);

        $pdo->prepare('INSERT INTO pwf_containers (container_code,container_no,container_type,shipment_date,destination_country,destination_port,forwarder,tracking_no,bl_no,status,notes,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$code,$cno,$ctype,$shipDate,$country,$port,$forwarder,$tracking,$blno,$status,$notes,$_SESSION['user_id']??null]);

        $containerId = (int)$pdo->lastInsertId();

        $orderIds = (array)($_POST['order_ids'] ?? []);
        $qtys     = (array)($_POST['qty_shipped'] ?? []);
        foreach ($orderIds as $k => $oid) {
            $oid = (int)$oid;
            $qty = max(0, (float)($qtys[$k] ?? 0));
            if ($oid > 0 && $qty > 0) {
                $pdo->prepare('INSERT INTO pwf_container_items (container_id,order_id,qty_shipped) VALUES (?,?,?)')
                    ->execute([$containerId, $oid, $qty]);
                // Partial or full ship logic
                $orderQty    = (float)$pdo->query("SELECT quantity FROM pwf_orders WHERE id=$oid")->fetchColumn();
                $totalShipped= (float)$pdo->query("SELECT COALESCE(SUM(qty_shipped),0) FROM pwf_container_items WHERE order_id=$oid")->fetchColumn();
                $newStatus   = ($totalShipped >= $orderQty) ? 'shipped' : 'partial_ship';
                $pdo->prepare("UPDATE pwf_orders SET status=?, updated_at=NOW() WHERE id=?")->execute([$newStatus, $oid]);
            }
        }
        $msg = "Container $code berhasil dibuat.";
        header("Location: shipping.php?msg=".urlencode($msg)); exit;

    } elseif ($action === 'add_item') {
        $cid   = (int)($_POST['container_id'] ?? 0);
        $oid   = (int)($_POST['order_id'] ?? 0);
        $qty   = max(0, (float)($_POST['qty_shipped'] ?? 0));
        $notes = trim($_POST['item_notes'] ?? '');
        if ($cid > 0 && $oid > 0 && $qty > 0) {
            $pdo->prepare('INSERT INTO pwf_container_items (container_id,order_id,qty_shipped,notes) VALUES (?,?,?,?)')
                ->execute([$cid,$oid,$qty,$notes]);
            $orderQty    = (float)$pdo->query("SELECT quantity FROM pwf_orders WHERE id=$oid")->fetchColumn();
            $totalShipped= (float)$pdo->query("SELECT COALESCE(SUM(qty_shipped),0) FROM pwf_container_items WHERE order_id=$oid")->fetchColumn();
            $newStatus   = ($totalShipped >= $orderQty) ? 'shipped' : 'partial_ship';
            $pdo->prepare("UPDATE pwf_orders SET status=?, updated_at=NOW() WHERE id=?")->execute([$newStatus, $oid]);
            $msg = 'Item berhasil ditambahkan ke container.';
        }

    } elseif ($action === 'update_container_status') {
        $cid    = (int)($_POST['container_id'] ?? 0);
        $status = $_POST['status'] ?? 'booked';
        if ($cid > 0) {
            $pdo->prepare('UPDATE pwf_containers SET status=?,updated_at=NOW() WHERE id=?')->execute([$status,$cid]);
            $msg = 'Status container diperbarui.';
        }
    }
}

if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);

// ── DATA ──────────────────────────────────────────────────────────────────────
// Orders yang bisa dikirim: ready_ship, on_progress (qty_done > 0), partial_ship
$readyOrders = $pdo->query("
    SELECT sub.*, (sub.qty_done - sub.already_shipped) AS qty_remaining
    FROM (
        SELECT o.id, o.order_code, o.product_name, o.specification, o.dimensions,
               o.quantity, o.qty_done, o.status,
               COALESCE((SELECT SUM(qty_shipped) FROM pwf_container_items WHERE order_id=o.id), 0) AS already_shipped,
               c.id AS customer_id, c.customer_name
        FROM pwf_orders o
        LEFT JOIN pwf_customers c ON c.id=o.customer_id
        WHERE o.status IN ('ready_ship','on_progress','partial_ship') AND o.qty_done > 0
    ) AS sub
    WHERE (sub.qty_done - sub.already_shipped) > 0
    ORDER BY FIELD(sub.status,'ready_ship','partial_ship','on_progress'), sub.id DESC
")->fetchAll();

// Customers that have ready orders (for filter)
$readyCustIds = array_unique(array_column($readyOrders, 'customer_id'));
$customers = [];
if (!empty($readyCustIds)) {
    $inStr = implode(',', array_map('intval', $readyCustIds));
    $customers = $pdo->query("SELECT id, customer_name FROM pwf_customers WHERE id IN ($inStr) ORDER BY customer_name")->fetchAll();
}

// Container history
$containers = $pdo->query("
    SELECT ct.*,
           COUNT(ci.id)          AS item_count,
           SUM(ci.qty_shipped)   AS total_qty
    FROM pwf_containers ct
    LEFT JOIN pwf_container_items ci ON ci.container_id=ct.id
    GROUP BY ct.id
    ORDER BY ct.id DESC
")->fetchAll();

// Items per container for history expansion
$allItems = $pdo->query("
    SELECT ci.container_id, ci.id AS ci_id, ci.qty_shipped, ci.notes AS item_notes,
           o.order_code, o.product_name, o.specification, o.quantity, o.qty_done,
           c.customer_name
    FROM pwf_container_items ci
    JOIN pwf_orders o ON o.id=ci.order_id
    LEFT JOIN pwf_customers c ON c.id=o.customer_id
    ORDER BY ci.container_id DESC, ci.id ASC
")->fetchAll();
$itemsByContainer = [];
foreach ($allItems as $row) {
    $itemsByContainer[(int)$row['container_id']][] = $row;
}

// All ready orders for add-item modal
$allReadyForModal = $pdo->query("
    SELECT sub.id, sub.order_code, sub.product_name, sub.customer_name,
           (sub.qty_done - sub.already_shipped) AS qty_remaining
    FROM (
        SELECT o.id, o.order_code, o.product_name, o.status, o.qty_done,
               COALESCE((SELECT SUM(qty_shipped) FROM pwf_container_items WHERE order_id=o.id),0) AS already_shipped,
               c.customer_name
        FROM pwf_orders o
        LEFT JOIN pwf_customers c ON c.id=o.customer_id
        WHERE o.status IN ('ready_ship','on_progress','partial_ship') AND o.qty_done > 0
    ) AS sub
    WHERE (sub.qty_done - sub.already_shipped) > 0
    ORDER BY sub.order_code ASC
")->fetchAll();

pwfOfficeHeader('Shipping & Container', 'shipping');
?>

<?php if ($msg): ?>
<div class="alert alert-success" style="margin-bottom:16px"><?= $msg ?></div>
<?php endif; ?>

<form method="post" id="containerForm">
<input type="hidden" name="_action" value="create_container">

<!-- ══ LANGKAH 1: DETAIL KONTAINER ══════════════════════════════════════════ -->
<div class="pwf-card" style="margin-bottom:16px">
  <div class="pwf-card-header" style="display:flex;align-items:center;gap:10px">
    <span style="display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:var(--gold);color:#fff;font-size:12px;font-weight:800;flex-shrink:0">1</span>
    <span><i class="bi bi-box-seam me-1"></i>Detail Kontainer</span>
  </div>
  <div class="pwf-card-body">
    <!-- Row 1: Container No, Tanggal, Tipe -->
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;margin-bottom:14px">
      <div>
        <label style="font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">No. Container (Fisik)</label>
        <input class="input" name="container_no" placeholder="Contoh: TEMU2134567" style="width:100%">
      </div>
      <div>
        <label style="font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Tanggal Kirim</label>
        <input class="input" type="date" name="shipment_date" value="<?= date('Y-m-d') ?>" style="width:100%">
      </div>
      <div>
        <label style="font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Tipe Container</label>
        <select class="select" name="container_type" style="width:100%">
          <option value="20ft">20ft</option>
          <option value="40ft">40ft</option>
          <option value="40hc" selected>40HC</option>
          <option value="lcl">LCL</option>
          <option value="fcl">FCL</option>
        </select>
      </div>
    </div>
    <!-- Row 2: Negara, Port, Forwarder -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:14px">
      <div>
        <label style="font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Negara Tujuan</label>
        <input class="input" name="destination_country" placeholder="Contoh: Japan" style="width:100%">
      </div>
      <div>
        <label style="font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Port Tujuan</label>
        <input class="input" name="destination_port" placeholder="Contoh: Osaka" style="width:100%">
      </div>
      <div>
        <label style="font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Forwarder</label>
        <input class="input" name="forwarder" placeholder="Nama perusahaan forwarder" style="width:100%">
      </div>
    </div>
    <!-- Row 3: BL No, Tracking, Status -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:14px">
      <div>
        <label style="font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">No. Bill of Lading</label>
        <input class="input" name="bl_no" placeholder="BL Number" style="width:100%">
      </div>
      <div>
        <label style="font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">No. Tracking</label>
        <input class="input" name="tracking_no" placeholder="Tracking / booking no" style="width:100%">
      </div>
      <div>
        <label style="font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Status</label>
        <select class="select" name="status" style="width:100%">
          <option value="draft">Draft</option>
          <option value="booked" selected>Booked</option>
          <option value="onboard">On Board</option>
          <option value="arrived">Arrived</option>
          <option value="closed">Closed</option>
        </select>
      </div>
    </div>
    <!-- Row 4: Catatan -->
    <div>
      <label style="font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Catatan</label>
      <textarea class="input" name="notes" placeholder="Catatan tambahan (opsional)" style="width:100%;min-height:64px;resize:vertical"></textarea>
    </div>
  </div>
</div>

<!-- ══ LANGKAH 2: PILIH ORDER ════════════════════════════════════════════════ -->
<div class="pwf-card" style="margin-bottom:16px">
  <div class="pwf-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
    <div style="display:flex;align-items:center;gap:10px">
      <span style="display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:var(--gold);color:#fff;font-size:12px;font-weight:800;flex-shrink:0">2</span>
      <span><i class="bi bi-clipboard-check me-1"></i>Pilih Order yang Akan Dikirim</span>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <label style="font-size:11px;color:var(--muted);font-weight:600">Filter Customer:</label>
      <select id="custFilter" class="select" style="font-size:12px;padding:5px 10px;min-width:160px" onchange="filterOrders()">
        <option value="">— Semua Customer —</option>
        <?php foreach ($customers as $cust): ?>
          <option value="<?= (int)$cust['id'] ?>"><?= htmlspecialchars($cust['customer_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <span id="selectedCount" style="font-size:11px;color:var(--muted);white-space:nowrap">0 dipilih</span>
    </div>
  </div>
  <div class="pwf-card-body" style="padding:0">
    <?php if (empty($readyOrders)): ?>
    <div style="padding:32px;text-align:center;color:var(--muted);font-size:13px">
      <i class="bi bi-inbox" style="font-size:28px;display:block;margin-bottom:8px"></i>
      Belum ada order yang siap kirim.<br>
      <span style="font-size:11px">Order dengan status <strong>Ready to Ship</strong>, <strong>In Progress</strong> (sebagian selesai), atau <strong>Partial Ship</strong> akan muncul di sini.</span>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse">
        <thead>
          <tr style="background:var(--nav-hover);border-bottom:2px solid var(--border)">
            <th style="padding:10px 12px;font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;width:36px">
              <input type="checkbox" id="checkAll" onclick="toggleAll(this)" style="cursor:pointer;width:15px;height:15px">
            </th>
            <th style="padding:10px 12px;font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px">Kode Order</th>
            <th style="padding:10px 12px;font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px">Customer</th>
            <th style="padding:10px 12px;font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px">Produk</th>
            <th style="padding:10px 12px;font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;text-align:center">Total PO</th>
            <th style="padding:10px 12px;font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;text-align:center">Selesai</th>
            <th style="padding:10px 12px;font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;text-align:center">Sisa / Bisa Kirim</th>
            <th style="padding:10px 12px;font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;text-align:center;min-width:100px">Qty Kirim</th>
            <th style="padding:10px 12px;font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px">Status</th>
          </tr>
        </thead>
        <tbody id="orderTableBody">
          <?php foreach ($readyOrders as $o):
            $pct = $o['quantity'] > 0 ? round($o['qty_done'] / $o['quantity'] * 100) : 0;
            $statusLabel = match($o['status']) {
              'ready_ship'   => ['label'=>'Ready Ship',   'cls'=>'status-ready_ship'],
              'on_progress'  => ['label'=>'In Progress',  'cls'=>'status-on_progress'],
              'partial_ship' => ['label'=>'Partial Ship', 'cls'=>'status-partial_ship'],
              default        => ['label'=>ucfirst($o['status']), 'cls'=>'']
            };
          ?>
          <tr class="order-row" data-cust="<?= (int)$o['customer_id'] ?>" data-oid="<?= (int)$o['id'] ?>"
              style="border-bottom:1px solid var(--border);transition:background .15s">
            <td style="padding:10px 12px;text-align:center">
              <input type="checkbox" class="row-check" style="cursor:pointer;width:15px;height:15px" onchange="onRowCheck(this)">
            </td>
            <td style="padding:10px 12px">
              <code style="font-size:12px;font-weight:700;color:var(--gold)"><?= htmlspecialchars($o['order_code']) ?></code>
            </td>
            <td style="padding:10px 12px;font-size:13px;font-weight:600;color:var(--text)"><?= htmlspecialchars($o['customer_name'] ?? '—') ?></td>
            <td style="padding:10px 12px">
              <div style="font-size:13px;font-weight:600;color:var(--text)"><?= htmlspecialchars($o['product_name']) ?></div>
              <?php if ($o['specification']): ?>
              <div style="font-size:10.5px;color:var(--muted);margin-top:1px"><?= htmlspecialchars(mb_substr($o['specification'],0,50)) ?></div>
              <?php endif; ?>
            </td>
            <td style="padding:10px 12px;text-align:center;font-size:13px;font-weight:700;color:var(--text)"><?= rtrim(rtrim(number_format((float)$o['quantity'],2),'0'),'.') ?></td>
            <td style="padding:10px 12px;text-align:center">
              <div style="font-size:13px;font-weight:700;color:<?= $pct>=100?'#22c55e':'var(--gold)' ?>"><?= rtrim(rtrim(number_format((float)$o['qty_done'],2),'0'),'.') ?></div>
              <div style="font-size:9.5px;color:var(--muted)"><?= $pct ?>%</div>
            </td>
            <td style="padding:10px 12px;text-align:center">
              <span style="font-size:14px;font-weight:800;color:<?= (float)$o['qty_remaining']>0?'#3b82f6':'var(--muted)' ?>">
                <?= rtrim(rtrim(number_format((float)$o['qty_remaining'],2),'0'),'.') ?>
              </span>
              <div style="font-size:9.5px;color:var(--muted);margin-top:2px">siap kirim</div>
            </td>
            <td style="padding:10px 12px;text-align:center">
              <input type="number" class="row-qty input" min="0.5" step="0.5"
                     value="<?= (float)$o['qty_remaining'] ?>"
                     max="<?= (float)$o['qty_remaining'] ?>"
                     placeholder="0"
                     style="width:80px;text-align:center;font-weight:700;font-size:13px;padding:6px 8px;opacity:.4;pointer-events:none">
            </td>
            <td style="padding:10px 12px">
              <span class="status-badge <?= $statusLabel['cls'] ?>" style="font-size:10px"><?= $statusLabel['label'] ?></span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ══ SUBMIT ════════════════════════════════════════════════════════════════ -->
<div style="display:flex;gap:12px;align-items:center;margin-bottom:28px">
  <button class="btn" type="submit" id="submitBtn"
          style="padding:13px 32px;font-size:15px;font-weight:700;opacity:.5;pointer-events:none">
    <i class="bi bi-box-seam me-2"></i>Buat Container &amp; Surat Jalan
  </button>
  <span id="submitHint" style="font-size:12px;color:var(--muted)">Pilih minimal 1 order untuk membuat container</span>
</div>

</form>

<!-- ══ HISTORY CONTAINER ═════════════════════════════════════════════════════ -->
<div class="pwf-card">
  <div class="pwf-card-header" style="display:flex;align-items:center;justify-content:space-between">
    <span><i class="bi bi-archive me-2" style="color:var(--gold)"></i>History Container</span>
    <span style="font-size:12px;color:var(--muted)"><?= count($containers) ?> container</span>
  </div>

  <?php if (empty($containers)): ?>
  <div style="padding:32px;text-align:center;color:var(--muted);font-size:13px">
    <i class="bi bi-inbox" style="font-size:28px;display:block;margin-bottom:8px"></i>
    Belum ada container yang dibuat.
  </div>
  <?php else: ?>
  <div style="padding:0">
    <?php foreach ($containers as $idx => $ct):
      $items = $itemsByContainer[(int)$ct['id']] ?? [];
      $statusBadge = match($ct['status']) {
        'draft'   => ['label'=>'Draft',   'bg'=>'#6b7280','tx'=>'#fff'],
        'booked'  => ['label'=>'Booked',  'bg'=>'#3b82f6','tx'=>'#fff'],
        'onboard' => ['label'=>'On Board','bg'=>'#8b5cf6','tx'=>'#fff'],
        'arrived' => ['label'=>'Arrived', 'bg'=>'#22c55e','tx'=>'#fff'],
        'closed'  => ['label'=>'Closed',  'bg'=>'#9ca3af','tx'=>'#fff'],
        default   => ['label'=>ucfirst($ct['status']),'bg'=>'#6b7280','tx'=>'#fff']
      };
      // Group items by customer
      $custGroups = [];
      foreach ($items as $item) {
        $cn = $item['customer_name'] ?? 'Unknown';
        $custGroups[$cn][] = $item;
      }
    ?>
    <div class="ctn-row" style="border-bottom:1px solid var(--border)">
      <!-- Container header bar -->
      <div class="ctn-header" onclick="toggleCtnItems(<?= (int)$ct['id'] ?>)"
           style="display:grid;grid-template-columns:auto 1fr auto auto auto auto auto;gap:10px;align-items:center;padding:13px 16px;cursor:pointer;transition:background .15s"
           onmouseenter="this.style.background='var(--nav-hover)'" onmouseleave="this.style.background=''">
        <i class="bi bi-chevron-right" id="chevron-<?= (int)$ct['id'] ?>"
           style="font-size:11px;color:var(--muted);transition:transform .2s;flex-shrink:0"></i>
        <div>
          <code style="font-size:13px;font-weight:700;color:var(--gold)"><?= htmlspecialchars($ct['container_code']) ?></code>
          <?php if ($ct['container_no']): ?>
          <span style="font-size:11px;color:var(--muted);margin-left:8px"><?= htmlspecialchars($ct['container_no']) ?></span>
          <?php endif; ?>
        </div>
        <div style="font-size:12px;font-weight:700;color:var(--text);white-space:nowrap">
          <?= date('d M Y', strtotime($ct['shipment_date'])) ?>
        </div>
        <div style="font-size:12px;font-weight:700;color:var(--text);white-space:nowrap">
          <?= strtoupper(htmlspecialchars($ct['container_type'])) ?> ·
          <?= htmlspecialchars($ct['destination_country'] ?: '—') ?>
          <?php if ($ct['destination_port']): ?>/<?= htmlspecialchars($ct['destination_port']) ?><?php endif; ?>
        </div>
        <div style="font-size:11.5px;color:var(--muted);white-space:nowrap">
          <i class="bi bi-box2"></i>
          <?= (int)$ct['item_count'] ?> item ·
          <?= rtrim(rtrim(number_format((float)$ct['total_qty'],2),'0'),'.') ?> pcs
        </div>
        <span style="font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:20px;background:<?= $statusBadge['bg'] ?>;color:<?= $statusBadge['tx'] ?>;white-space:nowrap">
          <?= $statusBadge['label'] ?>
        </span>
        <div style="display:flex;gap:6px" onclick="event.stopPropagation()">
          <a href="?print=<?= (int)$ct['id'] ?>" target="_blank"
             title="Print Surat Jalan"
             style="display:inline-flex;align-items:center;gap:4px;padding:5px 11px;border-radius:7px;background:#FFF7ED;border:1px solid #FED7AA;color:#C2410C;font-size:11.5px;font-weight:600;text-decoration:none">
            <i class="bi bi-printer"></i> Cetak
          </a>
          <button type="button"
                  onclick="openAddItem(<?= (int)$ct['id'] ?>, '<?= htmlspecialchars($ct['container_code']) ?>')"
                  title="Tambah order ke container ini"
                  style="display:inline-flex;align-items:center;gap:4px;padding:5px 11px;border-radius:7px;background:var(--nav-hover);border:1px solid var(--border);color:var(--text);font-size:11.5px;font-weight:600;cursor:pointer">
            <i class="bi bi-plus-lg"></i> Tambah
          </button>
        </div>
      </div>

      <!-- Container items (collapsed by default) -->
      <div id="ctn-items-<?= (int)$ct['id'] ?>" style="display:none;background:var(--nav-hover);border-top:1px solid var(--border)">
        <?php if (empty($items)): ?>
        <div style="padding:16px 24px;font-size:12px;color:var(--muted)">Belum ada item dalam container ini.</div>
        <?php else: ?>
        <div style="padding:12px 24px 16px">
          <!-- Group by customer -->
          <?php foreach ($custGroups as $custName => $custItems): ?>
          <div style="margin-bottom:14px">
            <div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:8px;display:flex;align-items:center;gap:8px">
              <i class="bi bi-person-fill" style="color:var(--gold)"></i>
              <?= htmlspecialchars($custName) ?>
              <span style="font-size:10px;font-weight:600;color:var(--muted);background:var(--border);padding:1px 7px;border-radius:10px"><?= count($custItems) ?> order</span>
            </div>
            <div style="display:flex;flex-direction:column;gap:4px">
              <?php foreach ($custItems as $ci):
                $sisa = (float)$ci['quantity'] - (float)$ci['qty_done'];
                $shipped = (float)$ci['qty_shipped'];
              ?>
              <div style="display:grid;grid-template-columns:140px 1fr 80px 80px 80px;gap:8px;align-items:center;padding:8px 12px;background:var(--card);border-radius:8px;border:1px solid var(--border)">
                <code style="font-size:11.5px;font-weight:700;color:var(--gold)"><?= htmlspecialchars($ci['order_code']) ?></code>
                <div>
                  <div style="font-size:12.5px;font-weight:600;color:var(--text)"><?= htmlspecialchars($ci['product_name']) ?></div>
                  <?php if ($ci['specification']): ?><div style="font-size:10px;color:var(--muted)"><?= htmlspecialchars(mb_substr($ci['specification'],0,50)) ?></div><?php endif; ?>
                </div>
                <div style="text-align:center">
                  <div style="font-size:10px;color:var(--muted)">Total PO</div>
                  <div style="font-size:13px;font-weight:700;color:var(--text)"><?= rtrim(rtrim(number_format((float)$ci['quantity'],2),'0'),'.') ?></div>
                </div>
                <div style="text-align:center">
                  <div style="font-size:10px;color:var(--muted)">Dikirim</div>
                  <div style="font-size:13px;font-weight:800;color:var(--gold)"><?= rtrim(rtrim(number_format($shipped,2),'0'),'.') ?></div>
                </div>
                <div style="text-align:center">
                  <div style="font-size:10px;color:var(--muted)">Sisa Prod.</div>
                  <div style="font-size:13px;font-weight:700;color:<?= $sisa>0?'#f59e0b':'#22c55e' ?>"><?= rtrim(rtrim(number_format($sisa,2),'0'),'.') ?></div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ══ ADD ITEM MODAL ════════════════════════════════════════════════════════ -->
<div class="modal fade" id="addItemModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
    <div class="modal-content" style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden">
      <div class="modal-header" style="padding:14px 18px;border-bottom:1px solid var(--border)">
        <div style="font-weight:700;font-size:14px;color:var(--text)">
          <i class="bi bi-plus-circle me-2" style="color:var(--gold)"></i>Tambah Order ke
          <span id="aiContainerCode" style="color:var(--gold)"></span>
        </div>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="_action" value="add_item">
        <input type="hidden" name="container_id" id="aiContainerId">
        <div class="modal-body" style="padding:18px;display:flex;flex-direction:column;gap:14px">
          <div>
            <label style="font-size:10.5px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Pilih Order</label>
            <select name="order_id" class="select" style="width:100%">
              <option value="">— Pilih Order —</option>
              <?php foreach ($allReadyForModal as $o): ?>
                <option value="<?= (int)$o['id'] ?>">
                  <?= htmlspecialchars($o['order_code'].' – '.$o['product_name'].' ('.$o['customer_name'].')') ?>
                  · sisa <?= rtrim(rtrim(number_format((float)$o['qty_remaining'],2),'0'),'.') ?> pcs
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label style="font-size:10.5px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Qty Dikirim</label>
            <input type="number" name="qty_shipped" min="0.5" step="0.5" class="input" placeholder="0" required style="width:100%">
          </div>
          <div>
            <label style="font-size:10.5px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Keterangan (opsional)</label>
            <input type="text" name="item_notes" class="input" placeholder="Catatan item" style="width:100%">
          </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--border);padding:12px 18px;gap:8px">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-sm" style="background:var(--gold);color:#fff;font-weight:600;border:none">Tambah ke Container</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// ── Order selection logic ───────────────────────────────────────────────────
function onRowCheck(cb) {
    const tr  = cb.closest('tr');
    const qty = tr.querySelector('.row-qty');
    qty.style.opacity     = cb.checked ? '1'     : '.4';
    qty.style.pointerEvents = cb.checked ? 'auto' : 'none';
    if (cb.checked) qty.focus();
    updateSubmitState();
}

function toggleAll(masterCb) {
    document.querySelectorAll('#orderTableBody .row-check:not([disabled])').forEach(cb => {
        const tr = cb.closest('tr');
        if (tr.style.display !== 'none') {
            cb.checked = masterCb.checked;
            onRowCheck(cb);
        }
    });
}

function filterOrders() {
    const custId = document.getElementById('custFilter').value;
    document.querySelectorAll('.order-row').forEach(tr => {
        const show = !custId || tr.dataset.cust === custId;
        tr.style.display = show ? '' : 'none';
    });
    updateSubmitState();
}

function updateSubmitState() {
    const checked = document.querySelectorAll('#orderTableBody .row-check:checked').length;
    const btn  = document.getElementById('submitBtn');
    const hint = document.getElementById('submitHint');
    const cnt  = document.getElementById('selectedCount');
    cnt.textContent = checked + ' dipilih';
    if (checked > 0) {
        btn.style.opacity      = '1';
        btn.style.pointerEvents= 'auto';
        hint.textContent       = checked + ' order siap dimasukkan ke container';
        hint.style.color       = 'var(--text)';
    } else {
        btn.style.opacity      = '.5';
        btn.style.pointerEvents= 'none';
        hint.textContent       = 'Pilih minimal 1 order untuk membuat container';
        hint.style.color       = 'var(--muted)';
    }
}

// On form submit, build hidden inputs from checked rows
document.getElementById('containerForm').addEventListener('submit', function(e) {
    const checked = document.querySelectorAll('#orderTableBody .row-check:checked');
    if (checked.length === 0) {
        e.preventDefault();
        alert('Pilih minimal 1 order sebelum membuat container.');
        return;
    }
    checked.forEach(cb => {
        const tr  = cb.closest('tr');
        const oid = tr.dataset.oid;
        const qty = tr.querySelector('.row-qty').value;
        if (oid && qty > 0) {
            const h1 = document.createElement('input');
            h1.type = 'hidden'; h1.name = 'order_ids[]'; h1.value = oid;
            const h2 = document.createElement('input');
            h2.type = 'hidden'; h2.name = 'qty_shipped[]'; h2.value = qty;
            this.appendChild(h1);
            this.appendChild(h2);
        }
    });
});

// ── Container history expand/collapse ──────────────────────────────────────
function toggleCtnItems(id) {
    const el      = document.getElementById('ctn-items-'+id);
    const chevron = document.getElementById('chevron-'+id);
    const open    = el.style.display !== 'none';
    el.style.display      = open ? 'none' : 'block';
    chevron.style.transform = open ? '' : 'rotate(90deg)';
}

// ── Add item modal ──────────────────────────────────────────────────────────
function openAddItem(cid, code) {
    document.getElementById('aiContainerId').value = cid;
    document.getElementById('aiContainerCode').textContent = code;
    new bootstrap.Modal(document.getElementById('addItemModal')).show();
}
</script>
<?php pwfOfficeFooter();
