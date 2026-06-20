<?php
/**
 * Sunsea - Kalkulator Harga Trip (Enhanced)
 * - Pilihan paket (3H2M, 4H3M, custom, dll)
 * - Input manual harga fleksibel
 * - Load komponen dari database (tiket, hotel, catering, guide, fasilitas)
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

// Load semua data master dari database
$dbPackages = $pdo->query("SELECT id, name, base_price, duration_days, duration_nights, min_pax, max_pax, includes FROM trip_packages WHERE is_active=1 ORDER BY name")->fetchAll();
$dbCustomers = $pdo->query("SELECT id, name FROM customers WHERE is_active=1 ORDER BY name")->fetchAll();

// Database items untuk picker
$dbTickets = [];
try {
    $dbTickets = $pdo->query("SELECT id, ticket_name, ticket_type, price_cost, price_sell, unit FROM tickets WHERE is_active=1 ORDER BY ticket_type, ticket_name")->fetchAll();
} catch(Exception $e) {}

$dbRooms = [];
try {
    $dbRooms = $pdo->query("SELECT r.id, r.room_type, r.price_cost, r.price_sell, p.name as partner_name
        FROM accommodation_rooms r JOIN accommodation_partners p ON p.id=r.partner_id
        WHERE r.is_active=1 AND p.is_active=1 ORDER BY p.name, r.room_type")->fetchAll();
} catch(Exception $e) {}

$dbCaterings = [];
try {
    $dbCaterings = $pdo->query("SELECT id, vendor_name, menu_name, portion_unit, price_cost, price_sell FROM caterings WHERE is_active=1 ORDER BY vendor_name, menu_name")->fetchAll();
} catch(Exception $e) {}

$dbGuides = [];
try {
    $dbGuides = $pdo->query("SELECT id, name, guide_type, daily_rate_cost, daily_rate_sell FROM guides WHERE is_active=1 ORDER BY guide_type, name")->fetchAll();
} catch(Exception $e) {}

$dbFacilities = [];
try {
    $dbFacilities = $pdo->query("SELECT id, name, unit, price_cost, price_sell, category FROM facilities WHERE is_active=1 ORDER BY category, name")->fetchAll();
} catch(Exception $e) {}

// Encode semua data ke JSON untuk JavaScript
$jsTickets    = json_encode($dbTickets);
$jsRooms      = json_encode($dbRooms);
$jsCaterings  = json_encode($dbCaterings);
$jsGuides     = json_encode($dbGuides);
$jsFacilities = json_encode($dbFacilities);

$pageTitle  = 'Kalkulator Harga Trip';
$activePage = 'calculator';
include 'layout-header.php';
?>

<style>
.calc-section { background:#fff; border:1px solid #dde5ef; border-radius:8px; padding:20px; margin-bottom:16px; }
.calc-section-title { font-size:14px; font-weight:700; color:#0c4a6e; margin-bottom:14px; display:flex; align-items:center; gap:8px; }
.pkg-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:10px; margin-bottom:12px; }
.pkg-card { border:2px solid #dde5ef; border-radius:8px; padding:12px; text-align:center; cursor:pointer; transition:all .15s; }
.pkg-card:hover,.pkg-card.active { border-color:#0EA5E9; background:#f0f9ff; }
.pkg-card .pkg-duration { font-size:20px; font-weight:800; color:#0c4a6e; }
.pkg-card .pkg-label { font-size:11px; color:#666; margin-top:2px; }
.calc-table { width:100%; border-collapse:collapse; font-size:13px; }
.calc-table th { padding:8px 10px; text-align:left; font-size:11px; color:#666; background:#f8fbff; font-weight:700; }
.calc-table td { padding:5px 7px; border-bottom:1px solid #f0f4f8; vertical-align:middle; }
.calc-input { width:100%; padding:6px 8px; border:1px solid #dde5ef; border-radius:4px; font-size:12px; font-family:inherit; box-sizing:border-box; }
.calc-input:focus { outline:none; border-color:#0EA5E9; }
.calc-select { width:100%; padding:6px 8px; border:1px solid #dde5ef; border-radius:4px; font-size:12px; font-family:inherit; background:#fff; box-sizing:border-box; }
.calc-btn-sm { padding:5px 10px; border:1px solid #dde5ef; border-radius:4px; font-size:12px; cursor:pointer; background:#fff; font-family:inherit; }
.summary-row { display:flex; justify-content:space-between; padding:6px 0; font-size:13px; }
.summary-total { display:flex; justify-content:space-between; font-size:18px; font-weight:800; color:#0EA5E9; border-top:2px solid #0EA5E9; padding-top:10px; margin-top:6px; }
.add-db-btn { display:inline-flex; align-items:center; gap:5px; padding:6px 12px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:5px; font-size:12px; font-weight:600; color:#0369a1; cursor:pointer; }
.add-db-btn:hover { background:#e0f2fe; }
</style>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;">

    <!-- LEFT: Builder -->
    <div>

        <!-- 1. PILIH PAKET -->
        <div class="calc-section">
            <div class="calc-section-title">📦 Pilihan Paket</div>
            <div class="pkg-grid" id="pkgGrid">
                <div class="pkg-card" onclick="selectPkg(this,'2D1N','2 Hari 1 Malam',2,1)">
                    <div class="pkg-duration">2D1N</div>
                    <div class="pkg-label">2 Hari 1 Malam</div>
                </div>
                <div class="pkg-card" onclick="selectPkg(this,'3D2N','3 Hari 2 Malam',3,2)">
                    <div class="pkg-duration">3D2N</div>
                    <div class="pkg-label">3 Hari 2 Malam</div>
                </div>
                <div class="pkg-card" onclick="selectPkg(this,'4D3N','4 Hari 3 Malam',4,3)">
                    <div class="pkg-duration">4D3N</div>
                    <div class="pkg-label">4 Hari 3 Malam</div>
                </div>
                <div class="pkg-card" onclick="selectPkg(this,'5D4N','5 Hari 4 Malam',5,4)">
                    <div class="pkg-duration">5D4N</div>
                    <div class="pkg-label">5 Hari 4 Malam</div>
                </div>
                <div class="pkg-card" onclick="selectPkgCustom(this)">
                    <div class="pkg-duration" style="font-size:16px;">✏️</div>
                    <div class="pkg-label">Custom</div>
                </div>
            </div>

            <div id="customDurWrap" style="display:none;margin-bottom:10px;">
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
                    <div>
                        <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:4px;">Nama Paket</label>
                        <input type="text" id="customPkgName" class="calc-input" placeholder="Custom Trip">
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:4px;">Hari</label>
                        <input type="number" id="customDays" class="calc-input" value="3" min="1">
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:4px;">Malam</label>
                        <input type="number" id="customNights" class="calc-input" value="2" min="0">
                    </div>
                </div>
                <button onclick="applyCustomPkg()" style="margin-top:8px;padding:6px 14px;background:#0EA5E9;color:#fff;border:none;border-radius:4px;font-weight:600;cursor:pointer;font-size:12px;">Terapkan</button>
            </div>

            <div style="border-top:1px solid #eef2f7;padding-top:12px;margin-top:4px;">
                <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:6px;">Atau Load dari Paket Wisata Tersimpan:</label>
                <select id="quickPkg" class="calc-select" style="max-width:360px;" onchange="loadFromSavedPackage(this.value)">
                    <option value="">-- Pilih paket tersimpan --</option>
                    <?php foreach ($dbPackages as $p): ?>
                    <option value="<?php echo $p['id']; ?>"
                        data-name="<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>"
                        data-price="<?php echo $p['base_price']; ?>"
                        data-days="<?php echo $p['duration_days']; ?>"
                        data-nights="<?php echo $p['duration_nights']; ?>"
                        data-includes="<?php echo htmlspecialchars($p['includes'] ?? '', ENT_QUOTES); ?>">
                        <?php echo htmlspecialchars($p['name']); ?> — Rp <?php echo number_format((float)$p['base_price'], 0, ',', '.'); ?>/org
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- 2. INFO TRIP -->
        <div class="calc-section">
            <div class="calc-section-title">ℹ️ Info Trip</div>
            <div style="display:grid;grid-template-columns:1fr 140px 140px 140px;gap:10px;">
                <div>
                    <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:4px;">Nama Trip / Paket</label>
                    <input type="text" id="tripName" class="calc-input" placeholder="Karimunjawa 3D2N">
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:4px;">Durasi</label>
                    <input type="text" id="tripDuration" class="calc-input" placeholder="3H 2M" readonly style="background:#f8fbff;">
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:4px;">Jumlah Pax</label>
                    <input type="number" id="paxCount" class="calc-input" value="1" min="1" oninput="recalc()">
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:4px;">Margin (%)</label>
                    <input type="number" id="marginPct" class="calc-input" value="15" min="0" max="300" step="0.5" oninput="recalc()">
                </div>
            </div>
        </div>

        <!-- 3. KOMPONEN BIAYA -->
        <div class="calc-section">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <div class="calc-section-title" style="margin-bottom:0;">🧾 Komponen Biaya</div>
                <button onclick="addManualRow()" style="padding:7px 12px;background:#0EA5E9;color:#fff;border:none;border-radius:5px;font-weight:600;font-size:12px;cursor:pointer;">+ Baris Manual</button>
            </div>

            <!-- Quick add dari database -->
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;padding:10px;background:#f8fbff;border:1px solid #dde5ef;border-radius:6px;">
                <span style="font-size:11px;font-weight:700;color:#0c4a6e;align-self:center;">Tambah dari Database:</span>
                <button class="add-db-btn" onclick="openDbPicker('ticket')">🎫 Tiket</button>
                <button class="add-db-btn" onclick="openDbPicker('hotel')">🏨 Hotel/Penginapan</button>
                <button class="add-db-btn" onclick="openDbPicker('catering')">🍽️ Catering</button>
                <button class="add-db-btn" onclick="openDbPicker('guide')">👤 Guide</button>
                <button class="add-db-btn" onclick="openDbPicker('facility')">⚙️ Fasilitas</button>
            </div>

            <!-- DB Picker Panel -->
            <div id="dbPickerPanel" style="display:none;padding:12px;background:#fff8e1;border:1px solid #fde68a;border-radius:6px;margin-bottom:12px;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <strong id="dbPickerTitle" style="font-size:13px;color:#92400e;min-width:80px;">Pilih Tiket:</strong>
                    <select id="dbPickerSelect" class="calc-select" style="flex:1;min-width:200px;max-width:360px;">
                        <option value="">-- Pilih item --</option>
                    </select>
                    <div style="display:flex;gap:4px;">
                        <input type="number" id="dbPickerQty" class="calc-input" value="1" min="0" step="0.5" style="width:70px;" placeholder="Qty">
                        <input type="text" id="dbPickerUnit" class="calc-input" value="unit" style="width:70px;" placeholder="Sat.">
                    </div>
                    <button onclick="addFromDb()" style="padding:7px 14px;background:#f59e0b;color:#fff;border:none;border-radius:4px;font-weight:700;font-size:12px;cursor:pointer;">+ Tambah</button>
                    <button onclick="closeDbPicker()" style="padding:7px 10px;background:#fff;border:1px solid #ddd;border-radius:4px;font-size:12px;cursor:pointer;">✕</button>
                </div>
                <div id="dbPickerPreview" style="margin-top:8px;font-size:12px;color:#666;"></div>
            </div>

            <!-- Tabel komponen -->
            <div style="overflow-x:auto;">
                <table class="calc-table" id="calcTable">
                    <thead>
                        <tr>
                            <th style="width:120px;">Kategori</th>
                            <th>Keterangan</th>
                            <th style="width:65px;">Qty</th>
                            <th style="width:65px;">Sat.</th>
                            <th style="width:120px;">Harga/Sat.</th>
                            <th style="width:40px;text-align:center;">/Pax</th>
                            <th style="width:110px;text-align:right;">Subtotal</th>
                            <th style="width:32px;"></th>
                        </tr>
                    </thead>
                    <tbody id="calcBody"></tbody>
                </table>
            </div>

            <div style="margin-top:12px;display:flex;gap:6px;flex-wrap:wrap;">
                <button onclick="addManualRow('accommodation')" class="calc-btn-sm">+ Penginapan</button>
                <button onclick="addManualRow('transport')" class="calc-btn-sm">+ Transport</button>
                <button onclick="addManualRow('meal')" class="calc-btn-sm">+ Makan</button>
                <button onclick="addManualRow('activity')" class="calc-btn-sm">+ Aktivitas</button>
                <button onclick="addManualRow('guide')" class="calc-btn-sm">+ Guide</button>
                <button onclick="addManualRow('equipment')" class="calc-btn-sm">+ Perlengkapan</button>
                <button onclick="addManualRow('other')" class="calc-btn-sm">+ Lainnya</button>
            </div>
        </div>
    </div>

    <!-- RIGHT: Ringkasan -->
    <div>
        <div class="calc-section" style="position:sticky;top:72px;">
            <div class="calc-section-title" style="margin-bottom:14px;">📊 Ringkasan</div>

            <div style="background:#f0f9ff;border-radius:8px;padding:14px;margin-bottom:14px;">
                <div class="summary-row"><span style="color:#666;">Total Biaya</span><strong id="sumCost">Rp 0</strong></div>
                <div class="summary-row"><span style="color:#666;">Biaya / Pax</span><strong id="sumCostPerPax">Rp 0</strong></div>
                <div class="summary-row"><span style="color:#666;">Margin (<span id="marginLabel">15</span>%)</span><strong style="color:#f59e0b;" id="sumMargin">Rp 0</strong></div>
                <div class="summary-row"><span style="color:#666;">Harga Jual / Pax</span><strong style="font-size:15px;color:#0EA5E9;" id="sumSellPerPax">Rp 0</strong></div>
            </div>

            <div style="margin-bottom:12px;">
                <label style="font-size:12px;font-weight:600;color:#666;display:block;margin-bottom:5px;">PPN (%)</label>
                <input type="number" id="taxCalc" class="calc-input" value="11" step="0.1" min="0" oninput="recalc()">
            </div>

            <div style="border-top:2px solid #0EA5E9;padding-top:12px;margin-bottom:14px;">
                <div class="summary-row"><span style="color:#666;">Subtotal</span><span id="sumSubtotal">Rp 0</span></div>
                <div class="summary-row"><span style="color:#666;">PPN</span><span id="sumTax">Rp 0</span></div>
                <div class="summary-total"><span>TOTAL</span><span id="sumTotal">Rp 0</span></div>
            </div>

            <div style="margin-bottom:12px;">
                <label style="font-size:12px;font-weight:600;color:#666;display:block;margin-bottom:5px;">Customer (untuk penawaran)</label>
                <select id="toCustomer" class="calc-select">
                    <option value="">-- Pilih Customer --</option>
                    <?php foreach ($dbCustomers as $c): ?>
                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button onclick="sendToQuotation()" style="width:100%;padding:11px;background:#0EA5E9;color:#fff;border:none;border-radius:6px;font-weight:700;font-size:14px;cursor:pointer;margin-bottom:8px;">
                📄 Buat Penawaran dari Kalkulator
            </button>
            <button onclick="window.print()" style="width:100%;padding:9px;background:#fff;color:#0EA5E9;border:1px solid #0EA5E9;border-radius:6px;font-weight:600;font-size:13px;cursor:pointer;margin-bottom:8px;">
                🖨️ Cetak Kalkulasi
            </button>
            <button onclick="clearAll()" style="width:100%;padding:8px;background:#fff;color:#ef4444;border:1px solid #fca5a5;border-radius:6px;font-weight:600;font-size:12px;cursor:pointer;">
                🗑️ Bersihkan Semua
            </button>
        </div>
    </div>
</div>

<form id="toQuotationForm" method="GET" action="quotations.php" style="display:none;">
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="customer_id" id="fCustomerId">
    <input type="hidden" name="from_calc" value="1">
</form>

<script>
var DB = {
    ticket:   <?php echo $jsTickets; ?>,
    hotel:    <?php echo $jsRooms; ?>,
    catering: <?php echo $jsCaterings; ?>,
    guide:    <?php echo $jsGuides; ?>,
    facility: <?php echo $jsFacilities; ?>
};
var activePkgDays = 0, activePkgNights = 0, currentDbType = '';

function selectPkg(el, code, label, days, nights) {
    document.querySelectorAll('.pkg-card').forEach(function(c) { c.classList.remove('active'); });
    el.classList.add('active');
    activePkgDays = days; activePkgNights = nights;
    document.getElementById('tripName').value = 'Trip Karimunjawa ' + code;
    document.getElementById('tripDuration').value = days + ' Hari ' + nights + ' Malam';
    document.getElementById('customDurWrap').style.display = 'none';
}
function selectPkgCustom(el) {
    document.querySelectorAll('.pkg-card').forEach(function(c) { c.classList.remove('active'); });
    el.classList.add('active');
    document.getElementById('customDurWrap').style.display = '';
}
function applyCustomPkg() {
    var name = document.getElementById('customPkgName').value || 'Custom Trip';
    var days = parseInt(document.getElementById('customDays').value) || 1;
    var nights = parseInt(document.getElementById('customNights').value) || 0;
    activePkgDays = days; activePkgNights = nights;
    document.getElementById('tripName').value = name;
    document.getElementById('tripDuration').value = days + ' Hari ' + nights + ' Malam';
}
function loadFromSavedPackage(pkgId) {
    if (!pkgId) return;
    var opt = document.querySelector('#quickPkg option[value="' + pkgId + '"]');
    if (!opt) return;
    var days = parseInt(opt.dataset.days) || 1;
    var nights = parseInt(opt.dataset.nights) || 0;
    activePkgDays = days; activePkgNights = nights;
    document.getElementById('tripName').value = opt.dataset.name;
    document.getElementById('tripDuration').value = days + ' Hari ' + nights + ' Malam';
    var includes = opt.dataset.includes || '';
    if (includes) {
        document.getElementById('calcBody').innerHTML = '';
        includes.split('\n').forEach(function(line) {
            line = line.replace(/^[-*•]\s*/, '').trim();
            if (line) addManualRow('other', line, 1, 'unit', 0, false);
        });
    }
    var basePrice = parseFloat(opt.dataset.price) || 0;
    if (basePrice > 0) addManualRow('other', 'Harga Dasar: ' + opt.dataset.name, 1, 'paket', basePrice, false);
    document.querySelectorAll('.pkg-card').forEach(function(c) { c.classList.remove('active'); });
    recalc();
}

