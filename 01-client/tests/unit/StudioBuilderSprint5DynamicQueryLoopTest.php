<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Sprint 5
 * Dynamic Bindings, Query Builder, Loop, Posts, Authors, Taxonomy, and Pagination.
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
    $studioS5UnitStandalone = true;
}

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Provider\AuthorsProvider;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Provider\PostsProvider;
use Slate\Module\StudioBuilder\Provider\TaxonomyProvider;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\HeadingRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\QueryLoopRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\TextRenderer;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;

final class _SbSprint5FakeMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia
    {
        return new ResolvedMedia('https://example.test/img' . $mediaId . '.jpg', 800, 600, 'image/jpeg');
    }
}
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Tenancy\TenantContext;

unit('sprint5 unit: PostsProvider parameter schema validates types, bounds, and defaults', function (): void {
    $provider = new PostsProvider();
    assert_eq('content.posts', $provider->key());
    assert_eq(50, $provider->maxResults());
    assert_null($provider->requiredEntitlement());
    assert_eq(StudioPermissions::VIEW, $provider->requiredPermission());

    $schema = $provider->parameterSchema();
    $valid = $schema->validate([
        'source'    => 'posts',
        'category'  => 'Tech',
        'limit'     => 10,
        'page'      => 2,
        'order_by'  => 'title',
        'order'     => 'asc',
    ]);
    assert_true($valid->isValid(), 'valid params accepted');

    $invalid = $schema->validate(['source' => 'unknown_source']);
    assert_true(!$invalid->isValid(), 'unknown source rejected');

    $outOfBounds = $schema->validate(['limit' => 100]);
    assert_true(!$outOfBounds->isValid(), 'limit > 50 rejected');
});

unit('sprint5 unit: AuthorsProvider and TaxonomyProvider contracts and parameters', function (): void {
    $authors = new AuthorsProvider();
    assert_eq('content.authors', $authors->key());
    assert_null($authors->requiredEntitlement());
    assert_eq(StudioPermissions::VIEW, $authors->requiredPermission());

    $validAuthors = $authors->parameterSchema()->validate(['limit' => 5]);
    assert_true($validAuthors->isValid(), 'authors limit accepted');

    $invalidAuthors = $authors->parameterSchema()->validate(['limit' => 200]);
    assert_true(!$invalidAuthors->isValid(), 'authors limit > 50 rejected');

    $taxonomy = new TaxonomyProvider();
    assert_eq('content.taxonomy', $taxonomy->key());
    assert_null($taxonomy->requiredEntitlement());
    assert_eq(StudioPermissions::VIEW, $taxonomy->requiredPermission());

    $validTax = $taxonomy->parameterSchema()->validate(['type' => 'category', 'limit' => 10]);
    assert_true($validTax->isValid(), 'taxonomy category accepted');

    $invalidTax = $taxonomy->parameterSchema()->validate(['type' => 'invalid_taxonomy']);
    assert_true(!$invalidTax->isValid(), 'invalid taxonomy rejected');
});

unit('sprint5 unit: BlockRegistry registers core.query_loop with schema and binding capabilities', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    assert_true($registry->has('core.query_loop'), 'core.query_loop is registered');

    $def = $registry->get('core.query_loop');
    assert_true($def !== null);
    assert_true($def->allowsChildren(), 'query loop allows custom card template children');
    assert_true(in_array('content.posts', $def->allowedBindingProviders(), true), 'allows content.posts binding');

    $schema = $def->schema();
    $validProps = $def->validateProps([
        'columns'           => 3,
        'per_page'          => 6,
        'card_variant'      => 'card',
        'enable_pagination' => true,
        'read_more_text'    => 'View Article',
    ]);
    assert_true($validProps->isValid(), 'valid query loop props accepted');

    $headingDef = $registry->get('core.heading');
    assert_true(in_array('content.posts', $headingDef->allowedBindingProviders(), true), 'core.heading allows content.posts binding');

    $textDef = $registry->get('core.text');
    assert_true(in_array('content.posts', $textDef->allowedBindingProviders(), true), 'core.text allows content.posts binding');
});

