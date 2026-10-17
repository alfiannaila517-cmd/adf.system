<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/invoices-store.php';
require_once __DIR__ . '/../includes/subscription-clients-store.php';
require_once __DIR__ . '/../includes/orders-store.php';
adf_admin_require_role('admin');

$settings = adf_invoice_settings();
$id = trim((string) ($_GET['id'] ?? $_POST['id'] ?? ''));
$existing = $id !== '' ? adf_invoice_find($id) : null;
if ($id !== '' && !$existing) {
    header('Location: invoices.php');
    exit;
}
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } else {
        [$inv, $error] = adf_invoice_from_post($_POST, $existing ?? []);
        if ($inv) {
            $all = adf_invoices_load();
            foreach ($all as $other) {
                if (($other['id'] ?? '') !== ($inv['id'] ?? '') && ($other['number'] ?? '') === $inv['number']) {
                    $error = 'Nomor invoice ' . $inv['number'] . ' sudah dipakai invoice lain.';
                    break;
                }
            }
            if ($error === '') {
                $found = false;
                foreach ($all as $i => $other) {
                    if (($other['id'] ?? '') === $inv['id']) {
                        $all[$i] = $inv;
                        $found = true;
                    }
                }
                if (!$found) {
                    $all[] = $inv;
                }
                if (adf_invoices_save($all)) {
                    header('Location: invoice-view.php?id=' . urlencode($inv['id']) . '&saved=1');
                    exit;
                }
                $error = 'Gagal menyimpan invoice (periksa izin tulis folder data).';
            }
        }
        // tampilkan kembali isian pengguna saat gagal
        $existing = ($existing ?? []) + [];
        $formInv = [
            'number' => trim((string) ($_POST['number'] ?? '')),
            'customer' => ['name' => (string) ($_POST['cust_name'] ?? ''), 'company' => (string) ($_POST['cust_company'] ?? ''), 'email' => (string) ($_POST['cust_email'] ?? ''), 'phone' => (string) ($_POST['cust_phone'] ?? ''), 'address' => (string) ($_POST['cust_address'] ?? '')],
            'issue_date' => (string) ($_POST['issue_date'] ?? ''), 'due_date' => (string) ($_POST['due_date'] ?? ''),
            'discount_type' => (string) ($_POST['discount_type'] ?? 'rp'), 'discount_value' => (string) ($_POST['discount_value'] ?? ''), 'tax_percent' => (string) ($_POST['tax_percent'] ?? ''),
            'notes' => (string) ($_POST['notes'] ?? ''), 'status' => (string) ($_POST['status'] ?? 'unpaid'), 'items' => [],
        ];
        foreach ((array) ($_POST['item_desc'] ?? []) as $i => $d) {
            $formInv['items'][] = ['desc' => (string) $d, 'qty' => $_POST['item_qty'][$i] ?? 1, 'unit_price' => $_POST['item_price'][$i] ?? 0, 'note' => $_POST['item_note'][$i] ?? ''];
        }
    }
}

$isNew = !$existing;
if (!isset($formInv)) {
    $today = date('Y-m-d');
    $formInv = $existing ?: [
        'number' => adf_invoice_next_number($today),
        'customer' => ['name' => trim((string) ($_GET['name'] ?? '')), 'company' => '', 'email' => '', 'phone' => '', 'address' => ''],
        'issue_date' => $today, 'due_date' => date('Y-m-d', strtotime('+' . max(0, (int) $settings['default_due_days']) . ' days')),
        'discount_type' => 'rp', 'discount_value' => 0, 'tax_percent' => $settings['default_tax_percent'], 'notes' => '', 'status' => 'unpaid',
        'items' => [['desc' => '', 'qty' => 1, 'unit_price' => '', 'note' => '']],
    ];
}
if (empty($formInv['items'])) {
    $formInv['items'] = [['desc' => '', 'qty' => 1, 'unit_price' => '', 'note' => '']];
}

