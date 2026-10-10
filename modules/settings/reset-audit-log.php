<?php
/**
 * Reset Audit Log
 * Hapus semua catatan audit log sistem
 */

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Only admin/owner/developer can access
if (!$auth->hasPermission('settings')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Reset Audit Log';

// Count current audit logs
$logCount = 0;
$logDetails = [];
try {
    $count = $db->fetchOne("SELECT COUNT(*) as total FROM audit_logs");
    $logCount = $count ? (int)$count['total'] : 0;
    
    // Get summary by action type
    $details = $db->fetchAll("SELECT action, COUNT(*) as total FROM audit_logs GROUP BY action ORDER BY total DESC");
    $logDetails = $details ?: [];
} catch (Exception $e) {
    // Table might not exist
}

// Handle reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reset'])) {
    $confirmText = trim($_POST['confirm_text'] ?? '');
    
    if ($confirmText !== 'RESET AUDIT LOG') {
        $_SESSION['error'] = 'Konfirmasi tidak valid. Ketik "RESET AUDIT LOG" untuk melanjutkan.';
    } else {
        try {
            $db->getConnection()->exec("DELETE FROM audit_logs");
            // Reset auto increment
            $db->getConnection()->exec("ALTER TABLE audit_logs AUTO_INCREMENT = 1");
            $_SESSION['success'] = '✅ Audit log berhasil direset. ' . $logCount . ' record telah dihapus.';
            header('Location: reset-audit-log.php');
            exit;
        } catch (Exception $e) {
            $_SESSION['error'] = '❌ Gagal reset audit log: ' . $e->getMessage();
        }
    }
}

include '../../includes/header.php';
?>

<style>
    .audit-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 1.5rem;
        margin-bottom: 1rem;
    }
    .audit-stat {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 0.75rem 0;
        border-bottom: 1px solid var(--border-color);
    }
    .audit-stat:last-child {
        border-bottom: none;
    }
    .reset-form {
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 8px;
        padding: 1.5rem;
    }
    .reset-input {
        width: 100%;
        padding: 0.75rem 1rem;
        border: 2px solid #fecaca;
        border-radius: 6px;
        font-size: 0.95rem;
        font-weight: 600;
        text-align: center;
        letter-spacing: 1px;
        margin: 0.75rem 0;
        background: white;
    }
    .reset-input:focus {
        outline: none;
        border-color: #ef4444;
    }
    .btn-reset {
        width: 100%;
        padding: 0.75rem;
        background: #dc2626;
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 0.95rem;
        font-weight: 700;
        cursor: pointer;
        transition: background 0.2s;
    }
    .btn-reset:hover {
        background: #b91c1c;
    }
    .btn-reset:disabled {
        background: #9ca3af;
        cursor: not-allowed;
    }
