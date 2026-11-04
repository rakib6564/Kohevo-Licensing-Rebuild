<?php
/**
 * Shop — the renderers of the Shop widgets in Kohevo Studio (one class per block type).
 *
 * Catalogue widgets (Product grid and the single product parts) render the products themselves: their output is the
 * same for every visitor, so the page stays cacheable. The cart widgets (Mini cart, Cart, Checkout, Order
 * confirmation) render a placeholder that studio-runtime.js fills with the visitor's own cart from Shop's endpoints
 * (see ShopStudio::fragment), with a plain link in it for visitors without JavaScript.
 *
 * Class names are the `sb-shop-*` names Studio's stylesheet styles and the widgets' style parts (studio-widgets.php)
 * select; a renamed class must be renamed there too.
 */

declare(strict_types=1);

namespace Slate\Plugin\Shop\Studio;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\Icon\IconLibrary;

/**
 * `shop.products`: a grid of product cards, with a compact filter bar (category chips, underlined tabs or a dropdown,
 * search, sort and the number of products) and pages (numbers, previous / next, or Load more).
 *
 * Every look is a setting of the widget, so an author never needs CSS:
 * - columns, column gap, row gap, card alignment and card text padding may differ on tablet and phone; they are written
 *   as custom properties on the grid (`--sb-dc-*`, `--sb-shop-gx-*`, `--sb-shop-gy-*`, `--sb-shop-al-*`,
 *   `--sb-shop-pad-*`) that the stylesheet reads per breakpoint;
 * - the card style, corner radius, image shape and fit, hover (zoom, or the product's second photo), category, title
 *   lines, short description, badges (sale as text or percent, sold out, new), and where the Add to cart sits (below,
 *   or on the image, Shopify-style) are classes and properties on the widget.
 * Colours, fonts, borders and spacing of each piece are the widget's style parts (Style › Parts).
 */
final class ShopProductsRenderer implements BlockRendererInterface
{
    public const SORTS = ['newest', 'price_asc', 'price_desc', 'title'];

