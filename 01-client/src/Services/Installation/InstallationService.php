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
     * QA Fix Round 1 (Phase 4, Fix 2): the canonical Installation ID format
     * — lowercase hex, exactly 32 characters (matches the 16-byte
     * bin2hex(random_bytes(16)) generation convention below).
     */
    public const INSTALLATION_ID_PATTERN = '/^[a-f0-9]{32}$/';

    public static function isValidInstallationId(string $installationId): bool
    {
        return preg_match(self::INSTALLATION_ID_PATTERN, $installationId) === 1;
    }

    /**
     * Provision the one tenant, its profile, the one installation identity,
     * and the first admin atomically. Safe to call again after a partial POST.
     *
     * Phase 5 (client installer rebuild): the target installer sequence no
     * longer creates the admin account in the same step as the tenant/
     * identity scaffolding -- admin creation must wait until AFTER a
     * license has been activated (see createAdminAccount() below). This
     * method is kept, unchanged in its own external behavior and return
     * shape, for the one remaining caller that still needs the old atomic
     * all-at-once shape (and for its own existing test coverage); it is now
     * a thin composition of provisionCoreSteps() + admin creation, not a
     * second, diverging implementation of either.
     *
     * @return array{tenant_id:int, installation_id:string, user_id:int}
     */
    public static function provision(string $adminName, string $adminEmail, string $passwordHash): array
    {
        return self::withTransaction(function () use ($adminName, $adminEmail, $passwordHash): array {
            $core = self::provisionCoreSteps($adminName, $adminEmail);
            $adminRoleId = self::ensureSuperAdminRole($core['tenant_id']);
            $userId = self::ensureFirstAdmin($core['tenant_id'], $adminName, $adminEmail, $passwordHash, $adminRoleId);

            return [
                'tenant_id'       => $core['tenant_id'],
                'installation_id' => $core['installation_id'],
                'user_id'         => $userId,
            ];
        });
    }

    /**
     * Phase 5 (docs/02-architecture/05-INSTALLATION-ACTIVATION.md §1, Step
     * 2 "Install Application"): the tenant/profile/role/installation-identity
     * scaffolding ALONE, with no admin account -- the target installer must
     * be able to reach a "Core Installed, Unlicensed" state (§3) before any
     * admin exists, since admin creation now happens only after a license
     * activates (Step 6, createAdminAccount() below). Safe to call again on
     * a retried Step 2 POST, via the same idempotency
     * provisionCoreSteps()/ensureInstallationIdentity() already provide.
     *
     * @return array{tenant_id:int, installation_id:string}
     */
    public static function provisionCore(): array
    {
        return self::withTransaction(function (): array {
            $core = self::provisionCoreSteps('', '');
            self::ensureSuperAdminRole($core['tenant_id']);
            return $core;
        });
    }

    /**
     * Phase 5, Step 6 ("Create Admin Account"): reachable only once the
     * installer's own state machine (install.php's installer_resolve_step())
     * has confirmed a verified license activation exists -- this method
     * itself does not re-check that, it trusts its caller, exactly as
     * provision() always has. It performs only the admin-creation half of
     * what provision() used to do in one shot. Idempotent on the same
     * (tenantId, email) pair, exactly like ensureFirstAdmin() already is; a
     * retried Step 4 POST (e.g. after a failure partway through finishing
     * setup) never creates a second admin or a second commercial
     * installation.
     */
    public static function createAdminAccount(int $tenantId, string $adminName, string $adminEmail, string $passwordHash): int
    {
        return self::withTransaction(function () use ($tenantId, $adminName, $adminEmail, $passwordHash): int {
            self::setTenantProfileOwner($tenantId, $adminName, $adminEmail);
            $adminRoleId = self::ensureSuperAdminRole($tenantId);
            return self::ensureFirstAdmin($tenantId, $adminName, $adminEmail, $passwordHash, $adminRoleId);
        });
    }

    /**
     * The tenant/profile/installation-identity portion shared by provision()
     * and provisionCore(). Deliberately not public on its own: it must
     * always run inside a transaction (withTransaction() below), and a
     * direct caller forgetting that would silently lose the atomicity every
     * existing test already relies on.
     *
     * @return array{tenant_id:int, installation_id:string}
     */
    private static function provisionCoreSteps(string $adminName, string $adminEmail): array
    {
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
        $installationId = self::ensureInstallationIdentity($tenantId);

        return ['tenant_id' => $tenantId, 'installation_id' => $installationId];
    }

    /**
     * Refreshes the tenant profile's owner name/email once real admin
     * details are known (Step 6) -- ensureTenantProfile() only ever SETS
     * these on first creation (Step 2, when they may still be blank) and
     * deliberately never overwrites an existing row, so this is the one
     * place that later fills them in from the split installer flow. A
     * no-op if both are still blank (defensive; every real caller has both
     * by the time it creates an admin account).
     */
    private static function setTenantProfileOwner(int $tenantId, string $adminName, string $adminEmail): void
    {
        $name = trim($adminName);
        $email = trim($adminEmail);
        if ($name === '' && $email === '') {
            return;
        }

        \Database::update('tenant_profiles', [
            'owner_name'  => $name !== '' ? mb_substr($name, 0, 120) : null,
            'owner_email' => $email !== '' ? mb_substr($email, 0, 190) : null,
        ], 'tenant_id = ?', [$tenantId]);
    }

    /** Runs $fn inside a transaction, reusing an already-open one if the caller started it. */
    private static function withTransaction(callable $fn): mixed
    {
        $pdo = \Database::get();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }

        try {
            $result = $fn();
            if ($started) {
                $pdo->commit();
            }
            return $result;
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
     * query. Returns null for an install that has never completed
     * provisioning (installer step 2, provision() above) — remote licensing
     * simply stays unconfigured/no-op until then, exactly as it already
     * does when LICENSE_* env vars are unset.
     *
     * QA Fix Round 1 (Phase 4, Fix 2E): also returns null — never the raw
     * value — if the stored row does not match the canonical 32-lowercase-
     * hex format. The column is only ever written by provision() itself
     * (always well-formed), so a malformed value here can only mean direct
     * database tampering/corruption; trusting it as-is would hand a
     * malformed "local identity" to every comparison built on top of this
     * accessor (RemoteLicenseClient's write-time check,
     * SlateLicenseCacheStore's read-time check), silently weakening both.
     * Failing safe here means those comparisons simply never match anything
     * — the fail-closed outcome the rest of Phase 4 already assumes.
     */
    public static function currentInstallationId(): ?string
    {
        $value = (string) \Database::value(
            'SELECT installation_id FROM installation_identity WHERE singleton_id = 1 LIMIT 1'
        );
        return self::isValidInstallationId($value) ? $value : null;
    }

    /**
     * QA Fix Round 2 (Fix 4): when the DB identity row is absent, a
     * well-formed INSTALLATION_ID already sitting in .env is reused
     * verbatim rather than silently discarded in favor of a fresh random
     * one -- generating a new value here would orphan whatever remote
     * license/installation the value on disk was already bound to. A
     * malformed .env value is never trusted as-is; it falls through to the
     * normal fresh-generation path below exactly as if no .env value were
     * present at all, so a corrupted/tampered .env can never propagate into
     * the database.
     */
    private static function ensureInstallationIdentity(int $tenantId): string
    {
        $row = \Database::row(
            'SELECT installation_id FROM installation_identity WHERE singleton_id = 1 OR tenant_id = ? LIMIT 1',
            [$tenantId]
        );
        if ($row !== null && (string) $row['installation_id'] !== '') {
            return (string) $row['installation_id'];
        }

        $envInstallationId = trim((string) \env('INSTALLATION_ID', ''));
        $installationId = self::isValidInstallationId($envInstallationId)
            ? $envInstallationId
            : bin2hex(random_bytes(16));

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
