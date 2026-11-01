<?php
/**
 * Kohevo Studio: core.gallery layouts, paging and filters.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DeviceProps;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Tenancy\TenantContext;

function sbgal_block(string $type, array $props, array $responsive = [], array $children = []): array
{
    $b = [
        'id' => CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => ($type === 'core.container' ? 2 : 1), 'props' => $props,
        'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [], 'children' => $children,
    ];
    return $responsive !== [] ? $b + ['responsive' => $responsive] : $b;
}

function sbgal_doc(array $block): array
{
    return ['schema_version' => '2.0', 'document_type' => 'page', 'template_key' => 'default', 'settings' => [], 'seo' => [], 'containers' => [CanonicalDocumentSchema::newContainer(['metadata' => ['label' => 'S'], 'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'children' => [$block]])]];
}

function sbgal_render(array $block): string
{
    $renderer = new DocumentRenderer(
        BlockRegistry::withAllCoreBlocks(),
        BlockRendererRegistry::withCoreRenderers(),
        new CoreMediaResolver(new TenantContext()),
        new ProviderBindingResolver(new DataProviderRegistry(), new TenantContext())
    );
    return $renderer->renderContainers(
        sbgal_doc($block)['containers'],
        RenderContext::forPublic(101, new SiteContext('https://example.test', 'Test Site')),
        (new ThemeResolver())->resolve('default'),
        new RenderCollector(),
        false
    );
}

/** @return list<array<string, mixed>> */
function sbgal_images(int $n, string $category = ''): array
{
    $out = [];
    for ($i = 1; $i <= $n; $i++) {
        $out[] = ['url' => "/uploads/{$i}.jpg", 'alt' => "Image {$i}", 'caption' => '', 'category' => $category, 'width' => 300, 'height' => 200];
    }
    return $out;
}

unit('gallery: a plain grid prints the same markup as before, with no runtime hooks', function (): void {
    $html = sbgal_render(sbgal_block('core.gallery', ['columns' => '4', 'images' => [['url' => '/a.jpg', 'alt' => 'A']]]));
    assert_true(str_contains($html, 'sb-gallery--cols-4 sb-gallery--gap-md sb-gallery--rounded sb-dcols"'), $html);
    assert_true(str_contains($html, 'sb-gallery__item sb-gallery__item--aspect-1-1'), $html);
    assert_true(!str_contains($html, 'data-sb-gallery') && !str_contains($html, 'sb-gallery-box'), $html);
});

unit('gallery: each layout has its class, and only grid and slider crop to a ratio', function (): void {
    foreach (['masonry', 'justified', 'mosaic'] as $layout) {
        $html = sbgal_render(sbgal_block('core.gallery', ['layout' => $layout, 'images' => sbgal_images(3)]));
        assert_true(str_contains($html, 'sb-gallery--' . $layout) && str_contains($html, 'data-sb-gallery="' . $layout . '"'), $layout);
        assert_true(!str_contains($html, 'sb-gallery__item--aspect'), $layout);
    }
    $html = sbgal_render(sbgal_block('core.gallery', ['layout' => 'justified', 'row_height' => 300, 'images' => sbgal_images(2)]));
    assert_true(str_contains($html, '--sb-gal-h:300px') && str_contains($html, '--sb-gal-r:1.5') && str_contains($html, 'width="300" height="200"'), $html);
    $html = sbgal_render(sbgal_block('core.gallery', ['layout' => 'mosaic', 'images' => sbgal_images(7)]));
    assert_eq(2, substr_count($html, 'is-big'));
});

unit('gallery: paging prints every image, hides those past the first page, and shows them without the runtime', function (): void {
    $html = sbgal_render(sbgal_block('core.gallery', ['pagination' => 'load_more', 'per_page' => 4, 'load_more_text' => 'More dishes', 'images' => sbgal_images(10)]));
    assert_eq(10, substr_count($html, '<figure'));
    assert_eq(6, substr_count($html, ' is-more'));
    assert_true(str_contains($html, 'data-sb-per-page="4"') && str_contains($html, 'data-sb-pagination="load_more"') && str_contains($html, '>More dishes</button>'), $html);
    assert_true(str_contains($html, '<noscript>'), 'all images show without the runtime');
    $short = sbgal_render(sbgal_block('core.gallery', ['pagination' => 'infinite', 'per_page' => 4, 'images' => sbgal_images(3)]));
    assert_true(!str_contains($short, 'is-more') && !str_contains($short, 'sb-gallery__more-btn'), 'nothing to page');
    $slider = sbgal_render(sbgal_block('core.gallery', ['layout' => 'slider', 'pagination' => 'load_more', 'per_page' => 2, 'images' => sbgal_images(5)]));
    assert_true(!str_contains($slider, 'is-more'), 'a slider scrolls instead');
});