    public function type(): string
    {
        return 'shop.products';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    /** One of the allowed values of an enum prop, else its default. */
    private static function pick(BlockRenderScope $scope, string $key, array $allowed, string $default): string
    {
        $v = $scope->string($key, $default);
        return in_array($v, $allowed, true) ? $v : $default;
    }

    public function render(BlockRenderScope $scope): string
    {
        if (!class_exists('ShopStoreAPI')) {
            return '';
        }
        $params = $scope->block()['bindings']['items']['params'] ?? [];
        $params = is_array($params) ? $params : [];
        $fixed = is_string($params['category'] ?? null) ? $params['category'] : '';
        $order = $scope->string('order', 'menu');
        $source = self::pick($scope, 'source', ShopProductsProvider::SOURCES, 'all');
        // Upsells, cross-sells and related products belong to the product whose page this is.
        $around = in_array($source, ['upsell', 'cross_sell', 'related'], true);
        $public = $scope->mode()->isPublic();
        $showFilter = $scope->bool('show_filter', false) && $fixed === '' && !$around;
        $filterStyle = self::pick($scope, 'filter_style', ['chips', 'underline', 'dropdown'], 'chips');
        $chosen = $showFilter && $public && is_string($_GET['category'] ?? null) && preg_match('/^[a-z0-9-]{1,120}$/', $_GET['category']) === 1 ? $_GET['category'] : '';
        $category = $fixed !== '' ? $fixed : $chosen;
        // A visitor's search and sort, when the grid shows those tools (?q= and ?sort= on the page's address).
        $showSearch = $scope->bool('show_search', false) && !$around;
        $showSort = $scope->bool('show_sort', false) && !$around;
        $search = $showSearch && $public && is_string($_GET['q'] ?? null) ? trim(mb_substr($_GET['q'], 0, 80)) : '';
        $sort = $showSort && $public && is_string($_GET['sort'] ?? null) && in_array($_GET['sort'], self::SORTS, true) ? $_GET['sort'] : '';
        if ($sort !== '') $order = $sort;
        $tag = strtolower(trim($scope->string('tag')));
        $perPage = max(1, min(48, (int) $scope->prop('per_page', 12)));
        $page = $public && !$around ? max(1, (int) ($_GET['page'] ?? 1)) : 1;
        try {
            if ($around) {
                $current = ShopStudio::current($scope);
                $rows = $current === null ? [] : \ShopStoreAPI::relatedProducts((int) $current['id'], $source, $perPage);
                $list = ['rows' => $rows, 'total' => count($rows), 'page' => 1, 'pages' => 1];
            } else {
                $list = \ShopStoreAPI::listProducts(['category' => $category, 'tag' => $tag !== '' ? \ShopStoreAPI::slugify($tag, 80) : '',
                    'featured' => $source === 'featured', 'order' => $order, 'per_page' => $perPage, 'page' => $page, 'q' => $search]);
            }
            $categories = $showFilter ? array_values(array_filter(\ShopStoreAPI::categories(), static fn(array $c): bool => $c['product_count'] > 0)) : [];
        } catch (\Throwable) {
            return '';
        }
        // Nothing to suggest: the section stays out of the page (the editor still shows the empty message).
        if ($around && $list['rows'] === [] && $public) {
            return '';
        }

        $heading = $scope->string('heading');
        $out = '<div class="sb-shop-products">' . ($heading !== '' ? '<h2 class="sb-shop-products__heading">' . Html::e($heading) . '</h2>' : '');
        $out .= self::toolbar($scope, $categories, $chosen, $filterStyle, $showSearch, $showSort, $search, $sort, (int) $list['total']);

        if ($list['rows'] === []) {
            $empty = $scope->string('empty_message');
            $msg = $search !== '' ? sprintf(Html::t('shop_no_search_results', 'Nothing matches “%s”.'), $search) : ($empty !== '' ? $empty : Html::t('shop_no_products', 'No products yet.'));
            return $out . '<p class="sb-empty sb-shop-products__empty">' . Html::e($msg) . '</p></div>';
        }

        $out .= '<ul class="' . Html::e(implode(' ', self::gridClasses($scope))) . '" style="' . Html::e(self::gridVars($scope)) . '"'
            . ' data-sb-load-grid="' . Html::e($scope->domId('grid')) . '">';
        foreach ($list['rows'] as $p) {
            $out .= self::card($scope, $p);
        }
        $out .= '</ul>';
        if ($scope->bool('show_pager', true) && $list['pages'] > 1) {
            $out .= self::pager($scope, $list, ['category' => $chosen, 'q' => $search, 'sort' => $sort]);
        }
        return $out . '</div>';
    }

    /** @return list<string> */
    private static function gridClasses(BlockRenderScope $scope): array
    {
        $classes = ['sb-shop-products__grid', 'sb-dcols'];
        if (self::pick($scope, 'density', ['regular', 'compact'], 'regular') === 'compact') $classes[] = 'sb-shop-products--compact';
        $classes[] = 'sb-shop-products--card-' . self::pick($scope, 'card_style', ['plain', 'outlined', 'raised', 'filled'], 'plain');
        $classes[] = 'sb-shop-products--ratio-' . self::pick($scope, 'image_ratio', ['square', 'portrait', 'tall', 'landscape', 'natural'], 'square');
        if (self::pick($scope, 'image_fit', ['cover', 'contain'], 'cover') === 'contain') $classes[] = 'sb-shop-products--fit-contain';
        $classes[] = 'sb-shop-products--hover-' . self::pick($scope, 'image_hover', ['none', 'zoom', 'second_image'], 'zoom');
        $lines = self::pick($scope, 'title_lines', ['all', '1', '2', '3'], 'all');
        if ($lines !== 'all') $classes[] = 'sb-shop-products--clamp-' . $lines;
        if (self::pick($scope, 'badge_position', ['top_left', 'top_right'], 'top_left') === 'top_right') $classes[] = 'sb-shop-products--badge-right';
        if (self::pick($scope, 'button_width', ['auto', 'full'], 'auto') === 'full') $classes[] = 'sb-shop-products--button-full';
        $pad = $scope->prop('card_padding');
        if (($pad !== null && $pad !== '') || $scope->hasDeviceValue('card_padding')) $classes[] = 'sb-shop-products--padded';
        return $classes;
    }

    /** The per-device numbers as custom properties: always all three devices, so a nested grid never inherits them. */
    private static function gridVars(BlockRenderScope $scope): string
    {
        $cols = max(1, min(6, (int) $scope->prop('columns', 3)));
        $c = $scope->deviceCounts('columns', 1, 6, min($cols, 2), 1);
        $vars = [\Slate\Module\StudioBuilder\Render\Block\DeviceColumns::vars('dc', $cols, $c['tablet'], $c['mobile'])];
        foreach (['gap' => ['gx', 80, 24], 'row_gap' => ['gy', 120, 24]] as $key => [$var, $max, $default]) {
            $d = max(0, min($max, (int) $scope->prop($key, $default)));
            $dev = $scope->deviceCounts($key, 0, $max, $d, min($d, 16));
            $vars[] = '--sb-shop-' . $var . '-d:' . $d . 'px;--sb-shop-' . $var . '-t:' . $dev['tablet'] . 'px;--sb-shop-' . $var . '-m:' . $dev['mobile'] . 'px';
        }
        $align = self::pick($scope, 'align', ['left', 'center'], 'left');
        $over = \Slate\Module\StudioBuilder\Document\DeviceProps::overrides($scope->block(), 'align');
        $al = static fn(mixed $v): string => $v === 'center' ? 'center' : 'left';
        $at = $al($over['tablet'] ?? $align);
        $am = $al($over['mobile'] ?? $at);
        $jc = static fn(string $a): string => $a === 'center' ? 'center' : 'flex-start';
        $vars[] = '--sb-shop-al-d:' . $align . ';--sb-shop-al-t:' . $at . ';--sb-shop-al-m:' . $am
            . ';--sb-shop-jc-d:' . $jc($align) . ';--sb-shop-jc-t:' . $jc($at) . ';--sb-shop-jc-m:' . $jc($am);
        if (in_array('sb-shop-products--padded', self::gridClasses($scope), true)) {
            $pd = max(0, min(48, (int) $scope->prop('card_padding', 16)));
            $pdev = $scope->deviceCounts('card_padding', 0, 48, $pd, $pd);
            $vars[] = '--sb-shop-pad-d:' . $pd . 'px;--sb-shop-pad-t:' . $pdev['tablet'] . 'px;--sb-shop-pad-m:' . $pdev['mobile'] . 'px';
        }
        $radius = $scope->prop('card_radius');
        if ($radius !== null && $radius !== '') $vars[] = '--sb-shop-radius:' . max(0, min(40, (int) $radius)) . 'px';
        return implode(';', $vars);
    }

    /**
     * The filter bar: categories (chips, underlined tabs, or a dropdown in the form), then the number of products, the
     * search box and the sort choice. One compact row on a wide screen; on a phone the chips scroll sideways and the
     * form takes its own row. The form is sent on Enter and when the sort or category changes (studio-runtime.js,
     * `data-sb-autosubmit`); its button is there for visitors without JavaScript.
     */
    private static function toolbar(BlockRenderScope $scope, array $categories, string $chosen, string $style, bool $showSearch, bool $showSort, string $search, string $sort, int $total): string
    {
        $showCount = $scope->bool('show_count', false);
        $hasCats = $categories !== [];
        if (!$hasCats && !$showSearch && !$showSort && !$showCount) return '';
        $allLabel = $scope->string('all_label') !== '' ? $scope->string('all_label') : Html::t('shop_all', 'All');
        $nav = '';
        if ($hasCats && $style !== 'dropdown') {
            $link = static fn(string $slug, string $label): string => '<a class="sb-shop-filter__link" href="' . Html::e('?' . http_build_query(array_filter(['category' => $slug, 'sort' => $sort]))) . '"'
                . ($slug === $chosen ? ' aria-current="true"' : '') . '>' . Html::e($label) . '</a>';
            $nav = '<nav class="sb-shop-filter sb-shop-filter--' . $style . '" aria-label="' . Html::e(Html::t('shop_categories', 'Categories')) . '">' . $link('', $allLabel);
            foreach ($categories as $c) $nav .= $link((string) $c['slug'], (string) $c['name']);
            $nav .= '</nav>';
        }
        $count = $showCount ? '<p class="sb-shop-toolbar__count" role="status">' . Html::e($total === 1 ? Html::t('shop_one_product', '1 product') : sprintf(Html::t('shop_n_products', '%d products'), $total)) . '</p>' : '';
        $form = '';
        if ($showSearch || $showSort || ($hasCats && $style === 'dropdown')) {
            $sortChoices = ['' => Html::t('shop_sort_default', 'Featured'), 'newest' => Html::t('shop_sort_newest', 'Newest'), 'price_asc' => Html::t('shop_sort_price_asc', 'Price: low to high'),
                            'price_desc' => Html::t('shop_sort_price_desc', 'Price: high to low'), 'title' => Html::t('shop_sort_title', 'Name')];
            $options = static fn(array $choices, string $current): string => implode('', array_map(
                static fn(string $k, string $l): string => '<option value="' . Html::e($k) . '"' . ($k === $current ? ' selected' : '') . '>' . Html::e($l) . '</option>', array_keys($choices), $choices));
            $cats = ['' => $allLabel];
            foreach ($categories as $c) $cats[(string) $c['slug']] = (string) $c['name'];
            $form = '<form class="sb-shop-tools" method="get" role="search" data-sb-autosubmit>'
                . ($hasCats && $style === 'dropdown'
                    ? '<label class="sb-shop-tools__item"><span class="sb-shop-sr">' . Html::e(Html::t('shop_categories', 'Categories')) . '</span><select class="sb-shop-tools__field" name="category" data-sb-autosubmit-field>' . $options($cats, $chosen) . '</select></label>'
                    : ($chosen !== '' ? '<input type="hidden" name="category" value="' . Html::e($chosen) . '">' : ''))
                . ($showSearch ? '<label class="sb-shop-tools__item sb-shop-tools__search"><span class="sb-shop-sr">' . Html::e(Html::t('shop_search_products', 'Search products')) . '</span>'
                    . '<input class="sb-shop-tools__field" type="search" name="q" value="' . Html::e($search) . '" placeholder="' . Html::e(Html::t('shop_search_products', 'Search products')) . '" maxlength="80" enterkeyhint="search"></label>' : '')
                . ($showSort ? '<label class="sb-shop-tools__item sb-shop-tools__sort"><span class="sb-shop-sr">' . Html::e(Html::t('shop_sort_by', 'Sort by')) . '</span>'
                    . '<select class="sb-shop-tools__field" name="sort" data-sb-autosubmit-field>' . $options($sortChoices, $sort) . '</select></label>' : '')
                . '<button type="submit" class="sb-button sb-button--secondary sb-shop-tools__go">' . Html::e($showSearch ? Html::t('shop_search', 'Search') : Html::t('shop_apply', 'Apply')) . '</button></form>';
        }
        $compact = self::pick($scope, 'density', ['regular', 'compact'], 'regular') === 'compact';
        return '<div class="sb-shop-toolbar' . ($compact ? ' sb-shop-toolbar--compact' : '') . '">' . $nav . '<div class="sb-shop-toolbar__end">' . $count . $form . '</div></div>';
    }

    /** One product card. */
    private static function card(BlockRenderScope $scope, array $p): string
    {
        $url = ShopStudio::productUrl($scope, $p);
        $hover = self::pick($scope, 'image_hover', ['none', 'zoom', 'second_image'], 'zoom');
        $alt = $hover === 'second_image' && isset($p['images'][1]) && (string) $p['images'][1] !== '' ? (string) $p['images'][1] : '';
        $label = $scope->string('button_label');
        $linkLabel = $scope->string('link_label');
        $showAdd = $scope->bool('show_add_to_cart', true) && $linkLabel === '';
        $onImage = $showAdd && self::pick($scope, 'button_placement', ['below', 'on_image'], 'below') === 'on_image';
        $icon = $scope->bool('show_icon', false) ? IconLibrary::markup($scope->string('icon', 'heart')) : '';
        $iconHtml = $icon !== '' ? '<span class="sb-shop-card__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">' . $icon . '</svg></span>' : '';
        $add = '';
        if ($showAdd) {
            $add = $p['has_variants'] && $p['in_stock']
                // Options are chosen on the product's page.
                ? '<p class="sb-shop-add sb-shop-card__add"><a class="sb-button sb-button--primary sb-shop-add__button sb-shop-card__choose" href="' . Html::e($url) . '">' . Html::e($scope->string('options_label') !== '' ? $scope->string('options_label') : Html::t('shop_choose_options', 'Choose options')) . '</a></p>'
                : ShopStudio::addToCartForm($scope, $p, $label, false, false, 'sb-shop-card__add');
        }
        $category = $scope->bool('show_category', false) && ($p['categories'][0]['name'] ?? '') !== '' ? '<p class="sb-shop-card__category">' . Html::e((string) $p['categories'][0]['name']) . '</p>' : '';
        $excerpt = $scope->bool('show_excerpt', false) && trim((string) $p['short_description']) !== ''
            ? '<p class="sb-shop-card__excerpt">' . Html::e(mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags((string) $p['short_description'])) ?? ''), 0, 140, '…')) . '</p>' : '';
        return '<li class="sb-shop-card">'
            . '<div class="sb-shop-card__media">'
            . '<a class="sb-shop-card__media-link" href="' . Html::e($url) . '" tabindex="-1" aria-hidden="true">'
            . ($p['image'] !== '' ? '<img class="sb-shop-card__image" src="' . Html::e($p['image']) . '" alt="" loading="lazy">' : '<span class="sb-shop-card__placeholder"></span>')
            . ($alt !== '' ? '<img class="sb-shop-card__image sb-shop-card__image--alt" src="' . Html::e($alt) . '" alt="" loading="lazy">' : '')
            . '</a>' . self::badges($scope, $p)
            . ($onImage ? '<div class="sb-shop-card__quick">' . $add . '</div>' : '')
            . '</div><div class="sb-shop-card__body">' . $iconHtml . $category
            . '<h3 class="sb-shop-card__title"><a href="' . Html::e($url) . '">' . Html::e($p['title']) . '</a></h3>'
            . $excerpt
            . ($scope->bool('show_price', true) ? ShopStudio::priceHtml($p) : '')
            // A text link to the product (the design's "Buy →") takes the place of the button.
            . ($linkLabel !== '' ? '<a class="sb-shop-card__link" href="' . Html::e($url) . '">' . Html::e($linkLabel) . '</a>' : '')
            . ($onImage ? '' : $add)
            . '</div></li>';
    }

    /** Sold out, on sale (as text or the percentage off) and new. */
    private static function badges(BlockRenderScope $scope, array $p): string
    {
        if (!$scope->bool('show_badges', true)) return '';
        $out = [];
        if (!$p['in_stock']) {
            $out[] = '<span class="sb-shop-card__badge">' . Html::e(Html::t('shop_sold_out', 'Sold out')) . '</span>';
        } elseif ($p['on_sale']) {
            $regular = (int) $p['price_cents'];
            $now = (int) $p['effective_cents'];
            $pct = $regular > 0 ? (int) round(($regular - $now) * 100 / $regular) : 0;
            $text = self::pick($scope, 'sale_badge', ['text', 'percent'], 'text') === 'percent' && $pct > 0 ? '−' . $pct . '%' : Html::t('shop_sale', 'Sale');
            $out[] = '<span class="sb-shop-card__badge sb-shop-card__badge--sale">' . Html::e($text) . '</span>';
        }
        $days = max(0, min(365, (int) $scope->prop('new_days', 0)));
        $created = strtotime((string) ($p['created_at'] ?? ''));
        if ($days > 0 && $p['in_stock'] && $created !== false && $created >= time() - $days * 86400) {
            $out[] = '<span class="sb-shop-card__badge sb-shop-card__badge--new">' . Html::e(Html::t('shop_new', 'New')) . '</span>';
        }
        return $out === [] ? '' : '<span class="sb-shop-card__badges">' . implode('', $out) . '</span>';
    }

    /** Numbers, Previous / Next, or a Load more button (a link to the next page that studio-runtime.js turns into more cards). */
    private static function pager(BlockRenderScope $scope, array $list, array $keep): string
    {
        $q = static fn(int $n): string => '?' . http_build_query(array_filter($keep + ['page' => $n > 1 ? $n : null]));
        $mode = self::pick($scope, 'pagination', ['numbers', 'prev_next', 'load_more'], 'numbers');
        if ($mode === 'load_more') {
            if ($list['page'] >= $list['pages']) return '';
            $label = $scope->string('load_more_label') !== '' ? $scope->string('load_more_label') : Html::t('shop_load_more', 'Load more');
            return '<p class="sb-shop-more"><a class="sb-button sb-button--secondary sb-shop-more__button" href="' . Html::e($q($list['page'] + 1)) . '" data-sb-load-more="'
                . Html::e($scope->domId('grid')) . '" rel="next">' . Html::e($label) . '</a></p>';
        }
        $out = '<nav class="sb-shop-pager" aria-label="' . Html::e(Html::t('shop_pages', 'Pages')) . '">';
        if ($list['page'] > 1) {
            $out .= '<a class="sb-shop-pager__link sb-shop-pager__prev" href="' . Html::e($q($list['page'] - 1)) . '" rel="prev">' . Html::e(Html::t('shop_previous', 'Previous')) . '</a>';
        }
        if ($mode === 'numbers') {
            for ($n = 1; $n <= $list['pages']; $n++) {
                $out .= $n === $list['page']
                    ? '<span class="sb-shop-pager__link" aria-current="page">' . $n . '</span>'
                    : '<a class="sb-shop-pager__link" href="' . Html::e($q($n)) . '">' . $n . '</a>';
            }
        } else {
            $out .= '<span class="sb-shop-pager__status">' . Html::e(sprintf(Html::t('shop_page_of', 'Page %1$d of %2$d'), $list['page'], $list['pages'])) . '</span>';
        }
        if ($list['page'] < $list['pages']) {
            $out .= '<a class="sb-shop-pager__link sb-shop-pager__next" href="' . Html::e($q($list['page'] + 1)) . '" rel="next">' . Html::e(Html::t('shop_next', 'Next')) . '</a>';
        }
        return $out . '</nav>';
    }
}

