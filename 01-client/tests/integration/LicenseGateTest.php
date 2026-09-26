<?php
/**
 * Phase 5 of the remote license server build: slate_license_gate(),
 * wired into index.php and public.php right alongside the existing
 * slate_maintenance_gate() (its only two real call sites — admin/login.php
 * and customer/login.php never called the maintenance gate either, so the
 * license gate follows the same actual precedent, not an assumption).
 *
 * The gate calls exit() when it restricts, so — same reasoning as every
 * other error-shell test in this suite — it's driven through
 * public-page-probe.php's CHILD process, never required inline.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/license_signing.php';

use Slate\Services\Licensing\SlateLicenseCacheStore;

function lgate_probe(string $page, string $query = ''): array {
    $cmd = license_test_env_prefix() . escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/public-page-probe.php') . ' '
         . escapeshellarg($page) . ' ' . escapeshellarg($query) . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    return ['status' => (int) ($m[1] ?? 0), 'body' => substr($out, strlen($m[0] ?? ''))];
}

function lgate_clear(): void {
    Database::query('DELETE FROM remote_license_cache WHERE tenant_id = ?', [current_tenant_id()]);
}

/**
 * QA Fix Round 1 (Phase 4, Fix 4): load()/readTrustState() no longer
 * grandfather a cache row that has no matching installation_id -- every
 * existing seeded fixture below now needs BOTH a well-formed
 * installation_id on the row AND a matching local installation_identity
 * row, or it would (correctly, per Fix 4) now read back as untrusted
 * rather than as the "genuinely licensed" state most of these tests are
 * actually about. lgate_local_identity() is the one shared, canonical
 * value every ordinary lgate_seed() call uses; the two Fix 1/Fix 4 tests
 * that are SPECIFICALLY about a mismatch use a deliberately different one.
 */
function lgate_local_identity(): string { return str_repeat('c', 32); }

function lgate_ensure_local_identity(?string $installationId = null): void {
    $installationId ??= lgate_local_identity();
    $row = Database::row('SELECT installation_id FROM installation_identity WHERE singleton_id = 1');
    if ($row === null) {
        Database::insert('installation_identity', [
            'singleton_id' => 1, 'tenant_id' => current_tenant_id(), 'installation_id' => $installationId,
        ]);
    } elseif ((string) $row['installation_id'] !== $installationId) {
        Database::update('installation_identity', ['installation_id' => $installationId], 'singleton_id = 1', []);
    }
}

function lgate_seed(string $status, string $fetchedAt): void {
    lgate_ensure_local_identity();
    license_test_seed_cache(current_tenant_id(), [
        'status' => $status, 'plan' => 'pro', 'entitlements' => ['white_label'],
        'expires_at' => null, 'fetched_at' => $fetchedAt,
        'installation_id' => lgate_local_identity(),
    ]);
}

unit('license gate: no cache row at all (never configured) -- the landing page renders normally, unrestricted', function () {
    lgate_clear();
    $res = lgate_probe('index.php');
    assert_eq(200, $res['status']);
    assert_false(str_contains($res['body'], 'License inactive'));
});

unit('license gate: a fresh "active" status passes through normally', function () {
    lgate_clear();
    try {
        lgate_seed('active', gmdate('Y-m-d H:i:s'));
        $res = lgate_probe('index.php');
        assert_eq(200, $res['status']);
        assert_false(str_contains($res['body'], 'License inactive'));
    } finally {
        lgate_clear();
    }
});

unit('license gate: a fresh "trial" status passes through normally', function () {
    lgate_clear();
    try {
        lgate_seed('trial', gmdate('Y-m-d H:i:s'));
        $res = lgate_probe('index.php');
        assert_eq(200, $res['status']);
    } finally {
        lgate_clear();
    }
});

unit('license gate: a "suspended" status renders the branded 403 "License inactive" page', function () {
    lgate_clear();
    try {
        lgate_seed('suspended', gmdate('Y-m-d H:i:s'));
        $res = lgate_probe('index.php');
        assert_eq(403, $res['status']);
        assert_true(str_contains($res['body'], 'License inactive'));
        assert_true(str_contains($res['body'], 'contact your provider'));
    } finally {
        lgate_clear();
    }
});

