<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$pdo = getPwfOfficePdo();

function fmtQty($v)
{
    return rtrim(rtrim(number_format((float)$v, 2), '0'), '.');
}

// ── AJAX: container archive detail ──────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'container_detail') {
    ob_clean();
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['error' => 'Invalid container ID']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM pwf_containers WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $container = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$container) {
            echo json_encode(['error' => 'Container not found']);
            exit;
        }

        $itemsStmt = $pdo->prepare("
            SELECT ci.qty_shipped, ci.notes AS item_notes,
                   o.order_code, o.product_name, o.specification, o.dimensions,
                   c.customer_name
            FROM pwf_container_items ci
            JOIN pwf_orders o ON o.id=ci.order_id
            LEFT JOIN pwf_customers c ON c.id=o.customer_id
            WHERE ci.container_id=?
            ORDER BY c.customer_name ASC, ci.id ASC
        ");
        $itemsStmt->execute([$id]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'container' => $container,
            'items' => $items,
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Stat cards ───────────────────────────────────────────────────────────────
$stats = [
    'customers'    => (int)$pdo->query('SELECT COUNT(*) FROM pwf_customers')->fetchColumn(),
    'active_orders' => (int)$pdo->query("SELECT COUNT(*) FROM pwf_orders WHERE status NOT IN ('completed','cancelled','shipped')")->fetchColumn(),
    'in_progress'  => (int)$pdo->query("SELECT COUNT(*) FROM pwf_orders WHERE status IN ('on_progress','partial_ship')")->fetchColumn(),
    'ready_ship'   => (int)$pdo->query("SELECT COUNT(*) FROM pwf_orders WHERE status IN ('ready_ship','completed')")->fetchColumn(),
];

// ── Production breakdown: only active production (exclude shipped) ─────────
$prodBreakdown = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN status IN ('completed','ready_ship')       THEN qty_done ELSE 0 END),0) AS qty_ready,
        COALESCE(SUM(CASE WHEN status IN ('on_progress','qc','partial_ship','draft') THEN qty_done ELSE 0 END),0) AS qty_producing,
        COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','shipped') THEN quantity ELSE 0 END),0) AS total_qty,
        COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','shipped') THEN qty_done ELSE 0 END),0) AS total_done
    FROM pwf_orders
")->fetch();
$totalDone      = (float)$prodBreakdown['total_done'];
$totalQty       = max(0.0001, (float)$prodBreakdown['total_qty']);
$dReady         = (float)$prodBreakdown['qty_ready'];
$dProducing     = (float)$prodBreakdown['qty_producing'];
$dRemaining     = max(0, $totalQty - $totalDone);

