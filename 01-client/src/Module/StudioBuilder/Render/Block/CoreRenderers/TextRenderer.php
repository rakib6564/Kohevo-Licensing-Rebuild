<?php
/**
 * Kohevo Studio — `core.text` renderer.
 *
 * Typography text block supporting content, sizing scale, alignment, and color tokens.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class TextRenderer implements BlockRendererInterface
{
    private const SIZES = ['xs', 'sm', 'base', 'lg', 'xl', 'lead', 'inherit'];
    private const ALIGN = ['left', 'center', 'right', 'justify'];

    public function type(): string
    {
        return 'core.text';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $content = $scope->string('content', '');
        $size = $scope->string('size', 'base');
        if (!in_array($size, self::SIZES, true)) {
            $size = 'base';
        }

        $align = $scope->string('align', 'left');
        if (!in_array($align, self::ALIGN, true)) {
            $align = 'left';
        }

        $classes = [
            'sb-text',
            'sb-text--' . $size,
        ];
        // `left` is the default, so it adds no class: a class on the paragraph would beat the Style tab's Alignment on the wrapper.
        if ($align !== 'left') {
            $classes[] = 'sb-text--align-' . $align;
        }

        $colorToken = $scope->prop('color_token');
        if ($colorToken !== null) {
            $colorClass = $scope->tokenClass('fg', $colorToken);
            if ($colorClass !== '') {
                $classes[] = $colorClass;
            }
        }

        $flow = Html::textFlow($scope, $classes);
        if ($content === '' && !empty($scope->block()['dynamic']['content'])) {
            return ''; // a tag with nothing to show
        }
        $text = Html::text($content);
        $url = Html::safeUrl($scope->prop('url'));
        if ($url !== null) {
            $text = '<a class="sb-text__link" href="' . Html::e($url) . '"' . ($scope->bool('new_tab') ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $text . '</a>';
        }
        return '<p' . Html::classAttr($classes) . $flow . '>' . $text . '</p>';
    }
}
