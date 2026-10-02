<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Sprint 6
 * Theme Builder: Header, Footer, Single, Archive, Search, 404, Conditions, and Theme Blocks.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/src/autoload.php';
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
    $studioS6UnitStandalone = true;
}

use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\ArchiveTitleRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\PostContentRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\PostMetaRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\PostTitleRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\SearchBoxRenderer;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeResolver;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeSource;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Module\StudioBuilder\Runtime\PublicResponse;
use Slate\Module\StudioBuilder\Theme\TemplateConditionMatcher;
use Slate\Module\StudioBuilder\Theme\ThemeTemplateResolver;
use Slate\Module\StudioBuilder\Theme\ThemeTemplateType;
use Slate\Tenancy\TenantContext;

unit('sprint6 unit: ThemeTemplateType enumeration, validation, and chrome checks', function (): void {
    assert_true(ThemeTemplateType::isValid('header'));
    assert_true(ThemeTemplateType::isValid('footer'));
    assert_true(ThemeTemplateType::isValid('single'));
    assert_true(ThemeTemplateType::isValid('archive'));
    assert_true(ThemeTemplateType::isValid('search'));
    assert_true(ThemeTemplateType::isValid('not_found'));
    assert_true(ThemeTemplateType::isValid('404'));

    assert_true(!ThemeTemplateType::isValid(''));
    assert_true(!ThemeTemplateType::isValid('custom'));
    assert_true(!ThemeTemplateType::isValid('popup'));
    assert_true(!ThemeTemplateType::isValid('modal'));

    assert_true(ThemeTemplateType::isChrome('header'));
    assert_true(ThemeTemplateType::isChrome('footer'));
    assert_true(!ThemeTemplateType::isChrome('single'));
    assert_true(!ThemeTemplateType::isChrome('archive'));
    assert_true(!ThemeTemplateType::isChrome('search'));
    assert_true(!ThemeTemplateType::isChrome('not_found'));

    $all = ThemeTemplateType::all();
    assert_eq(6, count($all));
    assert_true(in_array('header', $all, true));
    assert_true(in_array('not_found', $all, true));
});

unit('sprint6 unit: TemplateConditionMatcher scoring, specificity, and operators', function (): void {
    $matcher = new TemplateConditionMatcher();

    // 1. Exact condition match (score 300)
    $scoreCategory = $matcher->score([
        'rules' => [
            ['type' => 'include', 'condition' => 'category', 'operator' => 'in', 'value' => 'Engineering'],
        ],
    ], ['category' => 'Engineering', 'content_type' => 'post']);
    assert_true($scoreCategory >= 300, 'category match has high specificity');

    // 2. Content-type match (score 200)
    $scoreSingular = $matcher->score([
        'rules' => [
            ['type' => 'include', 'condition' => 'singular', 'operator' => 'is', 'value' => 'post'],
        ],
    ], ['content_type' => 'post', 'category' => 'Marketing']);
    assert_true($scoreSingular >= 200 && $scoreSingular < 300, 'content-type match has medium specificity');

    // 3. Global match (score 100)
    $scoreGlobal = $matcher->score([
        'rules' => [
            ['type' => 'include', 'condition' => 'entire_site'],
        ],
    ], ['content_type' => 'post', 'category' => 'Marketing']);
    assert_true($scoreGlobal >= 100 && $scoreGlobal < 200, 'global match has low specificity');

    // 4. Specificity ordering
    assert_true($scoreCategory > $scoreSingular, 'exact condition outscores content-type');
    assert_true($scoreSingular > $scoreGlobal, 'content-type outscores global');

    // 5. Non-matching condition returns 0
    $scoreMismatch = $matcher->score([
        'rules' => [
            ['type' => 'include', 'condition' => 'category', 'operator' => 'in', 'value' => 'Engineering'],
        ],
    ], ['category' => 'Design', 'content_type' => 'post']);
    assert_eq(0, $scoreMismatch, 'mismatched category returns score 0');

    // 6. Exclude rule overrides
    $scoreExcluded = $matcher->score([
        'rules' => [
            ['type' => 'include', 'condition' => 'entire_site'],
            ['type' => 'exclude', 'condition' => 'category', 'operator' => 'is', 'value' => 'Secret'],
        ],
    ], ['category' => 'Secret', 'content_type' => 'post']);
    assert_eq(0, $scoreExcluded, 'exclude rule zeroes out the score');
});

