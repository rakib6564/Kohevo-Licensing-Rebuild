<?php

declare(strict_types=1);

namespace Slate\Services\Auth;

use Slate\Data\Repository;
use Slate\Data\QueryBuilder;
use Slate\Tenancy\TenantContext;

/** Server-side admin session/device registry for revocation and inventory. */
final class SessionRepository extends Repository
{
    protected string $table = 'admin_sessions';

    public function __construct(TenantContext $tenants)
    {
        parent::__construct($tenants);
        self::ensureSchema();
    }

    public static function ensureSchema(): void
    {
        \Database::get()->exec("CREATE TABLE IF NOT EXISTS `admin_sessions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tenant_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `session_hash` CHAR(64) NOT NULL,
            `device_label` VARCHAR(190) NOT NULL DEFAULT '',
            `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
            `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
            `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at` DATETIME NULL,
            `revoked_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `tenant_session` (`tenant_id`, `session_hash`),
            KEY `tenant_user_active` (`tenant_id`, `user_id`, `revoked_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function register(int $userId, string $sessionId, string $deviceLabel, string $ip, string $userAgent): int
    {
        $hash = hash('sha256', $sessionId);
        $data = [
            'user_id' => $userId,
            'session_hash' => $hash,
            'device_label' => mb_substr($deviceLabel, 0, 190),
            'ip_address' => mb_substr($ip, 0, 45),
            'user_agent' => mb_substr($userAgent, 0, 500),
            'last_seen_at' => slate_db_now(),
            // PHP's clock on purpose: touch() compares this with strtotime()
            // against time(), so both sides are PHP. If that check ever moves
            // into SQL (expires_at > NOW()), this must become slate_db_now() —
            // PHP and MySQL do not share a timezone here.
            'expires_at' => date('Y-m-d H:i:s', time() + 8 * 3600),
            'revoked_at' => null,
        ];
        $existing = $this->query()->where('session_hash', $hash)->first();
        if ($existing !== null && !empty($existing['revoked_at'])) {
            $this->update((int)$existing['id'], $data);
            return (int)$existing['id'];
        }
        return $this->insert($data);
    }

    public function rotate(int $userId, string $previousSessionId, string $newSessionId, string $deviceLabel, string $ip, string $userAgent): int
    {
        if ($previousSessionId !== '') $this->revokeBySession($userId, $previousSessionId);
        return $this->register($userId, $newSessionId, $deviceLabel, $ip, $userAgent);
    }

    public function activeForUser(int $userId): array
    {
        return $this->query()->where('user_id', $userId)->whereNull('revoked_at')->get();
    }

    public function validateAndTouch(int $userId, string $sessionId): bool
    {
        $row = $this->query()->where('user_id', $userId)
            ->where('session_hash', hash('sha256', $sessionId))->whereNull('revoked_at')->first();
        if ($row === null) return false;
        if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) <= time()) return false;
        $this->update((int)$row['id'], ['last_seen_at' => slate_db_now()]);
        return true;
    }

    public function revokeById(int $userId, int $sessionId): int
    {
        return QueryBuilder::table($this->table)
            ->where('tenant_id', $this->tenants->id())->where('user_id', $userId)
            ->where('id', $sessionId)->whereNull('revoked_at')
            ->update(['revoked_at' => slate_db_now()]);
    }

    public function revokeBySession(int $userId, string $sessionId): int
    {
        return QueryBuilder::table($this->table)
            ->where('tenant_id', $this->tenants->id())->where('user_id', $userId)
            ->where('session_hash', hash('sha256', $sessionId))->whereNull('revoked_at')
            ->update(['revoked_at' => slate_db_now()]);
    }

    public function revokeOthers(int $userId, string $currentSessionId): int
    {
        return QueryBuilder::table($this->table)
            ->where('tenant_id', $this->tenants->id())->where('user_id', $userId)
            ->where('session_hash', '!=', hash('sha256', $currentSessionId))->whereNull('revoked_at')
            ->update(['revoked_at' => slate_db_now()]);
    }
}
