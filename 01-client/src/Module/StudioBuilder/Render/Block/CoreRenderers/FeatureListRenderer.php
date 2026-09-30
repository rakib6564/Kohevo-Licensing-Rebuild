<?php
/**
 * Kohevo Studio — `core.feature_list` renderer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class FeatureListRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'core.feature_list';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $title   = $scope->string('title');
        $columns = $scope->prop('columns', 3);
        $columns = is_int($columns) ? max(1, min(4, $columns)) : 3;
        $options = $scope->prop('card_options', []);
        $options = is_array($options) ? $options : [];
        $bordered = ($options['bordered'] ?? true) === true;
        $surface  = $scope->tokenClass('bg', $options['surface_token'] ?? null);

        $items = '';
        foreach ((array) $scope->prop('items', []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $heading = is_string($item['heading'] ?? null) ? $item['heading'] : '';
            $body    = is_string($item['body'] ?? null) ? $item['body'] : '';
            $url     = Html::safeUrl($item['url'] ?? null);
            $items  .= '<li' . Html::classAttr(['sb-feature', $bordered ? 'sb-feature--bordered' : '', $surface]) . '>'
                . '<h3 class="sb-feature__heading">' . Html::e($heading) . '</h3>'
                . ($body !== '' ? '<p class="sb-feature__body">' . Html::text($body) . '</p>' : '')
                . ($url !== null ? '<p><a class="sb-feature__link" href="' . Html::e($url) . '">' . Html::e(Html::t('studio_learn_more', 'Learn more')) . '</a></p>' : '')
                . '</li>';
        }

        return '<div class="sb-feature-list">'
            . ($title !== '' ? '<h2 class="sb-feature-list__title">' . Html::e($title) . '</h2>' : '')
            . ($items !== '' ? '<ul' . Html::classAttr(['sb-feature-list__items', 'sb-cols-1', 'sb-md-cols-' . $columns]) . '>' . $items . '</ul>' : '')
            . '</div>';
    }
}
