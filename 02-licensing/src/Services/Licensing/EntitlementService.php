<?php
/**
 * Slate — EntitlementService (Phase 1E C1/C2: the single entitlement gate).
 *
 * Every layer of the resolution chain is checked; none is ever skipped:
 *
 *   Installed?  -> PluginLoader::isActive($featureKey)
 *   Platform allowed? -> same as Installed in this phase: there is no
 *       separate platform-wide per-feature toggle beyond plugin activation
 *       itself (activate/deactivate on admin/plugins.php IS the platform-
 *       allowed switch) — documented here rather than left implicit.
 *   Tenant licensed? -> LicenseService::effectiveStatus($tenantId) must not
 *       be a blocking status. A tenant with NO license at all ('none') is
 *       treated as unrestricted for backward compatibility — this is a new
 *       concept being layered onto an app that already has real tenants
 *       with no license issued yet; enforcement only engages once a license
 *       actually exists (see the C1/C2 approved design notes).
 *   Plan entitled? -> PlanService::entitlementsFor($planId) contains the key.
 *       A tenant with no plan assigned is NOT entitled to anything beyond
 *       what core (non-plugin) admin pages already provide — there is no
 *       plan to derive entitlements from.
 *   Tenant enabled? -> a per-tenant settings toggle
 *       ("entitlement.$featureKey.enabled", default 1) — lets a tenant (or
 *       a platform admin acting for them) turn OFF a feature they are
 *       otherwise entitled to, without touching their plan or license.
 *
 * "Permission allowed" (the resolution chain's final layer, per the
 * approved design) is deliberately NOT folded in here: this codebase's
 * permission keys don't derive cleanly from a plugin slug (e.g. the
 * `stripe-payment` plugin's permissions are `stripe.manage_settings`/
 * `stripe.manage_charges`, not `stripe-payment.*`), so unifying that check
 * here would mean guessing a mapping rather than reusing a real one. Every
 * existing Auth::can()/requirePerm() call stays exactly as it is; callers
 * apply EntitlementService::canAccess() as an ADDITIONAL gate stacked on
 * top of their own existing permission check, never a replacement for it.
 *
 * Denial is silent throughout, per the approved design: callers hide the
 * nav item and let a direct hit fall through to a plain 403 — no "upgrade
 * your plan" messaging in this phase.
 *
 * "Restricted" (an expired/suspended/revoked/cancelled license) is not a
 * separate code path: this service only ever gates PLUGIN-backed features
 * (feature_key values corresponding to plugin slugs). Core admin
 * functionality (users, roles, settings, audit log, the dashboard itself)
 * was never routed through canAccess() to begin with, so it remains fully
 * available regardless of license state — satisfying "data remains safe /
 * platform administrators always retain access" without a separate state
 * machine. A non-active license only removes access to the *plugin*
 * features it would otherwise entitle.
 *
 * Layer: Services — depends on Data (Database), the PluginLoader kernel
 * module, and the sibling Plan/License services.
 */

declare(strict_types=1);

namespace Slate\Services\Licensing;

final class EntitlementService
{
    /** License statuses that still count as "licensed" for entitlement purposes. */
    private const LICENSED_STATUSES = ['trial', 'active', 'none'];

    public static function canAccess(int $tenantId, string $featureKey): bool
    {
        // 1. Installed? / 2. Platform allowed? (identical check this phase)
        if (!\PluginLoader::isActive($featureKey)) {
            return false;
        }

        return self::licensedForFeature($tenantId, $featureKey);
    }

    /**
     * Like canAccess(), but for a core platform capability that has no
     * PluginLoader entry at all — e.g. 'white_label' (Kohevo Brand
     * Persistence System — Phase 6). canAccess()'s layer 1/2 is a plugin
     * activation gate keyed on the feature key; a capability like
     * 'white_label' is never a plugin slug, so that gate would always
     * return false and permanently deny it regardless of licensing — not a
     * hypothetical, see plan_entitlements' own seeded feature_key values
     * (db/migrations/0015_platform_plans.php), all real plugin slugs. This
     * runs only the license -> plan -> tenant-toggle chain (layers 3-5),
     * unchanged, on the SAME plan_entitlements table via PlanService — a
     * capability entitlement is granted/managed exactly like a plugin
     * entitlement (PlanService::save() from admin/plans.php), no new data
     * model or second entitlement system.
     */
    public static function canAccessCapability(int $tenantId, string $featureKey): bool
    {
        return self::licensedForFeature($tenantId, $featureKey);
    }

    /** Layers 3-5 of the resolution chain: license -> plan -> tenant-toggle. Shared by canAccess() and canAccessCapability(). */
    private static function licensedForFeature(int $tenantId, string $featureKey): bool
    {
        // 3. Tenant licensed?
        $licenseStatus = LicenseService::effectiveStatus($tenantId);
        if (!in_array($licenseStatus, self::LICENSED_STATUSES, true)) {
            return false;
        }

        // 4. Plan entitled?
        // anti-drift-ignore: TENANT — looking up the SPECIFIC tenant's own profile by tenant_id, an explicit parameter, not the caller's ambient current_tenant_id()
        $planId = \Database::value("SELECT plan_id FROM tenant_profiles WHERE tenant_id = ?", [$tenantId]);
        if (empty($planId)) {
            return false; // no plan assigned: nothing to derive entitlements from
        }
        if (!in_array($featureKey, PlanService::entitlementsFor((int)$planId), true)) {
            return false;
        }

        // 5. Tenant enabled?
        if (\Database::setting("entitlement.$featureKey.enabled", $tenantId) === '0') {
            return false;
        }

        return true;
    }

    /** Every feature_key the given tenant currently has real access to (all 5 layers). Used for dashboard/UI display. */
    public static function enabledFeaturesFor(int $tenantId): array
    {
        $allFeatureKeys = array_unique(array_merge(
            ...array_map(fn(array $p) => PlanService::entitlementsFor((int)$p['id']), PlanService::list())
        ));
        return array_values(array_filter($allFeatureKeys, fn(string $key) => self::canAccess($tenantId, $key)));
    }
}
