<?php
/**
 * Licensing plugin — Installation domain service (Phase 2: Central
 * Licensing Platform Foundation).
 *
 * An Installation is the specific bound client deployment
 * (docs/02-architecture/02-CENTRAL-LICENSING-DOMAIN.md §1.5). Enforces
 * "1 License -> 1 Installation" (INV-03,
 * docs/00-project/REQUIREMENTS.md §7) against *currently-active* bindings
 * only, and never hard-deletes a binding on reset — it soft-deactivates
 * (`status = 'superseded'`, `deleted_at` set) so the full activation
 * history required by docs/00-project/REQUIREMENTS.md §8 is preserved
 * (docs/02-architecture/09-CENTRAL-DATABASE-DESIGN.md §7, decision D17).
 *
 * Scope note: this class is the *foundation* for what the public check-in
 * endpoint will eventually call (a later phase). It does not itself wire
 * into `LicensingAPI::handleCheckIn()` or any HTTP route — that endpoint
 * still operates on the pre-existing `licensing_installs`/
 * `licensing_installation_bindings` tables, unchanged, per
 * docs/02-architecture/13-MIGRATION-STRATEGY.md §4.
 */

declare(strict_types=1);

require_once __DIR__ . '/LicenseService.php';

class InstallationService {

    /**
     * QA Fix Round 1 (Phase 4, Fix 2): the canonical Installation ID format
     * — lowercase hex, exactly 32 characters (matches the 16-byte
     * `bin2hex(random_bytes(16))` generation convention both the client
     * installer and admin/license.php's own <input pattern> already assume).
     * Enforced here too, independently of admin/license.php's pre-check
     * (which already validates before ever calling activate()/reset()) —
     * this is the trust boundary for the OTHER caller of these methods,
     * the public check-in endpoint (LicensingAPI::handleCheckIn()), which
     * has no such UI-layer pre-check in front of it.
     */
    public const INSTALLATION_ID_PATTERN = '/^[a-f0-9]{32}$/';

    public static function isValidInstallationId(string $installationId): bool {
        return preg_match(self::INSTALLATION_ID_PATTERN, $installationId) === 1;
    }

    private static function assertValidInstallationId(string $installationId): void {
        if (!self::isValidInstallationId($installationId)) {
            throw new \InvalidArgumentException('Installation ID must be exactly 32 lowercase hex characters.');
        }
    }

