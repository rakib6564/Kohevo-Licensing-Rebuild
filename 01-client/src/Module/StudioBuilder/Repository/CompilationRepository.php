<?php
/**
 * Kohevo Studio — Compilation Repository (`studiobuilder_compilations`).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Repository;

final class CompilationRepository extends StudioRepository
{
    protected string $table = 'studiobuilder_compilations';

    /**
     * Find compiled output for a page and compile_mode ('published' or 'preview')
     * within the active tenant scope.
     */
    public function findByPageAndMode(int $pageId, string $compileMode = 'published'): ?array
    {
        return $this->query()
            ->where('page_id', $pageId)
            ->where('compile_mode', $compileMode)
            ->first();
    }

    /**
     * Insert or replace the single compilation for (active tenant, page, mode).
     * Relies on the table's own unique key; a concurrent insert of the same
     * artifact falls back to an update of the winner's row.
     *
     * @param array<string, mixed> $data
     */
    public function upsertForPage(int $pageId, string $compileMode, array $data): void
    {
        unset($data['tenant_id'], $data['page_id'], $data['compile_mode']);

        $existing = $this->findByPageAndMode($pageId, $compileMode);
        if ($existing !== null) {
            $this->update((int) $existing['id'], $data);
            return;
        }

        try {
            $this->insert(['page_id' => $pageId, 'compile_mode' => $compileMode] + $data);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $winner = $this->findByPageAndMode($pageId, $compileMode);
            if ($winner !== null) {
                $this->update((int) $winner['id'], $data);
            }
        }
    }

    /**
     * Drop every compilation (all modes) of the given pages within the active tenant.
     *
     * @param list<int> $pageIds
     */
    public function deleteForPages(array $pageIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $pageIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }
        return $this->query()->whereIn('page_id', $ids)->delete();
    }
}
