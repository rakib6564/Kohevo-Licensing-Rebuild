<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the last of the custom CSS a real rebuild (FightxLife) needed:
 * site-wide text settings (body and heading colour, body size and line height, heading line height, the space after
 * text and under headings, a section's side space), a text size that follows the block around it, the "all but the
 * first" part state (a line between items), a grid child spanning rows, and a public page that leaves no empty box
 * where a block had nothing to show.
 *
 * Autoloader only, no database. Reuses sbdc_block / sbdc_doc / sbdc_render from StudioBuilderDeviceColumnsTest.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Document\StyleSurface;
use Slate\Module\StudioBuilder\Registry\BlockParts;
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
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Tenancy\TenantContext;

function sbtt_valid(array $block): bool
{
    return DocumentValidator::validate(sbdc_doc($block), BlockRegistry::withAllCoreBlocks())->isValid();
}

function sbtt_render_editor(array $block): string
{
    $renderer = new DocumentRenderer(
        BlockRegistry::withAllCoreBlocks(),
        BlockRendererRegistry::withCoreRenderers(),
        new CoreMediaResolver(new TenantContext()),
        new ProviderBindingResolver(new DataProviderRegistry(), new TenantContext())
    );
    return $renderer->renderSections(
        sbdc_doc($block)['sections'],
        RenderContext::forEditor(101, new SiteContext('https://example.test', 'Test Site'), StudioActor::authenticated(1, StudioPermissions::ALL, true)),
        (new ThemeResolver())->resolve('default'),
        new RenderCollector(),
        false
    );
}

unit('text settings: new tokens default to the look every site already had, and body text follows Main text until set', function (): void {
    $tokens = (new ThemeResolver(null, static fn(): array => []))->resolve()->tokens();
    foreach (['type.body_size' => '1rem', 'type.body_leading' => '1.6', 'type.heading_leading' => '1.2', 'type.flow' => '1rem', 'type.heading_gap' => '0.5em', 'type.section_gutter' => '1rem', 'text.heading' => 'currentColor'] as $ref => $default) {
        assert_eq($default, $tokens[$ref] ?? null, "{$ref} default");
    }
    assert_eq($tokens['text.primary'], $tokens['text.body'], 'body text is Main text unless set');

    $sanitize = new \ReflectionMethod(ThemeResolver::class, 'sanitizeValue');
    foreach (['type.body_size' => '15px', 'type.body_leading' => '1.65', 'type.heading_leading' => '1.17', 'type.flow' => '0', 'type.heading_gap' => '0.5em', 'type.section_gutter' => '0px', 'text.heading' => '#0c0905'] as $ref => $ok) {
        assert_eq($ok, $sanitize->invoke(null, $ref, $ok), "accepted: {$ref} = {$ok}");
    }
    foreach (['type.body_size' => '1.6', 'type.body_leading' => '1.6;color:red', 'type.flow' => 'auto', 'type.heading_gap' => 'calc(1px)', 'type.unknown_thing' => 'x'] as $ref => $bad) {
        assert_eq(null, $sanitize->invoke(null, $ref, $bad), "refused: {$ref} = {$bad}");
    }
});

unit('text settings: the base stylesheet reads them, with today\'s values as fallbacks, and a heading colour any block colour beats', function (): void {
    StudioStylesheet::resetCache();
    $css = StudioStylesheet::css();
    foreach ([
        'color:var(--sb-text-body,var(--sb-text-primary));font-family:var(--sb-font-body);font-size:var(--sb-type-body_size,1rem);line-height:var(--sb-type-body_leading,1.6)',
        'line-height:var(--sb-type-heading_leading,1.2);margin:0 0 var(--sb-type-heading_gap,.5em)}',
        ':where(.sb-body) :where(h1,h2,h3,h4,h5,h6){color:var(--sb-text-heading,inherit)}',
        '.sb-text{margin:0 0 var(--sb-type-flow,1rem);line-height:var(--sb-type-body_leading,1.6)}',
        '.sb-text--base{font-size:var(--sb-type-body_size,1rem)}',
        '.sb-text--inherit{font-size:inherit;line-height:inherit}',
        '.sb-section__inner{margin:0 auto;padding-left:var(--sb-type-section_gutter,1rem);padding-right:var(--sb-type-section_gutter,1rem)',
        '.sb-icon{display:flex;margin:0 0 var(--sb-type-flow,1rem)}',
        '.sb-nav{margin:0 0 var(--sb-type-flow,1rem)}',
        '.sb-body a:not(.sb-button):not(.sb-heading__link){color:var(--sb-color-accent)}',
    ] as $rule) {
        assert_true(str_contains($css, $rule), "the stylesheet has {$rule}");
    }
    $text = sbdc_block('core.text', ['content' => 'Hi', 'size' => 'inherit']);
    assert_true(sbtt_valid($text), 'a text may take the size around it');
    assert_true(str_contains(sbdc_render($text), 'sb-text--inherit'));
});

unit('parts: "all but the first" draws a line between items, never before the first, and comes before hover', function (): void {
    assert_eq(['not_first' => ':not(:first-child)', 'hover' => ':hover'], BlockParts::STATES);
    $meta = sbdc_block('theme.post_meta', []);
    $parts = [
        'item'           => ['color' => '#999999'],
        'item:not_first' => ['border' => ['sides' => 'left', 'width' => '1px', 'style' => 'solid', 'color' => '#dddddd'], 'padding' => ['left' => '15px']],
        'item:hover'     => ['color' => '#e9008a'],
    ];
    assert_true(sbtt_valid($meta + ['style_parts' => $parts]));
    $rules = StyleSurface::partRules($parts, 'theme.post_meta');
    assert_eq(['.sb-post-meta > span:not(:first-child)'], $rules[1]['selectors']);
    assert_true(str_contains($rules[1]['declarations'], 'border-width:0 0 0 1px !important'), $rules[1]['declarations']);
    assert_eq(['.sb-post-meta > span:hover'], $rules[2]['selectors'], 'hover is written last, so it wins over the line state');
    $list = sbdc_block('core.list', ['style' => 'check']);
    assert_false(sbtt_valid($list + ['style_parts' => ['marker:not_first' => ['color' => '#e9008a']]]), 'a pseudo-element part takes no state');
    assert_false(sbtt_valid($meta + ['style_parts' => ['item:first' => ['color' => '#000000']]]), 'only the listed states');
});

unit('grid children span rows as they span columns, per device too', function (): void {
    assert_eq('grid-row:span 2', StyleSurface::declarations(['layout' => ['row_span' => 2]]));
    assert_eq('grid-row:1/-1', StyleSurface::declarations(['layout' => ['row_span' => 'full']]));
    assert_eq(['(max-width:767.98px)' => 'grid-row:span 1 !important'], StyleSurface::responsiveRules(['mobile' => ['style' => ['layout' => ['row_span' => 1]]]]));
    $box = sbdc_block('core.container', []);
    assert_true(sbtt_valid(array_replace($box, ['style' => ['layout' => ['row_span' => 2]]])));
    assert_false(sbtt_valid(array_replace($box, ['style' => ['layout' => ['row_span' => 0]]])), 'a span is 1 to 12 or full');
});

unit('public pages leave no empty box: a block with nothing to show and a layout of such blocks are left out; the editor keeps both', function (): void {
    $empty = sbdc_block('core.list', ['items' => []]);
    $layout = sbdc_block('layout.flex', [], [], [$empty, $empty]);
    $html = sbdc_render($layout);
    assert_false(str_contains($html, 'sb-block--core-list'), 'an empty list leaves no wrapper');
    assert_false(str_contains($html, 'sb-block--layout-flex'), 'nor does a row of them');
    $editor = sbtt_render_editor($layout);
    assert_true(str_contains($editor, 'sb-block--layout-flex') && str_contains($editor, 'sb-block--core-list'), 'the editor keeps them selectable');
    $band = sbdc_block('core.container', []);
    assert_true(str_contains(sbdc_render($band), 'sb-block--core-container'), 'a layout drawn with no children (a spacer, a band) still renders');
    $mixed = sbdc_block('layout.flex', [], [], [$empty, sbdc_block('core.text', ['content' => 'Hi'])]);
    $out = sbdc_render($mixed);
    assert_true(str_contains($out, 'sb-block--layout-flex') && !str_contains($out, 'sb-block--core-list'), 'a layout with something to show stays; only the empty child goes');
});
