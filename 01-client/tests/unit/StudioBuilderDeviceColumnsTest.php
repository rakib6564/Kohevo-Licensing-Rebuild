<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — counts that differ per device (DeviceProps, DeviceColumns).
 *
 * Autoloader only, no database.
 *
 * A field marked `per_device` may hold its own value on tablet and mobile (`responsive.<device>.props.<path>`); the
 * renderer writes the three counts as custom properties on the element that uses them, and a device without a value takes
 * the renderer's default for it (fewer columns on a phone).
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

function sbdc_block(string $type, array $props, array $responsive = [], array $children = []): array
{
    $b = [
        'id' => CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => 1, 'props' => $props,
        'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [], 'children' => $children,
    ];
    return $responsive !== [] ? $b + ['responsive' => $responsive] : $b;
}

function sbdc_doc(array $block): array
{
    return ['schema_version' => '2.0', 'document_type' => 'page', 'template_key' => 'default', 'settings' => [], 'seo' => [], 'containers' => [CanonicalDocumentSchema::newContainer(['metadata' => ['label' => 'S'], 'layout' => [] /*TODO*/, 'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'children' => [$block]])]];
}

function sbdc_render(array $block): string
{
    $renderer = new DocumentRenderer(
        BlockRegistry::withAllCoreBlocks(),
        BlockRendererRegistry::withCoreRenderers(),
        new CoreMediaResolver(new TenantContext()),
        new ProviderBindingResolver(new DataProviderRegistry(), new TenantContext())
    );
    return $renderer->renderSections(
        sbdc_doc($block)['containers'],
        RenderContext::forPublic(101, new SiteContext('https://example.test', 'Test Site')),
        (new ThemeResolver())->resolve('default'),
        new RenderCollector(),
        false
    );
}

unit('device columns: only a per-device field takes a tablet or mobile value, and it passes the field\'s own rules', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $valid = static fn (array $b): bool => DocumentValidator::validate(sbdc_doc($b), $registry)->isValid();
    assert_true($valid(sbdc_block('core.gallery', ['columns' => '6'], ['tablet' => ['props' => ['columns' => '3']], 'mobile' => ['props' => ['columns' => '2']]])), 'gallery columns per device');
    assert_true($valid(sbdc_block('core.carousel', ['slides' => []], ['mobile' => ['props' => ['options.per_view' => 1]]])), 'a nested field by its path');
    assert_true($valid(sbdc_block('layout.grid', ['columns' => 4], ['mobile' => ['props' => ['columns' => 2]], 'tablet' => ['style' => ['padding' => ['top' => '8px']]]])), 'next to a device style');
    foreach ([
        'a value the field refuses' => sbdc_block('core.gallery', ['columns' => '3'], ['mobile' => ['props' => ['columns' => '5']]]),
        'out of range'              => sbdc_block('layout.grid', ['columns' => 3], ['mobile' => ['props' => ['columns' => 13]]]),
        'not a whole number'        => sbdc_block('layout.grid', ['columns' => 3], ['mobile' => ['props' => ['columns' => 1.5]]]),
        'a field not per device'    => sbdc_block('core.gallery', ['columns' => '3'], ['mobile' => ['props' => ['gap' => 'lg']]]),
        'desktop props'             => sbdc_block('core.gallery', ['columns' => '3'], ['desktop' => ['props' => ['columns' => '2']]]),
        'props a list'              => sbdc_block('core.gallery', ['columns' => '3'], ['mobile' => ['props' => ['2']]]),
    ] as $why => $b) {
        assert_false($valid($b), "refused: {$why}");
    }
    assert_eq(['columns'], array_keys(DeviceProps::fields($registry->get('core.gallery')->schema())));
    assert_eq(['options.per_view'], array_keys(DeviceProps::fields($registry->get('core.carousel')->schema())));
});

unit('device columns: a gallery and a query loop drop columns on smaller screens unless the author chose per device', function (): void {
    $html = sbdc_render(sbdc_block('core.gallery', ['columns' => '6']));
    assert_true(str_contains($html, 'sb-gallery--cols-6 sb-gallery--gap-md sb-gallery--rounded sb-dcols" style="--sb-dc-d:6;--sb-dc-t:3;--sb-dc-m:2"'), $html);
    $html = sbdc_render(sbdc_block('core.gallery', ['columns' => '6'], ['tablet' => ['props' => ['columns' => '4']], 'mobile' => ['props' => ['columns' => '3']]]));
    assert_true(str_contains($html, 'style="--sb-dc-d:6;--sb-dc-t:4;--sb-dc-m:3"'), 'the author\'s counts: ' . $html);
    $html = sbdc_render(sbdc_block('core.gallery', ['columns' => '6'], ['tablet' => ['props' => ['columns' => '2']]]));
    assert_true(str_contains($html, 'style="--sb-dc-d:6;--sb-dc-t:2;--sb-dc-m:2"'), 'a phone never shows more than the tablet the author set: ' . $html);
});

unit('device columns: a grid, a feature list and text columns keep their markup until a device value is set', function (): void {
    $plain = sbdc_render(sbdc_block('layout.grid', ['columns' => 3]));
    assert_true(str_contains($plain, 'sb-cols-1 sb-md-cols-3') && !str_contains($plain, 'sb-dcols'), 'unchanged: ' . $plain);
    $grid = sbdc_render(sbdc_block('layout.grid', ['columns' => 3], ['mobile' => ['props' => ['columns' => 2]]]));
    assert_true((bool) preg_match('/<div class="[^"]*\bsb-dcols\b[^"]*"[^>]*style="[^"]*--sb-dc-d:3;--sb-dc-t:3;--sb-dc-m:2"/', $grid), 'the grid wrapper: ' . $grid);
    assert_true(!str_contains($grid, 'sb-md-cols-3'), 'one system at a time');

    $list = sbdc_render(sbdc_block('core.feature_list', ['columns' => 3, 'items' => [['heading' => 'A', 'body' => '']]], ['tablet' => ['props' => ['columns' => 2]]]));
    assert_true(str_contains($list, 'sb-feature-list__items sb-cols-1 sb-md-cols-3 sb-dcols" style="--sb-dc-d:3;--sb-dc-t:2;--sb-dc-m:1"'), $list);

    $text = sbdc_render(sbdc_block('core.rich_text', ['content' => '<p>x</p>', 'columns' => 1], ['tablet' => ['props' => ['columns' => 2]]]));
    assert_true(str_contains($text, 'sb-dtc" style="--sb-tc-d:1;--sb-tc-t:2;--sb-tc-m:1;column-gap:20px"'), 'columns on a tablet only: ' . $text);
});

unit('device columns: a carousel shows one slide on a phone, and the runtime reads the count for the device', function (): void {
    $slides = [['image' => null, 'quote' => 'A'], ['image' => null, 'quote' => 'B'], ['image' => null, 'quote' => 'C']];
    $html = sbdc_render(sbdc_block('core.carousel', ['slides' => $slides, 'options' => ['per_view' => 3]]));
    assert_true(str_contains($html, 'class="sb-carousel sb-dpv" data-sb-carousel data-sb-per-view="3" style="--sb-pv-d:3;--sb-pv-t:2;--sb-pv-m:1"'), substr($html, 0, 400));
    $one = sbdc_render(sbdc_block('core.carousel', ['slides' => $slides]));
    assert_true(!str_contains($one, 'sb-dpv'), 'one slide per view renders as before');

    StudioStylesheet::resetCache();
    $css = StudioStylesheet::css();
    assert_true(str_contains($css, '@media (max-width:767.98px){.sb-dcols{grid-template-columns:repeat(var(--sb-dc-m),minmax(0,1fr))!important}'), 'phone columns');
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/studio-builder/assets/public/studio-runtime.js');
    assert_true(str_contains($js, "getPropertyValue('--sb-pv')"), 'the runtime reads the per-device count');
});
