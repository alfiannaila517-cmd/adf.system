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
.po-drop-item:hover { background: #f0f9ff !important; }
.po-item-drop { background: var(--bg-primary, #fff) !important; }
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

    <!-- Items Section -->
    <div class="card" style="margin-bottom: 1.25rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 2px solid var(--bg-tertiary);">
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                <i data-feather="package" style="width: 16px; height: 16px;"></i>
                Daftar Item
            </h3>
            <button type="button" onclick="addItem()" class="btn btn-primary btn-sm">
                <i data-feather="plus" style="width: 14px; height: 14px;"></i>
                Tambah
            </button>
        </div>

        <div id="itemsContainer"></div>

        <!-- Totals -->
        <div style="margin-top: 2rem; padding: 1.5rem; background: var(--bg-secondary); border-radius: var(--radius-md);">
            <div style="max-width: 400px; margin-left: auto;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.75rem;">
                    <label style="color: var(--text-muted); font-weight: 500;">Subtotal:</label>
                    <div id="subtotalDisplay" style="font-weight: 600; text-align: right; font-size: 1.125rem;">Rp 0</div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.75rem; align-items: center;">
                    <label style="color: var(--text-muted); font-weight: 500;">Diskon:</label>
                    <input type="number" name="discount_amount" id="discountInput" class="form-control" style="text-align: right;" value="0" step="any" min="0" onchange="calculateTotal()" placeholder="0">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem; align-items: center;">
                    <label style="color: var(--text-muted); font-weight: 500;">Pajak/PPn:</label>
                    <input type="number" name="tax_amount" id="taxInput" class="form-control" style="text-align: right;" value="0" step="any" min="0" onchange="calculateTotal()" placeholder="0">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; padding-top: 1rem; border-top: 2px solid var(--bg-tertiary);">
                    <label style="font-weight: 700; font-size: 1.125rem;">TOTAL:</label>
                    <div id="grandTotalDisplay" style="font-weight: 800; font-size: 1.5rem; color: var(--primary-color); text-align: right;">Rp 0</div>
                </div>
            </div>
        </div>
    </div>

    <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
        <a href="purchase-orders.php" class="btn btn-secondary">
            <i data-feather="x" style="width: 16px; height: 16px;"></i>
            Batal
        </a>
        <button type="submit" class="btn btn-primary" style="min-width: 150px;">
            <i data-feather="check-circle" style="width: 16px; height: 16px;"></i>
            Simpan PO
        </button>
    </div>
</form>

<script>
    let itemCount = 0;
    const divisions = <?php echo json_encode($divisions); ?>;
    const GUDANG_API = '<?php echo BASE_URL; ?>/api/gudang-produk-search.php';

    function addItem() {
        itemCount++;
        const container = document.getElementById('itemsContainer');
        const itemDiv = document.createElement('div');
        itemDiv.className = 'item-row';
        itemDiv.style.cssText = 'display: grid; grid-template-columns: 0.3fr 2fr 1fr 0.7fr 0.7fr 1fr 1fr auto; gap: 0.5rem; padding: 0.5rem; border-bottom: 1px solid var(--bg-tertiary); align-items: start;';
        itemDiv.innerHTML = `
        <div style="font-weight: 600; color: var(--primary-color); font-size: 0.875rem; padding-top:.55rem;">#${itemCount}</div>
        
        <div style="position:relative;">
            <input type="text" name="items[${itemCount}][item_name]" class="form-control po-item-ac" placeholder="Ketik nama item..." required autocomplete="off" style="font-size: 0.875rem; padding: 0.5rem;" oninput="handleItemInput(this)" onblur="hideItemDrop(this,200)">
            <div class="po-item-drop" style="display:none;position:absolute;left:0;right:0;top:100%;background:#fff;border:1px solid #e2e8f0;border-radius:0 0 .5rem .5rem;max-height:200px;overflow-y:auto;z-index:999;box-shadow:0 4px 16px rgba(0,0,0,.12);"></div>
            <div class="po-item-hint" style="font-size:.7rem;margin-top:2px;display:none;"></div>
        </div>
        
        <select name="items[${itemCount}][division_id]" class="form-control" required style="font-size: 0.875rem; padding: 0.5rem;">
            ${divisions.map(d => `<option value="${d.id}">${d.division_name}</option>`).join('')}
        </select>
        
        <input type="number" name="items[${itemCount}][quantity]" class="form-control item-qty" step="any" min="0" value="1" required onchange="calculateItemTotal(this)" style="font-size: 0.875rem; padding: 0.5rem;">
        
        <select name="items[${itemCount}][unit_of_measure]" class="form-control po-item-unit" style="font-size: 0.875rem; padding: 0.5rem;">
            <option value="pcs">Pcs</option>
            <option value="kg">Kg</option>
            <option value="liter">Ltr</option>
            <option value="box">Box</option>
            <option value="pack">Pack</option>
            <option value="unit">Unit</option>
            <option value="botol">Botol</option>
            <option value="karton">Karton</option>
        </select>
        
        <input type="number" name="items[${itemCount}][unit_price]" class="form-control item-price" step="any" min="0" value="0" required onchange="calculateItemTotal(this)" placeholder="0" style="font-size: 0.875rem; padding: 0.5rem;">
        
        <div class="form-control item-subtotal" readonly style="background: #e6f7ff; font-weight: 700; color: var(--primary-color); font-size: 0.875rem; padding: 0.5rem;">Rp 0</div>
        
        <button type="button" onclick="removeItem(this)" class="btn btn-sm btn-danger" title="Hapus" style="padding: 0.5rem; margin-top:.1rem;">
            <i data-feather="x" style="width: 14px; height: 14px;"></i>
        </button>
        
        <input type="hidden" name="items[${itemCount}][item_description]" value="">
    `;
        container.appendChild(itemDiv);
        feather.replace();
    }

    // ── Gudang autocomplete helpers ────────────────────────────────────────────
    let acTimers = {};

    function handleItemInput(inp) {
        const key = inp.name;
        clearTimeout(acTimers[key]);
        const q = inp.value.trim();
        const drop = inp.nextElementSibling;
        const hint = drop.nextElementSibling;
        if (q.length < 2) { drop.style.display='none'; hint.style.display='none'; return; }
        acTimers[key] = setTimeout(() => fetchGudangItems(q, inp, drop, hint), 280);
    }

    async function fetchGudangItems(q, inp, drop, hint) {
        try {
            const r = await fetch(`${GUDANG_API}?action=search&q=${encodeURIComponent(q)}`);
            const d = await r.json();
            const items = d.data || [];
            if (!items.length) {
                drop.style.display='none';
                hint.textContent = '⚠️ Tidak ada di database Gudang — ketik manual';
                hint.style.color = '#f59e0b'; hint.style.display='block';
                return;
            }
            hint.style.display='none';
            drop.innerHTML = items.map(p =>
                `<div class="po-drop-item" style="padding:.5rem .75rem;cursor:pointer;font-size:.84rem;border-bottom:1px solid #f1f5f9;"
                    data-name="${p.nama_barang.replace(/"/g,'&quot;')}"
                    data-unit="${(p.satuan||'pcs').replace(/"/g,'&quot;')}"
                    onmousedown="selectGudangItem(this)">
                    <span style="font-weight:600;">${p.nama_barang}</span>
                    <span style="color:#64748b;font-size:.75rem;margin-left:.5rem;">${p.kategori||''} &middot; ${p.satuan||'pcs'}</span>
                </div>`
            ).join('');
            drop.style.display='block';
        } catch(e) {
            drop.style.display='none';
        }
    }

    function selectGudangItem(el) {
        const row   = el.closest('.item-row');
        const inp   = row.querySelector('.po-item-ac');
        const drop  = inp.nextElementSibling;
        const hint  = drop.nextElementSibling;
        const unit  = row.querySelector('.po-item-unit');
        inp.value = el.dataset.name;
        // match unit option or keep pcs
        if (unit) {
            const u = el.dataset.unit.toLowerCase();
            [...unit.options].forEach(o => { if (o.value.toLowerCase()===u) o.selected=true; });
        }
        drop.style.display='none';
        hint.textContent = '✅ Item dari Gudang Nasita';
        hint.style.color = '#16a34a'; hint.style.display='block';
        row.querySelector('.item-qty')?.focus();
    }

    function hideItemDrop(inp, delay) {
        setTimeout(() => {
            const drop = inp.nextElementSibling;
            if (drop) drop.style.display='none';
        }, delay);
    }

    function removeItem(btn) {
        if (document.querySelectorAll('.item-row').length === 1) {
            alert('Minimal harus ada 1 item!');
            return;
        }
        btn.closest('.item-row').remove();
        calculateTotal();
        // Renumber items
        document.querySelectorAll('.item-row').forEach((row, index) => {
            row.querySelector('div').textContent = `#${index + 1}`;
        });
        itemCount = document.querySelectorAll('.item-row').length;
    }

    function calculateItemTotal(input) {
        const row = input.closest('.item-row');
        const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
        const price = parseFloat(row.querySelector('.item-price').value) || 0;
        const itemSubtotal = qty * price;
        row.querySelector('.item-subtotal').textContent = 'Rp ' + itemSubtotal.toLocaleString('id-ID', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        });
        calculateTotal();
    }

    function calculateTotal() {
        let subtotal = 0;
        document.querySelectorAll('.item-row').forEach(row => {
            const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
            const price = parseFloat(row.querySelector('.item-price').value) || 0;
            subtotal += (qty * price);
        });

        const discount = parseFloat(document.getElementById('discountInput').value) || 0;
        const tax = parseFloat(document.getElementById('taxInput').value) || 0;
        const grandTotal = subtotal - discount + tax;

        document.getElementById('subtotalDisplay').textContent = 'Rp ' + subtotal.toLocaleString('id-ID', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        });
        document.getElementById('grandTotalDisplay').textContent = 'Rp ' + grandTotal.toLocaleString('id-ID', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        });
    }

    // Add first item on load
    document.addEventListener('DOMContentLoaded', function() {
        addItem();
        feather.replace();

        // Form validation before submit
        document.getElementById('poForm').addEventListener('submit', function(e) {
            let hasError = false;
            let errorMsg = '';

            // Check if at least one item exists
            const items = document.querySelectorAll('.item-row');
            if (items.length === 0) {
                hasError = true;
                errorMsg = 'Minimal tambahkan 1 item!';
            }

            // Validate each item
            items.forEach((row, index) => {
                const itemName = row.querySelector('input[name*="[item_name]"]').value.trim();
                const qty = parseFloat(row.querySelector('.item-qty').value);
                const price = parseFloat(row.querySelector('.item-price').value);

                if (!itemName) {
                    hasError = true;
                    errorMsg = `Item #${index + 1}: Nama item wajib diisi!`;
                }

                if (isNaN(qty) || qty <= 0) {
                    hasError = true;
                    errorMsg = `Item #${index + 1}: Quantity harus lebih dari 0!`;
                }

                if (isNaN(price) || price < 0) {
                    hasError = true;
                    errorMsg = `Item #${index + 1}: Harga tidak valid!`;
                }
            });

            if (hasError) {
                e.preventDefault();
                alert('❌ ' + errorMsg);
                return false;
            }

            console.log('Form data:', new FormData(this));
        });
    });
</script>

<?php include '../../includes/footer.php'; ?>