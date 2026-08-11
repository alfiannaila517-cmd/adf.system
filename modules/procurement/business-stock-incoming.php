<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/procurement_functions.php';
require_once '../../includes/business_helper.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Rekaman Stock Masuk';

$activeBusinessSlug = strtolower((string)($_SESSION['active_business_id'] ?? ''));
$activeBusinessConfig = getActiveBusinessConfig();

$activeBusinessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;
$activeBusinessName = (string)($activeBusinessConfig['name'] ?? '');

$transferBusinessConfigs = [
    'narayana-hotel' => __DIR__ . '/../../config/businesses/narayana-hotel.php',
    'bens-cafe' => __DIR__ . '/../../config/businesses/bens-cafe.php',
    'eaat-meet' => __DIR__ . '/../../config/businesses/eaat-meet.php',
    'eat-meet' => __DIR__ . '/../../config/businesses/eaat-meet.php',
];

$transferBusinessOptions = [];
foreach ($transferBusinessConfigs as $slug => $cfgPath) {
    if (!file_exists($cfgPath)) {
        continue;
    }
    $cfg = require $cfgPath;
    if (!empty($cfg['name'])) {
        $transferBusinessOptions[$slug] = [
            'slug' => $slug,
            'name' => (string)$cfg['name'],
        ];
    }
}

if ($activeBusinessId > 0) {
    if ($activeBusinessName === '') {
        $businessRow = $db->fetchOne('SELECT business_name FROM businesses WHERE id = ? LIMIT 1', [$activeBusinessId]);
        $activeBusinessName = $businessRow['business_name'] ?? '';
    }
}

