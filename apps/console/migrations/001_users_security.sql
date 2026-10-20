-- ADF Console: pengguna tim ADF + keamanan login (setara admin adfsystem.store lama)

CREATE TABLE IF NOT EXISTS console_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
    legacy_id VARCHAR(20) NULL COMMENT 'id di data/users.json store lama (hasil impor)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_username (username),
    UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Percobaan login gagal per kunci ("ip:1.2.3.4" / "user:nama"), dibersihkan otomatis setelah 15 menit
CREATE TABLE IF NOT EXISTS console_login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lock_key VARCHAR(190) NOT NULL,
    attempted_at INT UNSIGNED NOT NULL,
    KEY idx_key_time (lock_key, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link reset password sekali pakai (30 menit)
CREATE TABLE IF NOT EXISTS console_password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jejak aktivitas: siapa melakukan apa
CREATE TABLE IF NOT EXISTS console_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    username VARCHAR(60) NULL,
    action VARCHAR(80) NOT NULL,
    detail VARCHAR(500) NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
