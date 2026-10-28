<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the Video widget (`core.video`).
 *
 * Autoloader only, no database. Verifies that the video plays on the page like the reference editor's Video widget:
 * YouTube (youtube-nocookie in privacy mode), Vimeo and self-hosted files; autoplay, mute, loop, controls, start and
 * end times and lazy loading; an image overlay whose player waits in a <template> for the runtime to swap in, in
 * place or in a lightbox; the 9:16 ratio; and that a block saved before these settings existed renders as it did.
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

use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\VideoRenderer;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;

final class _SbvwMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia
    {
        return $mediaId === 7 ? new ResolvedMedia(7, '/uploads/media/poster.jpg', 1600, 900) : null;
    }
}

function sbvw_render(array $props): string
{
    $ctx = RenderContext::forPublic(1, new SiteContext('https://example.test', 'T', 'en'));
    return (new VideoRenderer())->render(new BlockRenderScope(['id' => 'blk_v', 'type' => 'core.video', 'props' => $props], $ctx, new _SbvwMedia(), new ResolvedTheme('default', []), new RenderCollector('nonce')));
}

/** The query of the first iframe's src, as an array. */
function sbvw_query(string $html): array
{
    assert_true(preg_match('~<iframe src="([^"]+)"~', $html, $m) === 1, 'an iframe: ' . $html);
    parse_str((string) parse_url(html_entity_decode($m[1]), PHP_URL_QUERY), $q);
    return $q;
}

