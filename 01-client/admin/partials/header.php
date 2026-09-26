<?php
/**
 * Slate — admin shell header.
 *
 * Renders the layout:
 *   Desktop (≥768px):  dark sidebar on left, light topbar across,
 *                       light off-white canvas under main content
 *   Mobile  (<768px):  topbar across, main content full-width,
 *                       bottom tab bar pinned to bottom of viewport
 *
 * Pages call this with $pageTitle set, then render content, then
 * include footer.php.
 *
 *   require_once dirname(__DIR__).'/../config.php';
 *   Auth::require();
 *   $pageTitle = 'My Page';
 *   require __DIR__ . '/partials/header.php';
 *   // ... page content ...
 *   require __DIR__ . '/partials/footer.php';
 *
 * NAV ITEMS are dynamic. Core declares its items here; plugins extend
 * via the `admin_nav_items` filter. Items are auto-filtered by the
 * current user's permissions and sorted by 'order'. Items optionally
 * carry a 'group' key — items with the same group render under an
 * uppercase section label. Core groups: overview, content, system.
 *
 * MOBILE TAB BAR shows the first 4 items + a "More" button that opens
 * a sheet listing the rest. Desktop sidebar shows all items grouped.
 *
 * TOPBAR slots (filters):
 *   admin_topbar_search   → string of HTML for the centre-right slot
 *                            (e.g. a search input). Empty by default.
 *   admin_topbar_actions  → array of HTML strings rendered as buttons
 *                            in the top-right. The first one is the
 *                            page's primary CTA.
 */

if (!defined('SLATE_ROOT')) exit;
Auth::require();

// Admin pages must never be cached — stale renders after a deploy are
// a real problem (see the Phase 0 hotfix story). Headers must be sent
// BEFORE any output, so this is the right place. Output buffer also
// isn't started yet here.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

require_once SLATE_ROOT . '/includes/breadcrumbs.php';

$user      = Auth::user();
$pageTitle = $pageTitle ?? 'Admin';

// Gravatar for the logged-in user (shown over the initial; d=404 → onerror
// removes the <img> and the gradient initial shows through).
$userEmail     = strtolower(trim((string)($user['email'] ?? '')));
$userAvatarUrl = ($userEmail !== '' && str_contains($userEmail, '@'))
    ? 'https://www.gravatar.com/avatar/' . md5($userEmail) . '?s=64&d=404'
    : '';

// ── Core nav items ───────────────────────────────────────────
// Each item: ['slug', 'label', 'href', 'icon', 'perm', 'order', 'group']
$coreNav = [
    [
        'slug'  => 'dashboard',
        'label' => __('dashboard', 'Dashboard'),
        'href'  => SLATE_URL . '/admin/index.php',
        'icon'  => 'home',
        'perm'  => null,
        'order' => 10,
        'group' => 'overview',
    ],
    // ── Editor ──
    [
        'slug'  => 'editor',
        'label' => __('visual_page_editor', 'Visual Page Editor'),
        'href'  => SLATE_URL . '/admin/editor.php',
        'icon'  => 'layout',
        'perm'  => 'content.edit',
        'order' => 50,
        'group' => 'editor',
    ],
    // ── Content ──
    [
        'slug'  => 'content-page',
        'label' => __('pages', 'Pages'),
        'href'  => SLATE_URL . '/admin/posts.php?type=page',
        'icon'  => 'file-text',
        'perm'  => 'content.view',
        'order' => 100,
        'group' => 'content',
    ],
    [
        'slug'  => 'content-post',
        'label' => __('posts', 'Posts'),
        'href'  => SLATE_URL . '/admin/posts.php?type=post',
        'icon'  => 'edit-3',
        'perm'  => 'content.view',
        'order' => 110,
        'group' => 'content',
    ],
    [
        'slug'  => 'content-templates',
        'label' => __('templates', 'Templates'),
        'href'  => SLATE_URL . '/admin/templates.php',
        'icon'  => 'copy',
        'perm'  => 'content.view',
        'order' => 120,
        'group' => 'content',
    ],
    [
        'slug'  => 'content-menus',
        'label' => __('navigation', 'Navigation'),
        'href'  => SLATE_URL . '/admin/menus.php',
        'icon'  => 'list',
        'perm'  => 'content.edit',
        'order' => 130,
        'group' => 'content',
    ],
    [
        'slug'       => 'media',
        'label'      => __('media_library', 'Media Library'),
        'href'       => SLATE_URL . '/admin/media.php',
        'icon'       => 'image',
        'perm'       => 'media.view',
        'order'      => 150,
        'group'      => 'settings',
        'mobile_tab' => false,
    ],
    [
        // No dedicated perm — admin/notifications.php itself only requires
        // Auth::require(), same as the topbar bell that already links here.
        'slug'       => 'notifications',
        'label'      => __('notifications', 'Notifications'),
        'href'       => SLATE_URL . '/admin/notifications.php',
        'icon'       => 'bell',
        'perm'       => null,
        'order'      => 160,
        'group'      => 'overview',
        'mobile_tab' => true,
    ],
    // ── Settings ──
    [
        'slug'       => 'settings-general',
        'label'      => __('general', 'General'),
        'href'       => SLATE_URL . '/admin/settings.php?tab=general',
        'icon'       => 'settings',
        'perm'       => 'settings.view',
        'order'      => 800,
        'group'      => 'settings',
        'mobile_tab' => true,
    ],
    [
        'slug'  => 'settings-permalinks',
        'label' => __('permalinks', 'Permalinks'),
        'href'  => SLATE_URL . '/admin/settings.php?tab=permalinks',
        'icon'  => 'link',
        'perm'  => 'settings.view',
        'order' => 810,
        'group' => 'settings',
    ],
    [
        'slug'  => 'settings-datetime',
        'label' => __('date_and_time', 'Date & Time'),
        'href'  => SLATE_URL . '/admin/settings.php?tab=datetime',
        'icon'  => 'clock',
        'perm'  => 'settings.view',
        'order' => 820,
        'group' => 'settings',
    ],
    [
        'slug'  => 'settings-branding',
        'label' => __('branding', 'Branding'),
        'href'  => SLATE_URL . '/admin/settings.php?tab=branding',
        'icon'  => 'palette',
        'perm'  => 'settings.view',
        'order' => 830,
        'group' => 'settings',
    ],
    [
        'slug'  => 'settings-media',
        'label' => __('media', 'Media'),
        'href'  => SLATE_URL . '/admin/settings.php?tab=media',
        'icon'  => 'film',
        'perm'  => 'settings.view',
        'order' => 840,
        'group' => 'settings',
    ],
    // Phase 8 — this installation's own license (status, plan, modules).
    // Not the platform-only 'licenses' management page below.
    [
        'slug'  => 'license',
        'label' => __('license', 'License'),
        'href'  => SLATE_URL . '/admin/license.php',
        'icon'  => 'shield',
        'perm'  => 'settings.view',
        'order' => 850,
        'group' => 'settings',
    ],
    // ── System ──
    [
        'slug'  => 'users',
        'label' => __('users', 'Users'),
        'href'  => SLATE_URL . '/admin/users.php',
        'icon'  => 'users',
        'perm'  => 'users.view',
        'order' => 900,
        'group' => 'system',
    ],
    [
        'slug'  => 'sessions',
        'label' => __('sessions_devices', 'Sessions & devices'),
        'href'  => SLATE_URL . '/admin/sessions.php',
        'icon'  => 'monitor',
        'perm'  => 'users.view',
        'order' => 905,
        'group' => 'system',
    ],
    [
        'slug'  => 'roles',
        'label' => __('roles', 'Roles'),
        'href'  => SLATE_URL . '/admin/roles.php',
        'icon'  => 'shield',
        'perm'  => null, // super-admin via short-circuit
        'order' => 910,
        'group' => 'system',
    ],
    [
        'slug'  => 'plugins',
        'label' => __('plugins', 'Plugins'),
        'href'  => SLATE_URL . '/admin/plugins.php',
        'icon'  => 'box',
        'perm'  => 'plugins.manage',
        'order' => 920,
        'group' => 'system',
    ],
    [
        'slug'  => 'audit',
        'label' => __('audit_log', 'Audit log'),
        'href'  => SLATE_URL . '/admin/audit.php',
        'icon'  => 'clipboard-list',
        'perm'  => 'audit.view',
        'order' => 930,
        'group' => 'system',
    ],
    [
        'slug'  => 'help',
        'label' => __('help_support', 'Help & Support'),
        'href'  => SLATE_URL . '/admin/help.php',
        'icon'  => 'life-buoy',
        'perm'  => null, // available to every signed-in admin
        'order' => 950,
        'group' => 'system',
    ],
];

// Platform-level nav — gated directly on Auth::isPlatformSuperAdmin() rather
// than a 'perm' key, since platform authority is not a per-tenant permission
// (platform_admins carries no tenant_id — see migration 0013). Ordinary
// tenant admins, including tenant Super Admins (role_id=1) who are NOT also
// platform_admins members, never see this group at all.
if (Auth::isPlatformSuperAdmin()) {
    $coreNav[] = [
        'slug'  => 'tenants',
        'label' => __('tenants', 'Tenants'),
        'href'  => SLATE_URL . '/admin/tenants.php',
        'icon'  => 'building-2',
        'perm'  => null,
        'order' => 10,
        'group' => 'platform',
    ];
    $coreNav[] = [
        'slug'  => 'licenses',
        'label' => __('licenses', 'Licenses'),
        'href'  => SLATE_URL . '/admin/licenses.php',
        'icon'  => 'key',
        'perm'  => null,
        'order' => 20,
        'group' => 'platform',
    ];
    $coreNav[] = [
        'slug'  => 'plans',
        'label' => __('plans', 'Plans'),
        'href'  => SLATE_URL . '/admin/plans.php',
        'icon'  => 'tag',
        'perm'  => null,
        'order' => 30,
        'group' => 'platform',
    ];
    $coreNav[] = [
        'slug'  => 'platform-admins',
        'label' => __('platform_admins', 'Platform Administrators'),
        'href'  => SLATE_URL . '/admin/platform-admins.php',
        'icon'  => 'shield',
        'perm'  => null,
        'order' => 50,
        'group' => 'platform',
    ];
}

// 'editor', 'content-page', 'content-post' and 'content-templates' are
// served by the Phase 2 DocumentSchema pipeline (admin/editor.php,
// admin/posts.php, admin/templates.php) — no plugin required. They're hidden
// only if that pipeline itself has been switched off (see admin/editor.php's
// own editor_phase2_enabled check).
//
// 'content-menus' (Navigation) is different: admin/menus.php is still a
// straight bridge into plugins/content-builder/admin/menus.php, which does
// not exist — that plugin was archived, not migrated. Keep it gated on the
// plugin actually being active; it reappears automatically if a real
// Content Builder plugin is ever installed again.
$hiddenNavSlugs = [];
if (Database::setting('editor_phase2_enabled') === '0') {
    $hiddenNavSlugs = array_merge($hiddenNavSlugs, ['editor', 'content-page', 'content-post', 'content-templates']);
}
if (!PluginLoader::isActive('content-builder')) {
    $hiddenNavSlugs[] = 'content-menus';
}
if ($hiddenNavSlugs !== []) {
    $coreNav = array_values(array_filter($coreNav, fn(array $item): bool => !in_array($item['slug'], $hiddenNavSlugs, true)));
}

// Plugin nav contributions
$navItems = Hook::applyFilters('admin_nav_items', $coreNav);

// Filter by permissions
$navItems = array_values(array_filter($navItems, function ($item) {
    if (!is_array($item) || empty($item['slug'])) return false;
    $perm = $item['perm'] ?? null;
    if ($perm !== null && $perm !== '' && !Auth::can($perm)) return false;
    return true;
}));

