<?php
/**
 * Kohevo Studio — `core.button` renderer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class ButtonRenderer implements BlockRendererInterface
{
    private const VARIANTS = ['primary', 'secondary', 'outline', 'ghost'];

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
            return '';
        }
        $variant = $scope->string('variant', 'primary');
        if (!in_array($variant, self::VARIANTS, true)) {
            $variant = 'primary';
        }
        $class = 'sb-button sb-button--' . $variant . ($scope->bool('full_width') ? ' sb-button--full' : '');
        return '<p class="sb-button-row">' . Html::link($link, $class) . '</p>';
    }
}
