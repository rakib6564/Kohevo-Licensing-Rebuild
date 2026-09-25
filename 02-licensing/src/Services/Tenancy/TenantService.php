<?php
/**
 * Slate — TenantService (Phase 1E B1: platform-level tenant management).
 *
 * `tenants` and `tenant_profiles` are not tenant-SCOPED tables (they ARE the
 * tenant records) — Slate\Data\Repository's automatic current_tenant_id()
 * filtering does not apply to them and would be actively wrong here (a
 * platform admin managing tenants must see and act on every tenant, not just
 * their own). This service therefore uses plain Database:: calls directly,
 * matching the established convention already used by AuditLog and
 * PluginLoader for similar platform-wide concerns — not Repository.
 *
 * Every cross-tenant query here is genuinely, structurally cross-tenant by
 * design (there is no "current tenant" to scope to when listing all
 * tenants), annotated for bin/anti-drift.php accordingly.
 *
 * Layer: Services — depends on Data (Database) + Audit only.
 */

declare(strict_types=1);

namespace Slate\Services\Tenancy;

final class TenantService
{
    public const LIFECYCLE_STATUSES = ['trial', 'active', 'suspended', 'deactivated', 'archived'];

    /**
     * Valid lifecycle transitions. Deliberately permissive about which
     * status can move to which — the real-world reasons to move a tenant
     * between states (billing failure, manual reactivation, an operator
     * correcting a mistake) don't fit a strict linear order — but every
     * transition must still go through transitionStatus() so it is always
     * validated (against LIFECYCLE_STATUSES) and always audited.
     */

    /**
     * Create a new tenant + its profile row together. Returns the new
     * tenant id.
     */
    public static function create(array $data): int
    {
        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Tenant name is required.');
        }
        if ($slug === '' || !preg_match('/^[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?$/', $slug)) {
            throw new \InvalidArgumentException('Slug must be lowercase letters, numbers, and hyphens only.');
        }

        // anti-drift-ignore: TENANT — tenants is the tenant table itself, not a tenant-scoped child table; there is no tenant_id to scope by
        $existing = \Database::value("SELECT id FROM tenants WHERE slug = ?", [$slug]);
        if ($existing) {
            throw new \InvalidArgumentException('That slug is already in use.');
        }

        $lifecycle = (string)($data['lifecycle_status'] ?? 'trial');
        if (!in_array($lifecycle, self::LIFECYCLE_STATUSES, true)) {
            $lifecycle = 'trial';
        }

        // anti-drift-ignore: TENANT — creating the tenant record itself; nothing to scope to yet
        $tenantId = \Database::insert('tenants', [
            'name'   => mb_substr($name, 0, 120),
            'slug'   => mb_substr($slug, 0, 64),
            'status' => 'active', // legacy status column keeps its existing narrower meaning
        ]);

        $defaultPlugins = $data['default_plugins'] ?? null;
        // anti-drift-ignore: TENANT — tenant_profiles is keyed 1:1 by tenant_id, which is the value being inserted, not something to additionally filter by
        \Database::insert('tenant_profiles', [
            'tenant_id'        => $tenantId,
            'owner_name'       => trim((string)($data['owner_name'] ?? '')) ?: null,
            'owner_email'      => trim((string)($data['owner_email'] ?? '')) ?: null,
            'lifecycle_status' => $lifecycle,
            'trial_ends_at'    => $data['trial_ends_at'] ?? null,
            'timezone'         => trim((string)($data['timezone'] ?? '')) ?: 'UTC',
            'locale'           => trim((string)($data['locale'] ?? '')) ?: 'en',
            'default_plugins'  => $defaultPlugins !== null ? json_encode(array_values((array)$defaultPlugins)) : null,
        ]);

        \AuditLog::record('tenant.created', "tenant#$tenantId", ['name' => $name, 'slug' => $slug]);

