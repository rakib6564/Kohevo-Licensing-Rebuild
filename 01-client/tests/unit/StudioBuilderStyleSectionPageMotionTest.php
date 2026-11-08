<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the page-level motion of ScrollEffects: page colours, adapt, the
 * progress bar, text effects, stagger, pointer glow and the magnet, plus the Dual Color Heading's rotating words and the
 * smooth-scrolling setting.
 *
 * Autoloader only, no database. Reuses sbsm_section / sbsm_doc / sbsm_render from StudioBuilderStyleSectionMotionTest
 * (loaded first, alphabetically).
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Backup\StudioBackupFormat;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\PageDocumentAssembler;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;

/** The opening tag of the rendered section. */
function sbpm_tag(array $interactions): string
{
    preg_match('/<section[^>]*>/', sbsm_render(sbsm_section(['interactions' => $interactions])), $m);
    return $m[0] ?? '';
}

/** A section holding one block of `$type` with `$props` and `$extra` block keys. */
function sbpm_with_block(string $type, array $props, array $extra = []): array
{
    return CanonicalDocumentSchema::newContainer(['metadata' => ['label' => 'S'], 'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'children' => [$extra + [
        'id' => CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => 1,
        'props' => $props, 'style' => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [],
    ]]]);
}

unit('page motion: each effect validates with a fixed shape and refuses anything else', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $ok = DocumentValidator::validate(sbsm_doc(sbsm_section(['interactions' => [
        'page_colors' => ['background' => '#0b0b0c', 'text' => 'text.primary'],
        'adapt'       => true,
        'progress'    => ['edge' => 'bottom', 'color' => '#ff5a1f', 'height' => 3],
        'text'        => ['effect' => 'light_words', 'dim' => 18],
        'stagger'     => ['step_ms' => 80],
        'glow'        => ['color' => 'rgba(255,90,31,.25)', 'size' => 320],
        'mouse'       => ['magnet' => 6],
    ]])), $registry);
    assert_true($ok->isValid(), 'every effect at once is valid: ' . json_encode($ok->errors()));
    foreach ([
        'page colours empty'     => ['page_colors' => []],
        'page colour injection'  => ['page_colors' => ['background' => 'red;position:fixed']],
        'page colours a list'    => ['page_colors' => ['#000']],
        'unknown page key'       => ['page_colors' => ['background' => '#000', 'border' => '#fff']],
        'muted alone'            => ['page_colors' => ['muted' => '#777']],
        'adapt a string'         => ['adapt' => 'yes'],
        'progress no edge'       => ['progress' => ['color' => '#000']],
        'progress left'          => ['progress' => ['edge' => 'left']],
        'progress too tall'      => ['progress' => ['edge' => 'top', 'height' => 40]],
        'unknown text effect'    => ['text' => ['effect' => 'typewriter']],
        'text no effect'         => ['text' => ['dim' => 20]],
        'dim too low'            => ['text' => ['effect' => 'light_words', 'dim' => 1]],
        'line step a string'     => ['text' => ['effect' => 'lines', 'step_ms' => '100']],
        'stagger too fast'       => ['stagger' => ['step_ms' => 5]],
        'stagger no step'        => ['stagger' => []],
        'glow no colour'         => ['glow' => ['size' => 300]],
        'glow too big'           => ['glow' => ['color' => '#fff', 'size' => 5000]],
        'magnet too strong'      => ['mouse' => ['magnet' => 11]],
        'magnet negative'        => ['mouse' => ['magnet' => -2]],
    ] as $why => $interactions) {
        assert_true(!DocumentValidator::validate(sbsm_doc(sbsm_section(['interactions' => $interactions])), $registry)->isValid(), "refused: {$why}");
    }
    $kept = DocumentNormalizer::normalize(sbsm_doc(sbsm_section(['interactions' => ['stagger' => ['step_ms' => 120], 'adapt' => true]])), $registry);
    assert_eq(['adapt' => true, 'stagger' => ['step_ms' => 120]], $kept['containers'][0]['interactions'], 'the canonical form keeps the settings');
});

