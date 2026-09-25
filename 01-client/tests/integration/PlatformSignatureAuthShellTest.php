<?php
/**
 * Kohevo Brand Persistence System — Phase 3 (Authentication/Login Identity
 * Integration).
 *
 * Renders the real, unauthenticated admin/customer login pages through the
 * existing public-page-probe.php fixture (both pages self-bootstrap via
 * config.php and need no session — they ARE the login forms), so this
 * exercises the actual live output — not just source, which
 * tests/unit/PlatformSignatureAuthIntegrationTest.php already covers
 * structurally without a DB.
 */

declare(strict_types=1);

use Slate\Services\Content\PlatformIdentity;

function psaut_probe(string $page): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/public-page-probe.php') . ' '
         . escapeshellarg($page) . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    return ['status' => (int) ($m[1] ?? 0), 'body' => substr($out, strlen($m[0] ?? ''))];
}

// ── Admin login ─────────────────────────────────────────────

unit('admin login: renders 200, with the platform mark appearing exactly once', function () {
    $res = psaut_probe('admin/login.php');
    assert_eq(200, $res['status']);
    $markUrl = PlatformIdentity::markUrl();
    assert_eq(1, substr_count($res['body'], $markUrl), "expected exactly one occurrence of the platform mark URL ($markUrl)");
});

unit('admin login: platform signature and the pre-existing "Built & maintained by" attribution both render', function () {
    $res = psaut_probe('admin/login.php');
    assert_eq(200, $res['status']);
    assert_true(str_contains($res['body'], 'class="auth-platform-signature"'), 'platform signature wrapper must render');
    assert_true(str_contains($res['body'], 'class="auth-credit"'), 'the pre-existing owner/support attribution must still render');
    assert_true(str_contains($res['body'], 'Powered by Kohevo'), 'the platform signature text must render');
});

unit('admin login: tenant logo/name rendering and the login form are unaffected', function () {
    $res = psaut_probe('admin/login.php');
    assert_eq(200, $res['status']);
    assert_true(str_contains($res['body'], 'class="auth-logo"'), 'the tenant logo/name block must still render');
    assert_true(str_contains($res['body'], 'name="email"') && str_contains($res['body'], 'name="password"'), 'the login form fields must still render');
    assert_true(str_contains($res['body'], 'name="_csrf"'), 'the CSRF field must still render');
});

// ── Customer authentication (auth-split, via customer/login.php) ────────

unit('customer login: renders 200, with the platform mark appearing exactly once', function () {
    $res = psaut_probe('customer/login.php');
    assert_eq(200, $res['status']);
    $markUrl = PlatformIdentity::markUrl();
    assert_eq(1, substr_count($res['body'], $markUrl), "expected exactly one occurrence of the platform mark URL ($markUrl)");
});

unit('customer login: platform signature renders after the tenant brand and the existing "New here?" form footer', function () {
    $res = psaut_probe('customer/login.php');
    assert_eq(200, $res['status']);
    assert_true(str_contains($res['body'], 'class="auth-platform-signature"'), 'platform signature wrapper must render');
    assert_true(str_contains($res['body'], 'class="auth-brand"'), 'the tenant brand block must still render');
    assert_true(str_contains($res['body'], 'class="auth-footer"'), 'the existing "New here? Create an account" footer must still render');

    $brandPos     = strpos($res['body'], 'class="auth-brand"');
    $authFooterPos = strpos($res['body'], 'class="auth-footer"');
    $signaturePos = strpos($res['body'], 'class="auth-platform-signature"');
    assert_true($brandPos !== false && $authFooterPos !== false && $signaturePos !== false, 'expected markers not found');
    assert_true($brandPos < $signaturePos, 'tenant brand must render before the platform signature');
    assert_true($authFooterPos < $signaturePos, 'the existing login form/footer must render before the platform signature (bottom-of-card placement)');
});

unit('customer login: the form and CSRF field are unaffected', function () {
    $res = psaut_probe('customer/login.php');
    assert_eq(200, $res['status']);
    assert_true(str_contains($res['body'], 'name="email"') && str_contains($res['body'], 'name="password"'), 'the login form fields must still render');
    assert_true(str_contains($res['body'], 'name="_csrf"'), 'the CSRF field must still render');
});

unit('customer register/forgot-password (other auth-split consumers) also render the platform signature exactly once, via the same shared partial', function () {
    foreach (['customer/register.php', 'customer/forgot-password.php'] as $page) {
        $res = psaut_probe($page);
        assert_eq(200, $res['status'], "$page did not render 200");
        assert_eq(1, substr_count($res['body'], PlatformIdentity::markUrl()), "$page: expected exactly one platform mark occurrence");
    }
});
