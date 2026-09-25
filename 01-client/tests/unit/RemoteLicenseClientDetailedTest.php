<?php
/**
 * Phase 5 (client installer rebuild): RemoteLicenseClient::checkInDetailed().
 *
 * checkIn() itself (tested exhaustively elsewhere in this codebase's
 * companion product tree) collapses every failure mode to a single `false`
 * on purpose, because a routine/cron caller never needs to distinguish them.
 * The installer's License Key step is different: docs/02-architecture/
 * 05-INSTALLATION-ACTIVATION.md §5 explicitly wants the operator to see a
 * different message for "couldn't reach the server" than for "the server
 * rejected this". checkInDetailed() is the additive method that exposes
 * exactly that one coarse split without touching checkIn()'s own contract.
 *
 * Pure, no DB, no network — signing uses ext-sodium directly (01-client has
 * no LicensingAPI::sign(), by design: only the Central Server signs).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../plugins/licensing/client/RemoteLicenseClient.php';

final class RlcdFakeCacheStore implements LicenseCacheStoreInterface {
    public ?array $saved = null;
    public function load(): ?array { return $this->saved; }
    public function save(array $status): void { $this->saved = $status; }
}

/** @return array{public:string, secret:string} base64-encoded Ed25519 keys. */
function rlcd_keypair(): array {
    $pair = sodium_crypto_sign_keypair();
    return [
        'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
        'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
    ];
}

function rlcd_sign(string $payload, string $secretKeyB64): string {
    return base64_encode(sodium_crypto_sign_detached($payload, base64_decode($secretKeyB64, true)));
}

/** Builds a genuinely valid signed envelope using only ext-sodium. */
function rlcd_envelope(array $keypair, array $statusFields): array {
    $payloadJson = (string) json_encode($statusFields, JSON_UNESCAPED_SLASHES);
    return ['payload' => $payloadJson, 'signature' => rlcd_sign($payloadJson, $keypair['secret'])];
}

function rlcd_config(string $publicKey): array {
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

unit('RemoteLicenseClient::checkInDetailed(): a valid signed 200 response returns ok=true, reason=null', function () {
    $keypair = rlcd_keypair();
    $envelope = rlcd_envelope($keypair, [
        'installation_id' => str_repeat('1', 32),
        'status' => 'active', 'plan' => 'pro', 'entitlements' => ['forms'],
        'expires_at' => null, 'checked_at' => '2026-09-21T00:00:00Z', 'next_check_after' => 86400,
    ]);
    $store = new RlcdFakeCacheStore();
    $client = new RemoteLicenseClient(rlcd_config($keypair['public']), $store, function () use ($envelope) {
        return [200, json_encode($envelope)];
    });

    $result = $client->checkInDetailed();

    assert_true($result['ok']);
    assert_null($result['reason']);
    assert_true($store->saved !== null);
    // checkIn() itself must still return the exact same bool it always has.
    assert_true($client->checkIn());
});

unit('RemoteLicenseClient::checkInDetailed(): a transport failure (network down) is reason=network', function () {
    $keypair = rlcd_keypair();
    $store = new RlcdFakeCacheStore();
    $client = new RemoteLicenseClient(rlcd_config($keypair['public']), $store, function () { return null; });

    $result = $client->checkInDetailed();

    assert_false($result['ok']);
    assert_eq('network', $result['reason']);
    assert_null($store->saved);
    assert_false($client->checkIn());
});

unit('RemoteLicenseClient::checkInDetailed(): a non-200 status (e.g. activation_limit/invalid key) is reason=rejected, never network', function () {
    $keypair = rlcd_keypair();
    $envelope = rlcd_envelope($keypair, ['status' => 'active']);
    $store = new RlcdFakeCacheStore();
    $client = new RemoteLicenseClient(rlcd_config($keypair['public']), $store, function () use ($envelope) {
        return [403, json_encode(['error' => 'invalid_request'])];
    });

    $result = $client->checkInDetailed();

    assert_false($result['ok']);
    assert_eq('rejected', $result['reason']);
    assert_null($store->saved);
});

unit('RemoteLicenseClient::checkInDetailed(): a malformed envelope is reason=rejected', function () {
    $keypair = rlcd_keypair();
    $store = new RlcdFakeCacheStore();
    $client = new RemoteLicenseClient(rlcd_config($keypair['public']), $store, function () {
        return [200, json_encode(['unexpected' => 'shape'])];
    });

    $result = $client->checkInDetailed();

    assert_false($result['ok']);
    assert_eq('rejected', $result['reason']);
});

unit('RemoteLicenseClient::checkInDetailed(): a signature from a DIFFERENT keypair is reason=rejected', function () {
    $serverKeypair   = rlcd_keypair();
    $attackerKeypair = rlcd_keypair();
    $envelope = rlcd_envelope($attackerKeypair, ['status' => 'active', 'plan' => 'pro', 'entitlements' => []]);
    $store = new RlcdFakeCacheStore();
    $client = new RemoteLicenseClient(rlcd_config($serverKeypair['public']), $store, function () use ($envelope) {
        return [200, json_encode($envelope)];
    });

    $result = $client->checkInDetailed();

    assert_false($result['ok']);
    assert_eq('rejected', $result['reason']);
});

unit('RemoteLicenseClient::checkInDetailed(): Phase 4 (D14) — an installation_id mismatch is reason=rejected, not network', function () {
    $keypair = rlcd_keypair();
    // Genuinely valid signature, wrong installation_id — exactly a cloned
    // raw_payload/raw_signature pair from a different installation.
    $envelope = rlcd_envelope($keypair, [
        'installation_id' => str_repeat('2', 32),
        'status' => 'active', 'plan' => 'pro', 'entitlements' => [],
    ]);
    $store = new RlcdFakeCacheStore();
    // rlcd_config()'s install_id is str_repeat('1', 32) — deliberately different.
    $client = new RemoteLicenseClient(rlcd_config($keypair['public']), $store, function () use ($envelope) {
        return [200, json_encode($envelope)];
    });

    $result = $client->checkInDetailed();

    assert_false($result['ok']);
    assert_eq('rejected', $result['reason']);
    assert_null($store->saved);
});

unit('RemoteLicenseClient::checkInDetailed(): a tampered payload (signature no longer matches) is reason=rejected', function () {
    $keypair = rlcd_keypair();
    $envelope = rlcd_envelope($keypair, ['status' => 'active']);
    $envelope['payload'] = json_encode(['status' => 'suspended']); // tampered after signing
    $store = new RlcdFakeCacheStore();
    $client = new RemoteLicenseClient(rlcd_config($keypair['public']), $store, function () use ($envelope) {
        return [200, json_encode($envelope)];
    });

    $result = $client->checkInDetailed();

    assert_false($result['ok']);
    assert_eq('rejected', $result['reason']);
});
