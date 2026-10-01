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

// ── P0 hardening: no HMAC / keyed-hash path may ever authenticate a licence ──

/** Runs $code in a child PHP with every sodium_* function disabled. @return array<string,mixed> */
function sig_run_without_sodium(string $code): array {
    $disabled = 'sodium_crypto_sign_detached,sodium_crypto_sign_verify_detached,sodium_crypto_sign_keypair,'
        . 'sodium_crypto_sign_publickey,sodium_crypto_sign_secretkey,sodium_crypto_sign_seed_keypair';
    $cmd = escapeshellarg(PHP_BINARY) . ' -d disable_functions=' . $disabled . ' -r ' . escapeshellarg($code) . ' 2>&1';
    $out = (string) shell_exec($cmd);
    $json = json_decode(trim($out), true);
    return is_array($json) ? $json : ['raw' => $out];
}

unit('P0: a forged "hmac:" signature made from the PUBLIC key alone is rejected (sodium installed) — client and Central', function () {
    $pair = LicensingAPI::generateSigningKeypair();
    $payload = json_encode(['status' => 'active', 'plan' => 'forged']);
    $pub = base64_decode($pair['public'], true);
    $forged = 'hmac:' . base64_encode(hash_hmac('sha256', $payload, $pub, true));
    $raw = base64_encode(hash_hmac('sha256', $payload, $pub, true));

    $verifier = new LicenseSignatureVerifier($pair['public']);
    assert_false($verifier->verify($payload, $forged), 'client must reject forged hmac:');
    assert_false($verifier->verify($payload, $raw), 'client must reject an unprefixed keyed-hash signature');
    assert_false(LicensingAPI::verify($payload, $forged, $pair['public']), 'Central verify must reject forged hmac:');
    assert_false(LicensingAPI::verify($payload, $raw, $pair['public']), 'Central verify must reject an unprefixed keyed-hash signature');
});

unit('P0: only an exact 64-byte Ed25519 signature verifies — wrong lengths and prefixes are rejected', function () {
    $pair = LicensingAPI::generateSigningKeypair();
    $payload = '{"status":"active"}';
    $good = LicensingAPI::sign($payload, $pair['secret']);
    $v = new LicenseSignatureVerifier($pair['public']);
    assert_true($v->verify($payload, $good));
    $bytes = base64_decode($good, true);
    assert_false($v->verify($payload, base64_encode(substr($bytes, 0, 63))), '63 bytes');
    assert_false($v->verify($payload, base64_encode($bytes . 'x')), '65 bytes');
    assert_false($v->verify($payload, 'hmac:' . $good), 'any scheme prefix');
    assert_false($v->verify($payload, ''), 'empty');
});

unit('P0: sign() returns Ed25519 or throws — never a substitute signature', function () {
    $pair = LicensingAPI::generateSigningKeypair();
    $sig = LicensingAPI::sign('payload', $pair['secret']);
    assert_false(str_starts_with($sig, 'hmac:'));
    assert_eq(SODIUM_CRYPTO_SIGN_BYTES, strlen((string) base64_decode($sig, true)));
    // A 32-byte value (the old no-sodium pseudo secret) is not a signing key.
    assert_throws(\InvalidArgumentException::class, fn() => LicensingAPI::sign('payload', base64_encode(random_bytes(32))));
    assert_throws(\InvalidArgumentException::class, fn() => LicensingAPI::sign('payload', 'not base64 !!'));
});

unit('P0: without ext-sodium Central cannot sign or generate keys, and nothing verifies (fail closed)', function () {
    $root = dirname(__DIR__, 2) . '/plugins/licensing';
    $code = 'require ' . var_export($root . '/LicensingAPI.php', true) . ';'
        . 'require ' . var_export($root . '/client/LicenseSignatureVerifier.php', true) . ';'
        . '$r=[]; $pub=base64_encode(random_bytes(32)); $pl="{}";'
        . '$forged="hmac:".base64_encode(hash_hmac("sha256",$pl,base64_decode($pub),true));'
        . '$raw=base64_encode(hash_hmac("sha256",$pl,base64_decode($pub),true));'
        . '$r["available"]=LicensingAPI::sodiumAvailable();'
        . 'foreach (["keypair"=>fn()=>LicensingAPI::generateSigningKeypair(),"sign"=>fn()=>LicensingAPI::sign($pl,base64_encode(random_bytes(64))),"verifier"=>fn()=>new LicenseSignatureVerifier($pub)] as $k=>$f) {'
        . ' try { $f(); $r[$k]="NO_THROW"; } catch (\\RuntimeException $e) { $r[$k]="throws"; } }'
        . '$r["central_verify_forged"]=LicensingAPI::verify($pl,$forged,$pub); $r["central_verify_raw"]=LicensingAPI::verify($pl,$raw,$pub);'
        . 'echo json_encode($r);';
    $r = sig_run_without_sodium($code);
    assert_eq(false, $r['available'] ?? null, 'sodium must read as unavailable in the child: ' . json_encode($r));
    assert_eq('throws', $r['keypair'] ?? null);
    assert_eq('throws', $r['sign'] ?? null);
    assert_eq('throws', $r['verifier'] ?? null);
    assert_eq(false, $r['central_verify_forged'] ?? null);
    assert_eq(false, $r['central_verify_raw'] ?? null);
});

unit('P0: no licensing source path derives a signature from hash_hmac / the public key', function () {
    $root = dirname(__DIR__, 2) . '/plugins/licensing';
    foreach (['/LicensingAPI.php', '/client/LicenseSignatureVerifier.php', '/client/RemoteLicenseClient.php'] as $f) {
        $src = (string) file_get_contents($root . $f);
        $code = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $src);
        assert_false(str_contains((string) $code, 'hash_hmac'), "$f must not call hash_hmac");
        assert_false(str_contains((string) $code, "'hmac:'"), "$f must not mint or special-case hmac: signatures");
    }
});
