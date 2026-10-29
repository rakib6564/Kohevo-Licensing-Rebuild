<?php
/**
 * Kohevo Studio (studio-builder) — what the menu editor's "Add menu items" column offers: the site's Studio pages, the
 * items of each content type the site created (`article`, ...), and the items a plugin keeps itself (Shop products)
 * while that plugin is active and licensed.
 *
 * A group is one box of the column; an item is a title and the public address a menu link to it gets. Only published
 * pages are offered (a link to a draft would be a 404 for visitors). The menu stores the address, not the page, so a
 * menu saved before this editor existed reads the same.
 *
 * `groups()` is pure (no database) so it is unit-testable; `forTenant()` gathers the inputs.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Menu;

use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\PageRoutes;
use Slate\Module\StudioBuilder\Registry\PluginSingleInterface;
use Slate\Module\StudioBuilder\Registry\PluginWidgetCatalog;

final class MenuSources
{
    /** Items one group offers at most (the editor searches within them). */
    public const MAX_ITEMS = 500;

    /**
     * The add panel's groups, in order: Pages, then each content type, then each plugin's items.
     *
     * @param list<array<string, mixed>> $pages      StudioEditorViews::page() rows
     * @param array<int, string>         $typesByPage page id => content type key
     * @param array<string, array<string, mixed>> $postTypes the site's content types, by key
     * @param list<array{key: string, label: string, singular: string, items: list<array{title: string, url: string, updated?: string}>}> $pluginGroups
     * @return list<array{key: string, label: string, singular: string, items: list<array{title: string, url: string, updated: string}>}>
     */
    public static function groups(array $pages, array $typesByPage, array $postTypes, array $pluginGroups = []): array
    {
        $groups = ['page' => ['key' => 'page', 'label' => self::t('studio_menu_source_pages', 'Pages'), 'singular' => self::t('studio_menu_type_page', 'Page'), 'items' => []]];
        foreach ($postTypes as $key => $type) {
            $key = (string) $key;
            if ($key === '' || $key === 'page') {
                continue;
            }
            $singular = trim((string) ($type['singular'] ?? '')) !== '' ? (string) $type['singular'] : $key;
            $groups['type:' . $key] = ['key' => 'type:' . $key, 'label' => trim((string) ($type['plural'] ?? '')) !== '' ? (string) $type['plural'] : $singular, 'singular' => $singular, 'items' => []];
        }
        foreach ($pages as $page) {
            if (!in_array($page['page_type'] ?? '', PageMeta::PAGE_TYPES, true) || empty($page['is_published'])) {
                continue;
            }
            $url = (string) ($page['public_path'] ?? '');
            $title = trim((string) ($page['title'] ?? ''));
            if ($url === '' || $title === '') {
                continue;
            }
            $type = $typesByPage[(int) ($page['id'] ?? 0)] ?? '';
            $group = isset($groups['type:' . $type]) ? 'type:' . $type : 'page';
            $groups[$group]['items'][] = ['title' => $title, 'url' => $url, 'updated' => (string) ($page['updated_at'] ?? $page['published_at'] ?? '')];
        }
        foreach ($pluginGroups as $plugin) {
            $items = [];
            foreach ($plugin['items'] as $item) {
                if (($item['title'] ?? '') !== '' && ($item['url'] ?? '') !== '') {
                    $items[] = ['title' => (string) $item['title'], 'url' => (string) $item['url'], 'updated' => (string) ($item['updated'] ?? '')];
                }
            }
            $groups['plugin:' . $plugin['key']] = ['key' => 'plugin:' . $plugin['key'], 'label' => $plugin['label'], 'singular' => $plugin['singular'], 'items' => $items];
        }
        foreach ($groups as $k => $group) {
            // Alphabetical, as "View all" lists them; the editor sorts by `updated` for "Most recent".
            usort($group['items'], static fn(array $a, array $b): int => strnatcasecmp($a['title'], $b['title']));
            $groups[$k]['items'] = array_slice($group['items'], 0, self::MAX_ITEMS);
        }
        return array_values($groups);
    }

    /**
     * The groups for one site.
     *
     * @param list<array<string, mixed>> $pages StudioApplicationService::listPages()
     * @return list<array{key: string, label: string, singular: string, items: list<array{title: string, url: string, updated: string}>}>
     */
    public static function forTenant(int $tenantId, array $pages): array
    {
        try {
            $types = PageMeta::postTypes($tenantId);
            $typesByPage = PageMeta::typesByPage($tenantId);
        } catch (\Throwable) {
            $types = [];
            $typesByPage = [];
        }
        return self::groups($pages, $typesByPage, $types, self::pluginGroups($tenantId));
    }

    /**
     * The items plugins keep themselves (PluginSingleInterface), one group per plugin content type, only while the
     * plugin is active and licensed for the site.
     *
     * @return list<array{key: string, label: string, singular: string, items: list<array{title: string, url: string, updated?: string}>}>
     */
    public static function pluginGroups(int $tenantId): array
    {
        $out = [];
        try {
            $singles = PluginWidgetCatalog::singles();
        } catch (\Throwable) {
            return [];
        }
        foreach ($singles as $single) {
            if (!$single instanceof PluginSingleInterface) {
                continue;
            }
            try {
                if (!\Slate\Services\Licensing\EntitlementService::canAccess($tenantId, $single->requiredEntitlement())) {
                    continue;
                }
                $prefix = PageRoutes::normalizePrefix($single->prefix($tenantId));
                if (!is_string($prefix) || $prefix === '') {
                    continue;
                }
                $items = [];
                foreach ($single->sitemap($tenantId) as $item) {
                    $slug = $item['slug'] ?? null;
                    if (is_string($slug) && $slug !== '') {
                        $items[] = ['title' => is_string($item['title'] ?? null) && $item['title'] !== '' ? $item['title'] : $slug, 'url' => PageRoutes::itemPath($tenantId, $prefix, $slug), 'updated' => (string) ($item['updated_at'] ?? '')];
                    }
                }
            } catch (\Throwable) {
                continue; // a plugin that fails to answer is left out of the panel, never an error on the screen
            }
            $type = $single->contentType();
            $out[] = ['key' => $type, 'label' => self::t('studio_menu_source_' . $type, $single->label()), 'singular' => self::t('studio_menu_type_' . $type, ucfirst(str_replace('_', ' ', $type))), 'items' => $items];
        }
        return $out;
    }

    private static function t(string $key, string $default): string
    {
        return function_exists('__') ? (string) __($key, $default) : $default;
    }
}
