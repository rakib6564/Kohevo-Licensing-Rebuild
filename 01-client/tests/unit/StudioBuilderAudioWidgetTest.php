<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the Audio widget (`core.audio`).
 *
 * Verifies the provider links it accepts, that a provider's player stays in a <template> until the visitor asks for
 * it, that a self-hosted file gets the card's own controls, and that anything else renders no player at all.
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
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\AudioRenderer;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;

final class _SbawMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia
    {
        return null;
    }
}

/** @param array<string, mixed> $props */
function sbaw_render(array $props): string
{
    $ctx = RenderContext::forPublic(1, new SiteContext('https://example.test', 'T', 'en'));
    return (new AudioRenderer())->render(new BlockRenderScope(['id' => 'blk_a', 'type' => 'core.audio', 'props' => $props], $ctx, new _SbawMedia(), new ResolvedTheme('default', []), new RenderCollector('nonce')));
}

unit('audio: provider links become their player, anything else is refused', function (): void {
    $sc = (string) AudioRenderer::embedUrl('soundcloud', 'https://soundcloud.com/bxfmradio/positive-fem');
    assert_true(str_starts_with($sc, 'https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fbxfmradio%2Fpositive-fem'), $sc);
    assert_eq('https://open.spotify.com/embed/episode/4pBb3MgCQxSDA9F188P69t', AudioRenderer::embedUrl('spotify', 'https://open.spotify.com/episode/4pBb3MgCQxSDA9F188P69t?si=x'));
    foreach ([['soundcloud', 'http://soundcloud.com/a/b'], ['soundcloud', 'https://evil.test/soundcloud.com/a'], ['soundcloud', 'https://soundcloud.com'], ['spotify', 'https://open.spotify.com/user/abc'], ['spotify', 'https://evil.test/episode/4pBb3MgCQxSDA9F188P69t'], ['spotify', 'javascript:alert(1)']] as [$source, $url]) {
        assert_eq(null, AudioRenderer::embedUrl($source, $url), $url);
    }
});

unit('audio: a provider player waits in a template, and the source link is always there', function (): void {
    $html = sbaw_render(['source' => 'soundcloud', 'url' => 'https://soundcloud.com/bxfmradio/positive-fem', 'title' => 'Fem <b>', 'subtitle' => 'BXFM']);
    assert_eq(1, preg_match('~<template data-sb-audio-player><iframe[^>]+w\.soundcloud\.com~', $html), $html);
    assert_eq(1, substr_count($html, '<iframe'), 'only the waiting player');
    assert_true(str_contains($html, 'Fem &lt;b&gt;') && str_contains($html, '>BXFM<'), 'title and subtitle, escaped');
    assert_true(str_contains($html, 'href="https://soundcloud.com/bxfmradio/positive-fem"'), 'the link to the source');
    assert_true(str_contains($html, 'data-sb-audio-play'), 'a play button');
});

unit('audio: a hosted file gets the card controls, and only a file the browser can play', function (): void {
    $html = sbaw_render(['source' => 'hosted', 'hosted_url' => '/uploads/media/podcast.mp3', 'title' => 'Episode']);
    assert_true(str_contains($html, '<audio src="/uploads/media/podcast.mp3" preload="none"'), $html);
    assert_true(str_contains($html, 'data-sb-audio-seek') && str_contains($html, 'data-sb-audio-time'), 'progress and time');
    assert_false(str_contains($html, '<iframe'), 'no provider');
    foreach (['/uploads/media/podcast.exe', 'javascript:alert(1).mp3', 'ftp://x.test/a.mp3', ''] as $url) {
        $bad = sbaw_render(['source' => 'hosted', 'hosted_url' => $url]);
        assert_true(str_contains($bad, 'sb-audio--empty') && !str_contains($bad, '<audio'), $url . ': ' . $bad);
    }
});