// Saran pelanggan: klien langganan + pelanggan dari pesanan (isi otomatis email/telepon saat dipilih)
$suggest = [];
foreach (adf_subscription_clients_load() as $c) {
    $n = trim((string) ($c['client_name'] ?? ''));
    if ($n !== '') {
        $suggest[$n] = ['email' => (string) ($c['notify_email'] ?? ''), 'phone' => ''];
    }
}
foreach (adf_orders_load() as $o) {
    $n = trim((string) ($o['name'] ?? ''));
    if ($n !== '' && !isset($suggest[$n])) {
        $suggest[$n] = ['email' => (string) ($o['email'] ?? ''), 'phone' => (string) ($o['whatsapp'] ?? '')];
    }
}

$csrf = adf_admin_csrf_token();
$adminNavForce = 'invoices.php';
$adminPageTitle = $isNew ? 'Buat Invoice' : 'Ubah Invoice';
require __DIR__ . '/../includes/admin-header.php';
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<div class="container admin-container admin-container-wide">
    <div class="inv-pagehead">
        <div>
            <h1><?php echo $isNew ? 'Buat Invoice' : 'Ubah Invoice'; ?></h1>
            <p class="admin-lead">Tagihan manual di luar langganan bulanan, mis. registrasi awal pelanggan baru, biaya setup, atau tambah modul.</p>
        </div>
        <a href="invoices.php" class="btn btn-outline">&larr; Daftar Invoice</a>
    </div>

    <?php if ($error): ?><div class="admin-alert admin-alert-error"><?php echo $e($error); ?></div><?php endif; ?>

    <form method="post" id="invForm" class="inv-form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?php echo $e($csrf); ?>">
        <input type="hidden" name="id" value="<?php echo $e($existing['id'] ?? ''); ?>">

        <div class="inv-grid">
            <div class="inv-main">
                <section class="inv-card">
                    <h2>Pelanggan</h2>
                    <div class="inv-fields two">
                        <label>Nama pelanggan *<input type="text" name="cust_name" id="custName" list="custList" required maxlength="150" value="<?php echo $e($formInv['customer']['name'] ?? ''); ?>" placeholder="Nama pemilik / penanggung jawab"></label>
                        <label>Perusahaan / bisnis<input type="text" name="cust_company" maxlength="150" value="<?php echo $e($formInv['customer']['company'] ?? ''); ?>" placeholder="Opsional"></label>
                        <label>Email<input type="email" name="cust_email" id="custEmail" maxlength="150" value="<?php echo $e($formInv['customer']['email'] ?? ''); ?>" placeholder="untuk mengirim invoice"></label>
                        <label>WhatsApp / telepon<input type="text" name="cust_phone" id="custPhone" maxlength="40" value="<?php echo $e($formInv['customer']['phone'] ?? ''); ?>"></label>
                        <label class="full">Alamat<textarea name="cust_address" rows="2" maxlength="400" placeholder="Opsional"><?php echo $e($formInv['customer']['address'] ?? ''); ?></textarea></label>
                    </div>
                    <datalist id="custList"><?php foreach (array_keys($suggest) as $n): ?><option value="<?php echo $e($n); ?>"><?php endforeach; ?></datalist>
                </section>

                <section class="inv-card">
                    <div class="inv-card-h">
                        <h2>Item tagihan</h2>
                        <div class="inv-chips" title="Klik untuk menambah baris dengan deskripsi siap pakai">
                            <?php foreach (['Registrasi awal pelanggan', 'Biaya setup & instalasi', 'Langganan bulanan', 'Tambah modul / fitur', 'Custom development', 'Domain & hosting', 'Pelatihan & pendampingan'] as $tpl): ?>
                                <button type="button" class="inv-chip" data-tpl="<?php echo $e($tpl); ?>">+ <?php echo $e($tpl); ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="inv-items" id="invItems">
                        <div class="inv-items-h"><span>Deskripsi</span><span>Qty</span><span>Harga satuan (Rp)</span><span>Jumlah</span><span></span></div>
                        <?php foreach ($formInv['items'] as $it): ?>
                            <div class="inv-item">
                                <div><input type="text" name="item_desc[]" class="i-desc" maxlength="240" value="<?php echo $e($it['desc'] ?? ''); ?>" placeholder="Deskripsi item"><input type="text" name="item_note[]" class="i-note" maxlength="240" value="<?php echo $e($it['note'] ?? ''); ?>" placeholder="Keterangan tambahan (opsional)"></div>
                                <div><input type="number" name="item_qty[]" class="i-qty" min="0.01" step="any" value="<?php echo $e($it['qty'] ?? 1); ?>"></div>
                                <div><input type="number" name="item_price[]" class="i-price" min="0" step="any" value="<?php echo $e($it['unit_price'] ?? ''); ?>" placeholder="0"></div>
                                <div class="i-sum">Rp 0</div>
                                <div><button type="button" class="inv-x" title="Hapus baris" aria-label="Hapus baris">&times;</button></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" id="addItem" style="margin-top:10px;">+ Tambah baris</button>
                </section>

                <section class="inv-card">
                    <h2>Catatan</h2>
                    <label class="inv-block">Catatan untuk pelanggan (tampil di invoice)
                        <textarea name="notes" rows="3" maxlength="800" placeholder="Mis. Biaya registrasi awal sistem Narayana Hotel, termasuk setup VPS."><?php echo $e($formInv['notes'] ?? ''); ?></textarea>
                    </label>
                </section>
            </div>

            <aside class="inv-side">
                <section class="inv-card">
                    <h2>Dokumen</h2>
                    <div class="inv-fields">
                        <label>Nomor invoice<input type="text" name="number" maxlength="40" value="<?php echo $e($formInv['number'] ?? ''); ?>"></label>
                        <label>Tanggal terbit *<input type="date" name="issue_date" required value="<?php echo $e($formInv['issue_date'] ?? ''); ?>"></label>
                        <label>Jatuh tempo *<input type="date" name="due_date" required value="<?php echo $e($formInv['due_date'] ?? ''); ?>"></label>
                        <label>Status
                            <select name="status">
                                <?php $st = (string) ($formInv['status'] ?? 'unpaid'); if ($st === 'cancelled') { $st = 'unpaid'; } ?>
                                <option value="draft" <?php echo $st === 'draft' ? 'selected' : ''; ?>>Draft (belum terbit)</option>
                                <option value="unpaid" <?php echo $st === 'unpaid' ? 'selected' : ''; ?>>Belum dibayar</option>
                                <option value="paid" <?php echo $st === 'paid' ? 'selected' : ''; ?>>Lunas</option>
                            </select>
                        </label>
                    </div>
                </section>

                <section class="inv-card">
                    <h2>Diskon &amp; pajak</h2>
                    <div class="inv-fields">
                        <label>Diskon
                            <span class="inv-inline">
                                <select name="discount_type" id="discType"><option value="rp" <?php echo ($formInv['discount_type'] ?? 'rp') === 'rp' ? 'selected' : ''; ?>>Rp</option><option value="percent" <?php echo ($formInv['discount_type'] ?? '') === 'percent' ? 'selected' : ''; ?>>%</option></select>
                                <input type="number" name="discount_value" id="discValue" min="0" step="any" value="<?php echo $e($formInv['discount_value'] ?? 0); ?>">
                            </span>
                        </label>
                        <label>PPN (%)<input type="number" name="tax_percent" id="taxPercent" min="0" max="100" step="any" value="<?php echo $e($formInv['tax_percent'] ?? 0); ?>"></label>
                    </div>
                </section>

                <section class="inv-card inv-total">
                    <div class="r"><span>Subtotal</span><b id="tSub">Rp 0</b></div>
                    <div class="r" id="rDisc" style="display:none;"><span>Diskon</span><b id="tDisc" class="neg">- Rp 0</b></div>
                    <div class="r" id="rTax" style="display:none;"><span>PPN</span><b id="tTax">Rp 0</b></div>
                    <div class="r grand"><span>Total</span><b id="tTotal">Rp 0</b></div>
                </section>

                <div class="inv-submit">
                    <button type="submit" class="btn btn-primary"><?php echo $isNew ? 'Simpan Invoice' : 'Simpan Perubahan'; ?></button>
                    <a href="invoices.php" class="btn btn-outline">Batal</a>
                </div>
            </aside>
        </div>
    </form>
