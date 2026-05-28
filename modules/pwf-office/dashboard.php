<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$pdo = getPwfOfficePdo();

// ── Stat cards ───────────────────────────────────────────────────────────────
$stats = [
    'customers'    => (int)$pdo->query('SELECT COUNT(*) FROM pwf_customers')->fetchColumn(),
    'active_orders'=> (int)$pdo->query("SELECT COUNT(*) FROM pwf_orders WHERE status NOT IN ('completed','cancelled','shipped')")->fetchColumn(),
    'in_progress'  => (int)$pdo->query("SELECT COUNT(*) FROM pwf_orders WHERE status IN ('on_progress','partial_ship')")->fetchColumn(),
    'ready_ship'   => (int)$pdo->query("SELECT COUNT(*) FROM pwf_orders WHERE status IN ('ready_ship','completed')")->fetchColumn(),
];

// ── Production completion: total qty done vs remaining ──────────────────────
$prodTotals = $pdo->query("
    SELECT COALESCE(SUM(qty_done),0) AS total_done,
           COALESCE(SUM(quantity),0) AS total_qty
    FROM pwf_orders WHERE status NOT IN ('cancelled')
")->fetch();
$totalDone = (float)$prodTotals['total_done'];
$totalQty  = max(0.0001, (float)$prodTotals['total_qty']);

// ── Per-customer production progress ────────────────────────────────────────
$custProg = $pdo->query("
    SELECT c.customer_name,
           COALESCE(SUM(o.quantity),0)  AS total_qty,
           COALESCE(SUM(o.qty_done),0)  AS total_done,
           SUM(CASE WHEN o.status='completed'   THEN 1 ELSE 0 END) AS cnt_completed,
           SUM(CASE WHEN o.status='on_progress' THEN 1 ELSE 0 END) AS cnt_progress,
           SUM(CASE WHEN o.status='shipped'     THEN 1 ELSE 0 END) AS cnt_shipped,
           COUNT(o.id) AS total_orders
    FROM pwf_customers c
    JOIN pwf_orders o ON o.customer_id=c.id
    WHERE o.status NOT IN ('cancelled')
    GROUP BY c.id, c.customer_name
    ORDER BY (SUM(o.qty_done)/NULLIF(SUM(o.quantity),0)) DESC
")->fetchAll();

// ── Per-customer order counts by status (for stacked bar) ───────────────────
$palette = ['#D4A017','#3b82f6','#22c55e','#f97316','#a855f7','#ec4899','#14b8a6','#f43f5e'];
$custNames      = array_column($custProg, 'customer_name');
$barCompleted   = array_map(fn($r)=>(int)$r['cnt_completed'],   $custProg);
$barInProgress  = array_map(fn($r)=>(int)$r['cnt_progress'],    $custProg);
$barShipped     = array_map(fn($r)=>(int)$r['cnt_shipped'],     $custProg);

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
        <div><div class="stat-val"><?= $stats['customers'] ?></div><div class="stat-lbl">Customers</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#FFF7ED"><i class="bi bi-clipboard2-check" style="color:#C2410C"></i></div>
        <div><div class="stat-val"><?= $stats['active_orders'] ?></div><div class="stat-lbl">Active Orders</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#F5F3FF"><i class="bi bi-hammer" style="color:#6D28D9"></i></div>
        <div><div class="stat-val"><?= $stats['in_progress'] ?></div><div class="stat-lbl">In Production</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#F0FDF4"><i class="bi bi-check2-circle" style="color:#15803D"></i></div>
        <div><div class="stat-val"><?= $stats['ready_ship'] ?></div><div class="stat-lbl">Ready to Ship</div></div>
    </div>
</div>

<!-- ══ CHARTS ROW ═══════════════════════════════════════════════════════════ -->
<div class="grid2" style="margin-bottom:20px;align-items:start">

    <!-- LEFT: Production Completion Donut + per-customer legend -->
    <div class="pwf-card">
        <div class="pwf-card-header" style="padding:10px 14px;font-size:11.5px">
            <i class="bi bi-pie-chart me-2" style="color:var(--gold)"></i>Production Completion
        </div>
        <div style="padding:14px 16px 16px">
            <!-- Donut -->
            <div style="display:flex;align-items:center;gap:18px;margin-bottom:14px">
                <div style="position:relative;flex-shrink:0;width:110px;height:110px">
                    <canvas id="pieChart"></canvas>
                    <div id="donutCenter" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none">
                        <div id="donutPct" style="font-size:20px;font-weight:800;color:var(--text);line-height:1"></div>
                        <div style="font-size:9.5px;color:var(--muted);margin-top:2px">overall</div>
                    </div>
                </div>
                <div style="flex:1;display:flex;flex-direction:column;gap:6px">
                    <div style="display:flex;justify-content:space-between;font-size:10.5px">
                        <span style="display:flex;align-items:center;gap:5px"><span style="width:8px;height:8px;border-radius:50%;background:#22c55e;display:inline-block"></span><span style="color:var(--muted)">Done</span></span>
                        <span id="lbl-done" style="font-weight:700;color:var(--text)"></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:10.5px">
                        <span style="display:flex;align-items:center;gap:5px"><span style="width:8px;height:8px;border-radius:50%;background:var(--border);display:inline-block"></span><span style="color:var(--muted)">Remaining</span></span>
                        <span id="lbl-rem" style="font-weight:700;color:var(--text)"></span>
                    </div>
                </div>
            </div>
            <!-- Per-customer rows -->
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:8px">Per Customer</div>
            <div style="display:flex;flex-direction:column;gap:7px">
            <?php foreach ($custProg as $i => $cp):
                $cpPct = $cp['total_qty'] > 0 ? min(100, round($cp['total_done']/$cp['total_qty']*100)) : 0;
                $cpColor = $cpPct>=100?'#22c55e':($cpPct>=60?'#D4A017':'#f97316');
                $col = $palette[$i % count($palette)];
            ?>
            <div>
                <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:3px">
                    <div style="display:flex;align-items:center;gap:5px">
                        <span style="width:7px;height:7px;border-radius:50%;background:<?= $col ?>;flex-shrink:0;display:inline-block"></span>
                        <span style="font-size:11.5px;font-weight:600;color:var(--text)"><?= htmlspecialchars($cp['customer_name']) ?></span>
                    </div>
                    <span style="font-size:11px;font-weight:700;color:<?= $cpColor ?>"><?= $cpPct ?>%</span>
                </div>
                <div style="height:4px;background:var(--border);border-radius:20px;overflow:hidden">
                    <div style="width:<?= $cpPct ?>%;height:100%;background:<?= $cpColor ?>;border-radius:20px"></div>
                </div>
                <div style="display:flex;gap:10px;margin-top:3px;font-size:9.5px;color:var(--muted)">
                    <?php if ($cp['cnt_completed']>0): ?><span style="color:#22c55e">✓ <?= $cp['cnt_completed'] ?> completed</span><?php endif; ?>
                    <?php if ($cp['cnt_progress']>0): ?><span style="color:#f97316">⟳ <?= $cp['cnt_progress'] ?> in progress</span><?php endif; ?>
                    <?php if ($cp['cnt_shipped']>0): ?><span style="color:#3b82f6">↗ <?= $cp['cnt_shipped'] ?> shipped</span><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($custProg)): ?><div style="text-align:center;color:var(--muted);font-size:12px;padding:12px 0">No data yet.</div><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- RIGHT: Stacked bar — orders per customer -->
    <div class="pwf-card">
        <div class="pwf-card-header" style="padding:10px 14px;font-size:11.5px">
            <i class="bi bi-bar-chart me-2" style="color:var(--gold)"></i>Orders per Customer
        </div>
        <div style="padding:14px 16px 12px">
            <div style="position:relative;width:100%;height:260px">
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
            <?= count($completedOrders) ?> order<?= count($completedOrders)!=1?'s':'' ?>
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
        <thead><tr>
            <th>Order</th><th>Customer</th><th>Product</th><th>Craftsman</th>
            <th style="text-align:center">PO Qty</th>
            <th style="text-align:center">Done</th>
            <th style="text-align:center">Shipped</th>
            <th style="text-align:center">Remaining</th>
            <th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($completedOrders as $r):
            $remaining = max(0, (float)$r['qty_done'] - (float)$r['qty_shipped']);
        ?>
        <tr>
            <td><code style="font-size:12px;color:var(--gold)"><?= htmlspecialchars($r['order_code']) ?></code></td>
            <td style="font-weight:600"><?= htmlspecialchars($r['customer_name'] ?? '—') ?></td>
            <td>
                <div style="font-weight:600"><?= htmlspecialchars($r['product_name']) ?></div>
                <?php if ($r['specification']): ?><div style="font-size:10.5px;color:var(--muted)"><?= htmlspecialchars(mb_substr($r['specification'],0,40)) ?></div><?php endif; ?>
            </td>
            <td style="font-size:12px"><?= htmlspecialchars($r['craftsman_name'] ?? '—') ?></td>
            <td style="text-align:center;font-weight:700"><?= rtrim(rtrim(number_format((float)$r['quantity'],2),'0'),'.') ?></td>
            <td style="text-align:center;font-weight:700;color:#15803D"><?= rtrim(rtrim(number_format((float)$r['qty_done'],2),'0'),'.') ?></td>
            <td style="text-align:center;color:var(--muted)"><?= (float)$r['qty_shipped']>0 ? rtrim(rtrim(number_format((float)$r['qty_shipped'],2),'0'),'.') : '–' ?></td>
            <td style="text-align:center">
                <span style="font-weight:800;font-size:14px;color:<?= $remaining>0?'#3b82f6':'#9ca3af' ?>">
                    <?= $remaining>0 ? rtrim(rtrim(number_format($remaining,2),'0'),'.') : '–' ?>
                </span>
            </td>
            <td><span class="status-badge status-<?= $r['status'] ?>"><?= str_replace('_',' ',ucfirst($r['status'])) ?></span></td>
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
            <?= count($inProgressOrders) ?> order<?= count($inProgressOrders)!=1?'s':'' ?>
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
        <thead><tr>
            <th>Order</th><th>Customer</th><th>Product</th><th>Craftsman</th>
            <th style="text-align:center">PO Qty</th>
            <th style="text-align:center">Done</th>
            <th style="text-align:center">Remaining</th>
            <th>Progress</th>
            <th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($inProgressOrders as $r):
            $pct      = (int)$r['progress_percent'];
            $remaining= max(0, (float)$r['quantity'] - (float)$r['qty_done']);
            $barColor = $pct >= 100 ? '#15803D' : ($pct >= 60 ? '#D4A017' : '#C2410C');
            $isOverdue= $r['due_date'] && strtotime($r['due_date']) < time() && !in_array($r['status'],['completed','shipped']);
            $statusMap = ['draft'=>['Draft','status-draft'],'on_progress'=>['In Progress','status-on_progress'],'partial_ship'=>['Partial Ship','status-partial_ship'],'qc'=>['QC','status-qc']];
            $sl = $statusMap[$r['status']] ?? [ucfirst($r['status']),''];
        ?>
        <tr>
            <td>
                <code style="font-size:11.5px;color:var(--gold)"><?= htmlspecialchars($r['order_code']) ?></code>
                <?php if ($isOverdue): ?><div><span style="font-size:9.5px;background:#FEF2F2;color:#991B1B;border-radius:4px;padding:1px 5px;font-weight:600">Overdue</span></div><?php endif; ?>
            </td>
            <td style="font-weight:600"><?= htmlspecialchars($r['customer_name'] ?? '—') ?></td>
            <td>
                <div style="font-weight:600"><?= htmlspecialchars($r['product_name']) ?></div>
                <?php if ($r['specification']): ?><div style="font-size:10.5px;color:var(--muted)"><?= htmlspecialchars(mb_substr($r['specification'],0,40)) ?></div><?php endif; ?>
            </td>
            <td style="font-size:12px"><?= htmlspecialchars($r['craftsman_name'] ?? '—') ?></td>
            <td style="text-align:center;font-weight:700"><?= rtrim(rtrim(number_format((float)$r['quantity'],2),'0'),'.') ?></td>
            <td style="text-align:center;font-weight:700;color:<?= $pct>=100?'#15803D':'var(--text)' ?>">
                <?= rtrim(rtrim(number_format((float)$r['qty_done'],2),'0'),'.') ?>
            </td>
            <td style="text-align:center;font-weight:700;color:<?= $remaining>0?'#C2410C':'#9ca3af' ?>">
                <?= $remaining>0 ? rtrim(rtrim(number_format($remaining,2),'0'),'.') : '–' ?>
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
        <span style="margin-left:auto;font-size:11px;color:var(--muted)"><?= count($containerHistory) ?> container<?= count($containerHistory)!=1?'s':'' ?></span>
    </div>
    <?php if (empty($containerHistory)): ?>
    <div style="padding:24px;text-align:center;color:var(--muted);font-size:13px">
        <i class="bi bi-inbox" style="font-size:24px;display:block;margin-bottom:6px"></i>
        No containers created yet.
    </div>
    <?php else: ?>
    <div style="padding:0">
    <table class="pwf-table">
        <thead><tr>
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
        </tr></thead>
        <tbody>
        <?php foreach ($containerHistory as $ct):
            $ctBadge = match($ct['ct_status']) {
                'draft'   => ['Draft',   '#6b7280','#fff'],
                'booked'  => ['Booked',  '#3b82f6','#fff'],
                'onboard' => ['On Board','#8b5cf6','#fff'],
                'arrived' => ['Arrived', '#15803D','#fff'],
                'closed'  => ['Closed',  '#9ca3af','#fff'],
                default   => [ucfirst($ct['ct_status']),'#6b7280','#fff']
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
            <td style="text-align:center;font-weight:700"><?= rtrim(rtrim(number_format((float)$ct['total_qty'],2),'0'),'.') ?></td>
            <td>
                <span style="font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:20px;background:<?= $ctBadge[1] ?>;color:<?= $ctBadge[2] ?>">
                    <?= $ctBadge[0] ?>
                </span>
            </td>
            <td>
                <a href="shipping.php?print=<?= (int)$ct['id'] ?>" target="_blank"
                   style="font-size:11px;color:var(--gold);text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:4px;padding:4px 9px;border:1px solid var(--border);border-radius:6px">
                    <i class="bi bi-printer"></i> Print
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- ══ CHART.JS ══════════════════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Inter', sans-serif";

const isDark     = document.documentElement.getAttribute('data-theme') === 'dark';
const gridColor  = isDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.055)';
const tickColor  = isDark ? '#6b7280' : '#9ca3af';
const tooltipBg  = isDark ? '#18181b' : '#fff';
const tooltipTxt = isDark ? '#e4e4e7' : '#18181b';
const tooltipBdr = isDark ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.08)';
const tt = {backgroundColor:tooltipBg,titleColor:tooltipTxt,bodyColor:tooltipTxt,borderColor:tooltipBdr,borderWidth:1,padding:10,cornerRadius:8};

