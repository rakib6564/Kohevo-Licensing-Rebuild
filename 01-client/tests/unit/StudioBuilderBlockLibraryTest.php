<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Block Library (tabs, accordion, carousel, stats).
 *
 * Dependency-free (no database): drives the real pipeline (registry → normalizer
 * → validator → renderer → page assembly) for the four interactive blocks added
 * in this suite — core.tabs, core.accordion, core.carousel, core.stats.
 *
 * Verifies: the blocks are registered and exposed to the palette manifest, that
 * empty props normalise into the starter content (so a freshly inserted block is
 * visible rather than blank), that each renderer emits exactly the data-sb-*
 * hooks studio-runtime.js binds to, that malformed items are rejected, and that
 * output is deterministic.
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
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeResolver;
use Slate\Module\StudioBuilder\Render\Compile\DynamicSlotResolver;
use Slate\Module\StudioBuilder\Render\Compile\StudioCompiler;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\PageDocumentAssembler;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Tenancy\TenantContext;

/** The four interactive block types introduced by the block library. */
const SBBL_TYPES = ['core.tabs', 'core.accordion', 'core.carousel', 'core.stats'];
const SBBL_TENANT = 103;

/** A media resolver that resolves nothing — these blocks need no assets. */
final class _SbblNoMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia
    {
        return null;
    }
}

/** @return array<string, mixed> */
function sbbl_pipeline(): array
{
    $tenants   = new TenantContext();
    $registry  = ModuleBlockDefinitions::studioRegistry();
    $renderers = BlockRendererRegistry::withStudioRenderers();
    $providers = new DataProviderRegistry();
    $media     = new _SbblNoMedia();

    $documents = new DocumentRenderer($registry, $renderers, $media, new ProviderBindingResolver($providers, $tenants));
    $compiler  = new StudioCompiler($tenants, $registry, $renderers, $documents, new ThemeResolver(null, static fn(): array => ['accent' => '#ff5500', 'heading' => 'Inter']), new ChromeResolver(), $media);
    $assembler = new PageDocumentAssembler(static fn(): string => 'Powered by Kohevo');

    return [
        'registry' => $registry, 'documents' => $documents, 'compiler' => $compiler,
        'slots' => new DynamicSlotResolver($documents), 'assembler' => $assembler, 'tenants' => $tenants,
    ];
}

/** @return array<string, mixed> */
function sbbl_block(string $type, array $props = [], array $extra = []): array
{
    return array_merge([
        'bindings'   => [],
        'children'   => [],
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'props'      => $props,
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'type'       => $type,
        'version'    => 1,
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ], $extra);
}

/** @return array<string, mixed> */
function sbbl_doc(array $blocks): array
{
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Interactive');
    $doc['sections'] = [[
        'blocks'     => $blocks,
        'global_ref' => null,
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => 'Main',
        'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ]];
    return $doc;
}

function sbbl_page(): PageAddress
{
    return new PageAddress(6, '11111111-2222-3333-4444-555555555556', 'Interactive', 'interactive', 'page', 'published', 'standalone', 9, 9);
}

function sbbl_site(): SiteContext
{
    return new SiteContext('https://acme.test', 'Acme Studio', 'https://acme.test/uploads/branding/logo.png', 'https://acme.test/uploads/branding/fav.png', 'en');
}

function sbbl_public(): RenderContext
{
    return RenderContext::forPublic(SBBL_TENANT, sbbl_site(), static fn(string $m): bool => in_array($m, ['booking', 'membership', 'forms'], true));
}

/** Full pipeline (compile → fill → assemble) as tenant SBBL_TENANT. */
function sbbl_render(array $p, array $doc, RenderContext $ctx): string
{
    return $p['tenants']->runAs(SBBL_TENANT, static function () use ($p, $doc, $ctx): string {
        $compiled = $p['compiler']->compile(sbbl_page(), ['id' => 9, 'page_id' => 6, 'document_json' => CanonicalJson::encode($doc)], $ctx);
        $filled   = $p['slots']->fill($compiled, $ctx);
        return $p['assembler']->assemble($compiled, $filled, $ctx);
    });
}


// ── Registry + palette manifest ──────────────────────────────────────────────

unit('block library: all four types are registered and exposed to the palette', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();

    foreach (SBBL_TYPES as $type) {
        assert_true($registry->has($type), "{$type} must be registered");
    }

    $manifest = $registry->editorManifests();
    $types = array_map(static fn(array $b): string => (string) ($b['type'] ?? ''), $manifest);
    foreach (SBBL_TYPES as $type) {
        assert_true(in_array($type, $types, true), "{$type} must appear in the editor manifest");
    }

    foreach ($manifest as $block) {
        if (!in_array($block['type'] ?? '', SBBL_TYPES, true)) {
            continue;
        }
        // default_props is what the palette merges on insert — it must carry the
        // starter content, otherwise a freshly inserted block renders blank.
        assert_true(isset($block['default_props']), "{$block['type']} must expose default_props");
        assert_true(is_array($block['default_props']), "{$block['type']} default_props must be an array");
        assert_true(isset($block['field_schema']) && $block['field_schema'] !== [], "{$block['type']} must expose its field schema");
    }
});

// ── Normalisation: empty props become starter content ────────────────────────

unit('block library: empty props normalise into visible starter content', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();

    $doc = sbbl_doc(array_map(
        static fn(string $type): array => sbbl_block($type, []),
        SBBL_TYPES
    ));
    $normalised = DocumentNormalizer::normalize($doc, $registry);

    $byType = [];
    foreach ($normalised['sections'][0]['blocks'] as $block) {
        $byType[$block['type']] = $block;
    }

    foreach (SBBL_TYPES as $type) {
        assert_true(isset($byType[$type]), "{$type} survives normalisation");
        assert_true($byType[$type]['props'] !== [], "{$type} must not normalise to empty props");
    }

    assert_true(count($byType['core.tabs']['props']['items']) >= 2, 'tabs get starter tabs');
    assert_true(count($byType['core.accordion']['props']['items']) >= 2, 'accordion gets starter rows');
    assert_true(count($byType['core.carousel']['props']['slides']) >= 2, 'carousel gets starter slides');
    assert_true(count($byType['core.stats']['props']['items']) >= 2, 'stats get starter figures');
    assert_eq('top', $byType['core.tabs']['props']['options']['position'], 'tabs option default applied');
    assert_eq(1, $byType['core.carousel']['props']['options']['per_view'], 'carousel option default applied');
});

