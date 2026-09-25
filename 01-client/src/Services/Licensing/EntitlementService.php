<?php
/**
 * Kohevo commercial entitlement gate.
 *
 * In remote mode, only the latest successfully verified remote_license_cache
 * row is authoritative. Local licenses, plans, and tenant plan assignments
 * remain available for administration and an explicitly time-bounded legacy
 * compatibility mode, but never override verified remote state.
 */

declare(strict_types=1);

namespace Slate\Services\Licensing;

final class EntitlementService
{
    private const LICENSED_STATUSES = ['trial', 'active'];
    private const REMOTE_GRACE_SECONDS = 7 * 86400;
    private const REMOTE_REQUIRED_ENV = ['LICENSE_SERVER_URL', 'LICENSE_SERVER_PUBLIC_KEY', 'LICENSE_PRODUCT', 'LICENSE_KEY'];

    public static function canAccess(int $tenantId, string $featureKey): bool
    {
        if (!\PluginLoader::isActive($featureKey)) return false;
        return self::licensedForFeature($tenantId, $featureKey);
    }

    public static function canAccessCapability(int $tenantId, string $featureKey): bool
    {
        return self::licensedForFeature($tenantId, $featureKey);
    }

    /** Returns remote, legacy, or unconfigured for diagnostics and tests. */
    public static function authorityMode(): string
    {
        if (self::remoteConfigured()) return 'remote';
        if (self::legacyCompatibilityEnabled()) return 'legacy';
        return 'unconfigured';
    }

    public static function remoteConfigured(): bool
    {
        foreach (self::REMOTE_REQUIRED_ENV as $key) {
            if (!function_exists('env') || trim((string) env($key, '')) === '') return false;
        }
        return true;
    }

    private static function licensedForFeature(int $tenantId, string $featureKey): bool
    {
        $mode = self::authorityMode();
        if ($mode === 'remote') return self::remoteAllows($tenantId, $featureKey);
        if ($mode === 'legacy') return self::legacyAllows($tenantId, $featureKey);
        return false;
    }

    private static function remoteAllows(int $tenantId, string $featureKey): bool
    {
        try {
            $state = (new SlateLicenseCacheStore($tenantId))->load();
            if ($state === null) return false;
            if (!in_array((string) ($state['status'] ?? ''), self::LICENSED_STATUSES, true)) return false;
            if (!empty($state['expires_at']) && strtotime((string) $state['expires_at']) <= time()) return false;
            if (!self::remoteStateFresh($state)) return false;
            if (!in_array($featureKey, is_array($state['entitlements'] ?? null) ? $state['entitlements'] : [], true)) return false;
            return \Database::setting("entitlement.$featureKey.enabled", $tenantId) !== '0';
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function legacyAllows(int $tenantId, string $featureKey): bool
    {
        $licenseStatus = LicenseService::effectiveStatus($tenantId);
        if (!in_array($licenseStatus, ['trial', 'active', 'none'], true)) return false;
        // anti-drift-ignore: TENANT — explicit tenant argument in compatibility path
        $planId = \Database::value('SELECT plan_id FROM tenant_profiles WHERE tenant_id = ?', [$tenantId]);
        if (empty($planId) || !in_array($featureKey, PlanService::entitlementsFor((int) $planId), true)) return false;
        return \Database::setting("entitlement.$featureKey.enabled", $tenantId) !== '0';
    }

    private static function legacyCompatibilityEnabled(): bool
    {
        if (!function_exists('env') || strtolower(trim((string) env('LICENSE_COMPAT_MODE', ''))) !== 'legacy') return false;
        $until = trim((string) env('LICENSE_COMPAT_UNTIL', ''));
        return $until !== '' && strtotime($until . (strlen($until) === 10 ? ' 23:59:59' : '')) !== false
            && strtotime($until . (strlen($until) === 10 ? ' 23:59:59' : '')) > time();
    }

    public static function enabledFeaturesFor(int $tenantId): array
    {
        if (self::authorityMode() === 'remote') {
            try {
                $state = (new SlateLicenseCacheStore($tenantId))->load();
                if ($state === null || !in_array((string) ($state['status'] ?? ''), self::LICENSED_STATUSES, true)
                    || (!empty($state['expires_at']) && strtotime((string) $state['expires_at']) <= time())
                    || !self::remoteStateFresh($state)) return [];
                $keys = is_array($state['entitlements'] ?? null) ? $state['entitlements'] : [];
                return array_values(array_filter($keys, fn($key) => is_string($key) && self::remoteAllows($tenantId, $key)));
            } catch (\Throwable $e) { return []; }
        }
        if (self::authorityMode() !== 'legacy') return [];
        $allFeatureKeys = array_unique(array_merge(...array_map(fn(array $p) => PlanService::entitlementsFor((int) $p['id']), PlanService::list())));
        return array_values(array_filter($allFeatureKeys, fn(string $key) => self::legacyAllows($tenantId, $key)));
    }

    private static function remoteStateFresh(array $state): bool
    {
        $fetchedAt = strtotime((string) ($state['fetched_at'] ?? ''));
        return $fetchedAt !== false && (time() - $fetchedAt) <= self::REMOTE_GRACE_SECONDS;
    }
}
