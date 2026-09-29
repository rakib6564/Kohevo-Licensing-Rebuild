<?php
/**
 * Kohevo Studio — Dependency Repository (`studiobuilder_dependencies`).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Repository;

final class DependencyRepository extends StudioRepository
{
    protected string $table = 'studiobuilder_dependencies';

    /**
     * List dependencies recorded for a page revision within the active tenant scope.
     */
    public function forPageRevision(int $pageId, int $revisionId): array
    {
        return $this->all([
            'page_id'     => $pageId,
            'revision_id' => $revisionId,
        ], 'id ASC');
    }

    /**
     * Replace dependency records for a specific (page_id, revision_id) within the active tenant scope.
     *
     * @param list<\Slate\Module\StudioBuilder\Dependency\DependencyRecord> $records
     */
    public function replaceForPageRevision(int $pageId, int $revisionId, array $records): void
    {
        $this->query()
            ->where('page_id', $pageId)
            ->where('revision_id', $revisionId)
            ->delete();

        foreach ($records as $record) {
            $this->insert([
                'page_id'         => $pageId,
                'revision_id'     => $revisionId,
                'node_id'         => $record->nodeId,
                'dependency_type' => $record->dependencyType,
                'dependency_key'  => $record->dependencyKey,
            ]);
        }
    }
}
