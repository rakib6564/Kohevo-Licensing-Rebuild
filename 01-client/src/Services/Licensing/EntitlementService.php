<?php
/**
 * Kohevo commercial entitlement gate.
 *
 * Only the latest successfully verified remote_license_cache row is
 * authoritative, and only in remote mode. Local licenses, plans, and tenant
 * plan assignments remain in the database for administration and display,
 * but never grant an entitlement.
 *
 * Phase 13 (docs/03-implementation/PHASE-13-LEGACY-HANDLING.md): the
 * LICENSE_COMPAT_MODE=legacy flag is still recognised, so authorityMode()
 * can report 'legacy' for diagnostics, but it no longer grants access from
 * the local tables. A legacy or unconfigured installation is not entitled to
 * any module or capability until it holds verified remote state.
 */

declare(strict_types=1);

namespace Slate\Services\Licensing;

final class EntitlementService
{
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

    /** Remote mode only; 'legacy' and 'unconfigured' both fail closed. */
    private static function licensedForFeature(int $tenantId, string $featureKey): bool
    {
        if (self::authorityMode() !== 'remote') return false;
        return self::remoteAllows($tenantId, $featureKey);
    }

    private static function remoteAllows(int $tenantId, string $featureKey): bool
    {
        try {
            $state = (new SlateLicenseCacheStore($tenantId))->load();
            if (!self::remoteStateUsable($state)) return false;
            if (!in_array($featureKey, is_array($state['entitlements'] ?? null) ? $state['entitlements'] : [], true)) return false;
            return \Database::setting("entitlement.$featureKey.enabled", $tenantId) !== '0';
        } catch (\Throwable $e) {
            return false;
        }
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
                if (!self::remoteStateUsable($state)) return [];
                $keys = is_array($state['entitlements'] ?? null) ? $state['entitlements'] : [];
                return array_values(array_filter($keys, fn($key) => is_string($key) && self::remoteAllows($tenantId, $key)));
            } catch (\Throwable $e) { return []; }
        }
        return [];
    }

    /**
     * Phase 9: whether a trusted remote snapshot still grants module access
     * at all — the exact same answer the Global License Guard gives, from
     * the same CommercialLicenseWindow evaluation. Entitlements therefore
     * stay live through the pre-expiry warning and the 7-day commercial
     * grace period (08 §2; Phase 9 §12) and end at exactly the moment the
     * Guard locks the application — no module-specific grace timer, and no
     * earlier cut-off at expires_at. Suspended/revoked/stale/malformed
     * snapshots are denied exactly as before (the evaluator's precedence).
     */
    private static function remoteStateUsable(?array $state): bool
    {
        if ($state === null) return false;
        $window = CommercialLicenseWindow::evaluate(['found' => true, 'trusted' => true, 'data' => $state], time());
        return $window['allowed'] === true;
    }
}
