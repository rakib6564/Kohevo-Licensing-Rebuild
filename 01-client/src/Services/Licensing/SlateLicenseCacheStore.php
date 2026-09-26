<?php
/**
 * Kohevo storage adapter for RemoteLicenseClient over remote_license_cache.
 * The table stores only the latest successfully verified remote state.
 *
 * Phase 10 (docs/02-architecture/10-CLIENT-LICENSING-DATABASE-DESIGN.md
 * §3–§4, 12-SECURITY-ARCHITECTURE.md §2.1/§2.7): the row also keeps the
 * exact signed envelope (raw_payload + raw_signature). Every read
 * re-verifies that signature against LICENSE_SERVER_PUBLIC_KEY and builds
 * the trusted state from the signed bytes alone — the unpacked columns
 * (status, expires_at, entitlements, …) are a convenience copy that must
 * agree with the signed payload, never a source of trust on their own. A
 * direct database edit of any of them is detected and fails closed.
 */

declare(strict_types=1);

namespace Slate\Services\Licensing;

require_once dirname(__DIR__, 3) . '/plugins/licensing/client/LicenseCacheStoreInterface.php';
require_once dirname(__DIR__, 3) . '/plugins/licensing/client/LicenseSignatureVerifier.php';

final class SlateLicenseCacheStore implements \LicenseCacheStoreInterface {

    /**
     * QA Fix Round 1 (Phase 4, Fix 2): the canonical Installation ID format
     * — lowercase hex, exactly 32 characters. Kept as this class's own
     * constant (rather than a cross-namespace reference) so it has no
     * load-order dependency on InstallationService.
     */
    private const INSTALLATION_ID_PATTERN = '/^[a-f0-9]{32}$/';

    private string $publicKey;

    /**
     * @param ?string $publicKey base64 Ed25519 public key to verify cached
     *        payloads against; defaults to LICENSE_SERVER_PUBLIC_KEY — the
     *        same key RemoteLicenseClient verified them with on write. With
     *        no usable key, nothing in the cache can be trusted.
     */
    public function __construct(private int $tenantId, ?string $publicKey = null) {
        $this->publicKey = $publicKey ?? (function_exists('env') ? (string) env('LICENSE_SERVER_PUBLIC_KEY', '') : '');
    }

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
     * from "a cache row exists but failed trust verification". load() alone
     * conflates both into null -- correct for entitlement consumers, but
     * WRONG for the Guard, which must never read an untrusted row as "not
     * yet configured".
     *
     * QA Fix Round 1 (Phase 4, Fix 4): NOTHING is grandfathered into trust
     * merely because it predates a phase. A row is trusted only when ALL of
     * the following hold, each checked independently:
     *
     *   1. its installation_id column is exactly 32 lowercase hex and equals
     *      this install's own InstallationService::currentInstallationId();
     *   2. (Phase 10) raw_signature verifies over raw_payload with the
     *      configured public key — a row with no signed payload (written
     *      before migration 0026, or inserted by hand) is untrusted;
     *   3. (Phase 10) the signed payload is well-formed and its OWN
     *      installation_id equals the local one (11 §2 / 10 §4 — a genuine
     *      payload copied from another installation still fails here);
     *   4. (Phase 10) every unpacked column agrees with the signed payload.
     *
     * `data` is then built from the signed payload only. `fetched_at` is
     * the earlier of the local write time and the payload's own signed
     * checked_at, so editing fetched_at forward cannot extend offline
     * tolerance past what the Central Server itself signed.
     *
     * `reason` (additive, diagnostics only) names why a found row was not
     * trusted.
     *
     * @return array{found:bool,trusted:bool,data:?array,reason:?string}
     */
    public function readTrustState(): array {
        // anti-drift-ignore: TENANT — explicit tenant constructor parameter
        $row = \Database::row('SELECT * FROM remote_license_cache WHERE tenant_id = ?', [$this->tenantId]);
        if (!$row) {
            return ['found' => false, 'trusted' => false, 'data' => null, 'reason' => null];
        }

        $cachedInstallationId = $row['installation_id'] ?? null;
        if (!is_string($cachedInstallationId) || $cachedInstallationId === ''
            || preg_match(self::INSTALLATION_ID_PATTERN, $cachedInstallationId) !== 1) {
            // Covers NULL, '' (never treated as equivalent to NULL -- both
            // simply fail this same check independently), and any
            // malformed/tampered value uniformly.
            return self::untrusted('installation_mismatch');
        }

        $localInstallationId = \Slate\Services\Installation\InstallationService::currentInstallationId();
        if ($localInstallationId === null || !hash_equals($localInstallationId, $cachedInstallationId)) {
            return self::untrusted('installation_mismatch');
        }

        if (!is_string($row['raw_payload'] ?? null) || $row['raw_payload'] === ''
            || !is_string($row['raw_signature'] ?? null) || $row['raw_signature'] === '') {
            return self::untrusted('unsigned');
        }
        if (!$this->signatureValid($row['raw_payload'], $row['raw_signature'])) {
            return self::untrusted('signature_invalid');
        }

        $signed = self::decodeSignedState($row['raw_payload']);
        if ($signed === null) {
            return self::untrusted('malformed_payload');
        }
        if (!hash_equals($localInstallationId, $signed['installation_id'])) {
            return self::untrusted('installation_mismatch');
        }
        if (!self::columnsMatch($row, $signed)) {
            return self::untrusted('tampered');
        }

        $signedCheckedAt = CommercialLicenseWindow::parseUtc($signed['checked_at']);
        $writtenAt = CommercialLicenseWindow::parseUtc(is_string($row['fetched_at'] ?? null) ? $row['fetched_at'] : null);
        $fetchedAt = $writtenAt === null ? $signedCheckedAt : min($writtenAt, $signedCheckedAt);

        $data = [
            'status' => $signed['status'],
            'plan' => $signed['plan'],
            'entitlements' => $signed['entitlements'],
            'expires_at' => $signed['expires_at'],
            'fetched_at' => gmdate('Y-m-d H:i:s', $fetchedAt),
            'installation_id' => $signed['installation_id'],
            'remote_checked_at' => gmdate('Y-m-d H:i:s', $signedCheckedAt),
            'next_check_after' => $signed['next_check_after'],
        ];
        foreach (['warning_days', 'grace_days'] as $field) {
            if ($signed[$field] !== null) $data[$field] = $signed[$field];
        }
        return ['found' => true, 'trusted' => true, 'data' => $data, 'reason' => null];
    }

