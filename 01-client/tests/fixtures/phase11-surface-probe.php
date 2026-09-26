<?php
/**
 * Phase 11: execute one module entry point in a CHILD process — the same
 * rationale as public-page-probe.php (the page exits, and SLATE_TESTING,
 * once defined, cannot be un-defined for the rest of a process) — with the
 * extra request context the Booking side-door endpoints need: an HTTP
 * method, request headers, and optionally a signed-in customer session.
 *
 * Prints:
 *
 *     STATUS <code>
 *     <body>
 *
 * Usage: php tests/fixtures/phase11-surface-probe.php <page> [method] [query] [customerId] [headersJson]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$page       = $argv[1] ?? '';
$method     = strtoupper($argv[2] ?? 'GET');
$query      = $argv[3] ?? '';
$customerId = (int) ($argv[4] ?? 0);
$headers    = json_decode((string) ($argv[5] ?? '{}'), true) ?: [];
if ($page === '') { fwrite(STDERR, "usage: phase11-surface-probe.php <page> [method] [query] [customerId] [headersJson]\n"); exit(2); }

$root = dirname(__DIR__, 2);
$file = $root . '/' . ltrim($page, '/');
if (!is_file($file)) { fwrite(STDERR, "no such page: {$file}\n"); exit(2); }

// Same Guard toggle as every other probe (06 §3a, D19): the real Guards only
// when the caller asks for them.
if (getenv('SLATE_LICENSE_GUARD_LIVE') !== '1') {
    define('SLATE_TESTING', true);
}

parse_str($query, $_GET);
$_POST = [];
$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REQUEST_URI']    = '/' . ltrim($page, '/') . ($query !== '' ? '?' . $query : '');
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SCRIPT_NAME']    = '/' . ltrim($page, '/');
foreach ($headers as $name => $value) {
    $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', (string) $name))] = (string) $value;
}

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

require $root . '/config.php';

if ($customerId > 0) {
    session_id('p11probe_' . bin2hex(random_bytes(12)));
    \Slate\Services\Auth\Auth::startSession();
    $_SESSION['slate_customer'] = ['id' => $customerId, 'tenant_id' => current_tenant_id(), 'email' => 'p11-probe@example.test'];
}

require $file;
