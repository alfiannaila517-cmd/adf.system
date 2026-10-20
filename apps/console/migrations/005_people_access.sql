-- Orang & Akses: satu orang = satu akun; akses = orang × bisnis × peran
-- Saat sistem lama dipindah, isi tabel ini diterjemahkan ke users / user_business_assignment / user_menu_permissions lama.

CREATE TABLE IF NOT EXISTS people (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(40) NULL,
    username VARCHAR(60) NULL COMMENT 'username login sistem lama (dibawa saat impor)',
    status ENUM('invited', 'active', 'disabled') NOT NULL DEFAULT 'invited',
    legacy_user_id INT NULL COMMENT 'adf_system.users.id lama',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email (email),
    UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Peran per bisnis (disalin dari templat jenis bisnis, lalu bisa diubah khusus bisnis itu)
-- permissions = {"cashbook": 2, "frontdesk": 3, …}  0 Tidak · 1 Lihat · 2 Input · 3 Kelola
CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id INT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(200) NULL,
    permissions JSON NOT NULL,
    is_owner TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'peran pemilik: akses penuh + portal owner',
    sort INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_business_code (business_id, code),
    CONSTRAINT fk_role_business FOREIGN KEY (business_id) REFERENCES businesses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Keanggotaan: orang X di bisnis Y dengan peran Z (+ pintu masuk & pengecualian per orang bila perlu)
CREATE TABLE IF NOT EXISTS memberships (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id INT UNSIGNED NOT NULL,
    business_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    portal_owner TINYINT(1) NOT NULL DEFAULT 0,
    portal_staff TINYINT(1) NOT NULL DEFAULT 0,
    overrides JSON NULL COMMENT 'pengecualian izin khusus orang ini {"modul": level}',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_person_business (person_id, business_id),
    KEY idx_business (business_id),
    CONSTRAINT fk_mem_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE CASCADE,
    CONSTRAINT fk_mem_business FOREIGN KEY (business_id) REFERENCES businesses (id) ON DELETE CASCADE,
    CONSTRAINT fk_mem_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Owner grup: otomatis pemilik di SEMUA bisnis client ini (termasuk bisnis yang ditambahkan kemudian)
CREATE TABLE IF NOT EXISTS client_owners (
    client_id INT UNSIGNED NOT NULL,
    person_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (client_id, person_id),
    CONSTRAINT fk_co_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_co_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
