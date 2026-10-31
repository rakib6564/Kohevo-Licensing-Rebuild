<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the container options of the Container section.
 *
 * Autoloader only, no database. A boxed content width (`layout.content_width` / `content_max`), a fixed background
 * (`background.attachment`), and the `main` and linked `a` wrapper elements (`tag`, `link`).
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Document\StyleSurface;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Operation\DocumentOperationApplier;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Tenancy\TenantContext;

/** A page with one container block carrying `$extra` (tag, link, style…). */
function sbco_doc(array $extra = [], array $style = []): array
{
    return ['schema_version' => '2.0', 'document_type' => 'page', 'template_key' => 'default', 'settings' => [], 'seo' => [], 'containers' => [CanonicalDocumentSchema::newContainer(['metadata' => ['label' => 'S'], 'id' => 'blk_aaaaaaaaaaaaaaaa', 'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'children' => [[
            'id' => 'blk_aaaaaaaaaaaaaaaa', 'type' => 'core.container', 'version' => 2, 'props' => [],
            'style' => $style + CanonicalDocumentSchema::defaultBlockStyle(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [],
        ] + $extra]])]];
}

function sbco_valid(array $extra = [], array $style = []): bool
{
    return DocumentValidator::validate(sbco_doc($extra, $style), BlockRegistry::withAllCoreBlocks())->isValid();
}

/** One container through the real renderer (public output). */
function sbco_render(array $extra): string
{
    $tenants  = new TenantContext();
    $renderer = new DocumentRenderer(BlockRegistry::withAllCoreBlocks(), BlockRendererRegistry::withCoreRenderers(), new CoreMediaResolver($tenants), new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
    return $renderer->renderBlock(sbco_doc($extra)['containers'][0]['children'][0], RenderContext::forPublic(101, new SiteContext('https://example.test', 'T')), (new ThemeResolver())->resolve('default'), new RenderCollector(), false);
}

// ── Boxed content width ───────────────────────────────────────────────────

unit('container: boxed holds the children to the width with side padding, and the author padding stays the minimum', function (): void {
    $css = StyleSurface::declarations(['layout' => ['content_width' => 'boxed', 'content_max' => '1300px'], 'padding' => ['left' => '2rem', 'right' => '1rem']]);
    assert_true(str_contains($css, 'padding-left:max(2rem,calc((100% - 1300px)/2))'), 'left side: ' . $css);
    assert_true(str_contains($css, 'padding-right:max(1rem,calc((100% - 1300px)/2))'), 'right side');
    assert_eq(1, substr_count($css, 'padding-left:'), 'one left padding, not the author\'s beside the boxed one');
    assert_false(str_contains($css, 'content'), 'content_width and content_max are never CSS properties of their own');
});

unit('container: full width changes nothing; boxed without a width keeps to the site content width', function (): void {
    assert_eq('', StyleSurface::declarations(['layout' => ['content_width' => 'full', 'content_max' => '900px']]), 'full width');
    assert_eq(
        'padding-left:max(0px,calc((100% - var(--sb-layout-content,80rem))/2));padding-right:max(0px,calc((100% - var(--sb-layout-content,80rem))/2))',
        StyleSurface::declarations(['layout' => ['content_width' => 'boxed']]),
        'boxed with no width of its own follows Site settings › Layout › Content width'
    );
});

unit('container: the boxed fields are validated, and set once for every device', function (): void {
    assert_true(sbco_valid([], ['layout' => ['content_width' => 'boxed', 'content_max' => '80%']]), 'boxed at 80%');
    assert_false(sbco_valid([], ['layout' => ['content_width' => 'wide']]), 'unknown content width');
    assert_false(sbco_valid([], ['layout' => ['content_max' => '1300px;color:red']]), 'no CSS smuggled in a width');
    assert_false(sbco_valid([], ['layout' => ['content_max' => 'auto']]), 'a width, not auto');
    assert_true(StyleSurface::responsiveIssues(['layout' => ['content_width' => 'boxed']], '$') !== [], 'not a per-device field');
});

// ── Fixed background ──────────────────────────────────────────────────────

unit('container: a background image can be fixed, and only scroll or fixed are accepted', function (): void {
    $bg = ['image' => ['media_id' => 7, 'alt' => ''], 'attachment' => 'fixed'];
    assert_true(str_contains(StyleSurface::declarations(['background' => $bg], '/media/7.jpg'), 'background-attachment:fixed !important'), 'fixed is written');
    assert_false(str_contains(StyleSurface::declarations(['background' => ['attachment' => 'fixed']], null), 'attachment'), 'nothing without an image');
    assert_eq([], StyleSurface::backgroundIssues($bg, '$'), 'fixed is fine');
    assert_true(StyleSurface::backgroundIssues(['attachment' => 'local'], '$') !== [], 'local is not offered');
    assert_eq([], StyleSurface::sectionIssues(['background' => $bg], '$'), 'a section background takes it too');
});

// ── main and the linked wrapper ───────────────────────────────────────────

unit('container: main and a are wrapper elements; a link needs a safe URL and only url and new_tab', function (): void {
    assert_true(sbco_valid(['tag' => 'main']), 'main');
    assert_true(sbco_valid(['tag' => 'a', 'link' => ['url' => 'https://example.test/menu', 'new_tab' => true]]), 'a with a link');
    assert_true(sbco_valid(['tag' => 'a', 'link' => ['url' => '#booking']]), 'an anchor');
    foreach ([['url' => 'javascript:alert(1)'], ['url' => ''], ['url' => '/x', 'new_tab' => 'yes'], ['url' => '/x', 'onclick' => 'y'], 'https://example.test', ['https://example.test']] as $bad) {
        assert_false(sbco_valid(['tag' => 'a', 'link' => $bad]), 'link ' . json_encode($bad) . ' must be refused');
    }
    assert_false(sbco_valid(['attributes' => ['href' => 'https://example.test']]), 'href is still never an author attribute');
});

unit('container: a linked wrapper renders its href; a new tab adds noopener; an unsafe stored URL is dropped', function (): void {
    $html = sbco_render(['tag' => 'a', 'link' => ['url' => 'https://example.test/menu?a=1&b=2', 'new_tab' => true]]);
    assert_true(str_starts_with($html, '<a href="https://example.test/menu?a=1&amp;b=2" target="_blank" rel="noopener noreferrer"'), $html);
    assert_true(str_ends_with($html, '</a>'), 'closed as an a');
    $same = sbco_render(['tag' => 'a', 'link' => ['url' => '/menu']]);
    assert_true(str_starts_with($same, '<a href="/menu" class='), 'same tab: no target: ' . $same);
    assert_false(str_contains(sbco_render(['tag' => 'a', 'link' => ['url' => 'javascript:alert(1)']]), 'href'), 'never an unsafe href, even if one was stored');
    assert_false(str_contains(sbco_render(['tag' => 'section', 'link' => ['url' => '/menu']]), 'href'), 'only an a takes the link');
    assert_true(str_starts_with(sbco_render(['tag' => 'main']), '<main '), 'main');
});

unit('container: changing the tag away from a drops the link', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $doc = DocumentOperationApplier::applyOne(sbco_doc(), new DocumentOperation('update_block_tag', ['block_id' => 'blk_aaaaaaaaaaaaaaaa', 'tag' => 'a', 'link' => ['url' => '/menu']]), $registry);
    assert_eq(['url' => '/menu'], $doc['containers'][0]['children'][0]['link'] ?? null, 'stored with the a tag');
    $doc = DocumentOperationApplier::applyOne($doc, new DocumentOperation('update_block_tag', ['block_id' => 'blk_aaaaaaaaaaaaaaaa', 'tag' => 'section']), $registry);
    assert_false(array_key_exists('link', $doc['containers'][0]['children'][0]), 'the address goes with the a');
    assert_throws(StudioValidationException::class, static fn () => DocumentOperationApplier::applyOne(sbco_doc(), new DocumentOperation('update_block_tag', ['block_id' => 'blk_aaaaaaaaaaaaaaaa', 'tag' => 'a', 'link' => ['url' => 'data:text/html,x']]), $registry), 'unsafe link refused');
});

