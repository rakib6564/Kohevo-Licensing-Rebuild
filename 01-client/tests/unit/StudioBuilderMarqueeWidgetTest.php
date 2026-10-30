<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the Text Marquee widget (`core.marquee`).
 *
 * Verifies that the two groups match (the seamless loop depends on it), that only one copy is read out, that the text
 * is escaped, that the loop time and direction stay inside their limits, and that the motion honours reduced motion.
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

use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\MarqueeRenderer;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;

final class _SbmqMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia
    {
        return null;
    }
}

/** @param array<string, mixed> $props */
function sbmq_render(array $props): string
{
    $ctx = RenderContext::forPublic(1, new SiteContext('https://example.test', 'T', 'en'));
    return (new MarqueeRenderer())->render(new BlockRenderScope(['id' => 'blk_m', 'type' => 'core.marquee', 'props' => $props], $ctx, new _SbmqMedia(), new ResolvedTheme('default', []), new RenderCollector('nonce')));
}

unit('marquee: two equal groups, and only the first copy is read out', function (): void {
    $html = sbmq_render(['text' => 'La force <b>']);
    assert_eq(2, substr_count($html, 'class="sb-marquee__group"'), $html);
    assert_eq(16, substr_count($html, 'La force &lt;b&gt;'), 'every copy, escaped');
    assert_eq(1, substr_count($html, '<span class="sb-marquee__text">'), 'one readable copy');
    assert_eq(15, substr_count($html, 'aria-hidden="true">La force'), 'every repeat is hidden');
    assert_eq(1, substr_count($html, 'sb-marquee__group" aria-hidden'), 'and so is the second group');
});

unit('marquee: loop time and direction stay inside their limits', function (): void {
    assert_true(str_contains(sbmq_render(['text' => 'x', 'duration' => 40]), 'animation-duration:40s'), 'the chosen time');
    assert_true(str_contains(sbmq_render(['text' => 'x', 'duration' => 1]), 'animation-duration:5s'), 'the shortest');
    assert_true(str_contains(sbmq_render(['text' => 'x', 'duration' => 9999]), 'animation-duration:180s'), 'the longest');
    assert_true(str_contains(sbmq_render(['text' => 'x', 'direction' => 'right']), 'sb-marquee--right'), 'right');
    assert_true(str_contains(sbmq_render(['text' => 'x', 'direction' => '"><script>']), 'sb-marquee--left'), 'an unknown direction falls back');
});

unit('marquee: pause on hover is opt-in, and empty text renders nothing', function (): void {
    assert_true(!str_contains(sbmq_render(['text' => 'x']), 'sb-marquee--pause'), 'off by default');
    assert_true(str_contains(sbmq_render(['text' => 'x', 'pause_on_hover' => true]), 'sb-marquee--pause'), 'on');
    assert_eq('', sbmq_render(['text' => '   ']));
});

unit('marquee: reduced motion stops the loop and hides the repeats', function (): void {
    assert_eq(1, preg_match('~@media \(prefers-reduced-motion:reduce\)\{\.sb-marquee__track\{[^}]*animation:none\}\.sb-marquee \[aria-hidden="true"\]\{display:none\}~', StudioStylesheet::css()));
});
