<?php
/**
 * Kohevo Studio — `core.countdown` renderer.
 *
 * The page is compiled once and cached, so the markup must not depend on "now". It carries the target in
 * `data-sb-countdown` for the runtime and a fixed, language-neutral date line that is all a visitor sees
 * with JavaScript off. The live units stay hidden until the runtime marks the element live.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class CountdownRenderer implements BlockRendererInterface
{
    /** An ISO 8601 date-time with an explicit zone, so the moment is unambiguous for every visitor. */
    public const TARGET_PATTERN = '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])T([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?(Z|[+-]([01]\d|2[0-3]):[0-5]\d)$/';

    public function type(): string
    {
        return 'core.countdown';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $target = $scope->string('target');
        if (preg_match(self::TARGET_PATTERN, $target) !== 1) {
            return '';
        }
        try {
            $moment = new \DateTimeImmutable($target);
        } catch (\Exception $e) {
            return '';
        }
        if ($moment->format('Y-m-d') !== substr($target, 0, 10)) {
            return ''; // 2030-02-31 and friends: PHP would roll them into the next month, so refuse instead
        }
        $utc   = $moment->setTimezone(new \DateTimeZone('UTC'));
        $label = trim($scope->string('label'));
        $done  = trim($scope->string('done_text'));

        $units = '';
        foreach ([['d', 'studio_countdown_days', 'Days'], ['h', 'studio_countdown_hours', 'Hours'], ['m', 'studio_countdown_minutes', 'Minutes'], ['s', 'studio_countdown_seconds', 'Seconds']] as [$key, $i18n, $name]) {
            $units .= '<div class="sb-countdown__unit"><span class="sb-countdown__num" data-sb-cd="' . $key . '">00</span>'
                . '<span class="sb-countdown__name">' . Html::e(Html::t($i18n, $name)) . '</span></div>';
        }

        return '<div class="sb-countdown" data-sb-countdown="' . Html::e($utc->format('Y-m-d\TH:i:s\Z')) . '">'
            . ($label !== '' ? '<p class="sb-countdown__label">' . Html::e($label) . '</p>' : '')
            . '<p class="sb-countdown__date"><time datetime="' . Html::e($utc->format('Y-m-d\TH:i:s\Z')) . '">' . Html::e($utc->format('Y-m-d H:i') . ' UTC') . '</time></p>'
            . '<div class="sb-countdown__units" role="timer" aria-live="off">' . $units . '</div>'
            . ($done !== '' ? '<p class="sb-countdown__done">' . Html::e($done) . '</p>' : '')
            . '</div>';
    }
}
