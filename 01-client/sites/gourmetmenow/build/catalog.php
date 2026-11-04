<?php
// Gourmet Me Now: the shop's products, from the WooCommerce export in data/products.json (made by the read-only
// export described in INVENTORY.md), through Shop's own importer (ShopImport).
//
//   cd <site> && php <bundle>/build/catalog.php        (or required by site.php)
//
// Decisions (user, 2026-11-04): one Peanut Brittle product with a flavour choice (WooCommerce product 603 and its
// variations). The per-flavour brittle products and the bourbon 8 oz / 16 oz product sold the same flavours a second
// time at other prices: they come over as drafts until Chef Gregory confirms prices (INVENTORY.md, section 9).
// Names, descriptions and prices are his, as they were on the old site; nothing is reworded here.
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once SLATE_ROOT . '/plugins/shop/ShopStoreAPI.php';
require_once SLATE_ROOT . '/plugins/shop/ShopImport.php';
$BUNDLE = dirname(__DIR__);

const GMN_DUPLICATES = [2139, 2140, 2142, 2145, 2146, 2147, 2149, 2152, 2153, 2154, 2155, 2157, 2158, 2161, 2162, 2164, 2165, 2166, 2178, 2233];
const GMN_CATEGORIES = [
    'Peanut Brittle'        => [603, 616, 2167, 2139, 2140, 2142, 2145, 2146, 2147, 2149, 2152, 2153, 2154, 2155, 2157, 2158, 2161, 2162, 2164, 2165, 2166, 2178, 2233],
    'Toffee & Treats'       => [615, 617, 602],
    'Seasonings & Dressing' => [598, 599, 600, 601],
];

/** A media file of the bundle as the site serves it: `2023/03/Regular-three.webp` → /uploads/media/gmn/202303-regular-three.webp */
function gmn_media_name(string $rel): string
{
    $rel = ltrim($rel, '/');
    return preg_match('#^(\d{4})/(\d{2})/(.+)$#', $rel, $m) ? $m[1] . $m[2] . '-' . strtolower($m[3]) : strtolower(basename($rel));
}

/** Copy every image of the old site into the library (once), under its stable name. */
function gmn_media(string $bundle): void
{
    $dst = getcwd() . '/uploads/media/gmn';
    if (!is_dir($dst)) { mkdir($dst, 0755, true); }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($bundle . '/media', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $f) {
        $rel = substr((string) $f, strlen($bundle . '/media/'));
        if (!preg_match('/\.(jpe?g|png|webp|gif)$/i', $rel)) { continue; }
        $name = gmn_media_name($rel);
        if (!is_file("{$dst}/{$name}")) { copy((string) $f, "{$dst}/{$name}"); }
        if (MID($name) <= 0) {
            $size = @getimagesize("{$dst}/{$name}") ?: [null, null, 'mime' => ''];
            Media::register(MEDIA_DIR . $name, ['mime' => (string) ($size['mime'] ?? ''), 'size_bytes' => filesize("{$dst}/{$name}"), 'width' => $size[0] ?? null, 'height' => $size[1] ?? null, 'original_name' => basename($rel)]);
        }
    }
    say('  + media');
}

