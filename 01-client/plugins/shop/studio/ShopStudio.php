<?php
/**
 * Shop — what the Studio widgets share: the product a widget shows, prices, the Add to cart form and the placeholders
 * studio-runtime.js fills with a visitor's own cart.
 *
 * A single product widget shows the product of the page it is on: the `single_item` the public runtime puts in the
 * render context for `/product/{slug}` (StudioPublicRuntime::handlePluginSingle). While an author edits or previews a
 * Single template there is no such product, so the widgets show the newest published one as a sample.
 */

declare(strict_types=1);

namespace Slate\Plugin\Shop\Studio;

use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\RenderMode;

final class ShopStudio
{
    /** The product a single product widget shows, in ShopStoreAPI's public shape, or null. */
    public static function current(BlockRenderScope $scope): ?array
    {
        $item = $scope->context()->attribute('single_item');
        if (is_array($item) && ($item['type'] ?? '') === 'product' && is_array($item['data'] ?? null)) {
            return $item['data'];
        }
        return $scope->mode()->isPrivate() ? self::sample() : null;
    }

    /** The product authors see while they edit or preview a template: the first in the shop's own order. */
    public static function sample(): ?array
    {
        if (!class_exists('ShopStoreAPI')) {
            return null;
        }
        try {
            return \ShopStoreAPI::listProducts(['per_page' => 1])['rows'][0] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Nothing for visitors; a short note for authors (no product yet, or the widget is off a product page). */
    public static function none(BlockRenderScope $scope): string
    {
        return $scope->showsDiagnostics()
            ? '<div class="sb-unavailable" role="note">' . Html::e(Html::t('shop_studio_no_product', 'Shows the product of a product page. Add a published product to see it here.')) . '</div>'
            : '';
    }

    /** An absolute address of a Shop endpoint (`cart.php`). */
    public static function endpoint(BlockRenderScope $scope, string $file): string
    {
        return $scope->site()->absoluteUrl('plugins/shop/public/' . $file);
    }

    /** An absolute address of a product (its `url` is site-relative). */
    public static function productUrl(BlockRenderScope $scope, array $product): string
    {
        return $scope->site()->absoluteUrl((string) ($product['url'] ?? '/'));
    }

    public static function money(int $cents, string $currency): string
    {
        return class_exists('ShopStoreAPI') ? \ShopStoreAPI::money($cents, $currency) : trim(number_format($cents / 100, 2) . ' ' . $currency);
    }

    /** The price: the sale price beside the struck-out regular one while on sale. */
    public static function priceHtml(array $product, bool $vatNote = false): string
    {
        $cur = (string) ($product['currency'] ?? '');
        $html = !empty($product['on_sale'])
            ? '<del class="sb-shop-price__regular"><span class="sb-shop-sr">' . Html::e(Html::t('shop_was', 'Was')) . ' </span>' . Html::e(self::money((int) $product['price_cents'], $cur)) . '</del> '
                . '<ins class="sb-shop-price__sale"><span class="sb-shop-sr">' . Html::e(Html::t('shop_now', 'Now')) . ' </span>' . Html::e(self::money((int) $product['effective_cents'], $cur)) . '</ins>'
            : '<span class="sb-shop-price__amount">' . Html::e(self::money((int) $product['price_cents'], $cur)) . '</span>';
        return '<p class="sb-shop-price' . (!empty($product['on_sale']) ? ' sb-shop-price--sale' : '') . '">' . $html
            . ($vatNote ? ' <span class="sb-shop-price__vat">' . Html::e(Html::t('shop_incl_vat', 'incl. VAT')) . '</span>' : '') . '</p>';
    }

    /**
     * The Add to cart form. It posts to the cart endpoint like any form (no JavaScript: the visitor comes back to the
     * page, or goes to the cart). With the runtime it is sent in place and the mini cart refreshes (`shop-cart`).
     */
    public static function addToCartForm(BlockRenderScope $scope, array $product, string $label, bool $quantity, bool $goToCart, string $class = ''): string
    {
        if (empty($product['in_stock'])) {
            return '<p class="sb-shop-add sb-shop-add--sold-out' . ($class !== '' ? ' ' . $class : '') . '"><span class="sb-button sb-shop-add__button" aria-disabled="true">' . Html::e(Html::t('shop_sold_out', 'Sold out')) . '</span></p>';
        }
        $id = (int) $product['id'];
        $max = $product['stock_qty'] === null ? 99 : min(99, (int) $product['stock_qty']);
        $qtyId = $scope->domId('qty-' . $id);
        $label = $label !== '' ? $label : Html::t('shop_add_to_cart', 'Add to cart');
        $return = (string) (parse_url(self::productUrl($scope, $product), PHP_URL_PATH) ?? '');
        return '<form class="sb-shop-add' . ($class !== '' ? ' ' . $class : '') . '" method="post" action="' . Html::e(self::endpoint($scope, 'cart.php')) . '"'
            . ($goToCart ? '' : ' data-sb-fragment-form data-sb-fragment-group="shop-cart"') . '>'
            . '<input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="' . $id . '">'
            . ($goToCart ? '' : '<input type="hidden" name="return" value="' . Html::e($return) . '">')
            . ($quantity
                ? '<label class="sb-shop-sr" for="' . Html::e($qtyId) . '">' . Html::e(Html::t('shop_quantity', 'Quantity')) . '</label>'
                    . '<input class="sb-shop-add__qty" id="' . Html::e($qtyId) . '" type="number" name="qty" value="1" min="1" max="' . $max . '" inputmode="numeric">'
                : '<input type="hidden" name="qty" value="1">')
            . '<button type="submit" class="sb-button sb-button--primary sb-shop-add__button">' . Html::e($label) . '</button>'
            . '<span class="sb-shop-add__message" data-sb-fragment-message role="status" aria-live="polite" data-sb-fragment-error="' . Html::e(Html::t('shop_err_try_again', 'Something went wrong. Please try again.')) . '"></span>'
            . '</form>';
    }

    /**
     * A placeholder studio-runtime.js fills from a Shop endpoint with this visitor's content; until then (and without
     * JavaScript) it holds `$fallback`.
     *
     * @param list<string> $query names of the page address's own query parameters to pass on (`order`, `key`)
     */
    public static function fragment(BlockRenderScope $scope, string $class, string $url, string $fallback, string $group = '', array $query = []): string
    {
        if ($scope->mode() === RenderMode::Editor) {
            // The editor canvas runs no script: show what the block is.
            return '<div class="' . Html::e($class) . ' sb-shop-fragment">' . $fallback . '</div>';
        }
        return '<div class="' . Html::e($class) . ' sb-shop-fragment" data-sb-fragment="' . Html::e($url) . '"'
            . ($group !== '' ? ' data-sb-fragment-group="' . Html::e($group) . '"' : '')
            . ($query !== [] ? ' data-sb-fragment-query="' . Html::e(implode(',', $query)) . '"' : '')
            . ' aria-live="polite">' . $fallback . '</div>';
    }

    /** A link a fallback uses: to a Shop endpoint's own plain page. */
    public static function fallbackLink(string $url, string $label, string $class = 'sb-link'): string
    {
        return '<p class="sb-shop-fallback"><a class="' . Html::e($class) . '" href="' . Html::e($url) . '">' . Html::e($label) . '</a></p>';
    }
}
