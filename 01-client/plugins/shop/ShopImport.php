<?php
/**
 * Shop — import products from a WooCommerce shop.
 *
 * Two files are understood:
 *  - the CSV that WooCommerce writes under Products › Export (one row per product and one per variation; the
 *    columns are matched by name, so their order and the extra "Meta:" columns do not matter);
 *  - a JSON list of products as the WooCommerce REST API returns them (`/wp-json/wc/v3/products`), each optionally
 *    carrying its variations under `variations` as full objects.
 *
 * Both are read into the same items (read()), checked into a plan the admin sees before anything is written
 * (plan()), then imported (run()). An item remembers where it came from (`woo:<id>` in external_ref), so importing
 * the same file again updates the products it made instead of adding new ones; failing that, a product is matched by
 * SKU, then by its address.
 *
 * What maps: name, address (JSON only: the CSV has no slug column), type (simple or variable; virtual is a service,
 * downloadable is digital), published, featured, descriptions (HTML turned into plain paragraphs), SKU, prices,
 * sale dates, stock, low-stock level, weight and sizes (converted to the shop's units), categories (the last part of
 * `Parent > Child`), tags, images (copied into the Media library, or kept as addresses), purchase note, download
 * limit and expiry, downloadable files, upsells and cross-sells. Variations become variants: their attributes become
 * the product's options, and a combination the file does not list is kept but not sold.
 *
 * Not imported, and said so in the plan: grouped and external products, attributes no variation uses (shown on
 * WooCommerce's "Additional information" tab), tax status and class, shipping classes, reviews.
 */

class ShopImport {

    public const MAX_BYTES = 20 * 1024 * 1024;
    public const MAX_ITEMS = 2000;

    // ── Reading ───────────────────────────────────────────────────────────

    /**
     * Read a file into items. Each item: ['ref', 'type', 'kind' => physical|digital|service, 'name', 'slug', 'status',
     * 'featured', 'description', 'short_description', 'sku', 'regular_price', 'sale_price', 'sale_from', 'sale_to',
     * 'manage_stock', 'stock', 'in_stock', 'low_stock', 'weight', 'length', 'width', 'height', 'categories', 'tags',
     * 'images', 'purchase_note', 'download_limit', 'download_days', 'downloads', 'upsells', 'cross_sells',
     * 'attributes' => [name => [values]], 'variation_attributes' => [names], 'variations' => [items with 'options']].
     *
     * @return array{items: list<array<string, mixed>>, units: array{weight: ?string, dimension: ?string}, error?: string}
     */
    public static function read(string $contents, string $filename = ''): array {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $trim = ltrim($contents);
        if ($trim === '') return ['items' => [], 'units' => ['weight' => null, 'dimension' => null], 'error' => __('shop_import_err_empty', 'The file is empty.')];
        if (str_ends_with(strtolower($filename), '.json') || $trim[0] === '[' || $trim[0] === '{') {
            return self::readJson($trim);
        }
        return self::readCsv($contents);
    }

