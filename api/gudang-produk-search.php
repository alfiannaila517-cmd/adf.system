<?php

/**
 * API: Gudang Produk Search
 * Autocomplete & lookup for gudang_nasita_barang master product table.
 */
define('APP_ACCESS', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db   = Database::getInstance();
$action = $_GET['action'] ?? 'search';

// Ensure gudang_nasita_barang exists
try {
    $db->query("CREATE TABLE IF NOT EXISTS `gudang_nasita_barang` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `kode_barang` VARCHAR(30) NULL,
        `nama_barang` VARCHAR(200) NOT NULL,
        `kategori`   VARCHAR(100) DEFAULT 'lainnya',
        `satuan`     VARCHAR(30)  DEFAULT 'pcs',
        `deskripsi`  TEXT NULL,
        `harga_beli` DECIMAL(15,2) DEFAULT 0,
        `harga_jual` DECIMAL(15,2) DEFAULT 0,
        `min_stock`  DECIMAL(15,2) DEFAULT 0,
        `is_active`  TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_nama_barang` (`nama_barang`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
}
try {
    $barangCols = array_column($db->fetchAll('SHOW COLUMNS FROM gudang_nasita_barang'), 'Field');
    if (!in_array('min_stock', $barangCols)) {
        $db->query('ALTER TABLE gudang_nasita_barang ADD COLUMN min_stock DECIMAL(15,2) DEFAULT 0 AFTER harga_jual');
    }
} catch (Throwable $e) {
}

// ─── Search (autocomplete) ───────────────────────────────────────────────────
if ($action === 'search') {
    $q = trim($_GET['q'] ?? '');
    if ($q === '') {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }
    $rows = $db->fetchAll(
        "SELECT id, kode_barang, nama_barang, kategori, satuan, COALESCE(harga_beli,0) AS harga_beli, COALESCE(min_stock,0) AS min_stock FROM gudang_nasita_barang
         WHERE COALESCE(is_active,1) = 1 AND nama_barang LIKE ?
         ORDER BY nama_barang ASC LIMIT 20",
        ['%' . $q . '%']
    );
    echo json_encode(['success' => true, 'data' => $rows ?: []]);
    exit;
}

// ─── List all products ───────────────────────────────────────────────────────
if ($action === 'list') {
    $rows = $db->fetchAll(
        "SELECT id, kode_barang, nama_barang, kategori, satuan, deskripsi, harga_beli, harga_jual, COALESCE(min_stock,0) AS min_stock, is_active
         FROM gudang_nasita_barang ORDER BY nama_barang ASC"
    );
    echo json_encode(['success' => true, 'data' => $rows ?: []]);
    exit;
}

// ─── Save (create / update) ──────────────────────────────────────────────────
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id         = (int)($_POST['id'] ?? 0);
    $nama       = trim($_POST['nama_barang'] ?? '');
    $kategori   = trim($_POST['kategori'] ?? 'lainnya') ?: 'lainnya';
    $satuan     = trim($_POST['satuan'] ?? 'pcs') ?: 'pcs';
    $deskripsi  = trim($_POST['deskripsi'] ?? '');
    $hargaBeli  = (float)($_POST['harga_beli'] ?? 0);
    $hargaJual  = (float)($_POST['harga_jual'] ?? 0);
    $minStock   = max(0, (float)($_POST['min_stock'] ?? 0));

    if ($nama === '') {
        echo json_encode(['success' => false, 'message' => 'Nama barang wajib diisi']);
        exit;
    }

    // Duplicate check (exclude self when editing)
    $dupeCheck = $db->fetchOne(
        "SELECT id FROM gudang_nasita_barang WHERE LOWER(nama_barang) = LOWER(?) AND id != ? LIMIT 1",
        [$nama, $id]
    );
    if ($dupeCheck) {
        echo json_encode(['success' => false, 'message' => "Nama \"$nama\" sudah ada di database produk (ID #{$dupeCheck['id']})."]);
        exit;
    }

    $data = [
        'nama_barang' => $nama,
        'kategori'    => $kategori,
        'satuan'      => $satuan,
        'deskripsi'   => $deskripsi,
        'harga_beli'  => $hargaBeli,
        'harga_jual'  => $hargaJual,
        'min_stock'   => $minStock,
        'is_active'   => 1,
    ];

    if ($id > 0) {
        $db->update('gudang_nasita_barang', $data, 'id = :id', ['id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Produk berhasil diperbarui', 'id' => $id]);
    } else {
        // Auto-generate kode_barang
        $prefix = 'BRG-';
        $last = $db->fetchOne('SELECT kode_barang FROM gudang_nasita_barang WHERE kode_barang LIKE ? ORDER BY kode_barang DESC LIMIT 1', [$prefix . '%']);
        $seq = $last ? ((int)substr($last['kode_barang'], -4) + 1) : 1;
        $data['kode_barang'] = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);

        $newId = $db->insert('gudang_nasita_barang', $data);
        echo json_encode(['success' => true, 'message' => 'Produk berhasil ditambahkan', 'id' => $newId]);
    }
    exit;
}

// ─── Toggle active ───────────────────────────────────────────────────────────
if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $cur = $db->fetchOne('SELECT is_active FROM gudang_nasita_barang WHERE id = ?', [$id]);
        if ($cur) {
            $db->update('gudang_nasita_barang', ['is_active' => $cur['is_active'] ? 0 : 1], 'id = :id', ['id' => $id]);
            echo json_encode(['success' => true]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'message' => 'Produk tidak ditemukan']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
