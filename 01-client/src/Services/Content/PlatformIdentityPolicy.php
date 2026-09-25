<?php
/**
 * Slate — PlatformIdentityPolicy: the one place that decides whether the
 * CURRENT tenant is licensed to suppress the Kohevo platform identity
 * (Kohevo Brand Persistence System — Phase 6).
 *
 * PlatformIdentity/PlatformSignature stay presentation-only and never touch
 * licensing or the database themselves (see their own docblocks — this is
 * deliberate: a low-level presentation component must not own licensing
 * infrastructure). This class is the only bridge between them and
 * EntitlementService. Canonical feature key: 'white_label', decided through
 * EntitlementService::canAccessCapability() — never a second entitlement
 * model.
 *
 * Fails safe by construction: no tenant context, a missing licensing class,
 * or ANY exception from the licensing chain (a real possibility — e.g.
 * Phase 4's DB-unavailable error-page scenario) is treated as "white-label
 * NOT available", i.e. Kohevo stays visible. A licensing failure must never
 * accidentally suppress the required platform identity.
 *
 * Memoized per tenant for the lifetime of the request/process — the same
 * established pattern as Auth::isPlatformAdmin()'s per-request cache. The
 * licensing chain this delegates to does several uncached DB round trips
 * (see EntitlementService/LicenseService), and PlatformSignature::render()/
 * PlatformIdentity::signature() are each called more than once per page
 * (header + footer, etc.) — without this, every one of those calls would
 * re-run the full chain.
 */

declare(strict_types=1);

namespace Slate\Services\Content;

final class PlatformIdentityPolicy
{
    private const FEATURE_KEY = 'white_label';

    /** @var array<int,bool> */
    private static array $cache = [];

    /**
     * Whether the current tenant is licensed to suppress the Kohevo
     * platform identity. See the class docblock for the fail-safe contract.
     */
    public static function whiteLabelActive(): bool
    {
        $tenantId = self::currentTenantId();
        if ($tenantId <= 0) {
            return false;
        }
        if (array_key_exists($tenantId, self::$cache)) {
            return self::$cache[$tenantId];
        }

        $active = false;
        try {
            if (class_exists('\Slate\Services\Licensing\EntitlementService')) {
                $active = \Slate\Services\Licensing\EntitlementService::canAccessCapability($tenantId, self::FEATURE_KEY);
            }
        } catch (\Throwable $e) {
            $active = false;
        }

        return self::$cache[$tenantId] = $active;
    }

    private static function currentTenantId(): int
    {
        if (!function_exists('current_tenant_id')) {
            return 0;
        }
        try {
            return (int) current_tenant_id();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Test-only: clear the per-tenant memoization so a test can change licensing state mid-run and observe it. */
    public static function resetCacheForTests(): void
    {
        self::$cache = [];
    }
}
