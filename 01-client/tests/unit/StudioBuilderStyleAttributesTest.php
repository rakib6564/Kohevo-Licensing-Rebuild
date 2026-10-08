<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — B2-P3d block attributes.
 *
 * Autoloader only, no database.
 *
 * Reuses `SBP5S_TENANT` from StudioBuilderPhase5StylesTest (loaded first, alphabetically).
 *
 * The Advanced tab edits `id`, `role`, `aria-*` and `data-*` with the editor's copy of the server's rule
 * (`ui/src/core/blockAttributes.mjs`); both read `ui/tests/fixtures/block-attributes.json`. A page never carries
 * two elements with one id.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
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
use Slate\Tenancy\TenantContext;

unit('block attributes: the server agrees with every case in the shared UI fixture', function (): void {
    $file = dirname(__DIR__, 2) . '/plugins/studio-builder/ui/tests/fixtures/block-attributes.json';
    $cases = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)['cases'];
    assert_true(count($cases) > 25, 'the fixture has cases');
    foreach ($cases as $case) {
        $issue = CanonicalDocumentSchema::blockAttributeIssue($case['name'], $case['value']);
        assert_eq($case['ok'], $issue === null, $case['name'] . ' = ' . json_encode($case['value']) . ($issue ? " -> {$issue}" : ''));
    }
});

/** Render several blocks through one collector (one page) and return the HTML of each. */
function sbat_render_page(array $attributeSets): array
{
    $renderer = new DocumentRenderer(
        BlockRegistry::withAllCoreBlocks(),
        BlockRendererRegistry::withCoreRenderers(),
        new CoreMediaResolver(new TenantContext()),
        new ProviderBindingResolver(new DataProviderRegistry(), new TenantContext())
    );
    $theme = (new ThemeResolver())->resolve('default');
    $ctx = RenderContext::forPublic(SBP5S_TENANT, new SiteContext('https://example.test', 'Test Site'));
    $collector = new RenderCollector();
    $out = [];
    foreach ($attributeSets as $attributes) {
        $out[] = $renderer->renderBlock([
            'id' => CanonicalDocumentSchema::newBlockId(), 'type' => 'core.heading', 'version' => 1,
            'props' => ['text' => 'T', 'level' => 'h2'], 'style' => CanonicalDocumentSchema::defaultBlockStyle(),
            'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [],
            'animation' => [], 'interactions' => [], 'attributes' => $attributes,
        ], $ctx, $theme, $collector, false);
    }
    return $out;
}

unit('block attributes: two blocks with one id render it once, on the first', function (): void {
    [$a, $b, $c] = sbat_render_page([['id' => 'pricing', 'data-x' => '1'], ['id' => 'pricing', 'data-y' => '2'], ['id' => 'other']]);
    assert_true(str_contains($a, ' id="pricing"'), 'the first keeps the id');
    assert_true(!str_contains($b, ' id="pricing"'), 'the second drops it');
    assert_true(str_contains($b, ' data-y="2"'), 'but keeps its other attributes');
    assert_true(str_contains($c, ' id="other"'), 'a different id is untouched');
});

unit('block attributes: handlers, style and reserved names never reach the tag', function (): void {
    [$html] = sbat_render_page([['id' => 'a', 'onclick' => 'x()', 'style' => 'color:red', 'data-sb-node' => 'evil', 'aria-label' => 'Ok "quoted"']]);
    assert_true(!str_contains($html, 'onclick') && !str_contains($html, 'color:red') && !str_contains($html, 'evil'), 'refused names are dropped');
    assert_true(str_contains($html, 'aria-label="Ok &quot;quoted&quot;"'), 'values are escaped');
});
