<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Phase 9D production
 * hardening.
 *
 * Autoloader only (no database): the request id, the safe failure-log line
 * (no message/SQL/path/secret leakage, bounded, injection-proof), the
 * session-cookie filter and — through a real `php -S` child serving the real
 * StudioHttpResponder — that a public Studio response carries no session
 * cookie while an authoring response keeps it and gets an X-Request-Id; the
 * denial-audit matrix of StudioAuthoringApi and its throttle; the public
 * Permissions-Policy; and static checks that pin the deployment files (CI
 * extension assertion, Nginx/Apache parity and routing of /robots.txt and
 * /sitemap.xml, one consistent PHP floor across the docs).
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Exception\StudioNotFoundException;
use Slate\Module\StudioBuilder\Exception\StudioRenderException;
use Slate\Module\StudioBuilder\Http\StudioApiRequest;
use Slate\Module\StudioBuilder\Http\StudioAuthoringApi;
use Slate\Module\StudioBuilder\Render\Cache\StudioCachePolicy;
use Slate\Module\StudioBuilder\Render\RenderMode;
use Slate\Module\StudioBuilder\Runtime\StudioDenialAudit;
use Slate\Module\StudioBuilder\Runtime\StudioHttpResponder;
use Slate\Module\StudioBuilder\Runtime\StudioLog;
use Slate\Module\StudioBuilder\Runtime\StudioRequestId;
use Slate\Module\StudioBuilder\Runtime\StudioReservedRoutes;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;

/** The repository root (01-client's parent holds .github, production-artifacts and README.md). */
function sbd9u_repo(): string
{
    return dirname(__DIR__, 3);
}

function sbd9u_read(string $relative): string
{
    $path = sbd9u_repo() . '/' . $relative;
    assert_true(is_file($path), "{$relative} exists");
    return (string) file_get_contents($path);
}

// ── Request id ───────────────────────────────────────────────────────────────

unit('phase9d request id: opaque, per request, stable within it and never taken from the client', function (): void {
    StudioRequestId::reset();
    $id = StudioRequestId::current();
    assert_true(preg_match('/^[a-f0-9]{16}$/', $id) === 1, 'opaque 64-bit hex id: ' . $id);
    assert_eq($id, StudioRequestId::current(), 'stable for the whole request');
    StudioRequestId::reset();
    assert_true(StudioRequestId::current() !== $id, 'a new request gets a new id');

    $_SERVER['HTTP_X_REQUEST_ID'] = "spoofed\r\nX-Injected: 1";
    StudioRequestId::reset();
    assert_true(preg_match('/^[a-f0-9]{16}$/', StudioRequestId::current()) === 1, 'an inbound X-Request-Id is ignored');
    unset($_SERVER['HTTP_X_REQUEST_ID']);
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Module/StudioBuilder/Runtime/StudioRequestId.php');
    assert_true(!str_contains($src, '$_SERVER') && !str_contains($src, 'getenv') && !str_contains($src, 'header('), 'the id is never derived from the request');
});

// ── Safe failure log ─────────────────────────────────────────────────────────

unit('phase9d log: a failure line says where it happened and never what the request or the exception message contained', function (): void {
    StudioRequestId::reset();
    $secretMessage = 'SQLSTATE[42S02]: SELECT * FROM users WHERE password = \'hunter2\' at /var/www/kohevo/data/.env token=abc123';
    $line = StudioLog::describe('public', 'render', new \RuntimeException($secretMessage));
    assert_true(str_starts_with($line, 'req=' . StudioRequestId::current() . ' studio.public.render failed: RuntimeException at StudioBuilderPhase9ProductionTest.php:'), 'request id, component.action, class and basename:line: ' . $line);
    foreach (['hunter2', 'SELECT', 'SQLSTATE', '/var/www', '.env', 'abc123', 'password'] as $leak) {
        assert_true(!str_contains($line, $leak), "a non-Studio exception message is never logged: {$leak}");
    }
    assert_true(!str_contains($line, dirname(__DIR__)), 'only the file basename, never a path');
    assert_true(!str_contains($line, "\n") && !str_contains($line, "\r"), 'one physical line');

    $pdo = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'secret@example.test\'');
    $pdo->errorInfo = ['23000', 1062, 'Duplicate entry \'secret@example.test\' for key email'];
    $line = StudioLog::describe('api', 'save_draft', $pdo);
    assert_true(str_contains($line, 'sqlstate=23000'), 'a database failure logs its SQLSTATE: ' . $line);
    assert_true(!str_contains($line, 'secret@example.test') && !str_contains($line, 'Duplicate'), 'and no row data');

    // A Studio wrapper does not hide the real cause; its authored message/code/reason are safe and included.
    $render = new StudioRenderException('Studio page compilation failed.', ['reason' => 'compiler_failure', 'page_id' => 77], new \LogicException('boom: ' . $secretMessage));
    $line = StudioLog::describe('public', 'render', $render);
    assert_true(str_contains($line, 'StudioRenderException') && str_contains($line, 'code=STUDIO_RENDER_FAILED') && str_contains($line, 'reason=compiler_failure'), 'Studio code and reason: ' . $line);
    assert_true(str_contains($line, 'msg="Studio page compilation failed."'), 'the authored message');
    assert_true(str_contains($line, ' << LogicException at '), 'the previous exception (the actual cause) is in the chain: ' . $line);
    assert_true(!str_contains($line, 'hunter2') && !str_contains($line, 'page_id') && !str_contains($line, '77'), 'details other than reason, and the cause\'s message, are not logged');

    // Bounded + injection-proof even for a Studio exception with a hostile message and hostile labels.
    $hostile = new StudioNotFoundException("line1\nFORGED [ERROR] admin logged in \"quoted\" " . str_repeat('x', 400));
    $line = StudioLog::describe("pub\nlic", "ren der\r\n[FORGED]", $hostile);
    assert_true(!str_contains($line, "\n") && !str_contains($line, "\r"), 'no line break can forge a second log line');
    assert_true(strlen($line) < 600, 'bounded length (' . strlen($line) . ')');
    assert_true(str_contains($line, 'studio.pub_lic.ren_der___FORGED_ failed:'), 'labels are reduced to an identifier charset: ' . $line);
    assert_true(substr_count($line, '"') === 2, 'the message cannot break out of its quotes');
});

