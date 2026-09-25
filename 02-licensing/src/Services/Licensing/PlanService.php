<?php
/**
 * Slate — PlanService (Phase 1E C1: platform-level Plans).
 *
 * Owns platform_plans + plan_entitlements (migration 0015). Not the same
 * concept as plugins/membership's membership_plans (a tenant's own plans
 * sold to its end customers) — see that migration's own header comment for
 * the distinction. Plain Database:: calls, matching TenantService: these
 * tables aren't tenant-scoped (a plan belongs to the platform, not to any
 * one tenant), so Repository's automatic current_tenant_id() filtering
 * would not apply here even if used.
 *
 * Layer: Services — depends on Data (Database) + Audit only.
 */

declare(strict_types=1);

namespace Slate\Services\Licensing;

final class PlanService
{
    /** All plans, active or not, ordered for display. */
    public static function list(): array
    {
        // anti-drift-ignore: TENANT — platform_plans belongs to the platform, not any one tenant; there is nothing to scope by
        return \Database::rows("SELECT * FROM platform_plans ORDER BY sort_order ASC, id ASC");
    }

    public static function find(int $planId): ?array
    {
        // anti-drift-ignore: TENANT — platform_plans belongs to the platform, not any one tenant
        $row = \Database::row("SELECT * FROM platform_plans WHERE id = ?", [$planId]);
        return $row ?: null;
    }

    /** Feature keys this plan entitles, where enabled=1. */
    public static function entitlementsFor(int $planId): array
    {
        // anti-drift-ignore: TENANT — plan_entitlements belongs to the platform's plan catalogue, not any one tenant
        $rows = \Database::rows(
            "SELECT feature_key FROM plan_entitlements WHERE plan_id = ? AND enabled = 1 ORDER BY id ASC",
            [$planId]
        );
        return array_column($rows, 'feature_key');
    }

    /** How many tenants currently hold this plan. */
    public static function tenantCount(int $planId): int
    {
        // anti-drift-ignore: TENANT — counting across all tenants is the point (is this plan safe to delete?)
        return (int) \Database::value("SELECT COUNT(*) FROM tenant_profiles WHERE plan_id = ?", [$planId]);
    }

    /**
     * Create or update a plan (id present = update) and replace its
     * entitlement set atomically-enough for admin use (delete + re-insert,
     * matching the small, infrequent scale of a plan's own feature list).
     *
     * @param string[] $featureKeys
     */
    public static function save(?int $planId, array $data, array $featureKeys): int
    {
        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Plan name is required.');
        }
        if ($slug === '' || !preg_match('/^[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?$/', $slug)) {
            throw new \InvalidArgumentException('Slug must be lowercase letters, numbers, and hyphens only.');
        }

        // anti-drift-ignore: TENANT — platform_plans belongs to the platform, not any one tenant
        $existing = \Database::row("SELECT id FROM platform_plans WHERE slug = ? AND id != ?", [$slug, $planId ?? 0]);
        if ($existing) {
            throw new \InvalidArgumentException('That slug is already in use by another plan.');
        }

        $row = [
            'slug'        => mb_substr($slug, 0, 64),
            'name'        => mb_substr($name, 0, 120),
            'description' => trim((string)($data['description'] ?? '')) ?: null,
            'is_active'   => !empty($data['is_active']) ? 1 : 0,
            'sort_order'  => (int)($data['sort_order'] ?? 0),
            'limits'      => json_encode(self::sanitizeLimits((array)($data['limits'] ?? []))),
        ];

        if ($planId !== null) {
            // anti-drift-ignore: TENANT — platform_plans belongs to the platform, not any one tenant
            \Database::query(
                "UPDATE platform_plans SET slug=?, name=?, description=?, is_active=?, sort_order=?, limits=?, updated_at=? WHERE id=?",
                [...array_values($row), \slate_db_now(), $planId]
            );
            \AuditLog::record('plan.updated', "plan#$planId", ['slug' => $slug]);
        } else {
            // anti-drift-ignore: TENANT — platform_plans belongs to the platform, not any one tenant
            $planId = \Database::insert('platform_plans', $row);
            \AuditLog::record('plan.created', "plan#$planId", ['slug' => $slug]);
        }

        // anti-drift-ignore: TENANT — plan_entitlements belongs to the platform's plan catalogue, not any one tenant
        \Database::query("DELETE FROM plan_entitlements WHERE plan_id = ?", [$planId]);
        foreach (array_unique(array_filter($featureKeys, 'is_string')) as $feature) {
            \Database::insert('plan_entitlements', ['plan_id' => $planId, 'feature_key' => $feature, 'enabled' => 1]);
        }
        \AuditLog::record('entitlement.changed', "plan#$planId", ['features' => array_values($featureKeys)]);

        return (int)$planId;
    }

    /** Only keys with a real numeric value survive; blank/absent limits mean "unlimited". */
    private static function sanitizeLimits(array $limits): array
    {
        $allowed = ['max_users', 'max_customers'];
        $out = [];
        foreach ($allowed as $key) {
            $v = $limits[$key] ?? null;
            $out[$key] = ($v === '' || $v === null) ? null : max(0, (int)$v);
        }
        return $out;
    }

    /**
     * Delete a plan, unless a tenant is still assigned to it (matching the
     * established soft-guard pattern in plugins/membership/admin/plans.php:
     * never let a delete strand referential data).
     */
    public static function delete(int $planId): void
    {
        if (self::tenantCount($planId) > 0) {
            throw new \InvalidArgumentException('This plan is still assigned to at least one tenant and cannot be deleted.');
        }
        // anti-drift-ignore: TENANT — plan_entitlements/platform_plans belong to the platform's plan catalogue, not any one tenant
        \Database::query("DELETE FROM plan_entitlements WHERE plan_id = ?", [$planId]);
        \Database::query("DELETE FROM platform_plans WHERE id = ?", [$planId]);
        \AuditLog::record('plan.deleted', "plan#$planId");
    }
}
