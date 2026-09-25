-- ============================================================
-- Slate — core database schema
-- ============================================================
-- This file is the COMPLETE core schema. Plugins add their own
-- tables via plugins/<slug>/install.sql at activation time.
--
-- Run by install.php on first install. Idempotent
-- (CREATE TABLE IF NOT EXISTS everywhere) so it's safe to re-run.
-- ============================================================

-- ── 1. Tenants ───────────────────────────────────────────────
-- Multi-tenant support is built in from day one even if a single-
-- tenant install only ever has one row. Every domain table that
-- needs tenant scoping has a `tenant_id` column.
CREATE TABLE IF NOT EXISTS `tenants` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(120) NOT NULL,
    `slug`       VARCHAR(64)  NOT NULL,
    `status`     ENUM('active', 'suspended', 'deleted') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Roles ─────────────────────────────────────────────────
-- Named roles. Super Admin is always role_id=1, always has all
-- permissions via short-circuit in Auth::can().
CREATE TABLE IF NOT EXISTS `roles` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `name`        VARCHAR(64)  NOT NULL,
    `slug`        VARCHAR(64)  NOT NULL,
    `description` VARCHAR(255) NULL,
    `is_system`   TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_slug` (`tenant_id`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Role permissions ──────────────────────────────────────
-- Permission keys are <domain>.<action> e.g. 'users.edit',
-- 'plugins.manage', 'hello.view'. Plugin-declared permissions
-- live here too, registered automatically on plugin activate.
CREATE TABLE IF NOT EXISTS `role_permissions` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_id`    INT UNSIGNED NOT NULL,
    `perm_key`   VARCHAR(128) NOT NULL,
    `granted`    TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `role_perm` (`role_id`, `perm_key`),
    CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Users (admin/staff) ───────────────────────────────────
-- The people who log in to /admin. Customers live in `customers`.
CREATE TABLE IF NOT EXISTS `users` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`      INT UNSIGNED NOT NULL DEFAULT 1,
    `email`          VARCHAR(190) NOT NULL,
    `password_hash`  VARCHAR(255) NOT NULL,
    `name`           VARCHAR(120) NOT NULL,
    `role_id`        INT UNSIGNED NOT NULL,
    `status`         ENUM('active', 'suspended', 'invited') NOT NULL DEFAULT 'active',
    `last_login_at`  DATETIME NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_email` (`tenant_id`, `email`),
    KEY `role_id` (`role_id`),
    CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. Customers ─────────────────────────────────────────────
-- The end users of plugins (booking customers, shop customers, etc).
-- Shell knows them as login + profile. Plugins extend their data
-- via their own tables linked by customer_id.
CREATE TABLE IF NOT EXISTS `customers` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`      INT UNSIGNED NOT NULL DEFAULT 1,
    `email`          VARCHAR(190) NOT NULL,
    `password_hash`  VARCHAR(255) NULL,
    `name`           VARCHAR(120) NULL,
    `phone`          VARCHAR(40)  NULL,
    `status`         ENUM('active', 'suspended', 'guest') NOT NULL DEFAULT 'active',
    `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `last_login_at`  DATETIME NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_email` (`tenant_id`, `email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5b. Customer auth tokens (verify + reset) ────────────────
-- Single-use, expiring, hashed tokens for customer email
-- verification and password reset. The plaintext token only ever
-- leaves the server in the email link; we store SHA-256(token).
-- Purpose is open-vocabulary text so future flows (magic-link,
-- passwordless, etc.) can reuse the same table.
CREATE TABLE IF NOT EXISTS `customer_auth_tokens` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`  INT UNSIGNED NOT NULL,
    `purpose`      VARCHAR(32) NOT NULL,
    `token_hash`   CHAR(64) NOT NULL,
    `expires_at`   DATETIME NOT NULL,
    `used_at`      DATETIME NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_token_purpose`    (`token_hash`, `purpose`),
    KEY `idx_customer_purpose` (`customer_id`, `purpose`),
    KEY `idx_expires`          (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. Settings (key/value, tenant-scoped) ───────────────────
-- The catch-all for tenant config. Plugins use a "<slug>." prefix
-- by convention (e.g. "booking.slot_duration").
CREATE TABLE IF NOT EXISTS `settings` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    `setting_key`   VARCHAR(190) NOT NULL,
    `setting_value` LONGTEXT NULL,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_key` (`tenant_id`, `setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 7. Plugins (registry) ────────────────────────────────────
-- The source of truth for what's installed and what's active.
-- The PluginLoader reads this on every request to decide which
-- plugins to boot. status='active' = boot; everything else = skip.
CREATE TABLE IF NOT EXISTS `plugins` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug`           VARCHAR(64) NOT NULL,
    `name`           VARCHAR(120) NOT NULL,
    `version`        VARCHAR(20) NOT NULL,
    `status`         ENUM('installed', 'active', 'inactive') NOT NULL DEFAULT 'installed',
    `manifest_json`  LONGTEXT NOT NULL,
    `installed_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `activated_at`   DATETIME NULL,
    `deactivated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`),
    KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 8. Audit log ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `user_id`    INT UNSIGNED NULL,
    `action`     VARCHAR(120) NOT NULL,
    `target`     VARCHAR(190) NULL,
    `meta_json`  LONGTEXT NULL,
    `ip`         VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_user_time` (`tenant_id`, `user_id`, `created_at`),
    KEY `action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 9. Contact forms ─────────────────────────────────────────