unit('phase9d log: failure() goes through the platform log sink with the level given and returns the line', function (): void {
    $seen = [];
    StudioLog::useSink(static function (string $line, string $level) use (&$seen): void {
        $seen[] = [$level, $line];
    });
    try {
        $line = StudioLog::failure('mcp', 'studio_get_page', new \RuntimeException('x'));
        StudioLog::failure('render', 'provider.forms', new \RuntimeException('y'), 'warning');
    } finally {
        StudioLog::useSink(null);
    }
    assert_eq(2, count($seen));
    assert_eq('error', $seen[0][0], 'default level is error');
    assert_eq($line, $seen[0][1]);
    assert_eq('warning', $seen[1][0]);
    assert_true(str_contains($seen[1][1], 'studio.render.provider.forms failed'), $seen[1][1]);

    // No call site may fall back to logging only a class name or a raw exception message again.
    $root = dirname(__DIR__, 2);
    $offenders = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src/Module/StudioBuilder', \FilesystemIterator::SKIP_DOTS));
    $files = array_merge(iterator_to_array($it), glob($root . '/plugins/studio-builder/*.php') ?: [], glob($root . '/plugins/studio-builder/admin/*.php') ?: []);
    foreach ($files as $file) {
        $path = (string) $file;
        if (!str_ends_with($path, '.php') || str_ends_with($path, 'StudioLog.php')) {
            continue;
        }
        foreach (file($path) ?: [] as $n => $text) {
            if (preg_match('/slate_log\s*\(/', $text) === 1 && str_contains($text, 'get_class') ) {
                $offenders[] = basename($path) . ':' . ($n + 1);
            }
            if (preg_match('/slate_log\s*\(.*getMessage\s*\(/', $text) === 1) {
                $offenders[] = basename($path) . ':' . ($n + 1) . ' (message)';
            }
        }
    }
    assert_eq([], $offenders, 'Studio failures are logged through StudioLog only');
});

// ── Session cookie on public responses ───────────────────────────────────────

unit('phase9d set-cookie: only the session cookie is dropped from a public response', function (): void {
    $lines = [
        'Content-Type: text/html',
        'Set-Cookie: SLATE_SID=abc123; path=/; HttpOnly; SameSite=Lax',
        'Set-Cookie: slate_consent=1; path=/',
        'set-cookie: SLATE_SID_OTHER=1; path=/',
        'Cache-Control: public, no-cache',
    ];
    assert_eq(['Set-Cookie: slate_consent=1; path=/', 'set-cookie: SLATE_SID_OTHER=1; path=/'], StudioHttpResponder::keptCookieLines($lines, 'SLATE_SID'));
    assert_eq([], StudioHttpResponder::keptCookieLines(['Set-Cookie: SLATE_SID=x'], 'SLATE_SID'));
    assert_eq(['Set-Cookie: SLATE_SID=x'], StudioHttpResponder::keptCookieLines(['Set-Cookie: SLATE_SID=x'], ''), 'no session name: nothing to recognise');
});

/**
 * Serve the REAL responder from a `php -S` child (the CLI SAPI keeps no header
 * list, the built-in web server does) after starting a session exactly like
 * Auth::startSession() does, and return [statusLine, headerLines, body].
 *
 * @return array{0: string, 1: list<string>, 2: string}
 */
