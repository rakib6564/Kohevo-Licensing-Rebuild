-- Backups plugin — install schema.
-- One row per backup attempt; `status` doubles as the resumable-step
-- cursor advanced by BackupRunner::advance() on each frequent_cron tick.

CREATE TABLE IF NOT EXISTS `backups_runs` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`       INT UNSIGNED NOT NULL DEFAULT 1,
    `status`          ENUM('pending','dumping','zipping','uploading','pruning','done','failed') NOT NULL DEFAULT 'pending',
    `progress_json`   LONGTEXT NULL,
    `local_path`      VARCHAR(500) NULL,
    `drive_file_id`   VARCHAR(120) NULL,
    `drive_file_name` VARCHAR(255) NULL,
    `size_bytes`      BIGINT UNSIGNED NULL,
    `error`           TEXT NULL,
    `started_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_at`     DATETIME NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_status` (`tenant_id`, `status`),
    KEY `tenant_created` (`tenant_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
