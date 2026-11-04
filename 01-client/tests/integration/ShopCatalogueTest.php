<?php
/**
 * Shop 0.3.0 catalogue: product types, weight and size (and a variant's falling back to its product's), sale dates,
 * tags, featured, upsells / cross-sells / related products, a cart of digital products checking out with nothing to
 * ship, download links (granted once on payment, counted against their limit, dead after a refund), the low-stock
 * alert, and the Studio grid's sources and Product Meta's new rows.
 *
 * Every row this file writes is removed and every setting it changes is restored. Data is synthetic.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/shop/ShopStoreAPI.php';

$scat = ['products' => [], 'categories' => [], 'orders' => [], 'settings' => []];
$scatSet = function (string $key, string $value) use (&$scat): void {
    if (!array_key_exists($key, $scat['settings'])) $scat['settings'][$key] = Database::setting('shop.' . $key);
    ShopStoreAPI::setSetting($key, $value);
};
$scatTag = 'sc' . bin2hex(random_bytes(3));

/** Save a product and remember it for clean-up; returns its id. */
$scatProduct = function (array $in) use (&$scat, $scatTag): int {
    $res = ShopStoreAPI::saveProduct($in + ['price' => '10.00', 'status' => 'published', 'slug' => $scatTag . '-' . bin2hex(random_bytes(3))]);
    if (!$res['ok']) throw new RuntimeException('product not saved: ' . json_encode($res['errors'] ?? []));
    $scat['products'][] = (int) $res['id'];
    return (int) $res['id'];
};

/** A paid order for these product ids (one each), made straight from the cart. */
$scatOrder = function (array $lines, array $in = []) use (&$scat): array {
    ShopStoreAPI::cartClear();
    foreach ($lines as $pid => $qty) ShopStoreAPI::cartAdd($pid, $qty);
    $placed = ShopStoreAPI::placeOrder($in + ['email' => 'scat@example.test', 'name' => 'Scat Buyer']);
    if (!$placed['ok']) throw new RuntimeException('order not placed: ' . json_encode($placed['errors'] ?? []));
    $scat['orders'][] = (int) $placed['order']['id'];
    return $placed['order'];
};

