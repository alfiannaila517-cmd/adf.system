-- Penanda data contoh (DEMO) supaya bisa dihapus bersih sebelum data asli diimpor
ALTER TABLE clients ADD COLUMN IF NOT EXISTS is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER notes;
