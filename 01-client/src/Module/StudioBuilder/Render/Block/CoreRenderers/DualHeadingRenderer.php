<?php
/**
 * Kohevo Studio — `core.dual_heading` renderer: a heading with a coloured part between two plain parts.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class DualHeadingRenderer implements BlockRendererInterface
{
    private const LEVELS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p'];

    public function type(): string
    {
        return 'core.dual_heading';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $level = in_array($scope->string('level', 'h2'), self::LEVELS, true) ? $scope->string('level', 'h2') : 'h2';
        $parts = [];
        foreach (['before' => 'sb-dual-heading__text', 'colored' => 'sb-dual-heading__highlight', 'after' => 'sb-dual-heading__text'] as $prop => $class) {
            if ($scope->string($prop) !== '') {
                $parts[] = '<span class="' . $class . '">' . Html::headingText($scope->string($prop)) . '</span>';
            }
        }
        if ($parts === []) {
            return '';
        }
        $text = implode(' ', $parts);
        $url = Html::safeUrl($scope->prop('url'));
        if ($url !== null) {
            $text = '<a class="sb-heading__link" href="' . Html::e($url) . '"' . ($scope->bool('new_tab') ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $text . '</a>';
        }
        return '<' . $level . ' class="sb-heading sb-dual-heading">' . $text . '</' . $level . '>';
    }
}
