-- Membership plugin — install schema (full, as of 0.1.0).
-- Fresh installs get every table here. Existing installs are kept current by
-- the idempotent MembershipAPI::ensureSchema() self-heal called on boot, plus
-- version-gated migrations in Membership.php.
--
-- Identity is NOT duplicated: members are core `customers`. These tables hang
-- off `customer_id` and add the membership layer (plans, subscriptions,
-- profile, wallet). Payments live in the shared `stripepayment_charges`
-- ledger; `membership_subscriptions.charge_id` points at it.

-- ── Plans ────────────────────────────────────────────────────────────────
-- A sellable plan. Bilingual name/description (FR primary, EN secondary).
-- plan_type distinguishes a base membership, an insurance add-on, and a
-- course-specific pass. `course_id` optionally links a course plan to a
-- Booking service (booking_services.id) — kept loose (no FK) so the plugins
-- stay independently installable.
CREATE TABLE IF NOT EXISTS `membership_plans` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT UNSIGNED NOT NULL DEFAULT 1,
    `name`              VARCHAR(160) NOT NULL,
    `name_fr`           VARCHAR(160) NULL,
    `description`       TEXT NULL,
    `description_fr`    TEXT NULL,
    `plan_type`         ENUM('membership','insurance','course') NOT NULL DEFAULT 'membership',
    `course_id`         INT UNSIGNED NULL,
    `price_cents`       INT UNSIGNED NOT NULL DEFAULT 0,
    `currency`          VARCHAR(8) NOT NULL DEFAULT 'USD',
    `duration_days`     INT UNSIGNED NOT NULL DEFAULT 365,
    `session_quota`     INT UNSIGNED NOT NULL DEFAULT 0,
    `grace_days`        INT UNSIGNED NOT NULL DEFAULT 0,
    `requires_insurance` TINYINT(1) NOT NULL DEFAULT 0,
    `insurance_mode`    ENUM('none','optional','required') NOT NULL DEFAULT 'none',
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`        INT NOT NULL DEFAULT 0,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_active` (`tenant_id`, `is_active`),
    KEY `tenant_type`   (`tenant_id`, `plan_type`),
    KEY `tenant_sort`   (`tenant_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Subscriptions ────────────────────────────────────────────────────────
-- A member's purchased term of a plan. Fixed-term: starts_at → expires_at,
-- then a grace window (grace_until) before it's treated as fully expired.
-- `amount_cents` snapshots the price paid so later plan edits don't rewrite
-- history. `charge_id` links the Stripe charge; `activation` records whether
-- it was bought online or activated manually by an admin (cash/in-person).
-- reminder_*_sent are dedup guards for the expiry-reminder cron.
CREATE TABLE IF NOT EXISTS `membership_subscriptions` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`       INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`     INT UNSIGNED NOT NULL,
    `plan_id`         INT UNSIGNED NOT NULL,
    `status`          ENUM('pending','active','expired','cancelled','paused') NOT NULL DEFAULT 'pending',
    `starts_at`       DATETIME NULL,
    `expires_at`      DATETIME NULL,
    `grace_until`     DATETIME NULL,
    `cancelled_at`    DATETIME NULL,
    `amount_cents`    INT UNSIGNED NOT NULL DEFAULT 0,
    `currency`        VARCHAR(8) NOT NULL DEFAULT 'USD',
    `insurance_included` TINYINT(1) NOT NULL DEFAULT 0,
    `insurance_fee_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `activation`      ENUM('online','manual') NOT NULL DEFAULT 'online',
    `charge_id`       INT UNSIGNED NULL,
    `stripe_session_id` VARCHAR(255) NULL,
    `reminder_7d_sent` TINYINT(1) NOT NULL DEFAULT 0,
    `reminder_3d_sent` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_customer_status` (`tenant_id`, `customer_id`, `status`),
    KEY `tenant_status_expires` (`tenant_id`, `status`, `expires_at`),
    KEY `plan_idx` (`plan_id`),
    KEY `stripe_session_idx` (`stripe_session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Member profiles ──────────────────────────────────────────────────────
-- One row per core customer. Holds the membership-specific profile the
-- onboarding wizard fills in, plus the QR token for the digital member card
-- and a per-member locale (FR primary).
CREATE TABLE IF NOT EXISTS `membership_profiles` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`           INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`         INT UNSIGNED NOT NULL,
    `gender`              ENUM('female','male','other','undisclosed') NOT NULL DEFAULT 'undisclosed',
    `dob`                 DATE NULL,
    `skill_level`         ENUM('beginner','intermediate','advanced','none') NOT NULL DEFAULT 'none',
    `medical_notes`       TEXT NULL,
    `allergies`           TEXT NULL,
    `emergency_name`      VARCHAR(160) NULL,
    `emergency_phone`     VARCHAR(40) NULL,
    `emergency_relation`  VARCHAR(80) NULL,
    `avatar_path`         VARCHAR(255) NULL,
    `consent_terms`       TINYINT(1) NOT NULL DEFAULT 0,
    `consent_media`       TINYINT(1) NOT NULL DEFAULT 0,
    `consent_at`          DATETIME NULL,
    `onboarding_step`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `onboarding_complete` TINYINT(1) NOT NULL DEFAULT 0,
    `qr_token`            CHAR(32) NULL,
    `locale`              VARCHAR(5) NOT NULL DEFAULT 'fr',
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_customer` (`tenant_id`, `customer_id`),
    UNIQUE KEY `qr_token` (`qr_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Wallet ───────────────────────────────────────────────────────────────
-- Per-member balance (signed cents) + an append-only transaction ledger.
CREATE TABLE IF NOT EXISTS `membership_wallet` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`   INT UNSIGNED NOT NULL,
    `balance_cents` INT NOT NULL DEFAULT 0,
    `currency`      VARCHAR(8) NOT NULL DEFAULT 'USD',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_customer` (`tenant_id`, `customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `membership_wallet_txns` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`          INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`        INT UNSIGNED NOT NULL,
    `delta_cents`        INT NOT NULL DEFAULT 0,
    `balance_after_cents` INT NOT NULL DEFAULT 0,
    `type`               ENUM('purchase','refund','adjustment','failed','topup') NOT NULL DEFAULT 'adjustment',
    `description`        VARCHAR(255) NULL,
    `ref`                VARCHAR(120) NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_customer_created` (`tenant_id`, `customer_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
