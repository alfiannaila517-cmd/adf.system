<?php

/** Buat / ubah invoice. ?client=ID&period=YYYY-MM = invoice bulanan grup (baris dari paket + tambahan tiap bisnis). */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireAdmin();
$db = Biz::db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$inv = $id ? Invoice::find($id) : null;
if ($id && !$inv) {
    flash('Invoice tidak ditemukan.', 'err');
    redirect('/invoices.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_ok()) {
            throw new RuntimeException('Sesi form kedaluwarsa, silakan coba lagi.');
        }
        $newId = Invoice::saveFromPost($_POST, $id);
        $saved = Invoice::find($newId);
        Auth::audit($id ? 'invoice.update' : 'invoice.add', "{$saved['number']} · {$saved['customer_name']}");
        $msg = ($saved['kind'] === 'manual' ? 'Tagihan manual ' : 'Invoice ') . $saved['number'] . ($id ? ' disimpan.' : ' dibuat.');
        // Setelah simpan: kembali ke tempat asal (tab Langganan bisnis untuk tagihan manual, daftar Invoice selain itu) — tanpa pratinjau
        $back = $saved['kind'] === 'manual' && $saved['business_id'] ? '/business.php?id=' . (int)$saved['business_id'] . '&tab=langganan' : '/invoices.php';
        // "Simpan & kirim tagihan": langsung kirim email ke pelanggan (draft ikut diterbitkan)
        if (!empty($_POST['send'])) {
            try {
                $to = Invoice::sendEmail($newId);
                Auth::audit('invoice.send', "{$saved['number']} → {$to}");
                $msg .= " Tagihan terkirim ke {$to}.";
            } catch (Throwable $ex) {
                flash($msg . ' Belum terkirim: ' . $ex->getMessage(), 'err');
                redirect($back);
            }
        }
        flash($msg);
        redirect($back);
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
        $_SESSION['old_invoice'] = $_POST;
        redirect('/invoice-edit.php' . ($id ? "?id={$id}" : ''));
    }
}

$s = Invoice::settings();
$old = $_SESSION['old_invoice'] ?? null;
unset($_SESSION['old_invoice']);
$clientId = (int)($_GET['client'] ?? 0);
$period = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['period'] ?? '')) ? $_GET['period'] : date('Y-m');