unit('sprint5 unit: BlockRenderScope resolves dynamic binding mapped properties', function (): void {
    $block = [
        'id'    => 'heading_1',
        'type'  => 'core.heading',
        'props' => ['text' => 'Fallback Title', 'level' => 'h2'],
        'bindings' => [
            'post' => [
                'provider' => 'content.posts',
                'mapping'  => [
                    'text' => 'title',
                ],
            ],
        ],
    ];

    $bindingRows = [
        'post' => [
            [
                'id'    => 42,
                'title' => 'Dynamic Blog Post Title',
                'slug'  => 'dynamic-blog-post-title',
            ],
        ],
    ];

    $context = RenderContext::forPublic(1, new SiteContext('https://example.test', 'Test Site', 'en'));
    $scope = new BlockRenderScope(
        $block,
        $context,
        new _SbSprint5FakeMedia(),
        new ResolvedTheme('default', []),
        new RenderCollector('nonce'),
        '',
        $bindingRows
    );

    // Dynamic mapped property should override static prop
    assert_eq('Dynamic Blog Post Title', $scope->string('text'));

    $renderer = new HeadingRenderer();
    $html = $renderer->render($scope);
    assert_true(str_contains($html, 'Dynamic Blog Post Title'), 'HeadingRenderer output contains dynamic bound title');
    assert_true(!str_contains($html, 'Fallback Title'), 'Fallback title was correctly replaced');
});

unit('sprint5 unit: QueryLoopRenderer renders post card grid and pagination', function (): void {
    $block = [
        'id'    => 'loop_1',
        'type'  => 'core.query_loop',
        'props' => [
            'columns'           => 2,
            'gap'               => 'lg',
            'card_variant'      => 'card',
            'read_more_text'    => 'Read Story',
            'enable_pagination' => true,
        ],
        'bindings' => [
            'items' => [
                'provider' => 'content.posts',
            ],
        ],
    ];

    $postRows = [
        [
            'id'             => 101,
            'title'          => 'First Post',
            'slug'           => 'first-post',
            'url'            => '/first-post',
            'excerpt'        => 'This is the first post excerpt describing something amazing.',
            'category'       => 'Design',
            'author_name'    => 'Alice Smith',
            'date_formatted' => 'Oct 1, 2026',
            'featured_image' => 'https://example.test/img1.jpg',
        ],
        [
            'id'             => 102,
            'title'          => 'Second Post',
            'slug'           => 'second-post',
            'url'            => '/second-post',
            'excerpt'        => 'This is the second post excerpt with helpful tips.',
            'category'       => 'Engineering',
            'author_name'    => 'Bob Jones',
            'date_formatted' => 'Oct 2, 2026',
            'featured_image' => '',
        ],
    ];

    $bindingRows = ['items' => $postRows];

    $context = RenderContext::forPublic(1, new SiteContext('https://example.test', 'Test Site', 'en'));
    $scope = new BlockRenderScope(
        $block,
        $context,
        new _SbSprint5FakeMedia(),
        new ResolvedTheme('default', []),
        new RenderCollector('nonce'),
        '',
        $bindingRows
    );

    $renderer = new QueryLoopRenderer();
    assert_true($renderer->isDynamic(), 'QueryLoopRenderer is dynamic');

    $html = $renderer->render($scope);
    assert_true(str_contains($html, 'sb-query-loop'), 'contains query loop container');
    assert_true(preg_match('/sb-grid sb-cols-2 sb-dcols sb-gap-[a-z0-9]+" style="--sb-dc-d:2;--sb-dc-t:2;--sb-dc-m:1"/', $html) === 1, 'the grid carries the column and gap classes the stylesheet defines, and one column on a phone');
    assert_true(str_contains($html, 'First Post'), 'renders first post title');
    assert_true(str_contains($html, 'Second Post'), 'renders second post title');
    assert_true(str_contains($html, 'Design'), 'renders category badge');
    assert_true(str_contains($html, 'Alice Smith'), 'renders author');
    assert_true(str_contains($html, 'Read Story'), 'renders read more button text');
    assert_false(str_contains($html, 'sb-pagination'), 'two posts fit on one page of six: no pager');
});

