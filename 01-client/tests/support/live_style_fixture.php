<?php
/**
 * What the server's renderer writes for a block's style, read back as data. Used by the live-style parity test and by
 * `regen-live-style.php`, which writes the expected values into `ui/tests/fixtures/live-style.json`.
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

const LIVE_STYLE_FIXTURE = __DIR__ . '/../../plugins/studio-builder/ui/tests/fixtures/live-style.json';

/** The classes the live compiler owns (the same family list as `MANAGED_CLASS` in core/liveStyle.mjs). */
const LIVE_STYLE_MANAGED = '/^(sb-(font|rad|shd|pad|bg|fg|bd)--[\w-]+|sb-font-(normal|medium|semibold|bold|extrabold|\d{3})|sb-(none|uppercase|lowercase|capitalize)|sb-radius-\w+|sb-border-(none|solid|dashed|dotted|double)|sb-shadow-\w+|sb-ty-[a-z]+)$/';

/** @return list<string> the theme's token refs */
function live_style_tokens(): array
{
    $refs = array_keys((new ThemeResolver())->resolve('default')->tokens());
    sort($refs);
    return $refs;
}

/**
 * @param array<string, mixed> $case {style, responsive?, style_states?}
 * @return array{scoped: list<string>, inline: list<string>, classes: list<string>, media: list<array{query: string, declarations: list<string>}>, reduceMotion: bool}
 */
function live_style_actual(array $case): array
{
    $tenants   = new TenantContext();
    $renderer  = new DocumentRenderer(BlockRegistry::withAllCoreBlocks(), BlockRendererRegistry::withCoreRenderers(), new CoreMediaResolver($tenants), new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
    $collector = new RenderCollector();
    $block = [
        'id' => 'blk_aaaaaaaaaaaaaaaaaaaaaaaa', 'type' => 'core.heading', 'version' => 1,
        'props' => ['text' => 'Styled', 'level' => 'h2'],
        'style' => ($case['style'] ?? []) + CanonicalDocumentSchema::defaultBlockStyle(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [], 'children' => [], 'animation' => [], 'interactions' => [],
    ];
    if (isset($case['responsive'])) {
        $block['responsive'] = $case['responsive'];
    }
    $html = $renderer->renderBlock($block, RenderContext::forPublic(101, new SiteContext('https://example.test', 'T')), (new ThemeResolver())->resolve('default'), $collector, false);
    $css = $collector->css();

    preg_match('/<div class="([^"]*)"/', $html, $m);
    $classes = array_values(array_filter(explode(' ', $m[1] ?? ''), static fn(string $c): bool => preg_match(LIVE_STYLE_MANAGED, $c) === 1));
    sort($classes);

    $inline = [];
    if (preg_match('/<div class="[^"]*"[^>]* style="([^"]*)"/', $html, $s) === 1) {
        foreach (explode(';', html_entity_decode($s[1])) as $decl) {
            if ($decl !== '' && !str_starts_with($decl, '--')) {
                $inline[] = $decl;
            }
        }
    }
    sort($inline);

    $scoped = [];
    $media  = [];
    $reduceMotion = false;
    if (preg_match('/\bsb-x-[0-9a-f]{16}\b/', $html, $x) === 1) {
        $cls = preg_quote($x[0], '/');
        if (preg_match('/(?:^|\})\.' . $cls . '\{([^}]*)\}/', $css, $r) === 1) {
            $scoped = explode(';', $r[1]);
        }
        $reduceMotion = str_contains($css, '@media (prefers-reduced-motion:reduce){.' . $x[0] . '{transition:none}}');
        if (preg_match_all('/@media (\([^)]*\))\{\.' . $cls . '\{([^}]*)\}\}/', $css, $all, PREG_SET_ORDER)) {
            foreach ($all as $one) {
                if (str_contains($one[2], '!important')) {
                    $media[] = ['query' => $one[1], 'declarations' => array_map(static fn(string $d): string => trim($d), explode(';', $one[2]))];
                }
            }
        }
    }
    sort($scoped);
    return ['scoped' => $scoped, 'inline' => $inline, 'classes' => $classes, 'media' => $media, 'reduceMotion' => $reduceMotion];
}
