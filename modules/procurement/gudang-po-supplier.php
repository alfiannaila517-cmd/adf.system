<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!($auth->hasPermission('gudang_nasita') || $auth->hasPermission('warehouse'))) {
    http_response_code(403);
    echo 'Akses Gudang Nasita ditolak.';
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'PO Supplier Gudang';

$ensureGudangPoSchema = function () use ($db) {
    try {
        $db->query("CREATE TABLE IF NOT EXISTS purchase_orders_header (
            id INT AUTO_INCREMENT PRIMARY KEY,
            business_id INT NULL,
            po_number VARCHAR(30) UNIQUE,
            supplier_id INT,
            po_date DATE NOT NULL,
            delivery_date DATE NULL,
            status VARCHAR(30) DEFAULT 'draft',
            total_amount DECIMAL(15,2) DEFAULT 0,
            notes TEXT,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_po_number (po_number),
            INDEX idx_supplier (supplier_id),
            INDEX idx_status (status),
            INDEX idx_po_date (po_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS purchase_orders_detail (
            id INT AUTO_INCREMENT PRIMARY KEY,
            po_header_id INT NOT NULL,
            line_number INT NULL,
            item_name VARCHAR(200) NOT NULL,
            item_description TEXT NULL,
            unit_of_measure VARCHAR(20) DEFAULT 'pcs',
            unit VARCHAR(20) NULL,
            quantity DECIMAL(15,2) NOT NULL DEFAULT 0,
            unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
            subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_price DECIMAL(15,2) NULL,
            received_quantity DECIMAL(15,2) NOT NULL DEFAULT 0,
            division_id INT NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_po_header (po_header_id),
            INDEX idx_item_name (item_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Backfill missing columns for older variants.
        $hdrCols = $db->fetchAll('SHOW COLUMNS FROM purchase_orders_header');
        $hdrNames = array_column($hdrCols, 'Field');
        $hdrRequired = [
            'po_number' => "VARCHAR(30) UNIQUE",
            'supplier_id' => 'INT NULL',
            'po_date' => 'DATE NULL',
            'status' => "VARCHAR(30) DEFAULT 'draft'",
            'total_amount' => 'DECIMAL(15,2) DEFAULT 0',
            'notes' => 'TEXT NULL',
            'created_by' => 'INT NULL',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ];
        foreach ($hdrRequired as $col => $def) {
            if (!in_array($col, $hdrNames, true)) {
                $db->query("ALTER TABLE purchase_orders_header ADD COLUMN `{$col}` {$def}");
            }
        }

        $dtlCols = $db->fetchAll('SHOW COLUMNS FROM purchase_orders_detail');
        $dtlNames = array_column($dtlCols, 'Field');
        $dtlRequired = [
            'po_header_id' => 'INT NOT NULL',
            'item_name' => "VARCHAR(200) NOT NULL DEFAULT ''",
            'unit_of_measure' => "VARCHAR(20) DEFAULT 'pcs'",
            'quantity' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
            'unit_price' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
            'subtotal' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
            'received_quantity' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        ];
        foreach ($dtlRequired as $col => $def) {
            if (!in_array($col, $dtlNames, true)) {
                $db->query("ALTER TABLE purchase_orders_detail ADD COLUMN `{$col}` {$def}");
            }
        }

        if (!in_array('unit_of_measure', $dtlNames, true) && in_array('unit', $dtlNames, true)) {
            $db->query("UPDATE purchase_orders_detail SET unit_of_measure = unit WHERE (unit_of_measure IS NULL OR unit_of_measure = '') AND unit IS NOT NULL");
        }
        if (!in_array('subtotal', $dtlNames, true) && in_array('total_price', $dtlNames, true)) {
            $db->query('UPDATE purchase_orders_detail SET subtotal = total_price WHERE subtotal = 0 AND total_price IS NOT NULL');
        }
    } catch (Throwable $e) {
        error_log('ensureGudangPoSchema error: ' . $e->getMessage());
    }
};

$ensureGudangPoSchema();

$createdById = null;
$userInDb = $db->fetchOne('SELECT id FROM users WHERE id = ? LIMIT 1', [$currentUser['id']]);
if ($userInDb) {
    $createdById = $currentUser['id'];
} else {
    // Current user not in this DB — use first available user as fallback
    $fallbackUser = $db->fetchOne('SELECT id FROM users ORDER BY id ASC LIMIT 1');
    $createdById = $fallbackUser ? (int)$fallbackUser['id'] : 1;
}

// ─── POST: buat PO baru ke supplier ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_po') {
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $notes      = trim($_POST['notes'] ?? '');
    $items      = $_POST['items'] ?? [];

    $validItems = [];
    foreach ($items as $it) {
        $nm = trim($it['item_name'] ?? '');
        $qt = (float)($it['quantity'] ?? 0);
        $un = trim($it['unit'] ?? 'pcs');
        $up = (float)($it['unit_price'] ?? 0);
        if ($nm !== '' && $qt > 0) {
            $validItems[] = ['item_name' => $nm, 'quantity' => $qt, 'unit' => $un, 'unit_price' => $up];
        }
    }

    if ($supplierId <= 0 || empty($validItems)) {
        $_SESSION['error'] = 'Pilih supplier dan tambahkan minimal 1 item.';
    } else {
        try {
            $poPrefix = 'GDN-' . date('Ymd') . '-';
            $lastPo = $db->fetchOne("SELECT po_number FROM purchase_orders_header WHERE po_number LIKE ? ORDER BY po_number DESC LIMIT 1", [$poPrefix . '%']);
            $poSeq  = $lastPo ? ((int)substr($lastPo['po_number'], -3) + 1) : 1;
            $poNumber = $poPrefix . str_pad($poSeq, 3, '0', STR_PAD_LEFT);

            $totalAmount = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $validItems));

            // Wrap in transaction so header rolls back if any detail insert fails
            $db->getConnection()->beginTransaction();

            $poHeaderId = $db->insert('purchase_orders_header', [
                'business_id'  => null,
                'po_number'    => $poNumber,
                'supplier_id'  => $supplierId,
                'po_date'      => date('Y-m-d'),
                'status'       => 'submitted',
                'total_amount' => $totalAmount,
                'notes'        => $notes ?: 'Restock Gudang Nasita',
                'created_by'   => $createdById,
            ]);
            if (!$poHeaderId) {
                throw new \RuntimeException('Gagal membuat header PO.');
            }

            // Probe optional columns once before the loop
            $detailCols    = $db->fetchAll("SHOW COLUMNS FROM purchase_orders_detail");
            $detailColNames = array_column($detailCols, 'Field');
            $firstDiv = in_array('division_id', $detailColNames)
                ? $db->fetchOne("SELECT id FROM divisions ORDER BY id ASC LIMIT 1")
                : null;

            foreach ($validItems as $idx => $it) {
                $detailData = [
                    'po_header_id'     => $poHeaderId,
                    'item_name'        => $it['item_name'],
                    'unit_of_measure'  => $it['unit'],
                    'quantity'         => $it['quantity'],
                    'unit_price'       => $it['unit_price'],
                    'subtotal'         => $it['quantity'] * $it['unit_price'],
                    'received_quantity' => 0,
                ];
                if (in_array('line_number', $detailColNames)) {
                    $detailData['line_number'] = $idx + 1;
                }
                if ($firstDiv) {
                    $detailData['division_id'] = (int)$firstDiv['id'];
                }
                $insertedId = $db->insert('purchase_orders_detail', $detailData);
                if (!$insertedId) {
                    throw new \RuntimeException('Gagal menyimpan item: ' . $it['item_name']);
                }
            }

            $db->getConnection()->commit();
            $_SESSION['success'] = 'PO ' . $poNumber . ' berhasil dibuat (' . count($validItems) . ' item).';
        } catch (Throwable $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->getConnection()->rollBack();
            }
            $_SESSION['error'] = 'Gagal buat PO: ' . $e->getMessage();
        }
    }
    header('Location: gudang-po-supplier.php');
    exit;
}

// ─── POST: terima barang dari supplier → tambah ke gudang ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'receive_goods') {
    $poId         = (int)($_POST['po_id'] ?? 0);
    $receivedItems = isset($_POST['received_qty']) && is_array($_POST['received_qty']) ? $_POST['received_qty'] : [];
    $notes        = trim($_POST['notes'] ?? '');

    $result = receivePurchaseOrderToGudang($poId, $receivedItems, $createdById ?? 1, $notes);
    if ($result['success']) {
        $_SESSION['success'] = 'Barang berhasil diterima ke Gudang Nasita.';
    } else {
        $_SESSION['error'] = $result['message'];
    }
    header('Location: gudang-po-supplier.php');
    exit;
}

