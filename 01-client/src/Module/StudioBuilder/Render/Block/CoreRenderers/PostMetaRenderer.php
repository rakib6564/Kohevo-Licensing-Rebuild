<?php
/**
 * Kohevo Studio — `theme.post_meta` renderer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class PostMetaRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'theme.post_meta';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $showAuthor   = $scope->bool('show_author', true);
        $showDate     = $scope->bool('show_date', true);
        $showCategory = $scope->bool('show_category', true);
        $separator    = $scope->string('separator', ' • ');

        $author = (string) ($scope->prop('author_name') ?? $scope->context()->attribute('author_name', 'Author'));
        $date   = (string) ($scope->prop('published_at') ?? $scope->context()->attribute('date', $scope->context()->attribute('published_at', date('M j, Y'))));
        $cat    = (string) ($scope->prop('category') ?? $scope->context()->attribute('category', ''));

        $items = [];
        if ($showAuthor && $author !== '') {
            $items[] = '<span class="sb-meta-author">' . Html::e('By ' . $author) . '</span>';
        }
        if ($showDate && $date !== '') {
            $items[] = '<span class="sb-meta-date">' . Html::e($date) . '</span>';
        }
        if ($showCategory && $cat !== '') {
            $items[] = '<span class="sb-meta-category">' . Html::e($cat) . '</span>';
        }

        if (empty($items)) {
            return '';
        }

        return '<div class="sb-post-meta">' . implode(Html::e($separator), $items) . '</div>';
    }
}
