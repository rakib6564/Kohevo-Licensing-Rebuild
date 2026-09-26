<?php
/**
 * Test-only Central Server signing stand-in (Phase 10).
 *
 * SlateLicenseCacheStore trusts a cache row only when its raw_payload is
 * signed by the configured LICENSE_SERVER_PUBLIC_KEY and bound to this
 * installation (docs/02-architecture/10-CLIENT-LICENSING-DATABASE-DESIGN.md
 * §4). Suites that need a trusted snapshot therefore seed one the same way
 * the real Central Server produces it: a payload in the 11-LICENSING-API-
 * CONTRACT.md §2 shape, signed with a fixed test keypair whose public half
 * the probes receive as LICENSE_SERVER_PUBLIC_KEY.
 *
 * The keypair is derived from a fixed, test-only seed so every process
 * (the test runner and each probe subprocess) agrees on it. It exists only
 * under tests/ and is never read by application code.
 */

declare(strict_types=1);

function license_test_secret_key(): string {
    static $secret = null;
    if ($secret === null) {
        $pair = sodium_crypto_sign_seed_keypair(hash('sha256', 'kohevo-phase10-test-only-signing-seed', true));
        $secret = sodium_crypto_sign_secretkey($pair);
    }
    return $secret;
}

function license_test_public_key(): string {
    return base64_encode(sodium_crypto_sign_publickey_from_secretkey(license_test_secret_key()));
}

/** `LICENSE_SERVER_PUBLIC_KEY=... ` for a probe subprocess command line. */
function license_test_env_prefix(): string {
    return 'LICENSE_SERVER_PUBLIC_KEY=' . escapeshellarg(license_test_public_key()) . ' ';
}

function license_test_sign(string $payload): string {
    return base64_encode(sodium_crypto_sign_detached($payload, license_test_secret_key()));
}

/**
 * The signed check-in payload for a snapshot described in the cache's own
 * vocabulary ({status, plan, entitlements, expires_at, fetched_at,
 * installation_id, remote_checked_at?, next_check_after?}). The signed
 * checked_at defaults to fetched_at, so a snapshot seeded as "fetched 8
 * days ago" is also signed 8 days ago. An absent installation_id stays
 * absent from the payload.
 */
function license_test_payload(array $fields): string {
    $payload = [];
    if (array_key_exists('installation_id', $fields) && $fields['installation_id'] !== null) {
        $payload['installation_id'] = $fields['installation_id'];
    }
    $checkedAt = $fields['remote_checked_at'] ?? $fields['fetched_at'] ?? null;
    $payload += [
        'status' => $fields['status'] ?? 'active',
        'plan' => $fields['plan'] ?? null,
        'entitlements' => $fields['entitlements'] ?? [],
        'expires_at' => $fields['expires_at'] ?? null,
        'warning_days' => 7,
        'grace_days' => 7,
        'checked_at' => $checkedAt !== null
            ? (new DateTimeImmutable((string) $checkedAt, new DateTimeZone('UTC')))->format('c')
            : gmdate('c'),
        'next_check_after' => $fields['next_check_after'] ?? 86400,
    ];
    return (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
}

/** $fields plus the raw_payload/raw_signature pair SlateLicenseCacheStore::save() requires. */
function license_test_signed_state(array $fields): array {
    $payload = license_test_payload($fields);
    return $fields + ['raw_payload' => $payload, 'raw_signature' => license_test_sign($payload)];
}

/**
 * Replace this tenant's cache row with a validly signed snapshot. The row
 * is cleared first: suites seed arbitrary states back to back, which is not
 * the out-of-order write the store's ordering check exists to refuse.
 */
function license_test_seed_cache(int $tenantId, array $fields): void {
    Database::query('DELETE FROM remote_license_cache WHERE tenant_id = ?', [$tenantId]);
    (new \Slate\Services\Licensing\SlateLicenseCacheStore($tenantId, license_test_public_key()))
        ->save(license_test_signed_state($fields));
}
