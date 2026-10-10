<?php
/**
 * Kohevo Studio — `core.divider` renderer: a horizontal rule with a line style, a thickness, a width and an alignment.
 * Every value is an allowlisted word (anything else falls back to the default), and the colour is the theme's border
 * colour unless the block's own border colour says otherwise (see StudioStylesheet).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class DividerRenderer implements BlockRendererInterface
{
    public const STYLES  = ['solid', 'dashed', 'dotted'];
    public const WEIGHTS = ['thin', 'medium', 'thick'];
    public const WIDTHS  = ['full', 'wide', 'narrow', 'short'];
    public const ALIGNS  = ['left', 'center', 'right'];

    public function type(): string
    {
        return 'core.divider';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $style  = self::pick($scope->string('style', 'solid'), self::STYLES, 'solid');
        $weight = self::pick($scope->string('weight', 'thin'), self::WEIGHTS, 'thin');
        $width  = self::pick($scope->string('width', 'full'), self::WIDTHS, 'full');
        $align  = self::pick($scope->string('align', 'center'), self::ALIGNS, 'center');

        return '<hr' . Html::classAttr(['sb-divider', 'sb-divider--' . $style, 'sb-divider--' . $weight, 'sb-divider--w-' . $width, 'sb-divider--' . $align]) . '>';
    }

    /** @param list<string> $allowed */
    private static function pick(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