/**
 * `shop.product_options`: one product's choices of an option (its flavours, sizes or colours) as compact chips, photo
 * swatches or photo tiles, each with its own photo, its price when the prices differ, and sold out when none of its
 * combinations is in stock. Each opens the product with that choice made (`?option=<value>`, which studio-runtime.js
 * puts in the Add to cart's picker). With "Show first", the rest wait behind a "+N more" button (a <details>, so it
 * works without JavaScript). The product is the page's own on a product page, or the one whose address is set.
 */
final class ShopProductOptionsRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'shop.product_options';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    private static function pick(BlockRenderScope $scope, string $key, array $allowed, string $default): string
    {
        $v = $scope->string($key, $default);
        return in_array($v, $allowed, true) ? $v : $default;
    }

    public function render(BlockRenderScope $scope): string
    {
        if (!class_exists('ShopStoreAPI')) {
            return '';
        }
        $slug = trim($scope->string('product'), '/ ');
        $slug = (string) preg_replace('#^.*/#', '', $slug);
        if ($slug !== '') {
            $p = \ShopStoreAPI::productBySlug($slug);
        } else {
            $p = ShopStudio::current($scope);
        }
        if ($p === null || ($p['attributes'] ?? []) === []) {
            return $scope->mode()->isPublic() ? '' : '<p class="sb-empty">' . Html::e(Html::t('shop_options_none', 'Choose a product with options, such as flavours or sizes.')) . '</p>';
        }
        $attrs = $p['attributes'];
        $index = 0;
        $wanted = mb_strtolower(trim($scope->string('attribute')));
        foreach ($attrs as $i => $a) {
            if ($wanted !== '' && mb_strtolower((string) $a['name']) === $wanted) { $index = $i; break; }
        }
        $attr = $attrs[$index];
        // Each value: a photo, the lowest price and whether anything with it is in stock, from the variants that carry it.
        $info = [];
        foreach ($p['variants'] as $v) {
            $value = (string) ($v['options'][$index] ?? '');
            if ($value === '') continue;
            $i = $info[$value] ?? ['image' => '', 'min' => null, 'in' => false];
            if ($i['image'] === '' && (string) ($v['image'] ?? '') !== '') $i['image'] = \ShopStoreAPI::assetUrl((string) $v['image']);
            $i['min'] = $i['min'] === null ? (int) $v['effective_cents'] : min($i['min'], (int) $v['effective_cents']);
            $i['in'] = $i['in'] || !empty($v['in_stock']);
            $info[$value] = $i;
        }
        $prices = array_values(array_unique(array_filter(array_column($info, 'min'), static fn($c): bool => $c !== null)));
        $showPrice = $scope->bool('show_price', false) && count($prices) > 1;
        $showPhoto = $scope->bool('show_photo', true);
        $display = self::pick($scope, 'display', ['chips', 'swatches', 'tiles'], 'chips');
        $hideSold = self::pick($scope, 'sold_out', ['muted', 'hidden'], 'muted') === 'hidden';
        $link = $scope->bool('link', true);
        $base = ShopStudio::productUrl($scope, $p);
        $items = [];
        foreach ($attr['values'] as $value) {
            $value = (string) $value;
            $i = $info[$value] ?? ['image' => '', 'min' => null, 'in' => false];
            if (!$i['in'] && $hideSold) continue;
            $photo = $showPhoto ? ($i['image'] !== '' ? $i['image'] : (string) ($p['image'] !== '' ? \ShopStoreAPI::assetUrl((string) $p['image']) : '')) : '';
            $inner = ($photo !== '' ? '<img class="sb-shop-option__photo" src="' . Html::e($photo) . '" alt="" loading="lazy">' : '')
                . '<span class="sb-shop-option__name">' . Html::e($value) . '</span>'
                . ($showPrice && $i['min'] !== null ? '<span class="sb-shop-option__price">' . Html::e(ShopStudio::money((int) $i['min'], (string) $p['currency'])) . '</span>' : '')
                . (!$i['in'] ? '<span class="sb-shop-sr"> (' . Html::e(Html::t('shop_sold_out', 'Sold out')) . ')</span>' : '');
            $class = 'sb-shop-option' . (!$i['in'] ? ' sb-shop-option--sold-out' : '');
            $items[] = '<li class="sb-shop-options__item">' . ($link
                ? '<a class="' . $class . '" href="' . Html::e($base . (str_contains($base, '?') ? '&' : '?') . 'option=' . rawurlencode($value)) . '">' . $inner . '</a>'
                : '<span class="' . $class . '">' . $inner . '</span>') . '</li>';
        }
        if ($items === []) return '';

        $classes = 'sb-shop-options sb-shop-options--' . $display . (self::pick($scope, 'density', ['regular', 'compact'], 'compact') === 'compact' ? ' sb-shop-options--compact' : '');
        // On a phone, one row that scrolls sideways (every choice in it, no "+N more").
        if ($scope->bool('phone_row', false) && $display !== 'tiles') $classes .= ' sb-shop-options--phone-row';
        $align = self::pick($scope, 'align', ['left', 'center'], 'left');
        $over = \Slate\Module\StudioBuilder\Document\DeviceProps::overrides($scope->block(), 'align');
        $jc = static fn(mixed $a): string => $a === 'center' ? 'center' : 'flex-start';
        $cols = max(2, min(8, (int) $scope->prop('columns', 6)));
        $c = $scope->deviceCounts('columns', 2, 8, min($cols, 4), min($cols, 3));
        $vars = '--sb-shop-oj-d:' . $jc($align) . ';--sb-shop-oj-t:' . $jc($over['tablet'] ?? $align) . ';--sb-shop-oj-m:' . $jc($over['mobile'] ?? ($over['tablet'] ?? $align))
            . ';--sb-shop-oc-d:' . $cols . ';--sb-shop-oc-t:' . $c['tablet'] . ';--sb-shop-oc-m:' . $c['mobile'];
        // "Show first N": the rest wait behind a "+N more" chip at the end of the list (a checkbox and its label, so it
        // works without JavaScript); opened, the chip says "Show fewer".
        $first = max(0, min(60, (int) $scope->prop('visible', 0)));
        $label = Html::e($attr['name'] !== '' ? (string) $attr['name'] : Html::t('shop_options', 'Options'));
        if ($first <= 0 || count($items) <= $first) {
            return '<ul class="' . $classes . '" style="' . Html::e($vars) . '" aria-label="' . $label . '">' . implode('', $items) . '</ul>';
        }
        $more = $scope->string('more_label');
        $more = $more !== '' ? $more : sprintf(Html::t('shop_options_more', '+%d more'), count($items) - $first);
        $id = Html::e($scope->domId('more'));
        $out = '<ul class="' . $classes . ' sb-shop-options--folded" style="' . Html::e($vars) . '" aria-label="' . $label . '">';
        foreach ($items as $n => $item) {
            $out .= $n < $first ? $item : str_replace('<li class="sb-shop-options__item">', '<li class="sb-shop-options__item sb-shop-options__item--extra">', $item);
        }
        return $out . '<li class="sb-shop-options__item sb-shop-options__item--toggle"><input class="sb-shop-sr sb-shop-options__check" type="checkbox" id="' . $id . '">'
            . '<label class="sb-shop-options__toggle" for="' . $id . '"><span class="sb-shop-options__toggle-more">' . Html::e($more) . '</span>'
            . '<span class="sb-shop-options__toggle-less">' . Html::e(Html::t('shop_options_less', 'Show fewer')) . '</span></label></li></ul>';
    }
}

