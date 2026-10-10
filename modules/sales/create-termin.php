<?php
/**
 * CQC Create Termin Invoice
 * Form untuk membuat faktur termin berdasarkan proyek
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
    // Ensure termin table exists
    ensureCQCTerminTable($pdo);
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

$currentUser = $auth->getCurrentUser();
$error = '';
$success = '';

// Get all projects with their contract values
$projects = $pdo->query("
    SELECT id, project_code, project_name, client_name, client_phone, client_email, 
           budget_idr as contract_value, solar_capacity_kwp
    FROM cqc_projects 
    WHERE status != 'completed'
    ORDER BY project_name
")->fetchAll(PDO::FETCH_ASSOC);

// Load quotation data if creating termin from a quotation
$fromQuotationId = isset($_GET['from_quotation']) ? (int)$_GET['from_quotation'] : 0;
$fromQuotation = null;
$preSelectedProjectId = 0;
$preContractValue = '';
$prePpnPercentage = 11;
$preDescription = '';

if ($fromQuotationId > 0) {
    try {
        ensureCQCQuotationTable($pdo);
        $stmtQ = $pdo->prepare("SELECT * FROM cqc_quotations WHERE id = ?");
        $stmtQ->execute([$fromQuotationId]);
        $fromQuotation = $stmtQ->fetch(PDO::FETCH_ASSOC);

        if ($fromQuotation) {
            // Pre-fill contract value from quotation subtotal (DPP before tax)
            $baseVal = floatval($fromQuotation['subtotal'] ?: ($fromQuotation['total_amount'] ?? 0));
            $preContractValue = number_format($baseVal, 0, ',', '.');
            $prePpnPercentage = floatval($fromQuotation['ppn_percentage'] ?? 11);
            $preDescription = "Pembayaran Termin 1 - " . ($fromQuotation['subject'] ?: $fromQuotation['quote_number']);

            // Match project by client name (case-insensitive substring match)
            $quotClient = strtolower(trim($fromQuotation['client_name'] ?? ''));
            foreach ($projects as $proj) {
                $projClient = strtolower(trim($proj['client_name']));
                if ($quotClient && ($quotClient === $projClient
                    || strpos($projClient, $quotClient) !== false
                    || strpos($quotClient, $projClient) !== false)) {
                    $preSelectedProjectId = $proj['id'];
                    break;
                }
            }
            // Fallback: match by phone number
            if (!$preSelectedProjectId && !empty($fromQuotation['client_phone'])) {
                $quotPhone = preg_replace('/\D/', '', $fromQuotation['client_phone']);
                foreach ($projects as $proj) {
                    $projPhone = preg_replace('/\D/', '', $proj['client_phone'] ?? '');
                    if ($quotPhone && $projPhone && strpos($projPhone, $quotPhone) !== false) {
                        $preSelectedProjectId = $proj['id'];
                        break;
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Ignore quotation load errors; form still works normally
    }
}

// Also support ?project_id=X direct pre-selection (e.g. from Pelunasan button)
if (!$preSelectedProjectId && isset($_GET['project_id'])) {
    $directProjId = (int)$_GET['project_id'];
    foreach ($projects as $proj) {
        if ((int)$proj['id'] === $directProjId) {
            $preSelectedProjectId = $directProjId;
            if (!$preContractValue) {
                $preContractValue = number_format(floatval($proj['contract_value']), 0, ',', '.');
            }
            // Suggest description for pelunasan
            if (!$preDescription) {
                $preDescription = "Pembayaran Pelunasan - " . htmlspecialchars($proj['project_name']);
            }
            break;
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $project_id = (int)$_POST['project_id'];
        $termin_number = (int)$_POST['termin_number'];
        $invoice_date = $_POST['invoice_date'];
        $due_date = $_POST['due_date'] ?: null;
        $description = trim($_POST['description']);
        $contract_value = floatval(str_replace(['.', ','], ['', '.'], $_POST['contract_value']));
        $percentage = floatval($_POST['percentage']);
        $ppn_percentage = floatval($_POST['ppn_percentage']);
        $pph_percentage = floatval($_POST['pph_percentage']);
        $retention_percentage = floatval($_POST['retention_percentage']);
        $notes = trim($_POST['notes']);
        
        // Validate
        if (!$project_id || !$termin_number || !$invoice_date || $percentage <= 0) {
            throw new Exception("Mohon lengkapi semua field yang wajib diisi.");
        }
        
        // Get project info
        $stmt = $pdo->prepare("SELECT project_code FROM cqc_projects WHERE id = ?");
        $stmt->execute([$project_id]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$project) {
            throw new Exception("Proyek tidak ditemukan.");
        }
        
        // Generate invoice number
        $year = date('Y');
        $month = date('m');
        $stmt = $pdo->prepare("SELECT COUNT(*) + 1 as next_num FROM cqc_termin_invoices WHERE YEAR(created_at) = ?");
        $stmt->execute([$year]);
        $nextNum = $stmt->fetch()['next_num'];
        $invoice_number = sprintf("INV/%s/%s/%03d/CQC", $year, $month, $nextNum);
        
        // Calculate amounts
        $base_amount = ($contract_value * $percentage) / 100;
        $ppn_amount = ($base_amount * $ppn_percentage) / 100;
        $pph_amount = ($base_amount * $pph_percentage) / 100;
        $retention_amount = ($base_amount * $retention_percentage) / 100;
        $total_amount = $base_amount + $ppn_amount - $pph_amount - $retention_amount;
        
        // Insert invoice
        $stmt = $pdo->prepare("
            INSERT INTO cqc_termin_invoices (
                invoice_number, project_id, termin_number, invoice_date, due_date, description,
                contract_value, percentage, base_amount, 
                ppn_percentage, ppn_amount, pph_percentage, pph_amount,
                retention_percentage, retention_amount, total_amount,
                payment_status, notes, created_by
            ) VALUES (
                :invoice_number, :project_id, :termin_number, :invoice_date, :due_date, :description,
                :contract_value, :percentage, :base_amount,
                :ppn_percentage, :ppn_amount, :pph_percentage, :pph_amount,
                :retention_percentage, :retention_amount, :total_amount,
                'draft', :notes, :created_by
            )
        ");
        
        $stmt->execute([
            'invoice_number' => $invoice_number,
            'project_id' => $project_id,
            'termin_number' => $termin_number,
            'invoice_date' => $invoice_date,
            'due_date' => $due_date,
            'description' => $description ?: "Pembayaran Termin $termin_number",
            'contract_value' => $contract_value,
            'percentage' => $percentage,
            'base_amount' => $base_amount,
            'ppn_percentage' => $ppn_percentage,
            'ppn_amount' => $ppn_amount,
            'pph_percentage' => $pph_percentage,
            'pph_amount' => $pph_amount,
            'retention_percentage' => $retention_percentage,
            'retention_amount' => $retention_amount,
            'total_amount' => $total_amount,
            'notes' => $notes,
            'created_by' => $currentUser['id']
        ]);
        
        // Update quotation status to 'approved' if invoice created from quotation
        if (!empty($_POST['from_quotation_id'])) {
            $fqId = (int)$_POST['from_quotation_id'];
            if ($fqId > 0) {
                try {
                    $pdo->prepare("UPDATE cqc_quotations SET status = 'approved' WHERE id = ?")
                        ->execute([$fqId]);
                } catch (Exception $e) { /* ignore status update error */ }
            }
        }

        $_SESSION['success'] = "Invoice $invoice_number berhasil dibuat!";
        header('Location: index-cqc.php');
        exit;
        
    } catch (PDOException $e) {
        error_log("CQC Invoice Save Error: " . $e->getMessage());
        $error = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get existing termins for selected project (via AJAX will be better, but simple approach here)
$existingTermins = [];
if (isset($_GET['project_id']) && $_GET['project_id'] > 0) {
    $stmt = $pdo->prepare("SELECT termin_number, percentage, total_amount, payment_status FROM cqc_termin_invoices WHERE project_id = ? ORDER BY termin_number");
    $stmt->execute([$_GET['project_id']]);
    $existingTermins = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = "Buat Invoice";
include '../../includes/header.php';
?>

<style>
    :root {
        --cqc-primary: #0d1f3c;
        --cqc-primary-light: #1a3a5c;
        --cqc-accent: #f0b429;
        --cqc-accent-dark: #d4960d;
        --cqc-success: #10b981;
        --cqc-danger: #ef4444;
        --cqc-text: #0d1f3c;
        --cqc-muted: #64748b;
        --cqc-border: #e2e8f0;
        --cqc-bg: #f8fafc;
    }

    .cqc-container { max-width: 900px; margin: 0 auto; font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }

    .cqc-header {
        background: #fff; padding: 16px 20px; border-radius: 12px; margin-bottom: 20px;
        display: flex; justify-content: space-between; align-items: center;
        border: 1px solid var(--cqc-border); border-left: 4px solid var(--cqc-accent);
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }
    .cqc-header h1 { font-size: 18px; font-weight: 700; color: var(--cqc-primary); margin: 0 0 4px; }
    .cqc-header p { font-size: 12px; margin: 0; color: var(--cqc-muted); }
    .cqc-header .btn-back {
        background: #fff; color: var(--cqc-muted); border: 1px solid var(--cqc-border);
        padding: 8px 16px; border-radius: 6px; font-weight: 600; font-size: 12px;
        text-decoration: none; display: flex; align-items: center; gap: 6px;
    }
    .cqc-header .btn-back:hover { background: var(--cqc-bg); }

    .cqc-form-card {
        background: #fff; border-radius: 12px; border: 1px solid var(--cqc-border);
        box-shadow: 0 2px 8px rgba(0,0,0,0.06); overflow: hidden;
    }
    .cqc-form-section {
        padding: 20px; border-bottom: 1px solid var(--cqc-border);
    }
    .cqc-form-section:last-child { border-bottom: none; }
    .cqc-section-title {
        font-size: 13px; font-weight: 700; color: var(--cqc-primary); margin-bottom: 16px;
        display: flex; align-items: center; gap: 8px;
    }
    .cqc-section-title span {
        width: 24px; height: 24px; background: var(--cqc-bg); border-radius: 6px;
        display: flex; align-items: center; justify-content: center; font-size: 12px;
    }

    .cqc-form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
    .cqc-form-grid.cols-3 { grid-template-columns: repeat(3, 1fr); }
    .cqc-form-grid.cols-4 { grid-template-columns: repeat(4, 1fr); }
    .cqc-form-full { grid-column: 1 / -1; }

    .cqc-form-group { margin-bottom: 0; }
    .cqc-form-label {
        display: block; font-size: 11px; font-weight: 600; color: var(--cqc-muted);
        margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.3px;
    }
    .cqc-form-label .required { color: var(--cqc-danger); }

    .cqc-form-input, .cqc-form-select, .cqc-form-textarea {
        width: 100%; padding: 10px 12px; border: 1px solid var(--cqc-border);
        border-radius: 8px; font-size: 13px; color: var(--cqc-text);
        background: #fff; transition: all 0.15s;
    }
    .cqc-form-input:focus, .cqc-form-select:focus, .cqc-form-textarea:focus {
        border-color: var(--cqc-accent); outline: none;
        box-shadow: 0 0 0 3px rgba(240,180,41,0.15);
    }
    .cqc-form-input.amount {
        font-weight: 700; text-align: right; font-size: 14px;
    }

    /* Calculation Preview */
    .cqc-calc-preview {
        background: linear-gradient(135deg, var(--cqc-bg), #fff);
        border: 1px solid var(--cqc-border); border-radius: 10px;
        padding: 16px; margin-top: 16px;
    }
    .cqc-calc-row {
        display: flex; justify-content: space-between; align-items: center;
        padding: 8px 0; font-size: 12px;
    }
    .cqc-calc-row.highlight {
        background: rgba(240,180,41,0.1); margin: 0 -16px; padding: 12px 16px;
        border-radius: 8px; font-size: 14px;
    }
    .cqc-calc-label { color: var(--cqc-muted); }
    .cqc-calc-value { font-weight: 600; color: var(--cqc-text); }
    .cqc-calc-value.total { font-size: 18px; font-weight: 800; color: var(--cqc-primary); }
    .cqc-calc-value.minus { color: var(--cqc-danger); }
    .cqc-calc-value.plus { color: var(--cqc-success); }

    /* Buttons */
    .cqc-form-actions {
        display: flex; gap: 12px; padding: 20px; background: var(--cqc-bg);
    }
    .cqc-btn {
        padding: 12px 24px; border-radius: 8px; font-weight: 700; font-size: 13px;
        cursor: pointer; transition: all 0.2s; border: none;
    }
    .cqc-btn-primary {
        background: var(--cqc-accent); color: var(--cqc-primary); flex: 2;
        box-shadow: 0 2px 8px rgba(240,180,41,0.3);
    }
    .cqc-btn-primary:hover { background: #ffc942; transform: translateY(-1px); }
    .cqc-btn-secondary {
        background: #fff; color: var(--cqc-muted); border: 1px solid var(--cqc-border); flex: 1;
    }
    .cqc-btn-secondary:hover { background: var(--cqc-bg); }

    /* Error Alert */
    .cqc-alert-error {
        background: #fef2f2; border-left: 4px solid var(--cqc-danger);
        padding: 14px 16px; border-radius: 8px; margin-bottom: 16px;
        color: #991b1b; font-size: 13px;
    }

    /* Project Info Card */
    .cqc-project-info {
        display: none; background: linear-gradient(135deg, rgba(13,31,60,0.03), rgba(240,180,41,0.05));
        border: 1px solid var(--cqc-border); border-radius: 10px; padding: 16px; margin-top: 16px;
    }
    .cqc-project-info.show { display: block; }
    .cqc-project-info-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
    .cqc-project-info-item label { font-size: 10px; color: var(--cqc-muted); text-transform: uppercase; }
    .cqc-project-info-item div { font-size: 13px; font-weight: 600; color: var(--cqc-text); margin-top: 2px; }

    @media (max-width: 768px) {
        .cqc-form-grid, .cqc-form-grid.cols-3, .cqc-form-grid.cols-4 { grid-template-columns: 1fr; }
    }
</style>
<style>
/* dark-theme-overrides */
body:not([data-theme="light"]) {
    --cqc-primary: #f1f5f9;
    --cqc-text: #e2e8f0;
    --cqc-muted: #94a3b8;
    --cqc-border: rgba(148,163,184,.2);
    --cqc-bg: #18233a;
}
body:not([data-theme="light"]) .cqc-header,
body:not([data-theme="light"]) .cqc-form-card { background: #111a2e; }
body:not([data-theme="light"]) .cqc-header .btn-back,
body:not([data-theme="light"]) .cqc-btn-secondary { background: #18233a; color: #cbd5e1; }
body:not([data-theme="light"]) .cqc-header .btn-back:hover,
body:not([data-theme="light"]) .cqc-btn-secondary:hover { background: #22304d; }
body:not([data-theme="light"]) .cqc-form-input,
body:not([data-theme="light"]) .cqc-form-select,
body:not([data-theme="light"]) .cqc-form-textarea { background: #0f172a; color: #e2e8f0; border-color: rgba(148,163,184,.25); }
body:not([data-theme="light"]) .cqc-form-input::placeholder,
body:not([data-theme="light"]) .cqc-form-textarea::placeholder { color: #64748b; }
body:not([data-theme="light"]) .cqc-form-select option { background: #0f172a; color: #e2e8f0; }
body:not([data-theme="light"]) .cqc-calc-preview { background: linear-gradient(135deg, #18233a, #111a2e); }
body:not([data-theme="light"]) .cqc-btn-primary { color: #0d1f3c; }
body:not([data-theme="light"]) .cqc-alert-error { background: rgba(239,68,68,.15); color: #fca5a5; }
body:not([data-theme="light"]) .cqc-project-info { background: linear-gradient(135deg, rgba(148,163,184,.06), rgba(240,180,41,.08)); }
body:not([data-theme="light"]) [style*="linear-gradient(135deg, #e8f5e9"] { background: rgba(34,197,94,.15) !important; border-left-color: #22c55e !important; }
body:not([data-theme="light"]) [style*="color: #1b5e20"] { color: #86efac !important; }
body:not([data-theme="light"]) [style*="color: #2e7d32"] { color: #4ade80 !important; }
</style>

<div class="cqc-container">
    <div class="cqc-header">
        <div>
            <h1>📄 Buat Invoice</h1>
            <p>Buat tagihan progress pembayaran proyek</p>
        </div>
        <a href="index-cqc.php" class="btn-back">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            Kembali
        </a>
    </div>

    <?php if ($error): ?>
        <div class="cqc-alert-error">⚠️ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($fromQuotation): ?>
    <div style="background: linear-gradient(135deg, #e8f5e9, #c8e6c9); border-left: 4px solid #2e7d32; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; display: flex; align-items: center; gap: 10px;">
        <span style="font-size: 20px;">🧾</span>
        <div>
            <div style="color: #1b5e20; font-weight: 700; font-size: 13px;">Invoice Termin 1 dari Quotation <strong><?php echo htmlspecialchars($fromQuotation['quote_number']); ?></strong></div>
            <div style="color: #2e7d32; font-size: 12px; margin-top: 2px;">Klien: <?php echo htmlspecialchars($fromQuotation['client_name']); ?> &bull; <?php echo htmlspecialchars($fromQuotation['subject'] ?: '-'); ?></div>
        </div>
    </div>
    <?php endif; ?>

    <form method="POST" id="terminForm">
        <?php if ($fromQuotationId > 0): ?>
        <input type="hidden" name="from_quotation_id" value="<?php echo $fromQuotationId; ?>">
        <?php endif; ?>
        <div class="cqc-form-card">
            
            <!-- Project Selection -->
            <div class="cqc-form-section">
                <div class="cqc-section-title"><span>📁</span> Pilih Proyek</div>
                
                <div class="cqc-form-group">
                    <label class="cqc-form-label">Proyek <span class="required">*</span></label>
                    <select name="project_id" id="projectSelect" class="cqc-form-select" required onchange="loadProjectInfo()">
                        <option value="">-- Pilih Proyek --</option>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?php echo $proj['id']; ?>" 
                                    data-client="<?php echo htmlspecialchars($proj['client_name']); ?>"
                                    data-phone="<?php echo htmlspecialchars($proj['client_phone']); ?>"
                                    data-email="<?php echo htmlspecialchars($proj['client_email']); ?>"
                                    data-contract="<?php echo $proj['contract_value']; ?>"
                                    data-kwp="<?php echo $proj['solar_capacity_kwp']; ?>"
                                    <?php echo ($proj['id'] == $preSelectedProjectId) ? 'selected' : ''; ?>>
                                [<?php echo $proj['project_code']; ?>] <?php echo $proj['project_name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="cqc-project-info" id="projectInfoCard">
                    <div class="cqc-project-info-grid">
                        <div class="cqc-project-info-item">
                            <label>Klien</label>
                            <div id="infoClient">-</div>
                        </div>
                        <div class="cqc-project-info-item">
                            <label>Kontak</label>
                            <div id="infoPhone">-</div>
                        </div>
                        <div class="cqc-project-info-item">
                            <label>Kapasitas</label>
                            <div id="infoKwp">-</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Invoice Details -->
            <div class="cqc-form-section">
                <div class="cqc-section-title"><span>📋</span> Detail Faktur</div>
                
                <div class="cqc-form-grid cols-4">
                    <div class="cqc-form-group">
                        <label class="cqc-form-label">Termin ke- <span class="required">*</span></label>
                        <select name="termin_number" id="terminNumber" class="cqc-form-select" required>
                            <option value="1">Termin 1</option>
                            <option value="2">Termin 2</option>
                            <option value="3">Termin 3</option>
                            <option value="4">Termin 4</option>
                            <option value="5">Termin 5</option>
                        </select>
                    </div>
                    <div class="cqc-form-group">
                        <label class="cqc-form-label">Tanggal Faktur <span class="required">*</span></label>
                        <input type="date" name="invoice_date" class="cqc-form-input" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="cqc-form-group">
                        <label class="cqc-form-label">Jatuh Tempo</label>
                        <input type="date" name="due_date" class="cqc-form-input" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                    </div>
                    <div class="cqc-form-group">
                        <label class="cqc-form-label">Persentase <span class="required">*</span></label>
                        <input type="number" name="percentage" id="percentage" class="cqc-form-input" 
                               step="0.01" min="0.01" max="100" placeholder="30" required onchange="calculateTotal()">
                    </div>
                </div>
                
                <div class="cqc-form-grid" style="margin-top: 16px;">
                    <div class="cqc-form-group cqc-form-full">
                        <label class="cqc-form-label">Keterangan</label>
                        <input type="text" name="description" class="cqc-form-input" placeholder="Pembayaran Termin 1 - DP 30%" value="<?php echo htmlspecialchars($preDescription); ?>">
                    </div>
                </div>
            </div>
            
            <!-- Amount Calculation -->
            <div class="cqc-form-section">
                <div class="cqc-section-title"><span>💰</span> Perhitungan Nilai</div>
                
                <div class="cqc-form-grid">
                    <div class="cqc-form-group">
                        <label class="cqc-form-label">Nilai Kontrak (DPP) <span class="required">*</span></label>
                        <input type="text" name="contract_value" id="contractValue" class="cqc-form-input amount" 
                               placeholder="0" required onkeyup="formatCurrency(this); calculateTotal();" value="<?php echo htmlspecialchars($preContractValue); ?>">
                    </div>
                    <div class="cqc-form-group">
                        <label class="cqc-form-label">PPN (%)</label>
                        <input type="number" name="ppn_percentage" id="ppnPercentage" class="cqc-form-input" 
                               step="0.01" min="0" max="100" value="<?php echo $prePpnPercentage; ?>" onchange="calculateTotal()">
                    </div>
                </div>
                
                <div class="cqc-form-grid cols-3" style="margin-top: 16px;">
                    <div class="cqc-form-group">
                        <label class="cqc-form-label">PPh 23/4(2) (%)</label>
                        <input type="number" name="pph_percentage" id="pphPercentage" class="cqc-form-input" 
                               step="0.01" min="0" max="100" value="0" onchange="calculateTotal()" placeholder="2">
                    </div>
                    <div class="cqc-form-group">
                        <label class="cqc-form-label">Retensi (%)</label>
                        <input type="number" name="retention_percentage" id="retentionPercentage" class="cqc-form-input" 
                               step="0.01" min="0" max="100" value="0" onchange="calculateTotal()" placeholder="5">
                    </div>
                </div>
                
                <!-- Calculation Preview -->
                <div class="cqc-calc-preview">
                    <div class="cqc-calc-row">
                        <span class="cqc-calc-label">DPP (Nilai Kontrak × %Termin)</span>
                        <span class="cqc-calc-value" id="calcDpp">Rp 0</span>
                    </div>
                    <div class="cqc-calc-row">
                        <span class="cqc-calc-label">(+) PPN <span id="ppnPctDisplay">11</span>%</span>
                        <span class="cqc-calc-value plus" id="calcPpn">+ Rp 0</span>
                    </div>
                    <div class="cqc-calc-row">
                        <span class="cqc-calc-label">(-) PPh <span id="pphPctDisplay">0</span>%</span>
                        <span class="cqc-calc-value minus" id="calcPph">- Rp 0</span>
                    </div>
                    <div class="cqc-calc-row">
                        <span class="cqc-calc-label">(-) Retensi <span id="retentionPctDisplay">0</span>%</span>
                        <span class="cqc-calc-value minus" id="calcRetention">- Rp 0</span>
                    </div>
                    <div class="cqc-calc-row highlight">
                        <span class="cqc-calc-label" style="font-weight: 700; color: var(--cqc-primary);">TOTAL TAGIHAN</span>
                        <span class="cqc-calc-value total" id="calcTotal">Rp 0</span>
                    </div>
                </div>
            </div>
            
            <!-- Notes -->
            <div class="cqc-form-section">
                <div class="cqc-section-title"><span>📝</span> Catatan</div>
                <div class="cqc-form-group">
                    <textarea name="notes" class="cqc-form-textarea" rows="3" placeholder="Catatan tambahan untuk faktur ini..."></textarea>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="cqc-form-actions">
                <button type="button" class="cqc-btn cqc-btn-secondary" onclick="location.href='index-cqc.php'">Batal</button>
                <button type="submit" class="cqc-btn cqc-btn-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align: middle; margin-right: 6px;"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17,21 17,13 7,13 7,21"/><polyline points="7,3 7,8 15,8"/></svg>
                    Simpan Invoice
                </button>
            </div>
        </div>
    </form>
</div>

<script>
function loadProjectInfo() {
    const select = document.getElementById('projectSelect');
    const option = select.options[select.selectedIndex];
    const infoCard = document.getElementById('projectInfoCard');
    
    if (select.value) {
        document.getElementById('infoClient').textContent = option.dataset.client || '-';
        document.getElementById('infoPhone').textContent = option.dataset.phone || '-';
        document.getElementById('infoKwp').textContent = option.dataset.kwp ? option.dataset.kwp + ' kWp' : '-';
        
        // Auto-fill contract value
        const contractValue = parseFloat(option.dataset.contract) || 0;
        if (contractValue > 0) {
            document.getElementById('contractValue').value = formatNumber(contractValue);
        }
        
        infoCard.classList.add('show');
        calculateTotal();
    } else {
        infoCard.classList.remove('show');
    }
}

function formatNumber(num) {
    return Math.round(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
}

function parseNumber(str) {
    return parseFloat(str.replace(/\./g, '').replace(',', '.')) || 0;
}

function formatCurrency(input) {
    let value = input.value.replace(/\D/g, '');
    input.value = value.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
}

function calculateTotal() {
    const contractValue = parseNumber(document.getElementById('contractValue').value);
    const percentage = parseFloat(document.getElementById('percentage').value) || 0;
    const ppnPct = parseFloat(document.getElementById('ppnPercentage').value) || 0;
    const pphPct = parseFloat(document.getElementById('pphPercentage').value) || 0;
    const retentionPct = parseFloat(document.getElementById('retentionPercentage').value) || 0;
    
    const dpp = (contractValue * percentage) / 100;
    const ppn = (dpp * ppnPct) / 100;
    const pph = (dpp * pphPct) / 100;
    const retention = (dpp * retentionPct) / 100;
    const total = dpp + ppn - pph - retention;
    
    document.getElementById('calcDpp').textContent = 'Rp ' + formatNumber(dpp);
    document.getElementById('calcPpn').textContent = '+ Rp ' + formatNumber(ppn);
    document.getElementById('calcPph').textContent = '- Rp ' + formatNumber(pph);
    document.getElementById('calcRetention').textContent = '- Rp ' + formatNumber(retention);
    document.getElementById('calcTotal').textContent = 'Rp ' + formatNumber(total);
    
    document.getElementById('ppnPctDisplay').textContent = ppnPct;
    document.getElementById('pphPctDisplay').textContent = pphPct;
    document.getElementById('retentionPctDisplay').textContent = retentionPct;
}

// Initial calculation
calculateTotal();

<?php if ($preSelectedProjectId > 0): ?>
// Auto-initialize project info card when coming from quotation
document.addEventListener('DOMContentLoaded', function() {
    var sel = document.getElementById('projectSelect');
    if (sel && sel.value) {
        loadProjectInfo();
        <?php if ($preContractValue): ?>
        // Restore quotation contract value (quotation subtotal, not project budget)
        document.getElementById('contractValue').value = '<?php echo addslashes($preContractValue); ?>';
        calculateTotal();
        <?php endif; ?>
    }
});
<?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>
