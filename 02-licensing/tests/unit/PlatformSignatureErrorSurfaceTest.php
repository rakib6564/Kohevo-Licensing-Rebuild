<?php
/**
 * Unit tests for the Phase 4 system/error-surface integration.
 *
 * Unlike the Phase 2/3 shell/login tests, includes/error_page.php's
 * slate_render_error() is a plain global function with NO app-bootstrap
 * dependency of its own — it works standalone with just the autoloader
 * loaded (exactly what tests/unit/run.php provides), and every DB read
 * inside it is already guarded by class_exists('Database')/try-catch. That
 * makes this the strongest possible test of the "database unavailable"
 * fallback path (Phase 4 §10): under this harness, the `Database` class
 * genuinely does not exist at all — not a simulated outage, the real thing —
 * so a passing assertion here is direct proof the platform signature
 * survives a total DB-layer failure, not just a mocked one.
 *
 * Real end-to-end rendering (the actual 404.php/403.php/500.php/PublicRouter/
 * maintenance-gate entry points, with a real DB) is covered by
 * tests/integration/PlatformSignatureErrorShellTest.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/error_page.php';

use Slate\Services\Content\PlatformIdentity;

/**
 * Render slate_render_error() and capture its output. Does NOT rely on
 * http_response_code() to prove the status: every prior test in this shared
 * CLI process has already echoed TAP output ("ok   - ..."), so PHP's own
 * "headers already sent" rule silently no-ops any further
 * http_response_code() calls in this process — a CLI/harness artifact
 * unrelated to real behavior (proven fine for real requests by
 * tests/integration/PlatformSignatureErrorShellTest.php, which uses fresh
 * child processes). Instead this reads the "Error <status>" badge the
 * template renders directly from the $status parameter, which is unaffected.
 */
function _pses_render(int $status, string $title, string $message): array
{
    ob_start();
    slate_render_error($status, $title, $message);
    $html = (string) ob_get_clean();
    return ['status_badge' => str_contains($html, "Error $status"), 'html' => $html];
}

foreach ([404, 403, 500, 503] as $status) {
    unit("error page $status: renders its status in the page and the platform signature exactly once, with the DB layer entirely unavailable", function () use ($status) {
        $res = _pses_render($status, 'Test title', 'Test message.');
        assert_true($res['status_badge'], "expected the page to render 'Error $status'");
        assert_eq(1, substr_count($res['html'], PlatformIdentity::markUrl()), "expected exactly one platform mark occurrence for status $status");
        assert_true(str_contains($res['html'], 'Powered by Kohevo'));
    });

    unit("error page $status: falls back to the gradient background (no hero image) when the DB layer is unavailable, and still shows no tenant business name — but the platform signature still renders", function () use ($status) {
        $res = _pses_render($status, 'Test title', 'Test message.');
        assert_true(str_contains($res['html'], 'radial-gradient'), 'expected the gradient fallback background');
        assert_false(str_contains($res['html'], 'class="biz"'), 'no Database class is loaded in this harness, so no tenant business name can resolve');
        assert_true(str_contains($res['html'], 'class="platform-signature"'), 'the platform signature must render regardless of tenant-branding availability');
    });

    unit("error page $status: the original title/message are preserved unchanged", function () use ($status) {
        $res = _pses_render($status, 'A Very Specific Title', 'A very specific message body.');
        assert_true(str_contains($res['html'], 'A Very Specific Title'));
        assert_true(str_contains($res['html'], 'A very specific message body.'));
    });
}

unit('error page: no fatal/exception is thrown when rendering with only the autoloader present (true DB-outage simulation)', function () {
    // If slate_render_error() threw, this test itself would report FAIL with
    // the exception message per the harness's own unit() semantics — so
    // simply reaching this assertion for every status above is already the
    // proof; this test exists to name that guarantee explicitly.
    foreach ([404, 403, 500, 503] as $status) {
        $res = _pses_render($status, 'x', 'y');
        assert_true($res['status_badge']);
    }
});

unit('error page: the platform signature source contains no hardcoded /assets/platform/brand/ path — only PlatformSignature is used', function () {
    $src = file_get_contents(__DIR__ . '/../../includes/error_page.php');
    assert_true(str_contains($src, 'PlatformSignature::render('), 'error_page.php must render the platform signature through PlatformSignature');
    assert_false(str_contains($src, '/assets/platform/brand/'), 'error_page.php must never name a platform asset path directly');
});

unit('error page: the rendered platform-signature div sits after the rendered .biz div, not before it (tenant identity primary, Kohevo secondary)', function () {
    // Checked on the SOURCE template markup (not the render() call site,
    // which is legitimately computed earlier in the function, before the
    // HTML section even starts — same as $biz, $accent, etc. are).
    $src = file_get_contents(__DIR__ . '/../../includes/error_page.php');
    $bizDivPos       = strpos($src, '<div class="biz">');
    $signatureDivPos = strpos($src, '<div class="platform-signature">');
    assert_true($bizDivPos !== false && $signatureDivPos !== false, 'expected markers not found');
    assert_true($signatureDivPos > $bizDivPos, 'the platform-signature div must come after the .biz div, per the tenant-then-platform hierarchy');
});

unit('error page: output never contains a raw <script tag from identity values (defensive, values are fixed constants)', function () {
    foreach ([404, 403, 500, 503] as $status) {
        $res = _pses_render($status, 'x', 'y');
        assert_false(str_contains($res['html'], '<script'));
    }
});

unit('500.php: the minimal bootstrap now registers the Slate\\ autoloader (regression guard for the Phase 4 fix)', function () {
    $src = file_get_contents(__DIR__ . '/../../500.php');
    assert_true(str_contains($src, "src/autoload.php"), '500.php must load the autoloader so PlatformSignature is resolvable on its own minimal-bootstrap path');
    // It must NOT load the full app (config.php) — that would reintroduce the
    // exact risk (PluginLoader::boot() re-triggering the original failure)
    // this file's minimal bootstrap exists to avoid.
    assert_false(str_contains($src, "require_once SLATE_ROOT . '/config.php'"), '500.php must still avoid the full config.php boot');
});