/** `shop.product_image`: the product's main image and its other images, which open in the lightbox. */
final class ShopProductImageRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'shop.product_image';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $p = ShopStudio::current($scope);
        if ($p === null) {
            return ShopStudio::none($scope);
        }
        $ratio = in_array($scope->string('ratio', 'square'), ['square', 'portrait', 'landscape', 'natural'], true) ? $scope->string('ratio', 'square') : 'square';
        $images = $p['images'];
        if ($images === []) {
            return '<div class="sb-shop-gallery sb-shop-gallery--' . $ratio . '"><div class="sb-shop-gallery__main sb-shop-gallery__main--empty"></div></div>';
        }
        // Thumbnails: every photo, the first one chosen; picking one shows it as the main photo (studio-runtime.js), and
        // the main photo opens in the lightbox. Below the photo (a strip that scrolls when it runs out of room) or, on a
        // wide screen, a column on its left. Their size may differ per device.
        $thumbs = $scope->bool('show_thumbnails', true) && count($images) > 1;
        $left = $thumbs && $scope->string('thumb_position', 'below') === 'left';
        $size = max(40, min(160, (int) $scope->prop('thumb_size', 72)));
        $dev = $scope->deviceCounts('thumb_size', 40, 160, $size, min($size, 60));
        // data-sb-shop-gallery: the product's id, so its Add to cart's flavour or size picker can show that variant's photo here.
        $out = '<div class="sb-shop-gallery sb-shop-gallery--' . $ratio . ($left ? ' sb-shop-gallery--thumbs-left' : '') . '" data-sb-shop-gallery="' . (int) $p['id'] . '"'
            . ($thumbs ? ' style="--sb-shop-thumb-d:' . $size . 'px;--sb-shop-thumb-t:' . $dev['tablet'] . 'px;--sb-shop-thumb-m:' . $dev['mobile'] . 'px"' : '') . '>'
            . '<figure class="sb-shop-gallery__main sb-gallery__item"><img class="sb-shop-gallery__image" src="' . Html::e($images[0]) . '" alt="' . Html::e($p['title']) . '"></figure>';
        if ($thumbs) {
            $out .= '<div class="sb-shop-gallery__thumbs" role="group" aria-label="' . Html::e(Html::t('shop_photos', 'Photos')) . '">';
            foreach ($images as $i => $src) {
                $out .= '<button type="button" class="sb-shop-gallery__thumb" data-sb-thumb="' . Html::e($src) . '"' . ($i === 0 ? ' aria-current="true"' : '')
                    . ' aria-label="' . Html::e(sprintf(Html::t('shop_show_photo', 'Show photo %d'), $i + 1)) . '"><img src="' . Html::e($src) . '" alt="" loading="lazy"></button>';
            }
            $out .= '</div>';
        }
        return $out . '</div>';
    }
}

