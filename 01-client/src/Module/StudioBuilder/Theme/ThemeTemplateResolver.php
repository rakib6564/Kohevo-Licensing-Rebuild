<?php
/**
 * Kohevo Studio (studio-builder) — Theme Template Resolver.
 *
 * Resolves active Theme Builder templates (headers, footers, singles, archives,
 * search, 404) based on tenant context, template conditions, and deterministic
 * precedence.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Theme;

use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeSource;

final class ThemeTemplateResolver
{
    /** The site-wide header or footer (ChromeResolver::SITE_PARTIAL_SLUG): the fallback, never chosen by conditions. */
    private const SITE_PARTIAL_SLUG = 'default';

    private const REGION_PAGE_TYPES = [
        'header' => 'header_partial',
        'footer' => 'footer_partial',
    ];

    public function __construct(
        private readonly ?object $pages = null,
        private readonly ?object $revisions = null,
    ) {}

    /**
     * Resolve the best conditional header or footer for a given page.
     *
     * @param string               $region  'header' or 'footer'
     * @param PageAddress          $page    Target page being rendered
     * @param array<string, mixed> $context Additional context (e.g. category, author_id)
     */
    public function resolveChrome(string $region, PageAddress $page, array $context = []): ?ChromeSource
    {
        if ($this->pages === null || $this->revisions === null) {
            return null;
        }

        $partialType = self::REGION_PAGE_TYPES[$region] ?? null;
        if ($partialType === null) {
            return null;
        }

        // Merge page attributes into evaluation context
        $evalContext = array_merge(self::pageContext($page), $context);

        // The page's author comes from its row. Its category and tags come only from the caller, which reads them
        // from the PUBLISHED document: pages.settings_json mirrors the draft, and an unpublished category must not
        // change the live header or footer.
        if (!isset($evalContext['author_id'])) {
            $pageRow = null;
            if ($page->id !== null && method_exists($this->pages, 'find')) {
                $pageRow = $this->pages->find($page->id);
            }
            if ($pageRow === null && method_exists($this->pages, 'findBySlug')) {
                $pageRow = $this->pages->findBySlug($page->slug, $page->pageType);
            }
            if ($pageRow !== null && (int) ($pageRow['created_by'] ?? 0) > 0) {
                $evalContext['author_id'] = (int) $pageRow['created_by'];
            }
        }

        // Fetch candidate partials for this region
        $candidates = $this->candidates($partialType);

        $bestScore = 0;
        $bestRow   = null;

        foreach ($candidates as $row) {
            if (($row['status'] ?? null) !== 'published' || empty($row['published_revision_id'])) {
                continue;
            }

            // Exclude default partial from conditional scoring (it serves as fallback)
            if (($row['slug'] ?? '') === self::SITE_PARTIAL_SLUG) {
                continue;
            }

            $settings = $this->publishedSettings($row);

            $conditions = self::chromeConditions($settings);
            if (!empty($conditions)) {
                $score = TemplateConditionMatcher::score($conditions, $evalContext);
                if ($score > 0 && self::outranks($score, $row, $bestScore, $bestRow)) {
                    $bestScore = $score;
                    $bestRow   = $row;
                }
            }
        }

        if ($bestRow !== null && $bestScore > 0) {
            $rev = method_exists($this->revisions, 'findByIdForPage')
                ? $this->revisions->findByIdForPage((int) $bestRow['id'], (int) $bestRow['published_revision_id'])
                : null;
            if ($rev !== null) {
                return ChromeSource::partial((int) $rev['id'], (string) $rev['document_json']);
            }
        }

        return null;
    }

    /**
     * Convenience method for header resolution.
     *
     * @param PageAddress          $page
     * @param array<string, mixed> $context
     */
    public function resolveHeader(PageAddress $page, array $context = []): ?ChromeSource
    {
        return $this->resolveChrome('header', $page, $context);
    }

    /**
     * Convenience method for footer resolution.
     *
     * @param PageAddress          $page
     * @param array<string, mixed> $context
     */
    public function resolveFooter(PageAddress $page, array $context = []): ?ChromeSource
    {
        return $this->resolveChrome('footer', $page, $context);
    }

    /**
     * The condition context a page's Single template is chosen by, beyond the page itself: the document's category,
     * and its custom content type when it has one (otherwise the page type stands in for it). The compiler and the
     * editor's `layout_template` query both use it, so the editor names the template the live page really gets.
     *
     * @param array<string, mixed> $settings the page document's settings
     * @return array<string, string>
     */
    public static function singleContext(array $settings, int $tenantId, int $pageId): array
    {
        $context = [];
        if (is_string($settings['category'] ?? null) && $settings['category'] !== '') {
            $context['category'] = $settings['category'];
        }
        try {
            $type = PageMeta::read($tenantId, $pageId)['type'];
            if ($type !== '') {
                $context['content_type'] = $type;
            }
        } catch (\Throwable) {
            // No custom content type: the page type stands in for it.
        }
        return $context;
    }

    /**
     * The width of the section a Single template puts the page's content in (the section holding its first Post
     * content block, at any depth), or null when the template has no Post content block.
     *
     * @param array<string, mixed> $template the template's document
     */
    public static function contentWidth(array $template): ?string
    {
        $holds = static function (array $blocks) use (&$holds): bool {
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                if (($block['type'] ?? '') === 'theme.post_content' || $holds(is_array($block['children'] ?? null) ? $block['children'] : [])) {
                    return true;
                }
            }
            return false;
        };
        foreach (is_array($template['sections'] ?? null) ? $template['sections'] : [] as $section) {
            if (is_array($section) && $holds(is_array($section['blocks'] ?? null) ? $section['blocks'] : [])) {
                $width = $section['layout']['width'] ?? 'wide';
                return in_array($width, \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::ALLOWED_CONTAINER_WIDTHS, true) ? $width : 'wide';
            }
        }
        return null;
    }

    /**
     * Resolve a Single template for a post or page.
     *
     * @param PageAddress          $page
     * @param array<string, mixed> $context
     * @return ?array{page: array<string, mixed>, revision: array<string, mixed>}
     */
    public function resolveSingle(PageAddress $page, array $context = []): ?array
    {
        return $this->resolveTemplate('single', array_merge(self::pageContext($page), $context));
    }

    /**
     * Resolve an Archive template for a taxonomy or term.
     *
     * @param string               $archiveType 'category', 'tag', 'author', 'all'
     * @param string               $term        Term name or slug
     * @param array<string, mixed> $context
     * @return ?array{page: array<string, mixed>, revision: array<string, mixed>}
     */
    public function resolveArchive(string $archiveType, string $term, array $context = []): ?array
    {
        return $this->resolveTemplate('archive', array_merge([
            'archive_type' => $archiveType,
            'term'         => $term,
            'category'     => $archiveType === 'category' ? $term : '',
            'tag'          => $archiveType === 'tag' ? $term : '',
        ], $context));
    }

    /**
     * Resolve a Search results template.
     *
     * @param string               $query
     * @param array<string, mixed> $context
     * @return ?array{page: array<string, mixed>, revision: array<string, mixed>}
     */
    public function resolveSearch(string $query, array $context = []): ?array
    {
        return $this->resolveTemplate('search', array_merge([
            'search_query' => $query,
            'is_search'    => true,
        ], $context));
    }

    /**
     * Resolve the 404 Not Found template.
     *
     * @return ?array{page: array<string, mixed>, revision: array<string, mixed>}
     */
    public function resolveNotFound(): ?array
    {
        return $this->resolveTemplate('404', ['is_404' => true]);
    }

    /**
     * @param string               $role 'single'|'archive'|'search'|'404'
     * @param array<string, mixed> $evalContext
     * @return ?array{page: array<string, mixed>, revision: array<string, mixed>}
     */
    private function resolveTemplate(string $role, array $evalContext): ?array
    {
        if ($this->pages === null || $this->revisions === null) {
            return null;
        }

        // Query published pages of type 'system' or 'page'
        $candidates = array_merge($this->candidates('system'), $this->candidates('page'));

        $bestScore = 0;
        $bestRow   = null;

        foreach ($candidates as $row) {
            if (($row['status'] ?? null) !== 'published' || empty($row['published_revision_id'])) {
                continue;
            }

            $slug = (string) ($row['slug'] ?? '');
            $settings = $this->publishedSettings($row);

            if (!self::isRoleMatch($role, $settings, $slug)) {
                continue;
            }

            $conditions = $settings['conditions'] ?? null;
            $score = 100; // default base match score for matching role

            if (!empty($conditions)) {
                $condScore = TemplateConditionMatcher::score($conditions, $evalContext);
                if ($condScore > 0) {
                    $score = $condScore;
                } else {
                    // Conditions defined but did not match
                    continue;
                }
            }

            if (self::outranks($score, $row, $bestScore, $bestRow)) {
                $bestScore = $score;
                $bestRow   = $row;
            }
        }

        if ($bestRow !== null && $bestScore > 0) {
            $rev = method_exists($this->revisions, 'findByIdForPage')
                ? $this->revisions->findByIdForPage((int) $bestRow['id'], (int) $bestRow['published_revision_id'])
                : null;
            if ($rev !== null) {
                return [
                    'page'     => $bestRow,
                    'revision' => $rev,
                ];
            }
        }

        return null;
    }

    /**
     * The Theme Builder role of a page: 'header' or 'footer' for a header or footer partial, 'single', 'archive',
     * 'search' or '404' for a template (from its `template_type` or conventional slug), else null.
     *
     * @param array<string, mixed> $settings the page document's settings
     */
    public static function roleOf(string $pageType, array $settings, string $slug): ?string
    {
        $region = array_search($pageType, self::REGION_PAGE_TYPES, true);
        if (is_string($region)) {
            return $region;
        }
        if (!in_array($pageType, ['system', 'page'], true)) {
            return null;
        }
        return TemplatePreviewContext::kind(is_string($settings['template_type'] ?? null) ? $settings['template_type'] : '', $slug);
    }

    /**
     * Whether the live site would consider a template with these settings for a page: its display conditions
     * match the page (a header or footer with none is only ever the site default; a single, archive, search or 404
     * template with none applies everywhere). Whether a more specific template then wins is not part of the answer.
     *
     * @param array<string, mixed> $settings the template's settings
     * @param array<string, mixed> $context  what the conditions may ask about the page (category, tags, author_id…)
     */
    public static function appliesTo(string $role, string $templateSlug, array $settings, PageAddress $page, array $context = []): bool
    {
        $evalContext = array_merge(self::pageContext($page), $context);
        if (isset(self::REGION_PAGE_TYPES[$role])) {
            if ($templateSlug === self::SITE_PARTIAL_SLUG) {
                return true;
            }
            $conditions = self::chromeConditions($settings);
            return !empty($conditions) && TemplateConditionMatcher::score($conditions, $evalContext) > 0;
        }
        $conditions = $settings['conditions'] ?? null;
        return empty($conditions) || TemplateConditionMatcher::score($conditions, $evalContext) > 0;
    }

    /**
     * The other published templates of one role, with the display conditions they were PUBLISHED with, so the
     * editor can say which of them would tie with the one being edited. The site default header or footer is
     * left out (it is the fallback, never in a tie).
     *
     * @return list<array{id: int, title: string, published_at: ?string, conditions: mixed}>
     */
    public function peers(string $role, int $exceptPageId): array
    {
        if ($this->pages === null || $this->revisions === null) {
            return [];
        }
        $partialType = self::REGION_PAGE_TYPES[$role] ?? null;
        $rows = $partialType !== null ? $this->candidates($partialType) : array_merge($this->candidates('system'), $this->candidates('page'));
        $out = [];
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $exceptPageId || ($row['status'] ?? null) !== 'published' || empty($row['published_revision_id'])) {
                continue;
            }
            $slug = (string) ($row['slug'] ?? '');
            $settings = $this->publishedSettings($row);
            if ($partialType !== null ? $slug === self::SITE_PARTIAL_SLUG : !self::isRoleMatch($role, $settings, $slug)) {
                continue;
            }
            $out[] = [
                'id'           => (int) $row['id'],
                'title'        => mb_substr((string) ($row['title'] ?? ''), 0, 191),
                'published_at' => isset($row['published_at']) ? (string) $row['published_at'] : null,
                'conditions'   => $partialType !== null ? self::chromeConditions($settings) : ($settings['conditions'] ?? null),
            ];
            if (count($out) >= 100) {
                break;
            }
        }
        return $out;
    }

    /**
     * A header or footer's display conditions; an older one that names only a `category` setting applies there.
     *
     * @param array<string, mixed> $settings
     */
    private static function chromeConditions(array $settings): mixed
    {
        $conditions = $settings['conditions'] ?? null;
        if (empty($conditions) && !empty($settings['category'])) {
            $conditions = [['scope' => 'category', 'value' => $settings['category']]];
        }
        return $conditions;
    }

    /** @param array<string, mixed> $settings */
    private static function isRoleMatch(string $role, array $settings, string $slug): bool
    {
        $templateType = (string) ($settings['template_type'] ?? '');
        return ($templateType === $role)
            || ($role === '404' && ($templateType === 'not_found' || $templateType === '404'))
            || ($slug === $role)
            || ($slug === "theme-{$role}");
    }

    /**
     * Whether a candidate beats the best one so far. The more specific match wins; between equally specific
     * ones, the most recently published, then the newer template (higher id). Never the title: renaming a
     * template must not change which one the site shows.
     *
     * @param array<string, mixed>  $row
     * @param ?array<string, mixed> $bestRow
     */
    private static function outranks(int $score, array $row, int $bestScore, ?array $bestRow): bool
    {
        if ($bestRow === null || $score !== $bestScore) {
            return $score > $bestScore;
        }
        $published = strcmp((string) ($row['published_at'] ?? ''), (string) ($bestRow['published_at'] ?? ''));
        if ($published !== 0) {
            return $published > 0;
        }
        return (int) ($row['id'] ?? 0) > (int) ($bestRow['id'] ?? 0);
    }

    /**
     * What a display condition can ask about the page being shown. The front page is the page published
     * as the homepage, whatever its slug; the content type is the page's type.
     *
     * @return array<string, mixed>
     */
    private static function pageContext(PageAddress $page): array
    {
        return [
            'page_id'       => $page->id,
            'slug'          => $page->slug,
            'page_type'     => $page->pageType,
            'content_type'  => $page->pageType,
            'is_front_page' => $page->routeMode === 'homepage',
        ];
    }

    /**
     * Published template candidates of one page type. Filtering on status happens in the query, before
     * any limit, so templates are not lost on a site with many pages.
     *
     * @return list<array<string, mixed>>
     */
    private function candidates(string $pageType): array
    {
        if (method_exists($this->pages, 'publishedOfType')) {
            return $this->pages->publishedOfType($pageType);
        }
        return method_exists($this->pages, 'allOfType') ? $this->pages->allOfType($pageType, 5000) : [];
    }

    /**
     * The settings a template candidate was PUBLISHED with.
     *
     * pages.settings_json mirrors the current draft: every draft save,
     * rollback, package import and template apply rewrites it. Selecting
     * templates from it let unpublished edits to display conditions or
     * template_type change the live site, including by users who may edit
     * but not publish. When the working draft is the published revision the
     * column is an exact mirror (and also carries the Theme Builder link
     * fields), so it is used; otherwise the published revision's own
     * document settings are.
     *
     * @param array<string, mixed> $row a published page row
     * @return array<string, mixed>
     */
    private function publishedSettings(array $row): array
    {
        $published = (int) ($row['published_revision_id'] ?? 0);
        $draft     = isset($row['active_draft_revision_id']) ? (int) $row['active_draft_revision_id'] : 0;

        if ($draft === 0 || $draft === $published) {
            return self::decodeObject((string) ($row['settings_json'] ?? ''));
        }

        $rev = method_exists($this->revisions, 'findByIdForPage')
            ? $this->revisions->findByIdForPage((int) $row['id'], $published)
            : null;
        $doc = $rev !== null ? self::decodeObject((string) ($rev['document_json'] ?? '')) : [];
        return is_array($doc['settings'] ?? null) ? $doc['settings'] : [];
    }

    /** @return array<string, mixed> */
    private static function decodeObject(string $json): array
    {
        if ($json === '') {
            return [];
        }
        try {
            $decoded = CanonicalJson::decode($json);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