        return (int)$tenantId;
    }

    /** One tenant, joined with its profile. Null if not found. */
    public static function find(int $tenantId): ?array
    {
        // anti-drift-ignore: TENANT — looks up an arbitrary tenant by its own id, for platform-admin management; not scoped to the caller's current tenant by design
        $row = \Database::row(
            "SELECT t.id, t.name, t.slug, t.status, t.created_at,
                    p.owner_name, p.owner_email, p.lifecycle_status, p.trial_ends_at,
                    p.timezone, p.locale, p.default_plugins, p.updated_at AS profile_updated_at
               FROM tenants t
          LEFT JOIN tenant_profiles p ON p.tenant_id = t.id
              WHERE t.id = ?",
            [$tenantId]
        );
        return $row ?: null;
    }

    /**
     * All tenants (optionally filtered), joined with their profile, newest
     * first. Platform-only — deliberately not tenant-scoped.
     *
     * @param array{status?:string,search?:string} $filters
     */
    public static function list(array $filters = []): array
    {
        $where  = [];
        $params = [];

        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '' && in_array($status, self::LIFECYCLE_STATUSES, true)) {
            $where[] = 'p.lifecycle_status = ?';
            $params[] = $status;
        }

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(t.name LIKE ? OR t.slug LIKE ? OR p.owner_email LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $sql = "SELECT t.id, t.name, t.slug, t.status, t.created_at,
                       p.owner_name, p.owner_email, p.lifecycle_status, p.trial_ends_at,
                       p.timezone, p.locale,
                       (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS user_count,
                       (SELECT COUNT(*) FROM customers c WHERE c.tenant_id = t.id) AS customer_count
                  FROM tenants t
             LEFT JOIN tenant_profiles p ON p.tenant_id = t.id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY t.created_at DESC, t.id DESC';

        // anti-drift-ignore: TENANT — this IS the cross-tenant listing platform admins need; there is no single current_tenant_id() to scope a "list every tenant" query to
        return \Database::rows($sql, $params);
    }

    /**
     * Move a tenant to a new lifecycle status. Validates the target status
     * and that the tenant exists; always audits. Returns false (no-op) if
     * the tenant is already in that status.
     */
    public static function transitionStatus(int $tenantId, string $newStatus): bool
    {
        if (!in_array($newStatus, self::LIFECYCLE_STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown tenant lifecycle status: $newStatus");
        }

        $current = self::find($tenantId);
        if ($current === null) {
            throw new \InvalidArgumentException("No such tenant: $tenantId");
        }
        if (($current['lifecycle_status'] ?? null) === $newStatus) {
            return false;
        }

        // anti-drift-ignore: TENANT — updates a specific tenant's own profile row by tenant_id, the platform-management equivalent of "by primary key"; not scoped to the caller's own tenant
        \Database::query(
            "UPDATE tenant_profiles SET lifecycle_status = ?, updated_at = ? WHERE tenant_id = ?",
            [$newStatus, \slate_db_now(), $tenantId]
        );

        \AuditLog::record('tenant.status_changed', "tenant#$tenantId", [
            'from' => $current['lifecycle_status'] ?? null,
            'to'   => $newStatus,
        ]);

        return true;
    }

    /** Update the editable profile fields (not lifecycle_status — use transitionStatus()). */
    public static function updateProfile(int $tenantId, array $data): void
    {
        $current = self::find($tenantId);
        if ($current === null) {
            throw new \InvalidArgumentException("No such tenant: $tenantId");
        }

        $fields = [
            'owner_name'  => trim((string)($data['owner_name'] ?? $current['owner_name'] ?? '')) ?: null,
            'owner_email' => trim((string)($data['owner_email'] ?? $current['owner_email'] ?? '')) ?: null,
            'timezone'    => trim((string)($data['timezone'] ?? $current['timezone'] ?? '')) ?: 'UTC',
            'locale'      => trim((string)($data['locale'] ?? $current['locale'] ?? '')) ?: 'en',
        ];

        // anti-drift-ignore: TENANT — updates a specific tenant's own profile row by tenant_id; not scoped to the caller's own tenant
        \Database::query(
            "UPDATE tenant_profiles SET owner_name = ?, owner_email = ?, timezone = ?, locale = ?, updated_at = ? WHERE tenant_id = ?",
            [$fields['owner_name'], $fields['owner_email'], $fields['timezone'], $fields['locale'], \slate_db_now(), $tenantId]
        );

        if (isset($data['name']) && trim((string)$data['name']) !== '') {
            // anti-drift-ignore: TENANT — updates a specific tenant's own row by id; not scoped to the caller's own tenant
            \Database::query("UPDATE tenants SET name = ? WHERE id = ?", [mb_substr(trim((string)$data['name']), 0, 120), $tenantId]);
        }

        \AuditLog::record('tenant.updated', "tenant#$tenantId", ['fields' => array_keys($fields)]);
    }
}
