<?php
/**
 * Kohevo Studio — `core.quote` renderer: a pull quote with an optional attribution.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class QuoteRenderer implements BlockRendererInterface
{
    public const ALIGNS = ['left', 'center'];

    public function type(): string
    {
        return 'core.quote';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $text = trim($scope->string('text'));
        if ($text === '') {
            return '';
        }
        $align = $scope->string('align', 'left');
        $align = in_array($align, self::ALIGNS, true) ? $align : 'left';
        $author = trim($scope->string('author'));
        $role   = trim($scope->string('role'));

        $caption = '';
        if ($author !== '' || $role !== '') {
            $caption = '<figcaption class="sb-quote__caption">'
                . ($author !== '' ? '<span class="sb-quote__author">' . Html::e($author) . '</span>' : '')
                . ($role !== '' ? '<span class="sb-quote__role">' . Html::e($role) . '</span>' : '')
                . '</figcaption>';
        }

        return '<figure' . Html::classAttr(['sb-quote', 'sb-quote--' . $align]) . '>'
            . '<blockquote class="sb-quote__text"><p>' . Html::text($text) . '</p></blockquote>'
            . $caption
            . '</figure>';
    }
}
