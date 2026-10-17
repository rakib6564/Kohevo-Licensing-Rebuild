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
use Slate\Module\StudioBuilder\Render\Chrome\ChromeSource;

final class ThemeTemplateResolver
{
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
        $evalContext = array_merge([
            'page_id'   => $page->id,
            'slug'      => $page->slug,
            'page_type' => $page->pageType,
        ], $context);

        if (!isset($evalContext['category']) && $this->pages !== null) {
            $pageRow = null;
            if ($page->id !== null && method_exists($this->pages, 'find')) {
                $pageRow = $this->pages->find($page->id);
            }
            if ($pageRow === null && method_exists($this->pages, 'findBySlug')) {
                $pageRow = $this->pages->findBySlug($page->slug, $page->pageType);
            }
            if ($pageRow !== null && !empty($pageRow['settings_json'])) {
                try {
                    $decoded = CanonicalJson::decode((string) $pageRow['settings_json']);
                    if (is_array($decoded)) {
                        if (!empty($decoded['category'])) {
                            $evalContext['category'] = (string) $decoded['category'];
                        }
                        if (!empty($decoded['tags'])) {
                            $evalContext['tags'] = $decoded['tags'];
                        }
                    }
                } catch (\Throwable) {}
            }
        }

        // Fetch candidate partials for this region
        $candidates = method_exists($this->pages, 'allOfType')
            ? $this->pages->allOfType($partialType, 100)
            : [];

        $bestScore = 0;
        $bestRow   = null;

        foreach ($candidates as $row) {
            if (($row['status'] ?? null) !== 'published' || empty($row['published_revision_id'])) {
                continue;
            }

            // Exclude default partial from conditional scoring (it serves as fallback)
            if (($row['slug'] ?? '') === 'default') {
                continue;
            }

            $settings = $this->publishedSettings($row);

            $conditions = $settings['conditions'] ?? null;
            if (empty($conditions)) {
                // If no explicit conditions, check if category or target was configured
                if (!empty($settings['category'])) {
                    $conditions = [['scope' => 'category', 'value' => $settings['category']]];
                }
            }

            if (!empty($conditions)) {
                $score = TemplateConditionMatcher::score($conditions, $evalContext);
                if ($score > $bestScore) {
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
     * Resolve a Single template for a post or page.
     *
     * @param PageAddress          $page
     * @param array<string, mixed> $context
     * @return ?array{page: array<string, mixed>, revision: array<string, mixed>}
     */
    public function resolveSingle(PageAddress $page, array $context = []): ?array
    {
        return $this->resolveTemplate('single', array_merge([
            'page_id'   => $page->id,
            'slug'      => $page->slug,
            'page_type' => $page->pageType,
        ], $context));
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
        $candidates = [];
        if (method_exists($this->pages, 'allOfType')) {
            $candidates = array_merge(
                $this->pages->allOfType('system', 50),
                $this->pages->allOfType('page', 100)
            );
        }

        $bestScore = 0;
        $bestRow   = null;

        foreach ($candidates as $row) {
            if (($row['status'] ?? null) !== 'published' || empty($row['published_revision_id'])) {
                continue;
            }

            $slug = (string) ($row['slug'] ?? '');
            $settings = $this->publishedSettings($row);

            $templateType = (string) ($settings['template_type'] ?? '');
            $isRoleMatch = ($templateType === $role)
                || ($role === '404' && ($templateType === 'not_found' || $templateType === '404'))
                || ($slug === $role)
                || ($slug === "theme-{$role}");

            if (!$isRoleMatch) {
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

            if ($score > $bestScore) {
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
