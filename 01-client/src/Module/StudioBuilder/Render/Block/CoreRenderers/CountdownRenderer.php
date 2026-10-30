<?php
/**
 * Kohevo Studio — `core.countdown` renderer.
 *
 * The page is compiled once and cached, so the markup must not depend on "now". It carries the target in
 * `data-sb-countdown` for the runtime and a fixed, language-neutral date line that is all a visitor sees
 * with JavaScript off. The live units stay hidden until the runtime marks the element live.
 *
 * An evergreen timer has no date: it carries its length, and the runtime starts it on each visitor's first view. What
 * happens at the end (a message, hiding, a redirect) is the runtime's too, on the public page only, so an author can
 * still see and edit a countdown that has already ended.
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

    public const TYPES = ['due_date', 'evergreen'];
    public const VIEWS = ['block', 'inline'];
    public const EXPIRE_ACTIONS = ['message', 'hide', 'redirect'];
    public const MESSAGE_ALIGNS = ['left', 'center', 'right', 'justify'];
    public const MAX_HOURS = 8760;

    /** The units: runtime key, visibility prop, custom label prop, language key, English name. */
    private const UNITS = [
        ['d', 'show_days', 'label_days', 'studio_countdown_days', 'Days'],
        ['h', 'show_hours', 'label_hours', 'studio_countdown_hours', 'Hours'],
        ['m', 'show_minutes', 'label_minutes', 'studio_countdown_minutes', 'Minutes'],
        ['s', 'show_seconds', 'label_seconds', 'studio_countdown_seconds', 'Seconds'],
    ];

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
        $evergreen = $scope->string('countdown_type') === 'evergreen';
        $moment = $evergreen ? null : self::moment($scope->string('target'));
        $length = $evergreen ? self::seconds($scope) : 0;
        if ($moment === null && $length === 0) {
            return '';
        }
        $target = $moment?->format('Y-m-d\TH:i:s\Z') ?? '';
        $label  = trim($scope->string('label'));
        $public = $scope->mode()->isPublic();

        $action = $scope->string('expire_action', 'message');
        $action = in_array($action, self::EXPIRE_ACTIONS, true) ? $action : 'message';
        $done = $action === 'message' ? trim($scope->string('done_text')) : '';

        $attributes = ' data-sb-countdown="' . Html::e($target) . '"';
        if ($evergreen) {
            // Only the public page keeps a visitor's start time: the canvas and previews restart on every load.
            $attributes .= ' data-sb-countdown-evergreen="' . $length . '"' . ($public ? ' data-sb-countdown-key="' . Html::e($scope->nodeId()) . '"' : '');
        }
        if ($public && $action === 'hide') {
            $attributes .= ' data-sb-countdown-expire="hide"';
        }
        $redirect = Html::safeUrl($scope->string('redirect_url'));
        if ($public && $action === 'redirect' && $redirect !== null) {
            $attributes .= ' data-sb-countdown-expire="redirect" data-sb-href="' . Html::e($redirect) . '"';
        }

        $classes = ['sb-countdown'];
        if ($scope->string('view') === 'inline') {
            $classes[] = 'sb-countdown--inline';
        }
        $align = $scope->string('message_align');
        if ($done !== '' && $align !== 'left' && in_array($align, self::MESSAGE_ALIGNS, true)) {
            $classes[] = 'sb-countdown--done-' . $align;
        }

        $showLabels = $scope->bool('show_labels', true);
        $custom = $scope->bool('custom_labels', false);
        $units = '';
        foreach (self::UNITS as [$key, $show, $custom_key, $i18n, $name]) {
            if (!$scope->bool($show, true)) {
                continue;
            }
            $text = $custom ? trim($scope->string($custom_key)) : '';
            $units .= '<div class="sb-countdown__unit"><span class="sb-countdown__num" data-sb-cd="' . $key . '">00</span>'
                . ($showLabels ? '<span class="sb-countdown__name">' . Html::e($text !== '' ? $text : Html::t($i18n, $name)) . '</span>' : '')
                . '</div>';
        }

        return '<div' . Html::classAttr($classes) . $attributes . '>'
            . ($label !== '' ? '<p class="sb-countdown__label">' . Html::e($label) . '</p>' : '')
            . ($moment !== null ? '<p class="sb-countdown__date"><time datetime="' . Html::e($target) . '">' . Html::e($moment->format('Y-m-d H:i') . ' UTC') . '</time></p>' : '')
            . '<div class="sb-countdown__units" role="timer" aria-live="off">' . $units . '</div>'
            . ($done !== '' ? '<p class="sb-countdown__done">' . Html::e($done) . '</p>' : '')
            . '</div>';
    }

    /** The due date in UTC, or null when it is not a real moment. */
    private static function moment(string $target): ?\DateTimeImmutable
    {
        if (preg_match(self::TARGET_PATTERN, $target) !== 1) {
            return null;
        }
        try {
            $moment = new \DateTimeImmutable($target);
        } catch (\Exception $e) {
            return null;
        }
        if ($moment->format('Y-m-d') !== substr($target, 0, 10)) {
            return null; // 2030-02-31 and friends: PHP would roll them into the next month, so refuse instead
        }
        return $moment->setTimezone(new \DateTimeZone('UTC'));
    }

    /** An evergreen timer's length in seconds. */
    private static function seconds(BlockRenderScope $scope): int
    {
        $number = static fn(string $key, int $default, int $max): int => is_numeric($scope->prop($key, $default)) ? max(0, min($max, (int) $scope->prop($key, $default))) : $default;
        return $number('evergreen_hours', 47, self::MAX_HOURS) * 3600 + $number('evergreen_minutes', 59, 59) * 60;
    }
}
