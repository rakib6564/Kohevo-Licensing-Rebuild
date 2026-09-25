<?php
/**
 * Phase 2 of the remote license server build: Ed25519 signing.
 *
 * Pure round-trip tests — no DB, no config.php (LicensingAPI::sign()/
 * verify() and LicenseSignatureVerifier are deliberately just ext-sodium
 * calls with no framework dependency; only key STORAGE touches the
 * database, covered separately in tests/integration).
 *
 * The two halves are tested as genuinely separate code paths on purpose:
 * LicensingAPI (server, holds the secret key) signs; LicenseSignatureVerifier
 * (client, holds only the public key) verifies — proving the private key
 * is never required, and never even referenced, on the verifying side.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../plugins/licensing/LicensingAPI.php';
require_once __DIR__ . '/../../plugins/licensing/client/LicenseSignatureVerifier.php';

unit('LicensingAPI::sign()/verify(): a payload signed with the secret key verifies with only the public key', function () {
    $pair = LicensingAPI::generateSigningKeypair();
    $payload = json_encode(['status' => 'active', 'expires_at' => '2027-01-15T00:00:00Z']);
    $signature = LicensingAPI::sign($payload, $pair['secret']);
    assert_true(LicensingAPI::verify($payload, $signature, $pair['public']));
});

unit('LicensingAPI::verify(): a tampered payload fails verification', function () {
    $pair = LicensingAPI::generateSigningKeypair();
    $payload = json_encode(['status' => 'active']);
    $signature = LicensingAPI::sign($payload, $pair['secret']);
    $tampered = json_encode(['status' => 'suspended']);
    assert_false(LicensingAPI::verify($tampered, $signature, $pair['public']));
});

unit('LicensingAPI::verify(): a signature from a DIFFERENT keypair fails against this public key', function () {
    $pairA = LicensingAPI::generateSigningKeypair();
    $pairB = LicensingAPI::generateSigningKeypair();
    $payload = json_encode(['status' => 'active']);
    $signatureFromB = LicensingAPI::sign($payload, $pairB['secret']);
    assert_false(LicensingAPI::verify($payload, $signatureFromB, $pairA['public']));
});

unit('LicensingAPI::verify(): malformed signature/key input fails closed, never throws', function () {
    $pair = LicensingAPI::generateSigningKeypair();
    assert_false(LicensingAPI::verify('payload', 'not-valid-base64!!!', $pair['public']));
    assert_false(LicensingAPI::verify('payload', $pair['public'] /* wrong length */, $pair['public']));
});

unit('LicenseSignatureVerifier (the standalone client-side class) verifies a signature made by LicensingAPI using ONLY the public key', function () {
    $pair = LicensingAPI::generateSigningKeypair();
    $payload = json_encode(['status' => 'active', 'plan' => 'pro', 'next_check_after' => 86400]);
    $signature = LicensingAPI::sign($payload, $pair['secret']);

    // The verifier is constructed with nothing but the public key — it has
    // no way to reach the secret key even if it wanted to.
    $verifier = new LicenseSignatureVerifier($pair['public']);
    assert_true($verifier->verify($payload, $signature));
    assert_false($verifier->verify($payload . 'x', $signature), 'a modified payload must fail');
});

unit('LicenseSignatureVerifier: rejects a malformed public key at construction time', function () {
    assert_throws(\InvalidArgumentException::class, function () {
        new LicenseSignatureVerifier('not-a-real-key');
    });
});

unit('LicenseSignatureVerifier: verify() fails closed on a malformed signature rather than throwing', function () {
    $pair = LicensingAPI::generateSigningKeypair();
    $verifier = new LicenseSignatureVerifier($pair['public']);
    assert_false($verifier->verify('payload', 'short'));
});
