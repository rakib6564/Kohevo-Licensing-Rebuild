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

use Slate\Module\StudioBuilder\Meta\LoopTemplates;
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Provider\PostsProvider;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\DeviceColumns;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\RenderContext;

final class QueryLoopRenderer implements BlockRendererInterface
{
    public const PAGER_STYLES = ['simple', 'numbers'];
    public const OPEN_IN = ['page', 'dialog'];
    /** Page links shown on each side of the current one in a numbered pager. */
    private const PAGER_SPREAD = 1;

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
        // An archive's own list follows Reading; a loop of another content type on the template keeps its number.
        $archiveItems = (int) $scope->context()->attribute('archive_items', 0);
        if ($archiveItems > 0 && in_array($scope->string('content_type', ''), ['', (string) $scope->context()->attribute('content_type', '')], true)) {
            $perPage = $archiveItems;
        }
        $currentPage = self::currentPage($scope);

        // Filter chips: a taxonomy field of the content type (or the built-in category) and the visitor's `?filter=`.
        $filterField = $scope->string('filter_field');
        $filterField = preg_match('/^[a-z][a-z0-9_]{0,63}$/', $filterField) === 1 ? $filterField : '';
        $term = $filterField !== '' ? self::chosenTerm($scope) : '';
        $numbers = $scope->string('pagination_style', 'simple') === 'numbers';
        $contentType = $scope->string('content_type', '') !== '' ? $scope->string('content_type', '') : self::listing($scope)['content_type'];
        $counts = null;
        $total = null;
        // When every item fits on one page the chips filter in place (the runtime hides the others) and the page
        // holds them all; otherwise each chip is a link and the server lists that term's items.
        $local = false;

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
                    'content_type' => $contentType,
                    'order_by'  => $scope->string('order_by', 'published_at'),
                    'order'     => $scope->string('order', 'desc'),
                    'limit'     => $perPage,
                    'page'      => $currentPage,
                ];
                // The render context names the tenant this page belongs to; run the query as that tenant
                // instead of depending on an ambient global being set.
                $tenants = new \Slate\Tenancy\TenantContext();
                [$rows, $counts, $total, $local] = $tenants->runAs($scope->context()->tenantId, static function () use ($provider, $tenants, $params, $filterField, $term, $numbers, $perPage, $currentPage): array {
                    $counts = $filterField !== '' ? PostsProvider::termCounts($tenants, $params, $filterField) : null;
                    $local = $counts !== null && $counts['all'] <= $perPage && $currentPage === 1;
                    if ($counts !== null && !$local && $term !== '') {
                        $params['term_field'] = $filterField;
                        $params['term'] = $term;
                    }
                    $total = null;
                    if ($numbers) {
                        $total = $counts === null ? PostsProvider::total($tenants, $params) : ($term !== '' && !$local ? (int) ($counts['terms'][$term] ?? 0) : $counts['all']);
                    }
                    return [$provider->execute($tenants, $params), $counts, $total, $local];
                });
            } catch (\Throwable) {
                $rows = [];
            }
        }

        // Rows a binding gave are all there is: chips by category count them and filter them in place.
        if ($counts === null && $filterField === 'category' && is_array($rows) && $rows !== []) {
            $terms = [];
            foreach ($rows as $row) {
                $c = trim((string) ($row['category'] ?? ''));
                if ($c !== '') {
                    $terms[$c] = ($terms[$c] ?? 0) + 1;
                }
            }
            $counts = ['all' => count($rows), 'terms' => $terms];
            $local = true;
        }

        // Each item's terms, for chips that filter in place.
        $termsOf = static fn(array $row): array => [];
        if ($counts !== null && $local) {
            $byPage = $filterField === 'category' ? [] : PageMeta::termsByPage($scope->context()->tenantId, $filterField);
            $termsOf = static fn(array $row): array => $filterField === 'category' ? [(string) ($row['category'] ?? '')] : ($byPage[(int) ($row['id'] ?? 0)] ?? []);
        }
        $itemAttrs = static function (array $row) use ($termsOf, $local, $term, $counts): string {
            $attrs = ' data-sb-item-url="' . Html::e((string) ($row['url'] ?? '')) . '"';
            if ($counts !== null && $local) {
                $terms = $termsOf($row);
                $attrs .= ' data-sb-terms="' . Html::e((string) json_encode(array_values($terms), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
                if ($term !== '' && !in_array($term, $terms, true)) {
                    $attrs .= ' hidden';
                }
            }
            return $attrs;
        };
        $chipsHtml = $counts !== null ? $this->chips($scope, $filterField, $contentType, $counts, $term) : '';

        $emptyMsg = $scope->string('empty_message', '');
        if ($emptyMsg === '' || $emptyMsg === 'No posts found.') {
            $emptyMsg = Html::t('studio_no_posts', 'No posts found.');
        }
        if ($rows === [] || $rows === null) {
            return '<div class="sb-query-loop sb-query-loop--empty">'
                . $chipsHtml
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
                $itemsHtml .= '<div class="sb-query-loop__item"' . $itemAttrs($row) . '>' . $cardItem . '</div>';
            }
        } elseif (($template = self::loopTemplate($scope)) !== null) {
            $copy = 0;
            foreach ($rows as $row) {
                $copy++;
                $itemsHtml .= '<div class="sb-query-loop__item"' . $itemAttrs($row) . '>' . self::uniqueIds($scope->renderDocument($template['document_json'], self::itemContext($scope, $row)), $copy) . '</div>';
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

                $itemsHtml .= '<article class="sb-post-card sb-post-card--' . Html::e($variant) . '"' . $itemAttrs($row) . '>'
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

        // Two columns at most on a tablet and one on a phone, unless the author chose otherwise per device.
        $devices = $scope->deviceCounts('columns', 1, 6, min($columns, 2), 1);
        $gridHtml = '<div class="sb-query-loop__grid sb-grid sb-cols-' . $columns . ' sb-dcols sb-gap-' . Html::e($gap) . '" style="'
            . DeviceColumns::vars('dc', $columns, $devices['tablet'], $devices['mobile']) . '">'
            . $itemsHtml
            . '</div>';

        // Without a total the pager only offers what is known: a previous page past page 1, and a next page when this
        // one came back full. A numbered pager counts the items first and links every page (with gaps when many).
        $paginationHtml = '';
        $hasPrev = $currentPage > 1;
        $hasNext = $total !== null ? $currentPage < (int) ceil($total / $perPage) : count($rows) >= $perPage;
        if ($enablePagination && !$local && ($hasPrev || $hasNext)) {
            $paginationHtml = '<nav class="sb-pagination' . ($total !== null ? ' sb-pagination--numbers' : '') . '" aria-label="' . Html::t('studio_pagination', 'Pagination') . '">'
                . ($hasPrev
                    ? '<a href="' . Html::e(self::pageHref($scope, $currentPage - 1, $term)) . '" class="sb-pagination__prev" rel="prev">' . Html::t('studio_prev', 'Previous') . '</a>'
                    : '<span class="sb-pagination__prev sb-pagination__disabled">' . Html::t('studio_prev', 'Previous') . '</span>')
                . ($total !== null
                    ? self::pageNumbers($scope, $currentPage, (int) ceil($total / $perPage), $term)
                    : '<span class="sb-pagination__page sb-pagination__current" aria-current="page">' . $currentPage . '</span>')
                . ($hasNext
                    ? '<a href="' . Html::e(self::pageHref($scope, $currentPage + 1, $term)) . '" class="sb-pagination__next" rel="next">' . Html::t('studio_next', 'Next') . '</a>'
                    : '<span class="sb-pagination__next sb-pagination__disabled">' . Html::t('studio_next', 'Next') . '</span>')
                . '</nav>';
        }

        // Open in a dialog: the runtime fetches an item's page and shows its content over this one, with previous/next.
        $dialog = $scope->string('open_in', 'page') === 'dialog'
            ? ' data-sb-loop-dialog data-sb-label-close="' . Html::e(Html::t('studio_close', 'Close')) . '" data-sb-label-prev="' . Html::e(Html::t('studio_prev', 'Previous')) . '" data-sb-label-next="' . Html::e(Html::t('studio_next', 'Next')) . '" data-sb-label-loading="' . Html::e(Html::t('studio_loading', 'Loading…')) . '"'
            : '';
        return '<div class="sb-query-loop"' . ($local ? ' data-sb-filter-local' : '') . $dialog . '>'
            . $chipsHtml
            . $gridHtml
            . $paginationHtml
            . '</div>';
    }

    /**
     * The filter chips: "All" and each term that has items, with how many. Each is a link to `?filter=<term>`; when the
     * loop holds every item, the runtime filters in place instead.
     *
     * @param array{all: int, terms: array<string, int>} $counts
     */
    private function chips(BlockRenderScope $scope, string $field, string $contentType, array $counts, string $term): string
    {
        $labels = [];
        if ($field !== 'category' && $contentType !== '') {
            try {
                $labels = PageMeta::taxonomies($scope->context()->tenantId, $contentType)[$field]['terms'] ?? [];
            } catch (\Throwable) {
                $labels = [];
            }
        }
        // The field's own order, then any term it no longer lists; terms without items are left out.
        $order = array_values(array_unique(array_merge(array_map('strval', array_keys($labels)), array_map('strval', array_keys($counts['terms'])))));
        $showCounts = $scope->bool('filter_counts', true);
        $chip = static function (string $value, string $label, int $n, bool $current) use ($scope, $showCounts): string {
            return '<li><a class="sb-filter__chip" href="' . Html::e(self::pageHref($scope, 1, $value, true)) . '" data-sb-filter-term="' . Html::e($value) . '"'
                . ($current ? ' aria-current="true"' : '') . '>'
                . Html::e($label)
                . ($showCounts ? '<span class="sb-filter__count">' . $n . '</span>' : '')
                . '</a></li>';
        };
        $all = $scope->string('filter_all_label', 'All');
        $html = $chip('', $all !== '' ? $all : Html::t('studio_all', 'All'), $counts['all'], $term === '');
        foreach ($order as $value) {
            $n = (int) ($counts['terms'][$value] ?? 0);
            if ($n > 0) {
                $html .= $chip($value, (string) ($labels[$value] ?? $value), $n, $term === $value);
            }
        }
        return '<nav class="sb-filter" aria-label="' . Html::t('studio_filter', 'Filter') . '"><ul class="sb-filter__list">' . $html . '</ul></nav>';
    }

    /** Page links around the current one: 1 … 4 5 6 … 12. */
    private static function pageNumbers(BlockRenderScope $scope, int $current, int $pages, string $term): string
    {
        $show = [1, $pages];
        for ($p = $current - self::PAGER_SPREAD; $p <= $current + self::PAGER_SPREAD; $p++) {
            $show[] = $p;
        }
        $show = array_values(array_unique(array_filter($show, static fn(int $p): bool => $p >= 1 && $p <= $pages)));
        sort($show);
        $html = '';
        $last = 0;
        foreach ($show as $p) {
            if ($p > $last + 1) {
                $html .= '<span class="sb-pagination__gap" aria-hidden="true">…</span>';
            }
            $html .= $p === $current
                ? '<span class="sb-pagination__page sb-pagination__current" aria-current="page">' . $p . '</span>'
                : '<a class="sb-pagination__page" href="' . Html::e(self::pageHref($scope, $p, $term)) . '">' . $p . '</a>';
            $last = $p;
        }
        return $html;
    }

    /** The term the visitor chose with `?filter=` (public pages only), or ''. */
    private static function chosenTerm(BlockRenderScope $scope): string
    {
        $raw = $scope->context()->mode->isPublic() ? ($_GET['filter'] ?? null) : $scope->context()->attribute('filter');
        return is_string($raw) ? mb_substr(trim($raw), 0, 80) : '';
    }

    /**
     * The Loop Item template for this loop. A card holding a loop of its own draws that one with built-in cards, so a
     * template can never draw itself forever.
     *
     * @return ?array{uuid: string, id: int, title: string, content_type: string, document_json: string}
     */
    private static function loopTemplate(BlockRenderScope $scope): ?array
    {
        if ($scope->context()->attribute('in_loop_item') === true) {
            return null;
        }
        try {
            return LoopTemplates::pick(LoopTemplates::published($scope->context()->tenantId), $scope->string('loop_template'), $scope->string('content_type') ?: self::listing($scope)['content_type']);
        } catch (\Throwable) {
            return null;
        }
    }

    /** The context a card is drawn in: that item's title, link, image and custom fields. It cannot be picked on the canvas.
     *
     * @param array<string, mixed> $row
     */
    private static function itemContext(BlockRenderScope $scope, array $row): RenderContext
    {
        $tenantId = $scope->context()->tenantId;
        $meta = PageMeta::read($tenantId, (int) ($row['id'] ?? 0));
        $fields = $meta['type'] === '' ? [] : PageMeta::fieldsFor($tenantId, $meta['type']);
        return $scope->context()->framing()->withAttributes(LoopTemplates::attributes($row) + ['in_loop_item' => true, 'page_meta' => $meta + ['fields' => $fields]]);
    }

    /**
     * What the page being shown is a listing of, when it is an archive or search template: its category, tag or author
     * term and its search query (render attributes set by the public runtime). A loop with its own filter keeps it;
     * one without lists the archive's or the search's posts instead of every post.
     *
     * @return array{category: string, tag: string, author_id: int, search: string, content_type: string}
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
            'content_type' => $type === 'content_type' ? $str('content_type') : '',
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

    /**
     * A pager or chip link: `?page=N`, keeping a search's `q` so the next page is still the same search, and the chosen
     * term (`filter`). A chip's link leaves the page out (a new term starts on its first page).
     */
    private static function pageHref(BlockRenderScope $scope, int $page, string $term = '', bool $chip = false): string
    {
        $query = self::listing($scope)['search'];
        $args = array_filter(['q' => $query, 'filter' => $term], static fn(string $v): bool => $v !== '');
        if (!$chip) {
            $args['page'] = $page;
        }
        return '?' . http_build_query($args);
    }

    /**
     * The page of results to show: a `page` prop, then a `page` render attribute, then the visitor's
     * `?page=` link (the pager writes it). Only a whole number from 1 to 1000 counts; anything else is page 1.
     */
    private static function currentPage(BlockRenderScope $scope): int
    {
        // The builder's own `?page=` names the page being edited, not a page of results.
        $link = $scope->context()->mode->isPublic() ? ($_GET['page'] ?? null) : null;
        $raw = $scope->prop('page') ?? $scope->context()->attribute('page') ?? $link;
        $page = filter_var(is_scalar($raw) ? (string) $raw : '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        return $page === false ? 1 : $page;
    }
}
