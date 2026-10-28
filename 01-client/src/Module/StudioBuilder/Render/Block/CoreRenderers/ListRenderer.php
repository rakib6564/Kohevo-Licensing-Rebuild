<?php
/**
 * Kohevo Studio — `core.list` renderer: a bulleted, numbered, ticked, dashed or plain list, stacked (in up to four
 * columns, set per device) or inline, with lines between the items if asked.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\DeviceColumns;
use Slate\Module\StudioBuilder\Render\Html;

final class ListRenderer implements BlockRendererInterface
{
    public const STYLES = ['bullet', 'number', 'check', 'dash', 'none'];

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

        $classes = ['sb-list', 'sb-list--' . $style];
        $vars = '';
        if ($scope->string('layout', 'stacked') === 'inline') {
            $classes[] = 'sb-list--inline';
        } else {
            $cols = (int) $scope->prop('columns', 1);
            $cols = $cols < 1 || $cols > 4 ? 1 : $cols;
            // Columns stay on a tablet and stack on a phone, unless the author set either device.
            if ($cols > 1 || $scope->hasDeviceValue('columns')) {
                $devices = $scope->deviceCounts('columns', 1, 4, $cols, 1);
                array_push($classes, 'sb-list--cols', 'sb-dcols');
                $vars = ' style="' . DeviceColumns::vars('dc', $cols, $devices['tablet'], $devices['mobile']) . '"';
            }
        }
        if ($scope->bool('divider')) {
            $classes[] = 'sb-list--divided';
        }
        $tag = $style === 'number' ? 'ol' : 'ul';
        return '<' . $tag . Html::classAttr($classes) . $vars . '>' . $items . '</' . $tag . '>';
    }
}