unit('page motion: page colours and adapt reach the tag as an attribute and custom properties only', function (): void {
    $tag = sbpm_tag(['page_colors' => ['background' => '#0b0b0c', 'text' => 'text.primary'], 'adapt' => true]);
    assert_true(str_contains($tag, ' data-sb-page-colors="bg fg"'), 'marked for the runtime with the colours it sets: ' . $tag);
    assert_true(str_contains($tag, '--sb-page-bg:#0b0b0c'), 'background colour');
    assert_true(str_contains($tag, '--sb-page-fg:var(--sb-text-primary)'), 'a theme colour becomes its variable');
    assert_true(str_contains($tag, ' data-sb-adapt') && (bool) preg_match('/class="[^"]*\bsb-adapt\b/', $tag), 'adapt');

    $all = sbpm_tag(['page_colors' => ['background' => '#ede8df', 'text' => '#0b0b0c', 'muted' => '#6b665e', 'line' => '#d2cbbe', 'accent' => 'color.accent']]);
    assert_true(str_contains($all, 'data-sb-page-colors="bg fg muted line accent"'), 'every colour listed: ' . $all);
    assert_true(str_contains($all, '--sb-page-muted:#6b665e') && str_contains($all, '--sb-page-line:#d2cbbe') && str_contains($all, '--sb-page-accent:var(--sb-color-accent)'), 'muted, line and accent travel too');

    $one = sbpm_tag(['page_colors' => ['text' => '#ffffff']]);
    assert_true(str_contains($one, '--sb-page-fg:#ffffff') && !str_contains($one, '--sb-page-bg'), 'a text colour alone');
    // A stored document is never trusted: junk renders nothing rather than reaching the style attribute.
    $junk = sbpm_tag(['page_colors' => ['background' => 'red;x:y'], 'adapt' => 'yes']);
    assert_true(!str_contains($junk, 'data-sb-page-colors') && !str_contains($junk, 'red;') && !str_contains($junk, 'data-sb-adapt'), 'junk is dropped: ' . $junk);
});

unit('page motion: the theme colours follow the page colours, and each section keeps its own until the runtime runs', function (): void {
    $css = StudioStylesheet::pageMotionCss();
    assert_true(str_contains($css, 'html[data-sb-paged-fg] body.sb-body{color:var(--sb-paged-fg);--sb-text-heading:var(--sb-paged-fg);--sb-text-body:var(--sb-paged-fg);--sb-text-primary:var(--sb-paged-fg)}'), 'text');
    assert_true(str_contains($css, 'html[data-sb-paged-muted] body.sb-body{--sb-text-muted:var(--sb-paged-muted)}'), 'muted');
    assert_true(str_contains($css, 'html[data-sb-paged-line] body.sb-body{--sb-border-default:var(--sb-paged-line)}'), 'lines');
    assert_true(str_contains($css, 'html[data-sb-paged-accent] body.sb-body{--sb-color-accent:var(--sb-paged-accent);--sb-text-accent:var(--sb-paged-accent)}'), 'accent');
    // Only a colour the section sets is pointed at: an unset custom property would blank the theme colour.
    // A section paints its own colours unless the runtime marks it as following the page (before the runtime, off screen).
    assert_true(str_contains($css, '[data-sb-page-colors~="muted"]:not([data-sb-page-on]){--sb-text-muted:var(--sb-page-muted)}'), 'own muted unless following the page');
    assert_true(!str_contains($css, '[data-sb-page-colors]{'), 'no rule for a section whatever it sets');
});