    /** @return array{items: list<array<string, mixed>>, units: array{weight: ?string, dimension: ?string}, error?: string} */
    private static function readCsv(string $contents): array {
        $units = ['weight' => null, 'dimension' => null];
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $contents);
        rewind($fh);
        $first = (string) strtok($contents, "\n");
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = fgetcsv($fh, 0, $sep, '"', '');
        if (!is_array($header)) return ['items' => [], 'units' => $units, 'error' => __('shop_import_err_format', 'This is not a WooCommerce product file.')];
        $keys = [];
        foreach ($header as $i => $h) {
            $h = trim((string) $h);
            if (preg_match('/^Weight \(([a-z]+)\)$/i', $h, $m)) $units['weight'] = strtolower($m[1]);
            if (preg_match('/^(Length|Width|Height) \(([a-z]+)\)$/i', $h, $m)) $units['dimension'] = strtolower($m[2]);
            $keys[$i] = strtolower((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $h));
        }
        if (!in_array('type', $keys, true) || !in_array('name', $keys, true)) {
            return ['items' => [], 'units' => $units, 'error' => __('shop_import_err_format', 'This is not a WooCommerce product file.')];
        }
        $rows = [];
        while (($cells = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
            if ($cells === [null] || $cells === []) continue;
            $row = [];
            foreach ($keys as $i => $k) $row[$k] = isset($cells[$i]) ? trim((string) $cells[$i]) : '';
            $rows[] = $row;
            if (count($rows) > self::MAX_ITEMS) break;
        }
        fclose($fh);

        $items = [];
        $children = [];
        foreach ($rows as $n => $r) {
            $types = array_map('trim', explode(',', strtolower($r['type'] ?? '')));
            $attrs = [];
            for ($a = 1; $a <= 20; $a++) {
                $name = trim((string) ($r["attribute $a name"] ?? ''));
                if ($name === '') continue;
                $attrs[$name] = ['values' => self::splitList((string) ($r["attribute $a value"] ?? '')), 'visible' => ($r["attribute $a visible"] ?? '1') !== '0'];
            }
            $downloads = [];
            for ($d = 1; $d <= 20; $d++) {
                $url = trim((string) ($r["download $d url"] ?? ''));
                if ($url !== '') $downloads[] = ['name' => trim((string) ($r["download $d name"] ?? '')) ?: basename((string) parse_url($url, PHP_URL_PATH)), 'url' => $url];
            }
            $item = [
                'row'               => $n + 2,
                'ref'               => ($r['id'] ?? '') !== '' ? 'woo:' . $r['id'] : '',
                'type'              => in_array('variation', $types, true) ? 'variation' : ($types[0] ?? 'simple'),
                'kind'              => in_array('downloadable', $types, true) ? 'digital' : (in_array('virtual', $types, true) ? 'service' : 'physical'),
                'name'              => self::plainTitle((string) ($r['name'] ?? '')),
                'slug'              => (string) ($r['slug'] ?? ''),
                'status'            => ($r['published'] ?? '1') === '1' ? 'published' : 'draft',
                'hidden'            => ($r['visibility in catalog'] ?? '') === 'hidden',
                'featured'          => ($r['is featured?'] ?? '0') === '1',
                'description'       => self::plainText((string) ($r['description'] ?? '')),
                'short_description' => self::plainText((string) ($r['short description'] ?? '')),
                'sku'               => (string) ($r['sku'] ?? ''),
                'regular_price'     => (string) ($r['regular price'] ?? ''),
                'sale_price'        => (string) ($r['sale price'] ?? ''),
                'sale_from'         => (string) ($r['date sale price starts'] ?? ''),
                'sale_to'           => (string) ($r['date sale price ends'] ?? ''),
                'stock'             => (string) ($r['stock'] ?? ''),
                'in_stock'          => ($r['in stock?'] ?? '1') !== '0',
                'low_stock'         => (string) ($r['low stock amount'] ?? ''),
                'weight'            => (string) ($r['weight'] ?? ''),
                'length'            => (string) ($r['length'] ?? ''),
                'width'             => (string) ($r['width'] ?? ''),
                'height'            => (string) ($r['height'] ?? ''),
                'categories'        => self::categoryNames((string) ($r['categories'] ?? '')),
                'tags'              => self::splitList((string) ($r['tags'] ?? '')),
                'images'            => array_values(array_filter(array_map('trim', explode(',', (string) ($r['images'] ?? ''))))),
                'purchase_note'     => self::plainText((string) ($r['purchase note'] ?? '')),
                'download_limit'    => (string) ($r['download limit'] ?? ''),
                'download_days'     => (string) ($r['download expiry days'] ?? ''),
                'downloads'         => $downloads,
                'upsells'           => self::splitList((string) ($r['upsells'] ?? '')),
                'cross_sells'       => self::splitList((string) ($r['cross-sells'] ?? '')),
                'attributes'        => array_map(static fn(array $a): array => $a['values'], $attrs),
                'variations'        => [],
            ];
            $item['manage_stock'] = $item['stock'] !== '';
            if ($item['type'] === 'variation') {
                $item['options'] = array_map(static fn(array $vals): string => (string) ($vals[0] ?? ''), $item['attributes']);
                $children[self::parentKey((string) ($r['parent'] ?? ''))][] = $item;
            } else {
                $items[] = $item;
            }
        }
        // Hang each variation under its product: Parent is `id:603` or the product's SKU.
        foreach ($items as &$item) {
            $mine = array_merge($children[$item['ref'] !== '' ? 'id:' . substr($item['ref'], 4) : "\0"] ?? [], $item['sku'] !== '' ? ($children['sku:' . $item['sku']] ?? []) : []);
            usort($mine, static fn(array $a, array $b): int => $a['row'] <=> $b['row']); // the file's order
            $item['variations'] = $mine;
            unset($children[$item['ref'] !== '' ? 'id:' . substr($item['ref'], 4) : "\0"], $children['sku:' . $item['sku']]);
        }
        unset($item);
        foreach ($children as $orphans) {
            foreach ($orphans as $o) $items[] = ['orphan' => true] + $o;
        }
        return ['items' => self::finishItems($items), 'units' => $units];
    }

