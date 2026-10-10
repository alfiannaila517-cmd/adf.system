<?php
/**
 * CQC Quotation - Create New Quotation (Penawaran Harga)
 * Theme: Navy + Gold
 * Format: QUOT/BULAN/TAHUN/NOMOR
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

require_once '../cqc-projects/db-helper.php';

try {
    $pdo = getCQCDatabaseConnection();
    ensureCQCQuotationTable($pdo);
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Fetch customers from Database module
$customers = [];
try {
    $bizDb = Database::getInstance();
    $customers = $bizDb->fetchAll("SELECT id, customer_code, customer_name, company_name, phone, email, address, city FROM customers WHERE is_active = 1 ORDER BY customer_name");
} catch (Exception $e) {
    // Customers table may not exist yet
}

$message = '';
$error = '';

// Generate quotation number: QUOT/MM/YYYY/XXX
function generateQuoteNumber($pdo) {
    $month = date('m');
    $year = date('Y');
    
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM cqc_quotations WHERE YEAR(quote_date) = $year AND MONTH(quote_date) = $month");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'] + 1;
    
    return sprintf('QUOT/%s/%s/%03d', $month, $year, $count);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        $quoteNumber = $_POST['quote_number'] ?: generateQuoteNumber($pdo);
        $quoteDate = $_POST['quote_date'];
        $validUntil = $_POST['valid_until'] ?: null;
        $clientName = trim($_POST['client_name']);
        $clientAttn = trim($_POST['client_attn'] ?? '');
        $clientPhone = trim($_POST['client_phone'] ?? '');
        $clientEmail = trim($_POST['client_email'] ?? '');
        $clientAddress = trim($_POST['client_address'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $termsConditions = trim($_POST['terms_conditions'] ?? '');
        
        // Calculate totals
        $subtotal = 0;
        $items = [];
        if (isset($_POST['item_desc']) && is_array($_POST['item_desc'])) {
            for ($i = 0; $i < count($_POST['item_desc']); $i++) {
                if (!empty($_POST['item_desc'][$i])) {
                    $qty = floatval($_POST['item_qty'][$i] ?? 1);
                    $price = floatval($_POST['item_price'][$i] ?? 0);
                    $amount = $qty * $price;
                    $subtotal += $amount;
                    $items[] = [
                        'description' => $_POST['item_desc'][$i],
                        'remarks' => $_POST['item_remarks'][$i] ?? '',
                        'quantity' => $qty,
                        'unit' => $_POST['item_unit'][$i] ?? 'unit',
                        'unit_price' => $price,
                        'amount' => $amount
                    ];
                }
            }
        }
        
        $discountPct = floatval($_POST['discount_percentage'] ?? 0);
        $discountAmt = $subtotal * $discountPct / 100;
        $afterDiscount = $subtotal - $discountAmt;
        
        $ppnPct = floatval($_POST['ppn_percentage'] ?? 11);
        $ppnAmt = $afterDiscount * $ppnPct / 100;
        
        $totalAmount = $afterDiscount + $ppnAmt;
        
        // Get project fields
        $projectName = trim($_POST['project_name'] ?? '');
        $projectLocation = trim($_POST['project_location'] ?? '');
        $solarCapacityKwp = floatval($_POST['solar_capacity_kwp'] ?? 0);
        
        // Insert quotation
        $stmt = $pdo->prepare("
            INSERT INTO cqc_quotations 
            (quote_number, quote_date, valid_until, client_name, client_attn, client_phone, client_email, client_address, 
             subject, project_name, project_location, solar_capacity_kwp, notes, terms_conditions,
             subtotal, discount_percentage, discount_amount, ppn_percentage, ppn_amount, total_amount, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $quoteNumber, $quoteDate, $validUntil, $clientName, $clientAttn, $clientPhone, $clientEmail, $clientAddress,
            $subject, $projectName, $projectLocation, $solarCapacityKwp, $notes, $termsConditions,
            $subtotal, $discountPct, $discountAmt, $ppnPct, $ppnAmt, $totalAmount, 
            $_SESSION['user_id'] ?? 1
        ]);
        
        $quotationId = $pdo->lastInsertId();
        
        // Insert items
        $stmtItem = $pdo->prepare("
            INSERT INTO cqc_quotation_items (quotation_id, description, remarks, quantity, unit, unit_price, amount, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($items as $idx => $item) {
            $stmtItem->execute([
                $quotationId,
                $item['description'],
                $item['remarks'],
                $item['quantity'],
                $item['unit'],
                $item['unit_price'],
                $item['amount'],
                $idx + 1
            ]);
        }
        
        $pdo->commit();
        
        header("Location: view-quotation.php?id=$quotationId&success=1");
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Gagal menyimpan quotation: " . $e->getMessage();
    }
}

$pageTitle = "Buat Quotation";
include '../../includes/header.php';
?>

<style>
    :root {
        --cqc-primary: #0d1f3c;
        --cqc-accent: #f0b429;
        --card-bg: var(--bg-secondary, #fff);
        --text: var(--text-primary, #333);
        --border: var(--bg-tertiary, #e2e8f0);
    }
    .quote-container { max-width: 900px; margin: 0 auto; }
    .card { background: var(--card-bg); border-radius: 10px; margin-bottom: 1.25rem; border: 1px solid var(--border); }
    .card-head { 
        background: var(--cqc-primary); 
        color: white; 
        padding: 12px 20px; 
        font-weight: 600; 
        border-radius: 10px 10px 0 0;
    }
    .card-body { padding: 20px; }
    .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
    .form-group { margin-bottom: 0; }
    .form-group label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 5px; color: var(--text); }
    .form-group input, .form-group textarea, .form-group select {
        width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px;
        font-size: 14px; background: var(--card-bg); color: var(--text);
    }
    .form-group input:focus, .form-group textarea:focus, .form-group select:focus {
        outline: none; border-color: var(--cqc-accent);
    }
    .items-table { width: 100%; border-collapse: collapse; }
    .items-table th { 
        background: var(--cqc-primary); color: white; padding: 10px; text-align: left; font-size: 13px;
    }
    .items-table td { padding: 8px; border-bottom: 1px solid var(--border); }
    .items-table input { width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px; font-size: 13px; }
    .col-no { width: 40px; }
    .col-desc { width: 35%; }
    .col-remarks { width: 15%; }
    .col-qty { width: 60px; }
    .col-unit { width: 60px; }
    .col-price { width: 120px; }
    .col-amount { width: 120px; }
    .col-action { width: 40px; }
    .btn-add { 
        background: var(--cqc-accent); color: var(--cqc-primary); border: none; 
        padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer;
    }
    .btn-remove { 
        background: #ef4444; color: white; border: none; width: 28px; height: 28px; 
        border-radius: 4px; cursor: pointer; font-size: 16px;
    }
    .summary-box {
        background: linear-gradient(135deg, var(--cqc-primary), #1a3a5c);
        color: white; padding: 20px; border-radius: 10px; margin-top: 20px;
    }
    .summary-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.1); }
    .summary-row.total { border-top: 2px solid var(--cqc-accent); font-size: 18px; font-weight: 700; }
    .btn-submit {
        background: var(--cqc-accent); color: var(--cqc-primary); border: none;
        padding: 14px 40px; border-radius: 8px; font-weight: 700; font-size: 15px;
        cursor: pointer; width: 100%; margin-top: 20px;
    }
    .btn-submit:hover { opacity: 0.9; }
</style>
<style>
/* dark-theme-overrides */
body:not([data-theme="light"]) input[readonly][style*="background: #f1f5f9"] { background: #18233a !important; color: #e2e8f0; }
body:not([data-theme="light"]) [style*="background: #fee2e2"] { background: rgba(239,68,68,.15) !important; border-color: rgba(239,68,68,.35) !important; color: #fca5a5 !important; }
body:not([data-theme="light"]) [style*="color: #64748b"] { color: #94a3b8 !important; }
</style>

<div class="quote-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div>
            <h2 style="margin: 0; color: var(--cqc-primary);">📝 Buat Quotation Baru</h2>
            <p style="margin: 5px 0 0; color: #64748b; font-size: 14px;">Penawaran Harga untuk Client</p>
        </div>
        <a href="index-cqc.php?tab=quotation" style="color: var(--cqc-primary); text-decoration: none; font-weight: 500;">
            ← Kembali ke Daftar
        </a>
    </div>

    <?php if ($error): ?>
        <div style="background: #fee2e2; border: 1px solid #fca5a5; color: #dc2626; padding: 12px 16px; border-radius: 8px; margin-bottom: 1rem;">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="quoteForm">
        <!-- Quote Info -->
        <div class="card">
            <div class="card-head">📋 Informasi Quotation</div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Nomor Quotation</label>
                        <input type="text" name="quote_number" value="<?php echo generateQuoteNumber($pdo); ?>" readonly style="background: #f1f5f9;">
                    </div>
                    <div class="form-group">
                        <label>Tanggal *</label>
                        <input type="date" name="quote_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Berlaku Sampai</label>
                        <input type="date" name="valid_until" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                    </div>
                    <div class="form-group">
                        <label>Subject / Perihal</label>
                        <input type="text" name="subject" placeholder="e.g. Penawaran Harga Solar Panel">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Client Info -->
        <div class="card">
            <div class="card-head">👤 Informasi Client (To)</div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Pilih dari Database</label>
                        <select id="customer_select" onchange="fillCustomerData()">
                            <option value="">-- Pilih Customer atau input manual --</option>
                            <?php foreach ($customers as $cust): ?>
                            <option value="<?php echo $cust['id']; ?>"
                                data-name="<?php echo htmlspecialchars($cust['customer_name'] . ($cust['company_name'] ? ' (' . $cust['company_name'] . ')' : '')); ?>"
                                data-phone="<?php echo htmlspecialchars($cust['phone'] ?? ''); ?>"
                                data-email="<?php echo htmlspecialchars($cust['email'] ?? ''); ?>"
                                data-address="<?php echo htmlspecialchars(($cust['address'] ?? '') . ($cust['city'] ? ', ' . $cust['city'] : '')); ?>">
                                <?php echo htmlspecialchars($cust['customer_code'] . ' - ' . $cust['customer_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nama Perusahaan / Client *</label>
                        <input type="text" name="client_name" id="client_name" required placeholder="PT. ABC">
                    </div>
                    <div class="form-group">
                        <label>Attention (Attn)</label>
                        <input type="text" name="client_attn" id="client_attn" placeholder="Nama PIC">
                    </div>
                    <div class="form-group">
                        <label>No. Telepon</label>
                        <input type="text" name="client_phone" id="client_phone" placeholder="+62...">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="client_email" id="client_email" placeholder="email@example.com">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Project Info (for auto-create project when ACC) -->
        <div class="card">
            <div class="card-head">☀️ Informasi Proyek</div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Nama Proyek *</label>
                        <input type="text" name="project_name" id="project_name" required placeholder="Contoh: Solar Panel PT. ABC">
                    </div>
                    <div class="form-group">
                        <label>Kapasitas (KWp)</label>
                        <input type="number" name="solar_capacity_kwp" step="0.1" min="0" placeholder="3.5">
                        <small style="color: #64748b; font-size: 11px;">Kilowatt Peak</small>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Lokasi Proyek *</label>
                        <input type="text" name="project_location" id="project_location" required placeholder="Alamat lengkap lokasi proyek">
                    </div>
                </div>
                <p style="margin: 10px 0 0 0; font-size: 12px; color: #64748b;">
                    <strong>💡 Info:</strong> Data proyek di atas akan otomatis membuat proyek baru saat quotation di-ACC oleh klien.
                </p>
            </div>
        </div>
        
        <!-- Items -->
        <div class="card">
            <div class="card-head">📦 Item Penawaran</div>
            <div class="card-body" style="padding: 0;">
                <table class="items-table" id="itemsTable">
                    <thead>
                        <tr>
                            <th class="col-no">NO</th>
                            <th class="col-desc">DESCRIPTION</th>
                            <th class="col-remarks">REMARKS</th>
                            <th class="col-qty">QTY</th>
                            <th class="col-unit">UNIT</th>
                            <th class="col-price">PRICE</th>
                            <th class="col-amount">TOTAL PRICE</th>
                            <th class="col-action"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                        <tr class="item-row">
                            <td class="row-no">1</td>
                            <td><input type="text" name="item_desc[]" placeholder="Deskripsi item..."></td>
                            <td><input type="text" name="item_remarks[]" placeholder="CQC"></td>
                            <td><input type="number" name="item_qty[]" value="1" min="0" step="0.01" class="qty-input"></td>
                            <td><input type="text" name="item_unit[]" value="unit"></td>
                            <td><input type="number" name="item_price[]" value="0" min="0" class="price-input"></td>
                            <td class="row-amount" style="text-align: right; font-weight: 600;">0</td>
                            <td><button type="button" class="btn-remove" onclick="removeRow(this)">×</button></td>
                        </tr>
                    </tbody>
                </table>
                <div style="padding: 15px;">
                    <button type="button" class="btn-add" onclick="addRow()">+ Tambah Item</button>
                </div>
            </div>
        </div>
        
        <!-- Tax & Discount -->
        <div class="card">
            <div class="card-head">💰 Diskon & Pajak</div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Diskon (%)</label>
                        <input type="number" name="discount_percentage" value="0" min="0" max="100" step="0.01" onchange="calculateTotal()">
                    </div>
                    <div class="form-group">
                        <label>PPN (%)</label>
                        <input type="number" name="ppn_percentage" value="11" min="0" max="100" step="0.01" onchange="calculateTotal()">
                    </div>
                </div>
                
                <!-- Summary -->
                <div class="summary-box">
                    <div class="summary-row">
                        <span>PRICES (Subtotal)</span>
                        <span id="subtotalDisplay">IDR 0</span>
                    </div>
                    <div class="summary-row">
                        <span>DISC</span>
                        <span id="discountDisplay">- IDR 0</span>
                    </div>
                    <div class="summary-row">
                        <span>TOTAL PRICE</span>
                        <span id="afterDiscDisplay">IDR 0</span>
                    </div>
                    <div class="summary-row">
                        <span>TAX 11%</span>
                        <span id="ppnDisplay">+ IDR 0</span>
                    </div>
                    <div class="summary-row total">
                        <span>GRAND TOTAL</span>
                        <span id="totalDisplay">IDR 0</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Terms & Conditions -->
        <div class="card">
            <div class="card-head">📜 Term and Condition</div>
            <div class="card-body">
                <div class="form-group">
                    <textarea name="terms_conditions" rows="5" placeholder="Masukkan syarat dan ketentuan...">Price include Tax 11%

TOP:
    1. Payment 100%COD
    2. FOT Jogja PT SGM

Lama waktu pekerjaan disesuaikan dengan Time Line After SPK</textarea>
                </div>
            </div>
        </div>
        
        <!-- Notes -->
        <div class="card">
            <div class="card-head">📝 Catatan</div>
            <div class="card-body">
                <div class="form-group">
                    <textarea name="notes" rows="3" placeholder="Catatan tambahan...">Demikian penawaran ini kami ajukan atas perhatiannya kami ucapkan terima kasih</textarea>
                </div>
            </div>
        </div>
        
        <button type="submit" class="btn-submit">💾 Simpan Quotation</button>
    </form>
</div>

<script>
let rowCount = 1;

function formatNumber(num) {
    return new Intl.NumberFormat('id-ID').format(Math.round(num));
}

function addRow() {
    rowCount++;
    const tbody = document.getElementById('itemsBody');
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML = `
        <td class="row-no">${rowCount}</td>
        <td><input type="text" name="item_desc[]" placeholder="Deskripsi item..."></td>
        <td><input type="text" name="item_remarks[]" placeholder="CQC"></td>
        <td><input type="number" name="item_qty[]" value="1" min="0" step="0.01" class="qty-input"></td>
        <td><input type="text" name="item_unit[]" value="unit"></td>
        <td><input type="number" name="item_price[]" value="0" min="0" class="price-input"></td>
        <td class="row-amount" style="text-align: right; font-weight: 600;">0</td>
        <td><button type="button" class="btn-remove" onclick="removeRow(this)">×</button></td>
    `;
    tbody.appendChild(tr);
    attachInputListeners(tr);
    updateRowNumbers();
}

function removeRow(btn) {
    const rows = document.querySelectorAll('.item-row');
    if (rows.length > 1) {
        btn.closest('tr').remove();
        updateRowNumbers();
        calculateTotal();
    }
}

function updateRowNumbers() {
    document.querySelectorAll('.item-row').forEach((row, idx) => {
        row.querySelector('.row-no').textContent = idx + 1;
    });
    rowCount = document.querySelectorAll('.item-row').length;
}

function attachInputListeners(row) {
    const inputs = row.querySelectorAll('.qty-input, .price-input');
    inputs.forEach(input => {
        input.addEventListener('input', calculateTotal);
    });
}

function calculateTotal() {
    let subtotal = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const price = parseFloat(row.querySelector('.price-input').value) || 0;
        const amount = qty * price;
        row.querySelector('.row-amount').textContent = formatNumber(amount);
        subtotal += amount;
    });
    
    const discountPct = parseFloat(document.querySelector('[name="discount_percentage"]').value) || 0;
    const discountAmt = subtotal * discountPct / 100;
    const afterDiscount = subtotal - discountAmt;
    
    const ppnPct = parseFloat(document.querySelector('[name="ppn_percentage"]').value) || 0;
    const ppnAmt = afterDiscount * ppnPct / 100;
    
    const total = afterDiscount + ppnAmt;
    
    document.getElementById('subtotalDisplay').textContent = 'IDR ' + formatNumber(subtotal);
    document.getElementById('discountDisplay').textContent = '- IDR ' + formatNumber(discountAmt);
    document.getElementById('afterDiscDisplay').textContent = 'IDR ' + formatNumber(afterDiscount);
    document.getElementById('ppnDisplay').textContent = '+ IDR ' + formatNumber(ppnAmt);
    document.getElementById('totalDisplay').textContent = 'IDR ' + formatNumber(total);
}

// Auto-fill customer data from dropdown
function fillCustomerData() {
    const select = document.getElementById('customer_select');
    const option = select.options[select.selectedIndex];
    
    if (option.value) {
        document.getElementById('client_name').value = option.dataset.name || '';
        document.getElementById('client_phone').value = option.dataset.phone || '';
        document.getElementById('client_email').value = option.dataset.email || '';
    }
}

// Initialize
document.querySelectorAll('.item-row').forEach(attachInputListeners);
calculateTotal();
</script>

<?php include '../../includes/footer.php'; ?>
