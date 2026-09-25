<?php
/**
 * Licensing plugin — License domain service (Phase 2: Central Licensing
 * Platform Foundation).
 *
 * A License is the actual commercial entitlement for a specific
 * installation (docs/00-project/DECISIONS.md §1,
 * docs/02-architecture/02-CENTRAL-LICENSING-DOMAIN.md §1.4) — independent of
 * whichever Plan it was created from. It owns its own entitlement grants
 * (`licensing_license_modules`) and an attributable, timestamped lifecycle
 * (`licensing_license_events`, INV-08).
 *
 * Scope note: this class implements the lifecycle *state machine* described
 * in docs/02-architecture/03-LICENSE-LIFECYCLE.md — it deliberately does
 * NOT implement the Warning/Grace/Locked enforcement timeline
 * (docs/02-architecture/08-EXPIRY-GRACE-OFFLINE.md), the check-in HTTP
 * contract, or any client-facing signed payload. Those are later phases.
 */

declare(strict_types=1);

require_once __DIR__ . '/LicensingAPI.php';

class LicenseService {

    /**
     * Core entitlements are never rows in `licensing_license_modules` — they
     * are implicit whenever the License itself is valid (INV-06,
     * docs/02-architecture/04-ENTITLEMENT-ARCHITECTURE.md §2). Naming per
     * that document §1 (proposed; confirmed non-blocking for Phase 2 by the
     * Antigravity review since these keys never touch the database).
     */
    public const CORE_MODULE_KEYS = ['admin-user', 'dashboard', 'site-settings'];

    /** V1 optional modules (docs/00-project/REQUIREMENTS.md §3, DECISIONS.md §5). */
    public const V1_OPTIONAL_MODULE_KEYS = ['forms', 'membership', 'booking'];

    private const NON_TERMINAL_STATUSES = ['unactivated', 'trial', 'active', 'expired', 'suspended'];
    private const EVENT_TYPES = ['activate', 'suspend', 'revoke', 'renew', 'extend', 'refresh', 'create', 'expire'];

    /**
     * Statuses from which a License may accept a first-time or rebinding
     * Installation activation (docs/02-architecture/03-LICENSE-LIFECYCLE.md
     * §3/§5). Suspended, Revoked, Expired, and Cancelled must never gain
     * (or regain, via a reset) a bound Installation — a first-time
     * activation attempt against those has no defined meaning.
     */
    public const ACTIVATABLE_STATUSES = ['unactivated', 'trial', 'active'];

    // ── Creation ─────────────────────────────────────────────────────────

    /**
     * Issue a new License. Generates and hashes a fresh raw key (reusing
     * LicensingAPI's existing key-generation convention — the raw key is
     * returned to the caller exactly once and never persisted, INV-05).
     *
     * $data keys: client_id, product_id, plan_id (nullable), label,
     * status (default 'unactivated'), starts_at (optional), expires_at
     * (nullable), warning_days (default 7), grace_days (default 7),
     * activation_limit (default 1), metadata (optional array), modules
     * (optional string[] of optional module keys to grant at issuance).
     *
     * @return array{id:int,license_key:string}
     */
    public static function issue(array $data, string $productSlug, ?int $actorId = null): array {
        $clientId  = (int) $data['client_id'];
        $productId = (int) $data['product_id'];
        $planId    = !empty($data['plan_id']) ? (int) $data['plan_id'] : null;

        if (Database::value('SELECT id FROM licensing_clients WHERE id = ?', [$clientId]) === null) {
            throw new \InvalidArgumentException('Unknown client.');
        }
        if (Database::value('SELECT id FROM licensing_products WHERE id = ?', [$productId]) === null) {
            throw new \InvalidArgumentException('Unknown product.');
        }
        if ($planId !== null) {
            $plan = Database::row('SELECT product_id, is_active FROM licensing_plans WHERE id = ?', [$planId]);
            if ($plan === null || (int) $plan['product_id'] !== $productId) {
                throw new \InvalidArgumentException('The selected plan does not exist or does not belong to this product.');
            }
            if (empty($plan['is_active'])) {
                throw new \InvalidArgumentException('The selected plan is not active and cannot be used to issue new licenses.');
            }
        }

        $licenseKey = LicensingAPI::generateLicenseKey($productSlug);

        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $id = Database::insert('licensing_licenses', [
                'client_id'        => $clientId,
                'product_id'       => $productId,
                'plan_id'          => $planId,
                'label'            => (string) ($data['label'] ?? ''),
                'license_key_hash' => hash('sha256', $licenseKey),
                'status'           => (string) ($data['status'] ?? 'unactivated'),
                'starts_at'        => $data['starts_at'] ?? slate_db_now(),
                'expires_at'       => !empty($data['expires_at']) ? $data['expires_at'] : null,
                'warning_days'     => (int) ($data['warning_days'] ?? 7),
                'grace_days'       => (int) ($data['grace_days'] ?? 7),
                'activation_limit' => max(1, (int) ($data['activation_limit'] ?? 1)),
                'metadata_json'    => isset($data['metadata']) ? (string) json_encode($data['metadata']) : null,
            ]);

            if (!empty($data['modules'])) {
                self::grantModulesInTransaction($id, (array) $data['modules']);
            }

            self::recordEventInTransaction($id, 'create', $actorId !== null ? 'admin' : 'system', $actorId, null, null);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return ['id' => $id, 'license_key' => $licenseKey];
    }

