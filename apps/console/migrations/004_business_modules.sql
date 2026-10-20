-- Modul & fitur yang menyala per bisnis (satu tempat; menggantikan business_menu_config + enabled_modules di file config)
-- Baris yang tidak ada = ikut bawaan jenis bisnis (core/Catalog.php)
CREATE TABLE IF NOT EXISTS business_modules (
    business_id INT UNSIGNED NOT NULL,
    kind ENUM('module', 'feature') NOT NULL,
    code VARCHAR(40) NOT NULL,
    enabled TINYINT(1) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (business_id, kind, code),
    CONSTRAINT fk_bm_business FOREIGN KEY (business_id) REFERENCES businesses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
