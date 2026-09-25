<?php
/**
 * Kohevo Brand Persistence System — Phase 4 (System/Error Surfaces Identity
 * Integration).
 *
 * Renders the real, live entry points (404.php, 403.php, 500.php via its
 * OWN real minimal bootstrap — not simulated, PublicRouter's unmatched-route
 * 404, and the maintenance gate's 503) through the existing
 * public-page-probe.php fixture, with a real database — so this is the
 * end-to-end proof that tests/unit/PlatformSignatureErrorSurfaceTest.php's
 * DB-free fallback proof and this file's real-DB proof together cover both
 * paths the spec calls out.
 */

declare(strict_types=1);

use Slate\Services\Content\PlatformIdentity;

function psaest_probe(string $page, string $query = ''): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/public-page-probe.php') . ' '
         . escapeshellarg($page) . ' ' . escapeshellarg($query) . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    return ['status' => (int) ($m[1] ?? 0), 'body' => substr($out, strlen($m[0] ?? ''))];
}

// ── 404 / 403 ────────────────────────────────────────────────

unit('404.php: renders 404, with the platform mark appearing exactly once and tenant branding intact', function () {
    $res = psaest_probe('404.php');
    assert_eq(404, $res['status']);
    assert_eq(1, substr_count($res['body'], PlatformIdentity::markUrl()));
    assert_true(str_contains($res['body'], 'exist or has moved'), 'the existing 404 message must be unchanged');
    assert_true(str_contains($res['body'], 'class="biz"'), 'tenant business/site name must still render with a real DB available');
});

unit('403.php: renders 403, with the platform mark appearing exactly once and tenant branding intact', function () {
    $res = psaest_probe('403.php');
    assert_eq(403, $res['status']);
    assert_eq(1, substr_count($res['body'], PlatformIdentity::markUrl()));
    assert_true(str_contains($res['body'], 'have permission to view this page'), 'the existing 403 message must be unchanged');
    assert_true(str_contains($res['body'], 'class="biz"'));
});

// ── 500 (the critical regression: its own real minimal bootstrap) ──────

unit('500.php: renders 500 through its OWN real minimal bootstrap, with the platform mark appearing exactly once', function () {
    $res = psaest_probe('500.php');
    assert_eq(500, $res['status']);
    assert_eq(1, substr_count($res['body'], PlatformIdentity::markUrl()), 'this is the surface the Phase 4 autoloader fix specifically targets — a regression here means that fix broke');
    assert_true(str_contains($res['body'], 'A server error stopped this page'), 'the existing 500 message must be unchanged');
});

unit('500.php: exposes no exception details, stack traces, or file paths', function () {
    $res = psaest_probe('500.php');
    assert_eq(500, $res['status']);
    foreach (['Fatal error', 'Stack trace', 'Uncaught', '.php on line', '/Users/', '/home/'] as $leak) {
        assert_false(str_contains($res['body'], $leak), "500 page must not leak: $leak");
    }
});

// ── PublicRouter unmatched-route 404 ────────────────────────

unit('PublicRouter: an unmatched public route renders 404 through the same shared renderer, platform mark exactly once', function () {
    $res = psaest_probe('public.php', '_path=totally-nonexistent-route-xyz');
    assert_eq(404, $res['status']);
    assert_eq(1, substr_count($res['body'], PlatformIdentity::markUrl()));
});

// ── Maintenance gate (503) ──────────────────────────────────

unit('maintenance gate: enabling maintenance_mode renders a real 503 with the platform mark exactly once, then the setting is restored', function () {
    $original = Database::setting('maintenance_mode');
    Database::setSetting('maintenance_mode', '1');
    try {
        $res = psaest_probe('index.php');
        assert_eq(503, $res['status']);
        assert_eq(1, substr_count($res['body'], PlatformIdentity::markUrl()));
        assert_true(str_contains($res['body'], 'Down for maintenance'), 'the existing maintenance message must be unchanged');
    } finally {
        if ($original === null) {
            Database::query('DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?', [current_tenant_id(), 'maintenance_mode']);
        } else {
            Database::setSetting('maintenance_mode', $original);
        }
    }
    assert_null(Database::setting('maintenance_mode'), 'maintenance_mode must be restored to its original (unset) state after this test');
});

// ── No duplication across the shared renderer's callers ─────

unit('the shared renderer is the only integration point — 404 rendered via two different callers (404.php and PublicRouter) each show exactly one signature, not two', function () {
    foreach ([['404.php', ''], ['public.php', '_path=another-nonexistent-route']] as [$page, $query]) {
        $res = psaest_probe($page, $query);
        assert_eq(1, substr_count($res['body'], PlatformIdentity::markUrl()), "$page must render exactly one platform mark, not a duplicate from caller + renderer");
    }
});