    /**
     * Bind a new Installation to a License. Enforces `activation_limit`
     * against currently-active Installations only (a superseded prior
     * Installation never counts, INV-03) and transitions the License
     * Unactivated -> Active on its first successful binding.
     *
     * $data keys: installation_id (32-char), domain, domain_normalized
     * (optional), installed_version (optional), last_seen_ip (optional).
     *
     * @throws \InvalidArgumentException unknown license
     * @throws \RuntimeException 'activation_limit' if already at capacity
     */
    public static function activate(int $licenseId, array $data, ?int $actorId = null): int {
        self::assertValidInstallationId((string) ($data['installation_id'] ?? ''));
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            // Lock the License row so two concurrent activation attempts
            // for the same license serialize on this check — mirrors the
            // existing `SELECT ... FOR UPDATE` pattern in
            // LicensingAPI::handleCheckIn().
            $license = Database::row('SELECT * FROM licensing_licenses WHERE id = ? FOR UPDATE', [$licenseId]);
            if ($license === null) {
                throw new \InvalidArgumentException('Unknown license.');
            }
            LicenseService::assertActivatable($license);

            $activeCount = (int) Database::value(
                'SELECT COUNT(*) FROM licensing_installations WHERE license_id = ? AND status = ?',
                [$licenseId, 'active']
            );
            $limit = max(1, (int) $license['activation_limit']);
            if ($activeCount >= $limit) {
                throw new \RuntimeException('activation_limit');
            }

            $id = self::insertActiveInstallation($licenseId, $data);

            LicenseService::activate($licenseId, $actorId);
            LicenseService::recordEventInTransaction(
                $licenseId, 'activate', $actorId !== null ? 'admin' : 'system', $actorId, null,
                ['installation_id' => $id]
            );

            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Administrator-initiated reset: soft-deactivates the License's current
     * active Installation (`status = 'superseded'`, `deleted_at` set) and
     * binds a new one in the same transaction — never a hard delete
     * (docs/02-architecture/09-CENTRAL-DATABASE-DESIGN.md §7, D17). This is
     * the intended path for a legitimate re-install (server migration,
     * disaster recovery); always explicit and audited, never automatic
     * (docs/02-architecture/03-LICENSE-LIFECYCLE.md §4.2).
     *
     * Recorded as an 'activate' event, same as any other new binding — the
     * License itself never changes its own commercial status on a reset.
     */
    public static function reset(int $licenseId, array $newInstallationData, ?int $actorId, ?string $reason = null): int {
        self::assertValidInstallationId((string) ($newInstallationData['installation_id'] ?? ''));
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $license = Database::row('SELECT * FROM licensing_licenses WHERE id = ? FOR UPDATE', [$licenseId]);
            if ($license === null) {
                throw new \InvalidArgumentException('Unknown license.');
            }
            LicenseService::assertActivatable($license);

            Database::update(
                'licensing_installations',
                ['status' => 'superseded', 'deleted_at' => slate_db_now()],
                'license_id = ? AND status = ?',
                [$licenseId, 'active']
            );

            $id = self::insertActiveInstallation($licenseId, $newInstallationData);

            // A reset of a still-Unactivated license (no Installation was
            // ever successfully bound before) must still drive the License's
            // own Unactivated -> Active transition — otherwise it ends up
            // with a bound, active Installation while its own status row
            // still says 'unactivated' (an internally inconsistent state).
            // Idempotent no-op for the ordinary case where the license is
            // already Active.
            LicenseService::activate($licenseId, $actorId);

            LicenseService::recordEventInTransaction(
                $licenseId, 'activate', $actorId !== null ? 'admin' : 'system', $actorId, $reason,
                ['installation_id' => $id, 'reset' => true]
            );

            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Explicitly revoke a single Installation's binding without touching
     * the License's own commercial status (mirrors the existing
     * distinction that a binding-level action is operational, not
     * commercial — docs/02-architecture/02-CENTRAL-LICENSING-DOMAIN.md
     * §1.5). Does not free the activation slot for reuse by a different
     * Installation ID — use `reset()` for that.
     *
     * $expectedLicenseId, when given, must match the Installation's actual
     * `license_id` — this is what stops an admin viewing License A from
     * revoking an Installation that actually belongs to License B (an
     * IDOR: the installation_id is just a row id, not scoped to the
     * license being viewed). Rejects with no row modified and nothing
     * revoked when it doesn't match.
     */
    public static function revoke(int $installationId, ?int $expectedLicenseId = null): void {
        if ($expectedLicenseId !== null) {
            $installation = self::find($installationId);
            if ($installation === null || (int) $installation['license_id'] !== $expectedLicenseId) {
                throw new \InvalidArgumentException('That installation does not belong to this license.');
            }
        }
        Database::update('licensing_installations', ['status' => 'revoked'], 'id = ? AND status = ?', [$installationId, 'active']);
    }

    private static function insertActiveInstallation(int $licenseId, array $data): int {
        // Recomputed here, from the raw domain, rather than trusting a
        // caller-supplied `domain_normalized` — a caller must never be able
        // to store a NULL/stale normalized domain for a domain that fails
        // validation (docs/02-architecture/11-LICENSING-API-CONTRACT.md's
        // normalization rules, shared with the public check-in endpoint).
        $domain = (string) $data['domain'];
        $domainNormalized = LicensingAPI::normalizeDomain($domain);
        if ($domainNormalized === null) {
            throw new \InvalidArgumentException('Enter a valid domain (no path, query string, or credentials).');
        }

        return Database::insert('licensing_installations', [
            'license_id'        => $licenseId,
            'installation_id'   => (string) $data['installation_id'],
            'domain'            => $domain,
            'domain_normalized' => $domainNormalized,
            'installed_version' => $data['installed_version'] ?? null,
            'status'            => 'active',
            'last_seen_at'      => slate_db_now(),
            'last_seen_ip'      => $data['last_seen_ip'] ?? null,
        ]);
    }

    public static function find(int $installationId): ?array {
        return Database::row('SELECT * FROM licensing_installations WHERE id = ?', [$installationId]);
    }

    public static function findByInstallationId(string $installationId): ?array {
        return Database::row('SELECT * FROM licensing_installations WHERE installation_id = ?', [$installationId]);
    }

    /**
     * Phase 4: record an ordinary, unattended routine check-in from an
     * ALREADY-bound, active Installation — never a binding/activation event
     * (LicensingAPI::handleCommercialCheckIn() calls this, never activate(),
     * once an installation row is found to already exist and match). A
     * single guarded UPDATE is atomic on its own; the `status = 'active'`
     * clause means a row concurrently revoked by an admin between the
     * caller's own lookup and this call simply touches nothing rather than
     * reviving a revoked row.
     */
    public static function touch(int $installationRowId, ?string $appVersion, ?string $ip): void {
        $update = ['last_seen_at' => slate_db_now()];
        if ($appVersion !== null && $appVersion !== '') $update['installed_version'] = $appVersion;
        if ($ip !== null && $ip !== '') $update['last_seen_ip'] = $ip;
        Database::update('licensing_installations', $update, 'id = ? AND status = ?', [$installationRowId, 'active']);
    }

    public static function active(int $licenseId): ?array {
        return Database::row(
            "SELECT * FROM licensing_installations WHERE license_id = ? AND status = 'active'",
            [$licenseId]
        );
    }

    /** Full history for a License, including superseded rows — most recent first. */
    public static function history(int $licenseId): array {
        return Database::rows(
            'SELECT * FROM licensing_installations WHERE license_id = ? ORDER BY created_at DESC, id DESC',
            [$licenseId]
        );
    }
}