/** `shop.product_title`: the product's name, as the page's heading by default. */
final class ShopProductTitleRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'shop.product_title';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $p = ShopStudio::current($scope);
        if ($p === null) {
            return ShopStudio::none($scope);
        }
        $tag = in_array($scope->string('tag', 'h1'), ['h1', 'h2', 'h3', 'h4'], true) ? $scope->string('tag', 'h1') : 'h1';
        return '<' . $tag . ' class="sb-shop-title">' . Html::e($p['title']) . '</' . $tag . '>';
    }
}

/** `shop.product_price`: the price, with the sale price when on sale. */
final class ShopProductPriceRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'shop.product_price';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $p = ShopStudio::current($scope);
        return $p === null ? ShopStudio::none($scope) : ShopStudio::priceHtml($p, $scope->bool('show_vat_note', true));
    }
}

/** `shop.product_add_to_cart`: quantity and the Add to cart button (Sold out when there is no stock). */
final class ShopAddToCartRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'shop.product_add_to_cart';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $p = ShopStudio::current($scope);
        if ($p === null) {
            return ShopStudio::none($scope);
        }
        return ShopStudio::addToCartForm($scope, $p, $scope->string('button_label'), $scope->bool('show_quantity', true), $scope->bool('go_to_cart', false));
    }
}

