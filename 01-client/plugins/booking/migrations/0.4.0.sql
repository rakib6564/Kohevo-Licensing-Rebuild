-- Booking 0.4.0 — customer management.
-- A booking-specific customer record keyed by email (guests included),
-- carrying profile fields, no-show / loyalty counters and tags.

CREATE TABLE IF NOT EXISTS `booking_customers` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`       INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`     INT UNSIGNED NULL,
    `email`           VARCHAR(200) NOT NULL,
    `name`            VARCHAR(160) NOT NULL,
    `phone`           VARCHAR(40) NULL,
    `birthday`        DATE NULL,
    `notes`           TEXT NULL,
    `tags`            VARCHAR(255) NULL,
    `no_show_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    `completed_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `booking_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    `loyalty_points`  INT NOT NULL DEFAULT 0,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_email` (`tenant_id`, `email`),
    KEY `tenant_name` (`tenant_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