// Sort by order (stable across PHP 8+)
usort($navItems, fn($a, $b) => ($a['order'] ?? 500) <=> ($b['order'] ?? 500));

// ── Active nav slug ──────────────────────────────────────────
$currentNav = $currentNav ?? null;
if (!$currentNav) {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $map = [
        'index.php'         => 'dashboard',
        'editor.php'        => 'editor',
        'templates.php'     => 'content-templates',
        'menus.php'         => 'content-menus',
        'media.php'         => 'media',
        'plugins.php'       => 'plugins',
        'users.php'         => 'users',
        'sessions.php'      => 'sessions',
        'roles.php'         => 'roles',
        'contact_forms.php' => 'contact-forms',
        'settings.php'      => 'settings-general',
        'audit.php'         => 'audit',
        'help.php'          => 'help',
        'license.php'       => 'license',
    ];
    $currentNav = $map[$script] ?? null;
    if ($script === 'posts.php') {
        $pt = $_GET['type'] ?? 'post';
        $currentNav = 'content-' . $pt;
    }
    if ($script === 'settings.php') {
        $tab = $_GET['tab'] ?? 'general';
        if ($tab === 'profile') $tab = 'general';
        $currentNav = 'settings-' . $tab;
    }
}

// ── Site identity ────────────────────────────────────────────
$siteName     = Database::setting('site_name')     ?: 'Kohevo';
$brandSublabel = Database::setting('brand_sublabel') ?: __('pro_admin', 'Pro Admin');
$brandLogoUrls = slate_logo_urls();
$brandLogoUrl  = $brandLogoUrls['light'];
$brandLogoDarkUrl = $brandLogoUrls['dark'];
// On dark sidebar themes the logo sits on a white tile so dark logo artwork
// stays legible; on the Light theme the logo shows clean (no tile).
$sidebarThemeKey = (string)(Database::setting('sidebar_theme') ?: 'ink');
$sidebarThemes   = function_exists('slate_sidebar_themes') ? slate_sidebar_themes() : [];
$sidebarLogoTile = (bool)($sidebarThemes[$sidebarThemeKey]['dark'] ?? true);

// ── Group nav items by 'group' field (preserves first-seen order) ─
// Note: use $_navGroupKey (not $g) so we never clobber a caller's $g variable.
$navGroups = [];
foreach ($navItems as $item) {
    $_navGroupKey = $item['group'] ?? '';
    if ($_navGroupKey === '' || !is_string($_navGroupKey)) $_navGroupKey = 'plugins';
    $_navGroupKey = strtolower(preg_replace('/[^a-z0-9_-]+/i', '-', $_navGroupKey)) ?: 'plugins';
    if (!isset($navGroups[$_navGroupKey])) $navGroups[$_navGroupKey] = [];
    $navGroups[$_navGroupKey][] = $item;
}
unset($_navGroupKey, $item);

// Canonical group ordering: Overview -> Editor -> Content -> Business plugins -> Settings -> Tools/System -> other
static $groupPriorities = [
    'platform'      => 5,
    'overview'      => 10,
    'editor'        => 20,
    'content'       => 25,
    'shop'          => 40,
    'booking'       => 45,
    'nutrition-app' => 50,
    'form-builder'  => 55,
    'plugins'       => 60,
    'mcp-ai-hub'    => 65,
    'licensing'     => 66,
    'settings'      => 70,
    'system'        => 80,
];
uksort($navGroups, function ($a, $b) use ($groupPriorities) {
    $pA = $groupPriorities[$a] ?? 60;
    $pB = $groupPriorities[$b] ?? 60;
    if ($pA !== $pB) {
        return $pA <=> $pB;
    }
    return strcmp((string)$a, (string)$b);
});

// Friendly display label for a group key.
if (!function_exists('slate_admin_group_label')) {
    function slate_admin_group_label(string $key): string {
        // Sidebar-specific overrides win first — several of these group
        // KEYS (e.g. 'settings') double as generic i18n keys used elsewhere
        // (page titles, breadcrumbs) with their own stored translation, so
        // checking __($key) first would pull in that unrelated string
        // instead of the label this sidebar section actually wants.
        static $defaults = [
            'platform'      => ['Platform',            'Plateforme'],
            'overview'      => ['Overview',             'Aperçu'],
            'editor'        => ['Editor',                'Éditeur'],
            'content'       => ['Content',               'Contenu'],
            'booking'       => ['Booking engine',        'Moteur de réservation'],
            'settings'      => ['Site settings',         'Réglages du site'],
            'system'        => ['Tools & connection',    'Outils et connexions'],
            'plugins'       => ['Plugins',                'Extensions'],
            'shop'          => ['Shop',                   'Boutique'],
            'media'         => ['Media',                  'Médias'],
            'mcp-ai-hub'    => ['MCP & AI Hub',          'MCP et hub IA'],
            'nutrition-app' => ['Coaching',               'Coaching'],
            'form-builder'  => ['Forms',                  'Formulaires'],
            'licensing'     => ['Licensing',              'Licences'],
        ];
        if (isset($defaults[$key])) {
            $locale = class_exists('I18n') ? I18n::currentLocale() : 'en';
            return strtoupper($defaults[$key][$locale === 'fr' ? 1 : 0]);
        }

        // Otherwise let a plugin translate its own group key.
        $translated = __($key, '');
        if ($translated !== '' && $translated !== $key) {
            return strtoupper($translated);
        }
        return strtoupper(str_replace(['-', '_'], ' ', $key));
    }
}

// ── Split nav for mobile tab bar vs the "More" sheet ─────────
// The bottom bar should surface day-to-day destinations, not occasional
// admin chores. So we fill it from the "overview" + "content" groups first
// (Dashboard, Forms, Submissions, Media, …) and let "system" items (Users,
// Roles, Plugins, Audit, Settings) fall through to "More". If there aren't
// four day-to-day items, we top up from whatever's left so the bar is full.
// Plugins can force an item onto/off the bar with 'mobile_tab' => true|false.
$tabGroups = ['overview', 'content'];
$tabForced = array_values(array_filter($navItems, fn($i) => !empty($i['mobile_tab'])));
$tabPrimary = array_values(array_filter($navItems, fn($i) =>
    empty($i['mobile_tab']) && in_array($i['group'] ?? '', $tabGroups, true) && ($i['mobile_tab'] ?? true) !== false));
$tabRest = array_values(array_filter($navItems, fn($i) =>
    empty($i['mobile_tab']) && !in_array($i['group'] ?? '', $tabGroups, true) && ($i['mobile_tab'] ?? true) !== false));

$tabBarItems = [];
$seenTab = [];
foreach (array_merge($tabForced, $tabPrimary, $tabRest) as $i) {
    $slug = $i['slug'] ?? '';
    if ($slug === '' || isset($seenTab[$slug])) continue;
    if (($i['mobile_tab'] ?? true) === false) continue;
    $seenTab[$slug] = true;
    $tabBarItems[] = $i;
    if (count($tabBarItems) >= 4) break;
}
// Everything not on the bar goes to "More".
$onBar = array_fill_keys(array_column($tabBarItems, 'slug'), true);
$moreNavItems = array_values(array_filter($navItems, fn($i) => empty($onBar[$i['slug'] ?? ''])));

// ── Topbar slots (plugin contributions) ──────────────────────
$topbarSearchHtml = (string)Hook::applyFilters('admin_topbar_search', '');
$topbarActions    = Hook::applyFilters('admin_topbar_actions', []);
if (!is_array($topbarActions)) $topbarActions = [];

// Command palette data — every nav destination becomes searchable. Used to
// fill the otherwise-empty topbar with a "jump to" search (no plugin search
// override present).
// Store icon *names* here; the SVG is resolved in the body, after the
// slate_admin_nav_icon() helper is defined further down this file.
$cmdItems = [];
foreach ($navItems as $i) {
    if (empty($i['href']) || empty($i['label'])) continue;
    $cmdItems[] = [
        'label' => (string)$i['label'],
        'href'  => (string)$i['href'],
        'icon'  => (string)($i['icon'] ?? 'circle'),
    ];
}

// ── Notifications (topbar bell) ──────────────────────────────
// The bell links to /admin/notifications.php (full page); we only need
// the unread count here for the badge.
$notifUnread = 0;
if (class_exists('Notifications')) {
    try {
        $notifUnread = Notifications::unreadCount();
    } catch (\Throwable $e) { /* feed unavailable — bell still renders */ }
}

if (!function_exists('slate_admin_time_ago')) {
    function slate_admin_time_ago(string $datetime): string {
        $ts = strtotime($datetime);
        if (!$ts) return '';
        // $datetime (e.g. notifications.created_at) is stamped by MySQL's
        // CURRENT_TIMESTAMP — the database's own SYSTEM clock — not PHP's.
        // The two are not guaranteed to agree (see slate_db_now()'s doc
        // comment: a documented multi-hour gap on this host already broke
        // the login throttle once). Comparing against PHP's time() mixed
        // the two clocks: when the DB clock reads ahead of PHP's, the diff
        // goes negative, which always fell into the "< 60s" bucket — every
        // notification read "just now" regardless of its real age. Use the
        // DB's own clock as the "now" reference instead (cached for the
        // life of this request — this helper can run once per row).
        static $dbNowTs = null;
        if ($dbNowTs === null) {
            // anti-drift-ignore: CLOCK — this IS the DB-clock path the rule
            // asks for: slate_db_now() is the value used. date('Y-m-d H:i:s')
            // is only the last-resort fallback for when that helper or
            // Database is unavailable (pre-install/bootstrap), where no DB
            // clock exists to read and PHP's is the only one there is.
            $raw = (function_exists('slate_db_now') && class_exists('Database'))
                ? slate_db_now() : date('Y-m-d H:i:s');
            // anti-drift-ignore: CLOCK — not a cross-clock comparison. $raw is
            // already the database's own now-string; strtotime() only parses it
            // into an int, and time() is the fallback if that parse fails.
            $dbNowTs = strtotime($raw) ?: time();
        }
        $diff = max(0, $dbNowTs - $ts);
        if ($diff < 60)      return 'just now';
        if ($diff < 3600)    return floor($diff / 60) . 'm ago';
        if ($diff < 86400)   return floor($diff / 3600) . 'h ago';
        if ($diff < 604800)  return floor($diff / 86400) . 'd ago';
        return I18n::localDate('j M', $ts);
    }
}

/**
 * Inline SVG icon helper — line icons, 24×24 viewBox, single stroke.
 * Sized via CSS (.nav-icon).
 *
 * Defined before HTML output so the sidebar's first call resolves.
 * Unknown names fall back to `circle`.
 */
