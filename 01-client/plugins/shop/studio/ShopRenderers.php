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

/** `shop.products`: a grid of products with an optional category filter and a pager. */
final class ShopProductsRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'shop.products';
    }

    public function isDynamic(): bool
    {
        return true;
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
        $showFilter = $scope->bool('show_filter', false) && $fixed === '';
        $chosen = $showFilter && is_string($_GET['category'] ?? null) && preg_match('/^[a-z0-9-]{1,120}$/', $_GET['category']) === 1 ? $_GET['category'] : '';
        $category = $fixed !== '' ? $fixed : $chosen;
        $perPage = max(1, min(48, (int) $scope->prop('per_page', 12)));
        $page = $scope->mode()->isPublic() ? max(1, (int) ($_GET['page'] ?? 1)) : 1;
        try {
            $list = \ShopStoreAPI::listProducts(['category' => $category, 'order' => $order, 'per_page' => $perPage, 'page' => $page]);
            $categories = $showFilter ? \ShopStoreAPI::categories() : [];
        } catch (\Throwable) {
            return '';
        }

        $columns = max(1, min(6, (int) $scope->prop('columns', 3)));
        $heading = $scope->string('heading');
        $out = '<div class="sb-shop-products" style="--sb-shop-cols:' . $columns . '">'
            . ($heading !== '' ? '<h2 class="sb-shop-products__heading">' . Html::e($heading) . '</h2>' : '');

        if ($showFilter && $categories !== []) {
            $link = static fn(string $slug, string $label): string => '<a class="sb-shop-filter__link" href="' . Html::e($slug === '' ? '?' : '?category=' . rawurlencode($slug)) . '"'
                . ($slug === $chosen ? ' aria-current="true"' : '') . '>' . Html::e($label) . '</a>';
            $out .= '<nav class="sb-shop-filter" aria-label="' . Html::e(Html::t('shop_categories', 'Categories')) . '">' . $link('', Html::t('shop_all', 'All'));
            foreach ($categories as $c) {
                if ($c['product_count'] > 0) {
                    $out .= $link($c['slug'], $c['name']);
                }
            }
            $out .= '</nav>';
        }

        if ($list['rows'] === []) {
            $empty = $scope->string('empty_message');
            return $out . '<p class="sb-empty sb-shop-products__empty">' . Html::e($empty !== '' ? $empty : Html::t('shop_no_products', 'No products yet.')) . '</p></div>';
        }

        $showPrice = $scope->bool('show_price', true);
        $showAdd = $scope->bool('show_add_to_cart', true);
        $label = $scope->string('button_label');
        $out .= '<ul class="sb-shop-products__grid">';
        foreach ($list['rows'] as $p) {
            $url = ShopStudio::productUrl($scope, $p);
            $out .= '<li class="sb-shop-card">'
                . '<a class="sb-shop-card__media" href="' . Html::e($url) . '" tabindex="-1" aria-hidden="true">'
                . ($p['image'] !== '' ? '<img class="sb-shop-card__image" src="' . Html::e($p['image']) . '" alt="" loading="lazy">' : '<span class="sb-shop-card__placeholder"></span>')
                . (!$p['in_stock'] ? '<span class="sb-shop-card__badge">' . Html::e(Html::t('shop_sold_out', 'Sold out')) . '</span>' : ($p['on_sale'] ? '<span class="sb-shop-card__badge sb-shop-card__badge--sale">' . Html::e(Html::t('shop_sale', 'Sale')) . '</span>' : ''))
                . '</a><div class="sb-shop-card__body">'
                . '<h3 class="sb-shop-card__title"><a href="' . Html::e($url) . '">' . Html::e($p['title']) . '</a></h3>'
                . ($showPrice ? ShopStudio::priceHtml($p) : '')
                . ($showAdd ? ShopStudio::addToCartForm($scope, $p, $label, false, false, 'sb-shop-card__add') : '')
                . '</div></li>';
        }
        $out .= '</ul>';

        if ($scope->bool('show_pager', true) && $list['pages'] > 1) {
            $q = static fn(int $n): string => '?' . http_build_query(array_filter(['category' => $chosen, 'page' => $n > 1 ? $n : null]));
            $out .= '<nav class="sb-shop-pager" aria-label="' . Html::e(Html::t('shop_pages', 'Pages')) . '">';
            if ($list['page'] > 1) {
                $out .= '<a class="sb-shop-pager__link sb-shop-pager__prev" href="' . Html::e($q($list['page'] - 1)) . '" rel="prev">' . Html::e(Html::t('shop_previous', 'Previous')) . '</a>';
            }
            for ($n = 1; $n <= $list['pages']; $n++) {
                $out .= $n === $list['page']
                    ? '<span class="sb-shop-pager__link" aria-current="page">' . $n . '</span>'
                    : '<a class="sb-shop-pager__link" href="' . Html::e($q($n)) . '">' . $n . '</a>';
            }
            if ($list['page'] < $list['pages']) {
                $out .= '<a class="sb-shop-pager__link sb-shop-pager__next" href="' . Html::e($q($list['page'] + 1)) . '" rel="next">' . Html::e(Html::t('shop_next', 'Next')) . '</a>';
            }
            $out .= '</nav>';
        }
        return $out . '</div>';
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
        $out = '<div class="sb-shop-gallery sb-shop-gallery--' . $ratio . '">'
            . '<figure class="sb-shop-gallery__main sb-gallery__item"><img class="sb-shop-gallery__image" src="' . Html::e($images[0]) . '" alt="' . Html::e($p['title']) . '"></figure>';
        if ($scope->bool('show_thumbnails', true) && count($images) > 1) {
            $out .= '<div class="sb-shop-gallery__thumbs">';
            foreach (array_slice($images, 1) as $i => $src) {
                $out .= '<figure class="sb-shop-gallery__thumb sb-gallery__item"><img src="' . Html::e($src) . '" alt="' . Html::e(sprintf('%s (%d)', $p['title'], $i + 2)) . '" loading="lazy"></figure>';
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
        $url = ShopStudio::endpoint($scope, 'cart.php') . '?view=mini&total=' . ($scope->bool('show_total', false) ? '1' : '0');
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

/** `shop.checkout`: contact, delivery and the summary, then on to Stripe (the Checkout page). */
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
