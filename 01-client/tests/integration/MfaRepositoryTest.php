<?php

declare(strict_types=1);

use Slate\Services\Auth\Mfa;
use Slate\Services\Auth\MfaRepository;
use Slate\Services\Auth\Auth;
use Slate\Tenancy\TenantContext;

$tenants = new TenantContext();
$repo = new MfaRepository($tenants);
$tid = current_tenant_id();
$admin = Database::row("SELECT id FROM users WHERE tenant_id=? AND status='active' AND role_id > 0 LIMIT 1", [$tid]);
if ($admin === null) throw new RuntimeException('Sanitized fixture must contain an active admin user for MFA validation.');
$userId = (int) $admin['id'];

unit('MFA recovery codes are tenant-bound and single-use', function () use ($tenants, $repo, $tid, $userId) {
    Database::query('DELETE FROM user_mfa_recovery_codes WHERE tenant_id=? AND user_id=?', [$tid, $userId]);
    Database::query('DELETE FROM user_mfa_factors WHERE tenant_id=? AND user_id=?', [$tid, $userId]);
    try {
        $factorId = $repo->enroll($userId, Mfa::generateSecret());
        assert_true($factorId > 0, 'factor enrollment returns an id');
        assert_eq(1, $repo->enable($userId), 'factor is enabled for challenge testing');
        assert_eq($userId, (int) $repo->factorForUser($userId)['user_id'], 'factor binds to admin');
        $codes = Mfa::generateRecoveryCodes(2);
        $repo->replaceRecoveryCodes($userId, array_map([Mfa::class, 'hashRecoveryCode'], $codes));
        assert_true($repo->consumeRecoveryCode($userId, $codes[0]), 'first use succeeds');
        assert_false($repo->consumeRecoveryCode($userId, $codes[0]), 'replay is rejected');
        $other = $tid + 70000;
        assert_throws(\InvalidArgumentException::class, fn () => $tenants->runAs($other, fn () => (new MfaRepository($tenants))->consumeRecoveryCode($userId, $codes[1])));
        assert_throws(\InvalidArgumentException::class, fn () => $repo->enroll(999999999, Mfa::generateSecret()));
        assert_throws(\InvalidArgumentException::class, function () use ($tenants, $userId, $tid, $codes) {
            $tenants->runAs($tid + 70000, fn () => (new MfaRepository($tenants))->replaceRecoveryCodes($userId, array_map([Mfa::class, 'hashRecoveryCode'], $codes)));
        });
        Auth::startSession();
        $_SESSION['slate_mfa_pending'] = ['id' => $userId];
        assert_false(Auth::completeMfa('000000', ''), 'invalid MFA code is rejected');
        $_SESSION['slate_mfa_pending'] = ['id' => $userId];
        $recovery = Mfa::generateRecoveryCodes(1)[0];
        $repo->replaceRecoveryCodes($userId, [Mfa::hashRecoveryCode($recovery)]);
        assert_true(Auth::completeMfa('', $recovery), 'recovery code completes pending MFA challenge');
    } finally {
        Database::query('DELETE FROM user_mfa_recovery_codes WHERE tenant_id=? AND user_id=?', [$tid, $userId]);
        Database::query('DELETE FROM user_mfa_factors WHERE tenant_id=? AND user_id=?', [$tid, $userId]);
        unset($_SESSION['slate_mfa_pending'], $_SESSION['slate_user']);
    }
});