if (!function_exists('slate_admin_nav_icon')) {
    function slate_admin_nav_icon(string $name): string {
        $icons = [
            'home'           => '<path d="M3 12L12 4l9 8M5 10v10h4v-6h6v6h4V10"/>',
            'users'          => '<path d="M17 11a4 4 0 1 0-8 0 4 4 0 0 0 8 0zM3 21v-2a5 5 0 0 1 5-5h10a5 5 0 0 1 5 5v2"/>',
            'shield'         => '<path d="M12 3l8 4v6c0 5-3.5 9-8 10-4.5-1-8-5-8-10V7l8-4z"/>',
            'box'            => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8zM3 8l9 5 9-5M12 13v9"/>',
            'mail'           => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
            'settings'       => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
            'star'           => '<path d="M12 2l3 7 7 .8-5.4 4.8L18 22l-6-3.5L6 22l1.4-7.4L2 9.8 9 9l3-7z"/>',
            'life-buoy'      => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.6"/><path d="M5.6 5.6l3.3 3.3M15.1 15.1l3.3 3.3M18.4 5.6l-3.3 3.3M8.9 15.1l-3.3 3.3"/>',
            'help-circle'    => '<circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 1 1 4.6 2.5c-.9.6-1.7 1.1-1.7 2.2"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
            'circle'         => '<circle cx="12" cy="12" r="9"/>',
            'logout'         => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
            'more'           => '<circle cx="12" cy="12" r="1.5"/><circle cx="5" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>',
            'search'         => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
            // Commerce / content
            'shopping-bag'   => '<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0"/>',
            'clipboard-list' => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h.01M9 16h.01M13 12h2M13 16h2"/>',
            'package'        => '<path d="M16.5 9.4L7.5 4.21M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16zM3.27 6.96L12 12l8.73-5.04M12 22V12"/>',
            'folder'         => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
            'tag'            => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1.5"/>',
            'bar-chart-2'    => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
            // Extended set added Phase 0 (was: featureless "circle" fallback)
            'truck'          => '<path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
            'image'          => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
            'edit-3'         => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/>',
            'send'           => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
            'bar-chart-3'    => '<path d="M3 3v18h18"/><path d="M7 16V9"/><path d="M11 16V5"/><path d="M15 16v-7"/><path d="M19 16v-4"/>',
            'calendar'       => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
            'credit-card'    => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
            // Extended icons for CMS, Editor, and Settings
            'layout'         => '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
            'file-text'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
            'palette'        => '<circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
            'copy'           => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
            'sliders'        => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
            'link'           => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'clock'          => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'film'           => '<rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"/><line x1="7" y1="2" x2="7" y2="22"/><line x1="17" y1="2" x2="17" y2="22"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="2" y1="7" x2="7" y2="7"/><line x1="2" y1="17" x2="7" y2="17"/><line x1="17" y1="17" x2="22" y2="17"/><line x1="17" y1="7" x2="22" y2="7"/>',
            // Booking sub-nav (were falling back to the featureless circle)
            'plus'           => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'list'           => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
            'map-pin'        => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
            // Asked for by plugin nav items that were silently falling back to
            // the generic circle: Studio wants chart (Reports), award
            // (Instructors) and sparkles (Recitals).
            'chart'          => '<path d="M4 20h16"/><path d="M7 20v-7"/><path d="M12 20V6"/><path d="M17 20v-10"/>',
            'award'          => '<circle cx="12" cy="9" r="5.2"/><path d="M8.6 13.4L7.4 21l4.6-2.4 4.6 2.4-1.2-7.6"/>',
            'sparkles'       => '<path d="M12 3.2l1.7 4.3 4.3 1.7-4.3 1.7L12 15.2l-1.7-4.3L6 9.2l4.3-1.7z"/><path d="M18.4 15.2l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/>',
            // Mood / food glyphs (Coaching admin: client, feed, chat, motivation)
            // emoji removal: replaces mood-face and meal-type emoji used in Coaching admin
            'smile'          => '<circle cx="12" cy="12" r="9.2"/><path d="M8.2 14.5c1 1.3 2.3 2 3.8 2s2.8-.7 3.8-2"/><circle cx="9" cy="9.5" r=".6" fill="currentColor" stroke="none"/><circle cx="15" cy="9.5" r=".6" fill="currentColor" stroke="none"/>',
            'frown'          => '<circle cx="12" cy="12" r="9.2"/><path d="M8.2 16c1-1.3 2.3-2 3.8-2s2.8.7 3.8 2"/><circle cx="9" cy="9.5" r=".6" fill="currentColor" stroke="none"/><circle cx="15" cy="9.5" r=".6" fill="currentColor" stroke="none"/>',
            'meh'            => '<circle cx="12" cy="12" r="9.2"/><path d="M8.2 15h7.6"/><circle cx="9" cy="9.5" r=".6" fill="currentColor" stroke="none"/><circle cx="15" cy="9.5" r=".6" fill="currentColor" stroke="none"/>',
            'angry'          => '<circle cx="12" cy="12" r="9.2"/><path d="M8.2 16c1-1.3 2.3-2 3.8-2s2.8.7 3.8 2"/><path d="M7.6 8.6l3 1.3"/><path d="M16.4 8.6l-3 1.3"/>',
            'worried'        => '<circle cx="12" cy="12" r="9.2"/><path d="M9 15.6a3.2 2 0 0 1 6 0"/><path d="M8 9l2-.9"/><path d="M16 9l-2-.9"/>',
            'moon'           => '<path d="M20.5 14.3A8.6 8.6 0 1 1 9.7 3.5a7 7 0 0 0 10.8 10.8z"/>',
            'egg'            => '<path d="M12 3.4C8.4 8 6.5 12.4 6.5 15.8a5.5 5.5 0 0 0 11 0C17.5 12.4 15.6 8 12 3.4z"/>',
            'salad'          => '<path d="M3.5 12h17a7.5 6 0 0 1-8.5 6 7.5 6 0 0 1-8.5-6z"/><path d="M12 12c0-3 1.6-5.4 4-6.4-2.6-1-5.4 0-6.4 2.6"/>',
            'plate'          => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4.6"/>',
            'cookie'         => '<circle cx="12" cy="12" r="9"/><circle cx="9" cy="10" r=".9" fill="currentColor" stroke="none"/><circle cx="14.2" cy="8.8" r=".9" fill="currentColor" stroke="none"/><circle cx="15" cy="14" r=".9" fill="currentColor" stroke="none"/><circle cx="10" cy="15.5" r=".9" fill="currentColor" stroke="none"/>',
            'stack'          => '<ellipse cx="12" cy="6" rx="7.5" ry="2.4"/><path d="M4.5 6v6c0 1.3 3.4 2.4 7.5 2.4s7.5-1.1 7.5-2.4V6"/><path d="M4.5 12v6c0 1.3 3.4 2.4 7.5 2.4s7.5-1.1 7.5-2.4v-6"/>',
            'briefcase'      => '<rect x="2.6" y="7.4" width="18.8" height="12.2" rx="2"/><path d="M8.4 7.4V5.6a2 2 0 0 1 2-2h3.2a2 2 0 0 1 2 2v1.8"/><path d="M2.6 12.4h18.8"/>',
            'bus'            => '<rect x="3.4" y="4.6" width="17.2" height="12" rx="2.2"/><path d="M3.4 13.4h17.2"/><circle cx="7.4" cy="19.4" r="1.6"/><circle cx="16.6" cy="19.4" r="1.6"/>',
            'book'           => '<path d="M4 5.2a2 2 0 0 1 2-2h12.5v16.6H6a2 2 0 0 0-2 2z"/><path d="M4 5.2v14.6"/><path d="M18.5 19.8H6a2 2 0 0 0-2 2"/>',
            'cart'           => '<circle cx="9.5" cy="20" r="1.3"/><circle cx="17.5" cy="20" r="1.3"/><path d="M2.6 3.6h2.5l2.4 12.3h11l2-8.3H6.4"/>',
            'dumbbell'       => '<path d="M4 10v4"/><path d="M2.6 8.8v6.4"/><path d="M20 10v4"/><path d="M21.4 8.8v6.4"/><path d="M8 12h8"/><rect x="5.5" y="9" width="3" height="6" rx="1"/><rect x="15.5" y="9" width="3" height="6" rx="1"/>',
            'camera'         => '<path d="M4 8.4a2 2 0 0 1 2-2h1.7l1.2-1.8h6.2l1.2 1.8H18a2 2 0 0 1 2 2v9.4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><circle cx="12" cy="13" r="3.6"/>',
            'trash'          => '<path d="M4.6 7.2h14.8"/><path d="M9 7.2V5.4a1.6 1.6 0 0 1 1.6-1.6h2.8A1.6 1.6 0 0 1 15 5.4v1.8"/><path d="M6.6 7.2l.9 12a2 2 0 0 0 2 1.9h5a2 2 0 0 0 2-1.9l.9-12"/>',
            'phone'          => '<path d="M6.6 3.6L9.4 8l-2 2.2a12.8 12.8 0 0 0 6.4 6.4l2.2-2 4.4 2.8v3a1.6 1.6 0 0 1-1.75 1.6A17.6 17.6 0 0 1 3 5.35 1.6 1.6 0 0 1 4.6 3.6z"/>',
            // Editor device-preview toggle (was monitor/phone emoji)
            'monitor'        => '<rect x="2.6" y="4" width="18.8" height="12.6" rx="1.8"/><path d="M8.6 20.8h6.8"/><path d="M12 16.6v4.2"/>',
            'tablet'         => '<rect x="5.6" y="2.6" width="12.8" height="18.8" rx="2.2"/><path d="M11.4 18.6h1.2"/>',
            'smartphone'     => '<rect x="7.2" y="2.6" width="9.6" height="18.8" rx="2.2"/><path d="M11.4 18.2h1.2"/>',
            // Misc admin actions previously rendered with emoji (up/down arrows, refresh, memo, picture)
            'upload'         => '<path d="M12 20V6"/><path d="M6.4 11.6L12 6l5.6 5.6"/><path d="M4.4 20h15.2"/>',
            'download'       => '<path d="M12 4v14"/><path d="M6.4 12.4L12 18l5.6-5.6"/><path d="M4.4 20h15.2"/>',
            'repeat'         => '<path d="M19.6 8.6H8.4a3.8 3.8 0 0 0-3.8 3.8v1"/><path d="M16.2 5.2l3.4 3.4-3.4 3.4"/><path d="M4.4 15.4h11.2a3.8 3.8 0 0 0 3.8-3.8v-1"/><path d="M7.8 18.8l-3.4-3.4 3.4-3.4"/>',
            // Coaching admin: chat, hydration, food/drink glyphs
            'message'        => '<path d="M20.6 11.7a8 8 0 0 1-8.6 8 9 9 0 0 1-3.3-.72L3.6 20.4l1.44-4.9a8 8 0 0 1-.72-3.34 8 8 0 0 1 8-8.05 8 8 0 0 1 8.28 7.6z"/>',
            'droplet'        => '<path d="M12 3.1c2.9 2.9 5.7 5.5 5.7 9a5.7 5.7 0 0 1-11.4 0c0-3.5 2.8-6.1 5.7-9z"/>',
            'utensils'       => '<path d="M6.3 3v6.1a2.6 2.6 0 0 0 2.6 2.6v9.3"/><path d="M11.5 3v4.6"/><path d="M17.7 3c-1.5 1.4-2.3 3.4-2.3 5.6 0 1.7.7 2.7 2.3 3.1v9.3"/>',
            'coffee'         => '<path d="M4.6 8.4h12v6.2a4.6 4.6 0 0 1-4.6 4.6H9.2a4.6 4.6 0 0 1-4.6-4.6z"/><path d="M16.6 9.6h1.3a2.4 2.4 0 0 1 0 4.8h-1.3"/><path d="M7 3.2v2"/><path d="M10.6 3.2v2"/><path d="M14.2 3.2v2"/>',
            // Nav items that were silently falling back to the featureless
            // circle: Coaching (user), Client feed (bell), Translations
            // (globe), Booking/Booking+ Messages (message-square), plus a
            // few icon names referenced elsewhere in the codebase that were
            // never registered (clipboard, target, message-circle).
            'user'           => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'bell'           => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
            'globe'          => '<circle cx="12" cy="12" r="9"/><line x1="3" y1="12" x2="21" y2="12"/><path d="M12 3a14 14 0 0 1 3.5 9A14 14 0 0 1 12 21 14 14 0 0 1 8.5 12 14 14 0 0 1 12 3z"/>',
            'message-square' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
            'clipboard'      => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',
            'target'         => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5.2"/><circle cx="12" cy="12" r="1.6"/>',
            'message-circle' => '<path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5z"/>',
        ];
        $path = $icons[$name] ?? $icons['circle'];
        return '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
             . 'stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" '
             . 'aria-hidden="true">' . $path . '</svg>';
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e(I18n::currentLocale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0E1117">
    <!-- Apply the saved sidebar-collapsed state before first paint (no flash). -->
    <script>try{if(localStorage.getItem('slate_sidebar_collapsed')==='1')document.documentElement.classList.add('sidebar-collapsed');}catch(e){}</script>
    <title><?= e($pageTitle) ?> — <?= e($siteName) ?></title>
    <link rel="icon" href="<?= e(slate_favicon_url()) ?>">
    <meta name="csrf" content="<?= e(csrf_token()) ?>">
    <?php slate_ui_emit_css(); ?>
    <?php slate_brand_accent_emit(); ?>
    <?php slate_sidebar_theme_emit(); ?>
    <?php require SLATE_ROOT . '/includes/a11y_head.php'; ?>
    <style>
    /* ─── Layout ──────────────────────────────────────────── */
    body {
        min-height: 100vh;
        min-height: 100dvh;
        background: var(--bg);
    }
    .app-layout {
        display: grid;
        /* minmax(0,1fr), NOT 1fr: bare 1fr is minmax(auto,1fr), whose auto floor
           is the widest grid item's min-content — a single unshrinkable child
           (wide row, long URL, nowrap meta) would inflate the track past the
           viewport, dragging main.content (width:100%) with it. The 0 floor caps
           the track at the container width so the page can never grow sideways. */
        grid-template-columns: minmax(0, 1fr);
        grid-template-rows: auto 1fr;
        min-height: 100vh;
        min-height: 100dvh;
    }
    /* The single mobile column wraps the whole UI; keep its panel shrinkable so
       nothing inside can stretch it horizontally. */
    .app-panel { min-width: 0; }

    /* ─── Topbar ──────────────────────────────────────────── */
    .topbar {
        position: sticky;
        top: 0;
        z-index: 30;
        height: var(--topbar-height);
        background: rgba(245, 244, 241, 0.82);
        backdrop-filter: saturate(180%) blur(12px);
        -webkit-backdrop-filter: saturate(180%) blur(12px);
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 0 20px 0 24px;
    }
    /* Mobile-only current PAGE TITLE in the topbar (sidebar hidden < 768px).
       Hidden on desktop, where the breadcrumb fills the topbar instead. */
    .topbar-page-title-m {
        display: none;
        flex: 1 1 auto; min-width: 0;
        font-family: var(--font-display);
        font-size: 16px; font-weight: 600;
        color: var(--text); letter-spacing: -0.015em;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    /* Breadcrumb relocated into the topbar (desktop). Hidden until populated
       and on mobile (where the breadcrumb stays in content). */
    .topbar-bc-slot { display: none; min-width: 0; flex: 1 1 auto; align-items: center; }
    .topbar-bc-slot.is-filled { display: flex; }
    .topbar-bc-slot .slate-bc { margin: 0; }
    .topbar-bc-slot .slate-bc-item { font-size: 12.5px; }
    .topbar-bc-slot .slate-bc-current { color: var(--text); }
    .topbar-search-slot {
        flex: 0 1 auto;
        min-width: 0;
        display: flex;
        justify-content: center;
    }
    .topbar-search-slot:empty { flex: 1 1 auto; }
    .topbar-search {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 10px 6px 11px;
        border-radius: var(--radius);
        background: var(--surface);
        border: 1px solid var(--border);
        width: 100%;
        max-width: 360px;
        color: var(--muted);
        transition: border-color 140ms ease, box-shadow 140ms ease;
    }
    .topbar-search:focus-within {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px var(--ring);
    }
    .topbar-search input {
        border: 0;
        background: transparent;
        outline: none;
        flex: 1;
        font-size: 13px;
        color: var(--text);
        font-family: inherit;
        min-width: 0;
    }
    .topbar-search input::placeholder { color: var(--subtle); }
    .topbar-search kbd {
        font-family: var(--font-mono);
        font-size: 10.5px;
        padding: 1px 5px;
        background: var(--surface-2);
        color: var(--muted);
        border-radius: 4px;
        border: 1px solid var(--border);
    }
    /* ── Command search (jump-to) ─────────────────────────── */
    .topbar-cmd { position: relative; width: 100%; max-width: 380px; }
    .topbar-cmd .topbar-search { max-width: none; cursor: text; }
    .topbar-cmd .topbar-search svg { color: var(--subtle); flex: none; }
    .topbar-cmd kbd { flex: none; }
    .topbar-cmd-menu {
        position: absolute; top: calc(100% + 7px); left: 0; right: 0;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: 13px; box-shadow: 0 16px 40px rgba(15,17,23,.16);
        padding: 6px; z-index: 70; max-height: 340px; overflow-y: auto;
        animation: topbar-pop-in .12s ease;
    }
    .topbar-cmd-menu[hidden] { display: none; }
    .topbar-cmd-sect {
        font-family: var(--font-mono); font-size: 9.5px; letter-spacing: .12em;
        text-transform: uppercase; color: var(--subtle); padding: 8px 10px 5px;
    }
    .topbar-cmd-item {
        display: flex; align-items: center; gap: 11px; width: 100%;
        padding: 9px 10px; border-radius: 9px; cursor: pointer;
        color: var(--text); font-size: 13px; text-decoration: none;
        border: 0; background: transparent; text-align: left; font-family: inherit;
    }
    .topbar-cmd-item:hover,
    .topbar-cmd-item.is-active { background: var(--surface-2); text-decoration: none; }
    .topbar-cmd-item .nav-icon,
    .topbar-cmd-item svg { width: 16px; height: 16px; color: var(--muted); flex: none; }
    .topbar-cmd-item.is-active .nav-icon { color: var(--accent); }
    .topbar-cmd-item .lbl { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 500; }
    .topbar-cmd-item .go {
        color: var(--subtle); font-size: 11px; opacity: 0; transition: opacity .12s;
    }
    .topbar-cmd-item.is-active .go { opacity: 1; }
    .topbar-cmd-empty { padding: 16px; text-align: center; color: var(--muted); font-size: 12.5px; }
    .topbar-actions {
        display: flex;
        align-items: center;
        gap: var(--space-2);
        flex-shrink: 0;
    }
    .topbar-icon-btn {
        width: 32px;
        height: 32px;
        border-radius: var(--radius-sm);
        background: transparent;
        border: 0;
        color: var(--muted);
        display: grid;
        place-items: center;
        cursor: pointer;
        position: relative;
        transition: background 140ms ease, color 140ms ease;
    }
    .topbar-icon-btn:hover { background: var(--surface-2); color: var(--text); }
    .topbar-icon-btn-dot {
        position: absolute;
        top: 6px; right: 6px;
        width: 6px; height: 6px;
        border-radius: 999px;
        background: var(--danger);
        box-shadow: 0 0 0 2px var(--bg);
    }
    .topbar-user {
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--muted);
        font-size: 12.5px;
    }
    .topbar-user-name {
        font-weight: 500;
        color: var(--text-2);
    }
    .topbar-avatar {
        position: relative; overflow: hidden;
        width: 30px; height: 30px;
        border-radius: 999px;
        background: linear-gradient(135deg, var(--accent), var(--accent-deep, var(--accent)));
        color: var(--on-accent, #fff);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 12px;
        letter-spacing: 0.01em;
        flex-shrink: 0;
        box-shadow: 0 1px 2px rgba(15,17,23,0.12);
    }
    .topbar-avatar img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; display: block; }

    /* ─── Topbar menus (notifications + user) ────────────── */
    .topbar-menu { position: relative; display: inline-flex; }
    .topbar-badge {
        position: absolute; top: 1px; right: 0;
        min-width: 15px; height: 15px; padding: 0 4px;
        border-radius: 999px; background: var(--danger); color: #fff;
        font-size: 9.5px; font-weight: 700; line-height: 15px; text-align: center;
        box-shadow: 0 0 0 2px var(--bg); pointer-events: none;
    }
    .topbar-user-btn {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 4px 4px 4px 11px; border: 1px solid transparent;
        border-radius: 999px; background: transparent; cursor: pointer;
        color: var(--text-2); font: inherit; transition: background .12s, border-color .12s;
    }
    .topbar-user-btn .topbar-user-name { font-size: 13px; font-weight: 500; color: var(--text-2); }
    .topbar-user-btn:hover { background: var(--surface-2); border-color: var(--border); }
    .topbar-user-caret { color: var(--subtle); }
    .topbar-pop {
        position: absolute; top: calc(100% + 8px); right: 0;
        min-width: 260px; max-width: 320px;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        box-shadow: 0 12px 32px rgba(15,17,23,0.14);
        z-index: 60; overflow: hidden;
        animation: topbar-pop-in .12s ease;
    }
    @keyframes topbar-pop-in { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
    .topbar-pop-head {
        display: flex; align-items: center; justify-content: space-between;
        padding: 11px 14px; border-bottom: 1px solid var(--border);
        font-size: 12.5px; font-weight: 600; color: var(--text);
    }
    .topbar-pop-link { background: none; border: 0; padding: 0; cursor: pointer;
        font: inherit; font-size: 11.5px; font-weight: 500; color: var(--accent); }
    .topbar-pop-link:hover { text-decoration: underline; }
    .topbar-pop-body { max-height: 360px; overflow-y: auto; }
    .topbar-pop-empty { padding: 22px 14px; text-align: center; color: var(--muted); font-size: 12.5px; }
    .topbar-notif {
        display: flex; gap: 9px; padding: 11px 14px; text-decoration: none;
        color: var(--text); border-bottom: 1px solid var(--border);
        transition: background .1s;
    }
    .topbar-notif:last-child { border-bottom: 0; }
    a.topbar-notif:hover { background: var(--surface-2); }
    .topbar-notif-dot { width: 7px; height: 7px; border-radius: 999px; background: transparent; margin-top: 5px; flex: none; }
    .topbar-notif.is-unread .topbar-notif-dot { background: var(--accent); }
    .topbar-notif-main { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
    .topbar-notif-title { font-size: 12.5px; font-weight: 600; color: var(--text); }
    .topbar-notif-body { font-size: 12px; color: var(--muted); overflow-wrap: anywhere; }
    .topbar-notif-time { font-size: 11px; color: var(--subtle); margin-top: 2px; }
    .topbar-pop-userhead { padding: 12px 14px; border-bottom: 1px solid var(--border); }
    .topbar-pop-username { font-size: 13px; font-weight: 600; color: var(--text); }
    .topbar-pop-userrole { font-size: 11.5px; color: var(--accent); margin-top: 1px; }
    .topbar-pop-useremail { font-size: 11.5px; color: var(--muted); margin-top: 2px; overflow-wrap: anywhere; }
    .topbar-pop-item {
        display: block; padding: 9px 14px; text-decoration: none;
        color: var(--text-2); font-size: 12.5px; transition: background .1s;
    }
    .topbar-pop-item:hover { background: var(--surface-2); color: var(--text); }
    .topbar-pop-item-danger { color: var(--danger); }
    .topbar-pop-item-danger:hover { background: var(--danger-soft); color: var(--danger); }

    main.content {
        padding: 16px 14px 28px;
        /* clear the fixed bottom tab bar (its height + safe area + a little room) */
        padding-bottom: calc(20px + var(--tabbar-height) + env(safe-area-inset-bottom, 0));
        max-width: var(--content-max);
        width: 100%;
        /* Centre the capped content in the panel so wide screens (and the
           collapsed sidebar) split the whitespace evenly instead of dumping it
           all on the right. */
        margin-inline: auto;
        min-width: 0;
    }

    /* ─── Sidebar (desktop only) ──────────────────────────── */
    .sidebar { display: none; }
    /* Collapse toggle is a desktop control (mobile uses the bottom sheet). */
    .sidebar-toggle { display: none; }

    /* Base SIZE (only) for every slate_admin_nav_icon() SVG, unconditional.
       This used to live only inside the min-width:768px block below
       (written for the desktop sidebar/tabbar), so any plain .btn using
       the same icon helper (e.g. a plugin's toolbar buttons) had NO
       sizing at all below 768px and fell back to the SVG's browser-
       default intrinsic size (~300x150px) -- a giant icon filling most
       of a mobile button. Deliberately NOT moving color here too: with
       no color rule at all below 768px, a .btn's icon was already
       falling back to inheriting the button's own (correct, visible)
       text color via currentColor -- adding color:var(--sidebar-muted)
       unconditionally would break exactly that, the same near-invisible-
       icon bug already fixed once for .settings-tab svg. The desktop-
       only color (plus its hover/active variants) stays below, scoped
       to where a sidebar-muted default actually makes sense. */
    .nav-icon {
        width: 18px; height: 18px;
        flex-shrink: 0;
    }

    @media (min-width: 768px) {
        /* Desktop: the page itself doesn't scroll — the content panel scrolls
           inside. (Both cards are sized to the viewport.) */
        body { overflow: hidden; }
        .app-layout {
            grid-template-columns: var(--sidebar-width) 1fr;
            grid-template-rows: 1fr;
            grid-template-areas: "sidebar panel";
            /* Full-screen, edge-to-edge: a fixed sidebar + a content panel that
               scrolls internally. The column width animates for the collapse. */
            height: 100vh;
            height: 100dvh;
            background: var(--bg);
            transition: grid-template-columns .22s cubic-bezier(.4, 0, .2, 1);
        }
        /* Collapsed → icon rail. The class is set on <html> (see head script)
           so it applies before first paint with no flash of the wide sidebar. */
        html.sidebar-collapsed .app-layout {
            grid-template-columns: var(--sidebar-collapsed-width) 1fr;
        }
        /* White rounded card holding the topbar + main. It OVERLAPS the
           sidebar's right edge (negative left margin) and floats on top of it
           (z-index + left shadow) — producing the soft curve between the two
           cards. It is also the scroll container, so the sticky topbar pins to
           its top and the rounded corners clip it. */
        .app-panel {
            grid-area: panel;
            display: flex;
            flex-direction: column;
            min-width: 0;
            /* Full-screen content surface; scrolls internally so the sticky
               topbar pins to its top. A whisper of translucency adds premium
               depth — but NO backdrop-filter here (it would trap position:fixed
               modals rendered inside the panel, e.g. content-builder / POS). */
            background: rgba(255, 255, 255, 0.72);
            overflow-y: auto;
            overflow-x: auto;
            overscroll-behavior: contain;
        }
        /* Topbar is a frosted strip pinned to the top of the glass panel — the
           content scrolling beneath it blurs through. */
        .topbar {
            background: rgba(255, 255, 255, 0.62);
            -webkit-backdrop-filter: blur(18px) saturate(160%);
            backdrop-filter: blur(18px) saturate(160%);
            border-bottom: 1px solid var(--border);
        }
        /* Sidebar collapse toggle (desktop only — sits at the topbar's far left). */
        .sidebar-toggle {
            flex: none;
            width: 34px; height: 34px;
            display: grid; place-items: center;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            color: var(--muted);
            cursor: pointer;
            transition: background .14s ease, color .14s ease, border-color .14s ease;
        }
        .sidebar-toggle:hover { background: var(--surface-2); color: var(--text); border-color: var(--border-strong); }
        .sidebar-toggle svg { width: 18px; height: 18px; transition: transform .22s cubic-bezier(.4,0,.2,1); }
        html.sidebar-collapsed .sidebar-toggle svg { transform: rotate(180deg); }
        /* Desktop: breadcrumb fills the topbar; the page title shows in the
           content page-header (the mobile-only topbar title is hidden). */
        .topbar-page-title-m { display: none; }
        /* Push the search + actions into a right-hand cluster. With a
           breadcrumb present the auto margin sits after it; on the dashboard
           (no breadcrumb) it fills the otherwise-empty left side — so the
           topbar reads consistently across every page. */
        .topbar-search-slot { justify-content: flex-end; margin-left: auto; }
        main.content {
            /* Grow to fill the panel so the white surface always reaches the
               bottom gutter, even on short pages. */
            flex: 1 0 auto;
            padding: 24px 32px 56px;
        }
        .sidebar {
            grid-area: sidebar;
            display: flex; flex-direction: column;
            /* Dark sidebar, full height, flush to the left edge. A faint accent
               glow at the top adds life. Solid (not glass) for a clean,
               minimal-premium read and best performance. */
            background:
                radial-gradient(120% 42% at 50% 0%,
                    color-mix(in srgb, var(--accent) 16%, transparent), transparent 60%),
                var(--sidebar-bg);
            color: var(--sidebar-text);
            padding: 16px 12px 12px;
            border-right: 1px solid var(--sidebar-border);
            position: relative;
            z-index: 1;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
        }

        /* ── Brand row ─────────────────────────────────────────── */
        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 2px 4px 14px;
            margin-bottom: 8px;
            border-bottom: 1px solid var(--sidebar-border);
            color: var(--sidebar-strong);
            text-decoration: none;
        }
        .sidebar-brand:hover { color: var(--sidebar-strong); text-decoration: none; }
        /* Logo variant. Clean by default (logo sits directly on the sidebar);
           on dark themes it gets a white tile (.is-tiled) so dark logo artwork
           stays legible. */
        .sidebar-brand.has-logo {
            display: block;
            text-align: left;
            padding: 2px 4px 14px;
        }
        .sidebar-brand-logo {
            display: block;
            padding: 0;
            transition: opacity .16s ease;
        }
        .sidebar-brand.has-logo:hover .sidebar-brand-logo { opacity: 0.85; }
        .sidebar-brand-logo img {
            display: block;
            width: auto; max-width: 100%;
            max-height: 46px;
            margin: 0;
        }
        /* Dark-theme tile */
        .sidebar-brand-logo.is-tiled {
            background: #fff;
            border-radius: 12px;
            padding: 11px 13px;
            box-shadow: inset 0 0 0 1px rgba(0,0,0,0.05), 0 2px 6px rgba(0,0,0,0.30);
        }
        .sidebar-brand-logo.is-tiled img { max-height: 40px; margin: 0 auto; }
        .sidebar-brand.has-logo:hover .sidebar-brand-logo.is-tiled {
            opacity: 1;
            transform: translateY(-1px);
            box-shadow: inset 0 0 0 1px rgba(0,0,0,0.05), 0 6px 16px rgba(0,0,0,0.40);
        }
        .sidebar-brand-tag {
            display: block;
            margin: 9px 2px 0;
            font-size: 10px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--sidebar-subtle);
        }
        /* Letter-mark fallback (no logo configured) */
        .sidebar-brand-mark {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--accent), var(--accent-deep));
            color: var(--on-accent);
            display: grid;
            place-items: center;
            font-family: var(--font-display);
            font-size: 15px;
            font-weight: 700;
            letter-spacing: -0.04em;
            flex-shrink: 0;
            box-shadow: 0 2px 10px color-mix(in srgb, var(--accent) 42%, transparent),
                        inset 0 1px 0 rgba(255,255,255,0.25);
        }
        .sidebar-brand-text {
            min-width: 0;
            flex: 1;
            line-height: 1.15;
        }
        .sidebar-brand-name {
            display: block;
            font-family: var(--font-display);
            font-size: 15px;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--sidebar-strong);
        }
        .sidebar-brand-sub {
            display: block;
            font-size: 10.5px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--sidebar-subtle);
            font-weight: 600;
            margin-top: 2px;
        }

        /* ── Nav groups — native <details>/<summary> dropdowns ───────────
             The browser owns open/closed state entirely; no JS height
             math, so there's no animation edge case to get wrong. Content
             show/hide is instant (a native <details> limitation), traded
             deliberately for rock-solid reliability over a fancier but
             fragile JS-measured height animation. Only the chevron
             rotates, via a plain CSS transition — that part is safe to
             animate since it's a simple transform, never height/'none'. */
        .nav-group { display: block; }
        .nav-group > summary { list-style: none; }
        .nav-group > summary::-webkit-details-marker,
        .nav-group > summary::marker { display: none; content: ""; }
        .nav-group:first-of-type > .nav-section-label { padding-top: 8px; }
        .nav-group .sidebar-nav { padding-top: 2px; }

        .nav-section-label {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            font: inherit;
            background: none;
            border: 0;
            cursor: pointer;
            font-size: 10.5px;
            letter-spacing: 0.10em;
            text-transform: uppercase;
            color: var(--sidebar-subtle);
            padding: 16px 12px 7px;
            font-weight: 700;
            border-radius: 8px;
            transition: color .14s ease;
        }
        .nav-section-label:hover { color: var(--sidebar-strong); }
        .nav-section-label::before {
            content: "";
            flex: none;
            width: 12px; height: 2px;
            border-radius: 2px;
            background: color-mix(in srgb, var(--accent) 55%, var(--sidebar-subtle));
        }
        .nav-section-label-text { flex: 1; text-align: left; }
        .nav-section-chevron {
            flex: none;
            width: 13px; height: 13px;
            transition: transform .2s ease;
        }
        .nav-group[open] > .nav-section-label .nav-section-chevron { transform: rotate(90deg); }

        /* ── Nav items (rounded icon-tile style — reference look) ─── */
        .sidebar-nav { list-style: none; padding: 0; margin: 0 0 4px; }
        .sidebar-item { position: relative; }
        .sidebar-item + .sidebar-item { margin-top: 3px; }
        .sidebar-item a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 5px 8px;
            color: var(--sidebar-muted);
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 500;
            border-radius: 12px;
            position: relative;
            transition: color .16s ease, background .16s ease, transform .16s ease;
        }
        .sidebar-item a:hover {
            color: var(--sidebar-strong);
            background: var(--sidebar-hover);
            transform: translateX(2px);
            text-decoration: none;
        }
        /* The icon sits in a rounded-square tile. */
        .sidebar-item a .nav-icon {
            width: 34px; height: 34px;
            padding: 8px;
            box-sizing: border-box;
            border-radius: 11px;
            background: var(--sidebar-hover);
            color: var(--sidebar-muted);
            transition: background .16s ease, color .16s ease, box-shadow .16s ease;
        }
        .sidebar-item a:hover .nav-icon {
            background: color-mix(in srgb, var(--sidebar-strong) 14%, transparent);
            color: var(--sidebar-strong);
        }
        /* Active — accent-tinted glass pill + a thin accent ring + a glowing
           accent icon tile + brighter label. A clear, premium selected state. */
        .sidebar-item.is-active a {
            color: var(--sidebar-strong);
            font-weight: 600;
            background: linear-gradient(90deg,
                color-mix(in srgb, var(--accent) 28%, transparent),
                color-mix(in srgb, var(--accent) 11%, transparent));
            box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--accent) 32%, transparent);
        }
        .sidebar-item.is-active a:hover { transform: none; }
        .sidebar-item.is-active a .nav-icon {
            background: var(--accent);
            color: var(--on-accent);
            box-shadow: 0 6px 16px color-mix(in srgb, var(--accent) 50%, transparent),
                        inset 0 1px 0 rgba(255, 255, 255, 0.30);
        }
        /* Width/height/flex-shrink moved to the unconditional base rule
           above (needed on mobile too); this desktop-scoped rule now only
           carries the sidebar/tabbar-appropriate default color. */
        .nav-icon {
            color: var(--sidebar-muted);
            transition: color .14s ease;
        }

        .sidebar-footer {
            margin-top: auto;
            padding: 10px 8px 4px;
            border-top: 1px solid var(--sidebar-border);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar-footer .sidebar-user-info { min-width: 0; flex: 1; }
        .sidebar-footer .sidebar-user-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--sidebar-strong);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
        }
        .sidebar-footer .sidebar-user-role {
            font-size: 11px;
            color: var(--sidebar-muted);
            line-height: 1.3;
        }
        .sidebar-footer-logout {
            width: 32px;
            height: 32px;
            border-radius: var(--radius-sm);
            color: var(--sidebar-muted);
            display: grid;
            place-items: center;
            text-decoration: none;
            transition: background 0.12s, color 0.12s;
        }
        .sidebar-footer-logout:hover { background: color-mix(in srgb, var(--accent) 20%, transparent); color: var(--sidebar-strong); }
        /* Rounded-square avatar tile (matches the reference's nav icon tiles). */
        .sidebar-footer .topbar-avatar { border-radius: 10px; }

        /* ── Platform signature (Kohevo) — one small row above the tenant's own
           sidebar-footer, deliberately smaller/muted than everything above it
           so it reads as platform attribution, not a second brand. */
        .sidebar-signature {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 8px 6px;
            margin-top: 4px;
            border-top: 1px solid var(--sidebar-border);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.02em;
            color: var(--sidebar-subtle);
        }
        .sidebar-signature img { display: block; height: 14px; width: auto; }
        /* Same problem as .sidebar-brand-logo.is-tiled (dark themes need a light
           tile behind the dark-ink mark) — same fix, sized for this smaller icon. */
        .sidebar-signature.is-tiled img {
            background: #fff;
            border-radius: 4px;
            padding: 2px 3px;
        }

        /* ── Collapsed sidebar → icon rail ─────────────────────── */
        html.sidebar-collapsed .sidebar { padding-left: 10px; padding-right: 10px; }
        /* Hide every text label; keep the icon tiles. */
        html.sidebar-collapsed .sidebar-brand-text,
        html.sidebar-collapsed .sidebar-brand-tag,
        html.sidebar-collapsed .sidebar-item a > span,
        html.sidebar-collapsed .sidebar-user-info { display: none; }
        /* Centre the brand mark / logo. */
        html.sidebar-collapsed .sidebar-brand,
        html.sidebar-collapsed .sidebar-brand.has-logo {
            justify-content: center; text-align: center; padding: 2px 0 14px;
        }
        html.sidebar-collapsed .sidebar-brand-logo img { max-height: 30px; margin: 0 auto; }
        /* Centre the nav icon tiles. */
        html.sidebar-collapsed .sidebar-item a { justify-content: center; padding: 5px 0; gap: 0; }
        html.sidebar-collapsed .sidebar-item a:hover { transform: none; }
        /* Drop the active row-pill when collapsed — the accent icon tile alone
           carries the state, cleaner at rail width. */
        html.sidebar-collapsed .sidebar-item.is-active a { background: transparent; box-shadow: none; }
        /* Section label → a centred accent tick acting as a divider. */
        html.sidebar-collapsed .nav-section-label {
            justify-content: center; gap: 0; padding: 12px 0 8px;
            font-size: 0; letter-spacing: 0;
        }
        html.sidebar-collapsed .nav-section-label::before { width: 18px; }
        html.sidebar-collapsed .nav-section-chevron { display: none; }
        /* Icon rail always shows every icon — per-group collapse state is a
           text-label affordance that doesn't make sense once labels are gone.
           A closed <details> hides its non-summary children via UA styles;
           forcing them back to display:block (overriding that) is the
           standard way to show <details> content regardless of [open]. */
        html.sidebar-collapsed .nav-group > .sidebar-nav { display: block !important; }
        /* Footer → centred avatar with the logout icon stacked beneath. */
        html.sidebar-collapsed .sidebar-footer {
            flex-direction: column; gap: 8px; padding: 10px 0 4px;
        }
    }

    /* ─── Bottom tab bar (mobile only) — fixed edge-to-edge nav ─── */
    .tabbar {
        position: fixed;
        bottom: 0; left: 0; right: 0;
        height: var(--tabbar-height);
        padding-bottom: env(safe-area-inset-bottom, 0);
        background: var(--surface);
        border-top: 1px solid var(--border);
        box-shadow: 0 -2px 10px rgba(15, 17, 23, 0.05);
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        align-items: stretch;
        z-index: 30;
        overflow: hidden;
    }
    .tabbar-item,
    .tabbar-fab {
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 4px;
        color: var(--subtle);
        text-decoration: none;
        font-size: 10px;
        font-weight: 600;
        line-height: 1.1;
        padding: 9px 3px 8px;
        min-width: 0;
        border: none;
        background: transparent;
        cursor: pointer;
        -webkit-tap-highlight-color: transparent;
        transition: color 0.16s ease, transform 0.12s ease;
    }
    .tabbar-item:hover,
    .tabbar-fab:hover { color: var(--text-2); text-decoration: none; }
    .tabbar-item:active,
    .tabbar-fab:active { transform: scale(0.93); }
    .tabbar-item.is-active { color: var(--accent); font-weight: 700; }
    /* Active: sliding accent indicator at the top edge of the cell. */
    .tabbar-item::before {
        content: "";
        position: absolute;
        top: 0; left: 50%;
        width: 26px; height: 3px;
        border-radius: 0 0 4px 4px;
        background: var(--accent);
        transform: translateX(-50%) scaleX(0);
        transform-origin: center;
        transition: transform 0.2s cubic-bezier(.22,1,.36,1);
    }
    .tabbar-item.is-active::before { transform: translateX(-50%) scaleX(1); }
    /* Every tab's icon box is the same 24px height so all labels line up. */
    .tabbar-item .nav-icon,
    .tabbar-fab svg {
        width: 24px; height: 24px; color: var(--subtle);
        transition: color .16s ease, transform .2s ease;
    }
    .tabbar-item.is-active .nav-icon { color: var(--accent); transform: translateY(-1px); }
    /* The More "+" sits in a soft circular chip (16px glyph + 4px padding =
       24px box, matching the other icons so nothing sits taller/lower). */
    .tabbar-fab svg {
        width: 16px; height: 16px;
        padding: 4px;
        box-sizing: content-box;
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: 999px;
        color: var(--muted);
    }
    .tabbar-fab[aria-expanded="true"] { color: var(--accent); }
    .tabbar-fab[aria-expanded="true"] svg { color: var(--accent); background: var(--accent-soft); border-color: transparent; transform: rotate(135deg); }
    .tabbar-fab:active svg { transform: scale(0.9); }
    .tabbar-item-label {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
    }

    @media (min-width: 768px) {
        .tabbar { display: none; }
        main.content { padding-bottom: 56px; }
    }
    @media (max-width: 767px) {
        /* Keep the mobile topbar minimal & uncluttered: brand · bell · avatar.
           Breadcrumb stays in the content; gear + name are dropped here. */
        .topbar { height: 54px; background: rgba(255,255,255,0.92); padding: 0 12px; gap: 8px; }
        .topbar-page-title-m { display: block; }
        .topbar-bc-slot { display: none !important; }
        /* Title lives in the topbar on mobile — drop the big content H1 so it
           isn't duplicated. Breadcrumb, sub-text and actions stay. */
        main.content .page-header h1 { display: none; }
        .topbar-search-slot { display: none; }
        .topbar-gear { display: none; }
        .topbar-user-name { display: none; }
        .topbar-user-caret { display: none; }
        .topbar-actions { gap: 4px; }
        .topbar-actions .btn { padding: 7px 10px; }
        .topbar-icon-btn { width: 36px; height: 36px; }
        .topbar-user-btn { padding: 3px; gap: 0; }
        .topbar-avatar { width: 30px; height: 30px; }
        .topbar-pop { min-width: 240px; max-width: calc(100vw - 24px); }

        /* Compact action buttons in page headers / toolbars on phones so
           multi-button rows (Mark completed, No-show, Cancel, …) don't
           dominate the screen. */
        .page-header .btn:not(.btn-lg):not(.btn-block),
        .toolbar .btn:not(.btn-lg):not(.btn-block) {
            min-height: 38px; padding: 8px 12px; font-size: 12.5px;
        }
        .page-header input[type="datetime-local"],
        .page-header input[type="date"] { min-height: 38px; padding: 7px 10px; font-size: 12.5px; }
        /* Detail-page toolbars wrap each action in an inline <form>; unwrap
           them so the buttons fill the row instead of leaving empty space. */
        .page-header .toolbar { display: flex; flex-wrap: wrap; gap: 8px; width: 100%; }
        .page-header .toolbar > form { display: contents; }
        .page-header .toolbar .btn { flex: 1 1 calc(50% - 4px); justify-content: center; }
        .page-header .toolbar input[type="datetime-local"],
        .page-header .toolbar input[type="date"] { flex: 1 1 100%; }
    }

    /* ─── More sheet (mobile overflow nav) ────────────────── */
    .sheet-overlay {
        position: fixed;
        inset: 0;
        background: rgba(14, 17, 23, 0.4);
        backdrop-filter: blur(3px);
        -webkit-backdrop-filter: blur(3px);
        z-index: 40;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.2s ease;
    }
    .sheet-overlay.is-open {
        opacity: 1;
        pointer-events: auto;
    }
    /* Full-screen menu (covers the whole viewport, slides up from the bottom). */
    .sheet {
        position: fixed;
        inset: 0;
        display: flex; flex-direction: column;
        background: var(--surface);
        border: 0;
        border-radius: 0;
        box-shadow: none;
        z-index: 50;
        transform: translateY(100%);
        transition: transform 0.32s cubic-bezier(.22,1.2,.36,1);
        max-height: none;
        overflow: hidden;
    }
    .sheet.is-open { transform: translateY(0); }
    /* No drag handle on the full-screen menu — the × closes it. */
    .sheet-grab { display: none; }
    .sheet-handle {
        width: 40px; height: 4px;
        background: var(--faint);
        border-radius: 999px;
    }
    .sheet-topbar {
        display: flex; align-items: center; gap: 8px;
        padding: calc(env(safe-area-inset-top, 0) + 14px) 14px 14px 20px;
        border-bottom: 1px solid var(--border);
    }
    .sheet-header {
        flex: 1; min-width: 0;
        font-size: 16px;
        font-weight: 600;
        color: var(--text);
        letter-spacing: -0.01em;
        font-family: var(--font-display);
    }
    .sheet-close {
        width: 32px; height: 32px; flex: none;
        display: grid; place-items: center;
        border: 1px solid var(--border); border-radius: 999px;
        background: var(--surface-2); color: var(--muted);
        cursor: pointer; -webkit-tap-highlight-color: transparent;
        transition: background .12s ease, color .12s ease;
    }
    .sheet-close:active { background: var(--surface-sunken); color: var(--text); }
    /* Inner scroll area; the bottom fade hints there's more below the fold. */
    .sheet-scroll {
        flex: 1 1 auto; min-height: 0;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        padding-bottom: calc(env(safe-area-inset-bottom, 0) + 12px);
        -webkit-mask-image: linear-gradient(180deg, #000 calc(100% - 22px), transparent);
                mask-image: linear-gradient(180deg, #000 calc(100% - 22px), transparent);
    }
    .sheet-section-label {
        display: flex; align-items: center; gap: 8px; width: 100%;
        font-family: var(--font-mono);
        font: 600 10.5px var(--font-mono);
        letter-spacing: 0.1em; text-transform: uppercase;
        color: var(--subtle); font-weight: 600;
        padding: 14px 20px 7px;
        background: none; border: 0;
        list-style: none;
    }
    .sheet-section-label::-webkit-details-marker,
    .sheet-section-label::marker { display: none; content: ""; }
    details.nav-group > .sheet-section-label { cursor: pointer; }
    .sheet-section-label .nav-section-label-text { flex: 1; text-align: left; }
    .sheet-section-label .nav-section-chevron { flex: none; width: 13px; height: 13px; transition: transform .2s ease; }
    details.nav-group[open] > .sheet-section-label .nav-section-chevron { transform: rotate(90deg); }
    /* App-style quick-menu: 2-column tile grid, grouped by section. */
    /* App-style single-column list rows. */
    .sheet-nav {
        list-style: none; margin: 0;
        padding: 0 14px 2px;
        display: flex; flex-direction: column; gap: 8px;
    }
    .sheet-nav li { margin: 0; }
    .sheet-nav a {
        display: flex; align-items: center; gap: 13px;
        padding: 11px 14px;
        color: var(--text);
        text-decoration: none;
        font-size: 14.5px;
        font-weight: 500;
        border: 1px solid var(--border);
        border-radius: 14px;
        background: var(--surface-2);
        min-height: 56px;
        -webkit-tap-highlight-color: transparent;
        transition: border-color .12s ease, background .12s ease, transform .08s ease;
    }
    .sheet-nav a:active { transform: scale(0.985); }
    .sheet-nav a:hover { background: var(--surface); border-color: var(--border-stronger); text-decoration: none; }
    .sheet-nav-ico {
        width: 38px; height: 38px; flex: none;
        display: grid; place-items: center;
        border-radius: 11px;
        background: var(--surface); border: 1px solid var(--border);
        color: var(--muted);
    }
    .sheet-nav .nav-icon { width: 20px; height: 20px; color: inherit; flex: none; }
    .sheet-nav-label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .sheet-nav-arr { flex: none; color: var(--subtle); transition: transform .12s ease, color .12s ease; }
    .sheet-nav a:hover .sheet-nav-arr { color: var(--muted); transform: translateX(2px); }
    .sheet-nav a.is-active {
        background: var(--accent-soft); border-color: transparent; color: var(--accent-deep);
    }
    .sheet-nav a.is-active .sheet-nav-ico { background: var(--accent); border-color: transparent; color: #fff; }
    .sheet-nav a.is-active .sheet-nav-arr { color: var(--accent); }
    .sheet-nav-danger { color: var(--danger); }
    .sheet-nav-danger .sheet-nav-ico { color: var(--danger); }

    @media (max-width: 480px) {
        .topbar-user-name { display: none; }
    }

    /* ════════════════════════════════════════════════════════════
       GLASSMORPHISM THEME  (global override — emitted last, wins the
       cascade). Frosted translucent surfaces over a soft gradient
       canvas, glowing accents, layered depth. Tweak the tokens below
       to dial the whole look up or down.
       ════════════════════════════════════════════════════════════ */
    :root {
        --glass-fill:      rgba(255, 255, 255, 0.66);
        --glass-fill-2:    rgba(255, 255, 255, 0.52);
        --glass-stroke:    rgba(255, 255, 255, 0.70);
        --glass-blur:      blur(18px) saturate(150%);
        --glass-shadow:    0 1px 0 rgba(255,255,255,0.55) inset,
                           0 4px 16px rgba(15, 17, 23, 0.05);
        --glass-shadow-hi: 0 1px 0 rgba(255,255,255,0.65) inset,
                           0 12px 30px rgba(15, 17, 23, 0.09);
    }

    /* Soft gradient canvas behind everything — gives the frosted
       surfaces colour to refract. */
    body {
        background:
            radial-gradient(1100px 700px at -4% -10%, color-mix(in srgb, var(--accent) 9%, transparent), transparent 56%),
            radial-gradient(1000px 720px at 106% -6%, color-mix(in srgb, var(--accent) 6%, transparent), transparent 55%),
            linear-gradient(180deg, #F3F5F9 0%, #ECEFF5 100%);
    }

    /* Frosted cards (used on every page except the dashboard, which has
       its own glass styles). */
    .card {
        background: var(--glass-fill);
        -webkit-backdrop-filter: var(--glass-blur);
                backdrop-filter: var(--glass-blur);
        border: 1px solid var(--glass-stroke);
        box-shadow: var(--glass-shadow);
    }
    .stat {
        background: var(--glass-fill);
        -webkit-backdrop-filter: var(--glass-blur);
                backdrop-filter: var(--glass-blur);
        border: 1px solid var(--glass-stroke);
        box-shadow: var(--glass-shadow);
    }
    .card-link:hover, .stat:hover {
        border-color: rgba(255, 255, 255, 0.92);
        box-shadow: var(--glass-shadow-hi);
    }

    /* Frosted inputs */
    .input,
    .field input[type="text"], .field input[type="email"],
    .field input[type="password"], .field input[type="number"],
    .field input[type="url"], .field input[type="search"],
    .field input[type="tel"], .field input[type="date"],
    .field input[type="datetime-local"], .field input[type="time"],
    .field select, .field textarea {
        background: var(--glass-fill-2);
        -webkit-backdrop-filter: blur(8px);
                backdrop-filter: blur(8px);
        border-color: rgba(255, 255, 255, 0.6);
    }

    /* Buttons stay clean & flat (solid fills, no glass blur, no colour glow) —
       see the base button styles in ui_components.php. */

    @media (min-width: 768px) {
        /* Let the canvas gradient show through (cards/sidebar/topbar
           frost it). */
        .app-layout { background: transparent; }

        /* Solid dark sidebar, full height, flush to the left edge — a faint
           accent glow at the top for life. Minimal & performant (no glass). */
        .sidebar {
            background:
                radial-gradient(120% 42% at 50% 0%,
                    color-mix(in srgb, var(--accent) 14%, transparent), transparent 60%),
                var(--sidebar-bg);
            border: 0;
            border-right: 1px solid var(--sidebar-border);
            box-shadow: none;
        }

        /* Clean full-screen content surface. NO backdrop-filter here — it would
           make the panel the containing block for position:fixed modals
           rendered inside it (content-builder / POS), trapping them. A whisper
           of translucency keeps a premium tint while content stays crisp. */
        .app-panel {
            background: rgba(255, 255, 255, 0.90);
            border: 0;
        }

        /* Frosted topbar pinned to the top of the panel. */
        .topbar {
            background: rgba(255, 255, 255, 0.62);
            -webkit-backdrop-filter: blur(16px) saturate(160%);
                    backdrop-filter: blur(16px) saturate(160%);
            border-bottom: 1px solid var(--border);
        }
    }

    /* Graceful fallback: if the browser can't do backdrop-filter, fall
       back to more-opaque solids so nothing turns unreadable. */
    @supports not ((-webkit-backdrop-filter: blur(1px)) or (backdrop-filter: blur(1px))) {
        .card, .stat { background: rgba(255, 255, 255, 0.96); }
        @media (min-width: 768px) {
            .app-panel { background: #FFFFFF; }
            .sidebar   { background: var(--sidebar-bg); }
            .topbar    { background: rgba(255, 255, 255, 0.96); }
        }
    }
    </style>
    <?php if (class_exists('Hook')) Hook::doAction('admin_head'); ?>
</head>
<body>
    <a class="skip-link" href="#content"><?= __('skip_to_content', 'Skip to main content') ?></a>

    <div class="app-layout">

        <!-- ─── Sidebar (desktop) ────────────────────────── -->
        <aside class="sidebar" aria-label="<?= __('primary_navigation', 'Primary navigation') ?>">
            <a href="<?= e(SLATE_URL) ?>/admin/"
               class="sidebar-brand<?= $brandLogoUrl !== '' ? ' has-logo' : '' ?>">
                <?php if ($brandLogoUrl !== ''): ?>
                    <span class="sidebar-brand-logo">
                        <?php /* The sidebar's own background is a per-tenant setting (sidebar_theme), not the OS theme -- the existing white-tile-on-dark-theme CSS above already keeps the light logo legible either way, so this always renders the light variant. */ ?>
                        <img src="<?= e($brandLogoUrl) ?>" alt="<?= e($siteName) ?>">
                    </span>
                    <span class="sidebar-brand-tag"><?= e($brandSublabel) ?></span>
                <?php else: ?>
                    <span class="sidebar-brand-mark" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($siteName, 0, 1))) ?></span>
                    <span class="sidebar-brand-text">
                        <span class="sidebar-brand-name"><?= e($siteName) ?></span>
                        <span class="sidebar-brand-sub"><?= e($brandSublabel) ?></span>
                    </span>
                <?php endif; ?>
            </a>

            <?php foreach ($navGroups as $groupKey => $groupItems):
                $groupKeyAttr = (string)$groupKey;
                // The group holding the current page starts expanded; every
                // other group starts collapsed. Native <details>/<summary>
                // does the actual show/hide — no JS, no height math, so
                // there's no animation edge case left to get wrong.
                $groupHasActive = false;
                foreach ($groupItems as $gi) {
                    if (($gi['slug'] ?? null) === $currentNav) { $groupHasActive = true; break; }
                }
            ?>
                <details class="nav-group" <?= $groupHasActive ? 'open' : '' ?>>
                    <summary class="nav-section-label">
                        <span class="nav-section-label-text"><?= e(slate_admin_group_label($groupKeyAttr)) ?></span>
                        <svg class="nav-section-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>
                    </summary>
                    <ul class="sidebar-nav">
                        <?php foreach ($groupItems as $item):
                            $isActive = ($currentNav === ($item['slug'] ?? null));
                        ?>
                            <li class="sidebar-item <?= $isActive ? 'is-active' : '' ?>">
                                <a href="<?= e($item['href']) ?>"
                                   <?= $isActive ? 'aria-current="page"' : '' ?>>
                                    <?= slate_admin_nav_icon($item['icon'] ?? 'circle') ?>
                                    <span><?= e($item['label']) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endforeach; ?>

            <?php /* Kohevo platform signature — persistent, secondary to the tenant
                     brand above. Routed entirely through PlatformSignature/
                     PlatformIdentity; this file never names an asset path or the
                     platform name directly. $sidebarLogoTile (already computed above
                     for the tenant logo) is reused as-is: the sidebar can be dark or
                     light depending on the tenant's chosen theme, and the mark needs
                     the same white-tile treatment the tenant logo already gets on
                     dark themes so it stays legible either way. */ ?>
            <div class="sidebar-signature<?= $sidebarLogoTile ? ' is-tiled' : '' ?>">
                <?= \Slate\Services\Content\PlatformSignature::render(\Slate\Services\Content\PlatformSignature::MODE_STANDARD) ?>
            </div>

            <div class="sidebar-footer">
                <span class="topbar-avatar" aria-hidden="true">
                    <?= e(mb_strtoupper(mb_substr($user['name'] ?? 'A', 0, 1))) ?>
                    <?php if ($userAvatarUrl !== ''): ?><img src="<?= e($userAvatarUrl) ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?>
                </span>
                <div class="sidebar-user-info">
                    <div class="sidebar-user-name"><?= e($user['name']) ?></div>
                    <div class="sidebar-user-role"><?= e($user['role'] ?? __('administrator', 'Administrator')) ?></div>
                </div>
                <a href="<?= e(SLATE_URL) ?>/admin/logout.php?csrf=<?= e(csrf_token()) ?>"
                   class="sidebar-footer-logout"
                   title="<?= __('logout', 'Log out') ?>"
                   aria-label="<?= __('logout', 'Log out') ?>">
                    <?= slate_admin_nav_icon('logout') ?>
                </a>
            </div>
        </aside>

        <!-- ─── Floating content panel (topbar + main as one rounded
             white surface on desktop; a transparent pass-through on
             mobile, where the topbar stays sticky and main scrolls the
             page as before). ────────────────────────────────────── -->
        <div class="app-panel">

        <!-- ─── Topbar ───────────────────────────────────── -->
        <header class="topbar">
            <!-- Sidebar collapse toggle (desktop only; CSS-hidden on mobile). -->
            <button type="button" class="sidebar-toggle" id="slate-sidebar-toggle"
                    aria-label="<?= __('toggle_sidebar', 'Collapse sidebar') ?>"
                    title="<?= __('toggle_sidebar', 'Collapse sidebar') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="4" width="18" height="16" rx="2.5"/>
                    <line x1="9" y1="4" x2="9" y2="20"/>
                </svg>
            </button>
            <!-- Desktop: breadcrumb is relocated into the slot below (topbar
                 carries context). Mobile: the sidebar is hidden, so the topbar
                 shows the current PAGE TITLE here (and the content's big H1 is
                 hidden on mobile to avoid duplication). -->
            <div class="topbar-page-title-m"><?= e($pageTitle) ?></div>
            <!-- The page breadcrumb is relocated here on desktop (see footer JS),
                 filling the topbar and freeing vertical space in the content. -->
            <div class="topbar-bc-slot" id="topbar-bc-slot"></div>
            <div class="topbar-search-slot">
                <?php if ($topbarSearchHtml !== ''): ?>
                    <?= $topbarSearchHtml ?>
                <?php elseif (!empty($cmdItems)): ?>
                    <div class="topbar-cmd" data-cmd>
                        <div class="topbar-search">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                            <input type="text" class="topbar-cmd-input"
                                   placeholder="<?= __('search_jump_to', 'Search or jump to…') ?>"
                                   aria-label="<?= __('search_jump_to', 'Search or jump to…') ?>"
                                   autocomplete="off" spellcheck="false" data-cmd-input>
                            <kbd>⌘K</kbd>
                        </div>
                        <div class="topbar-cmd-menu" data-cmd-menu hidden></div>
                        <?php
                        // Resolve icon names → inline SVG now (helper is defined above this point).
                        $cmdData = array_map(static fn($it) => [
                            'label' => $it['label'],
                            'href'  => $it['href'],
                            'ico'   => slate_admin_nav_icon($it['icon']),
                        ], $cmdItems);
                        ?>
                        <script type="application/json" data-cmd-data><?= json_encode($cmdData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
                        <script>
                        (function () {
                            var root = document.querySelector('[data-cmd]'); if (!root) return;
                            var input = root.querySelector('[data-cmd-input]');
                            var menu  = root.querySelector('[data-cmd-menu]');
                            var dataEl = root.querySelector('[data-cmd-data]');
                            var items = []; try { items = JSON.parse(dataEl.textContent || '[]'); } catch (e) {}
                            var active = -1, shown = [];
                            function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
                            function render() {
                                var q = (input.value || '').trim().toLowerCase();
                                shown = items.filter(function (it) { return !q || it.label.toLowerCase().indexOf(q) !== -1; });
                                if (active >= shown.length) active = shown.length - 1;
                                if (!shown.length) { menu.innerHTML = '<div class="topbar-cmd-empty">No matches</div>'; menu.hidden = false; return; }
                                var html = '<div class="topbar-cmd-sect">Go to</div>';
                                shown.forEach(function (it, i) {
                                    html += '<a class="topbar-cmd-item' + (i === active ? ' is-active' : '') + '" href="' + esc(it.href) + '" data-i="' + i + '">' + it.ico + '<span class="lbl">' + esc(it.label) + '</span><span class="go">↵</span></a>';
                                });
                                menu.innerHTML = html; menu.hidden = false;
                            }
                            function close() { menu.hidden = true; active = -1; }
                            function move(d) { if (!shown.length) return; active = (active + d + shown.length) % shown.length; render(); }
                            function go() { var t = active >= 0 ? shown[active] : shown[0]; if (t) window.location.href = t.href; }
                            input.addEventListener('focus', function () { active = -1; render(); });
                            input.addEventListener('input', function () { active = -1; render(); });
                            input.addEventListener('keydown', function (e) {
                                if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
                                else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
                                else if (e.key === 'Enter') { if (!menu.hidden) { e.preventDefault(); go(); } }
                                else if (e.key === 'Escape') { close(); input.blur(); }
                            });
                            menu.addEventListener('mousemove', function (e) {
                                var it = e.target.closest('.topbar-cmd-item'); if (!it) return;
                                active = parseInt(it.getAttribute('data-i'), 10);
                                Array.prototype.forEach.call(menu.querySelectorAll('.topbar-cmd-item'), function (el) {
                                    el.classList.toggle('is-active', el === it);
                                });
                            });
                            document.addEventListener('click', function (e) { if (!root.contains(e.target)) close(); });
                            document.addEventListener('keydown', function (e) {
                                if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
                                    e.preventDefault(); input.focus(); input.select();
                                }
                            });
                        })();
                        </script>
                    </div>
                <?php endif; ?>
            </div>
            <div class="topbar-actions">
                <?php foreach ($topbarActions as $actionHtml): ?>
                    <?= $actionHtml ?>
                <?php endforeach; ?>

                <!-- Notifications -->
                <a href="<?= e(SLATE_URL) ?>/admin/notifications.php" class="topbar-icon-btn" data-notif-bell
                   aria-label="Notifications<?= $notifUnread > 0 ? ' (' . (int)$notifUnread . ' unread)' : '' ?>"
                   title="Notifications">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                    <?php if ($notifUnread > 0): ?>
                        <span class="topbar-badge" data-notif-badge aria-hidden="true"><?= $notifUnread > 99 ? '99+' : (int)$notifUnread ?></span>
                    <?php else: ?>
                        <span class="topbar-badge" data-notif-badge aria-hidden="true" hidden></span>
                    <?php endif; ?>
                </a>
                <script>
                (function () {
                    // Live-updates the bell badge and plays a short chime when new
                    // notifications land, without a page reload. Polls every 25s —
                    // cheap, and matches the "sound + live" request for every
                    // Notifications-list entry (booking, coaching chat, forms, etc.),
                    // not just chat. Added 2026-09-06.
                    var bell  = document.querySelector('[data-notif-bell]');
                    var badge = document.querySelector('[data-notif-badge]');
                    if (!bell || !badge) return;
                    var known = <?= (int)$notifUnread ?>;
                    var soundOn = true;
                    try { soundOn = localStorage.getItem('slate_notif_sound') !== '0'; } catch (e) {}
                    var chime = null;
                    function beep() {
                        if (!soundOn) return;
                        try {
                            if (!chime) {
                                var Ctx = window.AudioContext || window.webkitAudioContext;
                                if (!Ctx) return;
                                chime = new Ctx();
                            }
                            var ctx = chime;
                            var o = ctx.createOscillator(), g = ctx.createGain();
                            o.type = 'sine'; o.frequency.value = 880;
                            g.gain.setValueAtTime(0.0001, ctx.currentTime);
                            g.gain.exponentialRampToValueAtTime(0.18, ctx.currentTime + 0.01);
                            g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35);
                            o.connect(g); g.connect(ctx.destination);
                            o.start(); o.stop(ctx.currentTime + 0.36);
                        } catch (e) { /* audio unavailable — badge still updates */ }
                    }
                    function render(n) {
                        if (n > 0) { badge.hidden = false; badge.textContent = n > 99 ? '99+' : String(n); }
                        else { badge.hidden = true; badge.textContent = ''; }
                        bell.setAttribute('aria-label', n > 0 ? 'Notifications (' + n + ' unread)' : 'Notifications');
                    }
                    function poll() {
                        fetch('<?= e(SLATE_URL) ?>/admin/notifications-poll.php', { credentials: 'same-origin' })
                            .then(function (r) { return r.ok ? r.json() : null; })
                            .then(function (data) {
                                if (!data || typeof data.unread !== 'number') return;
                                if (data.unread > known) beep();
                                known = data.unread;
                                render(known);
                            })
                            .catch(function () { /* offline this tick — try again next interval */ });
                    }
                    setInterval(poll, 25000);
                    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
                })();
                </script>

                <!-- Quick settings -->
                <a href="<?= e(SLATE_URL) ?>/admin/settings.php" class="topbar-icon-btn topbar-gear" aria-label="Settings" title="Settings">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                </a>

                <!-- User menu -->
                <div class="topbar-menu" data-topbar-menu>
                    <button type="button" class="topbar-user-btn" data-topbar-toggle
                            aria-haspopup="true" aria-expanded="false">
                        <span class="topbar-user-name"><?= e($user['name'] ?? 'Account') ?></span>
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
                             class="topbar-user-caret"><polyline points="6 9 12 15 18 9"/></svg>
                        <span class="topbar-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['name'] ?? 'A', 0, 1))) ?><?php if ($userAvatarUrl !== ''): ?><img src="<?= e($userAvatarUrl) ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?></span>
                    </button>
                    <div class="topbar-pop topbar-pop-user" data-topbar-pop hidden role="menu">
                        <div class="topbar-pop-userhead">
                            <div class="topbar-pop-username"><?= e($user['name'] ?? '') ?></div>
                            <div class="topbar-pop-userrole"><?= e($user['role'] ?? __('administrator', 'Administrator')) ?></div>
                            <?php if (!empty($user['email'])): ?>
                                <div class="topbar-pop-useremail"><?= e($user['email']) ?></div>
                            <?php endif; ?>
                        </div>
                        <a href="<?= e(SLATE_URL) ?>/admin/settings.php" class="topbar-pop-item" role="menuitem">Settings</a>
                        <a href="<?= e(SLATE_URL) ?>/admin/logout.php?csrf=<?= e(csrf_token()) ?>" class="topbar-pop-item topbar-pop-item-danger" role="menuitem">Log out</a>
                    </div>
                </div>
            </div>
        </header>

        <!-- ─── Main content ─────────────────────────────── -->
        <main class="content" id="content">
        <?php if (isset($_SESSION['slate_override_tenant'])):
            // Phase 1E B2 — visible for the whole duration of a platform
            // admin's tenant-switch. Deliberately reads the SESSION KEY
            // directly (not current_tenant_id()) so this can never render
            // for anyone who didn't go through admin/tenants.php's "Enter
            // Tenant" action, regardless of what current_tenant_id() itself
            // resolves to for other reasons (CLI override, etc).
            $_platformModeTenant = Database::row("SELECT name FROM tenants WHERE id = ?", [(int) $_SESSION['slate_override_tenant']]);
        ?>
            <div class="alert alert-warning" role="status" style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px;">
                <span>
                    <strong><?= __('platform_mode', 'PLATFORM MODE') ?></strong>
                    — <?= __('viewing', 'Viewing') ?>: <?= e($_platformModeTenant['name'] ?? ('#' . (int) $_SESSION['slate_override_tenant'])) ?>
                </span>
                <form method="post" action="<?= e(SLATE_URL) ?>/admin/exit-tenant.php" style="margin:0;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm"><?= __('exit_tenant', 'Exit Tenant') ?></button>
                </form>
            </div>
        <?php unset($_platformModeTenant); endif; ?>