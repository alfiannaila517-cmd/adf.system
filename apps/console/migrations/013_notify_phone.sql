-- Kontak penagihan per bisnis (bukan per grup): WhatsApp penagihan di samping email notifikasi
ALTER TABLE subscriptions
    ADD COLUMN IF NOT EXISTS notify_phone VARCHAR(40) NULL AFTER notify_email;
