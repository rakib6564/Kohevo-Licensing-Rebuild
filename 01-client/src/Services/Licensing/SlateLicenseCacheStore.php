<?php
/**
 * Kohevo storage adapter for RemoteLicenseClient over remote_license_cache.
 * The table stores only the latest successfully verified remote state.
 */

declare(strict_types=1);

namespace Slate\Services\Licensing;

require_once dirname(__DIR__, 3) . '/plugins/licensing/client/LicenseCacheStoreInterface.php';

final class SlateLicenseCacheStore implements \LicenseCacheStoreInterface {
    public function __construct(private int $tenantId) {}

    /**
     * Phase 4 (docs/02-architecture/15-PHASE-1-DECISIONS.md D14,
     * 10-CLIENT-LICENSING-DATABASE-DESIGN.md §4): read-time re-assertion of
     * the same installation_id binding RemoteLicenseClient::checkIn()
     * already enforces at write time. A cached row whose installation_id
     * does not match this install's own installation_identity is treated
     * exactly as "no trusted state" (null) -- the same fail-closed result a
     * verification failure produces -- so every existing caller
     * (EntitlementService, slate_license_gate()) restricts automatically
     * with no change to their own logic.
     *
     * A cached row with NO installation_id on record (NULL) is passed
     * through unchanged rather than treated as a mismatch: that column is
     * new in this phase, so every row written before this fix -- and every
     * row a test fixture writes directly via save() without going through
     * the real check-in flow -- predates the concept entirely. It is not
     * exploitable going forward: after this fix, save() is only ever
     * reached once RemoteLicenseClient::checkIn() has already confirmed the
     * signed payload's installation_id matches locally, so an attacker
     * copying another installation's genuine cloned payload always copies a
     * *non-null*, and therefore checkable, installation_id.
     */
    public function load(): ?array {
        // anti-drift-ignore: TENANT — explicit tenant constructor parameter
        $row = \Database::row('SELECT * FROM remote_license_cache WHERE tenant_id = ?', [$this->tenantId]);
        if (!$row) return null;

        $cachedInstallationId = isset($row['installation_id']) ? (string) $row['installation_id'] : '';
        if ($cachedInstallationId !== '') {
            $localInstallationId = \Slate\Services\Installation\InstallationService::currentInstallationId();
            if ($localInstallationId === null || $cachedInstallationId !== $localInstallationId) {
                return null; // untrusted: treated exactly like a failed signature verification
            }
        }

        return [
            'status' => (string) $row['status'],
            'plan' => $row['plan'],
            'entitlements' => json_decode((string) $row['entitlements'], true) ?: [],
            'expires_at' => $row['expires_at'],
            'fetched_at' => $row['fetched_at'],
            'installation_id' => $cachedInstallationId !== '' ? $cachedInstallationId : null,
            'remote_checked_at' => $row['remote_checked_at'] ?? null,
            'next_check_after' => isset($row['next_check_after']) ? (int) $row['next_check_after'] : null,
        ];
    }

    public function save(array $status): void {
        $data = [
            'status' => (string) ($status['status'] ?? 'unknown'),
            'plan' => $status['plan'] ?? null,
            'entitlements' => json_encode($status['entitlements'] ?? []),
            'expires_at' => $status['expires_at'] ?? null,
            'installation_id' => isset($status['installation_id']) && is_string($status['installation_id']) && $status['installation_id'] !== ''
                ? $status['installation_id'] : null,
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
