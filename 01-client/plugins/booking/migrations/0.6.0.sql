-- Booking 0.6.0 — Google Calendar 2-way sync.
-- Per-provider OAuth connection state lives as new columns on
-- booking_providers/booking_appointments (added via ensureColumn() in
-- Booking.php, not here — this file is only for the one new table).
--
-- Caches events on a connected provider's Google Calendar that Slate did
-- NOT create (their own meetings, days off, anything from another system).
-- BookingAPI::effectiveIntervals() subtracts these from availability so a
-- provider is never offered as bookable while they're actually busy.
-- Rows are fully rebuilt from Google's event feed on each pull, keyed by
-- the Google event id — never hand-edited.

CREATE TABLE IF NOT EXISTS `booking_google_busy_blocks` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`       INT UNSIGNED NOT NULL DEFAULT 1,
    `provider_id`     INT UNSIGNED NOT NULL,
    `google_event_id` VARCHAR(255) NOT NULL,
    `starts_at`       DATETIME NOT NULL,
    `ends_at`         DATETIME NOT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `provider_event` (`provider_id`, `google_event_id`),
    KEY `tenant_provider_window` (`tenant_id`, `provider_id`, `starts_at`, `ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
