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

// ── Order status breakdown (pie) ─────────────────────────────────────────────
$statusRows   = $pdo->query("SELECT status, COUNT(*) AS cnt FROM pwf_orders GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$statusLabels = array_keys($statusRows);
$statusValues = array_values($statusRows);

// ── Monthly orders ────────────────────────────────────────────────────────────
// ── Monthly orders (last 6 months axis) ─────────────────────────────────────
$monthlyAxis = $pdo->query("
    SELECT DISTINCT DATE_FORMAT(created_at,'%b %Y') AS month_label,
                    DATE_FORMAT(created_at,'%Y-%m') AS month_key
    FROM pwf_orders
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    ORDER BY month_key ASC
")->fetchAll(PDO::FETCH_KEY_PAIR); // month_label => month_key
$monthLabels = array_keys($monthlyAxis);
$monthKeys   = array_values($monthlyAxis);

// ── Orders per customer per month ────────────────────────────────────────────
$perCustMonthly = $pdo->query("
    SELECT c.customer_name,
           DATE_FORMAT(o.created_at,'%Y-%m') AS month_key,
           COUNT(o.id) AS cnt
    FROM pwf_orders o
    JOIN pwf_customers c ON c.id=o.customer_id
    WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY c.customer_name, month_key
    ORDER BY c.customer_name, month_key
")->fetchAll();

// Build dataset per customer
$custDatasets = [];
foreach ($perCustMonthly as $row) {
    $custDatasets[$row['customer_name']][$row['month_key']] = (int)$row['cnt'];
}
$chartPalette = ['#D4A017','#3b82f6','#22c55e','#f97316','#a855f7','#ec4899','#14b8a6','#f43f5e'];
$custChartData = [];
$pi = 0;
foreach ($custDatasets as $custName => $byMonth) {
    $data = [];
    foreach ($monthKeys as $mk) {
        $data[] = $byMonth[$mk] ?? 0;
    }
    $col = $chartPalette[$pi % count($chartPalette)];
    $custChartData[] = [
        'label'           => $custName,
        'data'            => $data,
        'borderColor'     => $col,
        'backgroundColor' => $col.'26',
        'tension'         => 0.4,
        'fill'            => false,
        'pointRadius'     => 4,
        'pointHoverRadius'=> 6,
        'borderWidth'     => 2,
    ];
    $pi++;
}

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
<div style="display:grid;grid-template-columns:220px 1fr;gap:16px;margin-bottom:20px;align-items:start">

    <!-- Donut: Status Breakdown -->
    <div class="pwf-card">
        <div class="pwf-card-header" style="padding:10px 14px;font-size:11.5px">
            <i class="bi bi-pie-chart me-2" style="color:var(--gold)"></i>Status Breakdown
        </div>
        <div style="padding:14px 10px 10px">
            <div style="position:relative;width:100%;height:180px">
                <canvas id="pieChart"></canvas>
            </div>
            <div id="pieLegend" style="display:flex;flex-direction:column;gap:4px;margin-top:10px"></div>
        </div>
    </div>

    <!-- Line: Orders per Customer per Month -->
    <div class="pwf-card">
        <div class="pwf-card-header" style="padding:10px 14px;font-size:11.5px;display:flex;align-items:center;justify-content:space-between">
            <span><i class="bi bi-graph-up me-2" style="color:var(--gold)"></i>Orders per Customer — Last 6 Months</span>
        </div>
        <div style="padding:14px 16px 12px">
            <div style="position:relative;width:100%;height:180px">
                <canvas id="lineChart"></canvas>
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

const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
const gridColor  = isDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.06)';
const tickColor  = isDark ? '#6b7280' : '#9ca3af';
const tooltipBg  = isDark ? '#1C1C1F' : '#fff';
const tooltipTxt = isDark ? '#ECECEC' : '#1C1C1F';

// ── Donut ─────────────────────────────────────────────────────────────────────
(function(){
    const labels = <?= json_encode(array_map(fn($s)=>ucwords(str_replace('_',' ',$s)), $statusLabels)) ?>;
    const values = <?= json_encode($statusValues) ?>;
    if(!values.length||values.every(v=>v==0)) return;
    const palette = ['#3b82f6','#f97316','#a855f7','#22c55e','#14b8a6','#D4A017','#6b7280'];
    const total   = values.reduce((a,b)=>a+b,0);

    new Chart(document.getElementById('pieChart'),{
        type:'doughnut',
        data:{labels,datasets:[{
            data:values,
            backgroundColor:palette.slice(0,values.length),
            borderWidth:0,
            hoverOffset:5,
            borderRadius:4,
            spacing:2
        }]},
        options:{
            responsive:true,
            maintainAspectRatio:false,
            cutout:'72%',
            plugins:{
                legend:{display:false},
                tooltip:{
                    backgroundColor:tooltipBg,
                    titleColor:tooltipTxt,
                    bodyColor:tooltipTxt,
                    borderColor:isDark?'rgba(255,255,255,.08)':'rgba(0,0,0,.08)',
                    borderWidth:1,
                    padding:10,
                    callbacks:{label:ctx=>` ${ctx.label}: ${ctx.parsed} (${Math.round(ctx.parsed/total*100)}%)`}
                }
            }
        }
    });

    // custom legend
    const leg = document.getElementById('pieLegend');
    labels.forEach((l,i)=>{
        if(!values[i]) return;
        const pct = Math.round(values[i]/total*100);
        const row = document.createElement('div');
        row.style.cssText='display:flex;align-items:center;justify-content:space-between;gap:6px;font-size:10.5px';
        row.innerHTML=`<span style="display:flex;align-items:center;gap:5px"><span style="width:8px;height:8px;border-radius:50%;background:${palette[i]};flex-shrink:0"></span><span style="color:${tickColor}">${l}</span></span><span style="font-weight:700;color:${tooltipTxt}">${values[i]} <span style="font-weight:400;color:${tickColor}">(${pct}%)</span></span>`;
        leg.appendChild(row);
    });
})();

// ── Line: per customer per month ─────────────────────────────────────────────
(function(){
    const labels   = <?= json_encode($monthLabels) ?>;
    const datasets = <?= json_encode($custChartData) ?>;
    if(!labels.length||!datasets.length) return;

    new Chart(document.getElementById('lineChart'),{
        type:'line',
        data:{labels,datasets},
        options:{
            responsive:true,
            maintainAspectRatio:false,
            interaction:{mode:'index',intersect:false},
            scales:{
                x:{
                    grid:{color:gridColor,drawBorder:false},
                    ticks:{color:tickColor,font:{size:10.5}}
                },
                y:{
                    beginAtZero:true,
                    ticks:{stepSize:1,color:tickColor,font:{size:10.5}},
                    grid:{color:gridColor,drawBorder:false}
                }
            },
            plugins:{
                legend:{
                    position:'bottom',
                    labels:{padding:14,font:{size:10.5},color:tickColor,
                            usePointStyle:true,pointStyleWidth:8}
                },
                tooltip:{
                    backgroundColor:tooltipBg,
                    titleColor:tooltipTxt,
                    bodyColor:tooltipTxt,
                    borderColor:isDark?'rgba(255,255,255,.08)':'rgba(0,0,0,.08)',
                    borderWidth:1,
                    padding:10
                }
            }
        }
    });
})();
</script>
<?php pwfOfficeFooter(); ?>
