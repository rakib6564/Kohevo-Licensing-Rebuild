<?php
/**
 * Kohevo Studio — what `core.nav_menu` and `layout.mega_menu` share: the hover pointer and the menu button.
 *
 * The toggle's look lives in StudioStylesheet::navWidgetCss() and its behaviour in studio-runtime.js (initNavToggles); both
 * read only the classes and markup built here, so each menu gets the dropdown, side panel and full screen modes alike.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class NavChrome
{
    public const POINTERS   = ['none', 'underline', 'overline', 'double_line', 'framed', 'background', 'text'];
    public const ANIMATIONS = ['none', 'fade', 'slide', 'grow'];
    public const TOGGLES    = ['none', 'tablet', 'mobile', 'always'];
    public const MODES      = ['dropdown', 'offcanvas', 'fullscreen'];
    public const ICONS      = ['hamburger', 'dots', 'text_icon'];
    public const ALIGNS     = ['left', 'center', 'right'];
    public const OPENINGS   = ['none', 'fade', 'slide'];

    /** @param list<string> $allowed */
    public static function pick(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * The pointer's classes and the `style` attribute with its colour (empty without one).
     *
     * @return array{0: list<string>, 1: string}
     */
    public static function pointer(BlockRenderScope $scope): array
    {
        $pointer = self::pick($scope->string('pointer', 'none'), self::POINTERS, 'none');
        if ($pointer === 'none') {
            return [[], ''];
        }
        $color = $scope->tokenVar($scope->prop('pointer_color'));
        return [
            ['sb-nav--pointer-' . $pointer, 'sb-nav--anim-' . self::pick($scope->string('pointer_animation', 'fade'), self::ANIMATIONS, 'fade')],
            $color !== '' ? ' style="--sb-nav-pointer:' . Html::e($color) . '"' : '',
        ];
    }

    /**
     * The menu button's classes and its markup; both empty when the menu never folds.
     *
     * @return array{0: list<string>, 1: string}
     */
    public static function toggle(BlockRenderScope $scope, string $listId): array
    {
        $toggle = self::pick($scope->string('toggle_breakpoint', 'none'), self::TOGGLES, 'none');
        if ($toggle === 'none') {
            return [[], ''];
        }
        $mode = self::pick($scope->string('toggle_mode', 'dropdown'), self::MODES, 'dropdown');
        $classes = ['sb-nav--toggle-' . $toggle, 'sb-nav--menu-' . $mode, 'sb-nav--tgl-' . self::pick($scope->string('toggle_align', 'right'), self::ALIGNS, 'right')];
        if ($mode === 'dropdown' && $scope->bool('toggle_full_width', true)) {
            $classes[] = 'sb-nav--menu-full';
        }
        $opening = self::pick($scope->string('toggle_animation', 'none'), self::OPENINGS, 'none');
        if ($opening !== 'none') {
            $classes[] = 'sb-nav--open-' . $opening;
        }
        return [$classes, self::bar($scope, $listId)];
    }

    /** The button shown at the end of a folded menu ('' without a label and a usable address). */
    public static function cta(BlockRenderScope $scope, string $tag): string
    {
        $label = trim($scope->string('toggle_cta_label'));
        if ($label === '' || self::pick($scope->string('toggle_breakpoint', 'none'), self::TOGGLES, 'none') === 'none') {
            return '';
        }
        $link = Html::link(['href' => $scope->string('toggle_cta_url'), 'label' => $label, 'target' => '_self'], 'sb-nav__cta-link');
        return str_starts_with($link, '<a') ? '<' . $tag . ' class="sb-nav__item sb-nav__cta">' . $link . '</' . $tag . '>' : '';
    }

    /**
     * The menu button. The button's `tabindex` keeps a page without scripts to one stop (the checkbox); the runtime removes it.
     * Both controls are named by the label, which only shows with the "text and icon" button. The backdrop of an off-canvas
     * panel is the checkbox's label, so it closes the panel without scripts too.
     */
    private static function bar(BlockRenderScope $scope, string $listId): string
    {
        $label = trim($scope->string('toggle_label', 'Menu'));
        $label = $label !== '' ? $label : Html::t('studio_menu', 'Menu');
        $icon = self::pick($scope->string('toggle_icon', 'hamburger'), self::ICONS, 'hamburger');
        $checkId = $listId . '-open';
        return '<div class="sb-nav__bar"><span>'
            . '<input type="checkbox" class="sb-nav__check" id="' . Html::e($checkId) . '" aria-label="' . Html::e($label) . '">'
            . '<button type="button" class="sb-nav__burger" tabindex="-1" aria-expanded="false" aria-controls="' . Html::e($listId) . '"'
            . ($icon === 'text_icon' ? '>' : ' aria-label="' . Html::e($label) . '">')
            . '<span class="sb-nav__icon sb-nav__icon--' . ($icon === 'dots' ? 'dots' : 'hamburger') . '" aria-hidden="true"></span>'
            . ($icon === 'text_icon' ? '<span class="sb-nav__burger-label">' . Html::e($label) . '</span>' : '')
            . '</button></span></div>'
            . '<label class="sb-nav__backdrop" for="' . Html::e($checkId) . '" aria-hidden="true"></label>';
    }
}
