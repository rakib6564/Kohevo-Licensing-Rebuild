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
            'data'        => $product,
        ];
    }

    public function sitemap(int $tenantId): array
    {
        return class_exists('ShopStoreAPI') && $tenantId === (int) current_tenant_id() ? \ShopStoreAPI::sitemap() : [];
    }
}
