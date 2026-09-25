<?php
/**
 * Phase 2 of the remote license server build: keypair storage.
 *
 * Sign()/verify() themselves are pure ext-sodium calls, tested without a DB
 * in tests/unit/LicensingSignatureTest.php. This file covers the part that
 * genuinely needs the database: storing the keypair, and specifically that
 * the secret key is never persisted in plain text — same at-rest
 * encryption (slate_encrypt_secret(), keyed off APP_SECRET) already used
 * for Stripe/Twilio/SMTP credentials elsewhere in this app.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/licensing/LicensingAPI.php';

function licsig_clear_keys(): void {
    Database::query("DELETE FROM settings WHERE setting_key IN ('licensing.signing_public_key', 'licensing.signing_secret_key')");
}

unit('licensing keypair: hasSigningKeypair() is false until a keypair is stored, true after', function () {
    licsig_clear_keys();
    try {
        assert_false(LicensingAPI::hasSigningKeypair());
        LicensingAPI::storeSigningKeypair(LicensingAPI::generateSigningKeypair());
        assert_true(LicensingAPI::hasSigningKeypair());
    } finally {
        licsig_clear_keys();
    }
});

unit('licensing keypair: the secret key is stored encrypted at rest, not in plain text', function () {
    licsig_clear_keys();
    try {
        $pair = LicensingAPI::generateSigningKeypair();
        LicensingAPI::storeSigningKeypair($pair);

        $rawStoredSecret = (string) Database::value(
            "SELECT setting_value FROM settings WHERE setting_key = ?", ['licensing.signing_secret_key']
        );
        assert_true(str_starts_with($rawStoredSecret, 'enc:v1:'), 'secret key must be stored through the enc:v1: envelope');
        assert_false(str_contains($rawStoredSecret, $pair['secret']), 'the raw secret key must never appear in the stored value');

        $rawStoredPublic = (string) Database::value(
            "SELECT setting_value FROM settings WHERE setting_key = ?", ['licensing.signing_public_key']
        );
        assert_eq($pair['public'], $rawStoredPublic, 'the public key is meant to be embedded elsewhere, so it is stored plainly');
    } finally {
        licsig_clear_keys();
    }
});

unit('licensing keypair: signingPublicKey()/signingSecretKey() round-trip back to the original values', function () {
    licsig_clear_keys();
    try {
        $pair = LicensingAPI::generateSigningKeypair();
        LicensingAPI::storeSigningKeypair($pair);

        assert_eq($pair['public'], LicensingAPI::signingPublicKey());
        assert_eq($pair['secret'], LicensingAPI::signingSecretKey());
    } finally {
        licsig_clear_keys();
    }
});

unit('licensing keypair: a full sign -> store -> retrieve -> verify round trip using the STORED keys, not the in-memory ones', function () {
    licsig_clear_keys();
    try {
        LicensingAPI::storeSigningKeypair(LicensingAPI::generateSigningKeypair());

        $payload = json_encode(['status' => 'active', 'checked_at' => '2026-09-21T10:15:00Z']);
        $signature = LicensingAPI::sign($payload, (string) LicensingAPI::signingSecretKey());

        assert_true(LicensingAPI::verify($payload, $signature, (string) LicensingAPI::signingPublicKey()));
    } finally {
        licsig_clear_keys();
    }
});

unit('licensing keypair: signingPublicKey()/signingSecretKey() return null when nothing is stored yet', function () {
    licsig_clear_keys();
    assert_null(LicensingAPI::signingPublicKey());
    assert_null(LicensingAPI::signingSecretKey());
});
