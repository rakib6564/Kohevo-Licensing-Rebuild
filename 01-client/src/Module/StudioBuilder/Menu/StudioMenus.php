<?php
/**
 * Kohevo Studio (studio-builder) — Named site menus (Header menu, Footer menu, Services menu, ...).
 *
 * A tenant keeps a few named lists of links; a Nav menu block picks one by its key instead of carrying its own links,
 * so a link changed here changes every place that menu is shown. Stored as ONE tenant-scoped setting
 * (`studio_menus`, JSON) rather than a new table, so there is nothing to migrate on a live site.
 *
 * The validation here is the only gate: a menu is read back through `normalize()` too, so a hand-edited setting can
 * never put an unsafe URL or an oversized list on a page. URLs go through the same allowlist as every other Studio link
 * (`FieldSchema::isSafeUrl`) and are checked again at render time by `Html::link`.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Menu;

use Slate\Module\StudioBuilder\Schema\FieldSchema;

final class StudioMenus
{
    public const SETTING_KEY     = 'studio_menus';
    public const MAX_MENUS       = 15;
    public const MAX_ITEMS       = 30;
    public const MAX_NAME        = 80;
    public const MAX_LABEL       = 80;
    public const MAX_URL         = 1000;
    public const KEY_PATTERN     = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    /** Suggested starting menus, offered by the admin screen as one-click names. */
    public const SUGGESTED = ['header' => 'Header menu', 'footer' => 'Footer menu', 'services' => 'Services menu'];

    /** @var array<int, string> per-request memo of the raw setting, by tenant */
    private static array $raw = [];

    /**
     * Every menu of a tenant, keyed by menu key.
     *
     * @return array<string, array{key: string, name: string, items: list<array{label: string, url: string, new_tab: bool}>}>
     */
    public static function all(int $tenantId): array
    {
        return self::decode(self::raw($tenantId));
    }

    /** @return array{key: string, name: string, items: list<array{label: string, url: string, new_tab: bool}>}|null */
    public static function find(int $tenantId, string $key): ?array
    {
        return self::all($tenantId)[$key] ?? null;
    }

    /** Short identity of the whole menu set: part of a page's compile fingerprint, so editing a menu refreshes the pages that show it. */
    public static function fingerprint(int $tenantId): string
    {
        $raw = self::raw($tenantId);
        return $raw === '' ? '-' : substr(hash('sha256', $raw), 0, 16);
    }

    /**
     * Create or replace one menu. Returns the stored menu, or throws \InvalidArgumentException with a readable reason.
     *
     * @param array<int, mixed> $items rows of ['label' =>, 'url' =>, 'new_tab' =>]
     * @return array{key: string, name: string, items: list<array{label: string, url: string, new_tab: bool}>}
     */
    public static function save(int $tenantId, string $key, string $name, array $items, ?string $replacesKey = null): array
    {
        $menu = self::validate($key, $name, $items);
        $all = self::all($tenantId);
        if ($replacesKey !== null && $replacesKey !== $key) {
            if (isset($all[$key])) {
                throw new \InvalidArgumentException('A menu with this key already exists.');
            }
            unset($all[$replacesKey]);
        } elseif ($replacesKey === null && isset($all[$key])) {
            throw new \InvalidArgumentException('A menu with this key already exists.');
        }
        $all[$key] = $menu;
        if (count($all) > self::MAX_MENUS) {
            throw new \InvalidArgumentException('You can keep up to ' . self::MAX_MENUS . ' menus.');
        }
        self::write($tenantId, $all);
        return $menu;
    }

    public static function delete(int $tenantId, string $key): bool
    {
        $all = self::all($tenantId);
        if (!isset($all[$key])) {
            return false;
        }
        unset($all[$key]);
        self::write($tenantId, $all);
        return true;
    }

    /**
     * One menu as the form posted it, checked. Pure: no storage, so it is unit-testable.
     *
     * @param array<int, mixed> $items
     * @return array{key: string, name: string, items: list<array{label: string, url: string, new_tab: bool}>}
     */
    public static function validate(string $key, string $name, array $items): array
    {
        $key  = trim($key);
        $name = trim($name);
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new \InvalidArgumentException('The key uses lowercase letters, numbers and hyphens (up to 63 characters).');
        }
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            throw new \InvalidArgumentException('Give the menu a name (up to ' . self::MAX_NAME . ' characters).');
        }
        $clean = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            $url   = trim((string) ($row['url'] ?? ''));
            if ($label === '' && $url === '') {
                continue; // an empty row is just a row the author did not use
            }
            if ($label === '' || mb_strlen($label) > self::MAX_LABEL) {
                throw new \InvalidArgumentException('Every link needs a label (up to ' . self::MAX_LABEL . ' characters).');
            }
            if ($url === '' || strlen($url) > self::MAX_URL || !FieldSchema::isSafeUrl($url)) {
                throw new \InvalidArgumentException('The link "' . $label . '" needs a web address, a path such as /about, or mailto:/tel:.');
            }
            $clean[] = ['label' => $label, 'url' => $url, 'new_tab' => !empty($row['new_tab'])];
        }
        if (count($clean) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException('A menu holds up to ' . self::MAX_ITEMS . ' links.');
        }
        return ['key' => $key, 'name' => $name, 'items' => $clean];
    }

    /** The stored JSON read back safely: anything that no longer validates is dropped, never shown. */
    public static function decode(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);
        $out = [];
        foreach (is_array($data) ? ($data['menus'] ?? []) : [] as $row) {
            if (!is_array($row) || count($out) >= self::MAX_MENUS) {
                continue;
            }
            try {
                $menu = self::validate((string) ($row['key'] ?? ''), (string) ($row['name'] ?? ''), is_array($row['items'] ?? null) ? $row['items'] : []);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $out[$menu['key']] = $menu;
        }
        return $out;
    }

    /** Test seam and cache reset after a write. */
    public static function forget(): void
    {
        self::$raw = [];
    }

    /** Test seam: pretend this tenant's stored setting is `$menus` (no database). */
    public static function useForTests(int $tenantId, array $menus): void
    {
        self::$raw[$tenantId] = $menus === [] ? '' : json_encode(['menus' => array_values($menus)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ;
    }

    private static function raw(int $tenantId): string
    {
        if ($tenantId <= 0) {
            return '';
        }
        if (!array_key_exists($tenantId, self::$raw)) {
            try {
                $value = class_exists('\Database') ? \Database::setting(self::SETTING_KEY, $tenantId) : null;
            } catch (\Throwable) {
                $value = null; // no database (unit harness) or it is down: no menus, never an error on a public page
            }
            self::$raw[$tenantId] = is_string($value) ? $value : '';
        }
        return self::$raw[$tenantId];
    }

    /** @param array<string, array{key: string, name: string, items: list<array<string, mixed>>}> $all */
    private static function write(int $tenantId, array $all): void
    {
        $json = $all === [] ? '' : json_encode(['menus' => array_values($all)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        \Database::setSetting(self::SETTING_KEY, $json, $tenantId);
        self::$raw[$tenantId] = $json;
    }
}
