<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';

$pdo = getPwfOfficePdo();

// DB migration: ensure dropped_at column exists
try { $pdo->query("SELECT dropped_at FROM pwf_containers LIMIT 1"); }
catch (PDOException $e) {
    $pdo->exec("ALTER TABLE pwf_containers ADD COLUMN dropped_at DATETIME NULL DEFAULT NULL");
}

// ─── Shared: company info ──────────────────────────────────────────────────────
function getCoInfo($pdo) {
    try {
        return $pdo->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('pwf_company_name','pwf_company_address','pwf_company_phone','pwf_login_logo')")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) { return []; }
}
function fmtQty($v) { return rtrim(rtrim(number_format((float)$v,2),'0'),'.'); }

// ─────────────────────────────────────────────────────────────────────────────
// PRINT: Surat Jalan per Container
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['print']) && (int)$_GET['print'] > 0) {
    $cid = (int)$_GET['print'];
    $stmt = $pdo->prepare("SELECT * FROM pwf_containers WHERE id=?");
    $stmt->execute([$cid]);
    $container = $stmt->fetch();
    if (!$container) { echo 'Container not found'; exit; }

    $items = $pdo->query("
        SELECT ci.qty_shipped, ci.notes AS item_notes,
               o.order_code, o.product_name, o.specification, o.dimensions, o.quantity,
               c.customer_name
        FROM pwf_container_items ci
        JOIN pwf_orders o ON o.id=ci.order_id
        LEFT JOIN pwf_customers c ON c.id=o.customer_id
        WHERE ci.container_id=$cid
        ORDER BY c.customer_name ASC, ci.id ASC
    ")->fetchAll();

    $ci = getCoInfo($pdo);
    $companyName  = $ci['pwf_company_name']    ?? 'Prapen Wood Furniture';
    $companyAddr  = $ci['pwf_company_address']  ?? 'Jl. Ngabul - Batealit No.KM. 5, Jepara';
    $companyPhone = $ci['pwf_company_phone']    ?? '';
    $logoUrl      = $ci['pwf_login_logo']       ?? '';
    $totalQty     = array_sum(array_column($items, 'qty_shipped'));
    $custSummary  = [];
    foreach ($items as $it) {
        $cn = $it['customer_name'] ?? 'Unknown';
        $custSummary[$cn] = ($custSummary[$cn] ?? 0) + (float)$it['qty_shipped'];
    }
    header('Content-Type: text/html; charset=utf-8');
    include __DIR__ . '/print-sj-template.php';
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// PRINT: Surat Jalan per Customer
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['print_cust']) && (int)$_GET['print_cust'] > 0 && isset($_GET['cust_id'])) {
    $cid    = (int)$_GET['print_cust'];
    $custId = (int)$_GET['cust_id'];

    $stmt = $pdo->prepare("SELECT * FROM pwf_containers WHERE id=?");
    $stmt->execute([$cid]);
    $container = $stmt->fetch();

    $stmt2 = $pdo->prepare("SELECT customer_name FROM pwf_customers WHERE id=?");
    $stmt2->execute([$custId]);
    $custNameOnly = $stmt2->fetchColumn();

    if (!$container || !$custNameOnly) { echo 'Not found'; exit; }

    $items = $pdo->query("
        SELECT ci.qty_shipped, ci.notes AS item_notes,
               o.order_code, o.product_name, o.specification, o.dimensions, o.quantity,
               c.customer_name
        FROM pwf_container_items ci
        JOIN pwf_orders o ON o.id=ci.order_id
        LEFT JOIN pwf_customers c ON c.id=o.customer_id
        WHERE ci.container_id=$cid AND o.customer_id=$custId
        ORDER BY ci.id ASC
    ")->fetchAll();

    if (empty($items)) { echo 'Tidak ada item untuk customer ini di container ini.'; exit; }

    $ci = getCoInfo($pdo);
    $companyName  = $ci['pwf_company_name']    ?? 'Prapen Wood Furniture';
    $companyAddr  = $ci['pwf_company_address']  ?? 'Jl. Ngabul - Batealit No.KM. 5, Jepara';
    $companyPhone = $ci['pwf_company_phone']    ?? '';
    $logoUrl      = $ci['pwf_login_logo']       ?? '';
    $totalQty     = array_sum(array_column($items, 'qty_shipped'));
    $custSummary  = null;
    header('Content-Type: text/html; charset=utf-8');
    include __DIR__ . '/print-sj-template.php';
    exit;
}

require_once __DIR__ . '/layout.php';
$msg = ''; $msgType = 'success';

// ─────────────────────────────────────────────────────────────────────────────
// POST HANDLERS
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';

    if ($action === 'create_container') {
        $shipDate  = $_POST['shipment_date'] ?? date('Y-m-d');
        $ctype     = $_POST['container_type'] ?? '40hc';
        $country   = trim($_POST['destination_country'] ?? '');
        $port      = trim($_POST['destination_port'] ?? '');
        $forwarder = trim($_POST['forwarder'] ?? '');
        $tracking  = trim($_POST['tracking_no'] ?? '');
        $blno      = trim($_POST['bl_no'] ?? '');
        $cno       = trim($_POST['container_no'] ?? '');
        $status    = $_POST['status'] ?? 'booked';
        $notes     = trim($_POST['notes'] ?? '');

        $ym  = date('Ym');
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM pwf_containers WHERE container_code LIKE 'CTN-$ym-%'")->fetchColumn() + 1;
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
                    ->execute([$containerId,$oid,$qty]);
                $orderQty     = (float)$pdo->query("SELECT quantity FROM pwf_orders WHERE id=$oid")->fetchColumn();
                $totalShipped = (float)$pdo->query("SELECT COALESCE(SUM(qty_shipped),0) FROM pwf_container_items WHERE order_id=$oid")->fetchColumn();
                $ns = ($totalShipped >= $orderQty) ? 'shipped' : 'partial_ship';
                $pdo->prepare("UPDATE pwf_orders SET status=?, updated_at=NOW() WHERE id=?")->execute([$ns,$oid]);
            }
        }
        $msg = "Container $code berhasil dibuat!";
        header("Location: shipping.php?msg=" . urlencode($msg));
        exit;

    } elseif ($action === 'add_item') {
        $cid   = (int)($_POST['container_id'] ?? 0);
        $oid   = (int)($_POST['order_id'] ?? 0);
        $qty   = max(0, (float)($_POST['qty_shipped'] ?? 0));
        $notes = trim($_POST['item_notes'] ?? '');
        if ($cid > 0 && $oid > 0 && $qty > 0) {
            $pdo->prepare('INSERT INTO pwf_container_items (container_id,order_id,qty_shipped,notes) VALUES (?,?,?,?)')
                ->execute([$cid,$oid,$qty,$notes]);
            $orderQty     = (float)$pdo->query("SELECT quantity FROM pwf_orders WHERE id=$oid")->fetchColumn();
            $totalShipped = (float)$pdo->query("SELECT COALESCE(SUM(qty_shipped),0) FROM pwf_container_items WHERE order_id=$oid")->fetchColumn();
            $ns = ($totalShipped >= $orderQty) ? 'shipped' : 'partial_ship';
            $pdo->prepare("UPDATE pwf_orders SET status=?, updated_at=NOW() WHERE id=?")->execute([$ns,$oid]);
            $msg = 'Item berhasil ditambahkan ke container.';
        }

    } elseif ($action === 'drop_container') {
        $cid = (int)($_POST['container_id'] ?? 0);
        if ($cid > 0) {
            $pdo->prepare('UPDATE pwf_containers SET status=?, dropped_at=NOW(), updated_at=NOW() WHERE id=?')
                ->execute(['onboard', $cid]);
            $msg = 'Container berhasil di-Drop ke kapal! Status: On Board.';
            header("Location: shipping.php?msg=" . urlencode($msg));
            exit;
        }

    } elseif ($action === 'update_container_status') {
        $cid    = (int)($_POST['container_id'] ?? 0);
        $status = $_POST['status'] ?? 'booked';
        if ($cid > 0) {
            $pdo->prepare('UPDATE pwf_containers SET status=?, updated_at=NOW() WHERE id=?')->execute([$status,$cid]);
            $msg = 'Status container diperbarui.';
        }

    } elseif ($action === 'delete_container') {
        $cid = (int)($_POST['container_id'] ?? 0);
        if ($cid > 0) {
            // Revert order statuses for all items in this container
            $orderIds = $pdo->query("SELECT DISTINCT order_id FROM pwf_container_items WHERE container_id=$cid")->fetchAll(PDO::FETCH_COLUMN);
            // First delete the container items
            $pdo->prepare('DELETE FROM pwf_container_items WHERE container_id=?')->execute([$cid]);
            // Re-evaluate each order's status
            foreach ($orderIds as $oid) {
                $oid = (int)$oid;
                $orderQty     = (float)$pdo->query("SELECT quantity FROM pwf_orders WHERE id=$oid")->fetchColumn();
                $totalShipped = (float)$pdo->query("SELECT COALESCE(SUM(qty_shipped),0) FROM pwf_container_items WHERE order_id=$oid")->fetchColumn();
                if ($totalShipped <= 0) {
                    // No shipments remaining — revert to ready_ship if qty_done >= qty, else on_progress
                    $qtyDone = (float)$pdo->query("SELECT qty_done FROM pwf_orders WHERE id=$oid")->fetchColumn();
                    $revert = ($qtyDone >= $orderQty) ? 'ready_ship' : 'on_progress';
                } else {
                    $revert = ($totalShipped >= $orderQty) ? 'shipped' : 'partial_ship';
                }
                $pdo->prepare('UPDATE pwf_orders SET status=?, updated_at=NOW() WHERE id=?')->execute([$revert, $oid]);
            }
            $pdo->prepare('DELETE FROM pwf_containers WHERE id=?')->execute([$cid]);
            $msg = 'Container berhasil dihapus dan status order telah dikembalikan.';
            header('Location: shipping.php?msg=' . urlencode($msg));
            exit;
        }
    }
}
if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);