    /** @return array{items: list<array<string, mixed>>, units: array{weight: ?string, dimension: ?string}, error?: string} */
    private static function readJson(string $contents): array {
        $units = ['weight' => null, 'dimension' => null];
        $data = json_decode($contents, true);
        if (is_array($data) && isset($data['products']) && is_array($data['products'])) $data = $data['products'];
        if (!is_array($data) || !array_is_list($data)) return ['items' => [], 'units' => $units, 'error' => __('shop_import_err_format', 'This is not a WooCommerce product file.')];
        $names = static fn($list): array => array_values(array_filter(array_map(static fn($x): string => is_array($x) ? trim((string) ($x['name'] ?? '')) : trim((string) $x), is_array($list) ? $list : [])));
        $one = static function (array $p) use ($names): array {
            $dims = is_array($p['dimensions'] ?? null) ? $p['dimensions'] : [];
            $attrs = [];
            foreach (is_array($p['attributes'] ?? null) ? $p['attributes'] : [] as $a) {
                if (!is_array($a) || trim((string) ($a['name'] ?? '')) === '') continue;
                $attrs[trim((string) $a['name'])] = isset($a['options']) && is_array($a['options']) ? array_values(array_map('strval', $a['options'])) : [(string) ($a['option'] ?? '')];
            }
            $images = [];
            foreach (is_array($p['images'] ?? null) ? $p['images'] : (is_array($p['image'] ?? null) ? [$p['image']] : []) as $img) {
                if (is_array($img) && trim((string) ($img['src'] ?? '')) !== '') $images[] = trim((string) $img['src']);
            }
            $downloads = [];
            foreach (is_array($p['downloads'] ?? null) ? $p['downloads'] : [] as $d) {
                if (is_array($d) && trim((string) ($d['file'] ?? '')) !== '') $downloads[] = ['name' => trim((string) ($d['name'] ?? '')) ?: basename((string) parse_url((string) $d['file'], PHP_URL_PATH)), 'url' => trim((string) $d['file'])];
            }
            $type = (string) ($p['type'] ?? 'simple');
            return [
                'row' => 0, 'ref' => isset($p['id']) ? 'woo:' . (int) $p['id'] : '', 'type' => $type,
                'kind' => !empty($p['downloadable']) ? 'digital' : (!empty($p['virtual']) ? 'service' : 'physical'),
                'name' => self::plainTitle((string) ($p['name'] ?? '')), 'slug' => (string) ($p['slug'] ?? ''),
                'status' => ($p['status'] ?? 'publish') === 'publish' ? 'published' : 'draft', 'hidden' => ($p['catalog_visibility'] ?? '') === 'hidden',
                'featured' => !empty($p['featured']),
                'description' => self::plainText((string) ($p['description'] ?? '')), 'short_description' => self::plainText((string) ($p['short_description'] ?? '')),
                'sku' => (string) ($p['sku'] ?? ''), 'regular_price' => (string) ($p['regular_price'] ?? ''), 'sale_price' => (string) ($p['sale_price'] ?? ''),
                'sale_from' => (string) ($p['date_on_sale_from'] ?? ''), 'sale_to' => (string) ($p['date_on_sale_to'] ?? ''),
                'manage_stock' => !empty($p['manage_stock']) && $p['manage_stock'] !== 'parent', 'stock' => isset($p['stock_quantity']) ? (string) $p['stock_quantity'] : '',
                'in_stock' => ($p['stock_status'] ?? 'instock') !== 'outofstock', 'low_stock' => (string) ($p['low_stock_amount'] ?? ''),
                'weight' => (string) ($p['weight'] ?? ''), 'length' => (string) ($dims['length'] ?? ''), 'width' => (string) ($dims['width'] ?? ''), 'height' => (string) ($dims['height'] ?? ''),
                'categories' => $names($p['categories'] ?? []), 'tags' => $names($p['tags'] ?? []), 'images' => $images,
                'purchase_note' => self::plainText((string) ($p['purchase_note'] ?? '')),
                'download_limit' => (string) (($p['download_limit'] ?? -1) >= 0 ? $p['download_limit'] : ''), 'download_days' => (string) (($p['download_expiry'] ?? -1) >= 0 ? $p['download_expiry'] : ''),
                'downloads' => $downloads,
                'upsells' => array_map(static fn($id): string => 'id:' . (int) $id, is_array($p['upsell_ids'] ?? null) ? $p['upsell_ids'] : []),
                'cross_sells' => array_map(static fn($id): string => 'id:' . (int) $id, is_array($p['cross_sell_ids'] ?? null) ? $p['cross_sell_ids'] : []),
                'attributes' => $attrs, 'variations' => [],
            ];
        };
        $items = [];
        foreach (array_slice($data, 0, self::MAX_ITEMS) as $p) {
            if (!is_array($p)) continue;
            $item = $one($p);
            foreach (is_array($p['variations'] ?? null) ? $p['variations'] : [] as $v) {
                if (!is_array($v)) continue; // a bare id: the REST list does not embed the variation
                $child = $one($v + ['type' => 'variation']);
                $child['type'] = 'variation';
                $child['options'] = array_map(static fn(array $vals): string => (string) ($vals[0] ?? ''), $child['attributes']);
                $item['variations'][] = $child;
            }
            $items[] = $item;
        }
        return ['items' => self::finishItems($items), 'units' => $units];
    }

