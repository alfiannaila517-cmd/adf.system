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
$prefillPoBusinessSlug = trim((string)($_GET['po_business'] ?? ''));
$prefillStockId = (int)($_GET['stock_id'] ?? 0);
$prefillQty = (float)($_GET['qty'] ?? 0);
$prefillTargetBusinessId = 0;
$prefillTargetBusinessName = '';
$prefillNotes = '';
$allowedPoBusinessSlugs = ['narayana-hotel', 'bens-cafe', 'eaat-meet'];
if (!in_array($prefillPoBusinessSlug, $allowedPoBusinessSlugs, true)) {
    $prefillPoBusinessSlug = '';
}

$resolvePoContext = function (int $poId, string $poBusinessSlug = '') use ($db, $allowedPoBusinessSlugs) {
    $originDbName = Database::getCurrentDatabase();
    $resolved = [
        'row' => null,
        'business_slug' => $poBusinessSlug,
        'business_name' => '',
    ];

    try {
        if ($poBusinessSlug !== '' && in_array($poBusinessSlug, $allowedPoBusinessSlugs, true)) {
            $cfgPath = __DIR__ . '/../../config/businesses/' . $poBusinessSlug . '.php';
            if (file_exists($cfgPath)) {
                $cfg = require $cfgPath;
                $bizDbName = (string)($cfg['database'] ?? '');
                if ($bizDbName !== '') {
                    $bizDb = Database::switchDatabase($bizDbName);
                    $resolved['row'] = $bizDb->fetchOne("\n                        SELECT poh.id, poh.po_number, poh.business_id, b.business_name, b.business_code\n                        FROM purchase_orders_header poh\n                        LEFT JOIN businesses b ON b.id = poh.business_id\n                        WHERE poh.id = ?\n                        LIMIT 1\n                    ", [$poId]);
                    $resolved['business_name'] = (string)($cfg['name'] ?? '');
                }
            }
        }

        if (!$resolved['row']) {
            $resolved['row'] = $db->fetchOne("\n                SELECT poh.id, poh.po_number, poh.business_id, b.business_name, b.business_code\n                FROM purchase_orders_header poh\n                LEFT JOIN businesses b ON b.id = poh.business_id\n                WHERE poh.id = ?\n                LIMIT 1\n            ", [$poId]);
            $resolved['business_slug'] = $resolved['business_slug'] ?: '';
        }
    } catch (Throwable $e) {
        error_log('Gudang transfer resolve PO error: ' . $e->getMessage());
    }

    if (!empty($originDbName)) {
        try {
            Database::switchDatabase($originDbName);
        } catch (Throwable $e) {
        }
    }

    return $resolved;
};

if ($prefillPoId > 0) {
    $resolvedPo = $resolvePoContext($prefillPoId, $prefillPoBusinessSlug);
    $poRow = $resolvedPo['row'];
    if ($poRow) {
        $prefillTargetBusinessId = (int)($poRow['business_id'] ?? 0);
        $prefillTargetBusinessName = trim((string)($poRow['business_name'] ?? ''));
        if ($prefillTargetBusinessName === '') {
            $prefillTargetBusinessName = trim((string)($resolvedPo['business_name'] ?? ''));
        }
        $prefillNotes = 'Proses PO ' . $poRow['po_number'];
    }
}

if ($prefillTargetBusinessId > 0 && $prefillTargetBusinessName === '') {
    $bizById = $db->fetchOne("SELECT id, business_name FROM businesses WHERE id = ? LIMIT 1", [$prefillTargetBusinessId]);
    if ($bizById && !empty($bizById['business_name'])) {
        $prefillTargetBusinessName = trim((string)$bizById['business_name']);
    }
}

$activeBusinessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;
$allBusinesses = $db->fetchAll("SELECT id, business_name, business_code FROM businesses WHERE (is_active = 1 OR is_active IS NULL) ORDER BY business_name ASC");
if (empty($allBusinesses)) {
    $allBusinesses = $db->fetchAll("SELECT id, business_name, business_code FROM businesses ORDER BY business_name ASC");
}
$allowedBusinesses = [];
foreach ($allBusinesses as $biz) {
    $codeNorm = strtolower(preg_replace('/[^a-z0-9]/', '', (string)($biz['business_code'] ?? '')));
    $nameNorm = strtolower(preg_replace('/[^a-z0-9]/', '', (string)($biz['business_name'] ?? '')));
    $isAllowed = in_array($codeNorm, ['narayanahotel', 'benscafe', 'eatmeet', 'eaatmeet'], true)
        || strpos($nameNorm, 'narayana') !== false
        || strpos($nameNorm, 'bens') !== false
        || strpos($nameNorm, 'eatmeet') !== false;
    if ($isAllowed) {
        $allowedBusinesses[] = $biz;
    }
    if ($prefillTargetBusinessId > 0 && (int)$biz['id'] === $prefillTargetBusinessId) {
        $prefillTargetBusinessName = (string)$biz['business_name'];
    }
}

