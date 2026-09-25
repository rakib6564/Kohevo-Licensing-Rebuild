<?php
/**
 * 500 handler. The original request already fatal-ed, so DON'T load full
 * config (PluginLoader::boot() could re-trigger the same failure). Instead do
 * a minimal, plugin-free bootstrap — just enough (.env + Database) for the
 * error page to show branding. Every step is best-effort; on any failure the
 * page falls back to an accent gradient.
 */
try {
    if (!defined('SLATE_ROOT')) define('SLATE_ROOT', __DIR__);
    if (is_file(SLATE_ROOT . '/.env')) {
        foreach (file(SLATE_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $_ENV[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
        }
    }
    if (!defined('SLATE_URL'))  define('SLATE_URL',  rtrim($_ENV['APP_URL'] ?? '', '/'));
    if (!defined('DB_HOST'))    define('DB_HOST',    $_ENV['DB_HOST']    ?? 'localhost');
    if (!defined('DB_NAME'))    define('DB_NAME',    $_ENV['DB_NAME']    ?? '');
    if (!defined('DB_USER'))    define('DB_USER',    $_ENV['DB_USER']    ?? '');
    if (!defined('DB_PASS'))    define('DB_PASS',    $_ENV['DB_PASS']    ?? '');
    if (!defined('DB_CHARSET')) define('DB_CHARSET', $_ENV['DB_CHARSET'] ?? 'utf8mb4');
    require_once SLATE_ROOT . '/includes/helpers.php';
    require_once SLATE_ROOT . '/includes/Database.php';
    // Phase 4 (Kohevo system/error identity): registers Slate\* class loading
    // only (see the autoloader's own docblock — additive, no side effects, no
    // plugin code) so error_page.php can render PlatformSignature even on this
    // deliberately plugin-free path. Does not load config.php/PluginLoader,
    // so it cannot reintroduce the failure this minimal bootstrap exists to avoid.
    require_once SLATE_ROOT . '/src/autoload.php';
} catch (\Throwable $e) {
    // Gradient fallback — the renderer guards every DB read.
}
require_once __DIR__ . '/includes/error_page.php';
slate_render_error(500, 'Something went wrong', 'A server error stopped this page from loading. Please try again in a moment.');