unit('page motion: the progress bar, text effects, stagger, glow and magnet render as data-sb-* attributes', function (): void {
    $tag = sbpm_tag(['progress' => ['edge' => 'top', 'color' => '#ff5a1f', 'height' => 4]]);
    assert_true(str_contains($tag, 'data-sb-progress="top"') && str_contains($tag, '--sb-progress-color:#ff5a1f') && str_contains($tag, '--sb-progress-h:4px'), $tag);
    assert_true(str_contains(sbpm_tag(['progress' => ['edge' => 'bottom']]), 'data-sb-progress="bottom"'), 'colour and height are optional');

    assert_true(str_contains(sbpm_tag(['text' => ['effect' => 'light_words', 'dim' => 25]]), 'data-sb-text="words:25"'), 'light words');
    assert_true(str_contains(sbpm_tag(['text' => ['effect' => 'light_words']]), 'data-sb-text="words:18"'), 'default dim');
    assert_true(str_contains(sbpm_tag(['text' => ['effect' => 'lines', 'step_ms' => 60]]), 'data-sb-text="lines:60"'), 'lines');
    assert_true(str_contains(sbpm_tag(['text' => ['effect' => 'lines']]), 'data-sb-text="lines:100"'), 'default line step');
    assert_true(!str_contains(sbpm_tag(['text' => ['effect' => 'typewriter']]), 'data-sb-text'), 'an unknown effect renders nothing');

    $stagger = sbpm_tag(['stagger' => ['step_ms' => 90]]);
    assert_true(str_contains($stagger, 'data-sb-stagger="90"') && str_contains($stagger, '--sb-stagger-step:90ms'), $stagger);

    $glow = sbpm_tag(['glow' => ['color' => 'color.accent', 'size' => 400]]);
    assert_true(str_contains($glow, ' data-sb-glow') && (bool) preg_match('/class="[^"]*\bsb-glow-on\b/', $glow), 'glow');
    assert_true(str_contains($glow, '--sb-glow-color:var(--sb-color-accent)') && str_contains($glow, '--sb-glow-size:400px'), $glow);

    assert_true(str_contains(sbpm_tag(['mouse' => ['magnet' => 6, 'tilt' => 2]]), 'data-sb-mouse="i:2 m:6"'), 'the magnet joins the mouse levels');
});

unit('page motion: the stylesheet paints what the runtime sets, and reduced motion holds nothing back', function (): void {
    StudioStylesheet::resetCache();
    $css = StudioStylesheet::css();
    foreach ([
        'html[data-sb-paged-bg] body.sb-body{background:var(--sb-paged-bg)}',
        '.sb-progress-bar{position:absolute;',
        'transform:scaleX(var(--sb-page-progress,0))',
        '[data-sb-text^="lines"]:not([data-sb-split]):not([class*="sb-animate-"]){animation:sb-text-hold 1.2s}',
        '[data-sb-lines-in] .sb-wm>.sb-w{transform:none}',
        'html[data-sb-ready] [data-sb-stagger]:not(.is-in) [class*="sb-animate-"]',
        'html[data-sb-ready] [data-sb-wait]{animation-play-state:paused}',
        '.sb-glow-on:hover>.sb-glow{opacity:1}',
        '[data-sb-text^="lines"]{animation:none}.sb-wm>.sb-w{transform:none;transition:none}',
    ] as $rule) {
        assert_true(str_contains($css, $rule), "stylesheet has {$rule}");
    }
    // The hold is an animation that ends: text whose runtime never arrives still appears.
    assert_true(str_contains($css, '@keyframes sb-text-hold{from,to{opacity:0}}') && !str_contains($css, 'sb-text-hold 1.2s both') && !str_contains($css, 'sb-text-hold 1.2s forwards'), 'the hold lets go');
});

