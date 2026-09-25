<?php
/**
 * Unit tests for the Phase 6 licensing/white-label Kohevo identity control.
 *
 * The DB-free unit harness (tests/unit/run.php: autoloader only, no
 * config.php) means the `Database` class genuinely does not exist here —
 * not a simulated outage, the real thing, exactly the same technique
 * tests/unit/PlatformSignatureErrorSurfaceTest.php already uses for Phase 4.
 * That makes this the strongest possible proof of PlatformIdentityPolicy's
 * central safety contract: a licensing-chain failure must never
 * accidentally suppress the required Kohevo platform identity.
 *
 * `current_tenant_id()` is defined here (guarded) because it normally lives
 * in includes/helpers.php, which this harness does not load — without a
 * fake, whiteLabelActive() would short-circuit on "no tenant id" before
 * ever reaching EntitlementService, which would prove a different (also
 * real, but less interesting) guard clause instead of the licensing-failure
 * catch itself.
 */

declare(strict_types=1);

use Slate\Services\Content\PlatformIdentity;
use Slate\Services\Content\PlatformIdentityPolicy;
use Slate\Services\Content\PlatformSignature;

if (!function_exists('current_tenant_id')) {
    function current_tenant_id(): int { return 424242; }
}

unit('PlatformIdentityPolicy::whiteLabelActive(): a total licensing-chain failure (Database class entirely unavailable) fails safe to false', function () {
    PlatformIdentityPolicy::resetCacheForTests();
    assert_false(PlatformIdentityPolicy::whiteLabelActive());
});

unit('PlatformIdentity::signature() and PlatformSignature::render() both remain visible under a total licensing-chain failure', function () {
    PlatformIdentityPolicy::resetCacheForTests();
    assert_eq('Powered by Kohevo', PlatformIdentity::signature());

    PlatformIdentityPolicy::resetCacheForTests();
    assert_true(str_contains(PlatformSignature::render(PlatformSignature::MODE_SIGNATURE), 'Powered by Kohevo'));

    PlatformIdentityPolicy::resetCacheForTests();
    assert_true(PlatformSignature::render(PlatformSignature::MODE_COMPACT) !== '');
});

unit('PlatformIdentityPolicy::whiteLabelActive(): memoizes per tenant for the process lifetime (does not re-run the chain on every call)', function () {
    PlatformIdentityPolicy::resetCacheForTests();
    $first  = PlatformIdentityPolicy::whiteLabelActive();
    $second = PlatformIdentityPolicy::whiteLabelActive();
    assert_eq($first, $second);
});

// ── Structural regression guards ────────────────────────────

unit('EntitlementService.php: canAccess() still gates on PluginLoader::isActive() (unchanged), and canAccessCapability() deliberately does not', function () {
    $src = file_get_contents(__DIR__ . '/../../src/Services/Licensing/EntitlementService.php');
    assert_true(str_contains($src, 'public static function canAccess(int $tenantId, string $featureKey): bool'));
    assert_true(str_contains($src, 'public static function canAccessCapability(int $tenantId, string $featureKey): bool'));

    // Isolate each method's own BODY (after its signature, before the next
    // method's docblock/signature starts) rather than counting the literal
    // string across the whole file, since the class-level header docblock
    // legitimately describes the concept in prose too.
    $canAccessBodyStart           = strpos($src, 'public static function canAccess(int $tenantId, string $featureKey): bool');
    $canAccessCapabilityBodyStart = strpos($src, 'public static function canAccessCapability(int $tenantId, string $featureKey): bool');
    $licensedForFeatureStart      = strpos($src, 'private static function licensedForFeature(');
    assert_true($canAccessBodyStart !== false && $canAccessCapabilityBodyStart !== false && $licensedForFeatureStart !== false);

    $canAccessBody           = substr($src, $canAccessBodyStart, $canAccessCapabilityBodyStart - $canAccessBodyStart);
    $canAccessCapabilityBody = substr($src, $canAccessCapabilityBodyStart, $licensedForFeatureStart - $canAccessCapabilityBodyStart);

    assert_true(str_contains($canAccessBody, 'PluginLoader::isActive'), 'canAccess() must still gate on plugin activation');
    assert_false(str_contains($canAccessCapabilityBody, 'PluginLoader::isActive'), 'canAccessCapability() must not gate on plugin activation — white_label is not a plugin slug');
});

unit('PlatformIdentity.php / PlatformSignature.php: white_label gating is confined to signature()/render() — the asset/name getters stay unconditional', function () {
    $identitySrc  = file_get_contents(__DIR__ . '/../../src/Services/Content/PlatformIdentity.php');
    $signatureSrc = file_get_contents(__DIR__ . '/../../src/Services/Content/PlatformSignature.php');

    assert_eq(1, substr_count($identitySrc, 'PlatformIdentityPolicy::whiteLabelActive()'), 'PlatformIdentity must consult the policy exactly once (inside signature())');
    assert_eq(1, substr_count($signatureSrc, 'PlatformIdentityPolicy::whiteLabelActive()'), 'PlatformSignature must consult the policy exactly once (inside render())');

    foreach (['markUrl', 'wordmarkUrl', 'wordmarkDarkUrl', 'faviconUrl', 'name'] as $unconditional) {
        $fnPos = strpos($identitySrc, "function $unconditional(");
        assert_true($fnPos !== false, "expected PlatformIdentity::$unconditional to exist");
    }
    assert_false(str_contains($identitySrc, "function markUrl(): string\n    {\n        if (PlatformIdentityPolicy"), 'markUrl() must remain unconditional — only signature() is gated');
});

unit('PlatformIdentityPolicy.php: no schema/tenant-branding source is read for the licensing decision (only EntitlementService)', function () {
    $src = file_get_contents(__DIR__ . '/../../src/Services/Content/PlatformIdentityPolicy.php');
    assert_true(str_contains($src, 'EntitlementService::canAccessCapability'));
    assert_false(str_contains($src, 'TenantBranding'));
    assert_false(str_contains($src, "Database::setting('site_name')"));
});
