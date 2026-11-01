-- Tokens on their way from Google to a site: sealed, single use, gone within minutes (GoogleBroker).
CREATE TABLE IF NOT EXISTS `licensing_google_handoffs` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `handoff_hash`    CHAR(64) NOT NULL,
    `installation_id` CHAR(32) NOT NULL,
    `tokens_sealed`   TEXT NOT NULL,
    `expires_at`      INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_google_handoff` (`handoff_hash`),
    KEY `idx_google_handoff_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
