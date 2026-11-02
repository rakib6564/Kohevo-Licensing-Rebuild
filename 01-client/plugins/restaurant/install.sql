-- Restaurant plugin — install schema (as of 0.5.1).
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
    `is_combo`     TINYINT(1) NOT NULL DEFAULT 0,
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

-- A combo is a menu item (is_combo = 1) priced at its own price_cents, plus
-- slots ("2 sides", "a drink"), each offering a choice of other menu items
-- with an optional price delta. Picks are stored on the order line as
-- restaurant_order_item_modifiers rows with kind = 'component'.
CREATE TABLE IF NOT EXISTS `restaurant_combo_slots` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    `combo_item_id` INT UNSIGNED NOT NULL,
    `label`         VARCHAR(120) NOT NULL,
    `min_pick`      INT UNSIGNED NOT NULL DEFAULT 1,
    `max_pick`      INT UNSIGNED NOT NULL DEFAULT 1,
    `sort_order`    INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `tenant_combo` (`tenant_id`, `combo_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `restaurant_combo_choices` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT UNSIGNED NOT NULL DEFAULT 1,
    `slot_id`           INT UNSIGNED NOT NULL,
    `item_id`           INT UNSIGNED NOT NULL,
    `price_delta_cents` INT NOT NULL DEFAULT 0,
    `sort_order`        INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `slot_item` (`slot_id`, `item_id`),
    KEY `tenant_slot` (`tenant_id`, `slot_id`)
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
    `member_ref` VARCHAR(80) NULL,
    `first_seen_via` VARCHAR(60) NULL,
    `notes`      TEXT NULL,
    `hist_spend_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `hist_orders`      INT UNSIGNED NOT NULL DEFAULT 0,
    `hist_first_seen`  DATE NULL,
    `hist_last_visit`  DATE NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `archived_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant` (`tenant_id`),
    KEY `tenant_phone` (`tenant_id`, `phone`),
    KEY `tenant_member` (`tenant_id`, `member_ref`)
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
    `discount_fixed_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `discount_pct`        DECIMAL(6,3) NOT NULL DEFAULT 0,
    `discount_reason`     VARCHAR(160) NULL,
    `comp_cents`          INT UNSIGNED NOT NULL DEFAULT 0,
    `comp_reason`         VARCHAR(160) NULL,
    `service_charge_pct`  DECIMAL(6,3) NOT NULL DEFAULT 0,
    `service_charge_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `tax_cents`           INT UNSIGNED NOT NULL DEFAULT 0,
    `tip_cents`           INT UNSIGNED NOT NULL DEFAULT 0,
    `total_cents`         INT UNSIGNED NOT NULL DEFAULT 0,
    `paid_cents`          INT UNSIGNED NOT NULL DEFAULT 0,
    `refunded_cents`      INT UNSIGNED NOT NULL DEFAULT 0,
    `source`              ENUM('pos','online','channel') NOT NULL DEFAULT 'pos',
    `channel`             VARCHAR(40) NULL,
    `external_ref`        VARCHAR(80) NULL,
    `flag_reason`         VARCHAR(255) NULL,
    `public_token`        VARCHAR(40) NULL,
    `fulfillment_status`  ENUM('new','accepted','preparing','ready','completed','cancelled') NOT NULL DEFAULT 'new',
    `requested_time`      VARCHAR(40) NULL,
    `notes`               VARCHAR(255) NULL,
    `opened_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `closed_at`           DATETIME NULL,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_orderno` (`tenant_id`, `order_no`),
    KEY `tenant_status` (`tenant_id`, `status`),
    KEY `tenant_table` (`tenant_id`, `table_id`),
    KEY `tenant_opened` (`tenant_id`, `opened_at`),
    KEY `tenant_customer` (`tenant_id`, `customer_id`, `status`),
    KEY `public_token` (`public_token`)
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
    `discount_cents`      INT UNSIGNED NOT NULL DEFAULT 0,
    `discount_fixed_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `discount_pct`        DECIMAL(6,3) NOT NULL DEFAULT 0,
    `discount_reason`     VARCHAR(160) NULL,
    `tax_rate`            DECIMAL(6,3) NOT NULL DEFAULT 0,
    `seat`                INT UNSIGNED NULL,
    `notes`               VARCHAR(255) NULL,
    `kitchen_status`      ENUM('new','sent','ready','served','void') NOT NULL DEFAULT 'new',
    `sent_at`             DATETIME NULL,
    `ready_at`            DATETIME NULL,
    `served_at`           DATETIME NULL,
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
    `kind`           ENUM('modifier','component') NOT NULL DEFAULT 'modifier',
    `slot_id`        INT UNSIGNED NULL,
    `component_item_id` INT UNSIGNED NULL,
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
    `refunded_cents`  INT UNSIGNED NOT NULL DEFAULT 0,
    `tendered_cents`  INT UNSIGNED NULL,
    `change_cents`    INT UNSIGNED NOT NULL DEFAULT 0,
    `stripe_pi_id`    VARCHAR(120) NULL,
    `stripe_charge_id` VARCHAR(120) NULL,
    `stripe_session_id` VARCHAR(120) NULL,
    `central_charge_id` INT UNSIGNED NULL,
    `reader_id`       INT UNSIGNED NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_order` (`tenant_id`, `order_id`),
    KEY `tenant_created` (`tenant_id`, `created_at`),
    KEY `pi` (`stripe_pi_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per refund against a payment. A payment's refunded_cents is the sum
-- of its succeeded rows here; status 'pending' is a refund sent to Stripe whose
-- answer has not come back yet, 'failed' is one Stripe (or the operator) rejected.
CREATE TABLE IF NOT EXISTS `restaurant_refunds` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `payment_id`       INT UNSIGNED NOT NULL,
    `order_id`         INT UNSIGNED NOT NULL,
    `method`           ENUM('cash','terminal','other') NOT NULL DEFAULT 'cash',
    `amount_cents`     INT UNSIGNED NOT NULL DEFAULT 0,
    `status`           ENUM('pending','succeeded','failed') NOT NULL DEFAULT 'pending',
    `stripe_refund_id` VARCHAR(120) NULL,
    `reason`           VARCHAR(255) NULL,
    `error`            VARCHAR(255) NULL,
    `user_id`          INT UNSIGNED NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_payment` (`tenant_id`, `payment_id`),
    KEY `tenant_order` (`tenant_id`, `order_id`),
    KEY `tenant_created` (`tenant_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Channels (aggregators and other order sources) ───────────────────────
-- A channel is an outside system that sends orders in our canonical JSON to
-- /order-channel/{slug}, signed with the channel's secret. Every order it
-- sends gets one restaurant_channel_orders row, unique per external id: that
-- row is the idempotency record, so a replayed delivery returns the first
-- outcome. Items arrive as the channel's SKUs and resolve through
-- restaurant_item_mappings; one unmapped SKU rejects the whole order.
CREATE TABLE IF NOT EXISTS `restaurant_channels` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `slug`             VARCHAR(40) NOT NULL,
    `name`             VARCHAR(120) NOT NULL,
    `is_enabled`       TINYINT(1) NOT NULL DEFAULT 0,
    `secret`           TEXT NULL,
    `callback_url`     VARCHAR(500) NULL,
    `last_verified_at` DATETIME NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_slug` (`tenant_id`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `restaurant_item_mappings` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `channel`      VARCHAR(40) NOT NULL,
    `external_sku` VARCHAR(120) NOT NULL,
    `item_id`      INT UNSIGNED NULL,
    `modifier_map` TEXT NULL,
    `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_channel_sku` (`tenant_id`, `channel`, `external_sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `restaurant_channel_orders` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`           INT UNSIGNED NOT NULL DEFAULT 1,
    `channel`             VARCHAR(40) NOT NULL,
    `external_order_id`   VARCHAR(120) NOT NULL,
    `order_id`            INT UNSIGNED NULL,
    `intake_status`       ENUM('received','created','rejected') NOT NULL DEFAULT 'received',
    `reject_reason`       VARCHAR(500) NULL,
    `unmapped`            TEXT NULL,
    `http_status`         SMALLINT UNSIGNED NULL,
    `response`            TEXT NULL,
    `callback_url`        VARCHAR(500) NULL,
    `claimed_total_cents` INT NULL,
    `raw_payload`         MEDIUMTEXT NULL,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_channel_ext` (`tenant_id`, `channel`, `external_order_id`),
    KEY `tenant_order` (`tenant_id`, `order_id`),
    KEY `tenant_status` (`tenant_id`, `intake_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each status callback we sent back to a channel, for support and retries.
CREATE TABLE IF NOT EXISTS `restaurant_channel_events` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `channel_order_id` INT UNSIGNED NOT NULL,
    `status`           VARCHAR(20) NOT NULL,
    `http_status`      SMALLINT UNSIGNED NULL,
    `error`            VARCHAR(255) NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_co` (`tenant_id`, `channel_order_id`)
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
