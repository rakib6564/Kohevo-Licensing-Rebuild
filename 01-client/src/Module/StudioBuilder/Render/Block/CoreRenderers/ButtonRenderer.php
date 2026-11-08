<?php
/**
 * Kohevo Studio — `core.button` renderer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\Icon\IconLibrary;

final class ButtonRenderer implements BlockRendererInterface
{
    private const VARIANTS = ['primary', 'secondary', 'outline', 'ghost'];
    private const SIZES    = ['sm', 'md', 'lg'];

    public function type(): string
    {
        return 'core.button';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $link = $scope->prop('link');
        if (!is_array($link)) {
            $href = $scope->string('url');
            $text = $scope->string('text');
            if ($href !== '' || $text !== '') {
                $link = ['href' => $href, 'label' => $text, 'target' => $scope->string('target', '_self')];
            } else {
                return '';
            }
        } else {
            $text = $scope->string('text');
            if ($text !== '' && empty($link['label'])) {
                $link['label'] = $text;
            }
            $target = $scope->string('target');
            if ($target !== '' && in_array($target, ['_self', '_blank'], true)) {
                $link['target'] = $target;
            }
        }
        $variant = $scope->string('variant', 'primary');
        if (!in_array($variant, self::VARIANTS, true)) {
            $variant = 'primary';
        }
        $size = $scope->string('size', 'md');
        if (!in_array($size, self::SIZES, true)) {
            $size = 'md';
        }
        $sizeClass = $size !== 'md' ? ' sb-button--' . $size : '';
        $class = 'sb-button sb-button--' . $variant . $sizeClass . ($scope->bool('full_width') ? ' sb-button--full' : '');
        $label = $this->withIcon($scope, (string) ($link['label'] ?? ''));
        $copy = trim($scope->string('copy_text'));
        if ($copy !== '') {
            // Copy to clipboard (studio-runtime.js): the button keeps its link, which is what a visitor without script gets.
            $attrs = ' data-sb-copy="' . Html::e($copy) . '" data-sb-copied="' . Html::e(trim($scope->string('copied_label', 'Copied')) ?: 'Copied') . '"';
            $html = Html::link($link, $class, $label);
            if (str_starts_with($html, '<a ')) {
                return '<p class="sb-button-row">' . '<a' . $attrs . substr($html, 2) . '</p>';
            }
            // No address: a real button, which only copies.
            $text = $label ?? Html::e((string) ($link['label'] ?? ''));
            return '<p class="sb-button-row"><button type="button" class="' . $class . '"' . $attrs . '>' . $text . '</button></p>';
        }
        return '<p class="sb-button-row">' . Html::link($link, $class, $label) . '</p>';
    }

    /** The label with its icon before or after it, `$spacing` px apart; null (the plain label) without an icon. */
    private function withIcon(BlockRenderScope $scope, string $label): ?string
    {
        $name = $scope->string('icon', 'none');
        if ($name === 'none' || !IconLibrary::has($name) || trim($label) === '') {
            return null;
        }
        $after = $scope->string('icon_position', 'before') === 'after';
        $spacing = $scope->prop('icon_spacing');
        $spacing = is_int($spacing) && $spacing >= 0 && $spacing <= 50 ? $spacing : 8;
        $icon = '<span class="sb-button__icon" aria-hidden="true" style="margin-' . ($after ? 'left' : 'right') . ':' . $spacing . 'px">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">'
            . IconLibrary::markup($name) . '</svg></span>';
        $text = '<span class="sb-button__label">' . Html::e($label) . '</span>';
        return $after ? $text . $icon : $icon . $text;
    }
}