/** `shop.product_description`: the full description, or the short one. */
final class ShopProductDescriptionRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'shop.product_description';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $p = ShopStudio::current($scope);
        if ($p === null) {
            return ShopStudio::none($scope);
        }
        $text = $scope->string('source', 'long') === 'short' ? $p['short_description'] : $p['description'];
        if (trim($text) === '') {
            return '';
        }
        $paras = '';
        foreach (preg_split('/\R{2,}/', trim($text)) ?: [] as $para) {
            $paras .= '<p>' . nl2br(Html::e(trim($para)), false) . '</p>';
        }
        return '<div class="sb-shop-description">' . $paras . '</div>';
    }
}

/** `shop.product_stock`: In stock, Only N left, or Sold out. */
final class ShopProductStockRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'shop.product_stock';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $p = ShopStudio::current($scope);
        if ($p === null) {
            return ShopStudio::none($scope);
        }
        $low = max(0, (int) $scope->prop('low_stock', 5));
        if (!$p['in_stock']) {
            [$class, $text] = ['sb-shop-stock--out', Html::t('shop_sold_out', 'Sold out')];
        } elseif ($p['stock_qty'] !== null && $p['stock_qty'] <= $low) {
            [$class, $text] = ['sb-shop-stock--low', sprintf(Html::t('shop_only_n_left', 'Only %d left'), $p['stock_qty'])];
        } else {
            if (!$scope->bool('show_in_stock', true)) {
                return '';
            }
            [$class, $text] = ['sb-shop-stock--in', Html::t('shop_in_stock', 'In stock')];
        }
        return '<p class="sb-shop-stock ' . $class . '">' . Html::e($text) . '</p>';
    }
}

