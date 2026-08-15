<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/business_helper.php';
require_once '../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();

// Get active business ID for database switching and redirect parameter
$activeBusinessId = isset($_SESSION['active_business_id']) ? (string)$_SESSION['active_business_id'] : '';
$activeBusinessSlug = strtolower($activeBusinessId);

// Keep DB routing identical with purchase-orders.php
$businessDatabases = [
    'narayana-hotel' => 'adf_narayana_hotel',
    'bens-cafe' => 'adf_benscafe',
    'eaat-meet' => 'adf_eat_meet',
    'eat-meet' => 'adf_eat_meet'
];

// Switch to active business database
$businessConfig = getActiveBusinessConfig();
$resolvedBusinessDb = $businessDatabases[$activeBusinessSlug] ?? ($businessConfig['database'] ?? null);
if (!empty($resolvedBusinessDb)) {
    Database::switchDatabase($resolvedBusinessDb);
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Buat Purchase Order';

// PO procurement di flow ini khusus untuk Gudang Nasita.
$gudangSupplier = null;
try {
    $gudangSupplier = $db->fetchOne("SELECT id, supplier_name FROM suppliers WHERE LOWER(supplier_name) LIKE '%gudang nasita%' LIMIT 1");

    if (!$gudangSupplier) {
        $supplierColumns = $db->fetchAll("SHOW COLUMNS FROM suppliers");
        $colMap = [];
        foreach ($supplierColumns as $col) {
            $field = strtolower((string)($col['Field'] ?? ''));
            if ($field !== '') {
                $colMap[$field] = $col;
            }
        }

        $insertData = [
            'supplier_name' => 'Gudang Nasita',
        ];

        if (isset($colMap['supplier_code'])) {
            $baseCode = 'GDN' . date('ymd');
            $seq = 1;
            do {
                $candidateCode = $baseCode . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
                $existsCode = $db->fetchOne('SELECT id FROM suppliers WHERE supplier_code = ? LIMIT 1', [$candidateCode]);
                $seq++;
            } while ($existsCode && $seq < 999);
            $insertData['supplier_code'] = $candidateCode;
        }

        if (isset($colMap['contact_person'])) {
            $insertData['contact_person'] = 'Internal Warehouse';
        }
        if (isset($colMap['payment_terms'])) {
            $insertData['payment_terms'] = !empty($colMap['payment_terms']['Default']) ? $colMap['payment_terms']['Default'] : 'net_30';
        }
        if (isset($colMap['is_active'])) {
            $insertData['is_active'] = 1;
        }
        if (isset($colMap['created_by'])) {
            $insertData['created_by'] = (int)($currentUser['id'] ?? 1);
        }

        $supplierId = $db->insert('suppliers', $insertData);
        if ($supplierId) {
            $gudangSupplier = $db->fetchOne('SELECT id, supplier_name FROM suppliers WHERE id = ? LIMIT 1', [$supplierId]);
        }
    }

    if (!$gudangSupplier) {
        $gudangSupplier = $db->fetchOne("SELECT id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
    }
} catch (Throwable $e) {
    $gudangSupplier = $db->fetchOne("SELECT id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
}

if (!$gudangSupplier || empty($gudangSupplier['id'])) {
    $_SESSION['error'] = '❌ Supplier internal Gudang Nasita belum tersedia.';
}

// Get divisions
$divisions = $db->fetchAll("SELECT * FROM divisions ORDER BY division_name");

// PO bisnis memakai katalog dan harga dari Gudang Nasita, tetapi header PO tetap disimpan di database bisnis aktif.
$gudangBarang = [];
try {
    $gudangConfig = require __DIR__ . '/../../config/businesses/gudang-nasita.php';
    $gudangDbName = (string)($gudangConfig['database'] ?? '');
    $originDbName = Database::getCurrentDatabase();
    if ($gudangDbName !== '') {
        $gudangDb = Database::switchDatabase($gudangDbName);
        $gudangBarang = $gudangDb->fetchAll(
            "SELECT id, COALESCE(kode_barang,'') AS kode_barang, nama_barang,
                    COALESCE(kategori,'lainnya') AS kategori, COALESCE(satuan,'pcs') AS satuan,
                    COALESCE(harga_beli,0) AS harga_beli
             FROM gudang_nasita_barang
             WHERE COALESCE(is_active,1) = 1
             ORDER BY nama_barang ASC"
        ) ?: [];
    }
    if (!empty($originDbName)) {
        Database::switchDatabase($originDbName);
        $db = Database::getInstance();
    }
} catch (Throwable $e) {
    error_log('create-po warehouse catalog error: ' . $e->getMessage());
    try {
        if (!empty($originDbName)) {
            Database::switchDatabase($originDbName);
            $db = Database::getInstance();
        }
    } catch (Throwable $restoreError) {
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = (int)($gudangSupplier['id'] ?? 0);
    $po_date = $_POST['po_date'];
    $expected_delivery_date = $_POST['expected_delivery_date'];
    $notes = $_POST['notes'];
    $discount_amount = (float)$_POST['discount_amount'];
    $tax_amount = (float)$_POST['tax_amount'];

    // Build items array
    $items = [];
    if (isset($_POST['items'])) {
        foreach ($_POST['items'] as $item) {
            if (!empty($item['item_name']) && isset($item['quantity']) && $item['quantity'] > 0 && isset($item['unit_price']) && $item['unit_price'] >= 0) {
                $items[] = [
                    'item_name' => $item['item_name'],
                    'item_description' => $item['item_description'] ?? '',
                    'unit_of_measure' => $item['unit_of_measure'] ?? 'pcs',
                    'quantity' => (float)$item['quantity'],
                    'unit_price' => (float)$item['unit_price'],
                    'division_id' => (int)$item['division_id'],
                    'notes' => $item['notes'] ?? ''
                ];
            }
        }
    }

    if ($supplier_id <= 0) {
        $_SESSION['error'] = '❌ Supplier internal Gudang Nasita belum tersedia.';
    } elseif (empty($items)) {
        $_SESSION['error'] = '❌ Minimal tambahkan 1 item dengan quantity dan harga yang valid';
    } else {
        $result = createPurchaseOrder($supplier_id, $po_date, $items, [
            'expected_delivery_date' => $expected_delivery_date,
            'status' => 'submitted',
            'notes' => $notes,
            'discount_amount' => $discount_amount,
            'tax_amount' => $tax_amount
        ]);

        if ($result['success']) {
            $_SESSION['success'] = '✅ ' . $result['message'];
            $redirectUrl = 'view-po.php?id=' . $result['po_id'];
            if (!empty($activeBusinessId)) {
                $redirectUrl .= '&po_business=' . urlencode($activeBusinessId);
            }
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            $_SESSION['error'] = '❌ ' . $result['message'];
        }
    }
}

include '../../includes/header.php';
?>
<style>
    .po-main-panel {
        display: grid;
        grid-template-columns: 1.1fr 1fr;
        gap: 1rem;
        margin-bottom: 1.25rem;
        min-height: 520px;
        height: calc(100vh - 330px);
        max-height: 720px;
    }

    .po-panel-card {
        background: #ffffff;
        border: 1px solid #d6e6f8;
        border-radius: 16px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
        overflow: hidden;
    }

    .po-panel-header {
        padding: 0.9rem 1rem;
        border-bottom: 1px solid #e5edf7;
        background: linear-gradient(180deg, #fbfdff 0%, #f8fbff 100%);
    }

    .po-panel-header-label {
        font-weight: 800;
        font-size: 0.95rem;
        color: #0f172a;
        margin-bottom: 0.5rem;
    }

    .po-search-wrap {
        width: 100%;
        border: 1px solid #dbe7f7;
        border-radius: 10px;
        padding: 0.7rem 0.85rem;
        font-size: 0.9rem;
        background: #fff;
        color: #0f172a;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .po-search-wrap:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
    }

    .po-left-list {
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        overflow-y: auto;
        min-height: 0;
    }

    .po-left-list .po-chk-row {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        padding: 0.8rem 0.9rem;
        border-bottom: 1px solid #edf3fa;
        cursor: pointer;
        transition: background 0.15s ease, transform 0.15s ease;
    }

    .po-left-list .po-chk-row:hover {
        background: #f4f9ff;
    }

    .po-left-list .po-chk-row.selected {
        background: linear-gradient(90deg, rgba(16, 185, 129, 0.08), rgba(59, 130, 246, 0.04));
        border-left: 3px solid #10b981;
        padding-left: 0.75rem;
    }

    .po-left-list .po-chk-row input[type=checkbox] {
        width: 18px;
        height: 18px;
        flex-shrink: 0;
        accent-color: #2563eb;
        margin: 0;
    }

    .po-left-list .item-info {
        flex: 1;
        min-width: 0;
    }

    .po-left-list .item-info strong {
        display: block;
        font-size: 0.9rem;
        line-height: 1.35;
        color: #0f172a;
        margin-bottom: 0.25rem;
    }

    .po-left-list .item-meta {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        font-size: 0.72rem;
        color: #64748b;
    }

    .po-left-list .item-price {
        font-size: 0.8rem;
        font-weight: 800;
        color: #0f9d6a;
        white-space: nowrap;
    }

    .po-right-list {
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        overflow-y: auto;
        min-height: 0;
    }

    .po-right-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 92px 54px 30px;
        align-items: center;
        gap: 0.6rem;
        padding: 0.75rem 0.9rem;
        border-bottom: 1px solid #edf3fa;
    }

    .po-right-row .item-name {
        min-width: 0;
        font-size: 0.85rem;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.35;
    }

    .po-right-row .item-unit {
        font-size: 0.72rem;
        color: #64748b;
        white-space: nowrap;
    }

    .po-right-row input.form-control {
        width: 100%;
        height: 38px;
        border: 1px solid #dbe7f7;
        border-radius: 9px;
        text-align: right;
        padding: 0.5rem 0.65rem;
        font-size: 0.84rem;
        background: #ffffff;
    }

    .po-right-row input.form-control:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
    }

    .po-right-row .remove-btn {
        border: 0;
        background: rgba(239, 68, 68, 0.08);
        color: #dc2626;
        width: 30px;
        height: 30px;
        border-radius: 8px;
        font-size: 1rem;
        cursor: pointer;
        transition: background 0.15s ease;
    }

    .po-right-row .remove-btn:hover {
        background: rgba(239, 68, 68, 0.15);
    }

    .po-footer-bar {
        padding: 0.9rem 1rem;
        border-top: 1px solid #e5edf7;
        background: linear-gradient(180deg, #fbfdff 0%, #f8fbff 100%);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .po-footer-summary {
        font-size: 0.82rem;
        color: #475569;
    }

    .po-footer-summary strong {
        color: #0f172a;
    }

    .po-btn-row {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .po-drop-item:hover {
        background: #f0f9ff !important;
    }

    .po-item-drop {
        background: var(--bg-primary, #fff) !important;
    }
</style>

<div style="margin-bottom: 1.25rem;">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <a href="purchase-orders.php" class="btn btn-secondary btn-sm">
            <i data-feather="arrow-left" style="width: 14px; height: 14px;"></i>
        </a>
        <div>
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">
                📝 Buat Purchase Order Baru
            </h2>
            <p style="color: var(--text-muted); font-size: 0.875rem;">Isi form untuk membuat PO baru</p>
        </div>
    </div>
</div>

<?php if (isset($_SESSION['error'])): ?>
    <div style="background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-left: 4px solid #ef4444; padding: 1.25rem 1.5rem; border-radius: 0.75rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(239,68,68,0.15);">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="width: 48px; height: 48px; background: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-feather="alert-circle" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-weight: 700; color: #991b1b; font-size: 1.125rem; margin-bottom: 0.25rem;">Error!</div>
                <div style="color: #b91c1c; font-size: 0.95rem;"><?php echo $_SESSION['error'];
                                                                    unset($_SESSION['error']); ?></div>
            </div>
            <button onclick="this.parentElement.parentElement.style.display='none'" style="background: none; border: none; color: #dc2626; font-size: 1.5rem; cursor: pointer; padding: 0; width: 32px; height: 32px;">&times;</button>
        </div>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['success'])): ?>
    <div style="background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); border-left: 4px solid #10b981; padding: 1.25rem 1.5rem; border-radius: 0.75rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(16,185,129,0.15);">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="width: 48px; height: 48px; background: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-feather="check-circle" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-weight: 700; color: #065f46; font-size: 1.125rem; margin-bottom: 0.25rem;">Berhasil!</div>
                <div style="color: #047857; font-size: 0.95rem;"><?php echo $_SESSION['success'];
                                                                    unset($_SESSION['success']); ?></div>
            </div>
            <button onclick="this.parentElement.parentElement.style.display='none'" style="background: none; border: none; color: #059669; font-size: 1.5rem; cursor: pointer; padding: 0; width: 32px; height: 32px;">&times;</button>
        </div>
    </div>
<?php endif; ?>

<form method="POST" id="poForm">
    <!-- Header Info Section -->
    <div class="card" style="margin-bottom: 1.25rem;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
            <div class="form-group" style="margin: 0;">
                <label class="form-label">
                    <i data-feather="users" style="width: 14px; height: 14px;"></i>
                    Tujuan PO
                </label>
                <input type="text" class="form-control" value="Gudang Nasita (Internal)" readonly style="font-weight: 700; background: #f8fafc;">
                <input type="hidden" name="supplier_id" value="<?php echo (int)($gudangSupplier['id'] ?? 0); ?>">
            </div>

            <div class="form-group" style="margin: 0;">
                <label class="form-label">
                    <i data-feather="calendar" style="width: 14px; height: 14px;"></i>
                    Tanggal PO *
                </label>
                <input type="date" name="po_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
            </div>

            <div class="form-group" style="margin: 0;">
                <label class="form-label">
                    <i data-feather="truck" style="width: 14px; height: 14px;"></i>
                    Estimasi Terima
                </label>
                <input type="date" name="expected_delivery_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
            </div>

            <div class="form-group" style="margin: 0;">
                <label class="form-label">
                    <i data-feather="message-square" style="width: 14px; height: 14px;"></i>
                    Catatan
                </label>
                <input type="text" name="notes" class="form-control" placeholder="Catatan tambahan...">
            </div>
        </div>
    </div>

    <!-- Split Panel: kiri checklist barang, kanan item terpilih -->
    <div class="po-main-panel">
        <div class="card po-panel-card" style="display:flex; flex-direction:column; overflow:hidden; padding:0;">
            <div class="po-panel-header">
                <div class="po-panel-header-label">Pilih Barang</div>
                <input type="text" id="poLeftSearch" class="po-search-wrap" placeholder="Cari nama barang..." autocomplete="off">
            </div>
            <div id="poLeftList" class="po-left-list" style="flex:1; overflow-y:auto; min-height:0;"></div>
            <div style="padding:0.6rem 1rem; border-top:1px solid #e5edf7; font-size:0.78rem; color:#64748b; flex-shrink:0; background:#fbfdff;">
                <span id="poLeftCount">0 barang</span> &nbsp;·&nbsp; <span id="poSelectedCount" style="color:#0f9d6a; font-weight:800;">0 dipilih</span>
            </div>
        </div>

        <div class="card po-panel-card" style="display:flex; flex-direction:column; overflow:hidden; padding:0;">
            <div class="po-panel-header">
                <div class="po-panel-header-label">Item yang Dipilih</div>
                <div style="display:grid; grid-template-columns:1fr; gap:0.6rem;">
                    <div>
                        <label class="form-label" style="font-size:0.82rem; margin-bottom:0.32rem; color:#475569;">Catatan</label>
                        <input type="text" id="poNotes" class="form-control" placeholder="Catatan tambahan..." style="height:38px; border-radius:10px; border-color:#dbe7f7;">
                    </div>
                </div>
            </div>

            <div id="poRightList" class="po-right-list" style="flex:1; overflow-y:auto; min-height:0; padding:0;">
                <div style="padding:2rem; text-align:center; color:#94a3b8; font-size:0.875rem;" id="poEmptyMsg">
                    ← Centang barang di sebelah kiri
                </div>
            </div>

            <div class="po-footer-bar">
                <span class="po-footer-summary"><strong id="poRightCount">0</strong> item · Total: <strong id="poRightTotal">Rp 0</strong></span>
                <div class="po-btn-row">
                    <a href="purchase-orders.php" class="btn btn-secondary">
                        <i data-feather="x" style="width:16px;height:16px;"></i>
                        Batal
                    </a>
                    <button type="button" class="btn btn-primary" onclick="submitSelectedPo()" style="min-width: 150px;">
                        <i data-feather="check-circle" style="width:16px;height:16px;"></i>
                        Simpan PO
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="poHiddenFormWrap" style="display:none;">
        <input type="hidden" name="discount_amount" value="0">
        <input type="hidden" name="tax_amount" value="0">
    </div>
</form>

<script>
    const divisions = <?php echo json_encode($divisions); ?>;
    const GUDANG_ITEMS = <?php echo json_encode(array_values($gudangBarang), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const selected = {};

    function renderLeft(q) {
        var list = document.getElementById('poLeftList');
        var filtered = q ? GUDANG_ITEMS.filter(function(p) {
            return (p.nama_barang || '').toLowerCase().includes(q.toLowerCase());
        }) : GUDANG_ITEMS;

        document.getElementById('poLeftCount').textContent = filtered.length + ' barang';

        if (!filtered.length) {
            list.innerHTML = '<div style="padding:1.5rem; text-align:center; color:#94a3b8; font-size:.875rem;">Tidak ada yang cocok</div>';
            return;
        }

        list.innerHTML = filtered.map(function(p) {
            var harga = parseFloat(p.harga_beli) || 0;
            var stok = parseFloat(p.current_stock) || 0;
            var minStok = parseFloat(p.min_stock) || 0;
            var stokLow = minStok > 0 && stok <= minStok;
            var stokColor = stokLow ? '#dc2626' : '#64748b';
            var stokWeight = stokLow ? '700' : 'normal';
            var stokText = stok % 1 === 0 ? stok.toLocaleString('id-ID') : stok.toLocaleString('id-ID', { maximumFractionDigits: 2 });
            var isSelected = !!selected[p.id];
            return '<div class="po-chk-row' + (isSelected ? ' selected' : '') + '" data-id="' + p.id + '" onclick="toggleItem(' + p.id + ')">' +
                '<input type="checkbox"' + (isSelected ? ' checked' : '') + ' onclick="event.stopPropagation();toggleItem(' + p.id + ')">' +
                '<div class="item-info">' +
                '<strong>' + (p.nama_barang || '') + '</strong>' +
                '<div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">' +
                '<span class="item-meta">' + (p.satuan || 'pcs') + (p.kode_barang ? ' · ' + p.kode_barang : '') + '</span>' +
                '<span style="font-size:.72rem;color:' + stokColor + ';font-weight:' + stokWeight + ';">' +
                (stokLow ? '⚠ ' : '') + 'Stok: ' + stokText + (minStok > 0 ? ' / min ' + ((minStok % 1 === 0 ? minStok.toLocaleString('id-ID') : minStok.toLocaleString('id-ID', { maximumFractionDigits: 2 }))) : '') +
                '</span>' +
                '</div>' +
                '</div>' +
                '<span class="item-price">' + (harga > 0 ? 'Rp ' + Math.round(harga).toLocaleString('id-ID') : '—') + '</span>' +
                '</div>';
        }).join('');
    }

    function toggleItem(id) {
        var p = GUDANG_ITEMS.find(function(x) { return x.id == id; });
        if (!p) return;
        if (selected[id]) {
            delete selected[id];
        } else {
            selected[id] = {
                nama: p.nama_barang,
                satuan: p.satuan || 'pcs',
                harga: parseFloat(p.harga_beli) || 0,
                qty: ''
            };
        }
        renderRight();

        var row = document.querySelector('#poLeftList [data-id="' + id + '"]');
        if (row) {
            var chk = row.querySelector('input[type=checkbox]');
            if (selected[id]) {
                row.classList.add('selected');
                if (chk) chk.checked = true;
            } else {
                row.classList.remove('selected');
                if (chk) chk.checked = false;
            }
        }

        document.getElementById('poSelectedCount').textContent = Object.keys(selected).length + ' dipilih';
    }

    function renderRight() {
        var ids = Object.keys(selected);
        var emptyMsg = document.getElementById('poEmptyMsg');
        var rightList = document.getElementById('poRightList');
        document.getElementById('poSelectedCount').textContent = ids.length + ' dipilih';
        document.getElementById('poRightCount').textContent = ids.length;

        if (!ids.length) {
            rightList.innerHTML = '<div style="padding:2rem;text-align:center;color:#94a3b8;font-size:.875rem;" id="poEmptyMsg">← Centang barang di sebelah kiri</div>';
            document.getElementById('poRightTotal').textContent = 'Rp 0';
            return;
        }

        var html = '';
        var total = 0;
        ids.forEach(function(id) {
            var s = selected[id];
            var subtotal = (parseFloat(s.qty) || 0) * s.harga;
            total += subtotal;
            html += '<div class="po-right-row">' +
                '<div class="item-name">' + s.nama + '<br><span class="item-unit">' + s.satuan + (s.harga > 0 ? ' · Rp ' + Math.round(s.harga).toLocaleString('id-ID') : '') + '</span></div>' +
                '<input type="number" class="form-control" placeholder="Qty" min="0.01" step="0.01" value="' + (s.qty || '') + '" oninput="selected[' + id + '].qty=this.value;updateTotal()">' +
                '<span class="item-unit">' + s.satuan + '</span>' +
                '<button type="button" class="remove-btn" onclick="toggleItem(' + id + ')">✕</button>' +
                '</div>';
        });

        rightList.innerHTML = html;
        document.getElementById('poRightTotal').textContent = 'Rp ' + Math.round(total).toLocaleString('id-ID');
    }

    function updateTotal() {
        var total = 0;
        Object.values(selected).forEach(function(s) {
            total += (parseFloat(s.qty) || 0) * s.harga;
        });
        document.getElementById('poRightTotal').textContent = 'Rp ' + Math.round(total).toLocaleString('id-ID');
    }

    function submitSelectedPo() {
        var ids = Object.keys(selected);
        if (!ids.length) {
            alert('Centang minimal 1 barang.');
            return;
        }

        var valid = 0;
        ids.forEach(function(id) {
            if (parseFloat(selected[id].qty) > 0) valid++;
        });
        if (!valid) {
            alert('Isi qty untuk barang yang dipilih.');
            return;
        }

        var form = document.getElementById('poForm');
        form.querySelectorAll('input[name^="items["]').forEach(function(el) { el.remove(); });

        var idx = 0;
        ids.forEach(function(id) {
            var s = selected[id];
            var qty = parseFloat(s.qty);
            if (!qty || qty <= 0) return;

            function addInput(name, val) {
                var el = document.createElement('input');
                el.type = 'hidden';
                el.name = name;
                el.value = val;
                form.appendChild(el);
            }

            addInput('items[' + idx + '][item_name]', s.nama);
            addInput('items[' + idx + '][quantity]', qty);
            addInput('items[' + idx + '][unit_of_measure]', s.satuan);
            addInput('items[' + idx + '][unit_price]', s.harga);
            addInput('items[' + idx + '][item_description]', '');
            addInput('items[' + idx + '][division_id]', <?php echo (int)($divisions[0]['id'] ?? 0); ?>);
            idx++;
        });

        form.submit();
    }

    document.addEventListener('DOMContentLoaded', function() {
        renderLeft('');
        document.getElementById('poLeftSearch').addEventListener('input', function() {
            renderLeft(this.value.trim());
        });
        feather.replace();
    });
</script>

<?php include '../../includes/footer.php'; ?>