<?php
/**
 * Kohevo Studio (studio-builder) — Advisory edit-session coordination.
 *
 * Uses the existing `studiobuilder_locks` table (one row per tenant+page,
 * `expires_at` expiry model) to tell an editor that someone else has the page
 * open. It is ADVISORY by design:
 *
 *  - A lock never blocks a write. Every mutation is still decided by
 *    `expected_revision_id` in `StudioRevisionService` — the lock only lets
 *    the UI warn early instead of failing late.
 *  - A lock is short-lived: TTL_SECONDS without a heartbeat and it expires on
 *    its own (DB clock), so a closed tab or crashed browser never leaves a
 *    permanent lock behind.
 *  - Only the holder (same user + same opaque token) can extend or release it.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Service;

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Exception\StudioNotFoundException;
use Slate\Module\StudioBuilder\Exception\StudioTenantScopeException;
use Slate\Module\StudioBuilder\Repository\LockRepository;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Tenancy\TenantContext;

final class StudioEditLockService
{
    public const TTL_SECONDS = 120;
    public const TOKEN_PATTERN = '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PageRepository $pages,
        private readonly LockRepository $locks,
    ) {}

    /**
     * Take the page's edit lock when it is free, expired, or already ours.
     *
     * @return array{held: bool, lock_token: ?string, ttl_seconds: int, other_editor: bool, other_expires_in: ?int}
     */
    public function acquire(int $pageId, int $userId): array
    {
        $this->requirePage($pageId);

        $pdo = Database::get();
        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }
        try {
            $row = $this->locks->lockRowForUpdate($pageId);
            if ($row === null) {
                $token = self::newToken();
                $this->locks->insertLock($pageId, $userId, $token, self::TTL_SECONDS);
                $result = self::held($token);
            } elseif ((int) $row['user_id'] === $userId && (int) $row['is_expired'] === 0) {
                // Same user, live lock (another tab of theirs): share the session.
                $token = (string) $row['lock_token'];
                $this->locks->takeOver((int) $row['id'], $pageId, $userId, $token, false, self::TTL_SECONDS);
                $result = self::held($token);
            } elseif ((int) $row['is_expired'] === 1) {
                $token = self::newToken();
                $this->locks->takeOver((int) $row['id'], $pageId, $userId, $token, true, self::TTL_SECONDS);
                $result = self::held($token);
            } else {
                $result = self::notHeld((int) $row['remaining_seconds']);
            }
            if ($ownsTx) {
                $pdo->commit();
            }
            return $result;
        } catch (\PDOException $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Two first-time acquirers raced on the unique (tenant, page) key:
            // the other one won — report it as held by someone else.
            if ((string) $e->getCode() === '23000') {
                return self::notHeld(self::TTL_SECONDS);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Extend our lock. When it was lost (expired and taken, or released), try to
     * re-acquire it so a long idle tab recovers on its own when the page is free.
     *
     * @return array{held: bool, lock_token: ?string, ttl_seconds: int, other_editor: bool, other_expires_in: ?int}
     */
    public function refresh(int $pageId, int $userId, string $token): array
    {
        $this->requirePage($pageId);
        if (preg_match(self::TOKEN_PATTERN, $token) === 1 && $this->locks->heartbeat($pageId, $userId, $token, self::TTL_SECONDS)) {
            return self::held($token);
        }
        return $this->acquire($pageId, $userId);
    }

    public function release(int $pageId, int $userId, string $token): bool
    {
        $this->requirePage($pageId);
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return false;
        }
        return $this->locks->release($pageId, $userId, $token) > 0;
    }

    private function requirePage(int $pageId): void
    {
        if (!$this->tenants->isScoped() || $this->tenants->id() <= 0) {
            throw new StudioTenantScopeException();
        }
        if ($this->pages->find($pageId) === null) {
            throw new StudioNotFoundException("Studio page {$pageId} was not found in the active tenant.", ['page_id' => $pageId]);
        }
    }

    /** @return array{held: bool, lock_token: ?string, ttl_seconds: int, other_editor: bool, other_expires_in: ?int} */
    private static function held(string $token): array
    {
        return ['held' => true, 'lock_token' => $token, 'ttl_seconds' => self::TTL_SECONDS, 'other_editor' => false, 'other_expires_in' => null];
    }

    /** @return array{held: bool, lock_token: ?string, ttl_seconds: int, other_editor: bool, other_expires_in: ?int} */
    private static function notHeld(int $remaining): array
    {
        return ['held' => false, 'lock_token' => null, 'ttl_seconds' => self::TTL_SECONDS, 'other_editor' => true, 'other_expires_in' => max(0, $remaining)];
    }

    private static function newToken(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
