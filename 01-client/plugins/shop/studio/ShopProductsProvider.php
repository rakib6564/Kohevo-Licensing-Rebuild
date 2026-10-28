<?php
/**
 * Shop — `shop.products` Studio data provider: the published products a Product grid lists.
 *
 * Thin adapter over `\ShopStoreAPI::listProducts()` (tenant-scoped, published only, public shape). Its parameter is
 * what an author picks for the grid in the Inspector: a category, from this shop's categories. The grid renderer pages
 * through the same listing itself, in the order the block asks for (the page number comes from the visitor's address).
 */

declare(strict_types=1);

namespace Slate\Plugin\Shop\Studio;

use Slate\Module\StudioBuilder\Provider\ParamChoicesProviderInterface;
use Slate\Module\StudioBuilder\Provider\PublicDataProviderInterface;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Tenancy\TenantContext;

final class ShopProductsProvider implements PublicDataProviderInterface, ParamChoicesProviderInterface
{
    public const ORDERS = ['menu', 'newest', 'price_asc', 'price_desc', 'title'];

    public function key(): string
    {
        return 'shop.products';
    }

    public function parameterSchema(): FieldSchema
    {
        return FieldSchema::define([
            ['key' => 'category', 'type' => 'string', 'label' => 'Category', 'required' => false, 'max_length' => 120],
        ]);
    }

    public function requiredEntitlement(): ?string
    {
        return 'shop';
    }

    public function requiredPermission(): string
    {
        return StudioPermissions::VIEW;
    }

    public function maxResults(): int
    {
        return 48;
    }

    public function execute(TenantContext $tenants, array $params): array
    {
        if (!class_exists('ShopStoreAPI') || $tenants->id() !== (int) current_tenant_id()) {
            return [];
        }
        return \ShopStoreAPI::listProducts([
            'category' => (string) ($params['category'] ?? ''),
            'per_page' => $this->maxResults(),
        ])['rows'];
    }

    public function paramChoices(string $param): array
    {
        if ($param !== 'category' || !class_exists('ShopStoreAPI')) {
            return [];
        }
        try {
            $categories = \ShopStoreAPI::categories();
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($categories as $c) {
            $out[] = ['value' => $c['slug'], 'label' => $c['name']];
            if (count($out) >= self::MAX_CHOICES) {
                break;
            }
        }
        return $out;
    }
}
