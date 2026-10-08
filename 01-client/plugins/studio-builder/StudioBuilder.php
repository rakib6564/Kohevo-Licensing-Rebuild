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
        \Hook::addFilter('i18n_lang_paths', [$this, 'addLangPath']);

        // MCP AI Gateway integration (Phase 7): Studio scopes + tools for AI
        // assistants. Registered unconditionally, like the other modules; the
        // module guard and the application layer refuse unlicensed tenants.
        require_once __DIR__ . '/StudioBuilderMcpHandler.php';
        StudioBuilderMcpHandler::register();

        // Custom Portfolio Widgets
        require_once __DIR__ . '/StudioBuilderPortfolioWidgets.php';
        \Slate\Module\StudioBuilder\StudioBuilderPortfolioWidgets::register();
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

        $studioNav = [
            ['slug' => 'studio-overview',        'label' => function_exists('__') ? __('studio_overview', 'Overview') : 'Overview',                  'file' => 'admin/index.php',           'icon' => 'bar-chart-2', 'order' => 200],
            ['slug' => 'studio-pages',           'label' => function_exists('__') ? __('studio_pages', 'Pages') : 'Pages',                            'file' => 'admin/pages.php',           'icon' => 'file-text',   'order' => 201],
            ['slug' => 'studio-content',         'label' => function_exists('__') ? __('studio_content', 'Content') : 'Content',                      'file' => 'admin/content.php',         'icon' => 'box',         'order' => 202],
            ['slug' => 'studio-media',           'label' => function_exists('__') ? __('studio_media', 'Media') : 'Media',                            'file' => 'admin/media.php',           'icon' => 'image',       'order' => 203],
            ['slug' => 'studio-templates',       'label' => function_exists('__') ? __('studio_templates', 'Templates') : 'Templates',              'file' => 'admin/templates.php',       'icon' => 'layout',      'order' => 204],
            ['slug' => 'studio-types-fields',    'label' => function_exists('__') ? __('studio_types_fields', 'Types & fields') : 'Types & fields',  'file' => 'admin/types-fields.php',    'icon' => 'tag',         'order' => 205],
            ['slug' => 'studio-themes',          'label' => function_exists('__') ? __('studio_themes', 'Themes') : 'Themes',                        'file' => 'admin/themes.php',          'icon' => 'palette',     'order' => 206],
            ['slug' => 'studio-seo',             'label' => function_exists('__') ? __('studio_seo', 'Site & SEO') : 'Site & SEO',                    'file' => 'admin/seo.php',             'icon' => 'globe',       'order' => 207],
            ['slug' => 'studio-global-settings', 'label' => function_exists('__') ? __('studio_global_settings', 'Global settings') : 'Global settings', 'file' => 'admin/global-settings.php', 'icon' => 'sliders',     'order' => 208],
            ['slug' => 'studio-ai-mcp',          'label' => function_exists('__') ? __('studio_ai_mcp', 'AI & MCP') : 'AI & MCP',                      'file' => 'admin/ai-mcp.php',          'icon' => 'sparkles',    'order' => 209],
            ['slug' => 'studio-reviews',         'label' => function_exists('__') ? __('studio_reviews', 'Reviews') : 'Reviews',                      'file' => 'admin/reviews.php',         'icon' => 'clock',       'order' => 210],
            ['slug' => 'studio-code-tracking',   'label' => function_exists('__') ? __('studio_code_tracking', 'Code & tracking') : 'Code & tracking','file' => 'admin/code-tracking.php',   'icon' => 'edit-3',      'order' => 211],
            ['slug' => 'studio-redirects',       'label' => function_exists('__') ? __('studio_redirects', 'Redirects') : 'Redirects',              'file' => 'admin/redirects.php',       'icon' => 'link',        'order' => 212],
        ];

        foreach ($studioNav as $nav) {
            $items[] = [
                'slug'  => $nav['slug'],
                'label' => $nav['label'],
                'href'  => $this->url($nav['file']),
                'icon'  => $nav['icon'],
                'perm'  => 'studio-builder.view',
                'order' => $nav['order'],
                'group' => 'studio',
            ];
        }

        return $items;
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
        return self::send(self::publicResponse(static fn(?string $ifNoneMatch) => $runtime->handlePath((string) $path, $ifNoneMatch)));
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
