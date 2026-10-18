<?php
/**
 * Router for PHP's built-in web server (SAPI "cli-server", i.e. not CLI),
 * used by AppEnvTestingBypassTest to check that APP_ENV=testing does not
 * bypass the licence guard or ModuleGuard on a request served by a web
 * server. Never deployed: tests/ is denied to the web (tests/.htaccess),
 * and this only answers on the throw-away server the test starts.
 */
if (($_GET['mode'] ?? '') === 'bypass') {
    // ModuleGuard's own bypass rule, without config.php: the full bootstrap
    // runs the licence guard first, which answers 403 on a test database
    // without a licence, so ModuleGuard would never be reached. Only the
    // autoloader and the same env() reader config.php defines are loaded.
    if (!function_exists('env')) {
        function env(string $key, $default = '') {
            if (isset($_ENV[$key])) return $_ENV[$key];
            $v = getenv($key);
            return ($v !== false) ? $v : $default;
        }
    }
    require dirname(__DIR__, 2) . '/src/autoload.php';
    $m = new ReflectionMethod(\Slate\Services\Licensing\ModuleGuard::class, 'isBypassed');
    header('Content-Type: application/json');
    echo json_encode(['sapi' => PHP_SAPI, 'app_env' => env('APP_ENV'), 'bypassed' => $m->invoke(null)]);
    return;
}
require dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json');
echo json_encode([
    'sapi'     => PHP_SAPI,
    'app_env'  => env('APP_ENV'),
    'allows'   => ModuleGuard::allows('booking'),
    'entitled' => ModuleGuard::isEntitled('booking'),
]);
