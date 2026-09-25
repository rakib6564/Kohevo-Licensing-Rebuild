-- Phase 2: bind a license to durable installation identities.
-- Existing licensing_installs rows remain valid; domain_normalized is backfilled
-- by the application on first use so legacy data is observable and safe.
--
-- Single-clause ALTER TABLE statements, no `IF NOT EXISTS` on ADD COLUMN/ADD
-- KEY: that's a MariaDB-only extension rejected as a syntax error on real
-- MySQL (see 0025_commercial_licensing_rebuild.sql's header for the verified
-- finding). LicensingAPI::ensureSchema() already runs each `;`-separated
-- statement in its own try/catch that logs and continues past a harmless
-- "Duplicate column name" / "Duplicate key name" error, so each ADD COLUMN
-- and each ADD KEY below is independently safe to (re)run against a fresh
-- database, a partially-migrated one, or a full replay.
ALTER TABLE `licensing_installs`
    ADD COLUMN `domain_normalized` VARCHAR(190) NULL AFTER `domain`;

ALTER TABLE `licensing_installs`
    ADD KEY `idx_install_domain_normalized` (`product_id`, `domain_normalized`);

CREATE TABLE IF NOT EXISTS `licensing_installation_bindings` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `install_id`          INT UNSIGNED NOT NULL,
    `installation_id`     CHAR(32) NOT NULL,
    `domain_normalized`   VARCHAR(190) NOT NULL,
    `first_registered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_ip`        VARCHAR(45) NULL,
    `status`              ENUM('active','suspended','revoked','cancelled') NOT NULL DEFAULT 'active',
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_binding_install_identity` (`install_id`, `installation_id`),
    UNIQUE KEY `uniq_binding_identity` (`installation_id`),
    KEY `idx_binding_install` (`install_id`),
    CONSTRAINT `fk_binding_install` FOREIGN KEY (`install_id`) REFERENCES `licensing_installs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `licensing_checkins`
    ADD COLUMN `failure_code` VARCHAR(64) NULL AFTER `response_status`;

ALTER TABLE `licensing_checkins`
    ADD KEY `idx_checkin_failure` (`failure_code`);
