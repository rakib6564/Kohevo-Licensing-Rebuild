-- Kohevo Studio (studio-builder) Canonical Baseline Schema (v1.0.0)
-- Single authoritative baseline DDL source for studiobuilder_* tables.

CREATE TABLE IF NOT EXISTS `studiobuilder_pages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT UNSIGNED NOT NULL,
    `uuid` CHAR(36) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(191) NOT NULL,
    `page_type` ENUM('page','landing','system','header_partial','footer_partial','section_preset') NOT NULL DEFAULT 'page',
    `status` ENUM('draft','published','scheduled','archived') NOT NULL DEFAULT 'draft',
    `route_mode` ENUM('standalone','homepage','system_override') NOT NULL DEFAULT 'standalone',
    `active_draft_revision_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `published_revision_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `seo_json` MEDIUMTEXT NULL,
    `settings_json` MEDIUMTEXT NULL,
    `published_at` DATETIME NULL DEFAULT NULL,
    `scheduled_for` DATETIME NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL DEFAULT NULL,
    `updated_by` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_studiobuilder_pages_tenant_uuid` (`tenant_id`, `uuid`),
    UNIQUE KEY `uq_studiobuilder_pages_tenant_slug_type` (`tenant_id`, `slug`, `page_type`),
    KEY `idx_studiobuilder_pages_tenant_status` (`tenant_id`, `status`),
    KEY `idx_studiobuilder_pages_tenant_route` (`tenant_id`, `route_mode`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `studiobuilder_revisions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT UNSIGNED NOT NULL,
    `page_id` BIGINT UNSIGNED NOT NULL,
    `revision_number` INT UNSIGNED NOT NULL,
    `revision_kind` ENUM('autosave','manual','publish','rollback','ai_operation','import') NOT NULL DEFAULT 'manual',
    `schema_version` VARCHAR(16) NOT NULL DEFAULT '1.0',
    `document_json` LONGTEXT NOT NULL,
    `summary` VARCHAR(255) NULL DEFAULT NULL,
    `parent_revision_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_by` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_studiobuilder_rev_tenant_page_num` (`tenant_id`, `page_id`, `revision_number`),
    KEY `idx_studiobuilder_rev_tenant_page_created` (`tenant_id`, `page_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `studiobuilder_compilations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT UNSIGNED NOT NULL,
    `page_id` BIGINT UNSIGNED NOT NULL,
    `revision_id` BIGINT UNSIGNED NOT NULL,
    `compile_mode` ENUM('published','preview') NOT NULL DEFAULT 'published',
    `compiled_html` LONGTEXT NOT NULL,
    `compiled_css` MEDIUMTEXT NOT NULL,
    `dynamic_manifest_json` MEDIUMTEXT NULL,
    `head_assets_json` MEDIUMTEXT NULL,
    `content_hash` CHAR(64) NOT NULL,
    `compiler_version` VARCHAR(16) NOT NULL DEFAULT '1.0.0',
    `compiled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_studiobuilder_comp_tenant_page_mode` (`tenant_id`, `page_id`, `compile_mode`),
    KEY `idx_studiobuilder_comp_tenant_rev` (`tenant_id`, `revision_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `studiobuilder_dependencies` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT UNSIGNED NOT NULL,
    `page_id` BIGINT UNSIGNED NOT NULL,
    `revision_id` BIGINT UNSIGNED NOT NULL,
    `node_id` VARCHAR(64) NOT NULL,
    `dependency_type` ENUM('media','module','entity','token_group','partial','form') NOT NULL,
    `dependency_key` VARCHAR(191) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_studiobuilder_dep_tenant_page_rev` (`tenant_id`, `page_id`, `revision_id`),
    KEY `idx_studiobuilder_dep_tenant_lookup` (`tenant_id`, `dependency_type`, `dependency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `studiobuilder_templates` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT UNSIGNED NOT NULL,
    `uuid` CHAR(36) NOT NULL,
    `template_key` VARCHAR(120) NOT NULL,
    `template_type` ENUM('page_template','section_preset','header_preset','footer_preset','block_preset') NOT NULL DEFAULT 'section_preset',
    `category` VARCHAR(64) NOT NULL DEFAULT 'general',
    `name` VARCHAR(191) NOT NULL,
    `description` TEXT NULL,
    `thumbnail_media_id` INT UNSIGNED NULL DEFAULT NULL,
    `schema_version` VARCHAR(16) NOT NULL DEFAULT '1.0',
    `document_json` LONGTEXT NOT NULL,
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_studiobuilder_tpl_tenant_key` (`tenant_id`, `template_key`),
    KEY `idx_studiobuilder_tpl_tenant_type_cat` (`tenant_id`, `template_type`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `studiobuilder_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT UNSIGNED NOT NULL,
    `token_group` VARCHAR(64) NOT NULL DEFAULT 'default',
    `schema_version` VARCHAR(16) NOT NULL DEFAULT '1.0',
    `tokens_json` MEDIUMTEXT NOT NULL,
    `compiled_css_vars` MEDIUMTEXT NOT NULL,
    `updated_by` INT UNSIGNED NULL DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_studiobuilder_tokens_tenant_group` (`tenant_id`, `token_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `studiobuilder_locks` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT UNSIGNED NOT NULL,
    `page_id` BIGINT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `lock_token` CHAR(36) NOT NULL,
    `acquired_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `heartbeat_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_studiobuilder_locks_tenant_page` (`tenant_id`, `page_id`),
    KEY `idx_studiobuilder_locks_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
