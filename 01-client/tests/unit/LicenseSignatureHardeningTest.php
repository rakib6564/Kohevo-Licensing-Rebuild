<?php
/**
 * P0 regression: the client must never trust a signature derived from the
 * PUBLIC key (hmac:, or a bare keyed hash), with or without ext-sodium.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/licensing/client/LicenseSignatureVerifier.php';

unit('P0 client: forged hmac: / keyed-hash signatures built from the public key are rejected (sodium installed)', function (): void {
    $pair = sodium_crypto_sign_keypair();
    $pubRaw = sodium_crypto_sign_publickey($pair);
    $pub = base64_encode($pubRaw);
    $payload = json_encode(['status' => 'active', 'plan' => 'forged']);
    $v = new LicenseSignatureVerifier($pub);

    $real = base64_encode(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair)));
    assert_true($v->verify($payload, $real), 'a genuine Ed25519 signature still verifies');
    assert_false($v->verify($payload . 'x', $real), 'tampered payload');

    $mac = hash_hmac('sha256', $payload, $pubRaw, true);
    assert_false($v->verify($payload, 'hmac:' . base64_encode($mac)), 'forged hmac: must be rejected');
    assert_false($v->verify($payload, base64_encode($mac)), 'unprefixed keyed hash must be rejected');
    assert_false($v->verify($payload, 'hmac:' . $real), 'prefix on a real signature must be rejected');
    assert_false($v->verify($payload, base64_encode(str_repeat("\0", 64))), 'zero signature');
    assert_false($v->verify($payload, ''), 'empty');
});

unit('P0 client: without ext-sodium the verifier cannot be built and the forgery is not accepted (fail closed)', function (): void {
    $file = dirname(__DIR__, 2) . '/plugins/licensing/client/LicenseSignatureVerifier.php';
    $code = 'require ' . var_export($file, true) . ';'
        . '$pub=random_bytes(32); $pl="{}"; $r=[];'
        . 'try { new LicenseSignatureVerifier(base64_encode($pub)); $r["ctor"]="NO_THROW"; } catch (\RuntimeException $e) { $r["ctor"]="throws"; }'
        . 'echo json_encode($r);';
    $disabled = 'sodium_crypto_sign_verify_detached,sodium_crypto_sign_detached,sodium_crypto_sign_keypair';
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=' . $disabled . ' -r ' . escapeshellarg($code) . ' 2>&1');
    $r = json_decode(trim($out), true);
    assert_true(is_array($r), 'child output: ' . $out);
    assert_eq('throws', $r['ctor'] ?? null, 'constructing the verifier without sodium must throw');
});

unit('P0 client: the verifier source has no HMAC path', function (): void {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/licensing/client/LicenseSignatureVerifier.php');
    $code = (string) preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $src);
    assert_false(str_contains($code, 'hash_hmac'));
    assert_false(str_contains($code, "'hmac:'"));
});
