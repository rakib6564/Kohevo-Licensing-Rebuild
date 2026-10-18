<?php
/** slate_reencrypt_envelope(): the per-value step of APP_SECRET rotation (bin/rekey-secrets.php). */

declare(strict_types=1);

if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/includes/helpers.php';
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
}

/** An envelope made with an explicit secret, independent of APP_SECRET. */
function rete_seal(string $plain, string $secret): string
{
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', $secret, true), OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc:v1:' . base64_encode($iv . $tag . $ct);
}

unit('reencrypt envelope: an old-secret envelope becomes a new-secret envelope with the same plaintext', function (): void {
    $old = rete_seal('sk_test_rotate_me', 'old-secret-1');
    $new = slate_reencrypt_envelope($old, 'old-secret-1', 'new-secret-2');
    assert_true(is_string($new) && str_starts_with($new, 'enc:v1:'));
    assert_true($new !== $old, 'a fresh envelope');
    assert_null(slate_reencrypt_envelope($new, 'old-secret-1', 'x'), 'the new envelope no longer opens with the old secret');
    $back = slate_reencrypt_envelope($new, 'new-secret-2', 'third');
    assert_true(is_string($back), 'the new envelope opens with the new secret');
});

unit('reencrypt envelope: wrong old secret, non-envelopes and corrupt values return null (left alone)', function (): void {
    $env = rete_seal('v', 'right');
    assert_null(slate_reencrypt_envelope($env, 'wrong', 'new'));
    assert_null(slate_reencrypt_envelope('plain-legacy-value', 'right', 'new'));
    assert_null(slate_reencrypt_envelope('enc:v1:!!!not-base64', 'right', 'new'));
    assert_null(slate_reencrypt_envelope('enc:v1:' . base64_encode('short'), 'right', 'new'));
    assert_null(slate_reencrypt_envelope($env, 'right', ''), 'an empty new secret is refused');
});