unit('page motion: a Dual Color Heading names its rotating words for the runtime, escaped and capped', function (): void {
    $html = sbsm_render(sbpm_with_block('core.dual_heading', ['before' => 'Websites for', 'colored' => 'builders', 'after' => '', 'rotate_words' => 'cleaners, hosts, <b>|cafés, hosts, ', 'rotate_ms' => 2400]));
    assert_true(str_contains($html, 'class="sb-dual-heading__highlight" data-sb-rotate="cleaners|hosts|&lt;b&gt; cafés" data-sb-rotate-ms="2400">builders</span>'), $html);
    $none = sbsm_render(sbpm_with_block('core.dual_heading', ['before' => 'A', 'colored' => 'b', 'after' => 'c', 'rotate_words' => ' , ']));
    assert_true(!str_contains($none, 'data-sb-rotate'), 'no words, no rotation');
    $slow = sbsm_render(sbpm_with_block('core.dual_heading', ['colored' => 'b', 'rotate_words' => 'x', 'rotate_ms' => 99999]));
    assert_true(str_contains($slow, 'data-sb-rotate-ms="10000"'), 'the pause is clamped');
    $def = BlockRegistry::withAllCoreBlocks()->get('core.dual_heading')->toEditorManifest();
    assert_eq('', $def['default_props']['rotate_words'] ?? null, 'off by default');
});

unit('page motion: smooth scrolling is one site setting, kept in a backup', function (): void {
    assert_eq('studio_smooth_scroll', PageDocumentAssembler::SMOOTH_SCROLL_SETTING);
    assert_true(in_array(PageDocumentAssembler::SMOOTH_SCROLL_SETTING, StudioBackupFormat::SETTING_KEYS, true), 'a backup carries it');
    $runtime = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/studio-builder/assets/public/studio-runtime.js');
    assert_true(str_contains($runtime, "hasAttribute('data-sb-smooth')") && str_contains($runtime, "matchMedia('(pointer: fine)')"), 'the runtime reads it, for fine pointers only');
    foreach (['initPageColors', 'initProgress', 'initTextEffects', 'initRotate', 'initGlow', 'initSmoothScroll', 'initViewportFallback'] as $feature) {
        assert_true(str_contains($runtime, "{$feature}]"), "{$feature} is booted");
    }
});

/** A block of `$type` for the browser fixture. */
function sbpm_block(string $type, array $props, array $extra = []): array
{
    return $extra + [
        'id' => CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => 1,
        'props' => $props, 'style' => CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [],
    ];
}

