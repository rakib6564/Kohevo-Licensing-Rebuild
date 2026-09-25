<?php
/**
 * Phase 1 A1 — login-lockout reset-bypass.
 *
 * Auth::clearLoginFailures($scope) deleted every login_attempts row for
 * scope+ip on ANY successful login, regardless of which account succeeded.
 * login_attempts.identifier was recorded on every failure but never used to
 * scope the reset. Exploit: attacker fails repeatedly against a victim's
 * account from one IP (recorded under that IP), then logs into their own,
 * unrelated account successfully from the SAME IP — which cleared the
 * victim's accumulated failures too, letting the attack cycle repeat
 * indefinitely between the attacker's own successful logins.
 *
 * The fix narrows clearLoginFailures() to scope+ip+identifier when an
 * identifier is given (every production login-success call site now
 * passes its own account's identifier); the failure COUNT/lockout-check
 * query (loginBlockedSeconds()) is DELIBERATELY left keyed by scope+ip
 * only, so the existing IP-wide protection — an attacker spraying failures
 * across many accounts from one IP still gets that IP blocked — is
 * unchanged. This suite proves both halves: the reset no longer crosses
 * accounts, and the count still does (by design).
 */

declare(strict_types=1);

use Slate\Services\Identity\ContactSeeder;
use Slate\Tenancy\TenantContext;

define('_ALFR_TENANT', slate_test_tenant(918300));

/** Read back the raw failure rows for one scope+ip, for direct inspection (not just the derived lockout state). */
function alfr_failure_identifiers(string $scope, string $ip): array
{
    $rows = Database::rows(
        "SELECT DISTINCT identifier FROM login_attempts WHERE tenant_id = ? AND scope = ? AND ip = ?",
        [_ALFR_TENANT, $scope, $ip]
    );
    return array_column($rows, 'identifier');
}

function alfr_wipe_admin(string $email): void
{
    Database::query('DELETE FROM admin_sessions WHERE tenant_id=? AND user_id IN (SELECT id FROM users WHERE tenant_id=? AND email=?)', [_ALFR_TENANT, _ALFR_TENANT, $email]);
    Database::query('DELETE FROM users WHERE tenant_id=? AND email=?', [_ALFR_TENANT, $email]);
}

function alfr_wipe_customer(string $email): void
{
    foreach (Database::rows('SELECT id FROM customers WHERE tenant_id=? AND email=?', [_ALFR_TENANT, $email]) as $row) {
        $id = (int) $row['id'];
        Database::query('DELETE FROM identities WHERE contact_id=?', [$id]);
        Database::query('DELETE FROM contact_emails WHERE contact_id=?', [$id]);
        Database::query('DELETE FROM contact_phones WHERE contact_id=?', [$id]);
        Database::query('DELETE FROM contacts WHERE id=?', [$id]);
        Database::query('DELETE FROM customers WHERE id=?', [$id]);
    }
}

function alfr_wipe_attempts(): void
{
    Database::query('DELETE FROM login_attempts WHERE tenant_id = ?', [_ALFR_TENANT]);
}

$T = new TenantContext();

// ── 1, 2, 6, 9: admin scope — reset-bypass closed, same-account reset and legitimate login preserved, no enumeration ──

