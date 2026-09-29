<?php
/**
 * Kohevo Studio — Revision Repository (`studiobuilder_revisions`).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Repository;

final class RevisionRepository extends StudioRepository
{
    protected string $table = 'studiobuilder_revisions';

    /**
     * Find a specific revision by page_id and revision_number within the active tenant scope.
     */
    public function findByPageAndNumber(int $pageId, int $revisionNumber): ?array
    {
        return $this->query()
            ->where('page_id', $pageId)
            ->where('revision_number', $revisionNumber)
            ->first();
    }

    /**
     * Find a specific revision by primary key and page_id within the active tenant scope.
     */
    public function findByIdForPage(int $pageId, int $revisionId): ?array
    {
        return $this->query()
            ->where('id', $revisionId)
            ->where('page_id', $pageId)
            ->first();
    }

    /**
     * List revisions for a page ordered newest first within the active tenant scope.
     */
    public function forPage(int $pageId, ?int $limit = null): array
    {
        return $this->all(['page_id' => $pageId], 'revision_number DESC', $limit);
    }

    /**
     * Studio revisions are strictly immutable once created.
     */
    public function update(int|string $id, array $data): int
    {
        throw new \LogicException('Studio revisions are immutable and cannot be updated.');
    }

    /**
     * Studio revisions are strictly append-only and cannot be deleted via RevisionRepository.
     */
    public function delete(int|string $id): int
    {
        throw new \LogicException('Studio revisions are immutable and cannot be deleted.');
    }
}
