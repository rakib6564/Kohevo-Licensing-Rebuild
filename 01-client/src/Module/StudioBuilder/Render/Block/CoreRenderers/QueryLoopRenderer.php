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
        $perPage = max(1, (int) $scope->prop('per_page', 6));
        $currentPage = self::currentPage($scope);

        // If no binding slot provided rows, execute PostsProvider directly
        if ($rows === null) {
            try {
                $provider = new PostsProvider();
                $listing = self::listing($scope);
                $params = [
                    'source'    => $scope->string('source', 'posts'),
                    'category'  => $scope->string('category', '') !== '' ? $scope->string('category', '') : $listing['category'],
                    'tag'       => $scope->string('tag', '') !== '' ? $scope->string('tag', '') : $listing['tag'],
                    'author_id' => (int) $scope->prop('author_id', 0) > 0 ? (int) $scope->prop('author_id', 0) : $listing['author_id'],
                    'search'    => $listing['search'],
                    'order_by'  => $scope->string('order_by', 'published_at'),
                    'order'     => $scope->string('order', 'desc'),
                    'limit'     => $perPage,
                    'page'      => $currentPage,
                ];
                // The render context names the tenant this page belongs to; run the query as that tenant
                // instead of depending on an ambient global being set.
                $tenants = new \Slate\Tenancy\TenantContext();
                $rows = $tenants->runAs($scope->context()->tenantId, static fn(): array => $provider->execute($tenants, $params));
            } catch (\Throwable) {
                $rows = [];
            }
        }

        $emptyMsg = $scope->string('empty_message', '');
        if ($emptyMsg === '' || $emptyMsg === 'No posts found.') {
            $emptyMsg = Html::t('studio_no_posts', 'No posts found.');
        }
        if ($rows === [] || $rows === null) {
            return '<div class="sb-query-loop sb-query-loop--empty">'
                . '<p class="sb-empty">' . Html::e($emptyMsg) . '</p>'
                . '</div>';
        }

        $columns = max(1, min(6, (int) $scope->prop('columns', 3)));
        $gap = $scope->string('gap', 'md');
        $variant = $scope->string('card_variant', 'card');
        $titleTag = $scope->string('title_level', 'h3');
        $titleTag = in_array($titleTag, ['h2', 'h3', 'h4', 'h5', 'h6'], true) ? $titleTag : 'h3';
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
            $copy = 0;
            foreach ($rows as $row) {
                $copy++;
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
                $cardItem = self::uniqueIds(strtr($childrenHtml, $tokens), $copy);
                $itemsHtml .= '<div class="sb-query-loop__item">' . $cardItem . '</div>';
            }
        } else {
            // Render standard responsive post cards
            foreach ($rows as $row) {
                $title     = (string) ($row['title'] ?? Html::t('studio_untitled', 'Untitled'));
                $url       = (string) ($row['url'] ?? '#');
                $excerpt   = (string) ($row['excerpt'] ?? '');
                $category  = (string) ($row['category'] ?? '');
                $author    = (string) ($row['author_name'] ?? '');
                $date      = (string) ($row['date_formatted'] ?? '');
                $datetime  = self::isoDate($row['published_at'] ?? null);
                $image     = (string) ($row['featured_image'] ?? '');

                // One link per card: the title. The image sits beside the title (so its alt would only repeat it) and the
                // button repeats the title's link, so both stay out of the tab order and the screen reader's link list.
                $mediaHtml = '';
                if ($showImage && $image !== '') {
                    $mediaHtml = '<div class="sb-post-card__media">'
                        . '<img src="' . Html::e($image) . '" alt="" loading="lazy">'
                        . '</div>';
                }

                $metaHtml = '';
                if (($showAuthor && $author !== '') || ($showDate && $date !== '')) {
                    $metaHtml = '<div class="sb-post-card__meta">';
                    if ($showAuthor && $author !== '') {
                        $metaHtml .= '<span class="sb-post-card__author">' . Html::e($author) . '</span>';
                    }
                    if ($showDate && $date !== '') {
                        $metaHtml .= '<time class="sb-post-card__date"' . ($datetime !== '' ? ' datetime="' . Html::e($datetime) . '"' : '') . '>' . Html::e($date) . '</time>';
                    }
                    $metaHtml .= '</div>';
                }

                $itemsHtml .= '<article class="sb-post-card sb-post-card--' . Html::e($variant) . '">'
                    . $mediaHtml
                    . '<div class="sb-post-card__body">'
                    . ($showCategory && $category !== '' ? '<span class="sb-post-card__category">' . Html::e($category) . '</span>' : '')
                    . '<' . $titleTag . ' class="sb-post-card__title"><a href="' . Html::e($url) . '">' . Html::e($title) . '</a></' . $titleTag . '>'
                    . ($showExcerpt && $excerpt !== '' ? '<p class="sb-post-card__excerpt">' . Html::e($excerpt) . '</p>' : '')
                    . $metaHtml
                    . '<p class="sb-button-row"><a class="sb-button sb-button--primary sb-button--sm" href="' . Html::e($url) . '" tabindex="-1" aria-hidden="true">' . Html::e($readMoreText) . '</a></p>'
                    . '</div>'
                    . '</article>';
            }
        }

        $gridHtml = '<div class="sb-query-loop__grid sb-grid sb-cols-' . $columns . ' sb-gap-' . Html::e($gap) . '">'
            . $itemsHtml
            . '</div>';

        // The provider returns one page and no total, so the pager only offers what is known:
        // a previous page past page 1, and a next page when this one came back full.
        $paginationHtml = '';
        $hasPrev = $currentPage > 1;
        $hasNext = count($rows) >= $perPage;
        if ($enablePagination && ($hasPrev || $hasNext)) {
            $paginationHtml = '<nav class="sb-pagination" aria-label="' . Html::t('studio_pagination', 'Pagination') . '">'
                . ($hasPrev
                    ? '<a href="' . Html::e(self::pageHref($scope, $currentPage - 1)) . '" class="sb-pagination__prev" rel="prev">' . Html::t('studio_prev', 'Previous') . '</a>'
                    : '<span class="sb-pagination__prev sb-pagination__disabled">' . Html::t('studio_prev', 'Previous') . '</span>')
                . '<span class="sb-pagination__page sb-pagination__current" aria-current="page">' . $currentPage . '</span>'
                . ($hasNext
                    ? '<a href="' . Html::e(self::pageHref($scope, $currentPage + 1)) . '" class="sb-pagination__next" rel="next">' . Html::t('studio_next', 'Next') . '</a>'
                    : '<span class="sb-pagination__next sb-pagination__disabled">' . Html::t('studio_next', 'Next') . '</span>')
                . '</nav>';
        }

        return '<div class="sb-query-loop">'
            . $gridHtml
            . $paginationHtml
            . '</div>';
    }

    /**
     * What the page being shown is a listing of, when it is an archive or search template: its category, tag or author
     * term and its search query (render attributes set by the public runtime). A loop with its own filter keeps it;
     * one without lists the archive's or the search's posts instead of every post.
     *
     * @return array{category: string, tag: string, author_id: int, search: string}
     */
    private static function listing(BlockRenderScope $scope): array
    {
        $context = $scope->context();
        $str = static fn(string $key): string => is_string($context->attribute($key)) ? mb_substr(trim((string) $context->attribute($key)), 0, 120) : '';
        $type = $str('archive_type');
        return [
            'category'  => mb_substr($str('category'), 0, 80),
            'tag'       => mb_substr($str('tag'), 0, 80),
            'author_id' => $type === 'author' && ctype_digit($str('term')) ? (int) $str('term') : 0,
            'search'    => $str('search_query'),
        ];
    }

    /**
     * The child template is repeated once per post, so every `id` in it would repeat too (two tabs, accordions or form
     * fields with the same id break their labels and controls). Copies after the first get `-N` ids, and the attributes
     * in that copy that point at those ids (`for`, `aria-*`, `href="#…"`, `data-sb-*`) follow.
     */
    private static function uniqueIds(string $html, int $copy): string
    {
        if ($copy <= 1 || preg_match_all('/\sid="([^"]+)"/', $html, $found) === 0) {
            return $html;
        }
        $ids = array_flip($found[1]);
        return (string) preg_replace_callback(
            '/(\s(?:id|for|headers|list|href|aria-[a-z]+|data-sb-[a-z-]+)=")([^"]*)"/',
            static function (array $m) use ($ids, $copy): string {
                $tokens = explode(' ', $m[2]);
                foreach ($tokens as $i => $token) {
                    $hash = str_starts_with($token, '#');
                    $bare = $hash ? substr($token, 1) : $token;
                    if ($bare !== '' && isset($ids[$bare])) {
                        $tokens[$i] = ($hash ? '#' : '') . $bare . '-' . $copy;
                    }
                }
                return $m[1] . implode(' ', $tokens) . '"';
            },
            $html
        );
    }

    /** A machine-readable date (`Y-m-d`) for `<time datetime>`, or '' when the value is not a date. */
    private static function isoDate(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', trim($value), $m) !== 1) {
            return '';
        }
        return $m[1];
    }

    /** A pager link: `?page=N`, keeping a search's `q` so the next page is still the same search. */
    private static function pageHref(BlockRenderScope $scope, int $page): string
    {
        $query = self::listing($scope)['search'];
        return '?' . http_build_query($query !== '' ? ['q' => $query, 'page' => $page] : ['page' => $page]);
    }

    /**
     * The page of results to show: a `page` prop, then a `page` render attribute, then the visitor's
     * `?page=` link (the pager writes it). Only a whole number from 1 to 1000 counts; anything else is page 1.
     */
    private static function currentPage(BlockRenderScope $scope): int
    {
        $raw = $scope->prop('page') ?? $scope->context()->attribute('page') ?? ($_GET['page'] ?? null);
        $page = filter_var(is_scalar($raw) ? (string) $raw : '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        return $page === false ? 1 : $page;
    }
}
