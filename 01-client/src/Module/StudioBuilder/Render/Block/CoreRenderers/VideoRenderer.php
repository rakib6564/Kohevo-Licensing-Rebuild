<?php
/**
 * Kohevo Studio — `core.video` renderer.
 *
 * Plays the video in place, like the reference editor's Video widget: a YouTube or Vimeo embed (YouTube through
 * youtube-nocookie in privacy mode) or a self-hosted file in a `<video>` element.
 *
 * With an image overlay the block first shows a poster button. The player is rendered here, server side, inside a
 * `<template>`; on click studio-runtime.js swaps the poster for that player, or opens it in a lightbox. The visitor
 * never leaves the page, and the runtime never builds a player URL of its own.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class VideoRenderer implements BlockRendererInterface
{
    public const ASPECTS = ['16:9', '4:3', '1:1', '9:16'];
    public const SOURCES = ['youtube', 'vimeo', 'hosted'];

    /** A self-hosted file the browser can play in a `<video>` element. */
    private const FILE_PATTERN = '/\.(mp4|m4v|webm|ogg|ogv|mov)$/i';

    /** The longest start or end time accepted, in seconds (24 hours). */
    public const MAX_SECONDS = 86400;

    public function type(): string
    {
        return 'core.video';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $aspect = $scope->string('aspect_ratio', '16:9');
        if (!in_array($aspect, self::ASPECTS, true)) {
            $aspect = '16:9';
        }
        $aspectClass = str_replace(':', '-', $aspect);
        $classes = ['sb-video-wrapper', 'sb-video--aspect-' . $aspectClass];

        $source = $scope->string('source', 'youtube');
        // A self-hosted block plays its file; a YouTube or Vimeo block plays its link. The provider is read from the
        // link itself, so a block saved before Source existed (only `url`) plays what it always played.
        $url = $source === 'hosted' && trim($scope->string('hosted_url')) !== ''
            ? trim($scope->string('hosted_url'))
            : trim($scope->string('url'));

        $options = [
            'autoplay' => $scope->bool('autoplay', false),
            'controls' => $scope->bool('controls', true),
            'muted'    => $scope->bool('muted', false),
            'loop'     => $scope->bool('loop', false),
            'start'    => self::seconds($scope->prop('start')),
            'end'      => self::seconds($scope->prop('end')),
            'privacy'  => $scope->bool('privacy', true),
        ];

        $player = self::player($url, $options);
        if ($player === null) {
            return '<div' . Html::classAttr($classes) . '><div class="sb-video__placeholder" style="background:#1f2937;color:#9ca3af;display:flex;align-items:center;justify-content:center;height:100%;">' . Html::e(Html::t('studio_video_unavailable', 'Video unavailable')) . '</div></div>';
        }

        $title = Html::t('studio_video', 'Video');
        $lazy = $scope->bool('lazy_load', true);

        $poster = $scope->bool('overlay', false) ? self::poster($scope, $player) : null;
        if ($poster === null) {
            return '<div' . Html::classAttr($classes) . '>' . self::playerHtml($player, $title, $lazy) . '</div>';
        }

        // Behind the poster: clicking is the visitor asking to watch, so the player it opens starts at once.
        $clicked = self::player($url, ['autoplay' => true] + $options);
        $classes[] = 'sb-video--overlay';
        $lightbox = $scope->bool('lightbox', false);

        return '<div' . Html::classAttr($classes) . ' data-sb-video data-sb-video-aspect="' . Html::e($aspectClass) . '"' . ($lightbox ? ' data-sb-video-lightbox' : '') . '>'
            . '<button type="button" class="sb-video__overlay" data-sb-video-play aria-label="' . Html::e(Html::t('studio_video_play', 'Play video')) . '"' . ($lightbox ? ' aria-haspopup="dialog"' : '') . '>'
            . ImageRenderer::img($poster['url'], $poster['alt'], $poster['width'], $poster['height'], $poster['ref'], $lazy)
            . ($scope->bool('play_icon', true) ? '<span class="sb-video__play" aria-hidden="true"><svg viewBox="0 0 24 24" width="24" height="24" focusable="false"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg></span>' : '')
            . '</button>'
            . '<template data-sb-video-player>' . self::playerHtml($clicked ?? $player, $title, false) . '</template>'
            . '</div>';
    }

    /**
     * The poster: the chosen overlay image, or a YouTube video's own thumbnail when none is chosen.
     *
     * @param array{kind: string, src: string, provider: string, id: string} $player
     * @return array{url: string, alt: string, width: ?int, height: ?int, ref: mixed}|null
     */
    private static function poster(BlockRenderScope $scope, array $player): ?array
    {
        $ref = $scope->prop('overlay_image');
        $image = $scope->image($ref);
        if ($image !== null) {
            $alt = is_array($ref) && is_string($ref['alt'] ?? null) ? $ref['alt'] : '';
            return ['url' => $image->url, 'alt' => $alt, 'width' => $image->width, 'height' => $image->height, 'ref' => $ref];
        }
        if ($player['provider'] === 'youtube') {
            return ['url' => 'https://i.ytimg.com/vi/' . $player['id'] . '/hqdefault.jpg', 'alt' => '', 'width' => 480, 'height' => 360, 'ref' => null];
        }
        return null;
    }

    /** @param array{kind: string, src: string} $player */
    private static function playerHtml(array $player, string $title, bool $lazy): string
    {
        if ($player['kind'] === 'file') {
            $o = $player['options'];
            return '<video src="' . Html::e($player['src']) . '"'
                . ($o['controls'] ? ' controls' : '')
                . ($o['autoplay'] ? ' autoplay' : '')
                . ($o['muted'] ? ' muted' : '')
                . ($o['loop'] ? ' loop' : '')
                . ' playsinline preload="' . ($lazy && !$o['autoplay'] ? 'none' : 'metadata') . '"></video>';
        }

        return '<iframe src="' . Html::e($player['src']) . '"' . ($lazy ? ' loading="lazy"' : '')
            . ' title="' . Html::e($title) . '"'
            . ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen"'
            // YouTube refuses to play an embed that sends no referrer (error 153), whatever the page's own policy.
            . ' referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>';
    }

    /**
     * What plays: an embed (`iframe`) or a file (`file`), or null when the address is none of those.
     *
     * @param array{autoplay?: bool, controls?: bool, muted?: bool, loop?: bool, start?: int, end?: int, privacy?: bool} $options
     * @return array{kind: string, src: string, provider: string, id: string, options: array<string, mixed>}|null
     */
    public static function player(string $url, array $options = []): ?array
    {
        $o = $options + ['autoplay' => false, 'controls' => true, 'muted' => false, 'loop' => false, 'start' => 0, 'end' => 0, 'privacy' => true];
        $o['start'] = max(0, min(self::MAX_SECONDS, (int) $o['start']));
        $o['end'] = max(0, min(self::MAX_SECONDS, (int) $o['end']));
        if ($o['end'] > 0 && $o['end'] <= $o['start']) {
            $o['end'] = 0;
        }

        $url = trim($url);
        if ($url === '') {
            return null;
        }

        // A site-relative file (a Media library upload, `/uploads/...`).
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match(self::FILE_PATTERN, $path) !== 1 || Html::safeUrl($url) === null) {
                return null;
            }
            return ['kind' => 'file', 'src' => self::fileSrc($url, $o), 'provider' => 'hosted', 'id' => '', 'options' => $o];
        }

        $parsed = parse_url($url);
        if (!is_array($parsed) || empty($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower($parsed['host'] ?? '');
        $path = $parsed['path'] ?? '';
        parse_str($parsed['query'] ?? '', $query);

        if (self::hostIs($host, 'youtube.com') || self::hostIs($host, 'youtu.be') || self::hostIs($host, 'youtube-nocookie.com')) {
            $id = self::youtubeId($host, $path, $query);
            if ($id === '') {
                return null;
            }
            // A `t=` in a shared link ("?t=90", "?t=1m30s") is where the author meant it to start.
            $start = $o['start'] > 0 ? $o['start'] : self::youtubeTime($query['t'] ?? ($query['start'] ?? ''));
            $params = [
                'autoplay'    => $o['autoplay'] ? '1' : '0',
                'controls'    => $o['controls'] ? '1' : '0',
                'mute'        => $o['muted'] ? '1' : '0',
                'loop'        => $o['loop'] ? '1' : '0',
                'playsinline' => '1',
                'rel'         => '0',
            ];
            if ($o['loop']) {
                $params['playlist'] = $id;
            }
            if ($start > 0) {
                $params['start'] = (string) $start;
            }
            if ($o['end'] > $start) {
                $params['end'] = (string) $o['end'];
            }
            $base = $o['privacy'] ? 'https://www.youtube-nocookie.com/embed/' : 'https://www.youtube.com/embed/';
            return ['kind' => 'iframe', 'src' => $base . $id . '?' . http_build_query($params), 'provider' => 'youtube', 'id' => $id, 'options' => $o];
        }

        if (self::hostIs($host, 'vimeo.com')) {
            // vimeo.com/123, vimeo.com/123/<hash> (unlisted), player.vimeo.com/video/123?h=<hash>, vimeo.com/channels/x/123.
            if (preg_match('~(?:^|/)(\d{3,})(?:/([0-9a-f]{6,}))?(?:/|$)~i', $path, $m) !== 1) {
                return null;
            }
            $params = [
                'autoplay' => $o['autoplay'] ? '1' : '0',
                'muted'    => $o['muted'] ? '1' : '0',
                'loop'     => $o['loop'] ? '1' : '0',
                'controls' => $o['controls'] ? '1' : '0',
                'playsinline' => '1',
            ];
            $hash = $m[2] ?? (is_string($query['h'] ?? null) ? $query['h'] : '');
            if ($hash !== '' && preg_match('/^[0-9a-f]{6,}$/i', $hash) === 1) {
                $params = ['h' => $hash] + $params;
            }
            if ($o['privacy']) {
                $params['dnt'] = '1';
            }
            $src = 'https://player.vimeo.com/video/' . $m[1] . '?' . http_build_query($params) . ($o['start'] > 0 ? '#t=' . $o['start'] . 's' : '');
            return ['kind' => 'iframe', 'src' => $src, 'provider' => 'vimeo', 'id' => $m[1], 'options' => $o];
        }

        // A file on any server (self-hosted, a CDN).
        if (preg_match(self::FILE_PATTERN, $path) === 1 && Html::safeUrl($url) !== null) {
            return ['kind' => 'file', 'src' => self::fileSrc($url, $o), 'provider' => 'hosted', 'id' => '', 'options' => $o];
        }

        return null;
    }

    /**
     * The embed address of a link, kept for callers that only want the URL.
     */
    public static function buildEmbedUrl(string $url, bool $autoplay, bool $controls, bool $muted, bool $loop, int $start = 0, int $end = 0, bool $privacy = true): ?string
    {
        $player = self::player($url, ['autoplay' => $autoplay, 'controls' => $controls, 'muted' => $muted, 'loop' => $loop, 'start' => $start, 'end' => $end, 'privacy' => $privacy]);
        return $player['src'] ?? null;
    }

    /** A whole number of seconds from a stored prop (a number or numeric text), 0 when unset. */
    private static function seconds(mixed $value): int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return max(0, min(self::MAX_SECONDS, (int) $value));
        }
        return 0;
    }

    /** A file with its start and end as a media fragment (`#t=10,20`), which every browser's `<video>` honours. */
    private static function fileSrc(string $url, array $o): string
    {
        $url = (string) preg_replace('/#.*$/', '', $url);
        if ($o['start'] > 0 || $o['end'] > 0) {
            $url .= '#t=' . $o['start'] . ($o['end'] > 0 ? ',' . $o['end'] : '');
        }
        return $url;
    }

    private static function hostIs(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.' . $domain);
    }

    /** @param array<string, mixed> $query */
    private static function youtubeId(string $host, string $path, array $query): string
    {
        if (self::hostIs($host, 'youtu.be')) {
            $id = explode('/', ltrim($path, '/'))[0] ?? '';
        } elseif (is_string($query['v'] ?? null) && $query['v'] !== '') {
            $id = $query['v'];
        } elseif (preg_match('~^/(?:embed|shorts|live|v)/([^/?#]+)~', $path, $m) === 1) {
            $id = $m[1];
        } else {
            $id = '';
        }
        return preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id) === 1 ? $id : '';
    }

    /** "90", "90s", "1m30s", "1h2m3s" as seconds. */
    private static function youtubeTime(mixed $t): int
    {
        if (!is_string($t) || $t === '') {
            return 0;
        }
        if (ctype_digit($t)) {
            return min(self::MAX_SECONDS, (int) $t);
        }
        if (preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $t, $m) === 1) {
            return min(self::MAX_SECONDS, (int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0));
        }
        return 0;
    }
}