unit('sprint6 unit: ThemeTemplateResolver conditional header and footer resolution', function (): void {
    // Fake pages repo
    $pagesRepo = new class {
        /** @var array<int, array<string, mixed>> */
        public array $pages = [];
        public function allOfType(string $type, int $limit = 100): array {
            return array_values(array_filter($this->pages, fn($p) => ($p['page_type'] ?? '') === $type && ($p['status'] ?? '') === 'published'));
        }
    };

    // Fake revisions repo
    $revisionsRepo = new class {
        /** @var array<int, array<string, mixed>> */
        public array $revisions = [];
        public function findByIdForPage(int $pageId, int $revId): ?array {
            return $this->revisions[$revId] ?? null;
        }
    };

    $resolver = new ThemeTemplateResolver($pagesRepo, $revisionsRepo);

    // Setup 2 header partials: one default (fallback) and one tech-specific
    $pagesRepo->pages = [
        [
            'id' => 101, 'uuid' => 'page-header-default', 'title' => 'Default Header', 'slug' => 'default', 'page_type' => 'header_partial',
            'status' => 'published', 'route_mode' => 'custom', 'published_revision_id' => 201,
            'settings_json' => json_encode(['template_type' => 'header']),
        ],
        [
            'id' => 102, 'uuid' => 'page-header-tech', 'title' => 'Tech Header', 'slug' => 'tech-header', 'page_type' => 'header_partial',
            'status' => 'published', 'route_mode' => 'custom', 'published_revision_id' => 202,
            'settings_json' => json_encode([
                'template_type' => 'header',
                'conditions' => [
                    'rules' => [['type' => 'include', 'condition' => 'category', 'value' => 'Tech']],
                ],
            ]),
        ],
    ];

    $revisionsRepo->revisions = [
        201 => [
            'id' => 201, 'page_id' => 101, 'uuid' => 'rev-header-default',
            'document_json' => json_encode([
                'schema_version' => '1.0',
                'document_type'  => 'header_partial',
                'template_key'   => 'default',
                'settings'       => ['title' => 'Default Header'],
                'sections'       => [],
            ]),
        ],
        202 => [
            'id' => 202, 'page_id' => 102, 'uuid' => 'rev-header-tech',
            'document_json' => json_encode([
                'schema_version' => '1.0',
                'document_type'  => 'header_partial',
                'template_key'   => 'default',
                'settings'       => ['title' => 'Tech Header'],
                'sections'       => [],
            ]),
        ],
    ];

    $targetPage = PageAddress::fromRow([
        'id' => 10, 'uuid' => '10000000-0000-0000-0000-000000000010', 'title' => 'Post 1', 'slug' => 'post-1', 'page_type' => 'page',
        'status' => 'published', 'route_mode' => 'standalone', 'published_revision_id' => 50,
    ]);

    // Case 1: Post with category=Tech matches the tech header
    $resolvedTech = $resolver->resolveHeader($targetPage, ['category' => 'Tech']);
    assert_true($resolvedTech !== null, 'tech header resolved');
    assert_eq(202, $resolvedTech->revisionId);

    // Case 2: Post with category=Design has no specific header, returns null (allowing ChromeResolver fallback to default)
    $resolvedDesign = $resolver->resolveHeader($targetPage, ['category' => 'Design']);
    assert_null($resolvedDesign, 'no specific header match for design');
});