/** A section with `$children` and `$extra` keys (interactions, animation). */
function sbpm_box(array $children, array $extra = []): array
{
    return $extra + CanonicalDocumentSchema::newContainer(['metadata' => ['label' => 'S'], 'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'children' => $children]);
}

unit('page motion: the markup and CSS match the browser fixture the runtime spec scrolls through', function (): void {
    $fade = ['animation' => ['type' => 'fade_up', 'duration_ms' => 300], 'interactions' => ['trigger' => 'viewport-enter']];
    $sections = [
        sbpm_box([sbpm_block('core.heading', ['text' => 'Header', 'level' => 'p'])], ['interactions' => ['sticky' => ['edge' => 'top'], 'adapt' => true, 'progress' => ['edge' => 'bottom', 'color' => '#ff5a1f', 'height' => 3]]]),
        sbpm_box([
            sbpm_block('core.heading', ['text' => 'Every line of this heading rises into place', 'level' => 'h1'], ['interactions' => ['text' => ['effect' => 'lines', 'step_ms' => 80]]]),
            sbpm_block('core.dual_heading', ['before' => 'Websites for', 'colored' => 'builders', 'after' => '', 'rotate_words' => 'cleaners, hosts', 'rotate_ms' => 1000]),
        ], ['interactions' => ['page_colors' => ['background' => '#0b0b0c', 'text' => '#ede8df']]]),
        sbpm_box([
            sbpm_block('core.text', ['content' => 'These words light up one by one as the page scrolls past them, from the first to the last.'], ['interactions' => ['text' => ['effect' => 'light_words', 'dim' => 20]]]),
        ], ['interactions' => ['page_colors' => ['background' => '#ede8df', 'text' => '#0b0b0c']]]),
        sbpm_box([
            sbpm_block('core.text', ['content' => 'One'], $fade),
            sbpm_block('core.text', ['content' => 'Two'], $fade),
            sbpm_block('core.text', ['content' => 'Three'], $fade),
        ], ['interactions' => ['stagger' => ['step_ms' => 100]]]),
        sbpm_box([
            sbpm_block('core.card', [], ['interactions' => ['glow' => ['color' => '#ff5a1f', 'size' => 200]], 'children' => [sbpm_block('core.text', ['content' => 'Glow'])]]),
            sbpm_block('core.button', ['link' => ['label' => 'Pull', 'href' => '#pull']], ['interactions' => ['mouse' => ['magnet' => 8]]]),
            sbpm_block('core.text', ['content' => 'Waits until seen'], $fade),
        ]),
    ];
    $html = implode('', array_map('sbsm_render', $sections));
    $html = (string) preg_replace('/ data-sb-node="[^"]*" data-sb-type="[^"]*"/', '', $html); // block ids are random
    $html = (string) preg_replace('/\bblk_[a-z0-9]+/', 'blk', $html);
    $html = (string) preg_replace('/\bsb-x-[a-f0-9]+/', 'sb-x', $html);
    // The page-level CSS, the one entrance animation used, and no scroll timelines: the spec stands for a browser
    // without them, so "scroll into view" relies on the runtime.
    $all = StudioStylesheet::css();
    preg_match('/@keyframes sb-fade-up\{[^@]*?\}\}/', $all, $kf);
    preg_match('/\.sb-animate-fade-up\{[^}]*\}/', $all, $rule);
    $css = '<style>' . ($kf[0] ?? '') . ($rule[0] ?? '') . StudioStylesheet::scrollEffectsCss() . '</style>';
    $fixture = __DIR__ . '/../../plugins/studio-builder/ui/tests/fixtures/page-motion.html';
    if (getenv('SBPM_WRITE_FIXTURE') === '1') {
        file_put_contents($fixture, $css . $html . "\n");
    }
    assert_true(($kf[0] ?? '') !== '' && ($rule[0] ?? '') !== '', 'the fade-up rules were found');
    assert_eq(trim((string) file_get_contents($fixture)), $css . $html, 'ui/tests/fixtures/page-motion.html is stale — regenerate with SBPM_WRITE_FIXTURE=1');
});

unit('scroll layout: marquee, sideways scroll and hover image validate with fixed shapes, and a node is not both a marquee and sideways', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    foreach ([
        'marquee'    => ['marquee' => ['speed' => 6, 'direction' => 'right', 'scroll' => true, 'pause' => true, 'width' => 240]],
        'sideways'   => ['horizontal' => ['offset' => 80, 'width' => 520], 'hover_image' => ['size' => 280]],
        'offset 0'   => ['horizontal' => ['offset' => 0]],
    ] as $why => $interactions) {
        $ok = DocumentValidator::validate(sbsm_doc(sbsm_section(['interactions' => $interactions])), $registry);
        assert_true($ok->isValid(), "valid: {$why} " . json_encode($ok->errors()));
    }
    foreach ([
        'marquee no speed'      => ['marquee' => ['direction' => 'left']],
        'marquee too fast'      => ['marquee' => ['speed' => 11]],
        'marquee up'            => ['marquee' => ['speed' => 3, 'direction' => 'up']],
        'scroll a string'       => ['marquee' => ['speed' => 3, 'scroll' => 'yes']],
        'item too narrow'       => ['marquee' => ['speed' => 3, 'width' => 20]],
        'sideways no offset'    => ['horizontal' => ['width' => 400]],
        'sideways offset large' => ['horizontal' => ['offset' => 900]],
        'both'                  => ['marquee' => ['speed' => 3], 'horizontal' => ['offset' => 0]],
        'hover image no size'   => ['hover_image' => []],
        'hover image too big'   => ['hover_image' => ['size' => 2000]],
    ] as $why => $interactions) {
        assert_true(!DocumentValidator::validate(sbsm_doc(sbsm_section(['interactions' => $interactions])), $registry)->isValid(), "refused: {$why}");
    }
});

unit('scroll layout: each renders as a data-sb-* attribute and custom properties, and sideways wins over a stored marquee', function (): void {
    $tag = sbpm_tag(['marquee' => ['speed' => 6, 'direction' => 'right', 'scroll' => true, 'pause' => true, 'width' => 240]]);
    assert_true(str_contains($tag, 'data-sb-ticker="6 right scroll pause"') && str_contains($tag, '--sb-item-w:240px'), $tag);
    assert_true(str_contains(sbpm_tag(['marquee' => ['speed' => 2]]), 'data-sb-ticker="2 left"'), 'defaults');
    $side = sbpm_tag(['horizontal' => ['offset' => 80, 'width' => 520]]);
    assert_true(str_contains($side, ' data-sb-horizontal') && str_contains($side, '--sb-hs-offset:80px') && str_contains($side, '--sb-item-w:520px'), $side);
    $both = sbpm_tag(['horizontal' => ['offset' => 0], 'marquee' => ['speed' => 3]]);
    assert_true(str_contains($both, 'data-sb-horizontal') && !str_contains($both, 'data-sb-ticker'), 'sideways wins: ' . $both);
    $hover = sbpm_tag(['hover_image' => ['size' => 300]]);
    assert_true(str_contains($hover, ' data-sb-hover-image') && str_contains($hover, '--sb-hover-size:300px'), $hover);
    // The text marquee's runtime selects [data-sb-marquee] and carousels use data-sb-loop: these must never collide.
    assert_true(!str_contains($tag, 'data-sb-marquee') && !str_contains($tag, 'data-sb-loop'), 'no attribute shared with the text marquee or carousel');
});

unit('scroll layout: the markup and CSS match the browser fixture the runtime spec scrolls through', function (): void {
    $items = static fn(string $prefix, int $n): array => array_map(static fn(int $i): array => sbpm_block('core.heading', ['text' => "{$prefix} {$i}", 'level' => 'h3']), range(1, $n));
    $row = static fn(string $name): array => sbpm_box([sbpm_block('core.heading', ['text' => $name, 'level' => 'h3'])]);
    $sections = [
        sbpm_box($items('Card', 3), ['interactions' => ['marquee' => ['speed' => 6, 'pause' => true, 'width' => 200]]]),
        sbpm_box($items('Panel', 4), ['interactions' => ['horizontal' => ['offset' => 0, 'width' => 500]]]),
        sbpm_box([$row('Construction'), $row('Logistics'), $row('Hospitality')], ['interactions' => ['hover_image' => ['size' => 200]]]),
    ];
    $html = implode('', array_map('sbsm_render', $sections));
    $html = (string) preg_replace('/ data-sb-node="[^"]*" data-sb-type="[^"]*"/', '', $html);
    $html = (string) preg_replace('/\bblk_[a-z0-9]+/', 'blk', $html);
    $html = (string) preg_replace('/\bsb-x-[a-f0-9]+/', 'sb-x', $html);
    $css = '<style>' . StudioStylesheet::scrollEffectsCss() . '</style>';
    $fixture = __DIR__ . '/../../plugins/studio-builder/ui/tests/fixtures/scroll-layout.html';
    if (getenv('SBPM_WRITE_FIXTURE') === '1') {
        file_put_contents($fixture, $css . $html . "\n");
    }
    assert_eq(trim((string) file_get_contents($fixture)), $css . $html, 'ui/tests/fixtures/scroll-layout.html is stale — regenerate with SBPM_WRITE_FIXTURE=1');
});
