<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the Countdown widget's options: Type, View, the units and their
 * labels, and what happens after it ends. The runtime's side is in ui/e2e/countdown-runtime.spec.mjs.
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

use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\CountdownRenderer;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\SiteLinks;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;

final class _SbcdMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia
    {
        return null;
    }
}

/** @param array<string, mixed> $props */
function sbcd_render(array $props): string
{
    $ctx = RenderContext::forPublic(1, new SiteContext('https://example.test', 'T', 'en'));
    $block = ['id' => 'blk_cd', 'type' => 'core.countdown', 'props' => $props + ['target' => '2030-01-01T00:00:00Z']];
    return (new CountdownRenderer())->render(new BlockRenderScope($block, $ctx, new _SbcdMedia(), new ResolvedTheme('default', []), new RenderCollector('nonce')));
}

unit('countdown: an evergreen timer carries its length and no date', function (): void {
    $html = sbcd_render(['countdown_type' => 'evergreen', 'evergreen_hours' => 2, 'evergreen_minutes' => 30]);
    assert_true(str_contains($html, 'data-sb-countdown=""'), $html);
    assert_true(str_contains($html, 'data-sb-countdown-evergreen="9000"'), 'hours and minutes in seconds');
    assert_true(str_contains($html, 'data-sb-countdown-key="blk_cd"'), 'the visitor\'s start is kept per block');
    assert_true(!str_contains($html, 'sb-countdown__date'), 'no date line');
    assert_eq('', sbcd_render(['countdown_type' => 'evergreen', 'evergreen_hours' => 0, 'evergreen_minutes' => 0]), 'no length, no timer');
});

unit('countdown: units and labels follow their switches', function (): void {
    $html = sbcd_render(['show_days' => false, 'show_seconds' => false]);
    assert_true(!str_contains($html, 'data-sb-cd="d"') && !str_contains($html, 'data-sb-cd="s"'), 'hidden units');
    assert_true(str_contains($html, 'data-sb-cd="h"') && str_contains($html, 'data-sb-cd="m"'), 'shown units');
    assert_true(!str_contains(sbcd_render(['show_labels' => false]), 'sb-countdown__name'), 'no labels');
    $custom = sbcd_render(['custom_labels' => true, 'label_days' => 'J <b>']);
    assert_true(str_contains($custom, '>J &lt;b&gt;<'), 'a custom label, escaped');
    assert_true(str_contains($custom, '>Hours<'), 'an empty custom label keeps the default');
    assert_true(!str_contains(sbcd_render(['label_days' => 'J']), '>J<'), 'custom labels are off until switched on');
    assert_true(str_contains(sbcd_render(['view' => 'inline']), 'sb-countdown--inline'), 'inline view');
});

unit('countdown: after expire keeps old blocks as they were', function (): void {
    $old = sbcd_render(['done_text' => 'Ended']);
    assert_true(str_contains($old, 'sb-countdown__done">Ended<'), 'the message stays the default');
    assert_true(!str_contains($old, 'data-sb-countdown-expire'), 'no action attribute');
    assert_true(str_contains(sbcd_render(['done_text' => 'Ended', 'message_align' => 'center']), 'sb-countdown--done-center'), 'alignment');
    $hide = sbcd_render(['expire_action' => 'hide', 'done_text' => 'Ended']);
    assert_true(str_contains($hide, 'data-sb-countdown-expire="hide"') && !str_contains($hide, 'Ended'), 'hide drops the message');
    $go = sbcd_render(['expire_action' => 'redirect', 'redirect_url' => '/merci/']);
    assert_true(str_contains($go, 'data-sb-countdown-expire="redirect"') && str_contains($go, 'data-sb-href="/merci/"'), 'redirect');
    assert_true(!str_contains(sbcd_render(['expire_action' => 'redirect', 'redirect_url' => 'javascript:alert(1)']), 'data-sb-href'), 'an unsafe address is dropped');
    assert_true(str_contains(SiteLinks::rebase($go, '/site'), 'data-sb-href="/site/merci/"'), 'a sub-folder install keeps the link on its own site');
});

unit('countdown: the new fields validate, and a target set before them still does', function (): void {
    $def = ModuleBlockDefinitions::studioRegistry()->get('core.countdown');
    assert_true($def->validateProps(['target' => '2030-01-01T00:00:00Z'])->isValid(), 'an old block');
    assert_true($def->validateProps(['target' => '2030-01-01T00:00:00Z', 'countdown_type' => 'evergreen', 'evergreen_hours' => 1, 'view' => 'inline', 'expire_action' => 'hide'])->isValid(), 'the new options');
    assert_false($def->validateProps(['target' => '2030-01-01T00:00:00Z', 'evergreen_minutes' => 60])->isValid(), 'minutes stop at 59');
    assert_false($def->validateProps(['target' => '2030-01-01T00:00:00Z', 'expire_action' => 'explode'])->isValid(), 'an unknown action');
});