    public static function find(int $licenseId): ?array {
        return Database::row('SELECT * FROM licensing_licenses WHERE id = ?', [$licenseId]);
    }

    public static function findByKeyHash(string $keyHash): ?array {
        return Database::row('SELECT * FROM licensing_licenses WHERE license_key_hash = ?', [$keyHash]);
    }

    // ── Entitlements ─────────────────────────────────────────────────────

    /**
     * Grant one or more optional modules to a License. Rejects Core keys
     * outright (INV-06) — Core is never represented as a row. Idempotent:
     * a module already granted is left as-is, not duplicated or errored.
     *
     * @param string[] $moduleKeys
     * @throws \InvalidArgumentException if a Core key is included
     */
    public static function grantModules(int $licenseId, array $moduleKeys): void {
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            self::grantModulesInTransaction($licenseId, $moduleKeys);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function grantModulesInTransaction(int $licenseId, array $moduleKeys): void {
        foreach ($moduleKeys as $key) {
            $key = (string) $key;
            if (in_array($key, self::CORE_MODULE_KEYS, true)) {
                throw new \InvalidArgumentException(
                    "'$key' is a Core module — Core is implicit and must never be an explicit license_modules row (INV-06)."
                );
            }
            $exists = Database::value(
                'SELECT id FROM licensing_license_modules WHERE license_id = ? AND module_key = ?',
                [$licenseId, $key]
            );
            if (!$exists) {
                Database::insert('licensing_license_modules', ['license_id' => $licenseId, 'module_key' => $key]);
            }
        }
    }

    public static function revokeModule(int $licenseId, string $moduleKey): void {
        Database::delete('licensing_license_modules', 'license_id = ? AND module_key = ?', [$licenseId, $moduleKey]);
    }

    /** @return string[] the optional modules currently granted (Core is never included — it's implicit). */
    public static function modules(int $licenseId): array {
        return array_column(
            Database::rows('SELECT module_key FROM licensing_license_modules WHERE license_id = ? ORDER BY module_key', [$licenseId]),
            'module_key'
        );
    }

    /**
     * Whether a License's Core entitlements (admin-user, dashboard,
     * site-settings) are currently accessible. Core has no row to check —
     * it is implicit whenever the license is commercially valid.
     *
     * Deliberately minimal: this checks only status + expiry, NOT the
     * Warning/Grace/Locked timeline (docs/02-architecture/
     * 08-EXPIRY-GRACE-OFFLINE.md, a later phase). "Valid" here means
     * Active or Trial and not yet past `expires_at`.
     */
    public static function hasCoreAccess(array $license): bool {
        $status = (string) $license['status'];
        if (!in_array($status, ['active', 'trial'], true)) return false;
        if (!empty($license['expires_at']) && strtotime((string) $license['expires_at']) <= time()) return false;
        return true;
    }

    // ── Lifecycle ────────────────────────────────────────────────────────

    /**
     * Statuses a License must currently be in for `activate()`/`reset()` to
     * bind (or rebind) an Installation to it (docs/02-architecture/
     * 03-LICENSE-LIFECYCLE.md §5) — enforced here, at the domain layer, so
     * InstallationService::activate()/reset() cannot be used to bind an
     * Installation to a Suspended, Revoked, Expired, or Cancelled license
     * regardless of what any admin-UI check does or doesn't do.
     */
    public static function assertActivatable(array $license): void {
        if (!in_array($license['status'], self::ACTIVATABLE_STATUSES, true)) {
            throw new \InvalidArgumentException(
                "Cannot bind an installation to a license in status '{$license['status']}' (docs/02-architecture/03-LICENSE-LIFECYCLE.md §5)."
            );
        }
    }

    /**
     * Unactivated -> Active, triggered by a first successful Installation
     * binding (docs/02-architecture/03-LICENSE-LIFECYCLE.md §3). Idempotent
     * no-op if the license is not currently Unactivated — a subsequent
     * check-in from the same, already-bound Installation is a Refresh, not
     * a re-Activate (§4.1 of that document), and InstallationService calls
     * this defensively on every bind.
     *
     * Deliberately does not record its own audit event: the only callers
     * are InstallationService::activate()/reset(), which record a single
     * 'activate' event carrying the installation-specific metadata — this
     * method existing separately would otherwise double that event.
     */
    public static function activate(int $licenseId, ?int $actorId = null): void {
        $license = self::find($licenseId);
        if ($license === null) throw new \InvalidArgumentException('Unknown license.');
        if ($license['status'] !== 'unactivated') return;

        Database::update('licensing_licenses', ['status' => 'active'], 'id = ?', [$licenseId]);
    }

    /** Valid from: active, trial, expired (docs/03 §3). */
    public static function suspend(int $licenseId, ?int $actorId, string $reason): void {
        $license = self::requireStatus($licenseId, ['active', 'trial', 'expired'], 'suspend');
        Database::update('licensing_licenses', ['status' => 'suspended'], 'id = ?', [$licenseId]);
        self::recordEvent($licenseId, 'suspend', $actorId !== null ? 'admin' : 'system', $actorId, $reason);
    }

    public static function unsuspend(int $licenseId, ?int $actorId, ?string $reason = null): void {
        self::requireStatus($licenseId, ['suspended'], 'unsuspend');
        Database::update('licensing_licenses', ['status' => 'active'], 'id = ?', [$licenseId]);
        self::recordEvent($licenseId, 'activate', $actorId !== null ? 'admin' : 'system', $actorId, $reason);
    }

    /** Valid from: any non-terminal state (docs/03 §3) — Revoked is terminal. */
    public static function revoke(int $licenseId, ?int $actorId, string $reason): void {
        self::requireStatus($licenseId, self::NON_TERMINAL_STATUSES, 'revoke');
        Database::update('licensing_licenses', ['status' => 'revoked', 'revoke_reason' => $reason], 'id = ?', [$licenseId]);
        self::recordEvent($licenseId, 'revoke', $actorId !== null ? 'admin' : 'system', $actorId, $reason);
    }

    /**
     * New commercial period — valid from Expired (including within or past
     * grace), per docs/03 §3. Sets a new `expires_at` and returns to Active.
     */
    public static function renew(int $licenseId, string $newExpiresAt, ?int $actorId = null, ?string $reason = null): void {
        $license = self::requireStatus($licenseId, ['expired'], 'renew');
        self::assertFutureExpiry($newExpiresAt);
        Database::update('licensing_licenses', ['status' => 'active', 'expires_at' => $newExpiresAt], 'id = ?', [$licenseId]);
        self::recordEvent($licenseId, 'renew', $actorId !== null ? 'admin' : 'system', $actorId, $reason, [
            'previous_expires_at' => $license['expires_at'], 'new_expires_at' => $newExpiresAt,
        ]);
    }

    /**
     * Push `expires_at` forward without treating it as a new commercial
     * period (e.g. a goodwill extension) — valid from Active or Expired,
     * per docs/03 §3. Same schema effect as Renew, distinct audit intent.
     */
    public static function extend(int $licenseId, string $newExpiresAt, ?int $actorId = null, ?string $reason = null): void {
        $license = self::requireStatus($licenseId, ['active', 'trial', 'expired'], 'extend');
        self::assertFutureExpiry($newExpiresAt);
        Database::update('licensing_licenses', ['status' => 'active', 'expires_at' => $newExpiresAt], 'id = ?', [$licenseId]);
        self::recordEvent($licenseId, 'extend', $actorId !== null ? 'admin' : 'system', $actorId, $reason, [
            'previous_expires_at' => $license['expires_at'], 'new_expires_at' => $newExpiresAt,
        ]);
    }

    /**
     * No state transition — re-reads the current state, whatever it is
     * (including expired/suspended/revoked, docs/03 §3's F-02 correction).
     * Recorded as its own event type for observability, attributed to the
     * admin who triggered it when there is one (rather than always
     * recording 'system', which would misattribute an admin-initiated
     * refresh).
     */
    public static function refresh(int $licenseId, ?int $actorId = null): array {
        $license = self::find($licenseId);
        if ($license === null) throw new \InvalidArgumentException('Unknown license.');
        self::recordEvent($licenseId, 'refresh', $actorId !== null ? 'admin' : 'system', $actorId);
        return $license;
    }

    /**
     * Parse a `Y-m-d` date string (as an HTML `<input type="date">` sends)
     * into a `Y-m-d 00:00:00` timestamp. A client can submit any string
     * regardless of the input's declared type, so this is validated here
     * rather than trusted and passed straight through to the database.
     */
    public static function parseDate(string $raw): string {
        $raw = trim($raw);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            throw new \InvalidArgumentException('Invalid date.');
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \InvalidArgumentException('Invalid date.');
        }
        return $raw . ' 00:00:00';
    }

