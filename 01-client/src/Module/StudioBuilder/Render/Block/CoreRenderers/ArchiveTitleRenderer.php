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
            // `%s` is replaced, not sprintf'd, so a stray % in a translation or a term cannot break the title.
            $fill = static fn(string $key, string $english, string $value): string => str_replace('%s', $value, Html::t($key, $english));
            if ($isSearch) {
                $title = $searchQuery !== '' ? $fill('studio_archive_search_results', 'Search Results: %s', $searchQuery) : Html::t('studio_archive_search', 'Search');
            } elseif ($archiveType === 'category') {
                $title = $fill('studio_archive_category', 'Category: %s', $term);
            } elseif ($archiveType === 'tag') {
                $title = $fill('studio_archive_tag', 'Tag: #%s', $term);
            } elseif ($archiveType === 'author') {
                $title = $fill('studio_archive_author', 'Author: %s', $term);
            } else {
                $title = Html::t('studio_archive', 'Archive');
            }
        }

        return '<' . $level . ' class="sb-archive-title">' . Html::e($title) . '</' . $level . '>';
    }
}