unit('license gate: "expired" and "revoked" also restrict, same as "suspended"', function () {
    foreach (['expired', 'revoked', 'cancelled'] as $status) {
        lgate_clear();
        try {
            lgate_seed($status, gmdate('Y-m-d H:i:s'));
            $res = lgate_probe('index.php');
            assert_eq(403, $res['status'], "status=$status must restrict");
        } finally {
            lgate_clear();
        }
    }
});

unit('license gate: an "active" status stale past the 7-day grace period restricts, even though the stored status itself is fine', function () {
    lgate_clear();
    try {
        lgate_seed('active', gmdate('Y-m-d H:i:s', time() - 8 * 86400));
        $res = lgate_probe('index.php');
        assert_eq(403, $res['status'], 'silence past the grace period must restrict, same as an explicit bad status');
    } finally {
        lgate_clear();
    }
});

unit('license gate: an "active" status still within the 7-day grace period does NOT restrict', function () {
    lgate_clear();
    try {
        lgate_seed('active', gmdate('Y-m-d H:i:s', time() - 6 * 86400));
        $res = lgate_probe('index.php');
        assert_eq(200, $res['status'], 'a missed check-in inside the grace window must not restrict yet');
    } finally {
        lgate_clear();
    }
});

/**
 * Deployment finding (live test, 2026-09-27): the gate used its own
 * "trial/active only" rule, so an 'expired' license inside its 7-day
 * commercial grace locked anonymous visitors out of the public site while
 * the Global License Guard and admins kept access. It now uses the same
 * CommercialLicenseWindow evaluation as the Guard.
 */
function lgate_seed_expiry(string $status, string $expiresAt): void {
    lgate_ensure_local_identity();
    license_test_seed_cache(current_tenant_id(), [
        'status' => $status, 'plan' => 'pro', 'entitlements' => ['white_label'],
        'expires_at' => $expiresAt, 'fetched_at' => gmdate('Y-m-d H:i:s'),
        'installation_id' => lgate_local_identity(),
    ]);
}

unit('license gate: an expired license inside its 7-day grace keeps the public site open for visitors (index.php and public.php)', function () {
    lgate_clear();
    try {
        lgate_seed_expiry('expired', gmdate('Y-m-d H:i:s', time() - 2 * 86400));
        $res = lgate_probe('index.php');
        assert_eq(200, $res['status'], 'grace: the landing page must stay open');
        assert_false(str_contains($res['body'], 'License inactive'));
        $res = lgate_probe('public.php', '_path=totally-nonexistent-route-xyz');
        assert_true($res['status'] !== 403 && !str_contains($res['body'], 'License inactive'), 'grace: public.php reaches routing, not the lock page');
    } finally {
        lgate_clear();
    }
});

unit('license gate: an expired license past its 7-day grace restricts; one expiring soon does not', function () {
    lgate_clear();
    try {
        lgate_seed_expiry('expired', gmdate('Y-m-d H:i:s', time() - 8 * 86400));
        $res = lgate_probe('index.php');
        assert_eq(403, $res['status'], 'beyond grace: restricted');
        assert_true(str_contains($res['body'], 'License inactive'));

        lgate_seed_expiry('active', gmdate('Y-m-d H:i:s', time() + 3 * 86400));
        assert_eq(200, lgate_probe('index.php')['status'], 'expiring in 3 days: still open');
    } finally {
        lgate_clear();
    }
});

unit('license gate: also runs on public.php, BEFORE PublicRouter dispatch -- a suspended install gets 403, not the route\'s own 404', function () {
    lgate_clear();
    try {
        lgate_seed('suspended', gmdate('Y-m-d H:i:s'));
        $res = lgate_probe('public.php', '_path=totally-nonexistent-route-xyz');
        assert_eq(403, $res['status'], 'the gate must run before routing, same as the maintenance gate does');
        assert_true(str_contains($res['body'], 'License inactive'));
    } finally {
        lgate_clear();
    }
});

