-- Bisnis yang sistemnya berjalan di server lain (mis. Karimunjawa Explore di hosting sendiri):
-- tidak dibuat otomatis di VPS; tetap ditagih & sinkron langganan lewat api/subscription-*.php Console
ALTER TABLE businesses
    ADD COLUMN IF NOT EXISTS hosted_elsewhere TINYINT(1) NOT NULL DEFAULT 0 AFTER domain;
