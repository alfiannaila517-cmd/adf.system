-- Sinkron langganan dengan sistem bisnis (pengganti API adfsystem.store): buku tagihan per tamu, tagihan manual ke sistem
-- Tagihan yang sudah punya link bayar di sistem klien (locked) atau diedit admin (edited) tidak dihitung ulang otomatis
ALTER TABLE subscription_charges
    ADD COLUMN IF NOT EXISTS locked TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN IF NOT EXISTS edited TINYINT(1) NOT NULL DEFAULT 0 AFTER locked;

-- Jumlah tamu (pax) per bulan yang dilaporkan sistem bisnis
CREATE TABLE IF NOT EXISTS subscription_usage (
    business_id INT UNSIGNED NOT NULL,
    period CHAR(7) NOT NULL,
    guests INT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (business_id, period),
    CONSTRAINT fk_usage_business FOREIGN KEY (business_id) REFERENCES businesses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Buku tagihan mulai dihitung dari periode ini; kapan sistem bisnis terakhir sinkron
ALTER TABLE subscriptions
    ADD COLUMN IF NOT EXISTS ledger_from CHAR(7) NULL AFTER notify_email,
    ADD COLUMN IF NOT EXISTS last_sync_at DATETIME NULL AFTER ledger_from;