unit('license gate / read-time verification (Phase 4, D14): a cached installation_id that does not match this install\'s own installation_identity is rejected at read time, exactly like an untrusted cache', function () {
    lgate_clear();
    lgate_ensure_local_identity(str_repeat('a', 32));
    try {
        // Exactly what a raw_payload/raw_signature pair lifted verbatim from
        // a DIFFERENT, legitimately-licensed installation's cache would look
        // like once written here -- genuinely well-formed, just for the
        // wrong installation.
        license_test_seed_cache(current_tenant_id(), [
            'status' => 'active', 'plan' => 'pro', 'entitlements' => ['white_label'],
            'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
            'installation_id' => str_repeat('b', 32),
        ]);

        $res = lgate_probe('index.php');
        assert_eq(403, $res['status'], 'a cache row bound to a different installation_id must restrict, not pass through');
        assert_true(str_contains($res['body'], 'License inactive'));
    } finally {
        lgate_clear();
    }
});

unit('license gate / read-time verification (Phase 4, D14): a cached installation_id that DOES match this install\'s own installation_identity passes through normally', function () {
    lgate_clear();
    lgate_ensure_local_identity(str_repeat('a', 32));
    try {
        license_test_seed_cache(current_tenant_id(), [
            'status' => 'active', 'plan' => 'pro', 'entitlements' => ['white_label'],
            'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
            'installation_id' => str_repeat('a', 32),
        ]);

        $res = lgate_probe('index.php');
        assert_eq(200, $res['status']);
        assert_false(str_contains($res['body'], 'License inactive'));
    } finally {
        lgate_clear();
    }
});

unit('license gate / QA Fix Round 1 (Fix 1): a cache row exists but is untrusted (mismatched) -- this must NOT be misread as "never configured" even when remote mode is off', function () {
    // Reproduces the exact scenario the QA finding described: no
    // LICENSE_* env vars set (remoteMode=false) -- the PRE-fix bug let this
    // combination pass through as 200 because load() === null was
    // indistinguishable from "no cache row at all".
    lgate_clear();
    lgate_ensure_local_identity(str_repeat('a', 32));
    try {
        license_test_seed_cache(current_tenant_id(), [
            'status' => 'active', 'plan' => 'pro', 'entitlements' => ['white_label'],
            'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
            'installation_id' => str_repeat('b', 32),
        ]);

        $res = lgate_probe('index.php');
        assert_eq(403, $res['status'], 'an untrusted (found but mismatched) cache row must restrict regardless of remote-mode configuration');
    } finally {
        lgate_clear();
    }
});

unit('license gate / QA Fix Round 1 (Fix 4): a NULL cached installation_id is untrusted, never grandfathered into trust', function () {
    lgate_clear();
    lgate_ensure_local_identity();
    try {
        // Phase 10: save() only ever persists a verified, installation-bound
        // payload, so a NULL column can only come from a direct edit of an
        // otherwise valid row -- which is exactly what is simulated here.
        license_test_seed_cache(current_tenant_id(), [
            'status' => 'active', 'plan' => 'pro', 'entitlements' => ['white_label'],
            'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
            'installation_id' => \Slate\Services\Installation\InstallationService::currentInstallationId(),
        ]);
        Database::update('remote_license_cache', ['installation_id' => null], 'tenant_id = ?', [current_tenant_id()]);
        $stored = Database::row('SELECT installation_id FROM remote_license_cache WHERE tenant_id = ?', [current_tenant_id()]);
        assert_null($stored['installation_id'], 'sanity check: the row was actually persisted with a NULL installation_id');

        $res = lgate_probe('index.php');
        assert_eq(403, $res['status'], 'a NULL cached installation_id must never be treated as trusted, even though the license STATUS itself is active');
    } finally {
        lgate_clear();
    }
});

