<?php
/**
 * Kohevo Studio (studio-builder) — Page chrome (header / footer) resolution.
 *
 * Uses ONLY what the canonical schema and the Phase 1 page model already
 * define — no second layout system:
 *
 *  - `settings.header_mode` / `settings.footer_mode` ∈ {inherit, custom, hidden}
 *  - the existing page types `header_partial` / `footer_partial`, authored as
 *    ordinary Studio documents with their own revisions and publish state.
 *
 * Phase 4 semantics (flagged in the Phase 4 report for ratification under
 * open question Q20; the management UI is Phase 6):
 *
 *   hidden  -> no header/footer region at all.
 *   inherit -> the tenant's site-wide partial: the PUBLISHED `header_partial`
 *              (resp. `footer_partial`) page whose slug is `default`; if none,
 *              a built-in minimal chrome from tenant branding (site name, logo).
 *   custom  -> the PUBLISHED partial whose slug equals this page's own slug
 *              (page-specific chrome); if none, falls back to `inherit`.
 *
 * Partials are always taken from their PUBLISHED revision — in preview too —
 * so a draft partial can never leak into any page. Only `page` and `landing`
 * documents receive chrome; partials/presets/system documents render bare.
 * All lookups go through the tenant-scoped PageRepository/RevisionRepository.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Chrome;

use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;
use Slate\Module\StudioBuilder\Theme\ThemeTemplateResolver;

final class ChromeResolver
{
    public const SITE_PARTIAL_SLUG = 'default';
    public const CHROMED_PAGE_TYPES = ['page', 'landing'];

    /**
     * Context flag: a theme template is being served as the page itself (a plugin's Single such as a Shop product,
     * search, archive, 404). The template is a `system` page, but the visitor sees it as a page, with the site's
     * header and footer.
     */
    public const SERVED_TEMPLATE = 'served_template';

    private const REGION_PAGE_TYPE = ['header' => 'header_partial', 'footer' => 'footer_partial'];

    /**
     * Repositories are optional only so the renderer can run in the
     * dependency-free unit harness; without them, `inherit`/`custom` resolve
     * to the built-in chrome.
     */
    public function __construct(
        private readonly ?PageRepository $pages = null,
        private readonly ?RevisionRepository $revisions = null,
        private readonly ?ThemeTemplateResolver $themeResolver = null,
    ) {}

    /**
     * The published Single template whose display conditions match this page, if any (Theme Builder). Only
     * pages that get chrome can have one.
     *
     * @param array<string, mixed> $context what the conditions may ask about the page (category, content_type…)
     * @return ?array{page: array<string, mixed>, revision: array<string, mixed>}
     */
    public function single(PageAddress $page, array $context = []): ?array
    {
        if ($this->themeResolver === null || !in_array($page->pageType, self::CHROMED_PAGE_TYPES, true)) {
            return null;
        }
        $hit = $this->themeResolver->resolveSingle($page, $context);
        // A template is never applied to itself.
        return ($hit !== null && (int) ($hit['page']['id'] ?? 0) !== (int) $page->id) ? $hit : null;
    }

    public function resolve(string $region, string $mode, PageAddress $page, array $context = []): ChromeSource
    {
        $chromed = in_array($page->pageType, self::CHROMED_PAGE_TYPES, true) || ($page->pageType === 'system' && !empty($context[self::SERVED_TEMPLATE]));
        if (!isset(self::REGION_PAGE_TYPE[$region]) || !$chromed || $mode === 'hidden') {
            return ChromeSource::hidden();
        }

        $partialType = self::REGION_PAGE_TYPE[$region];

        if ($mode === 'custom') {
            $custom = $this->publishedPartial($partialType, $page->slug);
            if ($custom !== null) {
                return $custom;
            }
        }

        // Conditional theme template resolution (Sprint 6 Theme Builder)
        if ($this->themeResolver !== null) {
            $conditional = $this->themeResolver->resolveChrome($region, $page, $context);
            if ($conditional !== null) {
                return $conditional;
            }
        }

        return $this->publishedPartial($partialType, self::SITE_PARTIAL_SLUG) ?? ChromeSource::builtin();
    }

    private function publishedPartial(string $pageType, string $slug): ?ChromeSource
    {
        if ($this->pages === null || $this->revisions === null) {
            return null;
        }
        $row = $this->pages->findBySlug($slug, $pageType);
        if ($row === null || ($row['status'] ?? null) !== 'published' || empty($row['published_revision_id'])) {
            return null;
        }
        $revision = $this->revisions->findByIdForPage((int) $row['id'], (int) $row['published_revision_id']);
        if ($revision === null) {
            return null;
        }
        return ChromeSource::partial((int) $revision['id'], (string) $revision['document_json']);
    }
}
