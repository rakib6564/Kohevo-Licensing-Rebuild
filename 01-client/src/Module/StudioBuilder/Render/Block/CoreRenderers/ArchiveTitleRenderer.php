<?php
/**
 * Kohevo Studio — `theme.archive_title` renderer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class ArchiveTitleRenderer implements BlockRendererInterface
{
    private const LEVELS = ['h1', 'h2', 'h3'];

    public function type(): string
    {
        return 'theme.archive_title';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $level = $scope->string('level', 'h1');
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'h1';
        }

        $archiveType = (string) $scope->context()->attribute('archive_type', '');
        $term        = (string) $scope->context()->attribute('term', '');
        $isSearch    = (bool) $scope->context()->attribute('is_search', false);
        $searchQuery = (string) $scope->context()->attribute('search_query', '');

        $title = $scope->string('title');
        if ($title === '') {
            if ($isSearch) {
                $title = $searchQuery !== '' ? "Search Results: {$searchQuery}" : 'Search';
            } elseif ($archiveType === 'category') {
                $title = "Category: {$term}";
            } elseif ($archiveType === 'tag') {
                $title = "Tag: #{$term}";
            } elseif ($archiveType === 'author') {
                $title = "Author: {$term}";
            } else {
                $title = 'Archive';
            }
        }

        return '<' . $level . ' class="sb-archive-title">' . Html::e($title) . '</' . $level . '>';
    }
}
