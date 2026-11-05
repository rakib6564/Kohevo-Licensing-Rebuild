<?php
/**
 * Shop — products as a Studio content type kept in Shop's own tables (PluginSingleInterface).
 *
 * `/product/{slug}` (the prefix is a Shop setting) is served by Studio with the published Single template whose
 * display conditions name Products; the single product widgets read the product from the render context. Only a
 * published product of the tenant being served is found.
 */

declare(strict_types=1);

namespace Slate\Plugin\Shop\Studio;

use Slate\Module\StudioBuilder\Registry\PluginSingleInterface;

final class ShopProductSingle implements PluginSingleInterface
{
    public function contentType(): string
    {
        return 'product';
    }

    public function label(): string
    {
        return 'Products';
    }

    public function requiredEntitlement(): string
    {
        return 'shop';
    }

    public function prefix(int $tenantId): string
    {
        return class_exists('ShopStoreAPI') && $tenantId === (int) current_tenant_id() ? \ShopStoreAPI::productPrefix() : 'product';
    }

    public function find(int $tenantId, string $slug): ?array
    {
        if (!class_exists('ShopStoreAPI') || $tenantId !== (int) current_tenant_id()) {
            return null;
        }
        $product = \ShopStoreAPI::productBySlug($slug);
        if ($product === null) {
            return null;
        }
        return [
            'id'          => (int) $product['id'],
            'slug'        => (string) $product['slug'],
            'title'       => (string) $product['title'],
            'description' => (string) ($product['short_description'] !== '' ? $product['short_description'] : $product['description']),
            'image'       => (string) $product['image'],
            'seo_title'       => (string) ($product['seo_title'] ?? ''),
            'seo_description' => (string) ($product['seo_description'] ?? ''),
            'schema'      => self::schema($product),
            'data'        => $product,
        ];
    }

    /**
     * The product for search engines (schema.org): a Product with its offer (or the price range of its variants), and
     * the breadcrumb Home › Shop › product. Studio writes it into the page head (StructuredData).
     *
     * @param array<string, mixed> $p ShopStoreAPI::publicProduct()
     * @return list<array<string, mixed>>
     */
    public static function schema(array $p): array
    {
        $base = rtrim((string) SLATE_URL, '/');
        if ($base === '' || !preg_match('#^https?://#i', $base)) {
            return [];
        }
        $url = $base . (string) $p['url'];
        $price = static fn(int $cents): string => number_format($cents / 100, 2, '.', '');
        $availability = 'https://schema.org/' . (!\ShopStoreAPI::closed() && !empty($p['in_stock']) ? 'InStock' : 'OutOfStock');
        $currency = strtoupper((string) $p['currency']);
        if (!empty($p['has_variants']) && (int) $p['min_cents'] !== (int) $p['max_cents']) {
            $offers = ['@type' => 'AggregateOffer', 'priceCurrency' => $currency, 'lowPrice' => $price((int) $p['min_cents']), 'highPrice' => $price((int) $p['max_cents']),
                'offerCount' => count((array) $p['variants']), 'availability' => $availability, 'url' => $url];
        } else {
            $offers = ['@type' => 'Offer', 'priceCurrency' => $currency, 'price' => $price((int) ($p['has_variants'] ? $p['min_cents'] : $p['effective_cents'])),
                'availability' => $availability, 'itemCondition' => 'https://schema.org/NewCondition', 'url' => $url];
            if (!empty($p['on_sale']) && ($ends = (string) ($p['sale_ends_at'] ?? '')) !== '' && ($ts = strtotime($ends)) !== false) {
                $offers['priceValidUntil'] = date('Y-m-d', $ts);
            }
        }
        $text = (string) ($p['seo_description'] ?? '') !== '' ? (string) $p['seo_description'] : ((string) $p['description'] !== '' ? (string) $p['description'] : (string) $p['short_description']);
        $product = ['@type' => 'Product', '@id' => $url . '#product', 'name' => (string) $p['title'], 'url' => $url];
        $plain = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($plain !== '') $product['description'] = mb_substr($plain, 0, 5000);
        $images = array_values(array_filter(array_map(static fn($i): string => \ShopStoreAPI::assetUrl((string) $i), (array) ($p['images'] ?? []))));
        if ($images !== []) $product['image'] = array_slice($images, 0, 10);
        if ((string) ($p['sku'] ?? '') !== '') $product['sku'] = (string) $p['sku'];
        $cats = (array) ($p['categories'] ?? []);
        if (isset($cats[0]['name']) && is_string($cats[0]['name'])) $product['category'] = $cats[0]['name'];
        if (($p['product_type'] ?? '') === 'physical' && is_numeric($p['weight'] ?? null) && (float) $p['weight'] > 0) {
            $unit = ['kg' => 'KGM', 'g' => 'GRM', 'lb' => 'LBR', 'oz' => 'ONZ'][(string) ($p['weight_unit'] ?? '')] ?? null;
            if ($unit !== null) $product['weight'] = ['@type' => 'QuantitativeValue', 'value' => (float) $p['weight'], 'unitCode' => $unit];
        }
        $product['offers'] = $offers;

        $crumbs = [['@type' => 'ListItem', 'position' => 1, 'name' => __('shop_breadcrumb_home', 'Home'), 'item' => $base . '/']];
        if (($shop = \ShopStoreAPI::shopPageUrl()) !== '') {
            $slug = trim((string) \ShopStoreAPI::setting('shop_page'), ' /');
            $name = '';
            try {
                $name = (string) (\Database::value("SELECT title FROM studiobuilder_pages WHERE tenant_id = ? AND slug = ? AND status = 'published' LIMIT 1", [current_tenant_id(), $slug]) ?? '');
            } catch (\Throwable $e) {
                $name = '';
            }
            $crumbs[] = ['@type' => 'ListItem', 'position' => 2, 'name' => $name !== '' ? $name : __('shop_products', 'Products'), 'item' => $shop];
        }
        $crumbs[] = ['@type' => 'ListItem', 'position' => count($crumbs) + 1, 'name' => (string) $p['title'], 'item' => $url];
        return [$product, ['@type' => 'BreadcrumbList', '@id' => $url . '#breadcrumb', 'itemListElement' => $crumbs]];
    }

    public function sitemap(int $tenantId): array
    {
        return class_exists('ShopStoreAPI') && $tenantId === (int) current_tenant_id() ? \ShopStoreAPI::sitemap() : [];
    }
}
