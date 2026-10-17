<?php

/**
 * Tampilan dokumen invoice ADF System (A4, siap cetak / simpan PDF).
 * Dipakai oleh admin/invoice-view.php (dengan tombol aksi) dan invoice.php (halaman publik untuk pelanggan).
 *
 *   adf_invoice_render_document($invoice, $settings, [
 *       'asset_base' => '../',      // awalan path ke folder situs (admin = '../', publik = '')
 *       'actions'    => '<a ...>',  // HTML tombol aksi tambahan (hanya admin)
 *       'public'     => false,      // true → tambah noindex & tanpa tombol admin
 *   ]);
 */
require_once __DIR__ . '/invoices-store.php';

/** Lambang ADF (huruf A segitiga) sebagai SVG inline — dipakai untuk merek & watermark. */
function adf_invoice_mark_svg(int $size = 48, string $stroke = '#0b0e14', string $accent = '#ff6a1a'): string
{
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
        . '<path d="M50 9 91 86H71L50 46 29 86H9L50 9Z" fill="' . $stroke . '"/>'
        . '<path d="M36 70h28" stroke="' . $accent . '" stroke-width="9" stroke-linecap="round"/>'
        . '</svg>';
}

function adf_invoice_render_document(array $inv, array $settings, array $opts = []): void
{
    $base = (string) ($opts['asset_base'] ?? '');
    $actions = (string) ($opts['actions'] ?? '');
    $flash = (string) ($opts['flash'] ?? '');
    $public = !empty($opts['public']);
    $e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    $t = adf_invoice_totals($inv);
    $status = adf_invoice_display_status($inv);
    $cust = $inv['customer'] ?? [];
    $logo = '';
    try {
        require_once __DIR__ . '/content-store.php';
        $logo = (string) (adf_load_content()['branding']['logo'] ?? '');
    } catch (Throwable $ex) {
    }
    $banks = array_values(array_filter((array) ($settings['banks'] ?? []), static fn($b) => trim((string) ($b['number'] ?? '')) !== ''));
    $terbilang = ucfirst(adf_terbilang((int) floor($t['total']))) . ' rupiah';
    $contact = array_filter([(string) ($settings['email'] ?? ''), (string) ($settings['whatsapp'] ?? ''), (string) ($settings['website'] ?? '')]);

    $statusTone = ['paid' => 'ok', 'unpaid' => 'warn', 'overdue' => 'bad', 'draft' => 'gray', 'cancelled' => 'gray'][$status] ?? 'gray';
    $stamp = ['paid' => 'LUNAS', 'draft' => 'DRAFT', 'cancelled' => 'BATAL', 'overdue' => 'JATUH TEMPO'][$status] ?? '';
    $title = 'Invoice ' . ($inv['number'] ?? '') . ' - ' . ($cust['name'] ?? '');
    ?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($public): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
    <title><?php echo $e($title); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --ink: #0b0e14; --ink-2: #334155; --muted: #64748b; --line: #e6e9ef; --soft: #f7f8fb; --acc: #ff6a1a; --acc-soft: #fff1e8; --ok: #15803d; --ok-bg: #dcfce7; --warn: #b45309; --warn-bg: #fef3c7; --bad: #b91c1c; --bad-bg: #fee2e2; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', system-ui, sans-serif; background: #e9ebf0; color: var(--ink); font-size: 12px; line-height: 1.55; padding: 24px 12px 96px; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-variant-numeric: tabular-nums; }
        .page { position: relative; width: 794px; min-height: 1123px; margin: 0 auto; background: #fff; box-shadow: 0 2px 6px rgba(0, 0, 0, .06), 0 28px 60px -28px rgba(0, 0, 0, .3); display: flex; flex-direction: column; overflow: hidden; }
        .topband { height: 10px; background: linear-gradient(90deg, var(--ink) 0 72%, var(--acc) 72% 100%); }
        .inner { position: relative; z-index: 2; flex: 1; padding: 34px 46px 0; }

        /* Watermark ADF System: tengah halaman, miring, samar tapi terlihat */
        .wm { position: absolute; z-index: 1; top: 52%; left: 50%; width: 600px; transform: translate(-50%, -50%) rotate(-16deg); text-align: center; opacity: .095; pointer-events: none; user-select: none; }
        .wm .mk { display: block; margin: 0 auto; }
        .wm .tx { font-weight: 800; font-size: 74px; letter-spacing: .06em; line-height: 1; margin-top: 8px; color: var(--ink); }
        .wm .tx b { color: var(--acc); font-weight: 800; }
        .wm .sub { font-weight: 700; font-size: 20px; letter-spacing: .5em; color: var(--ink); margin-top: 10px; }
        .stamp { position: absolute; z-index: 1; top: 40%; right: 52px; transform: rotate(-14deg); border: 5px double currentColor; border-radius: 14px; padding: 6px 26px; font-weight: 800; font-size: 46px; letter-spacing: .14em; line-height: 1.1; opacity: .13; pointer-events: none; }
        .stamp.ok { color: var(--ok); } .stamp.bad { color: var(--bad); } .stamp.gray { color: #475569; } .stamp.warn { color: var(--warn); }

        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; }
        .brand { display: flex; gap: 14px; align-items: center; }
        .brand-mark { width: 56px; height: 56px; border-radius: 14px; background: var(--ink); display: grid; place-items: center; flex-shrink: 0; overflow: hidden; }
        .brand-mark img { width: 100%; height: 100%; object-fit: contain; }
        .brand h1 { font-size: 22px; font-weight: 800; letter-spacing: -.02em; line-height: 1.1; }
        .brand h1 span { color: var(--acc); }
        .brand .tag { font-size: 10.5px; color: var(--muted); margin-top: 2px; }
        .brand .addr { font-size: 10px; color: var(--muted); margin-top: 6px; max-width: 280px; line-height: 1.45; }
        .doc { text-align: right; }
        .doc .t { font-size: 34px; font-weight: 800; letter-spacing: .2em; margin-right: -.2em; line-height: 1; }
        .doc .t::after { content: ''; display: block; width: 64px; height: 4px; background: var(--acc); border-radius: 3px; margin: 8px 0 0 auto; }
        .doc .no { margin-top: 10px; font-size: 13px; font-weight: 700; color: var(--ink-2); }
        .pill { display: inline-block; margin-top: 8px; padding: 3px 12px; border-radius: 999px; font-size: 10px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        .pill.ok { background: var(--ok-bg); color: var(--ok); } .pill.warn { background: var(--warn-bg); color: var(--warn); } .pill.bad { background: var(--bad-bg); color: var(--bad); } .pill.gray { background: #e8ebf0; color: #475569; }

        .parties { display: grid; grid-template-columns: 1.25fr 1fr; gap: 14px; margin: 26px 0 20px; }
        .card { border: 1px solid var(--line); border-radius: 12px; padding: 13px 16px; background: rgba(255, 255, 255, .82); }
        .card h3 { font-size: 9px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; color: var(--acc); padding-bottom: 7px; margin-bottom: 9px; border-bottom: 1px solid var(--acc-soft); }
        .card .nm { font-size: 15px; font-weight: 800; line-height: 1.3; }
        .card .co { font-size: 12px; font-weight: 600; color: var(--ink-2); margin-top: 1px; }
        .card .ln { font-size: 11px; color: var(--muted); margin-top: 3px; white-space: pre-line; }
        .kv { display: grid; grid-template-columns: 96px 1fr; gap: 5px 10px; font-size: 11.5px; }
        .kv span:nth-child(odd) { color: var(--muted); } .kv span:nth-child(even) { font-weight: 600; }

        table.items { width: 100%; border-collapse: collapse; }
        table.items thead th { background: var(--ink); color: #fff; font-size: 9.5px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; padding: 10px 12px; text-align: left; border-bottom: 3px solid var(--acc); }
        table.items thead th:first-child { border-radius: 9px 0 0 0; } table.items thead th:last-child { border-radius: 0 9px 0 0; }
        table.items td { padding: 11px 12px; border-bottom: 1px solid var(--line); vertical-align: top; font-size: 12px; }
        table.items tbody tr { break-inside: avoid; }
        table.items tbody tr:nth-child(even) td { background: rgba(247, 248, 251, .85); }
        table.items .d { font-weight: 600; } table.items .n { display: block; margin-top: 2px; font-size: 10.5px; color: var(--muted); font-weight: 400; }
        table.items .r { text-align: right; white-space: nowrap; } table.items .c { text-align: center; }

        .sumrow { display: grid; grid-template-columns: 1fr 290px; gap: 22px; margin-top: 16px; align-items: start; }
        .words { background: var(--acc-soft); border-left: 3px solid var(--acc); border-radius: 0 10px 10px 0; padding: 10px 14px; font-size: 10.5px; color: #7a3410; }
        .words small { display: block; font-size: 8.5px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: var(--acc); margin-bottom: 3px; }
        .words i { font-style: italic; font-weight: 600; }
        .totals { border: 1px solid var(--line); border-radius: 12px; overflow: hidden; background: #fff; }
        .totals .row { display: flex; justify-content: space-between; gap: 10px; padding: 8px 14px; font-size: 12px; }
        .totals .row + .row { border-top: 1px solid var(--line); }
        .totals .row span:first-child { color: var(--muted); } .totals .row span:last-child { font-weight: 600; white-space: nowrap; }
        .totals .disc span:last-child { color: var(--bad); }
        .totals .grand { background: var(--ink); padding: 12px 14px; }
        .totals .grand span:first-child { color: #cbd5e1; font-size: 11px; font-weight: 700; letter-spacing: .12em; }
        .totals .grand span:last-child { color: #fff; font-size: 16px; font-weight: 800; }
        .totals .grand span:last-child em { font-style: normal; color: var(--acc); }

        .pay { margin-top: 22px; }
        .pay h3, .sec h3 { font-size: 9px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; color: var(--acc); margin-bottom: 8px; }
        .banks { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 10px; }
        .bank { border: 1px solid var(--line); border-radius: 11px; padding: 10px 14px; background: var(--soft); }
        .bank b { display: block; font-size: 11px; letter-spacing: .06em; text-transform: uppercase; color: var(--ink-2); }
        .bank .num { font-size: 16px; font-weight: 800; letter-spacing: .06em; margin: 3px 0 1px; }
        .bank small { font-size: 10.5px; color: var(--muted); }
        .bank-missing { border: 1.5px dashed #f59e0b; background: #fffbeb; color: #92400e; border-radius: 11px; padding: 10px 14px; font-size: 11.5px; }
        .bank-missing a { color: #c2410c; font-weight: 700; }
        .pay .pnote { margin-top: 8px; font-size: 10.5px; color: var(--muted); }
        .cols2 { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; margin-top: 20px; }
        .sec .tx { font-size: 10.5px; color: var(--ink-2); white-space: pre-line; line-height: 1.6; }
        .sign { margin-top: 22px; display: flex; justify-content: flex-end; }
        .sign > div { width: 220px; text-align: center; font-size: 10.5px; color: var(--muted); }
        .sign .sp { height: 54px; }
        .sign .ln { border-top: 1px solid var(--ink); padding-top: 5px; font-weight: 700; color: var(--ink); font-size: 11.5px; }
        .foot { margin-top: 26px; background: var(--ink); color: #cbd5e1; padding: 15px 46px 17px; text-align: center; position: relative; z-index: 2; }
        .foot::before { content: ''; position: absolute; left: 0; top: 0; width: 28%; height: 3px; background: var(--acc); }
        .foot .ty { color: #fff; font-weight: 700; font-size: 12.5px; }
        .foot .ct { font-size: 10px; margin-top: 3px; letter-spacing: .02em; }

        .flash { width: 794px; max-width: 100%; margin: 0 auto 12px; padding: 10px 16px; border-radius: 10px; background: #ecfdf5; border: 1px solid #a7f3d0; color: #047857; font-weight: 600; font-size: 12.5px; }
        .flash.bad { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
        .actions { position: fixed; bottom: 18px; left: 50%; transform: translateX(-50%); z-index: 50; display: flex; gap: 8px; padding: 8px; border-radius: 14px; background: rgba(255, 255, 255, .92); backdrop-filter: blur(10px); box-shadow: 0 12px 30px -10px rgba(0, 0, 0, .4); flex-wrap: wrap; justify-content: center; max-width: calc(100vw - 24px); }
        .actions a, .actions button { border: 0; cursor: pointer; font: inherit; font-weight: 700; font-size: 12px; padding: 9px 16px; border-radius: 10px; text-decoration: none; background: #eef1f6; color: var(--ink); display: inline-flex; align-items: center; }
        .actions .pri { background: var(--acc); color: #fff; }

        @page { size: A4; margin: 0; }
        @media print {
            body { background: #fff; padding: 0; }
            .page { box-shadow: none; width: 100%; min-height: 296mm; margin: 0; }
            .actions, .flash, .nobank { display: none !important; }
            .page { width: 210mm; }
            .wm { position: fixed; } /* diulang di setiap halaman cetak */
            .stamp { position: fixed; }
        }
        @media screen and (max-width: 820px) {
            body { padding: 10px 0 96px; }
            .page { width: 100%; min-height: 0; }
            .inner { padding: 22px 18px 0; }
            .head, .parties, .sumrow, .cols2 { display: block; }
            .doc { text-align: left; margin-top: 14px; } .doc .t::after { margin-left: 0; }
            .card { margin-bottom: 10px; } .totals { margin-top: 12px; }
            table.items { font-size: 11px; } table.items td, table.items th { padding: 8px 6px; }
            .foot { padding: 14px 18px; } .wm { width: 320px; } .wm .tx { font-size: 44px; }
        }
    </style>
</head>

<body>
    <?php if ($flash !== ''): ?><div class="flash<?php echo stripos($flash, 'gagal') !== false ? ' bad' : ''; ?>"><?php echo $e($flash); ?></div><?php endif; ?>
    <div class="page" id="invoicePage">
        <div class="topband"></div>
        <div class="wm"><span class="mk"><?php echo adf_invoice_mark_svg(210, '#0b0e14', '#ff6a1a'); ?></span><div class="tx">ADF<b>system</b></div><div class="sub">DEVELOPER</div></div>
        <?php if ($stamp !== ''): ?><div class="stamp <?php echo $e($statusTone); ?>"><?php echo $e($stamp); ?></div><?php endif; ?>

        <div class="inner">
            <div class="head">
                <div class="brand">
                    <div class="brand-mark">
                        <?php if ($logo !== ''): ?><img src="<?php echo $e($base . $logo); ?>" alt=""><?php else: ?><?php echo adf_invoice_mark_svg(34, '#ffffff', '#ff6a1a'); ?><?php endif; ?>
                    </div>
                    <div>
                        <h1>ADF<span>system</span></h1>
                        <div class="tag"><?php echo $e($settings['tagline'] ?? ''); ?></div>
                        <div class="addr">
                            <b style="color:var(--ink-2);"><?php echo $e($settings['company_name'] ?? ''); ?></b><br>
                            <?php echo $e($settings['address'] ?? ''); ?>
                        </div>
                    </div>
                </div>
                <div class="doc">
                    <div class="t">INVOICE</div>
                    <div class="no"><?php echo $e($inv['number'] ?? ''); ?></div>
                    <span class="pill <?php echo $e($statusTone); ?>"><?php echo $e(adf_invoice_status_label($status)); ?></span>
                </div>
            </div>

            <div class="parties">
                <div class="card">
                    <h3>Ditagihkan kepada</h3>
                    <div class="nm"><?php echo $e($cust['name'] ?? ''); ?></div>
                    <?php if (!empty($cust['company'])): ?><div class="co"><?php echo $e($cust['company']); ?></div><?php endif; ?>
                    <?php if (!empty($cust['address'])): ?><div class="ln"><?php echo $e($cust['address']); ?></div><?php endif; ?>
                    <?php if (!empty($cust['email'])): ?><div class="ln"><?php echo $e($cust['email']); ?></div><?php endif; ?>
                    <?php if (!empty($cust['phone'])): ?><div class="ln"><?php echo $e($cust['phone']); ?></div><?php endif; ?>
                </div>
                <div class="card">
                    <h3>Detail invoice</h3>
                    <div class="kv">
                        <span>No. Invoice</span><span><?php echo $e($inv['number'] ?? ''); ?></span>
                        <span>Tanggal terbit</span><span><?php echo $e(adf_invoice_date($inv['issue_date'] ?? '')); ?></span>
                        <span>Jatuh tempo</span><span><?php echo $e(adf_invoice_date($inv['due_date'] ?? '')); ?></span>
                        <?php if (!empty($inv['paid_at'])): ?><span>Dibayar</span><span><?php echo $e(adf_invoice_date($inv['paid_at'])); ?></span><?php endif; ?>
                    </div>
                </div>
            </div>

            <table class="items">
                <thead>
                    <tr>
                        <th style="width:34px;">#</th>
                        <th>Deskripsi</th>
                        <th class="c" style="width:56px;">Qty</th>
                        <th class="r" style="width:120px;">Harga</th>
                        <th class="r" style="width:130px;">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (($inv['items'] ?? []) as $i => $it):
                        $qty = (float) ($it['qty'] ?? 0);
                        $price = (float) ($it['unit_price'] ?? 0); ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><span class="d"><?php echo $e($it['desc'] ?? ''); ?></span><?php if (!empty($it['note'])): ?><span class="n"><?php echo $e($it['note']); ?></span><?php endif; ?></td>
                            <td class="c"><?php echo $e(rtrim(rtrim(number_format($qty, 2, ',', '.'), '0'), ',')); ?></td>
                            <td class="r"><?php echo $e(adf_invoice_rp($price)); ?></td>
                            <td class="r"><b><?php echo $e(adf_invoice_rp($qty * $price)); ?></b></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="sumrow">
                <div class="words"><small>Terbilang</small><i><?php echo $e($terbilang); ?></i></div>
                <div class="totals">
                    <div class="row"><span>Subtotal</span><span><?php echo $e(adf_invoice_rp($t['subtotal'])); ?></span></div>
                    <?php if ($t['discount'] > 0): ?>
                        <div class="row disc"><span>Diskon<?php echo ($inv['discount_type'] ?? '') === 'percent' ? ' (' . $e(rtrim(rtrim(number_format((float) $inv['discount_value'], 2, ',', '.'), '0'), ',')) . '%)' : ''; ?></span><span>- <?php echo $e(adf_invoice_rp($t['discount'])); ?></span></div>
                    <?php endif; ?>
                    <?php if ($t['tax'] > 0): ?>
                        <div class="row"><span>PPN <?php echo $e(rtrim(rtrim(number_format($t['tax_percent'], 2, ',', '.'), '0'), ',')); ?>%</span><span><?php echo $e(adf_invoice_rp($t['tax'])); ?></span></div>
                    <?php endif; ?>
                    <div class="row grand"><span>TOTAL</span><span><em>Rp</em> <?php echo $e(number_format($t['total'], 0, ',', '.')); ?></span></div>
                </div>
            </div>

            <?php if ($banks): ?>
                <div class="pay">
                    <h3>Cara pembayaran</h3>
                    <div class="banks">
                        <?php foreach ($banks as $b): ?>
                            <div class="bank"><b><?php echo $e($b['bank'] ?? ''); ?></b><div class="num"><?php echo $e($b['number'] ?? ''); ?></div><small>a.n. <?php echo $e($b['holder'] ?? ''); ?></small></div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (trim((string) ($settings['payment_note'] ?? '')) !== ''): ?><div class="pnote"><?php echo $e($settings['payment_note']); ?></div><?php endif; ?>
                </div>
            <?php elseif (!$public): ?>
                <div class="pay nobank"><h3>Cara pembayaran</h3><div class="bank-missing">Nomor rekening belum diatur, jadi belum tampil di invoice. Isi di <a href="invoice-settings.php">Pengaturan Invoice → Rekening pembayaran</a>.</div></div>
            <?php endif; ?>

            <?php $noteTxt = trim((string) ($inv['notes'] ?? '')); $termsTxt = trim((string) ($settings['terms'] ?? '')); ?>
            <?php if ($noteTxt !== '' || $termsTxt !== ''): ?>
                <div class="cols2">
                    <div class="sec"><?php if ($noteTxt !== ''): ?><h3>Catatan</h3><div class="tx"><?php echo $e($noteTxt); ?></div><?php endif; ?></div>
                    <div class="sec"><?php if ($termsTxt !== ''): ?><h3>Syarat &amp; ketentuan</h3><div class="tx"><?php echo $e($termsTxt); ?></div><?php endif; ?></div>
                </div>
            <?php endif; ?>

            <div class="sign">
                <div>
                    Hormat kami,
                    <div class="sp"></div>
                    <div class="ln"><?php echo $e(trim((string) ($settings['signer_name'] ?? '')) !== '' ? $settings['signer_name'] : ($settings['company_name'] ?? '')); ?></div>
                    <?php if (trim((string) ($settings['signer_title'] ?? '')) !== ''): ?><div><?php echo $e($settings['signer_title']); ?></div><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="foot">
            <div class="ty">Terima kasih atas kepercayaan Anda</div>
            <div class="ct"><?php echo $e(implode('  ·  ', array_merge([(string) ($settings['company_name'] ?? '')], $contact))); ?></div>
        </div>
    </div>

    <div class="actions">
        <button type="button" class="pri" onclick="window.print()">Cetak / Simpan PDF</button>
        <?php echo $actions; ?>
    </div>
</body>

</html>
<?php
}
