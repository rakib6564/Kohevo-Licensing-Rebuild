<?php
/**
 * Kohevo Studio — `theme.search_box` renderer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class SearchBoxRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'theme.search_box';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $placeholder = $scope->string('placeholder', 'Search articles and resources...');
        $buttonText  = $scope->string('button_text', 'Search');
        $query       = (string) $scope->context()->attribute('search_query', '');

        return '<form class="sb-search-form" action="/search" method="GET" role="search">'
            . '<div class="sb-search-input-wrap">'
            . '<input type="search" name="q" class="sb-search-input" placeholder="' . Html::e($placeholder) . '" value="' . Html::e($query) . '" autocomplete="off">'
            . '<button type="submit" class="sb-search-button">' . Html::e($buttonText) . '</button>'
            . '</div>'
            . '</form>';
    }
}
