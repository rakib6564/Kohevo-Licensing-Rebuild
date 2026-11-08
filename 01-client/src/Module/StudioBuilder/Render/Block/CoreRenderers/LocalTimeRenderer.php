<?php
/**
 * Kohevo Studio — `core.local_time` renderer.
 *
 * The time where the site's owner is ("Dhaka · 14:32"), ticking. The server writes the time at render, so a page
 * without JavaScript still shows one (as fresh as the page cache); the runtime keeps it current with Intl in the
 * block's time zone, so a visitor anywhere sees the owner's time, not their own.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class LocalTimeRenderer implements BlockRendererInterface
{
    public const FORMATS = ['24h', '12h'];

    public function type(): string
    {
        return 'core.local_time';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    /** The time in that zone as the block shows it (`14:32`, `2:32 PM`, with seconds when asked). */
    public static function format(\DateTimeImmutable $now, string $zone, bool $h12, bool $seconds): string
    {
        $local = $now->setTimezone(new \DateTimeZone($zone));
        return $local->format(($h12 ? 'g:i' : 'H:i') . ($seconds ? ':s' : '') . ($h12 ? ' A' : ''));
    }

    public function render(BlockRenderScope $scope): string
    {
        $zone = MessageWidgetRenderer::zone($scope->string('timezone'));
        $h12 = $scope->string('format', '24h') === '12h';
        $seconds = $scope->bool('seconds', false);
        $now = new \DateTimeImmutable('now');
        $label = $scope->string('label', '');
        $city = $scope->string('city', '');
        $local = $now->setTimezone(new \DateTimeZone($zone));

        return '<p class="sb-local-time">'
            . ($label !== '' ? '<span class="sb-local-time__label">' . Html::e($label) . '</span> ' : '')
            . ($city !== '' ? '<span class="sb-local-time__city">' . Html::e($city) . '</span> ' : '')
            . '<time class="sb-local-time__time" datetime="' . Html::e($local->format('H:i')) . '" data-sb-clock data-sb-tz="' . Html::e($zone) . '"'
            . ($h12 ? ' data-sb-h12' : '') . ($seconds ? ' data-sb-seconds' : '') . '>'
            . Html::e(self::format($now, $zone, $h12, $seconds))
            . '</time>'
            . ($scope->bool('show_offset', false) ? ' <span class="sb-local-time__zone">' . Html::e('GMT' . $local->format('P')) . '</span>' : '')
            . '</p>';
    }
}
