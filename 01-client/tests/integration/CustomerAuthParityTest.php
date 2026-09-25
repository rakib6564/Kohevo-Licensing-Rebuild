<?php
/**
 * Phase 2A B4 — customer-auth PARITY GATE.
 *
 * Exercises the cut-over Auth customer methods (now reading through IdentityStore,
 * dual-writing to the spine) and asserts the observable behavior matches the
 * legacy contract across the mandated scenarios:
 *   successful login · invalid user/password · password rehash · suspended account ·
 *   email verification · password reset · token validation · session creation ·
 *   tenant isolation · registration dual-write.
 *
 * Fixtures are throwaway (dedicated tenant); no outbound email is triggered
 * (token flows are tested via directly-seeded tokens). Session calls are
 * error-suppressed for CLI; the real browser login is the human gate on top.
 */

declare(strict_types=1);

use Slate\Services\Identity\ContactSeeder;
use Slate\Tenancy\TenantContext;

define('_AUTH_TENANT', slate_test_tenant(918000));

function _auth_wipe(string $email): void
{
    $t = _AUTH_TENANT;

    // Orphan-proof first — see the note in IdentityStoreTest. The sweep below
    // reaches identities only through their customer row, so one left behind by
    // a mid-test failure survives and poisons every later run.
    foreach (Database::rows('SELECT id FROM identities WHERE credential_ref = ?', [$email]) as $i) {
        Database::query('DELETE FROM identity_tokens WHERE identity_id = ?', [(int) $i['id']]);
    }
    Database::query('DELETE FROM identities WHERE credential_ref = ?', [$email]);

    foreach (Database::rows('SELECT id FROM customers WHERE tenant_id=? AND email=?', [$t, $email]) as $c) {
        $cid = (int) $c['id'];
        Database::query('DELETE FROM customer_auth_tokens WHERE customer_id=?', [$cid]);
        Database::query('DELETE FROM identity_tokens WHERE identity_id IN (SELECT id FROM identities WHERE contact_id=?)', [$cid]);
        Database::query('DELETE FROM identities WHERE contact_id=?', [$cid]);
        Database::query('DELETE FROM contact_emails WHERE contact_id=?', [$cid]);
        Database::query('DELETE FROM contact_phones WHERE contact_id=?', [$cid]);
        Database::query('DELETE FROM contacts WHERE id=?', [$cid]);
        Database::query('DELETE FROM customers WHERE id=?', [$cid]);
    }
    Auth::clearLoginFailures('customer'); // avoid cross-test throttle on ip 0.0.0.0
}

/** Create a legacy customer + its dual-write spine mirror (post-B4 state). */
function _auth_customer(string $email, string $pw, string $status = 'active', bool $verified = true, ?string $hash = null): int
{
    $id = Database::insert('customers', [
        'tenant_id' => _AUTH_TENANT, 'email' => $email,
        'password_hash' => $hash ?? password_hash($pw, PASSWORD_DEFAULT),
        'name' => 'Parity User', 'status' => $status, 'email_verified' => $verified ? 1 : 0,
    ]);
    (new ContactSeeder())->syncCustomer($id);
    return $id;
}

$T = new TenantContext();

// ── 1 + 8. Successful login + session creation ────────────
unit('successful login returns true and builds the legacy session shape', function () use ($T) {
    $email = '__p_ok@example.test';
    $T->runAs(_AUTH_TENANT, function () use ($email) {
        _auth_wipe($email);
        try {
            $cid = _auth_customer($email, 'right-pass-1');
            $_SESSION = [];
            $ok = @Auth::attemptCustomerLogin($email, 'right-pass-1');
            assert_true($ok === true, 'login succeeds via IdentityStore');
            $s = $_SESSION['slate_customer'] ?? null;
            assert_true(is_array($s), 'session set');
            assert_eq($cid, $s['id'], 'session id == customer id == contact id (id-preservation)');
            assert_eq($email, $s['email']);
            assert_eq(_AUTH_TENANT, $s['tenant_id']);
        } finally { _auth_wipe($email); }
    });
});

// ── 2. Invalid username / password ────────────────────────
unit('invalid password and unknown user both return false (no leak)', function () use ($T) {
    $email = '__p_bad@example.test';
    $T->runAs(_AUTH_TENANT, function () use ($email) {
        _auth_wipe($email);
        try {
            _auth_customer($email, 'the-real-pass');
            assert_false(@Auth::attemptCustomerLogin($email, 'wrong-pass'), 'wrong password → false');
            Auth::clearLoginFailures('customer');
            assert_false(@Auth::attemptCustomerLogin('__nobody@example.test', 'x'), 'unknown user → false');
        } finally { _auth_wipe($email); }
    });
});

// ── 3. Password rehash ────────────────────────────────────
unit('a rehashable identity hash is upgraded on login, login still succeeds', function () use ($T) {
    $email = '__p_rehash@example.test';
    $T->runAs(_AUTH_TENANT, function () use ($email) {
        _auth_wipe($email);
        try {
            // Weak-cost bcrypt hash → needs_rehash under PASSWORD_DEFAULT.
            $weak = password_hash('rehash-me-99', PASSWORD_BCRYPT, ['cost' => 4]);
            $cid = _auth_customer($email, 'rehash-me-99', 'active', true, $weak);
            $before = Database::value("SELECT password_hash FROM identities WHERE contact_id=? AND provider='password'", [$cid]);
            assert_true(@Auth::attemptCustomerLogin($email, 'rehash-me-99') === true, 'login ok');
            $after = Database::value("SELECT password_hash FROM identities WHERE contact_id=? AND provider='password'", [$cid]);
            assert_true($before !== $after, 'identity hash rehashed');
            Auth::clearLoginFailures('customer');
            assert_true(@Auth::attemptCustomerLogin($email, 'rehash-me-99') === true, 'still logs in after rehash');
        } finally { _auth_wipe($email); }
    });
});

