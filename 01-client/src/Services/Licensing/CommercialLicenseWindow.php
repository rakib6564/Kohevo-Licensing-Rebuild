<?php
/**
 * Slate — commercial expiry & grace window (Phase 9).
 *
 * docs/02-architecture/08-EXPIRY-GRACE-OFFLINE.md §2: Active / Warning /
 * Grace / Locked are DERIVED at read time from the trusted, signed
 * snapshot's `status` + `expires_at` and the current time — never stored,
 * never swept by a cron job. This class is the one place that derivation
 * happens. Everything that needs it reads it from here:
 *
 *   - the Global License Guard (includes/license_guard.php) — lock / allow
 *   - EntitlementService (and therefore ModuleGuard, MCP and module cron
 *     listeners) — whether module entitlements are still live
 *   - LicenseStatusPresenter — labels, dates and the warning banner
 *
 * so a boundary can never drift between enforcement and presentation.
 *
 * Pure: no database, no environment, no request input. The caller passes
 * the trust state SlateLicenseCacheStore::readTrustState() already
 * produced and the server's own clock (time()). Nothing a browser sends —
 * query, POST, cookie, header, session — reaches this class.
 *
 * Commercial grace and offline tolerance are two separate windows (08 §1,
 * §4) and are evaluated independently here:
 *
 *   GRACE_SECONDS              measured from expires_at   (commercial)
 *   OFFLINE_TOLERANCE_SECONDS  measured from fetched_at   (network/API)
 *
 * Neither ever extends the other. A stale snapshot is locked no matter
 * what its expiry math says; a fresh snapshot past expires_at + grace is
 * locked no matter how recently it was fetched. Both currently happen to
 * be 7 days — they remain separate named constants so changing one is
 * never a silent change to the other (08 §4).
 *
 * Precedence (highest first — a lower row never overrides a higher one):
 *
 *   1. missing / untrusted (installation mismatch, legacy row) → lock
 *   2. malformed trusted data (bad status/fetched_at/expires_at) → lock
 *   3. administrative status (suspended, revoked, cancelled, any
 *      unrecognised value)                                      → lock
 *   4. stale past offline tolerance                              → lock
 *   5. expired beyond grace (now >= expires_at + 7d)             → lock
 *   6. expired within grace (expires_at <= now < +7d)            → allow
 *   7. expiring soon (expires_at - 7d <= now < expires_at)       → allow
 *   8. active                                                    → allow
 *
 * Commercial grace therefore only ever applies to an otherwise trusted,
 * fresh, commercially-licensed (trial/active) or commercially-expired
 * snapshot. It never rescues a suspended, revoked, untrusted or stale one.
 *
 * Time: every stored timestamp is interpreted as UTC. fetched_at is
 * written by SlateLicenseCacheStore as gmdate(); expires_at is the Central
 * Server's DATETIME, which it also produces and compares in UTC. Parsing
 * with an explicit UTC zone (rather than the process default) makes that
 * interpretation independent of date.timezone.
 */

declare(strict_types=1);

namespace Slate\Services\Licensing;

final class CommercialLicenseWindow
{
    /** 08 §2 — pre-expiry warning window. LOCKED (15-PHASE-1-DECISIONS.md). */
    public const WARNING_SECONDS = 7 * 86400;

    /** 08 §2 — post-expiry commercial grace. LOCKED (15-PHASE-1-DECISIONS.md). */
    public const GRACE_SECONDS = 7 * 86400;

    /** 08 §4 — network/API availability tolerance. NOT commercial grace. */
    public const OFFLINE_TOLERANCE_SECONDS = 7 * 86400;

    public const PHASE_ACTIVE        = 'active';
    public const PHASE_EXPIRING_SOON = 'expiring_soon';
    public const PHASE_GRACE         = 'grace';
    public const PHASE_LOCKED        = 'locked';

    /** Statuses a signed check-in may carry that still permit access (subject to the windows above). */
    private const LICENSED_STATUSES = ['trial', 'active'];

