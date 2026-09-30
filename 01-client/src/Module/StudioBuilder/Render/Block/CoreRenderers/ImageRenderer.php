<?php
/**
 * Kohevo Studio — `core.image` renderer.
 *
 * A media reference that no longer resolves for the current tenant (deleted,
 * foreign, non-image, unmanaged path) renders nothing publicly and a visible
 * notice in authoring contexts — never a guessed URL.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class ImageRenderer implements BlockRendererInterface
{
    private const RATIOS = ['auto' => 'auto', '16:9' => '16-9', '4:3' => '4-3', '1:1' => '1-1', '3:4' => '3-4'];

    public function type(): string
    {
        return 'core.image';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $ref   = $scope->prop('media');
        $image = $scope->image($ref);
        if ($image === null) {
            return $scope->showsDiagnostics()
                ? '<div class="sb-unavailable" role="note">' . Html::e(Html::t('studio_media_unavailable', 'Image unavailable')) . '</div>'
                : '';
        }

        $alt     = is_array($ref) && is_string($ref['alt'] ?? null) ? $ref['alt'] : '';
        $ratio   = self::RATIOS[$scope->string('aspect_ratio', 'auto')] ?? 'auto';
        $caption = $scope->string('caption');

        return '<figure' . Html::classAttr(['sb-image', 'sb-image--ratio-' . $ratio, $scope->bool('rounded', true) ? 'sb-image--rounded' : '']) . '>'
            . self::img($image->url, $alt, $image->width, $image->height, $ref)
            . ($caption !== '' ? '<figcaption>' . Html::e($caption) . '</figcaption>' : '')
            . '</figure>';
    }

    /**
     * @param mixed $ref normalized media_ref (focal point used for object-position)
     */
    public static function img(string $url, string $alt, ?int $width, ?int $height, mixed $ref, bool $lazy = true): string
    {
        $position = '';
        if (is_array($ref) && is_array($ref['focal_point'] ?? null) && count($ref['focal_point']) === 2) {
            [$x, $y] = $ref['focal_point'];
            if ((is_int($x) || is_float($x)) && (is_int($y) || is_float($y))) {
                $x = max(0.0, min(1.0, (float) $x));
                $y = max(0.0, min(1.0, (float) $y));
                // Only computed numbers reach this attribute — never authored text.
                $position = sprintf(' style="object-position:%s%% %s%%"', rtrim(rtrim(number_format($x * 100, 2, '.', ''), '0'), '.'), rtrim(rtrim(number_format($y * 100, 2, '.', ''), '0'), '.'));
            }
        }
        return '<img src="' . Html::e($url) . '" alt="' . Html::e($alt) . '"'
            . ($width !== null ? ' width="' . $width . '"' : '')
            . ($height !== null ? ' height="' . $height . '"' : '')
            . ($lazy ? ' loading="lazy"' : '') . ' decoding="async"' . $position . '>';
    }
}
