<?php
/**
 * Slate — the admin bar shown over a site's public pages.
 *
 * Never part of a public page: pages are cached and anonymous, so the bar is
 * fetched afterwards from admin/front-bar.php (no-store, real session) by
 * assets/js/front-admin-bar.js. Everything here therefore runs only for a
 * signed-in admin, and every item is checked against that admin's permissions
 * here on the server.
 *
 * Plugins add their items through the `front_admin_bar_items` filter, which
 * only runs for booted (active) plugins:
 *
 *   ['id' => 'forms', 'label' => 'Forms', 'href' => '...', 'perm' => 'forms.view',
 *    'order' => 40, 'menu' => 'bar', 'count' => 3]
 *
 * `menu` is `bar` (a link on the bar) or `new` (an entry under New); `primary`
 * marks the page's edit link.
 */

declare(strict_types=1);

namespace Slate\Services\Admin;

use Slate\Services\Auth\AdminHint;
use Slate\Services\Content\PlatformIdentity;
use Slate\Services\Content\PlatformIdentityPolicy;
use Slate\Services\Content\TenantBranding;

final class FrontAdminBar
{
    public const SCRIPT_PATH = 'assets/js/front-admin-bar.js';

    /**
     * The path of a page on this site from the browser's `location.pathname`: `/fightxlife/about` on a site under
     * `/fightxlife` is `about`.
     */
    public static function localPath(string $pathname, string $siteUrl): string
    {
        $base = AdminHint::sitePath($siteUrl);
        $path = '/' . ltrim(rawurldecode(substr($pathname, 0, 512)), '/');
        if ($base !== '/' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base));
        }
        return trim($path, '/');
    }

    /**
     * What this admin may see, in order. An item naming no permission stays; anything malformed goes.
     *
     * @param array<int, mixed> $items
     * @param callable(string): bool $can
     * @return list<array<string, mixed>>
     */
    public static function visibleItems(array $items, callable $can): array
    {
        $visible = [];
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['label']) || empty($item['href'])) {
                continue;
            }
            $perm = (string) ($item['perm'] ?? '');
            if ($perm !== '' && !$can($perm)) {
                continue;
            }
            $visible[] = $item;
        }
        usort($visible, static fn (array $a, array $b): int => (int) ($a['order'] ?? 100) <=> (int) ($b['order'] ?? 100));
        return $visible;
    }

    /** The bar for the signed-in admin on the page at `$path`, or '' when there is none. */
    public static function forCurrentUser(string $path): string
    {
        $user = \Auth::user();
        if ($user === null) {
            return '';
        }
        $url = rtrim((string) SLATE_URL, '/');
        $items = \Hook::applyFilters('front_admin_bar_items', self::coreItems($url), $path);
        return self::render([
            'items'     => self::visibleItems(is_array($items) ? $items : [], static fn (string $perm): bool => \Auth::can($perm)),
            'wordmark'  => PlatformIdentityPolicy::whiteLabelActive() ? '' : PlatformIdentity::wordmarkDarkUrl(),
            'dashboard' => $url . '/admin/',
            'home'      => $url . '/',
            'site'      => TenantBranding::siteName(),
            'user'      => (string) ($user['name'] ?? '') ?: (string) ($user['email'] ?? ''),
            'profile'   => \Auth::can('users.edit') ? $url . '/admin/users.php?edit=' . (int) $user['id'] : '',
            'logout'    => $url . '/admin/logout.php?csrf=' . rawurlencode(csrf_token()),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function coreItems(string $url): array
    {
        return [
            ['id' => 'media', 'label' => __('media', 'Media'), 'href' => $url . '/admin/media.php', 'perm' => 'media.view', 'order' => 30, 'menu' => 'bar'],
            ['id' => 'settings', 'label' => __('settings', 'Settings'), 'href' => $url . '/admin/settings.php?tab=general', 'perm' => 'settings.view', 'order' => 90, 'menu' => 'bar'],
            ['id' => 'new-media', 'label' => __('admin_bar_new_media', 'Media file'), 'href' => $url . '/admin/media.php', 'perm' => 'media.upload', 'order' => 20, 'menu' => 'new'],
            ['id' => 'new-user', 'label' => __('admin_bar_new_user', 'User'), 'href' => $url . '/admin/users.php?new=1', 'perm' => 'users.edit', 'order' => 90, 'menu' => 'new'],
        ];
    }

    /**
     * Pure: every value it prints is escaped.
     *
     * @param array{items: list<array<string, mixed>>, wordmark: string, dashboard: string, home: string, site: string, user: string, profile: string, logout: string} $view
     */
    public static function render(array $view): string
    {
        $esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $dashboard = __('dashboard', 'Dashboard');
        $primary = '';
        $links = '';
        $new = '';
        foreach ($view['items'] as $item) {
            $text = '<span>' . $esc((string) $item['label']) . '</span>';
            $count = (int) ($item['count'] ?? 0);
            if ($count > 0) {
                $text .= '<span class="count" aria-hidden="true">' . ($count > 99 ? '99+' : $count) . '</span>'
                    . '<span class="sr">' . $esc(sprintf(__('admin_bar_count', '%d new'), $count)) . '</span>';
            }
            $link = '<li><a href="' . $esc((string) $item['href']) . '"' . (!empty($item['primary']) ? ' class="primary"' : '') . '>' . $text . '</a></li>';
            if (!empty($item['primary'])) {
                $primary .= $link;
            } elseif (($item['menu'] ?? 'bar') === 'new') {
                $new .= $link;
            } else {
                $links .= $link;
            }
        }
        $newMenu = $new === '' ? '' : '<li class="menu">' . self::menuButton('kb-new', '<span>' . $esc(__('admin_bar_new', 'New')) . '</span>')
            . '<ul id="kb-new" hidden>' . $new . '</ul></li>';

        $name = $view['user'];
        $initial = mb_strtoupper(mb_substr($name, 0, 1));
        $userItems = ($view['profile'] !== '' ? '<li><a href="' . $esc($view['profile']) . '">' . $esc(__('admin_bar_profile', 'Profile')) . '</a></li>' : '')
            . '<li><a href="' . $esc($view['logout']) . '">' . $esc(__('logout', 'Log out')) . '</a></li>';

        $brand = $view['wordmark'] !== ''
            ? '<img src="' . $esc($view['wordmark']) . '" alt="" width="84" height="20"><span class="sr">' . $esc(PlatformIdentity::name() . ' — ' . $dashboard) . '</span>'
            : '<span>' . $esc($dashboard) . '</span>';

        return '<nav class="bar" aria-label="' . $esc(__('admin_bar_label', 'Admin bar')) . '">'
            . '<a class="brand" href="' . $esc($view['dashboard']) . '" title="' . $esc($dashboard) . '">' . $brand . '</a>'
            . '<a class="site" href="' . $esc($view['home']) . '">' . $esc($view['site']) . '</a>'
            . '<ul class="always">' . $primary . '</ul>'
            . '<button type="button" class="toggle" data-kb-toggle aria-expanded="false" aria-controls="kb-links">'
            . '<span class="sr">' . $esc(__('admin_bar_menu', 'Menu')) . '</span>'
            . '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>'
            . '<ul id="kb-links" class="links">' . $newMenu . $links . '</ul>'
            . '<div class="user menu">' . self::menuButton('kb-user', '<span class="avatar" aria-hidden="true">' . $esc($initial) . '</span><span class="name">' . $esc($name) . '</span>')
            . '<ul id="kb-user" hidden>' . $userItems . '</ul></div>'
            . '</nav>';
    }

    private static function menuButton(string $id, string $content): string
    {
        return '<button type="button" data-kb-menu aria-expanded="false" aria-controls="' . $id . '">' . $content
            . '<svg class="caret" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg></button>';
    }

    /**
     * The bar's script for a public page that is not a Studio page (those load it from studio-runtime.js). Such pages
     * are never stored by a cache (no-store), so reading the cookie here is safe, and a visitor gets nothing at all.
     */
    public static function scriptTag(): string
    {
        if (!AdminHint::isPresent() || (string) ($_GET['embed'] ?? '') === '1') {
            return '';
        }
        $file = SLATE_ROOT . '/' . self::SCRIPT_PATH;
        if (!is_file($file)) {
            return '';
        }
        $src = rtrim((string) SLATE_URL, '/') . '/' . self::SCRIPT_PATH . '?v=' . filemtime($file);
        return '<script src="' . htmlspecialchars($src, ENT_QUOTES) . '" defer></script>';
    }
}
