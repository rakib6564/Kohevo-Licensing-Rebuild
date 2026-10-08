<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — block `attributes` allow-list.
 *
 * Autoloader only, no database. Block attributes are emitted into the wrapper
 * tag, so they are a closed list (aria-*, data-*, id, role, tabindex, title,
 * lang). Pins: the validator refuses event handlers and other dangerous names
 * (so neither the builder nor AI/MCP can store them), and the renderer drops
 * them anyway for documents stored before the rule existed.
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
}

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
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
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Tenancy\TenantContext;

/** @param array<string, mixed> $attributes */
function sbat_block(array $attributes): array
{
    return [
        'id' => CanonicalDocumentSchema::newBlockId(), 'type' => 'core.heading', 'version' => 1,
        'props' => ['text' => 'Hello', 'level' => 'h2'], 'style' => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [],
        'attributes' => $attributes,
    ];
}

/** @param array<string, mixed> $attributes */
function sbat_doc(array $attributes): array
{
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default');
    $doc = DocumentOperationApplier::applyOne(
        $doc,
        new DocumentOperation('insert_section', ['index' => 0, 'section' => ['label' => 'Main']]),
        BlockRegistry::withAllCoreBlocks()
    );
    $doc['sections'][0]['blocks'][] = sbat_block($attributes);
    return $doc;
}

/** @param array<string, mixed> $attributes */
function sbat_codes(array $attributes): array
{
    try {
        DocumentNormalizer::validateAndNormalize(sbat_doc($attributes), BlockRegistry::withAllCoreBlocks());
    } catch (StudioValidationException $e) {
        return array_column($e->errors(), 'code');
    }
    return [];
}

/** @param array<string, mixed> $attributes */
function sbat_render(array $attributes): string
{
    $tenants  = new TenantContext();
    $renderer = new DocumentRenderer(
        BlockRegistry::withAllCoreBlocks(),
        BlockRendererRegistry::withCoreRenderers(),
        new CoreMediaResolver($tenants),
        new ProviderBindingResolver(new DataProviderRegistry(), $tenants)
    );
    $context = RenderContext::forPublic(101, new SiteContext('https://example.test', 'Test'));
    return $renderer->renderBlock(sbat_block($attributes), $context, new ResolvedTheme('default', []), new RenderCollector(), false);
}

unit('block attributes: aria-*, data-*, id, role, tabindex, title and lang validate', function (): void {
    $ok = [
        'aria-label' => 'Main title', 'aria-level' => 2, 'data-tracking' => 'hero', 'id' => 'hero',
        'role' => 'banner', 'tabindex' => 0, 'title' => 'Hi', 'lang' => 'fr',
    ];
    assert_eq([], sbat_codes($ok));
    assert_eq([], sbat_codes([]), 'an empty attribute map is fine');
});

unit('block attributes: event handlers, style, srcdoc, href and src are refused', function (): void {
    foreach (['onclick', 'onerror', 'onmouseover', 'ONCLICK', 'style', 'srcdoc', 'href', 'src', 'formaction', 'action', 'class'] as $name) {
        assert_true(in_array('invalid_attribute', sbat_codes([$name => 'alert(1)']), true), "$name must be refused");
    }
});

unit('block attributes: malformed names, reserved prefixes, bare prefixes and bad values are refused', function (): void {
    foreach (['data-sb-node', 'data-', 'aria-', 'data x', 'data-a"b', '1abc', '', 'data_x'] as $name) {
        assert_true(in_array('invalid_attribute', sbat_codes([$name => 'x']), true), "'$name' must be refused");
    }
    assert_true(in_array('invalid_attribute', sbat_codes(['data-a' => ['x']]), true), 'array values refused');
    assert_true(in_array('invalid_attribute', sbat_codes(['data-a' => true]), true), 'boolean values refused');
    assert_true(in_array('invalid_attribute', sbat_codes(['data-a' => null]), true), 'null values refused');
    assert_true(in_array('invalid_attribute', sbat_codes(['data-a' => str_repeat('x', 2001)]), true), 'over-long values refused');

    $many = [];
    for ($i = 0; $i < 33; $i++) {
        $many["data-k$i"] = 'v';
    }
    assert_true(in_array('too_many_attributes', sbat_codes($many), true), 'more than 32 attributes refused');
});

unit('block attributes: update_block_attributes cannot store a handler (builder and AI/MCP share the applier)', function (): void {
    $reg = BlockRegistry::withAllCoreBlocks();
    $doc = sbat_doc([]);
    $id  = $doc['sections'][0]['blocks'][0]['id'];
    $doc = DocumentOperationApplier::applyOne($doc, DocumentOperation::updateBlockAttributes($id, ['data-ok' => '1']), $reg);
    DocumentNormalizer::validateAndNormalize($doc, $reg);

    $bad = DocumentOperationApplier::applyOne($doc, DocumentOperation::updateBlockAttributes($id, ['onclick' => 'alert(1)']), $reg);
    assert_throws(StudioValidationException::class, static function () use ($bad, $reg): void {
        DocumentNormalizer::validateAndNormalize($bad, $reg);
    }, 'a document carrying onclick must not validate');
});

unit('block attributes: the renderer emits allowed attributes and drops anything else', function (): void {
    $html = sbat_render([
        'data-tracking' => 'hero', 'aria-label' => 'Main title', 'role' => 'banner',
        'onclick' => 'alert(1)', 'onmouseover' => 'alert(2)', 'style' => 'position:fixed', 'srcdoc' => '<script>',
        'data-sb-node' => 'spoof', 'data-x"onfocus="alert(3)' => 'x', 'href' => 'javascript:alert(4)',
    ]);
    assert_true(str_contains($html, 'data-tracking="hero"'), 'allowed data-* kept');
    assert_true(str_contains($html, 'aria-label="Main title"'), 'allowed aria-* kept');
    assert_true(str_contains($html, 'role="banner"'), 'role kept');
    foreach (['onclick', 'onmouseover', 'onfocus', 'srcdoc', 'javascript:', 'position:fixed', 'spoof'] as $needle) {
        assert_true(!str_contains($html, $needle), "$needle must not reach the page");
    }
});

unit('block attributes: attribute values are HTML-escaped on output', function (): void {
    $html = sbat_render(['data-note' => '"><script>alert(1)</script>']);
    assert_true(!str_contains($html, '<script>'), 'no raw script tag');
    assert_true(str_contains($html, 'data-note="'), 'attribute still emitted, escaped');
});