if (empty($allowedBusinesses)) {
    // Fallback: gunakan bisnis yang pernah membuat PO agar dropdown tidak kosong.
    $allowedBusinesses = $db->fetchAll("\n        SELECT DISTINCT b.id, b.business_name, b.business_code\n        FROM purchase_orders_header poh\n        INNER JOIN businesses b ON b.id = poh.business_id\n        ORDER BY b.business_name ASC\n    ");
}

if (empty($allowedBusinesses)) {
    $allowedBusinesses = $allBusinesses;
}

// Resolve target business from PO slug using already-loaded businesses (avoids stale DB closure issues)
$findBusinessBySlug = function (string $slug) use ($allBusinesses) {
    $slugNorm = preg_replace('/[^a-z0-9]/', '', strtolower($slug));
    if ($slugNorm === '') {
        return [0, ''];
    }
    foreach ($allBusinesses as $biz) {
        $codeNorm = strtolower(preg_replace('/[^a-z0-9]/', '', (string)($biz['business_code'] ?? '')));
        $nameNorm = strtolower(preg_replace('/[^a-z0-9]/', '', (string)($biz['business_name'] ?? '')));
        if ($slugNorm === $codeNorm || strpos($nameNorm, $slugNorm) !== false || strpos($slugNorm, $nameNorm) !== false) {
            return [(int)$biz['id'], (string)$biz['business_name']];
        }
    }
    return [0, ''];
};

