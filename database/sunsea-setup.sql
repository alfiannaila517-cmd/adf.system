-- ============================================================
--  SUNSEA TRAVEL BUREAU - Database Setup
--  Database: adf_sunsea  (hosting: adfb2574_sunsea)
--  
--  Run this script after creating the database:
--    CREATE DATABASE adf_sunsea CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--
--  Tables:
--    1. settings          - Konfigurasi bisnis
--    2. users             - User lokal (opsional, bisa pakai master)
--    3. customers         - Database pelanggan
--    4. trip_packages     - Paket wisata yang dijual
--    5. package_items     - Detail item dalam paket (akomodasi, transport, dll)
--    6. quotations        - Penawaran harga ke customer
--    7. quotation_items   - Line items dalam penawaran
--    8. invoices          - Invoice / tagihan ke customer
--    9. invoice_items     - Line items dalam invoice
--   10. payments          - Pembayaran dari customer
--   11. cash_book         - Kas harian (shared module)
--   12. cash_accounts     - Akun kas
--   13. bills             - Tagihan rutin
--   14. employees         - Data karyawan (untuk payroll)
--   15. payroll_periods   - Periode penggajian
-- ============================================================

-- ============================================================
-- 1. SETTINGS
-- ============================================================
CREATE TABLE IF NOT EXISTS `settings` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key`   VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT,
    `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('company_name',       'Sunsea'),
('company_tagline',    'Your Trusted Travel Partner in Karimunjawa'),
('company_address',    ''),
('company_phone',      ''),
('company_email',      ''),
('company_website',    ''),
('bank_name',          ''),
('bank_account',       ''),
('bank_holder',        ''),
('invoice_prefix',     'SS-INV'),
('quotation_prefix',   'SS-QUO'),
('default_tax_pct',    '11'),
('currency',           'IDR'),
('invoice_footer',     'Terima kasih telah mempercayakan perjalanan Anda kepada kami.'),
('quotation_validity', '7');

