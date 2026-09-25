-- Booking 0.2.0 — foundation for the full feature set.
-- New tables only. Column additions to existing tables and all data
-- backfills run as version-gated PHP in Booking::runMigrations() so they
-- stay portable across MySQL/MariaDB. Every table is `booking_`-prefixed
-- (required by PluginLoader::validatePluginSql).

-- Service categories (grouping + ordering on the storefront/admin).
CREATE TABLE IF NOT EXISTS `booking_categories` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `name`       VARCHAR(160) NOT NULL,
    `slug`       VARCHAR(80) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_slug` (`tenant_id`, `slug`),
    KEY `tenant_sort` (`tenant_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Locations — physical sites or virtual ("online") meeting points.
CREATE TABLE IF NOT EXISTS `booking_locations` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `name`       VARCHAR(160) NOT NULL,
    `type`       ENUM('in_person','online') NOT NULL DEFAULT 'in_person',
    `address`    TEXT NULL,
    `meeting_url` VARCHAR(500) NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_active` (`tenant_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bookable resources (rooms, chairs, equipment). `capacity` allows a
-- resource to host more than one concurrent appointment when relevant.
CREATE TABLE IF NOT EXISTS `booking_resources` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `name`       VARCHAR(160) NOT NULL,
    `capacity`   INT UNSIGNED NOT NULL DEFAULT 1,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_active` (`tenant_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which resource(s) a service consumes. Many-to-many.
CREATE TABLE IF NOT EXISTS `booking_service_resources` (
    `service_id`  INT UNSIGNED NOT NULL,
    `resource_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`service_id`, `resource_id`),
    KEY `resource_idx` (`resource_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional paid add-ons / extras per service (e.g. "Hot towel +$5").
CREATE TABLE IF NOT EXISTS `booking_service_addons` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `service_id`   INT UNSIGNED NOT NULL,
    `name`         VARCHAR(160) NOT NULL,
    `price_cents`  INT UNSIGNED NOT NULL DEFAULT 0,
    `duration_min` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`   INT NOT NULL DEFAULT 0,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_service` (`tenant_id`, `service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custom booking-form fields. service_id NULL = applies to every service.
CREATE TABLE IF NOT EXISTS `booking_custom_fields` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `service_id`   INT UNSIGNED NULL,
    `label`        VARCHAR(200) NOT NULL,
    `name`         VARCHAR(80) NOT NULL,
    `type`         ENUM('text','textarea','select','checkbox','file') NOT NULL DEFAULT 'text',
    `options_json` TEXT NULL,
    `is_required`  TINYINT(1) NOT NULL DEFAULT 0,
    `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`   INT NOT NULL DEFAULT 0,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_service` (`tenant_id`, `service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recurring weekly break/lunch blocks per provider, subtracted from
-- working hours by the slot engine.
CREATE TABLE IF NOT EXISTS `booking_provider_breaks` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `provider_id` INT UNSIGNED NOT NULL,
    `day_of_week` TINYINT NOT NULL,
    `start_time`  TIME NOT NULL,
    `end_time`    TIME NOT NULL,
    `label`       VARCHAR(80) NULL,
    PRIMARY KEY (`id`),
    KEY `provider_day` (`provider_id`, `day_of_week`),
    KEY `tenant_provider` (`tenant_id`, `provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Date-specific overrides: holidays (is_closed=1) or special hours.
-- provider_id NULL = applies to the whole business.
CREATE TABLE IF NOT EXISTS `booking_date_overrides` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `provider_id` INT UNSIGNED NULL,
    `date`        DATE NOT NULL,
    `is_closed`   TINYINT(1) NOT NULL DEFAULT 1,
    `start_time`  TIME NULL,
    `end_time`    TIME NULL,
    `note`        VARCHAR(160) NULL,
    PRIMARY KEY (`id`),
    KEY `tenant_date` (`tenant_id`, `date`),
    KEY `provider_date` (`provider_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
