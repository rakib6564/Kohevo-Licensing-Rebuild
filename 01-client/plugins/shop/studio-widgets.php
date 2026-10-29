<?php
/**
 * Shop — the widgets this plugin offers in Kohevo Studio (Add › Components), their style parts, the data provider the
 * Product grid binds to, and products as a content type Studio shows through a Single template (`/product/{slug}`).
 *
 * Read by Studio's PluginWidgetCatalog whether or not Shop is active, so pages that hold these blocks keep validating;
 * the `shop` entitlement (plugin active and licensed) decides whether they can be inserted and whether they render.
 */

declare(strict_types=1);

// Only ever included by Studio; a direct web request gets nothing.
if (!defined('SLATE_ROOT')) {
    http_response_code(404);
    exit;
}

use Slate\Module\StudioBuilder\Registry\DeclarativeBlockDefinition;
use Slate\Module\StudioBuilder\Render\Icon\IconLibrary;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Plugin\Shop\Studio as S;

require_once __DIR__ . '/ShopStoreAPI.php';
require_once __DIR__ . '/studio/ShopStudio.php';
require_once __DIR__ . '/studio/ShopRenderers.php';
require_once __DIR__ . '/studio/ShopProductsProvider.php';
require_once __DIR__ . '/studio/ShopProductSingle.php';

$block = static fn(string $type, string $label, string $description, string $icon, array $fields, array $more = []): DeclarativeBlockDefinition => new DeclarativeBlockDefinition(
    type: $type,
    version: 1,
    label: $label,
    title: $label,
    description: $description,
    category: 'business',
    icon: $icon,
    schema: FieldSchema::define($fields),
    requiredEntitlement: 'shop',
    allowedBindingProviders: $more['providers'] ?? [],
    bindingSlots: $more['slots'] ?? [],
);
$bool = static fn(string $key, string $label, bool $default): array => ['key' => $key, 'type' => 'boolean', 'label' => $label, 'required' => false, 'default' => $default];
$text = static fn(string $key, string $label, int $max = 80): array => ['key' => $key, 'type' => 'string', 'label' => $label, 'required' => false, 'default' => '', 'max_length' => $max];