/** The WooCommerce products as the REST API shape ShopImport reads, with the decisions above applied. */
function gmn_items(string $bundle): array
{
    $export = json_decode((string) file_get_contents($bundle . '/data/products.json'), true, 512, JSON_THROW_ON_ERROR);
    $byId = array_column($export['products'], null, 'id');
    $img = static fn(?array $i): ?array => $i && !empty($i['url']) ? ['src' => (string) $i['url'], 'alt' => (string) ($i['alt'] ?? '')] : null;
    $catOf = static function (int $id): array {
        foreach (GMN_CATEGORIES as $name => $ids) if (in_array($id, $ids, true)) return [['name' => $name]];
        return [];
    };
    // A flavour's own photo, from its per-flavour product, for the brittle's flavour choice.
    $flavourPhoto = [];
    foreach (GMN_DUPLICATES as $id) {
        $p = $byId[$id] ?? null;
        if ($p && !empty($p['image']['url'])) $flavourPhoto[mb_strtolower(ShopImport::plainTitle((string) $p['name']))] = (string) $p['image']['url'];
    }
    $photoFor = static function (string $flavour) use ($flavourPhoto): ?string {
        $f = mb_strtolower(preg_replace('/[^a-z ]+/i', ' ', $flavour) ?? '');
        $words = array_filter(preg_split('/\s+/', $f) ?: [], static fn(string $w): bool => strlen($w) > 3 && !in_array($w, ['peanut', 'brittle', 'with', 'holidays', 'from'], true));
        foreach ($flavourPhoto as $name => $url) {
            $hits = array_filter($words, static fn(string $w): bool => str_contains($name, $w));
            if ($words !== [] && count($hits) === count($words)) return $url;
        }
        return null;
    };
    $one = static function (array $p, bool $variation = false) use ($img, $catOf): array {
        $out = [
            'id' => (int) $p['id'], 'name' => (string) $p['name'], 'slug' => (string) $p['slug'], 'type' => $variation ? 'variation' : (string) $p['type'],
            'status' => (string) $p['status'] === 'publish' ? 'publish' : 'draft', 'featured' => !empty($p['featured']), 'catalog_visibility' => (string) ($p['catalog_visibility'] ?? 'visible'),
            'description' => (string) $p['description'], 'short_description' => (string) ($p['short_description'] ?? ''), 'sku' => (string) $p['sku'],
            'regular_price' => (string) $p['regular_price'], 'sale_price' => (string) $p['sale_price'],
            'date_on_sale_from' => (string) ($p['sale_from'] ?? ''), 'date_on_sale_to' => (string) ($p['sale_to'] ?? ''),
            'virtual' => !empty($p['virtual']), 'downloadable' => !empty($p['downloadable']),
            'manage_stock' => !empty($p['manage_stock']), 'stock_quantity' => $p['stock_quantity'], 'stock_status' => (string) $p['stock_status'],
            'weight' => (string) $p['weight'], 'dimensions' => ['length' => (string) $p['length'], 'width' => (string) $p['width'], 'height' => (string) $p['height']],
            'purchase_note' => (string) ($p['purchase_note'] ?? ''),
            'tax_status' => (string) ($p['tax_status'] ?? ''), 'tax_class' => (string) ($p['tax_class'] ?? ''),
            'images' => array_values(array_filter(array_merge([$img($p['image'] ?? null)], array_map($img, $p['gallery'] ?? [])))),
            'categories' => $variation ? [] : $catOf((int) $p['id']), 'tags' => array_map(static fn(string $t): array => ['name' => $t], $p['tags'] ?? []),
            'upsell_ids' => $p['upsells'] ?? [], 'cross_sell_ids' => $p['cross_sells'] ?? [],
        ];
        return $out;
    };
    $items = [];
    foreach ($export['products'] as $p) {
        $item = $one($p);
        $item['type'] = $p['type'] === 'variable' ? 'variable' : 'simple';
        if (in_array((int) $p['id'], GMN_DUPLICATES, true)) $item['status'] = 'draft';
        if ($p['type'] === 'variable') {
            $attr = [];
            foreach ($p['attributes'] as $a) if (!empty($a['variation'])) $attr[] = ['name' => $a['name'] === 'Choose Your Flavor' ? 'Flavor' : $a['name'], 'options' => $a['options'], 'variation' => true];
            $item['attributes'] = $attr;
            $item['variations'] = [];
            foreach ($p['variations'] as $v) {
                $child = $one($v, true);
                $value = (string) reset($v['attributes']);
                $child['attributes'] = [['name' => $attr[0]['name'] ?? 'Flavor', 'option' => $value]];
                if ($child['images'] === [] && ($photo = $photoFor($value)) !== null) $child['images'] = [['src' => $photo]];
                $item['variations'][] = $child;
            }
        }
        $items[] = $item;
    }
    return $items;
}

gmn_media($BUNDLE);
ShopStoreAPI::ensureSchema();
foreach (['currency' => 'USD', 'countries' => 'US', 'weight_unit' => 'oz', 'dimension_unit' => 'in', 'low_stock' => '2', 'product_prefix' => 'product', 'shop_page' => 'shop'] as $k => $v) {
    ShopStoreAPI::setSetting($k, $v);
}
// A category whose products are all drafts would show as an empty filter: none is made, and an old one goes.
foreach (ShopStoreAPI::categories() as $c) if ($c['name'] === 'Classes') ShopStoreAPI::deleteCategory($c['id']);
$read = ShopImport::read(json_encode(gmn_items($BUNDLE)), 'products.json');
$res = ShopImport::run($read['items'], [
    'images' => 'library', 'status' => 'file', 'weight_unit' => 'oz', 'dimension_unit' => 'in',
    // The photos are already in the library: an old address maps to its copy.
    'fetch' => static fn(string $url): array => ($id = MID($name = gmn_media_name(explode('/wp-content/uploads/', $url)[1] ?? basename($url)))) > 0
        ? ['id' => $id, 'path' => MEDIA_DIR . $name] : ['ok' => false, 'error' => 'not in the bundle'],
]);
say(sprintf('  + products: %d created, %d updated, %d variants, %d skipped', $res['created'], $res['updated'], $res['variants'], $res['skipped']));
foreach ($res['errors'] as $e) say('    ! ' . $e);