var DB_LABELS = { ticket:'🎫 Pilih Tiket', hotel:'🏨 Pilih Hotel/Penginapan', catering:'🍽️ Pilih Catering', guide:'👤 Pilih Guide', facility:'⚙️ Pilih Fasilitas' };
function openDbPicker(type) {
    currentDbType = type;
    document.getElementById('dbPickerTitle').textContent = DB_LABELS[type] || 'Pilih Item';
    var sel = document.getElementById('dbPickerSelect');
    sel.innerHTML = '<option value="">-- Pilih item --</option>';
    var items = DB[type] || [];
    items.forEach(function(item, i) {
        var label='', cost=0, sell=0, unit='unit';
        if (type==='ticket') { label='['+item.ticket_type+'] '+item.ticket_name+' — Jual: Rp '+fmt0(item.price_sell); cost=item.price_cost; sell=item.price_sell; unit=item.unit||'pax'; }
        else if (type==='hotel') { label=item.partner_name+' — '+item.room_type+' — Rp '+fmt0(item.price_sell)+'/malam'; cost=item.price_cost; sell=item.price_sell; unit='malam'; }
        else if (type==='catering') { label=item.vendor_name+' — '+item.menu_name+' ('+item.portion_unit+') — Rp '+fmt0(item.price_sell); cost=item.price_cost; sell=item.price_sell; unit=item.portion_unit||'porsi'; }
        else if (type==='guide') { label='['+item.guide_type+'] '+item.name+' — Rp '+fmt0(item.daily_rate_sell)+'/hari'; cost=item.daily_rate_cost; sell=item.daily_rate_sell; unit='hari'; }
        else if (type==='facility') { label=(item.category?'['+item.category+'] ':'')+item.name+' ('+item.unit+') — Rp '+fmt0(item.price_sell); cost=item.price_cost; sell=item.price_sell; unit=item.unit||'unit'; }
        var opt = document.createElement('option');
        opt.value = i; opt.textContent = label;
        opt.dataset.cost=cost; opt.dataset.sell=sell; opt.dataset.unit=unit;
        sel.appendChild(opt);
    });
    sel.onchange = function() {
        var o = sel.options[sel.selectedIndex];
        if (!o||!o.value) { document.getElementById('dbPickerPreview').textContent=''; return; }
        document.getElementById('dbPickerPreview').innerHTML = '<strong>Modal:</strong> Rp '+fmt0(o.dataset.cost)+' &nbsp;|&nbsp; <strong>Jual:</strong> Rp '+fmt0(o.dataset.sell)+' &nbsp;|&nbsp; Satuan: <strong>'+(o.dataset.unit||'-')+'</strong>';
        document.getElementById('dbPickerUnit').value = o.dataset.unit||'unit';
        document.getElementById('dbPickerQty').value = (type==='hotel'&&activePkgNights>0) ? activePkgNights : (type==='guide'&&activePkgDays>0) ? activePkgDays : 1;
    };
    document.getElementById('dbPickerPanel').style.display = '';
    document.getElementById('dbPickerPreview').textContent = '';
    document.getElementById('dbPickerQty').value = 1;
}
function addFromDb() {
    var sel = document.getElementById('dbPickerSelect');
    var opt = sel.options[sel.selectedIndex];
    if (!opt||!opt.value) { alert('Pilih item terlebih dahulu.'); return; }
    var items = DB[currentDbType]||[];
    var item = items[parseInt(opt.value)];
    if (!item) return;
    var qty = parseFloat(document.getElementById('dbPickerQty').value)||1;
    var unit = document.getElementById('dbPickerUnit').value||opt.dataset.unit||'unit';
    var sell = parseFloat(opt.dataset.sell)||0;
    var catMap = {ticket:'transport',hotel:'accommodation',catering:'meal',guide:'guide',facility:'equipment'};
    var desc='';
    if (currentDbType==='ticket') desc=item.ticket_name+' ('+item.ticket_type+')';
    else if (currentDbType==='hotel') desc=item.partner_name+' — '+item.room_type;
    else if (currentDbType==='catering') desc=item.vendor_name+' — '+item.menu_name;
    else if (currentDbType==='guide') desc='Guide '+item.guide_type+': '+item.name;
    else if (currentDbType==='facility') desc=item.name;
    addManualRow(catMap[currentDbType]||'other', desc, qty, unit, sell, false);
    sel.value=''; document.getElementById('dbPickerPreview').textContent=''; document.getElementById('dbPickerQty').value=1;
}
function closeDbPicker() { document.getElementById('dbPickerPanel').style.display='none'; currentDbType=''; }