unit('container: saving keeps a linked wrapper\'s address, and only with the a tag', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $kept = DocumentNormalizer::normalize(sbco_doc(['tag' => 'a', 'link' => ['url' => '/menu', 'new_tab' => true]]), $registry);
    assert_eq(['new_tab' => true, 'url' => '/menu'], $kept['containers'][0]['children'][0]['link'] ?? null, 'the link survives a save');
    $dropped = DocumentNormalizer::normalize(sbco_doc(['tag' => 'section', 'link' => ['url' => '/menu']]), $registry);
    assert_false(array_key_exists('link', $dropped['containers'][0]['children'][0]), 'a stray link on another tag is not kept');
});

unit('a section takes a border like a block: one for every side, each side\'s width, style and colour, and each corner\'s radius', function (): void {
    $border = ['style' => 'solid', 'width' => '1px', 'color' => 'color.accent', 'radius' => 'md',
        'bottom' => ['width' => '4px', 'color' => '#123456'], 'radius_corners' => ['tl' => '0']];
    assert_eq([], StyleSurface::sectionIssues(['border' => $border], '$'));
    assert_eq(
        'border-style:solid;border-width:1px;border-color:var(--sb-color-accent);border-radius:var(--sb-radius-md,.5rem);border-bottom-width:4px;border-bottom-color:#123456;border-top-left-radius:0',
        StyleSurface::sectionDeclarations(['border' => $border]),
        'all sides first, then the side and corner that win over them'
    );
    assert_eq(['$.border.width', '$.border.side', '$.border.top.width', '$.border.radius'], array_column(StyleSurface::sectionIssues(['border' => ['width' => 'red', 'side' => 1, 'top' => ['width' => 'x'], 'radius' => 'huge']], '$'), 'path'));
    assert_eq('border-style:solid;border-top-width:2px', StyleSurface::sectionDeclarations(['border' => ['top' => ['width' => '2px']]]), 'a width with no style draws a solid line');
    assert_eq('border-top-width:2px;border-top-style:dashed', StyleSurface::sectionDeclarations(['border' => ['top' => ['width' => '2px', 'style' => 'dashed']]]), 'a style the author chose is kept');
});