    /**
     * Persist a verified check-in. Everything stored is derived from the
     * signed `raw_payload` in $status — the parsed fields RemoteLicenseClient
     * also passes are not trusted on their own — and the signature is
     * verified again here, so no code path can write a row whose unpacked
     * columns say something the Central Server did not sign.
     *
     * All-or-nothing, inside one transaction holding the tenant's cache row
     * lock: either the full new row (columns + raw envelope) is written or
     * the previous row is left exactly as it was. If the stored row already
     * holds a verified state for the same installation that the Central
     * Server signed LATER (its checked_at), the older state is refused with
     * \LicenseCacheStaleException — two overlapping check-ins can never
     * leave the cache holding the older of the two answers.
     */
    public function save(array $status): void {
        $rawPayload = $status['raw_payload'] ?? null;
        $rawSignature = $status['raw_signature'] ?? null;
        if (!is_string($rawPayload) || $rawPayload === '' || !is_string($rawSignature) || $rawSignature === '') {
            throw new \InvalidArgumentException('A license cache entry must carry the signed payload it was derived from.');
        }
        if (!$this->signatureValid($rawPayload, $rawSignature)) {
            throw new \InvalidArgumentException('Refusing to cache a license state whose signature does not verify.');
        }
        $signed = self::decodeSignedState($rawPayload);
        if ($signed === null) {
            throw new \InvalidArgumentException('Refusing to cache a malformed signed license state.');
        }

        $data = [
            'status' => $signed['status'],
            'plan' => $signed['plan'],
            'entitlements' => json_encode($signed['entitlements']),
            'expires_at' => self::dbDateTime($signed['expires_at']),
            'installation_id' => $signed['installation_id'],
            'fetched_at' => self::dbDateTime($status['fetched_at'] ?? null) ?? \slate_db_now(),
            'remote_checked_at' => self::dbDateTime($signed['checked_at']),
            'next_check_after' => $signed['next_check_after'] !== null ? max(0, $signed['next_check_after']) : null,
            'signature_valid' => 1,
            'raw_payload' => $rawPayload,
            'raw_signature' => $rawSignature,
            'verified_at' => \slate_db_now(),
        ];
        $newCheckedAt = (int) CommercialLicenseWindow::parseUtc($signed['checked_at']);

        $pdo = \Database::get();
        $ownsTransaction = !$pdo->inTransaction();
        for ($attempt = 1; ; $attempt++) {
            try {
                if ($ownsTransaction) $pdo->beginTransaction();
                // anti-drift-ignore: TENANT — explicit tenant constructor parameter
                $existing = \Database::row(
                    'SELECT id, raw_payload, raw_signature FROM remote_license_cache WHERE tenant_id = ? FOR UPDATE',
                    [$this->tenantId]
                );
                if ($existing) {
                    $prior = is_string($existing['raw_payload'] ?? null) && is_string($existing['raw_signature'] ?? null)
                        && $this->signatureValid($existing['raw_payload'], $existing['raw_signature'])
                        ? self::decodeSignedState($existing['raw_payload']) : null;
                    if ($prior !== null && $prior['installation_id'] === $signed['installation_id']
                        && (int) CommercialLicenseWindow::parseUtc($prior['checked_at']) > $newCheckedAt) {
                        throw new \LicenseCacheStaleException('A later signed license state is already cached.');
                    }
                    \Database::update('remote_license_cache', $data, 'id = ?', [$existing['id']]);
                } else {
                    \Database::insert('remote_license_cache', $data + ['tenant_id' => $this->tenantId]);
                }
                if ($ownsTransaction) $pdo->commit();
                return;
            } catch (\Throwable $e) {
                if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
                // Two first-ever writes racing each other: one INSERT loses
                // on uniq_remote_license_cache_tenant (or the gap lock
                // deadlocks). Retry once — the retry sees the winner's row
                // and goes through the ordered UPDATE path above.
                $sqlState = $e instanceof \PDOException ? (string) $e->getCode() : '';
                if ($ownsTransaction && $attempt === 1 && in_array($sqlState, ['23000', '40001'], true)) continue;
                throw $e;
            }
        }
    }

