<?php
/**
 * Sunsea - Kalkulator Harga Trip
 * Tool untuk menghitung & menyusun estimasi harga paket wisata
 * Bisa langsung dijadikan penawaran
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();

$pdo = getSunseaConnection();

// Packages for quick-load
$packages = $pdo->query("SELECT id, name, base_price, duration_days, duration_nights, min_pax, max_pax, includes, excludes, itinerary FROM trip_packages WHERE is_active=1 ORDER BY name")->fetchAll();
$customers = $pdo->query("SELECT id, name FROM customers WHERE is_active=1 ORDER BY name")->fetchAll();

$pageTitle  = 'Kalkulator Harga Trip';
$activePage = 'calculator';

include 'layout-header.php';
?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;">

    <!-- LEFT: Item builder -->
    <div>
        <div class="ss-card" style="margin-bottom:16px;">
            <div class="ss-card-header">
                <div>
                    <div class="ss-card-title">Kalkulasi Harga Paket</div>
                    <div class="ss-card-sub">Susun komponen biaya, langsung hitung total</div>
                </div>
                <div style="display:flex;gap:8px;">
                    <button onclick="clearAll()" class="ss-btn ss-btn-outline ss-btn-sm">
                        <i data-feather="refresh-cw"></i> Bersihkan
                    </button>
                </div>
            </div>

            <!-- Quick load from package -->
            <div class="ss-form-group">
                <label class="ss-label">Load dari Paket Wisata (opsional)</label>
                <select id="quickPkg" class="ss-select" onchange="loadPackage(this.value)">
                    <option value="">-- Pilih paket untuk dimuat --</option>
                    <?php foreach ($packages as $p): ?>
                    <option value="<?php echo $p['id']; ?>"
                            data-price="<?php echo $p['base_price']; ?>"
                            data-days="<?php echo $p['duration_days']; ?>"
                            data-nights="<?php echo $p['duration_nights']; ?>"
                            data-name="<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>"
                            data-includes="<?php echo htmlspecialchars($p['includes'] ?? '', ENT_QUOTES); ?>">
                        <?php echo htmlspecialchars($p['name']); ?>
                        — <?php echo 'Rp ' . number_format((float)$p['base_price'], 0, ',', '.'); ?>/org
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Trip info -->
            <div class="ss-form-grid cols-3" style="margin-bottom:16px;">
                <div class="ss-form-group">
                    <label class="ss-label">Nama Trip / Paket</label>
                    <input type="text" id="tripName" class="ss-input" placeholder="Karimunjawa 3D2N">
                </div>
                <div class="ss-form-group">
                    <label class="ss-label">Jumlah Peserta</label>
                    <input type="number" id="paxCount" class="ss-input" value="1" min="1" oninput="recalc()">
                </div>
                <div class="ss-form-group">
                    <label class="ss-label">Margin Keuntungan (%)</label>
                    <input type="number" id="marginPct" class="ss-input" value="15" min="0" max="200" step="0.5" oninput="recalc()">
                </div>
            </div>

            <!-- Items -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <div style="font-size:13px;font-weight:700;">Komponen Biaya</div>
                <button onclick="addCalcItem()" class="ss-btn ss-btn-outline ss-btn-sm">
                    <i data-feather="plus"></i> Tambah Baris
                </button>
            </div>

            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;" id="calcTable">
                    <thead>
                        <tr style="background:var(--ss-gray-1);">
                            <th style="padding:8px 10px;text-align:left;font-size:11px;color:var(--ss-muted);width:120px;">Kategori</th>
                            <th style="padding:8px 10px;text-align:left;font-size:11px;color:var(--ss-muted);">Keterangan</th>
                            <th style="padding:8px 10px;text-align:left;font-size:11px;color:var(--ss-muted);width:55px;">Qty</th>
                            <th style="padding:8px 10px;text-align:left;font-size:11px;color:var(--ss-muted);width:55px;">Sat.</th>
                            <th style="padding:8px 10px;text-align:left;font-size:11px;color:var(--ss-muted);width:115px;">Harga Sat.</th>
                            <th style="padding:8px 10px;text-align:left;font-size:11px;color:var(--ss-muted);width:40px;">/ Pax</th>
                            <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--ss-muted);width:115px;">Subtotal</th>
                            <th style="width:36px;"></th>
                        </tr>
                    </thead>
                    <tbody id="calcBody">
                        <!-- Rows injected by JS -->
                    </tbody>
                </table>
            </div>

            <div style="margin-top:12px;display:flex;gap:8px;">
                <button onclick="addCalcItem('accommodation')" class="ss-btn ss-btn-outline ss-btn-sm">+ Penginapan</button>
                <button onclick="addCalcItem('transport')" class="ss-btn ss-btn-outline ss-btn-sm">+ Transport</button>
                <button onclick="addCalcItem('meal')" class="ss-btn ss-btn-outline ss-btn-sm">+ Makan</button>
                <button onclick="addCalcItem('activity')" class="ss-btn ss-btn-outline ss-btn-sm">+ Aktivitas</button>
                <button onclick="addCalcItem('guide')" class="ss-btn ss-btn-outline ss-btn-sm">+ Guide</button>
            </div>
        </div>

        <!-- Package info card (loaded from package) -->
        <div id="pkgInfoCard" style="display:none;" class="ss-card" style="border:1.5px solid var(--ss-gray-2);">
            <div class="ss-card-title" style="margin-bottom:10px;">Info Paket</div>
            <div id="pkgInfoContent" style="font-size:13px;color:var(--ss-muted);white-space:pre-wrap;"></div>
        </div>
    </div>

    <!-- RIGHT: Summary & Actions -->
    <div>
        <div class="ss-card" style="margin-bottom:16px;position:sticky;top:72px;">
            <div class="ss-card-title" style="margin-bottom:16px;">Ringkasan Kalkulasi</div>

            <div style="background:var(--ss-sky);border-radius:8px;padding:14px;margin-bottom:14px;">
                <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--ss-muted);margin-bottom:6px;">
                    <span>Total Biaya</span><span id="sumCost" style="font-weight:600;color:var(--ss-text);">Rp 0</span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--ss-muted);margin-bottom:6px;">
                    <span>Biaya / Pax</span><span id="sumCostPerPax" style="font-weight:600;color:var(--ss-text);">Rp 0</span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--ss-muted);margin-bottom:6px;">
                    <span>Margin <span id="marginLabel">15</span>%</span><span id="sumMargin" style="font-weight:600;color:var(--ss-warning);">Rp 0</span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--ss-muted);margin-bottom:6px;">
                    <span>Harga Jual / Pax</span><span id="sumSellPerPax" style="font-weight:700;font-size:14px;color:var(--ss-ocean);">Rp 0</span>
                </div>
            </div>

            <div style="margin-bottom:14px;">
                <label class="ss-label">PPN (%)</label>
                <input type="number" id="taxCalc" class="ss-input" value="11" step="0.1" min="0" oninput="recalc()">
            </div>

            <div style="border-top:2px solid var(--ss-ocean);padding-top:14px;margin-bottom:14px;">
                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">
                    <span style="color:var(--ss-muted);">Subtotal</span><span id="sumSubtotal" style="font-weight:600;">Rp 0</span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">
                    <span style="color:var(--ss-muted);">PPN</span><span id="sumTax" style="font-weight:600;">Rp 0</span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:18px;font-weight:800;color:var(--ss-ocean);">
                    <span>TOTAL</span><span id="sumTotal">Rp 0</span>
                </div>
            </div>

            <!-- Send to quotation form -->
            <div class="ss-form-group">
                <label class="ss-label">Customer (untuk buat penawaran)</label>
                <select id="toCustomer" class="ss-select">
                    <option value="">-- Pilih Customer --</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button onclick="sendToQuotation()" class="ss-btn ss-btn-primary" style="width:100%;">
                <i data-feather="file-plus"></i> Buat Penawaran dari Kalkulator
            </button>
            <div style="margin-top:8px;text-align:center;">
                <button onclick="window.print()" class="ss-btn ss-btn-outline" style="width:100%;">
                    <i data-feather="printer"></i> Cetak Kalkulasi
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden form to POST to quotations -->
<form id="toQuotationForm" method="GET" action="quotations.php" style="display:none;">
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="customer_id" id="fCustomerId">
    <input type="hidden" name="from_calc" value="1">
    <!-- Items will be in sessionStorage, picked up by quotations.php JS -->
</form>

<script>
var calcItems = [];
var categoryLabels = {
    accommodation: 'Penginapan', transport: 'Transport', meal: 'Makan',
    activity: 'Aktivitas', guide: 'Guide', equipment: 'Perlengkapan', other: 'Lainnya'
};
var categoryOptions = Object.keys(categoryLabels).map(function(k){
    return '<option value="'+k+'">'+categoryLabels[k]+'</option>';
}).join('');

function addCalcItem(type, desc, qty, unit, price, perPax) {
    type    = type    || 'other';
    desc    = desc    || '';
    qty     = qty     || 1;
    unit    = unit    || 'unit';
    price   = price   || 0;
    perPax  = perPax  !== undefined ? perPax : false;

    var id = Date.now() + Math.random();
    var tr = document.createElement('tr');
    tr.dataset.id = id;
    tr.style.borderBottom = '1px solid var(--ss-gray-2)';
    tr.innerHTML =
        '<td style="padding:6px 8px;"><select class="ss-select ci-type" style="font-size:12px;padding:5px 7px;" onchange="recalc()">'+
            categoryOptions.replace('value="'+type+'"', 'value="'+type+'" selected') +
        '</select></td>' +
        '<td style="padding:6px 8px;"><input type="text" class="ss-input ci-desc" style="font-size:12px;padding:5px 8px;" value="'+esc(desc)+'" placeholder="Keterangan..." onchange="recalc()"></td>' +
        '<td style="padding:6px 8px;"><input type="number" class="ss-input ci-qty" style="font-size:12px;padding:5px 6px;" value="'+qty+'" min="0" step="0.5" oninput="recalc()"></td>' +
        '<td style="padding:6px 8px;"><input type="text" class="ss-input ci-unit" style="font-size:12px;padding:5px 6px;" value="'+unit+'"></td>' +
        '<td style="padding:6px 8px;"><input type="text" class="ss-input ci-price" style="font-size:12px;padding:5px 8px;" value="'+fmtNum(price)+'" placeholder="0" oninput="recalc()"></td>' +
        '<td style="padding:6px 8px;text-align:center;"><input type="checkbox" class="ci-perpax" '+( perPax ? 'checked' : '' )+' onchange="recalc()" title="Biaya ini per pax?"></td>' +
        '<td style="padding:6px 8px;text-align:right;font-weight:600;" class="ci-sub">Rp 0</td>' +
        '<td style="padding:6px 8px;"><button type="button" onclick="removeCalcItem(this)" style="background:none;border:none;cursor:pointer;color:var(--ss-danger);"><i data-feather="x" style="width:14px;height:14px;"></i></button></td>';
    document.getElementById('calcBody').appendChild(tr);
    feather.replace();
    recalc();
}

function removeCalcItem(btn) { btn.closest('tr').remove(); recalc(); }

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }
function fmtNum(n){ return n ? Math.round(n).toLocaleString('id-ID') : ''; }
function unFmt(s){ return parseFloat(String(s).replace(/\./g,'').replace(',','.')) || 0; }
function fmt(n){ return 'Rp '+Math.round(n).toLocaleString('id-ID'); }

function recalc() {
    var pax     = parseInt(document.getElementById('paxCount').value) || 1;
    var margin  = parseFloat(document.getElementById('marginPct').value) || 0;
    var taxPct  = parseFloat(document.getElementById('taxCalc').value) || 0;
    var totalCost = 0;

    document.getElementById('marginLabel').textContent = margin;

    document.querySelectorAll('#calcBody tr').forEach(function(row){
        var qty      = parseFloat(row.querySelector('.ci-qty')?.value) || 0;
        var price    = unFmt(row.querySelector('.ci-price')?.value || '0');
        var perPax   = row.querySelector('.ci-perpax')?.checked;
        var sub      = qty * price * (perPax ? pax : 1);
        var subCell  = row.querySelector('.ci-sub');
        if (subCell) subCell.textContent = fmt(sub);
        totalCost += sub;
    });

    var costPerPax   = pax > 0 ? totalCost / pax : 0;
    var marginAmt    = costPerPax * margin / 100;
    var sellPerPax   = costPerPax + marginAmt;
    var subtotal     = sellPerPax * pax;
    var tax          = subtotal * taxPct / 100;
    var total        = subtotal + tax;

    document.getElementById('sumCost').textContent        = fmt(totalCost);
    document.getElementById('sumCostPerPax').textContent  = fmt(costPerPax);
    document.getElementById('sumMargin').textContent      = fmt(marginAmt * pax);
    document.getElementById('sumSellPerPax').textContent  = fmt(sellPerPax);
    document.getElementById('sumSubtotal').textContent    = fmt(subtotal);
    document.getElementById('sumTax').textContent         = fmt(tax);
    document.getElementById('sumTotal').textContent       = fmt(total);
}

function clearAll(){
    if (!confirm('Bersihkan semua item?')) return;
    document.getElementById('calcBody').innerHTML = '';
    document.getElementById('quickPkg').value = '';
    document.getElementById('tripName').value = '';
    document.getElementById('paxCount').value = 1;
    document.getElementById('pkgInfoCard').style.display = 'none';
    recalc();
}

function loadPackage(pkgId){
    if (!pkgId) return;
    var opt = document.querySelector('#quickPkg option[value="'+pkgId+'"]');
    if (!opt) return;

    var basePrice = parseFloat(opt.dataset.price) || 0;
    var name      = opt.dataset.name || '';
    var includes  = opt.dataset.includes || '';

    document.getElementById('tripName').value = name;
    document.getElementById('calcBody').innerHTML = '';

    // Parse includes text into rows
    if (includes) {
        var lines = includes.split('\n').filter(function(l){ return l.trim(); });
        lines.forEach(function(line){
            line = line.replace(/^[-*•]\s*/,'').trim();
            if (line) addCalcItem('other', line, 1, 'unit', 0, false);
        });
    }

    // Add base price row
    if (basePrice > 0) {
        addCalcItem('other', 'Harga Dasar Paket', 1, 'paket', basePrice, false);
    }

    // Show info card
    var infoCard = document.getElementById('pkgInfoCard');
    var infoCont = document.getElementById('pkgInfoContent');
    infoCont.textContent = (opt.dataset.includes ? 'Include:\n' + opt.dataset.includes : '') + '\n\n' + (opt.dataset.itinerary || '');
    infoCard.style.display = infoCont.textContent.trim() ? '' : 'none';

    recalc();
}

