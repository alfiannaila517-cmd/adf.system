<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Surat Jalan – <?= htmlspecialchars($container['container_code']) ?><?= isset($custNameOnly) ? ' ('.$custNameOnly.')' : '' ?></title>
    <style>
        * { box-sizing:border-box; margin:0; padding:0 }
        body { font-family:Arial,sans-serif; font-size:12px; color:#111; background:#fff; padding:24px 30px }
        .header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:18px; padding-bottom:14px; border-bottom:2px solid #111 }
        .co-logo { width:60px; height:60px; object-fit:contain; margin-right:12px }
        .co-name { font-size:16px; font-weight:700; margin-bottom:2px }
        .co-sub { font-size:10.5px; color:#555; line-height:1.5 }
        .doc-title h1 { font-size:22px; font-weight:900; letter-spacing:1px }
        .doc-title .doc-no { font-size:12px; color:#555; margin-top:3px }
        .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:0; margin-bottom:16px; border:1px solid #ccc; border-radius:4px; overflow:hidden }
        .info-box { padding:10px 14px; border-right:1px solid #ccc }
        .info-box:last-child { border-right:none }
        .info-box h4 { font-size:9.5px; text-transform:uppercase; letter-spacing:.5px; color:#888; margin-bottom:6px }
        .info-row { display:flex; gap:6px; margin-bottom:3px; font-size:11.5px }
        .info-label { min-width:100px; color:#666 }
        .info-val { font-weight:600 }
        .cust-banner { background:#FFF7ED; border:1px solid #FED7AA; border-radius:6px; padding:10px 14px; margin-bottom:14px; display:flex; align-items:center; gap:10px }
        table { width:100%; border-collapse:collapse; margin-bottom:16px }
        thead tr { background:#111; color:#fff }
        th { padding:8px 10px; text-align:left; font-size:11px; font-weight:600; letter-spacing:.3px }
        td { padding:8px 10px; border-bottom:1px solid #e5e5e5; font-size:11.5px; vertical-align:top }
        tbody tr:nth-child(even) { background:#F9F9F9 }
        .no-col { width:32px; text-align:center }
        .qty-col { width:64px; text-align:center; font-weight:700 }
        tfoot td { border-top:2px solid #111; font-weight:700; padding:9px 10px }
        .cust-sum { border:1px solid #e5e5e5; border-radius:4px; padding:10px 14px; margin-bottom:14px; background:#f9f9f9 }
        .cust-sum h4 { font-size:9.5px; text-transform:uppercase; letter-spacing:.5px; color:#888; margin-bottom:6px }
        .cust-sum-row { display:flex; justify-content:space-between; font-size:11.5px; padding:3px 0; border-bottom:1px solid #ececec }
        .cust-sum-row:last-child { border-bottom:none }
        .signatures { display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; margin-top:28px }
        .sig-box { text-align:center; border:1px solid #ccc; border-radius:6px; padding:14px }
        .sig-box .sig-title { font-size:10.5px; text-transform:uppercase; letter-spacing:.5px; color:#666; margin-bottom:40px }
        .sig-box .sig-name { border-top:1px solid #aaa; padding-top:6px; font-size:11px; color:#444; min-height:18px }
        .footer-note { font-size:10.5px; color:#888; text-align:center; border-top:1px solid #e5e5e5; padding-top:12px; margin-top:14px }
        @media print { body{padding:10px 16px} .print-btn{display:none!important} @page{margin:12mm 14mm} }
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
                <div class="co-sub"><?= htmlspecialchars($companyAddr) ?><?php if ($companyPhone): ?><br>Tel: <?= htmlspecialchars($companyPhone) ?><?php endif; ?></div>
            </div>
        </div>
        <div class="doc-title" style="text-align:right">
            <h1>SURAT JALAN</h1>
            <?php if (isset($custNameOnly)): ?>
            <div style="font-size:11px;font-weight:700;color:#C2410C;margin-top:2px">Customer: <?= htmlspecialchars($custNameOnly) ?></div>
            <?php endif; ?>
            <div class="doc-no">No: <?= htmlspecialchars($container['container_code']) ?></div>
            <div class="doc-no">Tanggal: <?= date('d F Y', strtotime($container['shipment_date'])) ?></div>
            <?php if (!empty($container['dropped_at'])): ?>
            <div class="doc-no" style="color:#7c3aed">On Board: <?= date('d M Y H:i', strtotime($container['dropped_at'])) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Customer Banner (per-customer print only) -->
    <?php if (isset($custNameOnly)): ?>
    <div class="cust-banner">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="#C2410C"><circle cx="10" cy="6" r="4"/><path d="M2 18c0-4.418 3.582-8 8-8s8 3.582 8 8H2z"/></svg>
        <div>
            <div style="font-size:14px;font-weight:800;color:#92400E"><?= htmlspecialchars($custNameOnly) ?></div>
            <div style="font-size:10.5px;color:#B45309">Surat Jalan khusus customer ini dalam container <?= htmlspecialchars($container['container_code']) ?></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Container Info -->
    <div class="info-grid">
        <div class="info-box">
            <h4>Informasi Pengiriman</h4>
            <div class="info-row"><span class="info-label">No. Container</span><span class="info-val"><?= htmlspecialchars($container['container_no'] ?: '—') ?></span></div>
            <div class="info-row"><span class="info-label">Tipe Container</span><span class="info-val"><?= strtoupper(htmlspecialchars($container['container_type'])) ?></span></div>
            <div class="info-row"><span class="info-label">Tanggal Kirim</span><span class="info-val"><?= date('d M Y', strtotime($container['shipment_date'])) ?></span></div>
            <div class="info-row"><span class="info-label">Forwarder</span><span class="info-val"><?= htmlspecialchars($container['forwarder'] ?: '—') ?></span></div>
            <?php if ($container['bl_no']): ?><div class="info-row"><span class="info-label">No. BL</span><span class="info-val"><?= htmlspecialchars($container['bl_no']) ?></span></div><?php endif; ?>
            <?php if ($container['tracking_no']): ?><div class="info-row"><span class="info-label">Tracking</span><span class="info-val"><?= htmlspecialchars($container['tracking_no']) ?></span></div><?php endif; ?>
        </div>
        <div class="info-box">
            <h4>Tujuan &amp; Status</h4>
            <div class="info-row"><span class="info-label">Negara</span><span class="info-val"><?= htmlspecialchars($container['destination_country'] ?: '—') ?></span></div>
            <div class="info-row"><span class="info-label">Port</span><span class="info-val"><?= htmlspecialchars($container['destination_port'] ?: '—') ?></span></div>
            <div class="info-row"><span class="info-label">Status</span><span class="info-val"><?= strtoupper(htmlspecialchars($container['status'])) ?></span></div>
            <!-- Customer breakdown (container-wide print) -->
            <?php if (!empty($custSummary)): ?>
            <div style="margin-top:8px">
                <div style="font-size:9.5px;text-transform:uppercase;letter-spacing:.5px;color:#888;margin-bottom:4px">Customer</div>
                <?php foreach ($custSummary as $cn => $qty): ?>
                <div class="info-row"><span class="info-label"><?= htmlspecialchars($cn) ?></span><span class="info-val"><?= rtrim(rtrim(number_format($qty,2),'0'),'.') ?> pcs</span></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Items Table -->
    <table>
        <thead>
            <tr>
                <th class="no-col">No</th>
                <th>Kode Order</th>
                <th>Nama Produk</th>
                <th>Spesifikasi</th>
                <th>Dimensi (cm)</th>
                <th class="qty-col">Dikirim</th>
                <th class="qty-col">Total PO</th>
                <?php if (!isset($custNameOnly)): ?><th>Customer</th><?php endif; ?>
                <th>Ket.</th>
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
                <td class="qty-col" style="font-size:13px"><?= rtrim(rtrim(number_format((float)$item['qty_shipped'],2),'0'),'.') ?></td>
                <td class="qty-col" style="color:#666"><?= rtrim(rtrim(number_format((float)$item['quantity'],2),'0'),'.') ?></td>
                <?php if (!isset($custNameOnly)): ?><td><?= htmlspecialchars($item['customer_name'] ?? '—') ?></td><?php endif; ?>
                <td style="font-size:10.5px;color:#666"><?= htmlspecialchars($item['item_notes'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($items)): ?><tr><td colspan="9" style="text-align:center;padding:20px;color:#aaa">Belum ada item</td></tr><?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" style="text-align:right">TOTAL QTY DIKIRIM:</td>
                <td class="qty-col" style="font-size:14px"><?= rtrim(rtrim(number_format($totalQty,2),'0'),'.') ?></td>
                <td colspan="<?= isset($custNameOnly)?2:3 ?>"></td>
            </tr>
        </tfoot>
    </table>

    <?php if ($container['notes']): ?>
    <div style="border:1px solid #e5e5e5;border-radius:4px;padding:10px 14px;margin-bottom:16px;font-size:11.5px">
        <strong>Catatan:</strong> <?= htmlspecialchars($container['notes']) ?>
    </div>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="signatures">
        <div class="sig-box"><div class="sig-title">Dibuat Oleh</div><div class="sig-name"></div></div>
        <div class="sig-box"><div class="sig-title">Mengetahui / Mandor</div><div class="sig-name"></div></div>
        <div class="sig-box"><div class="sig-title">Penerima</div><div class="sig-name"></div></div>
    </div>

    <div class="footer-note">Dokumen ini dicetak otomatis oleh PWF Office System · <?= date('d/m/Y H:i') ?></div>
</body>
</html>
