-- Licensing plugin — install schema (full, as of 0.1.0).
--
-- This plugin runs on its own dedicated, single-tenant Kohevo install (the
-- license server itself) — it is never activated on a regular multi-tenant
-- install alongside Booking/Forms/etc. Its tables therefore carry NO
-- tenant_id column: they are not tenant-scoped data being isolated within a
-- shared install, they ARE the whole install's data, same reasoning as
-- TenantService's own `tenants`/`tenant_profiles` tables.
--
-- No FOREIGN KEY constraints, matching this codebase's existing plugin
-- convention (see Membership's install.sql) — referential integrity is
-- enforced in application code, not the schema, so tables stay simple to
-- reason about and to evolve across phases.
--
-- One `clients` table across every product: the same client can hold
-- licenses for more than one product over time. `products`/`plans` scope
-- entitlements per product; `installs` is the one row per deployed
-- instance; `checkins` is the append-only phone-home log.

-- ── Products ─────────────────────────────────────────────────────────────
-- One row per product this server issues licenses for (e.g. "kohevo").
-- Deliberately minimal in Phase 1 — no billing/marketing fields yet.
CREATE TABLE IF NOT EXISTS `licensing_products` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug`       VARCHAR(64) NOT NULL,
    `name`       VARCHAR(160) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_product_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Clients ──────────────────────────────────────────────────────────────
-- Who owns the install(s). Shared across products on purpose.
CREATE TABLE IF NOT EXISTS `licensing_clients` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(160) NOT NULL,
    `email`      VARCHAR(190) NULL,
    `notes`      TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_client_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Plans ────────────────────────────────────────────────────────────────
-- A sellable plan, scoped to one product. `entitlements_json` is a freeform
-- list of feature-key strings, same convention as the local per-tenant
-- white_label entitlement already used elsewhere in Kohevo — this server
-- never needs to understand what a feature key *means*, only pass it along.
CREATE TABLE IF NOT EXISTS `licensing_plans` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id`         INT UNSIGNED NOT NULL,
    `slug`               VARCHAR(64) NOT NULL,
    `name`               VARCHAR(160) NOT NULL,
    `entitlements_json`  LONGTEXT NOT NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_plan_product_slug` (`product_id`, `slug`),
    KEY `idx_plan_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Installs ─────────────────────────────────────────────────────────────
-- One row per deployed standalone instance. The raw license key is never
-- stored — only its SHA-256 hash — same rule as the local `licenses` table
-- (src/Services/Licensing/LicenseService.php) this mirrors in spirit.
-- `domain` is unique per product (not globally) since two different
-- products could legitimately end up on related domains/subdomains.
CREATE TABLE IF NOT EXISTS `licensing_installs` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`          INT UNSIGNED NOT NULL,
    `product_id`         INT UNSIGNED NOT NULL,
    `plan_id`            INT UNSIGNED NULL,
    `label`              VARCHAR(190) NOT NULL DEFAULT '',
    `domain`             VARCHAR(190) NOT NULL,
    `license_key_hash`   CHAR(64) NOT NULL,
    `status`             ENUM('trial','active','expired','suspended','revoked','cancelled') NOT NULL DEFAULT 'trial',
    `issued_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `starts_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`         DATETIME NULL,
    `activation_limit`   INT UNSIGNED NOT NULL DEFAULT 1,
    `activation_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    `installed_version`  VARCHAR(40) NULL,
    `last_checkin_at`    DATETIME NULL,
    `last_checkin_ip`    VARCHAR(45) NULL,
    `revoke_reason`      VARCHAR(255) NULL,
    `metadata_json`      LONGTEXT NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_install_key_hash` (`license_key_hash`),
    UNIQUE KEY `uniq_install_product_domain` (`product_id`, `domain`),
    KEY `idx_install_client` (`client_id`),
    KEY `idx_install_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Checkins ─────────────────────────────────────────────────────────────
-- Append-only phone-home log. Kept separate from any general audit
-- mechanism on purpose — this is high-volume, external-facing traffic, a
-- genuinely different thing to log than an admin action.
CREATE TABLE IF NOT EXISTS `licensing_checkins` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `install_id`         INT UNSIGNED NOT NULL,
    `checked_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip`                 VARCHAR(45) NULL,
    `reported_domain`    VARCHAR(190) NULL,
    `reported_version`   VARCHAR(40) NULL,
    `response_status`    VARCHAR(20) NOT NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_checkin_install_time` (`install_id`, `checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
