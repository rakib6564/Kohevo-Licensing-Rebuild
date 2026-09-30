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

    /**
     * Distinct page ids (active tenant) whose CURRENT working draft or CURRENT
     * published revision depends on (type, key) — the pages that would break
     * if the dependency disappeared (archive/delete protection). Unlike
     * `pageIdsForDependency()` this ignores historical revisions.
     *
     * @return list<int>
     */
    public function currentDependentPageIds(string $dependencyType, string $dependencyKey): array
    {
        $this->assertValidTenantScope();
        if (!$this->tenants->isScoped()) {
            throw new \LogicException(static::class . '::currentDependentPageIds() requires a scoped tenant.');
        }
        $tenantId = $this->tenants->id();
        $rows = \Slate\Data\Database::rows(
            'SELECT DISTINCT d.page_id
               FROM `studiobuilder_dependencies` d
               INNER JOIN `studiobuilder_pages` p
                       ON p.tenant_id = d.tenant_id
                      AND p.id = d.page_id
                      AND p.status <> \'archived\'
                      AND (p.active_draft_revision_id = d.revision_id OR p.published_revision_id = d.revision_id)
              WHERE d.tenant_id = ? AND d.dependency_type = ? AND d.dependency_key = ?
              ORDER BY d.page_id ASC',
            [$tenantId, $dependencyType, $dependencyKey]
        );
        return array_values(array_map(static fn(array $r): int => (int) $r['page_id'], $rows));
    }

    /**
     * Distinct page ids (active tenant) with any revision depending on (type, key).
     *
     * @return list<int>
     */
    public function pageIdsForDependency(string $dependencyType, string $dependencyKey): array
    {
        $rows = $this->query()
            ->select('page_id')
            ->where('dependency_type', $dependencyType)
            ->where('dependency_key', $dependencyKey)
            ->get();
        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['page_id']] = true;
        }
        return array_keys($ids);
    }
}