unit('sprint6 unit: ThemeTemplateResolver archive, 404, and search resolution', function (): void {
    $pagesRepo = new class {
        public array $pages = [];
        public function allOfType(string $type, int $limit = 100): array {
            return array_values(array_filter($this->pages, fn($p) => ($p['page_type'] ?? '') === $type && ($p['status'] ?? '') === 'published'));
        }
    };
    $revisionsRepo = new class {
        public array $revisions = [];
        public function findByIdForPage(int $pageId, int $revId): ?array { return $this->revisions[$revId] ?? null; }
    };

    $resolver = new ThemeTemplateResolver($pagesRepo, $revisionsRepo);

    $pagesRepo->pages = [
        [
            'id' => 301, 'uuid' => 'page-404', 'title' => '404', 'slug' => '404', 'page_type' => 'system',
            'status' => 'published', 'route_mode' => 'custom', 'published_revision_id' => 401,
            'settings_json' => json_encode(['template_type' => 'not_found']),
        ],
        [
            'id' => 302, 'uuid' => 'page-archive', 'title' => 'Archive', 'slug' => 'archive', 'page_type' => 'system',
            'status' => 'published', 'route_mode' => 'custom', 'published_revision_id' => 402,
            'settings_json' => json_encode(['template_type' => 'archive']),
        ],
        [
            'id' => 303, 'uuid' => 'page-search', 'title' => 'Search', 'slug' => 'search', 'page_type' => 'system',
            'status' => 'published', 'route_mode' => 'custom', 'published_revision_id' => 403,
            'settings_json' => json_encode(['template_type' => 'search']),
        ],
    ];

    $revisionsRepo->revisions = [
        401 => [
            'id' => 401, 'page_id' => 301, 'uuid' => 'rev-404',
            'document_json' => json_encode([
                'schema_version' => '1.0',
                'document_type'  => 'system',
                'template_key'   => 'default',
                'settings'       => ['title' => '404 Page'],
                'sections'       => [],
            ]),
        ],
        402 => [
            'id' => 402, 'page_id' => 302, 'uuid' => 'rev-archive',
            'document_json' => json_encode([
                'schema_version' => '1.0',
                'document_type'  => 'system',
                'template_key'   => 'default',
                'settings'       => ['title' => 'Archive Page'],
                'sections'       => [],
            ]),
        ],
        403 => [
            'id' => 403, 'page_id' => 303, 'uuid' => 'rev-search',
            'document_json' => json_encode([
                'schema_version' => '1.0',
                'document_type'  => 'system',
                'template_key'   => 'default',
                'settings'       => ['title' => 'Search Page'],
                'sections'       => [],
            ]),
        ],
    ];

    $res404 = $resolver->resolveNotFound();
    assert_true($res404 !== null, '404 template resolved');
    assert_eq('page-404', $res404['page']['uuid']);

    $resArchive = $resolver->resolveArchive('category', 'Engineering');
    assert_true($resArchive !== null, 'archive template resolved');
    assert_eq('page-archive', $resArchive['page']['uuid']);

    $resSearch = $resolver->resolveSearch('studio builder');
    assert_true($resSearch !== null, 'search template resolved');
    assert_eq('page-search', $resSearch['page']['uuid']);
});

unit('sprint6 unit: PublicResponse::notFound returns 404 status and headers', function (): void {
    $res = PublicResponse::notFound(['Content-Type' => 'text/html; charset=utf-8'], '<h1>404 Not Found</h1>');
    assert_eq(404, $res->status);
    assert_eq('<h1>404 Not Found</h1>', $res->body);
    assert_eq('text/html; charset=utf-8', $res->headers['Content-Type'] ?? null);
});

final class _SbSprint6FakeMedia implements \Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?\Slate\Module\StudioBuilder\Render\Media\ResolvedMedia
    {
        return new \Slate\Module\StudioBuilder\Render\Media\ResolvedMedia('https://example.test/img' . $mediaId . '.jpg', 800, 600, 'image/jpeg');
    }
}

