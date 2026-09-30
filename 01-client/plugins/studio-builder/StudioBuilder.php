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
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Infrastructure\StudioSchemaManager;
use Slate\Module\StudioBuilder\Runtime\StudioHttpResponder;
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

        // MCP AI Gateway integration (Phase 7): Studio scopes + tools for AI
        // assistants. Registered unconditionally, like the other modules; the
        // module guard and the application layer refuse unlicensed tenants.
        require_once __DIR__ . '/StudioBuilderMcpHandler.php';
        StudioBuilderMcpHandler::register();
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
        $items[] = [
            'slug'  => 'studio-builder',
            'label' => function_exists('__') ? __('studio_pages', 'Studio pages') : 'Studio pages',
            'href'  => $this->url('admin/index.php'),
            'icon'  => 'layout',
            'perm'  => 'studio-builder.view',
            'order' => 205,
            'group' => 'content',
        ];
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
        $response = StudioRuntimeFactory::build()->publicRuntime->handlePath((string) $path, self::ifNoneMatch());
        if ($response === null) {
            return false;
        }
        StudioHttpResponder::sendPublic($response);
        return true;
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
        $response = StudioRuntimeFactory::build()->publicRuntime->handleHomepage(self::ifNoneMatch());
        if ($response === null) {
            return false;
        }
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
