-- ============================================================
--  SUNSEA - Register bisnis di Master Database (adf_system)
--  
--  Jalankan script ini sekali di database adf_system / adfb2574_adf
--  SETELAH menjalankan database/sunsea-setup.sql di database adf_sunsea
--  
--  Langkah:
--    1. Pastikan database adf_sunsea sudah dibuat & diisi schema
--    2. Login ke phpMyAdmin -> pilih database adf_system
--    3. Jalankan script ini
--    4. Assign user ke bisnis Sunsea via tabel user_menu_permissions
-- ============================================================

-- 1. Tambah bisnis ke tabel businesses
--    Sesuaikan owner_id dengan ID user owner yang benar
INSERT IGNORE INTO `businesses` (
    `business_code`,
    `business_name`,
    `business_type`,
    `database_name`,
    `owner_id`,
    `description`,
    `is_active`,
    `slug`
) VALUES (
    'SUNSEA',
    'Sunsea',
    'tourism',          -- Closest type: tourism
    'adf_sunsea',       -- Local DB name (auto-mapped to adfb2574_sunsea on hosting)
    1,                  -- !! GANTI dengan ID owner user yang sesuai !!
    'Biro Wisata Sunsea - Invoice, Penawaran, Kalkulasi Harga',
    1,
    'sunsea'
);

-- 2. Assign user ke bisnis Sunsea (user_menu_permissions)
--    Ganti user_id dengan ID user yang perlu akses
--    Ganti business_id dengan ID yang dihasilkan dari INSERT di atas
-- 
-- Contoh: assign user_id = 1 ke bisnis Sunsea (business_id dari SELECT)
-- 
-- SET @sunsea_id = (SELECT id FROM businesses WHERE business_code = 'SUNSEA' LIMIT 1);
-- INSERT IGNORE INTO user_menu_permissions (user_id, business_id, permission_level)
-- VALUES (1, @sunsea_id, 'full');
-- 
-- Atau jalankan dua query ini secara terpisah:

-- Query 1: Cek ID bisnis Sunsea setelah INSERT
SELECT id, business_code, business_name, database_name, slug 
FROM businesses 
WHERE business_code = 'SUNSEA';

-- Query 2: Setelah tahu ID bisnis, assign user (ganti 1 dengan user_id & 999 dengan business_id):
-- INSERT IGNORE INTO user_menu_permissions (user_id, business_id, permission_level)
-- VALUES (1, 999, 'full');

-- ============================================================
-- UNTUK HOSTING: database_name di tabel businesses boleh
-- tetap 'adf_sunsea' karena config/database.php otomatis
-- memetakan adf_sunsea -> adfb2574_sunsea saat di production.
-- ============================================================