unit('sprint6 unit: Theme block renderers output correct semantic HTML and dynamic data', function (): void {
    $collector = new RenderCollector();
    $site = new SiteContext('https://example.test', 'My Site', '', '', 'en');
    $theme = new ResolvedTheme('default', []);
    $media = new _SbSprint6FakeMedia();

    // 1. PostTitleRenderer
    $titleRenderer = new PostTitleRenderer();
    assert_eq('theme.post_title', $titleRenderer->type());

    // With dynamic context attribute
    $contextWithAttr = RenderContext::forPublic(1, $site, null, ['title' => 'Custom Dynamic Post Title']);
    $scopeWithAttr = new BlockRenderScope(
        ['id' => 't1', 'type' => 'theme.post_title', 'props' => ['tag' => 'h2', 'link_to_post' => false]],
        $contextWithAttr, $media, $theme, $collector
    );
    $htmlTitle = $titleRenderer->render($scopeWithAttr);
    assert_true(str_contains($htmlTitle, '<h2 class="sb-post-title">Custom Dynamic Post Title</h2>'), "rendered h2 title: {$htmlTitle}");

    // With link_to_post
    $contextLink = RenderContext::forPublic(1, $site, null, ['title' => 'Linked Title', 'post_url' => '/blog/linked']);
    $scopeLink = new BlockRenderScope(
        ['id' => 't2', 'type' => 'theme.post_title', 'props' => ['tag' => 'h1', 'link_to_post' => true]],
        $contextLink, $media, $theme, $collector
    );
    $htmlLink = $titleRenderer->render($scopeLink);
    assert_true(str_contains($htmlLink, '<a href="/blog/linked">Linked Title</a>'), "rendered linked title: {$htmlLink}");

    // 2. PostContentRenderer
    $contentRenderer = new PostContentRenderer();
    assert_eq('theme.post_content', $contentRenderer->type());
    $contextContent = RenderContext::forPublic(1, $site, null, ['content' => '<p>Article body content paragraph.</p>']);
    $scopeContent = new BlockRenderScope(
        ['id' => 'c1', 'type' => 'theme.post_content', 'props' => []],
        $contextContent, $media, $theme, $collector
    );
    $htmlContent = $contentRenderer->render($scopeContent);
    assert_true(str_contains($htmlContent, '<div class="sb-post-content"><p>Article body content paragraph.</p></div>'), "rendered post content: {$htmlContent}");

    // 3. PostMetaRenderer
    $metaRenderer = new PostMetaRenderer();
    assert_eq('theme.post_meta', $metaRenderer->type());
    $contextMeta = RenderContext::forPublic(1, $site, null, [
        'author_name' => 'Alice Dev',
        'date'        => '2026-10-03',
        'category'    => 'Engineering',
    ]);
    $scopeMeta = new BlockRenderScope(
        ['id' => 'm1', 'type' => 'theme.post_meta', 'props' => ['show_author' => true, 'show_date' => true, 'show_category' => true]],
        $contextMeta, $media, $theme, $collector
    );
    $htmlMeta = $metaRenderer->render($scopeMeta);
    assert_true(str_contains($htmlMeta, 'Alice Dev'), "contains author name: {$htmlMeta}");
    assert_true(str_contains($htmlMeta, '2026-10-03'), "contains date: {$htmlMeta}");
    assert_true(str_contains($htmlMeta, 'Engineering'), "contains category: {$htmlMeta}");

    // 4. ArchiveTitleRenderer
    $archiveRenderer = new ArchiveTitleRenderer();
    assert_eq('theme.archive_title', $archiveRenderer->type());
    $contextArchive = RenderContext::forPublic(1, $site, null, [
        'archive_type' => 'category',
        'term'         => 'Open Source',
    ]);
    $scopeArchive = new BlockRenderScope(
        ['id' => 'a1', 'type' => 'theme.archive_title', 'props' => ['tag' => 'h1', 'include_context' => true]],
        $contextArchive, $media, $theme, $collector
    );
    $htmlArchive = $archiveRenderer->render($scopeArchive);
    assert_true(str_contains($htmlArchive, 'Category: Open Source'), "contains category archive title: {$htmlArchive}");

    // 5. SearchBoxRenderer
    $searchRenderer = new SearchBoxRenderer();
    assert_eq('theme.search_box', $searchRenderer->type());
    $contextSearch = RenderContext::forPublic(1, $site, null, ['search_query' => 'elementor']);
    $scopeSearch = new BlockRenderScope(
        ['id' => 's1', 'type' => 'theme.search_box', 'props' => ['placeholder' => 'Search articles...', 'button_text' => 'Find']],
        $contextSearch, $media, $theme, $collector
    );
    $htmlSearch = $searchRenderer->render($scopeSearch);
    assert_true(str_contains($htmlSearch, '<form class="sb-search-form"') && str_contains($htmlSearch, 'action="/search"'), "contains form: {$htmlSearch}");
    assert_true(str_contains($htmlSearch, 'placeholder="Search articles..."'), "contains placeholder: {$htmlSearch}");
    assert_true(str_contains($htmlSearch, 'value="elementor"'), "contains pre-filled value: {$htmlSearch}");
    assert_true(str_contains($htmlSearch, '>Find</button>'), "contains submit button: {$htmlSearch}");
});
