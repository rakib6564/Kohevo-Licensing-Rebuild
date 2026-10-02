<?php
/**
 * Kohevo Studio — `core.video` renderer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class VideoRenderer implements BlockRendererInterface
{
    private const ASPECTS = ['16:9', '4:3', '1:1'];

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
        $url = trim($scope->string('url'));
        $aspect = $scope->string('aspect_ratio', '16:9');
        if (!in_array($aspect, self::ASPECTS, true)) {
            $aspect = '16:9';
        }

        $autoplay = $scope->bool('autoplay', false);
        $controls = $scope->bool('controls', true);
        $muted    = $scope->bool('muted', false);
        $loop     = $scope->bool('loop', false);

        $embedUrl = self::buildEmbedUrl($url, $autoplay, $controls, $muted, $loop);
        if ($embedUrl === null) {
            return '<div class="sb-video-wrapper sb-video--aspect-' . str_replace(':', '-', $aspect) . '"><div class="sb-video__placeholder" style="background:#1f2937;color:#9ca3af;display:flex;align-items:center;justify-content:center;height:100%;">Video unavailable</div></div>';
        }

        $classes = ['sb-video-wrapper', 'sb-video--aspect-' . str_replace(':', '-', $aspect)];

        if (str_ends_with(parse_url($embedUrl, PHP_URL_PATH) ?? '', '.mp4') || str_ends_with(parse_url($embedUrl, PHP_URL_PATH) ?? '', '.webm')) {
            $attrs = ' src="' . Html::e($embedUrl) . '"';
            if ($controls) {
                $attrs .= ' controls';
            }
            if ($autoplay) {
                $attrs .= ' autoplay';
            }
            if ($muted) {
                $attrs .= ' muted';
            }
            if ($loop) {
                $attrs .= ' loop';
            }
            return '<div' . Html::classAttr($classes) . '><video' . $attrs . ' preload="metadata"></video></div>';
        }

        return '<div' . Html::classAttr($classes) . '>'
            . '<iframe src="' . Html::e($embedUrl) . '" loading="lazy" title="Video" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>'
            . '</div>';
    }

    public static function buildEmbedUrl(string $url, bool $autoplay, bool $controls, bool $muted, bool $loop): ?string
    {
        if ($url === '') {
            return null;
        }

        $parsed = parse_url($url);
        if (!is_array($parsed) || empty($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parsed['host'] ?? '');
        $path = $parsed['path'] ?? '';

        // YouTube
        if (str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be')) {
            $videoId = '';
            if (str_contains($host, 'youtu.be')) {
                $videoId = ltrim($path, '/');
            } else {
                parse_str($parsed['query'] ?? '', $query);
                $videoId = (string) ($query['v'] ?? '');
                if ($videoId === '' && str_starts_with($path, '/embed/')) {
                    $videoId = substr($path, 7);
                }
            }
            $cleanId = preg_replace('/[^a-zA-Z0-9_-]/', '', $videoId);
            if ($cleanId === '') {
                return null;
            }
            $params = [
                'autoplay' => $autoplay ? '1' : '0',
                'controls' => $controls ? '1' : '0',
                'mute'     => $muted ? '1' : '0',
                'loop'     => $loop ? '1' : '0',
            ];
            if ($loop) {
                $params['playlist'] = $cleanId;
            }
            return 'https://www.youtube-nocookie.com/embed/' . $cleanId . '?' . http_build_query($params);
        }

        // Vimeo
        if (str_contains($host, 'vimeo.com')) {
            $videoId = preg_replace('/[^0-9]/', '', $path);
            if ($videoId === '') {
                return null;
            }
            $params = [
                'autoplay' => $autoplay ? '1' : '0',
                'muted'    => $muted ? '1' : '0',
                'loop'     => $loop ? '1' : '0',
            ];
            return 'https://player.vimeo.com/video/' . $videoId . '?' . http_build_query($params);
        }

        // Direct video link (e.g. mp4, webm)
        if (preg_match('/\.(mp4|webm|ogg)$/i', $path) === 1) {
            return $url;
        }

        return null;
    }
}
