-- Shop plugin — install schema (full, as of 0.3.0).
-- Fresh installs get every table here. Existing installs are kept current by
-- the idempotent ShopStoreAPI::ensureSchema() self-heal called on boot.
--
-- Every table carries tenant_id. A product carries its own currency, so a
-- change of the shop's default currency never re-denominates a price; an order
-- snapshots currency, prices, titles and SKUs so later product edits never
-- rewrite history. Payments live in the shared `stripepayment_charges` ledger;
-- `shop_orders.charge_id` points at it. Customers are core `customers`
-- (optional: guests check out with an email).

-- ── Products ─────────────────────────────────────────────────────────────
-- price_cents is VAT-inclusive (the price the customer pays). stock_qty NULL
-- means stock is not tracked; 0 means sold out. product_type: physical (shipped),
-- digital (files to download) or service (nothing to ship). weight and the three
-- sizes are in the shop's units (settings weight_unit, dimension_unit); NULL is
-- unknown. The sale price applies between sale_starts_at and sale_ends_at when
-- either is set. external_ref ties a product to the row it was imported from
-- (`woo:603`), so a second import updates it.
CREATE TABLE IF NOT EXISTS `shop_products` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT UNSIGNED NOT NULL DEFAULT 1,
    `title`             VARCHAR(200) NOT NULL,
    `slug`              VARCHAR(190) NOT NULL,
    `sku`               VARCHAR(64)  NULL,
    `price_cents`       INT UNSIGNED NOT NULL DEFAULT 0,
    `sale_price_cents`  INT UNSIGNED NULL,
    `currency`          VARCHAR(8)   NOT NULL DEFAULT 'EUR',
    `short_description` TEXT NULL,
    `description`       MEDIUMTEXT NULL,
    `images_json`       TEXT NULL,
    `attributes_json`   TEXT NULL,
    `stock_qty`         INT NULL,
    `product_type`      VARCHAR(16)  NOT NULL DEFAULT 'physical',
    `weight`            DECIMAL(10,3) NULL,
    `length`            DECIMAL(10,2) NULL,
    `width`             DECIMAL(10,2) NULL,
    `height`            DECIMAL(10,2) NULL,
    `sale_starts_at`    DATETIME NULL,
    `sale_ends_at`      DATETIME NULL,
    `featured`          TINYINT(1)   NOT NULL DEFAULT 0,
    `low_stock`         INT NULL,
    `purchase_note`     TEXT NULL,
    `download_limit`    INT NULL,
    `download_days`     INT NULL,
    `external_ref`      VARCHAR(64)  NULL,
    `status`            VARCHAR(16)  NOT NULL DEFAULT 'draft',
    `sort_order`        INT NOT NULL DEFAULT 0,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_slug` (`tenant_id`, `slug`),
    KEY `tenant_status_sort` (`tenant_id`, `status`, `sort_order`),
    KEY `tenant_external` (`tenant_id`, `external_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Variants ─────────────────────────────────────────────────────────────
