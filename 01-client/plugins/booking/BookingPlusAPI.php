<?php
/**
 * Booking — Native Capability Services (formerly Booking+).
 *
 * Implements client messaging, advanced service prerequisites/gates,
 * slot restrictions, and custom multi-tier reminder overlays directly
 * within the core Booking plugin as modular capabilities.
 *
 * Fully backwards-compatible with existing BookingPlusAPI signatures.
 */

declare(strict_types=1);

if (!class_exists('BookingPlusAPI')) {
    class BookingPlusAPI {

        // ── Schema self-heal ─────────────────────────────────────────────────
        public static function ensureSchema(): void {
            $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `bookingplus_service_config` (
    `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`              INT UNSIGNED NOT NULL DEFAULT 1,
    `service_id`             INT UNSIGNED NOT NULL,
    `min_advance_days`       INT UNSIGNED NOT NULL DEFAULT 0,
    `prereq_service_id`      INT UNSIGNED NULL,
    `prereq_message`         VARCHAR(500) NULL,
    `hsr_redirect_service_id` INT UNSIGNED NULL,
    `prep_page_url`          VARCHAR(500) NULL,
    `whatsapp_url`           VARCHAR(500) NULL,
    `auto_response_subject`  VARCHAR(200) NULL,
    `auto_response_body`     TEXT NULL,
    `reminder_8day_body`     TEXT NULL,
    `reminder_1day_body`     TEXT NULL,
    `reminder_10min_body`    TEXT NULL,
    `zoom_mode`              ENUM('manual','fallback_message','api') NOT NULL DEFAULT 'fallback_message',
    `zoom_join_url`          VARCHAR(500) NULL,
    `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_service` (`tenant_id`, `service_id`),
    KEY `tenant_prereq` (`tenant_id`, `prereq_service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bookingplus_slot_restrictions` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `service_id`  INT UNSIGNED NOT NULL,
    `provider_id` INT UNSIGNED NULL,
    `day_of_week` TINYINT NOT NULL,
    `start_time`  TIME NOT NULL,
    `end_time`    TIME NOT NULL,
    `label`       VARCHAR(80) NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tenant_day` (`tenant_id`, `day_of_week`),
    KEY `tenant_service` (`tenant_id`, `service_id`),
    KEY `tenant_provider` (`tenant_id`, `provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bookingplus_appointment_meta` (
    `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`              INT UNSIGNED NOT NULL DEFAULT 1,
    `appointment_id`         INT UNSIGNED NOT NULL,
    `zoom_join_url`          VARCHAR(500) NULL,
    `zoom_link_sent_at`      DATETIME NULL,
    `client_message`         TEXT NULL,
    `client_message_at`      DATETIME NULL,
    `therapist_notified_at`  DATETIME NULL,
    `therapist_replied_at`   DATETIME NULL,
    `nudge_sent_at`          DATETIME NULL,
    `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_appt` (`tenant_id`, `appointment_id`),
    KEY `nudge_scan` (`tenant_id`, `therapist_replied_at`, `nudge_sent_at`, `therapist_notified_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
            foreach (preg_split("/;\s*[\r\n]/", $sql) as $stmt) {
                $stmt = trim($stmt);
                if ($stmt !== '' && stripos($stmt, 'CREATE') !== false) {
                    try {
                        Database::query($stmt);
                    } catch (\Throwable $e) {
                        slate_log('Booking capability schema replay failed: ' . $e->getMessage(), 'warning');
                    }
                }
            }
        }

        // ── Service config CRUD ──────────────────────────────────────────────

        public static function getServiceConfig(int $serviceId): array {
            $tid = current_tenant_id();
            $row = Database::row(
                "SELECT * FROM bookingplus_service_config WHERE tenant_id = ? AND service_id = ? LIMIT 1",
                [$tid, $serviceId]
            );
            return $row ?: self::defaultServiceConfig($serviceId);
        }

        public static function defaultServiceConfig(int $serviceId): array {
            return [
                'id'                      => 0,
                'tenant_id'               => current_tenant_id(),
                'service_id'              => $serviceId,
                'min_advance_days'        => 0,
                'prereq_service_id'       => null,
                'prereq_message'          => null,
                'hsr_redirect_service_id' => null,
                'prep_page_url'           => null,
                'whatsapp_url'            => null,
                'auto_response_subject'   => null,
                'auto_response_body'      => null,
                'reminder_8day_body'      => null,
                'reminder_1day_body'      => null,
                'reminder_10min_body'     => null,
                'zoom_mode'               => 'fallback_message',
                'zoom_join_url'           => null,
            ];
        }

        public static function saveServiceConfig(int $serviceId, array $fields): int {
            $tid = current_tenant_id();
            $allowed = [
                'min_advance_days', 'prereq_service_id', 'prereq_message',
                'hsr_redirect_service_id', 'prep_page_url', 'whatsapp_url',
                'auto_response_subject', 'auto_response_body',
                'reminder_8day_body', 'reminder_1day_body', 'reminder_10min_body',
                'zoom_mode', 'zoom_join_url',
            ];
            $data = [];
            foreach ($allowed as $k) {
                if (array_key_exists($k, $fields)) $data[$k] = $fields[$k];
            }
            if (isset($data['zoom_mode']) && !in_array($data['zoom_mode'], ['manual','fallback_message','api'], true)) {
                $data['zoom_mode'] = 'fallback_message';
            }

            $existing = Database::row(
                "SELECT id FROM bookingplus_service_config WHERE tenant_id = ? AND service_id = ?",
                [$tid, $serviceId]
            );
            if ($existing) {
                Database::update('bookingplus_service_config', $data, 'id = ?', [(int)$existing['id']]);
                return (int)$existing['id'];
            }
            $data['tenant_id']  = $tid;
            $data['service_id'] = $serviceId;
            return (int) Database::insert('bookingplus_service_config', $data);
        }

        // ── Appointment meta CRUD ────────────────────────────────────────────

        public static function getAppointmentMeta(int $apptId): array {
            $tid = current_tenant_id();
            $row = Database::row(
                "SELECT * FROM bookingplus_appointment_meta WHERE tenant_id = ? AND appointment_id = ? LIMIT 1",
                [$tid, $apptId]
            );
            return $row ?: [
                'id'                    => 0,
                'appointment_id'        => $apptId,
                'zoom_join_url'         => null,
                'zoom_link_sent_at'     => null,
                'client_message'        => null,
                'client_message_at'    => null,
                'therapist_notified_at' => null,
                'therapist_replied_at'  => null,
                'nudge_sent_at'         => null,
            ];
        }

        public static function saveAppointmentMeta(int $apptId, array $fields): int {
            $tid = current_tenant_id();
            $allowed = [
                'zoom_join_url', 'zoom_link_sent_at',
                'client_message', 'client_message_at',
                'therapist_notified_at', 'therapist_replied_at', 'nudge_sent_at',
            ];
            $data = [];
            foreach ($allowed as $k) {
                if (array_key_exists($k, $fields)) $data[$k] = $fields[$k];
            }
            $existing = Database::row(
                "SELECT id FROM bookingplus_appointment_meta WHERE tenant_id = ? AND appointment_id = ?",
                [$tid, $apptId]
            );
            if ($existing) {
                Database::update('bookingplus_appointment_meta', $data, 'id = ?', [(int)$existing['id']]);
                return (int)$existing['id'];
            }
            $data['tenant_id']      = $tid;
            $data['appointment_id'] = $apptId;
            return (int) Database::insert('bookingplus_appointment_meta', $data);
        }

        // ── Template rendering ───────────────────────────────────────────────

        public static function renderTemplate(string $tpl, array $ctx): string {
            $ts = strtotime((string)($ctx['starts_at'] ?? 'now')) ?: time();
            $map = [
                '{{name}}'         => e((string)($ctx['customer_name']  ?? '')),
                '{{service}}'      => e((string)($ctx['service_name']   ?? '')),
                '{{provider}}'     => e((string)($ctx['provider_name']  ?? '')),
                '{{when}}'         => e(slate_format_datetime($ts, 'l, j F Y')),
                '{{date}}'         => e(I18n::localDate('j M Y', $ts)),
                '{{time}}'         => e(slate_format_time($ts)),
                '{{ref}}'          => e((string)($ctx['ref']            ?? '')),
                '{{prep_url}}'     => e((string)($ctx['prep_url']       ?? '')),
                '{{whatsapp_url}}' => e((string)($ctx['whatsapp_url']   ?? '')),
                '{{payment_note}}' => (string)($ctx['payment_note']  ?? ''),
                '{{zoom_url}}'     => e((string)($ctx['zoom_url']       ?? '')),
                '{{payment_link}}' => e((string)($ctx['payment_link']   ?? '')),
                '{{message_url}}'  => e((string)($ctx['message_url']    ?? '')),
            ];
            return strtr($tpl, $map);
        }

        public static function paymentNote(array $service): string {
            $mode = (string)($service['payment_mode'] ?? 'free');
            switch ($mode) {
                case 'free':
                    return '<p>This session is free of charge — no payment required.</p>';
                case 'deposit':
                    return '<p>The first hour is paid at booking; the balance is settled on the day of the session.</p>';
                case 'onsite':
                    return '<p>Payment is handled in person at the session.</p>';
                case 'full':
                default:
                    return '<p>You will receive your payment link 8 days before the session.</p>';
            }
        }

        // ── Prereq checking ──────────────────────────────────────────────────

        public static function customerHasCompleted(string $email, int $serviceId): bool {
            if ($email === '' || $serviceId <= 0) return false;
            $tid = current_tenant_id();
            $row = Database::row(
                "SELECT 1 FROM booking_appointments
                  WHERE tenant_id = ? AND service_id = ? AND customer_email = ?
                    AND status IN ('confirmed','completed')
                    AND starts_at <= NOW()
                  LIMIT 1",
                [$tid, $serviceId, $email]
            );
            return (bool) $row;
        }

        // ── Global settings helpers ──────────────────────────────────────────

        public static function globalWhatsappUrl(): string {
            return (string) (Database::setting('bookingplus.whatsapp_url') ?? Database::setting('booking.whatsapp_url') ?? '');
        }

        public static function globalNudgeHours(): int {
            $h = (int) (Database::setting('bookingplus.nudge_hours') ?? Database::setting('booking.nudge_hours') ?? 8);
            return max(1, $h);
        }

        public static function defaultReminderLeads(): string {
            return '11520,1440,10';
        }

        public static function saveSettings(array $post): void {
            if (!Auth::can('booking.manage_settings') && !Auth::isSuperAdmin()) return;

            if (array_key_exists('bookingplus_whatsapp_url', $post)) {
                Database::setSetting('bookingplus.whatsapp_url', trim((string) $post['bookingplus_whatsapp_url']));
            }
            if (array_key_exists('bookingplus_nudge_hours', $post)) {
                Database::setSetting('bookingplus.nudge_hours', (string) max(1, min(72, (int) $post['bookingplus_nudge_hours'])));
            }
        }
    }
}
