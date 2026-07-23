<?php

/**
 * Ringkasan Setor Tunai
 * Tracking transfer antar rekening kas, dapat diarsipkan
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();

// Get master DB untuk cash_transfers
try {
    $masterDb = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    $masterDb = $db->getConnection();
}

$businessId = getMasterBusinessId();
$currentUser = $auth->getCurrentUser();

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // Archive a cash transfer
    if ($_GET['ajax'] === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        $isArchive = intval($_POST['is_archived'] ?? 0);

        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
            exit;
        }

        try {
            if ($isArchive) {
                // Archive
                $stmt = $masterDb->prepare("UPDATE cash_transfers SET is_archived = 1, archived_at = NOW(), archived_by = ? WHERE id = ? AND business_id = ?");
                $stmt->execute([$_SESSION['user_id'], $id, $businessId]);
                echo json_encode(['success' => true, 'message' => 'Setor tunai berhasil diarsipkan']);
            } else {
                // Unarchive
                $stmt = $masterDb->prepare("UPDATE cash_transfers SET is_archived = 0, archived_at = NULL, archived_by = NULL WHERE id = ? AND business_id = ?");
                $stmt->execute([$id, $businessId]);
                echo json_encode(['success' => true, 'message' => 'Setor tunai berhasil di-unarchive']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// Get filters
$showArchived = isset($_GET['archived']) && $_GET['archived'] === '1';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$filterAccount = $_GET['account'] ?? '';

// Build query
$where = ['business_id = ?'];
$params = [$businessId];

if (!$showArchived) {
    $where[] = 'is_archived = 0';
}

if ($dateFrom) {
    $where[] = 'transfer_date >= ?';
    $params[] = $dateFrom;
}

if ($dateTo) {
    $where[] = 'transfer_date <= ?';
    $params[] = $dateTo;
}

if ($filterAccount) {
    $where[] = '(cash_account_id = ? OR bank_account_id = ?)';
    $params[] = $filterAccount;
    $params[] = $filterAccount;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$pageError = '';
$transfers = [];
$accounts = [];
$totalAmount = 0;

try {
    // Get transfers
    // NOTE: users table lives in the per-business database (via $db), NOT the
    // master DB ($masterDb) where cash_transfers/cash_accounts live. Joining
    // users here would fail/return nothing since the two are separate databases.
    // Resolve user names separately below using the business DB connection.
    $stmt = $masterDb->prepare("
        SELECT 
            ct.id, ct.amount, ct.transfer_date, ct.transfer_time,
            ct.description, ct.created_by, ct.is_archived, ct.archived_at, ct.archived_by,
            ca_cash.account_name as cash_account_name,
            ca_bank.account_name as bank_account_name
        FROM cash_transfers ct
        LEFT JOIN cash_accounts ca_cash ON ct.cash_account_id = ca_cash.id
        LEFT JOIN cash_accounts ca_bank ON ct.bank_account_id = ca_bank.id
        $whereClause
        ORDER BY ct.transfer_date DESC, ct.transfer_time DESC
    ");

    $stmt->execute($params);
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Resolve created_by / archived_by user names from the business DB (not master DB)
    $userIds = [];
    foreach ($transfers as $t) {
        if (!empty($t['created_by'])) $userIds[] = (int)$t['created_by'];
        if (!empty($t['archived_by'])) $userIds[] = (int)$t['archived_by'];
    }
    $userIds = array_values(array_unique($userIds));
    $userNames = [];
    if (!empty($userIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $uStmt = $db->getConnection()->prepare("SELECT id, full_name FROM users WHERE id IN ($placeholders)");
            $uStmt->execute($userIds);
            foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $userNames[$u['id']] = $u['full_name'];
            }
        } catch (Exception $e) {
            // Non-fatal: just show "Sistem" if user lookup fails
            error_log("cash-transfers.php: user lookup failed: " . $e->getMessage());
        }
    }
    foreach ($transfers as &$t) {
        $t['created_user'] = $userNames[$t['created_by']] ?? null;
        $t['archived_user'] = $userNames[$t['archived_by']] ?? null;
    }
    unset($t);

    // Get all cash accounts untuk dropdown
    $accStmt = $masterDb->prepare("
        SELECT id, account_name, account_type 
        FROM cash_accounts 
        WHERE business_id = ? AND is_active = 1
        ORDER BY account_type, account_name
    ");
    $accStmt->execute([$businessId]);
    $accounts = $accStmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary
    foreach ($transfers as $t) {
        $totalAmount += floatval($t['amount']);
    }
} catch (Exception $e) {
    error_log("cash-transfers.php: fatal query error: " . $e->getMessage());
    $pageError = 'Terjadi error saat memuat data setor tunai: ' . $e->getMessage();
}

$pageTitle = '🏦 Ringkasan Setor Tunai';
$pageSubtitle = 'Tracking transfer uang tunai ke rekening operasional, dapat diarsipkan';

include '../../includes/header.php';
?>

<style>
    .cash-transfer-card {
        padding: 1.25rem;
        background: var(--bg-primary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 10px;
        margin-bottom: 0.75rem;
        display: grid;
        grid-template-columns: auto 1fr auto;
        gap: 1rem;
        align-items: center;
        transition: all 0.2s;
    }

    .cash-transfer-card:hover {
        border-color: #0284c7;
        box-shadow: 0 2px 8px rgba(2, 132, 199, 0.1);
    }

    .transfer-status-icon {
        width: 48px;
        height: 48px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        background: rgba(2, 132, 199, 0.12);
    }

    .transfer-info {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .transfer-amount {
        font-weight: 700;
        font-size: 1rem;
        color: var(--text-primary);
    }

    .transfer-meta {
        font-size: 0.75rem;
        color: var(--text-muted);
    }

    .transfer-accounts {
        font-size: 0.8rem;
        color: var(--text-primary);
        margin-top: 0.3rem;
    }

    .transfer-actions {
        display: flex;
        gap: 0.5rem;
    }

    .btn-small {
        padding: 0.4rem 0.75rem;
        font-size: 0.75rem;
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.15s;
    }

    .btn-small:hover {
        border-color: #0284c7;
        background: #dbeafe;
    }

    .btn-archive {
        color: #0284c7;
    }

    .btn-unarchive {
        color: #059669;
    }

    .archived-tag {
        font-size: 0.65rem;
        background: #fef3c7;
        color: #92400e;
        padding: 0.15rem 0.4rem;
        border-radius: 4px;
        font-weight: 600;
    }

    .empty-state {
        text-align: center;
        padding: 3rem 1rem;
        color: var(--text-muted);
    }

    .empty-state-icon {
        font-size: 3rem;
        margin-bottom: 1rem;
    }

    .filter-card {
        background: var(--bg-primary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 10px;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        align-items: end;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
    }

    .filter-group label {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-muted);
    }

    .filter-group input,
    .filter-group select {
        height: 36px;
        font-size: 0.813rem;
        border: 1px solid var(--bg-tertiary);
        border-radius: 6px;
        padding: 0.4rem 0.6rem;
    }

    .summary-box {
        background: linear-gradient(135deg, #dbeafe, #bfdbfe);
        border: 1px solid #7dd3fc;
        border-radius: 10px;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
    }

    .summary-item {
        text-align: center;
    }

    .summary-label {
        font-size: 0.75rem;
        color: #0c4a6e;
        font-weight: 600;
        text-transform: uppercase;
        margin-bottom: 0.25rem;
    }

    .summary-value {
        font-size: 1.25rem;
        font-weight: 700;
        color: #0284c7;
    }
</style>

<div style="max-width: 1000px; margin: 0 auto;">
    <!-- Header -->
    <div class="card" style="margin-bottom: 1.5rem; padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center; border-left: 4px solid #0284c7;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: linear-gradient(135deg, #0284c7, #0369a1); display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                🏦
            </div>
            <div>
                <h1 style="font-size: 1.1rem; font-weight: 700; margin: 0; color: var(--text-primary);">Ringkasan Setor Tunai</h1>
                <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">Tracking transfer ke rekening operasional</p>
            </div>
        </div>
        <a href="add.php" class="btn btn-primary" style="padding: 0.5rem 1rem; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem;">
            <i data-feather="plus" style="width: 14px; height: 14px;"></i> Setor Tunai Baru
        </a>
    </div>

    <?php if ($pageError): ?>
        <div class="card" style="margin-bottom: 1.5rem; padding: 1rem 1.25rem; border-left: 4px solid #dc2626; background: #fef2f2; color: #991b1b;">
            ⚠️ <?php echo htmlspecialchars($pageError); ?>
        </div>
    <?php endif; ?>

    <!-- Summary -->
    <?php if (!empty($transfers)): ?>
        <div class="summary-box">
            <div class="summary-item">
                <div class="summary-label">Total Setor</div>
                <div class="summary-value">Rp <?php echo number_format($totalAmount, 0, ',', '.'); ?></div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Jumlah Transaksi</div>
                <div class="summary-value"><?php echo count($transfers); ?></div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Status</div>
                <div class="summary-value"><?php echo $showArchived ? 'Arsipan' : 'Aktif'; ?></div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="filter-card">
        <form method="GET" style="display: contents;">
            <div class="filter-group">
                <label>Dari Tanggal</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
            </div>
            <div class="filter-group">
                <label>Sampai Tanggal</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
            </div>
            <div class="filter-group">
                <label>Rekening</label>
                <select name="account">
                    <option value="">-- Semua Rekening --</option>
                    <?php foreach ($accounts as $acc): ?>
                        <option value="<?php echo $acc['id']; ?>" <?php echo ($filterAccount == $acc['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($acc['account_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select name="archived">
                    <option value="0" <?php echo !$showArchived ? 'selected' : ''; ?>>Aktif</option>
                    <option value="1" <?php echo $showArchived ? 'selected' : ''; ?>>Arsipan</option>
                </select>
            </div>
            <div class="filter-group">
                <button type="submit" class="btn btn-primary" style="padding: 0.5rem 1rem; font-size: 0.85rem;">
                    <i data-feather="filter" style="width: 14px; height: 14px; margin-right: 0.25rem;"></i> Filter
                </button>
            </div>
            <div class="filter-group">
                <a href="<?php echo $_SERVER['REQUEST_URI']; ?>" class="btn-small" style="text-align: center; text-decoration: none;">Reset</a>
            </div>
        </form>
    </div>

    <!-- Transfers List -->
    <div class="card" style="padding: 0;">
        <?php if (empty($transfers)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🏦</div>
                <div style="font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Belum ada setor tunai</div>
                <div style="font-size: 0.8rem;">Gunakan tombol "Setor Tunai Baru" di buku kas untuk mencatat transfer pertama Anda.</div>
            </div>
        <?php else: ?>
            <?php foreach ($transfers as $transfer): ?>
                <div class="cash-transfer-card" id="transfer-<?php echo $transfer['id']; ?>">
                    <div class="transfer-status-icon">
                        💰
                    </div>

                    <div class="transfer-info">
                        <div class="transfer-amount">
                            Rp <?php echo number_format($transfer['amount'], 0, ',', '.'); ?>
                            <?php if ($transfer['is_archived']): ?>
                                <span class="archived-tag">ARSIPAN</span>
                            <?php endif; ?>
                        </div>
                        <div class="transfer-meta">
                            📅 <?php echo date('d M Y', strtotime($transfer['transfer_date'])); ?>
                            🕒 <?php echo date('H:i', strtotime($transfer['transfer_time'])); ?>
                        </div>
                        <div class="transfer-accounts">
                            💵 <?php echo htmlspecialchars($transfer['cash_account_name']); ?> →
                            🏦 <?php echo htmlspecialchars($transfer['bank_account_name']); ?>
                        </div>
                        <div class="transfer-meta">
                            👤 <?php echo htmlspecialchars($transfer['created_user'] ?? 'Sistem'); ?>
                            <?php if ($transfer['is_archived'] && $transfer['archived_user']): ?>
                                | Diarsipkan oleh <?php echo htmlspecialchars($transfer['archived_user']); ?>
                                pada <?php echo date('d M Y H:i', strtotime($transfer['archived_at'])); ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($transfer['description']): ?>
                            <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.25rem; font-style: italic;">
                                "<?php echo htmlspecialchars($transfer['description']); ?>"
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="transfer-actions">
                        <button class="btn-small btn-<?php echo $transfer['is_archived'] ? 'unarchive' : 'archive'; ?>"
                            onclick="toggleArchive(<?php echo $transfer['id']; ?>, <?php echo $transfer['is_archived'] ? '0' : '1'; ?>)"
                            title="<?php echo $transfer['is_archived'] ? 'Batalkan arsip' : 'Arsipkan'; ?>">
                            <?php echo $transfer['is_archived'] ? '↩️ Unarchive' : '📦 Arsipkan'; ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <a href="index.php" class="btn btn-secondary" style="margin-top: 1.5rem; text-decoration: none; display: inline-block; padding: 0.625rem 1.25rem; font-size: 0.875rem;">
        ← Kembali ke Buku Kas
    </a>
</div>

<script>
    feather.replace();

    function toggleArchive(id, isArchive) {
        const action = isArchive ? 'Arsipkan' : 'Batalkan arsip';

        if (!confirm(`${action} setor tunai ini?`)) return;

        const formData = new FormData();
        formData.append('id', id);
        formData.append('is_archived', isArchive);

        fetch('cash-transfers.php?ajax=archive', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // Reload page
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(err => alert('Error: ' + err.message));
    }
</script>

<?php include '../../includes/footer.php'; ?>