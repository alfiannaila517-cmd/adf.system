-- Gudang Nasita System Setup SQL
-- Master Database: adfb2574_adf
-- Create warehouse management tables

USE adfb2574_adf;

-- ===== 1. MASTER DATA BARANG =====
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

-- ===== 2. STOK GUDANG =====
CREATE TABLE IF NOT EXISTS gudang_nasita_stock (
    id INT PRIMARY KEY AUTO_INCREMENT,
    barang_id INT NOT NULL,
    jumlah_stok INT NOT NULL DEFAULT 0,
    lokasi_gudang VARCHAR(100),
    terakhir_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    FOREIGN KEY (barang_id) REFERENCES gudang_nasita_barang(id),
    UNIQUE KEY unique_barang (barang_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== 3. HISTORY STOK =====
CREATE TABLE IF NOT EXISTS gudang_nasita_stock_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    barang_id INT NOT NULL,
    tipe_transaksi ENUM('masuk_po', 'keluar_transfer', 'adjustment', 'return') NOT NULL,
    jumlah INT NOT NULL,
    stok_sebelum INT,
    stok_sesudah INT,
    keterangan TEXT,
    referensi_id INT,
    referensi_tipe VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (barang_id) REFERENCES gudang_nasita_barang(id),
    INDEX idx_barang (barang_id),
    INDEX idx_tipe (tipe_transaksi),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== 4. MINIMUM STOCK ALERT =====
CREATE TABLE IF NOT EXISTS gudang_nasita_minimum_stock (
    id INT PRIMARY KEY AUTO_INCREMENT,
    barang_id INT NOT NULL,
    minimum_qty INT NOT NULL DEFAULT 10,
    alert_status ENUM('normal', 'warning', 'critical') DEFAULT 'normal',
    last_alert TIMESTAMP,
    is_active TINYINT DEFAULT 1,
    FOREIGN KEY (barang_id) REFERENCES gudang_nasita_barang(id),
    UNIQUE KEY unique_barang (barang_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== 5. SUPPLIER =====
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

-- ===== 6. PO KE SUPPLIER =====
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
    FOREIGN KEY (supplier_id) REFERENCES gudang_nasita_supplier(id),
    INDEX idx_no_po (no_po),
    INDEX idx_status (status),
    INDEX idx_tanggal (tanggal_po)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== 7. DETAIL PO SUPPLIER =====
CREATE TABLE IF NOT EXISTS gudang_nasita_po_detail (
    id INT PRIMARY KEY AUTO_INCREMENT,
    po_id INT NOT NULL,
    barang_id INT NOT NULL,
    jumlah_pesan INT NOT NULL,
    jumlah_diterima INT DEFAULT 0,
    harga_satuan DECIMAL(12,2) NOT NULL,
    subtotal DECIMAL(14,2) NOT NULL,
    keterangan TEXT,
    FOREIGN KEY (po_id) REFERENCES gudang_nasita_po_supplier(id) ON DELETE CASCADE,
    FOREIGN KEY (barang_id) REFERENCES gudang_nasita_barang(id),
    INDEX idx_po (po_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== 8. RECEIVING PO (PENERIMAAN BARANG) =====
CREATE TABLE IF NOT EXISTS gudang_nasita_receiving (
    id INT PRIMARY KEY AUTO_INCREMENT,
    po_id INT NOT NULL,
    no_receiving VARCHAR(50) UNIQUE NOT NULL,
    tanggal_terima DATE NOT NULL,
    status ENUM('draft', 'received', 'verified', 'cancelled') DEFAULT 'draft',
    keterangan TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    received_by INT,
    verified_by INT,
    FOREIGN KEY (po_id) REFERENCES gudang_nasita_po_supplier(id),
    INDEX idx_po (po_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== 9. DETAIL RECEIVING =====
CREATE TABLE IF NOT EXISTS gudang_nasita_receiving_detail (
    id INT PRIMARY KEY AUTO_INCREMENT,
    receiving_id INT NOT NULL,
    barang_id INT NOT NULL,
    jumlah_terima INT NOT NULL,
    jumlah_retur INT DEFAULT 0,
    keterangan TEXT,
    FOREIGN KEY (receiving_id) REFERENCES gudang_nasita_receiving(id) ON DELETE CASCADE,
    FOREIGN KEY (barang_id) REFERENCES gudang_nasita_barang(id),
    INDEX idx_receiving (receiving_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== 10. TRANSFER KE BISNIS =====
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
    FOREIGN KEY (bisnis_tujuan_id) REFERENCES businesses(id),
    INDEX idx_bisnis (bisnis_tujuan_id),
    INDEX idx_status (status),
    INDEX idx_tanggal (tanggal_transfer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== 11. DETAIL TRANSFER =====
CREATE TABLE IF NOT EXISTS gudang_nasita_transfer_detail (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transfer_id INT NOT NULL,
    barang_id INT NOT NULL,
    jumlah_transfer INT NOT NULL,
    jumlah_diterima INT DEFAULT 0,
    jumlah_retur INT DEFAULT 0,
    keterangan TEXT,
    FOREIGN KEY (transfer_id) REFERENCES gudang_nasita_transfers(id) ON DELETE CASCADE,
    FOREIGN KEY (barang_id) REFERENCES gudang_nasita_barang(id),
    INDEX idx_transfer (transfer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== 12. STOCK PER BISNIS (VIEW STOK YANG MEREKA TERIMA) =====
CREATE TABLE IF NOT EXISTS gudang_nasita_bisnis_stock (
    id INT PRIMARY KEY AUTO_INCREMENT,
    barang_id INT NOT NULL,
    bisnis_id INT NOT NULL,
    jumlah_stok INT NOT NULL DEFAULT 0,
    terakhir_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barang_id) REFERENCES gudang_nasita_barang(id),
    FOREIGN KEY (bisnis_id) REFERENCES businesses(id),
    UNIQUE KEY unique_barang_bisnis (barang_id, bisnis_id),
    INDEX idx_bisnis (bisnis_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