// Local baseline table allows reset-to-zero per business without deleting transfer history.
if ($activeBusinessId > 0) {
    try {
        $db->query("CREATE TABLE IF NOT EXISTS business_stock_reset_baseline (
            id INT AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            unit VARCHAR(50) NOT NULL,
            baseline_qty DECIMAL(15,2) NOT NULL DEFAULT 0,
            updated_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_business_item_unit (business_id, item_name, unit)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('business-stock-incoming baseline table error: ' . $e->getMessage());
    }
}

$incomingTransfers = [];
$rawStockSummary = [];
$stockSummary = [];
$baselineMap = [];
$rawStockMap = [];
$interTransferInMap = [];
$interTransferOutMap = [];
$masterPdo = null;

// Build map by item+unit for precise adjustments
$buildKey = function ($itemName, $unit) {
    return strtolower(trim((string)$itemName)) . '||' . strtolower(trim((string)$unit));
};

$getMapQty = function ($map, $key) {
    return isset($map[$key]) ? (float)$map[$key] : 0;
};

$computeVisibleQty = function ($itemName, $unit) use (&$rawStockMap, &$baselineMap, &$interTransferInMap, &$interTransferOutMap, $buildKey, $getMapQty) {
    $key = $buildKey($itemName, $unit);
    $gross = $getMapQty($rawStockMap, $key) + $getMapQty($interTransferInMap, $key) - $getMapQty($interTransferOutMap, $key);
    $visible = $gross - $getMapQty($baselineMap, $key);
    return $visible > 0 ? $visible : 0;
};

// Master DB table for inter-business transfers
try {
    $masterDsn = 'mysql:host=' . DB_HOST . ';dbname=' . (defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME) . ';charset=' . DB_CHARSET;
    $masterPdo = new PDO($masterDsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $masterPdo->exec("CREATE TABLE IF NOT EXISTS business_inter_stock_transfers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transfer_number VARCHAR(60) NOT NULL UNIQUE,
        source_business_slug VARCHAR(60) NOT NULL,
        source_business_name VARCHAR(255) NULL,
        target_business_slug VARCHAR(60) NOT NULL,
        target_business_name VARCHAR(255) NULL,
        item_name VARCHAR(255) NOT NULL,
        unit VARCHAR(50) NOT NULL,
        quantity DECIMAL(15,2) NOT NULL DEFAULT 0,
        notes TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_source_item (source_business_slug, item_name, unit),
        INDEX idx_target_item (target_business_slug, item_name, unit)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    $masterPdo = null;
    error_log('business-stock-incoming master transfer table error: ' . $e->getMessage());
}

if ($activeBusinessId > 0) {
    // gudang_nasita_transfers lives in Gudang DB (cross-DB read)
    $gudangCfgPath = __DIR__ . '/../../config/businesses/narayana-hotel.php';
    if (file_exists($gudangCfgPath)) {
        $gudangCfg = require $gudangCfgPath;
        $gudangDbName = (string)($gudangCfg['database'] ?? '');

        if ($gudangDbName !== '') {
            try {
                $originDbName = Database::getCurrentDatabase();
                $gudangDb = Database::switchDatabase($gudangDbName);

                $hasTargetBusinessId = false;
                try {
                    $transferCols = $gudangDb->fetchAll('SHOW COLUMNS FROM gudang_nasita_transfers');
                    foreach ($transferCols as $col) {
                        if (strtolower((string)($col['Field'] ?? '')) === 'target_business_id') {
                            $hasTargetBusinessId = true;
                            break;
                        }
                    }
                } catch (Throwable $e) {
                }

                $bizNameForMatch = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $activeBusinessName) . '%';

                if ($hasTargetBusinessId) {
                    $incomingTransfers = $gudangDb->fetchAll(
                        "SELECT
                            gt.id,
                            gt.transfer_number,
                            gt.target_business_name,
                            gt.status,
                            gt.notes,
                            gt.created_at,
                            gt.received_by,
                            u.full_name AS created_by_name,
                            r.full_name AS received_by_name,
                            COUNT(gti.id) AS items_count,
                            COALESCE(SUM(gti.quantity), 0) AS total_qty
                         FROM gudang_nasita_transfers gt
                         LEFT JOIN users u ON gt.created_by = u.id
                         LEFT JOIN users r ON gt.received_by = r.id
                         LEFT JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id
                         WHERE (gt.target_business_id = ? OR gt.target_business_name LIKE ?)
                         GROUP BY gt.id
                         ORDER BY gt.created_at DESC
                         LIMIT 100",
                        [$activeBusinessId, $bizNameForMatch]
                    );

                    $rawStockSummary = $gudangDb->fetchAll(
                        "SELECT
                            gti.item_name,
                            gti.unit,
                            COALESCE(SUM(gti.quantity), 0) AS total_received
                         FROM gudang_nasita_transfer_items gti
                         JOIN gudang_nasita_transfers gt ON gti.transfer_id = gt.id
                         WHERE (gt.target_business_id = ? OR gt.target_business_name LIKE ?)
                         GROUP BY gti.item_name, gti.unit
                         ORDER BY gti.item_name ASC",
                        [$activeBusinessId, $bizNameForMatch]
                    );
                } else {
                    $incomingTransfers = $gudangDb->fetchAll(
                        "SELECT
                            gt.id,
                            gt.transfer_number,
                            gt.target_business_name,
                            gt.status,
                            gt.notes,
                            gt.created_at,
                            gt.received_by,
                            u.full_name AS created_by_name,
                            r.full_name AS received_by_name,
                            COUNT(gti.id) AS items_count,
                            COALESCE(SUM(gti.quantity), 0) AS total_qty
                         FROM gudang_nasita_transfers gt
                         LEFT JOIN users u ON gt.created_by = u.id
                         LEFT JOIN users r ON gt.received_by = r.id
                         LEFT JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id
                         WHERE gt.target_business_name LIKE ?
                         GROUP BY gt.id
                         ORDER BY gt.created_at DESC
                         LIMIT 100",
                        [$bizNameForMatch]
                    );

                    $rawStockSummary = $gudangDb->fetchAll(
                        "SELECT
                            gti.item_name,
                            gti.unit,
                            COALESCE(SUM(gti.quantity), 0) AS total_received
                         FROM gudang_nasita_transfer_items gti
                         JOIN gudang_nasita_transfers gt ON gti.transfer_id = gt.id
                         WHERE gt.target_business_name LIKE ?
                         GROUP BY gti.item_name, gti.unit
                         ORDER BY gti.item_name ASC",
                        [$bizNameForMatch]
                    );
                }

                if (!empty($originDbName)) {
                    Database::switchDatabase($originDbName);
                    $db = Database::getInstance();
                }
            } catch (Throwable $e) {
                error_log('business-stock-incoming cross-db error: ' . $e->getMessage());
            }
        }
    }
}

foreach ($rawStockSummary as $row) {
    $itemName = (string)($row['item_name'] ?? '');
    $unit = (string)($row['unit'] ?? 'pcs');
    $key = $buildKey($itemName, $unit);
    $rawStockMap[$key] = (float)($row['total_received'] ?? 0);
}

if ($activeBusinessId > 0) {
    try {
        $baselineRows = $db->fetchAll(
            'SELECT item_name, unit, baseline_qty FROM business_stock_reset_baseline WHERE business_id = ?',
            [$activeBusinessId]
        );

        foreach ($baselineRows as $bRow) {
            $key = $buildKey($bRow['item_name'] ?? '', $bRow['unit'] ?? '');
            $baselineMap[$key] = (float)($bRow['baseline_qty'] ?? 0);
        }
    } catch (Throwable $e) {
        $baselineMap = [];
    }
}

if ($masterPdo && $activeBusinessSlug !== '') {
    try {
        $stmtIn = $masterPdo->prepare("SELECT item_name, unit, SUM(quantity) AS qty
            FROM business_inter_stock_transfers
            WHERE target_business_slug = ?
            GROUP BY item_name, unit");
        $stmtIn->execute([$activeBusinessSlug]);
        foreach ($stmtIn->fetchAll() as $row) {
            $interTransferInMap[$buildKey($row['item_name'] ?? '', $row['unit'] ?? '')] = (float)($row['qty'] ?? 0);
        }

        $stmtOut = $masterPdo->prepare("SELECT item_name, unit, SUM(quantity) AS qty
            FROM business_inter_stock_transfers
            WHERE source_business_slug = ?
            GROUP BY item_name, unit");
        $stmtOut->execute([$activeBusinessSlug]);
        foreach ($stmtOut->fetchAll() as $row) {
            $interTransferOutMap[$buildKey($row['item_name'] ?? '', $row['unit'] ?? '')] = (float)($row['qty'] ?? 0);
        }
    } catch (Throwable $e) {
        error_log('business-stock-incoming transfer aggregate error: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_business_stock_zero') {
    if ($activeBusinessId > 0) {
        try {
            foreach ($rawStockSummary as $row) {
                $itemName = trim((string)($row['item_name'] ?? ''));
                $unit = trim((string)($row['unit'] ?? 'pcs'));
                $qty = $computeVisibleQty($itemName, $unit);
                if ($itemName === '') {
                    continue;
                }

                $db->query(
                    "INSERT INTO business_stock_reset_baseline (business_id, item_name, unit, baseline_qty, updated_by)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE baseline_qty = VALUES(baseline_qty), updated_by = VALUES(updated_by)",
                    [$activeBusinessId, $itemName, $unit, $qty, (int)($currentUser['id'] ?? 0)]
                );
            }
            $_SESSION['success'] = 'Stok bisnis berhasil di-reset ke 0.';
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Gagal reset stok bisnis: ' . $e->getMessage();
        }
    }

    header('Location: business-stock-incoming.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_stock_item') {
    $itemName = trim((string)($_POST['item_name'] ?? ''));
    $unit = trim((string)($_POST['unit'] ?? 'pcs'));

    if ($activeBusinessId <= 0 || $itemName === '') {
        $_SESSION['error'] = 'Data item tidak valid untuk dihapus.';
    } else {
        try {
            $qtyNow = $computeVisibleQty($itemName, $unit);
            $key = $buildKey($itemName, $unit);
            $baselineNow = $getMapQty($baselineMap, $key);

            $db->query(
                "INSERT INTO business_stock_reset_baseline (business_id, item_name, unit, baseline_qty, updated_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE baseline_qty = VALUES(baseline_qty), updated_by = VALUES(updated_by)",
                [$activeBusinessId, $itemName, $unit, ($baselineNow + $qtyNow), (int)($currentUser['id'] ?? 0)]
            );
            $_SESSION['success'] = 'Item stok bisnis berhasil dihapus.';
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Gagal hapus item stok: ' . $e->getMessage();
        }
    }

    header('Location: business-stock-incoming.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'transfer_stock_business') {
    $itemName = trim((string)($_POST['item_name'] ?? ''));
    $unit = trim((string)($_POST['unit'] ?? 'pcs'));
    $targetSlug = strtolower(trim((string)($_POST['target_business_slug'] ?? '')));
    $qty = (float)($_POST['transfer_qty'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($activeBusinessSlug === '' || $itemName === '' || $qty <= 0 || $targetSlug === '' || $targetSlug === $activeBusinessSlug) {
        $_SESSION['error'] = 'Data transfer tidak valid.';
    } elseif (!$masterPdo) {
        $_SESSION['error'] = 'Fitur transfer antar bisnis belum siap (koneksi master DB gagal).';
    } else {
        $availableQty = $computeVisibleQty($itemName, $unit);
        if ($qty > $availableQty) {
            $_SESSION['error'] = 'Qty transfer melebihi stok tersedia (' . number_format($availableQty, 2) . ').';
        } else {
            try {
                $targetCfgPath = $transferBusinessConfigs[$targetSlug] ?? '';
                $targetName = $targetSlug;
                if ($targetCfgPath && file_exists($targetCfgPath)) {
                    $targetCfg = require $targetCfgPath;
                    $targetName = (string)($targetCfg['name'] ?? $targetSlug);
                }

                $prefix = 'BST-' . date('Ym') . '-';
                $last = $masterPdo->prepare("SELECT transfer_number FROM business_inter_stock_transfers WHERE transfer_number LIKE ? ORDER BY transfer_number DESC LIMIT 1");
                $last->execute([$prefix . '%']);
                $lastNo = $last->fetchColumn();
                $next = 1;
                if ($lastNo) {
                    $next = ((int)substr((string)$lastNo, -4)) + 1;
                }
                $transferNo = $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);

                $ins = $masterPdo->prepare("INSERT INTO business_inter_stock_transfers
                    (transfer_number, source_business_slug, source_business_name, target_business_slug, target_business_name, item_name, unit, quantity, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([
                    $transferNo,
                    $activeBusinessSlug,
                    $activeBusinessName,
                    $targetSlug,
                    $targetName,
                    $itemName,
                    $unit,
                    $qty,
                    $notes,
                    (int)($currentUser['id'] ?? 0)
                ]);

                $_SESSION['success'] = 'Transfer stok berhasil: ' . $transferNo;
            } catch (Throwable $e) {
                $_SESSION['error'] = 'Gagal transfer stok antar bisnis: ' . $e->getMessage();
            }
        }
    }

    header('Location: business-stock-incoming.php');
    exit;
}

if ($activeBusinessId > 0) {
    foreach ($rawStockSummary as $row) {
        $itemName = (string)($row['item_name'] ?? '');
        $unit = (string)($row['unit'] ?? 'pcs');
        $visibleQty = $computeVisibleQty($itemName, $unit);

        if ($visibleQty <= 0) {
            continue;
        }

        $stockSummary[] = [
            'item_name' => $itemName,
            'unit' => $unit,
            'total_received' => $visibleQty,
        ];
    }
}

$totalQtyVisible = 0;
foreach ($stockSummary as $row) {
    $totalQtyVisible += (float)($row['total_received'] ?? 0);
}

include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
    <div>
        <h2 style="font-size: 1.5rem; font-weight: 800; letter-spacing: -0.02em; color: var(--text-primary); margin-bottom: 0.25rem;">Stok &amp; Penerimaan Barang</h2>
        <p style="color: var(--text-muted); font-size: 0.875rem;">Stok terpisah untuk bisnis aktif<?php echo $activeBusinessName ? ' &mdash; ' . htmlspecialchars($activeBusinessName) : ''; ?></p>
    </div>
    <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
        <a href="purchase-orders.php" class="btn btn-secondary">
            <i data-feather="file-text" style="width: 16px; height: 16px;"></i>
            Purchase Orders
        </a>
        <form method="POST" onsubmit="return confirm('Reset stok bisnis ini ke 0? Histori transfer tetap aman.')" style="display:inline;">
            <input type="hidden" name="action" value="reset_business_stock_zero">
            <button type="submit" class="btn btn-danger">
                <i data-feather="rotate-ccw" style="width: 16px; height: 16px;"></i>
                Reset Stok ke 0
            </button>
        </form>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success" style="margin-bottom:1rem;"><?php echo htmlspecialchars($_SESSION['success']);
                                                                    unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger" style="margin-bottom:1rem;"><?php echo htmlspecialchars($_SESSION['error']);
                                                                unset($_SESSION['error']); ?></div>
<?php endif; ?>

<?php if ($activeBusinessId <= 0): ?>
    <div class="alert alert-warning">Tidak ada bisnis aktif di sesi ini.</div>
<?php else: ?>

    <div style="display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap:0.9rem; margin-bottom:1rem;">
        <div class="card" style="padding:0.9rem 1rem; border:1px solid #dbeafe; background:linear-gradient(145deg,#eff6ff,#ffffff);">
            <div style="font-size:0.75rem; color:#475569; margin-bottom:0.3rem;">Total Item Aktif</div>
            <div style="font-size:1.45rem; font-weight:800; color:#0f172a;"><?php echo count($stockSummary); ?></div>
        </div>
        <div class="card" style="padding:0.9rem 1rem; border:1px solid #dcfce7; background:linear-gradient(145deg,#f0fdf4,#ffffff);">
            <div style="font-size:0.75rem; color:#166534; margin-bottom:0.3rem;">Total Qty Stok Bisnis</div>
            <div style="font-size:1.45rem; font-weight:800; color:#14532d;"><?php echo number_format($totalQtyVisible, 2); ?></div>
        </div>
        <div class="card" style="padding:0.9rem 1rem; border:1px solid #fef3c7; background:linear-gradient(145deg,#fffbeb,#ffffff);">
            <div style="font-size:0.75rem; color:#92400e; margin-bottom:0.3rem;">Histori Transfer</div>
            <div style="font-size:1.45rem; font-weight:800; color:#78350f;"><?php echo count($incomingTransfers); ?></div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 1.25rem;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3 style="font-size:1rem; font-weight:700; margin:0;">Stok Bisnis (Total Diterima dari Gudang)</h3>
            <span style="font-size:0.8rem; color:var(--text-muted);"><?php echo count($stockSummary); ?> item | Khusus bisnis aktif</span>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nama Item</th>
                        <th>Unit</th>
                        <th class="text-right">Qty Saat Ini</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stockSummary)): ?>
                        <tr>
                            <td colspan="4" style="text-align:center; padding:2rem; color:var(--text-muted);">Belum ada stok masuk dari gudang.</td>
                        </tr>
                        <?php else: foreach ($stockSummary as $item): ?>
                            <tr>
                                <td style="font-weight:600;"><?php echo htmlspecialchars($item['item_name']); ?></td>
                                <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                <td class="text-right" style="font-weight:700; color:#0f9d6a;"><?php echo number_format((float)$item['total_received'], 2); ?></td>
                                <td class="text-center">
                                    <div style="display:flex; gap:0.4rem; justify-content:center; flex-wrap:wrap;">
                                        <button type="button" class="btn btn-sm btn-primary" style="height:32px; padding:0 0.7rem;" onclick="openTransferModal('<?php echo htmlspecialchars(addslashes($item['item_name'])); ?>','<?php echo htmlspecialchars(addslashes($item['unit'])); ?>','<?php echo htmlspecialchars((string)number_format((float)$item['total_received'], 2, '.', '')); ?>')">
                                            <i data-feather="send" style="width:13px; height:13px;"></i>
                                            Transfer
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus item stok ini dari bisnis aktif?')">
                                            <input type="hidden" name="action" value="delete_stock_item">
                                            <input type="hidden" name="item_name" value="<?php echo htmlspecialchars($item['item_name']); ?>">
                                            <input type="hidden" name="unit" value="<?php echo htmlspecialchars($item['unit']); ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" style="height:32px; padding:0 0.7rem;" title="Hapus item">
                                                <i data-feather="trash-2" style="width:13px; height:13px;"></i>
                                                Hapus
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                    <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3 style="font-size:1rem; font-weight:700; margin-bottom:1rem;">Histori Penerimaan dari Gudang</h3>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>No Transfer</th>
                        <th>Tanggal</th>
                        <th>Item</th>
                        <th class="text-right">Total Qty</th>
                        <th>Dikirim Oleh</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($incomingTransfers)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding:2rem; color:var(--text-muted);">Belum ada penerimaan barang dari gudang.</td>
                        </tr>
                        <?php else: foreach ($incomingTransfers as $transfer): ?>
                            <tr>
                                <td style="font-weight:600;"><?php echo htmlspecialchars($transfer['transfer_number']); ?></td>
                                <td style="font-size:0.875rem;"><?php echo !empty($transfer['created_at']) ? date('d M Y H:i', strtotime($transfer['created_at'])) : '-'; ?></td>
                                <td><?php echo (int)$transfer['items_count']; ?> item</td>
                                <td class="text-right" style="font-weight:600;"><?php echo number_format((float)$transfer['total_qty'], 2); ?></td>
                                <td style="font-size:0.875rem;"><?php echo htmlspecialchars($transfer['created_by_name'] ?? '-'); ?></td>
                            </tr>
                    <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

<div id="transferStockModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9999; align-items:center; justify-content:center; padding:1rem;">
    <div class="card" style="max-width:560px; width:100%; margin:0; border-radius:1rem; overflow:hidden; box-shadow:0 24px 64px rgba(0,0,0,0.25);">
        <div style="display:flex; justify-content:space-between; align-items:center; padding:1rem 1.25rem; background:linear-gradient(135deg,#4f46e5,#3730a3); color:#fff;">
            <div>
                <div style="font-size:1rem; font-weight:700;">Transfer Stock Antar Bisnis</div>
                <div style="font-size:0.82rem; opacity:0.9;" id="transferModalItemLabel">-</div>
            </div>
            <button type="button" onclick="closeTransferModal()" style="background:transparent; border:none; color:#fff; font-size:1.4rem; cursor:pointer;">&times;</button>
        </div>

        <form method="POST" style="padding:1rem 1.25rem;" onsubmit="return confirm('Kirim stok ke bisnis tujuan?')">
            <input type="hidden" name="action" value="transfer_stock_business">
            <input type="hidden" name="item_name" id="transfer_item_name" value="">
            <input type="hidden" name="unit" id="transfer_unit" value="">

            <div style="display:grid; grid-template-columns:1fr 140px; gap:0.75rem; margin-bottom:0.85rem;">
                <div>
                    <label class="form-label">Tujuan bisnis</label>
                    <select name="target_business_slug" class="form-control" required>
                        <option value="">Pilih bisnis tujuan</option>
                        <?php foreach ($transferBusinessOptions as $slug => $biz): ?>
                            <?php if (strtolower($slug) === $activeBusinessSlug): continue; endif; ?>
                            <option value="<?php echo htmlspecialchars($slug); ?>"><?php echo htmlspecialchars($biz['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Qty</label>
                    <input type="number" name="transfer_qty" id="transfer_qty" min="0.01" step="0.01" class="form-control" placeholder="Qty" required>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:0.75rem;">
                <label class="form-label">Catatan (opsional)</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Misal: transfer operasional antar outlet"></textarea>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; gap:0.75rem;">
                <div id="transferQtyInfo" style="font-size:0.82rem; color:#64748b;">Stok tersedia: -</div>
                <div style="display:flex; gap:0.5rem;">
                    <button type="button" class="btn btn-secondary" onclick="closeTransferModal()">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i data-feather="send" style="width:14px; height:14px;"></i>
                        Transfer Stock
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    function openTransferModal(itemName, unit, maxQty) {
        var modal = document.getElementById('transferStockModal');
        var itemInput = document.getElementById('transfer_item_name');
        var unitInput = document.getElementById('transfer_unit');
        var qtyInput = document.getElementById('transfer_qty');
        var label = document.getElementById('transferModalItemLabel');
        var qtyInfo = document.getElementById('transferQtyInfo');

        itemInput.value = itemName;
        unitInput.value = unit;
        qtyInput.value = '';
        qtyInput.max = maxQty;
        label.textContent = itemName + ' (' + unit + ')';
        qtyInfo.textContent = 'Stok tersedia: ' + maxQty + ' ' + unit;

        modal.style.display = 'flex';
    }

    function closeTransferModal() {
        var modal = document.getElementById('transferStockModal');
        modal.style.display = 'none';
    }

    window.addEventListener('click', function(e) {
        var modal = document.getElementById('transferStockModal');
        if (e.target === modal) {
            closeTransferModal();
        }
    });
</script>

<?php include '../../includes/footer.php'; ?>