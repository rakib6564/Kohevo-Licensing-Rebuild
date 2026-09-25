<?php
/**
 * Kohevo's storage adapter for RemoteLicenseClient — the small,
 * product-specific glue over remote_license_cache (0022 migration).
 * Everything reusable lives in plugins/licensing/client/; this file is
 * the ~30 lines each product writes for itself.
 *
 * Deliberately NOT the same table as the local `licenses` table
 * (Slate\Services\Licensing\LicenseService) — this is a cache of the last
 * verified copy of REMOTE state, not a thing a local admin edits, and
 * keeping the two separate avoids conflating "what the server last
 * confirmed" with "what was locally set" (see the chat design discussion
 * this was built from).
 *
 * QA Fix Round 1 (Phase 4, Fix 5): kept in installation_id-verification
 * parity with the 01-client copy of this class — same trust rules, same
 * readTrustState() shape — so the two never diverge in security behavior.
 * Pre-existing drift between the two copies unrelated to installation_id
 * (this file's flatter save() shape, its lack of remote_checked_at/
 * next_check_after) predates this phase and is left as-is; only the
 * Phase 4 installation_id behavior is synchronized here.
 */

declare(strict_types=1);

namespace Slate\Services\Licensing;

require_once dirname(__DIR__, 3) . '/plugins/licensing/client/LicenseCacheStoreInterface.php';

final class SlateLicenseCacheStore implements \LicenseCacheStoreInterface {

    /**
     * The canonical Installation ID format — lowercase hex, exactly 32
     * characters. Kept identical to the 01-client copy's constant.
     */
    private const INSTALLATION_ID_PATTERN = '/^[a-f0-9]{32}$/';

    public function __construct(private int $tenantId) {}

    public function load(): ?array {
        $state = $this->readTrustState();
        return $state['trusted'] ? $state['data'] : null;
    }

    /**
     * See the 01-client copy of this method for the full rationale (Fix 1:
     * distinguishes "no cache row" from "an untrusted cache row" for
     * slate_license_gate(); Fix 4: NULL/empty/malformed installation_id is
     * never grandfathered into trust).
     *
     * @return array{found:bool,trusted:bool,data:?array}
     */
    public function readTrustState(): array {
        // anti-drift-ignore: TENANT — reads the specific tenant this store was constructed for, an explicit constructor parameter, not the caller's ambient current_tenant_id()
        $row = \Database::row('SELECT * FROM remote_license_cache WHERE tenant_id = ?', [$this->tenantId]);
        if (!$row) {
            return ['found' => false, 'trusted' => false, 'data' => null];
        }

        $cachedInstallationId = $row['installation_id'] ?? null;
        if (!is_string($cachedInstallationId) || $cachedInstallationId === ''
            || preg_match(self::INSTALLATION_ID_PATTERN, $cachedInstallationId) !== 1) {
            return ['found' => true, 'trusted' => false, 'data' => null];
        }

        $localInstallationId = \Slate\Services\Installation\InstallationService::currentInstallationId();
        if ($localInstallationId === null || !hash_equals($localInstallationId, $cachedInstallationId)) {
            return ['found' => true, 'trusted' => false, 'data' => null];
        }

        return ['found' => true, 'trusted' => true, 'data' => [
            'status'       => (string) $row['status'],
            'plan'         => $row['plan'],
            'entitlements' => json_decode((string) $row['entitlements'], true) ?: [],
            'expires_at'   => $row['expires_at'],
            'fetched_at'   => $row['fetched_at'],
            'installation_id' => $cachedInstallationId,
        ]];
    }

    public function save(array $status): void {
        $installationId = $status['installation_id'] ?? null;
        $data = [
            'status'       => (string) ($status['status'] ?? 'unknown'),
            'plan'         => $status['plan'] ?? null,
            'entitlements' => json_encode($status['entitlements'] ?? []),
            'expires_at'   => $status['expires_at'] ?? null,
            'installation_id' => is_string($installationId) && $installationId !== ''
                && preg_match(self::INSTALLATION_ID_PATTERN, $installationId) === 1
                ? $installationId : null,
            'fetched_at'   => (string) ($status['fetched_at'] ?? \slate_db_now()),
        ];

        // anti-drift-ignore: TENANT — writes the specific tenant this store was constructed for, an explicit constructor parameter, not the caller's ambient current_tenant_id()
        $existingId = \Database::value('SELECT id FROM remote_license_cache WHERE tenant_id = ?', [$this->tenantId]);
        if ($existingId) {
            \Database::update('remote_license_cache', $data, 'id = ?', [$existingId]);
        } else {
            $data['tenant_id'] = $this->tenantId;
            \Database::insert('remote_license_cache', $data);
        }
    }
}