/** `shop.product_meta`: SKU and categories. */
final class ShopProductMetaRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'shop.product_meta';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $p = ShopStudio::current($scope);
        if ($p === null) {
            return ShopStudio::none($scope);
        }
        $rows = '';
        if ($scope->bool('show_sku', true) && $p['sku'] !== '') {
            $rows .= '<div class="sb-shop-meta__row"><dt>' . Html::e(Html::t('shop_sku', 'SKU')) . '</dt><dd class="sb-shop-meta__sku">' . Html::e($p['sku']) . '</dd></div>';
        }
        if ($scope->bool('show_categories', true) && $p['categories'] !== []) {
            $names = implode(', ', array_map(static fn(array $c): string => Html::e($c['name']), $p['categories']));
            $rows .= '<div class="sb-shop-meta__row"><dt>' . Html::e(count($p['categories']) > 1 ? Html::t('shop_categories', 'Categories') : Html::t('shop_category', 'Category')) . '</dt><dd class="sb-shop-meta__categories">' . $names . '</dd></div>';
        }
        if ($scope->bool('show_tags', false) && ($p['tags'] ?? []) !== []) {
            $names = implode(', ', array_map(static fn(array $t): string => Html::e($t['name']), $p['tags']));
            $rows .= '<div class="sb-shop-meta__row"><dt>' . Html::e(Html::t('shop_tags', 'Tags')) . '</dt><dd class="sb-shop-meta__tags">' . $names . '</dd></div>';
        }
        if ($scope->bool('show_weight', false) && ($p['weight'] ?? '') !== '') {
            $rows .= '<div class="sb-shop-meta__row"><dt>' . Html::e(Html::t('shop_weight', 'Weight')) . '</dt><dd class="sb-shop-meta__weight">' . Html::e($p['weight'] . ' ' . $p['weight_unit']) . '</dd></div>';
        }
        if ($scope->bool('show_dimensions', false) && ($p['dimensions'] ?? '') !== '') {
            $rows .= '<div class="sb-shop-meta__row"><dt>' . Html::e(Html::t('shop_size', 'Size')) . '</dt><dd class="sb-shop-meta__dimensions">' . Html::e($p['dimensions']) . '</dd></div>';
        }
        return $rows === '' ? '' : '<dl class="sb-shop-meta">' . $rows . '</dl>';
    }
}

