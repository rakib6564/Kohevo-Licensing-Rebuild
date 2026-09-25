<?php
declare(strict_types=1);

use Slate\Services\Identity\ContactSeeder;
use Slate\Tenancy\TenantContext;

define('_LOCKOUT_TENANT', slate_test_tenant(918100));

function _lockout_wipe(string $email): void
{
    foreach (Database::rows('SELECT id FROM customers WHERE tenant_id=? AND email=?', [_LOCKOUT_TENANT, $email]) as $row) {
        $id = (int)$row['id'];
        Database::query('DELETE FROM identities WHERE contact_id=?', [$id]);
        Database::query('DELETE FROM contact_emails WHERE contact_id=?', [$id]);
        Database::query('DELETE FROM contact_phones WHERE contact_id=?', [$id]);
        Database::query('DELETE FROM contacts WHERE id=?', [$id]);
        Database::query('DELETE FROM customers WHERE id=?', [$id]);
    }
    Database::query('DELETE FROM login_attempts WHERE tenant_id=?', [_LOCKOUT_TENANT]);
}

unit('customer lockout is IP-bound, signals throttling, and clears after success', function (): void {
    $email = '__lockout_boundary@example.test';
    $oldIp = $_SERVER['REMOTE_ADDR'] ?? null;
    $oldMax = Database::setting('max_login_attempts', _LOCKOUT_TENANT);
    $oldMinutes = Database::setting('lockout_minutes', _LOCKOUT_TENANT);
    _lockout_wipe($email);
    try {
        Database::setSetting('max_login_attempts', '2', _LOCKOUT_TENANT);
        Database::setSetting('lockout_minutes', '15', _LOCKOUT_TENANT);
        $tenants = new TenantContext();
        $tenants->runAs(_LOCKOUT_TENANT, function () use ($email): void {
            Database::insert('customers', [
                'tenant_id' => _LOCKOUT_TENANT,
                'email' => $email,
                'password_hash' => password_hash('lockout-pass-1', PASSWORD_DEFAULT),
                'name' => 'Lockout Boundary',
                'status' => 'active',
                'email_verified' => 1,
            ]);
            $customer = Database::row('SELECT id FROM customers WHERE tenant_id=? AND email=?', [_LOCKOUT_TENANT, $email]);
            (new ContactSeeder())->syncCustomer((int)$customer['id']);
            $_SERVER['REMOTE_ADDR'] = slate_test_ip('198.51.100.77');
            Auth::clearLoginFailures('customer');
            assert_false(Auth::attemptCustomerLogin($email, 'wrong-pass-1'), 'first wrong password rejected');
            assert_false(Auth::lastLoginWasThrottled(), 'first failure is not yet throttled');
            assert_false(Auth::attemptCustomerLogin($email, 'wrong-pass-2'), 'second wrong password rejected');
            assert_false(Auth::lastLoginWasThrottled(), 'the threshold is reached but the second failure itself is not throttled');
            assert_false(Auth::attemptCustomerLogin($email, 'lockout-pass-1'), 'correct password is blocked during lockout');
            assert_true(Auth::lastLoginWasThrottled(), 'lockout is signaled on the next attempt after the threshold');
            Auth::clearLoginFailures('customer');
            assert_true(Auth::attemptCustomerLogin($email, 'lockout-pass-1'), 'successful login works after explicit failure reset');
            Auth::logoutCustomer();
        });
    } finally {
        if ($oldIp === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $oldIp;
        if ($oldMax === null) Database::query('DELETE FROM settings WHERE tenant_id=? AND setting_key=?', [_LOCKOUT_TENANT, 'max_login_attempts']); else Database::setSetting('max_login_attempts', $oldMax, _LOCKOUT_TENANT);
        if ($oldMinutes === null) Database::query('DELETE FROM settings WHERE tenant_id=? AND setting_key=?', [_LOCKOUT_TENANT, 'lockout_minutes']); else Database::setSetting('lockout_minutes', $oldMinutes, _LOCKOUT_TENANT);
        _lockout_wipe($email);
    }
});