unit('admin login: an attacker\'s successful login does not clear a victim account\'s accumulated failures on the same IP', function () use ($T): void {
    $T->runAs(_ALFR_TENANT, function (): void {
        $ip = slate_test_ip('203.0.113.10');
        $victimEmail = '__alfr-victim@example.test';
        $attackerEmail = '__alfr-attacker@example.test';
        $oldIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $oldMax = Database::setting('max_login_attempts', _ALFR_TENANT);

        $roleId = Database::insert('roles', ['tenant_id' => _ALFR_TENANT, 'name' => 'Probe ALFR', 'slug' => '__probe-alfr-' . bin2hex(random_bytes(4)), 'is_system' => 0]);
        alfr_wipe_admin($victimEmail);
        alfr_wipe_admin($attackerEmail);
        alfr_wipe_attempts();

        Database::insert('users', ['tenant_id' => _ALFR_TENANT, 'email' => $victimEmail, 'password_hash' => password_hash('victim-real-pass', PASSWORD_DEFAULT), 'name' => 'Victim', 'role_id' => $roleId, 'status' => 'active']);
        Database::insert('users', ['tenant_id' => _ALFR_TENANT, 'email' => $attackerEmail, 'password_hash' => password_hash('attacker-own-pass', PASSWORD_DEFAULT), 'name' => 'Attacker', 'role_id' => $roleId, 'status' => 'active']);

        try {
            Database::setSetting('max_login_attempts', '10', _ALFR_TENANT);
            $_SERVER['REMOTE_ADDR'] = $ip;

            // Attacker fails several times against the victim's account — below
            // the lockout threshold, so this alone would not yet block anything.
            for ($i = 0; $i < 5; $i++) {
                assert_false(Auth::attemptLogin($victimEmail, 'wrong-guess-' . $i), 'each guess against the victim is rejected');
            }
            $before = alfr_failure_identifiers('admin', $ip);
            assert_true(in_array($victimEmail, $before, true), 'setup: the victim\'s failures were recorded');

            // The attack: log into the ATTACKER'S OWN, unrelated account
            // successfully, from the same IP.
            assert_true(Auth::attemptLogin($attackerEmail, 'attacker-own-pass'), 'the attacker\'s own login succeeds');
            Auth::logout();

            $after = alfr_failure_identifiers('admin', $ip);
            assert_true(in_array($victimEmail, $after, true), 'the victim\'s accumulated failures must survive a DIFFERENT account\'s successful login on the same IP');
        } finally {
            if ($oldIp === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $oldIp;
            if ($oldMax === null) Database::query('DELETE FROM settings WHERE tenant_id=? AND setting_key=?', [_ALFR_TENANT, 'max_login_attempts']); else Database::setSetting('max_login_attempts', $oldMax, _ALFR_TENANT);
            alfr_wipe_attempts();
            alfr_wipe_admin($victimEmail);
            alfr_wipe_admin($attackerEmail);
            Database::query('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
            Database::query('DELETE FROM roles WHERE id = ?', [$roleId]);
        }
    });
});

unit('admin login: a successful login to the SAME account clears that account\'s own failures, and the legitimate login flow still works', function () use ($T): void {
    $T->runAs(_ALFR_TENANT, function (): void {
        $ip = slate_test_ip('203.0.113.11');
        $email = '__alfr-owner@example.test';
        $oldIp = $_SERVER['REMOTE_ADDR'] ?? null;

        $roleId = Database::insert('roles', ['tenant_id' => _ALFR_TENANT, 'name' => 'Probe ALFR2', 'slug' => '__probe-alfr2-' . bin2hex(random_bytes(4)), 'is_system' => 0]);
        alfr_wipe_admin($email);
        alfr_wipe_attempts();
        Database::insert('users', ['tenant_id' => _ALFR_TENANT, 'email' => $email, 'password_hash' => password_hash('right-pass', PASSWORD_DEFAULT), 'name' => 'Owner', 'role_id' => $roleId, 'status' => 'active']);

        try {
            $_SERVER['REMOTE_ADDR'] = $ip;
            assert_false(Auth::attemptLogin($email, 'wrong-1'), 'wrong password rejected');
            assert_true(in_array($email, alfr_failure_identifiers('admin', $ip), true), 'the failure was recorded');

            assert_true(Auth::attemptLogin($email, 'right-pass'), 'the legitimate login succeeds');
            assert_false(in_array($email, alfr_failure_identifiers('admin', $ip), true), 'logging into the SAME account clears its own recorded failures');
            Auth::logout();
        } finally {
            if ($oldIp === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $oldIp;
            alfr_wipe_attempts();
            alfr_wipe_admin($email);
            Database::query('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
            Database::query('DELETE FROM roles WHERE id = ?', [$roleId]);
        }
    });
});

// ── 3: existing IP-wide count/lockout protection is unchanged ──

unit('admin login: cumulative failures against DIFFERENT accounts from one IP still trigger the shared IP lockout (count is unchanged)', function () use ($T): void {
    $T->runAs(_ALFR_TENANT, function (): void {
        $ip = slate_test_ip('203.0.113.12');
        $emailA = '__alfr-multiA@example.test';
        $emailB = '__alfr-multiB@example.test';
        $oldIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $oldMax = Database::setting('max_login_attempts', _ALFR_TENANT);

        alfr_wipe_admin($emailA);
        alfr_wipe_admin($emailB);
        alfr_wipe_attempts();

        try {
            Database::setSetting('max_login_attempts', '4', _ALFR_TENANT);
            $_SERVER['REMOTE_ADDR'] = $ip;

            // No such users exist — every attempt is a rejected, recorded
            // failure (dummyVerify path), exactly like a real wrong-password
            // failure for the throttle's purposes. Spread across two distinct
            // target identifiers from the SAME ip.
            assert_false(Auth::attemptLogin($emailA, 'x'));
            assert_false(Auth::attemptLogin($emailB, 'x'));
            assert_eq(0, Auth::loginBlockedSeconds('admin'), 'below threshold: not yet blocked');
            assert_false(Auth::attemptLogin($emailA, 'x'));
            assert_false(Auth::attemptLogin($emailB, 'x'));

            $blocked = Auth::loginBlockedSeconds('admin');
            assert_true($blocked > 0, 'the IP must be blocked once the COMBINED total across both accounts reaches the threshold — proving the count is still scope+ip, not scope+ip+account');
        } finally {
            if ($oldIp === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $oldIp;
            if ($oldMax === null) Database::query('DELETE FROM settings WHERE tenant_id=? AND setting_key=?', [_ALFR_TENANT, 'max_login_attempts']); else Database::setSetting('max_login_attempts', $oldMax, _ALFR_TENANT);
            alfr_wipe_attempts();
        }
    });
});

// ── 4: admin/customer scope isolation ──

unit('admin and customer login-failure scopes remain isolated from each other', function () use ($T): void {
    $T->runAs(_ALFR_TENANT, function (): void {
        $ip = slate_test_ip('203.0.113.13');
        $oldIp = $_SERVER['REMOTE_ADDR'] ?? null;
        alfr_wipe_attempts();
        try {
            $_SERVER['REMOTE_ADDR'] = $ip;
            Auth::recordLoginFailure('admin', 'shared@example.test');
            $adminIds = alfr_failure_identifiers('admin', $ip);
            $customerIds = alfr_failure_identifiers('customer', $ip);
            assert_true(in_array('shared@example.test', $adminIds, true), 'the admin-scope failure is recorded under admin');
            assert_true(!in_array('shared@example.test', $customerIds, true), 'the same identifier/IP must not appear under the customer scope');

            Auth::clearLoginFailures('customer', 'shared@example.test');
            assert_true(in_array('shared@example.test', alfr_failure_identifiers('admin', $ip), true), 'clearing the customer scope must not remove the admin-scope row for the same identifier/IP');
        } finally {
            if ($oldIp === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $oldIp;
            alfr_wipe_attempts();
        }
    });
});

// ── 7: shared-IP customers do not lock each other out unnecessarily ──

unit('customer login: an attacker\'s successful login does not clear a victim account\'s accumulated failures on the same IP', function () use ($T): void {
    $T->runAs(_ALFR_TENANT, function (): void {
        $ip = slate_test_ip('203.0.113.14');
        $victimEmail = '__alfr-cvictim@example.test';
        $attackerEmail = '__alfr-cattacker@example.test';
        $oldIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $oldMax = Database::setting('max_login_attempts', _ALFR_TENANT);

        alfr_wipe_customer($victimEmail);
        alfr_wipe_customer($attackerEmail);
        alfr_wipe_attempts();

        $victimId = Database::insert('customers', ['tenant_id' => _ALFR_TENANT, 'email' => $victimEmail, 'password_hash' => password_hash('victim-real-pass', PASSWORD_DEFAULT), 'name' => 'C Victim', 'status' => 'active', 'email_verified' => 1]);
        (new ContactSeeder())->syncCustomer((int) $victimId);
        $attackerId = Database::insert('customers', ['tenant_id' => _ALFR_TENANT, 'email' => $attackerEmail, 'password_hash' => password_hash('attacker-own-pass', PASSWORD_DEFAULT), 'name' => 'C Attacker', 'status' => 'active', 'email_verified' => 1]);
        (new ContactSeeder())->syncCustomer((int) $attackerId);

        try {
            Database::setSetting('max_login_attempts', '10', _ALFR_TENANT);
            $_SERVER['REMOTE_ADDR'] = $ip;

            for ($i = 0; $i < 5; $i++) {
                assert_false(@Auth::attemptCustomerLogin($victimEmail, 'wrong-guess-' . $i), 'each guess against the victim is rejected');
            }
            assert_true(in_array($victimEmail, alfr_failure_identifiers('customer', $ip), true), 'setup: the victim\'s failures were recorded');

            assert_true(@Auth::attemptCustomerLogin($attackerEmail, 'attacker-own-pass'), 'the attacker\'s own login succeeds');
            Auth::logoutCustomer();

            assert_true(in_array($victimEmail, alfr_failure_identifiers('customer', $ip), true), 'the victim\'s accumulated failures must survive a DIFFERENT customer account\'s successful login on the same IP');
        } finally {
            if ($oldIp === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $oldIp;
            if ($oldMax === null) Database::query('DELETE FROM settings WHERE tenant_id=? AND setting_key=?', [_ALFR_TENANT, 'max_login_attempts']); else Database::setSetting('max_login_attempts', $oldMax, _ALFR_TENANT);
            alfr_wipe_attempts();
            alfr_wipe_customer($victimEmail);
            alfr_wipe_customer($attackerEmail);
        }
    });
});

// ── 8/9: no enumeration signal — unknown vs known-wrong-password behave the same ──

unit('admin login: an unknown email and a known email with a wrong password fail identically (no enumeration signal)', function () use ($T): void {
    $T->runAs(_ALFR_TENANT, function (): void {
        $ip = slate_test_ip('203.0.113.15');
        $knownEmail = '__alfr-known@example.test';
        $oldIp = $_SERVER['REMOTE_ADDR'] ?? null;

        $roleId = Database::insert('roles', ['tenant_id' => _ALFR_TENANT, 'name' => 'Probe ALFR3', 'slug' => '__probe-alfr3-' . bin2hex(random_bytes(4)), 'is_system' => 0]);
        alfr_wipe_admin($knownEmail);
        alfr_wipe_attempts();
        Database::insert('users', ['tenant_id' => _ALFR_TENANT, 'email' => $knownEmail, 'password_hash' => password_hash('the-real-pass', PASSWORD_DEFAULT), 'name' => 'Known', 'role_id' => $roleId, 'status' => 'active']);

        try {
            $_SERVER['REMOTE_ADDR'] = $ip;
            $resultUnknown = Auth::attemptLogin('__alfr-unknown@example.test', 'whatever');
            $throttledUnknown = Auth::lastLoginWasThrottled();
            $needsMfaUnknown = Auth::lastLoginNeedsMfa();

            $resultKnownWrong = Auth::attemptLogin($knownEmail, 'wrong-password');
            $throttledKnownWrong = Auth::lastLoginWasThrottled();
            $needsMfaKnownWrong = Auth::lastLoginNeedsMfa();

            assert_eq($resultUnknown, $resultKnownWrong, 'both must return the same boolean (false)');
            assert_eq($throttledUnknown, $throttledKnownWrong, 'both must report the same throttle flag');
            assert_eq($needsMfaUnknown, $needsMfaKnownWrong, 'both must report the same MFA-pending flag');

            $ids = alfr_failure_identifiers('admin', $ip);
            assert_true(in_array('__alfr-unknown@example.test', $ids, true), 'the unknown-account attempt is recorded the same way as a real one');
            assert_true(in_array($knownEmail, $ids, true), 'the known-account attempt is recorded');
        } finally {
            if ($oldIp === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $oldIp;
            alfr_wipe_attempts();
            alfr_wipe_admin($knownEmail);
            Database::query('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
            Database::query('DELETE FROM roles WHERE id = ?', [$roleId]);
        }
    });
});
