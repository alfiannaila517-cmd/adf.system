-- ADF Console: client (grup usaha) → bisnis → langganan, tagihan, invoice, pembayaran, order
-- Pengganti file JSON adfsystem.store (subscription-clients, ledger, manual-invoices, invoices, orders)

-- Client = pemilik / grup usaha (mis. Grup Narayana). Satu client punya banyak bisnis.
CREATE TABLE IF NOT EXISTS clients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    contact_name VARCHAR(120) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(40) NULL,
    address VARCHAR(300) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bisnis = satu sistem / layanan yang ditagih sendiri (hotel, kafe, gudang, website, …)
CREATE TABLE IF NOT EXISTS businesses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    slug VARCHAR(60) NOT NULL,
    name VARCHAR(150) NOT NULL,
    type VARCHAR(30) NOT NULL DEFAULT 'other' COMMENT 'hotel, restaurant, warehouse, manufacture, contractor, travel, website, other',
    status ENUM('active', 'suspended', 'archived') NOT NULL DEFAULT 'active',
    db_name VARCHAR(64) NULL,
    db_host VARCHAR(100) NULL,
    domain VARCHAR(190) NULL,
    notes TEXT NULL,
    legacy_business_id INT NULL COMMENT 'adf_system.businesses.id lama',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slug (slug),
    KEY idx_client (client_id),
    CONSTRAINT fk_business_client FOREIGN KEY (client_id) REFERENCES clients (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Langganan per bisnis. client_key + client_token dipakai sistem bisnis untuk sinkron (API sama dengan store lama).
CREATE TABLE IF NOT EXISTS subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id INT UNSIGNED NOT NULL,
    client_key VARCHAR(80) NOT NULL,
    client_token VARCHAR(128) NOT NULL,
    display_name VARCHAR(150) NULL COMMENT 'client_name yang dikirim ke sistem bisnis',
    base_fee DECIMAL(15,2) NOT NULL DEFAULT 0,
    per_guest_fee DECIMAL(15,2) NOT NULL DEFAULT 0,
    start_date DATE NULL,
    due_date_override DATE NULL,
    locked TINYINT(1) NOT NULL DEFAULT 0,
    locked_at DATETIME NULL,
    pakasir_slug VARCHAR(120) NULL,
    pakasir_api_key VARCHAR(255) NULL,
    pakasir_webhook_secret VARCHAR(255) NULL,
    notify_email VARCHAR(190) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_client_key (client_key),
    UNIQUE KEY uq_business (business_id),
    CONSTRAINT fk_sub_business FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tagihan langganan per periode (biaya dasar + tamu × tarif + carry + penyesuaian) — pengganti subscription-ledger.json
CREATE TABLE IF NOT EXISTS subscription_charges (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    legacy_id VARCHAR(40) NULL,
    business_id INT UNSIGNED NOT NULL,
    kind ENUM('recurring', 'topup') NOT NULL DEFAULT 'recurring',
    ref VARCHAR(40) NOT NULL COMMENT 'periode YYYY-MM atau penanda top-up',
    period CHAR(7) NOT NULL,
    base_fee DECIMAL(15,2) NOT NULL DEFAULT 0,
    guest_count INT NOT NULL DEFAULT 0,
    carry_guests INT NOT NULL DEFAULT 0,
    carry_from VARCHAR(190) NULL,
    per_guest_fee DECIMAL(15,2) NOT NULL DEFAULT 0,
    adjustment DECIMAL(15,2) NOT NULL DEFAULT 0,
    note VARCHAR(300) NULL,
    status ENUM('unpaid', 'paid', 'cancelled') NOT NULL DEFAULT 'unpaid',
    due_date DATE NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_business_ref (business_id, ref),
    KEY idx_period (period),
    CONSTRAINT fk_charge_business FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoice: bebas (pelanggan siapa pun), manual langganan, atau bulanan client (draf yang bisa diedit bebas)
CREATE TABLE IF NOT EXISTS invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    legacy_id VARCHAR(40) NULL,
    number VARCHAR(40) NOT NULL,
    token CHAR(24) NOT NULL COMMENT 'link publik invoice',
    kind ENUM('free', 'manual', 'monthly') NOT NULL DEFAULT 'free',
    client_id INT UNSIGNED NULL,
    business_id INT UNSIGNED NULL,
    customer_name VARCHAR(150) NOT NULL,
    customer_company VARCHAR(150) NULL,
    customer_email VARCHAR(190) NULL,
    customer_phone VARCHAR(40) NULL,
    customer_address VARCHAR(300) NULL,
    issue_date DATE NOT NULL,
    due_date DATE NOT NULL,
    discount_type ENUM('rp', 'percent') NOT NULL DEFAULT 'rp',
    discount_value DECIMAL(15,2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    status ENUM('draft', 'unpaid', 'paid', 'cancelled') NOT NULL DEFAULT 'draft',
    paid_at DATETIME NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_number (number),
    UNIQUE KEY uq_token (token),
    KEY idx_client (client_id),
    KEY idx_business (business_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    business_id INT UNSIGNED NULL COMMENT 'baris milik bisnis tertentu (tagihan gabungan grup)',
    description VARCHAR(240) NOT NULL,
    note VARCHAR(240) NULL,
    qty DECIMAL(12,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    sort INT NOT NULL DEFAULT 0,
    KEY idx_invoice (invoice_id),
    CONSTRAINT fk_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pembayaran masuk (Pakasir / transfer / manual), terhubung ke invoice atau tagihan langganan
CREATE TABLE IF NOT EXISTS payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NULL,
    charge_id INT UNSIGNED NULL,
    business_id INT UNSIGNED NULL,
    amount DECIMAL(15,2) NOT NULL,
    method VARCHAR(40) NULL COMMENT 'qris, va_bni, transfer, cash, …',
    gateway VARCHAR(30) NOT NULL DEFAULT 'manual' COMMENT 'pakasir | manual',
    gateway_ref VARCHAR(120) NULL,
    paid_at DATETIME NOT NULL,
    raw JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_invoice (invoice_id),
    KEY idx_charge (charge_id),
    UNIQUE KEY uq_gateway_ref (gateway, gateway_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order dari checkout website / pembayaran langganan (pengganti orders.json)
CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id VARCHAR(80) NOT NULL,
    txn_id VARCHAR(120) NULL,
    product_title VARCHAR(200) NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    name VARCHAR(150) NULL,
    email VARCHAR(190) NULL,
    whatsapp VARCHAR(40) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    source VARCHAR(30) NULL COMMENT 'checkout | pakasir | subscription',
    payment_method VARCHAR(40) NULL,
    client_id INT UNSIGNED NULL,
    business_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_order (order_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pengaturan Console (data perusahaan untuk invoice, nomor urut, rekening, dll.) sebagai pasangan kunci-nilai JSON
CREATE TABLE IF NOT EXISTS console_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value JSON NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