unit('license gate / QA Fix Round 1 (Fix 4): an EMPTY STRING cached installation_id is untrusted -- never treated as equivalent to NULL, and never trusted either', function () {
    lgate_clear();
    lgate_ensure_local_identity();
    try {
        // Bypass save()'s own sanitization (which would itself already
        // coerce '' to NULL) to prove readTrustState() independently
        // rejects a raw '' value found directly in the row, not merely one
        // that happens to reach it through save().
        license_test_seed_cache(current_tenant_id(), [
            'status' => 'active', 'plan' => 'pro', 'entitlements' => ['white_label'],
            'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
            'installation_id' => \Slate\Services\Installation\InstallationService::currentInstallationId(),
        ]);
        Database::update('remote_license_cache', ['installation_id' => ''], 'tenant_id = ?', [current_tenant_id()]);
        $stored = Database::row('SELECT installation_id FROM remote_license_cache WHERE tenant_id = ?', [current_tenant_id()]);
        assert_eq('', $stored['installation_id'], 'sanity check: the row genuinely holds an empty string, not NULL');

        $res = lgate_probe('index.php');
        assert_eq(403, $res['status'], 'an empty-string cached installation_id must restrict, exactly like NULL, but via its own independent check');
    } finally {
        lgate_clear();
    }
});

unit('license gate / QA Fix Round 1 (Fix 4): a MALFORMED (wrong-length/non-hex) cached installation_id is untrusted', function () {
    lgate_clear();
    lgate_ensure_local_identity();
    try {
        Database::insert('remote_license_cache', [
            'tenant_id' => current_tenant_id(), 'status' => 'active', 'plan' => 'pro',
            'entitlements' => json_encode(['white_label']), 'expires_at' => null,
            'installation_id' => 'not-32-hex-chars', 'fetched_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $res = lgate_probe('index.php');
        assert_eq(403, $res['status'], 'a malformed cached installation_id must restrict, not be compared as though it were well-formed');
    } finally {
        lgate_clear();
    }
});

unit('license gate / QA Fix Round 1 (Fix 2E): a MALFORMED local installation_identity row means NOTHING can ever be trusted, even a well-formed matching-looking cache value', function () {
    lgate_clear();
    $priorIdentity = Database::row('SELECT * FROM installation_identity WHERE singleton_id = 1');
    try {
        Database::query('DELETE FROM installation_identity WHERE singleton_id = 1');
        // Only reachable via direct DB corruption/tampering -- provision()
        // itself never writes anything but a well-formed 32-hex value.
        Database::insert('installation_identity', [
            'singleton_id' => 1, 'tenant_id' => current_tenant_id(), 'installation_id' => str_repeat('z', 32),
        ]);

        // A signed payload can never carry a malformed id (save() refuses
        // it), so the matching-looking row is written directly.
        $payload = license_test_payload([
            'status' => 'active', 'plan' => 'pro', 'entitlements' => ['white_label'],
            'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
            'installation_id' => str_repeat('z', 32), // identical string to the corrupted local row
        ]);
        Database::insert('remote_license_cache', [
            'tenant_id' => current_tenant_id(), 'status' => 'active', 'plan' => 'pro',
            'entitlements' => json_encode(['white_label']), 'expires_at' => null,
            'installation_id' => str_repeat('z', 32), 'fetched_at' => gmdate('Y-m-d H:i:s'),
            'remote_checked_at' => gmdate('Y-m-d H:i:s'), 'next_check_after' => 86400,
            'raw_payload' => $payload, 'raw_signature' => license_test_sign($payload),
        ]);

        $res = lgate_probe('index.php');
        assert_eq(403, $res['status'], 'InstallationService::currentInstallationId() must refuse to return a malformed value, so this can never match anything');
    } finally {
        lgate_clear();
        Database::query('DELETE FROM installation_identity WHERE singleton_id = 1');
        if ($priorIdentity !== null) {
            Database::insert('installation_identity', $priorIdentity);
        }
    }
});

unit('license gate: remote mode with no verified cache restricts non-admin traffic instead of falling back locally', function () {
    lgate_clear();
    $keys = ['LICENSE_SERVER_URL','LICENSE_SERVER_PUBLIC_KEY','LICENSE_PRODUCT','LICENSE_KEY'];
    foreach ($keys as $key) putenv($key . '=phase3-test');
    try {
        $res = lgate_probe('index.php');
        assert_eq(403, $res['status']);
    } finally {
        foreach ($keys as $key) putenv($key);
        lgate_clear();
    }
});