// ── Rendering: the hooks studio-runtime.js binds to ──────────────────────────

unit('block library: render the exact data-sb-* hooks the runtime binds to', function (): void {
    $p = sbbl_pipeline();
    $doc = sbbl_doc(array_map(
        static fn(string $type): array => sbbl_block($type, []),
        SBBL_TYPES
    ));

    $html = sbbl_render($p, $doc, sbbl_public());

    // Tabs — initTabs() binds on [data-sb-tabs]; tab→panel pairing by data-sb-tab.
    assert_true(str_contains($html, 'data-sb-tabs'), 'tabs root present');
    assert_true(str_contains($html, 'data-sb-tab="t1"'), 'first tab key is t1');
    assert_true(str_contains($html, 'data-sb-tab="t2"'), 'second tab key is t2');
    assert_true(str_contains($html, 'role="tab"'), 'tabs carry role=tab');
    assert_true(str_contains($html, 'aria-controls="'), 'panels referenced by aria-controls');
    assert_true(str_contains($html, 'aria-selected="true"'), 'first tab selected in static HTML');

    // Accordion — initAccordion() binds on [data-sb-accordion]; it walks
    // [data-sb-accordion-item] children and finds each panel via
    // [data-sb-accordion-panel]. Missing either attribute means init skips the
    // item entirely and no row ever toggles, so assert all three.
    assert_true(str_contains($html, 'data-sb-accordion'), 'accordion root present');
    assert_true(str_contains($html, 'data-sb-accordion-item'), 'accordion items are addressable');
    assert_true(str_contains($html, 'data-sb-accordion-panel'), 'accordion panels are addressable');
    assert_true(str_contains($html, 'data-sb-accordion-button'), 'accordion buttons present');
    assert_true(str_contains($html, 'aria-expanded="true"'), 'first row renders open');
    assert_true(str_contains($html, 'aria-expanded="false"'), 'later rows render closed');

    // Closed rows must ship closed, or `aria-expanded="false"` is a lie to
    // anyone without script. The open first row must not carry `hidden`.
    preg_match_all('/<div[^>]*data-sb-accordion-panel[^>]*>/u', $html, $pm);
    assert_eq(3, count($pm[0]), 'every row emits a panel');
    $withHidden = 0;
    foreach ($pm[0] as $tag) {
        if (str_contains($tag, ' hidden')) {
            $withHidden++;
        }
    }
    assert_eq(2, $withHidden, 'the two closed panels ship with the hidden attribute');

    // Carousel — initCarousel() binds on [data-sb-carousel]; slides via
    // [data-sb-slide]; dots via numeric data-sb-car-dot.
    assert_true(str_contains($html, 'data-sb-carousel'), 'carousel root present');
    assert_true(str_contains($html, 'data-sb-slide'), 'carousel slides present');
    assert_true(str_contains($html, 'data-sb-carousel-track'), 'carousel track present');
    // initCarousel only rebuilds/updates dots when it can find the host; without
    // data-sb-car-dots the dots array stays empty and the active dot never moves.
    assert_true(str_contains($html, 'data-sb-car-dots'), 'carousel exposes the dots host');
    assert_true(str_contains($html, 'data-sb-car-dot="0"'), 'first dot is index 0');
    assert_true(str_contains($html, 'aria-current="true"'), 'active dot marked');

    // Stats — initCounters() binds on [data-sb-counter] and counts to
    // data-sb-to; pre-rendered text must equal the final value.
    assert_true(str_contains($html, 'data-sb-counter'), 'stat counters present');
    assert_true(str_contains($html, 'data-sb-to="1840"'), 'counter target exposed');
    assert_true(str_contains($html, '>1,840<'), 'pre-rendered value matches the final count');
    assert_true(str_contains($html, 'data-sb-suffix="%"'), 'suffix exposed to the runtime');
    assert_true(str_contains($html, 'data-sb-prefix="+"'), 'prefix exposed to the runtime');

    // Public output carries no editor node metadata (Phase 4 contract).
    assert_true(!str_contains($html, 'data-sb-node'), 'public output carries no editor node metadata');
});