// ─── POST: batalkan PO ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_po') {
    $poId = (int)($_POST['po_id'] ?? 0);
    if ($poId > 0) {
        $db->update('purchase_orders_header', ['status' => 'cancelled'], 'id = :id', ['id' => $poId]);
        $_SESSION['success'] = 'PO dibatalkan.';
    }
    header('Location: gudang-po-supplier.php');
    exit;
}
// ─── POST: hapus PO permanen ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_po') {
    $poId = (int)($_POST['po_id'] ?? 0);
    if ($poId > 0) {
        try {
            $db->query('DELETE FROM purchase_orders_detail WHERE po_header_id = ?', [$poId]);
            $db->query('DELETE FROM purchase_orders_header WHERE id = ?', [$poId]);
            $_SESSION['success'] = 'PO berhasil dihapus permanen.';
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Gagal hapus PO: ' . $e->getMessage();
        }
    }
    header('Location: gudang-po-supplier.php');
    exit;
}
// ─── Data ──────────────────────────────────────────────────────────────────
$viewPoId = (int)($_GET['view'] ?? 0);
$printPoId = (int)($_GET['print'] ?? 0);

$gudangPOs = $db->fetchAll("
    SELECT poh.*, s.supplier_name,
           COUNT(pod.id) AS items_count,
           COALESCE(SUM(pod.quantity), 0) AS total_ordered,
           COALESCE(SUM(pod.received_quantity), 0) AS total_received
    FROM purchase_orders_header poh
    LEFT JOIN suppliers s ON poh.supplier_id = s.id
    LEFT JOIN purchase_orders_detail pod ON pod.po_header_id = poh.id
    WHERE poh.business_id IS NULL AND poh.po_number LIKE 'GDN-%'
    GROUP BY poh.id
    ORDER BY poh.created_at DESC
    LIMIT 100
");

$suppliers = $db->fetchAll("SELECT id, supplier_name FROM suppliers WHERE is_active = 1 OR is_active IS NULL ORDER BY supplier_name ASC");
if (empty($suppliers)) {
    $suppliers = $db->fetchAll("SELECT id, supplier_name FROM suppliers ORDER BY supplier_name ASC");
}

$viewPo = null;
if ($viewPoId > 0) {
    $viewPo = getPurchaseOrder($viewPoId);
}

$printPo = null;
if ($printPoId > 0) {
    $printPo = getPurchaseOrder($printPoId);
}

// ─── Print mode ────────────────────────────────────────────────────────────
if ($printPo) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>PO ' . htmlspecialchars($printPo['po_number']) . '</title>';
    echo '<style>body{font-family:Arial,sans-serif;font-size:12px;margin:20px;}h2{margin:0 0 4px;}table{width:100%;border-collapse:collapse;margin-top:12px;}th,td{border:1px solid #999;padding:6px 8px;text-align:left;}th{background:#f0f0f0;}.text-right{text-align:right;}.footer{margin-top:24px;display:flex;justify-content:space-between;}@media print{button{display:none}}</style>';
    echo '</head><body>';
    echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;"><div>';
    echo '<h2>PURCHASE ORDER</h2>';
    echo '<strong>Gudang Nasita</strong><br>Narayana Hotel Karimunjawa';
    echo '</div><div style="text-align:right;">';
    echo '<strong>No PO:</strong> ' . htmlspecialchars($printPo['po_number']) . '<br>';
    echo '<strong>Tanggal:</strong> ' . date('d M Y', strtotime($printPo['po_date'])) . '<br>';
    echo '<strong>Status:</strong> ' . ucfirst($printPo['status']);
    echo '</div></div>';
    echo '<hr style="margin:12px 0;">';
    echo '<strong>Kepada Yth:</strong><br>';
    echo htmlspecialchars($printPo['supplier_name'] ?? '-');
    echo '<table><thead><tr><th>#</th><th>Item</th><th class="text-right">Qty</th><th>Unit</th><th class="text-right">Harga Satuan</th><th class="text-right">Total</th></tr></thead><tbody>';
    $grandTotal = 0;
    foreach ($printPo['items'] as $idx => $item) {
        $sub = (float)$item['quantity'] * (float)$item['unit_price'];
        $grandTotal += $sub;
        echo '<tr><td>' . ($idx + 1) . '</td><td>' . htmlspecialchars($item['item_name']) . '</td>';
        echo '<td class="text-right">' . number_format((float)$item['quantity'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($item['unit_of_measure'] ?: ($item['unit'] ?? '')) . '</td>';
        echo '<td class="text-right">Rp ' . number_format((float)$item['unit_price'], 0, ',', '.') . '</td>';
        echo '<td class="text-right">Rp ' . number_format($sub, 0, ',', '.') . '</td></tr>';
    }
    echo '</tbody><tfoot><tr><td colspan="5" class="text-right"><strong>Total</strong></td><td class="text-right"><strong>Rp ' . number_format($grandTotal, 0, ',', '.') . '</strong></td></tr></tfoot></table>';
    if (!empty($printPo['notes'])) {
        echo '<p style="margin-top:12px;"><strong>Catatan:</strong> ' . htmlspecialchars($printPo['notes']) . '</p>';
    }
    echo '<div class="footer"><div><strong>Dibuat oleh:</strong><br><br><br>___________________________<br>' . htmlspecialchars($printPo['created_by_name'] ?? 'Gudang Nasita') . '</div>';
    echo '<div><strong>Disetujui:</strong><br><br><br>___________________________<br>&nbsp;</div>';
    echo '<div><strong>Diterima:</strong><br><br><br>___________________________<br>Supplier</div></div>';
    echo '<br><button onclick="window.print()">🖨️ Cetak</button>';
    echo '</body></html>';
    exit;
}

$forceTheme = 'light';
include '../../includes/header.php';
?>

<div style="margin-bottom:1.25rem; display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
    <div>
        <h2 style="font-size:1.5rem; font-weight:700; color:var(--text-primary); margin-bottom:0.25rem;">PO Supplier Gudang</h2>
        <p style="color:var(--text-muted); font-size:0.875rem;">Pemesanan barang ke supplier untuk restock Gudang Nasita</p>
    </div>
    <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
        <a href="gudang-nasita.php" class="btn btn-secondary">
            <i data-feather="arrow-left" style="width:16px;height:16px;"></i> Kembali ke Gudang
        </a>
        <button type="button" class="btn btn-primary" onclick="document.getElementById('createPoModal').style.display='flex'">
            <i data-feather="plus" style="width:16px;height:16px;"></i> Buat PO Baru
        </button>
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

<?php if (empty($suppliers)): ?>
    <div class="alert alert-warning" style="margin-bottom:1rem;">
        ⚠️ Belum ada supplier. Tambahkan supplier terlebih dahulu di <a href="suppliers.php">menu Pemasok</a>.
    </div>
<?php endif; ?>

<!-- Terima Barang section (jika ada PO yang dibuka) -->
<?php if ($viewPo): ?>
    <div class="card" style="margin-bottom:1.25rem; border:2px solid #0f9d6a;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.75rem;">
            <div>
                <h3 style="font-size:1rem; font-weight:700; margin:0; color:#0f9d6a;">
                    📦 Terima Barang — <?php echo htmlspecialchars($viewPo['po_number']); ?>
                </h3>
                <div style="font-size:0.812rem; color:var(--text-muted); margin-top:0.2rem;">
                    Supplier: <strong><?php echo htmlspecialchars($viewPo['supplier_name'] ?? '-'); ?></strong> &nbsp;|&nbsp;
                    Tanggal PO: <?php echo date('d M Y', strtotime($viewPo['po_date'])); ?>
                </div>
            </div>
            <div style="display:flex; gap:0.5rem;">
                <a href="gudang-po-supplier.php?print=<?php echo (int)$viewPo['id']; ?>" target="_blank" class="btn btn-sm btn-primary" style="font-weight:700;">
                    <i data-feather="printer" style="width:14px;height:14px;"></i> Cetak PO
                </a>
                <a href="gudang-po-supplier.php" class="btn btn-sm btn-secondary">Tutup</a>
            </div>
        </div>

        <?php if (in_array($viewPo['status'], ['submitted', 'approved', 'partially_received'])): ?>
            <?php if (empty($viewPo['items'])): ?>
                <div class="alert alert-danger" style="margin-bottom:1rem;">
                    ⚠️ PO ini tidak memiliki detail item — kemungkinan dibuat saat terjadi error database sebelumnya.<br>
                    <strong>Batalkan PO ini dan buat PO baru.</strong>
                </div>
                <form method="POST" onsubmit="return confirm('Batalkan PO ini?')">
                    <input type="hidden" name="action" value="cancel_po">
                    <input type="hidden" name="po_id" value="<?php echo (int)$viewPo['id']; ?>">
                    <button type="submit" class="btn btn-danger">✕ Batalkan PO Ini</button>
                    <a href="gudang-po-supplier.php" class="btn btn-secondary" style="margin-left:0.5rem;">Kembali</a>
                </form>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="receive_goods">
                    <input type="hidden" name="po_id" value="<?php echo (int)$viewPo['id']; ?>">
                    <div class="table-responsive">
                        <table class="table" style="font-size:0.875rem;">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th class="text-right">Qty PO</th>
                                    <th>Unit</th>
                                    <th class="text-right">Sudah Diterima</th>
                                    <th class="text-right">Sisa</th>
                                    <th class="text-right" style="min-width:120px;">Qty Datang Sekarang</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($viewPo['items'] as $item):
                                    $remainQty = max(0, (float)$item['quantity'] - (float)($item['received_quantity'] ?? 0));
                                ?>
                                    <tr style="<?php echo $remainQty <= 0 ? 'opacity:0.5;' : ''; ?>">
                                        <td style="font-weight:600;"><?php echo htmlspecialchars($item['item_name']); ?></td>
                                        <td class="text-right"><?php echo number_format((float)$item['quantity'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($item['unit_of_measure'] ?: ($item['unit'] ?? '')); ?></td>
                                        <td class="text-right" style="color:#0f9d6a;"><?php echo number_format((float)($item['received_quantity'] ?? 0), 2); ?></td>
                                        <td class="text-right" style="font-weight:600; color:<?php echo $remainQty > 0 ? '#d97706' : '#6b7280'; ?>;"><?php echo number_format($remainQty, 2); ?></td>
                                        <td class="text-right">
                                            <?php if ($remainQty > 0): ?>
                                                <input type="number" name="received_qty[<?php echo (int)$item['id']; ?>]"
                                                    class="form-control" style="width:110px; text-align:right;"
                                                    step="0.01" min="0" max="<?php echo $remainQty; ?>"
                                                    value="<?php echo $remainQty; ?>">
                                            <?php else: ?>
                                                <span style="color:#6b7280;">✓ Lunas</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="form-group" style="margin-top:0.75rem;">
                        <label class="form-label">Catatan Penerimaan</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Catatan kondisi barang, dll."></textarea>
                    </div>
                    <div style="display:flex; justify-content:flex-end; margin-top:1rem;">
                        <button type="submit" class="btn btn-success" style="font-size:0.95rem; padding:0.6rem 1.5rem;">
                            <i data-feather="check-circle" style="width:16px;height:16px;"></i>
                            Tambahkan ke Gudang
                        </button>
                    </div>
                </form>
            <?php endif; // end empty/non-empty items check 
            ?>
        <?php else: ?>
            <div class="alert alert-info">PO ini sudah berstatus <strong><?php echo ucfirst(str_replace('_', ' ', $viewPo['status'])); ?></strong> — semua barang sudah diterima.</div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Daftar PO Gudang -->
<div class="card">
    <h3 style="font-size:1rem; font-weight:700; margin-bottom:1rem;">Daftar PO Supplier Gudang</h3>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>No PO</th>
                    <th>Tanggal</th>
                    <th>Supplier</th>
                    <th>Item</th>
                    <th>Status</th>
                    <th class="text-right">Total</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($gudangPOs)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:2.5rem; color:var(--text-muted);">Belum ada PO supplier. Klik "Buat PO Baru" untuk mulai.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($gudangPOs as $po):
                        $statusColor = ['submitted' => 'warning', 'completed' => 'success', 'cancelled' => 'danger', 'partially_received' => 'info'][$po['status']] ?? 'secondary';
                        $statusLabel = ['submitted' => '⏳ Menunggu Datang', 'completed' => '✓ Selesai', 'cancelled' => '✗ Dibatalkan', 'partially_received' => '📦 Sebagian Diterima'][$po['status']] ?? ucfirst($po['status']);
                    ?>
                        <tr>
                            <td style="font-weight:600;"><?php echo htmlspecialchars($po['po_number']); ?></td>
                            <td><?php echo date('d M Y', strtotime($po['po_date'])); ?></td>
                            <td><?php echo htmlspecialchars($po['supplier_name'] ?? '-'); ?></td>
                            <td><?php echo (int)$po['items_count']; ?> item</td>
                            <td><span class="badge badge-<?php echo $statusColor; ?>"><?php echo $statusLabel; ?></span></td>
                            <td class="text-right">Rp <?php echo number_format((float)($po['total_amount'] ?? 0), 0, ',', '.'); ?></td>
                            <td>
                                <div style="display:flex; gap:0.35rem; justify-content:center; flex-wrap:wrap;">
                                    <?php if (in_array($po['status'], ['submitted', 'partially_received'])): ?>
                                        <a href="gudang-po-supplier.php?view=<?php echo (int)$po['id']; ?>" class="btn btn-sm btn-success">
                                            <i data-feather="package" style="width:13px;height:13px;"></i> Terima Barang
                                        </a>
                                    <?php endif; ?>
                                    <a href="gudang-po-supplier.php?print=<?php echo (int)$po['id']; ?>" target="_blank" class="btn btn-sm btn-primary" style="font-weight:700;">
                                        <i data-feather="printer" style="width:13px;height:13px;"></i> Cetak PO
                                    </a>
                                    <?php if ($po['status'] === 'submitted'): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Batalkan PO ini?')">
                                            <input type="hidden" name="action" value="cancel_po">
                                            <input type="hidden" name="po_id" value="<?php echo (int)$po['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-warning" title="Batalkan">
                                                <i data-feather="x" style="width:13px;height:13px;"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus permanen PO ini? Data tidak bisa dikembalikan.')">
                                        <input type="hidden" name="action" value="delete_po">
                                        <input type="hidden" name="po_id" value="<?php echo (int)$po['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Hapus Permanen">
                                            <i data-feather="trash-2" style="width:13px;height:13px;"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    feather.replace();
    document.addEventListener('click', function(e) {
        if (e.target === document.getElementById('createPoModal')) document.getElementById('createPoModal').style.display = 'none';
    });

    function addItem() {
        var tbody = document.getElementById('itemsBody');
        var idx = tbody.querySelectorAll('tr').length;
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><input type="text" name="items[' + idx + '][item_name]" class="form-control" placeholder="Nama barang" required></td>' +
            '<td><input type="number" name="items[' + idx + '][quantity]" class="form-control" step="0.01" min="0.01" placeholder="0" required style="width:90px;"></td>' +
            '<td><input type="text" name="items[' + idx + '][unit]" class="form-control" placeholder="pcs" value="pcs" style="width:70px;"></td>' +
            '<td><input type="number" name="items[' + idx + '][unit_price]" class="form-control" step="1" min="0" placeholder="0" style="width:110px;"></td>' +
            '<td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest(\'tr\').remove()"><i data-feather="trash-2" style="width:13px;height:13px;"></i></button></td>';
        tbody.appendChild(tr);
        feather.replace();
    }
</script>

<!-- Modal Buat PO Baru -->
<div id="createPoModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:2100; align-items:center; justify-content:center; padding:1rem;">
    <div class="card" style="width:min(760px,100%); max-height:92vh; overflow:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3 style="font-size:1.05rem; margin:0;">📋 Buat PO Baru ke Supplier</h3>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('createPoModal').style.display='none'">Tutup</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_po">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.85rem; margin-bottom:1rem;">
                <div>
                    <label class="form-label">Supplier *</label>
                    <select name="supplier_id" class="form-control" required>
                        <option value="">-- Pilih Supplier --</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?php echo (int)$sup['id']; ?>"><?php echo htmlspecialchars($sup['supplier_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Catatan</label>
                    <input type="text" name="notes" class="form-control" placeholder="Contoh: Kebutuhan minggu ini">
                </div>
            </div>

            <div style="margin-bottom:0.75rem;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                    <label class="form-label" style="margin:0;">Item yang Dipesan *</label>
                    <button type="button" class="btn btn-sm btn-success" onclick="addItem()">+ Tambah Item</button>
                </div>
                <div class="table-responsive">
                    <table class="table" style="font-size:0.875rem;">
                        <thead>
                            <tr>
                                <th>Nama Item</th>
                                <th>Qty</th>
                                <th>Unit</th>
                                <th>Harga Satuan</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            <tr>
                                <td><input type="text" name="items[0][item_name]" class="form-control" placeholder="Nama barang" required></td>
                                <td><input type="number" name="items[0][quantity]" class="form-control" step="0.01" min="0.01" placeholder="0" required style="width:90px;"></td>
                                <td><input type="text" name="items[0][unit]" class="form-control" placeholder="pcs" value="pcs" style="width:70px;"></td>
                                <td><input type="number" name="items[0][unit_price]" class="form-control" step="1" min="0" placeholder="0" style="width:110px;"></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('createPoModal').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">Buat PO</button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>