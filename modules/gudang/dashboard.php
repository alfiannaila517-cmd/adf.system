<?php
/**
 * Gudang Nasita — Dashboard
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();
if (!($auth->hasPermission('gudang_nasita') || $auth->hasPermission('warehouse') || $auth->hasPermission('gudang_view'))) {
    http_response_code(403); echo 'Akses ditolak.'; exit;
}

$db = Database::getInstance();
$pageTitle = 'Dashboard Gudang';

// ── Summary stats ─────────────────────────────────────────────────────────────
$totalItems    = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM gudang_nasita_stock WHERE COALESCE(is_active,1)=1")['c'] ?? 0);
$totalQty      = (float)($db->fetchOne("SELECT COALESCE(SUM(quantity),0) AS q FROM gudang_nasita_stock WHERE COALESCE(is_active,1)=1")['q'] ?? 0);
$lowStockCount = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM gudang_nasita_stock WHERE COALESCE(is_active,1)=1 AND reorder_level>0 AND quantity<=reorder_level")['c'] ?? 0);
$transfersToday= (int)($db->fetchOne("SELECT COUNT(*) AS c FROM gudang_nasita_transfers WHERE DATE(COALESCE(tanggal_transfer,transfer_date,created_at))=CURDATE()")['c'] ?? 0);

// ── Low stock items ────────────────────────────────────────────────────────────
$lowStockItems = $db->fetchAll(
    "SELECT item_name, quantity, reorder_level, unit, COALESCE(category,'lainnya') AS category
     FROM gudang_nasita_stock
     WHERE COALESCE(is_active,1)=1 AND reorder_level>0 AND quantity<=reorder_level
     ORDER BY (quantity/reorder_level) ASC LIMIT 8"
) ?: [];

// ── Barang masuk terbaru ───────────────────────────────────────────────────────
$masukTerbaru = $db->fetchAll(
    "SELECT gm.quantity, gm.movement_type, gm.notes,
            COALESCE(gm.movement_date, gm.created_at) AS tgl,
            gs.item_name, gs.unit
     FROM gudang_nasita_movements gm
     JOIN gudang_nasita_stock gs ON gm.stock_id = gs.id
     WHERE gm.movement_type IN ('in_supplier','in_manual','manual_in')
     ORDER BY COALESCE(gm.movement_date, gm.created_at) DESC LIMIT 8"
) ?: [];

// ── Barang keluar / transfer terbaru ─────────────────────────────────────────
$keluarTerbaru = $db->fetchAll(
    "SELECT gm.quantity, gm.notes,
            COALESCE(gm.movement_date, gm.created_at) AS tgl,
            gs.item_name, gs.unit
     FROM gudang_nasita_movements gm
     JOIN gudang_nasita_stock gs ON gm.stock_id = gs.id
     WHERE gm.movement_type IN ('out_transfer','transfer_out')
     ORDER BY COALESCE(gm.movement_date, gm.created_at) DESC LIMIT 8"
) ?: [];

// ── Permintaan terbaru (transfers) ────────────────────────────────────────────
$permintaanTerbaru = $db->fetchAll(
    "SELECT COALESCE(transfer_number, no_transfer) AS no_transfer,
            COALESCE(target_business_name, bisnis_tujuan) AS bisnis_tujuan,
            COALESCE(tanggal_transfer, transfer_date, created_at) AS tgl,
            total_qty, items_count,
            COALESCE(status,'completed') AS status
     FROM gudang_nasita_transfers
     ORDER BY COALESCE(tanggal_transfer, transfer_date, created_at) DESC LIMIT 8"
) ?: [];

// ── 7-day movement chart data ─────────────────────────────────────────────────
$chartDays = $chartMasuk = $chartKeluar = [];
for ($i = 6; $i >= 0; $i--) {
    $chartDays[] = date('d M', strtotime("-$i days"));
    $d = date('Y-m-d', strtotime("-$i days"));
    $chartMasuk[]  = (float)($db->fetchOne("SELECT COALESCE(SUM(quantity),0) AS q FROM gudang_nasita_movements WHERE movement_type IN ('in_supplier','in_manual','manual_in') AND DATE(COALESCE(movement_date,created_at))=?", [$d])['q'] ?? 0);
    $chartKeluar[] = (float)($db->fetchOne("SELECT COALESCE(SUM(quantity),0) AS q FROM gudang_nasita_movements WHERE movement_type IN ('out_transfer','transfer_out') AND DATE(COALESCE(movement_date,created_at))=?", [$d])['q'] ?? 0);
}

// ── Transfer qty per bisnis for pie chart ────────────────────────────────────
$bizTransferRows = $db->fetchAll(
    "SELECT COALESCE(target_business_name, bisnis_tujuan, 'Lainnya') AS bisnis,
            COALESCE(SUM(total_qty),0) AS total
     FROM gudang_nasita_transfers
     GROUP BY COALESCE(target_business_name, bisnis_tujuan, 'Lainnya')
     ORDER BY total DESC LIMIT 6"
) ?: [];

include __DIR__ . '/../../includes/header.php';
?>

<style>
.gd-stat{border-radius:1rem;padding:1.2rem 1.4rem;display:flex;align-items:center;gap:1rem;}
.gd-stat-icon{width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.gd-stat-icon svg{width:24px;height:24px;}
.gd-card-title{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:.2rem;}
.gd-card-val{font-size:2rem;font-weight:800;line-height:1;color:var(--text-primary);}
.gd-card-sub{font-size:.76rem;color:var(--text-muted);margin-top:.2rem;}
.gd-section-title{font-size:.95rem;font-weight:700;color:var(--text-primary);margin:0 0 .85rem;}
.gd-table{width:100%;border-collapse:collapse;font-size:.83rem;}
.gd-table th{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);padding:.45rem .7rem;border-bottom:1px solid var(--border);white-space:nowrap;}
.gd-table td{padding:.55rem .7rem;border-bottom:1px solid var(--border);}
.gd-table tr:last-child td{border-bottom:none;}
.gd-badge{display:inline-block;padding:.2rem .6rem;border-radius:999px;font-size:.7rem;font-weight:700;}
.prog-bar-wrap{height:5px;background:var(--bg-tertiary,#e2e8f0);border-radius:3px;overflow:hidden;min-width:50px;}
.prog-bar-fill{height:100%;border-radius:3px;}
</style>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.4rem;flex-wrap:wrap;gap:.75rem;">
    <div>
        <h2 style="font-size:1.45rem;font-weight:800;margin:0;color:var(--text-primary);">Dashboard Gudang Nasita</h2>
        <p style="font-size:.85rem;color:var(--text-muted);margin:.2rem 0 0;">Ringkasan operasional gudang hari ini &mdash; <?php echo date('l, d F Y'); ?></p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a href="<?php echo BASE_URL; ?>/modules/procurement/gudang-nasita.php" class="btn btn-primary" style="font-size:.82rem;"><i data-feather="archive" style="width:14px;height:14px;"></i> Stock Gudang</a>
        <a href="<?php echo BASE_URL; ?>/modules/procurement/gudang-transfer.php" class="btn btn-success" style="font-size:.82rem;"><i data-feather="send" style="width:14px;height:14px;"></i> Transfer</a>
    </div>
</div>

<!-- Stat cards -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.25rem;">
    <div class="card gd-stat">
        <div class="gd-stat-icon" style="background:#ede9fe;color:#7c3aed;"><i data-feather="package"></i></div>
        <div><div class="gd-card-title">Total Item</div><div class="gd-card-val"><?php echo $totalItems; ?></div><div class="gd-card-sub">Produk aktif</div></div>
    </div>
    <div class="card gd-stat">
        <div class="gd-stat-icon" style="background:#dcfce7;color:#16a34a;"><i data-feather="layers"></i></div>
        <div><div class="gd-card-title">Total Qty</div><div class="gd-card-val"><?php echo number_format($totalQty,0,',','.'); ?></div><div class="gd-card-sub">Unit keseluruhan</div></div>
    </div>
    <div class="card gd-stat" style="<?php echo $lowStockCount>0?'border-left:3px solid #f59e0b;':''; ?>">
        <div class="gd-stat-icon" style="background:#fef3c7;color:#d97706;"><i data-feather="alert-triangle"></i></div>
        <div><div class="gd-card-title">Stok Menipis</div><div class="gd-card-val" style="color:<?php echo $lowStockCount>0?'#d97706':'var(--text-primary)'; ?>"><?php echo $lowStockCount; ?></div><div class="gd-card-sub">Di bawah reorder</div></div>
    </div>
    <div class="card gd-stat">
        <div class="gd-stat-icon" style="background:#dbeafe;color:#2563eb;"><i data-feather="send"></i></div>
        <div><div class="gd-card-title">Transfer Hari Ini</div><div class="gd-card-val"><?php echo $transfersToday; ?></div><div class="gd-card-sub">Pengiriman ke bisnis</div></div>
    </div>
</div>

<!-- Charts -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:1rem;margin-bottom:1.25rem;">
    <div class="card" style="padding:1.25rem;">
        <div class="gd-section-title">📊 Pergerakan Stok 7 Hari Terakhir</div>
        <canvas id="movementChart" height="120"></canvas>
    </div>
    <div class="card" style="padding:1.25rem;display:flex;flex-direction:column;">
        <div class="gd-section-title">🏢 Distribusi Barang Terkirim</div>
        <?php if(empty($bizTransferRows)): ?>
            <div style="flex:1;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:.875rem;">Belum ada data transfer</div>
        <?php else: ?>
            <div style="flex:1;display:flex;align-items:center;justify-content:center;">
                <canvas id="bizPieChart" style="max-width:180px;max-height:180px;"></canvas>
            </div>
            <!-- Legend below chart -->
            <div id="bizPieLegend" style="display:flex;flex-wrap:wrap;gap:.4rem .75rem;justify-content:center;margin-top:.85rem;"></div>
        <?php endif; ?>
    </div>
</div>

<!-- 3 table panels -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.25rem;">

    <!-- Stok menipis -->
    <div class="card" style="padding:1.1rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;">
            <div class="gd-section-title" style="margin:0;color:#d97706;">⚠️ Stok Menipis</div>
            <?php if($lowStockCount>0):?><span class="gd-badge" style="background:#fef3c7;color:#92400e;"><?php echo $lowStockCount; ?></span><?php endif;?>
        </div>
        <?php if(empty($lowStockItems)):?>
            <div style="text-align:center;padding:1.5rem;color:#16a34a;font-size:.85rem;">✅ Semua stok aman</div>
        <?php else:?>
            <table class="gd-table">
                <thead><tr><th>Item</th><th>Sisa</th><th>%</th></tr></thead>
                <tbody>
                <?php foreach($lowStockItems as $it):
                    $pct=min(100,$it['reorder_level']>0?round($it['quantity']/$it['reorder_level']*100):0);
                    $bc=$pct<=25?'#ef4444':($pct<=60?'#f59e0b':'#22c55e');
                ?>
                    <tr>
                        <td><div style="font-weight:600;font-size:.81rem;"><?php echo htmlspecialchars($it['item_name']);?></div><div style="font-size:.7rem;color:var(--text-muted);"><?php echo htmlspecialchars($it['category']);?></div></td>
                        <td style="font-weight:700;color:#ef4444;white-space:nowrap;"><?php echo number_format($it['quantity'],0).' '.$it['unit'];?></td>
                        <td><div class="prog-bar-wrap"><div class="prog-bar-fill" style="width:<?php echo $pct;?>%;background:<?php echo $bc;?>;"></div></div><div style="font-size:.67rem;color:var(--text-muted);margin-top:1px;"><?php echo $pct;?>%</div></td>
                    </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        <?php endif;?>
    </div>

    <!-- Barang masuk terbaru -->
    <div class="card" style="padding:1.1rem;">
        <div class="gd-section-title" style="color:#16a34a;">📦 Barang Masuk Terbaru</div>
        <?php if(empty($masukTerbaru)):?>
            <div style="text-align:center;padding:1.5rem;color:var(--text-muted);font-size:.85rem;">Belum ada data</div>
        <?php else:?>
            <table class="gd-table">
                <thead><tr><th>Item</th><th>Qty</th><th>Tgl</th></tr></thead>
                <tbody>
                <?php foreach($masukTerbaru as $m):?>
                    <tr>
                        <td><div style="font-weight:600;font-size:.81rem;"><?php echo htmlspecialchars($m['item_name']);?></div><div style="font-size:.7rem;color:var(--text-muted);"><?php echo htmlspecialchars($m['notes']??'');?></div></td>
                        <td><span style="font-weight:700;color:#16a34a;">+<?php echo number_format($m['quantity'],0);?></span> <span style="font-size:.7rem;color:var(--text-muted);"><?php echo $m['unit'];?></span></td>
                        <td style="font-size:.71rem;color:var(--text-muted);white-space:nowrap;"><?php echo date('d M',strtotime($m['tgl']));?></td>
                    </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        <?php endif;?>
    </div>

    <!-- Barang keluar terbaru -->
    <div class="card" style="padding:1.1rem;">
        <div class="gd-section-title" style="color:#2563eb;">🚚 Barang Keluar Terbaru</div>
        <?php if(empty($keluarTerbaru)):?>
            <div style="text-align:center;padding:1.5rem;color:var(--text-muted);font-size:.85rem;">Belum ada data</div>
        <?php else:?>
            <table class="gd-table">
                <thead><tr><th>Item</th><th>Qty</th><th>Tgl</th></tr></thead>
                <tbody>
                <?php foreach($keluarTerbaru as $k):?>
                    <tr>
                        <td><div style="font-weight:600;font-size:.81rem;"><?php echo htmlspecialchars($k['item_name']);?></div><div style="font-size:.7rem;color:var(--text-muted);"><?php echo htmlspecialchars($k['notes']??'');?></div></td>
                        <td><span style="font-weight:700;color:#2563eb;"><?php echo number_format($k['quantity'],0);?></span> <span style="font-size:.7rem;color:var(--text-muted);"><?php echo $k['unit'];?></span></td>
                        <td style="font-size:.71rem;color:var(--text-muted);white-space:nowrap;"><?php echo date('d M',strtotime($k['tgl']));?></td>
                    </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        <?php endif;?>
    </div>
</div>

<!-- Permintaan / transfer terbaru -->
<div class="card" style="padding:1.1rem 1.25rem;margin-bottom:.5rem;">
    <div class="gd-section-title">📋 Riwayat Transfer Terbaru</div>
    <?php if(empty($permintaanTerbaru)):?>
        <div style="text-align:center;padding:1.5rem;color:var(--text-muted);font-size:.875rem;">Belum ada data transfer</div>
    <?php else:?>
        <div class="table-responsive">
            <table class="gd-table">
                <thead><tr><th>No Transfer</th><th>Bisnis Tujuan</th><th>Tanggal</th><th class="text-right">Qty</th><th>Item</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach($permintaanTerbaru as $tr):
                    $sc=match(strtolower($tr['status']??'completed')){'completed','received'=>['#dcfce7','#166534'],'submitted','pending'=>['#fef3c7','#92400e'],'cancelled'=>['#fee2e2','#991b1b'],default=>['#e0e7ff','#3730a3']};
                ?>
                    <tr>
                        <td style="font-weight:700;font-size:.81rem;"><?php echo htmlspecialchars($tr['no_transfer']??'-');?></td>
                        <td style="font-weight:600;"><?php echo htmlspecialchars($tr['bisnis_tujuan']??'-');?></td>
                        <td style="font-size:.76rem;color:var(--text-muted);"><?php echo $tr['tgl']?date('d M Y H:i',strtotime($tr['tgl'])):'-';?></td>
                        <td class="text-right" style="font-weight:700;"><?php echo number_format((float)($tr['total_qty']??0),0,',','.');?></td>
                        <td><?php echo (int)($tr['items_count']??0);?> item</td>
                        <td><span class="gd-badge" style="background:<?php echo $sc[0];?>;color:<?php echo $sc[1];?>;"><?php echo strtoupper($tr['status']??'DONE');?></span></td>
                    </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        </div>
    <?php endif;?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    const dark=document.body.getAttribute('data-theme')==='dark';
    const gc=dark?'rgba(255,255,255,.07)':'rgba(0,0,0,.06)';
    const lc=dark?'#94a3b8':'#64748b';
    const mCtx=document.getElementById('movementChart');
    if(mCtx){new Chart(mCtx,{type:'bar',data:{labels:<?php echo json_encode($chartDays);?>,datasets:[{label:'Masuk',data:<?php echo json_encode($chartMasuk);?>,backgroundColor:'rgba(22,163,74,.72)',borderColor:'#16a34a',borderWidth:1.5,borderRadius:6},{label:'Keluar',data:<?php echo json_encode($chartKeluar);?>,backgroundColor:'rgba(37,99,235,.65)',borderColor:'#2563eb',borderWidth:1.5,borderRadius:6}]},options:{responsive:true,plugins:{legend:{labels:{color:lc,boxWidth:11,font:{size:11}}},tooltip:{mode:'index',intersect:false}},scales:{x:{grid:{color:gc},ticks:{color:lc}},y:{grid:{color:gc},ticks:{color:lc},beginAtZero:true}}}});}
    const cCtx=document.getElementById('bizPieChart');
    const bizLabels=<?php echo json_encode(array_column($bizTransferRows,'bisnis')); ?>;
    const bizVals=<?php echo json_encode(array_map(fn($r)=>(float)$r['total'],$bizTransferRows)); ?>;
    const palette=['#7c3aed','#0ea5e9','#f59e0b','#ef4444','#10b981','#e11d48'];
    if(cCtx&&bizLabels.length){
        const chart=new Chart(cCtx,{type:'pie',data:{labels:bizLabels,datasets:[{data:bizVals,backgroundColor:palette.slice(0,bizLabels.length),borderWidth:3,borderColor:dark?'#1e293b':'#fff',hoverOffset:10}]},options:{responsive:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>' '+c.label+': '+c.parsed.toLocaleString('id-ID')+' qty'}}}}});
        // Custom legend below
        const leg=document.getElementById('bizPieLegend');
        if(leg){const total=bizVals.reduce((a,b)=>a+b,0);bizLabels.forEach((l,i)=>{const pct=total>0?Math.round(bizVals[i]/total*100):0;leg.innerHTML+=`<div style="display:flex;align-items:center;gap:.35rem;font-size:.75rem;"><span style="width:10px;height:10px;border-radius:50%;background:${palette[i]};flex-shrink:0;"></span><span style="font-weight:700;color:${palette[i]}">${l}</span><span style="color:var(--text-muted);">${pct}%</span></div>`;});}
    }
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
