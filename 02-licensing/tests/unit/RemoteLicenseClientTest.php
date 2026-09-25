<?php
/**
 * Phase 4 of the remote license server build: RemoteLicenseClient.
 *
 * Pure, no DB, no network — the HTTP transport is injected as a callable
 * (RemoteLicenseClient's constructor accepts one to override the default
 * cURL implementation), so every response scenario the real server could
 * send back is exercised with a canned closure instead of a live server.
 * signing/verification itself reuses LicensingAPI::sign() from Phase 2 to
 * build genuinely valid signed envelopes, not hand-waved fixtures.
 *
 * The one invariant that matters more than any single scenario: on every
 * failure mode below, the fake cache store's save() must NEVER be called.
 * Silence — or a bad response — must never look like anything happened.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../plugins/licensing/LicensingAPI.php';
require_once __DIR__ . '/../../plugins/licensing/client/RemoteLicenseClient.php';

final class FakeLicenseCacheStore implements LicenseCacheStoreInterface {
    public ?array $saved = null;
    public function load(): ?array { return $this->saved; }
    public function save(array $status): void { $this->saved = $status; }
}

function rlc_config(string $publicKey): array {
    return [
        'server_url'  => 'https://license.example.test',
        'public_key'  => $publicKey,
        'product'     => 'kohevo',
        'license_key' => 'a-real-license-key',
        'install_id'  => str_repeat('1', 32),
        'domain'      => 'client.example',
        'app_version' => '1.0.0',
    ];
}

/** Builds a genuinely valid signed envelope, exactly matching what LicensingAPI::handleCheckIn() produces. */
function rlc_signed_envelope(array $keypair, array $statusFields): array {
    $payloadJson = json_encode($statusFields, JSON_UNESCAPED_SLASHES);
    $signature   = LicensingAPI::sign($payloadJson, $keypair['secret']);
    return ['payload' => $payloadJson, 'signature' => $signature];
}

unit('RemoteLicenseClient::checkIn(): a valid signed 200 response updates the cache store and returns true', function () {
    $keypair = LicensingAPI::generateSigningKeypair();
    $envelope = rlc_signed_envelope($keypair, [
        'installation_id' => str_repeat('1', 32),
        'status' => 'active', 'plan' => 'pro', 'entitlements' => ['white_label'],
        'expires_at' => null, 'checked_at' => '2026-09-21T00:00:00Z', 'next_check_after' => 86400,
    ]);
    $store = new FakeLicenseCacheStore();
    $client = new RemoteLicenseClient(rlc_config($keypair['public']), $store, function (string $url, string $body) use ($envelope) {
        return [200, json_encode($envelope)];
    });

    $ok = $client->checkIn();

    assert_true($ok);
    assert_true($store->saved !== null);
    assert_eq('active', $store->saved['status']);
    assert_eq('pro', $store->saved['plan']);
    assert_eq(['white_label'], $store->saved['entitlements']);
    assert_null($store->saved['expires_at']);
    assert_eq(str_repeat('1', 32), $store->saved['installation_id']);
});

unit('RemoteLicenseClient::checkIn(): Phase 4 (D14) -- a validly signed payload whose installation_id does NOT match this install\'s own install_id is rejected and never touches the cache', function () {
    $keypair = LicensingAPI::generateSigningKeypair();
    // Genuinely valid signature, genuinely valid shape -- exactly what a
    // cloned raw_payload/raw_signature pair lifted from a DIFFERENT,
    // legitimately-licensed installation would look like.
    $envelope = rlc_signed_envelope($keypair, [
        'installation_id' => str_repeat('2', 32),
        'status' => 'active', 'plan' => 'pro', 'entitlements' => ['white_label'],
        'expires_at' => null, 'checked_at' => '2026-09-21T00:00:00Z', 'next_check_after' => 86400,
    ]);
    $store = new FakeLicenseCacheStore();
    // rlc_config()'s install_id is str_repeat('1', 32) -- deliberately different.
    $client = new RemoteLicenseClient(rlc_config($keypair['public']), $store, function () use ($envelope) {
        return [200, json_encode($envelope)];
    });

    assert_false($client->checkIn());
    assert_null($store->saved);
});

