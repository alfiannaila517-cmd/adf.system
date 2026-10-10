<?php
/**
 * CQC General Invoice - Create New Invoice
 * Theme: Navy + Gold
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
    ensureCQCGeneralInvoiceTable($pdo);
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

// Pre-fill from quotation if from_quotation param exists
$fromQuotation = null;
$quotationItems = [];
$preSelectedCustomerId = 0;
$fromQuotationId = isset($_GET['from_quotation']) ? (int)$_GET['from_quotation'] : 0;
if ($fromQuotationId > 0) {
    try {
        ensureCQCQuotationTable($pdo);
        $stmtQ = $pdo->prepare("SELECT * FROM cqc_quotations WHERE id = ?");
        $stmtQ->execute([$fromQuotationId]);
        $fromQuotation = $stmtQ->fetch(PDO::FETCH_ASSOC);
        if ($fromQuotation) {
            $stmtQI = $pdo->prepare("SELECT * FROM cqc_quotation_items WHERE quotation_id = ? ORDER BY sort_order");
            $stmtQI->execute([$fromQuotationId]);
            $quotationItems = $stmtQI->fetchAll(PDO::FETCH_ASSOC);
            // Try to match customer from database by phone or name
            foreach ($customers as $cust) {
                $custPhone = preg_replace('/[^0-9]/', '', $cust['phone'] ?? '');
                $quotPhone = preg_replace('/[^0-9]/', '', $fromQuotation['client_phone'] ?? '');
                $quotName = strtolower(trim($fromQuotation['client_name'] ?? ''));
                $custName = strtolower(trim($cust['customer_name'] ?? ''));
                $custCompany = strtolower(trim($cust['company_name'] ?? ''));
                if (($custPhone && $quotPhone && $custPhone === $quotPhone) ||
                    $custName === $quotName ||
                    ($custCompany && strpos($quotName, $custCompany) !== false) ||
                    strpos($quotName, $custName) !== false) {
                    $preSelectedCustomerId = $cust['id'];
                    break;
                }
            }
        }
    } catch (Exception $e) {}
}

// Generate invoice number
function generateInvoiceNumber($pdo) {
    $prefix = 'INV';
    $year = date('Y');
    $month = date('m');
    
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM cqc_general_invoices WHERE YEAR(invoice_date) = $year AND MONTH(invoice_date) = $month");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'] + 1;
    
    return sprintf('%s/%s/%s/%03d/CQC', $prefix, $year, $month, $count);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        $invoiceNumber = $_POST['invoice_number'] ?: generateInvoiceNumber($pdo);
        $invoiceDate = $_POST['invoice_date'];
        $dueDate = $_POST['due_date'] ?: null;
        $clientName = trim($_POST['client_name']);
        $clientPhone = trim($_POST['client_phone'] ?? '');
        $clientEmail = trim($_POST['client_email'] ?? '');
        $clientAddress = trim($_POST['client_address'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
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
        
        $pphPct = floatval($_POST['pph_percentage'] ?? 0);
        $pphAmt = $afterDiscount * $pphPct / 100;
        
        $totalAmount = $afterDiscount + $ppnAmt - $pphAmt;
        
        // Insert invoice
        $stmt = $pdo->prepare("
            INSERT INTO cqc_general_invoices 
            (invoice_number, invoice_date, due_date, client_name, client_phone, client_email, client_address, subject, notes,
             subtotal, discount_percentage, discount_amount, ppn_percentage, ppn_amount, pph_percentage, pph_amount, total_amount, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $invoiceNumber, $invoiceDate, $dueDate, $clientName, $clientPhone, $clientEmail, $clientAddress, $subject, $notes,
            $subtotal, $discountPct, $discountAmt, $ppnPct, $ppnAmt, $pphPct, $pphAmt, $totalAmount, 
            $_SESSION['user_id'] ?? 1
        ]);
        
        $invoiceId = $pdo->lastInsertId();
        
        // Insert items
        $stmtItem = $pdo->prepare("
            INSERT INTO cqc_general_invoice_items (invoice_id, description, quantity, unit, unit_price, amount, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($items as $idx => $item) {
            $stmtItem->execute([$invoiceId, $item['description'], $item['quantity'], $item['unit'], $item['unit_price'], $item['amount'], $idx]);
        }
        
        // Mark quotation as approved if created from quotation
        $fromQuotIdPost = intval($_POST['from_quotation_id'] ?? 0);
        if ($fromQuotIdPost > 0) {
            try {
                $pdo->prepare("UPDATE cqc_quotations SET status = 'approved', updated_at = NOW() WHERE id = ?")->execute([$fromQuotIdPost]);
            } catch (Exception $e) {}
        }
        
        $pdo->commit();
        
        header('Location: view-invoice.php?id=' . $invoiceId);
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Error: ' . $e->getMessage();
    }
}

$pageTitle = "Buat Invoice Baru";
include '../../includes/header.php';
?>

<style>
    :root { --navy: #0d1f3c; --gold: #f0b429; --gold-dark: #c49a1a; }
    .inv-wrap { max-width: 900px; margin: 0 auto; padding: 20px; }
    .inv-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 3px solid var(--navy); }
    .inv-head h1 { font-size: 22px; color: var(--navy); }
    .btn-back { background: #f1f5f9; color: #475569; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-size: 13px; font-weight: 600; }
    .btn-back:hover { background: #e2e8f0; }
    
    .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 15px rgba(0,0,0,0.06); margin-bottom: 20px; overflow: hidden; }
    .card-head { background: linear-gradient(135deg, var(--navy), #1a3a5c); color: #fff; padding: 12px 20px; font-size: 13px; font-weight: 700; }
    .card-body { padding: 20px; }
    
    .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
    .form-group { margin-bottom: 0; }
    .form-group.full { grid-column: span 2; }
    .form-group label { display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 5px; text-transform: uppercase; }
    .form-group input, .form-group textarea, .form-group select { width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 13px; }
    .form-group input:focus, .form-group textarea:focus { outline: none; border-color: var(--gold); box-shadow: 0 0 0 3px rgba(240,180,41,0.15); }
    .form-group textarea { resize: vertical; min-height: 60px; }
    
    .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 15px; font-size: 13px; }
    .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    
    /* Items Table */
    .items-table { width: 100%; border-collapse: collapse; }
    .items-table th { background: var(--navy); color: #fff; padding: 10px 12px; font-size: 11px; text-transform: uppercase; text-align: left; }
    .items-table th:first-child { border-radius: 6px 0 0 0; }
    .items-table th:last-child { border-radius: 0 6px 0 0; text-align: center; }
    .items-table td { padding: 8px; border-bottom: 1px solid #e2e8f0; }
    .items-table input { width: 100%; padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 12px; }
    .items-table .col-desc { width: 40%; }
    .items-table .col-qty { width: 10%; }
    .items-table .col-unit { width: 12%; }
    .items-table .col-price { width: 18%; }
    .items-table .col-amount { width: 15%; text-align: right; font-weight: 600; color: var(--navy); }
    .items-table .col-action { width: 5%; text-align: center; }
    
    .btn-remove { background: #fee2e2; color: #dc2626; border: none; width: 28px; height: 28px; border-radius: 6px; cursor: pointer; font-size: 14px; }
    .btn-add-row { background: var(--gold); color: var(--navy); border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 12px; margin-top: 10px; }
    
    /* Summary */
    .summary-box { background: #f8fafc; border-radius: 8px; padding: 15px 20px; margin-top: 15px; }
    .summary-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e2e8f0; font-size: 13px; }
    .summary-row:last-child { border-bottom: none; }
    .summary-row.total { background: var(--navy); color: #fff; margin: 10px -20px -15px; padding: 12px 20px; border-radius: 0 0 8px 8px; }
    .summary-row.total .value { color: var(--gold); font-size: 16px; font-weight: 800; }
    .summary-row input { width: 80px; padding: 5px 8px; border: 1px solid #e2e8f0; border-radius: 4px; text-align: right; font-size: 12px; }
    
    .form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 25px; padding-top: 20px; border-top: 1px solid #e2e8f0; }
    .btn-save { background: linear-gradient(135deg, var(--gold), var(--gold-dark)); color: var(--navy); padding: 12px 30px; border: none; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-save:hover { transform: translateY(-2px); box-shadow: 0 5px 20px rgba(240,180,41,0.3); }
    .btn-draft { background: #f1f5f9; color: #475569; padding: 12px 25px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }
</style>
<style>
/* dark-theme-overrides */
body:not([data-theme="light"]) .inv-head h1 { color: #f1f5f9; }
body:not([data-theme="light"]) .inv-head { border-bottom-color: rgba(148,163,184,.3); }
body:not([data-theme="light"]) .btn-back,
body:not([data-theme="light"]) .btn-draft { background: #18233a; color: #cbd5e1; }
body:not([data-theme="light"]) .btn-back:hover { background: #22304d; }
body:not([data-theme="light"]) .card { background: #111a2e; border: 1px solid rgba(148,163,184,.2); box-shadow: 0 2px 15px rgba(0,0,0,.35); }
body:not([data-theme="light"]) .form-group label { color: #94a3b8; }
body:not([data-theme="light"]) .form-group input,
body:not([data-theme="light"]) .form-group textarea,
body:not([data-theme="light"]) .form-group select,
body:not([data-theme="light"]) .items-table input,
body:not([data-theme="light"]) .summary-row input { background: #0f172a; color: #e2e8f0; border-color: rgba(148,163,184,.25); }
body:not([data-theme="light"]) .form-group input::placeholder,
body:not([data-theme="light"]) .form-group textarea::placeholder,
body:not([data-theme="light"]) .items-table input::placeholder { color: #64748b; }
body:not([data-theme="light"]) .form-group select option { background: #0f172a; color: #e2e8f0; }
body:not([data-theme="light"]) .alert-error { background: rgba(239,68,68,.15); color: #fca5a5; border-color: rgba(239,68,68,.35); }
body:not([data-theme="light"]) .items-table th { background: #1a3a5c; color: #f1f5f9; }
body:not([data-theme="light"]) .items-table td { border-bottom-color: rgba(148,163,184,.15); color: #e2e8f0; }
body:not([data-theme="light"]) .items-table .col-amount { color: #f1f5f9; }
body:not([data-theme="light"]) .btn-remove { background: rgba(239,68,68,.15); color: #fca5a5; }
body:not([data-theme="light"]) .btn-add-row,
body:not([data-theme="light"]) .btn-save { color: #0d1f3c; }
body:not([data-theme="light"]) .summary-box { background: #18233a; }
body:not([data-theme="light"]) .summary-row { border-bottom-color: rgba(148,163,184,.2); color: #e2e8f0; }
body:not([data-theme="light"]) .summary-row.total { background: #1a3a5c; color: #fff; }
body:not([data-theme="light"]) .form-actions { border-top-color: rgba(148,163,184,.2); }
body:not([data-theme="light"]) [style*="background: #e0f7fa"] { background: rgba(56,189,248,.12) !important; border-left-color: #38bdf8 !important; color: #e2e8f0; }
</style>

<div class="inv-wrap">
    <div class="inv-head">
        <h1>📝 Buat Invoice Baru</h1>
        <a href="index-cqc.php" class="btn-back">← Kembali</a>
    </div>
    
    <?php if ($error): ?>
    <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <form method="POST" id="invoiceForm">
        <input type="hidden" name="from_quotation_id" value="<?php echo $fromQuotationId; ?>">
        
        <?php if ($fromQuotation): ?>
        <div style="background: #e0f7fa; border-left: 4px solid #0d1f3c; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 13px;">
            📝 Invoice dibuat dari Quotation <strong><?php echo htmlspecialchars($fromQuotation['quote_number']); ?></strong>
            — Status quotation akan otomatis menjadi <strong>Disetujui</strong> setelah simpan.
        </div>
        <?php endif; ?>
        
        <!-- Invoice Details -->
        <div class="card">
            <div class="card-head">📋 Detail Invoice</div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Nomor Invoice *</label>
                        <input type="text" name="invoice_number" value="<?php echo generateInvoiceNumber($pdo); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Subject / Perihal</label>
                        <input type="text" name="subject" id="subject" value="<?php echo htmlspecialchars($fromQuotation['subject'] ?? ''); ?>" placeholder="e.g. Jasa Konsultasi, Penjualan Barang">
                    </div>
                    <div class="form-group">
                        <label>Tanggal Invoice *</label>
                        <input type="date" name="invoice_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Tanggal Jatuh Tempo</label>
                        <input type="date" name="due_date" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Client Info -->
        <div class="card">
            <div class="card-head">👤 Informasi Client</div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Pilih dari Database</label>
                        <select id="customer_select" onchange="fillCustomerData()" style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--card-bg); color: var(--text);">
                            <option value="">-- Pilih Customer atau input manual --</option>
                            <?php foreach ($customers as $cust): ?>
                            <option value="<?php echo $cust['id']; ?>"
                                <?php echo ($preSelectedCustomerId == $cust['id']) ? 'selected' : ''; ?>
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
                        <label>Nama Client *</label>
                        <input type="text" name="client_name" id="client_name" required placeholder="PT ABC atau Nama Perorangan" value="<?php echo htmlspecialchars($fromQuotation['client_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>No. Telepon</label>
                        <input type="text" name="client_phone" id="client_phone" placeholder="+62..." value="<?php echo htmlspecialchars($fromQuotation['client_phone'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="client_email" id="client_email" placeholder="email@example.com" value="<?php echo htmlspecialchars($fromQuotation['client_email'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Alamat</label>
                        <input type="text" name="client_address" id="client_address" placeholder="Alamat lengkap" value="<?php echo htmlspecialchars($fromQuotation['client_address'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Invoice Items -->
        <div class="card">
            <div class="card-head">📦 Item Invoice</div>
            <div class="card-body">
                <table class="items-table" id="itemsTable">
                    <thead>
                        <tr>
                            <th class="col-desc">Deskripsi</th>
                            <th class="col-qty">Qty</th>
                            <th class="col-unit">Satuan</th>
                            <th class="col-price">Harga Satuan</th>
                            <th class="col-amount">Jumlah</th>
                            <th class="col-action"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                        <?php if (!empty($quotationItems)): ?>
                        <?php foreach ($quotationItems as $qi): ?>
                        <tr class="item-row">
                            <td><input type="text" name="item_desc[]" value="<?php echo htmlspecialchars($qi['description']); ?>"></td>
                            <td><input type="number" name="item_qty[]" value="<?php echo $qi['quantity']; ?>" min="0" step="0.01" class="qty-input"></td>
                            <td><input type="text" name="item_unit[]" value="<?php echo htmlspecialchars($qi['unit']); ?>" placeholder="unit"></td>
                            <td><input type="number" name="item_price[]" value="<?php echo $qi['unit_price']; ?>" min="0" class="price-input"></td>
                            <td class="col-amount"><span class="row-amount"><?php echo number_format($qi['amount'], 0, ',', '.'); ?></span></td>
                            <td class="col-action"><button type="button" class="btn-remove" onclick="removeRow(this)">×</button></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr class="item-row">
                            <td><input type="text" name="item_desc[]" placeholder="Deskripsi item..."></td>
                            <td><input type="number" name="item_qty[]" value="1" min="0" step="0.01" class="qty-input"></td>
                            <td><input type="text" name="item_unit[]" value="unit" placeholder="unit"></td>
                            <td><input type="number" name="item_price[]" value="0" min="0" class="price-input"></td>
                            <td class="col-amount"><span class="row-amount">0</span></td>
                            <td class="col-action"><button type="button" class="btn-remove" onclick="removeRow(this)">×</button></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <button type="button" class="btn-add-row" onclick="addRow()">+ Tambah Item</button>
                
                <!-- Summary -->
                <div class="summary-box">
                    <div class="summary-row">
                        <span>Subtotal</span>
                        <span class="value" id="subtotalDisplay">IDR 0</span>
                    </div>
                    <div class="summary-row">
                        <span>Diskon <input type="number" name="discount_percentage" value="<?php echo $fromQuotation ? (($fromQuotation['discount_type'] === 'percentage' ? $fromQuotation['discount_value'] : 0)) : 0; ?>" min="0" max="100" step="0.1" style="width:60px" onchange="calculateTotal()">%</span>
                        <span class="value" id="discountDisplay">- IDR 0</span>
                    </div>
                    <div class="summary-row">
                        <span>PPN <input type="number" name="ppn_percentage" value="<?php echo $fromQuotation ? $fromQuotation['ppn_percentage'] : 11; ?>" min="0" max="100" step="0.1" style="width:60px" onchange="calculateTotal()">%</span>
                        <span class="value" id="ppnDisplay">+ IDR 0</span>
                    </div>
                    <div class="summary-row">
                        <span>PPh <input type="number" name="pph_percentage" value="0" min="0" max="100" step="0.1" style="width:60px" onchange="calculateTotal()">%</span>
                        <span class="value" id="pphDisplay">- IDR 0</span>
                    </div>
                    <div class="summary-row total">
                        <span>TOTAL</span>
                        <span class="value" id="totalDisplay">IDR 0</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Notes -->
        <div class="card">
            <div class="card-head">📝 Catatan</div>
            <div class="card-body">
                <div class="form-group full">
                    <textarea name="notes" placeholder="Catatan tambahan untuk invoice..."></textarea>
                </div>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn-save">💾 Simpan Invoice</button>
        </div>
    </form>
</div>

<script>
function formatNumber(num) {
    return new Intl.NumberFormat('id-ID').format(num);
}

function addRow() {
    const tbody = document.getElementById('itemsBody');
    const row = document.createElement('tr');
    row.className = 'item-row';
    row.innerHTML = `
        <td><input type="text" name="item_desc[]" placeholder="Deskripsi item..."></td>
        <td><input type="number" name="item_qty[]" value="1" min="0" step="0.01" class="qty-input"></td>
        <td><input type="text" name="item_unit[]" value="unit" placeholder="unit"></td>
        <td><input type="number" name="item_price[]" value="0" min="0" class="price-input"></td>
        <td class="col-amount"><span class="row-amount">0</span></td>
        <td class="col-action"><button type="button" class="btn-remove" onclick="removeRow(this)">×</button></td>
    `;
    tbody.appendChild(row);
    attachInputListeners(row);
}

function removeRow(btn) {
    const rows = document.querySelectorAll('.item-row');
    if (rows.length > 1) {
        btn.closest('tr').remove();
        calculateTotal();
    }
}

// Auto-fill customer data from dropdown
function fillCustomerData() {
    const select = document.getElementById('customer_select');
    const option = select.options[select.selectedIndex];
    
    if (option.value) {
        document.getElementById('client_name').value = option.dataset.name || '';
        document.getElementById('client_phone').value = option.dataset.phone || '';
        document.getElementById('client_email').value = option.dataset.email || '';
        document.getElementById('client_address').value = option.dataset.address || '';
    }
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
    
    const pphPct = parseFloat(document.querySelector('[name="pph_percentage"]').value) || 0;
    const pphAmt = afterDiscount * pphPct / 100;
    
    const total = afterDiscount + ppnAmt - pphAmt;
    
    document.getElementById('subtotalDisplay').textContent = 'IDR ' + formatNumber(subtotal);
    document.getElementById('discountDisplay').textContent = '- IDR ' + formatNumber(discountAmt);
    document.getElementById('ppnDisplay').textContent = '+ IDR ' + formatNumber(ppnAmt);
    document.getElementById('pphDisplay').textContent = '- IDR ' + formatNumber(pphAmt);
    document.getElementById('totalDisplay').textContent = 'IDR ' + formatNumber(total);
}

// Initialize
document.querySelectorAll('.item-row').forEach(attachInputListeners);
calculateTotal();
</script>

<?php include '../../includes/footer.php'; ?>