/** `shop.mini_cart`: a link to the cart with the visitor's item count, for a header. */
final class ShopMiniCartRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'shop.mini_cart';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $cartUrl = class_exists('ShopStoreAPI') ? \ShopStoreAPI::pageUrl('cart_page') : $scope->site()->absoluteUrl('cart');
        $label = $scope->string('label') !== '' ? $scope->string('label') : Html::t('shop_cart', 'Cart');
        $fallback = '<a class="sb-shop-mini__link" href="' . Html::e($cartUrl) . '"><span class="sb-shop-mini__label">' . Html::e($label) . '</span></a>';
        $url = ShopStudio::endpoint($scope, 'cart.php') . '?view=mini&total=' . ($scope->bool('show_total', false) ? '1' : '0')
            . ($scope->string('label') !== '' ? '&label=' . rawurlencode($scope->string('label')) : '');
        return ShopStudio::fragment($scope, 'sb-shop-mini', $url, $fallback, 'shop-cart');
    }
}

/** `shop.cart`: the visitor's cart with quantities, totals and the way to checkout (the Cart page). */
final class ShopCartRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'shop.cart';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $url = ShopStudio::endpoint($scope, 'cart.php');
        return ShopStudio::fragment($scope, 'sb-shop-cart-block', $url . '?view=cart',
            ShopStudio::fallbackLink($url, Html::t('shop_view_cart', 'View your cart')), 'shop-cart');
    }
}

/** `shop.checkout`: contact, delivery, the payment choice and the summary, then on to the gateway's payment page. */
final class ShopCheckoutRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'shop.checkout';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $url = ShopStudio::endpoint($scope, 'checkout.php');
        return ShopStudio::fragment($scope, 'sb-shop-checkout-block', $url,
            ShopStudio::fallbackLink($url, Html::t('shop_go_checkout', 'Proceed to checkout')), '', ['cancelled']);
    }
}

/** `shop.order_confirmation`: the order the page's address names (`?order=…&key=…`), on the order-received page. */
final class ShopOrderConfirmationRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'shop.order_confirmation';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $heading = $scope->string('heading');
        $fallback = '<p class="sb-shop-order__lead">' . Html::e(Html::t('shop_order_loading', 'Your order details appear here.')) . '</p>';
        return ($heading !== '' ? '<h2 class="sb-shop-order__heading">' . Html::e($heading) . '</h2>' : '')
            . ShopStudio::fragment($scope, 'sb-shop-order-block', ShopStudio::endpoint($scope, 'order.php'), $fallback, '', ['order', 'key']);
    }
}