// ── ARIA integrity + no-JS fallback ─────────────────────────────────────────

unit('block library: aria-controls/labelledby pairs resolve and output is deterministic', function (): void {
    $p = sbbl_pipeline();
    $doc = sbbl_doc(array_map(
        static fn(string $type): array => sbbl_block($type, []),
        SBBL_TYPES
    ));

    $a = sbbl_render($p, $doc, sbbl_public());
    $b = sbbl_render($p, $doc, sbbl_public());

    assert_eq($a, $b, 'static compilation must be deterministic for identical input');

    // Every aria-controls / aria-labelledby target must exist as an id in the
    // document — a dangling reference is an accessibility defect the runtime
    // would inherit.
    preg_match_all('/\saria-(?:controls|labelledby)="([^"]+)"/', $a, $m);
    $ids = [];
    preg_match_all('/\sid="([^"]+)"/', $a, $m2);
    foreach ($m2[1] as $id) {
        $ids[$id] = true;
    }
    assert_true(count($m[1]) > 0, 'the interactive blocks emit aria references');
    $dangling = [];
    foreach ($m[1] as $ref) {
        foreach (preg_split('/\s+/', $ref) ?: [] as $one) {
            if ($one !== '' && !isset($ids[$one])) {
                $dangling[] = $one;
            }
        }
    }
    assert_eq([], $dangling, 'every aria-controls/labelledby target must exist as an id');
});

// ── Styling: every block must actually be styled ────────────────────────────

unit('block library: the public stylesheet styles every block it renders', function (): void {
    // The stylesheet memoises itself; force a fresh compile so this assertion
    // sees the current source rather than a cached string.
    \Slate\Module\StudioBuilder\Render\StudioStylesheet::resetCache();
    $css = \Slate\Module\StudioBuilder\Render\StudioStylesheet::css();

    // Tabs / accordion / carousel / stats each emit a distinct surface class.
    foreach (['.sb-tabs{', '.sb-tabs__tab', '.sb-accordion{', '.sb-accordion__button', '.sb-carousel{', '.sb-carousel__slide'] as $needle) {
        assert_true(str_contains($css, $needle), "stylesheet must define {$needle}");
    }

    // Stats had no rules at all before this guard: `.sb-cols-N` only sets
    // grid-template-columns, so without `.sb-stats{display:grid}` the figures
    // would stack as plain blocks.
    assert_true(str_contains($css, '.sb-stats{display:grid'), 'stats container must be a grid');
    assert_true(str_contains($css, '.sb-stat__value'), 'stat value must be styled');
    assert_true(str_contains($css, '.sb-stat__label'), 'stat label must be styled');

    // The count-up rewrites text every frame; tabular figures stop the cell
    // from jittering as digit widths change.
    assert_true(str_contains($css, 'font-variant-numeric:tabular-nums'), 'stat values use tabular figures');

    // Reduced-motion contract must still hold after adding these rules.
    assert_true(str_contains($css, 'prefers-reduced-motion'), 'reduced motion is honoured');
});


// ── Validation: malformed props must fail closed ─────────────────────────────

unit('block library: malformed items are rejected by the validator', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();

    $stats = $registry->get('core.stats');
    assert_true($stats !== null, 'core.stats resolves');

    // A stat row with no value cannot be rendered as a counter (the renderer
    // skips it), and it must not pass validation either.
    $bad = $stats->validateProps([
        'items' => [['label' => 'No value here']],
    ]);
    assert_false($bad->isValid(), 'a stat row without a value must fail validation');
    assert_true($bad->errors() !== [], 'the failure is reported with errors');

    // A stat row with no label is equally unrenderable.
    $bad2 = $stats->validateProps([
        'items' => [['value' => 5]],
    ]);
    assert_false($bad2->isValid(), 'a stat row without a label must fail validation');

    // Decimals are capped at 3 by the schema.
    $bad3 = $stats->validateProps([
        'items' => [['value' => 5, 'label' => 'Five', 'decimals' => 9]],
    ]);
    assert_false($bad3->isValid(), 'decimals above the schema maximum must fail validation');

    // The happy path still passes.
    $ok = $stats->validateProps([
        'items' => [['value' => 5, 'label' => 'Five']],
    ]);
    assert_true($ok->isValid(), 'a well-formed stats block validates');

    // Unknown props fail closed, as everywhere else in the schema.
    $tabs = $registry->get('core.tabs');
    assert_true($tabs !== null, 'core.tabs resolves');
    $unknown = $tabs->validateProps(['items' => [['label' => 'A', 'content' => 'B']], 'bogus' => 1]);
    assert_false($unknown->isValid(), 'unknown props must fail closed');
});

