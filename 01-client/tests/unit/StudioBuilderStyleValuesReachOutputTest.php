<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — accepted style values reach the page (W-F23).
 *
 * Autoloader only, no database.
 *
 * A style value the validator accepts must change what the renderer writes; one that never could is refused at save
 * time instead of being stored and silently ignored. Three were dead: `style.spacing` (no emitter: margin and padding
 * are the spacing controls), `typography.transform: "none"` (written as `sb-none`, which no rule defines) and a
 * `style` under any responsive key but tablet and mobile (desktop is the block's own style; only those two have a
 * media query).
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
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

/** One heading with the given style and responsive overrides. */
function sbsv_block(array $style, ?array $responsive = null): array
{
    $block = [
        'id' => 'blk_aaaaaaaaaaaaaaaaaaaaaaaa', 'type' => 'core.heading', 'version' => 1,
        'props' => ['text' => 'Styled', 'level' => 'h2'],
        'style' => $style + CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [], 'children' => [],
    ];
    if ($responsive !== null) {
        $block['responsive'] = $responsive;
    }
    return $block;
}

/** The block's wrapper HTML plus the page CSS it adds, through the real renderer. */
function sbsv_render(array $block): string
{
    $tenants   = new TenantContext();
    $renderer  = new DocumentRenderer(BlockRegistry::withAllCoreBlocks(), BlockRendererRegistry::withCoreRenderers(), new CoreMediaResolver($tenants), new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
    $collector = new RenderCollector();
    $html = $renderer->renderBlock($block + ['animation' => [], 'interactions' => []], RenderContext::forPublic(101, new SiteContext('https://example.test', 'T')), (new ThemeResolver())->resolve('default'), $collector, false);
    return $html . "\n" . $collector->css();
}

function sbsv_validate(array $block): \Slate\Module\StudioBuilder\Schema\ValidationResult
{
    return DocumentValidator::validate([
        'schema_version' => '1.0', 'document_type' => 'page', 'template_key' => 'default', 'settings' => [], 'seo' => [],
        'sections' => [[
            'id' => CanonicalDocumentSchema::newSectionId(), 'label' => 'S', 'global_ref' => null, 'layout' => [],
            'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'blocks' => [$block],
        ]],
    ], BlockRegistry::withAllCoreBlocks());
}

/** A valid value for every style key, as an author (or the API) would store it. */
function sbsv_samples(): array
{
    return [
        'align'         => 'center',
        'surface_token' => 'surface.accent',
        'text_token'    => 'text.primary',
        'spacing_token' => 'space.md',
        'radius_token'  => 'radius.md',
        'shadow_token'  => 'shadow.md',
        'font_token'    => 'font.heading',
        'border_token'  => 'border.default',
        'typography'    => ['size' => '1.5rem'],
        'color'         => '#e8734a',
        'background'    => ['color' => '#0b0c0f'],
        'border'        => ['style' => 'solid', 'width' => '2px'],
        'shadow'        => 'md',
        'text_shadow'   => ['x' => '1px', 'y' => '2px', 'blur' => '4px', 'color' => 'rgba(0,0,0,0.4)'],
        'overlay'       => ['color' => 'rgba(0,0,0,0.5)', 'opacity' => 0.8, 'blend' => 'multiply'],
        'dimensions'    => ['max_width' => '40rem'],
        'opacity'       => 0.5,
        'z_index'       => 3,
        'layout'        => ['display' => 'flex', 'gap' => '1rem'],
        'position'      => ['mode' => 'relative', 'top' => '1rem'],
        'effects'       => ['cursor' => 'pointer'],
        'margin'        => ['top' => '1rem'],
        'padding'       => ['top' => '1rem'],
    ];
}

unit('style values: every style key the validator accepts changes the rendered output', function (): void {
    $samples = sbsv_samples();
    $plain   = sbsv_render(sbsv_block([]));
    foreach (CanonicalDocumentSchema::ALLOWED_STYLE_KEYS as $key) {
        assert_true(array_key_exists($key, $samples), "style.{$key} is accepted: give it a sample here, and an emitter, or stop accepting it");
        $block  = sbsv_block([$key => $samples[$key]]);
        $result = sbsv_validate($block);
        assert_true($result->isValid(), "the style.{$key} sample validates: " . json_encode($result->errors()));
        assert_true(sbsv_render($block) !== $plain, "style.{$key} is accepted but renders nothing");
    }
});

unit('style values: style.spacing is refused (margin and padding are the spacing controls)', function (): void {
    $result = sbsv_validate(sbsv_block(['spacing' => ['top' => '1rem']]));
    assert_false($result->isValid(), 'style.spacing has no emitter, so it must not be stored');
});

unit('style values: every text transform renders a class the stylesheet defines', function (): void {
    $css = StudioStylesheet::css();
    foreach (CanonicalDocumentSchema::ALLOWED_TEXT_TRANSFORMS as $transform) {
        $block = sbsv_block(['typography' => ['transform' => $transform]]);
        assert_true(sbsv_validate($block)->isValid(), "transform {$transform} validates");
        preg_match('/<h2 class="([^"]*)"|<div class="([^"]*)"/', sbsv_render($block), $m);
        $classes = explode(' ', ($m[1] ?? '') . ' ' . ($m[2] ?? ''));
        $defined = array_filter($classes, static fn (string $c): bool => $c !== '' && str_contains($css, '.' . $c . '{text-transform:' . $transform . '}'));
        assert_true($defined !== [], "transform {$transform} must put on a class whose rule is text-transform:{$transform}: " . implode(' ', $classes));
    }
});

unit('style values: a responsive style is refused unless it has a media query to land in', function (): void {
    $override = ['typography' => ['size' => '1rem']];
    foreach (CanonicalDocumentSchema::ALLOWED_RESPONSIVE_BREAKPOINTS as $bp) {
        $block  = sbsv_block([], [$bp => ['style' => $override]]);
        $valid  = sbsv_validate($block)->isValid();
        $output = sbsv_render($block);
        $drawn  = str_contains($output, '@media') && str_contains($output, 'font-size:1rem');
        assert_true($valid === $drawn, "responsive.{$bp}.style is " . ($valid ? 'accepted' : 'refused') . ' but ' . ($drawn ? 'renders' : 'renders nothing'));
    }
    assert_true(sbsv_validate(sbsv_block([], ['tablet' => ['style' => $override]]))->isValid(), 'tablet keeps its override');
    assert_false(sbsv_validate(sbsv_block([], ['desktop' => ['style' => $override]]))->isValid(), 'desktop is the block style itself');
    assert_true(sbsv_validate(sbsv_block([], ['desktop' => ['hide' => true]]))->isValid(), 'a desktop hide flag is still accepted');
});
