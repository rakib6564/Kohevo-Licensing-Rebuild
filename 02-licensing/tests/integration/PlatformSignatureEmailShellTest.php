<?php
/**
 * Kohevo Brand Persistence System — Phase 5 (Communications/Email Identity
 * Integration).
 *
 * Real end-to-end rendering of the two email-builder paths that are safe to
 * call directly (pure builders, no Mailer::send() involved):
 * BrandedEmail::shell() (the shared shell — the only current caller is the
 * Forms plugin, via FormsAPI::emailShell()/emailAccent()) and BookingAPI's
 * private brandedEmailShell() (its own separate shell). Both are exercised
 * with real tenant branding settings via Slate\Tenancy\TenantContext::runAs()
 * against throwaway synthetic tenants, mirroring
 * tests/integration/NotificationsTenantIsolationTest.php's pattern.
 *
 * Auth's verification/reset emails and Membership's purchase/cancel emails
 * build their HTML inline and call \Mailer::send() directly with no
 * injection seam — calling them here would risk a real send attempt. Those
 * are covered structurally instead, in
 * tests/unit/PlatformSignatureEmailSurfaceTest.php (see that file's docblock
 * for why, and tests/integration/CustomerAuthParityTest.php for the
 * established precedent of avoiding the same send path).
 */

declare(strict_types=1);

use Slate\Services\Content\PlatformIdentity;
use Slate\Tenancy\TenantContext;

define('_P5A_TENANT', slate_test_tenant(995100));
define('_P5B_TENANT', slate_test_tenant(995200));

// ── BrandedEmail::shell() ────────────────────────────────────

unit('BrandedEmail::shell(): with tenant branding configured, the platform signature renders exactly once, after the tenant footer, without altering tenant identity', function () {
    $tenants = new TenantContext();
    $html = $tenants->runAs(_P5A_TENANT, function () {
        Database::setSetting('site_name', 'Acme Studio');
        Database::setSetting('brand_accent_color', '#112233');
        Database::setSetting('business_address', '123 Main St');
        return \Slate\Services\Notifications\BrandedEmail::shell(
            ['title' => 'Test', 'header_label' => 'Test'],
            '<tr><td>body</td></tr>'
        );
    });

    assert_eq(1, substr_count($html, PlatformIdentity::signature()), 'expected exactly one platform signature occurrence');
    assert_true(str_contains($html, 'Acme Studio'), 'tenant site name must still render');
    assert_true(str_contains($html, '123 Main St'), 'tenant business address must still render');

    $tenantFooterPos = strpos($html, 'Sent by');
    $platformPos     = strpos($html, PlatformIdentity::signature());
    assert_true($tenantFooterPos !== false && $platformPos !== false, 'expected markers not found');
    assert_true($platformPos > $tenantFooterPos, 'platform signature must render after the tenant "Sent by" footer line');
});

unit('BrandedEmail::shell(): with no tenant branding configured, the platform signature still renders and the email does not break', function () {
    $tenant  = slate_test_tenant(995300);
    $tenants = new TenantContext();
    $html = $tenants->runAs($tenant, function () {
        return \Slate\Services\Notifications\BrandedEmail::shell(['title' => 'Test'], '<tr><td>body</td></tr>');
    });

    assert_eq(1, substr_count($html, PlatformIdentity::signature()), 'missing tenant branding must not remove the platform signature');
    assert_true(str_contains($html, '<!DOCTYPE html>'), 'the email must still render as a complete document');
});

unit('BrandedEmail::shell(): the platform signature text is identical across tenants while tenant branding correctly diverges', function () {
    $tenants = new TenantContext();
    $tenants->runAs(_P5A_TENANT, function () { Database::setSetting('site_name', 'Tenant A Co'); });
    $tenants->runAs(_P5B_TENANT, function () { Database::setSetting('site_name', 'Tenant B Co'); });

    $htmlA = $tenants->runAs(_P5A_TENANT, fn () => \Slate\Services\Notifications\BrandedEmail::shell(['title' => 'x'], '<tr><td>x</td></tr>'));
    $htmlB = $tenants->runAs(_P5B_TENANT, fn () => \Slate\Services\Notifications\BrandedEmail::shell(['title' => 'x'], '<tr><td>x</td></tr>'));

    assert_true(str_contains($htmlA, 'Tenant A Co') && !str_contains($htmlA, 'Tenant B Co'), 'tenant A must not see tenant B branding');
    assert_true(str_contains($htmlB, 'Tenant B Co') && !str_contains($htmlB, 'Tenant A Co'), 'tenant B must not see tenant A branding');
    assert_eq(1, substr_count($htmlA, PlatformIdentity::signature()));
    assert_eq(1, substr_count($htmlB, PlatformIdentity::signature()));
    assert_true(str_contains($htmlA, PlatformIdentity::signature()) && str_contains($htmlB, PlatformIdentity::signature()),
        'the platform identity itself must be identical across tenants, unlike tenant branding');
});

// ── BookingAPI's own separate shell (private, reached via Reflection) ──

unit('BookingAPI::brandedEmailShell(): platform signature renders exactly once, after the tenant site-name/year footer line', function () {
    $tenant  = slate_test_tenant(995400);
    $tenants = new TenantContext();
    $html = $tenants->runAs($tenant, function () {
        Database::setSetting('site_name', 'Booking Tenant');
        $m = new \ReflectionMethod(\BookingAPI::class, 'brandedEmailShell');
        $m->setAccessible(true);
        return $m->invoke(null, '<tr><td>hello</td></tr>', 'preheader');
    });

    assert_eq(1, substr_count($html, PlatformIdentity::signature()));
    assert_true(str_contains($html, 'Booking Tenant'));

    $tenantLinePos = strpos($html, 'Booking Tenant');
    $platformPos   = strpos($html, PlatformIdentity::signature());
    assert_true($tenantLinePos !== false && $platformPos !== false, 'expected markers not found');
    assert_true($platformPos > $tenantLinePos, 'platform signature must render after the tenant site-name/year footer line');
});

unit('BookingAPI::brandedEmailShell(): with no tenant branding configured, the platform signature still renders and the email does not break', function () {
    $tenant  = slate_test_tenant(995500);
    $tenants = new TenantContext();
    $html = $tenants->runAs($tenant, function () {
        $m = new \ReflectionMethod(\BookingAPI::class, 'brandedEmailShell');
        $m->setAccessible(true);
        return $m->invoke(null, '<tr><td>hello</td></tr>');
    });

    assert_eq(1, substr_count($html, PlatformIdentity::signature()), 'missing tenant branding must not remove the platform signature');
    assert_true(str_contains($html, '<!DOCTYPE html>'), 'the email must still render as a complete document');
});
