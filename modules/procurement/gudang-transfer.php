<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!($auth->hasPermission('gudang_nasita') || $auth->hasPermission('warehouse_transfers') || $auth->hasPermission('warehouse'))) {
    http_response_code(403);
    echo 'Akses transfer Gudang Nasita ditolak.';
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Transfer Gudang Nasita';

$message = '';
$messageType = 'success';

$prefillPoId = (int)($_GET['po_id'] ?? 0);
$prefillTargetBusinessId = 0;
$prefillNotes = '';
if ($prefillPoId > 0) {
    $poRow = $db->fetchOne("SELECT id, po_number, business_id FROM purchase_orders_header WHERE id = ? LIMIT 1", [$prefillPoId]);
    if ($poRow) {
        $prefillTargetBusinessId = (int)($poRow['business_id'] ?? 0);
        $prefillNotes = 'Proses PO ' . $poRow['po_number'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetBusinessId = (int)($_POST['target_business_id'] ?? 0);
    $stockId = (int)($_POST['stock_id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $sourcePoId = (int)($_POST['source_po_id'] ?? 0);
    if ($sourcePoId <= 0) {
        $sourcePoId = null;
    }

    $result = transferGudangNasitaStock($targetBusinessId, [
        [
            'stock_id' => $stockId,
            'quantity' => $quantity,
            'notes' => $notes,
        ]
    ], $currentUser['id'], $notes, $sourcePoId);

    if ($result['success']) {
        $message = $result['message'] . ' Ke ' . $result['business_name'];
        $messageType = 'success';
    } else {
        $message = $result['message'];
        $messageType = 'danger';
    }
}

$stockItems = getGudangNasitaStock(300);
$transfers = getGudangNasitaTransfers(50);
$businesses = $db->fetchAll("\n    SELECT id, business_name, business_code\n    FROM businesses\n    WHERE is_active = 1 AND (business_name LIKE '%Narayana%' OR business_name LIKE '%Eat Meet%' OR business_name LIKE '%Bens%')\n    ORDER BY business_name ASC\n");

include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem; display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
    <div>
        <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">Transfer Gudang Nasita</h2>
        <p style="color: var(--text-muted); font-size: 0.875rem;">Kirim stok dari gudang pusat ke bisnis tujuan</p>
    </div>
    <a href="gudang-nasita.php" class="btn btn-secondary">
        <i data-feather="archive" style="width: 16px; height: 16px;"></i>
        Kembali ke Gudang
    </a>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($prefillPoId > 0): ?>
    <div class="alert alert-info" style="margin-bottom:1rem;">
        Proses transfer berdasarkan PO bisnis. Silakan pilih item gudang lalu transfer ke bisnis tujuan.
    </div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: 1.1fr 1fr; gap: 1.25rem; align-items:start;">
    <div class="card">
        <h3 style="font-size:1rem; font-weight:700; margin-bottom:1rem;">Form Transfer</h3>
        <form method="POST">
            <input type="hidden" name="source_po_id" value="<?php echo (int)$prefillPoId; ?>">
            <div class="form-group">
                <label class="form-label">Tujuan Bisnis</label>
                <select name="target_business_id" class="form-control" required>
                    <option value="">-- Pilih bisnis --</option>
                    <?php foreach ($businesses as $biz): ?>
                        <option value="<?php echo (int)$biz['id']; ?>" <?php echo ((int)$biz['id'] === (int)$prefillTargetBusinessId) ? 'selected' : ''; ?>><?php echo htmlspecialchars($biz['business_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Item Gudang</label>
                <select name="stock_id" class="form-control" required>
                    <option value="">-- Pilih item --</option>
                    <?php foreach ($stockItems as $item): ?>
                        <option value="<?php echo (int)$item['id']; ?>"><?php echo htmlspecialchars($item['item_name']); ?> (<?php echo number_format((float)$item['quantity'], 2); ?> <?php echo htmlspecialchars($item['unit']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Qty Transfer</label>
                <input type="number" step="0.01" min="0.01" name="quantity" class="form-control" required>
            </div>

            <div class="form-group">
                <label class="form-label">Catatan</label>
                <textarea name="notes" class="form-control" rows="3" placeholder="Catatan pengiriman, nomor PO, dll."><?php echo htmlspecialchars($prefillNotes); ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary">
                <i data-feather="send" style="width: 16px; height: 16px;"></i>
                Transfer Sekarang
            </button>
        </form>
    </div>

    <div class="card">
        <h3 style="font-size:1rem; font-weight:700; margin-bottom:1rem;">Riwayat Transfer</h3>
        <div style="display:grid; gap:0.75rem; max-height: 620px; overflow-y:auto; padding-right:0.25rem;">
            <?php if (empty($transfers)): ?>
                <div style="color:var(--text-muted); font-size:0.875rem;">Belum ada transfer</div>
            <?php else: ?>
                <?php foreach ($transfers as $transfer): ?>
                    <div style="padding:0.85rem; border:1px solid var(--border); border-radius:0.85rem; background: var(--bg-secondary);">
                        <div style="display:flex; justify-content:space-between; gap:1rem; align-items:flex-start;">
                            <div>
                                <div style="font-weight:700; color: var(--text-primary);"><?php echo htmlspecialchars($transfer['transfer_number']); ?></div>
                                <div style="font-size:0.813rem; color:var(--text-muted);"><?php echo htmlspecialchars($transfer['target_business_name']); ?></div>
                                <div style="font-size:0.813rem; color:var(--text-muted);"><?php echo (int)$transfer['items_count']; ?> item | <?php echo number_format((float)$transfer['total_qty'], 2); ?> qty</div>
                            </div>
                            <span class="badge badge-<?php echo $transfer['status'] === 'sent' ? 'warning' : 'secondary'; ?>"><?php echo ucfirst($transfer['status']); ?></span>
                        </div>
                        <?php if (!empty($transfer['notes'])): ?>
                            <div style="margin-top:0.5rem; font-size:0.813rem; color:var(--text-muted);"><?php echo htmlspecialchars($transfer['notes']); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>