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
     * A Global Component: the non-archived `container_preset` page with this
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
        return $this->query()
            ->where('page_type', $pageType)
            ->where('status', '<>', 'archived')
            ->orderBy('title', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * Every page of the tenant that is not archived, for a backup: no limit, and in a stable order.
     *
     * @return list<array<string, mixed>>
     */
    public function allForBackup(): array
    {
        return $this->query()->where('status', '<>', 'archived')->orderBy('id', 'ASC')->get();
    }

    /**
     * Every page that is not archived, newest change first. Archived pages are left out in SQL, before the limit: filtering
     * them afterwards let a run of recently archived pages (a bulk delete) use up the limit and hide live ones.
     *
     * @return list<array<string, mixed>>
     */
    public function allLive(int $limit): array
    {
        return array_map([$this, 'hydrate'], $this->query()
            ->where('status', '<>', 'archived')
            ->orderBy('updated_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(max(1, $limit))
            ->get());
    }

    /**
     * Published pages of one type within the active tenant scope, for Theme Builder template lookup.
     * The status filter runs in SQL before the limit, so a site with many drafts or archived pages
     * still offers every published candidate; the limit only guards against a runaway query.
     *
     * @return list<array<string, mixed>>
     */
    public function publishedOfType(string $pageType, int $limit = 5000): array
    {
        return $this->query()
            ->where('page_type', $pageType)
            ->where('status', 'published')
            ->whereNotNull('published_revision_id')
            ->orderBy('title', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * The other non-archived pages of the given types that are also marked as the homepage.
     *
     * @param list<string> $pageTypes
     * @return list<array<string, mixed>>
     */
    public function otherHomepages(int $exceptPageId, array $pageTypes): array
    {
        if ($pageTypes === []) {
            return [];
        }
        return $this->query()
            ->where('route_mode', 'homepage')
            ->whereIn('page_type', $pageTypes)
            ->where('status', '<>', 'archived')
            ->where('id', '<>', $exceptPageId)
            ->get();
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

    /**
     * The published pages of the given types that could be Theme Builder templates: a `template_type` in their current
     * settings, a conventional template slug, or a draft that differs from the published revision (whose published
     * settings then have to be read). Every other published page is left out in SQL, so template lookup no longer loads
     * the whole site on each request.
     *
     * @param list<string> $pageTypes
     * @param list<string> $slugs
     * @return list<array<string, mixed>>
     */
    public function templateCandidates(array $pageTypes, array $slugs, int $limit = 5000): array
    {
        if ($pageTypes === [] || !$this->tenants->isScoped()) {
            return [];
        }
        $types = implode(',', array_fill(0, count($pageTypes), '?'));
        $slugMarks = $slugs === [] ? "''" : implode(',', array_fill(0, count($slugs), '?'));
        return \Slate\Data\Database::all(
            "SELECT * FROM studiobuilder_pages WHERE tenant_id = ? AND status = 'published' AND published_revision_id IS NOT NULL"
            . " AND page_type IN ({$types})"
            . " AND ((settings_json LIKE '%\"template_type\":\"%' AND settings_json NOT LIKE '%\"template_type\":\"\"%')"
            . " OR slug IN ({$slugMarks})"
            . ' OR (active_draft_revision_id IS NOT NULL AND active_draft_revision_id <> published_revision_id))'
            . ' ORDER BY id ASC LIMIT ' . max(1, $limit),
            array_merge([$this->tenants->id()], $pageTypes, $slugs)
        );
    }

    /**
     * A cheap stamp that changes whenever anything a template lookup reads changes for these page types: a page is
     * published, unpublished, archived or saved (revision ids only grow). Lets a lookup reuse what it built.
     *
     * @param list<string> $pageTypes
     */
    public function templateStamp(array $pageTypes): string
    {
        if ($pageTypes === [] || !$this->tenants->isScoped()) {
            return '';
        }
        $types = implode(',', array_fill(0, count($pageTypes), '?'));
        $row = \Slate\Data\Database::row(
            "SELECT COUNT(*) AS n, COALESCE(SUM(published_revision_id), 0) AS p, COALESCE(SUM(active_draft_revision_id), 0) AS d"
            . " FROM studiobuilder_pages WHERE tenant_id = ? AND status = 'published' AND page_type IN ({$types})",
            array_merge([$this->tenants->id()], $pageTypes)
        );
        return $this->tenants->id() . ':' . (int) ($row['n'] ?? 0) . ':' . (string) ($row['p'] ?? 0) . ':' . (string) ($row['d'] ?? 0);
    }

    /**
     * Address metadata of every published, publicly routable page of the active
     * tenant — the sitemap's candidate set. Identity/lifecycle columns only:
     * no `seo_json`, no document.
     *
     * @param list<string> $pageTypes
     * @return list<array<string, mixed>>
     */
    public function publishedForSitemap(array $pageTypes, int $limit): array
    {
        if ($pageTypes === []) {
            return [];
        }
        return $this->query()
            ->select('id', 'slug', 'page_type', 'route_mode', 'published_revision_id', 'published_at')
            ->where('status', 'published')
            ->whereIn('page_type', $pageTypes)
            ->whereNotNull('published_revision_id')
            ->orderBy('id', 'ASC')
            ->limit(max(1, $limit))
            ->get();
    }
}
