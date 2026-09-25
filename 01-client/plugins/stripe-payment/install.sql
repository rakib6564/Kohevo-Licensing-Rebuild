-- Stripe Payment Gateway — install schema.
--
-- Two tables:
--   * stripepayment_sessions — Shop-specific mapping from Stripe
--     session/intent ids to shop cart sids and resulting order_ids.
--     This is back-compat with the v1.x Shop checkout flow.
--   * stripepayment_charges — generic charge ledger (Phase 2). Every
--     successful Stripe charge across all consuming plugins (Shop,
--     Booking, Forms, etc.) lands here so the Charges admin page can
--     show one cross-plugin view and refunds become a one-liner.

CREATE TABLE IF NOT EXISTS stripepayment_sessions (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    stripe_session_id VARCHAR(255) NULL,
    payment_intent_id VARCHAR(255) NULL,
    shop_sid          VARCHAR(64)  NOT NULL,
    flow              VARCHAR(16)  NOT NULL DEFAULT 'hosted',
    order_id          INT UNSIGNED NULL,
    status            VARCHAR(20)  NOT NULL DEFAULT 'pending',
    billing_json      TEXT NULL,
    created_at        DATETIME NOT NULL,
    completed_at      DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_stripe_session_id (stripe_session_id),
    UNIQUE KEY uniq_payment_intent_id (payment_intent_id),
    KEY idx_shop_sid (shop_sid),
    KEY idx_status   (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `stripepayment_charges` (
    `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`                INT UNSIGNED NOT NULL DEFAULT 1,
    `mode`                     ENUM('test','live') NOT NULL DEFAULT 'test',
    `source_plugin`            VARCHAR(80) NOT NULL,
    `source_id`                VARCHAR(190) NULL,
    `stripe_payment_intent_id` VARCHAR(255) NULL,
    `stripe_session_id`        VARCHAR(255) NULL,
    `customer_email`           VARCHAR(200) NULL,
    `amount_cents`             INT UNSIGNED NOT NULL DEFAULT 0,
    `refunded_cents`           INT UNSIGNED NOT NULL DEFAULT 0,
    `currency`                 VARCHAR(8) NOT NULL DEFAULT 'USD',
    `status`                   ENUM('succeeded','refunded','partial_refund','failed') NOT NULL DEFAULT 'succeeded',
    `meta_json`                LONGTEXT NULL,
    `created_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tenant_created`   (`tenant_id`, `created_at`),
    KEY `idx_source`           (`tenant_id`, `source_plugin`, `source_id`),
    KEY `idx_pi`               (`stripe_payment_intent_id`),
    KEY `idx_session`          (`stripe_session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
