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

$incomingTransfers = [];
$businessPurchaseOrders = [];

if ($activeBusinessId > 0) {
    // POs live in the current (business) DB
    $businessPurchaseOrders = $db->fetchAll("
        SELECT
            poh.id,
            poh.po_number,
            poh.po_date,
            poh.status,
            poh.notes,
            poh.created_at,
            s.supplier_name,
            COUNT(pod.id) AS items_count,
            COALESCE(SUM(pod.quantity), 0) AS ordered_qty,
            COALESCE(SUM(pod.received_quantity), 0) AS received_qty
        FROM purchase_orders_header poh
        LEFT JOIN suppliers s ON s.id = poh.supplier_id
        LEFT JOIN purchase_orders_detail pod ON pod.po_header_id = poh.id
        WHERE poh.business_id = ?
        GROUP BY poh.id
        ORDER BY poh.created_at DESC
        LIMIT 100
    ", [$activeBusinessId]);

    // gudang_nasita_transfers lives in Narayana (gudang) DB — cross-DB query
    $gudangCfgPath = __DIR__ . '/../../config/businesses/narayana-hotel.php';
    if (file_exists($gudangCfgPath)) {
        $gudangCfg = require $gudangCfgPath;
        $gudangDbName = (string)($gudangCfg['database'] ?? '');
        if ($gudangDbName !== '') {
            try {
                $originDbName = Database::getCurrentDatabase();
                $gudangDb = Database::switchDatabase($gudangDbName);
                // Match by name so ID mismatches across DBs are not a problem
                $bizNameForMatch = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $activeBusinessName) . '%';
                $incomingTransfers = $gudangDb->fetchAll("
                    SELECT
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
                    LIMIT 100
                ", [$bizNameForMatch]);
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

// Aggregate stock per item from all received transfers (also from gudang DB)
$stockSummary = [];
if (!empty($incomingTransfers)) {
    $gudangCfgPath2 = __DIR__ . '/../../config/businesses/narayana-hotel.php';
    if (file_exists($gudangCfgPath2)) {
        $gudangCfg2 = require $gudangCfgPath2;
        $gudangDbName2 = (string)($gudangCfg2['database'] ?? '');
        if ($gudangDbName2 !== '') {
            try {
                $originDbName3 = Database::getCurrentDatabase();
                $gudangDb3 = Database::switchDatabase($gudangDbName2);
                $bizNameForMatch2 = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $activeBusinessName) . '%';
                $stockRows = $gudangDb3->fetchAll("
                    SELECT gti.item_name, gti.unit, COALESCE(SUM(gti.quantity), 0) AS total_received
                    FROM gudang_nasita_transfer_items gti
                    JOIN gudang_nasita_transfers gt ON gti.transfer_id = gt.id
                    WHERE gt.target_business_name LIKE ?
                    GROUP BY gti.item_name, gti.unit
                    ORDER BY gti.item_name ASC
                ", [$bizNameForMatch2]);
                $stockSummary = $stockRows;
                if (!empty($originDbName3)) {
                    Database::switchDatabase($originDbName3);
                    $db = Database::getInstance();
                }
            } catch (Throwable $e) {
                error_log('business-stock-incoming stock summary error: ' . $e->getMessage());
            }
        }
    }
}

include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
    <div>
        <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">Stok &amp; Penerimaan Barang</h2>
        <p style="color: var(--text-muted); font-size: 0.875rem;">Stok yang sudah diterima dari Gudang Nasita<?php echo $activeBusinessName ? ' &mdash; ' . htmlspecialchars($activeBusinessName) : ''; ?></p>
    </div>
    <a href="purchase-orders.php" class="btn btn-secondary">
        <i data-feather="file-text" style="width: 16px; height: 16px;"></i>
        Purchase Orders
    </a>
</div>

<?php if ($activeBusinessId <= 0): ?>
    <div class="alert alert-warning">Tidak ada bisnis aktif di sesi ini.</div>
<?php else: ?>

    <!-- Stock inventory per item -->
    <div class="card" style="margin-bottom: 1.25rem;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3 style="font-size:1rem; font-weight:700; margin:0;">Stok Bisnis (Total Diterima dari Gudang)</h3>
            <span style="font-size:0.8rem; color:var(--text-muted);"><?php echo count($stockSummary); ?> item</span>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nama Item</th>
                        <th>Unit</th>
                        <th class="text-right">Total Diterima</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stockSummary)): ?>
                        <tr><td colspan="3" style="text-align:center; padding:2rem; color:var(--text-muted);">Belum ada stok masuk dari gudang.</td></tr>
                    <?php else: foreach ($stockSummary as $sItem): ?>
                        <tr>
                            <td style="font-weight:600;"><?php echo htmlspecialchars($sItem['item_name']); ?></td>
                            <td><?php echo htmlspecialchars($sItem['unit']); ?></td>
                            <td class="text-right" style="font-weight:700; color:#0f9d6a;"><?php echo number_format((float)$sItem['total_received'], 2); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Incoming transfer history -->
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
                        <tr><td colspan="5" style="text-align:center; padding:2rem; color:var(--text-muted);">Belum ada penerimaan barang dari gudang.</td></tr>
                    <?php else: foreach ($incomingTransfers as $transfer): ?>
                        <tr>
                            <td style="font-weight:600;"><?php echo htmlspecialchars($transfer['transfer_number']); ?></td>
                            <td style="font-size:0.875rem;"><?php echo !empty($transfer['created_at']) ? date('d M Y H:i', strtotime($transfer['created_at'])) : '-'; ?></td>
                            <td><?php echo (int)$transfer['items_count']; ?> item</td>
                            <td class="text-right" style="font-weight:600;"><?php echo number_format((float)$transfer['total_qty'], 2); ?></td>
                            <td style="font-size:0.875rem;"><?php echo htmlspecialchars($transfer['created_by_name'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>