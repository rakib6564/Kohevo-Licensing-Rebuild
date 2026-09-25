<?php
/**
 * Kohevo storage adapter for RemoteLicenseClient over remote_license_cache.
 * The table stores only the latest successfully verified remote state.
 */

declare(strict_types=1);

namespace Slate\Services\Licensing;

require_once dirname(__DIR__, 3) . '/plugins/licensing/client/LicenseCacheStoreInterface.php';

final class SlateLicenseCacheStore implements \LicenseCacheStoreInterface {

    /**
     * QA Fix Round 1 (Phase 4, Fix 2): the canonical Installation ID format
     * — lowercase hex, exactly 32 characters. Kept as this class's own
     * constant (rather than a cross-namespace reference) so it has no
     * load-order dependency on InstallationService.
     */
    private const INSTALLATION_ID_PATTERN = '/^[a-f0-9]{32}$/';

    public function __construct(private int $tenantId) {}

    /**
     * The trusted-state-or-null contract every existing caller
     * (EntitlementService) already relies on: both "no cache row" and "a
     * cache row exists but failed trust verification" collapse to null
     * here, because both mean the identical thing to an entitlement
     * consumer -- "there is nothing to grant access from". See
     * readTrustState() for the one caller (slate_license_gate()) that MUST
     * distinguish the two.
     */
    public function load(): ?array {
        $state = $this->readTrustState();
        return $state['trusted'] ? $state['data'] : null;
    }

    /**
     * QA Fix Round 1 (Phase 4, Fix 1): distinguishes "no cache row at all"
     * from "a cache row exists but failed installation-identity trust
     * verification". load() alone conflates both into null -- correct for
     * entitlement consumers, but WRONG for slate_license_gate(), which
     * previously treated any null as "not yet configured" and could let a
     * request through even though an untrusted (installation-id-mismatched)
     * cache row genuinely existed. A caller that needs to react differently
     * to those two cases (only slate_license_gate() today) reads this
     * instead of load().
     *
     * QA Fix Round 1 (Phase 4, Fix 4): NOTHING is grandfathered into trust
     * merely because it predates this phase. A cache row's installation_id
     * must be present, non-empty, exactly 32 lowercase hex characters
     * (never merely "truthy"), AND equal to this install's own
     * InstallationService::currentInstallationId() — a NULL, an empty
     * string, or a malformed value on the row are each independently
     * rejected here (not folded into one "falsy" branch), and every
     * rejection is reported as `trusted => false`, never silently upgraded
     * to `found => false`. A legacy/pre-migration row is therefore
     * `found => true, trusted => false` — it requires a fresh, verified
     * check-in before it is ever read as commercial state again, exactly
     * like a row an attacker tampered with.
     *
     * @return array{found:bool,trusted:bool,data:?array}
     */
    public function readTrustState(): array {
        // anti-drift-ignore: TENANT — explicit tenant constructor parameter
        $row = \Database::row('SELECT * FROM remote_license_cache WHERE tenant_id = ?', [$this->tenantId]);
        if (!$row) {
            return ['found' => false, 'trusted' => false, 'data' => null];
        }

        $cachedInstallationId = $row['installation_id'] ?? null;
        if (!is_string($cachedInstallationId) || $cachedInstallationId === ''
            || preg_match(self::INSTALLATION_ID_PATTERN, $cachedInstallationId) !== 1) {
            // Covers NULL, '' (never treated as equivalent to NULL -- both
            // simply fail this same check independently), and any
            // malformed/tampered value uniformly.
            return ['found' => true, 'trusted' => false, 'data' => null];
        }

        $localInstallationId = \Slate\Services\Installation\InstallationService::currentInstallationId();
        if ($localInstallationId === null || !hash_equals($localInstallationId, $cachedInstallationId)) {
            return ['found' => true, 'trusted' => false, 'data' => null];
        }

        return ['found' => true, 'trusted' => true, 'data' => [
            'status' => (string) $row['status'],
            'plan' => $row['plan'],
            'entitlements' => json_decode((string) $row['entitlements'], true) ?: [],
            'expires_at' => $row['expires_at'],
            'fetched_at' => $row['fetched_at'],
            'installation_id' => $cachedInstallationId,
            'remote_checked_at' => $row['remote_checked_at'] ?? null,
            'next_check_after' => isset($row['next_check_after']) ? (int) $row['next_check_after'] : null,
        ]];
    }

    public function save(array $status): void {
        $installationId = $status['installation_id'] ?? null;
        $data = [
            'status' => (string) ($status['status'] ?? 'unknown'),
            'plan' => $status['plan'] ?? null,
            'entitlements' => json_encode($status['entitlements'] ?? []),
            'expires_at' => $status['expires_at'] ?? null,
            // QA Fix Round 1 (Fix 4): only ever persist a well-formed value;
            // anything else is stored as NULL, never as the raw (possibly
            // malformed or empty-string) input -- '' is never written as a
            // stand-in for "no id".
            'installation_id' => is_string($installationId) && $installationId !== ''
                && preg_match(self::INSTALLATION_ID_PATTERN, $installationId) === 1
                ? $installationId : null,
            'fetched_at' => self::dbDateTime($status['fetched_at'] ?? null) ?? \slate_db_now(),
            'remote_checked_at' => self::dbDateTime($status['remote_checked_at'] ?? null),
            'next_check_after' => isset($status['next_check_after']) ? max(0, (int) $status['next_check_after']) : null,
        ];
        // anti-drift-ignore: TENANT — explicit tenant constructor parameter
        $existingId = \Database::value('SELECT id FROM remote_license_cache WHERE tenant_id = ?', [$this->tenantId]);
        if ($existingId) {
            \Database::update('remote_license_cache', $data, 'id = ?', [$existingId]);
        } else {
            $data['tenant_id'] = $this->tenantId;
            \Database::insert('remote_license_cache', $data);
        }
    }

    private static function dbDateTime(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') return null;
        $timestamp = strtotime((string) $value);
        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }
}