    /** @return array{found:bool,trusted:bool,data:null,reason:string} */
    private static function untrusted(string $reason): array {
        return ['found' => true, 'trusted' => false, 'data' => null, 'reason' => $reason];
    }

    private function signatureValid(string $payload, string $signature): bool {
        try {
            return (new \LicenseSignatureVerifier($this->publicKey))->verify($payload, $signature);
        } catch (\Throwable $e) {
            return false; // missing or malformed configured public key
        }
    }

    /**
     * The signed check-in payload (11-LICENSING-API-CONTRACT.md §2),
     * decoded and shape-checked with the same rules RemoteLicenseClient
     * applies on receipt, or null if it is not a usable state at all.
     *
     * @return ?array{installation_id:string,status:string,plan:?string,entitlements:string[],
     *                expires_at:?string,checked_at:string,next_check_after:?int,
     *                warning_days:?int,grace_days:?int}
     */
    private static function decodeSignedState(string $rawPayload): ?array {
        $p = json_decode($rawPayload, true);
        if (!is_array($p)) return null;

        $installationId = $p['installation_id'] ?? null;
        if (!is_string($installationId) || preg_match(self::INSTALLATION_ID_PATTERN, $installationId) !== 1) return null;
        if (!is_string($p['status'] ?? null) || $p['status'] === '') return null;
        if (!is_string($p['checked_at'] ?? null) || CommercialLicenseWindow::parseUtc($p['checked_at']) === null) return null;

        $entitlements = $p['entitlements'] ?? [];
        if (!is_array($entitlements) || !array_is_list($entitlements)) return null;
        foreach ($entitlements as $key) {
            if (!is_string($key)) return null;
        }
        foreach (['plan', 'expires_at'] as $field) {
            if (isset($p[$field]) && !is_string($p[$field])) return null;
        }
        // A present expiry must be a real date: never silently read as "no
        // expiry" (CommercialLicenseWindow's own malformed rule, 08 §2).
        if (isset($p['expires_at']) && trim($p['expires_at']) !== '' && CommercialLicenseWindow::parseUtc($p['expires_at']) === null) return null;
        foreach (['next_check_after', 'warning_days', 'grace_days'] as $field) {
            if (isset($p[$field]) && !is_int($p[$field])) return null;
        }

        return [
            'installation_id' => $installationId,
            'status' => $p['status'],
            'plan' => $p['plan'] ?? null,
            'entitlements' => $entitlements,
            'expires_at' => $p['expires_at'] ?? null,
            'checked_at' => $p['checked_at'],
            'next_check_after' => $p['next_check_after'] ?? null,
            'warning_days' => $p['warning_days'] ?? null,
            'grace_days' => $p['grace_days'] ?? null,
        ];
    }

    /**
     * Whether every unpacked column still says exactly what the signed
     * payload says. Any disagreement means the row was edited after it was
     * written (the only writer, save(), derives all of them from the
     * payload) and the whole row is untrusted.
     */
    private static function columnsMatch(array $row, array $signed): bool {
        if (!is_string($row['status'] ?? null) || $row['status'] !== $signed['status']) return false;
        if (($row['plan'] ?? null) !== $signed['plan']) return false;
        if ($row['installation_id'] !== $signed['installation_id']) return false;

        $entitlements = is_string($row['entitlements'] ?? null) ? json_decode($row['entitlements'], true) : null;
        if ($entitlements !== $signed['entitlements']) return false;

        if (self::sameInstant($row['expires_at'] ?? null, $signed['expires_at']) === false) return false;
        if (self::sameInstant($row['remote_checked_at'] ?? null, $signed['checked_at']) === false) return false;

        $storedNext = isset($row['next_check_after']) ? (int) $row['next_check_after'] : null;
        $signedNext = $signed['next_check_after'] !== null ? max(0, $signed['next_check_after']) : null;
        return $storedNext === $signedNext;
    }

    /** Both absent, or both the same UTC second. A present-but-unreadable value on either side never matches. */
    private static function sameInstant(mixed $stored, ?string $signed): bool {
        $storedEmpty = $stored === null || (is_string($stored) && trim($stored) === '');
        $signedEmpty = $signed === null || trim($signed) === '';
        if ($storedEmpty || $signedEmpty) return $storedEmpty && $signedEmpty;
        $a = CommercialLicenseWindow::parseUtc($stored);
        $b = CommercialLicenseWindow::parseUtc($signed);
        return $a !== null && $b !== null && $a === $b;
    }

    private static function dbDateTime(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') return null;
        $timestamp = CommercialLicenseWindow::parseUtc((string) $value);
        return $timestamp === null ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }
}