-- Core feature per the plan. A simple drag-build-render form
-- system. Plugins do NOT add their own form storage here; if
-- they need forms, they use their own tables.
CREATE TABLE IF NOT EXISTS `contact_forms` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `title`      VARCHAR(190) NOT NULL,
    `slug`       VARCHAR(64) NOT NULL,
    `fields`     LONGTEXT NOT NULL, -- JSON array of field defs
    `settings`   LONGTEXT NULL,     -- JSON: notify email, thankyou, redirect
    `status`     ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_slug` (`tenant_id`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contact_form_submissions` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `form_id`    INT UNSIGNED NOT NULL,
    `data_json`  LONGTEXT NOT NULL,
    `ip`         VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_form_time` (`tenant_id`, `form_id`, `created_at`),
    CONSTRAINT `fk_cfs_form` FOREIGN KEY (`form_id`) REFERENCES `contact_forms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 10. Language overrides ───────────────────────────────────
-- Admin-editable per-locale string overrides. Carried from Bookify.
-- Applies to core strings AND any active plugin's strings.
CREATE TABLE IF NOT EXISTS `lang_overrides` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `locale`     VARCHAR(10) NOT NULL,
    `lang_key`   VARCHAR(191) NOT NULL,
    `lang_value` TEXT NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_locale_key` (`tenant_id`, `locale`, `lang_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 11. Media files (core, shared media library) ─────────────
-- Source of truth for the built-in WordPress-style media library.
-- Shared by all plugins (shop, booking, membership, ...). New uploads
-- land in /uploads/media/<Y>/<m>/; pre-existing files are surfaced by
-- the importer (Media::importKnown).
CREATE TABLE IF NOT EXISTS `media_files` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    `path`          VARCHAR(500) NOT NULL,
    `kind`          ENUM('image','document','other') NOT NULL DEFAULT 'image',
    `mime`          VARCHAR(120) NOT NULL DEFAULT '',
    `size_bytes`    INT UNSIGNED NOT NULL DEFAULT 0,
    `width`         INT UNSIGNED NULL,
    `height`        INT UNSIGNED NULL,
    `original_name` VARCHAR(255) NULL,
    `folder`        VARCHAR(190) NOT NULL DEFAULT '',
    `uploaded_by`   INT UNSIGNED NULL,
    `uploaded_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_path` (`tenant_id`, `path`),
    KEY `tenant_kind` (`tenant_id`, `kind`),
    KEY `tenant_uploaded_at` (`tenant_id`, `uploaded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 12. Media usage (pluggable reference tracking) ───────────
-- Records where each media file is used so the library can show
-- "in use" and block deletion of referenced files. Plugins call
-- Media::attach()/detach(); they may also report derived/legacy
-- references via the `media_usage` hook filter (no row required).
CREATE TABLE IF NOT EXISTS `media_usage` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `media_id`    INT UNSIGNED NOT NULL,
    `context`     VARCHAR(64)  NOT NULL,
    `object_type` VARCHAR(64)  NOT NULL DEFAULT '',
    `object_id`   VARCHAR(64)  NOT NULL DEFAULT '',
    `field`       VARCHAR(64)  NOT NULL DEFAULT '',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `usage_unique` (`tenant_id`, `media_id`, `context`, `object_type`, `object_id`, `field`),
    KEY `media` (`media_id`),
    CONSTRAINT `fk_media_usage_media` FOREIGN KEY (`media_id`) REFERENCES `media_files`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seed: default tenant ─────────────────────────────────────
INSERT INTO `tenants` (`id`, `name`, `slug`, `status`)
VALUES (1, 'Default', 'default', 'active')
ON DUPLICATE KEY UPDATE `name` = `name`;

-- ── Seed: default roles ──────────────────────────────────────
-- Super Admin gets everything by short-circuit. Other roles get
-- nothing until an admin grants permissions via /admin/roles.php.
INSERT INTO `roles` (`id`, `tenant_id`, `name`, `slug`, `description`, `is_system`)
VALUES
    (1, 1, 'Super Admin', 'super-admin', 'Full access. Cannot be edited.',         1),
    (2, 1, 'Manager',     'manager',     'Operational management.',                1),
    (3, 1, 'Editor',      'editor',      'Edit content, forms, and settings.',     1),
    (4, 1, 'Viewer',      'viewer',      'Read-only access to admin.',             1)
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- ── Seed: core permission keys ───────────────────────────────
-- Plugins add their own via plugin.json's `permissions[]` on activate.
-- These are the core ones the shell defines.
INSERT INTO `role_permissions` (`role_id`, `perm_key`, `granted`) VALUES
    -- Manager: most things except plugins and roles
    (2, 'users.view',       1),
    (2, 'users.edit',       1),
    (2, 'customers.view',   1),
    (2, 'customers.edit',   1),
    (2, 'contact.view',     1),
    (2, 'contact.manage',   1),
    (2, 'settings.view',    1),
    (2, 'settings.edit',    1),
    (2, 'audit.view',       1),
    (2, 'media.view',       1),
    (2, 'media.upload',     1),
    (2, 'media.delete',     1),
    -- Editor: settings + content
    (3, 'contact.view',     1),
    (3, 'contact.manage',   1),
    (3, 'settings.view',    1),
    (3, 'media.view',       1),
    (3, 'media.upload',     1),
    -- Viewer: read-only
    (4, 'users.view',       1),
    (4, 'customers.view',   1),
    (4, 'contact.view',     1),
    (4, 'settings.view',    1),
    (4, 'media.view',       1)
ON DUPLICATE KEY UPDATE `granted` = VALUES(`granted`);