function sbd9u_serve(string $route): array
{
    static $server = null;
    static $port = 0;
    if ($server === null) {
        $root = dirname(__DIR__, 2);
        $router = tempnam(sys_get_temp_dir(), 'sbd9router') . '.php';
        file_put_contents($router, '<?php
            define("SLATE_ROOT", ' . var_export($root, true) . ');
            require SLATE_ROOT . "/src/autoload.php";
            use Slate\Module\StudioBuilder\Runtime\PublicResponse;
            use Slate\Module\StudioBuilder\Runtime\StudioHttpResponder;
            use Slate\Module\StudioBuilder\Render\Cache\StudioCachePolicy;
            use Slate\Module\StudioBuilder\Render\RenderMode;
            // what config.php -> Auth::startSession() does on EVERY web request
            session_set_cookie_params(["lifetime" => 0, "path" => "/", "httponly" => true, "samesite" => "Lax"]);
            session_name("SLATE_SID");
            session_start();
            $_SESSION["slate_last_activity"] = time();
            $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
            if ($path === "/public") {
                setcookie("slate_consent", "1", ["path" => "/"]);
                StudioHttpResponder::sendPublic(PublicResponse::ok(StudioCachePolicy::headersFor(RenderMode::Public, str_repeat("a", 64)), "<html>page</html>"));
            } elseif ($path === "/public-500") {
                StudioHttpResponder::sendPublic(PublicResponse::error());
            } elseif ($path === "/public-304") {
                StudioHttpResponder::sendPublic(PublicResponse::notModified(StudioCachePolicy::headersFor(RenderMode::Public, str_repeat("a", 64))));
            } elseif ($path === "/authoring-403") {
                StudioHttpResponder::sendAuthoringError(403);
            }');
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        assert_true($probe !== false, 'a free local port: ' . $errstr);
        $port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        assert_true(is_resource($server), 'php -S started');
        register_shutdown_function(static function () use (&$server, $router): void {
            if (is_resource($server)) {
                proc_terminate($server);
                proc_close($server);
            }
            @unlink($router);
            @unlink(substr($router, 0, -4));
        });
        $up = false;
        for ($i = 0; $i < 60 && !$up; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
            if ($c !== false) {
                fclose($c);
                $up = true;
            } else {
                usleep(100000);
            }
        }
        assert_true($up, 'the php -S child accepts connections');
    }
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0]]);
    $body = @file_get_contents('http://127.0.0.1:' . $port . $route, false, $ctx);
    $headers = $http_response_header ?? [];
    assert_true($headers !== [], "{$route}: the child answered");
    return [array_shift($headers), $headers, (string) $body];
}

function sbd9u_header(array $lines, string $name): ?string
{
    foreach ($lines as $line) {
        if (stripos($line, $name . ':') === 0) {
            return trim(substr($line, strlen($name) + 1));
        }
    }
    return null;
}

/** @param list<string> $lines @return list<string> */
function sbd9u_cookies(array $lines): array
{
    return array_values(array_filter($lines, static fn(string $l): bool => stripos($l, 'Set-Cookie:') === 0));
}

unit('phase9d set-cookie: a public Studio page never carries the session cookie (real responder, real session start, real web server)', function (): void {
    [$status, $headers, $body] = sbd9u_serve('/public');
    assert_true(str_contains($status, '200'), $status);
    assert_eq('<html>page</html>', $body);
    $cookies = sbd9u_cookies($headers);
    foreach ($cookies as $cookie) {
        assert_true(stripos($cookie, 'SLATE_SID') === false, 'no session cookie on a public page: ' . $cookie);
    }
    assert_eq(1, count($cookies), 'other cookies are left alone: ' . json_encode($cookies));
    assert_true(str_contains($cookies[0], 'slate_consent=1'));
    assert_eq('public, no-cache', sbd9u_header($headers, 'Cache-Control'), 'the public cache policy is unchanged');
    assert_true(sbd9u_header($headers, 'X-Request-Id') === null, 'no request id on a successful public page (it is cacheable)');
    assert_eq(StudioCachePolicy::PERMISSIONS_POLICY, sbd9u_header($headers, 'Permissions-Policy'));

    [, $headers304] = sbd9u_serve('/public-304');
    assert_eq([], sbd9u_cookies($headers304), 'a 304 revalidation carries no session cookie either');
});

