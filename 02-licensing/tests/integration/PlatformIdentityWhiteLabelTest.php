<?php
/**
 * Kohevo Brand Persistence System — Phase 6 (Licensing/White-Label Identity
 * Control).
 *
 * Full resolution-chain matrix for PlatformIdentityPolicy::whiteLabelActive()
 * and its two consumers (PlatformIdentity::signature(),
 * PlatformSignature::render()), through the REAL licensing services
 * (LicenseService/PlanService/EntitlementService) and real tenants, mirroring
 * the established fixture pattern in tests/integration/EntitlementServiceTest.php
 * and DashboardMetricsTest.php. Every scenario resets
 * PlatformIdentityPolicy's per-tenant memoization before asserting, since a
 * single test may change a tenant's licensing state mid-scenario.
 */

declare(strict_types=1);

use Slate\Services\Content\PlatformIdentity;
use Slate\Services\Content\PlatformIdentityPolicy;
use Slate\Services\Content\PlatformSignature;
use Slate\Services\Licensing\EntitlementService;
use Slate\Services\Licensing\LicenseService;
use Slate\Services\Licensing\PlanService;
use Slate\Services\Tenancy\TenantService;
use Slate\Tenancy\TenantContext;

/** A fresh tenant with no plan and no license — the "nothing configured yet" baseline. */
function piwt_bare_tenant(string $tag): int
{
    return TenantService::create(['name' => "White-Label $tag", 'slug' => 'piwt-' . strtolower($tag) . '-' . bin2hex(random_bytes(4))]);
}

function piwt_cleanup_tenant(int $tenantId): void
{
    Database::query("DELETE FROM licenses WHERE tenant_id = ?", [$tenantId]);
    Database::query("DELETE FROM settings WHERE tenant_id = ? AND (setting_key LIKE 'entitlement.%' OR setting_key IN ('site_name','brand_accent_color','brand_logo_path'))", [$tenantId]);
    Database::query("DELETE FROM tenant_profiles WHERE tenant_id = ?", [$tenantId]);
    Database::query("DELETE FROM tenants WHERE id = ?", [$tenantId]);
}

function piwt_cleanup_plan(int $planId): void
{
    Database::query("DELETE FROM plan_entitlements WHERE plan_id = ?", [$planId]);
    Database::query("DELETE FROM platform_plans WHERE id = ?", [$planId]);
}

/** Wires plan+active license so the tenant is fully entitled to 'white_label'. */
function piwt_entitle(int $tenantId, int $planId): void
{
    Database::query("UPDATE tenant_profiles SET plan_id = ? WHERE tenant_id = ?", [$planId, $tenantId]);
    LicenseService::issue($tenantId, $planId, ['status' => 'active']);
}

function piwt_assert_visible(int $tenantId, string $why): void
{
    $tenants = new TenantContext();
    $tenants->runAs($tenantId, function () use ($why) {
        PlatformIdentityPolicy::resetCacheForTests();
        assert_false(PlatformIdentityPolicy::whiteLabelActive(), $why);
        assert_eq('Powered by Kohevo', PlatformIdentity::signature(), $why);
        assert_true(str_contains(PlatformSignature::render(PlatformSignature::MODE_SIGNATURE), 'Powered by Kohevo'), $why);
        assert_true(PlatformSignature::render(PlatformSignature::MODE_COMPACT) !== '', $why . ' (compact mark must also render)');
    });
}

function piwt_assert_suppressed(int $tenantId, string $why): void
{
    $tenants = new TenantContext();
    $tenants->runAs($tenantId, function () use ($why) {
        PlatformIdentityPolicy::resetCacheForTests();
        assert_true(PlatformIdentityPolicy::whiteLabelActive(), $why);
        assert_eq('', PlatformIdentity::signature(), $why);
        assert_eq('', PlatformSignature::render(PlatformSignature::MODE_SIGNATURE), $why);
        assert_eq('', PlatformSignature::render(PlatformSignature::MODE_STANDARD), $why);
        assert_eq('', PlatformSignature::render(PlatformSignature::MODE_COMPACT), $why . ' (compact mark must ALSO be suppressed, not just the "Powered by" text)');
    });
}

// ── No entitlement ───────────────────────────────────────────

unit('white_label: a tenant with no plan and no license at all keeps Kohevo visible', function () {
    $tenantId = piwt_bare_tenant('NoEntitlement');
    try {
        piwt_assert_visible($tenantId, 'no entitlement configured');
    } finally {
        piwt_cleanup_tenant($tenantId);
    }
});

// ── Entitlement present ──────────────────────────────────────

