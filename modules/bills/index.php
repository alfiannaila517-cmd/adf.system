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
        padding: 25px;
    }

    .card h2 {
        font-size: 16px;
        color: #333;
        margin-bottom: 20px;
        border-bottom: 2px solid var(--navy);
        padding-bottom: 10px;
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
        background: #f8f9fa;
        padding: 15px;
        border-radius: 10px;
        margin-bottom: 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-left: 4px solid var(--navy);
        transition: box-shadow .2s;
    }

    .bill-row:hover {
        box-shadow: 0 4px 12px rgba(13, 31, 60, 0.1);
    }

    .bill-info h4 {
        font-size: 14px;
        color: #333;
        margin-bottom: 5px;
    }

    .bill-info p {
        font-size: 12px;
        color: #666;
        margin: 2px 0;
    }

    .bill-amount {
        text-align: right;
    }

    .bill-amount .total {
        font-size: 16px;
        font-weight: 700;
        color: #333;
    }

    .bill-amount .status {
        font-size: 11px;
        margin-top: 5px;
        padding: 3px 8px;
        border-radius: 3px;
        display: inline-block;
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
        padding: 6px 12px;
        margin-left: 5px;
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 600;
    }

    .btn-pay {
        background: #28a745;
        color: white;
    }

    .btn-pay:hover {
        background: #218838;
    }

    .btn-edit {
        background: var(--navy);
        color: white;
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
                        <label for="category">Kategori</label>
                        <select id="category" name="category_id">
                            <option value="">-- Pilih Kategori --</option>
                            <option value="1">Biaya Utilitas</option>
                            <option value="2">Biaya Gaji</option>
                            <option value="3">Biaya Sewa</option>
                            <option value="4">Biaya Operasional</option>
                            <option value="5">Lainnya</option>
                        </select>
                    </div>
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
                    onchange="loadBills()"
                    style="width: 150px; padding: 8px; border: 1px solid #ddd; border-radius: 5px; margin-top: 5px;">
            </div>

            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('all', event)">Semua</button>
                <button class="tab-btn" onclick="switchTab('pending', event)">Pending</button>
                <button class="tab-btn" onclick="switchTab('partial', event)">Cicilan</button>
                <button class="tab-btn" onclick="switchTab('paid', event)">Lunas</button>
            </div>

            <div id="driverProfitSummary" style="display:none; margin: 15px 0; padding: 14px 16px; background: linear-gradient(135deg,#1a2540,#101a30); border-radius: 10px; color: #fff;">
                <div style="font-size: 13px; opacity:.85; margin-bottom: 8px;">🚗 Ringkasan Keuntungan Driver/Mitra (Bulan Ini)</div>
                <div style="display:flex; gap: 18px; flex-wrap: wrap;">
                    <div>
                        <div style="font-size: 11px; opacity:.7;">Total Sewa</div>
                        <div id="dpTripTotal" style="font-size: 16px; font-weight: 700;">Rp 0</div>
                    </div>
                    <div>
                        <div style="font-size: 11px; opacity:.7;">Dibayar ke Mitra</div>
                        <div id="dpDriverTotal" style="font-size: 16px; font-weight: 700; color:#ffb84d;">Rp 0</div>
                    </div>
                    <div>
                        <div style="font-size: 11px; opacity:.7;">Keuntungan Hotel</div>
                        <div id="dpProfitTotal" style="font-size: 16px; font-weight: 700; color:#5dd97a;">Rp 0</div>
                    </div>
                </div>
            </div>

            <div id="billsList" class="bill-list">
                <p style="color: #999; text-align: center; padding: 40px 20px;">Loading...</p>
            </div>
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
            document.getElementById('driverProfitSummary').style.display = 'none';
            return;
        }

        try {
            const url = BASE_URL + `/api/get-monthly-bills.php?month=${month}&limit=50`;
            console.log('[Bills] Fetching from:', url);
            console.log('[Bills] Active business:', ACTIVE_BUSINESS);

            const response = await fetch(url, {
                method: 'GET',
                credentials: 'include'  // Include cookies for session
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
                document.getElementById('driverProfitSummary').style.display = 'none';
                return;
            }

            if (!result.bills || result.bills.length === 0) {
                listEl.innerHTML = '<p style="color: #999; text-align: center; padding: 40px;">Tidak ada tagihan bulan ini</p>';
                document.getElementById('driverProfitSummary').style.display = 'none';
                return;
            }

            updateDriverProfitSummary(result.bills);

            // Filter by current tab
            let filtered = result.bills;
            if (currentTab !== 'all') {
                filtered = result.bills.filter(b => b.status === currentTab);
            }

            if (filtered.length === 0) {
                listEl.innerHTML = `<p style="color: #999; text-align: center; padding: 40px;">Tidak ada tagihan dengan status ini</p>`;
                return;
            }

            let html = '';
            filtered.forEach(bill => {
                const statusClass = `status-${bill.status}`;
                const progress = bill.amount > 0 ? Math.round((bill.paid_amount / bill.amount) * 100) : 0;
                const isDriverTrip = bill.source_type === 'driver_trip' && bill.trip_total !== null;
                const driverBreakdown = isDriverTrip ? `
                        <p style="font-size: 12px; color: #555; margin-top: 4px;">
                            Sewa: <strong>Rp ${formatNumber(bill.trip_total)}</strong>
                            &middot; Bayar Mitra: <strong>Rp ${formatNumber(bill.amount)}</strong>
                            &middot; Untung Hotel: <strong style="color:#2e7d32;">Rp ${formatNumber(bill.hotel_profit || 0)}</strong>
                        </p>` : '';

                html += `
                <div class="bill-row">
                    <div class="bill-info">
                        <h4>${bill.bill_name} <small>(${bill.bill_code})</small></h4>
                        <p><strong>${bill.category_name || 'Umum'}</strong></p>
                        <p>Rp ${formatNumber(bill.paid_amount)} / Rp ${formatNumber(bill.amount)}</p>
                        ${driverBreakdown}
                        <div style="margin-top: 5px; background: #eee; height: 6px; border-radius: 3px; overflow: hidden; width: 100%;">
                            <div style="background: var(--navy); height: 100%; width: ${progress}%;"></div>
                        </div>
                    </div>
                    <div style="text-align: right; white-space: nowrap;">
                        <div class="bill-amount">
                            <div class="total">Rp ${formatNumber(bill.amount)}</div>
                            <span class="status ${statusClass}">${bill.status.toUpperCase()}</span>
                        </div>
                        <div style="margin-top: 10px;">
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
            document.getElementById('driverProfitSummary').style.display = 'none';
        }
    }

    // DRIVER/MITRA PROFIT SUMMARY (dari tagihan auto-generate Hotel Service)
    function updateDriverProfitSummary(bills) {
        const box = document.getElementById('driverProfitSummary');
        const driverBills = (bills || []).filter(b => b.source_type === 'driver_trip' && b.trip_total !== null);

        if (driverBills.length === 0) {
            box.style.display = 'none';
            return;
        }

        let tripTotal = 0, driverTotal = 0, profitTotal = 0;
        driverBills.forEach(b => {
            tripTotal += Number(b.trip_total) || 0;
            driverTotal += Number(b.amount) || 0;
            profitTotal += Number(b.hotel_profit) || 0;
        });

        document.getElementById('dpTripTotal').textContent = 'Rp ' + formatNumber(tripTotal);
        document.getElementById('dpDriverTotal').textContent = 'Rp ' + formatNumber(driverTotal);
        document.getElementById('dpProfitTotal').textContent = 'Rp ' + formatNumber(profitTotal);
        box.style.display = 'block';
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
        loadBills();
    });
</script>

<?php include '../../includes/footer.php'; ?>