// Search titles and descriptions from the old site's Rank Math (data/rankmath.json, read from its database), as
// written, with its placeholders filled in. Left out until Chef Gregory answers (QUESTIONS-FOR-GREGORY.md, question 10):
// an old price, a "Product 2155" placeholder, a title naming another product, a typo, a "dairy free" claim nobody has
// confirmed, and "Recipe" on products that are spice mixes.
const GMN_SEO_SKIP_TITLE = [603 => 'names "Salted Simple" brittle', 2233 => 'typo "Brtittles"', 2178 => '"Dairy Free" not confirmed', 598 => 'says Recipe', 599 => 'says Recipe'];
const GMN_SEO_SKIP_DESC = [600 => 'old price $15.99', 2155 => 'says "Product 2155"'];
$rmFile = $BUNDLE . '/data/rankmath.json';
if (is_file($rmFile)) {
    $rm = json_decode((string) file_get_contents($rmFile), true) ?: [];
    $sep = trim((string) ($rm['_defaults']['sep'] ?? '-')) ?: '-';
    $seo = 0;
    foreach ($rm as $wooId => $r) {
        if (!is_array($r) || !ctype_digit((string) $wooId)) continue;
        $row = Database::row("SELECT id, title FROM shop_products WHERE tenant_id = ? AND external_ref = ?", [$tid, 'woo:' . $wooId]);
        if (!$row) continue;
        $fill = static fn(string $t): string => trim(preg_replace('/\s+/', ' ', str_replace(['%title%', '%sep%', '%sitename%'], [(string) $row['title'], $sep, NAME], $t)) ?? '', " -|");
        $title = isset(GMN_SEO_SKIP_TITLE[(int) $wooId]) ? '' : $fill((string) ($r['title'] ?? ''));
        $desc = isset(GMN_SEO_SKIP_DESC[(int) $wooId]) ? '' : trim((string) ($r['description'] ?? ''));
        // A title that is only the product name adds nothing: the default (name | site) is better.
        if ($title === trim((string) $row['title'])) $title = '';
        Database::update('shop_products', ['seo_title' => $title !== '' ? mb_substr($title, 0, 200) : null, 'seo_description' => $desc !== '' ? mb_substr($desc, 0, 400) : null], 'id = ?', [(int) $row['id']]);
        if ($title !== '' || $desc !== '') $seo++;
    }
    say("  + search titles and descriptions for {$seo} products (Rank Math)");
}

// Shipping and tax as the old store had them (INVENTORY.md, sections 4 and 5), until Chef Gregory answers:
// - pickup only in Paso Robles, Templeton and Atascadero, free (his appointment phone is on the hold list: left out);
// - the rest of the US: live USPS rates through EasyPost with the old $3.00 handling fee; no other country;
// - prices before tax, tax by the delivery address; no rate is set, so no tax is added (as before).
// The EasyPost key comes from GMN_EASYPOST_KEY; without one, checkout says carrier rates are not available.
foreach (['shipping_enabled' => '1', 'pickup_enabled' => '0', 'tax_mode' => 'exclusive', 'tax_label' => '',
          'from_city' => getenv('GMN_FROM_CITY') ?: 'Paso Robles', 'from_region' => getenv('GMN_FROM_STATE') ?: 'CA',
          'from_postcode' => getenv('GMN_FROM_ZIP') ?: '93446', 'from_country' => 'US', 'from_company' => "Chef Gregory's Gourmet Me Now"] as $k => $v) {
    ShopStoreAPI::setSetting($k, $v);
}
if (($key = (string) getenv('GMN_EASYPOST_KEY')) !== '') ShopShipping::setApiKey($key);
foreach (ShopShipping::zones() as $z) ShopShipping::deleteZone((int) $z['id']);
$local = ShopShipping::saveZone(['name' => 'Local pickup', 'countries' => 'US', 'regions' => 'CA', 'postcodes' => '93446, 93465, 93422', 'sort' => 0]);
ShopShipping::saveMethod($local['id'], ['kind' => 'pickup', 'label' => 'Local pickup by appointment (Paso Robles, Templeton, Atascadero)', 'cost' => '0', 'enabled' => 1]);
ShopShipping::saveMethod($local['id'], ['kind' => 'carrier', 'label' => '', 'carriers' => 'USPS', 'markup' => '3.00', 'enabled' => 1, 'sort' => 1]);
$us = ShopShipping::saveZone(['name' => 'United States', 'countries' => 'US', 'sort' => 1]);
ShopShipping::saveMethod($us['id'], ['kind' => 'carrier', 'label' => '', 'carriers' => 'USPS', 'markup' => '3.00', 'enabled' => 1]);
say('  + shipping: local pickup, USPS rates (EasyPost ' . (ShopShipping::apiKey() !== '' ? 'key set' : 'no key yet') . '); tax added at checkout, no rates');