unit('white_label: a tenant with an active license on a plan entitled to white_label suppresses Kohevo (mark AND text, every PlatformSignature mode)', function () {
    $tenantId = piwt_bare_tenant('Entitled');
    $planId = PlanService::save(null, ['name' => 'WL Plan', 'slug' => 'piwt-wl-' . bin2hex(random_bytes(4))], ['white_label']);
    try {
        piwt_entitle($tenantId, $planId);
        piwt_assert_suppressed($tenantId, 'fully entitled tenant');
    } finally {
        piwt_cleanup_tenant($tenantId);
        piwt_cleanup_plan($planId);
    }
});

// ── Invalid / expired license ────────────────────────────────

unit('white_label: a suspended or revoked license keeps Kohevo visible even on an otherwise-entitled plan', function () {
    $tenantId = piwt_bare_tenant('Suspended');
    $planId = PlanService::save(null, ['name' => 'WL Plan Susp', 'slug' => 'piwt-wl-susp-' . bin2hex(random_bytes(4))], ['white_label']);
    try {
        Database::query("UPDATE tenant_profiles SET plan_id = ? WHERE tenant_id = ?", [$planId, $tenantId]);
        $license = LicenseService::issue($tenantId, $planId, ['status' => 'active']);
        piwt_assert_suppressed($tenantId, 'sanity: active + entitled = suppressed');

        LicenseService::suspend($license['id'], 'test');
        piwt_assert_visible($tenantId, 'a suspended license must fail closed for white_label');

        LicenseService::activate($license['id']);
        LicenseService::revoke($license['id'], 'test');
        piwt_assert_visible($tenantId, 'a revoked license must fail closed for white_label');
    } finally {
        piwt_cleanup_tenant($tenantId);
        piwt_cleanup_plan($planId);
    }
});

unit('white_label: an expired license keeps Kohevo visible even on an otherwise-entitled plan', function () {
    $tenantId = piwt_bare_tenant('Expired');
    $planId = PlanService::save(null, ['name' => 'WL Plan Exp', 'slug' => 'piwt-wl-exp-' . bin2hex(random_bytes(4))], ['white_label']);
    try {
        Database::query("UPDATE tenant_profiles SET plan_id = ? WHERE tenant_id = ?", [$planId, $tenantId]);
        LicenseService::issue($tenantId, $planId, ['status' => 'active', 'expires_at' => date('Y-m-d H:i:s', time() - 3600)]);
        piwt_assert_visible($tenantId, 'an expired license must fail closed for white_label (lazy expiry via LicenseService::effectiveStatus())');
    } finally {
        piwt_cleanup_tenant($tenantId);
        piwt_cleanup_plan($planId);
    }
});

// ── Missing plan ──────────────────────────────────────────────

unit('white_label: an active license with no plan assigned keeps Kohevo visible', function () {
    $tenantId = piwt_bare_tenant('NoPlan');
    try {
        LicenseService::issue($tenantId, null, ['status' => 'active']);
        piwt_assert_visible($tenantId, 'no plan assigned means nothing to derive the entitlement from');
    } finally {
        piwt_cleanup_tenant($tenantId);
    }
});

// ── Tenant isolation ──────────────────────────────────────────

unit('white_label: tenant isolation — an entitled tenant A and a bare tenant B behave independently', function () {
    $tenantA = piwt_bare_tenant('IsoA');
    $tenantB = piwt_bare_tenant('IsoB');
    $planId  = PlanService::save(null, ['name' => 'WL Plan Iso', 'slug' => 'piwt-wl-iso-' . bin2hex(random_bytes(4))], ['white_label']);
    try {
        piwt_entitle($tenantA, $planId);
        // tenant B deliberately left bare.

        piwt_assert_suppressed($tenantA, 'tenant A is entitled');
        piwt_assert_visible($tenantB, "tenant B's bare state must be unaffected by tenant A's entitlement");
    } finally {
        piwt_cleanup_tenant($tenantA);
        piwt_cleanup_tenant($tenantB);
        piwt_cleanup_plan($planId);
    }
});

// ── Tenant branding manipulation must not grant white_label ───

unit('white_label: changing ordinary tenant branding settings (site_name/logo/accent) never grants white_label on its own', function () {
    $tenantId = piwt_bare_tenant('BrandingOnly');
    try {
        $tenants = new TenantContext();
        $tenants->runAs($tenantId, function () {
            Database::setSetting('site_name', 'Totally Rebranded Co');
            Database::setSetting('brand_accent_color', '#ff00ff');
            Database::setSetting('brand_logo_path', '/uploads/fake-logo.png');
        });
        piwt_assert_visible($tenantId, 'ordinary branding settings must never be a source of licensing truth');
    } finally {
        piwt_cleanup_tenant($tenantId);
    }
});

// ── Per-tenant toggle can revoke an otherwise-entitled tenant ──

