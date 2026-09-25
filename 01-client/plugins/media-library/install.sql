-- Media library plugin schema.
--
-- This table is a METADATA CACHE for files that live on disk. The
-- filesystem is the source of truth — the library scans
-- uploads/shop/products/ (and subfolders) directly, then enriches each
-- result with cached metadata from this table when a row exists.
--
-- We don't INSERT here on install: existing files are picked up by the
-- scan, and new uploads through the library's own form insert their
-- own row. Pre-existing files just get on-the-fly dimension lookups
-- the first time they're listed.

CREATE TABLE IF NOT EXISTS `medialibrary_files` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    -- The stored relative path, e.g. /uploads/shop/products/abc123.webp.
    -- Same shape Uploads::handle() returns and the same shape stored
    -- in shop_products.image_url, so usage queries are direct == matches.
    `path`         VARCHAR(500) NOT NULL,
    `mime`         VARCHAR(100) NOT NULL DEFAULT '',
    `size_bytes`   INT UNSIGNED NOT NULL DEFAULT 0,
    `width`        INT UNSIGNED NULL,
    `height`       INT UNSIGNED NULL,
    `original_name` VARCHAR(255) NULL,
    `uploaded_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `uploaded_by`  INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_path` (`tenant_id`, `path`),
    KEY `tenant_uploaded_at` (`tenant_id`, `uploaded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
