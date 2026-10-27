<?php
/**
 * Kohevo Studio — `layout.offcanvas` renderer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\Icon\IconLibrary;

final class OffcanvasRenderer implements BlockRendererInterface
{
    private const POSITIONS = ['left', 'right', 'top', 'bottom'];
    private const VARIANTS = ['primary', 'secondary', 'outline', 'ghost', 'plain'];

    public function type(): string
    {
        return 'layout.offcanvas';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $rawId = $scope->string('drawer_id', 'drawer-1');
        $drawerId = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawId) ?: 'drawer-1';
        $title = $scope->string('title', 'Menu');
        $position = $scope->string('position', 'right');
        if (!in_array($position, self::POSITIONS, true)) {
            $position = 'right';
        }

        $html = $this->trigger($scope, $drawerId);

        $offcanvasClasses = ['sb-offcanvas', 'sb-offcanvas--' . $position];
        // Without a title the header keeps only the close button and the dialog is named by its aria-label.
        $named = trim($title) !== ''
            ? ' aria-labelledby="' . Html::e($drawerId) . '-title"'
            : ' aria-label="' . Html::e(Html::t('studio_menu', 'Menu')) . '"';
        $html .= '<div' . Html::classAttr($offcanvasClasses) . ' id="' . Html::e($drawerId) . '" role="dialog"' . $named . ' aria-modal="true" aria-hidden="true">'
            . '<a href="#" class="sb-offcanvas__backdrop" data-sb-offcanvas-close aria-label="' . Html::e(Html::t('studio_close', 'Close')) . '"></a>'
            . '<aside class="sb-offcanvas__panel">'
            . '<header' . Html::classAttr(['sb-offcanvas__header', trim($title) === '' ? 'sb-offcanvas__header--bare' : '']) . '>'
            . (trim($title) !== '' ? '<h3 id="' . Html::e($drawerId) . '-title">' . Html::e($title) . '</h3>' : '')
            . '<a href="#" class="sb-offcanvas__close" data-sb-offcanvas-close aria-label="' . Html::e(Html::t('studio_close', 'Close')) . '">&times;</a>'
            . '</header>'
            . '<div class="sb-offcanvas__body">'
            . $scope->childrenHtml()
            . '</div>'
            . '</aside>'
            . '</div>';

        return $html;
    }

    /**
     * The link that opens the drawer: its text, an icon, or both. With the text hidden it still names the
     * link for screen readers, so an icon-only trigger (a hamburger) is never an unlabelled control.
     */
    private function trigger(BlockRenderScope $scope, string $drawerId): string
    {
        $text = $scope->string('trigger_text');
        $icon = $scope->string('trigger_icon', 'none');
        $icon = $icon !== 'none' && IconLibrary::has($icon) ? $icon : '';
        if ($text === '' && $icon === '') {
            return '';
        }
        $variant = $scope->string('trigger_variant', 'outline');
        $variant = in_array($variant, self::VARIANTS, true) ? $variant : 'outline';
        $showText = $icon === '' || $scope->bool('trigger_show_text', true);
        $label = $text !== '' ? $text : Html::t('studio_open_menu', 'Open menu');

        $inner = '';
        if ($icon !== '') {
            $size = $scope->prop('trigger_icon_size');
            $size = is_int($size) && $size >= 12 && $size <= 64 ? $size : 24;
            $inner .= '<svg class="sb-offcanvas-trigger__icon" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
                . IconLibrary::markup($icon) . '</svg>';
        }
        if ($showText && $text !== '') {
            $inner .= '<span class="sb-offcanvas-trigger__label">' . Html::e($text) . '</span>';
        }
        $classes = ['sb-offcanvas-trigger', 'sb-button', 'sb-button--' . $variant, $icon !== '' ? 'sb-offcanvas-trigger--icon' : ''];
        return '<a href="#' . Html::e($drawerId) . '"' . Html::classAttr($classes) . ' data-sb-offcanvas-open="' . Html::e($drawerId) . '"'
            . (!$showText || $text === '' ? ' aria-label="' . Html::e($label) . '"' : '')
            . ' aria-controls="' . Html::e($drawerId) . '">'
            . $inner
            . '</a>';
    }
}
