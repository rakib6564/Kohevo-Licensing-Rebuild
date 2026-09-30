<?php
/**
 * Kohevo Studio — Lock Repository (`studiobuilder_locks`).
 *
 * Advisory edit-session locks. Every timestamp is computed by MySQL (`NOW()`),
 * never by PHP, so expiry is judged on one clock no matter which web node
 * handled the heartbeat. Every statement names `tenant_id` and binds the
 * ACTIVE tenant — a page id alone never identifies a lock.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Repository;

use Slate\Data\Database;

final class LockRepository extends StudioRepository
{
    protected string $table = 'studiobuilder_locks';

    /**
     * Find the active edit lock for a page within the active tenant scope.
     */
    public function findByPageId(int $pageId): ?array
    {
        return $this->query()
            ->where('page_id', $pageId)
            ->first();
    }

    /**
     * Delete any lock for a page within the active tenant scope.
     */
    public function deleteByPageId(int $pageId): int
    {
        return $this->query()
            ->where('page_id', $pageId)
            ->delete();
    }

    /**
     * The page's lock row, locked FOR UPDATE, with DB-clock derived state.
     * Must run inside a transaction.
     *
     * @return null|array<string, mixed> row + `is_expired` (0|1) + `remaining_seconds`
     */
    public function lockRowForUpdate(int $pageId): ?array
    {
        return Database::row(
            'SELECT `id`, `user_id`, `lock_token`, `expires_at`,
                    (`expires_at` <= NOW()) AS `is_expired`,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), `expires_at`)) AS `remaining_seconds`
               FROM `studiobuilder_locks`
              WHERE `tenant_id` = ? AND `page_id` = ?
              FOR UPDATE',
            [$this->tenantId(), $pageId]
        );
    }

    public function insertLock(int $pageId, int $userId, string $token, int $ttlSeconds): void
    {
        Database::query(
            'INSERT INTO `studiobuilder_locks`
                (`tenant_id`, `page_id`, `user_id`, `lock_token`, `acquired_at`, `heartbeat_at`, `expires_at`)
             VALUES (?, ?, ?, ?, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))',
            [$this->tenantId(), $pageId, $userId, $token, $ttlSeconds]
        );
    }

    /** Hand the (expired or own) lock row to `$userId` with a fresh expiry. */
    public function takeOver(int $lockId, int $pageId, int $userId, string $token, bool $newSession, int $ttlSeconds): void
    {
        Database::query(
            'UPDATE `studiobuilder_locks`
                SET `user_id` = ?, `lock_token` = ?,
                    `acquired_at` = IF(?, NOW(), `acquired_at`),
                    `heartbeat_at` = NOW(), `expires_at` = DATE_ADD(NOW(), INTERVAL ? SECOND)
              WHERE `tenant_id` = ? AND `page_id` = ? AND `id` = ?',
            [$userId, $token, $newSession ? 1 : 0, $ttlSeconds, $this->tenantId(), $pageId, $lockId]
        );
    }

    /** Extend a lock the caller still holds. Returns whether it is (still) held by them. */
    public function heartbeat(int $pageId, int $userId, string $token, int $ttlSeconds): bool
    {
        Database::query(
            'UPDATE `studiobuilder_locks`
                SET `heartbeat_at` = NOW(), `expires_at` = DATE_ADD(NOW(), INTERVAL ? SECOND)
              WHERE `tenant_id` = ? AND `page_id` = ? AND `user_id` = ? AND `lock_token` = ? AND `expires_at` > NOW()',
            [$ttlSeconds, $this->tenantId(), $pageId, $userId, $token]
        );
        // Affected-row counts are unreliable when nothing changed within the same
        // second, so ownership is confirmed by reading the row back.
        return (int) Database::value(
            'SELECT COUNT(*) FROM `studiobuilder_locks`
              WHERE `tenant_id` = ? AND `page_id` = ? AND `user_id` = ? AND `lock_token` = ? AND `expires_at` > NOW()',
            [$this->tenantId(), $pageId, $userId, $token]
        ) === 1;
    }

    /** Release only the caller's own lock. */
    public function release(int $pageId, int $userId, string $token): int
    {
        return Database::query(
            'DELETE FROM `studiobuilder_locks`
              WHERE `tenant_id` = ? AND `page_id` = ? AND `user_id` = ? AND `lock_token` = ?',
            [$this->tenantId(), $pageId, $userId, $token]
        )->rowCount();
    }

    private function tenantId(): int
    {
        $this->assertValidTenantScope();
        $tenantId = $this->tenants->id();
        if (!$this->tenants->isScoped() || $tenantId <= 0) {
            throw new \RuntimeException(self::class . ' requires an active tenant scope.');
        }
        return $tenantId;
    }
}
