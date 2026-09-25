<?php

declare(strict_types=1);

namespace Slate\Services\Auth;

use Slate\Data\Repository;
use Slate\Data\QueryBuilder;
use Slate\Tenancy\TenantContext;

/** Persistence boundary for admin MFA factors and one-time recovery codes. */
final class MfaRepository extends Repository
{
    protected string $table = 'user_mfa_factors';

    public function __construct(TenantContext $tenants)
    {
        parent::__construct($tenants);
        self::ensureSchema();
    }

    public static function ensureSchema(): void
    {
        \Database::get()->exec("CREATE TABLE IF NOT EXISTS `user_mfa_factors` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tenant_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `secret` VARCHAR(128) NOT NULL,
            `enabled_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `tenant_user` (`tenant_id`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        \Database::get()->exec("CREATE TABLE IF NOT EXISTS `user_mfa_recovery_codes` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tenant_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `code_hash` VARCHAR(255) NOT NULL,
            `used_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), KEY `tenant_user_used` (`tenant_id`, `user_id`, `used_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function factorForUser(int $userId): ?array
    {
        return $this->query()->where('user_id', $userId)->first();
    }

    public function enroll(int $userId, string $secret): int
    {
        $this->assertPrivilegedUser($userId);
        return $this->insert(['user_id' => $userId, 'secret' => $secret]);
    }

    public function enable(int $userId): int
    {
        $this->assertPrivilegedUser($userId);
        return $this->query()->where('user_id', $userId)->update(['enabled_at' => slate_db_now()]);
    }

    public function recoveryCodesForUser(int $userId): array
    {
        return QueryBuilder::table('user_mfa_recovery_codes')
            ->where('tenant_id', $this->tenants->id())
            ->where('user_id', $userId)
            ->whereNull('used_at')->get();
    }

    public function replaceRecoveryCodes(int $userId, array $hashes): void
    {
        $this->assertPrivilegedUser($userId);
        QueryBuilder::table('user_mfa_recovery_codes')
            ->where('tenant_id', $this->tenants->id())->where('user_id', $userId)->delete();
        foreach ($hashes as $hash) {
            QueryBuilder::table('user_mfa_recovery_codes')->insert([
                'tenant_id' => $this->tenants->id(), 'user_id' => $userId, 'code_hash' => $hash,
            ]);
        }
    }

    /** Consume a matching code once; returns true only for the first successful use. */
    public function consumeRecoveryCode(int $userId, string $code): bool
    {
        $this->assertPrivilegedUser($userId);
        foreach ($this->recoveryCodesForUser($userId) as $row) {
            if (!Mfa::verifyRecoveryCode($code, (string) $row['code_hash'])) continue;
            $changed = QueryBuilder::table('user_mfa_recovery_codes')
                ->where('tenant_id', $this->tenants->id())->where('id', (int) $row['id'])
                ->whereNull('used_at')->update(['used_at' => slate_db_now()]);
            return $changed === 1;
        }
        return false;
    }

    private function assertPrivilegedUser(int $userId): void
    {
        $user = \Database::row(
            "SELECT id FROM users WHERE id = ? AND tenant_id = ? AND status = 'active' AND role_id > 0",
            [$userId, $this->tenants->id()]
        );
        if ($user === null) {
            throw new \InvalidArgumentException('MFA requires an active admin user in the current tenant.');
        }
    }
}