unit('RemoteLicenseClient::checkIn(): a validly signed payload with no installation_id at all is rejected and never touches the cache', function () {
    $keypair = LicensingAPI::generateSigningKeypair();
    $envelope = rlc_signed_envelope($keypair, [
        'status' => 'active', 'plan' => 'pro', 'entitlements' => [],
    ]);
    $store = new FakeLicenseCacheStore();
    $client = new RemoteLicenseClient(rlc_config($keypair['public']), $store, function () use ($envelope) {
        return [200, json_encode($envelope)];
    });

    assert_false($client->checkIn());
    assert_null($store->saved);
});

unit('RemoteLicenseClient::checkIn(): QA Fix Round 1 (Fix 2D) -- a validly signed payload whose installation_id is well-formed-but-wrong is still rejected on the equality check', function () {
    $keypair = LicensingAPI::generateSigningKeypair();
    $envelope = rlc_signed_envelope($keypair, [
        'installation_id' => str_repeat('9', 32), // well-formed, just not THIS install's id
        'status' => 'active', 'plan' => 'pro', 'entitlements' => [],
    ]);
    $store = new FakeLicenseCacheStore();
    $client = new RemoteLicenseClient(rlc_config($keypair['public']), $store, function () use ($envelope) {
        return [200, json_encode($envelope)];
    });

    assert_false($client->checkIn());
    assert_null($store->saved);
});

unit('RemoteLicenseClient::checkIn(): QA Fix Round 1 (Fix 2D) -- a validly signed payload whose installation_id fails FORMAT (not the equality check) is rejected independently', function () {
    $keypair = LicensingAPI::generateSigningKeypair();
    // QA Fix Round 2 (Fix 3): strtoupper(str_repeat('1', 32)) is NOT a valid
    // uppercase fixture -- '1' has no case, so strtoupper() leaves it
    // byte-for-byte identical to a well-formed lowercase-hex id, meaning
    // this case never actually exercised format rejection at all. Use a
    // genuine uppercase-hex-letter fixture instead, so this scenario tests
    // FORMAT rejection independently of the equality check.
    foreach (['not-32-hex-chars', str_repeat('1', 31), str_repeat('1', 33), str_repeat('A', 32), ''] as $badId) {
        $envelope = rlc_signed_envelope($keypair, [
            'installation_id' => $badId,
            'status' => 'active', 'plan' => 'pro', 'entitlements' => [],
        ]);
        $store = new FakeLicenseCacheStore();
        $client = new RemoteLicenseClient(rlc_config($keypair['public']), $store, function () use ($envelope) {
            return [200, json_encode($envelope)];
        });

        assert_false($client->checkIn(), 'expected rejection for malformed installation_id=' . var_export($badId, true));
        assert_null($store->saved);
    }
});

unit('RemoteLicenseClient::checkIn(): QA Fix Round 1 (Fix 2D) -- a malformed LOCALLY-configured install_id is never treated as though it could legitimately match anything', function () {
    $keypair = LicensingAPI::generateSigningKeypair();
    // A genuinely well-formed, self-consistent server payload -- the only
    // thing wrong here is the CLIENT's own config.
    $envelope = rlc_signed_envelope($keypair, [
        'installation_id' => 'not-a-valid-local-id', // mirrors the malformed config below verbatim
        'status' => 'active', 'plan' => 'pro', 'entitlements' => [],
    ]);
    $store = new FakeLicenseCacheStore();
    $config = rlc_config($keypair['public']);
    $config['install_id'] = 'not-a-valid-local-id';
    $client = new RemoteLicenseClient($config, $store, function () use ($envelope) {
        return [200, json_encode($envelope)];
    });

    assert_false($client->checkIn(), 'a malformed local install_id must never match, even a payload carrying the identical malformed string');
    assert_null($store->saved);
});

unit('RemoteLicenseClient::checkIn(): the outgoing request body carries the configured product/key/install_id/domain', function () {
    $keypair = LicensingAPI::generateSigningKeypair();
    $captured = null;
    $store = new FakeLicenseCacheStore();
    $client = new RemoteLicenseClient(rlc_config($keypair['public']), $store, function (string $url, string $body) use (&$captured) {
        $captured = ['url' => $url, 'body' => json_decode($body, true)];
        return null; // don't care about the response for this test
    });

    $client->checkIn();

    assert_eq('https://license.example.test/licensing/check', $captured['url']);
    assert_eq('kohevo', $captured['body']['product']);
    assert_eq('a-real-license-key', $captured['body']['license_key']);
    assert_eq(str_repeat('1', 32), $captured['body']['install_id']);
    assert_eq('client.example', $captured['body']['domain']);
    assert_eq('1.0.0', $captured['body']['app_version']);
});