return [
    'blocks' => [
        $block('shop.products', 'Product Grid', 'Your products in a grid, with a category filter and pages', 'grid-panes', [
            $text('heading', 'Heading', 200),
            ['key' => 'columns', 'type' => 'number', 'label' => 'Columns', 'required' => false, 'default' => 3, 'min' => 1, 'max' => 6, 'integer_only' => true],
            ['key' => 'order', 'type' => 'enum', 'label' => 'Order', 'required' => false, 'default' => 'menu', 'allowed_values' => S\ShopProductsProvider::ORDERS],
            ['key' => 'per_page', 'type' => 'number', 'label' => 'Products per page', 'required' => false, 'default' => 12, 'min' => 1, 'max' => 48, 'integer_only' => true],
            $bool('show_filter', 'Show category filter', false),
            $bool('show_pager', 'Show pages', true),
            $bool('show_price', 'Show prices', true),
            $bool('show_add_to_cart', 'Show Add to cart', true),
            $text('button_label', 'Button label'),
            $text('link_label', 'Product link text (instead of the button)'),
            $text('options_label', 'Button label for products with options'),
            $bool('show_icon', 'Show an icon on the card', false),
            ['key' => 'icon', 'type' => 'enum', 'label' => 'Card icon', 'required' => false, 'default' => 'heart', 'allowed_values' => IconLibrary::names()],
            $text('empty_message', 'Message when empty', 200),
        ], ['providers' => ['shop.products'], 'slots' => ['items' => 'shop.products']]),
        $block('shop.product_image', 'Product Images', 'The product\'s image and its other images', 'gallery', [
            ['key' => 'ratio', 'type' => 'enum', 'label' => 'Image shape', 'required' => false, 'default' => 'square', 'allowed_values' => ['square', 'portrait', 'landscape', 'natural']],
            $bool('show_thumbnails', 'Show other images', true),
        ]),
        $block('shop.product_title', 'Product Title', 'The product\'s name', 'heading', [
            ['key' => 'tag', 'type' => 'enum', 'label' => 'HTML Tag', 'required' => false, 'default' => 'h1', 'allowed_values' => ['h1', 'h2', 'h3', 'h4']],
        ]),
        $block('shop.product_price', 'Product Price', 'The price, and the sale price when on sale', 'list', [
            $bool('show_vat_note', 'Show "incl. VAT"', true),
        ]),
        $block('shop.product_add_to_cart', 'Add to Cart', 'Options, quantity and the Add to cart button', 'arrow-up-right', [
            $text('button_label', 'Button label'),
            $bool('show_quantity', 'Show quantity', true),
            $bool('go_to_cart', 'Go to the cart after adding', false),
        ]),
        $block('shop.product_description', 'Product Description', 'The product\'s description', 'pilcrow', [
            ['key' => 'source', 'type' => 'enum', 'label' => 'Text', 'required' => false, 'default' => 'long', 'allowed_values' => ['long', 'short']],
        ]),
        $block('shop.product_stock', 'Product Stock', 'In stock, only a few left, or sold out', 'list-check', [
            ['key' => 'low_stock', 'type' => 'number', 'label' => 'Say "only N left" from', 'required' => false, 'default' => 5, 'min' => 0, 'max' => 100, 'integer_only' => true],
            $bool('show_in_stock', 'Show "In stock"', true),
        ]),
        $block('shop.product_meta', 'Product Meta', 'SKU and categories', 'list', [
            $bool('show_sku', 'Show SKU', true),
            $bool('show_categories', 'Show categories', true),
        ]),
        $block('shop.mini_cart', 'Mini Cart', 'A cart link with the item count, for your header', 'box', [
            $text('label', 'Label', 40),
            $bool('show_total', 'Show the cart total', false),
        ]),
        $block('shop.cart', 'Cart', 'The visitor\'s cart, for your Cart page', 'table', []),
        $block('shop.checkout', 'Checkout', 'Details, delivery and payment, for your Checkout page', 'form', []),
        $block('shop.order_confirmation', 'Order Confirmation', 'The order just placed, for your order-received page', 'list-check', [
            $text('heading', 'Heading', 200),
        ]),
    ],
    'renderers' => [
        new S\ShopProductsRenderer(),
        new S\ShopProductImageRenderer(),
        new S\ShopProductTitleRenderer(),
        new S\ShopProductPriceRenderer(),
        new S\ShopAddToCartRenderer(),
        new S\ShopProductDescriptionRenderer(),
        new S\ShopProductStockRenderer(),
        new S\ShopProductMetaRenderer(),
        new S\ShopMiniCartRenderer(),
        new S\ShopCartRenderer(),
        new S\ShopCheckoutRenderer(),
        new S\ShopOrderConfirmationRenderer(),
    ],
    'providers' => [
        new S\ShopProductsProvider(),
    ],
    'singles' => [
        new S\ShopProductSingle(),
    ],
    // What an author can style on its own (Style › Parts). Selectors are inside the block (BlockParts).
    'parts' => [
        'shop.products' => [
            'card'    => ['label' => 'Card', 'selectors' => ['.sb-shop-card']],
            'image'   => ['label' => 'Image', 'selectors' => ['.sb-shop-card__media']],
            'body'    => ['label' => 'Card text', 'selectors' => ['.sb-shop-card__body']],
            'icon'    => ['label' => 'Icon', 'selectors' => ['.sb-shop-card__icon']],
            'link'    => ['label' => 'Product link', 'selectors' => ['.sb-shop-card__link']],
            'title'   => ['label' => 'Title', 'selectors' => ['.sb-shop-card__title', '.sb-shop-card__title a']],
            'price'   => ['label' => 'Price', 'selectors' => ['.sb-shop-card .sb-shop-price']],
            'button'  => ['label' => 'Button', 'selectors' => ['.sb-shop-card .sb-shop-add__button']],
            'badge'   => ['label' => 'Badge', 'selectors' => ['.sb-shop-card__badge']],
            'filter'  => ['label' => 'Category filter', 'selectors' => ['.sb-shop-filter__link']],
            'pager'   => ['label' => 'Pages', 'selectors' => ['.sb-shop-pager__link']],
            'grid'    => ['label' => 'Cards layout', 'selectors' => ['.sb-shop-products__grid']],
        ],
        'shop.product_image' => [
            'main'   => ['label' => 'Main image', 'selectors' => ['.sb-shop-gallery__main']],
            'thumbs' => ['label' => 'Other images', 'selectors' => ['.sb-shop-gallery__thumb']],
        ],
        'shop.product_price' => [
            'regular' => ['label' => 'Regular price', 'selectors' => ['.sb-shop-price__amount', '.sb-shop-price__regular']],
            'sale'    => ['label' => 'Sale price', 'selectors' => ['.sb-shop-price__sale']],
            'vat'     => ['label' => 'VAT note', 'selectors' => ['.sb-shop-price__vat']],
        ],
        'shop.product_add_to_cart' => [
            'button'   => ['label' => 'Button', 'selectors' => ['.sb-shop-add__button']],
            'options'  => ['label' => 'Option pickers', 'selectors' => ['.sb-shop-add__select']],
            'option_labels' => ['label' => 'Option names', 'selectors' => ['.sb-shop-add__option-label']],
            'variant_price' => ['label' => 'Chosen price', 'selectors' => ['.sb-shop-add__price']],
            'quantity' => ['label' => 'Quantity', 'selectors' => ['.sb-shop-add__qty']],
            'message'  => ['label' => 'Message', 'selectors' => ['.sb-shop-add__message']],
        ],
        'shop.product_stock' => [
            'in'  => ['label' => 'In stock', 'selectors' => ['.sb-shop-stock--in']],
            'low' => ['label' => 'Few left', 'selectors' => ['.sb-shop-stock--low']],
            'out' => ['label' => 'Sold out', 'selectors' => ['.sb-shop-stock--out']],
        ],
        'shop.product_meta' => [
            'label' => ['label' => 'Labels', 'selectors' => ['.sb-shop-meta dt']],
            'value' => ['label' => 'Values', 'selectors' => ['.sb-shop-meta dd']],
        ],
        'shop.mini_cart' => [
            'link'  => ['label' => 'Link', 'selectors' => ['.sb-shop-mini__link']],
            'count' => ['label' => 'Count', 'selectors' => ['.sb-shop-mini__count']],
            'total' => ['label' => 'Total', 'selectors' => ['.sb-shop-mini__total']],
        ],
        'shop.cart' => [
            'table'  => ['label' => 'Table', 'selectors' => ['.sb-shop-cart__table']],
            'title'  => ['label' => 'Product names', 'selectors' => ['.sb-shop-cart__title']],
            'options' => ['label' => 'Chosen options', 'selectors' => ['.sb-shop-cart__options']],
            'totals' => ['label' => 'Totals', 'selectors' => ['.sb-shop-cart__totals']],
            'button' => ['label' => 'Checkout button', 'selectors' => ['.sb-shop-cart__checkout']],
        ],
        'shop.checkout' => [
            'fields'  => ['label' => 'Fields', 'selectors' => ['.sb-shop-checkout__field input', '.sb-shop-checkout__field select', '.sb-shop-checkout__field textarea']],
            'labels'  => ['label' => 'Labels', 'selectors' => ['.sb-shop-checkout__field label', '.sb-shop-checkout legend']],
            'summary' => ['label' => 'Order summary', 'selectors' => ['.sb-shop-summary']],
            'button'  => ['label' => 'Pay button', 'selectors' => ['.sb-shop-checkout__pay']],
        ],
        'shop.order_confirmation' => [
            'heading' => ['label' => 'Heading', 'selectors' => ['.sb-shop-order__heading']],
            'lead'    => ['label' => 'Message', 'selectors' => ['.sb-shop-order__lead']],
            'total'   => ['label' => 'Total', 'selectors' => ['.sb-shop-order__total']],
        ],
    ],
];