    /**
     * @param array{found?:bool,trusted?:bool,data?:?array} $trust SlateLicenseCacheStore::readTrustState()
     * @param int $now server clock, Unix seconds
     *
     * @return array{
     *   allowed:bool, reason:?string, phase:?string,
     *   expires_at:?int, grace_ends_at:?int,
     *   seconds_until_expiry:?int, grace_seconds_remaining:?int
     * } reason is null when allowed; phase is null when the lock is not a
     *   commercial-expiry lock (untrusted, suspended, stale, …).
     */
    public static function evaluate(array $trust, int $now): array
    {
        if (empty($trust['found'])) {
            return self::locked('missing');
        }
        if (empty($trust['trusted']) || !is_array($trust['data'] ?? null)) {
            return self::locked('untrusted');
        }

        $data = $trust['data'];

        $status = $data['status'] ?? null;
        if (!is_string($status) || $status === '') {
            return self::locked('malformed');
        }

        $fetchedAt = self::parseUtc($data['fetched_at'] ?? null);
        if ($fetchedAt === null) {
            return self::locked('malformed');
        }

        $rawExpires = $data['expires_at'] ?? null;
        $expiresAt = null;
        if ($rawExpires !== null && !(is_string($rawExpires) && trim($rawExpires) === '')) {
            $expiresAt = self::parseUtc($rawExpires);
            if ($expiresAt === null) {
                // Present but unreadable: never treated as "no expiry".
                return self::locked('malformed');
            }
        }

        // 3. Administrative / unknown status. 'expired' is the one
        //    non-licensed status that is itself the grace trigger
        //    (03-LICENSE-LIFECYCLE.md §1) — everything else locks.
        if ($status !== 'expired' && !in_array($status, self::LICENSED_STATUSES, true)) {
            return self::locked($status);
        }

        // 4. Offline tolerance — how long any signed snapshot is trusted
        //    without hearing from the server again (08 §4). Independent of
        //    expires_at; never extends it and is never extended by it.
        if (($now - $fetchedAt) > self::OFFLINE_TOLERANCE_SECONDS) {
            return self::locked('stale');
        }

        if ($expiresAt === null) {
            // No commercial end date. A signed 'expired' with nothing to
            // measure grace from cannot be given a grace window.
            return $status === 'expired'
                ? self::locked('expired', self::PHASE_LOCKED)
                : self::allowed(self::PHASE_ACTIVE, null);
        }

        $graceEndsAt = $expiresAt + self::GRACE_SECONDS;

        // 5. Beyond grace — at exactly expires_at + 7d the lock applies.
        if ($now >= $graceEndsAt) {
            return self::locked('expired', self::PHASE_LOCKED, $expiresAt, $graceEndsAt);
        }

        // 6. Commercial grace — at exactly expires_at grace begins.
        if ($now >= $expiresAt) {
            return self::allowed(self::PHASE_GRACE, $expiresAt, $graceEndsAt, $now);
        }

        if ($status === 'expired') {
            // A signed 'expired' whose own expires_at is still in the
            // future is internally inconsistent. Fail closed rather than
            // guess which half of the payload is right.
            return self::locked('malformed');
        }

        // 7. Pre-expiry warning — begins at exactly expires_at - 7d.
        if (($expiresAt - $now) <= self::WARNING_SECONDS) {
            return self::allowed(self::PHASE_EXPIRING_SOON, $expiresAt, $graceEndsAt, $now);
        }

        return self::allowed(self::PHASE_ACTIVE, $expiresAt, $graceEndsAt, $now);
    }

    /**
     * A stored timestamp as UTC Unix seconds, or null if it is not a real
     * date. Strings without an explicit offset (MySQL DATETIME) are read as
     * UTC; strings with one (ISO-8601 'c') honour it. Zero dates, relative
     * phrases and impossible calendar dates are rejected.
     */
    public static function parseUtc(mixed $value): ?int
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) return null;
        // Absolute dates only — never a relative phrase ('+1 year', 'now',
        // 'tomorrow') that would move with the clock it is compared against.
        if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/', $value) !== 1) return null;
        try {
            $dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return null;
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null; // e.g. '2026-02-31' silently rolling over
        }
        return $dt->getTimestamp();
    }

    /** @return array<string,mixed> */
    private static function allowed(string $phase, ?int $expiresAt, ?int $graceEndsAt = null, ?int $now = null): array
    {
        return [
            'allowed'                 => true,
            'reason'                  => null,
            'phase'                   => $phase,
            'expires_at'              => $expiresAt,
            'grace_ends_at'           => $graceEndsAt,
            'seconds_until_expiry'    => $expiresAt !== null && $now !== null ? max(0, $expiresAt - $now) : null,
            'grace_seconds_remaining' => $phase === self::PHASE_GRACE && $graceEndsAt !== null && $now !== null
                ? max(0, $graceEndsAt - $now) : null,
        ];
    }

    /** @return array<string,mixed> */
    private static function locked(string $reason, ?string $phase = null, ?int $expiresAt = null, ?int $graceEndsAt = null): array
    {
        return [
            'allowed'                 => false,
            'reason'                  => $reason,
            'phase'                   => $phase,
            'expires_at'              => $expiresAt,
            'grace_ends_at'           => $graceEndsAt,
            'seconds_until_expiry'    => null,
            'grace_seconds_remaining' => null,
        ];
    }
}