// ── 4. Suspended account ──────────────────────────────────
unit('a suspended customer cannot log in even with the right password', function () use ($T) {
    $email = '__p_susp@example.test';
    $T->runAs(_AUTH_TENANT, function () use ($email) {
        _auth_wipe($email);
        try {
            _auth_customer($email, 'susp-pass-1', 'suspended');
            assert_false(@Auth::attemptCustomerLogin($email, 'susp-pass-1'), 'suspended → false');
        } finally { _auth_wipe($email); }
    });
});

// ── 5. Email verification (dual-write) ────────────────────
unit('verifyCustomerEmail flips the flag on BOTH customers and identities', function () use ($T) {
    $email = '__p_verify@example.test';
    $T->runAs(_AUTH_TENANT, function () use ($email) {
        _auth_wipe($email);
        try {
            $cid = _auth_customer($email, 'verify-pass-1', 'active', false);
            $raw = bin2hex(random_bytes(16));
            Database::insert('customer_auth_tokens', [
                'tenant_id' => _AUTH_TENANT, 'customer_id' => $cid, 'purpose' => 'verify_email',
                'token_hash' => hash('sha256', $raw), 'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            ]);
            $ret = Auth::verifyCustomerEmail($raw);
            assert_eq($cid, $ret, 'verify returns the customer id');
            assert_eq(1, (int) Database::value('SELECT email_verified FROM customers WHERE id=?', [$cid]), 'customers verified');
            assert_eq(1, (int) Database::value("SELECT email_verified FROM identities WHERE contact_id=? AND provider='password'", [$cid]), 'identity verified (dual-write)');
        } finally { _auth_wipe($email); }
    });
});

// ── 6 + 7. Password reset + token validation ──────────────
unit('resetCustomerPassword dual-writes; new password works, old fails; token single-use', function () use ($T) {
    $email = '__p_reset@example.test';
    $T->runAs(_AUTH_TENANT, function () use ($email) {
        _auth_wipe($email);
        try {
            $cid = _auth_customer($email, 'old-pass-123');
            $raw = bin2hex(random_bytes(16));
            Database::insert('customer_auth_tokens', [
                'tenant_id' => _AUTH_TENANT, 'customer_id' => $cid, 'purpose' => 'password_reset',
                'token_hash' => hash('sha256', $raw), 'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            ]);
            $res = Auth::resetCustomerPassword($raw, 'brand-new-pass-1');
            assert_true(($res['ok'] ?? false) === true, 'reset ok');

            $res2 = Auth::resetCustomerPassword($raw, 'another-pass-1');
            assert_false($res2['ok'] ?? false, 'reset token is single-use');

            Auth::clearLoginFailures('customer');
            assert_true(@Auth::attemptCustomerLogin($email, 'brand-new-pass-1') === true, 'new password works');
            Auth::clearLoginFailures('customer');
            assert_false(@Auth::attemptCustomerLogin($email, 'old-pass-123'), 'old password rejected');

            $cuHash = (string) Database::value('SELECT password_hash FROM customers WHERE id=?', [$cid]);
            $idHash = (string) Database::value("SELECT password_hash FROM identities WHERE contact_id=? AND provider='password'", [$cid]);
            assert_true(password_verify('brand-new-pass-1', $cuHash), 'customers hash updated');
            assert_true(password_verify('brand-new-pass-1', $idHash), 'identity hash updated (dual-write)');
        } finally { _auth_wipe($email); }
    });
});

// ── 9. Tenant isolation ───────────────────────────────────
unit('login is tenant-isolated (same email, different tenant, cannot cross)', function () use ($T) {
    $email = '__p_tenant@example.test';
    $T->runAs(_AUTH_TENANT, function () use ($email) { _auth_wipe($email); _auth_customer($email, 'tenant-pass-1'); });
    try {
        $crossed = $T->runAs(_AUTH_TENANT + 5, fn () => @Auth::attemptCustomerLogin($email, 'tenant-pass-1'));
        assert_false($crossed, 'credentials do not authenticate under another tenant');
    } finally {
        $T->runAs(_AUTH_TENANT, fn () => _auth_wipe($email));
    }
});

// ── 10. Registration dual-write alignment ─────────────────
unit('dual-write keeps customer/contact/identity ids aligned and hashes mirrored', function () use ($T) {
    $email = '__p_dualwrite@example.test';
    $T->runAs(_AUTH_TENANT, function () use ($email) {
        _auth_wipe($email);
        try {
            $cid = _auth_customer($email, 'dual-pass-1');
            assert_true(Database::value('SELECT 1 FROM contacts WHERE id=?', [$cid]) !== null, 'contact id preserved');
            assert_eq($cid, (int) Database::value("SELECT contact_id FROM identities WHERE contact_id=? AND provider='password'", [$cid]));
            assert_eq(1, (int) Database::value('SELECT COUNT(*) FROM identities WHERE contact_id=?', [$cid]));
            $cu = (string) Database::value('SELECT password_hash FROM customers WHERE id=?', [$cid]);
            $id = (string) Database::value('SELECT password_hash FROM identities WHERE contact_id=?', [$cid]);
            assert_eq($cu, $id, 'password hash mirrored verbatim');
        } finally { _auth_wipe($email); }
    });
});
