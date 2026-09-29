<?php
/**
 * Kohevo Studio — Page Repository (`studiobuilder_pages`).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Repository;

final class PageRepository extends StudioRepository
{
    protected string $table = 'studiobuilder_pages';

    /**
     * Find a Studio page by slug and page_type within the active tenant scope.
     */
    public function findBySlug(string $slug, string $pageType = 'page'): ?array
    {
        return $this->query()
            ->where('slug', $slug)
            ->where('page_type', $pageType)
            ->first();
    }

    /**
     * Find a Studio page by UUID within the active tenant scope.
     */
    public function findByUuid(string $uuid): ?array
    {
        return $this->query()
            ->where('uuid', $uuid)
            ->first();
    }
}
