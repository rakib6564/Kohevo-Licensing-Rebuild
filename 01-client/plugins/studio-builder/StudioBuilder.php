<?php
/**
 * Kohevo Studio (studio-builder) — Plugin Bootstrap.
 *
 * Phase 1 Foundation:
 * - Verifies and self-heals the `studiobuilder_*` schema via StudioSchemaManager,
 *   using `plugins/studio-builder/install.sql` as the single baseline DDL authority.
 * - Provides the commercial entitlement check (`studio-builder`) via ModuleGuard.
 *
 * Phase 4 Public Runtime:
 * - Registers Studio as the PublicRouter's LAST-RESORT handler
 *   (`public_fallback`) — only for paths no plugin prefix claimed — and as the
 *   optional published-homepage handler (`public_homepage`). Both answer
 *   "not mine" (false) for anything that is not a published Studio page of the
 *   current tenant, so the platform's ordinary 404 / landing page render
 *   unchanged. Both hooks exist only while this plugin is active and booted.
 *
 * Phase 5 Builder Shell:
 * - Adds the "Studio pages" admin nav entry (entitled installs, studio-builder.view)
 *   leading to `admin/index.php` (page list) and `admin/builder.php` (the React
 *   builder shell, backed by `admin/api.php` + `admin/canvas.php`).
 *
 * Phase 7 AI + MCP:
 * - Registers the Studio MCP scopes and tools on the MCP gateway's extension
 *   points through `StudioBuilderMcpHandler` (same pattern as every other
 *   commercial module). Every tool call goes through the same application
 *   boundary as the Builder; no tool publishes.
 *
 * Phase 9A public locale:
 * - A served Studio page pins the tenant's site language (StudioPublicLocale)
 *   for the rest of the request, so filters that run after the render (e.g.
 *   the multilang-translate output buffer) see the same locale as the page,
 *   not the visitor's ?lang= / session / force-locale header.
 *
 * Phase 9B ETag correctness:
 * - The Studio ETag (and any 304) is used only when no content-rewriting
 *   output buffer sits above Studio (publicResponse()), so an ETag always
 *   describes the body the client actually receives.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Infrastructure\StudioSchemaManager;
use Slate\Module\StudioBuilder\Runtime\PublicResponse;
use Slate\Module\StudioBuilder\Runtime\StudioHttpResponder;
use Slate\Module\StudioBuilder\Runtime\StudioPublicLocale;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;

class StudioBuilder extends Plugin
{
    public const SLUG = 'studio-builder';
    public const ENTITLEMENT = 'studio-builder';

    public function boot(): void
    {
        StudioSchemaManager::ensureVerified($this);

        \Hook::addFilter('public_fallback', [self::class, 'servePublicPath'], 10, 2);
        \Hook::addFilter('public_homepage', [self::class, 'servePublicHomepage'], 10, 1);
        \Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);
        \Hook::addAction('admin_head', [$this, 'adminHead']);
        \Hook::addFilter('admin_topbar_actions', [$this, 'addTopbarActions']);
        \Hook::addFilter('front_admin_bar_items', [$this, 'addFrontBarItems'], 10, 2);
        \Hook::addFilter('i18n_lang_paths', [$this, 'addLangPath']);
        // The media library will not delete a file a Studio page still uses.
        // Registered once even if the plugin boots again: a second copy would count every use twice.
        $mediaUsage = [\Slate\Module\StudioBuilder\Dependency\StudioMediaUsage::class, 'filter'];
        \Hook::removeFilter('media_usage', $mediaUsage);
        \Hook::addFilter('media_usage', $mediaUsage);

        // MCP AI Gateway integration (Phase 7): Studio scopes + tools for AI
        // assistants. Registered unconditionally, like the other modules; the
        // module guard and the application layer refuse unlicensed tenants.
        require_once __DIR__ . '/StudioBuilderMcpHandler.php';
        StudioBuilderMcpHandler::register();

    }

    /**
     * @param array<string, array<int, string>> $paths
     * @return array<string, array<int, string>>
     */
    public function addLangPath(array $paths): array
    {
        foreach (['fr', 'en'] as $loc) {
            $paths[$loc][] = $this->dir('lang');
        }
        return $paths;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public function addAdminNav(array $items): array
    {
        if (!self::isEntitled()) {
            return $items;
        }
        $order = 200;
        foreach (self::navItems() as $nav) {
            $items[] = [
                'slug'    => $nav['slug'],
                'label'   => $nav['label'],
                'href'    => $this->url($nav['file']),
                'icon'    => $nav['icon'],
                'perm'    => $nav['perm'] ?? 'studio-builder.view',
                'order'   => $order++,
                'group'   => 'studio',
                'section' => $nav['section'],
            ];
        }
        return $items;
    }

    /** Whether this request is one of the Studio admin screens (they all render the platform admin layout). */
    public static function onStudioScreen(): bool
    {
        $script = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        return str_contains($script, '/plugins/studio-builder/admin/');
    }

    /**
     * `admin_head`: the Studio admin stylesheet, and the viewer's density choice applied before the first paint
     * (Compact is the default; Comfortable is remembered in this browser).
     */
    public function adminHead(): void
    {
        if (!self::onStudioScreen()) {
            return;
        }
        $css = $this->dir('assets/admin/studio-admin.css');
        $version = is_file($css) ? (string) filemtime($css) : '1';
        echo '<link rel="stylesheet" href="' . htmlspecialchars($this->url('assets/admin/studio-admin.css') . '?v=' . $version, ENT_QUOTES) . '">';
        echo "<script>try{if(localStorage.getItem('sb-density')==='comfortable')document.documentElement.dataset.sbDensity='comfortable'}catch(e){}</script>";
    }

    /**
     * `admin_topbar_actions`: on a Studio screen, open the builder (on the homepage, else the first page), view the site,
     * and switch between Compact and Comfortable rows.
     *
     * @param array<int, string> $actions
     * @return array<int, string>
     */
    public function addTopbarActions(mixed $actions): array
    {
        $actions = is_array($actions) ? $actions : [];
        if (!self::onStudioScreen() || !self::isEntitled()) {
            return $actions;
        }
        $t = static fn (string $key, string $default): string => function_exists('__') ? __($key, $default) : $default;
        $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES);
        $icon = static fn (string $paths): string => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';

        $pageId = self::builderPageId();
        $builder = $pageId !== null ? $this->url('admin/builder.php') . '?page=' . $pageId : $this->url('admin/pages.php');
        $open = $t('studio_nav_open_builder', 'Open builder');
        $view = $t('studio_nav_view_site', 'View site');
        $density = $t('studio_density_toggle', 'Compact or comfortable rows');
        $base = defined('SLATE_URL') ? rtrim((string) SLATE_URL, '/') . '/' : '/';

        $actions[] = '<a href="' . $esc($builder) . '" class="topbar-icon-btn sb-topbar-action" title="' . $esc($open) . '" aria-label="' . $esc($open) . '"' . ($pageId !== null ? ' target="_blank" rel="noopener"' : '') . '>'
            . $icon('<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>') . '</a>';
        $actions[] = '<a href="' . $esc($base) . '" class="topbar-icon-btn sb-topbar-action" title="' . $esc($view) . '" aria-label="' . $esc($view) . '" target="_blank" rel="noopener">'
            . $icon('<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>') . '</a>';
        $actions[] = '<button type="button" class="topbar-icon-btn sb-topbar-action" data-sb-density-toggle title="' . $esc($density) . '" aria-label="' . $esc($density) . '"'
            . ' onclick="var r=document.documentElement,c=r.dataset.sbDensity===\'comfortable\';if(c){delete r.dataset.sbDensity}else{r.dataset.sbDensity=\'comfortable\'}try{localStorage.setItem(\'sb-density\',c?\'compact\':\'comfortable\')}catch(e){}">'
            . $icon('<line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/>') . '</button>';
        return $actions;
    }

    /**
     * `front_admin_bar_items`: Edit for the page being viewed, Pages, and New page.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public function addFrontBarItems(array $items, mixed $path = ''): array
    {
        if (!self::isEntitled()) {
            return $items;
        }
        $t = static fn (string $key, string $default): string => function_exists('__') ? __($key, $default) : $default;
        if (\Auth::can('studio-builder.edit')) {
            $pageId = StudioRuntimeFactory::build()->publicRuntime->publishedPageIdAt((string) $path);
            if ($pageId !== null) {
                $items[] = ['id' => 'edit-page', 'label' => $t('studio_bar_edit_page', 'Edit page'), 'href' => $this->url('admin/builder.php') . '?page=' . $pageId,
                    'perm' => 'studio-builder.edit', 'order' => 10, 'menu' => 'bar', 'primary' => true];
            }
        }
        $items[] = ['id' => 'pages', 'label' => $t('studio_nav_pages', 'Pages'), 'href' => $this->url('admin/pages.php'), 'perm' => 'studio-builder.view', 'order' => 20, 'menu' => 'bar'];
        $items[] = ['id' => 'new-page', 'label' => $t('studio_bar_new_page', 'Page'), 'href' => $this->url('admin/page-new.php'), 'perm' => 'studio-builder.edit', 'order' => 10, 'menu' => 'new'];
        return $items;
    }

    /** The page "Open builder" opens: the homepage, else the first live page, else the first page; null with none. */
    public static function builderPageId(): ?int
    {
        try {
            $found = \Database::value(
                "SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND page_type IN ('page','landing') AND status <> 'archived' ORDER BY (route_mode = 'homepage') DESC, (status = 'published') DESC, id ASC LIMIT 1",
                [function_exists('current_tenant_id') ? current_tenant_id() : 0]
            );
            return $found ? (int) $found : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The Studio sidebar, in order: Home, then Content, Site, Search and Advanced, each under a small label. The one list
     * of Studio admin screens (each screen's `$currentNav` is its `slug`). Media is the platform's Media Library.
     *
     * @return list<array{slug: string, label: string, file: string, icon: string, section: string, perm?: string}>
     */
    public static function navItems(): array
    {
        $t = static fn (string $key, string $default): string => function_exists('__') ? __($key, $default) : $default;
        $content  = $t('studio_nav_section_content', 'Content');
        $site     = $t('studio_nav_section_site', 'Site');
        $search   = $t('studio_nav_section_search', 'Search');
        $advanced = $t('studio_nav_section_advanced', 'Advanced');

        $items = [
            ['slug' => 'studio-overview', 'label' => $t('studio_nav_home', 'Home'), 'file' => 'admin/index.php', 'icon' => 'home', 'section' => ''],
            ['slug' => 'studio-pages', 'label' => $t('studio_nav_pages', 'Pages'), 'file' => 'admin/pages.php', 'icon' => 'file-text', 'section' => $content],
        ];
        // Each content type the site made has its own list, right under Pages (Content types › Show in the sidebar).
        try {
            $tenantId = function_exists('current_tenant_id') ? current_tenant_id() : 0;
            foreach (\Slate\Module\StudioBuilder\Meta\PageMeta::postTypes($tenantId) as $key => $type) {
                if (\Slate\Module\StudioBuilder\Meta\PageMeta::showsInMenu($type)) {
                    $items[] = ['slug' => 'studio-type-' . $key, 'label' => (string) ($type['plural'] ?? $type['singular'] ?? $key), 'file' => 'admin/pages.php?type=' . rawurlencode((string) $key), 'icon' => 'folder', 'section' => $content];
                }
            }
        } catch (\Throwable $e) {
            // No settings table yet (install): the fixed entries only.
        }
        return array_merge($items, [
            ['slug' => 'studio-templates', 'label' => $t('studio_nav_templates', 'Templates'), 'file' => 'admin/templates.php', 'icon' => 'layout', 'section' => $site],
            ['slug' => 'studio-menus', 'label' => $t('studio_nav_menus', 'Menus'), 'file' => 'admin/menus.php', 'icon' => 'list', 'section' => $site],
            // Colours, fonts, widths and custom CSS are edited in the builder (Settings › Site); this opens it there.
            ['slug' => 'studio-design', 'label' => $t('studio_nav_design', 'Design'), 'file' => 'admin/themes.php', 'icon' => 'palette', 'section' => $site],
            ['slug' => 'studio-seo', 'label' => $t('studio_nav_seo', 'SEO'), 'file' => 'admin/seo.php', 'icon' => 'globe', 'section' => $search],
            ['slug' => 'studio-redirects', 'label' => $t('studio_nav_redirects', 'Redirects'), 'file' => 'admin/redirects.php', 'icon' => 'link', 'section' => $search],
            // Scripts run on every public page: Studio administrators only, as on the screen itself.
            ['slug' => 'studio-code-tracking', 'label' => $t('studio_nav_tracking', 'Tracking & code'), 'file' => 'admin/code-tracking.php', 'icon' => 'edit-3', 'section' => $search, 'perm' => 'studio-builder.admin'],
            ['slug' => 'studio-types-fields', 'label' => $t('studio_nav_content_types', 'Content types'), 'file' => 'admin/types-fields.php', 'icon' => 'tag', 'section' => $advanced],
            // A backup holds every draft and the site's scripts: Studio administrators only.
            ['slug' => 'studio-backup', 'label' => $t('studio_nav_backup', 'Backup'), 'file' => 'admin/backup.php', 'icon' => 'package', 'section' => $advanced, 'perm' => 'studio-builder.admin'],
            ['slug' => 'studio-reviews', 'label' => $t('studio_nav_history', 'History'), 'file' => 'admin/reviews.php', 'icon' => 'clock', 'section' => $advanced],
            ['slug' => 'studio-ai-mcp', 'label' => $t('studio_nav_ai_mcp', 'AI & MCP'), 'file' => 'admin/ai-mcp.php', 'icon' => 'sparkles', 'section' => $advanced],
        ]);
    }

    /**
     * Pure, non-bypassed check whether the current installation is entitled and active for Kohevo Studio.
     */
    public static function isEntitled(): bool
    {
        return \ModuleGuard::isEntitled(self::ENTITLEMENT);
    }

    /**
     * Bypass-aware runtime guard check for background/listener entry points.
     */
    public static function allows(): bool
    {
        return \ModuleGuard::allows(self::ENTITLEMENT);
    }

    /**
     * `public_fallback` filter: serve a published Studio page for an otherwise
     * unrouted path. Returns true only when a response was sent.
     */
    public static function servePublicPath(mixed $handled, mixed $path = ''): bool
    {
        if ($handled === true) {
            return true;
        }
        if (!self::isReadRequest()) {
            return false;
        }
        $runtime = StudioRuntimeFactory::build()->publicRuntime;
        // A browser is shown the sitemap as a readable page; `?format=xml` (the page's own link) always returns the XML.
        $accept = isset($_SERVER['HTTP_ACCEPT']) && is_string($_SERVER['HTTP_ACCEPT']) && ($_GET['format'] ?? '') !== 'xml'
            ? substr($_SERVER['HTTP_ACCEPT'], 0, 400) : null;
        $request = self::withSearchQuery((string) $path);
        $response = self::publicResponse(static fn(?string $ifNoneMatch) => $runtime->handlePath($request, $ifNoneMatch, $accept))
            // An address nothing serves gets the published 404 template, when there is one, with status 404.
            ?? self::publicResponse(static fn(?string $ifNoneMatch) => $runtime->handleNotFound());
        return self::send($response);
    }

    /**
     * `/search` with the visitor's query attached: the router strips `?q=` before the path reaches Studio,
     * so the search template would otherwise never learn what was searched for. `s` is accepted too.
     */
    public static function withSearchQuery(string $path): string
    {
        if (trim($path, '/') !== 'search') {
            return $path;
        }
        $raw = $_GET['q'] ?? $_GET['s'] ?? '';
        $query = is_string($raw) ? trim(mb_substr($raw, 0, 191)) : '';
        return $query === '' ? $path : 'search?q=' . rawurlencode($query);
    }

    /**
     * `public_homepage` filter: serve the tenant's published Studio homepage
     * (route_mode = homepage) at `/`. Returns false — leaving the existing
     * landing page untouched — whenever no such page is published.
     */
    public static function servePublicHomepage(mixed $handled): bool
    {
        if ($handled === true) {
            return true;
        }
        if (!self::isReadRequest()) {
            return false;
        }
        $runtime = StudioRuntimeFactory::build()->publicRuntime;
        return self::send(self::publicResponse(static fn(?string $ifNoneMatch) => $runtime->handleHomepage($ifNoneMatch)));
    }

    /**
     * The public response exactly as it will be sent (Phase 9B). The ETag is a
     * hash of the HTML Studio renders, so it is only published — and a 304
     * only ever answered — when that HTML is the client-visible body: no
     * content-rewriting output buffer (e.g. multilang-translate's) is active
     * above this handler. Otherwise the page is always sent in full (200)
     * without an ETag. Public for tests; `$serve` receives the If-None-Match
     * value to honour (null = none).
     *
     * @param \Closure(?string): ?PublicResponse $serve
     */
    public static function publicResponse(\Closure $serve): ?PublicResponse
    {
        $final = StudioHttpResponder::bodyIsFinal(ob_list_handlers());
        $response = $serve($final ? self::ifNoneMatch() : null);
        return ($response !== null && !$final) ? $response->withoutValidator() : $response;
    }

    private static function send(?PublicResponse $response): bool
    {
        if ($response === null) {
            return false;
        }
        StudioPublicLocale::pin();
        StudioHttpResponder::sendPublic($response);
        return true;
    }

    /** Studio pages are read-only documents: any other method keeps the platform's existing behavior. */
    private static function isReadRequest(): bool
    {
        return in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true);
    }

    private static function ifNoneMatch(): ?string
    {
        $value = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
        return is_string($value) && strlen($value) <= 512 ? $value : null;
    }
}
