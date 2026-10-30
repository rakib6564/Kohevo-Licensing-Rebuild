<?php
/**
 * Kohevo Studio — `core.audio` renderer.
 *
 * A podcast or radio card: title, subtitle and a play button.
 *
 * A SoundCloud or Spotify link stays out of the page until the visitor asks for it: the provider's player waits inside
 * a `<template>` and studio-runtime.js swaps it in on click, so nothing loads from the provider before then. A
 * self-hosted file plays in an `<audio>` element driven by the card's own button, progress bar and time. The link to
 * the source is always there, which is also what a visitor without JavaScript uses.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class AudioRenderer implements BlockRendererInterface
{
    public const SOURCES = ['soundcloud', 'spotify', 'hosted'];

    /** A self-hosted file the browser can play in an `<audio>` element. */
    private const FILE_PATTERN = '/\.(mp3|m4a|aac|ogg|oga|opus|wav)$/i';

    private const LABELS = ['soundcloud' => 'SoundCloud', 'spotify' => 'Spotify'];

    /** The height each provider's compact player needs, in pixels. */
    private const HEIGHTS = ['soundcloud' => 166, 'spotify' => 152];

    private const PLAY_ICON = '<svg viewBox="0 0 24 24" width="24" height="24" focusable="false" aria-hidden="true"><path fill="currentColor" d="M8 5v14l11-7z"/></svg>';
    private const PAUSE_ICON = '<svg viewBox="0 0 24 24" width="24" height="24" focusable="false" aria-hidden="true"><path fill="currentColor" d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>';

    public function type(): string
    {
        return 'core.audio';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $source = $scope->string('source', 'soundcloud');
        $source = in_array($source, self::SOURCES, true) ? $source : 'soundcloud';
        $title = trim($scope->string('title'));
        $subtitle = trim($scope->string('subtitle'));
        $url = trim($scope->string($source === 'hosted' ? 'hosted_url' : 'url'));

        $embed = $source === 'hosted' ? null : self::embedUrl($source, $url);
        $file = $source === 'hosted' && self::isFile($url) ? $url : null;
        if ($embed === null && $file === null) {
            return '<div class="sb-audio sb-audio--empty">' . Html::e(Html::t('studio_audio_empty', 'Add an audio link in the settings.')) . '</div>';
        }

        $name = $title !== '' ? $title : Html::t('studio_audio', 'Audio');
        $play = sprintf(Html::t('studio_audio_play', 'Listen: %s'), $name);
        $text = ($title !== '' ? '<p class="sb-audio__title">' . Html::e($title) . '</p>' : '')
            . ($subtitle !== '' ? '<p class="sb-audio__subtitle">' . Html::e($subtitle) . '</p>' : '');
        $head = '<div class="sb-audio__head"><div class="sb-audio__text">' . $text . '</div>'
            . '<button type="button" class="sb-audio__play" data-sb-audio-play aria-label="' . Html::e($play) . '">'
            . '<span class="sb-audio__icon-play">' . self::PLAY_ICON . '</span><span class="sb-audio__icon-pause">' . self::PAUSE_ICON . '</span></button></div>';

        if ($file !== null) {
            return '<div class="sb-audio sb-audio--hosted" data-sb-audio>' . $head
                . '<audio src="' . Html::e($file) . '" preload="none" data-sb-audio-file></audio>'
                . '<div class="sb-audio__bar"><input type="range" class="sb-audio__seek" data-sb-audio-seek min="0" max="100" step="0.1" value="0" aria-label="' . Html::e(Html::t('studio_audio_seek', 'Position')) . '">'
                . '<span class="sb-audio__time" data-sb-audio-time>0:00 / 0:00</span></div></div>';
        }

        $link = sprintf(Html::t('studio_audio_open', 'Listen on %s'), self::LABELS[$source]);
        return '<div class="sb-audio sb-audio--' . $source . '" data-sb-audio>' . $head
            . '<template data-sb-audio-player><iframe class="sb-audio__embed" src="' . Html::e((string) $embed) . '" height="' . self::HEIGHTS[$source] . '"'
            . ' title="' . Html::e($name) . '" allow="autoplay; clipboard-write; encrypted-media" referrerpolicy="strict-origin-when-cross-origin"></iframe></template>'
            . '<a class="sb-audio__link" href="' . Html::e($url) . '" target="_blank" rel="noopener noreferrer">' . Html::e($link) . '</a></div>';
    }

    /** The provider's player for a public link, or null for any other address. */
    public static function embedUrl(string $source, string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($source === 'soundcloud') {
            if (!in_array($host, ['soundcloud.com', 'www.soundcloud.com'], true) || $path === '') {
                return null;
            }
            return 'https://w.soundcloud.com/player/?' . http_build_query(['url' => 'https://soundcloud.com' . $path, 'auto_play' => 'true', 'visual' => 'false', 'show_comments' => 'false', 'show_teaser' => 'false']);
        }
        if ($host !== 'open.spotify.com' || preg_match('#^(?:/intl-[a-z]{2})?/(episode|show|track|album|playlist)/([A-Za-z0-9]{10,40})$#', $path, $m) !== 1) {
            return null;
        }
        return 'https://open.spotify.com/embed/' . $m[1] . '/' . $m[2];
    }

    private static function isFile(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $scheme = parse_url($url, PHP_URL_SCHEME);
        return preg_match(self::FILE_PATTERN, $path) === 1 && (is_string($scheme) ? in_array($scheme, ['http', 'https'], true) : str_starts_with($url, '/'));
    }
}
