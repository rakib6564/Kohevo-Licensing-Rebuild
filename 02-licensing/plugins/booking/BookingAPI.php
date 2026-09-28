<?php
/**
 * Booking — public API.
 *
 * Static methods so other plugins can integrate without instantiating
 * the bootstrap class:
 *
 *   BookingAPI::getActiveServices()
 *   BookingAPI::getService(int|string $idOrSlug)
 *   BookingAPI::getProvidersForService(int $serviceId)
 *   BookingAPI::getProviderHours(int $providerId)
 *   BookingAPI::computeAvailableSlots(int $serviceId, int $providerId, string $date)
 *   BookingAPI::createAppointment(array $args)  // returns ['ok'=>..., 'id'=>..., 'ref'=>...]
 *   BookingAPI::generateRef()
 *
 * Slot engine notes:
 *   - Days are interpreted in the *site's* timezone (date_default_timezone_get()).
 *     This first cut treats providers' tz column as informational; we always
 *     compute slots in server-local time. Multi-tz support is a follow-up.
 *   - Slot interval = service.duration_min + service.buffer_min.
 *   - A slot is "available" iff:
 *       a) it sits entirely within at least one of the provider's
 *          weekly hours rows for that day_of_week, AND
 *       b) no confirmed appointment for the same provider overlaps it.
 *   - Race protection on createAppointment(): the final overlap check
 *     runs inside a transaction with SELECT ... FOR UPDATE so two
 *     concurrent requests can't both win.
 */

class BookingAPI {

    /** True once per request after we've confirmed the schema exists. */
    private static bool $schemaChecked = false;