// Fix GET-phase: if target still 0, resolve from slug via loaded list
if ($prefillPoId > 0 && $prefillTargetBusinessId <= 0 && $prefillPoBusinessSlug !== '') {
    [$prefillTargetBusinessId, $resolvedName] = $findBusinessBySlug($prefillPoBusinessSlug);
    if ($prefillTargetBusinessName === '' && $resolvedName !== '') {
        $prefillTargetBusinessName = $resolvedName;
    }
}
// Load display name from config file as final fallback
if ($prefillPoId > 0 && $prefillTargetBusinessId > 0 && $prefillTargetBusinessName === '') {
    $bizCfgPath = __DIR__ . '/../../config/businesses/' . $prefillPoBusinessSlug . '.php';
    if ($prefillPoBusinessSlug !== '' && file_exists($bizCfgPath)) {
        $bizCfg = require $bizCfgPath;
        $prefillTargetBusinessName = (string)($bizCfg['name'] ?? '');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetBusinessId = (int)($_POST['target_business_id'] ?? 0);
    $stockId = (int)($_POST['stock_id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $sourcePoId = (int)($_POST['source_po_id'] ?? 0);
    $sourcePoBusinessSlug = trim((string)($_POST['source_po_business'] ?? ''));
    if (!in_array($sourcePoBusinessSlug, $allowedPoBusinessSlugs, true)) {
        $sourcePoBusinessSlug = '';
    }
    $redirectBase = 'gudang-transfer.php' . ($sourcePoId > 0 ? '?po_id=' . $sourcePoId . '&po_business=' . urlencode($sourcePoBusinessSlug) : '');

    // Resolve target business name + ID from the source business's own DB config
    $resolvedBizName = '';
    $resolvedBizId = $targetBusinessId;
    if ($sourcePoBusinessSlug !== '') {
        $bizCfgPath = __DIR__ . '/../../config/businesses/' . $sourcePoBusinessSlug . '.php';
        if (file_exists($bizCfgPath)) {
            $bizCfgData = require $bizCfgPath;
            $resolvedBizName = (string)($bizCfgData['name'] ?? '');
            $bizDbName = (string)($bizCfgData['database'] ?? '');
            if ($bizDbName !== '' && $resolvedBizId <= 0) {
                // Try to get the business's own ID from its own database
                try {
                    $originDbName = Database::getCurrentDatabase();
                    $bizDb = Database::switchDatabase($bizDbName);
                    $bizRow = $bizDb->fetchOne('SELECT id, business_name FROM businesses ORDER BY id ASC LIMIT 1');
                    if ($bizRow) {
                        $resolvedBizId = (int)$bizRow['id'];
                        if ($resolvedBizName === '') {
                            $resolvedBizName = (string)$bizRow['business_name'];
                        }
                    }
                    if (!empty($originDbName)) {
                        Database::switchDatabase($originDbName);
                    }
                } catch (Throwable $e) {
                    error_log('gudang-transfer biz resolve error: ' . $e->getMessage());
                }
            }
        }
    }

    // Fallback: resolve from already-loaded businesses list
    if ($resolvedBizId <= 0 && $sourcePoBusinessSlug !== '') {
        [$resolvedBizId, $resolvedBizNameFallback] = $findBusinessBySlug($sourcePoBusinessSlug);
        if ($resolvedBizName === '') {
            $resolvedBizName = $resolvedBizNameFallback;
        }
    }

    if ($sourcePoId <= 0) {
        $sourcePoId = null;
    } else if ($resolvedBizId <= 0) {
        header('Location: ' . $redirectBase . (strpos($redirectBase, '?') !== false ? '&' : '?') . 'transfer_err=' . urlencode('Bisnis tujuan tidak ditemukan. Hubungi admin.'));
        exit;
    }

    $result = transferGudangNasitaStock($resolvedBizId ?: $targetBusinessId, [
        [
            'stock_id' => $stockId,
            'quantity' => $quantity,
            'notes' => $notes,
        ]
    ], $currentUser['id'], $notes, $sourcePoId, $resolvedBizName ?: null);

    $sep = strpos($redirectBase, '?') !== false ? '&' : '?';
    if ($result['success']) {
        header('Location: ' . $redirectBase . $sep . 'transfer_ok=1&biz=' . urlencode($result['business_name'] ?? $resolvedBizName));
    } else {
        header('Location: ' . $redirectBase . $sep . 'transfer_err=' . urlencode($result['message']));
    }
    exit;
}

$stockItems = getGudangNasitaStock(300);
$transfers = getGudangNasitaTransfers(50);

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

<?php if (!empty($_GET['transfer_ok'])): ?>
    <div class="alert alert-success" id="transferResult">
        ✅ Barang berhasil ditransfer ke <strong><?php echo htmlspecialchars((string)($_GET['biz'] ?? '')); ?></strong>!
        <script>document.getElementById('transferResult').scrollIntoView({behavior:'smooth'});</script>
    </div>
<?php elseif (!empty($_GET['transfer_err'])): ?>
    <div class="alert alert-danger" id="transferResult">
        ❌ <?php echo htmlspecialchars((string)$_GET['transfer_err']); ?>
        <script>document.getElementById('transferResult').scrollIntoView({behavior:'smooth'});</script>
    </div>
<?php endif; ?>
<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<?php if ($prefillPoId > 0): ?>
    <div class="alert alert-info" style="margin-bottom:1rem;">
        Proses transfer berdasarkan PO bisnis. Tujuan bisnis otomatis mengikuti sumber PO.
    </div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: 1.1fr 1fr; gap: 1.25rem; align-items:start;">
    <div class="card">
        <h3 style="font-size:1rem; font-weight:700; margin-bottom:1rem;">Form Transfer</h3>
        <form method="POST">
            <input type="hidden" name="source_po_id" value="<?php echo (int)$prefillPoId; ?>">
            <input type="hidden" name="source_po_business" value="<?php echo htmlspecialchars($prefillPoBusinessSlug); ?>">
            <div class="form-group">
                <label class="form-label">Tujuan Bisnis</label>
                <?php if ($prefillPoId > 0): ?>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars(($prefillTargetBusinessName !== '' ? $prefillTargetBusinessName : 'Business') . ' (otomatis)'); ?>" readonly style="font-weight:700; background:#f8fafc; cursor:not-allowed;">
                    <input type="hidden" name="target_business_id" value="<?php echo (int)$prefillTargetBusinessId; ?>">
                    <div style="margin-top:0.35rem; font-size:0.812rem; color:var(--text-muted);">
                        Tujuan otomatis mengikuti bisnis dari PO sumber.
                    </div>
                <?php else: ?>
                    <select name="target_business_id" class="form-control" required>
                        <option value="">-- Pilih bisnis --</option>
                        <?php foreach ($allowedBusinesses as $biz): ?>
                            <option value="<?php echo (int)$biz['id']; ?>" <?php echo ($activeBusinessId > 0 && (int)$biz['id'] === $activeBusinessId) ? 'selected' : ''; ?>><?php echo htmlspecialchars($biz['business_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">Item Gudang</label>
                <select name="stock_id" class="form-control" required>
                    <option value="">-- Pilih item --</option>
                    <?php foreach ($stockItems as $item): ?>
                        <option value="<?php echo (int)$item['id']; ?>" <?php echo ((int)$item['id'] === $prefillStockId) ? 'selected' : ''; ?>><?php echo htmlspecialchars($item['item_name']); ?> (<?php echo number_format((float)$item['quantity'], 2); ?> <?php echo htmlspecialchars($item['unit']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Qty Transfer</label>
                <input type="number" step="0.01" min="0.01" name="quantity" class="form-control" value="<?php echo $prefillQty > 0 ? htmlspecialchars((string)$prefillQty) : ''; ?>" required>
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