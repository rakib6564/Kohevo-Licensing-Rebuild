<?php
/**
 * Slate — public router.
 *
 * Maps clean public URLs (e.g. /forms/<slug>, /book/...) to plugin
 * handlers. Plugins register their public prefixes via the
 * `public_routes` filter.
 *
 * Slate is otherwise a direct-file-routing app. The public router
 * is opt-in: anything actually present on disk is still served by
 * Apache directly (admin/, customer/, plugins/<slug>/storefront/
 * for legacy plugins like Shop). Only paths that don't resolve to
 * a file fall through to public.php, which delegates here.
 *
 * Route registration (in a plugin's boot()):
 *
 *   Hook::addFilter('public_routes', function (array $routes): array {
 *       $routes['forms'] = [
 *           'handler' => plugin_dir('forms') . '/public/router.php',
 *           // Optional: 'methods' => ['GET', 'POST']
 *       ];
 *       return $routes;
 *   });
 *
 * The handler receives:
 *   - $_GET['_route_prefix']  — the matched prefix (e.g. "forms")
 *   - $_GET['_route_path']    — the remainder (e.g. "contact-us")
 *
 * The longest-prefix-match wins, so a plugin registering "forms/admin"
 * could intercept "forms/admin/foo" without breaking "forms/<slug>".
 */

declare(strict_types=1);

namespace Slate\Kernel\Http;

// Phase 1 A3: migrated from includes/PublicRouter.php into Slate\Kernel\Http.
// The global name `PublicRouter` is provided by a class_alias in
// src/compat/aliases.php. Behavior is identical to the pre-move class.

class PublicRouter {

    /**
     * Dispatch the public path. Renders a 404 if no plugin claims
     * the prefix.
     */
    public static function dispatch(string $path): void {
        $path = trim($path, '/');

        // Defensive: reject anything that tries to climb out of the
        // public URL space. The handler is required to be an absolute
        // file path so a malicious path can't traverse into it, but
        // strip ".." segments anyway.
        $path = preg_replace('#(^|/)\.\.(/|$)#', '/', (string)$path);
        $path = ltrim((string)$path, '/');

        $routes = \Hook::applyFilters('public_routes', []);
        if (!is_array($routes)) $routes = [];

        // Built-in headless & mobile API gateway
        if (!isset($routes['api/v1']) && defined('SLATE_ROOT') && file_exists(SLATE_ROOT . '/api/v1.php')) {
            $routes['api/v1'] = [
                'handler' => SLATE_ROOT . '/api/v1.php',
                'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            ];
        }

        // Built-in Customer Portal gateway (/member, /member/...)
        if (!isset($routes['member']) && defined('SLATE_ROOT') && file_exists(SLATE_ROOT . '/customer/portal_router.php')) {
            $routes['member'] = [
                'handler' => SLATE_ROOT . '/customer/portal_router.php',
                'methods' => ['GET', 'POST'],
            ];
        }

        $best = null; // [prefix, route]
        foreach ($routes as $prefix => $route) {
            $prefix = trim((string)$prefix, '/');
            if ($prefix === '' || !is_array($route)) continue;
            if ($path === $prefix || str_starts_with($path . '/', $prefix . '/')) {
                if ($best === null || strlen($prefix) > strlen($best[0])) {
                    $best = [$prefix, $route];
                }
            }
        }

        if ($best === null) {
            self::renderNotFound($path);
            return;
        }

        [$prefix, $route] = $best;
        $handler = (string)($route['handler'] ?? '');
        if ($handler === '' || !is_file($handler)) {
            slate_log("PublicRouter: prefix '{$prefix}' is registered but handler is missing: {$handler}", 'error');
            self::renderNotFound($path);
            return;
        }

        // Method gate (optional)
        if (!empty($route['methods']) && is_array($route['methods'])) {
            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            $allowed = array_map('strtoupper', $route['methods']);
            if (!in_array($method, $allowed, true)) {
                http_response_code(405);
                header('Allow: ' . implode(', ', $allowed));
                echo '405 Method Not Allowed';
                return;
            }
        }

        $remainder = substr($path, strlen($prefix));
        $remainder = ltrim($remainder, '/');

        $_GET['_route_prefix'] = $prefix;
        $_GET['_route_path']   = $remainder;

        require $handler;
    }

    /**
     * The list of public prefixes Slate knows about. Useful for
     * plugins that want to surface a list of public links in the
     * admin (e.g. "here are your public URLs").
     */
    public static function knownPrefixes(): array {
        $routes = \Hook::applyFilters('public_routes', []);
        return is_array($routes) ? array_keys($routes) : [];
    }

    private static function renderNotFound(string $path): void {
        // Branded error page (hero image + glass card). config.php is already
        // loaded here (public.php requires it), so branding is available.
        require_once SLATE_ROOT . '/includes/error_page.php';
        slate_render_error(404, 'Page not found', "We couldn't find the page you were looking for.");
    }
}