// ── Per-customer production progress (qty-based) ────────────────────────────
$custProg = $pdo->query("
    SELECT c.customer_name,
           COALESCE(SUM(o.quantity),0)  AS total_qty,
           COALESCE(SUM(o.qty_done),0)  AS total_done,
           COALESCE(SUM(CASE WHEN o.status IN ('completed','ready_ship')             THEN o.qty_done ELSE 0 END),0) AS qty_ready,
           COALESCE(SUM(CASE WHEN o.status IN ('on_progress','qc','partial_ship','draft') THEN o.qty_done ELSE 0 END),0) AS qty_producing,
           SUM(CASE WHEN o.status IN ('completed','ready_ship')                 THEN 1 ELSE 0 END) AS cnt_completed,
           SUM(CASE WHEN o.status IN ('on_progress','qc','partial_ship','draft') THEN 1 ELSE 0 END) AS cnt_progress,
           COUNT(o.id) AS total_orders
    FROM pwf_customers c
    JOIN pwf_orders o ON o.customer_id=c.id
    WHERE o.status NOT IN ('cancelled','shipped')
    GROUP BY c.id, c.customer_name
    ORDER BY (SUM(o.qty_done)/NULLIF(SUM(o.quantity),0)) DESC
")->fetchAll();

// ── Bar chart: qty-based stacked horizontal per customer ────────────────────
$custNames    = array_column($custProg, 'customer_name');
$barReady     = array_map(fn($r) => round((float)$r['qty_ready'],     2), $custProg);
$barProducing = array_map(fn($r) => round((float)$r['qty_producing'], 2), $custProg);
$barRemaining = array_map(fn($r) => round(max(0, (float)$r['total_qty'] - (float)$r['total_done']), 2), $custProg);
$barTotal     = array_map(fn($r) => round((float)$r['total_qty'], 2), $custProg);

// ── Completed orders (100% done, not yet shipped in container) ────────────────
$completedOrders = $pdo->query("
    SELECT o.order_code, o.product_name, o.specification, o.quantity, o.qty_done,
           COALESCE((SELECT SUM(ci.qty_shipped) FROM pwf_container_items ci WHERE ci.order_id=o.id),0) AS qty_shipped,
           c.customer_name, t.craftsman_name, o.due_date, o.status
    FROM pwf_orders o
    LEFT JOIN pwf_customers c ON c.id=o.customer_id
    LEFT JOIN pwf_craftsmen t ON t.id=o.assigned_craftsman_id
    WHERE o.status IN ('completed','ready_ship')
    ORDER BY o.id DESC
")->fetchAll();

// ── In-progress orders (still being produced) ────────────────────────────────
$inProgressOrders = $pdo->query("
    SELECT o.order_code, o.product_name, o.specification, o.quantity, o.qty_done,
           o.progress_percent, o.status, o.due_date,
           COALESCE((SELECT SUM(ci.qty_shipped) FROM pwf_container_items ci WHERE ci.order_id=o.id),0) AS qty_shipped,
           c.customer_name, t.craftsman_name
    FROM pwf_orders o
    LEFT JOIN pwf_customers c ON c.id=o.customer_id
    LEFT JOIN pwf_craftsmen t ON t.id=o.assigned_craftsman_id
    WHERE o.status IN ('on_progress','partial_ship','draft','qc')
    ORDER BY FIELD(o.status,'on_progress','partial_ship','qc','draft'), o.progress_percent DESC, o.id DESC
")->fetchAll();

// ── Container / shipped history ───────────────────────────────────────────────
$containerHistory = $pdo->query("
    SELECT ct.id, ct.container_code, ct.container_no, ct.container_type, ct.shipment_date,
           ct.destination_country, ct.destination_port, ct.status AS ct_status,
           ct.forwarder, ct.tracking_no,
           COUNT(ci.id)        AS item_count,
           SUM(ci.qty_shipped) AS total_qty,
           GROUP_CONCAT(DISTINCT c.customer_name ORDER BY c.customer_name SEPARATOR ', ') AS customers
    FROM pwf_containers ct
    LEFT JOIN pwf_container_items ci ON ci.container_id=ct.id
    LEFT JOIN pwf_orders o ON o.id=ci.order_id
    LEFT JOIN pwf_customers c ON c.id=o.customer_id
    GROUP BY ct.id
    ORDER BY ct.id DESC
    LIMIT 15
")->fetchAll();

pwfOfficeHeader('Dashboard', 'dashboard');
?>

<!-- ══ STAT CARDS ═══════════════════════════════════════════════════════════ -->
<div class="stat-cards" style="margin-bottom:20px">
    <div class="stat-card">
        <div class="stat-icon" style="background:#EFF6FF"><i class="bi bi-people" style="color:#1D4ED8"></i></div>
        <div>
            <div class="stat-val"><?= $stats['customers'] ?></div>
            <div class="stat-lbl">Customers</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#FFF7ED"><i class="bi bi-clipboard2-check" style="color:#C2410C"></i></div>
        <div>
            <div class="stat-val"><?= $stats['active_orders'] ?></div>
            <div class="stat-lbl">Active Orders</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#F5F3FF"><i class="bi bi-hammer" style="color:#6D28D9"></i></div>
        <div>
            <div class="stat-val"><?= $stats['in_progress'] ?></div>
            <div class="stat-lbl">In Production</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#F0FDF4"><i class="bi bi-check2-circle" style="color:#15803D"></i></div>
        <div>
            <div class="stat-val"><?= $stats['ready_ship'] ?></div>
            <div class="stat-lbl">Ready to Ship</div>
        </div>
    </div>
</div>

<!-- ══ CHARTS ROW ═══════════════════════════════════════════════════════════ -->
<div class="grid2" style="margin-bottom:20px;align-items:stretch">

    <!-- LEFT: Production Completion Donut + per-customer legend -->
    <div class="pwf-card" style="display:flex;flex-direction:column;height:460px">
        <div class="pwf-card-header" style="padding:10px 14px;font-size:11.5px;flex-shrink:0">
            <i class="bi bi-pie-chart me-2" style="color:var(--gold)"></i>Production Completion
        </div>
        <div style="padding:14px 16px 14px;flex:1;overflow-y:auto;overflow-x:hidden">
            <!-- Donut -->
            <div style="display:flex;align-items:center;gap:20px;margin-bottom:12px">
                <div style="position:relative;flex-shrink:0;width:148px;height:148px;isolation:isolate">
                    <canvas id="pieChart" style="position:relative;z-index:1"></canvas>
                    <div id="donutCenter" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;z-index:0">
                        <div id="donutPct" style="font-size:30px;font-weight:800;color:var(--text);line-height:1"></div>
                        <div style="font-size:10px;color:var(--muted);margin-top:3px">overall</div>
                    </div>
                </div>
                <div style="flex:1;display:flex;flex-direction:column;gap:5px">
                    <div style="display:flex;justify-content:space-between;font-size:11px">
                        <span style="display:flex;align-items:center;gap:5px"><span style="width:8px;height:8px;border-radius:50%;background:#22c55e;display:inline-block"></span><span style="color:var(--muted)">Done / Ready</span></span>
                        <span id="lbl-done" style="font-weight:700;color:var(--text)"></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:11px">
                        <span style="display:flex;align-items:center;gap:5px"><span style="width:8px;height:8px;border-radius:50%;background:#f59e0b;display:inline-block"></span><span style="color:var(--muted)">Producing</span></span>
                        <span id="lbl-prod" style="font-weight:700;color:var(--text)"></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:11px">
                        <span style="display:flex;align-items:center;gap:5px"><span style="width:8px;height:8px;border-radius:50%;background:var(--border);display:inline-block"></span><span style="color:var(--muted)">Remaining</span></span>
                        <span id="lbl-rem" style="font-weight:700;color:var(--text)"></span>
                    </div>
                </div>
            </div>
            <!-- Per-customer rows -->
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:8px">Per Customer</div>
            <div style="display:flex;flex-direction:column;gap:7px">
                <?php
                $palette = ['#D4A017', '#3b82f6', '#22c55e', '#f97316', '#a855f7', '#ec4899', '#14b8a6', '#f43f5e'];
                foreach ($custProg as $i => $cp):
                    $cpPct   = $cp['total_qty'] > 0 ? min(100, round($cp['total_done'] / $cp['total_qty'] * 100)) : 0;
                    $cpColor = $cpPct >= 100 ? '#22c55e' : ($cpPct >= 60 ? '#D4A017' : '#f97316');
                    $col     = $palette[$i % count($palette)];
                ?>
                    <div>
                        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:3px">
                            <div style="display:flex;align-items:center;gap:5px">
                                <span style="width:7px;height:7px;border-radius:50%;background:<?= $col ?>;flex-shrink:0;display:inline-block"></span>
                                <span style="font-size:11.5px;font-weight:600;color:var(--text)"><?= htmlspecialchars($cp['customer_name']) ?></span>
                            </div>
                            <span style="font-size:11px;font-weight:700;color:<?= $cpColor ?>"><?= $cpPct ?>%</span>
                        </div>
                        <div style="height:5px;background:var(--border);border-radius:20px;overflow:hidden">
                            <div style="width:<?= $cpPct ?>%;height:100%;background:<?= $cpColor ?>;border-radius:20px;transition:width .6s ease"></div>
                        </div>
                        <div style="display:flex;gap:8px;margin-top:3px;font-size:9.5px;flex-wrap:wrap">
                            <?php if ($cp['cnt_completed'] > 0): ?><span style="color:#22c55e">✓ <?= $cp['cnt_completed'] ?> done/ready</span><?php endif; ?>
                            <?php if ($cp['cnt_progress']  > 0): ?><span style="color:#f59e0b">⟳ <?= $cp['cnt_progress'] ?> producing</span><?php endif; ?>
                            <span style="color:var(--muted)"><?= fmtQty($cp['total_done']) ?> / <?= fmtQty($cp['total_qty']) ?> pcs</span>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($custProg)): ?><div style="text-align:center;color:var(--muted);font-size:12px;padding:12px 0">No data yet.</div><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- RIGHT: Qty progress per customer (horizontal stacked bar) -->
    <div class="pwf-card" style="display:flex;flex-direction:column;height:460px">
        <div class="pwf-card-header" style="padding:10px 14px;font-size:11.5px;flex-shrink:0">
            <i class="bi bi-bar-chart-steps me-2" style="color:var(--gold)"></i>Production Qty by Customer
        </div>
        <div style="padding:12px 14px 10px;flex:1;overflow-y:auto;overflow-x:hidden;display:flex;flex-direction:column">
            <div style="position:relative;width:100%;height:320px;max-height:320px">
                <canvas id="barChart"></canvas>
            </div>
        </div>
    </div>

</div>

<!-- ══ COMPLETED (100%) ══════════════════════════════════════════════════════ -->
<div class="pwf-card" style="margin-bottom:20px;border-left:4px solid #15803D">
    <div class="pwf-card-header" style="background:rgba(21,128,61,.05)">
        <i class="bi bi-check2-all me-2" style="color:#15803D"></i>
        <span style="font-weight:700;color:#15803D">Completed — Ready to Ship</span>
        <span style="margin-left:auto;font-size:11px;background:#F0FDF4;color:#15803D;padding:3px 10px;border-radius:20px;font-weight:700">
            <?= count($completedOrders) ?> order<?= count($completedOrders) != 1 ? 's' : '' ?>
        </span>
    </div>
    <?php if (empty($completedOrders)): ?>
        <div style="padding:24px;text-align:center;color:var(--muted);font-size:13px">
            <i class="bi bi-inbox" style="font-size:24px;display:block;margin-bottom:6px"></i>
            No completed orders yet.
        </div>
    <?php else: ?>
        <div style="padding:0">
            <table class="pwf-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th>Craftsman</th>
                        <th style="text-align:center">PO Qty</th>
                        <th style="text-align:center">Done</th>
                        <th style="text-align:center">Shipped</th>
                        <th style="text-align:center">Remaining</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($completedOrders as $r):
                        $remaining = max(0, (float)$r['qty_done'] - (float)$r['qty_shipped']);
                    ?>
                        <tr>
                            <td><code style="font-size:12px;color:var(--gold)"><?= htmlspecialchars($r['order_code']) ?></code></td>
                            <td style="font-weight:600"><?= htmlspecialchars($r['customer_name'] ?? '—') ?></td>
                            <td>
                                <div style="font-weight:600"><?= htmlspecialchars($r['product_name']) ?></div>
                                <?php if ($r['specification']): ?><div style="font-size:10.5px;color:var(--muted)"><?= htmlspecialchars(mb_substr($r['specification'], 0, 40)) ?></div><?php endif; ?>
                            </td>
                            <td style="font-size:12px"><?= htmlspecialchars($r['craftsman_name'] ?? '—') ?></td>
                            <td style="text-align:center;font-weight:700"><?= rtrim(rtrim(number_format((float)$r['quantity'], 2), '0'), '.') ?></td>
                            <td style="text-align:center;font-weight:700;color:#15803D"><?= rtrim(rtrim(number_format((float)$r['qty_done'], 2), '0'), '.') ?></td>
                            <td style="text-align:center;color:var(--muted)"><?= (float)$r['qty_shipped'] > 0 ? rtrim(rtrim(number_format((float)$r['qty_shipped'], 2), '0'), '.') : '–' ?></td>
                            <td style="text-align:center">
                                <span style="font-weight:800;font-size:14px;color:<?= $remaining > 0 ? '#3b82f6' : '#9ca3af' ?>">
                                    <?= $remaining > 0 ? rtrim(rtrim(number_format($remaining, 2), '0'), '.') : '–' ?>
                                </span>
                            </td>
                            <td><span class="status-badge status-<?= $r['status'] ?>"><?= str_replace('_', ' ', ucfirst($r['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ══ IN PROGRESS ═══════════════════════════════════════════════════════════ -->
<div class="pwf-card" style="margin-bottom:20px;border-left:4px solid #C2410C">
    <div class="pwf-card-header" style="background:rgba(194,65,12,.05)">
        <i class="bi bi-hammer me-2" style="color:#C2410C"></i>
        <span style="font-weight:700;color:#C2410C">In Production</span>
        <span style="margin-left:auto;font-size:11px;background:#FFF7ED;color:#C2410C;padding:3px 10px;border-radius:20px;font-weight:700">
            <?= count($inProgressOrders) ?> order<?= count($inProgressOrders) != 1 ? 's' : '' ?>
        </span>
    </div>
    <?php if (empty($inProgressOrders)): ?>
        <div style="padding:24px;text-align:center;color:var(--muted);font-size:13px">
            <i class="bi bi-inbox" style="font-size:24px;display:block;margin-bottom:6px"></i>
            No orders currently in production.
        </div>
    <?php else: ?>
        <div style="padding:0">
            <table class="pwf-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th>Craftsman</th>
                        <th style="text-align:center">PO Qty</th>
                        <th style="text-align:center">Done</th>
                        <th style="text-align:center">Remaining</th>
                        <th>Progress</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inProgressOrders as $r):
                        $pct      = (int)$r['progress_percent'];
                        $remaining = max(0, (float)$r['quantity'] - (float)$r['qty_done']);
                        $barColor = $pct >= 100 ? '#15803D' : ($pct >= 60 ? '#D4A017' : '#C2410C');
                        $isOverdue = $r['due_date'] && strtotime($r['due_date']) < time() && !in_array($r['status'], ['completed', 'shipped']);
                        $statusMap = ['draft' => ['Draft', 'status-draft'], 'on_progress' => ['In Progress', 'status-on_progress'], 'partial_ship' => ['Partial Ship', 'status-partial_ship'], 'qc' => ['QC', 'status-qc']];
                        $sl = $statusMap[$r['status']] ?? [ucfirst($r['status']), ''];
                    ?>
                        <tr>
                            <td>
                                <code style="font-size:11.5px;color:var(--gold)"><?= htmlspecialchars($r['order_code']) ?></code>
                                <?php if ($isOverdue): ?><div><span style="font-size:9.5px;background:#FEF2F2;color:#991B1B;border-radius:4px;padding:1px 5px;font-weight:600">Overdue</span></div><?php endif; ?>
                            </td>
                            <td style="font-weight:600"><?= htmlspecialchars($r['customer_name'] ?? '—') ?></td>
                            <td>
                                <div style="font-weight:600"><?= htmlspecialchars($r['product_name']) ?></div>
                                <?php if ($r['specification']): ?><div style="font-size:10.5px;color:var(--muted)"><?= htmlspecialchars(mb_substr($r['specification'], 0, 40)) ?></div><?php endif; ?>
                            </td>
                            <td style="font-size:12px"><?= htmlspecialchars($r['craftsman_name'] ?? '—') ?></td>
                            <td style="text-align:center;font-weight:700"><?= rtrim(rtrim(number_format((float)$r['quantity'], 2), '0'), '.') ?></td>
                            <td style="text-align:center;font-weight:700;color:<?= $pct >= 100 ? '#15803D' : 'var(--text)' ?>">
                                <?= rtrim(rtrim(number_format((float)$r['qty_done'], 2), '0'), '.') ?>
                            </td>
                            <td style="text-align:center;font-weight:700;color:<?= $remaining > 0 ? '#C2410C' : '#9ca3af' ?>">
                                <?= $remaining > 0 ? rtrim(rtrim(number_format($remaining, 2), '0'), '.') : '–' ?>
                            </td>
                            <td style="min-width:120px">
                                <div style="display:flex;align-items:center;gap:7px">
                                    <div style="flex:1;height:7px;background:var(--border);border-radius:20px;overflow:hidden">
                                        <div style="width:<?= $pct ?>%;height:100%;background:<?= $barColor ?>;border-radius:20px"></div>
                                    </div>
                                    <span style="font-size:11.5px;font-weight:700;color:<?= $barColor ?>;min-width:32px"><?= $pct ?>%</span>
                                </div>
                            </td>
                            <td><span class="status-badge <?= $sl[1] ?>"><?= $sl[0] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ══ CONTAINER SHIPPING HISTORY ═══════════════════════════════════════════ -->
<div class="pwf-card" style="margin-bottom:20px">
    <div class="pwf-card-header">
        <i class="bi bi-archive me-2" style="color:var(--gold)"></i>
        <span>Container Shipping History</span>
        <span style="margin-left:auto;font-size:11px;color:var(--muted)"><?= count($containerHistory) ?> container<?= count($containerHistory) != 1 ? 's' : '' ?></span>
    </div>
    <?php if (empty($containerHistory)): ?>
        <div style="padding:24px;text-align:center;color:var(--muted);font-size:13px">
            <i class="bi bi-inbox" style="font-size:24px;display:block;margin-bottom:6px"></i>
            No containers created yet.
        </div>
    <?php else: ?>
        <div style="padding:0">
            <table class="pwf-table">
                <thead>
                    <tr>
                        <th>Container Code</th>
                        <th>Shipment Date</th>
                        <th>Type</th>
                        <th>Destination</th>
                        <th>Forwarder</th>
                        <th>Customers</th>
                        <th style="text-align:center">Items</th>
                        <th style="text-align:center">Total Qty</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($containerHistory as $ct):
                        $ctBadge = match ($ct['ct_status']) {
                            'draft'   => ['Draft',   '#6b7280', '#fff'],
                            'booked'  => ['Booked',  '#3b82f6', '#fff'],
                            'onboard' => ['On Board', '#8b5cf6', '#fff'],
                            'arrived' => ['Arrived', '#15803D', '#fff'],
                            'closed'  => ['Closed',  '#9ca3af', '#fff'],
                            default   => [ucfirst($ct['ct_status']), '#6b7280', '#fff']
                        };
                    ?>
                        <tr>
                            <td>
                                <code style="font-size:12px;font-weight:700;color:var(--gold)"><?= htmlspecialchars($ct['container_code']) ?></code>
                                <?php if ($ct['container_no']): ?><div style="font-size:10.5px;color:var(--muted)"><?= htmlspecialchars($ct['container_no']) ?></div><?php endif; ?>
                            </td>
                            <td style="font-size:12px;font-weight:600"><?= date('d M Y', strtotime($ct['shipment_date'])) ?></td>
                            <td style="font-weight:700;font-size:12px"><?= strtoupper(htmlspecialchars($ct['container_type'])) ?></td>
                            <td style="font-size:12px">
                                <?= htmlspecialchars($ct['destination_country'] ?: '—') ?>
                                <?php if ($ct['destination_port']): ?><div style="font-size:10.5px;color:var(--muted)"><?= htmlspecialchars($ct['destination_port']) ?></div><?php endif; ?>
                            </td>
                            <td style="font-size:12px"><?= htmlspecialchars($ct['forwarder'] ?: '—') ?></td>
                            <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($ct['customers'] ?: '—') ?></td>
                            <td style="text-align:center;font-weight:700"><?= (int)$ct['item_count'] ?></td>
                            <td style="text-align:center;font-weight:700"><?= rtrim(rtrim(number_format((float)$ct['total_qty'], 2), '0'), '.') ?></td>
                            <td>
                                <span style="font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:20px;background:<?= $ctBadge[1] ?>;color:<?= $ctBadge[2] ?>">
                                    <?= $ctBadge[0] ?>
                                </span>
                            </td>
                            <td>
                                <div style="display:flex;gap:6px;justify-content:flex-end">
                                    <button type="button"
                                        onclick="openContainerArchive(<?= (int)$ct['id'] ?>)"
                                        style="font-size:11px;background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;border-radius:6px;padding:4px 9px;font-weight:600;display:inline-flex;align-items:center;gap:4px;cursor:pointer">
                                        <i class="bi bi-archive"></i> Arsip
                                    </button>
                                    <a href="shipping.php?print=<?= (int)$ct['id'] ?>" target="_blank"
                                        style="font-size:11px;color:var(--gold);text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:4px;padding:4px 9px;border:1px solid var(--border);border-radius:6px">
                                        <i class="bi bi-printer"></i> Print
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ══ CONTAINER ARCHIVE MODAL ══════════════════════════════════════════════ -->
<div class="modal-overlay" id="containerArchiveModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.42);z-index:9500;align-items:center;justify-content:center;padding:18px">
    <div style="width:min(980px,96vw);max-height:92vh;overflow:auto;background:#fff;border-radius:14px;border:1px solid var(--border);box-shadow:0 24px 80px rgba(0,0,0,.25)">
        <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;gap:10px;position:sticky;top:0;background:#fff;z-index:1">
            <div>
                <div id="caTitle" style="font-size:15px;font-weight:800;color:var(--text)">Arsip Container</div>
                <div id="caMeta" style="font-size:11px;color:var(--muted);margin-top:2px"></div>
            </div>
            <button type="button" onclick="closeContainerArchive()" style="border:1px solid var(--border);background:#fff;border-radius:7px;padding:4px 8px;font-size:16px;line-height:1;cursor:pointer">&times;</button>
        </div>
        <div style="padding:14px 18px">
            <div id="caLoading" style="text-align:center;padding:30px 0;color:var(--muted)">Memuat data arsip...</div>
            <div id="caContent" style="display:none">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:14px">
                    <div style="border:1px solid var(--border);border-radius:8px;padding:8px 10px;background:#FAFAF9"><div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px">Tanggal Kirim</div><div id="caDate" style="font-size:13px;font-weight:700;color:var(--text);margin-top:2px">—</div></div>
                    <div style="border:1px solid var(--border);border-radius:8px;padding:8px 10px;background:#FAFAF9"><div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px">Tujuan</div><div id="caDest" style="font-size:13px;font-weight:700;color:var(--text);margin-top:2px">—</div></div>
                    <div style="border:1px solid var(--border);border-radius:8px;padding:8px 10px;background:#FAFAF9"><div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px">Total Item</div><div id="caItems" style="font-size:13px;font-weight:700;color:var(--text);margin-top:2px">—</div></div>
                    <div style="border:1px solid var(--border);border-radius:8px;padding:8px 10px;background:#FAFAF9"><div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px">Total Qty</div><div id="caQty" style="font-size:13px;font-weight:700;color:var(--text);margin-top:2px">—</div></div>
                </div>
                <div style="border:1px solid var(--border);border-radius:10px;overflow:auto">
                    <table class="pwf-table" style="margin:0">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Order Code</th>
                                <th>Product</th>
                                <th style="text-align:center">Qty Shipped</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody id="caRows"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ CHART.JS ══════════════════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
    if (typeof Chart === 'undefined') {
        document.getElementById('donutPct').textContent = '—';
        document.getElementById('lbl-done').textContent = 'N/A';
        document.getElementById('lbl-prod').textContent = 'N/A';
        document.getElementById('lbl-rem').textContent = 'N/A';
    } else {
        Chart.defaults.font.family = "'Inter', sans-serif";

        // Ensure Chart.js tooltips always render above everything
        Chart.register({
            id: 'tooltipZIndex',
            afterDraw(chart) {
                if (chart.tooltip?._active?.length) {
                    const ctx = chart.ctx;
                    ctx.save();
                    ctx.canvas.style.zIndex = '999';
                    ctx.restore();
                }
            }
        });

        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        const gridColor = isDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.055)';
        const tickColor = isDark ? '#6b7280' : '#9ca3af';
        const tooltipBg = isDark ? '#18181b' : '#fff';
        const tooltipTxt = isDark ? '#e4e4e7' : '#18181b';
        const tooltipBdr = isDark ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.08)';
        const tt = {
            backgroundColor: tooltipBg,
            titleColor: tooltipTxt,
            bodyColor: tooltipTxt,
            borderColor: tooltipBdr,
            borderWidth: 1,
            padding: 10,
            cornerRadius: 8
        };

        // ── Donut: 4-segment production breakdown ────────────────────────────────
        (function() {
            const ready = <?= round($dReady, 2) ?>;
            const producing = <?= round($dProducing, 2) ?>;
            const remaining = <?= round($dRemaining, 2) ?>;
            const totalDone = ready + producing;
            const totalQty = totalDone + remaining;
            const pct = totalQty > 0 ? Math.min(100, Math.round(totalDone / totalQty * 100)) : 0;
            document.getElementById('donutPct').textContent = pct + '%';
            document.getElementById('lbl-done').textContent = ready + ' pcs';
            document.getElementById('lbl-prod').textContent = producing + ' pcs';
            document.getElementById('lbl-rem').textContent = remaining + ' pcs';
            if (totalQty === 0) return;

            new Chart(document.getElementById('pieChart'), {
                type: 'doughnut',
                data: {
                    labels: ['Done / Ready', 'Producing', 'Remaining'],
                    datasets: [{
                        data: [ready, producing, remaining || 0.001],
                        backgroundColor: [
                            '#22c55e',
                            '#f59e0b',
                            isDark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.06)'
                        ],
                        borderWidth: 0,
                        hoverOffset: 6,
                        borderRadius: 3,
                        spacing: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '74%',
                    animation: {
                        animateRotate: true,
                        duration: 800
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            ...tt,
                            callbacks: {
                                label: ctx => {
                                    const v = ctx.parsed;
                                    const tot = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                    const p = tot > 0 ? Math.round(v / tot * 100) : 0;
                                    return ` ${ctx.label}: ${v} pcs (${p}%)`;
                                }
                            }
                        }
                    }
                }
            });
        })();

        // ── Horizontal stacked bar: qty per customer ─────────────────────────────
        (function() {
            const labels = <?= json_encode($custNames) ?>;
            const ready = <?= json_encode($barReady) ?>;
            const producing = <?= json_encode($barProducing) ?>;
            const remaining = <?= json_encode($barRemaining) ?>;
            const totals = <?= json_encode($barTotal) ?>;
            if (!labels.length) return;

            new Chart(document.getElementById('barChart'), {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                            label: 'Done / Ready',
                            data: ready,
                            backgroundColor: '#22c55e',
                            borderRadius: 0,
                            borderSkipped: false,
                            stack: 'qty',
                            barThickness: 22,
                            maxBarThickness: 24,
                            categoryPercentage: 0.72,
                            barPercentage: 0.78
                        },
                        {
                            label: 'Producing',
                            data: producing,
                            backgroundColor: '#f59e0b',
                            borderRadius: 0,
                            borderSkipped: false,
                            stack: 'qty',
                            barThickness: 22,
                            maxBarThickness: 24,
                            categoryPercentage: 0.72,
                            barPercentage: 0.78
                        },
                        {
                            label: 'Remaining',
                            data: remaining,
                            backgroundColor: isDark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.065)',
                            borderRadius: {
                                topRight: 4,
                                bottomRight: 4
                            },
                            borderSkipped: false,
                            stack: 'qty',
                            barThickness: 22,
                            maxBarThickness: 24,
                            categoryPercentage: 0.72,
                            barPercentage: 0.78
                        }
                    ]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 700
                    },
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    scales: {
                        x: {
                            stacked: true,
                            beginAtZero: true,
                            grid: {
                                color: gridColor,
                                drawBorder: false
                            },
                            ticks: {
                                color: tickColor,
                                font: {
                                    size: 10
                                },
                                callback: v => v + ' pcs'
                            }
                        },
                        y: {
                            stacked: true,
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: tickColor,
                                font: {
                                    size: 11,
                                    weight: '600'
                                },
                                maxRotation: 0
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 14,
                                font: {
                                    size: 10.5
                                },
                                color: tickColor,
                                usePointStyle: true,
                                pointStyleWidth: 8
                            }
                        },
                        tooltip: {
                            ...tt,
                            callbacks: {
                                title: ctx => ctx[0].label,
                                label: ctx => {
                                    const tot = totals[ctx.dataIndex] || 1;
                                    const v = ctx.parsed.x;
                                    const p = Math.round(v / tot * 100);
                                    return ` ${ctx.dataset.label}: ${v} pcs (${p}%)`;
                                },
                                footer: ctx => {
                                    const tot = totals[ctx[0].dataIndex];
                                    return `Total PO: ${tot} pcs`;
                                }
                            }
                        }
                    }
                }
            });
        })();
    }

    function fmtDateID(s) {
        if (!s) return '—';
        const d = new Date(s);
        const m = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        return d.getDate() + ' ' + m[d.getMonth()] + ' ' + d.getFullYear();
    }

    function escHtml(str) {
        return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function openContainerArchive(containerId) {
        const modal = document.getElementById('containerArchiveModal');
        const loading = document.getElementById('caLoading');
        const content = document.getElementById('caContent');
        modal.style.display = 'flex';
        loading.style.display = 'block';
        content.style.display = 'none';

        fetch('dashboard.php?ajax=container_detail&id=' + encodeURIComponent(containerId))
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    loading.innerHTML = '<span style="color:#991B1B">' + escHtml(data.error) + '</span>';
                    return;
                }

                const ct = data.container || {};
                const items = data.items || [];
                const totalQty = items.reduce((s, it) => s + (parseFloat(it.qty_shipped) || 0), 0);

                document.getElementById('caTitle').textContent = 'Arsip ' + (ct.container_code || 'Container');
                document.getElementById('caMeta').textContent = 'Container No: ' + (ct.container_no || '—') + ' · Status: ' + (ct.status || '—');
                document.getElementById('caDate').textContent = fmtDateID(ct.shipment_date);
                document.getElementById('caDest').textContent = (ct.destination_country || '—') + (ct.destination_port ? ' / ' + ct.destination_port : '');
                document.getElementById('caItems').textContent = items.length + ' item';
                document.getElementById('caQty').textContent = (Number.isInteger(totalQty) ? totalQty.toFixed(0) : totalQty.toFixed(2).replace(/\.00$/, '')) + ' pcs';

                const rows = document.getElementById('caRows');
                rows.innerHTML = '';
                if (!items.length) {
                    rows.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--muted);padding:16px">Belum ada item di container ini.</td></tr>';
                } else {
                    rows.innerHTML = items.map(it => {
                        const qty = parseFloat(it.qty_shipped || 0);
                        const qtyTxt = Number.isInteger(qty) ? qty.toFixed(0) : qty.toFixed(2).replace(/\.00$/, '');
                        return '<tr>' +
                            '<td style="font-weight:600">' + escHtml(it.customer_name || '—') + '</td>' +
                            '<td><code style="font-size:11px;color:var(--gold)">' + escHtml(it.order_code || '—') + '</code></td>' +
                            '<td>' + escHtml(it.product_name || '—') + '</td>' +
                            '<td style="text-align:center;font-weight:700">' + qtyTxt + '</td>' +
                            '<td style="font-size:11px;color:var(--muted)">' + escHtml(it.item_notes || '') + '</td>' +
                            '</tr>';
                    }).join('');
                }

                loading.style.display = 'none';
                content.style.display = 'block';
            })
            .catch(() => {
                loading.innerHTML = '<span style="color:#991B1B">Gagal memuat arsip container.</span>';
            });
    }

    function closeContainerArchive() {
        document.getElementById('containerArchiveModal').style.display = 'none';
    }

    document.getElementById('containerArchiveModal').addEventListener('click', function(e) {
        if (e.target === this) closeContainerArchive();
    });
</script>
<?php pwfOfficeFooter(); ?>