// ── Donut: qty done vs remaining ─────────────────────────────────────────────
(function(){
    const done = <?= round($totalDone, 2) ?>;
    const rem  = <?= round(max(0, $totalQty - $totalDone), 2) ?>;
    const pct  = <?= min(100, round($totalDone / $totalQty * 100)) ?>;
    document.getElementById('donutPct').textContent = pct + '%';
    document.getElementById('lbl-done').textContent = done + ' pcs';
    document.getElementById('lbl-rem').textContent  = rem  + ' pcs';
    if(done === 0 && rem === 0) return;
    new Chart(document.getElementById('pieChart'),{
        type:'doughnut',
        data:{
            labels:['Done','Remaining'],
            datasets:[{
                data:[done, rem],
                backgroundColor:['#22c55e', isDark?'rgba(255,255,255,.08)':'rgba(0,0,0,.07)'],
                borderWidth:0,
                hoverOffset:4,
                borderRadius:4,
                spacing:2
            }]
        },
        options:{
            responsive:true,
            maintainAspectRatio:false,
            cutout:'76%',
            plugins:{
                legend:{display:false},
                tooltip:{...tt, callbacks:{label:ctx=>` ${ctx.label}: ${ctx.parsed} pcs`}}
            }
        }
    });
})();

// ── Stacked bar: orders per customer ─────────────────────────────────────────
(function(){
    const labels    = <?= json_encode($custNames) ?>;
    const completed = <?= json_encode($barCompleted) ?>;
    const progress  = <?= json_encode($barInProgress) ?>;
    const shipped   = <?= json_encode($barShipped) ?>;
    if(!labels.length) return;
    new Chart(document.getElementById('barChart'),{
        type:'bar',
        data:{
            labels,
            datasets:[
                {label:'Completed',  data:completed, backgroundColor:'#22c55e', borderRadius:{topLeft:4,topRight:4}, borderSkipped:false},
                {label:'In Progress',data:progress,  backgroundColor:'#f97316', borderRadius:{topLeft:4,topRight:4}, borderSkipped:false},
                {label:'Shipped',    data:shipped,   backgroundColor:'#3b82f6', borderRadius:{topLeft:4,topRight:4}, borderSkipped:false},
            ]
        },
        options:{
            responsive:true,
            maintainAspectRatio:false,
            interaction:{mode:'index',intersect:false},
            scales:{
                x:{stacked:true,grid:{display:false},ticks:{color:tickColor,font:{size:11},maxRotation:0}},
                y:{stacked:true,beginAtZero:true,ticks:{stepSize:1,color:tickColor,font:{size:10.5}},grid:{color:gridColor,drawBorder:false}}
            },
            plugins:{
                legend:{position:'bottom',labels:{padding:16,font:{size:10.5},color:tickColor,usePointStyle:true,pointStyleWidth:8}},
                tooltip:tt
            }
        }
    });
})();
</script>
<?php pwfOfficeFooter(); ?>
