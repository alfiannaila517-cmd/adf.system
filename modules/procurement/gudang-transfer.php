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
        // Do NOT use business_id from cross-DB context — IDs differ between databases.
        // Only take business name from config and PO number for notes.
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

// When po_business is given, ALWAYS resolve from narayana's businesses table (slug is reliable, cross-DB IDs are not)
if ($prefillPoId > 0 && $prefillPoBusinessSlug !== '') {
    [$prefillTargetBusinessId, $resolvedName] = $findBusinessBySlug($prefillPoBusinessSlug);
    if ($prefillTargetBusinessName === '' && $resolvedName !== '') {
        $prefillTargetBusinessName = $resolvedName;
    }
} elseif ($prefillPoId > 0 && $prefillTargetBusinessId <= 0 && $prefillPoBusinessSlug !== '') {
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

// Load PO items from source business DB and match with gudang stock
$poData = null;
$poItemsWithStock = [];
if ($prefillPoId > 0 && $prefillPoBusinessSlug !== '') {
    $bizCfgPath2 = __DIR__ . '/../../config/businesses/' . $prefillPoBusinessSlug . '.php';
    if (file_exists($bizCfgPath2)) {
        $bizCfgData2 = require $bizCfgPath2;
        $bizDbName2 = (string)($bizCfgData2['database'] ?? '');
        if ($bizDbName2 !== '') {
            try {
                $originDbName2 = Database::getCurrentDatabase();
                $bizDb2 = Database::switchDatabase($bizDbName2);
                $poHeader2 = $bizDb2->fetchOne('SELECT * FROM purchase_orders_header WHERE id = ? LIMIT 1', [$prefillPoId]);
                if ($poHeader2) {
                    $poDetails2 = $bizDb2->fetchAll('SELECT pod.* FROM purchase_orders_detail pod WHERE pod.po_header_id = ? ORDER BY pod.id', [$prefillPoId]);
                    $poHeader2['items'] = $poDetails2;
                    $poData = $poHeader2;
                }
                if (!empty($originDbName2)) {
                    Database::switchDatabase($originDbName2);
                    $db = Database::getInstance();
                }
            } catch (Throwable $e) {
                error_log('gudang-transfer load PO items error: ' . $e->getMessage());
            }
        }
    }
    if ($poData && !empty($poData['items'])) {
        foreach ($poData['items'] as $poItem) {
            $pItemName = trim((string)($poItem['item_name'] ?? ''));
            $pUnit = trim((string)($poItem['unit'] ?? 'pcs'));
            $pOrdered = (float)($poItem['quantity'] ?? 0);
            $pReceived = (float)($poItem['received_quantity'] ?? 0);
            $pRemaining = max(0, $pOrdered - $pReceived);
            // Exact then partial match against gudang stock
            $gStock = $db->fetchOne('SELECT * FROM gudang_nasita_stock WHERE LOWER(item_name) = LOWER(?) AND is_active = 1 LIMIT 1', [$pItemName]);
            if (!$gStock && $pItemName !== '') {
                $gStock = $db->fetchOne('SELECT * FROM gudang_nasita_stock WHERE LOWER(item_name) LIKE ? AND is_active = 1 ORDER BY quantity DESC LIMIT 1', ['%' . strtolower($pItemName) . '%']);
            }
            $poItemsWithStock[] = [
                'po_detail_id' => (int)($poItem['id'] ?? 0),
                'item_name'    => $pItemName,
                'unit'         => $pUnit,
                'ordered_qty'  => $pOrdered,
                'received_qty' => $pReceived,
                'remaining_qty'=> $pRemaining,
                'gudang_stock' => $gStock ?: null,
            ];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetBusinessId = (int)($_POST['target_business_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $sourcePoId = (int)($_POST['source_po_id'] ?? 0);
    $sourcePoBusinessSlug = trim((string)($_POST['source_po_business'] ?? ''));
    if (!in_array($sourcePoBusinessSlug, $allowedPoBusinessSlugs, true)) {
        $sourcePoBusinessSlug = '';
    }
    $redirectBase = 'gudang-transfer.php' . ($sourcePoId > 0 ? '?po_id=' . $sourcePoId . '&po_business=' . urlencode($sourcePoBusinessSlug) : '');

    // Build items array — support both multi-item (PO mode) and single-item (manual mode)
    $transferItems = [];
    if (!empty($_POST['transfer_items']) && is_array($_POST['transfer_items'])) {
        foreach ($_POST['transfer_items'] as $tItem) {
            $tStockId = (int)($tItem['stock_id'] ?? 0);
            $tQty = (float)($tItem['qty'] ?? 0);
            if ($tStockId > 0 && $tQty > 0) {
                $transferItems[] = ['stock_id' => $tStockId, 'quantity' => $tQty, 'notes' => $notes];
            }
        }
    } else {
        $stockId = (int)($_POST['stock_id'] ?? 0);
        $quantity = (float)($_POST['quantity'] ?? 0);
        if ($stockId > 0 && $quantity > 0) {
            $transferItems[] = ['stock_id' => $stockId, 'quantity' => $quantity, 'notes' => $notes];
        }
    }
    if (empty($transferItems)) {
        $sep = strpos($redirectBase, '?') !== false ? '&' : '?';
        header('Location: ' . $redirectBase . $sep . 'transfer_err=' . urlencode('Tidak ada item transfer yang valid. Isi qty minimal 1 item.'));
        exit;
    }

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

    $result = transferGudangNasitaStock($resolvedBizId ?: $targetBusinessId, $transferItems, $currentUser['id'], $notes, $sourcePoId, $resolvedBizName ?: null);

    // After successful transfer, update PO status in source business DB to 'completed'
    if ($result['success'] && $sourcePoId !== null && $sourcePoBusinessSlug !== '') {
        $poStatusCfgPath = __DIR__ . '/../../config/businesses/' . $sourcePoBusinessSlug . '.php';
        if (file_exists($poStatusCfgPath)) {
            $poStatusCfg = require $poStatusCfgPath;
            $poStatusDbName = (string)($poStatusCfg['database'] ?? '');
            if ($poStatusDbName !== '') {
                try {
                    $originDbForPo = Database::getCurrentDatabase();
                    $poStatusDb = Database::switchDatabase($poStatusDbName);
                    $poStatusDb->update('purchase_orders_header', ['status' => 'completed'], 'id = :id', ['id' => $sourcePoId]);
                    if (!empty($originDbForPo)) {
                        Database::switchDatabase($originDbForPo);
                    }
                } catch (Throwable $e) {
                    error_log('gudang-transfer PO status update error: ' . $e->getMessage());
                }
            }
        }
    }

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

<?php if ($prefillPoId > 0 && $poData): ?>
    <div class="alert alert-info" style="margin-bottom:1rem;">
        Transfer untuk PO <strong><?php echo htmlspecialchars($poData['po_number'] ?? ''); ?></strong>
        dari <strong><?php echo htmlspecialchars($prefillTargetBusinessName ?: $prefillPoBusinessSlug); ?></strong>.
        Isi qty yang akan dikirim untuk setiap item.
    </div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: 1.1fr 1fr; gap: 1.25rem; align-items:start;">
    <div class="card">
        <h3 style="font-size:1rem; font-weight:700; margin-bottom:1rem;">Form Transfer</h3>
        <form method="POST">
            <input type="hidden" name="source_po_id" value="<?php echo (int)$prefillPoId; ?>">
            <input type="hidden" name="source_po_business" value="<?php echo htmlspecialchars($prefillPoBusinessSlug); ?>">
            <input type="hidden" name="target_business_id" value="<?php echo (int)$prefillTargetBusinessId; ?>">

            <div class="form-group" style="margin-bottom:1rem;">
                <label class="form-label">Tujuan Bisnis</label>
                <?php if ($prefillPoId > 0): ?>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars(($prefillTargetBusinessName !== '' ? $prefillTargetBusinessName : 'Business')); ?>" readonly style="font-weight:700; background:#f8fafc; cursor:not-allowed;">
                <?php else: ?>
                    <select name="target_business_id" class="form-control" required>
                        <option value="">-- Pilih bisnis --</option>
                        <?php foreach ($allowedBusinesses as $biz): ?>
                            <option value="<?php echo (int)$biz['id']; ?>" <?php echo ($activeBusinessId > 0 && (int)$biz['id'] === $activeBusinessId) ? 'selected' : ''; ?>><?php echo htmlspecialchars($biz['business_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <?php if ($prefillPoId > 0 && !empty($poItemsWithStock)): ?>
                <!-- PO mode: table of PO items with gudang stock check -->
                <div style="overflow-x:auto; margin-bottom:1rem;">
                    <table class="table" style="font-size:0.875rem;">
                        <thead>
                            <tr>
                                <th>Item PO</th>
                                <th class="text-right">Diminta</th>
                                <th>Stok Gudang</th>
                                <th class="text-right" style="min-width:110px;">Kirim (qty)</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($poItemsWithStock as $idx => $pItem): ?>
                            <?php $gStock = $pItem['gudang_stock']; ?>
                            <tr style="<?php echo ($pItem['remaining_qty'] <= 0) ? 'opacity:0.5;' : ''; ?>">
                                <td style="font-weight:600;"><?php echo htmlspecialchars($pItem['item_name']); ?></td>
                                <td class="text-right"><?php echo number_format($pItem['remaining_qty'], 2); ?> <?php echo htmlspecialchars($pItem['unit']); ?></td>
                                <td>
                                    <?php if ($gStock): ?>
                                        <span style="color:<?php echo (float)$gStock['quantity'] > 0 ? '#0f9d6a' : '#d97706'; ?>; font-weight:600;">
                                            <?php echo number_format((float)$gStock['quantity'], 2); ?> <?php echo htmlspecialchars($gStock['unit']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;">Tidak ada di gudang</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($gStock && (float)$gStock['quantity'] > 0 && $pItem['remaining_qty'] > 0): ?>
                                        <input type="hidden" name="transfer_items[<?php echo $idx; ?>][stock_id]" value="<?php echo (int)$gStock['id']; ?>">
                                        <input type="number" name="transfer_items[<?php echo $idx; ?>][qty]" step="0.01" min="0" max="<?php echo min($pItem['remaining_qty'], (float)$gStock['quantity']); ?>" value="<?php echo min($pItem['remaining_qty'], (float)$gStock['quantity']); ?>" class="form-control" style="width:100px; text-align:right;">
                                    <?php else: ?>
                                        <span style="color:#9ca3af;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <!-- Simple mode: single item dropdown -->
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
            <?php endif; ?>

            <div class="form-group">
                <label class="form-label">Catatan</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Catatan pengiriman..."><?php echo htmlspecialchars($prefillNotes); ?></textarea>
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