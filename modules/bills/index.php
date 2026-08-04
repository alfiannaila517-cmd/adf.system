<?php

/**
 * MONTHLY BILLS MODULE - SIMPLE VERSION
 * Direct bill entry without templates
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/business_helper.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ../../login.php');
    exit;
}

$bizConfig = getActiveBusinessConfig();
$themeColor = $bizConfig['theme']['color_primary'] ?? '#0d1f3c';
$themeSecondary = $bizConfig['theme']['color_secondary'] ?? '#1e3a5c';

// Cash accounts for the "Bayar" (pay driver trip) modal - same source used by modules/cashbook/add.php
$cashAccounts = [];
try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $masterBusinessId = getMasterBusinessId();
    if ($masterBusinessId) {
        $stmt = $masterDb->prepare("SELECT id, account_name, account_type FROM cash_accounts WHERE business_id = ? AND is_active = 1 AND account_type IN ('cash', 'bank', 'e-wallet', 'credit_card') ORDER BY account_type = 'cash' DESC, account_type = 'bank' DESC, account_name");
        $stmt->execute([$masterBusinessId]);
        $cashAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error fetching cash accounts for bills page: " . $e->getMessage());
    $cashAccounts = [];
}

// Divisions & categories for the "Tambah Tagihan Manual" form - so the cashbook entry
// gets the correct division/category instead of always defaulting to "Biaya Operasional"
$billDivisions = [];
$billCategories = [];
try {
    $bizDb = Database::getInstance();
    $billDivisions = $bizDb->fetchAll(
        "SELECT id, division_name FROM divisions WHERE is_active = 1 AND division_type IN ('expense', 'both') ORDER BY division_name"
    );
    $billCategories = $bizDb->fetchAll(
        "SELECT id, category_name, division_id FROM categories WHERE category_type = 'expense' ORDER BY category_name"
    );
} catch (Exception $e) {
    error_log("Error fetching divisions/categories for bills page: " . $e->getMessage());
    $billDivisions = [];
    $billCategories = [];
}

include '../../includes/header.php';
?>

<style>
    :root {
        --navy: <?php echo htmlspecialchars($themeColor); ?>;
        --navy2: <?php echo htmlspecialchars($themeSecondary); ?>;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    .main-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }

    .page-header {
        margin-bottom: 30px;
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .page-icon {
        width: 46px;
        height: 46px;
        border-radius: 12px;
        background: linear-gradient(135deg, var(--navy), var(--navy2));
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        flex-shrink: 0;
        box-shadow: 0 6px 16px rgba(13, 31, 60, 0.3);
    }

    .page-header h1 {
        font-size: 24px;
        color: #1e293b;
        margin-bottom: 3px;
    }

    .page-header p {
        color: #666;
        font-size: 13px;
    }

    .content-grid {
        display: grid;
        grid-template-columns: 1fr 2fr;
        gap: 30px;
    }

    .card {
        background: white;
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        padding: 16px;
    }

    .card h2 {
        font-size: 15px;
        color: #333;
        margin-bottom: 14px;
        border-bottom: 2px solid var(--navy);
        padding-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .form-group {
        margin-bottom: 15px;
    }

    .form-group label {
        display: block;
        font-weight: 600;
        color: #333;
        margin-bottom: 8px;
        font-size: 14px;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 5px;
        font-size: 14px;
        font-family: inherit;
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: var(--navy);
        box-shadow: 0 0 0 3px rgba(13, 31, 60, 0.1);
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }

    .btn-submit {
        width: 100%;
        padding: 12px;
        background: linear-gradient(135deg, var(--navy), var(--navy2));
        color: white;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        margin-top: 10px;
        transition: all 0.3s;
    }

    .btn-submit:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 14px rgba(13, 31, 60, 0.35);
    }

    .alert {
        padding: 12px 15px;
        border-radius: 5px;
        margin-bottom: 15px;
        font-size: 14px;
    }

    .alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .alert-error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .bill-row {
        background: #f3f5fb;
        padding: 9px 12px;
        border-radius: 6px;
        margin-bottom: 6px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        border-left: 3px solid var(--navy);
        transition: background .15s;
    }

    .bill-row:hover {
        background: #e9edf7;
    }

    .bill-info h4 {
        font-size: 12.5px;
        color: #1a2540;
        margin-bottom: 3px;
        font-weight: 700;
    }

    .bill-info h4 small {
        color: #6b7690;
        font-weight: 500;
    }

    .bill-info p {
        font-size: 11px;
        color: #5a6478;
        font-weight: 500;
        margin: 1px 0;
    }

    .bill-amount {
        text-align: right;
    }

    .bill-amount .total {
        font-size: 13px;
        font-weight: 700;
        color: #1a2540;
    }

    .bill-amount .status {
        font-size: 10px;
        margin-top: 4px;
        padding: 2px 7px;
        border-radius: 3px;
        display: inline-block;
        font-weight: 700;
    }

    .status-paid {
        background: #d4edda;
        color: #155724;
    }

    .status-partial {
        background: #fff3cd;
        color: #856404;
    }

    .status-pending {
        background: #d1ecf1;
        color: #0c5460;
    }

    .btn-action {
        padding: 4px 9px;
        margin-left: 5px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 10.5px;
        font-weight: 600;
    }

    .btn-pay {
        background: #22c55e;
        color: white;
    }

    .btn-pay:hover {
        background: #16a34a;
    }

    .btn-edit {
        background: var(--navy);
        color: #fff;
    }

    .btn-edit:hover {
        background: var(--navy2);
    }

    .tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        border-bottom: 2px solid #ddd;
    }

    .tab-btn {
        padding: 10px 20px;
        border: none;
        background: none;
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        color: #666;
        border-bottom: 3px solid transparent;
        transition: all 0.3s;
    }

    .tab-btn.active {
        color: var(--navy);
        border-bottom-color: var(--navy);
    }

    .bill-list {
        max-height: 600px;
        overflow-y: auto;
    }

    .category-tabs {
        display: flex;
        gap: 6px;
        margin-bottom: 12px;
    }

    .category-btn {
        flex: 1;
        padding: 7px 8px;
        border: 1px solid #e2e6ee;
        background: #f7f8fb;
        border-radius: 7px;
        cursor: pointer;
        font-size: 11.5px;
        font-weight: 600;
        color: #555;
        transition: all .15s;
        text-align: center;
    }

    .category-btn:hover {
        background: #eef1f8;
    }

    .category-btn.active {
        background: linear-gradient(135deg, var(--navy), var(--navy2));
        border-color: var(--navy);
        color: #fff;
    }

    .driver-recap-card {
        background: #fff;
        border: 1px solid #e2e6ee;
        border-radius: 8px;
        padding: 12px 14px;
        margin-bottom: 10px;
    }

    .driver-recap-card .dr-name {
        font-size: 13px;
        font-weight: 700;
        color: #1a2540;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }

    .btn-print-recap {
        flex-shrink: 0;
        padding: 4px 10px;
        font-size: 10.5px;
        font-weight: 700;
        border: 1px solid #cbd5f5;
        border-radius: 5px;
        background: #eef2ff;
        color: #3546a3;
        cursor: pointer;
        white-space: nowrap;
    }

    .btn-print-recap:hover {
        background: #dfe5fb;
    }

    .driver-recap-card .dr-stats {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px;
        font-size: 11.5px;
        margin-bottom: 6px;
    }

    .driver-recap-card .dr-stat {
        background: #f7f8fb;
        border-radius: 6px;
        padding: 6px;
        text-align: center;
    }

    .driver-recap-card .dr-stat .v {
        font-size: 13px;
        font-weight: 800;
        color: #1a2540;
    }

    .driver-recap-card .dr-stat .l {
        color: #6b7690;
        font-size: 10px;
    }

    .driver-recap-card .dr-breakdown {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 6px;
        margin-bottom: 6px;
    }

    .driver-recap-card .dr-breakdown .v {
        font-size: 12px;
        font-weight: 700;
        color: #1a2540;
    }

    .driver-recap-card .dr-breakdown .l {
        color: #6b7690;
        font-size: 9.5px;
    }

    .driver-recap-card table {
        width: 100%;
        border-collapse: collapse;
        font-size: 10.5px;
        margin-top: 6px;
    }

    .driver-recap-card th {
        text-align: left;
        color: #6b7690;
        font-weight: 600;
        padding: 4px 3px;
        border-bottom: 1px solid #eef0f5;
    }

    .driver-recap-card td {
        padding: 4px 3px;
        border-bottom: 1px solid #f4f6fa;
        color: #333;
    }

    .pay-filter-bar {
        display: flex;
        gap: 6px;
        margin-bottom: 10px;
    }

    .pay-filter-btn {
        flex: 1;
        padding: 6px 8px;
        border: 1px solid #e2e6ee;
        background: #f7f8fb;
        border-radius: 6px;
        cursor: pointer;
        font-size: 11px;
        font-weight: 600;
        color: #555;
        transition: all .15s;
        text-align: center;
    }

    .pay-filter-btn:hover {
        background: #eef1f8;
    }

    .pay-filter-btn.active {
        background: var(--navy);
        border-color: var(--navy);
        color: #fff;
    }

    .driver-recap-card .dr-paid-summary {
        display: flex;
        justify-content: space-between;
        font-size: 10.5px;
        color: #6b7690;
        margin: -2px 0 8px;
        padding: 0 2px;
    }

    .btn-trip-paid {
        color: #16794d;
        font-weight: 700;
        font-size: 10px;
        white-space: nowrap;
    }

    .btn-trip-pay {
        padding: 3px 8px;
        font-size: 10px;
        font-weight: 700;
        border: none;
        border-radius: 4px;
        background: #22c55e;
        color: #fff;
        cursor: pointer;
        white-space: nowrap;
    }

    .btn-trip-pay:hover {
        background: #16a34a;
    }

    .btn-trip-edit {
        padding: 3px 7px;
        font-size: 10px;
        font-weight: 700;
        border: 1.5px solid #3b82f6;
        border-radius: 4px;
        background: #fff;
        color: #1d4ed8;
        cursor: pointer;
        white-space: nowrap;
    }

    .btn-trip-edit:hover {
        background: #eff6ff;
    }

    /* PAY DRIVER TRIP MODAL */
    .dp-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(10, 15, 30, 0.55);
        z-index: 99999;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .dp-modal-overlay.open {
        display: flex;
    }

    .dp-modal {
        background: #fff;
        border-radius: 12px;
        padding: 20px 22px;
        width: 100%;
        max-width: 380px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    }

    .dp-modal h3 {
        margin: 0 0 14px;
        font-size: 15px;
        font-weight: 700;
        color: #1a2540;
    }

    .dp-summary {
        background: #f3f5fb;
        border-radius: 8px;
        padding: 10px 12px;
        margin-bottom: 14px;
    }

    .dp-summary div {
        display: flex;
        justify-content: space-between;
        font-size: 12.5px;
        color: #4b5568;
        padding: 3px 0;
    }

    .dp-summary strong {
        color: #1a2540;
    }

    .dp-field {
        margin-bottom: 14px;
    }

    .dp-field label {
        display: block;
        font-size: 11.5px;
        font-weight: 700;
        color: #6b7690;
        margin-bottom: 6px;
    }

    .dp-field select {
        width: 100%;
        padding: 8px 10px;
        border: 1px solid #dfe3ee;
        border-radius: 7px;
        font-size: 13px;
        color: #1a2540;
        background: #fff;
    }

    .dp-method-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }

    .dp-method-btn {
        padding: 9px 6px;
        font-size: 12.5px;
        font-weight: 600;
        border: 1.5px solid #dfe3ee;
        border-radius: 8px;
        background: #fff;
        color: #4b5568;
        cursor: pointer;
    }

    .dp-method-btn.active {
        border-color: var(--navy);
        background: var(--navy);
        color: #fff;
    }

    .dp-actions {
        display: flex;
        gap: 10px;
        margin-top: 6px;
    }

    .dp-btn-cancel,
    .dp-btn-confirm {
        flex: 1;
        padding: 10px;
        border: none;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
    }

    .dp-btn-cancel {
        background: #eef0f5;
        color: #4b5568;
    }

    .dp-btn-confirm {
        background: #22c55e;
        color: #fff;
    }

    .dp-btn-confirm:hover {
        background: #16a34a;
    }

    .dp-btn-confirm:disabled {
        background: #9ad6b3;
        cursor: not-allowed;
    }

    .checkbox-group {
        display: flex;
        gap: 20px;
        margin-top: 10px;
    }

    .checkbox-group label {
        display: flex;
        align-items: center;
        cursor: pointer;
        margin-bottom: 0;
    }

    .checkbox-group input {
        width: auto;
        margin-right: 8px;
    }

    @media (max-width: 768px) {
        .content-grid {
            grid-template-columns: 1fr;
        }

        .form-row {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="main-container">
    <!-- PAGE HEADER -->
    <div class="page-header">
        <div class="page-icon">📊</div>
        <div>
            <h1>Menu Tagihan Bulanan</h1>
            <p>Kelola tagihan bulanan hotel secara otomatis tanpa template</p>
        </div>
    </div>

    <!-- CONTENT GRID -->
    <div class="content-grid">
        <!-- LEFT: FORM TAMBAH TAGIHAN -->
        <div class="card">
            <h2>➕ Tambah Tagihan Baru</h2>

            <div id="formMessage"></div>

            <form id="billForm" onsubmit="submitBill(event)">
                <div class="form-group">
                    <label for="billName">Nama Tagihan *</label>
                    <input
                        type="text"
                        id="billName"
                        name="bill_name"
                        placeholder="Contoh: Listrik, Air, Gaji, Sewa"
                        required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="billMonth">Bulan *</label>
                        <input
                            type="month"
                            id="billMonth"
                            name="bill_month"
                            required>
                    </div>
                    <div class="form-group">
                        <label for="amount">Jumlah (Rp) *</label>
                        <input
                            type="number"
                            id="amount"
                            name="amount"
                            placeholder="500000"
                            min="0"
                            step="1000"
                            required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="dueDate">Tanggal Jatuh Tempo</label>
                        <input
                            type="date"
                            id="dueDate"
                            name="due_date">
                    </div>
                    <div class="form-group">
                        <label for="divisionId">Divisi</label>
                        <select id="divisionId" name="division_id" onchange="filterBillCategories()">
                            <option value="">-- Pilih Divisi --</option>
                            <?php foreach ($billDivisions as $div): ?>
                                <option value="<?php echo (int)$div['id']; ?>"><?php echo htmlspecialchars($div['division_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="category">Kategori</label>
                    <select id="category" name="category_id">
                        <option value="">-- Pilih Kategori --</option>
                        <?php foreach ($billCategories as $cat): ?>
                            <option value="<?php echo (int)$cat['id']; ?>" data-division="<?php echo (int)($cat['division_id'] ?? 0); ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="notes">Catatan</label>
                    <textarea
                        id="notes"
                        name="notes"
                        rows="3"
                        placeholder="Contoh: Tagihan bulanan dari PLN..."></textarea>
                </div>

                <div class="checkbox-group">
                    <label>
                        <input
                            type="checkbox"
                            id="isRecurring"
                            name="is_recurring"
                            value="1">
                        Tagihan Berulang (Bulanan)
                    </label>
                </div>

                <button type="submit" class="btn-submit">💾 Simpan Tagihan</button>
            </form>
        </div>

        <!-- RIGHT: LIST TAGIHAN -->
        <div class="card">
            <h2>📋 Daftar Tagihan</h2>

            <div style="margin-bottom: 15px;">
                <label style="font-size: 14px; font-weight: 600; color: #333;">Bulan:</label>
                <input
                    type="month"
                    id="filterMonth"
                    onchange="onMonthChange()"
                    style="width: 150px; padding: 8px; border: 1px solid #ddd; border-radius: 5px; margin-top: 5px;">
            </div>

            <div class="category-tabs">
                <button class="category-btn" data-cat="driver" onclick="switchCategory('driver')">🚗 Tagihan Driver</button>
                <button class="category-btn active" data-cat="manual" onclick="switchCategory('manual')">📝 Tagihan Manual</button>
                <button class="category-btn" data-cat="bulanan" onclick="switchCategory('bulanan')">🔁 Tagihan Bulanan</button>
            </div>

            <div id="manualBillsWrap">
                <div class="tabs">
                    <button class="tab-btn active" onclick="switchTab('all', event)">Semua</button>
                    <button class="tab-btn" onclick="switchTab('pending', event)">Pending</button>
                    <button class="tab-btn" onclick="switchTab('partial', event)">Cicilan</button>
                    <button class="tab-btn" onclick="switchTab('paid', event)">Lunas</button>
                </div>

                <div id="billsList" class="bill-list">
                    <p style="color: #999; text-align: center; padding: 40px 20px;">Loading...</p>
                </div>
            </div>

            <div id="driverRecapSection" class="bill-list" style="display:none;">
                <p style="color: #999; text-align: center; padding: 40px 20px;">Loading...</p>
            </div>
        </div>
    </div>
</div>

<!-- PAY DRIVER TRIP MODAL -->
<div id="payTripModalOverlay" class="dp-modal-overlay" onclick="if(event.target===this)closePayTripModal()">
    <div class="dp-modal">
        <h3>💸 Bayar Trip Driver</h3>
        <div class="dp-summary">
            <div><span>Driver / Mitra</span><strong id="ptDriverName">-</strong></div>
            <div><span>Jumlah</span><strong id="ptAmount">Rp 0</strong></div>
        </div>
        <div class="dp-field">
            <label>Metode Pembayaran</label>
            <div class="dp-method-grid">
                <button type="button" class="dp-method-btn active" data-method="cash" onclick="selectPayMethod('cash')">💵 Cash</button>
                <button type="button" class="dp-method-btn" data-method="transfer" onclick="selectPayMethod('transfer')">🏦 Transfer</button>
                <button type="button" class="dp-method-btn" data-method="card" onclick="selectPayMethod('card')">💳 Kartu</button>
                <button type="button" class="dp-method-btn" data-method="other" onclick="selectPayMethod('other')">⚙️ Lainnya</button>
            </div>
        </div>
        <div class="dp-field">
            <label>Sumber Dana / Rekening</label>
            <select id="ptCashAccount">
                <?php foreach ($cashAccounts as $acc): ?>
                    <option value="<?php echo (int)$acc['id']; ?>"><?php echo htmlspecialchars($acc['account_name']); ?> (<?php echo htmlspecialchars($acc['account_type']); ?>)</option>
                <?php endforeach; ?>
                <?php if (empty($cashAccounts)): ?>
                    <option value="1">Kas Tunai (default)</option>
                <?php endif; ?>
            </select>
        </div>
        <div class="dp-actions">
            <button type="button" class="dp-btn-cancel" onclick="closePayTripModal()">Batal</button>
            <button type="button" class="dp-btn-confirm" id="ptConfirmBtn" onclick="confirmPayDriverTrip()">✅ Bayar & Catat ke Kas</button>
        </div>
    </div>
</div>

<!-- EDIT DRIVER TRIP AMOUNT MODAL -->
<div id="editTripModalOverlay" class="dp-modal-overlay" onclick="if(event.target===this)closeEditTripModal()">
    <div class="dp-modal">
        <h3>✏️ Edit Nominal Trip</h3>
        <div class="dp-summary">
            <div><span>Driver / Mitra</span><strong id="etDriverName">-</strong></div>
            <div><span>Trip</span><strong id="etTripLabel" style="font-size:11px;text-align:right;max-width:55%;">-</strong></div>
        </div>
        <div class="dp-field">
            <label>Total Tarif (Rp)</label>
            <input type="number" id="etTotalPrice" min="0" step="1000"
                style="width:100%;padding:8px 10px;border:1px solid #dfe3ee;border-radius:7px;font-size:13px;color:#1a2540;box-sizing:border-box;"
                oninput="updateEditCompanyAmount()">
        </div>
        <div class="dp-field">
            <label>Bagian Driver / Pemilik (Rp)</label>
            <input type="number" id="etOwnerAmount" min="0" step="1000"
                style="width:100%;padding:8px 10px;border:1px solid #dfe3ee;border-radius:7px;font-size:13px;color:#1a2540;box-sizing:border-box;"
                oninput="updateEditCompanyAmount()">
        </div>
        <div class="dp-summary">
            <div><span>Bagian Perusahaan (otomatis)</span><strong id="etCompanyAmount" style="color:#1d4ed8;">Rp 0</strong></div>
        </div>
        <div class="dp-actions">
            <button type="button" class="dp-btn-cancel" onclick="closeEditTripModal()">Batal</button>
            <button type="button" class="dp-btn-confirm" id="etConfirmBtn" onclick="confirmEditDriverTrip()" style="background:#1d4ed8;">💾 Simpan</button>
        </div>
    </div>
</div>

<script>
    const BASE_URL = '<?php echo BASE_URL; ?>';
    const ACTIVE_BUSINESS = '<?php echo $_SESSION['active_business_id'] ?? 'narayana-hotel'; ?>';

    // Set default month to current month
    document.getElementById('billMonth').valueAsDate = new Date();
    document.getElementById('filterMonth').valueAsDate = new Date();

    let currentTab = 'all';
    let currentCategory = 'manual';

    // Reload whichever category is currently active when the month filter changes
    function onMonthChange() {
        if (currentCategory === 'driver') {
            loadDriverRecap();
        } else {
            loadBills();
        }
    }

    // SWITCH CATEGORY (Driver / Manual / Bulanan)
    function switchCategory(cat) {
        currentCategory = cat;
        document.querySelectorAll('.category-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.cat === cat);
        });

        const isDriver = cat === 'driver';
        document.getElementById('manualBillsWrap').style.display = isDriver ? 'none' : 'block';
        document.getElementById('driverRecapSection').style.display = isDriver ? 'block' : 'none';

        if (isDriver) {
            loadDriverRecap();
        } else {
            loadBills();
        }
    }

    // SHOW ONLY CATEGORIES BELONGING TO THE SELECTED DIVISION
    function filterBillCategories() {
        const divisionId = document.getElementById('divisionId').value;
        const categorySelect = document.getElementById('category');
        const options = categorySelect.querySelectorAll('option[data-division]');

        options.forEach(opt => {
            const matches = !divisionId || opt.getAttribute('data-division') === divisionId;
            opt.hidden = !matches;
        });

        // Reset selected category if it no longer belongs to the chosen division
        const selectedOpt = categorySelect.selectedOptions[0];
        if (selectedOpt && selectedOpt.hidden) {
            categorySelect.value = '';
        }
    }

    // SUBMIT FORM
    async function submitBill(e) {
        e.preventDefault();

        const formData = new FormData(document.getElementById('billForm'));
        formData.append('business', ACTIVE_BUSINESS);
        try {
            const response = await fetch(BASE_URL + '/api/add-monthly-bill.php', {
                method: 'POST',
                body: formData,
                credentials: 'include' // Include cookies for authentication
            });

            const result = await response.json();
            const msgEl = document.getElementById('formMessage');

            if (result.success) {
                msgEl.innerHTML = `<div class="alert alert-success">✅ ${result.message} (${result.bill_code})</div>`;
                document.getElementById('billForm').reset();
                document.getElementById('billMonth').valueAsDate = new Date();
                filterBillCategories();

                setTimeout(() => loadBills(), 1000);
            } else {
                msgEl.innerHTML = `<div class="alert alert-error">❌ ${result.message}</div>`;
            }
        } catch (error) {
            document.getElementById('formMessage').innerHTML =
                `<div class="alert alert-error">❌ Error: ${error.message}</div>`;
        }
    }

    // LOAD BILLS LIST
    async function loadBills() {
        const month = document.getElementById('filterMonth').value;
        const listEl = document.getElementById('billsList');

        if (!month) {
            listEl.innerHTML = '<p style="color: #999; text-align: center; padding: 40px;">Pilih bulan terlebih dahulu</p>';
            return;
        }

        try {
            const url = BASE_URL + `/api/get-monthly-bills.php?month=${month}&limit=50`;
            console.log('[Bills] Fetching from:', url);
            console.log('[Bills] Active business:', ACTIVE_BUSINESS);

            const response = await fetch(url, {
                method: 'GET',
                credentials: 'include' // Include cookies for session
            });

            console.log('[Bills] Response status:', response.status);
            console.log('[Bills] Response headers:', response.headers);

            if (!response.ok) {
                const errorText = await response.text();
                console.error('[Bills] Error response:', errorText);
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const result = await response.json();
            console.log('[Bills] Data loaded successfully:', result);

            if (!result.success) {
                listEl.innerHTML = `<p style="color: #d32f2f; text-align: center; padding: 20px;">Error: ${result.message}</p>`;
                return;
            }

            if (!result.bills || result.bills.length === 0) {
                listEl.innerHTML = '<p style="color: #999; text-align: center; padding: 40px;">Tidak ada tagihan bulan ini</p>';
                return;
            }

            // Filter by category (manual = one-time entries, bulanan = recurring), then by status tab
            let filtered = result.bills.filter(b => {
                if (currentCategory === 'bulanan') return b.is_recurring === 1;
                return b.source_type !== 'driver_trip' && b.is_recurring !== 1;
            });
            if (currentTab !== 'all') {
                filtered = filtered.filter(b => b.status === currentTab);
            }

            if (filtered.length === 0) {
                listEl.innerHTML = `<p style="color: #999; text-align: center; padding: 40px;">Tidak ada tagihan dengan status ini</p>`;
                return;
            }

            let html = '';
            filtered.forEach(bill => {
                const statusClass = `status-${bill.status}`;
                const progress = bill.amount > 0 ? Math.round((bill.paid_amount / bill.amount) * 100) : 0;

                html += `
                <div class="bill-row">
                    <div class="bill-info">
                        <h4>${bill.bill_name} <small>(${bill.bill_code})</small></h4>
                        <p>${bill.category_name || 'Umum'} &middot; Rp ${formatNumber(bill.paid_amount)} / Rp ${formatNumber(bill.amount)}</p>
                        <div style="margin-top: 4px; background: #dfe4f0; height: 4px; border-radius: 3px; overflow: hidden; width: 100%;">
                            <div style="background: var(--navy); height: 100%; width: ${progress}%;"></div>
                        </div>
                    </div>
                    <div style="text-align: right; white-space: nowrap;">
                        <div class="bill-amount">
                            <div class="total">Rp ${formatNumber(bill.amount)}</div>
                            <span class="status ${statusClass}">${bill.status.toUpperCase()}</span>
                        </div>
                        <div style="margin-top: 6px;">
                            <button onclick="editBill(${bill.id})" class="btn-action btn-edit">Edit</button>
                            <button onclick="openPayment(${bill.id}, '${bill.bill_name}', ${bill.amount}, ${bill.paid_amount})" class="btn-action btn-pay">Bayar</button>
                        </div>
                    </div>
                </div>
            `;
            });

            listEl.innerHTML = html;
        } catch (error) {
            console.error('[Bills] Error:', error);
            listEl.innerHTML = `<p style="color: #d32f2f; text-align: center; padding: 20px;">❌ Error: ${error.message}</p>`;
        }
    }

    // LOAD DRIVER/MITRA RECAP (Tagihan Driver tab)
    let lastDriverRecap = [];
    let driverPayFilter = 'all'; // all | unpaid | paid

    async function loadDriverRecap() {
        const month = document.getElementById('filterMonth').value;
        const recapEl = document.getElementById('driverRecapSection');

        if (!month) {
            recapEl.innerHTML = '<p style="color: #999; text-align: center; padding: 40px;">Pilih bulan terlebih dahulu</p>';
            return;
        }

        recapEl.innerHTML = '<p style="color: #999; text-align: center; padding: 40px 20px;">Loading...</p>';

        try {
            const response = await fetch(BASE_URL + `/api/get-driver-recap.php?month=${month}`, {
                method: 'GET',
                credentials: 'include'
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const result = await response.json();

            if (!result.success) {
                recapEl.innerHTML = `<p style="color: #d32f2f; text-align: center; padding: 20px;">Error: ${result.message}</p>`;
                return;
            }

            lastDriverRecap = result.recap || [];
            renderDriverRecap();
        } catch (error) {
            console.error('[DriverRecap] Error:', error);
            recapEl.innerHTML = `<p style="color: #d32f2f; text-align: center; padding: 20px;">❌ Error: ${error.message}</p>`;
        }
    }

    // RENDER DRIVER/MITRA RECAP (uses lastDriverRecap + driverPayFilter, no re-fetch)
    function renderDriverRecap() {
        const recapEl = document.getElementById('driverRecapSection');

        if (!lastDriverRecap || lastDriverRecap.length === 0) {
            recapEl.innerHTML = '<p style="color: #999; text-align: center; padding: 40px;">Belum ada tagihan driver/mitra bulan ini</p>';
            return;
        }

        const typeLabel = {
            car_rental: 'Rental Mobil',
            airport_drop: 'Airport Drop',
            harbor_drop: 'Harbor Drop'
        };

        let html = `
            <div class="pay-filter-bar">
                <button class="pay-filter-btn ${driverPayFilter === 'all' ? 'active' : ''}" onclick="setDriverPayFilter('all')">Semua Trip</button>
                <button class="pay-filter-btn ${driverPayFilter === 'unpaid' ? 'active' : ''}" onclick="setDriverPayFilter('unpaid')">Belum Dibayar</button>
                <button class="pay-filter-btn ${driverPayFilter === 'paid' ? 'active' : ''}" onclick="setDriverPayFilter('paid')">Sudah Dibayar</button>
            </div>
        `;

        lastDriverRecap.forEach((dr, idx) => {
            const rows = (dr.detail_rows || []).filter(d => {
                if (driverPayFilter === 'unpaid') return !d.paid;
                if (driverPayFilter === 'paid') return d.paid;
                return true;
            });

            if (rows.length === 0 && driverPayFilter !== 'all') return;

            const driverNameSafe = (dr.partner_owner || 'Tanpa Pemilik').replace(/'/g, "\\'");

            const detailRows = rows.slice(0, 15).map(d => `
                <tr>
                    <td>${new Date(d.trx_date).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })}</td>
                    <td>${typeLabel[d.service_type] || d.service_type}<br><span style="color:#94a0b8;">${d.label || ''}</span></td>
                    <td>${d.guest_name || '—'}${d.room_number ? '<br><span style="color:#94a0b8;">Kamar ' + d.room_number + '</span>' : ''}</td>
                    <td style="text-align:right;font-weight:700;">Rp ${formatNumber(d.total_price)}</td>
                    <td style="text-align:right;font-weight:700;color:#16794d;">Rp ${formatNumber(d.owner_amount)}</td>
                    <td style="text-align:right;">
                        <div style="display:flex;gap:4px;justify-content:flex-end;align-items:center;flex-wrap:wrap;">
                            <button class="btn-trip-edit" onclick="editDriverTripAmount(${d.trip_id}, '${d.source || 'trip'}', ${d.total_price}, ${d.owner_amount}, '${driverNameSafe}', '${typeLabel[d.service_type] || d.service_type}')">✏️ Edit</button>
                            ${d.paid
                                ? '<span class="btn-trip-paid">✅ Lunas</span>'
                                : `<button class="btn-trip-pay" onclick="payDriverTrip(${d.trip_id}, '${d.service_type}', ${d.owner_amount}, '${driverNameSafe}', '${d.source || 'trip'}')">Bayar</button>`
                            }
                        </div>
                    </td>
                </tr>`).join('');

            html += `
                <div class="driver-recap-card">
                    <div class="dr-name">
                        <span>🤝 ${dr.partner_owner || 'Tanpa Pemilik'}${dr.owner_phone ? ' <span style="font-weight:400;color:#6b7690;font-size:11px;">&middot; ' + dr.owner_phone + '</span>' : ''}</span>
                        <button class="btn-print-recap" onclick="printDriverRecap(${idx})">🖨️ Cetak Rekap</button>
                    </div>
                    <div class="dr-stats">
                        <div class="dr-stat"><div class="v">${dr.total_trips}</div><div class="l">Trip</div></div>
                        <div class="dr-stat"><div class="v">Rp ${formatNumber(dr.total_revenue)}</div><div class="l">Total Revenue</div></div>
                        <div class="dr-stat"><div class="v">Rp ${formatNumber(dr.owner_total)}</div><div class="l">Bagian Pemilik (${Math.round(dr.avg_comm_pct)}%)</div></div>
                        <div class="dr-stat"><div class="v">Rp ${formatNumber(dr.hotel_total)}</div><div class="l">Komisi Hotel</div></div>
                    </div>
                    <div class="dr-paid-summary">
                        <span>✅ Sudah Dibayar: <strong style="color:#16794d;">Rp ${formatNumber(dr.paid_total || 0)}</strong> (${dr.paid_trips || 0} trip)</span>
                        <span>⏳ Belum Dibayar: <strong style="color:#d97706;">Rp ${formatNumber(dr.unpaid_total || 0)}</strong> (${dr.unpaid_trips || 0} trip)</span>
                    </div>
                    <div class="dr-breakdown">
                        <div class="dr-stat"><div class="v">${dr.rental_trips}</div><div class="l">Rental Mobil</div></div>
                        <div class="dr-stat"><div class="v">${dr.airport_trips}</div><div class="l">Airport Drop</div></div>
                        <div class="dr-stat"><div class="v">${dr.harbor_trips}</div><div class="l">Harbor Drop</div></div>
                    </div>
                    ${detailRows ? `
                    <div style="font-size:11px;font-weight:700;color:#475569;margin-top:8px;">Detail Transaksi</div>
                    <table>
                        <thead><tr><th>Tanggal</th><th>Jenis</th><th>Tamu</th><th style="text-align:right;">Total</th><th style="text-align:right;">Pemilik</th><th style="text-align:right;">Aksi</th></tr></thead>
                        <tbody>${detailRows}</tbody>
                    </table>` : '<p style="color:#999;font-size:11px;text-align:center;padding:8px;">Tidak ada trip dengan filter ini</p>'}
                </div>`;
        });

        recapEl.innerHTML = html;
    }

    // PRINT A DRIVER'S FULL TRIP RECAP (so the driver can carry a physical copy)
    function printDriverRecap(idx) {
        const dr = lastDriverRecap[idx];
        if (!dr) return;

        const typeLabel = {
            car_rental: 'Rental Mobil',
            airport_drop: 'Airport Drop',
            harbor_drop: 'Harbor Drop'
        };
        const monthVal = document.getElementById('filterMonth').value;
        const monthLabel = monthVal ?
            new Date(monthVal + '-01').toLocaleDateString('id-ID', {
                month: 'long',
                year: 'numeric'
            }) :
            '';
        const rows = dr.detail_rows || [];

        const rowsHtml = rows.map((d, i) => `
            <tr>
                <td>${i + 1}</td>
                <td>${new Date(d.trx_date).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })}</td>
                <td>${typeLabel[d.service_type] || d.service_type}<br><small>${d.label || ''}</small></td>
                <td>${d.guest_name || '—'}${d.room_number ? ' (Kamar ' + d.room_number + ')' : ''}</td>
                <td style="text-align:right;">Rp ${formatNumber(d.total_price)}</td>
                <td style="text-align:right;">Rp ${formatNumber(d.owner_amount)}</td>
                <td style="text-align:center;">${d.paid ? 'Lunas' : 'Belum'}</td>
            </tr>`).join('');

        const printWindow = window.open('', '_blank', 'width=900,height=700');
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>Rekap Trip - ${dr.partner_owner || 'Tanpa Pemilik'}</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 24px; color: #1a2540; }
                    h1 { font-size: 18px; margin-bottom: 2px; }
                    .sub { color: #6b7690; font-size: 12px; margin-bottom: 16px; }
                    .summary { display: flex; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
                    .summary div { border: 1px solid #e2e6ee; border-radius: 6px; padding: 8px 14px; font-size: 12px; }
                    .summary b { display: block; font-size: 15px; }
                    table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
                    th, td { border: 1px solid #d8dee8; padding: 5px 6px; }
                    th { background: #f3f5fb; text-align: left; }
                    small { color: #6b7690; }
                    .footer { margin-top: 28px; display: flex; justify-content: space-between; font-size: 12px; }
                    .footer div { text-align: center; width: 200px; }
                    .footer .line { margin-top: 48px; border-top: 1px solid #333; padding-top: 4px; }
                    @media print { .no-print { display: none; } }
                </style>
            </head>
            <body>
                <button class="no-print" onclick="window.print()" style="float:right;padding:6px 14px;">Cetak</button>
                <h1>Rekap Trip Driver / Mitra</h1>
                <div class="sub">${dr.partner_owner || 'Tanpa Pemilik'}${dr.owner_phone ? ' · ' + dr.owner_phone : ''} &mdash; Periode ${monthLabel}</div>
                <div class="summary">
                    <div><b>${dr.total_trips}</b>Total Trip</div>
                    <div><b>Rp ${formatNumber(dr.total_revenue)}</b>Total Revenue</div>
                    <div><b>Rp ${formatNumber(dr.owner_total)}</b>Bagian Pemilik</div>
                    <div><b>Rp ${formatNumber(dr.paid_total || 0)}</b>Sudah Dibayar</div>
                    <div><b>Rp ${formatNumber(dr.unpaid_total || 0)}</b>Belum Dibayar</div>
                </div>
                <table>
                    <thead>
                        <tr><th>#</th><th>Tanggal</th><th>Jenis</th><th>Tamu</th><th style="text-align:right;">Total</th><th style="text-align:right;">Bagian Pemilik</th><th style="text-align:center;">Status</th></tr>
                    </thead>
                    <tbody>${rowsHtml || '<tr><td colspan="7" style="text-align:center;color:#999;">Tidak ada trip bulan ini</td></tr>'}</tbody>
                </table>
                <div class="footer">
                    <div>Driver / Mitra<div class="line">${dr.partner_owner || ''}</div></div>
                    <div>Hotel<div class="line">Frontdesk</div></div>
                </div>
            </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.focus();
    }

    // PAY A SINGLE DRIVER TRIP (marks trip as paid + auto-syncs to buku kas)
    let pendingPayTrip = null;
    let pendingPayMethod = 'cash';

    function payDriverTrip(tripId, sourceType, amount, driverName, source = 'trip') {
        pendingPayTrip = {
            tripId,
            sourceType,
            amount,
            driverName,
            source
        };
        pendingPayMethod = 'cash';

        document.getElementById('ptDriverName').textContent = driverName;
        document.getElementById('ptAmount').textContent = 'Rp ' + formatNumber(amount);
        document.querySelectorAll('.dp-method-btn').forEach(b => b.classList.toggle('active', b.dataset.method === 'cash'));
        document.getElementById('ptConfirmBtn').disabled = false;

        document.getElementById('payTripModalOverlay').classList.add('open');
    }

    function selectPayMethod(method) {
        pendingPayMethod = method;
        document.querySelectorAll('.dp-method-btn').forEach(b => b.classList.toggle('active', b.dataset.method === method));
    }

    function closePayTripModal() {
        document.getElementById('payTripModalOverlay').classList.remove('open');
        pendingPayTrip = null;
    }

    async function confirmPayDriverTrip() {
        if (!pendingPayTrip) return;
        const {
            tripId,
            sourceType,
            driverName,
            source
        } = pendingPayTrip;
        const cashAccountId = document.getElementById('ptCashAccount').value || '1';

        const confirmBtn = document.getElementById('ptConfirmBtn');
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Memproses...';

        const formData = new FormData();
        formData.append('trip_id', tripId);
        formData.append('source_type', sourceType);
        formData.append('source', source);
        formData.append('payment_method', pendingPayMethod);
        formData.append('cash_account_id', cashAccountId);
        formData.append('driver_name', driverName);
        formData.append('business', ACTIVE_BUSINESS);

        try {
            const response = await fetch(BASE_URL + '/api/pay-driver-trip.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            });

            const result = await response.json();

            if (result.success) {
                closePayTripModal();
                loadDriverRecap();
            } else {
                alert(`❌ ${result.message}`);
                confirmBtn.disabled = false;
                confirmBtn.textContent = '✅ Bayar & Catat ke Kas';
            }
        } catch (error) {
            alert(`❌ Error: ${error.message}`);
            confirmBtn.disabled = false;
            confirmBtn.textContent = '✅ Bayar & Catat ke Kas';
        }
    }

    // EDIT DRIVER TRIP AMOUNT
    let pendingEditTrip = null;

    function editDriverTripAmount(tripId, source, totalPrice, ownerAmount, driverName, tripLabel) {
        pendingEditTrip = { tripId, source };
        document.getElementById('etDriverName').textContent = driverName || '-';
        document.getElementById('etTripLabel').textContent = tripLabel || '-';
        document.getElementById('etTotalPrice').value = totalPrice;
        document.getElementById('etOwnerAmount').value = ownerAmount;
        document.getElementById('etConfirmBtn').disabled = false;
        document.getElementById('etConfirmBtn').textContent = '💾 Simpan';
        updateEditCompanyAmount();
        document.getElementById('editTripModalOverlay').classList.add('open');
    }

    function updateEditCompanyAmount() {
        const total = parseFloat(document.getElementById('etTotalPrice').value) || 0;
        const owner = parseFloat(document.getElementById('etOwnerAmount').value) || 0;
        document.getElementById('etCompanyAmount').textContent = 'Rp ' + formatNumber(Math.max(0, total - owner));
    }

    function closeEditTripModal() {
        document.getElementById('editTripModalOverlay').classList.remove('open');
        pendingEditTrip = null;
    }

    async function confirmEditDriverTrip() {
        if (!pendingEditTrip) return;
        const totalPrice = parseFloat(document.getElementById('etTotalPrice').value);
        const ownerAmount = parseFloat(document.getElementById('etOwnerAmount').value);

        if (isNaN(totalPrice) || totalPrice < 0) { alert('Total tarif tidak valid'); return; }
        if (isNaN(ownerAmount) || ownerAmount < 0) { alert('Bagian pemilik tidak valid'); return; }
        if (ownerAmount > totalPrice) { alert('Bagian pemilik tidak boleh melebihi total tarif'); return; }

        const btn = document.getElementById('etConfirmBtn');
        btn.disabled = true;
        btn.textContent = 'Menyimpan...';

        const fd = new FormData();
        fd.append('trip_id', pendingEditTrip.tripId);
        fd.append('source', pendingEditTrip.source);
        fd.append('total_price', totalPrice);
        fd.append('owner_amount', ownerAmount);

        try {
            const res = await fetch(BASE_URL + '/api/edit-driver-trip-amount.php', { method: 'POST', body: fd, credentials: 'include' });
            const result = await res.json();
            if (result.success) {
                closeEditTripModal();
                loadDriverRecap();
            } else {
                alert('❌ ' + result.message);
                btn.disabled = false;
                btn.textContent = '💾 Simpan';
            }
        } catch (err) {
            alert('❌ Error: ' + err.message);
            btn.disabled = false;
            btn.textContent = '💾 Simpan';
        }
    }

    // SWITCH PAY FILTER (Semua / Belum Dibayar / Sudah Dibayar)
    function setDriverPayFilter(status) {
        driverPayFilter = status;
        renderDriverRecap();
    }

    // SWITCH TABS
    function switchTab(tab, event) {
        event.preventDefault();
        currentTab = tab;
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        event.target.classList.add('active');
        loadBills();
    }

    // FORMAT NUMBER
    function formatNumber(num) {
        return new Intl.NumberFormat('id-ID').format(num);
    }

    // EDIT BILL (placeholder)
    function editBill(billId) {
        alert(`Edit bill ${billId} - Coming soon!`);
    }

    // OPEN PAYMENT MODAL
    function openPayment(billId, billName, amount, paidAmount) {
        const remaining = amount - paidAmount;
        const paymentAmount = prompt(
            `Bayar tagihan: ${billName}\n\nJumlah tagihan: Rp ${formatNumber(amount)}\nSudah dibayar: Rp ${formatNumber(paidAmount)}\nSisa: Rp ${formatNumber(remaining)}\n\nBerapa yang mau dibayar?`,
            remaining
        );

        if (paymentAmount === null) return;

        const paymentValue = parseFloat(paymentAmount);
        if (isNaN(paymentValue) || paymentValue <= 0) {
            alert('Jumlah tidak valid');
            return;
        }

        if (paymentValue > remaining) {
            alert(`Pembayaran melebihi sisa tagihan!\nSisa: Rp ${formatNumber(remaining)}`);
            return;
        }

        const paymentMethod = prompt('Metode pembayaran? (cash, transfer, card, other)', 'cash');
        if (!paymentMethod) return;

        const cashAccountId = prompt('Dari rekening mana? (1=Kas Tunai, 2=Bank Utama, dst)\nBiarkan kosong jika default', '1');
        if (cashAccountId === null) return;

        recordPayment(billId, paymentValue, paymentMethod, cashAccountId || '1');
    }

    // RECORD PAYMENT
    async function recordPayment(billId, amount, method, accountId) {
        const formData = new FormData();
        formData.append('bill_id', billId);
        formData.append('amount', amount);
        formData.append('payment_method', method);
        formData.append('cash_account_id', accountId);
        formData.append('business', ACTIVE_BUSINESS);

        try {
            const response = await fetch(BASE_URL + '/api/pay-monthly-bill.php', {
                method: 'POST',
                body: formData,
                credentials: 'include' // Include cookies for authentication
            });

            const result = await response.json();

            if (result.success) {
                alert(`✅ ${result.message}\nStatus: ${result.bill_status}\nSisa: Rp ${formatNumber(result.remaining)}`);
                loadBills();
            } else {
                alert(`❌ ${result.message}`);
            }
        } catch (error) {
            alert(`❌ Error: ${error.message}`);
        }
    }

    // Load on page load
    window.addEventListener('load', () => {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('cat') === 'driver') {
            switchCategory('driver');
        } else {
            loadBills();
        }
    });
</script>

<?php include '../../includes/footer.php'; ?>