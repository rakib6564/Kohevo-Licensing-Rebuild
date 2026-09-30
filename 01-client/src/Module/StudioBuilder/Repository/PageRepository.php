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

    /**
     * A Global Component: the non-archived `section_preset` page with this
     * uuid, within the active tenant scope (Phase 6 live reference target).
     */
    public function findComponentByRef(string $ref): ?array
    {
        if (preg_match(\Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::COMPONENT_REF_PATTERN, $ref) !== 1) {
            return null;
        }
        $row = $this->query()
            ->where('uuid', $ref)
            ->where('page_type', \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE)
            ->first();
        return ($row !== null && ($row['status'] ?? null) !== 'archived') ? $row : null;
    }

    /**
     * All non-archived pages of one type within the active tenant scope.
     *
     * @return list<array<string, mixed>>
     */
    public function allOfType(string $pageType, int $limit = 500): array
    {
        $rows = $this->query()
            ->where('page_type', $pageType)
            ->orderBy('title', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit(max(1, $limit))
            ->get();
        return array_values(array_filter($rows, static fn(array $r): bool => ($r['status'] ?? null) !== 'archived'));
    }

    /**
     * The tenant's published homepage (`route_mode = homepage`) among the given
     * routable page types; the most recently published one wins if several exist.
     *
     * @param list<string> $pageTypes
     */
    public function findPublishedHomepage(array $pageTypes): ?array
    {
        if ($pageTypes === []) {
            return null;
        }
        return $this->query()
            ->where('route_mode', 'homepage')
            ->where('status', 'published')
            ->whereIn('page_type', $pageTypes)
            ->whereNotNull('published_revision_id')
            ->orderBy('published_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->first();
    }
}
