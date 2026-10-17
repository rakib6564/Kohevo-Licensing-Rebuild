<?php
/**
 * Router for PHP's built-in web server (SAPI "cli-server", i.e. not CLI),
 * used by AppEnvTestingBypassTest to check that APP_ENV=testing does not
 * bypass the licence guard or ModuleGuard on a request served by a web
 * server. Never deployed: tests/ is denied to the web (tests/.htaccess),
 * and this only answers on the throw-away server the test starts.
 */
require dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json');
echo json_encode([
    'sapi'     => PHP_SAPI,
    'app_env'  => env('APP_ENV'),
    'allows'   => ModuleGuard::allows('booking'),
    'entitled' => ModuleGuard::isEntitled('booking'),
]);
