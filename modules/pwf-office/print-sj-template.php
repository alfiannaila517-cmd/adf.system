<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Delivery Note – <?= htmlspecialchars($container['container_code']) ?><?= isset($custNameOnly) ? ' ('.$custNameOnly.')' : '' ?></title>
    <style>
        * { box-sizing:border-box; margin:0; padding:0 }
        body { font-family:'Segoe UI',Arial,sans-serif; font-size:12px; color:#111; background:#fff; padding:24px 32px }

        /* ── Header ── */
        .dn-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:20px; padding-bottom:16px; border-bottom:3px solid #111 }
        .co-logo { height:90px; width:auto; max-width:130px; object-fit:contain; margin-right:16px }
        .co-name { font-size:18px; font-weight:800; margin-bottom:3px; color:#111 }
        .co-sub  { font-size:10.5px; color:#555; line-height:1.7; max-width:280px }
        .doc-no  { font-size:11.5px; color:#555; margin-top:4px }
        .doc-no strong { color:#111 }

        /* ── Info grid ── */
        .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:0; margin-bottom:16px; border:1px solid #d1d5db; border-radius:7px; overflow:hidden }
        .info-box  { padding:12px 16px; border-right:1px solid #d1d5db }
        .info-box:last-child { border-right:none }
        .info-box h4 { font-size:9px; text-transform:uppercase; letter-spacing:.8px; color:#6b7280; margin-bottom:8px; font-weight:700 }
        .info-row  { display:flex; gap:8px; margin-bottom:4px; font-size:11.5px }
        .info-label { min-width:110px; color:#6b7280 }
        .info-val  { font-weight:700; color:#111 }

        /* ── Customer banner ── */
        .cust-banner { background:linear-gradient(135deg,#FFF7ED 0%,#FEF3C7 100%); border:1px solid #FCD34D; border-radius:8px; padding:12px 18px; margin-bottom:16px; display:flex; align-items:center; gap:14px }

        /* ── Table ── */
        table { width:100%; border-collapse:collapse; margin-bottom:16px }
        thead tr { background:#1f2937; color:#fff }
        th { padding:9px 10px; text-align:left; font-size:9.5px; font-weight:700; letter-spacing:.5px; text-transform:uppercase }
        td { padding:9px 10px; border-bottom:1px solid #e5e7eb; vertical-align:middle; font-size:11.5px }
        tbody tr:nth-child(even) { background:#f9fafb }
        .no-col { width:28px; text-align:center }

        /* ── Fulfillment cell ── */
        .prog-track { height:5px; background:#e5e7eb; border-radius:3px; overflow:hidden; margin-bottom:3px }
        .prog-fill  { height:5px; border-radius:3px }
        .pct-label  { font-size:9px; color:#9ca3af; margin-bottom:4px }
        .pill       { display:inline-flex; align-items:center; gap:3px; padding:2px 7px; border-radius:20px; font-size:9px; font-weight:600 }
        .pill-po    { background:#e0f2fe; color:#0369a1 }
        .pill-prod  { background:#dbeafe; color:#1d4ed8 }

        /* ── tfoot ── */
        tfoot td { border-top:2px solid #1f2937; font-weight:700; padding:10px 10px; background:#f9fafb }

        /* ── Signatures ── */
        .signatures { display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; margin-top:32px }
        .sig-box    { text-align:center; border:1px solid #d1d5db; border-radius:8px; padding:16px }
        .sig-title  { font-size:9.5px; text-transform:uppercase; letter-spacing:.6px; color:#6b7280; margin-bottom:44px; font-weight:600 }
        .sig-name   { border-top:1px solid #9ca3af; padding-top:6px; font-size:11px; color:#374151; min-height:18px }

        .footer-note { font-size:10px; color:#9ca3af; text-align:center; border-top:1px solid #e5e7eb; padding-top:12px; margin-top:16px }
        @media print {
            body { padding:10px 14px }
            .print-btn { display:none !important }
            @page { margin:8mm 10mm }
        }
    </style>
</head>
<body>

<div style="text-align:right;margin-bottom:14px">
    <button class="print-btn" onclick="window.print()"
        style="padding:9px 26px;background:#1f2937;color:#fff;border:none;border-radius:7px;font-size:13px;cursor:pointer;font-weight:700;letter-spacing:.3px">
        🖨️ Print / Save PDF
    </button>
</div>

<!-- ══ HEADER ══════════════════════════════════════════════════════════════ -->
<div class="dn-header">
    <div style="display:flex;align-items:center">
        <?php if ($logoUrl): ?>
        <img src="<?= htmlspecialchars($logoUrl) ?>" class="co-logo" alt="logo">
        <?php endif; ?>
        <div>
            <div class="co-name"><?= htmlspecialchars($companyName) ?></div>
            <div class="co-sub"><?= htmlspecialchars($companyAddr) ?><?php if ($companyPhone): ?><br>Tel: <?= htmlspecialchars($companyPhone) ?><?php endif; ?></div>
        </div>
    </div>
    <div style="text-align:right">
        <div style="font-size:28px;font-weight:900;letter-spacing:2.5px;color:#111">DELIVERY NOTE</div>
        <?php if (isset($custNameOnly)): ?>
        <div style="font-size:11px;font-weight:700;color:#C2410C;margin-top:4px;letter-spacing:.3px">Customer: <?= htmlspecialchars($custNameOnly) ?></div>
        <?php endif; ?>
        <div class="doc-no">No: <strong><?= htmlspecialchars($container['container_code']) ?></strong></div>
        <div class="doc-no">Date: <?= date('d F Y', strtotime($container['shipment_date'])) ?></div>
        <?php if (!empty($container['dropped_at'])): ?>
        <div class="doc-no" style="color:#7c3aed">On Board: <?= date('d M Y H:i', strtotime($container['dropped_at'])) ?></div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ CUSTOMER BANNER (per-customer only) ═════════════════════════════════ -->
<?php if (isset($custNameOnly)): ?>
<div class="cust-banner">
    <svg width="24" height="24" viewBox="0 0 20 20" fill="#B45309"><circle cx="10" cy="6" r="4"/><path d="M2 18c0-4.418 3.582-8 8-8s8 3.582 8 8H2z"/></svg>
    <div>
        <div style="font-size:15px;font-weight:800;color:#92400E"><?= htmlspecialchars($custNameOnly) ?></div>
        <div style="font-size:10.5px;color:#B45309;margin-top:2px">
            Delivery Note for this customer · Container <strong><?= htmlspecialchars($container['container_code']) ?></strong>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ SHIPPING INFO ════════════════════════════════════════════════════════ -->
<div class="info-grid">
    <div class="info-box">
        <h4>Shipping Information</h4>
        <div class="info-row"><span class="info-label">Container No.</span><span class="info-val"><?= htmlspecialchars($container['container_no'] ?: '—') ?></span></div>
        <div class="info-row"><span class="info-label">Container Type</span><span class="info-val"><?= strtoupper(htmlspecialchars($container['container_type'])) ?></span></div>
        <div class="info-row"><span class="info-label">Shipment Date</span><span class="info-val"><?= date('d M Y', strtotime($container['shipment_date'])) ?></span></div>
        <div class="info-row"><span class="info-label">Forwarder</span><span class="info-val"><?= htmlspecialchars($container['forwarder'] ?: '—') ?></span></div>
        <?php if ($container['bl_no']): ?><div class="info-row"><span class="info-label">BL No.</span><span class="info-val"><?= htmlspecialchars($container['bl_no']) ?></span></div><?php endif; ?>
        <?php if ($container['tracking_no']): ?><div class="info-row"><span class="info-label">Tracking No.</span><span class="info-val"><?= htmlspecialchars($container['tracking_no']) ?></span></div><?php endif; ?>
    </div>
    <div class="info-box">
        <h4>Destination &amp; Status</h4>
        <div class="info-row"><span class="info-label">Country</span><span class="info-val"><?= htmlspecialchars($container['destination_country'] ?: '—') ?></span></div>
        <div class="info-row"><span class="info-label">Port</span><span class="info-val"><?= htmlspecialchars($container['destination_port'] ?: '—') ?></span></div>
        <div class="info-row"><span class="info-label">Status</span><span class="info-val"><?= strtoupper(htmlspecialchars($container['status'])) ?></span></div>
        <?php if (!empty($custSummary)): ?>
        <div style="margin-top:10px">
            <div style="font-size:9px;text-transform:uppercase;letter-spacing:.6px;color:#6b7280;margin-bottom:5px;font-weight:700">Customers in this container</div>
            <?php foreach ($custSummary as $cn => $qty): ?>
            <div class="info-row"><span class="info-label"><?= htmlspecialchars($cn) ?></span><span class="info-val"><?= fmtQty($qty) ?> pcs</span></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ ITEMS TABLE ══════════════════════════════════════════════════════════ -->
<table>
    <thead>
        <tr>
            <th class="no-col">#</th>
            <th style="min-width:100px">Order Code</th>
            <th style="min-width:120px">Product Name</th>
            <th style="min-width:110px">Specification</th>
            <th style="min-width:72px">Dim. (cm)</th>
            <th style="text-align:center;min-width:70px">This Shipment</th>
            <th style="min-width:180px">Order Fulfillment</th>
            <?php if (!isset($custNameOnly)): ?><th style="min-width:80px">Customer</th><?php endif; ?>
            <th>Notes</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($items as $i => $item):
            $qty_po        = (float)$item['quantity'];
            $qty_done      = (float)($item['qty_done'] ?? 0);
            $qty_here      = (float)$item['qty_shipped'];
            $total_shipped = (float)($item['total_all_shipped'] ?? $qty_here);
            $remaining     = max(0, $qty_po - $total_shipped);
            $pct_ship      = $qty_po > 0 ? min(100, round($total_shipped / $qty_po * 100)) : 0;
            $bar_color     = $pct_ship >= 100 ? '#16a34a' : ($pct_ship >= 60 ? '#3b82f6' : '#f59e0b');
            $rem_color     = $remaining <= 0 ? '#15803d' : '#c2410c';
            $rem_bg        = $remaining <= 0 ? '#dcfce7' : '#fff7ed';
        ?>
        <tr>
            <td class="no-col" style="font-size:10px;color:#9ca3af"><?= $i + 1 ?></td>
            <td><strong style="color:#1f2937;font-size:12px"><?= htmlspecialchars($item['order_code']) ?></strong></td>
            <td><?= htmlspecialchars($item['product_name']) ?></td>
            <td style="font-size:10.5px;color:#6b7280"><?= nl2br(htmlspecialchars(mb_substr($item['specification'] ?? '', 0, 80))) ?></td>
            <td style="font-size:11px;color:#374151"><?= htmlspecialchars($item['dimensions'] ?: '—') ?></td>

            <!-- ── This Shipment qty ── -->
            <td style="text-align:center;padding:8px 6px;border-left:2px solid #e5e7eb">
                <div style="font-size:20px;font-weight:900;color:#1f2937;line-height:1"><?= fmtQty($qty_here) ?></div>
                <div style="font-size:8.5px;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-top:1px">pcs</div>
            </td>

            <!-- ── Order Fulfillment ── -->
            <td style="padding:7px 10px;border-left:1px solid #e5e7eb">
                <!-- Progress bar: total shipped / total PO -->
                <div class="prog-track">
                    <div class="prog-fill" style="width:<?= $pct_ship ?>%;background:<?= $bar_color ?>"></div>
                </div>
                <div class="pct-label">
                    <?= $pct_ship ?>% shipped &nbsp;·&nbsp;
                    <?= fmtQty($total_shipped) ?> of <?= fmtQty($qty_po) ?> pcs total
                </div>
                <!-- Stat pills -->
                <div style="display:flex;gap:5px;flex-wrap:wrap">
                    <span class="pill pill-po">PO&nbsp;<?= fmtQty($qty_po) ?></span>
                    <span class="pill pill-prod">Prod&nbsp;<?= fmtQty($qty_done) ?></span>
                    <span class="pill" style="background:<?= $rem_bg ?>;color:<?= $rem_color ?>">
                        <?php if ($remaining <= 0): ?>✓ Complete<?php else: ?>Bal&nbsp;<?= fmtQty($remaining) ?><?php endif; ?>
                    </span>
                </div>
            </td>

            <?php if (!isset($custNameOnly)): ?>
            <td style="font-size:11px;color:#374151"><?= htmlspecialchars($item['customer_name'] ?? '—') ?></td>
            <?php endif; ?>
            <td style="font-size:10.5px;color:#6b7280"><?= htmlspecialchars($item['item_notes'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($items)): ?>
        <tr><td colspan="9" style="text-align:center;padding:24px;color:#9ca3af;font-style:italic">No items in this delivery note</td></tr>
        <?php endif; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5" style="text-align:right;font-size:11px;letter-spacing:.3px">TOTAL QTY SHIPPED (THIS CONTAINER):</td>
            <td style="text-align:center;font-size:17px;font-weight:900;color:#1f2937"><?= fmtQty($totalQty) ?></td>
            <td colspan="<?= isset($custNameOnly) ? 2 : 3 ?>"></td>
        </tr>
    </tfoot>
</table>

<?php if ($container['notes']): ?>
<div style="border:1px solid #e5e7eb;border-radius:6px;padding:10px 16px;margin-bottom:18px;font-size:11.5px;background:#fafafa">
    <strong style="color:#374151">Notes:</strong> <?= htmlspecialchars($container['notes']) ?>
</div>
<?php endif; ?>

<!-- ══ SIGNATURES ════════════════════════════════════════════════════════════ -->
<div class="signatures">
    <div class="sig-box"><div class="sig-title">Prepared By</div><div class="sig-name"></div></div>
    <div class="sig-box"><div class="sig-title">Verified By / Supervisor</div><div class="sig-name"></div></div>
    <div class="sig-box"><div class="sig-title">Recipient</div><div class="sig-name"></div></div>
</div>

<div class="footer-note">Auto-generated by PWF Office System · <?= date('d/m/Y H:i') ?></div>
</body>
</html>