unit('phase9d error headers: a public 500 is no-store, detail-free, carries a request id and no session cookie; an authoring error keeps its session and carries the id', function (): void {
    [$status, $headers, $body] = sbd9u_serve('/public-500');
    assert_true(str_contains($status, '500'), $status);
    assert_eq('no-store', sbd9u_header($headers, 'Cache-Control'));
    assert_true(preg_match('/^[a-f0-9]{16}$/', (string) sbd9u_header($headers, 'X-Request-Id')) === 1, 'request id on the 500: ' . json_encode($headers));
    assert_true(str_contains($body, 'Something went wrong'), 'the platform\'s generic error page');
    foreach (sbd9u_cookies($headers) as $cookie) {
        assert_true(stripos($cookie, 'SLATE_SID') === false, 'no session cookie on a public 500');
    }
    foreach (['Exception', 'Stack trace', '#0 ', '.php', 'SELECT'] as $leak) {
        assert_true(stripos($body, $leak) === false, "no internal detail in the public 500 body: {$leak}");
    }

    [$status, $headers, $body] = sbd9u_serve('/authoring-403');
    assert_true(str_contains($status, '403'), $status);
    assert_true(preg_match('/^[a-f0-9]{16}$/', (string) sbd9u_header($headers, 'X-Request-Id')) === 1, 'request id on an authoring error');
    assert_eq('private, no-store, max-age=0', sbd9u_header($headers, 'Cache-Control'));
    assert_true(count(array_filter(sbd9u_cookies($headers), static fn(string $c): bool => str_contains($c, 'SLATE_SID='))) === 1, 'the authoring endpoints keep the session cookie');
    assert_true(!str_contains($body, 'Exception'), 'no internal detail');
});

// ── Public headers ───────────────────────────────────────────────────────────

unit('phase9d headers: the public policy denies unused powerful features and keeps the existing cache contract; private modes are unchanged', function (): void {
    $pub = StudioCachePolicy::headersFor(RenderMode::Public, str_repeat('a', 64));
    assert_eq('camera=(), microphone=(), geolocation=(), payment=(), usb=()', $pub['Permissions-Policy']);
    assert_eq('public, no-cache', $pub['Cache-Control']);
    assert_eq('nosniff', $pub['X-Content-Type-Options']);
    assert_eq('strict-origin-when-cross-origin', $pub['Referrer-Policy']);
    assert_true(!isset($pub['Content-Language']), 'Content-Language is the runtime\'s (tenant locale), never the cache policy\'s');
    foreach (['fullscreen', 'autoplay', 'clipboard', 'web-share', 'sync-xhr'] as $kept) {
        assert_true(!str_contains($pub['Permissions-Policy'], $kept), "{$kept} is not disabled");
    }
    assert_true(!isset(StudioCachePolicy::headersFor(RenderMode::Preview)['Permissions-Policy']) && !isset(StudioCachePolicy::headersFor(RenderMode::Editor)['Permissions-Policy']), 'the builder canvas/preview are not touched');
    // The runtime (not the cache policy) sets Content-Language from the tenant locale; pin that wiring statically.
    $runtime = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Module/StudioBuilder/Runtime/StudioPublicRuntime.php');
    assert_true(str_contains($runtime, "'Content-Language' => \$locale") && str_contains($runtime, 'StudioPublicLocale::resolve()'), 'Content-Language comes from StudioPublicLocale::resolve()');
    preg_match('/private static function languageHeader\(\).*?\n    }\n/s', $runtime, $m);
    assert_true(isset($m[0]) && !preg_match('/\$_(GET|SERVER|SESSION|COOKIE|REQUEST)|currentLocale|HTTP_ACCEPT_LANGUAGE/', $m[0]), 'never derived from the visitor');
});

// ── Denial audit ─────────────────────────────────────────────────────────────

