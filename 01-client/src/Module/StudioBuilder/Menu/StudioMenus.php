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
 *
 * Renaming: a menu remembers the keys it had before (`aliases`), and `find()` resolves an old key to it, so every Nav
 * menu block saved with the old key keeps showing the menu. A key a live menu now uses always wins over an alias.
 *
 * Writes: every write re-reads the stored set under a per-tenant named lock and applies just its own change to it, so
 * two admins saving different menus at the same time both keep their change. A caller that showed a menu to an editor
 * can pass that menu's `version()` back to `save()` (or `delete()`), which refuses when someone else changed the menu
 * in between instead of silently overwriting it.
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
    /** Earlier keys a renamed menu still answers to. */
    public const MAX_ALIASES     = 10;
    /** Seconds a write waits for another write of the same tenant's menus. */
    private const LOCK_WAIT      = 5;

    /** Suggested starting menus, offered by the admin screen as one-click names. */
    public const SUGGESTED = ['header' => 'Header menu', 'footer' => 'Footer menu', 'services' => 'Services menu'];

    /** @var array<int, string> per-request memo of the raw setting, by tenant */
    private static array $raw = [];

    /**
     * Every menu of a tenant, keyed by menu key. A renamed menu also carries `aliases`, the keys it had before.
     *
     * @return array<string, array{key: string, name: string, items: list<array{label: string, url: string, new_tab: bool}>}>
     */
    public static function all(int $tenantId): array
    {
        return self::decode(self::raw($tenantId));
    }

    /**
     * A menu by its key, or by a key it had before it was renamed (blocks saved with the old key keep working).
     *
     * @return array{key: string, name: string, items: list<array{label: string, url: string, new_tab: bool}>}|null
     */
    public static function find(int $tenantId, string $key): ?array
    {
        return self::lookup(self::all($tenantId), $key);
    }

    /** A short version of one menu as stored: pass it back to `save()` / `delete()` to refuse a write over someone else's change. */
    public static function version(array $menu): string
    {
        return substr(hash('sha256', (string) json_encode($menu, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 16);
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
    public static function save(int $tenantId, string $key, string $name, array $items, ?string $replacesKey = null, ?string $expectedVersion = null): array
    {
        $menu = self::validate($key, $name, $items);
        return self::mutate($tenantId, static function (array $all) use ($menu, $replacesKey, $expectedVersion): array {
            $key = $menu['key'];
            $old = $replacesKey !== null ? self::lookup($all, $replacesKey) : null;
            self::assertVersion($old, $expectedVersion);
            if ($old !== null && $old['key'] !== $key) {
                if (isset($all[$key])) {
                    throw new \InvalidArgumentException('A menu with this key already exists.');
                }
                // Renamed: blocks that name the old key keep finding this menu.
                $aliases = array_merge($old['aliases'] ?? [], [$old['key']]);
                $aliases = array_values(array_unique(array_filter($aliases, static fn(string $a): bool => $a !== $key)));
                $menu['aliases'] = array_slice($aliases, -self::MAX_ALIASES);
                unset($all[$old['key']]);
            } elseif ($old !== null) {
                if (($old['aliases'] ?? []) !== []) {
                    $menu['aliases'] = $old['aliases'];
                }
            } elseif (isset($all[$key])) {
                throw new \InvalidArgumentException('A menu with this key already exists.');
            }
            $all[$key] = $menu;
            if (count($all) > self::MAX_MENUS) {
                throw new \InvalidArgumentException('You can keep up to ' . self::MAX_MENUS . ' menus.');
            }
            // A key now in use is no longer an alias of another menu.
            foreach ($all as $k => $other) {
                if ($k !== $key && in_array($key, $other['aliases'] ?? [], true)) {
                    $all[$k]['aliases'] = array_values(array_diff($other['aliases'], [$key]));
                    if ($all[$k]['aliases'] === []) {
                        unset($all[$k]['aliases']);
                    }
                }
            }
            return [$all, $menu];
        });
    }

    public static function delete(int $tenantId, string $key, ?string $expectedVersion = null): bool
    {
        return self::mutate($tenantId, static function (array $all) use ($key, $expectedVersion): array {
            $menu = self::lookup($all, $key);
            if ($menu === null) {
                return [null, false];
            }
            self::assertVersion($menu, $expectedVersion);
            unset($all[$menu['key']]);
            return [$all, true];
        });
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

    /** Well-formed earlier keys of a stored menu (none of them its own key). @return list<string> */
    private static function cleanAliases(mixed $aliases, string $key): array
    {
        $out = [];
        foreach (is_array($aliases) ? $aliases : [] as $alias) {
            if (is_string($alias) && $alias !== $key && preg_match(self::KEY_PATTERN, $alias) === 1 && !in_array($alias, $out, true)) {
                $out[] = $alias;
            }
        }
        return array_slice($out, -self::MAX_ALIASES);
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
            $aliases = self::cleanAliases($row['aliases'] ?? null, $menu['key']);
            if ($aliases !== []) {
                $menu['aliases'] = $aliases;
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

    /**
     * A menu by key, else the menu that had that key before a rename. A live key always wins over an alias.
     *
     * @param array<string, array<string, mixed>> $all
     * @return array<string, mixed>|null
     */
    private static function lookup(array $all, string $key): ?array
    {
        if ($key === '') {
            return null;
        }
        if (isset($all[$key])) {
            return $all[$key];
        }
        foreach ($all as $menu) {
            if (in_array($key, $menu['aliases'] ?? [], true)) {
                return $menu;
            }
        }
        return null;
    }

    /** @param array<string, mixed>|null $menu */
    private static function assertVersion(?array $menu, ?string $expectedVersion): void
    {
        if ($expectedVersion === null || $expectedVersion === '') {
            return;
        }
        if ($menu === null || !hash_equals(self::version($menu), $expectedVersion)) {
            throw new \InvalidArgumentException('Someone else changed this menu while you were editing it. Reload it and make your change again.');
        }
    }

    /**
     * Read-modify-write of the stored set under a per-tenant named lock: `$change` gets the set as stored NOW (not the
     * per-request memo, which may be stale) and returns [new set or null for no write, result].
     *
     * @param callable(array<string, array<string, mixed>>): array{0: array<string, array<string, mixed>>|null, 1: mixed} $change
     */
    private static function mutate(int $tenantId, callable $change): mixed
    {
        $lock = 'studio_menus_' . $tenantId;
        if ((int) \Database::value('SELECT GET_LOCK(?, ?)', [$lock, self::LOCK_WAIT]) !== 1) {
            throw new \InvalidArgumentException('The menus are being saved by someone else. Try again in a moment.');
        }
        try {
            unset(self::$raw[$tenantId]);
            [$all, $result] = $change(self::all($tenantId));
            if ($all !== null) {
                self::write($tenantId, $all);
            }
            return $result;
        } finally {
            \Database::value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** @param array<string, array{key: string, name: string, items: list<array<string, mixed>>}> $all */
    private static function write(int $tenantId, array $all): void
    {
        $json = $all === [] ? '' : json_encode(['menus' => array_values($all)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        \Database::setSetting(self::SETTING_KEY, $json, $tenantId);
        self::$raw[$tenantId] = $json;
    }
}
