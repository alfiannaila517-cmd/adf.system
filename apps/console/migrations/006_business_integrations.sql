-- Integrasi pihak ketiga per bisnis (WhatsApp, Cloudbeds, fingerprint, email, OpenAI).
-- config  = nilai biasa (JSON);  secrets = nilai rahasia terenkripsi Vault (JSON berisi "v1:…"), kunci di secrets/vault.env
CREATE TABLE IF NOT EXISTS business_integrations (
    business_id INT UNSIGNED NOT NULL,
    provider VARCHAR(30) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    config JSON NULL,
    secrets JSON NULL,
    last_test_at DATETIME NULL,
    last_test_ok TINYINT(1) NULL,
    last_test_msg VARCHAR(300) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (business_id, provider),
    CONSTRAINT fk_bi_business FOREIGN KEY (business_id) REFERENCES businesses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
