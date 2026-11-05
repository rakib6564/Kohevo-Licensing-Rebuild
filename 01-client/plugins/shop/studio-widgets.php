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
        $block('shop.products', 'Product Grid', 'Your products in a grid, with a filter bar, sorting, search and pages', 'grid-panes', [
            // What it shows.
            $text('heading', 'Heading', 200),
            ['key' => 'source', 'type' => 'enum', 'label' => 'Show', 'required' => false, 'default' => 'all', 'allowed_values' => S\ShopProductsProvider::SOURCES],
            $text('tag', 'Only products with this tag', 80),
            ['key' => 'order', 'type' => 'enum', 'label' => 'Order', 'required' => false, 'default' => 'menu', 'allowed_values' => S\ShopProductsProvider::ORDERS],
            ['key' => 'per_page', 'type' => 'number', 'label' => 'Products per page', 'required' => false, 'default' => 12, 'min' => 1, 'max' => 48, 'integer_only' => true],
            // Layout: columns, gaps and alignment may differ on tablet and phone.
            ['key' => 'columns', 'type' => 'number', 'label' => 'Columns', 'required' => false, 'per_device' => true, 'default' => 3, 'min' => 1, 'max' => 6, 'integer_only' => true],
            ['key' => 'gap', 'type' => 'number', 'label' => 'Column gap (px)', 'required' => false, 'per_device' => true, 'default' => 24, 'min' => 0, 'max' => 80, 'integer_only' => true],
            ['key' => 'row_gap', 'type' => 'number', 'label' => 'Row gap (px)', 'required' => false, 'per_device' => true, 'default' => 24, 'min' => 0, 'max' => 120, 'integer_only' => true],
            ['key' => 'align', 'type' => 'enum', 'label' => 'Card alignment', 'required' => false, 'per_device' => true, 'default' => 'left', 'allowed_values' => ['left', 'center']],
            // The card.
            ['key' => 'density', 'type' => 'enum', 'label' => 'Size', 'required' => false, 'default' => 'regular', 'allowed_values' => ['regular', 'compact']],
            ['key' => 'card_style', 'type' => 'enum', 'label' => 'Card style', 'required' => false, 'default' => 'plain', 'allowed_values' => ['plain', 'outlined', 'raised', 'filled']],
            ['key' => 'card_padding', 'type' => 'number', 'label' => 'Card text padding (px)', 'required' => false, 'per_device' => true, 'min' => 0, 'max' => 48, 'integer_only' => true],
            ['key' => 'card_radius', 'type' => 'number', 'label' => 'Corner radius (px)', 'required' => false, 'min' => 0, 'max' => 40, 'integer_only' => true],
            ['key' => 'image_ratio', 'type' => 'enum', 'label' => 'Image shape', 'required' => false, 'default' => 'square', 'allowed_values' => ['square', 'portrait', 'tall', 'landscape', 'natural']],
            ['key' => 'image_fit', 'type' => 'enum', 'label' => 'Image fit', 'required' => false, 'default' => 'cover', 'allowed_values' => ['cover', 'contain']],
            ['key' => 'image_hover', 'type' => 'enum', 'label' => 'Image on hover', 'required' => false, 'default' => 'zoom', 'allowed_values' => ['none', 'zoom', 'second_image']],
            $bool('show_category', 'Show the category', false),
            ['key' => 'title_lines', 'type' => 'enum', 'label' => 'Title lines', 'required' => false, 'default' => 'all', 'allowed_values' => ['all', '1', '2', '3']],
            $bool('show_excerpt', 'Show the short description', false),
            $bool('show_price', 'Show prices', true),
            // Badges.
            $bool('show_badges', 'Show badges', true),
            ['key' => 'sale_badge', 'type' => 'enum', 'label' => 'Sale badge', 'required' => false, 'default' => 'text', 'allowed_values' => ['text', 'percent']],
            ['key' => 'new_days', 'type' => 'number', 'label' => '"New" badge for (days)', 'required' => false, 'default' => 0, 'min' => 0, 'max' => 365, 'integer_only' => true],
            ['key' => 'badge_position', 'type' => 'enum', 'label' => 'Badge position', 'required' => false, 'default' => 'top_left', 'allowed_values' => ['top_left', 'top_right']],
            // Buying.
            $bool('show_add_to_cart', 'Show Add to cart', true),
            ['key' => 'button_placement', 'type' => 'enum', 'label' => 'Add to cart placement', 'required' => false, 'default' => 'below', 'allowed_values' => ['below', 'on_image']],
            ['key' => 'button_width', 'type' => 'enum', 'label' => 'Button width', 'required' => false, 'default' => 'auto', 'allowed_values' => ['auto', 'full']],
            $text('button_label', 'Button label'),
            $text('link_label', 'Product link text (instead of the button)'),
            $text('options_label', 'Button label for products with options'),
            $bool('show_icon', 'Show an icon on the card', false),
            ['key' => 'icon', 'type' => 'string', 'format' => 'icon', 'max_length' => 40, 'pattern' => '/^[a-z0-9-]{0,40}$/', 'label' => 'Card icon', 'required' => false, 'default' => 'heart'],
            // The filter bar above the grid.
            $bool('show_filter', 'Show category filter', false),
            ['key' => 'filter_style', 'type' => 'enum', 'label' => 'Filter style', 'required' => false, 'default' => 'chips', 'allowed_values' => ['chips', 'underline', 'dropdown']],
            $text('all_label', 'Label for all products', 40),
            $bool('show_search', 'Show a search box', false),
            $bool('show_sort', 'Let visitors sort', false),
            $bool('show_count', 'Show the number of products', false),
            // Pages.
            $bool('show_pager', 'Show pages', true),
            ['key' => 'pagination', 'type' => 'enum', 'label' => 'Pages as', 'required' => false, 'default' => 'numbers', 'allowed_values' => ['numbers', 'prev_next', 'load_more']],
            $text('load_more_label', 'Load more label', 40),
            $text('empty_message', 'Message when empty', 200),
        ], ['providers' => ['shop.products'], 'slots' => ['items' => 'shop.products']]),
        $block('shop.product_image', 'Product Images', 'The product\'s image and its other images', 'gallery', [
            ['key' => 'ratio', 'type' => 'enum', 'label' => 'Image shape', 'required' => false, 'default' => 'square', 'allowed_values' => ['square', 'portrait', 'landscape', 'natural']],
            $bool('show_thumbnails', 'Show other images', true),
            ['key' => 'thumb_position', 'type' => 'enum', 'label' => 'Thumbnails', 'required' => false, 'default' => 'below', 'allowed_values' => ['below', 'left']],
            ['key' => 'thumb_size', 'type' => 'number', 'label' => 'Thumbnail size (px)', 'required' => false, 'per_device' => true, 'default' => 72, 'min' => 40, 'max' => 160, 'integer_only' => true],
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
        $block('shop.product_meta', 'Product Meta', 'SKU, categories, tags, weight and size', 'list', [
            $bool('show_sku', 'Show SKU', true),
            $bool('show_categories', 'Show categories', true),
            $bool('show_tags', 'Show tags', false),
            $bool('show_weight', 'Show weight', false),
            $bool('show_dimensions', 'Show size', false),
        ]),
        $block('shop.product_options', 'Product Options', 'A product\'s flavours or sizes as swatches that open it with that choice made', 'list', [
            $text('product', 'Product address (empty: this product page\'s)', 190),
            $text('attribute', 'Option (empty: the first)', 60),
            ['key' => 'display', 'type' => 'enum', 'label' => 'Show as', 'required' => false, 'default' => 'chips', 'allowed_values' => ['chips', 'swatches', 'tiles']],
            ['key' => 'density', 'type' => 'enum', 'label' => 'Size', 'required' => false, 'default' => 'compact', 'allowed_values' => ['regular', 'compact']],
            ['key' => 'align', 'type' => 'enum', 'label' => 'Alignment', 'required' => false, 'per_device' => true, 'default' => 'left', 'allowed_values' => ['left', 'center']],
            ['key' => 'columns', 'type' => 'number', 'label' => 'Columns (tiles)', 'required' => false, 'per_device' => true, 'default' => 6, 'min' => 2, 'max' => 8, 'integer_only' => true],
            $bool('show_photo', 'Show each choice\'s photo', true),
            $bool('show_price', 'Show prices that differ', false),
            ['key' => 'sold_out', 'type' => 'enum', 'label' => 'Sold-out choices', 'required' => false, 'default' => 'muted', 'allowed_values' => ['muted', 'hidden']],
            ['key' => 'visible', 'type' => 'number', 'label' => 'Show first (0: all)', 'required' => false, 'default' => 0, 'min' => 0, 'max' => 60, 'integer_only' => true],
            $text('more_label', '"More" label', 40),
            $bool('phone_row', 'One scrolling row on phones', false),
            $bool('link', 'Open the product with the choice made', true),
        ]),
        $block('shop.mini_cart', 'Mini Cart', 'A cart link with the item count, for your header', 'box', [
            $text('label', 'Label', 40),
            $bool('show_total', 'Show the cart total', false),
            $bool('hide_when_empty', 'Hide until the cart has an item', false),
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
        new S\ShopProductOptionsRenderer(),
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
            'category' => ['label' => 'Category', 'selectors' => ['.sb-shop-card__category']],
            'excerpt' => ['label' => 'Short description', 'selectors' => ['.sb-shop-card__excerpt']],
            'sale_badge' => ['label' => 'Sale badge', 'selectors' => ['.sb-shop-card__badge--sale']],
            'toolbar' => ['label' => 'Filter bar', 'selectors' => ['.sb-shop-toolbar']],
            'filter'  => ['label' => 'Category filter', 'selectors' => ['.sb-shop-filter__link']],
            'filter_active' => ['label' => 'Chosen category', 'selectors' => ['.sb-shop-filter__link[aria-current="true"]']],
            'fields'  => ['label' => 'Search and sort', 'selectors' => ['.sb-shop-tools__field']],
            'count'   => ['label' => 'Number of products', 'selectors' => ['.sb-shop-toolbar__count']],
            'pager'   => ['label' => 'Pages', 'selectors' => ['.sb-shop-pager__link']],
            'more'    => ['label' => 'Load more button', 'selectors' => ['.sb-shop-more__button']],
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
        'shop.product_options' => [
            'option'   => ['label' => 'Choice', 'selectors' => ['.sb-shop-option']],
            'photo'    => ['label' => 'Photo', 'selectors' => ['.sb-shop-option__photo']],
            'name'     => ['label' => 'Name', 'selectors' => ['.sb-shop-option__name']],
            'price'    => ['label' => 'Price', 'selectors' => ['.sb-shop-option__price']],
            'sold_out' => ['label' => 'Sold-out choice', 'selectors' => ['.sb-shop-option--sold-out']],
            'more'     => ['label' => '"More" button', 'selectors' => ['.sb-shop-options__toggle']],
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
            'image'   => ['label' => 'Product photos', 'selectors' => ['.sb-shop-cart__image']],
            'stepper' => ['label' => 'Quantity', 'selectors' => ['.sb-shop-cart__stepper']],
            'line_total' => ['label' => 'Line totals', 'selectors' => ['.sb-shop-cart__line-total']],
            'remove'  => ['label' => 'Remove button', 'selectors' => ['.sb-shop-cart__remove']],
            'totals' => ['label' => 'Totals', 'selectors' => ['.sb-shop-cart__totals']],
            'button' => ['label' => 'Checkout button', 'selectors' => ['.sb-shop-cart__checkout']],
            'continue' => ['label' => 'Continue shopping button', 'selectors' => ['.sb-shop-cart__continue']],
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