unit('white_label: the per-tenant "enabled" toggle can turn white_label back off without touching plan or license', function () {
    $tenantId = piwt_bare_tenant('Toggle');
    $planId = PlanService::save(null, ['name' => 'WL Plan Toggle', 'slug' => 'piwt-wl-toggle-' . bin2hex(random_bytes(4))], ['white_label']);
    try {
        piwt_entitle($tenantId, $planId);
        piwt_assert_suppressed($tenantId, 'sanity: fully entitled');

        Database::setSetting('entitlement.white_label.enabled', '0', $tenantId);
        piwt_assert_visible($tenantId, 'the tenant-level toggle must be able to turn white_label back off');

        Database::setSetting('entitlement.white_label.enabled', '1', $tenantId);
        piwt_assert_suppressed($tenantId, 'turning the toggle back on restores the entitlement');
    } finally {
        piwt_cleanup_tenant($tenantId);
        piwt_cleanup_plan($planId);
    }
});

// ── Client-side manipulation cannot grant white_label ──────────

unit('white_label: PlatformIdentityPolicy reads no request input at all (no $_GET/$_POST/$_COOKIE/$_REQUEST) — a client cannot influence the decision', function () {
    $src = file_get_contents(dirname(__DIR__, 2) . '/src/Services/Content/PlatformIdentityPolicy.php');
    foreach (['$_GET', '$_POST', '$_COOKIE', '$_REQUEST', 'HTTP_'] as $superglobal) {
        assert_false(str_contains($src, $superglobal), "PlatformIdentityPolicy must never read $superglobal");
    }
});

// ── No schema change: the freeform feature_key column absorbs 'white_label' ──

unit('white_label: no schema change was required — plan_entitlements.feature_key already accepted a freeform value, confirmed by successfully storing/reading it', function () {
    $planId = PlanService::save(null, ['name' => 'WL Schema Probe', 'slug' => 'piwt-wl-schema-' . bin2hex(random_bytes(4))], ['white_label']);
    try {
        assert_true(in_array('white_label', PlanService::entitlementsFor($planId), true));
    } finally {
        piwt_cleanup_plan($planId);
    }
});

// ── Cross-phase regression: shell (Phase 2), error page (Phase 4), email (Phase 5) ──

unit('white_label regression: admin shell (Phase 2) and error page (Phase 4) both stay visible for a non-entitled tenant, and both suppress for an entitled one', function () {
    $tenantId = piwt_bare_tenant('CrossPhaseVisible');
    try {
        $tenants = new TenantContext();
        $tenants->runAs($tenantId, function () {
            PlatformIdentityPolicy::resetCacheForTests();
            // Phase 2's admin shell call site.
            assert_true(str_contains(PlatformSignature::render(PlatformSignature::MODE_STANDARD), 'Kohevo'));
            // Phase 4's error page renderer, exercised directly (same function admin/customer error pages call).
            require_once dirname(__DIR__, 2) . '/includes/error_page.php';
            ob_start();
            slate_render_error(404, 'Not found', 'Test.');
            $html = (string) ob_get_clean();
            assert_true(str_contains($html, 'Powered by Kohevo'));
        });
    } finally {
        piwt_cleanup_tenant($tenantId);
    }
});

unit('white_label regression: an entitled tenant suppresses Kohevo on the error page (Phase 4) and in a BrandedEmail (Phase 5) alike', function () {
    $tenantId = piwt_bare_tenant('CrossPhaseSuppressed');
    $planId = PlanService::save(null, ['name' => 'WL Plan CrossPhase', 'slug' => 'piwt-wl-cross-' . bin2hex(random_bytes(4))], ['white_label']);
    try {
        piwt_entitle($tenantId, $planId);

        $tenants = new TenantContext();
        $tenants->runAs($tenantId, function () {
            PlatformIdentityPolicy::resetCacheForTests();

            require_once dirname(__DIR__, 2) . '/includes/error_page.php';
            ob_start();
            slate_render_error(404, 'Not found', 'Test.');
            $html = (string) ob_get_clean();
            assert_false(str_contains($html, 'Powered by Kohevo'), 'error page must suppress the platform signature for an entitled tenant');
            assert_true(str_contains($html, 'Error 404'), 'the error page itself must still render correctly');

            $emailHtml = \Slate\Services\Notifications\BrandedEmail::shell(['title' => 'x'], '<tr><td>x</td></tr>');
            assert_false(str_contains($emailHtml, 'Powered by Kohevo'), 'BrandedEmail must suppress the platform signature line for an entitled tenant');
            assert_true(str_contains($emailHtml, '<!DOCTYPE html>'), 'the email must still render as a complete, valid document');
        });
    } finally {
        piwt_cleanup_tenant($tenantId);
        piwt_cleanup_plan($planId);
    }
});
