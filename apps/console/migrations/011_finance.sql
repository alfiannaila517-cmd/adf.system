-- Pembukuan ADF: catatan manual di luar pembayaran otomatis (Pakasir / invoice) —
-- uang masuk tunai/transfer, pengeluaran (VPS, domain, gaji, …), dan penarikan saldo Pakasir ke rekening.
CREATE TABLE IF NOT EXISTS finance_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_date DATE NOT NULL,
    kind ENUM('in', 'out', 'withdraw') NOT NULL COMMENT 'in = uang masuk, out = pengeluaran, withdraw = tarik dana Pakasir (bukan pendapatan)',
    category VARCHAR(60) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    method VARCHAR(40) NULL,
    note VARCHAR(300) NULL,
    client_id INT UNSIGNED NULL,
    created_by VARCHAR(60) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_date (entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