function sbd9u_editor(): StudioActor
{
    return StudioActor::authenticated(7, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
}

unit('phase9d denial audit: CSRF, cross-site, rate-limit denials of a signed-in user are audited with safe metadata; routine 4xx are not', function (): void {
    StudioRequestId::reset();
    $rows = [];
    $auditor = static function (string $code, array $meta) use (&$rows): void {
        $rows[] = [$code, $meta];
    };
    $app = StudioRuntimeFactory::build()->app;
    $body = json_encode(['page_id' => 1, 'expected_revision_id' => 1, 'operations' => [], 'api_key' => 'SECRET-BODY-MARKER']);
    $post = static fn(string $action, bool $csrf = true, string $type = 'application/json', ?string $site = 'same-origin', ?string $b = null): StudioApiRequest
        => new StudioApiRequest('POST', $action, [], $b ?? (string) $body, $type, $csrf, $site);

    $api = new StudioAuthoringApi($app, null, null, $auditor);
    $r = $api->handle($post('operations', false), sbd9u_editor());
    assert_eq('csrf_error', $r->errorCode());
    $r = $api->handle($post('operations', true, 'application/json', 'cross-site'), sbd9u_editor());
    assert_eq('csrf_error', $r->errorCode());
    assert_eq(2, count($rows));
    assert_eq('csrf_error', $rows[0][0]);
    assert_eq('token', $rows[0][1]['reason'], 'a bad token');
    assert_eq('cross_site', $rows[1][1]['reason'], 'a cross-site fetch');
    assert_eq(['status' => 403, 'method' => 'POST', 'action' => 'operations', 'request_id' => StudioRequestId::current(), 'reason' => 'token'], $rows[0][1], 'exactly these fields');
    assert_true(!str_contains(json_encode($rows), 'SECRET-BODY-MARKER') && !str_contains(json_encode($rows), 'api_key'), 'the request body is never audited');
    assert_eq(StudioRequestId::current(), $r->headers()['X-Request-Id'], 'the response carries the same id the audit row holds');

    $rows = [];
    $limited = new StudioAuthoringApi($app, static fn(): bool => false, null, $auditor);
    $r = $limited->handle($post('operations'), sbd9u_editor());
    assert_eq(429, $r->status);
    assert_eq(1, count($rows));
    assert_eq('rate_limited', $rows[0][0]);
    assert_eq(429, $rows[0][1]['status']);

    // Ordinary refusals are not security signals and are not audited.
    $rows = [];
    $api->handle(new StudioApiRequest('GET', 'pages'), StudioActor::guest());                       // 401 anonymous
    $api->handle(new StudioApiRequest('GET', 'drop_tables'), sbd9u_editor());                        // 404 unknown action
    $api->handle(new StudioApiRequest('GET', 'operations'), sbd9u_editor());                         // 405
    $api->handle($post('operations', true, 'text/plain'), sbd9u_editor());                           // 415
    $api->handle($post('operations', true, 'application/json', 'same-origin', str_repeat('x', StudioAuthoringApi::MAX_BODY_BYTES + 1)), sbd9u_editor()); // 413
    $api->handle($post('operations', true, 'application/json', 'same-origin', '{not json'), sbd9u_editor());   // malformed body
    assert_eq([], $rows, 'unauthenticated, unknown, wrong-method, unsupported-type, oversized and malformed requests are not audited');

    // A failing auditor never changes the response.
    $boom = new StudioAuthoringApi($app, null, null, static function (): void {
        throw new \RuntimeException('audit down');
    });
    assert_eq('csrf_error', $boom->handle($post('operations', false), sbd9u_editor())->errorCode());

    // No auditor wired (tests, other callers): nothing happens.
    assert_eq('csrf_error', (new StudioAuthoringApi($app))->handle($post('operations', false), sbd9u_editor())->errorCode());
});

unit('phase9d request id header: every failure response carries it, a success does not; nothing in it identifies a tenant, page or user', function (): void {
    StudioRequestId::reset();
    $ok = \Slate\Module\StudioBuilder\Http\StudioApiResponse::ok(['x' => 1]);
    assert_true(!isset($ok->headers()['X-Request-Id']), 'no id on a success');
    $api = new StudioAuthoringApi(StudioRuntimeFactory::build()->app);
    foreach ([
        $api->handle(new StudioApiRequest('GET', 'drop_tables'), sbd9u_editor()),
        $api->handle(new StudioApiRequest('GET', 'pages'), StudioActor::guest()),
        $api->handle(new StudioApiRequest('GET', 'operations'), sbd9u_editor()),
    ] as $failure) {
        $id = $failure->headers()['X-Request-Id'] ?? '';
        assert_true(preg_match('/^[a-f0-9]{16}$/', $id) === 1, 'a failure ' . $failure->status . ' carries the opaque id');
        assert_true(!str_contains($failure->body(), $id), 'the id is a header only: it is not in the JSON body, so the body contract is unchanged');
    }
});

unit('phase9d denial audit throttle: one row per class per window, skipped rows are counted onto the next, classes are independent', function (): void {
    $s = null;
    assert_eq(0, StudioDenialAudit::admit($s, 'csrf_error', 1000), 'first denial is recorded');
    assert_null(StudioDenialAudit::admit($s, 'csrf_error', 1001), 'inside the window: skipped');
    assert_null(StudioDenialAudit::admit($s, 'csrf_error', 1059), 'still inside');
    assert_eq(0, StudioDenialAudit::admit($s, 'rate_limited', 1002), 'another class is independent');
    assert_eq(2, StudioDenialAudit::admit($s, 'csrf_error', 1060), 'window over: recorded, carrying how many were skipped');
    assert_null(StudioDenialAudit::admit($s, 'csrf_error', 1061));
    assert_eq(0, StudioDenialAudit::admit($s, 'authorization_error', 500), 'a clock that went backwards does not wedge a class');
    $bad = ['csrf_error' => 'junk'];
    assert_eq(0, StudioDenialAudit::admit($bad, 'x', 10), 'corrupt session state is reset, not trusted');
    assert_true(is_array($bad));
});

// ── Static deployment checks ─────────────────────────────────────────────────

unit('phase9d ci: the workflow asserts mbstring (and the other required extensions) in the job that runs the Studio suites, with an actionable message', function (): void {
    $ci = sbd9u_read('.github/workflows/ci.yml');
    $assert = strpos($ci, 'Assert required PHP extensions');
    $studio = strpos($ci, 'php 01-client/tests/run-studio-builder.php unit');
    $setup = strpos($ci, 'shivammathur/setup-php');
    assert_true($setup !== false && $assert !== false && $studio !== false, 'all three present');
    assert_true($setup < $assert && $assert < $studio, 'asserted after PHP is set up and before the Studio suites run, in the same job');
    assert_true(substr_count($ci, 'licensing-ci:') === 1 && substr_count($ci, "\n  ") >= 1, 'a single job owns setup, assertion and suites');
    assert_true(str_contains($ci, 'extension_loaded("mbstring")') && str_contains($ci, 'function_exists("mb_substr")'), 'mbstring is checked');
    assert_true(str_contains($ci, "::error::The PHP mbstring extension is missing") && str_contains($ci, "add 'mbstring' to the setup-php 'extensions:' list"), 'the failure names the cause and the fix');
    assert_true(str_contains($ci, 'for ext in pdo_mysql curl json openssl sodium'), 'the other documented required extensions are asserted too');
    assert_true(preg_match('/extensions:\s*[^\n]*mbstring/', $ci) === 1, 'and mbstring is actually installed by setup-php');
    // No polyfill / replacement implementation.
    assert_true(!str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), 'polyfill'), 'no Composer mbstring polyfill');
});

