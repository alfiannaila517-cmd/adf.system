-- Link pembayaran payment gateway (Mayar) yang dibuat Console: tagihan langganan (charge / periode), invoice, pesanan website.
-- Webhook gateway → cari baris ini → cek ulang status ke gateway → tandai lunas.
CREATE TABLE IF NOT EXISTS payment_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gateway VARCHAR(20) NOT NULL DEFAULT 'mayar',
    ref_type ENUM('charge', 'invoice', 'order') NOT NULL,
    ref VARCHAR(80) NOT NULL COMMENT 'periode (charge), id invoice, atau order_id',
    business_id INT UNSIGNED NULL,
    description VARCHAR(200) NULL,
    amount DECIMAL(15,2) NOT NULL,
    gateway_id VARCHAR(80) NOT NULL COMMENT 'id invoice di gateway',
    gateway_txn VARCHAR(80) NULL,
    link VARCHAR(500) NOT NULL,
    status ENUM('pending', 'paid', 'expired') NOT NULL DEFAULT 'pending',
    expires_at DATETIME NULL,
    checked_at DATETIME NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gateway_id (gateway, gateway_id),
    KEY idx_ref (ref_type, ref),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