    /** Work out which attributes make the variants (those the variations use) of every variable item. */
    private static function finishItems(array $items): array {
        foreach ($items as &$item) {
            $used = [];
            foreach ($item['variations'] as $v) foreach (array_keys($v['options'] ?? []) as $name) $used[$name] = true;
            $item['variation_attributes'] = array_keys($used);
        }
        unset($item);
        return $items;
    }

    private static function parentKey(string $parent): string {
        $parent = trim($parent);
        if (preg_match('/^id:(\d+)$/i', $parent, $m)) return 'id:' . $m[1];
        return $parent === '' ? "\0" : 'sku:' . $parent;
    }

    /** `a, b\, c` → ['a', 'b, c'] (WooCommerce escapes a comma inside a value). @return list<string> */
    public static function splitList(string $text): array {
        $parts = preg_split('/(?<!\\\\),/', $text) ?: [];
        return array_values(array_filter(array_map(static fn(string $s): string => trim(str_replace('\\,', ',', $s)), $parts), static fn(string $s): bool => $s !== ''));
    }

    /** `Brittles, Gifts > Holiday` → ['Brittles', 'Holiday']: the last part of each path. @return list<string> */
    public static function categoryNames(string $text): array {
        $out = [];
        foreach (self::splitList($text) as $path) {
            $parts = array_map('trim', explode('>', $path));
            $name = self::plainTitle((string) end($parts));
            if ($name !== '' && !in_array(mb_strtolower($name), array_map('mb_strtolower', $out), true)) $out[] = $name;
        }
        return $out;
    }