/** The `location ~ <regex> {` patterns of an Nginx doc block, in order. @return list<string> */
function sbd9u_nginx_regex_locations(string $conf): array
{
    preg_match_all('/^\s*location\s+~\s+(\S+)\s*\{/m', $conf, $m);
    return $m[1];
}

function sbd9u_nginx_block(string $doc, string $anchor): string
{
    $start = strpos($doc, $anchor);
    assert_true($start !== false, "{$anchor} present in the Nginx guide");
    $open = strpos($doc, '```nginx', $start);
    assert_true($open !== false, 'an nginx code block follows the heading');
    $end = strpos($doc, "\n```", $open + 8);
    return substr($doc, $open, $end - $open);
}

/** Emulate nginx's first-matching-regex-location rule for the doc's block (PCRE, case-sensitive, `~`). */
function sbd9u_nginx_denies(array $regexes, string $uri): bool
{
    foreach ($regexes as $regex) {
        if (preg_match('~' . str_replace('~', '\~', $regex) . '~', $uri) === 1) {
            return $regex !== '\.php$';
        }
    }
    return false;
}

unit('phase9d nginx: the client guide denies plugin UI sources (parity with ui/.htaccess) without blocking public assets, Studio slugs, robots.txt or sitemap.xml', function (): void {
    $apache = sbd9u_read('01-client/plugins/studio-builder/ui/.htaccess');
    assert_true(str_contains($apache, 'Require all denied'), 'the Apache side: ui/ is denied by its own .htaccess');
    $doc = sbd9u_read('production-artifacts/NGINX-SETUP.md');
    $client = sbd9u_nginx_block($doc, '## 2. Client Application Nginx Configuration');
    $central = sbd9u_nginx_block($doc, '## 1. Central Licensing Server Nginx Configuration');
    $regexes = sbd9u_nginx_regex_locations($client);
    assert_true(in_array('^/plugins/[^/]+/ui(/|$)', $regexes, true), 'the ui deny rule is documented for the client: ' . json_encode($regexes));

    foreach (['/plugins/studio-builder/ui/package.json', '/plugins/studio-builder/ui/src/main.jsx', '/plugins/studio-builder/ui/tests/seo.test.mjs', '/plugins/studio-builder/ui', '/plugins/studio-builder/ui/', '/plugins/any-plugin/ui/x'] as $uri) {
        assert_true(sbd9u_nginx_denies($regexes, $uri), "denied: {$uri}");
    }
    foreach ([
        '/plugins/studio-builder/assets/builder/builder.js', '/plugins/studio-builder/assets/builder/builder.css', '/plugins/forms/assets/forms.js',
        '/plugins/studio-builder/uikit.css', '/plugins/ui/x', '/uploads/media/a.jpg',
        '/robots.txt', '/sitemap.xml', '/about', '/studio-ui', '/plugins-and-more', '/data-protection', '/documentation', '/database', '/binary-options', '/srcset', '/audit-services', '/dbs',
    ] as $uri) {
        assert_true(!sbd9u_nginx_denies($regexes, $uri), "served / routed: {$uri}");
    }
    foreach (['/data/slate.log', '/data', '/includes/Database.php', '/src/autoload.php', '/bin/migrate', '/db/schema.sql', '/tests/smoke.php', '/docs/x', '/audit/findings.md', '/Claude/notes.md', '/.env', '/.git/config'] as $uri) {
        assert_true(sbd9u_nginx_denies($regexes, $uri), "existing deny still holds: {$uri}");
    }
    assert_true(!in_array('^/plugins/[^/]+/ui(/|$)', sbd9u_nginx_regex_locations($central), true), 'the central server has no Studio UI and needs no such rule');

    // The INSTALL.md examples use the same terminated deny (an unterminated one 403s public pages such as /docs-foo or /database).
    foreach (['01-client/INSTALL.md', '02-licensing/INSTALL.md'] as $install) {
        $text = sbd9u_read($install);
        preg_match('/location ~ (\^\/\(\\\\\.env[^ ]*) \{/', $text, $m);
        assert_true(isset($m[1]) && str_ends_with($m[1], '(/|$)'), "{$install}: the deny regex is terminated: " . ($m[1] ?? 'not found'));
        $regexes2 = [$m[1]];
        assert_true(!sbd9u_nginx_denies($regexes2, '/database') && !sbd9u_nginx_denies($regexes2, '/docs-and-guides') && sbd9u_nginx_denies($regexes2, '/data/slate.log'), "{$install}: blocks internals only");
    }
    assert_true(str_contains((string) sbd9u_read('01-client/INSTALL.md'), 'location ~ ^/plugins/[^/]+/ui(/|$)'), 'the client INSTALL.md example carries the ui rule too');
});