unit('RemoteLicenseClient::checkIn(): a transport failure (network down) returns false and never touches the cache', function () {
    $keypair = LicensingAPI::generateSigningKeypair();
    $store = new FakeLicenseCacheStore();
    $client = new RemoteLicenseClient(rlc_config($keypair['public']), $store, function () { return null; });

    assert_false($client->checkIn());
    assert_null($store->saved);
});

unit('RemoteLicenseClient::checkIn(): a non-200 status returns false and never touches the cache', function () {
    $keypair = LicensingAPI::generateSigningKeypair();
    $envelope = rlc_signed_envelope($keypair, ['status' => 'active']);
    $store = new FakeLicenseCacheStore();
    $client = new RemoteLicenseClient(rlc_config($keypair['public']), $store, function () use ($envelope) {
        return [500, json_encode($envelope)]; // even a validly-signed body doesn't matter if the status isn't 200
    });

    assert_false($client->checkIn());
    assert_null($store->saved);
});

unit('RemoteLicenseClient::checkIn(): a malformed envelope (missing payload/signature) returns false and never touches the cache', function () {
    $keypair = LicensingAPI::generateSigningKeypair();
    $store = new FakeLicenseCacheStore();
    $client = new RemoteLicenseClient(rlc_config($keypair['public']), $store, function () {
        return [200, json_encode(['unexpected' => 'shape'])];
    });

    assert_false($client->checkIn());
    assert_null($store->saved);
});

unit('RemoteLicenseClient::checkIn(): a response signed by a DIFFERENT keypair fails verification and never touches the cache', function () {
    $serverKeypair   = LicensingAPI::generateSigningKeypair();
    $attackerKeypair = LicensingAPI::generateSigningKeypair();
    $envelope = rlc_signed_envelope($attackerKeypair, ['status' => 'active', 'plan' => 'pro', 'entitlements' => []]);
    $store = new FakeLicenseCacheStore();
    // The client is configured with the REAL server's public key.
    $client = new RemoteLicenseClient(rlc_config($serverKeypair['public']), $store, function () use ($envelope) {
        return [200, json_encode($envelope)];
    });

    assert_false($client->checkIn());
    assert_null($store->saved);
});

unit('RemoteLicenseClient::checkIn(): a tampered payload (signature no longer matches) fails verification and never touches the cache', function () {
    $keypair = LicensingAPI::generateSigningKeypair();
    $envelope = rlc_signed_envelope($keypair, ['status' => 'active']);
    $envelope['payload'] = json_encode(['status' => 'suspended']); // tampered after signing
    $store = new FakeLicenseCacheStore();
    $client = new RemoteLicenseClient(rlc_config($keypair['public']), $store, function () use ($envelope) {
        return [200, json_encode($envelope)];
    });

    assert_false($client->checkIn());
    assert_null($store->saved);
});

unit('RemoteLicenseClient::checkIn(): a payload tampered to carry a DIFFERENT installation_id after signing fails verification and never touches the cache', function () {
    $keypair = LicensingAPI::generateSigningKeypair();
    $envelope = rlc_signed_envelope($keypair, [
        'installation_id' => str_repeat('1', 32), // matches rlc_config()'s install_id at signing time
        'status' => 'active', 'plan' => 'pro', 'entitlements' => [],
    ]);
    // Tampered AFTER signing -- the signature no longer matches this exact
    // byte string, so this is caught by signature verification itself, not
    // merely the installation_id equality check -- proving the two layers
    // are independently effective.
    $envelope['payload'] = json_encode([
        'installation_id' => str_repeat('9', 32), 'status' => 'active', 'plan' => 'pro', 'entitlements' => [],
    ]);
    $store = new FakeLicenseCacheStore();
    $client = new RemoteLicenseClient(rlc_config($keypair['public']), $store, function () use ($envelope) {
        return [200, json_encode($envelope)];
    });

    assert_false($client->checkIn());
    assert_null($store->saved);
});

unit('RemoteLicenseClient::checkIn(): a validly signed payload with no "status" key fails and never touches the cache', function () {
    $keypair = LicensingAPI::generateSigningKeypair();
    $envelope = rlc_signed_envelope($keypair, ['plan' => 'pro']); // signed, but malformed content
    $store = new FakeLicenseCacheStore();
    $client = new RemoteLicenseClient(rlc_config($keypair['public']), $store, function () use ($envelope) {
        return [200, json_encode($envelope)];
    });

    assert_false($client->checkIn());
    assert_null($store->saved);
});
