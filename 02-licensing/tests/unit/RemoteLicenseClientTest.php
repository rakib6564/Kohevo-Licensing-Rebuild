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
        'install_id'  => 'install-uuid-1',
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
        'installation_id' => 'install-uuid-1',
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
    assert_eq('install-uuid-1', $store->saved['installation_id']);
});

unit('RemoteLicenseClient::checkIn(): Phase 4 (D14) -- a validly signed payload whose installation_id does NOT match this install\'s own install_id is rejected and never touches the cache', function () {
    $keypair = LicensingAPI::generateSigningKeypair();
    // Genuinely valid signature, genuinely valid shape -- exactly what a
    // cloned raw_payload/raw_signature pair lifted from a DIFFERENT,
    // legitimately-licensed installation would look like.
    $envelope = rlc_signed_envelope($keypair, [
        'installation_id' => 'some-other-installs-id',
        'status' => 'active', 'plan' => 'pro', 'entitlements' => ['white_label'],
        'expires_at' => null, 'checked_at' => '2026-09-21T00:00:00Z', 'next_check_after' => 86400,
    ]);
    $store = new FakeLicenseCacheStore();
    // rlc_config()'s install_id is 'install-uuid-1' -- deliberately different.
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
    assert_eq('install-uuid-1', $captured['body']['install_id']);
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
