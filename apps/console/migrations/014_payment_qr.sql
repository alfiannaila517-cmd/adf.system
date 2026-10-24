-- Halaman bayar ADF sendiri: QRIS dinamis Mayar (gateway 'mayar_qr') dengan kode unik di nominal.
--   token       alamat halaman bayar publik (/bayar.php?t=…)
--   base_amount nominal tagihan sebelum kode unik (amount = base_amount + kode unik)
--   qr_string / qr_image  dari Mayar /qr-codes/create
ALTER TABLE payment_links
    ADD COLUMN IF NOT EXISTS token CHAR(24) NULL AFTER gateway_txn,
    ADD COLUMN IF NOT EXISTS base_amount DECIMAL(15,2) NULL AFTER amount,
    ADD COLUMN IF NOT EXISTS qr_string TEXT NULL AFTER link,
    ADD COLUMN IF NOT EXISTS qr_image VARCHAR(500) NULL AFTER qr_string,
    ADD UNIQUE KEY IF NOT EXISTS uq_token (token);
