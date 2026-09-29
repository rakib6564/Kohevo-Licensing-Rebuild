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
}
