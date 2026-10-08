<?php
/**
 * Kohevo Studio — `core.link` renderer: a plain text link (no button chrome).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class LinkRenderer implements BlockRendererInterface
{
    public const STYLES = ['underline', 'plain', 'arrow'];
    public const ALIGNS = ['left', 'center', 'right'];

    public function type(): string
    {
        return 'core.link';
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
        $style = $scope->string('style', 'underline');
        $style = in_array($style, self::STYLES, true) ? $style : 'underline';
        $align = $scope->string('align', 'left');
        $align = in_array($align, self::ALIGNS, true) ? $align : 'left';

        $anchor = Html::link($link, 'sb-link sb-link--' . $style);
        if ($anchor === '') {
            return '';
        }
        return '<p' . Html::classAttr(['sb-link-row', 'sb-link-row--' . $align]) . '>' . $anchor . '</p>';
    }
}
