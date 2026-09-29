<?php
/**
 * Kohevo Studio — Lock Repository (`studiobuilder_locks`).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Repository;

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
}
