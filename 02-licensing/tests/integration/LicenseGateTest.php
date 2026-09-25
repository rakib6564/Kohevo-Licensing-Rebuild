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

function lgate_seed(string $status, string $fetchedAt): void {
    (new SlateLicenseCacheStore(current_tenant_id()))->save([
        'status' => $status, 'plan' => 'pro', 'entitlements' => ['white_label'],
        'expires_at' => null, 'fetched_at' => $fetchedAt,
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
