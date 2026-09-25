<?php
/**
 * Kohevo — fresh-install provisioning.
 *
 * Keeps the installer idempotent without making TENANT_ID=1 a product
 * invariant. The core schema currently supplies a bootstrap tenant on a fresh
 * database; this service resolves that row from the database and persists the
 * resolved tenant/site relationship explicitly.
 */

declare(strict_types=1);

namespace Slate\Services\Installation;

final class InstallationService
{
    /**
     * Provision the one tenant, its profile, the one installation identity,
     * and the first admin atomically. Safe to call again after a partial POST.
     *
     * @return array{tenant_id:int, installation_id:string, user_id:int}
     */
    public static function provision(string $adminName, string $adminEmail, string $passwordHash): array
    {
        $pdo = \Database::get();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }

        try {
            $tenants = \Database::rows('SELECT id, name, slug, status FROM tenants ORDER BY id ASC');
            if (count($tenants) > 1) {
                throw new \RuntimeException('This installation contains more than one tenant; fresh installation cannot continue safely.');
            }

            if ($tenants === []) {
                $slug = self::slugFromUrl();
                $tenantId = \Database::insert('tenants', [
                    'name'   => $adminName !== '' ? mb_substr($adminName, 0, 120) : 'Kohevo business',
                    'slug'   => $slug,
                    'status' => 'active',
                ]);
            } else {
                $tenantId = (int) $tenants[0]['id'];
            }

            self::ensureTenantProfile($tenantId, $adminName, $adminEmail);
            $adminRoleId = self::ensureSuperAdminRole($tenantId);
            $installationId = self::ensureInstallationIdentity($tenantId);
            $userId = self::ensureFirstAdmin($tenantId, $adminName, $adminEmail, $passwordHash, $adminRoleId);

            if ($started) {
                $pdo->commit();
            }

            return [
                'tenant_id'       => $tenantId,
                'installation_id' => $installationId,
                'user_id'         => $userId,
            ];
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function ensureTenantProfile(int $tenantId, string $adminName, string $adminEmail): void
    {
        $exists = \Database::value('SELECT id FROM tenant_profiles WHERE tenant_id = ?', [$tenantId]);
        if ($exists) {
            return;
        }

        \Database::insert('tenant_profiles', [
            'tenant_id'        => $tenantId,
            'owner_name'       => trim($adminName) !== '' ? mb_substr(trim($adminName), 0, 120) : null,
            'owner_email'      => trim($adminEmail) !== '' ? mb_substr(trim($adminEmail), 0, 190) : null,
            'lifecycle_status' => 'active',
            'timezone'         => 'UTC',
            'locale'           => 'en',
        ]);
    }

    private static function ensureSuperAdminRole(int $tenantId): int
    {
        $legacy = \Database::row('SELECT id, tenant_id, name, slug, is_system FROM roles WHERE id = 1 LIMIT 1');
        if ($legacy !== null && (int) $legacy['tenant_id'] !== $tenantId) {
            throw new \RuntimeException('System role ID 1 belongs to another tenant; installation stopped without reassigning it.');
        }
        if ($legacy !== null && ((string) $legacy['slug'] !== 'super-admin' || (int) $legacy['is_system'] !== 1)) {
            throw new \RuntimeException('System role ID 1 is incompatible with the expected super-admin role; installation stopped without changing it.');
        }

        if ($legacy !== null) {
            return 1;
        }

        $role = \Database::row(
            'SELECT id, tenant_id, name, slug, is_system FROM roles WHERE tenant_id = ? AND slug = ? LIMIT 1',
            [$tenantId, 'super-admin']
        );
        if ($role !== null) {
            if ((int) $role['is_system'] !== 1 || (string) $role['name'] !== 'Super Admin') {
                throw new \RuntimeException('The installation tenant has an incompatible super-admin role; installation stopped without changing it.');
            }
            return (int) $role['id'];
        }

        return \Database::insert('roles', [
            'id'          => 1,
            'tenant_id'   => $tenantId,
            'name'        => 'Super Admin',
            'slug'        => 'super-admin',
            'description' => 'Full access. Cannot be edited.',
            'is_system'   => 1,
        ]);
    }

    /**
     * Phase 4 (PHASE-4-LICENSE-KEY-INSTALLATION-IDENTITY §A): the single
     * authoritative accessor for this deployment's own Installation ID —
     * every other call site (bin/license-check.php,
     * SlateLicenseCacheStore's read-time verification, and any future
     * caller) reads it through here rather than repeating the underlying
     * query. Returns null only for an install that has never completed
     * provisioning (installer step 2, provision() above) — remote licensing
     * simply stays unconfigured/no-op until then, exactly as it already
     * does when LICENSE_* env vars are unset.
     */
    public static function currentInstallationId(): ?string
    {
        $value = (string) \Database::value(
            'SELECT installation_id FROM installation_identity WHERE singleton_id = 1 LIMIT 1'
        );
        return $value !== '' ? $value : null;
    }

    private static function ensureInstallationIdentity(int $tenantId): string
    {
        $row = \Database::row(
            'SELECT installation_id FROM installation_identity WHERE singleton_id = 1 OR tenant_id = ? LIMIT 1',
            [$tenantId]
        );
        if ($row !== null && (string) $row['installation_id'] !== '') {
            return (string) $row['installation_id'];
        }

        $installationId = bin2hex(random_bytes(16));
        \Database::insert('installation_identity', [
            'singleton_id'    => 1,
            'tenant_id'       => $tenantId,
            'installation_id' => $installationId,
        ]);
        return $installationId;
    }

    private static function ensureFirstAdmin(int $tenantId, string $name, string $email, string $passwordHash, int $roleId): int
    {
        $existing = \Database::row(
            'SELECT id FROM users WHERE tenant_id = ? AND email = ? LIMIT 1',
            [$tenantId, $email]
        );
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return \Database::insert('users', [
            'tenant_id'     => $tenantId,
            'email'         => $email,
            'password_hash' => $passwordHash,
            'name'          => $name,
            'role_id'       => $roleId,
            'status'        => 'active',
        ]);
    }

    private static function slugFromUrl(): string
    {
        $url = defined('SLATE_URL') ? (string) SLATE_URL : '';
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: 'kohevo-business');
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $host));
        $slug = trim($slug, '-');
        return mb_substr($slug !== '' ? $slug : 'kohevo-business', 0, 64);
    }
}
