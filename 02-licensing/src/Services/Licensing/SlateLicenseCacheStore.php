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
 */

declare(strict_types=1);

namespace Slate\Services\Licensing;

require_once dirname(__DIR__, 3) . '/plugins/licensing/client/LicenseCacheStoreInterface.php';

final class SlateLicenseCacheStore implements \LicenseCacheStoreInterface {

    public function __construct(private int $tenantId) {}

    public function load(): ?array {
        // anti-drift-ignore: TENANT — reads the specific tenant this store was constructed for, an explicit constructor parameter, not the caller's ambient current_tenant_id()
        $row = \Database::row('SELECT * FROM remote_license_cache WHERE tenant_id = ?', [$this->tenantId]);
        if (!$row) return null;

        return [
            'status'       => (string) $row['status'],
            'plan'         => $row['plan'],
            'entitlements' => json_decode((string) $row['entitlements'], true) ?: [],
            'expires_at'   => $row['expires_at'],
            'fetched_at'   => $row['fetched_at'],
        ];
    }

    public function save(array $status): void {
        $data = [
            'status'       => (string) ($status['status'] ?? 'unknown'),
            'plan'         => $status['plan'] ?? null,
            'entitlements' => json_encode($status['entitlements'] ?? []),
            'expires_at'   => $status['expires_at'] ?? null,
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