// Nilai awal form: data lama (gagal simpan) → invoice yang diedit → invoice baru (opsional dari grup)
if ($old) {
    $f = $old;
    $items = [];
    foreach ((array)($old['item_desc'] ?? []) as $i => $d) {
        $items[] = ['description' => $d, 'note' => $old['item_note'][$i] ?? '', 'qty' => $old['item_qty'][$i] ?? 1, 'unit_price' => $old['item_price'][$i] ?? 0, 'business_id' => $old['item_biz'][$i] ?? ''];
    }
} elseif ($inv) {
    $f = ['number' => $inv['number'], 'cust_name' => $inv['customer_name'], 'cust_company' => $inv['customer_company'], 'cust_email' => $inv['customer_email'],
        'cust_phone' => $inv['customer_phone'], 'cust_address' => $inv['customer_address'], 'issue_date' => $inv['issue_date'], 'due_date' => $inv['due_date'],
        'discount_type' => $inv['discount_type'], 'discount_value' => (float)$inv['discount_value'], 'tax_percent' => (float)$inv['tax_percent'],
        'notes' => $inv['notes'], 'status' => $inv['status'], 'kind' => $inv['kind'], 'client_id' => $inv['client_id'], 'bill_business_id' => $inv['kind'] === 'manual' ? $inv['business_id'] : null];
    $items = $inv['items'];
} else {
    $f = ['number' => '', 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+' . (int)$s['default_due_days'] . ' days')),
        'discount_type' => 'rp', 'discount_value' => 0, 'tax_percent' => (float)$s['default_tax_percent'], 'notes' => '', 'status' => 'unpaid', 'kind' => 'free'];
    $items = [];
    if ($clientId && ($c = Biz::client($clientId))) {
        $f += ['cust_name' => $c['contact_name'] ?: $c['name'], 'cust_company' => $c['contact_name'] ? $c['name'] : '', 'cust_email' => $c['email'], 'cust_phone' => $c['phone'], 'cust_address' => $c['address']];
        $f['kind'] = 'monthly';
        $f['client_id'] = $clientId;
        $f['notes'] = 'Tagihan langganan ADF System periode ' . Invoice::date($period . '-01', false) . '.';
        foreach (Invoice::monthlyLines($clientId, $period) as $l) {
            $items[] = ['description' => $l['desc'], 'note' => $l['note'], 'qty' => $l['qty'], 'unit_price' => $l['unit_price'], 'business_id' => $l['business_id']];
        }
        // Grup tanpa paket berbiaya → baris kosong; form tetap bisa diisi manual
    } elseif (($billId = (int)($_GET['bill'] ?? 0)) && ($bb = Biz::business($billId)) && Biz::subscription($billId)) {
        // Dari tab Langganan bisnis: tagihan manual yang ditagih lewat sistem bisnis itu, pelanggan = data grup
        $c = Biz::client((int)$bb['client_id']);
        $bsub = Biz::subscription($billId);
        // Kontak penagihan per bisnis (Pengaturan langganan bisnis itu), bukan kontak grup
        $f += ['cust_name' => $bb['name'], 'cust_company' => $c['name'] ?? '', 'cust_email' => (string)($bsub['notify_email'] ?? ''), 'cust_phone' => (string)($bsub['notify_phone'] ?? ''), 'cust_address' => $c['address'] ?? ''];
        $f['client_id'] = (int)$bb['client_id'];
        $f['bill_business_id'] = $billId;
        $items = [['description' => '', 'note' => $bb['name'], 'qty' => 1, 'unit_price' => '', 'business_id' => $billId]];
    }
}
$items = $items ?: [['description' => '', 'note' => '', 'qty' => 1, 'unit_price' => '', 'business_id' => '']];
$subBiz = $db->query('SELECT b.id, b.name FROM businesses b JOIN subscriptions s ON s.business_id = b.id JOIN clients c ON c.id = b.client_id WHERE c.is_demo = 0 ORDER BY b.name')->fetchAll();
// bisnis yang sedang ditagih selalu ada di pilihan (mis. bisnis di grup demo)
if (!empty($f['bill_business_id']) && !in_array((int)$f['bill_business_id'], array_map('intval', array_column($subBiz, 'id')), true) && ($bb = Biz::business((int)$f['bill_business_id']))) {
    $subBiz[] = ['id' => $bb['id'], 'name' => $bb['name']];
}
$money = static fn ($v) => $v === '' || $v === null ? '' : number_format((float)$v, 0, ',', '.');
$qtyFmt = static fn ($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');

View::start($inv ? 'Ubah invoice' : 'Invoice baru', 'invoices', $me);
?>
<style>
    /* Form invoice ringkas: muat satu layar */
    .inv { display: grid; gap: 6px 8px; }
    .inv label { font-size: 10.5px; margin: 0 0 2px; }
    .inv input, .inv select { padding: 4px 8px; font-size: 12px; }
    .inv-a { grid-template-columns: 1.4fr 1.2fr 1fr 1.2fr; }
    .inv-b { grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1.3fr; }
    #items td { padding: 2px 4px; border: 0; }
    #items th { padding: 0 4px 4px; }
    #items input { padding: 4px 7px; font-size: 12px; }
    .inv-foot { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; border-top: 1px solid var(--line); margin-top: 8px; padding-top: 10px; }
    .inv-foot input, .inv-foot select { padding: 4px 7px; font-size: 12px; }
    .inv-sum { font-size: 11px; color: var(--mu); text-align: right; line-height: 1.35; }
    @media (max-width: 860px) { .inv-a, .inv-b { grid-template-columns: 1fr 1fr; } }
</style>
<form method="post" autocomplete="off" novalidate id="invForm">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="kind" value="<?= e($f['kind'] ?? 'free') ?>"><input type="hidden" name="client_id" value="<?= (int)($f['client_id'] ?? 0) ?>">
    <div class="pagehead" style="margin-bottom:10px;align-items:center">
        <div><div class="crumb" style="margin:0"><a href="/invoices.php">Invoice</a> ›</div>
            <h1><?= $inv ? 'Ubah invoice ' . e($inv['number']) : ($f['kind'] === 'monthly' ? 'Invoice bulanan · ' . e(Invoice::date($period . '-01', false)) : 'Invoice baru') ?></h1></div>
        <div style="display:flex;gap:6px"><a class="btn ghost" href="<?= $inv ? '/invoice-view.php?id=' . $id : (!empty($f['bill_business_id']) ? '/business.php?id=' . (int)$f['bill_business_id'] . '&tab=langganan' : '/invoices.php') ?>">Batal</a><button class="btn ghost">Simpan</button><button class="btn" name="send" value="1" title="Simpan lalu kirim ke email pelanggan">Simpan &amp; kirim tagihan</button></div>
    </div>

    <div class="card" style="margin-bottom:10px">
        <div class="inv inv-a">
            <div><label>Pelanggan *</label><input name="cust_name" value="<?= e($f['cust_name'] ?? '') ?>"></div>
            <div><label>Perusahaan</label><input name="cust_company" value="<?= e($f['cust_company'] ?? '') ?>"></div>
            <div><label>WhatsApp</label><input name="cust_phone" value="<?= e($f['cust_phone'] ?? '') ?>"></div>
            <div><label>Email</label><input name="cust_email" type="email" value="<?= e($f['cust_email'] ?? '') ?>"></div>
        </div>
        <div class="inv inv-b" style="margin-top:6px">
            <div><label>Alamat</label><input name="cust_address" value="<?= e($f['cust_address'] ?? '') ?>"></div>
            <div><label>Nomor</label><input name="number" value="<?= e($f['number'] ?? '') ?>" placeholder="<?= e(Invoice::nextNumber()) ?>"></div>
            <div><label>Terbit</label><input name="issue_date" type="date" value="<?= e($f['issue_date'] ?? '') ?>"></div>
            <div><label>Jatuh tempo</label><input name="due_date" type="date" value="<?= e($f['due_date'] ?? '') ?>"></div>
            <div><label>Status</label><select name="status"><?php foreach (['unpaid' => 'Belum dibayar', 'draft' => 'Draft', 'paid' => 'Lunas'] as $k => $l): ?><option value="<?= $k ?>" <?= ($f['status'] ?? 'unpaid') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <?php if (($f['kind'] ?? 'free') !== 'monthly'): ?><div><label>Tagih lewat sistem</label><select name="bill_business_id"><option value="">— tidak (link invoice saja) —</option><?php foreach ($subBiz as $sb): ?><option value="<?= (int)$sb['id'] ?>" <?= (int)($f['bill_business_id'] ?? 0) === (int)$sb['id'] ? 'selected' : '' ?>><?= e($sb['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        </div>
    </div>

    <div class="card" style="margin:0">
        <div style="overflow-x:auto">
            <table id="items">
                <thead><tr><th>Deskripsi</th><th>Keterangan</th><th style="width:56px">Qty</th><th style="width:120px">Harga (Rp)</th><th style="width:110px;text-align:right">Jumlah</th><th style="width:30px"></th></tr></thead>
                <tbody>
                    <?php foreach ($items as $it): ?>
                        <tr>
                            <td><input name="item_desc[]" value="<?= e($it['description']) ?>" placeholder="mis. Langganan Narayana Hotel"><input type="hidden" name="item_biz[]" value="<?= e((string)($it['business_id'] ?? '')) ?>"></td>
                            <td><input name="item_note[]" value="<?= e((string)$it['note']) ?>" placeholder="opsional" style="color:var(--ink2)"></td>
                            <td><input name="item_qty[]" value="<?= e($qtyFmt($it['qty'])) ?>" inputmode="decimal"></td>
                            <td><input name="item_price[]" value="<?= e($money($it['unit_price'])) ?>" inputmode="numeric"></td>
                            <td class="amt" style="text-align:right;font-weight:600;white-space:nowrap">-</td>
                            <td><button type="button" class="btn ghost sm del" title="Hapus baris">×</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <button type="button" class="btn ghost sm" id="addRow" style="margin-top:6px">+ Baris</button>

        <div class="inv-foot">
            <input name="notes" value="<?= e(str_replace(["\r", "\n"], ' ', (string)($f['notes'] ?? ''))) ?>" placeholder="Catatan untuk pelanggan (opsional)" style="flex:1;min-width:220px">
            <span class="muted" style="font-size:11px">Diskon</span>
            <select name="discount_type" style="width:auto"><option value="rp" <?= ($f['discount_type'] ?? 'rp') === 'rp' ? 'selected' : '' ?>>Rp</option><option value="percent" <?= ($f['discount_type'] ?? '') === 'percent' ? 'selected' : '' ?>>%</option></select>
            <input name="discount_value" value="<?= e((string)($f['discount_value'] ?? 0)) ?>" inputmode="decimal" style="width:90px">
            <span class="muted" style="font-size:11px">PPN %</span>
            <input name="tax_percent" value="<?= e((string)($f['tax_percent'] ?? 0)) ?>" inputmode="decimal" style="width:56px">
            <div class="inv-sum">Subtotal <span id="tSub">-</span> · Diskon <span id="tDisc">-</span> · PPN <span id="tTax">-</span>
                <div style="font-size:16px;font-weight:700;color:var(--ink)">Total <span id="tTotal" style="color:var(--brand)">-</span></div></div>
        </div>
    </div>
</form>


<script>
(function () {
    var tbody = document.querySelector('#items tbody');
    var num = function (v) { return parseFloat(String(v).replace(/\./g, '').replace(',', '.')) || 0; };
    var rp = function (n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); };
    function recalc() {
        var sub = 0;
        tbody.querySelectorAll('tr').forEach(function (tr) {
            var q = parseFloat(String(tr.querySelector('[name="item_qty[]"]').value).replace(',', '.')) || 0;
            var a = q * num(tr.querySelector('[name="item_price[]"]').value);
            tr.querySelector('.amt').textContent = rp(a);
            sub += a;
        });
        var dv = parseFloat(String(document.querySelector('[name=discount_value]').value).replace(',', '.')) || 0;
        var disc = document.querySelector('[name=discount_type]').value === 'percent' ? sub * Math.min(dv, 100) / 100 : Math.min(dv, sub);
        var tax = (sub - disc) * (parseFloat(String(document.querySelector('[name=tax_percent]').value).replace(',', '.')) || 0) / 100;
        document.getElementById('tSub').textContent = rp(sub);
        document.getElementById('tDisc').textContent = disc ? '- ' + rp(disc) : 'Rp 0';
        document.getElementById('tTax').textContent = rp(tax);
        document.getElementById('tTotal').textContent = rp(sub - disc + tax);
    }
    document.getElementById('addRow').addEventListener('click', function () {
        var tr = tbody.querySelector('tr').cloneNode(true);
        tr.querySelectorAll('input').forEach(function (i) { i.value = i.name === 'item_qty[]' ? '1' : ''; });
        tbody.appendChild(tr);
        tr.querySelector('input').focus();
        recalc();
    });
    tbody.addEventListener('click', function (e) {
        if (!e.target.classList.contains('del')) return;
        if (tbody.querySelectorAll('tr').length > 1) e.target.closest('tr').remove();
        else e.target.closest('tr').querySelectorAll('input').forEach(function (i) { i.value = i.name === 'item_qty[]' ? '1' : ''; });
        recalc();
    });
    // Harga diketik → titik ribuan otomatis
    tbody.addEventListener('input', function (e) {
        if (e.target.name === 'item_price[]') {
            var d = e.target.value.replace(/\D/g, '');
            e.target.value = d ? Number(d).toLocaleString('id-ID') : '';
        }
        recalc();
    });
    document.getElementById('invForm').addEventListener('input', recalc);
    document.getElementById('invForm').addEventListener('change', recalc);
    recalc();
})();
</script>
<?php View::end();