try {
    ShopStoreAPI::ensureSchema();
    if (session_status() !== PHP_SESSION_ACTIVE) $_SESSION = $_SESSION ?? [];
    $scatSet('weight_unit', 'oz');
    $scatSet('dimension_unit', 'in');
    $scatSet('low_stock', '3');
    $scatSet('low_stock_email', 'scat-stock@example.test');
    $scatSet('shipping_enabled', '1');
    $scatSet('pickup_enabled', '1');
    $scatSet('countries', 'US');

    unit('shop catalogue: the new columns and tables exist after ensureSchema', function (): void {
        foreach (['product_type', 'weight', 'length', 'sale_starts_at', 'featured', 'low_stock', 'purchase_note', 'download_limit', 'external_ref'] as $c) {
            assert_eq(1, (int) Database::value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_products' AND COLUMN_NAME = ?", [$c]), "shop_products.$c");
        }
        foreach (['shop_tags', 'shop_product_tags', 'shop_product_links', 'shop_product_files', 'shop_downloads'] as $t) {
            assert_eq(1, (int) Database::value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$t]), $t);
        }
    });

    unit('shop catalogue: weight, size, type, tags and featured are saved and shown in the shop\'s units', function () use ($scatProduct, $scatTag): void {
        $id = $scatProduct(['title' => 'Scat Brittle', 'product_type' => 'physical', 'weight' => '16,05', 'length' => '12', 'width' => '7', 'height' => '3',
            'tags' => "Gift box, {$scatTag} vegan, gift box", 'featured' => '1', 'purchase_note' => 'Keep in a cool place.']);
        $p = ShopStoreAPI::publishedProduct($id);
        assert_eq(['physical', true, '16.05', 'oz', '12 × 7 × 3 in', true], [$p['product_type'], $p['needs_shipping'], $p['weight'], $p['weight_unit'], $p['dimensions'], $p['featured']]);
        assert_eq(['Gift box', "{$scatTag} vegan"], array_column($p['tags'], 'name'), 'repeated tags are dropped, case-insensitively');
        assert_eq(round(16.05 * 28.349523125, 3), round(ShopStoreAPI::toGrams(16.05), 3));
        $bad = ShopStoreAPI::saveProduct(['title' => 'x', 'price' => '1', 'weight' => 'heavy', 'sale_starts_at' => '2026-13-01']);
        assert_true(isset($bad['errors']['weight'], $bad['errors']['sale_starts_at']), 'an unreadable weight or date is refused');
        $svc = $scatProduct(['title' => 'Scat Class', 'product_type' => 'service', 'weight' => '5']);
        assert_eq(['service', false, ''], [ShopStoreAPI::publishedProduct($svc)['product_type'], ShopStoreAPI::publishedProduct($svc)['needs_shipping'], ShopStoreAPI::publishedProduct($svc)['weight']],
            'a service keeps no weight');
        assert_true(in_array($id, array_column(ShopStoreAPI::listProducts(['tag' => ShopStoreAPI::slugify("{$scatTag} vegan"), 'per_page' => 48])['rows'], 'id'), true), 'listed by tag');
        assert_true(in_array($id, array_column(ShopStoreAPI::listProducts(['featured' => true, 'per_page' => 48])['rows'], 'id'), true), 'listed as featured');
    });

    unit('shop catalogue: a sale price applies only between its dates, also for listing by price', function () use ($scatProduct): void {
        $id = $scatProduct(['title' => 'Scat Sale', 'price' => '29.00', 'sale_price' => '19.00', 'sale_starts_at' => '2031-01-01 00:00', 'sale_ends_at' => '2031-02-01T00:00']);
        $row = ShopStoreAPI::productRow($id);
        assert_eq(['2031-01-01 00:00:00', '2031-02-01 00:00:00'], [$row['sale_starts_at'], $row['sale_ends_at']]);
        assert_eq(2900, ShopStoreAPI::effectivePrice($row, strtotime('2030-12-31 23:59')), 'before');
        assert_eq(1900, ShopStoreAPI::effectivePrice($row, strtotime('2031-01-15 12:00')), 'during');
        assert_eq(2900, ShopStoreAPI::effectivePrice($row, strtotime('2031-02-01 00:00')), 'the end is exclusive');
        assert_eq(2900, ShopStoreAPI::publishedProduct($id)['effective_cents'], 'not on sale today');
        $res = ShopStoreAPI::saveProduct(['title' => 'x', 'price' => '5', 'sale_price' => '4', 'sale_starts_at' => '2031-02-01', 'sale_ends_at' => '2031-01-01']);
        assert_true(isset($res['errors']['sale_ends_at']), 'a sale cannot end before it starts');
        $open = $scatProduct(['title' => 'Scat Open Sale', 'price' => '29.00', 'sale_price' => '9.00']);
        $list = array_column(ShopStoreAPI::listProducts(['ids' => [$id, $open], 'order' => 'price_asc'])['rows'], 'id');
        assert_eq([$open, $id], $list, 'price order uses the price paid now (29.00 future sale vs 9.00 open sale)');
    });

    unit('shop catalogue: a variant\'s empty weight and size are the product\'s; a typed size is read', function () use ($scatProduct): void {
        assert_eq(['length' => 12.0, 'width' => null, 'height' => 3.5], ShopStoreAPI::parseSize('12 × - x 3,5'));
        assert_null(ShopStoreAPI::parseSize('12 x 7'), 'two parts are not a size');
        $id = $scatProduct(['title' => 'Scat Variants', 'weight' => '16', 'length' => '10', 'width' => '8', 'height' => '2', 'attributes' => "Size: 8 oz, 16 oz"]);
        $rows = ShopStoreAPI::variantRows($id);
        $fields = [];
        foreach ($rows as $v) {
            $small = ShopStoreAPI::variantValues($v) === ['8 oz'];
            $fields[(string) $v['options_key']] = ['enabled' => '1', 'price' => $small ? '18.00' : '', 'weight' => $small ? '8' : '', 'size' => $small ? '8 x 5 x 2' : ''];
        }
        assert_true(ShopStoreAPI::saveProduct(['title' => 'Scat Variants', 'slug' => ShopStoreAPI::productRow($id)['slug'], 'price' => '10.00', 'status' => 'published',
            'weight' => '16', 'length' => '10', 'width' => '8', 'height' => '2', 'attributes' => "Size: 8 oz, 16 oz", 'variants' => $fields], $id)['ok']);
        $by = [];
        foreach (ShopStoreAPI::publishedProduct($id)['variants'] as $v) $by[$v['label']] = [$v['weight'], $v['dimensions'], $v['effective_cents']];
        assert_eq(['8', '8 × 5 × 2 in', 1800], $by['8 oz']);
        assert_eq(['16', '10 × 8 × 2 in', 1000], $by['16 oz'], 'empty: the product\'s');
    });

    unit('shop catalogue: upsells keep their order, related products share a category, drafts and the product itself never show', function () use ($scatProduct, &$scat, $scatTag): void {
        $cat = ShopStoreAPI::saveCategory(['name' => $scatTag . ' Brittles']);
        $scat['categories'][] = (int) $cat['id'];
        $a = $scatProduct(['title' => 'Scat A', 'categories' => [$cat['id']]]);
        $b = $scatProduct(['title' => 'Scat B', 'categories' => [$cat['id']]]);
        $c = $scatProduct(['title' => 'Scat C', 'categories' => [$cat['id']], 'status' => 'draft']);
        $d = $scatProduct(['title' => 'Scat D', 'upsells' => [$b, $a, $d ?? 0, 999999999], 'cross_sells' => [$a]]);
        assert_eq([$b, $a], ShopStoreAPI::linkedIds($d, 'upsell'), 'unknown ids and itself are dropped, order kept');
        assert_eq([$b, $a], array_column(ShopStoreAPI::relatedProducts($d, 'upsell'), 'id'));
        assert_eq([$a], array_column(ShopStoreAPI::relatedProducts($d, 'cross_sell'), 'id'));
        assert_eq([$b], array_column(ShopStoreAPI::relatedProducts($a, 'related', 8), 'id'), 'same category, published, not itself');
        ShopStoreAPI::cartClear();
        ShopStoreAPI::cartAdd($d, 1);
        assert_eq([$a], array_column(ShopStoreAPI::cartCrossSells(), 'id'));
        ShopStoreAPI::cartAdd($a, 1);
        assert_eq([], ShopStoreAPI::cartCrossSells(), 'a cross-sell already in the cart is not suggested');
        ShopStoreAPI::cartClear();
        ShopStoreAPI::deleteProduct($a);
        assert_eq([$b], ShopStoreAPI::linkedIds($d, 'upsell'), 'deleting a product removes the links to it');
        $scat['products'] = array_values(array_diff($scat['products'], [$a]));
        unset($c);
    });

    unit('shop catalogue: a cart of digital products checks out with nothing to ship, and its downloads are counted', function () use ($scatProduct, $scatOrder): void {
        $id = $scatProduct(['title' => 'Scat Recipe Book', 'product_type' => 'digital', 'price' => '12.00', 'download_limit' => '2', 'download_days' => '30']);
        $tmp = tempnam(sys_get_temp_dir(), 'scat');
        file_put_contents($tmp, '%PDF-1.4 synthetic');
        $added = ShopStoreAPI::addProductFile($id, $tmp, 'Recipes.pdf');
        assert_true($added['ok'], 'file kept: ' . ($added['error'] ?? ''));
        assert_false(ShopStoreAPI::addProductFile($id, __FILE__, 'evil.php')['ok'], 'a .php file is refused');
        $file = ShopStoreAPI::productFiles($id)[0];
        assert_true(is_file(ShopStoreAPI::filesDir() . '/' . $file['path']) && !str_contains(ShopStoreAPI::filesDir(), '/uploads/'), 'kept outside uploads/');
        assert_true(str_contains(ShopStoreAPI::filesDir(), '/data/shop-files/') && is_file(dirname(ShopStoreAPI::filesDir()) . '/.htaccess'), 'kept under data/, which the web server refuses');

        ShopStoreAPI::cartClear();
        ShopStoreAPI::cartAdd($id, 1);
        assert_eq(['none'], ShopStoreAPI::deliveryOptions(), 'nothing to ship');
        $order = $scatOrder([$id => 1], ['delivery' => 'none']);
        assert_eq(['none', 0, null], [$order['delivery'], (int) $order['shipping_cents'], $order['address_line1']]);
        assert_eq('digital', ShopStoreAPI::orderItems((int) $order['id'])[0]['product_type']);
        assert_eq([], ShopStoreAPI::orderDownloads($order), 'no link before payment');

        assert_true(ShopStoreAPI::markPaid((int) $order['id'], 1200, 'EUR'));
        assert_eq(0, ShopStoreAPI::grantDownloads((int) $order['id']), 'links are made once');
        $links = ShopStoreAPI::orderDownloads(ShopStoreAPI::order((int) $order['id']));
        assert_eq(1, count($links));
        assert_eq(['Recipes.pdf', 2, true], [$links[0]['name'], $links[0]['left'], $links[0]['usable']]);
        parse_str((string) parse_url($links[0]['url'], PHP_URL_QUERY), $q);
        $got = ShopStoreAPI::claimDownload((string) $q['t']);
        assert_eq('%PDF-1.4 synthetic', file_get_contents($got['abs']));
        assert_true(ShopStoreAPI::claimDownload((string) $q['t']) !== null, 'second download');
        assert_null(ShopStoreAPI::claimDownload((string) $q['t']), 'the limit of 2 holds');
        assert_null(ShopStoreAPI::claimDownload(str_repeat('a', 40)), 'an unknown link');
        assert_eq('No longer available', ShopStoreAPI::downloadTerms(ShopStoreAPI::orderDownloads(ShopStoreAPI::order((int) $order['id']))[0]));
        assert_eq([], ShopStoreAPI::purchaseNotes((int) $order['id']), 'no purchase note set');

        Database::update('shop_orders', ['status' => 'refunded'], 'id = ?', [(int) $order['id']]);
        Database::update('shop_downloads', ['download_count' => 0], 'order_id = ?', [(int) $order['id']]);
        assert_null(ShopStoreAPI::claimDownload((string) $q['t']), 'a refunded order\'s links stop working');
        assert_eq([], ShopStoreAPI::orderDownloads(ShopStoreAPI::order((int) $order['id'])));
    });

    unit('shop catalogue: a mixed cart still ships, and the order keeps each line\'s type', function () use ($scatProduct, $scatOrder): void {
        $book = $scatProduct(['title' => 'Scat Ebook', 'product_type' => 'digital']);
        $jar = $scatProduct(['title' => 'Scat Jar', 'weight' => '8']);
        ShopStoreAPI::cartClear();
        ShopStoreAPI::cartAdd($book, 1);
        ShopStoreAPI::cartAdd($jar, 1);
        assert_eq(['shipping', 'pickup'], ShopStoreAPI::deliveryOptions());
        $order = $scatOrder([$book => 1, $jar => 1], ['delivery' => 'pickup']);
        assert_eq(['digital', 'physical'], array_column(ShopStoreAPI::orderItems((int) $order['id']), 'product_type'));
        $bad = ShopStoreAPI::placeOrder(['email' => 'scat@example.test', 'name' => 'Scat', 'delivery' => 'none']);
        assert_true(isset($bad['errors']['delivery']), '"nothing to ship" is refused for a cart with a physical product');
    });

    unit('shop catalogue: the sale that reaches the low-stock level emails the shop once, and the list shows it', function () use ($scatProduct, $scatOrder): void {
        $id = $scatProduct(['title' => 'Scat Toffee', 'track_stock' => '1', 'stock_qty' => '5']);
        $own = $scatProduct(['title' => 'Scat Dressing', 'track_stock' => '1', 'stock_qty' => '9', 'low_stock' => '8']);
        assert_eq([3, 8], [ShopStoreAPI::lowStockThreshold($id), ShopStoreAPI::lowStockThreshold($own)], 'the shop\'s level, or the product\'s own');
        $since = (int) Database::value("SELECT COALESCE(MAX(id), 0) FROM audit_log");
        $mails = static fn(): int => (int) Database::value("SELECT COUNT(*) FROM audit_log WHERE id > ? AND action = 'mail.send' AND target = 'scat-stock@example.test'", [$since]);
        $o1 = $scatOrder([$id => 1], ['delivery' => 'pickup']);
        ShopStoreAPI::markPaid((int) $o1['id'], 1000, 'EUR');
        assert_eq(0, $mails(), '5 → 4: still above 3');
        $o2 = $scatOrder([$id => 2], ['delivery' => 'pickup']);
        ShopStoreAPI::markPaid((int) $o2['id'], 2000, 'EUR');
        assert_eq(1, $mails(), '4 → 2: crossed');
        $o3 = $scatOrder([$id => 1], ['delivery' => 'pickup']);
        ShopStoreAPI::markPaid((int) $o3['id'], 1000, 'EUR');
        assert_eq(1, $mails(), '2 → 1: already low, no second email');
        $low = array_column(ShopStoreAPI::lowStock(), 'stock', 'product_id');
        assert_eq(1, $low[$id] ?? null);
        assert_false(isset($low[$own]), '9 is above its own level of 8');
    });

    unit('shop catalogue: the Product Grid offers its sources and a tag, and Product Meta tags, weight and size', function (): void {
        $decl = require dirname(__DIR__, 2) . '/plugins/shop/studio-widgets.php';
        $fields = [];
        foreach ($decl['blocks'] as $b) $fields[$b->type()] = array_column($b->schema()->fields(), 'key');
        assert_true(in_array('source', $fields['shop.products'], true) && in_array('tag', $fields['shop.products'], true), 'the grid has Show and tag');
        assert_true(in_array('show_tags', $fields['shop.product_meta'], true) && in_array('show_dimensions', $fields['shop.product_meta'], true), 'meta has tags and size');
        assert_eq(['all', 'featured', 'upsell', 'cross_sell', 'related'], \Slate\Plugin\Shop\Studio\ShopProductsProvider::SOURCES);
    });
} finally {
    ShopStoreAPI::cartClear();
    foreach ($scat['orders'] as $oid) {
        Database::delete('shop_downloads', 'order_id = ?', [$oid]);
        Database::delete('shop_order_items', 'order_id = ?', [$oid]);
        Database::delete('shop_orders', 'id = ?', [$oid]);
    }
    foreach ($scat['products'] as $pid) {
        Database::delete('shop_order_items', 'product_id = ?', [$pid]);
        ShopStoreAPI::deleteProduct($pid);
    }
    foreach ($scat['categories'] as $cid) ShopStoreAPI::deleteCategory($cid);
    ShopStoreAPI::pruneTags();
    foreach ($scat['settings'] as $key => $orig) {
        if ($orig === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), 'shop.' . $key]);
        else Database::setSetting('shop.' . $key, $orig);
    }
}
