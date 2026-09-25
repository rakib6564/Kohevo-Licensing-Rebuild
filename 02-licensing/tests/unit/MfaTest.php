<?php

declare(strict_types=1);

use Slate\Services\Auth\Mfa;

unit('MFA verifies the RFC 6238-compatible six-digit TOTP vector', function () {
    assert_true(Mfa::verifyTotp('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 59, 0));
    assert_false(Mfa::verifyTotp('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287083', 59, 0));
});

unit('MFA accepts only the configured adjacent time window', function () {
    assert_true(Mfa::verifyTotp('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 59, 1));
    assert_false(Mfa::verifyTotp('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'not-code', 59));
});

unit('MFA recovery codes normalize and hash without retaining plaintext', function () {
    $code = 'ab12- cd34';
    $hash = Mfa::hashRecoveryCode($code);
    assert_true(Mfa::verifyRecoveryCode('AB12CD34', $hash));
    assert_false(Mfa::verifyRecoveryCode('AB12CD35', $hash));
    assert_eq(10, count(Mfa::generateRecoveryCodes()));
});

unit('MFA rejects unsafe secret and recovery-code parameters', function () {
    assert_throws(\InvalidArgumentException::class, fn () => Mfa::generateSecret(9));
    assert_throws(\InvalidArgumentException::class, fn () => Mfa::hashRecoveryCode('---'));
    assert_throws(\InvalidArgumentException::class, fn () => Mfa::generateRecoveryCodes(21));
});
