<?php
/**
 * Slate — Headless & Mobile API Gateway (/api/v1/).
 *
 * Provides a standardized RESTful API surface for headless frontends
 * and mobile applications (iOS / Android) with real-time delta sync,
 * Bearer token authentication, and modular route discovery.
 */

declare(strict_types=1);

namespace Slate\Kernel\Http;

use Database;
use Hook;
use Throwable;

class ApiRouter {

    public static function handle(): void {
        // Send JSON headers and CORS for mobile and web clients
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Tenant-ID, X-Requested-With');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        $routePath = trim((string)($_GET['_route_path'] ?? ''), '/');

        // Resolve auth context if Authorization header is present
        $auth = self::resolveAuth();

        // API Root: /api/v1
        if ($routePath === '') {
            $modules = Hook::applyFilters('api_v1_modules', ['booking']);
            self::respondSuccess([
                'name'      => 'Kohevo Headless & Mobile API',
                'version'   => '1.0',
                'timestamp' => slate_db_now(),
                'modules'   => is_array($modules) ? array_values($modules) : [],
            ]);
            return;
        }

        $parts = explode('/', $routePath, 2);
        $module = $parts[0];
        $subPath = $parts[1] ?? '';

        $routes = Hook::applyFilters('api_v1_routes', []);
        if (!is_array($routes) || !isset($routes[$module])) {
            self::respondError("Module '{$module}' does not exist or has no active API endpoints.", 'NOT_FOUND', 404);
            return;
        }

        $target = $routes[$module];
        try {
            if (is_callable($target)) {
                $target($subPath, $method, $auth);
            } elseif (is_array($target) && isset($target['handler']) && is_callable($target['handler'])) {
                $target['handler']($subPath, $method, $auth);
            } elseif (is_string($target) && class_exists($target) && method_exists($target, 'handle')) {
                $target::handle($subPath, $method, $auth);
            } else {
                self::respondError("Invalid handler for module '{$module}'.", 'HANDLER_ERROR', 500);
            }
        } catch (Throwable $e) {
            slate_log("API exception on /api/v1/{$routePath}: " . $e->getMessage(), 'error');
            self::respondError($e->getMessage(), 'INTERNAL_ERROR', 500);
        }
    }

    public static function resolveAuth(): array {
        $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        $token = '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            $token = trim($m[1]);
        }

        if ($token === '') {
            return ['authenticated' => false, 'type' => 'guest', 'tenant_id' => current_tenant_id()];
        }

        // Extensible token authentication via Hook filters
        $customAuth = Hook::applyFilters('api_v1_authenticate', null, $token);
        if (is_array($customAuth) && !empty($customAuth['authenticated'])) {
            return $customAuth;
        }

        return ['authenticated' => false, 'type' => 'invalid_token', 'tenant_id' => current_tenant_id()];
    }

    public static function respondSuccess(mixed $data, array $meta = [], int $status = 200): void {
        http_response_code($status);
        $payload = [
            'success' => true,
            'data'    => $data,
            'meta'    => array_merge([
                'timestamp' => slate_db_now(),
                'tenant_id' => current_tenant_id(),
            ], $meta),
        ];
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    public static function respondError(string $message, string $code = 'ERROR', int $status = 400, array $details = []): void {
        http_response_code($status);
        $payload = [
            'success' => false,
            'error'   => [
                'code'    => $code,
                'message' => $message,
                'details' => $details,
            ],
            'meta'    => [
                'timestamp' => slate_db_now(),
                'tenant_id' => current_tenant_id(),
            ],
        ];
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