    /** Renew/Extend must always move `expires_at` into the future. */
    private static function assertFutureExpiry(string $newExpiresAt): void {
        $ts = strtotime($newExpiresAt);
        if ($ts === false || $ts <= time()) {
            throw new \InvalidArgumentException('The new expiry date must be in the future.');
        }
    }

    /**
     * Lazily persists the Active/Trial -> Expired transition for a single
     * License once its `expires_at` has passed (docs/02-architecture/
     * 03-LICENSE-LIFECYCLE.md §2: "expires_at reached" is a data-level
     * transition, not merely lazy enforcement at read time) — this is what
     * makes Renew reachable in practice, since `renew()` requires the
     * *stored* status to already be Expired. Called opportunistically
     * whenever an admin views a License (admin/license.php) so this does
     * not depend solely on `sweepExpired()`'s daily cron cadence. Returns
     * the (possibly updated) license row.
     */
    public static function syncExpiry(int $licenseId): array {
        $license = self::find($licenseId);
        if ($license === null) throw new \InvalidArgumentException('Unknown license.');
        if (!in_array($license['status'], ['active', 'trial'], true)) return $license;
        if (empty($license['expires_at']) || strtotime((string) $license['expires_at']) > time()) return $license;

        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $affected = Database::update(
                'licensing_licenses', ['status' => 'expired'],
                "id = ? AND status IN ('active', 'trial')", [$licenseId]
            );
            if ($affected > 0) {
                self::recordEventInTransaction($licenseId, 'expire', 'system', null);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return self::find($licenseId);
    }

    /**
     * Sweeps every License whose `expires_at` has passed but is still
     * stored as Active/Trial, transitioning each to Expired. Registered on
     * `daily_cron` (Licensing::boot()) — mirrors
     * `\Slate\Services\Licensing\LicenseService::sweepExpired()`'s existing
     * `daily_cron` registration (config.php) for the unrelated per-tenant
     * license system, so a License nobody happens to view in the admin
     * still becomes reachable for Renew.
     *
     * @return int number of licenses transitioned to Expired
     */
    public static function sweepExpired(): int {
        $ids = array_column(Database::rows(
            "SELECT id FROM licensing_licenses WHERE status IN ('active', 'trial') AND expires_at IS NOT NULL AND expires_at <= ?",
            [slate_db_now()]
        ), 'id');

        $count = 0;
        foreach ($ids as $licenseId) {
            $updated = self::syncExpiry((int) $licenseId);
            if ($updated['status'] === 'expired') $count++;
        }
        return $count;
    }

    private static function requireStatus(int $licenseId, array $allowed, string $operation): array {
        $license = self::find($licenseId);
        if ($license === null) throw new \InvalidArgumentException('Unknown license.');
        if (!in_array($license['status'], $allowed, true)) {
            throw new \InvalidArgumentException(
                "Cannot $operation a license in status '{$license['status']}' (docs/02-architecture/03-LICENSE-LIFECYCLE.md §3/§5)."
            );
        }
        return $license;
    }

    // ── Audit trail (INV-08) ─────────────────────────────────────────────

    public static function recordEvent(
        int $licenseId, string $eventType, string $actorType, ?int $actorId = null,
        ?string $reason = null, ?array $metadata = null
    ): int {
        $pdo = Database::get();
        if ($pdo->inTransaction()) {
            return self::recordEventInTransaction($licenseId, $eventType, $actorType, $actorId, $reason, $metadata);
        }
        $pdo->beginTransaction();
        try {
            $id = self::recordEventInTransaction($licenseId, $eventType, $actorType, $actorId, $reason, $metadata);
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function recordEventInTransaction(
        int $licenseId, string $eventType, string $actorType, ?int $actorId = null,
        ?string $reason = null, ?array $metadata = null
    ): int {
        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            throw new \InvalidArgumentException("Unknown license event_type '$eventType'.");
        }
        if (!in_array($actorType, ['admin', 'system'], true)) {
            throw new \InvalidArgumentException("Unknown license event actor_type '$actorType'.");
        }
        return Database::insert('licensing_license_events', [
            'license_id'    => $licenseId,
            'event_type'    => $eventType,
            'actor_type'    => $actorType,
            'actor_id'      => $actorType === 'admin' ? $actorId : null,
            'reason'        => $reason,
            'metadata_json' => $metadata !== null ? (string) json_encode($metadata) : null,
        ]);
    }

    /** @return array[] events for a license, most recent first. */
    public static function events(int $licenseId): array {
        return Database::rows(
            'SELECT * FROM licensing_license_events WHERE license_id = ? ORDER BY created_at DESC, id DESC',
            [$licenseId]
        );
    }
}
