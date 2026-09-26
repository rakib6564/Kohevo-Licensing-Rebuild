<?php
/**
 * Licensing — Phase 13 legacy-license policy.
 *
 * Legacy licenses live in `licensing_installs` (+ `licensing_plans.
 * entitlements_json`, `licensing_installation_bindings`) and still check in
 * through LicensingAPI::handleLegacyCheckIn(), unchanged. What Phase 13
 * removes is the legacy admin's ability to GRANT: the commercial authority
 * for anything new is Plan → License → Installation → Entitlements
 * (licensing_licenses & co., admin/licenses.php). The legacy screens may
 * only wind an existing legacy license down.
 *
 *   refused  — issuing a new legacy license, regenerating its key (a new
 *              credential), resetting its bindings (a new activation),
 *              moving it to trial/active, pushing expires_at later or
 *              removing it, raising activation_limit, adding a legacy
 *              plan entitlement.
 *   allowed  — expired/suspended/revoked/cancelled, an earlier expiry, a
 *              lower activation limit, removing a legacy plan entitlement,
 *              label edits, delete.
 *
 * Pure functions, no database access. See
 * docs/03-implementation/PHASE-13-LEGACY-HANDLING.md.
 */

final class LegacyLicensePolicy
{
    /** Statuses the legacy screens may still set — never trial/active. */
    public const RESTRICTIVE_STATUSES = ['expired', 'suspended', 'revoked', 'cancelled'];

    public static function allowsStatus(string $status): bool
    {
        return in_array($status, self::RESTRICTIVE_STATUSES, true);
    }

    /**
     * The expiry to store for a requested change: the request when it ends
     * the license no later than today's value, otherwise the current value.
     * NULL means "never expires", so it is later than any date.
     *
     * @return array{0:?string,1:bool} [value to store, whether the request was refused]
     */
    public static function restrictedExpiry(?string $current, ?string $requested): array
    {
        $current   = self::normalizeDate($current);
        $requested = self::normalizeDate($requested);
        if ($requested === $current) return [$current, false];
        if ($requested === null) return [$current, true];
        if ($current === null || strtotime($requested) <= strtotime($current)) return [$requested, false];
        return [$current, true];
    }

    /** @return array{0:int,1:bool} [value to store, whether the request was refused] */
    public static function restrictedActivationLimit(int $current, int $requested): array
    {
        $requested = max(1, $requested);
        return $requested > $current ? [$current, true] : [$requested, false];
    }

    /**
     * Legacy plan entitlements may lose keys, never gain them. A new plan
     * starts with none.
     *
     * @param string[] $current
     * @param string[] $requested
     * @return array{0:string[],1:bool} [keys to store, whether any were refused]
     */
    public static function restrictedEntitlements(array $current, array $requested): array
    {
        $kept = array_values(array_intersect(array_values(array_unique($requested)), $current));
        return [$kept, count($kept) !== count(array_unique($requested))];
    }

    private static function normalizeDate(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);
        if ($value === '') return null;
        $ts = strtotime($value);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}