function sendToQuotation(){
    var custId = document.getElementById('toCustomer').value;
    if (!custId) { alert('Pilih customer terlebih dahulu.'); return; }

    // Save calc items to sessionStorage so quotations.php can pre-fill
    var items = [];
    document.querySelectorAll('#calcBody tr').forEach(function(row){
        items.push({
            type  : row.querySelector('.ci-type')?.value || 'other',
            desc  : row.querySelector('.ci-desc')?.value || '',
            qty   : parseFloat(row.querySelector('.ci-qty')?.value) || 1,
            unit  : row.querySelector('.ci-unit')?.value || 'unit',
            price : unFmt(row.querySelector('.ci-price')?.value || '0'),
            perPax: row.querySelector('.ci-perpax')?.checked ? 1 : 0,
        });
    });

    var pax    = parseInt(document.getElementById('paxCount').value) || 1;
    var margin = parseFloat(document.getElementById('marginPct').value) || 0;
    var name   = document.getElementById('tripName').value;

    sessionStorage.setItem('sunsea_calc', JSON.stringify({ items, pax, margin, tripName: name }));

    document.getElementById('fCustomerId').value = custId;
    document.getElementById('toQuotationForm').submit();
}

// Initialize with 3 empty rows
addCalcItem('accommodation');
addCalcItem('transport');
addCalcItem('meal');
recalc();
</script>

<?php include 'layout-footer.php'; ?>