    /** A product name without the HTML some shops type into it (`Seasoning <b>LOW SODIUM</b>`). */
    public static function plainTitle(string $html): string {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** HTML (or WooCommerce's `\n`-escaped text) as plain paragraphs separated by an empty line. */
    public static function plainText(string $html): string {
        $s = str_replace(['\\r\\n', '\\n'], "\n", $html);
        $s = (string) preg_replace('~<\s*br\s*/?>~i', "\n", $s);
        $s = (string) preg_replace('~</\s*(p|div|h[1-6]|li|tr|blockquote)\s*>~i', "\n\n", $s);
        $s = (string) preg_replace('~<\s*li[^>]*>~i', '• ', $s);
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $s);
        $s = (string) preg_replace('/ *\n */', "\n", $s);
        return trim((string) preg_replace("/\n{3,}/", "\n\n", $s));
    }

    // ── Units ─────────────────────────────────────────────────────────────

    public static function convertWeight(?float $value, string $from, string $to): ?float {
        if ($value === null || $from === $to) return $value;
        $g = ShopStoreAPI::toGrams($value, $from);
        $per = match ($to) { 'g' => 1.0, 'lb' => 453.59237, 'oz' => 28.349523125, default => 1000.0 };
        return round($g / $per, 3);
    }

    public static function convertLength(?float $value, string $from, string $to): ?float {
        if ($value === null || $from === $to) return $value;
        $mm = $value * match ($from) { 'mm' => 1.0, 'm' => 1000.0, 'in' => 25.4, default => 10.0 };
        return round($mm / match ($to) { 'mm' => 1.0, 'm' => 1000.0, 'in' => 25.4, default => 10.0 }, 2);
    }

    // ── Plan ──────────────────────────────────────────────────────────────

    /**
     * What an import would do, without writing anything.
     *
     * @return array{create: int, update: int, variants: int, images: int, files: int, skipped: int, rows: list<array{name: string, action: string, detail: string, warnings: list<string>}>}
     */
    public static function plan(array $items): array {
        ShopStoreAPI::ensureSchema();
        $out = ['create' => 0, 'update' => 0, 'variants' => 0, 'images' => 0, 'files' => 0, 'skipped' => 0, 'rows' => []];
        $seenSlugs = [];
        foreach ($items as $item) {
            $warnings = [];
            if (!empty($item['orphan'])) {
                $out['skipped']++;
                $out['rows'][] = ['name' => $item['name'] ?: '—', 'action' => 'skip', 'detail' => '', 'warnings' => [__('shop_import_w_orphan', 'A variation whose product is not in the file.')]];
                continue;
            }
            if (in_array($item['type'], ['grouped', 'external'], true)) {
                $out['skipped']++;
                $out['rows'][] = ['name' => $item['name'] ?: '—', 'action' => 'skip', 'detail' => $item['type'], 'warnings' => [__('shop_import_w_type', 'Grouped and external products are not imported.')]];
                continue;
            }
            if ($item['name'] === '') {
                $out['skipped']++;
                $out['rows'][] = ['name' => '—', 'action' => 'skip', 'detail' => '', 'warnings' => [__('shop_import_w_name', 'No name.')]];
                continue;
            }
            $existing = self::match($item);
            $out[$existing ? 'update' : 'create']++;
            $slug = ShopStoreAPI::slugify($item['slug'] !== '' ? $item['slug'] : $item['name']);
            if (!$existing && isset($seenSlugs[$slug])) $warnings[] = sprintf(__('shop_import_w_slug', 'Another product in the file has the address /%s; this one gets a number added.'), $slug);
            $seenSlugs[$slug] = true;
            $variants = count($item['variations']);
            $out['variants'] += $variants;
            $out['images'] += count($item['images']) + count(array_filter(array_column($item['variations'], 'images')));
            $out['files'] += count($item['downloads']);
            $unused = array_diff(array_keys($item['attributes']), $item['variation_attributes']);
            if ($unused !== []) $warnings[] = sprintf(__('shop_import_w_attributes', 'Not imported: the attribute %s, which no variation uses.'), implode(', ', $unused));
            if (count($item['variation_attributes']) > ShopStoreAPI::MAX_ATTRIBUTES) $warnings[] = sprintf(__('shop_err_attributes_many', 'At most %d attributes.'), ShopStoreAPI::MAX_ATTRIBUTES);
            if ($item['type'] === 'variable' && $variants === 0) $warnings[] = __('shop_import_w_no_variations', 'A variable product without variations: imported as a simple product.');
            $noPrice = array_filter($item['variations'], static fn(array $v): bool => ShopStoreAPI::parseMoney($v['regular_price']) === null);
            if ($noPrice !== []) $warnings[] = sprintf(__('shop_import_w_variant_price', '%d variations have no price and will not be sold.'), count($noPrice));
            if ($variants === 0 && ShopStoreAPI::parseMoney($item['regular_price']) === null) $warnings[] = __('shop_import_w_price', 'No price: imported as a draft at 0.00.');
            if ($item['hidden']) $warnings[] = __('shop_import_w_hidden', 'Hidden from the catalogue in WooCommerce: imported as a draft.');
            $detail = $variants > 0 ? sprintf(__('shop_n_variants', '%d variants'), $variants) : ShopStoreAPI::money((int) ShopStoreAPI::parseMoney($item['regular_price']), ShopStoreAPI::currency());
            $out['rows'][] = ['name' => $item['name'], 'action' => $existing ? 'update' : 'create', 'detail' => $detail, 'warnings' => $warnings];
        }
        return $out;
    }

    /** The product an item updates: by import reference, then SKU, then address. */
    private static function match(array $item): ?array {
        $tid = current_tenant_id();
        if ($item['ref'] !== '' && ($row = Database::row("SELECT * FROM shop_products WHERE tenant_id = ? AND external_ref = ?", [$tid, $item['ref']]))) return $row;
        if ($item['sku'] !== '' && ($row = Database::row("SELECT * FROM shop_products WHERE tenant_id = ? AND sku = ?", [$tid, $item['sku']]))) return $row;
        if ($item['slug'] !== '' && ($row = Database::row("SELECT * FROM shop_products WHERE tenant_id = ? AND slug = ?", [$tid, ShopStoreAPI::slugify($item['slug'])]))) return $row;
        return null;
    }

    // ── Run ───────────────────────────────────────────────────────────────

    /**
     * Import the items.
     *
     * @param array{images?: string, status?: string, weight_unit?: ?string, dimension_unit?: ?string, fetch?: callable} $opts
     *   images: `library` (copy into the Media library, the default) or `link` (keep the addresses);
     *   status: `file` (as published in the file, the default) or `draft`;
     *   weight_unit / dimension_unit: the file's units (converted to the shop's);
     *   fetch: (url) => media record, for tests (default Media::importUrl).
     * @return array{created: int, updated: int, variants: int, images: int, files: int, skipped: int, errors: list<string>}
     */
    public static function run(array $items, array $opts = []): array {
        ShopStoreAPI::ensureSchema();
        @set_time_limit(0);
        $res = ['created' => 0, 'updated' => 0, 'variants' => 0, 'images' => 0, 'files' => 0, 'skipped' => 0, 'errors' => []];
        $wFrom = in_array($opts['weight_unit'] ?? '', ShopStoreAPI::WEIGHT_UNITS, true) ? (string) $opts['weight_unit'] : ShopStoreAPI::weightUnit();
        $dFrom = in_array($opts['dimension_unit'] ?? '', ShopStoreAPI::DIMENSION_UNITS, true) ? (string) $opts['dimension_unit'] : ShopStoreAPI::dimensionUnit();
        $images = ($opts['images'] ?? 'library') === 'link' ? 'link' : 'library';
        $fetch = $opts['fetch'] ?? static fn(string $url): array => Media::importUrl($url);
        $imageCache = [];
        $image = static function (string $url) use ($images, $fetch, &$imageCache, &$res): ?string {
            if ($url === '' || !ShopStoreAPI::isImageUrl($url)) return null;
            if ($images === 'link') return $url;
            if (!array_key_exists($url, $imageCache)) {
                $m = $fetch($url);
                $imageCache[$url] = is_array($m) && !empty($m['path']) ? (string) $m['path'] : null;
                if ($imageCache[$url] !== null) $res['images']++;
                else $res['errors'][] = sprintf(__('shop_import_e_image', 'Image not copied: %s'), $url) . (is_array($m) && !empty($m['error']) ? ' (' . $m['error'] . ')' : '');
            }
            return $imageCache[$url];
        };
        $categoryIds = [];
        foreach (ShopStoreAPI::categories() as $c) $categoryIds[mb_strtolower($c['name'])] = $c['id'];
        $weight = static fn(string $raw): string => ($n = ShopStoreAPI::parseMeasure($raw)) === null || $n === false ? '' : ShopStoreAPI::measure(self::convertWeight($n, $wFrom, ShopStoreAPI::weightUnit()));
        $length = static fn(string $raw): string => ($n = ShopStoreAPI::parseMeasure($raw)) === null || $n === false ? '' : ShopStoreAPI::measure(self::convertLength($n, $dFrom, ShopStoreAPI::dimensionUnit()));
        $date = static fn(string $raw): string => is_string($d = ShopStoreAPI::parseDateTime(substr(str_replace('T', ' ', trim($raw)), 0, 19))) ? $d : '';
        $money = static fn(string $raw): string => ($c = ShopStoreAPI::parseMoney($raw)) === null ? '' : number_format($c / 100, 2, '.', '');

        $made = []; // import reference / SKU => product id, for upsells and cross-sells
        $pending = [];
        foreach ($items as $item) {
            if (!empty($item['orphan']) || in_array($item['type'], ['grouped', 'external'], true) || $item['name'] === '') {
                $res['skipped']++;
                continue;
            }
            try {
                $existing = self::match($item);
                $cats = [];
                foreach ($item['categories'] as $name) {
                    $key = mb_strtolower($name);
                    if (!isset($categoryIds[$key])) {
                        $saved = ShopStoreAPI::saveCategory(['name' => $name]);
                        if (!$saved['ok']) $saved = ShopStoreAPI::saveCategory(['name' => $name, 'slug' => ShopStoreAPI::slugify($name, 110) . '-' . substr(md5($name), 0, 4)]);
                        if ($saved['ok']) $categoryIds[$key] = (int) $saved['id'];
                    }
                    if (isset($categoryIds[$key])) $cats[] = $categoryIds[$key];
                }
                $imgs = array_values(array_filter(array_map($image, array_slice($item['images'], 0, ShopStoreAPI::MAX_IMAGES))));

                // Variants: the attributes the variations use, every value any variation has, each variation's fields.
                $vars = array_values(array_filter($item['variations'], static fn(array $v): bool => array_filter($v['options'] ?? []) !== []));
                $attrNames = array_slice($item['variation_attributes'], 0, ShopStoreAPI::MAX_ATTRIBUTES);
                $attributes = [];
                foreach ($attrNames as $name) {
                    // The product's own list of values first (its order), then any value only a variation has.
                    $values = [];
                    foreach ($item['attributes'][$name] ?? [] as $val) {
                        $val = mb_substr(trim((string) $val), 0, 60);
                        $used = array_filter($vars, static fn(array $v): bool => mb_strtolower(trim((string) ($v['options'][$name] ?? ''))) === mb_strtolower($val));
                        if ($val !== '' && $used !== [] && !in_array(mb_strtolower($val), array_map('mb_strtolower', $values), true)) $values[] = $val;
                    }
                    foreach ($vars as $v) {
                        $val = mb_substr(trim((string) ($v['options'][$name] ?? '')), 0, 60);
                        if ($val !== '' && !in_array(mb_strtolower($val), array_map('mb_strtolower', $values), true)) $values[] = $val;
                    }
                    if ($values !== []) $attributes[] = ['name' => mb_substr($name, 0, 60), 'values' => array_slice($values, 0, ShopStoreAPI::MAX_ATTRIBUTE_VALUES)];
                }
                $fields = [];
                $prices = [];
                foreach ($vars as $v) {
                    $values = [];
                    foreach ($attributes as $a) {
                        $val = mb_substr(trim((string) ($v['options'][$a['name']] ?? '')), 0, 60);
                        // "Any" value in WooCommerce: the first one stands in for it.
                        $values[] = $val !== '' && in_array($val, $a['values'], true) ? $val : $a['values'][0];
                    }
                    $price = ShopStoreAPI::parseMoney($v['regular_price']);
                    if ($price !== null) $prices[] = $price;
                    $vImg = $v['images'] !== [] ? $image($v['images'][0]) : null;
                    $stock = $v['manage_stock'] && preg_match('/^-?\d+$/', $v['stock']) ? (string) max(0, (int) $v['stock']) : (!$v['in_stock'] ? '0' : '');
                    $fields[ShopStoreAPI::optionsKey($values)] = [
                        'sku' => $v['sku'], 'price' => $money($v['regular_price']), 'sale_price' => $price !== null ? $money($v['sale_price']) : '',
                        'stock' => $stock, 'image' => $vImg ?? '', 'weight' => $weight($v['weight']),
                        'size' => self::sizeOf($length($v['length']), $length($v['width']), $length($v['height'])),
                        'enabled' => $price !== null && $v['status'] === 'published' ? '1' : '', 'external_ref' => $v['ref'],
                    ];
                }
                // A combination no variation lists is not sold.
                foreach (ShopStoreAPI::combinations($attributes) as $combo) {
                    $key = ShopStoreAPI::optionsKey($combo);
                    if (!isset($fields[$key])) $fields[$key] = ['enabled' => ''];
                }
                $productPrice = $attributes !== [] && $prices !== [] ? min($prices) : ShopStoreAPI::parseMoney($item['regular_price']);
                $status = ($opts['status'] ?? 'file') === 'draft' || $item['hidden'] || $productPrice === null ? 'draft' : $item['status'];
                $slugBase = $item['slug'] !== '' ? $item['slug'] : $item['name'];
                $slug = $existing ? (string) $existing['slug'] : self::freeSlug(ShopStoreAPI::slugify($slugBase));
                $manage = $attributes === [] && ($item['manage_stock'] || !$item['in_stock']);
                $in = [
                    'title' => $item['name'], 'slug' => $slug, 'sku' => $item['sku'],
                    'price' => $productPrice === null ? '0' : number_format($productPrice / 100, 2, '.', ''),
                    'sale_price' => $attributes === [] && ShopStoreAPI::parseMoney($item['sale_price']) !== null && ShopStoreAPI::parseMoney($item['sale_price']) < (int) $productPrice ? $money($item['sale_price']) : '',
                    'sale_starts_at' => $date($item['sale_from']), 'sale_ends_at' => $date($item['sale_to']),
                    'track_stock' => $manage ? '1' : '', 'stock_qty' => $manage ? (string) max(0, (int) ($item['manage_stock'] ? $item['stock'] : 0)) : '',
                    'short_description' => $item['short_description'], 'description' => $item['description'], 'images' => implode("\n", $imgs),
                    'status' => $status, 'sort_order' => $existing['sort_order'] ?? 0, 'categories' => $cats,
                    'product_type' => $item['kind'], 'weight' => $weight($item['weight']), 'length' => $length($item['length']), 'width' => $length($item['width']), 'height' => $length($item['height']),
                    'featured' => $item['featured'] ? '1' : '', 'low_stock' => preg_match('/^\d+$/', $item['low_stock']) ? $item['low_stock'] : '',
                    'purchase_note' => $item['purchase_note'],
                    'download_limit' => preg_match('/^[1-9]\d*$/', $item['download_limit']) ? $item['download_limit'] : '',
                    'download_days' => preg_match('/^[1-9]\d*$/', $item['download_days']) ? $item['download_days'] : '',
                    'tags' => $item['tags'], 'external_ref' => $item['ref'] !== '' ? $item['ref'] : ($existing['external_ref'] ?? ''),
                    'attributes' => $attributes, 'variants' => $fields,
                ];
                if ($in['sale_price'] === '') { $in['sale_starts_at'] = ''; $in['sale_ends_at'] = ''; }
                $saved = ShopStoreAPI::saveProduct($in, $existing ? (int) $existing['id'] : 0);
                if (!$saved['ok']) {
                    $res['skipped']++;
                    $res['errors'][] = $item['name'] . ': ' . implode(' ', array_values($saved['errors'] ?? []));
                    continue;
                }
                $id = (int) $saved['id'];
                $res[$existing ? 'updated' : 'created']++;
                $res['variants'] += count($vars);
                foreach ($item['downloads'] as $d) $res['files'] += self::importFile($id, $d, $res['errors']) ? 1 : 0;
                if ($item['ref'] !== '') $made[$item['ref']] = $id;
                if ($item['sku'] !== '') $made['sku:' . $item['sku']] = $id;
                $pending[$id] = ['upsell' => $item['upsells'], 'cross_sell' => $item['cross_sells']];
            } catch (\Throwable $e) {
                $res['skipped']++;
                $res['errors'][] = $item['name'] . ': ' . $e->getMessage();
                slate_log('Shop import failed for ' . $item['name'] . ': ' . $e->getMessage(), 'warning');
            }
        }
        // Links last: they may name products later in the file.
        foreach ($pending as $id => $kinds) {
            foreach ($kinds as $kind => $refs) {
                if ($refs === []) continue;
                $ids = [];
                foreach ($refs as $r) {
                    $r = trim($r);
                    $key = preg_match('/^id:(\d+)$/i', $r, $m) ? 'woo:' . $m[1] : 'sku:' . $r;
                    $ids[] = $made[$key] ?? (int) Database::value("SELECT id FROM shop_products WHERE tenant_id = ? AND " . (str_starts_with($key, 'woo:') ? 'external_ref' : 'sku') . " = ?",
                        [current_tenant_id(), str_starts_with($key, 'woo:') ? $key : substr($key, 4)]);
                }
                ShopStoreAPI::setLinks($id, $kind, $ids);
            }
        }
        if (class_exists('AuditLog')) AuditLog::record('shop.products_imported', 'shop', ['created' => $res['created'], 'updated' => $res['updated'], 'skipped' => $res['skipped']]);
        return $res;
    }

    private static function sizeOf(string $l, string $w, string $h): string {
        return ($l . $w . $h) === '' ? '' : implode(' x ', array_map(static fn(string $p): string => $p !== '' ? $p : '-', [$l, $w, $h]));
    }

    /** An address no product has yet: the slug, else the slug with -2, -3, … */
    private static function freeSlug(string $slug): string {
        $tid = current_tenant_id();
        $try = $slug;
        for ($n = 2; (int) Database::value("SELECT COUNT(*) FROM shop_products WHERE tenant_id = ? AND slug = ?", [$tid, $try]) > 0; $n++) {
            $try = substr($slug, 0, 180) . '-' . $n;
        }
        return $try;
    }

    /** Copy a downloadable file from the old shop into the product's private files (skipped when it is there already). */
    private static function importFile(int $productId, array $download, array &$errors): bool {
        foreach (ShopStoreAPI::productFiles($productId) as $f) if ($f['name'] === $download['name']) return false;
        if (!class_exists('\\Slate\\Services\\Media\\RemoteFile')) return false;
        $got = \Slate\Services\Media\RemoteFile::download($download['url'], ShopStoreAPI::MAX_FILE_BYTES);
        if (empty($got['ok'])) {
            $errors[] = sprintf(__('shop_import_e_file', 'File not copied: %s'), $download['url']);
            return false;
        }
        try {
            $added = ShopStoreAPI::addProductFile($productId, (string) $got['file'], $download['name']);
            if (!$added['ok']) $errors[] = $download['name'] . ': ' . $added['error'];
            return $added['ok'];
        } finally {
            @unlink((string) $got['file']);
        }
    }
}
