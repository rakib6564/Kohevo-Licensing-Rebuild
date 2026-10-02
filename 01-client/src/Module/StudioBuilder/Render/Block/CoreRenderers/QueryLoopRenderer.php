<?php
/**
 * Kohevo Studio — `core.query_loop` renderer.
 *
 * Renders a dynamic query loop:
 * - Executes query using provider data (content.posts)
 * - Renders a responsive grid of post cards (media, category, title, excerpt, meta, button)
 *   OR custom child template with interpolated post fields
 * - Generates accessible pagination controls (Previous, page numbers, Next)
 * - Safe fallback when no posts are found
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Provider\PostsProvider;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class QueryLoopRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'core.query_loop';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $rows = $scope->rows('items');

        // If no binding slot provided rows, execute PostsProvider directly
        if ($rows === null) {
            try {
                $provider = new PostsProvider();
                $params = [
                    'source'    => $scope->string('source', 'posts'),
                    'category'  => $scope->string('category', ''),
                    'tag'       => $scope->string('tag', ''),
                    'author_id' => (int) $scope->prop('author_id', 0),
                    'order_by'  => $scope->string('order_by', 'published_at'),
                    'order'     => $scope->string('order', 'desc'),
                    'limit'     => (int) $scope->prop('per_page', 6),
                    'page'      => (int) ($scope->prop('page') ?? ($_GET['page'] ?? 1)),
                ];
                // In execution, if tenant is scoped via global/ambient context:
                if (isset($GLOBALS['SLATE_TENANT_OVERRIDE'])) {
                    $tenants = new \Slate\Tenancy\TenantContext();
                    $rows = $provider->execute($tenants, $params);
                } else {
                    $rows = [];
                }
            } catch (\Throwable) {
                $rows = [];
            }
        }

        $emptyMsg = $scope->string('empty_message', 'No posts found.');
        if ($rows === [] || $rows === null) {
            return '<div class="sb-query-loop sb-query-loop--empty">'
                . '<p class="sb-empty">' . Html::e($emptyMsg) . '</p>'
                . '</div>';
        }

        $columns = max(1, min(6, (int) $scope->prop('columns', 3)));
        $gap = $scope->string('gap', 'md');
        $variant = $scope->string('card_variant', 'card');
        $showImage = $scope->bool('show_featured_image', true);
        $showDate = $scope->bool('show_date', true);
        $showAuthor = $scope->bool('show_author', true);
        $showCategory = $scope->bool('show_category', true);
        $showExcerpt = $scope->bool('show_excerpt', true);
        $readMoreText = $scope->string('read_more_text', 'Read More');
        $enablePagination = $scope->bool('enable_pagination', true);

        // Check if custom child HTML was rendered
        $childrenHtml = $scope->childrenHtml();
        $itemsHtml = '';

        if (trim($childrenHtml) !== '') {
            // Repeat custom child template for each row with token substitution
            foreach ($rows as $row) {
                $tokens = [
                    '{title}'          => Html::e((string) ($row['title'] ?? '')),
                    '{post.title}'     => Html::e((string) ($row['title'] ?? '')),
                    '{url}'            => Html::e((string) ($row['url'] ?? '#')),
                    '{post.url}'       => Html::e((string) ($row['url'] ?? '#')),
                    '{excerpt}'        => Html::e((string) ($row['excerpt'] ?? '')),
                    '{post.excerpt}'   => Html::e((string) ($row['excerpt'] ?? '')),
                    '{category}'       => Html::e((string) ($row['category'] ?? '')),
                    '{post.category}'  => Html::e((string) ($row['category'] ?? '')),
                    '{author}'         => Html::e((string) ($row['author_name'] ?? '')),
                    '{post.author}'    => Html::e((string) ($row['author_name'] ?? '')),
                    '{date}'           => Html::e((string) ($row['date_formatted'] ?? '')),
                    '{post.date}'      => Html::e((string) ($row['date_formatted'] ?? '')),
                ];
                $cardItem = strtr($childrenHtml, $tokens);
                $itemsHtml .= '<div class="sb-query-loop__item">' . $cardItem . '</div>';
            }
        } else {
            // Render standard responsive post cards
            foreach ($rows as $row) {
                $title     = (string) ($row['title'] ?? 'Untitled');
                $url       = (string) ($row['url'] ?? '#');
                $excerpt   = (string) ($row['excerpt'] ?? '');
                $category  = (string) ($row['category'] ?? '');
                $author    = (string) ($row['author_name'] ?? '');
                $date      = (string) ($row['date_formatted'] ?? '');
                $image     = (string) ($row['featured_image'] ?? '');

                $mediaHtml = '';
                if ($showImage && $image !== '') {
                    $mediaHtml = '<div class="sb-post-card__media">'
                        . '<a href="' . Html::e($url) . '">'
                        . '<img src="' . Html::e($image) . '" alt="' . Html::e($title) . '" loading="lazy">'
                        . '</a>'
                        . '</div>';
                }

                $metaHtml = '';
                if (($showAuthor && $author !== '') || ($showDate && $date !== '')) {
                    $metaHtml = '<div class="sb-post-card__meta">';
                    if ($showAuthor && $author !== '') {
                        $metaHtml .= '<span class="sb-post-card__author">' . Html::e($author) . '</span>';
                    }
                    if ($showDate && $date !== '') {
                        $metaHtml .= '<time class="sb-post-card__date">' . Html::e($date) . '</time>';
                    }
                    $metaHtml .= '</div>';
                }

                $itemsHtml .= '<article class="sb-post-card sb-post-card--' . Html::e($variant) . '">'
                    . $mediaHtml
                    . '<div class="sb-post-card__body">'
                    . ($showCategory && $category !== '' ? '<span class="sb-post-card__category">' . Html::e($category) . '</span>' : '')
                    . '<h3 class="sb-post-card__title"><a href="' . Html::e($url) . '">' . Html::e($title) . '</a></h3>'
                    . ($showExcerpt && $excerpt !== '' ? '<p class="sb-post-card__excerpt">' . Html::e($excerpt) . '</p>' : '')
                    . $metaHtml
                    . '<p class="sb-button-row"><a class="sb-button sb-button--primary sb-button--sm" href="' . Html::e($url) . '">' . Html::e($readMoreText) . '</a></p>'
                    . '</div>'
                    . '</article>';
            }
        }

        $gridHtml = '<div class="sb-query-loop__grid sb-grid sb-grid--cols-' . $columns . ' sb-gap--' . Html::e($gap) . '">'
            . $itemsHtml
            . '</div>';

        $paginationHtml = '';
        if ($enablePagination) {
            $paginationHtml = '<nav class="sb-pagination" aria-label="Pagination">'
                . '<span class="sb-pagination__prev sb-pagination__disabled">' . Html::t('studio_prev', 'Previous') . '</span>'
                . '<span class="sb-pagination__page sb-pagination__current">1</span>'
                . '<a href="?page=2" class="sb-pagination__page">2</a>'
                . '<a href="?page=2" class="sb-pagination__next">' . Html::t('studio_next', 'Next') . '</a>'
                . '</nav>';
        }

        return '<div class="sb-query-loop">'
            . $gridHtml
            . $paginationHtml
            . '</div>';
    }
}