-- A product with attributes (shop_products.attributes_json: [{name, values}]) is
-- sold as variants, one row per combination of values: options_json lists one
-- value per attribute, in the attributes' order; options_key is its SHA-1.
-- price_cents NULL means the product's price; stock_qty NULL means not tracked;
-- weight and sizes NULL mean the product's. A disabled variant is a combination
-- the shop does not sell.
CREATE TABLE IF NOT EXISTS `shop_variants` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT UNSIGNED NOT NULL DEFAULT 1,
    `product_id`        INT UNSIGNED NOT NULL,
    `options_json`      TEXT NOT NULL,
    `options_key`       CHAR(40) NOT NULL,
    `sku`               VARCHAR(64) NULL,
    `price_cents`       INT UNSIGNED NULL,
    `sale_price_cents`  INT UNSIGNED NULL,
    `stock_qty`         INT NULL,
    `image`             VARCHAR(500) NULL,
    `weight`            DECIMAL(10,3) NULL,
    `length`            DECIMAL(10,2) NULL,
    `width`             DECIMAL(10,2) NULL,
    `height`            DECIMAL(10,2) NULL,
    `external_ref`      VARCHAR(64) NULL,
    `enabled`           TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`        INT NOT NULL DEFAULT 0,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `product_options` (`tenant_id`, `product_id`, `options_key`),
    KEY `tenant_product` (`tenant_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Categories ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `shop_categories` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `name`        VARCHAR(120) NOT NULL,
    `slug`        VARCHAR(120) NOT NULL,
    `sort_order`  INT NOT NULL DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_slug` (`tenant_id`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_product_categories` (
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `product_id`  INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`product_id`, `category_id`),
    KEY `tenant_category` (`tenant_id`, `category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Tags ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `shop_tags` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `name`        VARCHAR(80)  NOT NULL,
    `slug`        VARCHAR(80)  NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_slug` (`tenant_id`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_product_tags` (
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `product_id`  INT UNSIGNED NOT NULL,
    `tag_id`      INT UNSIGNED NOT NULL,
    PRIMARY KEY (`product_id`, `tag_id`),
    KEY `tenant_tag` (`tenant_id`, `tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Linked products ──────────────────────────────────────────────────────
-- kind: upsell (shown on the product's page) or cross_sell (shown with the cart).
CREATE TABLE IF NOT EXISTS `shop_product_links` (
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `product_id`  INT UNSIGNED NOT NULL,
    `kind`        VARCHAR(16)  NOT NULL,
    `linked_id`   INT UNSIGNED NOT NULL,
    `sort_order`  INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`product_id`, `kind`, `linked_id`),
    KEY `tenant_product` (`tenant_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Digital files ────────────────────────────────────────────────────────
-- A digital product's files live outside the web root's public folders
-- (data/shop-files/<tenant>/); `path` is relative to that folder. A paid
-- order gets one shop_downloads row per file of each digital line, with an
-- unguessable token, and the product's download limit and expiry copied in.
CREATE TABLE IF NOT EXISTS `shop_product_files` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `product_id`  INT UNSIGNED NOT NULL,
    `name`        VARCHAR(190) NOT NULL,
    `path`        VARCHAR(255) NOT NULL,
    `size_bytes`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_product` (`tenant_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_downloads` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`       INT UNSIGNED NOT NULL DEFAULT 1,
    `order_id`        INT UNSIGNED NOT NULL,
    `order_item_id`   INT UNSIGNED NOT NULL,
    `file_id`         INT UNSIGNED NOT NULL,
    `token`           CHAR(40)     NOT NULL,
    `download_count`  INT UNSIGNED NOT NULL DEFAULT 0,
    `download_limit`  INT NULL,
    `expires_at`      DATETIME NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `token` (`token`),
    KEY `tenant_order` (`tenant_id`, `order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Orders ───────────────────────────────────────────────────────────────
-- status: pending_payment → paid → fulfilled; cancelled; refunded.
-- ref is shown to the customer; access_key is the unguessable half of the
-- confirmation link (a guest has no account to sign in with). delivery is
-- shipping, pickup, or none (an order with nothing to ship: digital or service).
CREATE TABLE IF NOT EXISTS `shop_orders` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT UNSIGNED NOT NULL DEFAULT 1,
    `ref`               VARCHAR(24)  NOT NULL,
    `access_key`        CHAR(40)     NOT NULL,
    `customer_id`       INT UNSIGNED NULL,
    `email`             VARCHAR(190) NOT NULL,
    `name`              VARCHAR(160) NOT NULL,
    `phone`             VARCHAR(40)  NULL,
    `delivery`          VARCHAR(16)  NOT NULL DEFAULT 'shipping',
    `address_line1`     VARCHAR(190) NULL,
    `address_line2`     VARCHAR(190) NULL,
    `postal_code`       VARCHAR(20)  NULL,
    `city`              VARCHAR(120) NULL,
    `country`           VARCHAR(2)   NULL,
    `notes`             TEXT NULL,
    `status`            VARCHAR(20)  NOT NULL DEFAULT 'pending_payment',
    `currency`          VARCHAR(8)   NOT NULL DEFAULT 'EUR',
    `subtotal_cents`    INT UNSIGNED NOT NULL DEFAULT 0,
    `shipping_cents`    INT UNSIGNED NOT NULL DEFAULT 0,
    `total_cents`       INT UNSIGNED NOT NULL DEFAULT 0,
    `stripe_session_id` VARCHAR(255) NULL,
    `charge_id`         INT UNSIGNED NULL,
    `stock_note`        VARCHAR(255) NULL,
    `paid_at`           DATETIME NULL,
    `fulfilled_at`      DATETIME NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_ref` (`tenant_id`, `ref`),
    KEY `tenant_status` (`tenant_id`, `status`),
    KEY `stripe_session` (`stripe_session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_order_items` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `order_id`         INT UNSIGNED NOT NULL,
    `product_id`       INT UNSIGNED NULL,
    `variant_id`       INT UNSIGNED NULL,
    `title`            VARCHAR(200) NOT NULL,
    `options_label`    VARCHAR(255) NULL,
    `sku`              VARCHAR(64)  NULL,
    `product_type`     VARCHAR(16)  NOT NULL DEFAULT 'physical',
    `unit_price_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `qty`              INT UNSIGNED NOT NULL DEFAULT 1,
    `line_total_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `order_items` (`tenant_id`, `order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
