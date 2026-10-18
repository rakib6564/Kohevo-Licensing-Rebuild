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

use Slate\Services\Licensing\SlateLicenseCacheStore;

function lgate_probe(string $page, string $query = ''): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' '
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
 * QA Fix Round 1 (Fix 4/5): the gate trusts a cache row only when its
 * installation_id matches this install's own installation_identity row
 * (SlateLicenseCacheStore::readTrustState()). These fixtures used to seed
 * rows with no installation_id, which the gate correctly treats as an
 * untrusted row and restricts, so the "licensed" cases failed. Every
 * ordinary seed now carries the same installation_id as a local identity
 * row, as in 01-client's copy of this test. The local identity row is put
 * back exactly as it was at shutdown.
 */
function lgate_local_identity(): string { return str_repeat('c', 32); }

function lgate_ensure_local_identity(): void {
    static $restoreRegistered = false;
    $prior = Database::row('SELECT * FROM installation_identity WHERE singleton_id = 1');
    if (!$restoreRegistered) {
        $restoreRegistered = true;
        register_shutdown_function(static function () use ($prior): void {
            Database::query('DELETE FROM installation_identity WHERE singleton_id = 1');
            if ($prior) Database::insert('installation_identity', $prior);
        });
    }
    if (!$prior) {
        Database::insert('installation_identity', ['singleton_id' => 1, 'tenant_id' => current_tenant_id(), 'installation_id' => lgate_local_identity()]);
    } elseif ((string) $prior['installation_id'] !== lgate_local_identity()) {
        Database::update('installation_identity', ['installation_id' => lgate_local_identity()], 'singleton_id = 1', []);
    }
}

function lgate_seed(string $status, string $fetchedAt, ?string $installationId = 'local'): void {
    lgate_ensure_local_identity();
    (new SlateLicenseCacheStore(current_tenant_id()))->save([
        'status' => $status, 'plan' => 'pro', 'entitlements' => ['white_label'],
        'expires_at' => null, 'fetched_at' => $fetchedAt,
        'installation_id' => $installationId === 'local' ? lgate_local_identity() : $installationId,
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

unit('license gate: an active row with no installation_id (or another install\'s id) is untrusted and restricts', function () {
    lgate_clear();
    try {
        foreach ([null, str_repeat('d', 32)] as $id) {
            lgate_seed('active', gmdate('Y-m-d H:i:s'), $id);
            $res = lgate_probe('index.php');
            assert_eq(403, $res['status'], 'an untrusted cache row must restrict, not pass as licensed (' . var_export($id, true) . ')');
            assert_true(str_contains($res['body'], 'License inactive'));
        }
    } finally {
        lgate_clear();
    }
});

