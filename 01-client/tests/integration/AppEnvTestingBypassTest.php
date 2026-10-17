<?php
/**
 * APP_ENV=testing must not switch off the licence guard or ModuleGuard on
 * a request served by a web server. Both used to honour it for every
 * request, so a production .env that wrongly said "testing" served an
 * unlicensed install and unentitled modules. The check runs against PHP's
 * built-in web server (SAPI cli-server), a real non-CLI SAPI.
 */

declare(strict_types=1);

/** Start the built-in server on the probe router with extra env; returns [status, body]. */
function aetb_request(array $env, string $path = '/'): array
{
    $port = 21000 + random_int(0, 8999);
    $cmd  = [PHP_BINARY, '-S', "127.0.0.1:{$port}", dirname(__DIR__) . '/fixtures/sapi-guard-probe.php'];
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname(__DIR__, 2), array_merge(getenv(), $env));
    try {
        if (!is_resource($proc)) throw new RuntimeException('could not start the built-in server');
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
        for ($i = 0; $i < 50; $i++) {
            usleep(100000);
            $body = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $ctx);
            if ($body !== false) {
                $status = 0;
                foreach ($http_response_header ?? [] as $h) {
                    if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) $status = (int) $m[1];
                }
                return [$status, (string) $body];
            }
        }
        throw new RuntimeException('the built-in server did not answer');
    } finally {
        if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    }
}

unit('APP_ENV=testing does not bypass the licence guard or ModuleGuard on a web request', function (): void {
    [$baseStatus, $baseBody] = aetb_request([]);
    [$status, $body] = aetb_request(['APP_ENV' => 'testing']);
    if ($baseStatus === 200) {
        // This test database holds a valid licence: then the request reaches
        // the router, and ModuleGuard must still refuse an unentitled module.
        $r = json_decode($body, true);
        assert_true(is_array($r) && $r['sapi'] === 'cli-server', 'precondition: served by the built-in web server');
        assert_eq($r['entitled'], $r['allows'], 'APP_ENV=testing must not widen ModuleGuard on a web request');
        return;
    }
    assert_eq($baseStatus, $status, 'with APP_ENV=testing the web request gets the same guard response as without it: ' . substr($body, 0, 160));
    assert_false(str_contains($body, '"allows":true'), 'the guarded router must not be reached');
});

unit('ModuleGuard itself does not treat APP_ENV=testing as a bypass on a web request', function (): void {
    [$status, $body] = aetb_request(['APP_ENV' => 'testing'], '/?mode=bypass');
    $r = json_decode($body, true);
    assert_eq(200, $status, 'probe answered: ' . substr($body, 0, 160));
    assert_true(is_array($r) && $r['sapi'] === 'cli-server', 'precondition: served by the built-in web server');
    assert_eq('testing', $r['app_env'], 'precondition: the web process sees APP_ENV=testing');
    assert_false($r['bypassed'], 'ModuleGuard must not bypass entitlement checks for a web request because APP_ENV=testing');
});
