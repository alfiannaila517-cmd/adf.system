<?php
/**
 * Gudang Nasita - Dashboard
 * Main warehouse overview and quick actions
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../includes/header.php';

$auth = Auth::getInstance();

// Check permission
if (!$auth->hasPermission('gudang_view')) {
    die("Access denied");
}

$masterDb = Database::switchDatabase(DB_NAME);

// Get warehouse statistics
$stats = [
    'total_barang' => 0,
    'stok_gudang' => 0,
    'po_pending' => 0,
    'transfer_pending' => 0,
    'stok_minimum' => 0,
];

try {
    // Total barang
    $row = $masterDb->fetchOne("SELECT COUNT(*) AS total FROM gudang_nasita_barang WHERE is_active = 1");
    $stats['total_barang'] = (int)($row['total'] ?? 0);
    
    // Total stok value
    $row = $masterDb->fetchOne("SELECT SUM(gs.jumlah_stok * gb.harga_beli) AS total_value FROM gudang_nasita_stock gs JOIN gudang_nasita_barang gb ON gs.barang_id = gb.id");
    $stats['stok_gudang'] = (float)($row['total_value'] ?? 0);
    
    // Pending PO
    $row = $masterDb->fetchOne("SELECT COUNT(*) AS total FROM gudang_nasita_po_supplier WHERE status IN ('submitted', 'approved', 'partially_received')");
    $stats['po_pending'] = (int)($row['total'] ?? 0);
    
    // Pending transfers
    $row = $masterDb->fetchOne("SELECT COUNT(*) AS total FROM gudang_nasita_transfers WHERE status IN ('submitted', 'approved')");
    $stats['transfer_pending'] = (int)($row['total'] ?? 0);
    
    // Minimum stock alerts
    $row = $masterDb->fetchOne("SELECT COUNT(*) AS total FROM gudang_nasita_minimum_stock ms JOIN gudang_nasita_stock gs ON ms.barang_id = gs.barang_id WHERE gs.jumlah_stok <= ms.minimum_qty");
    $stats['stok_minimum'] = (int)($row['total'] ?? 0);
    
} catch (Exception $e) {
    error_log("Gudang Dashboard Error: " . $e->getMessage());
}

?>

<div class="page-wrapper">
    <div class="page-header">
        <h1>Dashboard Gudang Nasita</h1>
    </div>

    <div class="container-fluid mt-4">
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h5 class="card-title">Total Barang</h5>
                        <h2><?php echo number_format($stats['total_barang']); ?></h2>
                        <small>Items di Gudang</small>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h5 class="card-title">Nilai Stok</h5>
                        <h2>Rp<?php echo number_format((int)$stats['stok_gudang'], 0); ?></h2>
                        <small>Total nilai barang</small>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <h5 class="card-title">PO Pending</h5>
                        <h2><?php echo number_format($stats['po_pending']); ?></h2>
                        <small>Belum selesai</small>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h5 class="card-title">Stok Minimum</h5>
                        <h2><?php echo number_format($stats['stok_minimum']); ?></h2>
                        <small>Perlu restock</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <a href="<?php echo BASE_URL; ?>/modules/gudang/barang.php" class="btn btn-primary">
                            <i data-feather="package"></i> Master Barang
                        </a>
                        <a href="<?php echo BASE_URL; ?>/modules/gudang/stock.php" class="btn btn-success">
                            <i data-feather="layers"></i> Manage Stok
                        </a>
                        <a href="<?php echo BASE_URL; ?>/modules/gudang/po-supplier.php" class="btn btn-warning">
                            <i data-feather="file-text"></i> PO Supplier
                        </a>
                        <a href="<?php echo BASE_URL; ?>/modules/gudang/transfer.php" class="btn btn-info">
                            <i data-feather="arrow-right"></i> Transfer ke Bisnis
                        </a>
                        <a href="<?php echo BASE_URL; ?>/modules/gudang/minimum-stock.php" class="btn btn-danger">
                            <i data-feather="alert-circle"></i> Minimum Stock
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Low Stock Alert -->
        <?php if ($stats['stok_minimum'] > 0): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="alert alert-warning">
                    <strong>⚠️ Perhatian!</strong> Ada <?php echo $stats['stok_minimum']; ?> item dengan stok di bawah minimum. 
                    <a href="<?php echo BASE_URL; ?>/modules/gudang/minimum-stock.php" class="alert-link">Lihat detail</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