var categoryLabels = {accommodation:'Penginapan',transport:'Transport',meal:'Makan',activity:'Aktivitas',guide:'Guide',equipment:'Perlengkapan',other:'Lainnya'};
var categoryOptions = Object.keys(categoryLabels).map(function(k){return '<option value="'+k+'">'+categoryLabels[k]+'</option>';}).join('');

function addManualRow(type, desc, qty, unit, price, perPax) {
    type=type||'other'; desc=desc||''; qty=qty||1; unit=unit||'unit'; price=price||0; perPax=perPax!==undefined?perPax:false;
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td><select class="calc-select ci-type" onchange="recalc()">'+categoryOptions.replace('value="'+type+'"','value="'+type+'" selected')+'</select></td>'+
        '<td><input type="text" class="calc-input ci-desc" value="'+esc(desc)+'" placeholder="Keterangan..." oninput="recalc()"></td>'+
        '<td><input type="number" class="calc-input ci-qty" value="'+qty+'" min="0" step="0.5" oninput="recalc()" style="text-align:center;"></td>'+
        '<td><input type="text" class="calc-input ci-unit" value="'+unit+'" style="text-align:center;"></td>'+
        '<td><input type="text" class="calc-input ci-price" value="'+(price?fmtNum(price):'')+'" placeholder="0 (manual)" oninput="recalc()"></td>'+
        '<td style="text-align:center;"><input type="checkbox" class="ci-perpax"'+(perPax?' checked':'')+' onchange="recalc()" title="Per pax?" style="width:16px;height:16px;cursor:pointer;"></td>'+
        '<td style="text-align:right;font-weight:700;" class="ci-sub">Rp 0</td>'+
        '<td style="text-align:center;"><button type="button" onclick="this.closest(\'tr\').remove();recalc();" style="background:none;border:none;cursor:pointer;color:#ef4444;font-size:16px;line-height:1;">×</button></td>';
    document.getElementById('calcBody').appendChild(tr);
    recalc();
}

