<?php
/**
 * Execute /api/v1's ApiRouter::handle() in a CHILD process, exactly as a real
 * request reaches api/v1.php via the .htaccess rewrite, with an optional
 * Bearer token and query string.
 *
 * A child, not the current process, for the same reason as
 * public-page-probe.php (the page exits), plus one more specific to this
 * router: which api_v1_authenticate filters are registered is decided once,
 * when a plugin's boot() runs at config.php load time. A test that flips a
 * plugin's active/inactive row in the parent process needs a FRESH boot to
 * see that change take effect — this fixture's whole point is to provide one.
 *
 * Usage: php tests/fixtures/api-request-probe.php <routePath> [method=GET] [bearerToken=''] [queryString='']
 *
 * Prints:
 *     STATUS <code>
 *     <json body>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$routePath = $argv[1] ?? '';
$method    = strtoupper((string)($argv[2] ?? 'GET'));
$bearer    = (string)($argv[3] ?? '');
$query     = (string)($argv[4] ?? '');

$root = dirname(__DIR__, 2);

parse_str($query, $_GET);
$_GET['_route_path'] = $routePath;
$_POST = [];
$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REQUEST_URI']    = '/api/v1/' . ltrim($routePath, '/') . ($query !== '' ? '?' . $query : '');
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SCRIPT_NAME']    = '/api/v1.php';
if ($bearer !== '') {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
}

// ApiRouter writes its JSON body with echo and its status with
// http_response_code(), then calls exit() — buffer the body so the status
// line can be emitted first regardless of that ordering.
ob_start();
$shutdownDone = false;
register_shutdown_function(static function () use (&$shutdownDone): void {
    if ($shutdownDone) return;
    $shutdownDone = true;
    $body = ob_get_length() !== false ? (string) ob_get_clean() : '';
    $code = http_response_code();
    fwrite(STDOUT, 'STATUS ' . ($code === false ? 200 : (int) $code) . "\n");
    fwrite(STDOUT, $body);
});

require $root . '/api/v1.php';
