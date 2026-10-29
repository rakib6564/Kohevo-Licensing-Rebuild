<?php
/**
 * Kohevo Studio — a content type a Kohevo plugin keeps in its own tables and Studio shows through a Single template.
 *
 * Shop's products are rows of `shop_products`, not Studio pages. A plugin declares the type in its `studio-widgets.php`
 * (`'singles' => [...]`) and Studio then:
 *   - serves `/{prefix}/{slug}` with the published Single template whose display conditions pick that content type
 *     (`singular` = the type's key), rendered with the item in the render context (`single_item`);
 *   - offers the type in the display-conditions dialog;
 *   - lists the items in the sitemap.
 * With no Single template for the type, or the plugin inactive or unlicensed, the address is the ordinary 404.
 *
 * `find()` answers only a published item of that tenant; the shape it returns is public (it is rendered to anyone).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Registry;

interface PluginSingleInterface
{
    /** The content type key display conditions name (`product`): lowercase letters, digits and `_`. */
    public function contentType(): string;

    /** The type's plural name for the conditions dialog (`Products`). */
    public function label(): string;

    /** The module entitlement the plugin's widgets carry (`shop`). */
    public function requiredEntitlement(): string;

    /** The address prefix of an item (`product` → `/product/{slug}`), normalised by `PageRoutes::normalizePrefix()`. */
    public function prefix(int $tenantId): string;

    /**
     * One published item by its slug, or null.
     *
     * @return ?array{id: int, slug: string, title: string, description?: string, image?: string, data?: array<string, mixed>}
     */
    public function find(int $tenantId, string $slug): ?array;

    /**
     * Every published item's slug and last change, for the sitemap (bounded by the plugin).
     *
     * @return list<array{slug: string, updated_at?: string}>
     */
    public function sitemap(int $tenantId): array;
}
