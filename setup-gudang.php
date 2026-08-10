<?php
/**
 * Setup Script - Gudang Nasita Database Tables
 * Run this once to initialize Gudang Nasita warehouse system
 */

require_once __DIR__ . '/config/database.php';

try {
    echo "[Gudang Nasita Setup]\n";
    echo "=" . str_repeat("-", 50) . "\n\n";
    
    $db = Database::getInstance();
    $masterDb = Database::switchDatabase(DB_NAME);
    
    // SQL Setup (prefer file, fallback to embedded SQL for production deploy safety)
    $sql_file = __DIR__ . '/sql/setup-gudang-nasita.sql';
    if (file_exists($sql_file)) {
        $sql_content = file_get_contents($sql_file);
        echo "Using SQL file: {$sql_file}\n";
    } else {
        echo "SQL file not found, using embedded fallback SQL...\n";
        $sql_content = <<<'SQL'
CREATE TABLE IF NOT EXISTS gudang_nasita_barang (
    id INT PRIMARY KEY AUTO_INCREMENT,
    kode_barang VARCHAR(50) UNIQUE NOT NULL,
    nama_barang VARCHAR(255) NOT NULL,
    deskripsi TEXT,
    satuan VARCHAR(20) NOT NULL DEFAULT 'pcs',
    harga_beli DECIMAL(12,2) NOT NULL DEFAULT 0,
    harga_jual DECIMAL(12,2) NOT NULL DEFAULT 0,
    kategori VARCHAR(100),
    supplier_id INT,
    is_active TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_kode (kode_barang),
    INDEX idx_kategori (kategori),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gudang_nasita_stock (
    id INT PRIMARY KEY AUTO_INCREMENT,
    barang_id INT NOT NULL,
    jumlah_stok INT NOT NULL DEFAULT 0,
    lokasi_gudang VARCHAR(100),
    terakhir_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    UNIQUE KEY unique_barang (barang_id),
    CONSTRAINT fk_gns_barang FOREIGN KEY (barang_id) REFERENCES gudang_nasita_barang(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gudang_nasita_supplier (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nama_supplier VARCHAR(255) NOT NULL,
    kontak_person VARCHAR(255),
    telepon VARCHAR(20),
    email VARCHAR(100),
    alamat TEXT,
    kota VARCHAR(100),
    is_active TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gudang_nasita_po_supplier (
    id INT PRIMARY KEY AUTO_INCREMENT,
    no_po VARCHAR(50) UNIQUE NOT NULL,
    supplier_id INT NOT NULL,
    tanggal_po DATE NOT NULL,
    tanggal_dibutuhkan DATE,
    status ENUM('draft', 'submitted', 'approved', 'partially_received', 'received', 'cancelled') DEFAULT 'draft',
    total_harga DECIMAL(14,2) NOT NULL DEFAULT 0,
    keterangan TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    INDEX idx_no_po (no_po),
    INDEX idx_status (status),
    INDEX idx_tanggal (tanggal_po),
    CONSTRAINT fk_gnps_supplier FOREIGN KEY (supplier_id) REFERENCES gudang_nasita_supplier(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gudang_nasita_transfers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    no_transfer VARCHAR(50) UNIQUE NOT NULL,
    bisnis_tujuan_id INT NOT NULL,
    tanggal_transfer DATE NOT NULL,
    status ENUM('draft', 'submitted', 'approved', 'received', 'cancelled') DEFAULT 'draft',
    catatan TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    INDEX idx_bisnis (bisnis_tujuan_id),
    INDEX idx_status (status),
    INDEX idx_tanggal (tanggal_transfer),
    CONSTRAINT fk_gnt_bisnis FOREIGN KEY (bisnis_tujuan_id) REFERENCES businesses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gudang_nasita_minimum_stock (
    id INT PRIMARY KEY AUTO_INCREMENT,
    barang_id INT NOT NULL,
    minimum_qty INT NOT NULL DEFAULT 10,
    alert_status ENUM('normal', 'warning', 'critical') DEFAULT 'normal',
    last_alert TIMESTAMP NULL,
    is_active TINYINT DEFAULT 1,
    UNIQUE KEY unique_barang (barang_id),
    CONSTRAINT fk_gnms_barang FOREIGN KEY (barang_id) REFERENCES gudang_nasita_barang(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    }

    $statements = array_filter(array_map('trim', explode(';', $sql_content)));
    
    $success_count = 0;
    $skip_count = 0;
    
    foreach ($statements as $statement) {
        if (empty($statement) || strpos(trim($statement), '--') === 0) {
            continue;
        }
        
        try {
            $db->execute($statement);
            $success_count++;
            echo "✓ Executed: " . substr(trim($statement), 0, 60) . "...\n";
        } catch (Exception $e) {
            // Ignore "table already exists" type errors
            if (strpos($e->getMessage(), 'already exists') !== false) {
                $skip_count++;
                echo "⊘ Skipped (already exists): " . substr(trim($statement), 0, 50) . "...\n";
            } else {
                echo "✗ ERROR: " . $e->getMessage() . "\n";
                throw $e;
            }
        }
    }
    
    // Ensure Gudang Nasita appears in business dropdown
    $existingBusiness = $masterDb->fetchOne("SELECT id FROM businesses WHERE slug = ? LIMIT 1", ['gudang-nasita']);
    if ($existingBusiness) {
        echo "⊘ Business exists: Gudang Nasita (ID: " . (int)$existingBusiness['id'] . ")\n";
    } else {
        $masterDb->execute(
            "INSERT INTO businesses (business_name, business_code, slug, database_name, business_type, is_active, status, created_at) VALUES (?, ?, ?, ?, ?, 1, 'active', NOW())",
            ['Gudang Nasita', 'gudang-nasita', 'gudang-nasita', DB_NAME, 'warehouse']
        );
        echo "✓ Business inserted: Gudang Nasita\n";
    }

    echo "\n" . str_repeat("-", 50) . "\n";
    echo "Setup completed!\n";
    echo "✓ Executed: {$success_count} statements\n";
    echo "⊘ Skipped: {$skip_count} statements (already exist)\n";
    echo "\nGudang Nasita system is ready!\n";
    echo "Business added: Gudang Nasita (warehouse)\n";
    echo "\nNext steps:\n";
    echo "1. Go to Developer Panel > User Permissions\n";
    echo "2. Select 'Gudang Nasita' from business dropdown\n";
    echo "3. Assign warehouse permissions to your user\n";
    echo "4. Login & select 'Gudang Nasita' from business dropdown\n";
    echo "5. Menu 'Gudang Nasita' will appear in sidebar\n";
    
} catch (Exception $e) {
    echo "\n✗ Setup failed: " . $e->getMessage() . "\n";
    http_response_code(500);
    exit(1);
}
?>