unit('phase9d routing: /robots.txt and /sitemap.xml reach public.php on Apache and Nginx and no Studio slug can shadow them', function (): void {
    // Apache: the shipped .htaccess routes every non-file request to public.php?_path=
    $htaccess = sbd9u_read('01-client/.htaccess');
    assert_true(str_contains($htaccess, 'RewriteRule ^(.*)$ public.php?_path=$1 [QSA,L]'), 'Apache: catch-all to public.php');
    assert_true(str_contains($htaccess, 'RewriteCond %{REQUEST_FILENAME} -f [OR]'), 'a real file wins (so none may be shipped)');
    assert_true(strpos($htaccess, 'api/v1') < strpos($htaccess, 'public.php?_path=$1'), 'the specific rules come before the catch-all');
    foreach (['/robots.txt', '/sitemap.xml'] as $uri) {
        assert_true(preg_match('~^(\.|/\.)~', ltrim($uri, '/')) !== 1, "{$uri} is not a dotfile");
        assert_true(preg_match('~^/(data|db_backups|includes|src|bin|db|tests|audit|docs|Claude)/~', $uri) !== 1, "{$uri} is not an internal directory");
    }
    // Nginx: the documented fallback routes them too; no regex location of the client block intercepts them.
    $client = sbd9u_nginx_block(sbd9u_read('production-artifacts/NGINX-SETUP.md'), '## 2. Client Application Nginx Configuration');
    assert_true(str_contains($client, 'try_files $uri $uri/ /public.php?_path=$uri&$args;'), 'Nginx: fallback to public.php');
    $regexes = sbd9u_nginx_regex_locations($client);
    foreach (['/robots.txt', '/sitemap.xml'] as $uri) {
        assert_true(!sbd9u_nginx_denies($regexes, $uri) && preg_match('~\.php$~', $uri) !== 1, "Nginx does not deny or hand {$uri} to PHP-FPM directly");
    }
    // The guides say not to ship static copies and not to add blocks that would hide Studio's.
    foreach (['production-artifacts/NGINX-SETUP.md', 'production-artifacts/APACHE-SETUP.md'] as $guide) {
        assert_true(str_contains(sbd9u_read($guide), '/robots.txt') && str_contains(sbd9u_read($guide), 'static'), "{$guide} documents the routing and the no-static-file rule");
    }
    // Neither app ships a physical file that would shadow the generated ones.
    foreach (['01-client/robots.txt', '01-client/sitemap.xml', '02-licensing/robots.txt', '02-licensing/sitemap.xml'] as $shadow) {
        assert_true(!file_exists(sbd9u_repo() . '/' . $shadow), "no static {$shadow}");
    }
    // A Studio page can never take these names: the SEO files and their bare names are reserved, and `.` is not a slug character.
    $reserved = new StudioReservedRoutes(static fn(): array => []);
    foreach (['robots.txt', 'sitemap.xml', 'robots.TXT'] as $dotted) {
        assert_true(preg_match(\Slate\Module\StudioBuilder\Domain\PageAddress::SLUG_PATTERN, $dotted) !== 1, "{$dotted} is not a valid slug at all (slugs have no dot)");
    }
    foreach (['robots', 'sitemap'] as $bare) {
        assert_true($reserved->isReserved($bare), "the bare slug {$bare} is reserved too, so no page can sit next to the files");
    }
});