</style>
<style>
/* dark-theme-overrides */
/* --card-bg / --border-color are not defined globally; give the cards explicit dark surfaces */
body:not([data-theme="light"]) .audit-card { background: #111a2e; border-color: rgba(148,163,184,.2); }
body:not([data-theme="light"]) .audit-stat { border-bottom-color: rgba(148,163,184,.15); }
body:not([data-theme="light"]) .reset-form { background: rgba(239,68,68,.1); border-color: rgba(239,68,68,.35); }
body:not([data-theme="light"]) .reset-input { background: #0f172a; color: #e2e8f0; border-color: rgba(239,68,68,.5); }
body:not([data-theme="light"]) .reset-input::placeholder { color: #64748b; }
body:not([data-theme="light"]) .btn-reset:disabled { background: #475569; color: #cbd5e1; }
body:not([data-theme="light"]) [style*="background: #f0fdf4"] { background: rgba(34,197,94,.15) !important; border-color: rgba(34,197,94,.35) !important; color: #86efac !important; }
body:not([data-theme="light"]) [style*="background: #fef2f2"] { background: rgba(239,68,68,.15) !important; border-color: rgba(239,68,68,.35) !important; color: #fca5a5 !important; }
body:not([data-theme="light"]) .reset-form [style*="color: #7f1d1d"] { color: #fca5a5 !important; }
body:not([data-theme="light"]) .reset-form [style*="color: #991b1b"] { color: #fca5a5 !important; }
body:not([data-theme="light"]) .reset-form h3[style*="color: #dc2626"] { color: #f87171 !important; }
</style>

<!-- Back button -->
<div style="margin-bottom: 1rem;">
    <a href="index.php" style="display: inline-flex; align-items: center; gap: 0.5rem; color: var(--text-muted); text-decoration: none; font-size: 0.875rem; font-weight: 500;">
        <i data-feather="arrow-left" style="width: 16px; height: 16px;"></i>
        Kembali ke Pengaturan
    </a>
</div>

<h2 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1rem;">
    <i data-feather="trash-2" style="width: 20px; height: 20px; color: var(--danger); vertical-align: middle;"></i>
    Reset Audit Log
</h2>

<?php if (isset($_SESSION['success'])): ?>
<div style="padding: 0.75rem 1rem; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; color: #166534; margin-bottom: 1rem; font-size: 0.875rem;">
    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
<div style="padding: 0.75rem 1rem; background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; color: #dc2626; margin-bottom: 1rem; font-size: 0.875rem;">
    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
</div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
    
    <!-- Status -->
    <div class="audit-card">
        <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1rem;">
            Status Audit Log
        </h3>
        
        <div style="text-align: center; padding: 1.5rem 0; margin-bottom: 1rem;">
            <div style="font-size: 2.5rem; font-weight: 800; color: <?php echo $logCount > 0 ? '#dc2626' : '#16a34a'; ?>;">
                <?php echo number_format($logCount); ?>
            </div>
            <div style="font-size: 0.875rem; color: var(--text-muted);">Total Record Audit Log</div>
        </div>
        
        <?php if (!empty($logDetails)): ?>
        <div style="border-top: 1px solid var(--border-color); padding-top: 0.75rem;">
            <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.5rem;">Detail per Aksi:</div>
            <?php foreach ($logDetails as $detail): ?>
            <div class="audit-stat">
                <span style="font-size: 0.85rem; color: var(--text-primary); font-weight: 500; flex: 1;"><?php echo htmlspecialchars($detail['action']); ?></span>
                <span style="font-size: 0.85rem; font-weight: 700; color: var(--text-primary);"><?php echo number_format($detail['total']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Reset Form -->
    <div>
        <div class="reset-form">
            <h3 style="font-size: 1rem; font-weight: 700; color: #dc2626; margin-bottom: 0.5rem;">
                ⚠️ Hapus Semua Audit Log
            </h3>
            <p style="font-size: 0.85rem; color: #7f1d1d; margin-bottom: 1rem; line-height: 1.5;">
                Tindakan ini akan menghapus <strong>semua <?php echo number_format($logCount); ?> record</strong> audit log secara permanen. Data yang dihapus tidak dapat dikembalikan.
            </p>
            
            <form method="POST">
                <label style="font-size: 0.8rem; font-weight: 600; color: #991b1b;">
                    Ketik <strong>RESET AUDIT LOG</strong> untuk konfirmasi:
                </label>
                <input type="text" name="confirm_text" class="reset-input" placeholder="RESET AUDIT LOG" autocomplete="off" id="confirmInput">
                <input type="hidden" name="confirm_reset" value="1">
                <button type="submit" class="btn-reset" id="resetBtn" disabled>
                    Hapus Semua Audit Log
                </button>
            </form>
        </div>
        
        <div style="margin-top: 1rem; padding: 1rem; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px;">
            <h4 style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.5rem;">ℹ️ Tentang Audit Log</h4>
            <ul style="font-size: 0.8rem; color: var(--text-muted); line-height: 1.8; padding-left: 1.25rem; margin: 0;">
                <li>Audit log mencatat semua aktivitas penghapusan transaksi</li>
                <li>Digunakan untuk tracking siapa menghapus data apa</li>
                <li>Reset audit log tidak mempengaruhi data transaksi</li>
                <li>Setelah reset, log baru akan mulai terekam lagi</li>
            </ul>
        </div>
    </div>
    
</div>

<script>
    feather.replace();
    
    // Enable/disable reset button based on confirmation text
    document.getElementById('confirmInput').addEventListener('input', function() {
        document.getElementById('resetBtn').disabled = this.value.trim() !== 'RESET AUDIT LOG';
    });
</script>

<?php include '../../includes/footer.php'; ?>
