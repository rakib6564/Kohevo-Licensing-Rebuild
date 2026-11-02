-- Restaurant plugin — install schema (Phase 1, as of 0.1.0).
-- Fresh installs get every table here. Existing installs are brought up to
-- date by version-gated migrations in Restaurant.php (mirrors Booking/Shop).
-- All money is stored as integer cents. Every table is tenant-scoped.

-- ── Menu ──────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `restaurant_menu_categories` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `name`       VARCHAR(160) NOT NULL,
    `slug`       VARCHAR(80) NOT NULL,
    `description` VARCHAR(255) NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_slug` (`tenant_id`, `slug`),
    KEY `tenant_active` (`tenant_id`, `is_active`),
    KEY `tenant_sort` (`tenant_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `restaurant_items` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `category_id`  INT UNSIGNED NULL,
    `name`         VARCHAR(160) NOT NULL,
    `slug`         VARCHAR(80) NOT NULL,
    `description`  TEXT NULL,
    `price_cents`  INT UNSIGNED NOT NULL DEFAULT 0,
    `tax_rate`     DECIMAL(6,3) NOT NULL DEFAULT 0,
    `photo_path`   VARCHAR(255) NULL,
    `daypart`      ENUM('all','breakfast','lunch','dinner','late') NOT NULL DEFAULT 'all',
    `is_86`        TINYINT(1) NOT NULL DEFAULT 0,
    `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`   INT NOT NULL DEFAULT 0,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_slug` (`tenant_id`, `slug`),
    KEY `tenant_active` (`tenant_id`, `is_active`),
    KEY `tenant_cat` (`tenant_id`, `category_id`),
    KEY `tenant_sort` (`tenant_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `restaurant_modifier_groups` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `name`        VARCHAR(160) NOT NULL,
    `min_select`  INT UNSIGNED NOT NULL DEFAULT 0,
    `max_select`  INT UNSIGNED NOT NULL DEFAULT 1,
    `is_required` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order`  INT NOT NULL DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `restaurant_modifiers` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `group_id`         INT UNSIGNED NOT NULL,
    `name`             VARCHAR(160) NOT NULL,
    `price_delta_cents` INT NOT NULL DEFAULT 0,
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`       INT NOT NULL DEFAULT 0,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_group` (`tenant_id`, `group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `restaurant_item_modifier_groups` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `item_id`    INT UNSIGNED NOT NULL,
    `group_id`   INT UNSIGNED NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `item_group` (`item_id`, `group_id`),
    KEY `tenant_item` (`tenant_id`, `item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Floor ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `restaurant_sections` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `name`       VARCHAR(120) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `restaurant_tables` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `section_id` INT UNSIGNED NULL,
    `label`      VARCHAR(40) NOT NULL,
    `seats`      INT UNSIGNED NOT NULL DEFAULT 2,
    `status`     ENUM('open','seated','dirty') NOT NULL DEFAULT 'open',
    `pos_x`      INT NOT NULL DEFAULT 0,
    `pos_y`      INT NOT NULL DEFAULT 0,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_section` (`tenant_id`, `section_id`),
    KEY `tenant_status` (`tenant_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Customers ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `restaurant_customers` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `name`       VARCHAR(160) NOT NULL,
    `phone`      VARCHAR(40) NULL,
    `email`      VARCHAR(200) NULL,
    `notes`      TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant` (`tenant_id`),
    KEY `tenant_phone` (`tenant_id`, `phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Orders ────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `restaurant_orders` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`           INT UNSIGNED NOT NULL DEFAULT 1,
    `order_no`            VARCHAR(24) NOT NULL,
    `type`                ENUM('dine_in','takeout','delivery') NOT NULL DEFAULT 'dine_in',
    `table_id`            INT UNSIGNED NULL,
    `customer_id`         INT UNSIGNED NULL,
    `server_id`           INT UNSIGNED NULL,
    `status`              ENUM('open','sent','paid','closed','voided') NOT NULL DEFAULT 'open',
    `guest_count`         INT UNSIGNED NOT NULL DEFAULT 1,
    `subtotal_cents`      INT UNSIGNED NOT NULL DEFAULT 0,
    `discount_cents`      INT UNSIGNED NOT NULL DEFAULT 0,
    `service_charge_pct`  DECIMAL(6,3) NOT NULL DEFAULT 0,
    `service_charge_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `tax_cents`           INT UNSIGNED NOT NULL DEFAULT 0,
    `tip_cents`           INT UNSIGNED NOT NULL DEFAULT 0,
    `total_cents`         INT UNSIGNED NOT NULL DEFAULT 0,
    `paid_cents`          INT UNSIGNED NOT NULL DEFAULT 0,
    `source`              ENUM('pos','online') NOT NULL DEFAULT 'pos',
    `notes`               VARCHAR(255) NULL,
    `opened_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `closed_at`           DATETIME NULL,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_orderno` (`tenant_id`, `order_no`),
    KEY `tenant_status` (`tenant_id`, `status`),
    KEY `tenant_table` (`tenant_id`, `table_id`),
    KEY `tenant_opened` (`tenant_id`, `opened_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `restaurant_order_items` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`           INT UNSIGNED NOT NULL DEFAULT 1,
    `order_id`            INT UNSIGNED NOT NULL,
    `check_no`            INT UNSIGNED NOT NULL DEFAULT 1,
    `item_id`             INT UNSIGNED NULL,
    `name_snapshot`       VARCHAR(160) NOT NULL,
    `qty`                 INT UNSIGNED NOT NULL DEFAULT 1,
    `unit_price_cents`    INT UNSIGNED NOT NULL DEFAULT 0,
    `modifiers_total_cents` INT NOT NULL DEFAULT 0,
    `line_total_cents`    INT NOT NULL DEFAULT 0,
    `tax_rate`            DECIMAL(6,3) NOT NULL DEFAULT 0,
    `seat`                INT UNSIGNED NULL,
    `notes`               VARCHAR(255) NULL,
    `kitchen_status`      ENUM('new','sent','ready','served','void') NOT NULL DEFAULT 'new',
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_order` (`tenant_id`, `order_id`),
    KEY `order_check` (`order_id`, `check_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `restaurant_order_item_modifiers` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`      INT UNSIGNED NOT NULL DEFAULT 1,
    `order_item_id`  INT UNSIGNED NOT NULL,
    `modifier_id`    INT UNSIGNED NULL,
    `name_snapshot`  VARCHAR(160) NOT NULL,
    `price_delta_cents` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `order_item` (`order_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `restaurant_payments` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`       INT UNSIGNED NOT NULL DEFAULT 1,
    `order_id`        INT UNSIGNED NOT NULL,
    `check_no`        INT UNSIGNED NOT NULL DEFAULT 1,
    `method`          ENUM('cash','terminal','other') NOT NULL DEFAULT 'cash',
    `amount_cents`    INT UNSIGNED NOT NULL DEFAULT 0,
    `tip_cents`       INT UNSIGNED NOT NULL DEFAULT 0,
    `status`          ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'paid',
    `stripe_pi_id`    VARCHAR(120) NULL,
    `stripe_charge_id` VARCHAR(120) NULL,
    `reader_id`       INT UNSIGNED NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_order` (`tenant_id`, `order_id`),
    KEY `pi` (`stripe_pi_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `restaurant_readers` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `label`            VARCHAR(120) NOT NULL,
    `stripe_reader_id` VARCHAR(120) NOT NULL,
    `status`           ENUM('online','offline','unknown') NOT NULL DEFAULT 'unknown',
    `last_seen_at`     DATETIME NULL,
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_reader` (`tenant_id`, `stripe_reader_id`),
    KEY `tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
