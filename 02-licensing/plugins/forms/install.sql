-- Forms plugin — install schema.
-- Tables all carry the `forms_` prefix, enforced by the loader.

CREATE TABLE IF NOT EXISTS `forms_definitions` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `slug`             VARCHAR(80) NOT NULL,
    `title`            VARCHAR(200) NOT NULL,
    `description`      TEXT NULL,
    `fields_json`      LONGTEXT NOT NULL,
    `submit_label`     VARCHAR(80) NOT NULL DEFAULT 'Submit',
    `success_message`  TEXT NULL,
    `redirect_url`     VARCHAR(500) NULL,
    `notify_email`     VARCHAR(200) NULL,
    `confirm_submitter` TINYINT(1) NOT NULL DEFAULT 0,
    `confirm_subject`  VARCHAR(200) NULL,
    `confirm_body`     TEXT NULL,
    `status`           ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    `submission_limit` INT UNSIGNED NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_slug` (`tenant_id`, `slug`),
    KEY `tenant_status` (`tenant_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forms_submissions` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    `form_id`       INT UNSIGNED NOT NULL,
    `ref`           VARCHAR(32) NOT NULL,
    `data_json`     LONGTEXT NOT NULL,
    `submitter_email` VARCHAR(200) NULL,
    `ip`            VARCHAR(60) NULL,
    `user_agent`    VARCHAR(255) NULL,
    `read_at`       DATETIME NULL,
    `email_sent`    TINYINT(1) NOT NULL DEFAULT 0,
    `email_error`   TEXT NULL,
    `country`       VARCHAR(2) NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_ref` (`tenant_id`, `ref`),
    KEY `tenant_form` (`tenant_id`, `form_id`),
    KEY `tenant_read` (`tenant_id`, `read_at`),
    KEY `tenant_created` (`tenant_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forms_webhooks` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
    `form_id`      INT UNSIGNED NOT NULL,
    `url`          VARCHAR(500) NOT NULL,
    `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_form` (`tenant_id`, `form_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forms_webhook_log` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    `webhook_id`    INT UNSIGNED NOT NULL,
    `submission_id` INT UNSIGNED NULL,
    `status_code`   INT NULL,
    `response_body` TEXT NULL,
    `error`         TEXT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_webhook` (`tenant_id`, `webhook_id`),
    KEY `tenant_created` (`tenant_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forms_spam_log` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `form_id`    INT UNSIGNED NOT NULL,
    `code`       VARCHAR(40) NOT NULL,
    `reason`     VARCHAR(255) NULL,
    `ip`         VARCHAR(60) NULL,
    `country`    VARCHAR(2) NULL,
    `user_agent` VARCHAR(255) NULL,
    `snippet`    TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_form` (`tenant_id`, `form_id`),
    KEY `tenant_created` (`tenant_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