</div>

<script>
    (function() {
        var SUG = <?php echo json_encode($suggest, JSON_UNESCAPED_UNICODE); ?>;
        var box = document.getElementById('invItems');
        var fmt = function(n) { return 'Rp ' + Math.round(n || 0).toLocaleString('id-ID'); };

        function rowHtml(desc) {
            var d = document.createElement('div');
            d.className = 'inv-item';
            d.innerHTML = '<div><input type="text" name="item_desc[]" class="i-desc" maxlength="240" placeholder="Deskripsi item"><input type="text" name="item_note[]" class="i-note" maxlength="240" placeholder="Keterangan tambahan (opsional)"></div>' +
                '<div><input type="number" name="item_qty[]" class="i-qty" min="0.01" step="any" value="1"></div>' +
                '<div><input type="number" name="item_price[]" class="i-price" min="0" step="any" placeholder="0"></div>' +
                '<div class="i-sum">Rp 0</div>' +
                '<div><button type="button" class="inv-x" title="Hapus baris" aria-label="Hapus baris">&times;</button></div>';
            if (desc) d.querySelector('.i-desc').value = desc;
            return d;
        }

        function recalc() {
            var sub = 0;
            box.querySelectorAll('.inv-item').forEach(function(r) {
                var q = parseFloat(r.querySelector('.i-qty').value) || 0, p = parseFloat(r.querySelector('.i-price').value) || 0;
                r.querySelector('.i-sum').textContent = fmt(q * p);
                sub += q * p;
            });
            var dv = parseFloat(document.getElementById('discValue').value) || 0;
            var disc = document.getElementById('discType').value === 'percent' ? sub * Math.min(100, dv) / 100 : Math.min(dv, sub);
            var base = Math.max(0, sub - disc), tp = parseFloat(document.getElementById('taxPercent').value) || 0, tax = base * tp / 100;
            document.getElementById('tSub').textContent = fmt(sub);
            document.getElementById('rDisc').style.display = disc > 0 ? '' : 'none';
            document.getElementById('tDisc').textContent = '- ' + fmt(disc);
            document.getElementById('rTax').style.display = tax > 0 ? '' : 'none';
            document.getElementById('tTax').textContent = fmt(tax);
            document.getElementById('tTotal').textContent = fmt(base + tax);
        }

        box.addEventListener('input', recalc);
        box.addEventListener('click', function(e) {
            var x = e.target.closest('.inv-x');
            if (!x) return;
            var rows = box.querySelectorAll('.inv-item');
            if (rows.length > 1) x.closest('.inv-item').remove();
            else { rows[0].querySelectorAll('input').forEach(function(i) { if (i.classList.contains('i-qty')) i.value = 1; else i.value = ''; }); }
            recalc();
        });
        document.getElementById('addItem').addEventListener('click', function() { var r = rowHtml(''); box.appendChild(r); r.querySelector('.i-desc').focus(); });
        document.querySelectorAll('.inv-chip').forEach(function(c) {
            c.addEventListener('click', function() {
                var rows = box.querySelectorAll('.inv-item'), last = rows[rows.length - 1];
                if (last && !last.querySelector('.i-desc').value.trim() && !last.querySelector('.i-price').value) { last.querySelector('.i-desc').value = c.dataset.tpl; last.querySelector('.i-price').focus(); }
                else { var r = rowHtml(c.dataset.tpl); box.appendChild(r); r.querySelector('.i-price').focus(); }
                recalc();
            });
        });
        ['discValue', 'discType', 'taxPercent'].forEach(function(id) { document.getElementById(id).addEventListener('input', recalc); document.getElementById(id).addEventListener('change', recalc); });

        // Pilih nama pelanggan yang sudah ada → email/telepon terisi otomatis (bila masih kosong)
        document.getElementById('custName').addEventListener('change', function() {
            var s = SUG[this.value.trim()];
            if (!s) return;
            var em = document.getElementById('custEmail'), ph = document.getElementById('custPhone');
            if (!em.value && s.email) em.value = s.email;
            if (!ph.value && s.phone) ph.value = s.phone;
        });
        recalc();
    })();
</script>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