unit('gallery: filters list each category once, hover and captions add a class, the lightbox can be turned off', function (): void {
    $images = [...sbgal_images(2, 'Desserts'), ...sbgal_images(1, 'Seafood & <Fish>'), ...sbgal_images(1, 'Desserts')];
    $html = sbgal_render(sbgal_block('core.gallery', ['show_filters' => true, 'all_label' => 'Everything', 'images' => $images]));
    assert_eq(3, substr_count($html, 'sb-gallery__filter"'));
    assert_true(str_contains($html, '>Everything</button>') && str_contains($html, 'data-sb-cat="Seafood &amp; &lt;Fish&gt;"'), $html);
    $one = sbgal_render(sbgal_block('core.gallery', ['show_filters' => true, 'images' => sbgal_images(2, 'Desserts')]));
    assert_true(!str_contains($one, 'sb-gallery__filters'), 'one category filters nothing');
    $html = sbgal_render(sbgal_block('core.gallery', ['hover' => 'zoom', 'captions' => 'hover', 'link_to' => 'none', 'images' => sbgal_images(2)]));
    assert_true(str_contains($html, 'sb-gallery--hover-zoom') && str_contains($html, 'sb-gallery--cap-hover') && str_contains($html, 'data-sb-no-lightbox'), $html);
});

unit('gallery: the schema takes the new settings and refuses what it does not know', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $valid = static fn (array $b): bool => DocumentValidator::validate(sbgal_doc($b), $registry)->isValid();
    assert_true($valid(sbgal_block('core.gallery', ['layout' => 'mosaic', 'aspect_ratio' => '4:5', 'pagination' => 'infinite', 'per_page' => 16, 'images' => sbgal_images(120, 'Food')])), 'all new props');
    assert_true(!$valid(sbgal_block('core.gallery', ['layout' => 'collage'])), 'unknown layout');
    assert_true(!$valid(sbgal_block('core.gallery', ['per_page' => 1])), 'per page below the minimum');
});

unit('gallery: order, lazy load, spacing per device, link and overlay text', function (): void {
    $html = sbgal_render(sbgal_block('core.gallery', ['order' => 'random', 'lazy_load' => false, 'images' => sbgal_images(3)]));
    assert_true(str_contains($html, 'data-sb-order="random"') && !str_contains($html, 'loading="lazy"'), $html);
    $html = sbgal_render(sbgal_block('core.gallery', ['spacing' => 14, 'images' => sbgal_images(2)], ['mobile' => ['props' => ['spacing' => 6]]]));
    assert_true(str_contains($html, 'sb-gallery--spaced') && str_contains($html, '--sb-gal-sd:14px;--sb-gal-st:14px;--sb-gal-sm:6px'), $html);
    $none = sbgal_render(sbgal_block('core.gallery', ['spacing' => 0, 'gap' => 'lg', 'images' => sbgal_images(2)]));
    assert_true(!str_contains($none, 'sb-gallery--spaced') && str_contains($none, 'sb-gallery--gap-lg'), 'no spacing leaves the Gap choice alone');
    $html = sbgal_render(sbgal_block('core.gallery', ['link_to' => 'file', 'images' => sbgal_images(2)]));
    assert_true(str_contains($html, '<a class="sb-gallery__link" href="/uploads/1.jpg" target="_blank"') && str_contains($html, 'data-sb-no-lightbox'), $html);
    $html = sbgal_render(sbgal_block('core.gallery', ['captions' => 'hover', 'overlay_text' => 'alt', 'images' => sbgal_images(1)]));
    assert_true(str_contains($html, '<figcaption>Image 1</figcaption>'), $html);
    $html = sbgal_render(sbgal_block('core.gallery', ['columns' => '5', 'images' => sbgal_images(1)]));
    assert_true(str_contains($html, 'sb-gallery--cols-5'), $html);
});

unit('gallery: the runtime and the stylesheet carry the layouts', function (): void {
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/studio-builder/assets/public/studio-runtime.js');
    assert_true(str_contains($js, "['galleries', initGalleries]") && str_contains($js, 'placeMasonry') && str_contains($js, 'IntersectionObserver') && str_contains($js, "data-sb-order"), 'runtime');
    assert_true(str_contains($js, ':not([data-sb-no-lightbox])'), 'the lightbox respects the setting');
    $css = StudioStylesheet::css();
    foreach (['.sb-gallery--justified', '.sb-gallery--masonry.is-placed', '.sb-gallery--mosaic', '.sb-gallery--slider', '.sb-gallery__more-btn', '.sb-gallery--spaced', '.sb-gallery--cols-5'] as $rule) {
        assert_true(str_contains($css, $rule), $rule);
    }
});
