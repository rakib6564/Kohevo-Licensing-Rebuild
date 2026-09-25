-- Booking 0.5.0 — payments & billing: coupons + gift cards.

CREATE TABLE IF NOT EXISTS `booking_coupons` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `code`        VARCHAR(60) NOT NULL,
    `type`        ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    `value`       INT UNSIGNED NOT NULL DEFAULT 0,
    `min_total_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `max_uses`    INT UNSIGNED NULL,
    `used_count`  INT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at`  DATETIME NULL,
    `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_code` (`tenant_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_gift_cards` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    `code`          VARCHAR(60) NOT NULL,
    `initial_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `balance_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `currency`      VARCHAR(8) NOT NULL DEFAULT 'USD',
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_code` (`tenant_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
