<?php
/**
 * Shop — the Studio pages a shop needs, made in one go from Shop settings: Cart, Checkout and Order received (each
 * holding its widget), and the product page's Single template (display condition: Products) laid out with the single
 * product widgets: images beside title, price, stock, short description, Add to cart and meta, the description below.
 *
 * A page whose address is already taken is left alone, so running it again never overwrites an author's work. Pages
 * are created and published through Studio's application service, like any page an author makes.
 */

declare(strict_types=1);

namespace Slate\Plugin\Shop\Studio;

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;

final class ShopStudioPages
{
    public const TEMPLATE_SLUG = 'template-product';

    /**
     * @return list<array{slug: string, title: string, id: ?int, created: bool}>
     */
    public static function create(StudioRuntime $runtime, StudioActor $actor): array
    {
        $defs = [];
        foreach ($runtime->registry->editorManifests() as $m) {
            $defs[$m['type']] = $m;
        }
        $b = static function (string $type, array $props = [], array $children = []) use ($defs): array {
            if (!isset($defs[$type])) {
                throw new \RuntimeException("Block type {$type} is not available (is Shop active and licensed?).");
            }
            return [
                'id'         => CanonicalDocumentSchema::newBlockId(),
                'type'       => $type,
                'version'    => (int) ($defs[$type]['version'] ?? 1),
                'props'      => array_replace((array) ($defs[$type]['default_props'] ?? []), $props),
                'style'      => ['align' => ['base' => 'left']],
                'visibility' => ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']],
                'bindings'   => [],
                'children'   => $children,
            ];
        };
        $container = static fn(string $label, array $blocks, ?string $size = null): array => CanonicalDocumentSchema::newContainer([
            'metadata'   => ['label' => $label],
            'props'      => ['gap' => 'md'],
            'style'      => ['surface_token' => 'surface.primary', 'layout' => ['content_width' => 'boxed', 'gutter' => 'site', 'padding_y' => 'lg'] + ($size !== null ? ['content_size' => $size] : [])],
            'responsive' => ['mobile' => ['style' => ['layout' => ['padding_y' => 'md']]]],
            'children'   => $blocks,
        ]);
        $t = static fn(string $key, string $en): string => function_exists('__') ? (string) __($key, $en) : $en;

        $pages = [
            [\ShopStoreAPI::setting('cart_page'), $t('shop_cart', 'Cart'), 'page', [], [$container('Cart', [
                $b('core.heading', ['text' => $t('shop_cart', 'Cart'), 'level' => 'h1']),
                $b('shop.cart'),
            ])]],
            [\ShopStoreAPI::setting('checkout_page'), $t('shop_checkout', 'Checkout'), 'page', [], [$container('Checkout', [
                $b('core.heading', ['text' => $t('shop_checkout', 'Checkout'), 'level' => 'h1']),
                $b('shop.checkout'),
            ])]],
            [\ShopStoreAPI::setting('thanks_page'), $t('shop_order_received', 'Order received'), 'page', [], [$container('Order', [
                $b('core.heading', ['text' => $t('shop_order_received', 'Order received'), 'level' => 'h1']),
                $b('shop.order_confirmation'),
            ])]],
            [self::TEMPLATE_SLUG, '[Theme] ' . $t('shop_product_page', 'Product page'), 'system',
                ['template_type' => 'single', 'conditions' => ['rules' => [['action' => 'include', 'condition' => 'singular', 'value' => 'product']]]],
                [
                    $container('Product', [
                        $b('layout.grid', ['columns' => 2, 'gap' => 'lg', 'align' => 'start'], [
                            $b('shop.product_image'),
                            $b('core.container', ['direction' => 'vertical', 'gap' => 'sm'], [
                                $b('shop.product_title'),
                                $b('shop.product_price'),
                                $b('shop.product_stock'),
                                $b('shop.product_description', ['source' => 'short']),
                                $b('shop.product_add_to_cart'),
                                $b('shop.product_meta'),
                            ]),
                        ]),
                    ], 'wide'),
                    $container('Description', [$b('shop.product_description')]),
                ]],
        ];

        $out = [];
        foreach ($pages as [$slug, $title, $pageType, $settings, $containers]) {
            $existing = $runtime->pages->findBySlug($slug, $pageType);
            if ($existing !== null) {
                $out[] = ['slug' => $slug, 'title' => $title, 'id' => (int) $existing['id'], 'created' => false];
                continue;
            }
            $doc = CanonicalDocumentSchema::emptyDocument($pageType, 'default', $title);
            $doc['containers'] = $containers;
            $doc['settings'] = array_replace($doc['settings'], $settings);
            // A shop's own pages and a template's page are never search results.
            $doc['seo']['robots'] = 'noindex,follow';
            $created = $runtime->app->createPage($actor, $title, $slug, $pageType, 'standalone');
            $pageId = (int) $created['page']['id'];
            $draft = $runtime->app->saveDraft($actor, $pageId, $doc, (int) $created['revision']['id'], 'manual', 'Shop pages');
            $runtime->app->publish($actor, $pageId, (int) $draft['revision']['id'], 'Shop pages');
            $out[] = ['slug' => $slug, 'title' => $title, 'id' => $pageId, 'created' => true];
        }
        return $out;
    }
}
