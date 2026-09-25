<?php
/**
 * Slate — Development Router for PHP Built-in Web Server.
 *
 * Usage:
 *   php -S 127.0.0.1:8000 dev-server.php
 *
 * Emulates Apache .htaccess rewrite behavior:
 *   1. Direct static file serving (CSS, JS, images, fonts).
 *   2. Direct PHP script serving (/admin/login.php, etc.).
 *   3. Directory index resolution (/admin/ -> /admin/index.php).
 *   4. Legacy /shop/* route handling.
 *   5. Fallback delegation to public.php for PublicRouter paths.
 */

$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = urldecode((string)$uri);

// Block directory traversal
if (str_contains($path, '..')) {
    http_response_code(400);
    echo 'Bad Request';
    exit;
}

$file = __DIR__ . $path;

// Block sensitive system and configuration files
if (preg_match('#/(?:\.env|\.git|includes/|db/|bin/|tests/|docs/|uploads/_plugin_staging)#', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// Serve directory index
if (is_dir($file)) {
    $indexPath = rtrim($file, '/') . '/index.php';
    if (file_exists($indexPath)) {
        $_SERVER['SCRIPT_FILENAME'] = $indexPath;
        require $indexPath;
        exit;
    }
}

// Direct file access
if (is_file($file)) {
    if (str_ends_with($file, '.php')) {
        $_SERVER['SCRIPT_FILENAME'] = $file;
        require $file;
        exit;
    }
    // Let PHP built-in server serve static assets with built-in MIME handling
    return false;
}

// Legacy shop storefront routing
if ($path === '/shop' || str_starts_with($path, '/shop/')) {
    $_GET['_path'] = ltrim(substr($path, 5), '/');
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/plugins/shop/storefront/router.php';
    require __DIR__ . '/plugins/shop/storefront/router.php';
    exit;
}

// Fallback to PublicRouter
$_GET['_path'] = ltrim($path, '/');
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public.php';
require __DIR__ . '/public.php';