unit('video: a block saved before Source existed (only url) still plays its YouTube link in place, through youtube-nocookie', function (): void {
    $html = sbvw_render(['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'aspect_ratio' => '16:9', 'autoplay' => false, 'controls' => true, 'muted' => false, 'loop' => false]);
    assert_true(str_contains($html, 'src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?'), $html);
    assert_true(str_contains($html, 'sb-video-wrapper') && str_contains($html, 'sb-video--aspect-16-9'), 'same wrapper classes as before');
    assert_true(str_contains($html, 'loading="lazy"'), 'lazy by default');
    assert_true(str_contains($html, 'allowfullscreen') && str_contains($html, 'referrerpolicy="strict-origin-when-cross-origin"'), 'fullscreen, and a referrer YouTube accepts');
    assert_false(str_contains($html, '<a '), 'never a link that leaves the page');
    assert_false(str_contains($html, 'data-sb-video-play'), 'no overlay unless asked for');
});

unit('video: YouTube links of every shape resolve to the same embed', function (): void {
    foreach ([
        'https://youtu.be/dQw4w9WgXcQ',
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=x',
        'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
        'https://www.youtube.com/embed/dQw4w9WgXcQ',
        'https://www.youtube.com/shorts/dQw4w9WgXcQ',
        'https://www.youtube.com/live/dQw4w9WgXcQ',
        'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
    ] as $link) {
        $player = VideoRenderer::player($link);
        assert_eq('youtube', $player['provider'] ?? null, $link);
        assert_eq('dQw4w9WgXcQ', $player['id'] ?? null, $link);
    }
    foreach (['https://evil.example/watch?v=dQw4w9WgXcQ', 'https://notyoutube.com/watch?v=dQw4w9WgXcQ', 'javascript:alert(1)', 'https://www.youtube.com/watch?v=<script>', 'nope', ''] as $bad) {
        assert_null(VideoRenderer::player($bad), "refused: {$bad}");
    }
});

unit('video: autoplay, mute, loop, controls, start and end reach the YouTube player', function (): void {
    $q = sbvw_query(sbvw_render(['url' => 'https://youtu.be/dQw4w9WgXcQ', 'autoplay' => true, 'muted' => true, 'loop' => true, 'controls' => false, 'start' => 15, 'end' => 45]));
    assert_eq(['1', '1', '1', '0', '15', '45', 'dQw4w9WgXcQ', '1'], [$q['autoplay'] ?? null, $q['mute'] ?? null, $q['loop'] ?? null, $q['controls'] ?? null, $q['start'] ?? null, $q['end'] ?? null, $q['playlist'] ?? null, $q['playsinline'] ?? null]);

    // A shared link's own "t=" is where it starts, unless Start says otherwise.
    assert_eq('90', sbvw_query(sbvw_render(['url' => 'https://youtu.be/dQw4w9WgXcQ?t=1m30s']))['start'] ?? null);
    assert_eq('5', sbvw_query(sbvw_render(['url' => 'https://youtu.be/dQw4w9WgXcQ?t=90', 'start' => 5]))['start'] ?? null);
    // An end before the start means nothing.
    assert_false(isset(sbvw_query(sbvw_render(['url' => 'https://youtu.be/dQw4w9WgXcQ', 'start' => 30, 'end' => 10]))['end']));
});

unit('video: Privacy Mode off uses youtube.com; on (the default) uses youtube-nocookie and asks Vimeo not to track', function (): void {
    assert_true(str_contains(sbvw_render(['url' => 'https://youtu.be/dQw4w9WgXcQ', 'privacy' => false]), 'src="https://www.youtube.com/embed/dQw4w9WgXcQ?'));
    assert_true(str_contains(sbvw_render(['url' => 'https://youtu.be/dQw4w9WgXcQ']), 'src="https://www.youtube-nocookie.com/embed/'));
    assert_eq('1', sbvw_query(sbvw_render(['source' => 'vimeo', 'url' => 'https://vimeo.com/76979871']))['dnt'] ?? null);
    assert_false(isset(sbvw_query(sbvw_render(['source' => 'vimeo', 'url' => 'https://vimeo.com/76979871', 'privacy' => false]))['dnt']));
});

unit('video: Vimeo plays in place, unlisted links keep their hash, and the start time is a fragment', function (): void {
    $html = sbvw_render(['source' => 'vimeo', 'url' => 'https://vimeo.com/76979871', 'autoplay' => true, 'muted' => true, 'loop' => true, 'start' => 12]);
    assert_true(str_contains($html, 'src="https://player.vimeo.com/video/76979871?'), $html);
    assert_true(str_contains($html, '#t=12s"'), 'start as #t=');
    $q = sbvw_query($html);
    assert_eq(['1', '1', '1'], [$q['autoplay'] ?? null, $q['muted'] ?? null, $q['loop'] ?? null]);

    assert_eq('abcdef1234', VideoRenderer::player('https://vimeo.com/76979871/abcdef1234') ? sbvw_query(sbvw_render(['url' => 'https://vimeo.com/76979871/abcdef1234']))['h'] ?? null : null);
    assert_eq('76979871', VideoRenderer::player('https://player.vimeo.com/video/76979871?h=abcdef1234')['id'] ?? null);
    assert_null(VideoRenderer::player('https://vimeo.com/about'), 'a Vimeo page that is no video');
});

unit('video: a self-hosted file plays in a <video> element, with its start and end as a media fragment', function (): void {
    $html = sbvw_render(['source' => 'hosted', 'hosted_url' => '/uploads/media/clip.mp4', 'controls' => true, 'loop' => true, 'muted' => true, 'start' => 3, 'end' => 9]);
    assert_true(str_contains($html, '<video src="/uploads/media/clip.mp4#t=3,9"'), $html);
    assert_true(str_contains($html, ' controls') && str_contains($html, ' loop') && str_contains($html, ' muted') && str_contains($html, ' playsinline'), $html);
    assert_true(str_contains($html, 'preload="none"'), 'lazy: nothing is fetched before play');
    assert_true(str_contains(sbvw_render(['source' => 'hosted', 'hosted_url' => '/uploads/media/clip.mp4', 'lazy_load' => false]), 'preload="metadata"'));
    assert_true(str_contains(sbvw_render(['source' => 'hosted', 'hosted_url' => 'https://cdn.example.com/a/clip.webm', 'autoplay' => true]), ' autoplay'));

    // Hosted with no file falls back to the link, so switching Source never blanks a working block.
    assert_true(str_contains(sbvw_render(['source' => 'hosted', 'url' => 'https://youtu.be/dQw4w9WgXcQ']), 'youtube-nocookie.com/embed/dQw4w9WgXcQ'));
    // Not a playable file, or not an allowed address: the "unavailable" box, never a guessed player.
    foreach (['/uploads/media/doc.pdf', 'javascript:alert(1)//x.mp4', '//evil.example/x.mp4', 'ftp://example.com/x.mp4'] as $bad) {
        $out = sbvw_render(['source' => 'hosted', 'hosted_url' => $bad, 'url' => '']);
        assert_true(str_contains($out, 'sb-video__placeholder') && !str_contains($out, '<video') && !str_contains($out, '<iframe'), "refused: {$bad}");
    }
});

unit('video: the four aspect ratios, 9:16 included, and anything else falls back to 16:9', function (): void {
    foreach (['16:9' => '16-9', '4:3' => '4-3', '1:1' => '1-1', '9:16' => '9-16'] as $ratio => $class) {
        assert_true(str_contains(sbvw_render(['url' => 'https://youtu.be/dQw4w9WgXcQ', 'aspect_ratio' => $ratio]), 'sb-video--aspect-' . $class));
    }
    assert_true(str_contains(sbvw_render(['url' => 'https://youtu.be/dQw4w9WgXcQ', 'aspect_ratio' => '2:1']), 'sb-video--aspect-16-9'));
    $css = StudioStylesheet::css();
    foreach (['.sb-video--aspect-9-16{aspect-ratio:9/16}', '.sb-video__overlay{', '.sb-video__play{', '.sb-video-lightbox__frame'] as $rule) {
        assert_true(str_contains($css, $rule), "the stylesheet has {$rule}");
    }
});

unit('video: an image overlay is a poster button; the player waits in a <template>, autoplaying, for the runtime to swap in', function (): void {
    $html = sbvw_render(['url' => 'https://youtu.be/dQw4w9WgXcQ', 'overlay' => true, 'overlay_image' => ['media_id' => 7, 'alt' => 'Sara'], 'autoplay' => false]);
    assert_true(str_contains($html, 'data-sb-video ') && str_contains($html, 'sb-video--overlay'), $html);
    assert_true(str_contains($html, '<button type="button" class="sb-video__overlay" data-sb-video-play aria-label="Play video">'), 'a real button, named');
    assert_true(str_contains($html, 'src="/uploads/media/poster.jpg" alt="Sara"'), 'the chosen poster');
    assert_true(str_contains($html, 'class="sb-video__play"'), 'the play icon by default');
    assert_true(preg_match('~<template data-sb-video-player><iframe src="([^"]+)"~', $html, $m) === 1, 'the player is in the template');
    assert_true(str_contains(html_entity_decode($m[1]), 'autoplay=1'), 'clicking the poster is asking to watch: the player starts');
    assert_false(str_contains(substr($html, (int) strpos($html, '<template')), 'loading="lazy"'), 'the swapped-in player loads at once');
    assert_false(str_contains($html, '<a '), 'never a link to YouTube');
    assert_false(str_contains($html, 'data-sb-video-lightbox'), 'in place unless Lightbox is on');
    assert_eq(1, substr_count($html, '<iframe'), 'only the template holds a player');

    assert_false(str_contains(sbvw_render(['url' => 'https://youtu.be/dQw4w9WgXcQ', 'overlay' => true, 'overlay_image' => ['media_id' => 7, 'alt' => ''], 'play_icon' => false]), 'sb-video__play'), 'Play Icon off');
});

unit('video: Lightbox opens the overlay\'s player in a dialog; a hosted file\'s player autoplays the same way', function (): void {
    $html = sbvw_render(['url' => 'https://youtu.be/dQw4w9WgXcQ', 'overlay' => true, 'overlay_image' => ['media_id' => 7, 'alt' => ''], 'lightbox' => true, 'aspect_ratio' => '9:16']);
    assert_true(str_contains($html, ' data-sb-video-lightbox') && str_contains($html, 'data-sb-video-aspect="9-16"') && str_contains($html, 'aria-haspopup="dialog"'), $html);

    $file = sbvw_render(['source' => 'hosted', 'hosted_url' => '/uploads/media/clip.mp4', 'overlay' => true, 'overlay_image' => ['media_id' => 7, 'alt' => '']]);
    assert_true(str_contains($file, '<template data-sb-video-player><video src="/uploads/media/clip.mp4"') && str_contains($file, ' autoplay'), $file);
});

unit('video: an overlay with no image uses a YouTube video\'s own thumbnail, and otherwise plays without one', function (): void {
    $yt = sbvw_render(['url' => 'https://youtu.be/dQw4w9WgXcQ', 'overlay' => true]);
    assert_true(str_contains($yt, 'src="https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg"'), $yt);
    $vimeo = sbvw_render(['source' => 'vimeo', 'url' => 'https://vimeo.com/76979871', 'overlay' => true, 'overlay_image' => ['media_id' => 99, 'alt' => '']]);
    assert_false(str_contains($vimeo, 'data-sb-video-play'), 'no poster to show: the player itself');
    assert_true(str_contains($vimeo, '<iframe src="https://player.vimeo.com/video/76979871'), $vimeo);
});

unit('video: the schema offers the controls, keeps the old fields, and refuses out-of-range times', function (): void {
    $def = BlockRegistry::withAllCoreBlocks()->get('core.video');
    $keys = array_map(static fn(array $f): string => $f['key'], $def->toEditorManifest()['field_schema']);
    foreach (['source', 'url', 'hosted_url', 'start', 'end', 'aspect_ratio', 'autoplay', 'muted', 'loop', 'controls', 'privacy', 'lazy_load', 'overlay', 'overlay_image', 'play_icon', 'lightbox'] as $key) {
        assert_true(in_array($key, $keys, true), "field {$key}");
    }
    $schema = $def->schema();
    assert_true($schema->validate(['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'aspect_ratio' => '16:9', 'autoplay' => false, 'controls' => true, 'muted' => false, 'loop' => false])->isValid(), 'a block saved before this change is still valid');
    assert_true($schema->validate(['source' => 'hosted', 'hosted_url' => '/uploads/media/clip.mp4', 'start' => 0, 'end' => 30, 'aspect_ratio' => '9:16', 'overlay' => true, 'overlay_image' => ['media_id' => 7, 'alt' => 'x'], 'lightbox' => true])->isValid(), 'the new settings');
    assert_false($schema->validate(['start' => -1])->isValid(), 'a negative start');
    assert_false($schema->validate(['end' => VideoRenderer::MAX_SECONDS + 1])->isValid(), 'an end past 24 hours');
    assert_false($schema->validate(['source' => 'dailymotion'])->isValid(), 'an unknown source');
    assert_false($schema->validate(['hosted_url' => 'javascript:alert(1)'])->isValid(), 'an unsafe file address');
});

unit('video: the runtime swaps the template in place or opens it in a lightbox, and undoes it when disabled', function (): void {
    $js = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/assets/public/studio-runtime.js');
    assert_true(str_contains($js, "['videos', initVideos]"), 'a registered feature');
    foreach (['[data-sb-video]', '[data-sb-video-play]', 'template[data-sb-video-player]', 'data-sb-video-lightbox', 'openOverlay(box, button)', "frame.innerHTML = ''", 'replaceChild(s.button, s.player)'] as $needle) {
        assert_true(str_contains($js, $needle), "runtime: {$needle}");
    }
    assert_false((bool) preg_match('/youtube\.com|location\.href\s*=|window\.open/', substr($js, (int) strpos($js, 'function initVideos'), 4000)), 'the runtime builds no player address and never navigates');
});