    /**
     * Lazily create the booking_* tables if they don't already exist.
     * Defensive against half-activated installs (install.sql didn't
     * run or partially failed).
     */
    public static function ensureSchema(): void {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        try {
            $pdo = Database::get();
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `booking_services` (
                    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`      INT UNSIGNED NOT NULL DEFAULT 1,
                    `name`           VARCHAR(160) NOT NULL,
                    `slug`           VARCHAR(80) NOT NULL,
                    `description`    TEXT NULL,
                    `duration_min`   INT UNSIGNED NOT NULL DEFAULT 30,
                    `buffer_min`     INT UNSIGNED NOT NULL DEFAULT 0,
                    `price_cents`    INT UNSIGNED NOT NULL DEFAULT 0,
                    `currency`       VARCHAR(8) NOT NULL DEFAULT 'USD',
                    `color`          VARCHAR(16) NOT NULL DEFAULT '#2563EB',
                    `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `tenant_slug` (`tenant_id`, `slug`),
                    KEY `tenant_active` (`tenant_id`, `is_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `booking_providers` (
                    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
                    `name`       VARCHAR(160) NOT NULL,
                    `email`      VARCHAR(200) NULL,
                    `timezone`   VARCHAR(60) NOT NULL DEFAULT 'UTC',
                    `bio`        TEXT NULL,
                    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `tenant_active` (`tenant_id`, `is_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `booking_provider_services` (
                    `provider_id` INT UNSIGNED NOT NULL,
                    `service_id`  INT UNSIGNED NOT NULL,
                    PRIMARY KEY (`provider_id`, `service_id`),
                    KEY `service_idx` (`service_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `booking_provider_hours` (
                    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`   INT UNSIGNED NOT NULL DEFAULT 1,
                    `provider_id` INT UNSIGNED NOT NULL,
                    `day_of_week` TINYINT NOT NULL,
                    `start_time`  TIME NOT NULL,
                    `end_time`    TIME NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `provider_day` (`provider_id`, `day_of_week`),
                    KEY `tenant_provider` (`tenant_id`, `provider_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `booking_appointments` (
                    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`      INT UNSIGNED NOT NULL DEFAULT 1,
                    `ref`            VARCHAR(32) NOT NULL,
                    `service_id`     INT UNSIGNED NOT NULL,
                    `provider_id`    INT UNSIGNED NOT NULL,
                    `customer_id`    INT UNSIGNED NULL,
                    `customer_name`  VARCHAR(160) NOT NULL,
                    `customer_email` VARCHAR(200) NOT NULL,
                    `customer_phone` VARCHAR(40) NULL,
                    `notes`          TEXT NULL,
                    `starts_at`      DATETIME NOT NULL,
                    `ends_at`        DATETIME NOT NULL,
                    `status`         ENUM('confirmed','cancelled','no_show','completed') NOT NULL DEFAULT 'confirmed',
                    `reminder_24h_sent` TINYINT(1) NOT NULL DEFAULT 0,
                    `reminder_1h_sent`  TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `tenant_ref` (`tenant_id`, `ref`),
                    KEY `tenant_status_starts` (`tenant_id`, `status`, `starts_at`),
                    KEY `provider_slot` (`provider_id`, `starts_at`, `ends_at`),
                    KEY `customer_starts` (`customer_id`, `starts_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            if (function_exists('slate_log')) {
                slate_log('BookingAPI::ensureSchema failed: ' . $e->getMessage(), 'error');
            }
        }
    }

    public static function getActiveServices(): array {
        self::ensureSchema();
        return Database::rows(
            "SELECT * FROM booking_services
              WHERE tenant_id = ? AND is_active = 1
           ORDER BY name",
            [current_tenant_id()]
        );
    }

    /** Locale-aware service name (FR when locale is fr and name_fr is set). */
    public static function serviceName(array $service): string {
        $locale = class_exists('I18n') ? I18n::currentLocale() : 'en';
        if ($locale === 'fr' && !empty($service['name_fr'])) return (string) $service['name_fr'];
        return (string) ($service['name'] ?? '');
    }

    /** Locale-aware service description (FR when locale is fr and description_fr is set). */
    public static function serviceDescription(array $service): string {
        $locale = class_exists('I18n') ? I18n::currentLocale() : 'en';
        if ($locale === 'fr' && !empty($service['description_fr'])) return (string) $service['description_fr'];
        return (string) ($service['description'] ?? '');
    }

    /**
     * Resolve an appointment from its self-service manage token (CORE-1).
     *
     * The manage token is the credential for every unauthenticated
     * self-service surface: the manage/cancel page, the payment return, the
     * printable invoice, the pay-intent endpoint and Booking+'s message page.
     * Before this method existed the same lookup was written five separate
     * times and one copy — booking-plus/public/message.php — omitted the
     * tenant filter, so a token issued by one tenant resolved against
     * another. That is the entire reason this is a method and not a query.
     *
     * Tenant scoping is not optional and not a parameter. $extraSelect and
     * $joinProvider only widen the projection.
     *
     * @param string $token       the raw token from the request
     * @param bool   $withProvider join the provider row (some callers need it)
     * @return array|null the appointment, or null for an unknown/foreign token
     */
    public static function findByManageToken(string $token, bool $withProvider = true): ?array {
        // Reject anything that is not the shape generateManageToken() issues,
        // before it reaches the database. One caller previously accepted
        // [a-zA-Z0-9]{16,64}, which is wider than anything ever minted.
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        // Two whole statements rather than one assembled from ternaries. The
        // duplication is deliberate: built by concatenation, the fragment
        // naming the tables and the fragment carrying the tenant clause are
        // separate string literals, so the anti-drift guard cannot see that
        // this query is scoped and reports it. Suppressing that with an
        // ignore would put a muted security check on the one method whose
        // entire reason for existing is that a copy of this query once
        // shipped unscoped. Writing the clause inline keeps the guard live
        // here, which is where it matters most.
        if ($withProvider) {
            $sql = "SELECT a.*, s.name AS service_name, s.name_fr AS service_name_fr, s.currency, p.name AS provider_name
                      FROM booking_appointments a
                      JOIN booking_services  s ON s.id = a.service_id
                      JOIN booking_providers p ON p.id = a.provider_id
                     WHERE a.manage_token = ? AND a.tenant_id = ?
                     LIMIT 1";
        } else {
            $sql = "SELECT a.*, s.name AS service_name, s.name_fr AS service_name_fr, s.currency
                      FROM booking_appointments a
                      JOIN booking_services s ON s.id = a.service_id
                     WHERE a.manage_token = ? AND a.tenant_id = ?
                     LIMIT 1";
        }

        return Database::row($sql, [$token, slate_tenant_id()]);
    }

    public static function getAppointment(int $id): ?array {
        $tid = current_tenant_id();
        return Database::row(
            "SELECT a.*, s.name AS service_name, s.name_fr AS service_name_fr, p.name AS provider_name
               FROM booking_appointments a
          LEFT JOIN booking_services  s ON s.id = a.service_id AND s.tenant_id = a.tenant_id
          LEFT JOIN booking_providers p ON p.id = a.provider_id AND p.tenant_id = a.tenant_id
              WHERE a.id = ? AND a.tenant_id = ?",
            [$id, $tid]
        );
    }

    public static function getAppointmentByRef(string $ref): ?array {
        $tid = current_tenant_id();
        return Database::row(
            "SELECT a.*, s.name AS service_name, s.name_fr AS service_name_fr, p.name AS provider_name
               FROM booking_appointments a
          LEFT JOIN booking_services  s ON s.id = a.service_id AND s.tenant_id = a.tenant_id
          LEFT JOIN booking_providers p ON p.id = a.provider_id AND p.tenant_id = a.tenant_id
              WHERE a.ref = ? AND a.tenant_id = ?",
            [$ref, $tid]
        );
    }

    public static function getService($idOrSlug): ?array {
        $tid = current_tenant_id();
        if (is_numeric($idOrSlug)) {
            return Database::row(
                "SELECT * FROM booking_services WHERE id = ? AND tenant_id = ?",
                [(int)$idOrSlug, $tid]
            );
        }
        return Database::row(
            "SELECT * FROM booking_services WHERE slug = ? AND tenant_id = ?",
            [(string)$idOrSlug, $tid]
        );
    }

    public static function getProvidersForService(int $serviceId): array {
        return Database::rows(
            "SELECT p.* FROM booking_providers p
               JOIN booking_provider_services ps ON ps.provider_id = p.id
              WHERE ps.service_id = ? AND p.tenant_id = ? AND p.is_active = 1
           ORDER BY p.name",
            [$serviceId, current_tenant_id()]
        );
    }

    public static function getAllProviders(): array {
        return Database::rows(
            "SELECT * FROM booking_providers
              WHERE tenant_id = ? AND is_active = 1
           ORDER BY name",
            [current_tenant_id()]
        );
    }

    public static function getProviderHours(int $providerId): array {
        return Database::rows(
            "SELECT * FROM booking_provider_hours
              WHERE provider_id = ? AND tenant_id = ?
           ORDER BY day_of_week, start_time",
            [$providerId, current_tenant_id()]
        );
    }

    /**
     * Resolve a provider's net bookable intervals for a date as a list of
     * [startTs, endTs] pairs. Applies, in order:
     *   1. Date overrides (a provider-specific row beats a global one): a
     *      closed day yields nothing; a special-hours row replaces that day's
     *      weekly hours.
     *   2. Otherwise the provider's recurring weekly hours for the weekday.
     *   3. Recurring break/lunch blocks for the weekday are subtracted.
     */
    public static function effectiveIntervals(int $providerId, string $date): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return [];
        $tid = current_tenant_id();
        $dow = (int) date('w', strtotime($date));

        // 1. Date override — provider-specific row wins over a global (NULL) one.
        $override = null;
        try {
            $override = Database::row(
                "SELECT * FROM booking_date_overrides
                  WHERE tenant_id = ? AND `date` = ? AND (provider_id = ? OR provider_id IS NULL)
               ORDER BY (provider_id IS NULL) ASC
                  LIMIT 1",
                [$tid, $date, $providerId]
            );
        } catch (\Throwable $e) { /* table may not exist on a fresh half-migrated install */ }

        $blocks = [];
        if ($override) {
            if ((int)$override['is_closed'] === 1) return [];
            if (!empty($override['start_time']) && !empty($override['end_time'])) {
                $blocks[] = ['start_time' => $override['start_time'], 'end_time' => $override['end_time']];
            }
        }
        if (!$blocks) {
            $blocks = Database::rows(
                "SELECT start_time, end_time FROM booking_provider_hours
                  WHERE provider_id = ? AND tenant_id = ? AND day_of_week = ?
               ORDER BY start_time",
                [$providerId, $tid, $dow]
            );
        }
        if (!$blocks) return [];

        // 2. Subtract recurring breaks for this weekday.
        $breaks = [];
        try {
            $breaks = Database::rows(
                "SELECT start_time, end_time FROM booking_provider_breaks
                  WHERE provider_id = ? AND tenant_id = ? AND day_of_week = ?
               ORDER BY start_time",
                [$providerId, $tid, $dow]
            );
        } catch (\Throwable $e) { /* table may not exist yet */ }

        $intervals = [];
        foreach ($blocks as $b) {
            $start = strtotime($date . ' ' . $b['start_time']);
            $end   = strtotime($date . ' ' . $b['end_time']);
            if ($start === false || $end === false || $end <= $start) continue;

            $segments = [[$start, $end]];
            foreach ($breaks as $br) {
                $bs = strtotime($date . ' ' . $br['start_time']);
                $be = strtotime($date . ' ' . $br['end_time']);
                if ($bs === false || $be === false || $be <= $bs) continue;
                $next = [];
                foreach ($segments as [$segS, $segE]) {
                    if ($be <= $segS || $bs >= $segE) { $next[] = [$segS, $segE]; continue; }
                    if ($bs > $segS) $next[] = [$segS, min($bs, $segE)];
                    if ($be < $segE) $next[] = [max($be, $segS), $segE];
                }
                $segments = $next;
            }
            foreach ($segments as [$segS, $segE]) {
                if ($segE > $segS) $intervals[] = [$segS, $segE];
            }
        }

        // 3. Subtract busy blocks pulled from the provider's connected Google
        // Calendar (meetings booked outside Slate). No-op when nothing is
        // connected — googleBusyIntervals() returns [] in that case.
        $busy = self::googleBusyIntervals($providerId, $date);
        if ($busy) {
            $final = [];
            foreach ($intervals as [$segS, $segE]) {
                $segments = [[$segS, $segE]];
                foreach ($busy as [$bs, $be]) {
                    if ($be <= $bs) continue;
                    $next = [];
                    foreach ($segments as [$ss, $se]) {
                        if ($be <= $ss || $bs >= $se) { $next[] = [$ss, $se]; continue; }
                        if ($bs > $ss) $next[] = [$ss, min($bs, $se)];
                        if ($be < $se) $next[] = [max($be, $ss), $se];
                    }
                    $segments = $next;
                }
                foreach ($segments as [$ss, $se]) {
                    if ($se > $ss) $final[] = [$ss, $se];
                }
            }
            $intervals = $final;
        }

        return $intervals;
    }

    /** Effective service duration in minutes, honoring a per-staff override. */
    public static function effectiveDuration(array $service, int $providerId): int {
        $base = (int)($service['duration_min'] ?? 0);
        try {
            $ov = Database::value(
                "SELECT duration_min FROM booking_provider_services
                  WHERE provider_id = ? AND service_id = ?",
                [$providerId, (int)($service['id'] ?? 0)]
            );
            if ($ov !== null && (int)$ov > 0) return (int)$ov;
        } catch (\Throwable $e) { /* override column may not exist pre-migration */ }
        return $base;
    }

    /**
     * Compute available start times for (service, provider, date) as
     * ['HH:MM', ...] in 24h server-local time. Honors working hours, date
     * overrides, breaks, buffers before/after, slot interval, the advance
     * window, and per-slot capacity (group bookings). $partySize = seats
     * requested.
     *
     * Thin wrapper over slotCapacityMap() — kept so every existing caller's
     * signature/return shape is untouched. Callers that also want the
     * remaining-capacity figure per slot (e.g. the "N spots left" badge in
     * the public widget) should call computeSlotCapacityMap() instead.
     */
    public static function computeAvailableSlots(int $serviceId, int $providerId, string $date, int $partySize = 1): array {
        return array_keys(self::slotCapacityMap($serviceId, $providerId, $date, $partySize));
    }

    /**
     * Same computation as computeAvailableSlots(), but returns
     * ['HH:MM' => ['capacity' => int, 'remaining' => int], ...] — the
     * service's total per-slot capacity and how many seats are still free
     * at the moment of the call. $partySize still gates which slots appear
     * at all (a slot with fewer free seats than $partySize is omitted, same
     * as computeAvailableSlots()); "remaining" reflects the slot's true
     * headroom, not just whether this specific party fits.
     *
     * Like the rest of this availability check, "remaining" is a snapshot —
     * another customer can book between this call and a later submission,
     * which createAppointment()'s own race-check still guards against.
     */
    public static function computeSlotCapacityMap(int $serviceId, int $providerId, string $date, int $partySize = 1): array {
        return self::slotCapacityMap($serviceId, $providerId, $date, $partySize);
    }

    private static function slotCapacityMap(int $serviceId, int $providerId, string $date, int $partySize = 1): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return [];
        $partySize = max(1, $partySize);

        $tid     = current_tenant_id();
        $service = self::getService($serviceId);
        if (!$service || empty($service['is_active'])) return [];

        $duration = self::effectiveDuration($service, $providerId);
        if ($duration <= 0) return [];
        $bufferBefore = (int)($service['buffer_before_min'] ?? 0);
        $bufferAfter  = (int)($service['buffer_min'] ?? 0);
        $interval     = (int)($service['slot_interval_min'] ?? 0);
        if ($interval <= 0) $interval = $duration;
        $interval     = max(5, $interval);
        $capacity     = max(1, (int)($service['capacity'] ?? 1));

        $now      = time();
        $minStart = $now + ((int)($service['min_advance_min'] ?? 0)) * 60;
        $maxAdv   = (int)($service['max_advance_days'] ?? 365);
        $maxStart = $maxAdv > 0 ? $now + $maxAdv * 86400 : PHP_INT_MAX;

        $intervals = self::effectiveIntervals($providerId, $date);
        if (!$intervals) return [];

        // Existing bookings (pending or confirmed) that hold capacity today.
        $busy = Database::rows(
            "SELECT starts_at, ends_at, party_size FROM booking_appointments
              WHERE provider_id = ? AND tenant_id = ?
                AND status IN ('pending','awaiting_approval','confirmed')
                AND DATE(starts_at) = ?",
            [$providerId, $tid, $date]
        );

        $slots = [];
        foreach ($intervals as [$winStart, $winEnd]) {
            for ($cs = $winStart; $cs + $duration * 60 <= $winEnd; $cs += $interval * 60) {
                $ce = $cs + $duration * 60;
                if ($cs <= $now || $cs < $minStart || $cs > $maxStart) continue;

                // Padded window enforces the buffer gaps around the candidate.
                $padS = $cs - $bufferBefore * 60;
                $padE = $ce + $bufferAfter * 60;
                $taken = 0;
                foreach ($busy as $bz) {
                    $bs = strtotime($bz['starts_at']);
                    $be = strtotime($bz['ends_at']);
                    if ($bs < $padE && $be > $padS) {
                        $taken += max(1, (int)$bz['party_size']);
                    }
                }
                if ($taken + $partySize > $capacity) continue;

                if (!empty($service['requires_resource'])
                    && self::freeResourceId($serviceId, $cs, $ce, $partySize) === null) {
                    continue;
                }
                // Extension point: companion plugins (e.g. Booking+) may hide
                // this slot based on per-service reserved-window rules. No-op
                // when nothing listens.
                if (!Hook::applyFilters('booking_slot_allowed', true, $serviceId, $providerId, $date, $cs, $ce)) {
                    continue;
                }
                $slots[date('H:i', $cs)] = [
                    'capacity'  => $capacity,
                    'remaining' => max(0, $capacity - $taken),
                ];
            }
        }
        return $slots;
    }

    /**
     * Pick a resource the service needs that is free for [startTs, endTs].
     * Returns a resource id, or null if the service requires one but none is
     * free. Returns 0 when the service has no resource requirement at all.
     * Resources carry their own capacity (concurrent bookings allowed).
     */
    public static function freeResourceId(int $serviceId, int $startTs, int $endTs, int $partySize = 1): ?int {
        $tid = current_tenant_id();
        try {
            $resources = Database::rows(
                "SELECT r.* FROM booking_resources r
                   JOIN booking_service_resources sr ON sr.resource_id = r.id
                  WHERE sr.service_id = ? AND r.tenant_id = ? AND r.is_active = 1
               ORDER BY r.id",
                [$serviceId, $tid]
            );
        } catch (\Throwable $e) {
            return 0; // resources not set up — treat as no requirement
        }
        if (!$resources) return 0;

        $startSql = date('Y-m-d H:i:s', $startTs);
        $endSql   = date('Y-m-d H:i:s', $endTs);
        foreach ($resources as $r) {
            $used = (int) Database::value(
                "SELECT COALESCE(SUM(party_size), 0) FROM booking_appointments
                  WHERE resource_id = ? AND tenant_id = ? AND status IN ('pending','awaiting_approval','confirmed')
                    AND starts_at < ? AND ends_at > ?",
                [(int)$r['id'], $tid, $endSql, $startSql]
            );
            if ($used + $partySize <= max(1, (int)$r['capacity'])) return (int)$r['id'];
        }
        return null;
    }

    /**
     * Create an appointment with race-safe capacity reservation.
     *
     * Required keys: service_id, provider_id, starts_at, customer_name,
     *   customer_email.
     * Optional: customer_phone, customer_id, notes, party_size,
     *   addons (int[]), custom (assoc), location_id, source
     *   ('online'|'walkin'|'admin'), recurrence_group, ignore_hours (bool,
     *   admin only).
     *
     * Returns ['ok'=>true, 'id'=>int, 'ref'=>string, 'status'=>..., ...]
     *      or ['ok'=>false, 'error'=>string].
     */
    public static function createAppointment(array $args): array {
        $serviceId  = (int)($args['service_id']  ?? 0);
        $providerId = (int)($args['provider_id'] ?? 0);
        $startsAt   = trim((string)($args['starts_at'] ?? ''));
        $name       = trim((string)($args['customer_name']  ?? ''));
        $email      = trim((string)($args['customer_email'] ?? ''));
        $phone      = trim((string)($args['customer_phone'] ?? ''));
        $notes      = trim((string)($args['notes'] ?? ''));
        $customerId = isset($args['customer_id']) ? (int)$args['customer_id'] : null;
        $partySize  = max(1, (int)($args['party_size'] ?? 1));
        $source     = in_array(($args['source'] ?? 'online'), ['online','walkin','admin'], true)
                        ? (string)$args['source'] : 'online';
        $addonIds   = array_map('intval', (array)($args['addons'] ?? []));
        $custom     = is_array($args['custom'] ?? null) ? $args['custom'] : [];
        $recurGroup = isset($args['recurrence_group']) ? substr((string)$args['recurrence_group'], 0, 32) : null;

        if ($serviceId <= 0 || $providerId <= 0) return ['ok' => false, 'error' => 'Service and provider are required.'];
        if ($name === '')                         return ['ok' => false, 'error' => 'Your name is required.'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'A valid email is required.'];

        // Normalise starts_at — accept 'YYYY-MM-DDTHH:MM' from datetime-local.
        $startsAt = str_replace('T', ' ', $startsAt);
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $startsAt)) {
            return ['ok' => false, 'error' => 'Invalid time.'];
        }
        $startTs = strtotime($startsAt);
        if (!$startTs) return ['ok' => false, 'error' => 'Invalid time.'];
        // Walk-ins can start "now"; online/admin future bookings can't be past.
        if ($source !== 'walkin' && $startTs <= time()) {
            return ['ok' => false, 'error' => 'Pick a future time slot.'];
        }

        $service = self::getService($serviceId);
        if (!$service || empty($service['is_active'])) {
            return ['ok' => false, 'error' => 'That service isn\'t available.'];
        }

        // Extension point: other plugins may gate self-service (online)
        // bookings — e.g. the Membership plugin enforces an active membership,
        // a completed profile, or an insurance requirement. The filter is a
        // no-op (returns the default ['ok'=>true]) when nothing listens, so
        // booking behaves exactly as before on installs without a gate.
        // Admin/walk-in bookings bypass the gate by design.
        if ($source === 'online') {
            $gate = Hook::applyFilters('booking_can_book', ['ok' => true], [
                'customer_id' => $customerId,
                'service'     => $service,
                'starts_at'   => $startsAt,
                'party_size'  => $partySize,
                'source'      => $source,
            ]);
            if (is_array($gate) && empty($gate['ok'])) {
                return ['ok' => false, 'error' => (string)($gate['error'] ?? 'Booking is not allowed for this account.')];
            }
        }

        // Confirm the provider performs the service.
        $assoc = Database::row(
            "SELECT 1 FROM booking_provider_services WHERE provider_id = ? AND service_id = ?",
            [$providerId, $serviceId]
        );
        if (!$assoc) return ['ok' => false, 'error' => 'That provider doesn\'t offer this service.'];

        // Duration = staff/service duration + add-on extra minutes.
        $duration = self::effectiveDuration($service, $providerId);
        $addons   = self::resolveAddons($serviceId, $addonIds);
        foreach ($addons as $a) $duration += (int)($a['duration_min'] ?? 0);
        if ($duration <= 0) return ['ok' => false, 'error' => 'That service has no duration set.'];

        $capacity     = max(1, (int)($service['capacity'] ?? 1));
        $bufferBefore = (int)($service['buffer_before_min'] ?? 0);
        $bufferAfter  = (int)($service['buffer_min'] ?? 0);
        if ($partySize > $capacity) return ['ok' => false, 'error' => 'That exceeds the capacity for this slot.'];

        // Advance window — enforced for online self-service only.
        if ($source === 'online') {
            $minStart = time() + ((int)($service['min_advance_min'] ?? 0)) * 60;
            $maxAdv   = (int)($service['max_advance_days'] ?? 365);
            if ($startTs < $minStart) return ['ok' => false, 'error' => 'That time is too soon to book online.'];
            if ($maxAdv > 0 && $startTs > time() + $maxAdv * 86400) {
                return ['ok' => false, 'error' => 'That date is too far in advance.'];
            }
        }

        $endTs       = $startTs + $duration * 60;
        /*
         * DEFERRED — KNOWN BUG. These two columns are written on the PHP clock
         * and compared against the MySQL clock, which on this host is a four-
         * hour gap (includes/helpers.php:193-213, and the two production
         * incidents recorded there). Comparison sites: Booking.php:326-327
         * (reminder leads), :349-350 (follow-ups), :151 and :155 (dashboard),
         * admin/index.php:24 and :35-36 (today / next 7 days). A reminder can
         * therefore fire hours after the session it is reminding about.
         *
         * NOT fixed with the rest of CORE-1, on purpose. Every other clock
         * site was a stamp — "record that this happened now" — where old and
         * new rows mean the same thing after conversion. This is a
         * user-chosen wall-clock time: strtotime() then date() both run in
         * PHP's zone and cancel, so the stored string is the literal time the
         * customer picked. Changing how it is written retroactively changes
         * what every existing row MEANS, by the offset. A search-and-replace
         * here is worse than the bug: the bug is consistently four hours
         * wrong, a half-migration is inconsistently and unknowably wrong.
         *
         * A fix needs: a decision on which clock owns appointment times
         * (booking_providers.timezone exists and is currently decorative), a
         * migration that rewrites existing rows — created_at is MySQL-written,
         * so the per-row skew can be recovered rather than assumed — and all
         * six comparison sites changed in the same commit.
         *
         * The anti-drift CLOCK rule does NOT catch this: it matches
         * date('Y-m-d H:i:s') with no second argument, a clock READ. These
         * pass a timestamp, so they are a conversion, and flagging every
         * conversion would bury the rule in noise. That gap is why this note
         * is here rather than in the baseline.
         *
         * Tracked: Claude/slate-issue-queue.md, CORE-1 follow-ups. Audit: H-4.
         */
        $endsAt      = date('Y-m-d H:i:s', $endTs);
        $startsAtSql = date('Y-m-d H:i:s', $startTs);
        $tid         = current_tenant_id();

        // Validate against working hours / overrides / breaks (admin may skip).
        if (empty($args['ignore_hours'])) {
            $fits = false;
            foreach (self::effectiveIntervals($providerId, date('Y-m-d', $startTs)) as [$ws, $we]) {
                if ($startTs >= $ws && $endTs <= $we) { $fits = true; break; }
            }
            if (!$fits) return ['ok' => false, 'error' => 'That time is outside the provider\'s working hours.'];
        }

        // Required custom fields.
        $missing = self::validateCustomFields($serviceId, $custom);
        if ($missing !== null) return ['ok' => false, 'error' => $missing];

        // Pricing snapshot + discounts (coupon, then gift card).
        $couponCode = strtoupper(trim((string)($args['coupon_code'] ?? '')));
        $giftCode   = strtoupper(trim((string)($args['gift_card_code'] ?? '')));
        $mode       = (string)($service['payment_mode'] ?? 'free');

        $pre      = self::computeTotals($service, $providerId, $addons, $partySize, 0);
        $discount = 0;
        if ($couponCode !== '') {
            $cv = self::validateCoupon($couponCode, (int)$pre['subtotal_cents']);
            if (!empty($cv['ok'])) $discount = (int)$cv['discount_cents'];
            else $couponCode = ''; // silently drop an invalid code
        }
        $totals = self::computeTotals($service, $providerId, $addons, $partySize, $discount);

        // Amount collected online for this mode (gift card reduces it).
        $payTarget = $mode === 'full'    ? (int)$totals['total_cents']
                   : ($mode === 'deposit' ? (int)$totals['deposit_cents'] : 0);
        $giftApplied = 0;
        if ($giftCode !== '' && $payTarget > 0) {
            $gc = self::getGiftCard($giftCode);
            if ($gc) $giftApplied = max(0, min((int)$gc['balance_cents'], $payTarget));
            else $giftCode = '';
        }
        $dueNow = max(0, $payTarget - $giftApplied);

        $needsPayment  = ($mode === 'full' || $mode === 'deposit') && $dueNow > 0;
        // Manual-confirmation mode (booking.confirmation_mode setting) only
        // applies to bookings that would otherwise auto-confirm instantly —
        // one still needing online payment already has its own hold-and-wait
        // step ('pending') and confirms on payment success, same as today.
        $needsApproval = !$needsPayment && self::confirmationMode() === 'manual';
        $status        = $needsPayment ? 'pending' : ($needsApproval ? 'awaiting_approval' : 'confirmed');
        $paymentStatus = $needsPayment ? 'pending' : ($giftApplied > 0 ? 'paid' : 'none');

        // Race-safe capacity reservation + insert.
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $padStart = date('Y-m-d H:i:s', $startTs - $bufferBefore * 60);
            $padEnd   = date('Y-m-d H:i:s', $endTs   + $bufferAfter  * 60);
            $taken = (int) Database::value(
                "SELECT COALESCE(SUM(party_size), 0) FROM booking_appointments
                  WHERE provider_id = ? AND tenant_id = ? AND status IN ('pending','awaiting_approval','confirmed')
                    AND starts_at < ? AND ends_at > ?
                  FOR UPDATE",
                [$providerId, $tid, $padEnd, $padStart]
            );
            if ($taken + $partySize > $capacity) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Sorry, that slot was just taken. Pick another time.'];
            }

            // Assign a resource if the service requires one.
            $resourceId = null;
            if (!empty($service['requires_resource'])) {
                $picked = self::freeResourceId($serviceId, $startTs, $endTs, $partySize);
                if ($picked === null) {
                    $pdo->rollBack();
                    return ['ok' => false, 'error' => 'No room/resource is free for that time.'];
                }
                $resourceId = $picked > 0 ? $picked : null;
            }

            $ref         = self::generateRef();
            $manageToken = bin2hex(random_bytes(16));
            $id  = Database::insert('booking_appointments', [
                'tenant_id'        => $tid,
                'ref'              => $ref,
                'manage_token'     => $manageToken,
                'service_id'       => $serviceId,
                'provider_id'      => $providerId,
                'location_id'      => isset($args['location_id']) ? (int)$args['location_id'] : ($service['location_id'] ?? null),
                'resource_id'      => $resourceId,
                'customer_id'      => $customerId,
                'customer_name'    => mb_substr($name,  0, 160),
                'customer_email'   => mb_substr($email, 0, 200),
                'customer_phone'   => $phone !== '' ? mb_substr($phone, 0, 40) : null,
                'party_size'       => $partySize,
                'notes'            => $notes !== '' ? $notes : null,
                'addons_json'      => $addons ? json_encode($addons, JSON_UNESCAPED_UNICODE) : null,
                'custom_json'      => $custom ? json_encode($custom, JSON_UNESCAPED_UNICODE) : null,
                'starts_at'        => $startsAtSql,
                'ends_at'          => $endsAt,
                'status'           => $status,
                'source'           => $source,
                'recurrence_group' => $recurGroup,
                'price_cents'      => $totals['price_cents'],
                'tax_cents'        => $totals['tax_cents'],
                'deposit_cents'    => $totals['deposit_cents'],
                'discount_cents'   => $discount,
                'coupon_code'      => $couponCode !== '' ? $couponCode : null,
                'gift_card_code'   => $giftCode   !== '' ? $giftCode   : null,
                'gift_applied_cents' => $giftApplied,
                'paid_cents'       => $needsPayment ? 0 : $giftApplied,
                'payment_status'   => $paymentStatus,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'error' => 'Booking failed: ' . $e->getMessage()];
        }

        AuditLog::record('booking.created', (string)$id, [
            'ref' => $ref, 'service_id' => $serviceId, 'provider_id' => $providerId, 'party_size' => $partySize,
        ]);
        Hook::doAction('booking_created', $id, $serviceId, $providerId);

        // Surface the new booking in the admin notification bell. Flag it
        // distinctly when it's sitting in manual review — that's the one
        // case where staff need to act, not just glance.
        if (class_exists('Notifications')) {
            $whenTs = strtotime((string)$startsAtSql);
            $awaits = $status === 'awaiting_approval';
            Notifications::add(($awaits ? __('booking_notif_awaiting_approval', 'Booking awaiting approval') : __('booking_notif_new_booking', 'New booking')) . ' · ' . $ref, [
                'body' => ($name !== '' ? $name : __('booking_notif_a_customer', 'A customer'))
                        . ' ' . ($awaits ? __('booking_notif_requested', 'requested') : __('booking_notif_booked', 'booked')) . ' '
                        . (self::serviceName($service) ?: __('booking_notif_a_service', 'a service'))
                        . ($whenTs ? ' · ' . I18n::localDate('D j M, g:ia', $whenTs) : '')
                        . ($awaits ? ' — ' . __('booking_notif_needs_approval', 'needs your approval.') : ''),
                'url'  => function_exists('plugin_url')
                          ? plugin_url('booking', 'admin/appointment.php') . '?id=' . $id
                          : (defined('SLATE_URL') ? SLATE_URL . '/plugins/booking/admin/appointment.php?id=' . $id : ''),
                'icon' => $awaits ? 'alert-triangle' : 'calendar',
            ]);
        }

        // Upsert the booking-customer profile (guests included).
        self::upsertCustomer($email, $name, $phone, $customerId);

        // Redeem coupon / gift card now for bookings that are confirmed without
        // an online payment step (free, on-site, or fully gift-covered). Paid
        // bookings redeem on payment success in markPaid().
        if ($status === 'confirmed') {
            if ($couponCode !== '') self::redeemCoupon($couponCode);
            if ($giftApplied > 0) {
                $debited = self::redeemGiftCard($giftCode, $giftApplied);
                if ($debited < $giftApplied) {
                    // The card was drained between validation and debit — keep
                    // the appointment's recorded gift/paid amounts honest.
                    Database::update('booking_appointments', [
                        'gift_applied_cents' => $debited,
                        'paid_cents'         => $needsPayment ? 0 : $debited,
                        'payment_status'     => $debited > 0 ? 'paid' : 'none',
                    ], 'id = ? AND tenant_id = ?', [$id, $tid]);
                    slate_log("Booking $id: gift card $giftCode debited $debited of "
                        . "requested $giftApplied (concurrent drain)", 'warning');
                }
            }
        }

        $providerRow = Database::row("SELECT name, email FROM booking_providers WHERE id = ?", [$providerId]) ?: [];

        // Notify only once the slot is actually held. Pending-payment bookings
        // are confirmed (and emailed) after payment in the Payments phase.
        // A booking awaiting manual approval is held too (see the capacity
        // check above), so it gets a notice of its own — just not the
        // "confirmed" one, since it isn't yet.
        if ($status === 'confirmed' || $status === 'awaiting_approval') {
            $awaits = $status === 'awaiting_approval';
            try {
                if ($awaits) {
                    self::sendAwaitingApprovalNotice([
                        'ref' => $ref, 'customer_name' => $name, 'customer_email' => $email,
                        'service_name' => self::serviceName($service), 'provider_name' => (string)($providerRow['name'] ?? ''),
                        'starts_at' => $startsAtSql, 'manage_token' => $manageToken,
                    ]);
                } else {
                    self::sendConfirmation([
                        'ref'             => $ref,
                        'customer_name'   => $name,
                        'customer_email'  => $email,
                        'customer_phone'  => $phone,
                        'service_name'    => self::serviceName($service),
                        'provider_name'   => (string)($providerRow['name'] ?? ''),
                        'starts_at'       => $startsAtSql,
                        'manage_token'    => $manageToken,
                        'confirm_subject' => $service['confirm_subject'] ?? null,
                        'confirm_body'    => $service['confirm_body'] ?? null,
                    ]);
                }
            } catch (\Throwable $e) {
                slate_log('Booking: confirmation email failed: ' . $e->getMessage(), 'warning');
            }
            if (!empty($providerRow['email'])) {
                try {
                    self::sendStaffNotification($providerRow, [
                        'ref'           => $ref,
                        'service_name'  => self::serviceName($service),
                        'customer_name' => $name,
                        'customer_email'=> $email,
                        'customer_phone'=> $phone,
                        'party_size'    => $partySize,
                        'starts_at'     => $startsAtSql,
                    ], $awaits);
                } catch (\Throwable $e) {
                    slate_log('Booking: staff email failed: ' . $e->getMessage(), 'warning');
                }
            }
        }

        return [
            'ok' => true, 'id' => $id, 'ref' => $ref, 'status' => $status,
            'manage_token'   => $manageToken,
            'needs_payment'  => $needsPayment,
            'needs_approval' => $needsApproval,
            'total_cents'    => $totals['price_cents'] + $totals['tax_cents'],
            'deposit_cents'  => $totals['deposit_cents'],
        ];
    }

    /**
     * 'auto' (default — a booking that doesn't need online payment confirms
     * instantly, same as always) or 'manual' (it lands on 'awaiting_approval'
     * until an admin approves or declines it via approveAppointment() /
     * declineAppointment() — see the "Booking confirmations" settings card).
     */
    public static function confirmationMode(): string {
        return Database::setting('booking.confirmation_mode') === 'manual' ? 'manual' : 'auto';
    }

    /**
     * Approve a booking that's sitting in manual review. Runs the same
     * "just got confirmed" side effects an auto-confirmed booking gets at
     * creation time: redeem any coupon/gift card that was validated but
     * held back pending approval, send the real confirmation email, and
     * notify staff. Returns ['ok'=>bool, 'error'=>?string].
     */
    public static function approveAppointment(int $id): array {
        $tid = current_tenant_id();
        $row = Database::row(
            "SELECT a.*, s.name AS service_name, s.name_fr AS service_name_fr, s.confirm_subject, s.confirm_body,
                    p.name AS provider_name, p.email AS provider_email
               FROM booking_appointments a
               JOIN booking_services  s ON s.id = a.service_id
               JOIN booking_providers p ON p.id = a.provider_id
              WHERE a.id = ? AND a.tenant_id = ?",
            [$id, $tid]
        );
        if (!$row) return ['ok' => false, 'error' => 'Appointment not found.'];
        if ($row['status'] === 'confirmed') return ['ok' => true]; // already approved — idempotent
        if ($row['status'] !== 'awaiting_approval') {
            return ['ok' => false, 'error' => 'Only a booking awaiting approval can be approved.'];
        }

        Database::update('booking_appointments', ['status' => 'confirmed'], 'id = ? AND tenant_id = ?', [$id, $tid]);
        AuditLog::record('booking.approved', (string)$id, []);
        Hook::doAction('booking_approved', $id);

        if (!empty($row['coupon_code'])) self::redeemCoupon((string)$row['coupon_code']);
        if ((int)($row['gift_applied_cents'] ?? 0) > 0 && !empty($row['gift_card_code'])) {
            $debited = self::redeemGiftCard((string)$row['gift_card_code'], (int)$row['gift_applied_cents']);
            if ($debited < (int)$row['gift_applied_cents']) {
                Database::update('booking_appointments', [
                    'gift_applied_cents' => $debited,
                    'paid_cents'         => $debited,
                    'payment_status'     => $debited > 0 ? 'paid' : 'none',
                ], 'id = ? AND tenant_id = ?', [$id, $tid]);
                slate_log("Booking $id: gift card {$row['gift_card_code']} debited $debited of "
                    . "requested {$row['gift_applied_cents']} (concurrent drain)", 'warning');
            }
        }

        try {
            self::sendConfirmation([
                'ref'             => $row['ref'],
                'customer_name'   => $row['customer_name'],
                'customer_email'  => $row['customer_email'],
                'customer_phone'  => $row['customer_phone'],
                'service_name'    => $row['service_name'],
                'provider_name'   => $row['provider_name'],
                'starts_at'       => $row['starts_at'],
                'manage_token'    => $row['manage_token'],
                'confirm_subject' => $row['confirm_subject'] ?? null,
                'confirm_body'    => $row['confirm_body'] ?? null,
            ]);
        } catch (\Throwable $e) {
            slate_log('Booking: approval confirmation email failed: ' . $e->getMessage(), 'warning');
        }
        if (!empty($row['provider_email'])) {
            try {
                self::sendStaffNotification(
                    ['name' => $row['provider_name'], 'email' => $row['provider_email']],
                    ['ref' => $row['ref'], 'service_name' => $row['service_name'], 'customer_name' => $row['customer_name'],
                     'customer_email' => $row['customer_email'], 'customer_phone' => $row['customer_phone'],
                     'party_size' => $row['party_size'], 'starts_at' => $row['starts_at']]
                );
            } catch (\Throwable $e) {
                slate_log('Booking: approval staff email failed: ' . $e->getMessage(), 'warning');
            }
        }
        return ['ok' => true];
    }

    /**
     * Decline a booking that's sitting in manual review. Frees the slot
     * (status → cancelled, same mechanics as any other cancellation, so it
     * drops out of every capacity/overlap check immediately) and notifies
     * the customer with the given reason.
     */
    public static function declineAppointment(int $id, string $reason = ''): array {
        $tid = current_tenant_id();
        $row = Database::row(
            "SELECT a.*, s.name AS service_name, s.name_fr AS service_name_fr, p.name AS provider_name
               FROM booking_appointments a
               JOIN booking_services  s ON s.id = a.service_id
               JOIN booking_providers p ON p.id = a.provider_id
              WHERE a.id = ? AND a.tenant_id = ?",
            [$id, $tid]
        );
        if (!$row) return ['ok' => false, 'error' => 'Appointment not found.'];
        if ($row['status'] !== 'awaiting_approval') {
            return ['ok' => false, 'error' => 'Only a booking awaiting approval can be declined.'];
        }

        $note = $reason !== '' ? $reason : 'Your booking request was not approved.';
        Database::update('booking_appointments', [
            'status'        => 'cancelled',
            'cancel_reason' => mb_substr($note, 0, 255),
            'cancelled_at'  => slate_db_now(),
        ], 'id = ? AND tenant_id = ?', [$id, $tid]);
        AuditLog::record('booking.declined', (string)$id, ['reason' => $reason]);
        Hook::doAction('booking_declined', $id, $reason);

        try { self::sendCancellation($row, $note); }
        catch (\Throwable $e) { slate_log('Booking: decline email failed: ' . $e->getMessage(), 'warning'); }

        return ['ok' => true];
    }

    public static function cancelAppointment(int $id, string $reason = '', bool $notify = true): bool {
        $tid = current_tenant_id();
        $row = Database::row(
            "SELECT a.*, s.name AS service_name, s.name_fr AS service_name_fr, p.name AS provider_name
               FROM booking_appointments a
               JOIN booking_services  s ON s.id = a.service_id
               JOIN booking_providers p ON p.id = a.provider_id
              WHERE a.id = ? AND a.tenant_id = ?",
            [$id, $tid]
        );
        if (!$row) return false;
        if ($row['status'] === 'cancelled') return true;

        Database::update('booking_appointments', [
            'status'        => 'cancelled',
            'cancel_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
            'cancelled_at'  => slate_db_now(),   // CORE-1: DB clock; compared against NOW()
        ], 'id = ?', [$id]);
        AuditLog::record('booking.cancelled', (string)$id, ['reason' => $reason]);
        Hook::doAction('booking_cancelled', $id);

        if ($notify) {
            try { self::sendCancellation($row, $reason); }
            catch (\Throwable $e) { slate_log('Booking: cancel email failed: ' . $e->getMessage(), 'warning'); }
        }
        return true;
    }

    /**
     * Move an appointment to a new start, re-validating hours + capacity.
     * Preserves the original duration. Returns ['ok'=>bool, 'error'=>?string].
     */
    public static function rescheduleAppointment(int $id, string $newStart, bool $notify = true): array {
        $tid = current_tenant_id();
        $row = Database::row(
            "SELECT a.*, s.name AS service_name, s.name_fr AS service_name_fr, p.name AS provider_name
               FROM booking_appointments a
               JOIN booking_services  s ON s.id = a.service_id
               JOIN booking_providers p ON p.id = a.provider_id
              WHERE a.id = ? AND a.tenant_id = ?",
            [$id, $tid]
        );
        if (!$row) return ['ok' => false, 'error' => 'Appointment not found.'];

        $newStart = str_replace('T', ' ', trim($newStart));
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $newStart)) {
            return ['ok' => false, 'error' => 'Invalid time.'];
        }
        $startTs = strtotime($newStart);
        if (!$startTs) return ['ok' => false, 'error' => 'Invalid time.'];

        $service    = self::getService((int)$row['service_id']);
        if (!$service) return ['ok' => false, 'error' => 'That service no longer exists.'];

        // Can't reschedule into the past, and honour the service's
        // minimum-advance window (createAppointment enforces these for new
        // online bookings; reschedule must too, or the rules are bypassable).
        if ($startTs <= time()) {
            return ['ok' => false, 'error' => 'Please choose a time in the future.'];
        }
        $minAdvance = (int)($service['min_advance_min'] ?? 0);
        if ($minAdvance > 0 && $startTs < time() + $minAdvance * 60) {
            return ['ok' => false, 'error' => 'That time is too soon — please choose a later slot.'];
        }

        $providerId = (int)$row['provider_id'];
        $duration   = (int) round((strtotime($row['ends_at']) - strtotime($row['starts_at'])) / 60);
        if ($duration <= 0) $duration = self::effectiveDuration($service, $providerId);
        $endTs        = $startTs + $duration * 60;
        $partySize    = max(1, (int)$row['party_size']);
        $bufferBefore = (int)($service['buffer_before_min'] ?? 0);
        $bufferAfter  = (int)($service['buffer_min'] ?? 0);
        $capacity     = max(1, (int)($service['capacity'] ?? 1));

        $fits = false;
        foreach (self::effectiveIntervals($providerId, date('Y-m-d', $startTs)) as [$ws, $we]) {
            if ($startTs >= $ws && $endTs <= $we) { $fits = true; break; }
        }
        if (!$fits) return ['ok' => false, 'error' => 'That time is outside the provider\'s working hours.'];

        $startsAtSql = date('Y-m-d H:i:s', $startTs);
        $endsAtSql   = date('Y-m-d H:i:s', $endTs);

        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $padStart = date('Y-m-d H:i:s', $startTs - $bufferBefore * 60);
            $padEnd   = date('Y-m-d H:i:s', $endTs   + $bufferAfter  * 60);
            $taken = (int) Database::value(
                "SELECT COALESCE(SUM(party_size), 0) FROM booking_appointments
                  WHERE provider_id = ? AND tenant_id = ? AND status IN ('pending','awaiting_approval','confirmed')
                    AND id <> ? AND starts_at < ? AND ends_at > ?
                  FOR UPDATE",
                [$providerId, $tid, $id, $padEnd, $padStart]
            );
            if ($taken + $partySize > $capacity) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'That slot is already full.'];
            }
            Database::update('booking_appointments', [
                'starts_at'         => $startsAtSql,
                'ends_at'           => $endsAtSql,
                'reschedule_count'  => (int)$row['reschedule_count'] + 1,
                'reminder_24h_sent' => 0,
                'reminder_1h_sent'  => 0,
            ], 'id = ?', [$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'error' => 'Reschedule failed: ' . $e->getMessage()];
        }

        AuditLog::record('booking.rescheduled', (string)$id, ['from' => $row['starts_at'], 'to' => $startsAtSql]);
        Hook::doAction('booking_rescheduled', $id);
        if ($notify) {
            try { self::sendReschedule($row, $startsAtSql); }
            catch (\Throwable $e) { slate_log('Booking: reschedule email failed: ' . $e->getMessage(), 'warning'); }
        }
        return ['ok' => true];
    }

    // ── Add-ons, pricing, custom fields ───────────────────────────

    /** Active add-on rows for the given ids, scoped to the service + tenant. */
    public static function resolveAddons(int $serviceId, array $addonIds): array {
        $addonIds = array_values(array_unique(array_filter(array_map('intval', $addonIds), fn($i) => $i > 0)));
        if (!$addonIds) return [];
        try {
            $place = implode(',', array_fill(0, count($addonIds), '?'));
            return Database::rows(
                "SELECT id, name, price_cents, duration_min FROM booking_service_addons
                  WHERE service_id = ? AND tenant_id = ? AND is_active = 1 AND id IN ($place)",
                array_merge([$serviceId, current_tenant_id()], $addonIds)
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Base price for a service honoring a per-staff override (in cents). */
    public static function serviceBasePriceCents(array $service, int $providerId): int {
        try {
            $ov = Database::value(
                "SELECT price_cents FROM booking_provider_services WHERE provider_id = ? AND service_id = ?",
                [$providerId, (int)($service['id'] ?? 0)]
            );
            if ($ov !== null && $ov !== '') return (int)$ov;
        } catch (\Throwable $e) { /* override column may not exist pre-migration */ }
        return (int)($service['price_cents'] ?? 0);
    }

    /**
     * Money snapshot in cents for a booking. Applies (in order): per-person
     * group-pricing tiers, add-ons, a flat discount (coupon/gift card), then
     * tax (per-service rate, falling back to the global booking.tax_rate),
     * then the deposit due per the service's payment mode.
     *
     * Returns subtotal_cents, discount_cents, price_cents (taxable = subtotal
     * − discount), tax_cents, total_cents, deposit_cents.
     */
    public static function computeTotals(array $service, int $providerId, array $addons, int $partySize = 1, int $discountCents = 0): array {
        $partySize = max(1, $partySize);
        $perPerson = self::serviceBasePriceCents($service, $providerId);

        // Group pricing tiers: per-person price for parties >= min (largest
        // matching min wins). Stored as [{"min":N,"price_cents":C}, …].
        $tiers   = json_decode((string)($service['price_tiers_json'] ?? '[]'), true) ?: [];
        $bestMin = 0;
        foreach ($tiers as $t) {
            $min = (int)($t['min'] ?? 0);
            $pc  = (int)($t['price_cents'] ?? -1);
            if ($min > 0 && $min <= $partySize && $pc >= 0 && $min >= $bestMin) {
                $perPerson = $pc;
                $bestMin   = $min;
            }
        }

        $addonTotal = 0;
        foreach ($addons as $a) $addonTotal += (int)($a['price_cents'] ?? 0);

        $subtotal = ($perPerson + $addonTotal) * $partySize;
        $discount = max(0, min($subtotal, $discountCents));
        $taxable  = $subtotal - $discount;

        $rate = (float)($service['tax_rate'] ?? 0);
        if ($rate <= 0) $rate = (float)(Database::setting('booking.tax_rate') ?: 0);
        $tax  = (int) round($taxable * $rate / 100);
        $grand = $taxable + $tax;

        $deposit = 0;
        $mode = (string)($service['payment_mode'] ?? 'free');
        if ($mode === 'full') {
            $deposit = $grand;
        } elseif ($mode === 'deposit') {
            $dval = (int)($service['deposit_value'] ?? 0);
            $deposit = (string)($service['deposit_type'] ?? 'percent') === 'fixed'
                ? min($grand, $dval)
                : (int) round($grand * $dval / 100);
        }
        return [
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'price_cents'    => $taxable,
            'tax_cents'      => $tax,
            'total_cents'    => $grand,
            'deposit_cents'  => $deposit,
        ];
    }

    // ── Coupons, gift cards, payments ─────────────────────────────

    /**
     * Validate a coupon code against an order subtotal (cents).
     * Returns ['ok'=>bool, 'discount_cents'=>int, 'row'=>array|null, 'error'=>?string].
     */
    public static function validateCoupon(string $code, int $subtotalCents): array {
        $code = strtoupper(trim($code));
        if ($code === '') return ['ok' => false, 'discount_cents' => 0, 'row' => null, 'error' => 'No code.'];
        try {
            $c = Database::row("SELECT * FROM booking_coupons WHERE tenant_id = ? AND code = ?", [current_tenant_id(), $code]);
        } catch (\Throwable $e) { $c = null; }
        if (!$c || (int)$c['is_active'] !== 1)             return ['ok' => false, 'discount_cents' => 0, 'row' => null, 'error' => 'Invalid coupon.'];
        if (!empty($c['expires_at']) && strtotime($c['expires_at']) < slate_db_time()) return ['ok' => false, 'discount_cents' => 0, 'row' => null, 'error' => 'Coupon expired.'];
        if ($c['max_uses'] !== null && (int)$c['used_count'] >= (int)$c['max_uses']) return ['ok' => false, 'discount_cents' => 0, 'row' => null, 'error' => 'Coupon fully redeemed.'];
        if ($subtotalCents < (int)$c['min_total_cents'])   return ['ok' => false, 'discount_cents' => 0, 'row' => null, 'error' => 'Order below the coupon minimum.'];

        $discount = $c['type'] === 'fixed'
            ? min($subtotalCents, (int)$c['value'])
            : (int) round($subtotalCents * (int)$c['value'] / 100);
        return ['ok' => true, 'discount_cents' => $discount, 'row' => $c, 'error' => null];
    }

    /** Redeem a coupon (increment usage). Best-effort. */
    public static function redeemCoupon(string $code): void {
        $code = strtoupper(trim($code));
        if ($code === '') return;
        try {
            // Atomic, cap-respecting increment so concurrent bookings can't
            // push used_count past max_uses.
            Database::query(
                "UPDATE booking_coupons
                    SET used_count = used_count + 1
                  WHERE tenant_id = ? AND code = ?
                    AND (max_uses IS NULL OR used_count < max_uses)",
                [current_tenant_id(), $code]);
        } catch (\Throwable $e) { /* non-fatal */ }
    }

    /** Look up an active gift card with a positive balance. */
    public static function getGiftCard(string $code): ?array {
        $code = strtoupper(trim($code));
        if ($code === '') return null;
        try {
            return Database::row("SELECT * FROM booking_gift_cards WHERE tenant_id = ? AND code = ? AND is_active = 1", [current_tenant_id(), $code]);
        } catch (\Throwable $e) { return null; }
    }

    /**
     * Atomically debit a gift card. Only succeeds when the balance still
     * covers the amount, so two concurrent bookings can't both spend the
     * same balance (prevents over-redemption). Returns the amount actually
     * debited (0 if the balance was insufficient / the card was drained).
     */
    public static function redeemGiftCard(string $code, int $amountCents): int {
        $code = strtoupper(trim($code));
        if ($code === '' || $amountCents <= 0) return 0;
        try {
            $stmt = Database::query(
                "UPDATE booking_gift_cards
                    SET balance_cents = balance_cents - ?
                  WHERE tenant_id = ? AND code = ? AND is_active = 1 AND balance_cents >= ?",
                [$amountCents, current_tenant_id(), $code, $amountCents]
            );
            return $stmt->rowCount() === 1 ? $amountCents : 0;
        } catch (\Throwable $e) { return 0; }
    }

    /**
     * Create a Stripe checkout session for a pending appointment's amount due
     * (deposit for deposit-mode, otherwise the full total). Returns the hosted
     * checkout URL, or ['ok'=>false,'error'=>…].
     */
    public static function startPayment(array $appt): array {
        if (!class_exists('StripePaymentAPI') || !StripePaymentAPI::isConfigured()) {
            return ['ok' => false, 'error' => 'Online payment is not available right now.'];
        }
        $due = (int)$appt['deposit_cents'] > 0 ? (int)$appt['deposit_cents'] : ((int)$appt['price_cents'] + (int)$appt['tax_cents']);
        $due -= (int)($appt['paid_cents'] ?? 0);
        if ($due <= 0) return ['ok' => false, 'error' => 'Nothing to pay.'];

        $service  = self::getService((int)$appt['service_id']);
        $currency = strtolower((string)($service['currency'] ?? 'usd'));
        $base = SLATE_URL . '/book/pay';
        try {
            $sess = StripePaymentAPI::createCheckout(
                [['name' => ($service['name'] ?? 'Appointment') . ' (' . $appt['ref'] . ')', 'amount_cents' => $due]],
                [
                    'currency'       => $currency,
                    'customer_email' => (string)$appt['customer_email'],
                    'success_url'    => $base . '/done?token=' . rawurlencode((string)$appt['manage_token']) . '&session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url'     => SLATE_URL . '/book/manage?token=' . rawurlencode((string)$appt['manage_token']),
                    'metadata'       => ['booking_appt_id' => (string)$appt['id'], 'booking_token' => (string)$appt['manage_token']],
                ]
            );
            return ['ok' => true, 'url' => $sess['url']];
        } catch (\Throwable $e) {
            slate_log('Booking: startPayment failed: ' . $e->getMessage(), 'error');
            return ['ok' => false, 'error' => 'Could not start payment.'];
        }
    }

    /**
     * Mark a pending appointment paid + confirmed. Idempotent. Records the
     * payment, redeems coupon/gift card, records a charge row, and emails the
     * customer + staff. $amountCents is what was actually collected.
     */
    public static function markPaid(int $apptId, int $amountCents, string $stripeRef = ''): bool {
        $tid = current_tenant_id();
        $a = Database::row(
            "SELECT a.*, s.name AS service_name, s.name_fr AS service_name_fr, s.confirm_subject, s.confirm_body,
                    p.name AS provider_name, p.email AS provider_email
               FROM booking_appointments a
               JOIN booking_services  s ON s.id = a.service_id
               JOIN booking_providers p ON p.id = a.provider_id
              WHERE a.id = ? AND a.tenant_id = ?",
            [$apptId, $tid]
        );
        if (!$a) return false;
        if (($a['payment_status'] ?? '') === 'paid') return true; // already done

        $deposit = (int)$a['deposit_cents'];
        $total   = (int)$a['price_cents'] + (int)$a['tax_cents'];
        $paid    = (int)$a['paid_cents'] + max(0, $amountCents);
        $status  = $paid >= $total ? 'paid' : ($paid > 0 ? 'deposit_paid' : 'pending');

        Database::update('booking_appointments', [
            'status'         => 'confirmed',
            'payment_status' => $status,
            'paid_cents'     => $paid,
            'payment_ref'    => $stripeRef !== '' ? mb_substr($stripeRef, 0, 120) : ($a['payment_ref'] ?? null),
        ], 'id = ? AND tenant_id = ?', [$apptId, $tid]);

        if (!empty($a['coupon_code']))    self::redeemCoupon((string)$a['coupon_code']);
        if (!empty($a['gift_card_code'])) self::redeemGiftCard((string)$a['gift_card_code'], (int)($a['gift_applied_cents'] ?? 0));

        AuditLog::record('booking.paid', (string)$apptId, ['amount' => $amountCents, 'ref' => $stripeRef]);
        Hook::doAction('booking_paid', $apptId, $amountCents);

        // Now that the slot is paid+held, send the confirmation + staff notice.
        try {
            self::sendConfirmation([
                'ref'             => $a['ref'],
                'customer_name'   => $a['customer_name'],
                'customer_email'  => $a['customer_email'],
                'customer_phone'  => $a['customer_phone'],
                'service_name'    => $a['service_name'],
                'provider_name'   => $a['provider_name'],
                'starts_at'       => $a['starts_at'],
                'manage_token'    => $a['manage_token'],
                'confirm_subject' => $a['confirm_subject'] ?? null,
                'confirm_body'    => $a['confirm_body'] ?? null,
            ]);
            if (!empty($a['provider_email'])) {
                self::sendStaffNotification(
                    ['name' => $a['provider_name'], 'email' => $a['provider_email']],
                    ['ref' => $a['ref'], 'service_name' => $a['service_name'], 'customer_name' => $a['customer_name'],
                     'customer_email' => $a['customer_email'], 'customer_phone' => $a['customer_phone'],
                     'party_size' => $a['party_size'], 'starts_at' => $a['starts_at']]
                );
            }
        } catch (\Throwable $e) {
            slate_log('Booking: post-payment email failed: ' . $e->getMessage(), 'warning');
        }
        return true;
    }

    /**
     * Webhook entry point (fired by the stripe-payment plugin). Confirms a
     * pending booking when its checkout session completes. Idempotent — the
     * stripe plugin de-dupes charges and markPaid() no-ops once paid.
     *
     * Also handles the failure/expiry side of the same flow. This matters
     * most for async payment methods like Bancontact: the customer is sent
     * to their bank and back, so a decline doesn't happen on our page at
     * all — it only ever shows up as a webhook. Before this, nothing
     * subscribed to those event types, so a declined Bancontact payment
     * left the appointment on 'pending' forever with no record of what
     * happened and nothing telling the customer or staff.
     */
    public static function handleStripeEvent(array $event): void {
        $type = (string)($event['type'] ?? '');
        $isSession = $type === 'checkout.session.completed' || $type === 'checkout.session.async_payment_succeeded';
        // payment_intent.succeeded covers the embedded Payment Element flow
        // used by the inline booking widget (no checkout session involved).
        $isIntent  = $type === 'payment_intent.succeeded';
        // Failure/expiry: payment_intent.payment_failed covers a declined
        // card or embedded-flow attempt; checkout.session.async_payment_failed
        // and checkout.session.expired cover a Bancontact (or other
        // redirect-based) session that came back declined or was never
        // completed before Stripe's checkout session timed out.
        $isFailure = $type === 'payment_intent.payment_failed'
                  || $type === 'checkout.session.async_payment_failed'
                  || $type === 'checkout.session.expired';
        if (!$isSession && !$isIntent && !$isFailure) {
            return;
        }
        $obj  = $event['data']['object'] ?? [];
        if (!is_array($obj)) return;
        $meta   = is_array($obj['metadata'] ?? null) ? $obj['metadata'] : [];
        $apptId = (int)($meta['booking_appt_id'] ?? 0);
        if ($apptId <= 0) return; // not a booking payment

        if ($isFailure) {
            self::handleFailedPayment($apptId, $obj, $type);
            return;
        }

        if ($isIntent) {
            // PaymentIntent object: amount_received + the PI id itself.
            $amount = (int)($obj['amount_received'] ?? $obj['amount'] ?? 0);
            $piId   = (string)($obj['id'] ?? '');
            $sessId = '';
            $email  = (string)($obj['receipt_email'] ?? '');
        } else {
            $amount = (int)($obj['amount_total'] ?? 0);
            $piId   = (string)($obj['payment_intent'] ?? '');
            $sessId = (string)($obj['id'] ?? '');
            $email  = (string)($obj['customer_details']['email'] ?? ($obj['customer_email'] ?? ''));
        }

        $chargeId = null;
        if (class_exists('StripePaymentAPI')) {
            $chargeId = StripePaymentAPI::recordCharge([
                'source_plugin'            => 'booking',
                'source_id'                => (string)$apptId,
                'stripe_session_id'        => $sessId,
                'stripe_payment_intent_id' => $piId,
                'customer_email'           => $email,
                'amount_cents'             => $amount,
                'currency'                 => strtoupper((string)($obj['currency'] ?? 'USD')),
                'status'                   => 'succeeded',
            ]);
        }
        if ($chargeId) {
            try {
                Database::update('booking_appointments',
                    ['charge_id' => $chargeId, 'stripe_session_id' => $sessId],
                    'id = ?', [$apptId]);
            } catch (\Throwable $e) { /* columns guaranteed by migration */ }
        }
        self::markPaid($apptId, $amount, $piId);
    }

    /**
     * Record a declined/expired payment attempt against its appointment and
     * make it visible instead of leaving 'pending' looking like an ordinary
     * unpaid booking. Does NOT cancel the appointment or release the slot —
     * the hold + retry-payment flow (startPayment() / /book/manage) already
     * exist and are exactly what the customer needs to come back to.
     *
     * Idempotent-ish: a stale/duplicate failure webhook is ignored once the
     * appointment has moved past 'awaiting payment' (paid, cancelled, etc.)
     * so it can never clobber a later, better outcome.
     */
    private static function handleFailedPayment(int $apptId, array $obj, string $eventType): void {
        $a = Database::row(
            "SELECT a.*, s.name AS service_name, s.name_fr AS service_name_fr, p.name AS provider_name, p.email AS provider_email
               FROM booking_appointments a
               JOIN booking_services  s ON s.id = a.service_id  AND s.tenant_id = a.tenant_id
               JOIN booking_providers p ON p.id = a.provider_id AND p.tenant_id = a.tenant_id
              WHERE a.id = ?",
            [$apptId]
        );
        if (!$a) return;
        if ($a['status'] !== 'pending') return; // already resolved one way or another
        if (!in_array(($a['payment_status'] ?? ''), ['pending', 'none', 'failed'], true)) return;

        $reason = self::stripeFailureReason($obj, $eventType);
        $isSessionObj = strpos($eventType, 'checkout.session') === 0;

        if (class_exists('StripePaymentAPI')) {
            StripePaymentAPI::recordCharge([
                'source_plugin'            => 'booking',
                'source_id'                => (string)$apptId,
                'stripe_session_id'        => $isSessionObj ? (string)($obj['id'] ?? '') : '',
                'stripe_payment_intent_id' => $isSessionObj ? (string)($obj['payment_intent'] ?? '') : (string)($obj['id'] ?? ''),
                'customer_email'           => (string)($obj['receipt_email'] ?? ($obj['customer_details']['email'] ?? ($obj['customer_email'] ?? ''))),
                'amount_cents'             => (int)($obj['amount'] ?? $obj['amount_total'] ?? 0),
                'currency'                 => strtoupper((string)($obj['currency'] ?? 'USD')),
                'status'                   => 'failed',
                'meta'                     => ['reason' => $reason, 'event_type' => $eventType],
            ]);
        }

        Database::update('booking_appointments', ['payment_status' => 'failed'], 'id = ?', [$apptId]);
        AuditLog::record('booking.payment_failed', (string)$apptId, ['reason' => $reason, 'event' => $eventType]);
        Hook::doAction('booking_payment_failed', $apptId, $reason);

        // Surface it in the admin bell the same way a new booking does — a
        // silently-declined payment is exactly the kind of thing that must
        // not depend on staff noticing on their own.
        if (class_exists('Notifications')) {
            Notifications::add(__('booking_notif_payment_failed', 'Payment failed') . ' · ' . $a['ref'], [
                'body' => sprintf(
                    __('booking_notif_payment_failed_body', '%s\'s payment for %s did not go through%s.'),
                    ($a['customer_name'] ?: __('booking_notif_a_customer', 'A customer')),
                    ($a['service_name'] ?? __('booking_notif_a_booking', 'a booking')),
                    ($reason !== '' ? ' (' . $reason . ')' : '')
                ),
                'url'  => function_exists('plugin_url')
                          ? plugin_url('booking', 'admin/appointment.php') . '?id=' . $apptId
                          : (defined('SLATE_URL') ? SLATE_URL . '/plugins/booking/admin/appointment.php?id=' . $apptId : ''),
                'icon' => 'alert-triangle',
            ]);
        }

        try {
            self::sendPaymentFailedNotice($a, $reason);
        } catch (\Throwable $e) {
            slate_log('Booking: payment-failed email failed: ' . $e->getMessage(), 'warning');
        }
    }

    /** Best-effort human-readable reason for a Stripe payment failure event. */
    private static function stripeFailureReason(array $obj, string $eventType): string {
        if ($eventType === 'checkout.session.expired') {
            return __('booking_email_payment_session_expired', 'The payment session expired before the payment was completed.');
        }
        $lastErr = $obj['last_payment_error']['message'] ?? null;
        if (is_string($lastErr) && $lastErr !== '') return $lastErr; // Stripe's own message, passed through as-is
        $declineCode = $obj['last_payment_error']['decline_code'] ?? null;
        if (is_string($declineCode) && $declineCode !== '') return sprintf(__('booking_email_payment_declined_code', 'Declined (%s).'), $declineCode);
        return __('booking_email_payment_declined', 'Payment was declined.');
    }

    /** Email the customer that their payment attempt didn't go through. */
    public static function sendPaymentFailedNotice(array $a, string $reason = ''): bool {
        $manageUrl = defined('SLATE_URL') && !empty($a['manage_token'])
                   ? SLATE_URL . '/book/manage?token=' . rawurlencode((string)$a['manage_token']) : '';
        $inner = '<h2 style="margin:0 0 18px;font-size:20px;font-weight:700;color:#2d2a26;">' . e(__('booking_email_payment_issue_heading', 'Payment issue')) . '</h2>'
              . '<p style="margin:0 0 14px;">' . sprintf(__('booking_email_greeting', 'Hello %s,'), e($a['customer_name'])) . '</p>'
              . '<p style="margin:0 0 14px;">' . sprintf(
                    __('booking_email_payment_failed_body_html', 'We couldn\'t process your payment for <strong>%s</strong>%s.'),
                    e($a['service_name'] ?? ''),
                    ($reason !== '' ? ' (' . e($reason) . ')' : '')
                ) . '</p>'
              . '<p style="margin:0 0 8px;">' . e(__('booking_email_payment_no_charge', 'No amount was charged. Your slot is still held, but a successful payment is required to confirm it.')) . '</p>'
              . self::emailInfoCard([[__('booking_email_label_reference', 'Reference'), '<code>' . e($a['ref']) . '</code>']])
              . ($manageUrl !== '' ? self::emailButton($manageUrl, __('booking_email_retry_payment_btn', 'Retry payment')) : '');
        $body = self::brandedEmailShell($inner, __('booking_email_payment_action_required_preheader', "Action required: your payment didn't go through"));
        return (bool) Mailer::send($a['customer_email'], sprintf(__('booking_email_payment_failed_subject', "We couldn't process your payment (%s)"), $a['ref']), $body, $a['customer_name']);
    }

    /**
     * Refund a paid appointment via the Stripe plugin's recorded charge.
     * Returns ['ok'=>bool, 'error'=>?string].
     */
    public static function refundAppointment(int $apptId, ?int $amountCents = null): array {
        if (!class_exists('StripePaymentAPI')) return ['ok' => false, 'error' => 'Stripe plugin not active.'];
        $tid = current_tenant_id();
        $a = Database::row("SELECT * FROM booking_appointments WHERE id = ? AND tenant_id = ?", [$apptId, $tid]);
        if (!$a) return ['ok' => false, 'error' => 'Appointment not found.'];
        if (empty($a['charge_id'])) return ['ok' => false, 'error' => 'No recorded charge to refund.'];

        $res = StripePaymentAPI::refundCharge((int)$a['charge_id'], $amountCents);
        if (empty($res['ok'])) return ['ok' => false, 'error' => $res['error'] ?? 'Refund failed.'];

        $refunded = $amountCents ?? (int)$a['paid_cents'];
        $newStatus = $refunded >= (int)$a['paid_cents'] ? 'refunded' : 'partially_refunded';
        Database::update('booking_appointments', ['payment_status' => $newStatus], 'id = ? AND tenant_id = ?', [$apptId, $tid]);
        AuditLog::record('booking.refunded', (string)$apptId, ['amount' => $refunded]);
        return ['ok' => true];
    }

    /** Returns an error string if a required custom field is empty, else null. */
    public static function validateCustomFields(int $serviceId, array $values): ?string {
        foreach (self::getCustomFields($serviceId) as $f) {
            if ((int)($f['is_required'] ?? 0) !== 1) continue;
            $v = $values[$f['name']] ?? '';
            if (is_array($v)) $v = implode('', $v);
            if (trim((string)$v) === '') {
                return 'Please complete the field: ' . ($f['label'] ?? $f['name']);
            }
        }
        return null;
    }

    // ── Catalog getters (defensive: tolerate pre-migration installs) ──

    public static function getCategories(bool $activeOnly = false): array {
        try {
            return Database::rows(
                "SELECT * FROM booking_categories WHERE tenant_id = ?"
                . ($activeOnly ? " AND is_active = 1" : "")
                . " ORDER BY sort_order, name",
                [current_tenant_id()]
            );
        } catch (\Throwable $e) { return []; }
    }

    public static function getLocations(bool $activeOnly = false): array {
        try {
            return Database::rows(
                "SELECT * FROM booking_locations WHERE tenant_id = ?"
                . ($activeOnly ? " AND is_active = 1" : "")
                . " ORDER BY sort_order, name",
                [current_tenant_id()]
            );
        } catch (\Throwable $e) { return []; }
    }

    public static function getResources(bool $activeOnly = false): array {
        try {
            return Database::rows(
                "SELECT * FROM booking_resources WHERE tenant_id = ?"
                . ($activeOnly ? " AND is_active = 1" : "")
                . " ORDER BY name",
                [current_tenant_id()]
            );
        } catch (\Throwable $e) { return []; }
    }

    public static function getServiceAddons(int $serviceId, bool $activeOnly = true): array {
        try {
            return Database::rows(
                "SELECT * FROM booking_service_addons WHERE service_id = ? AND tenant_id = ?"
                . ($activeOnly ? " AND is_active = 1" : "")
                . " ORDER BY sort_order, id",
                [$serviceId, current_tenant_id()]
            );
        } catch (\Throwable $e) { return []; }
    }

    /** Custom fields for a service = global (service_id NULL) + service-specific. */
    public static function getCustomFields(int $serviceId): array {
        try {
            return Database::rows(
                "SELECT * FROM booking_custom_fields
                  WHERE tenant_id = ? AND is_active = 1 AND (service_id IS NULL OR service_id = ?)
               ORDER BY sort_order, id",
                [current_tenant_id(), $serviceId]
            );
        } catch (\Throwable $e) { return []; }
    }

    public static function getProviderBreaks(int $providerId): array {
        try {
            return Database::rows(
                "SELECT * FROM booking_provider_breaks WHERE provider_id = ? AND tenant_id = ?
               ORDER BY day_of_week, start_time",
                [$providerId, current_tenant_id()]
            );
        } catch (\Throwable $e) { return []; }
    }

    public static function getDateOverrides(?int $providerId = null): array {
        try {
            return Database::rows(
                "SELECT * FROM booking_date_overrides
                  WHERE tenant_id = ? AND (provider_id = ? OR provider_id IS NULL)
               ORDER BY `date`",
                [current_tenant_id(), $providerId]
            );
        } catch (\Throwable $e) { return []; }
    }

    // ── Customer management ───────────────────────────────────────

    /** Insert or update a booking-customer profile, bumping booking_count. */
    public static function upsertCustomer(string $email, string $name, string $phone = '', ?int $customerId = null): void {
        $email = trim($email);
        if ($email === '') return;
        $tid = current_tenant_id();
        try {
            $existing = Database::row("SELECT id FROM booking_customers WHERE tenant_id = ? AND email = ?", [$tid, $email]);
            if ($existing) {
                Database::query(
                    "UPDATE booking_customers
                        SET booking_count = booking_count + 1,
                            name = ?,
                            phone = COALESCE(NULLIF(?, ''), phone),
                            customer_id = COALESCE(?, customer_id)
                      WHERE id = ?",
                    [mb_substr($name, 0, 160), mb_substr($phone, 0, 40), $customerId, (int)$existing['id']]
                );
            } else {
                Database::insert('booking_customers', [
                    'tenant_id'     => $tid,
                    'customer_id'   => $customerId,
                    'email'         => mb_substr($email, 0, 200),
                    'name'          => mb_substr($name, 0, 160),
                    'phone'         => $phone !== '' ? mb_substr($phone, 0, 40) : null,
                    'booking_count' => 1,
                ]);
            }
        } catch (\Throwable $e) {
            slate_log('Booking: upsertCustomer failed: ' . $e->getMessage(), 'warning');
        }
    }

    /** Loyalty points awarded per completed appointment (from settings). */
    public static function loyaltyPerBooking(): int {
        return max(0, (int)(Database::setting('booking.loyalty_points_per_booking') ?: 0));
    }

    /**
     * Change an appointment's status and adjust customer counters
     * (no-show / completed + loyalty). Returns true on success.
     */
    public static function changeStatus(int $id, string $status): bool {
        if (!in_array($status, ['pending','confirmed','cancelled','no_show','completed'], true)) return false;
        $tid = current_tenant_id();
        $row = Database::row("SELECT * FROM booking_appointments WHERE id = ? AND tenant_id = ?", [$id, $tid]);
        if (!$row) return false;
        $prev = (string)$row['status'];
        if ($prev === $status) return true;

        Database::update('booking_appointments', ['status' => $status], 'id = ? AND tenant_id = ?', [$id, $tid]);
        AuditLog::record('booking.status_changed', (string)$id, ['from' => $prev, 'to' => $status]);
        Hook::doAction('booking_status_changed', $id, $status);

        $email = (string)$row['customer_email'];
        if ($status === 'no_show' && $prev !== 'no_show') {
            self::bumpCustomer($email, ['no_show_count' => 1]);
        } elseif ($status === 'completed' && $prev !== 'completed') {
            self::bumpCustomer($email, ['completed_count' => 1, 'loyalty_points' => self::loyaltyPerBooking()]);
        }
        return true;
    }

    /** Apply integer increments to a booking-customer's counters. */
    private static function bumpCustomer(string $email, array $increments): void {
        $email = trim($email);
        if ($email === '' || !$increments) return;
        $allowed = ['no_show_count', 'completed_count', 'loyalty_points', 'booking_count'];
        $sets = [];
        $params = [];
        foreach ($increments as $col => $delta) {
            if (!in_array($col, $allowed, true)) continue;
            $sets[] = "`{$col}` = `{$col}` + ?";
            $params[] = (int)$delta;
        }
        if (!$sets) return;
        $params[] = current_tenant_id();
        $params[] = $email;
        try {
            Database::query("UPDATE booking_customers SET " . implode(', ', $sets) . " WHERE tenant_id = ? AND email = ?", $params);
        } catch (\Throwable $e) {
            slate_log('Booking: bumpCustomer failed: ' . $e->getMessage(), 'warning');
        }
    }

    public static function getBookingCustomers(string $search = '', string $tag = ''): array {
        $tid = current_tenant_id();
        $where = ['tenant_id = ?'];
        $params = [$tid];
        if ($search !== '') {
            $where[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        if ($tag !== '') { $where[] = 'tags LIKE ?'; $params[] = '%' . $tag . '%'; }
        try {
            return Database::rows(
                "SELECT * FROM booking_customers WHERE " . implode(' AND ', $where) . " ORDER BY name LIMIT 500",
                $params
            );
        } catch (\Throwable $e) { return []; }
    }

    public static function getBookingCustomer(int $id): ?array {
        try {
            return Database::row("SELECT * FROM booking_customers WHERE id = ? AND tenant_id = ?", [$id, current_tenant_id()]);
        } catch (\Throwable $e) { return null; }
    }

    /** Appointment history for a customer email (most recent first). */
    public static function customerHistory(string $email, int $limit = 100): array {
        try {
            return Database::rows(
                "SELECT a.*, s.name AS service_name, s.name_fr AS service_name_fr, p.name AS provider_name
                   FROM booking_appointments a
                   JOIN booking_services  s ON s.id = a.service_id
                   JOIN booking_providers p ON p.id = a.provider_id
                  WHERE a.tenant_id = ? AND a.customer_email = ?
               ORDER BY a.starts_at DESC LIMIT ?",
                [current_tenant_id(), $email, max(1, $limit)]
            );
        } catch (\Throwable $e) { return []; }
    }

    public static function updateCustomerProfile(int $id, array $fields): bool {
        $tid = current_tenant_id();
        $allowed = ['name', 'phone', 'birthday', 'notes', 'tags', 'loyalty_points'];
        $set = [];
        foreach ($allowed as $k) if (array_key_exists($k, $fields)) $set[$k] = $fields[$k];
        if (!$set) return false;
        try {
            Database::update('booking_customers', $set, 'id = ? AND tenant_id = ?', [$id, $tid]);
            AuditLog::record('booking.customer_updated', (string)$id);
            return true;
        } catch (\Throwable $e) { return false; }
    }

    /** GDPR: assemble all data held for a customer email. */
    public static function exportCustomerData(string $email): array {
        return [
            'profile'      => Database::row("SELECT * FROM booking_customers WHERE tenant_id = ? AND email = ?", [current_tenant_id(), $email]),
            'appointments' => self::customerHistory($email, 1000),
            'exported_at'  => date('c'),
        ];
    }

    /** GDPR: delete the profile and anonymise the customer's appointments. */
    public static function deleteCustomerData(string $email): bool {
        $email = trim($email);
        if ($email === '') return false;
        $tid = current_tenant_id();
        try {
            Database::query(
                "UPDATE booking_appointments
                    SET customer_name = 'Deleted', customer_email = CONCAT('deleted+', id, '@example.invalid'),
                        customer_phone = NULL, customer_id = NULL, notes = NULL, custom_json = NULL
                  WHERE tenant_id = ? AND customer_email = ?",
                [$tid, $email]
            );
            Database::delete('booking_customers', 'tenant_id = ? AND email = ?', [$tid, $email]);
            AuditLog::record('booking.customer_deleted', $email);
            return true;
        } catch (\Throwable $e) {
            slate_log('Booking: deleteCustomerData failed: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    public static function generateRef(): string {
        return 'BK-' . strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * Email helpers
     */

    public static function sendConfirmation(array $args): bool {
        $siteName = Database::setting('site_name') ?: 'Kohevo';
        $when     = self::frenchDate(slate_format_datetime($args['starts_at'], 'l, j F Y'));

        // Per-service template override (placeholders rendered, then still
        // wrapped in the branded shell — the stored template is just the
        // inner message, so admins editing it never have to touch the
        // header/footer chrome) or a branded default.
        if (!empty($args['confirm_body'])) {
            $subject = self::renderTemplate((string)($args['confirm_subject'] ?: __('booking_email_confirm_subject_default', 'Confirmation of your appointment ({{ref}})')), $args);
            $inner   = self::renderTemplate((string)$args['confirm_body'], $args);
        } else {
            $subject = sprintf(__('booking_email_confirm_subject', 'Your appointment confirmation (%s)'), $args['ref']);
            $inner   = '<h2 style="margin:0 0 18px;font-size:20px;font-weight:700;color:#2d2a26;">' . e(__('booking_email_confirmed_heading', 'Appointment confirmed')) . '</h2>'
                     . '<p style="margin:0 0 14px;">' . sprintf(__('booking_email_greeting', 'Hello %s,'), e($args['customer_name'])) . '</p>'
                     . '<p style="margin:0 0 4px;">' . e(__('booking_email_confirmed_body', 'Your appointment is confirmed — we look forward to seeing you.')) . '</p>'
                     . self::emailInfoCard([
                           [__('service', 'Service'), e($args['service_name'])],
                           [__('booking_email_label_provider', 'Practitioner'), e($args['provider_name'])],
                           [__('booking_email_label_date', 'Date'), e($when)],
                           [__('booking_email_label_reference', 'Reference'), '<code>' . e($args['ref']) . '</code>'],
                       ]);
            if (!empty($args['manage_token']) && defined('SLATE_URL')) {
                $manageUrl = SLATE_URL . '/book/manage?token=' . rawurlencode((string)$args['manage_token']);
                $inner .= self::emailButton($manageUrl, __('booking_email_manage_booking_btn', 'Manage my booking'));
                $inner .= '<p style="margin:6px 0 0;font-size:13px;color:#8a7d61;">' . e(__('booking_email_manage_hint', 'Cancel or reschedule in one click.')) . '</p>';
            } else {
                $inner .= '<p style="margin:18px 0 0;">' . e(__('booking_email_reply_to_change', 'To cancel or reschedule, simply reply to this email.')) . '</p>';
            }
        }
        $body = self::brandedEmailShell($inner, sprintf(__('booking_email_confirmed_preheader', 'Your appointment is confirmed — %s'), $args['service_name']));
        $ok = (bool) Mailer::send($args['customer_email'], $subject, $body, $args['customer_name']);

        if (!empty($args['customer_phone'])) {
            self::notifySms((string)$args['customer_phone'],
                sprintf(__('booking_sms_confirmed', '%s: booking confirmed for %s on %s. Ref %s'), $siteName, $args['service_name'], $when, $args['ref']));
        }
        return $ok;
    }

    /**
     * Email the customer that their booking request was received and is
     * waiting on staff review (booking.confirmation_mode = 'manual').
     * Deliberately distinct from sendConfirmation() — this booking is NOT
     * confirmed yet, and must not read like it is.
     */
    public static function sendAwaitingApprovalNotice(array $args): bool {
        $when = self::frenchDate(slate_format_datetime($args['starts_at'] ?? '', 'l, j F Y'));
        $inner = '<h2 style="margin:0 0 18px;font-size:20px;font-weight:700;color:#2d2a26;">' . e(__('booking_email_request_received_heading', 'Request received')) . '</h2>'
              . '<p style="margin:0 0 14px;">' . sprintf(__('booking_email_greeting', 'Hello %s,'), e($args['customer_name'])) . '</p>'
              . '<p style="margin:0 0 4px;">' . e(__('booking_email_awaiting_approval_body', "Thank you — we've received your booking request. It isn't confirmed yet: our team reviews every request before approval, and we'll email you as soon as it's approved.")) . '</p>'
              . self::emailInfoCard([
                    [__('service', 'Service'), e($args['service_name'] ?? '')],
                    [__('booking_email_label_with', 'With'), e($args['provider_name'] ?? '')],
                    [__('booking_email_label_requested_slot', 'Requested slot'), e($when)],
                    [__('booking_email_label_reference', 'Reference'), '<code>' . e($args['ref'] ?? '') . '</code>'],
                ]);
        if (!empty($args['manage_token']) && defined('SLATE_URL')) {
            $manageUrl = SLATE_URL . '/book/manage?token=' . rawurlencode((string)$args['manage_token']);
            $inner .= self::emailButton($manageUrl, __('booking_email_manage_request_btn', 'Manage my request'));
            $inner .= '<p style="margin:6px 0 0;font-size:13px;color:#8a7d61;">' . e(__('booking_email_view_or_cancel_anytime', 'View or cancel anytime.')) . '</p>';
        }
        $body = self::brandedEmailShell($inner, __('booking_email_awaiting_approval_preheader', 'Your booking request is under review'));
        return (bool) Mailer::send($args['customer_email'], sprintf(__('booking_email_awaiting_approval_subject', "We've received your booking request (%s)"), $args['ref']), $body, $args['customer_name']);
    }

    public static function sendReminder(array $appt, string $when, int $leadMinutes = 0): void {
        $inner = '<h2 style="margin:0 0 18px;font-size:20px;font-weight:700;color:#2d2a26;">' . e(__('booking_email_reminder_heading', 'Appointment reminder')) . '</h2>'
              . '<p style="margin:0 0 14px;">' . sprintf(__('booking_email_greeting', 'Hello %s,'), e($appt['customer_name'])) . '</p>'
              . '<p style="margin:0 0 4px;">' . sprintf(
                    __('booking_email_reminder_body', 'This is a reminder: your appointment for <strong>%s</strong> with %s is in %s.'),
                    e($appt['service_name']), e($appt['provider_name']), e($when)
                ) . '</p>'
              . self::emailInfoCard([
                    [__('booking_email_label_starts', 'Starts'), '<strong>' . e(self::frenchDate(slate_format_datetime($appt['starts_at'], 'l, j F Y'))) . '</strong>'],
                    [__('booking_email_label_reference', 'Reference'), '<code>' . e($appt['ref']) . '</code>'],
                ]);
        // Extension point: companion plugins (e.g. Booking+) may override the
        // inner content per-service and per-lead-time. No-op when nothing
        // listens. Still runs through brandedEmailShell() below either way.
        $inner = Hook::applyFilters('booking_reminder_body', $inner, $appt, $leadMinutes);
        $body  = self::brandedEmailShell($inner, sprintf(__('booking_email_reminder_preheader', 'Your appointment is coming up — %s'), $appt['service_name']));
        Mailer::send($appt['customer_email'], sprintf(__('booking_email_reminder_subject', 'Reminder: %s in %s'), $appt['service_name'], $when), $body, $appt['customer_name']);

        if (!empty($appt['customer_phone'])) {
            $siteName = Database::setting('site_name') ?: 'Kohevo';
            self::notifySms((string)$appt['customer_phone'],
                sprintf(
                    __('booking_sms_reminder', '%s reminder: %s %s (%s). Ref %s'),
                    $siteName, $appt['service_name'], $when,
                    self::frenchDate(slate_format_datetime($appt['starts_at'], 'j M')), $appt['ref']
                ));
        }
    }

    /** Feedback / follow-up email sent after an appointment completes. */
    public static function sendFollowup(array $appt): void {
        $inner = '<h2 style="margin:0 0 18px;font-size:20px;font-weight:700;color:#2d2a26;">' . e(__('booking_email_feedback_heading', 'Your feedback matters to us')) . '</h2>'
              . '<p style="margin:0 0 14px;">' . sprintf(__('booking_email_greeting', 'Hello %s,'), e($appt['customer_name'])) . '</p>'
              . '<p style="margin:0;">' . sprintf(
                    __('booking_email_feedback_body', "Thank you for your visit for <strong>%s</strong>. We'd love your feedback — just reply to this email."),
                    e($appt['service_name'])
                ) . '</p>';
        $body = self::brandedEmailShell($inner, __('booking_email_feedback_preheader', 'How did your appointment go?'));
        Mailer::send($appt['customer_email'], sprintf(__('booking_email_feedback_subject', 'How did it go · %s?'), $appt['service_name']), $body, $appt['customer_name']);
    }

    /**
     * Notify the assigned staff member of a new booking. $needsApproval
     * flags a booking sitting in manual review — the subject/lead line
     * says so explicitly, since this is the one case where the provider
     * (or whoever else is watching the admin bell) needs to act on it.
     */
    public static function sendStaffNotification(array $provider, array $appt, bool $needsApproval = false): bool {
        if (empty($provider['email'])) return false;
        $when = self::frenchDate(slate_format_datetime($appt['starts_at'], 'l, j F Y'));
        $badge = $needsApproval
            ? '<span style="display:inline-block;padding:4px 10px;border-radius:20px;background-color:#fdf0e0;color:#9a6a1a;font-size:12px;font-weight:700;margin-bottom:14px;">' . e(__('booking_email_badge_approval_needed', 'APPROVAL NEEDED')) . '</span><br>'
            : '<span style="display:inline-block;padding:4px 10px;border-radius:20px;background-color:#eef7ee;color:#2f7d3c;font-size:12px;font-weight:700;margin-bottom:14px;">' . e(__('booking_email_badge_new_booking', 'NEW BOOKING')) . '</span><br>';
        $inner = $badge
              . '<h2 style="margin:10px 0 18px;font-size:20px;font-weight:700;color:#2d2a26;">'
              . e($needsApproval ? __('booking_email_staff_needs_approval_heading', 'A booking is waiting for your approval') : __('booking_email_staff_new_booking_heading', 'You have a new booking')) . '</h2>'
              . '<p style="margin:0 0 4px;">' . sprintf(__('booking_email_greeting', 'Hello %s,'), e($provider['name'] ?? '')) . '</p>'
              . self::emailInfoCard(array_filter([
                    [__('service', 'Service'), e($appt['service_name'])],
                    [__('booking_email_label_date', 'Date'), e($when)],
                    [__('booking_email_label_client', 'Client'), e($appt['customer_name']) . ' &lt;' . e($appt['customer_email']) . '&gt;'
                        . (!empty($appt['customer_phone']) ? ' · ' . e($appt['customer_phone']) : '')],
                    (($appt['party_size'] ?? 1) > 1) ? [__('booking_email_label_attendees', 'Attendees'), (int)$appt['party_size']] : null,
                    [__('booking_email_label_reference', 'Reference'), '<code>' . e($appt['ref']) . '</code>'],
                ]));
        $prefix = $needsApproval
            ? __('booking_email_approval_required_prefix', 'Approval required · ')
            : __('booking_email_new_booking_prefix', 'New booking · ');
        $body = self::brandedEmailShell($inner, $prefix . $appt['service_name']);
        $subject = $prefix . $appt['service_name'] . ' (' . $appt['ref'] . ')';
        return (bool) Mailer::send($provider['email'], $subject, $body, (string)($provider['name'] ?? ''));
    }

    /** Email the customer that their appointment was cancelled. */
    public static function sendCancellation(array $appt, string $reason = ''): bool {
        $when = self::frenchDate(slate_format_datetime($appt['starts_at'], 'l, j F Y'));
        $inner = '<h2 style="margin:0 0 18px;font-size:20px;font-weight:700;color:#2d2a26;">' . e(__('booking_email_cancelled_heading', 'Appointment cancelled')) . '</h2>'
              . '<p style="margin:0 0 14px;">' . sprintf(__('booking_email_greeting', 'Hello %s,'), e($appt['customer_name'])) . '</p>'
              . '<p style="margin:0 0 4px;">' . sprintf(
                    __('booking_email_cancelled_body', 'Your appointment for <strong>%s</strong> on %s has been cancelled.'),
                    e($appt['service_name']), e($when)
                ) . '</p>'
              . self::emailInfoCard(array_filter([
                    $reason !== '' ? [__('booking_email_label_reason', 'Reason'), e($reason)] : null,
                    [__('booking_email_label_reference', 'Reference'), '<code>' . e($appt['ref']) . '</code>'],
                ]));
        $body = self::brandedEmailShell($inner, __('booking_email_cancelled_preheader', 'Your appointment has been cancelled'));
        return (bool) Mailer::send($appt['customer_email'], sprintf(__('booking_email_cancelled_subject', 'Your booking has been cancelled (%s)'), $appt['ref']), $body, $appt['customer_name']);
    }

    /** Email the customer that their appointment was moved. */
    public static function sendReschedule(array $appt, string $newStartsAt): bool {
        $when = self::frenchDate(slate_format_datetime($newStartsAt, 'l, j F Y'));
        $inner = '<h2 style="margin:0 0 18px;font-size:20px;font-weight:700;color:#2d2a26;">' . e(__('booking_email_rescheduled_heading', 'Appointment rescheduled')) . '</h2>'
              . '<p style="margin:0 0 14px;">' . sprintf(__('booking_email_greeting', 'Hello %s,'), e($appt['customer_name'])) . '</p>'
              . '<p style="margin:0 0 4px;">' . sprintf(
                    __('booking_email_rescheduled_body', 'Your appointment for <strong>%s</strong> has been moved.'),
                    e($appt['service_name'])
                ) . '</p>'
              . self::emailInfoCard([
                    [__('booking_email_label_new_time', 'New time'), '<strong>' . e($when) . '</strong>'],
                    [__('booking_email_label_reference', 'Reference'), '<code>' . e($appt['ref']) . '</code>'],
                ]);
        $body = self::brandedEmailShell($inner, __('booking_email_rescheduled_preheader', 'Your appointment has been rescheduled'));
        return (bool) Mailer::send($appt['customer_email'], sprintf(__('booking_email_rescheduled_subject', 'Your booking has been rescheduled (%s)'), $appt['ref']), $body, $appt['customer_name']);
    }

    // ── Notification config + channels ────────────────────────────

    /**
     * Reminder lead times in minutes, from settings. Default 24h + 1h.
     *
     * The DEFAULT is filterable; the stored value is not. A plugin that wants a
     * different cadence out of the box returns one from
     * `booking_default_reminder_leads` instead of writing
     * `booking.reminder_leads` itself — that setting has exactly one writer,
     * Booking's own settings screen. Booking+ used to write it from a second
     * screen and re-seed it at boot, so whichever screen was saved last won.
     */
    public static function reminderLeads(): array {
        $default = (string) Hook::applyFilters('booking_default_reminder_leads', '1440,60');
        $raw = (string) (Database::setting('booking.reminder_leads') ?: $default);
        $leads = [];
        foreach (explode(',', $raw) as $p) {
            $m = (int) trim($p);
            if ($m > 0) $leads[$m] = true;
        }
        return array_keys($leads) ?: [1440, 60];
    }

    // ── Branded email chrome ────────────────────────────────────────────
    // Table-based markup with everything inlined — no <style> block, no
    // flexbox/grid, no CSS shorthand Outlook's Word rendering engine drops.
    // Every booking email (customer and staff) is built as: brandedEmailShell(
    // heading + paragraphs + optional emailInfoCard() + optional emailButton() ).

    /** The tenant's brand accent color (Global Styles), falling back to Slate's default blue. */
    private static function brandAccent(): string {
        $accent = trim((string) Database::setting('brand_accent_color'));
        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent) ? $accent : '#111111';
    }

    /**
     * Wrap inner content HTML in the branded header/card/footer chrome —
     * logo (or site name) on an accent-colored band, a white rounded card
     * for the message, a quiet footer. $innerHtml is trusted HTML built by
     * the caller (already-escaped dynamic values); this does no escaping
     * of its own beyond the site name / accent color it reads itself.
     */
    private static function brandedEmailShell(string $innerHtml, string $preheader = ''): string {
        // The layout now lives in the shared core template so every notification email matches.
        return \Slate\Services\Notifications\EmailTemplate::shell($innerHtml, $preheader);
    }

    /**
     * A soft accent-tinted "details" card for service/provider/date/time/
     * ref rows. $rows is a list of [label, valueHtml] pairs — valueHtml is
     * NOT escaped here (matches every existing caller's own e($x) calls),
     * so callers stay in full control of what's already-safe vs. raw.
     */
    private static function emailInfoCard(array $rows): string {
        return \Slate\Services\Notifications\EmailTemplate::infoCard($rows);
    }

    /** Bulletproof branded CTA button (table+link, not a bare <a> — renders correctly in Outlook). */
    private static function emailButton(string $url, string $label): string {
        return \Slate\Services\Notifications\EmailTemplate::button($url, $label);
    }

    /**
     * PHP's date() has no locale awareness (strftime(), which did, was
     * removed in 8.1) — 'l'/'F'/'M' always come out in English regardless
     * of site language. Scoped to this file's own email/SMS text rather
     * than fixed in the shared slate_format_datetime() helper, since that
     * helper is used site-wide (admin UI, other plugins, EN-language
     * pages too) and forcing French there would be a much bigger, riskier
     * change than what was actually asked for here.
     */
    private static function frenchDate(string $formatted): string {
        static $map = [
            'Monday' => 'Lundi', 'Tuesday' => 'Mardi', 'Wednesday' => 'Mercredi', 'Thursday' => 'Jeudi',
            'Friday' => 'Vendredi', 'Saturday' => 'Samedi', 'Sunday' => 'Dimanche',
            'January' => 'janvier', 'February' => 'février', 'March' => 'mars', 'April' => 'avril',
            'May' => 'mai', 'June' => 'juin', 'July' => 'juillet', 'August' => 'août',
            'September' => 'septembre', 'October' => 'octobre', 'November' => 'novembre', 'December' => 'décembre',
            'Jan' => 'janv.', 'Feb' => 'févr.', 'Mar' => 'mars', 'Apr' => 'avr.', 'Jun' => 'juin', 'Jul' => 'juil.',
            'Aug' => 'août', 'Sep' => 'sept.', 'Oct' => 'oct.', 'Nov' => 'nov.', 'Dec' => 'déc.',
        ];
        return strtr($formatted, $map);
    }

    /** Substitute {{placeholders}} in a per-service template (HTML-escaped). */
    public static function renderTemplate(string $tpl, array $a): string {
        $ts  = strtotime((string)($a['starts_at'] ?? 'now')) ?: time();
        $map = [
            '{{name}}'     => e((string)($a['customer_name'] ?? '')),
            '{{service}}'  => e((string)($a['service_name'] ?? '')),
            '{{provider}}' => e((string)($a['provider_name'] ?? '')),
            '{{when}}'     => e(self::frenchDate(slate_format_datetime($ts, 'l, j F Y'))),
            '{{date}}'     => e(self::frenchDate(date('j M Y', $ts))),
            '{{time}}'     => e(slate_format_time($ts)),
            '{{ref}}'      => e((string)($a['ref'] ?? '')),
        ];
        return strtr($tpl, $map);
    }

    /** Best-effort SMS + WhatsApp to a customer, per settings. Never throws. */
    public static function notifySms(string $to, string $body): void {
        $to = trim($to);
        if ($to === '') return;
        try {
            if (Database::setting('booking.sms_enabled') === '1')      self::sms($to, $body);
            if (Database::setting('booking.whatsapp_enabled') === '1') self::whatsapp($to, $body);
        } catch (\Throwable $e) {
            slate_log('Booking: SMS notify failed: ' . $e->getMessage(), 'warning');
        }
    }

    /** Send an SMS via Twilio. Returns true on 2xx. */
    public static function sms(string $to, string $body): bool {
        $from = (string) Database::setting('booking.twilio_sms_from');
        if ($from === '') return false;
        return self::twilioSend($from, $to, $body);
    }

    /** Send a WhatsApp message via Twilio. */
    public static function whatsapp(string $to, string $body): bool {
        $from = (string) Database::setting('booking.twilio_whatsapp_from');
        if ($from === '') return false;
        $to   = str_starts_with($to, 'whatsapp:') ? $to : 'whatsapp:' . $to;
        $from = str_starts_with($from, 'whatsapp:') ? $from : 'whatsapp:' . $from;
        return self::twilioSend($from, $to, $body);
    }

    /** Low-level Twilio Messages API call. Credentials from settings. */
    private static function twilioSend(string $from, string $to, string $body): bool {
        $sid   = (string) Database::setting('booking.twilio_sid');
        $token = (string) slate_decrypt_secret((string) Database::setting('booking.twilio_token'));
        if ($sid === '' || $token === '' || !function_exists('curl_init')) return false;

        $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => $sid . ':' . $token,
            CURLOPT_POSTFIELDS     => http_build_query(['From' => $from, 'To' => $to, 'Body' => mb_substr($body, 0, 1500)]),
            CURLOPT_TIMEOUT        => 12,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($code < 200 || $code >= 300) {
            slate_log("Booking: Twilio send failed (HTTP {$code}) {$err}", 'warning');
            return false;
        }
        return true;
    }

    public static function slugify(string $name, ?int $excludeId = null): string {
        $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
        if ($base === '') $base = 'service';
        if (mb_strlen($base) > 60) $base = substr($base, 0, 60);
        $slug = $base;
        $i = 2;
        $tid = current_tenant_id();
        while (true) {
            $row = Database::row(
                "SELECT id FROM booking_services WHERE tenant_id = ? AND slug = ?" . ($excludeId ? " AND id <> ?" : ""),
                $excludeId ? [$tid, $slug, $excludeId] : [$tid, $slug]
            );
            if (!$row) return $slug;
            $slug = $base . '-' . $i++;
            if ($i > 999) return $base . '-' . bin2hex(random_bytes(3));
        }
    }

    /* ───────────────────── Content Builder integration ─────────────────────
     * Lets booking be dropped into any page as a block. The block renders the
     * service CATALOGUE inline — real, indexable, themeable content — and each
     * card links to /book, where the four-step wizard, payments and CSRF keep
     * working untouched. Optionally pin one service, which then renders as a
     * single call to action instead of the full list.
     *
     * It used to embed the whole widget in a same-origin iframe. That kept the
     * flow working but put nothing in the document: the page carried a reference,
     * not content, and --slate-* could not reach inside the frame. */

    /** Active services as editor select options, plus a "full flow" default. */
    public static function pickerOptions(): array {
        $out = [['v' => '', 'l' => 'Show all services (catalogue)']];
        try {
            foreach (self::getActiveServices() as $s) {
                $name = self::serviceName($s);
                $out[] = ['v' => (string)$s['id'], 'l' => ($name !== '' ? $name : ('Service #' . $s['id']))];
            }
        } catch (\Throwable $e) {
            // schema not ready / no services — just offer the full flow.
        }
        return $out;
    }

    /**
     * The service catalogue, as HTML.
     *
     * Extracted from the standalone step 1 so the booking BLOCK and the booking
     * PAGE render the same cards from one implementation. Duplicating the markup
     * would have been quicker and would drift the first time a field is added to
     * a service — the same reason the form block calls renderFormBody() rather
     * than rebuilding fields.
     *
     * $href maps a service id to its destination, because the two callers differ:
     * the standalone page keeps its embed/query state, the block links out to
     * /book. Passing a callable keeps that difference at the call site instead of
     * threading flags through here.
     */
    public static function renderServiceCatalogue(callable $href, ?int $onlyServiceId = null): string
    {
        $services = self::getActiveServices();
        if ($onlyServiceId !== null) {
            $services = array_values(array_filter(
                $services,
                static fn (array $s): bool => (int)($s['id'] ?? 0) === $onlyServiceId
            ));
        }

        if (!$services) {
            return '<div class="alert alert-info">'
                 . ($onlyServiceId !== null
                      ? e(__('booking_service_no_longer_available', 'That service is no longer available.'))
                      : e(__('booking_no_services_available', 'No services are currently available.')))
                 . '</div>';
        }

        $catName = [];
        foreach (self::getCategories(true) as $c) {
            $catName[(int)$c['id']] = $c['name'];
        }

        $byCat = [];
        foreach ($services as $s) {
            $byCat[(int)($s['category_id'] ?? 0)][] = $s;
        }
        ksort($byCat);

        $out = '';
        foreach ($byCat as $cid => $list) {
            // A category heading only makes sense when the catalogue is grouped;
            // a single pinned service is a call to action, not a category.
            if ($onlyServiceId === null && $cid > 0 && isset($catName[$cid])) {
                $out .= '<h3 class="book-cat-title">' . e($catName[$cid]) . '</h3>';
            }
            $out .= '<div class="book-grid">';
            foreach ($list as $s) {
                $price = ((int)$s['price_cents']) > 0
                    ? slate_format_price((int)$s['price_cents'], $s['currency'])
                    : e(__('booking_free', 'Free'));
                // Extension point: other plugins (e.g. Membership) flag a
                // service card with a small pill — "Membership required" etc.
                // — without BookingAPI needing to know they exist.
                $badges = Hook::applyFilters('booking_service_card_badges', [], $s);
                $out .= '<a class="book-card-link" href="' . e((string) $href((int)$s['id'])) . '">'
                      . '<div class="book-card-title">' . e(self::serviceName($s)) . '</div>'
                      . '<div class="book-card-meta">' . sprintf(e(__('booking_n_min', '%d min')), (int)$s['duration_min']) . ' · ' . $price
                      . (!empty($s['is_online']) ? ' · ' . e(__('booking_online', 'online')) : '') . '</div>';
                if ($badges) {
                    $out .= '<div class="book-card-badges">';
                    foreach ($badges as $b) {
                        $tone = e((string)($b['tone'] ?? 'accent'));
                        $out .= '<span class="book-badge book-badge-' . $tone . '">' . e((string)($b['label'] ?? '')) . '</span>';
                    }
                    $out .= '</div>';
                }
                $desc = self::serviceDescription($s);
                if ($desc !== '') {
                    $out .= '<div class="book-card-desc">'
                          . e(mb_strimwidth($desc, 0, 140, '…')) . '</div>';
                }
                $out .= '</a>';
            }
            $out .= '</div>';
        }
        return $out;
    }

    /**
     * The `booking` block, rendered INLINE into the host page (Phase D, D2).
     *
     * It was an <iframe src="/book?embed=1"> with a postMessage height dance —
     * the same shape the form block had, with the same costs: the document
     * carried {service, minHeight} instead of content, --slate-* stopped at the
     * frame boundary, and a services catalogue that is genuinely worth indexing
     * was invisible to search engines.
     *
     * SCOPE, deliberately narrow. Booking is a four-step stateful wizard
     * (service → date → slot → details, then payment and manage), with its step
     * state in the query string. Hosting all of that on a CMS page would mean the
     * page absorbing every step transition and POST — a project, not a commit —
     * and the later steps are a slot picker, which is not content anyone indexes.
     *
     * So the block inlines STEP ONE, the catalogue, which is the part that is
     * real content: service names, durations, prices, descriptions, grouped by
     * category. Each card links to /book, where the wizard takes over unchanged.
     * The stateful flow stays where it already works.
     *
     * The cards come from renderServiceCatalogue(), shared with the standalone
     * page, so the two surfaces cannot drift.
     */
    public static function renderContentBlock(array $props, array $block = []): string
    {
        // Phase 11: the block is a public Booking surface on any page — an
        // unentitled installation renders nothing, like its /book route (07 §4).
        if (!\ModuleGuard::allows('booking')) return '';

        $serviceId = (int)($props['service'] ?? 0);
        $base = rtrim(SLATE_URL, '/') . '/book';

        $catalogue = self::renderServiceCatalogue(
            static fn (int $id): string => $base . '?service=' . $id,
            $serviceId > 0 ? $serviceId : null
        );

        // book-shell / book-card reproduce the standalone page's wrapper so the
        // widget stylesheet applies. Its selectors are class-based and unscoped,
        // so no body class is needed.
        return '<div class="cb-booking-block">'
             . '<div class="book-shell"><div class="book-card">'
             . $catalogue
             . '</div></div>'
             . '</div>';
    }

    // ─────────────────────────────────────────────────────────────
    // Customer self-service policy (cancel / reschedule)
    // ─────────────────────────────────────────────────────────────
    //
    // Admin-initiated cancel/reschedule (from the admin UI or API) always
    // calls cancelAppointment()/rescheduleAppointment() directly and bypasses
    // this policy by design — same convention as createAppointment()'s
    // source==='online' gate. Only the public self-service manage page
    // (plugins/booking/public/router.php, bookpub_manage()) calls these
    // first and refuses the action on ['ok'=>false].

    /**
     * Whether a customer may cancel this appointment online right now, per
     * the "Customer self-service" settings in Booking → Settings.
     */
    public static function canSelfCancel(array $appt): array {
        $enabled = (string)(Database::setting('booking.self_cancel_enabled') ?? '1');
        if ($enabled === '0') {
            return ['ok' => false, 'error' => 'Online cancellation isn\'t available for this booking. Please contact us directly.'];
        }
        if (in_array($appt['status'] ?? '', ['cancelled', 'completed', 'no_show'], true)) {
            return ['ok' => false, 'error' => 'This booking can no longer be cancelled online.'];
        }
        $startTs = strtotime((string)($appt['starts_at'] ?? ''));
        if ($startTs === false || $startTs <= time()) {
            return ['ok' => false, 'error' => 'This appointment has already started or passed — please contact us directly.'];
        }
        $minHours = max(0, (int)(Database::setting('booking.cancel_min_notice_hours') ?? 0));
        if ($minHours > 0 && ($startTs - time()) < $minHours * 3600) {
            return ['ok' => false, 'error' => 'Online cancellation requires at least ' . self::humanNotice($minHours)
                . ' notice. Please contact us directly to cancel this booking.'];
        }
        return ['ok' => true];
    }

    /**
     * Whether a customer may reschedule this appointment online right now,
     * per the "Customer self-service" settings in Booking → Settings.
     */
    public static function canSelfReschedule(array $appt): array {
        $enabled = (string)(Database::setting('booking.self_reschedule_enabled') ?? '1');
        if ($enabled === '0') {
            return ['ok' => false, 'error' => 'Online rescheduling isn\'t available for this booking. Please contact us directly.'];
        }
        if (in_array($appt['status'] ?? '', ['cancelled', 'completed', 'no_show'], true)) {
            return ['ok' => false, 'error' => 'This booking can no longer be rescheduled online.'];
        }
        $startTs = strtotime((string)($appt['starts_at'] ?? ''));
        if ($startTs === false || $startTs <= time()) {
            return ['ok' => false, 'error' => 'This appointment has already started or passed — please contact us directly.'];
        }
        $minHours = max(0, (int)(Database::setting('booking.reschedule_min_notice_hours') ?? 0));
        if ($minHours > 0 && ($startTs - time()) < $minHours * 3600) {
            return ['ok' => false, 'error' => 'Online rescheduling requires at least ' . self::humanNotice($minHours)
                . ' notice. Please contact us directly to reschedule this booking.'];
        }
        $maxResched = max(0, (int)(Database::setting('booking.max_reschedules') ?? 0));
        if ($maxResched > 0 && (int)($appt['reschedule_count'] ?? 0) >= $maxResched) {
            $times = $maxResched === 1 ? 'once' : "{$maxResched} times";
            return ['ok' => false, 'error' => "This booking has already been rescheduled the maximum allowed ({$times}). Please contact us directly."];
        }
        return ['ok' => true];
    }

    /** "24" -> "24 hours"; "48" -> "2 days"; used only for policy error text. */
    private static function humanNotice(int $hours): string {
        if ($hours % 24 === 0 && $hours >= 24) {
            $d = $hours / 24;
            return $d . ' day' . ($d === 1 ? '' : 's');
        }
        return $hours . ' hour' . ($hours === 1 ? '' : 's');
    }

    /**
     * Busy intervals for $providerId on $date sourced from their connected
     * Google Calendar (meetings/events created outside Slate — vacations,
     * personal appointments, other systems). Empty when Google Calendar
     * isn't connected for this provider, or nothing overlaps this date.
     * Consumed by effectiveIntervals() so availability (and therefore
     * booking/reschedule validation) reflects the provider's real calendar,
     * not just Slate's own bookings.
     */
    private static function googleBusyIntervals(int $providerId, string $date): array {
        $tid = current_tenant_id();
        try {
            $rows = Database::rows(
                "SELECT starts_at, ends_at FROM booking_google_busy_blocks
                  WHERE provider_id = ? AND tenant_id = ?
                    AND starts_at < ? AND ends_at > ?",
                [$providerId, $tid, $date . ' 23:59:59', $date . ' 00:00:00']
            );
        } catch (\Throwable $e) {
            return []; // table not migrated yet on this install
        }
        $out = [];
        foreach ($rows as $r) {
            $s = strtotime((string)$r['starts_at']);
            $e = strtotime((string)$r['ends_at']);
            if ($s !== false && $e !== false && $e > $s) $out[] = [$s, $e];
        }
        return $out;
    }

}
