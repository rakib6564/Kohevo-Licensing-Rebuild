<?php
/**
 * Phase 10: the framework-free RemoteLicenseClient's synchronization
 * contract, with an in-memory store and a fake transport (no DB, no
 * network). The database-backed behaviour of the same flow — against the
 * real Central Server and the real SlateLicenseCacheStore — is in
 * tests/integration/Phase10SynchronizationTest.php.
 *
 *   - the exact signed envelope (raw_payload/raw_signature) is handed to
 *     the store, so it can be re-verified at read time (10 §3–§4)
 *   - every rejection has a stable diagnostic category (lastFailure())
 *     while checkInDetailed() keeps its coarse network/rejected split
 *   - a store that refuses an older state, or fails outright, is reported
 *     as a failed check-in and never throws out of checkIn()
 */

declare(strict_types=1);

require_once __DIR__ . '/../../plugins/licensing/client/RemoteLicenseClient.php';

final class RlcsMemoryStore implements LicenseCacheStoreInterface {
    public ?array $saved = null;
    public ?\Throwable $throw = null;
    public function load(): ?array { return $this->saved; }
    public function save(array $status): void {
        if ($this->throw !== null) throw $this->throw;
        $this->saved = $status;
    }
}

function rlcs_keys(): array {
    static $pair = null;
    $pair ??= sodium_crypto_sign_keypair();
    return ['public' => base64_encode(sodium_crypto_sign_publickey($pair)), 'secret' => sodium_crypto_sign_secretkey($pair)];
}

function rlcs_payload(array $overrides = []): array {
    return $overrides + [
        'installation_id' => str_repeat('1', 32), 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['forms'],
        'expires_at' => '2030-01-01 00:00:00', 'warning_days' => 7, 'grace_days' => 7,
        'checked_at' => '2026-09-26T10:00:00+00:00', 'next_check_after' => 86400,
    ];
}

function rlcs_body(array $payload): string {
    $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
    return (string) json_encode(['payload' => $json, 'signature' => base64_encode(sodium_crypto_sign_detached($json, rlcs_keys()['secret']))]);
}

function rlcs_client(RlcsMemoryStore $store, callable $transport): RemoteLicenseClient {
    return new RemoteLicenseClient([
        'server_url' => 'https://license.example.test', 'public_key' => rlcs_keys()['public'], 'product' => 'kohevo',
        'license_key' => 'RAW-KEY-NEVER-STORED', 'install_id' => str_repeat('1', 32), 'domain' => 'client.example',
    ], $store, $transport);
}

unit('Phase 10 RemoteLicenseClient: the verbatim signed envelope reaches the store alongside the parsed fields, and no raw license key does', function () {
    $store = new RlcsMemoryStore();
    $body = rlcs_body(rlcs_payload());
    $client = rlcs_client($store, static fn() => [200, $body]);
    assert_true($client->checkIn());
    assert_null($client->lastFailure());
    $envelope = json_decode($body, true);
    assert_eq($envelope['payload'], $store->saved['raw_payload'], 'exact signed bytes, not a re-serialization');
    assert_eq($envelope['signature'], $store->saved['raw_signature']);
    assert_eq('active', $store->saved['status']);
    assert_eq('2026-09-26T10:00:00+00:00', $store->saved['remote_checked_at']);
    assert_false(str_contains((string) json_encode($store->saved), 'RAW-KEY-NEVER-STORED'));
});

unit('Phase 10 RemoteLicenseClient: each rejection has its own stable category; the store is never called', function () {
    $key = rlcs_keys();
    $foreignJson = (string) json_encode(rlcs_payload(), JSON_UNESCAPED_SLASHES);
    $cases = [
        'network'               => [static fn() => null, 'network'],
        'http 500'              => [static fn() => [500, '{"error":"server_error"}'], 'http_status'],
        'http 404'              => [static fn() => [404, '{"error":"invalid_request"}'], 'http_status'],
        'not json'              => [static fn() => [200, 'oops'], 'malformed_envelope'],
        'bad signature'         => [static fn() => [200, json_encode(['payload' => $foreignJson, 'signature' => base64_encode(str_repeat("\0", 64))])], 'signature_invalid'],
        'other installation'    => [static fn() => [200, rlcs_body(rlcs_payload(['installation_id' => str_repeat('2', 32)]))], 'installation_mismatch'],
        'no installation_id'    => [static fn() => [200, rlcs_body(array_diff_key(rlcs_payload(), ['installation_id' => 1]))], 'installation_mismatch'],
        'empty status'          => [static fn() => [200, rlcs_body(rlcs_payload(['status' => '']))], 'malformed_payload'],
        'no checked_at'         => [static fn() => [200, rlcs_body(array_diff_key(rlcs_payload(), ['checked_at' => 1]))], 'malformed_payload'],
        'garbage checked_at'    => [static fn() => [200, rlcs_body(rlcs_payload(['checked_at' => 'not a date']))], 'malformed_payload'],
        'entitlements object'   => [static fn() => [200, rlcs_body(rlcs_payload(['entitlements' => ['forms' => 1]]))], 'malformed_payload'],
        'plan not a string'     => [static fn() => [200, rlcs_body(rlcs_payload(['plan' => ['x']]))], 'malformed_payload'],
        'next_check not an int' => [static fn() => [200, rlcs_body(rlcs_payload(['next_check_after' => '86400']))], 'malformed_payload'],
    ];
    foreach ($cases as $label => [$transport, $category]) {
        $store = new RlcsMemoryStore();
        $client = rlcs_client($store, $transport);
        $detailed = $client->checkInDetailed();
        assert_false($detailed['ok'], $label);
        assert_eq($category === 'network' ? 'network' : 'rejected', $detailed['reason'], "$label: coarse reason unchanged");
        assert_eq($category, $client->lastFailure(), "$label: category");
        assert_null($store->saved, "$label: nothing saved");
    }
});

unit('Phase 10 RemoteLicenseClient: a store refusing an older state, or failing, is a failed check-in — never an exception', function () {
    $store = new RlcsMemoryStore();
    $store->throw = new LicenseCacheStaleException('newer state cached');
    $client = rlcs_client($store, static fn() => [200, rlcs_body(rlcs_payload())]);
    assert_eq(['ok' => false, 'reason' => 'rejected'], $client->checkInDetailed());
    assert_eq('stale_response', $client->lastFailure());

    $store->throw = new \PDOException('SQLSTATE[HY000]: gone away');
    assert_false($client->checkIn());
    assert_eq('cache_write_failed', $client->lastFailure());

    $store->throw = null;
    assert_true($client->checkIn(), 'the same client recovers on the next attempt');
    assert_null($client->lastFailure(), 'a success clears the previous failure category');
});