function recalc() {
    var pax=parseInt(document.getElementById('paxCount').value)||1;
    var margin=parseFloat(document.getElementById('marginPct').value)||0;
    var taxPct=parseFloat(document.getElementById('taxCalc').value)||0;
    var totalCost=0;
    document.getElementById('marginLabel').textContent=margin;
    document.querySelectorAll('#calcBody tr').forEach(function(row) {
        var qty=parseFloat(row.querySelector('.ci-qty')?.value)||0;
        var price=unFmt(row.querySelector('.ci-price')?.value||'0');
        var perPax=row.querySelector('.ci-perpax')?.checked;
        var sub=qty*price*(perPax?pax:1);
        var cell=row.querySelector('.ci-sub');
        if (cell) cell.textContent=fmt(sub);
        totalCost+=sub;
    });
    var costPerPax=pax>0?totalCost/pax:0;
    var marginAmt=costPerPax*margin/100;
    var sellPerPax=costPerPax+marginAmt;
    var subtotal=sellPerPax*pax;
    var tax=subtotal*taxPct/100;
    var total=subtotal+tax;
    document.getElementById('sumCost').textContent=fmt(totalCost);
    document.getElementById('sumCostPerPax').textContent=fmt(costPerPax);
    document.getElementById('sumMargin').textContent=fmt(marginAmt*pax);
    document.getElementById('sumSellPerPax').textContent=fmt(sellPerPax);
    document.getElementById('sumSubtotal').textContent=fmt(subtotal);
    document.getElementById('sumTax').textContent=fmt(tax);
    document.getElementById('sumTotal').textContent=fmt(total);
}
function clearAll() {
    if (!confirm('Bersihkan semua?')) return;
    document.getElementById('calcBody').innerHTML='';
    document.getElementById('tripName').value=''; document.getElementById('tripDuration').value='';
    document.getElementById('quickPkg').value='';
    document.querySelectorAll('.pkg-card').forEach(function(c){c.classList.remove('active');});
    closeDbPicker(); recalc();
}
function sendToQuotation() {
    var custId=document.getElementById('toCustomer').value;
    if (!custId){alert('Pilih customer terlebih dahulu.');return;}
    var items=[];
    document.querySelectorAll('#calcBody tr').forEach(function(row){
        items.push({type:row.querySelector('.ci-type')?.value||'other',desc:row.querySelector('.ci-desc')?.value||'',qty:parseFloat(row.querySelector('.ci-qty')?.value)||1,unit:row.querySelector('.ci-unit')?.value||'unit',price:unFmt(row.querySelector('.ci-price')?.value||'0'),perPax:row.querySelector('.ci-perpax')?.checked?1:0});
    });
    sessionStorage.setItem('sunsea_calc',JSON.stringify({items,pax:parseInt(document.getElementById('paxCount').value)||1,margin:parseFloat(document.getElementById('marginPct').value)||0,tripName:document.getElementById('tripName').value}));
    document.getElementById('fCustomerId').value=custId;
    document.getElementById('toQuotationForm').submit();
}
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');}
function fmtNum(n){return n?Math.round(n).toLocaleString('id-ID'):'';}
function unFmt(s){return parseFloat(String(s).replace(/\./g,'').replace(',','.'))||0;}
function fmt(n){return 'Rp '+Math.round(n).toLocaleString('id-ID');}
function fmt0(n){return Math.round(n).toLocaleString('id-ID');}

addManualRow('accommodation'); addManualRow('transport'); addManualRow('meal'); recalc();
</script>

<?php include 'layout-footer.php'; ?>