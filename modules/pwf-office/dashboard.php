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

// ── Live production overview per order (all active) ──────────────────────────
$productionOrders = $pdo->query("
    SELECT o.id, o.order_code, o.product_name, o.specification, o.quantity, o.qty_done,
           o.progress_percent, o.status, o.due_date,
           c.customer_name, c.id AS customer_id,
           t.craftsman_name,
           COALESCE((SELECT SUM(ci.qty_shipped) FROM pwf_container_items ci WHERE ci.order_id=o.id),0) AS qty_shipped
    FROM pwf_orders o
    LEFT JOIN pwf_customers c ON c.id=o.customer_id
    LEFT JOIN pwf_craftsmen t ON t.id=o.assigned_craftsman_id
    WHERE o.status NOT IN ('cancelled')
    ORDER BY FIELD(o.status,'on_progress','partial_ship','ready_ship','completed','shipped','qc','draft'), o.id DESC
")->fetchAll();

// ── Per-customer summary ──────────────────────────────────────────────────────
$custSummary = $pdo->query("
    SELECT c.id, c.customer_name,
           COUNT(o.id)                                                          AS total_orders,
           SUM(o.quantity)                                                      AS total_qty,
           SUM(o.qty_done)                                                      AS total_done,
           SUM(CASE WHEN o.status IN ('completed','shipped') THEN 1 ELSE 0 END) AS finished_orders,
           SUM(CASE WHEN o.status IN ('ready_ship','completed') THEN 1 ELSE 0 END) AS ready_orders,
           COALESCE((SELECT SUM(ci.qty_shipped)
                     FROM pwf_container_items ci
                     JOIN pwf_orders o2 ON o2.id=ci.order_id
                     WHERE o2.customer_id=c.id), 0)                            AS total_shipped
    FROM pwf_customers c
    LEFT JOIN pwf_orders o ON o.customer_id=c.id AND o.status NOT IN ('cancelled')
    GROUP BY c.id, c.customer_name
    HAVING total_orders > 0
    ORDER BY total_done DESC
")->fetchAll();

// ── Ready to ship orders ──────────────────────────────────────────────────────
$readyToShipOrders = $pdo->query("
    SELECT o.order_code, o.product_name, o.quantity, o.qty_done,
           COALESCE((SELECT SUM(ci.qty_shipped) FROM pwf_container_items ci WHERE ci.order_id=o.id),0) AS qty_shipped,
           c.customer_name, o.status, o.due_date
    FROM pwf_orders o
    LEFT JOIN pwf_customers c ON c.id=o.customer_id
    WHERE o.status IN ('ready_ship','completed') AND o.id NOT IN (
        SELECT DISTINCT order_id FROM pwf_container_items
        JOIN pwf_orders oo ON oo.id=pwf_container_items.order_id
        WHERE oo.status='shipped'
    )
    ORDER BY o.id DESC
")->fetchAll();

// ── Order status breakdown (pie) ─────────────────────────────────────────────
$statusRows   = $pdo->query("SELECT status, COUNT(*) AS cnt FROM pwf_orders GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$statusLabels = array_keys($statusRows);
$statusValues = array_values($statusRows);

// ── Monthly orders ────────────────────────────────────────────────────────────
$monthly = $pdo->query("
    SELECT DATE_FORMAT(created_at,'%b %Y') AS month_label,
           DATE_FORMAT(created_at,'%Y-%m') AS month_key,
           COUNT(*) AS cnt
    FROM pwf_orders
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month_key, month_label
    ORDER BY month_key ASC
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
        <div><div class="stat-val"><?= $stats['active_orders'] ?></div><div class="stat-lbl">Order Aktif</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#F5F3FF"><i class="bi bi-hammer" style="color:#6D28D9"></i></div>
        <div><div class="stat-val"><?= $stats['in_progress'] ?></div><div class="stat-lbl">Sedang Produksi</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#F0FDF4"><i class="bi bi-check2-circle" style="color:#15803D"></i></div>
        <div><div class="stat-val"><?= $stats['ready_ship'] ?></div><div class="stat-lbl">Siap Kirim</div></div>
    </div>
</div>

<!-- ══ SECTION: SIAP KIRIM ══════════════════════════════════════════════════ -->
<?php if (!empty($readyToShipOrders)): ?>
<div class="pwf-card" style="margin-bottom:20px;border-left:4px solid #15803D">
    <div class="pwf-card-header" style="background:rgba(21,128,61,.06)">
        <i class="bi bi-box-seam me-2" style="color:#15803D"></i>
        <span style="color:#15803D;font-weight:700">Siap Dikirim</span>
        <span style="margin-left:auto;font-size:11px;background:#F0FDF4;color:#15803D;padding:3px 10px;border-radius:20px;font-weight:700">
            <?= count($readyToShipOrders) ?> order
        </span>
    </div>
    <div style="padding:0">
    <table class="pwf-table">
        <thead>
            <tr>
                <th>Order</th>
                <th>Customer</th>
                <th>Produk</th>
                <th style="text-align:center">Total PO</th>
                <th style="text-align:center">Selesai</th>
                <th style="text-align:center">Sudah Kirim</th>
                <th style="text-align:center">Sisa Kirim</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($readyToShipOrders as $r):
            $sisaKirim = max(0, (float)$r['qty_done'] - (float)$r['qty_shipped']);
        ?>
            <tr>
                <td><code style="font-size:12px;color:var(--gold)"><?= htmlspecialchars($r['order_code']) ?></code></td>
                <td style="font-weight:600"><?= htmlspecialchars($r['customer_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($r['product_name']) ?></td>
                <td style="text-align:center;font-weight:700"><?= rtrim(rtrim(number_format((float)$r['quantity'],2),'0'),'.') ?></td>
                <td style="text-align:center;font-weight:700;color:#15803D"><?= rtrim(rtrim(number_format((float)$r['qty_done'],2),'0'),'.') ?></td>
                <td style="text-align:center;color:var(--muted)"><?= rtrim(rtrim(number_format((float)$r['qty_shipped'],2),'0'),'.') ?></td>
                <td style="text-align:center">
                    <span style="font-weight:800;font-size:14px;color:<?= $sisaKirim>0?'#3b82f6':'#9ca3af' ?>">
                        <?= rtrim(rtrim(number_format($sisaKirim,2),'0'),'.') ?>
                    </span>
                </td>
                <td><span class="status-badge status-<?= $r['status'] ?>"><?= str_replace('_',' ',ucfirst($r['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- ══ SECTION: PRODUKSI LIVE ════════════════════════════════════════════════ -->
<?php if (!empty($productionOrders)): ?>
<div class="pwf-card" style="margin-bottom:20px">
    <div class="pwf-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
        <span><i class="bi bi-bar-chart-steps me-2" style="color:var(--gold)"></i>Progress Produksi — Semua Order</span>
        <span style="font-size:11px;color:var(--muted)"><?= count($productionOrders) ?> order</span>
    </div>
    <div style="padding:0">
    <table class="pwf-table">
        <thead>
            <tr>
                <th>Order / Customer</th>
                <th>Produk</th>
                <th>Pengrajin</th>
                <th style="text-align:center">Total PO</th>
                <th style="text-align:center">Selesai</th>
                <th style="text-align:center">Kurang</th>
                <th style="text-align:center">Sudah Kirim</th>
                <th>Progress</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($productionOrders as $r):
            $pct      = (int)$r['progress_percent'];
            $kurang   = max(0, (float)$r['quantity'] - (float)$r['qty_done']);
            $barColor = $pct >= 100 ? '#15803D' : ($pct >= 60 ? '#D4A017' : '#C2410C');
            $isOverdue= $r['due_date'] && strtotime($r['due_date']) < time() && !in_array($r['status'],['completed','shipped']);
            $statusMap = [
                'draft'        => ['Draft',        'status-draft'],
                'on_progress'  => ['In Progress',  'status-on_progress'],
                'partial_ship' => ['Partial Ship', 'status-partial_ship'],
                'ready_ship'   => ['Ready Ship',   'status-ready_ship'],
                'completed'    => ['Completed',    'status-completed'],
                'shipped'      => ['Shipped',      'status-shipped'],
                'qc'           => ['QC',           'status-qc'],
            ];
            $sl = $statusMap[$r['status']] ?? [ucfirst($r['status']),''];
        ?>
            <tr>
                <td>
                    <code style="font-size:11.5px;color:var(--gold)"><?= htmlspecialchars($r['order_code']) ?></code>
                    <div style="font-size:11.5px;font-weight:600;color:var(--text);margin-top:1px">
                        <?= htmlspecialchars($r['customer_name'] ?? '—') ?>
                    </div>
                    <?php if ($isOverdue): ?>
                    <span style="font-size:9.5px;background:#FEF2F2;color:#991B1B;border-radius:4px;padding:1px 5px;font-weight:600">Overdue</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="font-size:13px;font-weight:600;color:var(--text)"><?= htmlspecialchars($r['product_name']) ?></div>
                    <?php if ($r['specification']): ?><div style="font-size:10.5px;color:var(--muted)"><?= htmlspecialchars(mb_substr($r['specification'],0,40)) ?></div><?php endif; ?>
                </td>
                <td style="font-size:12px"><?= htmlspecialchars($r['craftsman_name'] ?? '—') ?></td>
                <td style="text-align:center;font-weight:700"><?= rtrim(rtrim(number_format((float)$r['quantity'],2),'0'),'.') ?></td>
                <td style="text-align:center;font-weight:700;color:<?= $pct>=100?'#15803D':'var(--text)' ?>">
                    <?= rtrim(rtrim(number_format((float)$r['qty_done'],2),'0'),'.') ?>
                </td>
                <td style="text-align:center;font-weight:700;color:<?= $kurang>0?'#C2410C':'#15803D' ?>">
                    <?= $kurang > 0 ? rtrim(rtrim(number_format($kurang,2),'0'),'.') : '–' ?>
                </td>
                <td style="text-align:center;color:var(--muted)">
                    <?= (float)$r['qty_shipped'] > 0 ? rtrim(rtrim(number_format((float)$r['qty_shipped'],2),'0'),'.') : '–' ?>
                </td>
                <td style="min-width:120px">
                    <div style="display:flex;align-items:center;gap:7px">
                        <div style="flex:1;height:7px;background:var(--border);border-radius:20px;overflow:hidden">
                            <div style="width:<?= $pct ?>%;height:100%;background:<?= $barColor ?>;border-radius:20px;transition:width .4s"></div>
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
</div>
<?php endif; ?>

<!-- ══ SECTION: RINGKASAN PER CUSTOMER ══════════════════════════════════════ -->
<div class="pwf-card" style="margin-bottom:20px">
    <div class="pwf-card-header">
        <i class="bi bi-people me-2" style="color:var(--gold)"></i>Ringkasan Per Customer
    </div>
    <div style="padding:0">
    <table class="pwf-table">
        <thead>
            <tr>
                <th>Customer</th>
                <th style="text-align:center">Order</th>
                <th style="text-align:center">Total PO (pcs)</th>
                <th style="text-align:center">Selesai Prod.</th>
                <th style="text-align:center">Kurang</th>
                <th style="text-align:center">Sudah Kirim</th>
                <th style="text-align:center">Siap Kirim</th>
                <th>Progress</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($custSummary as $r):
            $done    = (float)$r['total_done'];
            $total   = max(0.0001,(float)$r['total_qty']);
            $pct     = min(100,(int)round($done/$total*100));
            $kurang  = max(0,$total-$done);
            $shipped = (float)$r['total_shipped'];
            $barColor= $pct>=100?'#15803D':($pct>=60?'#D4A017':'#C2410C');
        ?>
            <tr>
                <td style="font-weight:700;font-size:13px"><?= htmlspecialchars($r['customer_name']) ?></td>
                <td style="text-align:center">
                    <span style="font-weight:700"><?= (int)$r['total_orders'] ?></span>
                    <?php if ((int)$r['finished_orders']>0): ?>
                    <span style="font-size:10px;color:#15803D;margin-left:3px">(<?= (int)$r['finished_orders'] ?> selesai)</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;font-weight:700"><?= rtrim(rtrim(number_format($total,2),'0'),'.') ?></td>
                <td style="text-align:center;font-weight:700;color:<?= $pct>=100?'#15803D':'var(--text)' ?>">
                    <?= rtrim(rtrim(number_format($done,2),'0'),'.') ?>
                </td>
                <td style="text-align:center;font-weight:700;color:<?= $kurang>0?'#C2410C':'#9ca3af' ?>">
                    <?= $kurang>0 ? rtrim(rtrim(number_format($kurang,2),'0'),'.') : '–' ?>
                </td>
                <td style="text-align:center;color:var(--muted)">
                    <?= $shipped>0 ? rtrim(rtrim(number_format($shipped,2),'0'),'.') : '–' ?>
                </td>
                <td style="text-align:center">
                    <?php if ((int)$r['ready_orders']>0): ?>
                    <span style="font-weight:700;color:#15803D;font-size:13px"><?= (int)$r['ready_orders'] ?> order</span>
                    <?php else: ?>
                    <span style="color:var(--muted)">–</span>
                    <?php endif; ?>
                </td>
                <td style="min-width:130px">
                    <div style="display:flex;align-items:center;gap:7px">
                        <div style="flex:1;height:7px;background:var(--border);border-radius:20px;overflow:hidden">
                            <div style="width:<?= $pct ?>%;height:100%;background:<?= $barColor ?>;border-radius:20px"></div>
                        </div>
                        <span style="font-size:11.5px;font-weight:700;color:<?= $barColor ?>;min-width:32px"><?= $pct ?>%</span>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($custSummary)): ?>
            <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:24px">Belum ada data.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- ══ CHARTS ROW ═══════════════════════════════════════════════════════════ -->
<div class="grid2" style="margin-bottom:20px">
    <div class="pwf-card">
        <div class="pwf-card-header"><i class="bi bi-pie-chart me-2" style="color:var(--gold)"></i>Status Breakdown</div>
        <div class="pwf-card-body" style="padding:20px">
            <div style="position:relative;width:100%;height:240px"><canvas id="pieChart"></canvas></div>
        </div>
    </div>
    <div class="pwf-card">
        <div class="pwf-card-header"><i class="bi bi-graph-up me-2" style="color:var(--gold)"></i>Order Bulanan (6 Bulan)</div>
        <div class="pwf-card-body" style="padding:20px">
            <div style="position:relative;width:100%;height:240px"><canvas id="lineChart"></canvas></div>
        </div>
    </div>
</div>

<!-- ══ CHART.JS ══════════════════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.color = '#78716C';

// PIE
(function(){
    const labels = <?= json_encode(array_map(fn($s)=>ucwords(str_replace('_',' ',$s)), $statusLabels)) ?>;
    const values = <?= json_encode($statusValues) ?>;
    const palette = ['#1D4ED8','#C2410C','#6D28D9','#15803D','#047857','#D4A017','#9ca3af'];
    if(!values.length||values.every(v=>v==0)) return;
    new Chart(document.getElementById('pieChart'),{
        type:'doughnut',
        data:{labels,datasets:[{data:values,backgroundColor:palette.slice(0,values.length),borderWidth:2,borderColor:'#fff',hoverOffset:6}]},
        options:{responsive:true,maintainAspectRatio:false,cutout:'60%',plugins:{legend:{position:'bottom',labels:{padding:12,font:{size:11}}}}}
    });
})();

// LINE
(function(){
    const labels = <?= json_encode(array_column($monthly,'month_label')) ?>;
    const values = <?= json_encode(array_map('intval',array_column($monthly,'cnt'))) ?>;
    if(!labels.length) return;
    new Chart(document.getElementById('lineChart'),{
        type:'line',
        data:{labels,datasets:[{label:'Orders',data:values,borderColor:'#D4A017',backgroundColor:'rgba(212,160,23,.12)',tension:.4,fill:true,pointBackgroundColor:'#D4A017',pointRadius:4}]},
        options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true,ticks:{stepSize:1}}},plugins:{legend:{display:false}}}
    });
})();
</script>
<?php pwfOfficeFooter(); ?>
