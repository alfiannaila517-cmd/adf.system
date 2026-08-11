<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Rekaman Stock Masuk';

$activeBusinessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;
$activeBusinessName = '';

if ($activeBusinessId > 0) {
    $businessRow = $db->fetchOne('SELECT business_name FROM businesses WHERE id = ? LIMIT 1', [$activeBusinessId]);
    $activeBusinessName = $businessRow['business_name'] ?? '';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_business_stock_zero') {
    if ($activeBusinessId > 0) {
        try {
            foreach ($rawStockSummary as $row) {
                $itemName = trim((string)($row['item_name'] ?? ''));
                $unit = trim((string)($row['unit'] ?? 'pcs'));
                $qty = (float)($row['total_received'] ?? 0);
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

if ($activeBusinessId > 0) {
    try {
        $baselineRows = $db->fetchAll(
            'SELECT item_name, unit, baseline_qty FROM business_stock_reset_baseline WHERE business_id = ?',
            [$activeBusinessId]
        );

        $baselineMap = [];
        foreach ($baselineRows as $bRow) {
            $key = strtolower(trim((string)$bRow['item_name'])) . '||' . strtolower(trim((string)$bRow['unit']));
            $baselineMap[$key] = (float)$bRow['baseline_qty'];
        }

        foreach ($rawStockSummary as $row) {
            $itemName = (string)($row['item_name'] ?? '');
            $unit = (string)($row['unit'] ?? 'pcs');
            $totalReceived = (float)($row['total_received'] ?? 0);
            $key = strtolower(trim($itemName)) . '||' . strtolower(trim($unit));
            $baselineQty = $baselineMap[$key] ?? 0;
            $visibleQty = $totalReceived - $baselineQty;
            if ($visibleQty < 0) {
                $visibleQty = 0;
            }

            $stockSummary[] = [
                'item_name' => $itemName,
                'unit' => $unit,
                'total_received' => $visibleQty,
            ];
        }
    } catch (Throwable $e) {
        $stockSummary = $rawStockSummary;
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
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stockSummary)): ?>
                        <tr>
                            <td colspan="3" style="text-align:center; padding:2rem; color:var(--text-muted);">Belum ada stok masuk dari gudang.</td>
                        </tr>
                        <?php else: foreach ($stockSummary as $item): ?>
                            <tr>
                                <td style="font-weight:600;"><?php echo htmlspecialchars($item['item_name']); ?></td>
                                <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                <td class="text-right" style="font-weight:700; color:#0f9d6a;"><?php echo number_format((float)$item['total_received'], 2); ?></td>
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

<?php include '../../includes/footer.php'; ?>