unit('query loop pager: only links to pages that can exist (W-F3)', function (): void {
    $rows = static fn(int $n): array => array_map(static fn(int $i): array => ['id' => $i, 'title' => "Post {$i}", 'url' => "/p{$i}"], range(1, $n));
    $render = static function (array $props, array $rows): string {
        $block = ['id' => 'loop_p', 'type' => 'core.query_loop', 'props' => $props + ['enable_pagination' => true], 'bindings' => ['items' => ['provider' => 'content.posts']]];
        $scope = new BlockRenderScope($block, RenderContext::forPublic(1, new SiteContext('https://example.test', 'Test Site', 'en')), new _SbSprint5FakeMedia(), new ResolvedTheme('default', []), new RenderCollector('nonce'), '', ['items' => $rows]);
        return (new QueryLoopRenderer())->render($scope);
    };

    $first = $render(['per_page' => 3], $rows(3));
    assert_true(str_contains($first, 'href="?page=2" class="sb-pagination__next"'), 'a full first page offers the next page');
    assert_false(str_contains($first, 'href="?page=0"') || str_contains($first, 'sb-pagination__prev" rel'), 'page 1 has no previous link');
    assert_true(str_contains($first, 'aria-current="page">1<'), 'the current page is 1');

    $last = $render(['per_page' => 3, 'page' => 3], $rows(1));
    assert_true(str_contains($last, 'href="?page=2" class="sb-pagination__prev"'), 'page 3 links back to page 2');
    assert_false(str_contains($last, '?page=4'), 'a short page offers no next page');
    assert_true(str_contains($last, 'aria-current="page">3<'), 'the current page is 3');

    assert_false(str_contains($render(['per_page' => 6], $rows(3)), 'sb-pagination'), 'everything on page 1: no pager');
    assert_false(str_contains($render(['per_page' => 3, 'enable_pagination' => false], $rows(3)), 'sb-pagination'), 'pagination switched off: no pager');
});

unit('sprint5 unit: QueryLoopRenderer custom template child repeats with token substitution', function (): void {
    $block = [
        'id'    => 'loop_custom',
        'type'  => 'core.query_loop',
        'props' => ['columns' => 3],
        'bindings' => ['items' => ['provider' => 'content.posts']],
    ];

    $postRows = [
        [
            'title'          => 'Post Alpha',
            'url'            => '/alpha',
            'author_name'    => 'Dr. Alpha',
            'category'       => 'Science',
        ],
        [
            'title'          => 'Post Beta',
            'url'            => '/beta',
            'author_name'    => 'Prof. Beta',
            'category'       => 'Math',
        ],
    ];

    $customChildHtml = '<div class="custom-card"><h4 class="custom-title"><a href="{url}">{title}</a></h4><span class="custom-author">{author}</span></div>';

    $context = RenderContext::forPublic(1, new SiteContext('https://example.test', 'Test Site', 'en'));
    $scope = new BlockRenderScope(
        $block,
        $context,
        new _SbSprint5FakeMedia(),
        new ResolvedTheme('default', []),
        new RenderCollector('nonce'),
        $customChildHtml,
        ['items' => $postRows]
    );

    $renderer = new QueryLoopRenderer();
    $html = $renderer->render($scope);

    assert_true(str_contains($html, 'Post Alpha'), 'interpolates Post Alpha');
    assert_true(str_contains($html, 'href="/alpha"'), 'interpolates /alpha link');
    assert_true(str_contains($html, 'Dr. Alpha'), 'interpolates Dr. Alpha');
    assert_true(str_contains($html, 'Post Beta'), 'interpolates Post Beta');
    assert_true(str_contains($html, 'Prof. Beta'), 'interpolates Prof. Beta');
});

unit('query loop: every class on its grid is one the stylesheet defines, and a phone gets one column', function (): void {
    $css = \Slate\Module\StudioBuilder\Render\StudioStylesheet::css();
    foreach (range(1, 6) as $n) {
        assert_true(str_contains($css, ".sb-cols-{$n}{grid-template-columns:repeat({$n},minmax(0,1fr))}"), "columns {$n}");
    }
    foreach (\Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::ALLOWED_SPACING_SCALE as $gap) {
        assert_true(str_contains($css, ".sb-gap-{$gap}{gap:"), "gap {$gap}");
    }
    assert_true(str_contains($css, '.sb-query-loop .sb-query-loop__grid{grid-template-columns:minmax(0,1fr)}'), 'one column on a phone, over the column utility');
});
