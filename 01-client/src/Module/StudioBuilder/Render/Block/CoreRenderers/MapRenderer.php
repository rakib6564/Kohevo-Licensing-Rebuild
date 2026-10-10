<?php
/**
 * Kohevo Studio — `core.map` renderer: an OpenStreetMap embed centred on a point.
 *
 * The frame address is built here from two validated numbers, never from author text.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class MapRenderer implements BlockRendererInterface
{
    public const HEIGHTS = ['sm', 'md', 'lg'];

    public function type(): string
    {
        return 'core.map';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $lat = $scope->prop('latitude');
        $lon = $scope->prop('longitude');
        if (!is_numeric($lat) || !is_numeric($lon)) {
            return '';
        }
        $lat = (float) $lat;
        $lon = (float) $lon;
        if ($lat < -85.0 || $lat > 85.0 || $lon < -180.0 || $lon > 180.0) {
            return '';
        }
        $zoom = (int) $scope->prop('zoom', 13);
        $zoom = max(1, min(19, $zoom));
        $height = $scope->string('height', 'md');
        $height = in_array($height, self::HEIGHTS, true) ? $height : 'md';
        $title = trim($scope->string('title', 'Map'));

        return '<div' . Html::classAttr(['sb-map', 'sb-map--' . $height]) . '>'
            . '<iframe src="' . Html::e(self::embedUrl($lat, $lon, $zoom)) . '" title="' . Html::e($title !== '' ? $title : 'Map')
            . '" loading="lazy" referrerpolicy="no-referrer"></iframe></div>';
    }

    /** The visible box is about two tiles wide at the chosen zoom, centred on the point. */
    public static function embedUrl(float $lat, float $lon, int $zoom): string
    {
        $lonSpan = 360.0 / (2 ** $zoom) * 2.0;
        $latSpan = $lonSpan * 0.55;
        $n = static fn (float $v): string => number_format($v, 5, '.', '');
        $bbox = $n(max(-180.0, $lon - $lonSpan / 2)) . ',' . $n(max(-85.0, $lat - $latSpan / 2)) . ',' . $n(min(180.0, $lon + $lonSpan / 2)) . ',' . $n(min(85.0, $lat + $latSpan / 2));
        return 'https://www.openstreetmap.org/export/embed.html?bbox=' . $bbox . '&layer=mapnik&marker=' . $n($lat) . ',' . $n($lon);
    }
}
