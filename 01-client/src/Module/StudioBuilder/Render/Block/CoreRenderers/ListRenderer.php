<?php
/**
 * Kohevo Studio — `core.list` renderer: a bulleted, numbered, ticked or plain list.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class ListRenderer implements BlockRendererInterface
{
    public const STYLES = ['bullet', 'number', 'check', 'none'];

    public function type(): string
    {
        return 'core.list';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $style = $scope->string('style', 'bullet');
        $style = in_array($style, self::STYLES, true) ? $style : 'bullet';

        $items = '';
        foreach ((array) $scope->prop('items', []) as $item) {
            $text = is_array($item) && is_string($item['text'] ?? null) ? trim($item['text']) : '';
            if ($text !== '') {
                $items .= '<li class="sb-list__item">' . Html::text($text) . '</li>';
            }
        }
        if ($items === '') {
            return '';
        }

        $tag = $style === 'number' ? 'ol' : 'ul';
        return '<' . $tag . Html::classAttr(['sb-list', 'sb-list--' . $style]) . '>' . $items . '</' . $tag . '>';
    }
}