unit('phase9d docs: one PHP floor everywhere — 8.2 (the code uses `readonly class`), CI on 8.3 — and sodium/mbstring/DOM are described as the code really uses them', function (): void {
    // The floor is real: a readonly class is a parse error before 8.2.
    assert_true(str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/src/Tenancy/ScopeContext.php'), 'final readonly class'), 'the code needs PHP 8.2');
    assert_true(str_contains(sbd9u_read('.github/workflows/ci.yml'), "php-version: '8.3'"), 'CI runs 8.3');

    $floorFiles = [
        'README.md', '01-client/INSTALL.md', '02-licensing/INSTALL.md', '01-client/README.md', 'production-artifacts/CLIENT-SETUP.md',
        'production-artifacts/CENTRAL-SETUP.md', 'production-artifacts/PRODUCTION-DEPLOYMENT.md', 'production-artifacts/GO-LIVE-CHECKLIST.md',
        'docs/06-operations/SHARED-HOSTING.md', '02-licensing/docs/CLIENT-ONBOARDING.md',
    ];
    foreach ($floorFiles as $file) {
        $text = sbd9u_read($file);
        assert_true(preg_match('/PHP\s*\**8\.2\b/i', $text) === 1 || str_contains($text, 'PHP-8.2'), "{$file} states the PHP 8.2 floor");
        // Only "8.1 cannot run it" style mentions are allowed; never 8.1 as a supported version.
        assert_true(preg_match('/PHP\s*\**8\.1\s*(\+|or newer)/i', $text) === 0 && !str_contains($text, 'PHP-8.1'), "{$file} does not claim PHP 8.1 support");
    }
    foreach (['01-client/INSTALL.md', '02-licensing/INSTALL.md', 'production-artifacts/GO-LIVE-CHECKLIST.md', 'production-artifacts/CLIENT-SETUP.md', 'production-artifacts/CENTRAL-SETUP.md', 'docs/06-operations/SHARED-HOSTING.md', 'README.md'] as $file) {
        $text = sbd9u_read($file);
        foreach (['pdo_mysql', 'mbstring', 'curl', 'json', 'openssl', 'sodium'] as $ext) {
            assert_true(str_contains($text, $ext), "{$file} lists {$ext}");
        }
        assert_true(!str_contains($text, 'sodium_compat') && !str_contains($text, 'pure-PHP'), "{$file}: no claim of a sodium fallback the code does not have");
    }
    assert_true(str_contains(sbd9u_read('production-artifacts/CLIENT-SETUP.md'), 'dom') && str_contains(sbd9u_read('01-client/INSTALL.md'), 'libxml'), 'DOM/libxml documented for Studio HTML import');
    foreach (['production-artifacts/CLIENT-SETUP.md', 'production-artifacts/CENTRAL-SETUP.md', 'production-artifacts/ENV-SETUP.md'] as $file) {
        assert_true(str_contains(sbd9u_read($file), 'MySQL 8.0') || str_contains(sbd9u_read($file), 'APP_URL'), "{$file}: current");
    }
    assert_true(!str_contains(sbd9u_read('01-client/README.md'), 'MySQL/MariaDB 5.7+'), 'the stale 5.7 database claim is gone');

    // The shipped .htaccess files are embedded verbatim in the Apache guide.
    $apache = sbd9u_read('production-artifacts/APACHE-SETUP.md');
    assert_true(str_contains($apache, trim(sbd9u_read('01-client/.htaccess'))), 'APACHE-SETUP embeds the real 01-client/.htaccess');
    assert_true(str_contains($apache, trim(sbd9u_read('02-licensing/.htaccess'))), 'APACHE-SETUP embeds the real 02-licensing/.htaccess');
});

unit('phase9d config: no application config names a hard-coded host as the APP_URL default; APP_ENV=testing is documented as never-for-production', function (): void {
    foreach (['01-client/config.php', '02-licensing/config.php'] as $file) {
        $config = sbd9u_read($file);
        assert_true(preg_match("/^define\\('SLATE_URL', rtrim\\(\\(string\\)env\\('APP_URL', ''\\), '\\/'\\)\\);$/m", $config) === 1, "{$file}: SLATE_URL fails closed to ''");
        assert_true(!str_contains($config, 'rakibhasaan.com'), "{$file}: no hard-coded live domain");
        assert_true(str_contains($config, "ini_set('display_errors', '0');"), "{$file}: errors are never displayed");
    }
    $env = sbd9u_read('production-artifacts/ENV-SETUP.md');
    assert_true(str_contains($env, 'Never set `APP_ENV=testing` in production'), 'APP_ENV=testing warning');
    assert_true(str_contains($env, 'X-Request-Id') && str_contains($env, 'req=<id>'), 'the request-id log workflow is documented');
});