-- ============================================================
-- 2. CUSTOMERS - Database Pelanggan
-- ============================================================
CREATE TABLE IF NOT EXISTS `customers` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `code`          VARCHAR(20) NOT NULL UNIQUE COMMENT 'SS-CUST-001',
    `name`          VARCHAR(150) NOT NULL,
    `type`          ENUM('individual','group','corporate','travel_agent') DEFAULT 'individual',
    `email`         VARCHAR(150),
    `phone`         VARCHAR(30),
    `whatsapp`      VARCHAR(30),
    `address`       TEXT,
    `city`          VARCHAR(100),
    `country`       VARCHAR(100) DEFAULT 'Indonesia',
    `id_number`     VARCHAR(50)  COMMENT 'KTP / Passport number',
    `id_type`       ENUM('ktp','passport','other') DEFAULT 'ktp',
    `notes`         TEXT,
    `is_active`     TINYINT(1) DEFAULT 1,
    `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (`code`),
    INDEX idx_name (`name`),
    INDEX idx_type (`type`),
    INDEX idx_active (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. TRIP PACKAGES - Paket Wisata
-- ============================================================
CREATE TABLE IF NOT EXISTS `trip_packages` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `code`          VARCHAR(20) NOT NULL UNIQUE COMMENT 'SS-PKG-001',
    `name`          VARCHAR(200) NOT NULL,
    `category`      ENUM('open_trip','private_trip','snorkeling','diving','island_tour','custom') DEFAULT 'open_trip',
    `duration_days` SMALLINT DEFAULT 1,
    `duration_nights` SMALLINT DEFAULT 0,
    `min_pax`       SMALLINT DEFAULT 1,
    `max_pax`       SMALLINT DEFAULT 20,
    `base_price`    DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Harga dasar per orang',
    `description`   TEXT,
    `includes`      TEXT  COMMENT 'What is included (JSON or text)',
    `excludes`      TEXT  COMMENT 'What is NOT included',
    `itinerary`     TEXT  COMMENT 'Jadwal perjalanan',
    `notes`         TEXT,
    `is_active`     TINYINT(1) DEFAULT 1,
    `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (`code`),
    INDEX idx_category (`category`),
    INDEX idx_active (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. PACKAGE ITEMS - Komponen detail paket
-- ============================================================
CREATE TABLE IF NOT EXISTS `package_items` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `package_id`  INT NOT NULL,
    `item_type`   ENUM('accommodation','transport','meal','activity','guide','equipment','other') DEFAULT 'other',
    `description` VARCHAR(255) NOT NULL,
    `unit`        VARCHAR(30) DEFAULT 'pax',
    `unit_price`  DECIMAL(15,2) DEFAULT 0.00,
    `notes`       TEXT,
    `sort_order`  SMALLINT DEFAULT 0,
    FOREIGN KEY (`package_id`) REFERENCES `trip_packages`(`id`) ON DELETE CASCADE,
    INDEX idx_package (`package_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. QUOTATIONS - Surat Penawaran Harga
-- ============================================================
CREATE TABLE IF NOT EXISTS `quotations` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `quotation_no`    VARCHAR(30) NOT NULL UNIQUE COMMENT 'SS-QUO-2026-001',
    `customer_id`     INT NOT NULL,
    `package_id`      INT          COMMENT 'NULL if fully custom',
    `trip_date`       DATE,
    `trip_end_date`   DATE,
    `pax_count`       SMALLINT DEFAULT 1,
    `status`          ENUM('draft','sent','approved','rejected','expired','converted') DEFAULT 'draft',
    `subtotal`        DECIMAL(15,2) DEFAULT 0.00,
    `tax_pct`         DECIMAL(5,2)  DEFAULT 11.00,
    `tax_amount`      DECIMAL(15,2) DEFAULT 0.00,
    `discount_amount` DECIMAL(15,2) DEFAULT 0.00,
    `total_amount`    DECIMAL(15,2) DEFAULT 0.00,
    `notes`           TEXT,
    `internal_notes`  TEXT,
    `valid_until`     DATE,
    `sent_at`         TIMESTAMP NULL,
    `approved_at`     TIMESTAMP NULL,
    `converted_invoice_id` INT NULL,
    `created_by`      VARCHAR(100),
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE RESTRICT,
    INDEX idx_quotation_no (`quotation_no`),
    INDEX idx_customer (`customer_id`),
    INDEX idx_status (`status`),
    INDEX idx_trip_date (`trip_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. QUOTATION ITEMS
-- ============================================================
CREATE TABLE IF NOT EXISTS `quotation_items` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `quotation_id` INT NOT NULL,
    `item_type`    ENUM('accommodation','transport','meal','activity','guide','equipment','other') DEFAULT 'other',
    `description`  VARCHAR(255) NOT NULL,
    `qty`          DECIMAL(10,2) DEFAULT 1,
    `unit`         VARCHAR(30) DEFAULT 'pax',
    `unit_price`   DECIMAL(15,2) DEFAULT 0.00,
    `subtotal`     DECIMAL(15,2) DEFAULT 0.00,
    `notes`        TEXT,
    `sort_order`   SMALLINT DEFAULT 0,
    FOREIGN KEY (`quotation_id`) REFERENCES `quotations`(`id`) ON DELETE CASCADE,
    INDEX idx_quotation (`quotation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. INVOICES - Tagihan
-- ============================================================
CREATE TABLE IF NOT EXISTS `invoices` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_no`      VARCHAR(30) NOT NULL UNIQUE COMMENT 'SS-INV-2026-001',
    `quotation_id`    INT          COMMENT 'NULL if direct invoice',
    `customer_id`     INT NOT NULL,
    `trip_date`       DATE,
    `trip_end_date`   DATE,
    `pax_count`       SMALLINT DEFAULT 1,
    `status`          ENUM('draft','issued','partial','paid','cancelled','overdue') DEFAULT 'draft',
    `subtotal`        DECIMAL(15,2) DEFAULT 0.00,
    `tax_pct`         DECIMAL(5,2)  DEFAULT 11.00,
    `tax_amount`      DECIMAL(15,2) DEFAULT 0.00,
    `discount_amount` DECIMAL(15,2) DEFAULT 0.00,
    `total_amount`    DECIMAL(15,2) DEFAULT 0.00,
    `paid_amount`     DECIMAL(15,2) DEFAULT 0.00,
    `remaining_amount` DECIMAL(15,2) DEFAULT 0.00,
    `due_date`        DATE,
    `notes`           TEXT,
    `internal_notes`  TEXT,
    `issued_at`       TIMESTAMP NULL,
    `paid_at`         TIMESTAMP NULL,
    `created_by`      VARCHAR(100),
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE RESTRICT,
    INDEX idx_invoice_no (`invoice_no`),
    INDEX idx_customer (`customer_id`),
    INDEX idx_status (`status`),
    INDEX idx_due_date (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. INVOICE ITEMS
-- ============================================================
CREATE TABLE IF NOT EXISTS `invoice_items` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_id`  INT NOT NULL,
    `item_type`   ENUM('accommodation','transport','meal','activity','guide','equipment','other') DEFAULT 'other',
    `description` VARCHAR(255) NOT NULL,
    `qty`         DECIMAL(10,2) DEFAULT 1,
    `unit`        VARCHAR(30) DEFAULT 'pax',
    `unit_price`  DECIMAL(15,2) DEFAULT 0.00,
    `subtotal`    DECIMAL(15,2) DEFAULT 0.00,
    `notes`       TEXT,
    `sort_order`  SMALLINT DEFAULT 0,
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE,
    INDEX idx_invoice (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. PAYMENTS - Pembayaran dari Customer
-- ============================================================
CREATE TABLE IF NOT EXISTS `payments` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_id`      INT NOT NULL,
    `payment_date`    DATE NOT NULL,
    `amount`          DECIMAL(15,2) NOT NULL,
    `method`          ENUM('cash','transfer','qris','other') DEFAULT 'transfer',
    `bank_name`       VARCHAR(100),
    `reference`       VARCHAR(100) COMMENT 'No. transaksi / bukti transfer',
    `notes`           TEXT,
    `created_by`      VARCHAR(100),
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE RESTRICT,
    INDEX idx_invoice (`invoice_id`),
    INDEX idx_date (`payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. CASH ACCOUNTS - Akun Kas/Bank
-- ============================================================
CREATE TABLE IF NOT EXISTS `cash_accounts` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `account_name`    VARCHAR(100) NOT NULL,
    `account_type`    ENUM('cash','bank','qris','other') DEFAULT 'cash',
    `bank_name`       VARCHAR(100),
    `account_number`  VARCHAR(50),
    `current_balance` DECIMAL(15,2) DEFAULT 0.00,
    `is_active`       TINYINT(1) DEFAULT 1,
    `is_default_account` TINYINT(1) DEFAULT 0,
    `description`     TEXT,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `cash_accounts` (`account_name`, `account_type`, `is_default_account`) VALUES
('Kas Tunai', 'cash', 1),
('Rekening Bank', 'bank', 0);

-- ============================================================
-- 11. CASH BOOK - Kas Harian
-- ============================================================
CREATE TABLE IF NOT EXISTS `cash_book` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `cash_account_id` INT,
    `transaction_date` DATE NOT NULL,
    `transaction_time` TIME DEFAULT '00:00:00',
    `type`            ENUM('income','expense') NOT NULL,
    `category`        VARCHAR(100),
    `description`     VARCHAR(255) NOT NULL,
    `amount`          DECIMAL(15,2) NOT NULL,
    `reference`       VARCHAR(100),
    `customer_id`     INT          COMMENT 'Link ke customer jika terkait',
    `invoice_id`      INT          COMMENT 'Link ke invoice jika terkait',
    `created_by`      VARCHAR(100),
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_date (`transaction_date`),
    INDEX idx_type (`type`),
    INDEX idx_account (`cash_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 12. BILLS - Tagihan Rutin
-- ============================================================
CREATE TABLE IF NOT EXISTS `bills` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `bill_name`   VARCHAR(150) NOT NULL,
    `category`    VARCHAR(100),
    `amount`      DECIMAL(15,2) NOT NULL,
    `due_day`     TINYINT COMMENT 'Tanggal jatuh tempo tiap bulan (1-31)',
    `is_active`   TINYINT(1) DEFAULT 1,
    `notes`       TEXT,
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bill_payments` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `bill_id`     INT NOT NULL,
    `period`      VARCHAR(7) COMMENT 'YYYY-MM',
    `paid_date`   DATE,
    `amount`      DECIMAL(15,2),
    `status`      ENUM('unpaid','paid') DEFAULT 'unpaid',
    `notes`       TEXT,
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`bill_id`) REFERENCES `bills`(`id`) ON DELETE CASCADE,
    UNIQUE KEY uk_bill_period (`bill_id`, `period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 13. EMPLOYEES
-- ============================================================
CREATE TABLE IF NOT EXISTS `employees` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `employee_code` VARCHAR(20),
    `name`        VARCHAR(150) NOT NULL,
    `position`    VARCHAR(100),
    `division`    VARCHAR(100),
    `phone`       VARCHAR(30),
    `email`       VARCHAR(150),
    `join_date`   DATE,
    `base_salary` DECIMAL(15,2) DEFAULT 0.00,
    `is_active`   TINYINT(1) DEFAULT 1,
    `notes`       TEXT,
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 14. USER PREFERENCES (for theme, etc.)
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_preferences` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT NOT NULL,
    `branch_id`   VARCHAR(50) DEFAULT 'sunsea',
    `theme`       VARCHAR(20) DEFAULT 'light',
    `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_branch (`user_id`, `branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEQUENCE HELPERS (auto-numbering views)
-- ============================================================
CREATE TABLE IF NOT EXISTS `sequences` (
    `seq_name`   VARCHAR(50) PRIMARY KEY,
    `last_value` INT DEFAULT 0,
    `year`       SMALLINT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `sequences` (`seq_name`, `last_value`, `year`) VALUES
('quotation', 0, YEAR(NOW())),
('invoice',   0, YEAR(NOW()));
