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

    /** Dynamic: it shows the visitor's own query, which must never be baked into a page every visitor shares. */
    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $placeholder = $scope->string('placeholder', 'Search articles and resources...');
        $buttonText  = $scope->string('button_text', 'Search');
        $query       = (string) $scope->context()->attribute('search_query', '');

        // The site's own search address, so an install in a folder (`/site/search`) searches itself.
        $action = $scope->site()->absoluteUrl('search');
        $name = $placeholder !== '' ? $placeholder : Html::t('studio_search', 'Search');

        return '<form class="sb-search-form" action="' . Html::e($action) . '" method="GET" role="search">'
            . '<div class="sb-search-input-wrap">'
            . '<input type="search" name="q" class="sb-search-input" placeholder="' . Html::e($placeholder) . '" aria-label="' . Html::e($name) . '" value="' . Html::e($query) . '" autocomplete="off">'
            . '<button type="submit" class="sb-search-button">' . Html::e($buttonText) . '</button>'
            . '</div>'
            . '</form>';
    }
}