// ─────────────────────────────────────────────────────────────────────────────
// DATA
// ─────────────────────────────────────────────────────────────────────────────
$readyOrders = $pdo->query("
    SELECT sub.*, (sub.qty_done - sub.already_shipped) AS qty_remaining
    FROM (
        SELECT o.id, o.order_code, o.product_name, o.specification, o.dimensions,
               o.quantity, o.qty_done, o.status,
               COALESCE((SELECT SUM(qty_shipped) FROM pwf_container_items WHERE order_id=o.id),0) AS already_shipped,
               c.id AS customer_id, c.customer_name
        FROM pwf_orders o
        LEFT JOIN pwf_customers c ON c.id=o.customer_id
        WHERE o.status IN ('ready_ship','on_progress','partial_ship') AND o.qty_done > 0
    ) AS sub
    WHERE (sub.qty_done - sub.already_shipped) > 0
    ORDER BY FIELD(sub.status,'ready_ship','partial_ship','on_progress'), sub.id DESC
")->fetchAll();

$readyCustIds = array_unique(array_column($readyOrders,'customer_id'));
$customers = [];
if (!empty($readyCustIds)) {
    $inStr = implode(',', array_map('intval', $readyCustIds));
    $customers = $pdo->query("SELECT id,customer_name FROM pwf_customers WHERE id IN ($inStr) ORDER BY customer_name")->fetchAll();
}

$containers = $pdo->query("
    SELECT ct.*,
           COUNT(ci.id) AS item_count,
           COALESCE(SUM(ci.qty_shipped),0) AS total_qty,
           COUNT(DISTINCT o2.customer_id) AS cust_count
    FROM pwf_containers ct
    LEFT JOIN pwf_container_items ci ON ci.container_id=ct.id
    LEFT JOIN pwf_orders o2 ON o2.id=ci.order_id
    GROUP BY ct.id
    ORDER BY ct.id DESC
")->fetchAll();

$allItems = $pdo->query("
    SELECT ci.container_id, ci.id AS ci_id, ci.qty_shipped, ci.notes AS item_notes,
           o.id AS order_id, o.order_code, o.product_name, o.specification, o.dimensions,
           o.quantity, o.qty_done, o.customer_id,
           c.customer_name
    FROM pwf_container_items ci
    JOIN pwf_orders o ON o.id=ci.order_id
    LEFT JOIN pwf_customers c ON c.id=o.customer_id
    ORDER BY ci.container_id DESC, c.customer_name ASC, ci.id ASC
")->fetchAll();
$itemsByContainer = [];
foreach ($allItems as $row) {
    $itemsByContainer[(int)$row['container_id']][] = $row;
}

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

// Stats
$totalContainers  = count($containers);
$cntActive   = count(array_filter($containers, fn($c)=>in_array($c['status'],['draft','booked'])));
$cntOnboard  = count(array_filter($containers, fn($c)=>in_array($c['status'],['onboard','arrived'])));
$cntClosed   = count(array_filter($containers, fn($c)=>$c['status']==='closed'));
$totalPcs    = array_sum(array_column($containers,'total_qty'));
$totalOrders = count($readyOrders);

pwfOfficeHeader('Shipping & Container','shipping');
?>

<?php if ($msg): ?>
<div class="alert alert-success" style="margin-bottom:16px"><?= $msg ?></div>
<?php endif; ?>

<!-- ══ STATS BAR ════════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">
    <div class="pwf-card" style="padding:16px 18px">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:6px"><i class="bi bi-boxes me-1"></i>Total Container</div>
        <div style="font-size:28px;font-weight:800;color:var(--text);line-height:1"><?= $totalContainers ?></div>
        <div style="font-size:10.5px;color:var(--muted);margin-top:3px"><?= $totalPcs > 0 ? fmtQty($totalPcs).' pcs dikirim' : 'Belum ada' ?></div>
    </div>
    <div class="pwf-card" style="padding:16px 18px;border-left:3px solid var(--gold)">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:6px"><i class="bi bi-bookmark-check me-1"></i>Aktif / Booked</div>
        <div style="font-size:28px;font-weight:800;color:var(--gold);line-height:1"><?= $cntActive ?></div>
        <div style="font-size:10.5px;color:var(--muted);margin-top:3px"><?= $totalOrders ?> order siap kirim</div>
    </div>
    <div class="pwf-card" style="padding:16px 18px;border-left:3px solid #8b5cf6">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:6px"><i class="bi bi-truck me-1"></i>Dikirim / On Board</div>
        <div style="font-size:28px;font-weight:800;color:#8b5cf6;line-height:1"><?= $cntOnboard ?></div>
        <div style="font-size:10.5px;color:var(--muted);margin-top:3px">dalam perjalanan</div>
    </div>
    <div class="pwf-card" style="padding:16px 18px;border-left:3px solid #22c55e">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:6px"><i class="bi bi-check2-all me-1"></i>Selesai / Closed</div>
        <div style="font-size:28px;font-weight:800;color:#22c55e;line-height:1"><?= $cntClosed ?></div>
        <div style="font-size:10.5px;color:var(--muted);margin-top:3px">telah selesai</div>
    </div>
</div>

<!-- ══ ACTION HEADER ════════════════════════════════════════════════════════ -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
    <div>
        <div style="font-size:16px;font-weight:800;color:var(--text)"><i class="bi bi-archive me-2" style="color:var(--gold)"></i>History Container</div>
        <div style="font-size:11.5px;color:var(--muted);margin-top:2px"><?= $totalContainers ?> container terdaftar</div>
    </div>
    <button type="button" data-bs-toggle="modal" data-bs-target="#createContainerModal"
        style="display:inline-flex;align-items:center;gap:8px;padding:10px 22px;background:var(--gold);color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer">
        <i class="bi bi-plus-lg"></i> Buat Container Baru
    </button>
</div>

<!-- ══ FILTER TABS ═══════════════════════════════════════════════════════════ -->
<div style="display:flex;gap:0;margin-bottom:0;border-bottom:2px solid var(--border)">
    <?php
    $tabs = [
        ['id'=>'all',     'label'=>'Semua',        'count'=>$totalContainers],
        ['id'=>'active',  'label'=>'Aktif',         'count'=>$cntActive],
        ['id'=>'onboard', 'label'=>'Dikirim',       'count'=>$cntOnboard],
        ['id'=>'closed',  'label'=>'Selesai',       'count'=>$cntClosed],
    ];
    foreach ($tabs as $tab):
    ?>
    <button class="ctn-tab" data-filter="<?= $tab['id'] ?>"
        style="padding:9px 18px;font-size:12px;font-weight:600;border:none;background:transparent;cursor:pointer;color:var(--muted);border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .15s">
        <?= $tab['label'] ?>
        <span style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:var(--border);font-size:10px;font-weight:700;margin-left:5px"><?= $tab['count'] ?></span>
    </button>
    <?php endforeach; ?>
</div>

<!-- ══ CONTAINER LIST ════════════════════════════════════════════════════════ -->
<div class="pwf-card" style="border-top-left-radius:0;border-top-right-radius:0;border-top:none">
    <?php if (empty($containers)): ?>
    <div style="padding:48px;text-align:center;color:var(--muted)">
        <i class="bi bi-inbox" style="font-size:36px;display:block;margin-bottom:10px"></i>
        <div style="font-size:14px;font-weight:600">Belum ada container</div>
        <div style="font-size:12px;margin-top:4px">Klik "Buat Container Baru" untuk membuat container pengiriman pertama</div>
    </div>
    <?php else: ?>
    <?php foreach ($containers as $idx => $ct):
        $items = $itemsByContainer[(int)$ct['id']] ?? [];
        $custGroups = [];
        foreach ($items as $item) {
            $cid2  = $item['customer_id'];
            $cname = $item['customer_name'] ?? 'Unknown';
            if (!isset($custGroups[$cid2])) $custGroups[$cid2] = ['name'=>$cname,'items'=>[]];
            $custGroups[$cid2]['items'][] = $item;
        }
        // Status info
        $statusCfg = match($ct['status']) {
            'draft'   => ['label'=>'Draft',    'bg'=>'#6b7280','tx'=>'#fff','group'=>'active'],
            'booked'  => ['label'=>'Booked',   'bg'=>'#3b82f6','tx'=>'#fff','group'=>'active'],
            'onboard' => ['label'=>'On Board', 'bg'=>'#8b5cf6','tx'=>'#fff','group'=>'onboard'],
            'arrived' => ['label'=>'Arrived',  'bg'=>'#22c55e','tx'=>'#fff','group'=>'onboard'],
            'closed'  => ['label'=>'Closed',   'bg'=>'#9ca3af','tx'=>'#fff','group'=>'closed'],
            default   => ['label'=>ucfirst($ct['status']),'bg'=>'#6b7280','tx'=>'#fff','group'=>'other']
        };
        $isDroppable = in_array($ct['status'],['draft','booked']);
        // Timeline steps
        $statusOrder = ['draft','booked','onboard','arrived','closed'];
        $curIdx = array_search($ct['status'], $statusOrder);
    ?>
    <div class="ctn-row" data-group="<?= $statusCfg['group'] ?>" style="border-bottom:1px solid var(--border)">

        <!-- ── Container Header Bar ── -->
        <div onclick="toggleCtn(<?= (int)$ct['id'] ?>)"
            style="display:flex;align-items:center;gap:10px;padding:13px 16px;cursor:pointer;transition:background .15s"
            onmouseenter="this.style.background='var(--nav-hover)'" onmouseleave="this.style.background=''">
            <i class="bi bi-chevron-right" id="chev-<?= (int)$ct['id'] ?>"
                style="font-size:11px;color:var(--muted);transition:transform .25s;flex-shrink:0"></i>

            <!-- Code + physical no -->
            <div style="min-width:160px">
                <code style="font-size:13px;font-weight:800;color:var(--gold)"><?= htmlspecialchars($ct['container_code']) ?></code>
                <?php if ($ct['container_no']): ?>
                <div style="font-size:10.5px;color:var(--muted);margin-top:1px"><?= htmlspecialchars($ct['container_no']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Date + Type -->
            <div style="min-width:110px">
                <div style="font-size:12px;font-weight:700;color:var(--text)"><?= date('d M Y', strtotime($ct['shipment_date'])) ?></div>
                <div style="font-size:10.5px;color:var(--muted)"><?= strtoupper(htmlspecialchars($ct['container_type'])) ?></div>
            </div>

            <!-- Destination -->
            <div style="flex:1;min-width:120px">
                <div style="font-size:12px;font-weight:600;color:var(--text)">
                    <?= htmlspecialchars($ct['destination_country'] ?: '—') ?>
                    <?php if ($ct['destination_port']): ?>
                    <span style="color:var(--muted);font-weight:400"> / <?= htmlspecialchars($ct['destination_port']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($ct['forwarder']): ?>
                <div style="font-size:10.5px;color:var(--muted)"><?= htmlspecialchars($ct['forwarder']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Items + pcs + customers -->
            <div style="text-align:center;min-width:90px">
                <div style="font-size:13px;font-weight:800;color:var(--text)"><?= fmtQty($ct['total_qty']) ?> pcs</div>
                <div style="font-size:10px;color:var(--muted)"><?= (int)$ct['item_count'] ?> item · <?= (int)$ct['cust_count'] ?> cust</div>
            </div>

            <!-- Status badge -->
            <span style="font-size:10.5px;font-weight:700;padding:4px 10px;border-radius:20px;background:<?= $statusCfg['bg'] ?>;color:<?= $statusCfg['tx'] ?>;white-space:nowrap;flex-shrink:0">
                <?= $statusCfg['label'] ?>
            </span>
            <?php if ($ct['dropped_at']): ?>
            <div style="font-size:10px;color:#8b5cf6;white-space:nowrap;flex-shrink:0">
                <i class="bi bi-send-check"></i> <?= date('d/m/y H:i', strtotime($ct['dropped_at'])) ?>
            </div>
            <?php endif; ?>

            <!-- Action buttons -->
            <div style="display:flex;gap:5px;flex-shrink:0" onclick="event.stopPropagation()">
                <!-- Cetak SJ per container -->
                <a href="?print=<?= (int)$ct['id'] ?>" target="_blank"
                    title="Cetak Surat Jalan"
                    style="display:inline-flex;align-items:center;gap:3px;padding:5px 10px;border-radius:7px;background:#FFF7ED;border:1px solid #FED7AA;color:#C2410C;font-size:11px;font-weight:600;text-decoration:none;white-space:nowrap">
                    <i class="bi bi-printer"></i> SJ
                </a>
                <!-- Cetak per Customer (if >1 customer) -->
                <?php if (count($custGroups) > 0): ?>
                <button type="button"
                    onclick="openPrintCust(<?= (int)$ct['id'] ?>, '<?= htmlspecialchars(addslashes($ct['container_code'])) ?>', <?= htmlspecialchars(json_encode(array_map(fn($gid,$g)=>['id'=>$gid,'name'=>$g['name']], array_keys($custGroups), $custGroups))) ?>)"
                    title="Cetak Surat Jalan per Customer"
                    style="display:inline-flex;align-items:center;gap:3px;padding:5px 10px;border-radius:7px;background:#EFF6FF;border:1px solid #BFDBFE;color:#1D4ED8;font-size:11px;font-weight:600;cursor:pointer;white-space:nowrap">
                    <i class="bi bi-person-lines-fill"></i> Per Cust
                </button>
                <?php endif; ?>
                <!-- Tambah order -->
                <button type="button"
                    onclick="openAddItem(<?= (int)$ct['id'] ?>, '<?= htmlspecialchars($ct['container_code']) ?>')"
                    title="Tambah order ke container ini"
                    style="display:inline-flex;align-items:center;gap:3px;padding:5px 10px;border-radius:7px;background:var(--nav-hover);border:1px solid var(--border);color:var(--text);font-size:11px;font-weight:600;cursor:pointer;white-space:nowrap">
                    <i class="bi bi-plus-lg"></i> Tambah
                </button>
                <!-- Drop ke Kapal -->
                <?php if ($isDroppable): ?>
                <button type="button"
                    onclick="openDrop(<?= (int)$ct['id'] ?>, '<?= htmlspecialchars(addslashes($ct['container_code'])) ?>', <?= (int)$ct['item_count'] ?>, '<?= fmtQty($ct['total_qty']) ?>')"
                    title="Drop Container ke Kapal"
                    style="display:inline-flex;align-items:center;gap:3px;padding:5px 10px;border-radius:7px;background:#FFF1F2;border:1px solid #FECDD3;color:#BE123C;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap">
                    <i class="bi bi-send-check"></i> Drop
                </button>
                <?php else: ?>
                <!-- Update Status -->
                <button type="button"
                    onclick="openUpdateStatus(<?= (int)$ct['id'] ?>, '<?= htmlspecialchars(addslashes($ct['container_code'])) ?>', '<?= $ct['status'] ?>')"
                    style="display:inline-flex;align-items:center;gap:3px;padding:5px 10px;border-radius:7px;background:var(--nav-hover);border:1px solid var(--border);color:var(--muted);font-size:11px;font-weight:600;cursor:pointer;white-space:nowrap">
                    <i class="bi bi-arrow-repeat"></i> Status
                </button>
                <?php endif; ?>
                <!-- Hapus Container -->
                <button type="button"
                    onclick="openDelete(<?= (int)$ct['id'] ?>, '<?= htmlspecialchars(addslashes($ct['container_code'])) ?>', <?= (int)$ct['item_count'] ?>)"
                    title="Hapus Container"
                    style="display:inline-flex;align-items:center;gap:3px;padding:5px 10px;border-radius:7px;background:#FEF2F2;border:1px solid #FECACA;color:#DC2626;font-size:11px;font-weight:600;cursor:pointer;white-space:nowrap">
                    <i class="bi bi-trash3"></i> Hapus
                </button>
            </div>
        </div><!-- /header bar -->

        <!-- ── Container Expanded Detail ── -->
        <div id="ctn-detail-<?= (int)$ct['id'] ?>" style="display:none;border-top:1px solid var(--border)">

            <!-- Status Timeline -->
            <div style="padding:12px 24px 10px;background:var(--nav-hover);border-bottom:1px solid var(--border)">
                <div style="display:flex;align-items:center;gap:0;font-size:10.5px">
                    <?php foreach ($statusOrder as $si => $stKey):
                        $isDone    = is_numeric($curIdx) && $si < $curIdx;
                        $isCurrent = is_numeric($curIdx) && $si == $curIdx;
                        $stLabels  = ['draft'=>'Draft','booked'=>'Booked','onboard'=>'On Board','arrived'=>'Arrived','closed'=>'Closed'];
                        $stIcons   = ['draft'=>'bi-pencil','booked'=>'bi-bookmark-check','onboard'=>'bi-truck','arrived'=>'bi-geo-alt-fill','closed'=>'bi-check2-circle'];
                        $col = $isCurrent ? 'var(--gold)' : ($isDone ? '#22c55e' : 'var(--border)');
                        $txtCol = $isCurrent ? 'var(--gold)' : ($isDone ? '#22c55e' : 'var(--muted)');
                    ?>
                    <div style="display:flex;flex-direction:column;align-items:center;gap:3px;min-width:72px">
                        <div style="width:28px;height:28px;border-radius:50%;background:<?= $col ?>;display:flex;align-items:center;justify-content:center;border:2px solid <?= $col ?>">
                            <i class="bi <?= $stIcons[$stKey] ?>" style="color:<?= ($isCurrent||$isDone)?'#fff':'var(--muted)' ?>;font-size:12px"></i>
                        </div>
                        <span style="color:<?= $txtCol ?>;font-weight:<?= $isCurrent?'700':'500' ?>"><?= $stLabels[$stKey] ?></span>
                        <?php if ($isCurrent && $stKey === 'onboard' && $ct['dropped_at']): ?>
                        <span style="font-size:9px;color:#8b5cf6"><?= date('d/m H:i', strtotime($ct['dropped_at'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($si < count($statusOrder)-1): ?>
                    <div style="flex:1;height:2px;background:<?= ($isDone||($isCurrent&&$si<$curIdx))?'#22c55e':'var(--border)' ?>;margin-bottom:16px;min-width:16px"></div>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Container meta info -->
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;padding:12px 24px;border-bottom:1px solid var(--border);font-size:11.5px">
                <div>
                    <span style="color:var(--muted);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px">Forwarder</span>
                    <div style="font-weight:600;color:var(--text);margin-top:2px"><?= htmlspecialchars($ct['forwarder'] ?: '—') ?></div>
                </div>
                <div>
                    <span style="color:var(--muted);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px">No. Bill of Lading</span>
                    <div style="font-weight:600;color:var(--text);margin-top:2px"><?= htmlspecialchars($ct['bl_no'] ?: '—') ?></div>
                </div>
                <div>
                    <span style="color:var(--muted);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px">No. Tracking</span>
                    <div style="font-weight:600;color:var(--text);margin-top:2px"><?= htmlspecialchars($ct['tracking_no'] ?: '—') ?></div>
                </div>
            </div>

            <!-- Items per Customer -->
            <?php if (empty($items)): ?>
            <div style="padding:20px 24px;font-size:12px;color:var(--muted)">Belum ada item.</div>
            <?php else: ?>
            <div style="padding:12px 24px 16px">
                <?php foreach ($custGroups as $cid2 => $cg):
                    $custTotalQty  = array_sum(array_column($cg['items'],'quantity'));
                    $custShipped   = array_sum(array_column($cg['items'],'qty_shipped'));
                    $custRemaining = array_sum(array_map(fn($r)=>max(0,(float)$r['quantity']-(float)$r['qty_done']), $cg['items']));
                ?>
                <div style="margin-bottom:16px">
                    <!-- Customer header -->
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                        <div style="display:flex;align-items:center;gap:8px">
                            <i class="bi bi-person-fill" style="color:var(--gold);font-size:13px"></i>
                            <span style="font-size:12.5px;font-weight:700;color:var(--text)"><?= htmlspecialchars($cg['name']) ?></span>
                            <span style="font-size:10px;background:var(--border);padding:2px 7px;border-radius:10px;color:var(--muted);font-weight:600"><?= count($cg['items']) ?> order</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;font-size:11px">
                            <span style="color:var(--muted)">Total PO: <strong style="color:var(--text)"><?= fmtQty($custTotalQty) ?> pcs</strong></span>
                            <span style="color:#D4A017">Dikirim: <strong><?= fmtQty($custShipped) ?> pcs</strong></span>
                            <?php if ($custRemaining > 0): ?>
                            <span style="color:#f97316">Sisa Prod: <strong><?= fmtQty($custRemaining) ?> pcs</strong></span>
                            <?php endif; ?>
                            <!-- Cetak SJ per customer link -->
                            <a href="?print_cust=<?= (int)$ct['id'] ?>&cust_id=<?= (int)$cid2 ?>" target="_blank"
                                style="display:inline-flex;align-items:center;gap:3px;padding:4px 9px;border-radius:6px;background:#FFF7ED;border:1px solid #FED7AA;color:#C2410C;font-size:10.5px;font-weight:600;text-decoration:none">
                                <i class="bi bi-printer"></i> Cetak SJ
                            </a>
                        </div>
                    </div>
                    <!-- Order rows -->
                    <div style="display:flex;flex-direction:column;gap:4px">
                        <?php foreach ($cg['items'] as $ci2):
                            $sisa = max(0,(float)$ci2['quantity']-(float)$ci2['qty_done']);
                        ?>
                        <div style="display:grid;grid-template-columns:150px 1fr 70px 70px 70px;gap:8px;align-items:center;padding:9px 12px;background:var(--card);border-radius:8px;border:1px solid var(--border)">
                            <code style="font-size:11.5px;font-weight:700;color:var(--gold)"><?= htmlspecialchars($ci2['order_code']) ?></code>
                            <div>
                                <div style="font-size:12.5px;font-weight:600;color:var(--text)"><?= htmlspecialchars($ci2['product_name']) ?></div>
                                <?php if ($ci2['specification']): ?>
                                <div style="font-size:10px;color:var(--muted)"><?= htmlspecialchars(mb_substr($ci2['specification'],0,55)) ?></div>
                                <?php endif; ?>
                                <?php if ($ci2['item_notes']): ?>
                                <div style="font-size:10px;color:#f97316;margin-top:1px"><i class="bi bi-chat-left-text"></i> <?= htmlspecialchars($ci2['item_notes']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div style="text-align:center">
                                <div style="font-size:9.5px;color:var(--muted)">Total PO</div>
                                <div style="font-size:13px;font-weight:700;color:var(--text)"><?= fmtQty($ci2['quantity']) ?></div>
                            </div>
                            <div style="text-align:center">
                                <div style="font-size:9.5px;color:var(--muted)">Dikirim</div>
                                <div style="font-size:13px;font-weight:800;color:var(--gold)"><?= fmtQty($ci2['qty_shipped']) ?></div>
                            </div>
                            <div style="text-align:center">
                                <div style="font-size:9.5px;color:var(--muted)">Sisa Prod</div>
                                <div style="font-size:13px;font-weight:700;color:<?= $sisa>0?'#f59e0b':'#22c55e' ?>"><?= fmtQty($sisa) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($ct['notes']): ?>
            <div style="margin:0 24px 14px;padding:10px 14px;background:#FFF7ED;border-radius:8px;border:1px solid #FED7AA;font-size:11.5px;color:#92400E">
                <i class="bi bi-info-circle me-1"></i><strong>Catatan:</strong> <?= htmlspecialchars($ct['notes']) ?>
            </div>
            <?php endif; ?>
        </div><!-- /detail -->
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div><!-- /container list -->

<!-- ══ MODAL: Create Container ══════════════════════════════════════════════ -->
<div class="modal fade" id="createContainerModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" style="max-width:900px">
        <div class="modal-content" style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden">
            <div class="modal-header" style="padding:16px 22px;border-bottom:1px solid var(--border)">
                <div style="font-size:15px;font-weight:800;color:var(--text)">
                    <i class="bi bi-box-seam me-2" style="color:var(--gold)"></i>Buat Container Baru
                </div>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="containerForm">
                <input type="hidden" name="_action" value="create_container">
                <div class="modal-body" style="padding:20px 22px">

                    <!-- Step 1 -->
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
                        <span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:var(--gold);color:#fff;font-size:11px;font-weight:800;flex-shrink:0">1</span>
                        <span style="font-size:13px;font-weight:700;color:var(--text)"><i class="bi bi-box-seam me-1"></i>Detail Kontainer</span>
                    </div>
                    <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;margin-bottom:12px">
                        <div>
                            <label class="form-lbl">No. Container (Fisik)</label>
                            <input class="input" name="container_no" placeholder="Contoh: TEMU2134567" style="width:100%">
                        </div>
                        <div>
                            <label class="form-lbl">Tanggal Kirim</label>
                            <input class="input" type="date" name="shipment_date" value="<?= date('Y-m-d') ?>" style="width:100%">
                        </div>
                        <div>
                            <label class="form-lbl">Tipe Container</label>
                            <select class="select" name="container_type" style="width:100%">
                                <option value="20ft">20ft</option>
                                <option value="40ft">40ft</option>
                                <option value="40hc" selected>40HC</option>
                                <option value="lcl">LCL</option>
                                <option value="fcl">FCL</option>
                            </select>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px">
                        <div>
                            <label class="form-lbl">Negara Tujuan</label>
                            <input class="input" name="destination_country" placeholder="Contoh: Japan" style="width:100%">
                        </div>
                        <div>
                            <label class="form-lbl">Port Tujuan</label>
                            <input class="input" name="destination_port" placeholder="Contoh: Osaka" style="width:100%">
                        </div>
                        <div>
                            <label class="form-lbl">Forwarder</label>
                            <input class="input" name="forwarder" placeholder="Nama perusahaan forwarder" style="width:100%">
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px">
                        <div>
                            <label class="form-lbl">No. Bill of Lading</label>
                            <input class="input" name="bl_no" placeholder="BL Number" style="width:100%">
                        </div>
                        <div>
                            <label class="form-lbl">No. Tracking</label>
                            <input class="input" name="tracking_no" placeholder="Tracking / booking no" style="width:100%">
                        </div>
                        <div>
                            <label class="form-lbl">Status Awal</label>
                            <select class="select" name="status" style="width:100%">
                                <option value="draft">Draft</option>
                                <option value="booked" selected>Booked</option>
                            </select>
                        </div>
                    </div>
                    <div style="margin-bottom:20px">
                        <label class="form-lbl">Catatan</label>
                        <textarea class="input" name="notes" placeholder="Catatan tambahan (opsional)" style="width:100%;min-height:56px;resize:vertical"></textarea>
                    </div>

                    <hr style="border-color:var(--border);margin-bottom:16px">

                    <!-- Step 2: Customer-first order selection -->
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px">
                        <div style="display:flex;align-items:center;gap:10px">
                            <span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:var(--gold);color:#fff;font-size:11px;font-weight:800;flex-shrink:0">2</span>
                            <span style="font-size:13px;font-weight:700;color:var(--text)"><i class="bi bi-clipboard-check me-1"></i>Pilih Order per Customer</span>
                        </div>
                        <span id="mSelectedCnt" style="font-size:11px;font-weight:600;color:var(--muted);background:var(--border);padding:3px 10px;border-radius:20px">0 order dipilih</span>
                    </div>
                    <div style="font-size:11.5px;color:var(--muted);margin-bottom:12px;padding:9px 12px;background:var(--nav-hover);border-radius:8px;border:1px solid var(--border)">
                        <i class="bi bi-info-circle me-1" style="color:var(--gold)"></i>
                        1 container bisa memuat order dari <strong>banyak customer</strong>. Pilih order dari masing-masing customer, lalu klik <strong>Buat Container</strong>.
                    </div>

                    <?php if (empty($readyOrders)): ?>
                    <div style="padding:24px;text-align:center;color:var(--muted);font-size:13px;border:1px dashed var(--border);border-radius:10px">
                        <i class="bi bi-inbox" style="font-size:28px;display:block;margin-bottom:8px"></i>
                        Belum ada order yang siap kirim.<br>
                        <span style="font-size:11px">Order <strong>Ready to Ship</strong>, <strong>In Progress</strong> (ada qty selesai), atau <strong>Partial Ship</strong> akan muncul di sini.</span>
                    </div>
                    <?php else:
                        // Group readyOrders by customer
                        $ordersByCust = [];
                        foreach ($readyOrders as $o) {
                            $cid2  = (int)$o['customer_id'];
                            $cname = $o['customer_name'] ?? 'Unknown';
                            if (!isset($ordersByCust[$cid2])) $ordersByCust[$cid2] = ['name'=>$cname,'orders'=>[]];
                            $ordersByCust[$cid2]['orders'][] = $o;
                        }
                    ?>
                    <!-- Customer-grouped accordion -->
                    <div id="mOrderBody" style="display:flex;flex-direction:column;gap:8px">
                        <?php foreach ($ordersByCust as $cid2 => $cg): ?>
                        <div style="border:1px solid var(--border);border-radius:10px;overflow:hidden">
                            <!-- Customer header (click to expand) -->
                            <div class="m-cust-header" data-custid="<?= (int)$cid2 ?>"
                                onclick="toggleMCust(this)"
                                style="display:flex;align-items:center;gap:10px;padding:11px 14px;cursor:pointer;background:var(--nav-hover);transition:background .15s"
                                onmouseenter="this.style.background='var(--border)'" onmouseleave="this.style.background='var(--nav-hover)'">
                                <input type="checkbox" class="m-cust-check" style="cursor:pointer;width:15px;height:15px;flex-shrink:0"
                                    onclick="event.stopPropagation();toggleCustOrders(this, <?= (int)$cid2 ?>)">
                                <i class="bi bi-person-fill" style="color:var(--gold);font-size:14px;flex-shrink:0"></i>
                                <span style="font-size:13px;font-weight:700;color:var(--text);flex:1"><?= htmlspecialchars($cg['name']) ?></span>
                                <span style="font-size:10.5px;color:var(--muted)"><?= count($cg['orders']) ?> order ready</span>
                                <span class="m-cust-sel-cnt" data-custid="<?= (int)$cid2 ?>"
                                    style="font-size:10.5px;font-weight:700;color:var(--gold);display:none">0 dipilih</span>
                                <i class="bi bi-chevron-down m-chev" style="font-size:10px;color:var(--muted);transition:transform .2s"></i>
                            </div>
                            <!-- Orders table (collapsed by default) -->
                            <div class="m-cust-orders" style="display:none">
                                <table style="width:100%;border-collapse:collapse">
                                    <thead>
                                        <tr style="background:var(--nav-hover);border-bottom:1px solid var(--border);border-top:1px solid var(--border)">
                                            <th style="padding:8px 10px;font-size:9.5px;font-weight:700;color:var(--muted);text-transform:uppercase;width:30px">
                                                <input type="checkbox" class="m-all-in-cust" data-custid="<?= (int)$cid2 ?>"
                                                    onclick="toggleAllInCust(this,<?= (int)$cid2 ?>)" style="cursor:pointer;width:13px;height:13px">
                                            </th>
                                            <th style="padding:8px 10px;font-size:9.5px;font-weight:700;color:var(--muted);text-transform:uppercase">Kode Order</th>
                                            <th style="padding:8px 10px;font-size:9.5px;font-weight:700;color:var(--muted);text-transform:uppercase">Produk</th>
                                            <th style="padding:8px 10px;font-size:9.5px;font-weight:700;color:var(--muted);text-transform:uppercase;text-align:center">Total PO</th>
                                            <th style="padding:8px 10px;font-size:9.5px;font-weight:700;color:var(--muted);text-transform:uppercase;text-align:center">Selesai</th>
                                            <th style="padding:8px 10px;font-size:9.5px;font-weight:700;color:var(--muted);text-transform:uppercase;text-align:center">Bisa Kirim</th>
                                            <th style="padding:8px 10px;font-size:9.5px;font-weight:700;color:var(--muted);text-transform:uppercase;text-align:center;min-width:86px">Qty Kirim</th>
                                            <th style="padding:8px 10px;font-size:9.5px;font-weight:700;color:var(--muted);text-transform:uppercase">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cg['orders'] as $o):
                                            $pct = $o['quantity']>0 ? round($o['qty_done']/$o['quantity']*100) : 0;
                                            $sl  = match($o['status']) {
                                                'ready_ship'   => ['Ready Ship',   'status-ready_ship'],
                                                'on_progress'  => ['In Progress',  'status-on_progress'],
                                                'partial_ship' => ['Partial Ship', 'status-partial_ship'],
                                                default        => [ucfirst($o['status']),'']
                                            };
                                        ?>
                                        <tr class="m-order-row" data-cust="<?= (int)$o['customer_id'] ?>" data-oid="<?= (int)$o['id'] ?>"
                                            style="border-bottom:1px solid var(--border)">
                                            <td style="padding:8px 10px;text-align:center">
                                                <input type="checkbox" class="m-row-check" style="cursor:pointer;width:13px;height:13px" onchange="onMRowCheck(this,<?= (int)$cid2 ?>)">
                                            </td>
                                            <td style="padding:8px 10px"><code style="font-size:11.5px;font-weight:700;color:var(--gold)"><?= htmlspecialchars($o['order_code']) ?></code></td>
                                            <td style="padding:8px 10px">
                                                <div style="font-size:12px;font-weight:600;color:var(--text)"><?= htmlspecialchars($o['product_name']) ?></div>
                                                <?php if ($o['specification']): ?><div style="font-size:9.5px;color:var(--muted)"><?= htmlspecialchars(mb_substr($o['specification'],0,45)) ?></div><?php endif; ?>
                                            </td>
                                            <td style="padding:8px 10px;text-align:center;font-size:12.5px;font-weight:700;color:var(--text)"><?= fmtQty($o['quantity']) ?></td>
                                            <td style="padding:8px 10px;text-align:center">
                                                <div style="font-size:12.5px;font-weight:700;color:<?= $pct>=100?'#22c55e':'var(--gold)' ?>"><?= fmtQty($o['qty_done']) ?></div>
                                                <div style="font-size:9px;color:var(--muted)"><?= $pct ?>%</div>
                                            </td>
                                            <td style="padding:8px 10px;text-align:center">
                                                <span style="font-size:13px;font-weight:800;color:#3b82f6"><?= fmtQty($o['qty_remaining']) ?></span>
                                            </td>
                                            <td style="padding:8px 10px;text-align:center">
                                                <input type="number" class="m-row-qty input" min="0.5" step="0.5"
                                                    value="<?= (float)$o['qty_remaining'] ?>"
                                                    max="<?= (float)$o['qty_remaining'] ?>"
                                                    style="width:70px;text-align:center;font-weight:700;font-size:12px;padding:5px 6px;opacity:.4;pointer-events:none">
                                            </td>
                                            <td style="padding:8px 10px">
                                                <span class="status-badge <?= $sl[1] ?>" style="font-size:9.5px"><?= $sl[0] ?></span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--border);padding:12px 22px;gap:8px">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <span id="mSubmitHint" style="font-size:11px;color:var(--muted);flex:1">Pilih minimal 1 order</span>
                    <button type="submit" id="mSubmitBtn"
                        style="padding:9px 24px;background:var(--gold);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;opacity:.5;pointer-events:none">
                        <i class="bi bi-box-seam me-1"></i>Buat Container
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>

<!-- ══ MODAL: Add Item ══════════════════════════════════════════════════════ -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:440px">
        <div class="modal-content" style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden">
            <div class="modal-header" style="padding:14px 18px;border-bottom:1px solid var(--border)">
                <div style="font-weight:700;font-size:14px;color:var(--text)">
                    <i class="bi bi-plus-circle me-2" style="color:var(--gold)"></i>Tambah Order ke
                    <span id="aiCode" style="color:var(--gold)"></span>
                </div>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="_action" value="add_item">
                <input type="hidden" name="container_id" id="aiContainerId">
                <div class="modal-body" style="padding:18px;display:flex;flex-direction:column;gap:12px">
                    <div>
                        <label class="form-lbl">Pilih Order</label>
                        <select name="order_id" class="select" style="width:100%">
                            <option value="">— Pilih Order —</option>
                            <?php foreach ($allReadyForModal as $o): ?>
                            <option value="<?= (int)$o['id'] ?>">
                                <?= htmlspecialchars($o['order_code'].' – '.$o['product_name'].' ('.$o['customer_name'].')') ?>
                                · sisa <?= fmtQty($o['qty_remaining']) ?> pcs
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-lbl">Qty Dikirim</label>
                        <input type="number" name="qty_shipped" min="0.5" step="0.5" class="input" placeholder="0" required style="width:100%">
                    </div>
                    <div>
                        <label class="form-lbl">Keterangan (opsional)</label>
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

<!-- ══ MODAL: Drop Container ════════════════════════════════════════════════ -->
<div class="modal fade" id="dropModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
        <div class="modal-content" style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden">
            <div class="modal-header" style="padding:14px 18px;border-bottom:1px solid var(--border);background:#FFF1F2">
                <div style="font-weight:700;font-size:14px;color:#BE123C">
                    <i class="bi bi-send-check me-2"></i>Drop Container ke Kapal
                </div>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:20px 20px 10px">
                <div style="text-align:center;margin-bottom:16px">
                    <div style="font-size:22px;font-weight:900;color:var(--gold)" id="dropCode"></div>
                    <div style="font-size:12px;color:var(--muted);margin-top:4px" id="dropInfo"></div>
                </div>
                <div style="background:#FFF1F2;border:1px solid #FECDD3;border-radius:8px;padding:12px 14px;font-size:12px;color:#BE123C;margin-bottom:6px">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Aksi ini akan mengubah status container menjadi <strong>On Board</strong> dan mencatat waktu pengiriman. Status tidak bisa dikembalikan ke Draft atau Booked.
                </div>
                <div style="font-size:11px;color:var(--muted);text-align:center">Pastikan semua order sudah benar sebelum melanjutkan.</div>
            </div>
            <form method="post" id="dropForm">
                <input type="hidden" name="_action" value="drop_container">
                <input type="hidden" name="container_id" id="dropContainerId">
                <div class="modal-footer" style="border-top:1px solid var(--border);padding:12px 18px;gap:8px">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" style="padding:8px 22px;background:#BE123C;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer">
                        <i class="bi bi-send-check me-1"></i>Ya, Drop ke Kapal!
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ MODAL: Update Status ═════════════════════════════════════════════════ -->
<div class="modal fade" id="updateStatusModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:360px">
        <div class="modal-content" style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden">
            <div class="modal-header" style="padding:13px 18px;border-bottom:1px solid var(--border)">
                <div style="font-weight:700;font-size:14px;color:var(--text)"><i class="bi bi-arrow-repeat me-2" style="color:var(--gold)"></i>Update Status <span id="usCode" style="color:var(--gold)"></span></div>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="_action" value="update_container_status">
                <input type="hidden" name="container_id" id="usContainerId">
                <div class="modal-body" style="padding:18px">
                    <label class="form-lbl">Status Baru</label>
                    <select name="status" id="usStatus" class="select" style="width:100%">
                        <option value="draft">Draft</option>
                        <option value="booked">Booked</option>
                        <option value="onboard">On Board</option>
                        <option value="arrived">Arrived</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--border);padding:12px 18px;gap:8px">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm" style="background:var(--gold);color:#fff;font-weight:600;border:none">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ MODAL: Hapus Container ══════════════════════════════════════════════ -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
        <div class="modal-content" style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden">
            <div class="modal-header" style="padding:14px 18px;border-bottom:1px solid var(--border);background:#FEF2F2">
                <div style="font-weight:700;font-size:14px;color:#DC2626">
                    <i class="bi bi-trash3 me-2"></i>Hapus Container
                </div>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:20px 20px 10px">
                <div style="text-align:center;margin-bottom:16px">
                    <div style="font-size:20px;font-weight:900;color:var(--gold)" id="delCode"></div>
                    <div style="font-size:12px;color:var(--muted);margin-top:4px" id="delInfo"></div>
                </div>
                <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:12px 14px;font-size:12px;color:#DC2626;margin-bottom:6px">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Aksi ini akan <strong>menghapus container</strong> beserta semua item di dalamnya.<br>
                    Status order akan dikembalikan otomatis (partial_ship → sebelumnya).
                </div>
                <div id="delWarningOnboard" style="display:none;background:#FFF7ED;border:1px solid #FED7AA;border-radius:8px;padding:10px 14px;font-size:12px;color:#92400E;margin-top:8px">
                    <i class="bi bi-exclamation-circle me-1"></i>
                    <strong>Peringatan:</strong> Container ini sudah di-Drop ke kapal (On Board). Menghapus bisa menyebabkan data tidak konsisten.
                </div>
            </div>
            <form method="post" id="deleteForm">
                <input type="hidden" name="_action" value="delete_container">
                <input type="hidden" name="container_id" id="delContainerId">
                <div class="modal-footer" style="border-top:1px solid var(--border);padding:12px 18px;gap:8px">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" style="padding:8px 22px;background:#DC2626;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer">
                        <i class="bi bi-trash3 me-1"></i>Ya, Hapus Container
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ MODAL: Print per Customer ════════════════════════════════════════════ -->
<div class="modal fade" id="printCustModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px">
        <div class="modal-content" style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden">
            <div class="modal-header" style="padding:13px 18px;border-bottom:1px solid var(--border)">
                <div style="font-weight:700;font-size:14px;color:var(--text)"><i class="bi bi-person-lines-fill me-2" style="color:var(--gold)"></i>Cetak Surat Jalan per Customer</div>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:16px 18px">
                <div style="font-size:12px;color:var(--muted);margin-bottom:12px">Container: <strong id="pcCode" style="color:var(--gold)"></strong></div>
                <div id="pcCustList" style="display:flex;flex-direction:column;gap:8px"></div>
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--border);padding:10px 18px">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- inline label style -->
<style>
.form-lbl { display:block; font-size:10.5px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.4px; margin-bottom:5px }
.ctn-tab.active-tab { color:var(--gold)!important; border-bottom-color:var(--gold)!important }
</style>

<script>
// ── Tab filtering ─────────────────────────────────────────────────────────────
(function(){
    const tabs = document.querySelectorAll('.ctn-tab');
    function applyFilter(filter) {
        tabs.forEach(t => t.classList.toggle('active-tab', t.dataset.filter === filter));
        document.querySelectorAll('.ctn-row').forEach(row => {
            if (filter === 'all') { row.style.display = ''; return; }
            const g = row.dataset.group;
            row.style.display = (
                (filter === 'active'  && g === 'active')  ||
                (filter === 'onboard' && g === 'onboard') ||
                (filter === 'closed'  && g === 'closed')
            ) ? '' : 'none';
        });
    }
    tabs.forEach(t => t.addEventListener('click', () => applyFilter(t.dataset.filter)));
    applyFilter('all');
})();

// ── Container history expand/collapse ─────────────────────────────────────────
function toggleCtn(id) {
    const el  = document.getElementById('ctn-detail-' + id);
    const chv = document.getElementById('chev-' + id);
    const open = el.style.display !== 'none';
    el.style.display = open ? 'none' : 'block';
    chv.style.transform = open ? '' : 'rotate(90deg)';
}

// ── Delete modal ─────────────────────────────────────────────────────────────
function openDelete(cid, code, items) {
    document.getElementById('delContainerId').value = cid;
    document.getElementById('delCode').textContent  = code;
    document.getElementById('delInfo').textContent  = items + ' item akan dihapus dari container ini';
    // Show warning if onboard (items param usage as proxy — we check via class)
    const row = document.querySelector('.ctn-row[data-group="onboard"] [onclick*="openDelete(' + cid + '"]');
    document.getElementById('delWarningOnboard').style.display = row ? 'block' : 'none';
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// ── Drop modal ────────────────────────────────────────────────────────────────
function openDrop(cid, code, items, pcs) {
    document.getElementById('dropContainerId').value = cid;
    document.getElementById('dropCode').textContent  = code;
    document.getElementById('dropInfo').textContent  = items + ' item · ' + pcs + ' pcs';
    new bootstrap.Modal(document.getElementById('dropModal')).show();
}

// ── Update status modal ───────────────────────────────────────────────────────
function openUpdateStatus(cid, code, currentStatus) {
    document.getElementById('usContainerId').value = cid;
    document.getElementById('usCode').textContent  = code;
    document.getElementById('usStatus').value      = currentStatus;
    new bootstrap.Modal(document.getElementById('updateStatusModal')).show();
}

// ── Add item modal ────────────────────────────────────────────────────────────
function openAddItem(cid, code) {
    document.getElementById('aiContainerId').value = cid;
    document.getElementById('aiCode').textContent  = code;
    new bootstrap.Modal(document.getElementById('addItemModal')).show();
}

// ── Print per customer modal ──────────────────────────────────────────────────
function openPrintCust(cid, code, customers) {
    document.getElementById('pcCode').textContent = code;
    const list = document.getElementById('pcCustList');
    list.innerHTML = '';
    customers.forEach(c => {
        const btn = document.createElement('a');
        btn.href   = '?print_cust=' + cid + '&cust_id=' + c.id;
        btn.target = '_blank';
        btn.style.cssText = 'display:flex;align-items:center;gap:10px;padding:11px 14px;background:var(--nav-hover);border:1px solid var(--border);border-radius:9px;text-decoration:none;color:var(--text);font-size:13px;font-weight:600;transition:background .15s';
        btn.onmouseenter = () => btn.style.background = 'var(--border)';
        btn.onmouseleave = () => btn.style.background = 'var(--nav-hover)';
        btn.innerHTML = '<i class="bi bi-person-fill" style="color:var(--gold)"></i>' + c.name +
            '<span style="margin-left:auto"><i class="bi bi-printer" style="color:#C2410C"></i></span>';
        list.appendChild(btn);
    });
    new bootstrap.Modal(document.getElementById('printCustModal')).show();
}

// ── Modal: customer accordion expand/collapse ─────────────────────────────────
function toggleMCust(header) {
    const panel = header.nextElementSibling;
    const chev  = header.querySelector('.m-chev');
    const open  = panel.style.display !== 'none';
    panel.style.display = open ? 'none' : 'block';
    chev.style.transform = open ? '' : 'rotate(180deg)';
}

// Toggle all orders within a customer section
function toggleCustOrders(masterCb, custId) {
    const rows = document.querySelectorAll('#mOrderBody .m-order-row[data-cust="' + custId + '"] .m-row-check');
    rows.forEach(cb => { cb.checked = masterCb.checked; onMRowCheck(cb, custId); });
}
function toggleAllInCust(masterCb, custId) {
    const rows = document.querySelectorAll('#mOrderBody .m-order-row[data-cust="' + custId + '"] .m-row-check');
    rows.forEach(cb => { cb.checked = masterCb.checked; onMRowCheck(cb, custId); });
    // Sync outer customer checkbox
    const custCb = document.querySelector('.m-cust-check[data-custid="' + custId + '"]');
    if (custCb) custCb.checked = masterCb.checked;
}

// ── Modal: order row check ────────────────────────────────────────────────────
function onMRowCheck(cb, custId) {
    const tr  = cb.closest('tr');
    const qty = tr.querySelector('.m-row-qty');
    qty.style.opacity       = cb.checked ? '1' : '.4';
    qty.style.pointerEvents = cb.checked ? 'auto' : 'none';
    if (cb.checked) qty.focus();
    // Update per-customer selected count badge
    if (custId) {
        const cnt  = document.querySelectorAll('#mOrderBody .m-order-row[data-cust="' + custId + '"] .m-row-check:checked').length;
        const badge = document.querySelector('.m-cust-sel-cnt[data-custid="' + custId + '"]');
        if (badge) { badge.textContent = cnt + ' dipilih'; badge.style.display = cnt > 0 ? 'inline' : 'none'; }
    }
    updateMSubmitState();
}

function updateMSubmitState() {
    const checked = document.querySelectorAll('#mOrderBody .m-row-check:checked').length;
    const btn  = document.getElementById('mSubmitBtn');
    const cnt  = document.getElementById('mSelectedCnt');
    const info = document.getElementById('mSubmitHint');
    if (cnt) cnt.textContent = checked + ' order dipilih';
    if (checked > 0) {
        btn.style.opacity = '1'; btn.style.pointerEvents = 'auto';
        if (info) { info.textContent = checked + ' order dari berbagai customer siap dimasukkan'; info.style.color = 'var(--text)'; }
    } else {
        btn.style.opacity = '.5'; btn.style.pointerEvents = 'none';
        if (info) { info.textContent = 'Pilih minimal 1 order'; info.style.color = 'var(--muted)'; }
    }
}

// On form submit: inject hidden inputs from checked rows
document.getElementById('containerForm').addEventListener('submit', function(e) {
    const checked = document.querySelectorAll('#mOrderBody .m-row-check:checked');
    if (checked.length === 0) { e.preventDefault(); alert('Pilih minimal 1 order!'); return; }
    checked.forEach(cb => {
        const tr  = cb.closest('tr');
        const oid = tr.dataset.oid;
        const qty = tr.querySelector('.m-row-qty').value;
        if (oid && qty > 0) {
            const h1 = document.createElement('input'); h1.type='hidden'; h1.name='order_ids[]'; h1.value=oid; this.appendChild(h1);
            const h2 = document.createElement('input'); h2.type='hidden'; h2.name='qty_shipped[]'; h2.value=qty; this.appendChild(h2);
        }
    });
});
</script>
<?php pwfOfficeFooter(); ?>

