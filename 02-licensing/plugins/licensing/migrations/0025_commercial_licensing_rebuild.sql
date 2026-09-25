-- 0025_commercial_licensing_rebuild.sql — Phase 2: Central Licensing
-- Platform Foundation.
--
-- Splits the conflated `licensing_installs` (License + Installation) into
-- distinct `licensing_licenses` and `licensing_installations` tables, moves
-- entitlements off the Plan (`licensing_plans.entitlements_json`) onto a
-- per-license grant table, and adds a lifecycle audit trail — per the
-- approved target schema (docs/02-architecture/09-CENTRAL-DATABASE-
-- DESIGN.md) and the corrections locked in by the independent Antigravity
-- review (docs/02-architecture/15-PHASE-1-DECISIONS.md, decisions D14-D20;
-- docs/02-architecture/16-PHASE-1-ANTIGRAVITY-REVIEW.md, findings F-01/F-03).
--
-- Purely additive: `licensing_installs`, `licensing_installation_bindings`,
-- `licensing_checkins`, `licensing_products`, and `licensing_clients` are
-- left structurally untouched — no data migration, per docs/02-architecture/
-- 13-MIGRATION-STRATEGY.md §1/§4 (a locked project decision: there is no
-- live licensing data to migrate, and this rebuild targets new
-- installations only, so no dual-write/dual-read is built).
--
-- `licensing_plans.entitlements_json` is intentionally KEPT here, not
-- dropped as docs/02-architecture/09-CENTRAL-DATABASE-DESIGN.md §4
-- describes ("Removed") — admin/plans.php still reads and writes that
-- column today and is only "deprecated" (13 §2), not yet replaced;
-- dropping it now would break a currently-working admin screen. Only the
-- additive `description`/`is_active` columns are added below. Actual
-- removal is deferred to the Phase 3 admin-UI rebuild.
--
-- Deliberately plain `ADD COLUMN` (no `IF NOT EXISTS`), unlike 0024's
-- precedent: verified directly against a real MySQL 8/9-family server that
-- `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` is rejected with a syntax
-- error there (it is a MariaDB extension, not standard MySQL DDL) — see
-- this phase's completion report for the full finding. This statement only
-- ever needs to succeed once, the first time this migration runs against a
-- database that doesn't have these columns yet; `LicensingAPI::
-- ensureSchema()`'s existing per-statement try/catch already tolerates
-- (logs, does not crash on) a harmless "column already exists" error on an
-- accidental replay, exactly as it does for any other statement here.
ALTER TABLE `licensing_plans`
    ADD COLUMN `description` TEXT NULL AFTER `name`,
    ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `description`;

-- ── Plan Modules ─────────────────────────────────────────────────────────
-- Template default module set, consulted only at license-creation time as
-- a pre-fill convenience — never read at validation/check-in time. Changing
-- a plan's template later has zero effect on already-issued licenses.
CREATE TABLE IF NOT EXISTS `licensing_plan_modules` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `plan_id`    INT UNSIGNED NOT NULL,
    `module_key` VARCHAR(64) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_plan_module` (`plan_id`, `module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Licenses ─────────────────────────────────────────────────────────────
-- The actual commercial entitlement for a specific installation — split out
-- of `licensing_installs` so a License can exist, be issued, and even be
-- suspended/revoked before any Installation has ever activated it.
CREATE TABLE IF NOT EXISTS `licensing_licenses` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`          INT UNSIGNED NOT NULL,
    `product_id`         INT UNSIGNED NOT NULL,
    `plan_id`            INT UNSIGNED NULL,
    `label`              VARCHAR(190) NOT NULL DEFAULT '',
    `license_key_hash`   CHAR(64) NOT NULL,
    `status`             ENUM('unactivated','trial','active','expired','suspended','revoked','cancelled') NOT NULL DEFAULT 'unactivated',
    `issued_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `starts_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`         DATETIME NULL,
    `warning_days`       INT UNSIGNED NOT NULL DEFAULT 7,
    `grace_days`         INT UNSIGNED NOT NULL DEFAULT 7,
    `activation_limit`   INT UNSIGNED NOT NULL DEFAULT 1,
    `revoke_reason`      VARCHAR(255) NULL,
    `metadata_json`      LONGTEXT NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_license_key_hash` (`license_key_hash`),
    KEY `idx_license_client` (`client_id`),
    KEY `idx_license_status` (`status`),
    KEY `idx_license_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Installations ────────────────────────────────────────────────────────
-- The specific bound client deployment — split out of `licensing_installs`
-- and `licensing_installation_bindings`. `active_license_id` is a generated
-- column, NULL for any row whose `status` is not 'active' and equal to
-- `license_id` otherwise — this carries the "at most one currently-active
-- Installation per License" constraint via an ordinary UNIQUE key, since
-- InnoDB has no native partial/filtered unique index and a flat
-- UNIQUE(license_id) would make soft-deactivation-on-reset impossible.
-- A reset (administrator-initiated) supersedes the old row
-- (`status='superseded', deleted_at=NOW()`) instead of deleting it, then
-- inserts a new row in the same transaction — preserving the full
-- installation/activation history this table exists to retain.
CREATE TABLE IF NOT EXISTS `licensing_installations` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `license_id`          INT UNSIGNED NOT NULL,
    `installation_id`     CHAR(32) NOT NULL,
    `domain`              VARCHAR(190) NOT NULL,
    `domain_normalized`   VARCHAR(190) NULL,
    `installed_version`   VARCHAR(40) NULL,
    `status`              ENUM('active','suspended','revoked','superseded') NOT NULL DEFAULT 'active',
    `deleted_at`          DATETIME NULL,
    `active_license_id`   INT UNSIGNED GENERATED ALWAYS AS (IF(`status` = 'active', `license_id`, NULL)) STORED,
    `first_activated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at`        DATETIME NULL,
    `last_seen_ip`        VARCHAR(45) NULL,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_installation_identity` (`installation_id`),
    UNIQUE KEY `uniq_installation_active_license` (`active_license_id`),
    KEY `idx_installation_license` (`license_id`),
    KEY `idx_installation_status` (`status`),
    CONSTRAINT `fk_installation_license` FOREIGN KEY (`license_id`) REFERENCES `licensing_licenses`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── License Modules ──────────────────────────────────────────────────────
-- The actual, per-license entitlement grant — independent of the Plan it
-- was created from once issued. Core modules (admin-user, dashboard,
-- site-settings) are never rows here: they are implicit whenever the
-- license itself is valid, so nothing can ever "un-grant" Core by deleting
-- a row (see LicenseService::CORE_MODULE_KEYS).
CREATE TABLE IF NOT EXISTS `licensing_license_modules` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `license_id` INT UNSIGNED NOT NULL,
    `module_key` VARCHAR(64) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_license_module` (`license_id`, `module_key`),
    KEY `idx_license_modules_key` (`module_key`),
    CONSTRAINT `fk_license_module_license` FOREIGN KEY (`license_id`) REFERENCES `licensing_licenses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── License Events ───────────────────────────────────────────────────────
-- Lifecycle audit trail: every Activate/Suspend/Revoke/Renew/Extend/
-- Refresh/Create is attributable (actor) and timestamped. Distinct from
-- `licensing_checkins` by design — checkins are high-volume, unattended
-- phone-home traffic; events are low-volume, attributable, commercially
-- significant state changes.
CREATE TABLE IF NOT EXISTS `licensing_license_events` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `license_id`     INT UNSIGNED NOT NULL,
    `event_type`     VARCHAR(32) NOT NULL,
    `actor_type`     ENUM('admin','system') NOT NULL,
    `actor_id`       INT UNSIGNED NULL,
    `reason`         VARCHAR(255) NULL,
    `metadata_json`  LONGTEXT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_license_events_license` (`license_id`, `created_at`),
    CONSTRAINT `fk_license_event_license` FOREIGN KEY (`license_id`) REFERENCES `licensing_licenses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
