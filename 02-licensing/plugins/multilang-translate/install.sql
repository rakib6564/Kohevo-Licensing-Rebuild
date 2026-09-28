-- Multilang Translate — install schema.

CREATE TABLE IF NOT EXISTS `multilangtranslate_languages` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `code`        VARCHAR(10) NOT NULL,
    `name`        VARCHAR(100) NOT NULL,
    `native_name` VARCHAR(100) NOT NULL DEFAULT '',
    `flag`        VARCHAR(10) NOT NULL DEFAULT '',
    `is_default`  TINYINT(1) NOT NULL DEFAULT 0,
    `enabled`     TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`  INT NOT NULL DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_code` (`tenant_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `multilangtranslate_strings` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `text_hash`    CHAR(40) NOT NULL,
    `source_text`  TEXT NOT NULL,
    `source_area`  VARCHAR(40) NOT NULL DEFAULT 'unknown',
    `occurrences`  INT UNSIGNED NOT NULL DEFAULT 1,
    `ignored`      TINYINT(1) NOT NULL DEFAULT 0,
    `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_hash` (`tenant_id`, `text_hash`),
    KEY `tenant_area` (`tenant_id`, `source_area`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `multilangtranslate_translations` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `string_id`        INT UNSIGNED NOT NULL,
    `locale`           VARCHAR(10) NOT NULL,
    `translated_text`  TEXT NULL,
    `status`           ENUM('blank','draft','published') NOT NULL DEFAULT 'blank',
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `string_locale` (`string_id`, `locale`),
    KEY `tenant_locale_status` (`tenant_id`, `locale`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 2: exclusion rules — patterns that stop text from ever being
-- harvested (system codes, emails, sensitive strings, boilerplate noise).
-- Checked by the Harvester before a string is written to
-- multilangtranslate_strings; matched strings are silently skipped.
CREATE TABLE IF NOT EXISTS `multilangtranslate_exclude_rules` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`      INT UNSIGNED NOT NULL DEFAULT 1,
    `pattern`        VARCHAR(500) NOT NULL,
    `pattern_type`   ENUM('contains','exact','regex') NOT NULL DEFAULT 'contains',
    `target_area`    ENUM('any','admin','customer','public') NOT NULL DEFAULT 'any',
    `case_sensitive` TINYINT(1) NOT NULL DEFAULT 0,
    `enabled`        TINYINT(1) NOT NULL DEFAULT 1,
    `note`           VARCHAR(255) NOT NULL DEFAULT '',
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_enabled` (`tenant_id`, `enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
