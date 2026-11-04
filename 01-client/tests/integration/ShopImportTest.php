<?php
/**
 * Shop 0.3.0 importer: a WooCommerce product CSV (tests/fixtures/shop-woocommerce-products.csv, synthetic) and a REST
 * API JSON list. Checks the reading (HTML names and descriptions, escaped commas, `Parent > Child` categories, units
 * from the header, variations under their product by id and by SKU), the plan (new, skipped, the notes), the import
 * (variants with their own price, weight and size, an unpriced variation not sold, a combination not listed, types,
 * sale dates, units converted, images through the copy hook, upsells and cross-sells resolved) and a second import
 * updating rather than duplicating.
 *
 * Images are not fetched: the run gets a stand-in for Media::importUrl. Every row written is removed.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/shop/ShopStoreAPI.php';
require_once dirname(__DIR__, 2) . '/plugins/shop/ShopImport.php';

$simp = ['settings' => []];
$simpSet = function (string $key, string $value) use (&$simp): void {
    if (!array_key_exists($key, $simp['settings'])) $simp['settings'][$key] = Database::setting('shop.' . $key);
    ShopStoreAPI::setSetting($key, $value);
};
$simpFetched = [];
$simpFetch = function (string $url) use (&$simpFetched): array {
    $simpFetched[] = $url;
    return str_contains($url, 'broken') ? ['ok' => false, 'error' => 'gone'] : ['id' => 1, 'path' => '/uploads/media/test/' . basename((string) parse_url($url, PHP_URL_PATH))];
};
$simpRefs = static fn(): array => array_map('strval', array_column(Database::rows("SELECT external_ref FROM shop_products WHERE tenant_id = ? AND external_ref LIKE 'woo:90%'", [current_tenant_id()]), 'external_ref'));
$simpByRef = static fn(string $ref): ?array => Database::row("SELECT * FROM shop_products WHERE tenant_id = ? AND external_ref = ?", [current_tenant_id(), $ref]);

try {
    ShopStoreAPI::ensureSchema();
    $simpSet('weight_unit', 'kg');
    $simpSet('dimension_unit', 'cm');
    $csv = (string) file_get_contents(dirname(__DIR__) . '/fixtures/shop-woocommerce-products.csv');

    unit('shop import: the WooCommerce CSV is read into products with their variations', function () use ($csv): void {
        $read = ShopImport::read($csv, 'products.csv');
        assert_true(!isset($read['error']), $read['error'] ?? '');
        assert_eq(['weight' => 'oz', 'dimension' => 'in'], $read['units'], 'units come from the header');
        $items = array_column($read['items'], null, 'ref');
        $brittle = $items['woo:9001'];
        assert_eq('Test Brittle Over 3 flavours', $brittle['name'], 'HTML stripped from the name');
        assert_eq("Line one.\n\nLine & two.", $brittle['description'], 'paragraphs and entities');
        assert_eq(['Brittles'], $brittle['categories'], 'the last part of a category path');
        assert_eq(['Regular', 'Maple, Bacon', 'Thai Ginger'], $brittle['attributes']['Flavour'], 'an escaped comma stays inside a value');
        assert_eq(['Flavour'], $brittle['variation_attributes'], 'Ingredients is not used by any variation');
        assert_eq(['woo:9011', 'woo:9012', 'woo:9013'], array_column($brittle['variations'], 'ref'), 'by id:… and by the parent SKU');
        assert_eq(['service', 'digital'], [$items['woo:9003']['kind'], $items['woo:9004']['kind']]);
        assert_true(!empty($items['woo:9007']['orphan']), 'a variation without its product');
        assert_eq('This is not a WooCommerce product file.', ShopImport::read("a,b\n1,2", 'x.csv')['error'] ?? '');
    });

    unit('shop import: the plan counts what would happen and says what does not carry over', function () use ($csv, $simpRefs): void {
        $plan = ShopImport::plan(ShopImport::read($csv)['items']);
        assert_eq([5, 0, 3, 2], [$plan['create'], $plan['update'], $plan['variants'], $plan['skipped']], 'create, update, variants, skipped (grouped, orphan)');
        $notes = implode(' | ', array_merge(...array_column($plan['rows'], 'warnings')));
        foreach (['Ingredients', 'no price and will not be sold', 'Grouped and external', 'imported as a draft', 'whose product is not in the file'] as $needle) {
            assert_true(str_contains($notes, $needle), "note: $needle");
        }
        assert_eq([], $simpRefs(), 'the plan writes nothing');
    });

    unit('shop import: products, variants, units, sale dates, images and links are imported', function () use ($csv, &$simpFetched, $simpFetch, $simpByRef): void {
        $res = ShopImport::run(ShopImport::read($csv)['items'], ['images' => 'library', 'status' => 'file', 'weight_unit' => 'oz', 'dimension_unit' => 'in', 'fetch' => $simpFetch]);
        assert_eq([5, 0, 3, 2], [$res['created'], $res['updated'], $res['variants'], $res['skipped']], json_encode($res['errors']));
        assert_eq(4, $res['images'], 'four distinct images copied (each once)');

        $b = $simpByRef('woo:9001');
        assert_eq(['test-brittle-over-3-flavours', 'published', 1, 'physical', 2900], [$b['slug'], $b['status'], (int) $b['featured'], $b['product_type'], (int) $b['price_cents']],
            'address from the name, the lowest priced variation as the product price');
        assert_eq('0.454', ShopStoreAPI::measure($b['weight']), '16 oz in kg');
        assert_eq('30.48', ShopStoreAPI::measure($b['length']), '12 in in cm');
        assert_eq(['/uploads/media/test/brittle.jpg', '/uploads/media/test/brittle-2.jpg'], ShopStoreAPI::images($b));
        $vars = [];
        foreach (ShopStoreAPI::variantRows((int) $b['id']) as $v) $vars[ShopStoreAPI::optionsLabel(ShopStoreAPI::variantValues($v))] = $v;
        assert_eq(['Regular', 'Maple, Bacon', 'Thai Ginger'], array_keys($vars));
        assert_eq([2900, 2500, 12, 1, 'woo:9011'], [(int) $vars['Regular']['price_cents'], (int) $vars['Regular']['sale_price_cents'], (int) $vars['Regular']['stock_qty'], (int) $vars['Regular']['enabled'], $vars['Regular']['external_ref']]);
        assert_eq(['0.227', '20.32', '/uploads/media/test/maple.jpg'], [ShopStoreAPI::measure($vars['Maple, Bacon']['weight']), ShopStoreAPI::measure($vars['Maple, Bacon']['length']), $vars['Maple, Bacon']['image']]);
        assert_eq(0, (int) $vars['Thai Ginger']['enabled'], 'no price: not sold');
        assert_eq(['Crunchy', 'Gift'], array_column(ShopStoreAPI::tagsOf([(int) $b['id']])[(int) $b['id']] ?? [], 'name'));

        $t = $simpByRef('woo:9002');
        assert_eq([2995, 2495, '2031-01-01 00:00:00', '2031-01-31 00:00:00', 4, 2, 'Keep cool.'],
            [(int) $t['price_cents'], (int) $t['sale_price_cents'], $t['sale_starts_at'], $t['sale_ends_at'], (int) $t['stock_qty'], (int) $t['low_stock'], $t['purchase_note']]);
        assert_eq(['service', 'draft'], [$simpByRef('woo:9003')['product_type'], $simpByRef('woo:9003')['status']], 'unpublished stays a draft');
        assert_eq(['digital', 3, 30], [$simpByRef('woo:9004')['product_type'], (int) $simpByRef('woo:9004')['download_limit'], (int) $simpByRef('woo:9004')['download_days']]);
        assert_eq('draft', $simpByRef('woo:9006')['status'], 'hidden in the catalogue: a draft');
        assert_eq([(int) $simpByRef('woo:9003')['id']], ShopStoreAPI::linkedIds((int) $b['id'], 'upsell'), 'upsell by id:9003');
        assert_eq([(int) $t['id']], ShopStoreAPI::linkedIds((int) $b['id'], 'cross_sell'), 'cross-sell by SKU');
        $cats = array_column(ShopStoreAPI::categories(), 'name');
        assert_true(in_array('Brittles', $cats, true) && in_array('Test Sweets', $cats, true) && in_array('Books', $cats, true));
    });

    unit('shop import: importing the same file again updates the products instead of adding new ones', function () use ($csv, $simpFetch, $simpRefs, $simpByRef): void {
        $before = count($simpRefs());
        Database::update('shop_products', ['price_cents' => 1], 'tenant_id = ? AND external_ref = ?', [current_tenant_id(), 'woo:9002']);
        $res = ShopImport::run(ShopImport::read($csv)['items'], ['images' => 'link', 'status' => 'draft', 'weight_unit' => 'oz', 'dimension_unit' => 'in', 'fetch' => $simpFetch]);
        assert_eq([0, 5], [$res['created'], $res['updated']]);
        assert_eq($before, count($simpRefs()), 'no duplicates');
        assert_eq(2995, (int) $simpByRef('woo:9002')['price_cents'], 'the file\'s price is back');
        assert_eq(['https://shop.example.test/img/toffee.jpg'], ShopStoreAPI::images($simpByRef('woo:9002')), 'images kept as addresses this time');
        assert_eq('draft', $simpByRef('woo:9002')['status'], 'imported as drafts');
        assert_eq(3, count(ShopStoreAPI::variantRows((int) $simpByRef('woo:9001')['id'])), 'variants matched, not added');
    });

    unit('shop import: a REST API JSON list with embedded variations imports the same way', function () use ($simpFetch, $simpByRef): void {
        $json = json_encode([[
            'id' => 9101, 'name' => 'Test Seasoning', 'slug' => 'test-seasoning-json', 'type' => 'variable', 'status' => 'publish', 'featured' => false,
            'description' => '<p>Low sodium.</p>', 'short_description' => '', 'sku' => 'TST-SEA', 'weight' => '0.25', 'dimensions' => ['length' => '5', 'width' => '2', 'height' => '5'],
            'categories' => [['id' => 1, 'name' => 'Seasonings']], 'tags' => [], 'images' => [['src' => 'https://shop.example.test/img/broken.jpg']],
            'attributes' => [['name' => 'Heat', 'options' => ['Mild', 'Spicy'], 'variation' => true]],
            'variations' => [
                ['id' => 9111, 'sku' => 'TST-SEA-M', 'regular_price' => '9.97', 'status' => 'publish', 'manage_stock' => true, 'stock_quantity' => 20, 'attributes' => [['name' => 'Heat', 'option' => 'Mild']]],
                ['id' => 9112, 'sku' => 'TST-SEA-S', 'regular_price' => '10.97', 'status' => 'publish', 'attributes' => [['name' => 'Heat', 'option' => 'Spicy']]],
            ],
        ]]);
        $read = ShopImport::read($json, 'products.json');
        $res = ShopImport::run($read['items'], ['images' => 'library', 'weight_unit' => 'kg', 'dimension_unit' => 'cm', 'fetch' => $simpFetch]);
        assert_eq([1, 2], [$res['created'], $res['variants']]);
        assert_true(str_contains(implode(' ', $res['errors']), 'broken.jpg'), 'an image that cannot be copied is reported, not fatal');
        $p = $simpByRef('woo:9101');
        assert_eq(['test-seasoning-json', 997, '0.25'], [$p['slug'], (int) $p['price_cents'], ShopStoreAPI::measure($p['weight'])], 'the slug is kept from JSON');
        $prices = array_map(static fn(array $v): int => (int) $v['price_cents'], ShopStoreAPI::variantRows((int) $p['id']));
        assert_eq([997, 1097], $prices);
    });
} finally {
    $tid = current_tenant_id();
    foreach (Database::rows("SELECT id FROM shop_products WHERE tenant_id = ? AND external_ref LIKE 'woo:9%'", [$tid]) as $r) ShopStoreAPI::deleteProduct((int) $r['id']);
    foreach (ShopStoreAPI::categories() as $c) {
        if (in_array($c['name'], ['Brittles', 'Test Sweets', 'Classes', 'Books', 'Seasonings'], true) && $c['product_count'] === 0) ShopStoreAPI::deleteCategory($c['id']);
    }
    ShopStoreAPI::pruneTags();
    foreach ($simp['settings'] as $key => $orig) {
        if ($orig === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [$tid, 'shop.' . $key]);
        else Database::setSetting('shop.' . $key, $orig);
    }
}
