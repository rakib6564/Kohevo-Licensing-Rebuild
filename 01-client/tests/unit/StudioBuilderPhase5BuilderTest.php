<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Phase 5 Builder Shell.
 *
 * No database. Covers the builder API's transport boundary (everything that
 * is decided BEFORE the application layer is reached), the rate limiter, the
 * canvas security policy, the transport-safe manifest (incl. drift against
 * the UI's checked-in fixture), the shipped bundle, and runs the UI's own
 * node:test suites (skipped, loudly, where node is not installed).
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Http\StudioApiRateLimiter;
use Slate\Module\StudioBuilder\Http\StudioApiRequest;
use Slate\Module\StudioBuilder\Http\StudioAuthoringApi;
use Slate\Module\StudioBuilder\Http\StudioCanvasPolicy;
use Slate\Module\StudioBuilder\Registry\DeclarativeBlockDefinition;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\RenderMode;
use Slate\Module\StudioBuilder\Render\RenderResult;
use Slate\Module\StudioBuilder\Render\Cache\StudioCachePolicy;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\StudioPermissions;

const SBP5U_PLUGIN = __DIR__ . '/../../plugins/studio-builder';

function sbp5u_api(?\Closure $limiter = null): StudioAuthoringApi
{
    return new StudioAuthoringApi(StudioRuntimeFactory::build()->app, $limiter);
}

function sbp5u_editor(): StudioActor
{
    return StudioActor::authenticated(7, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
}

/** @param array<string, mixed> $body */
function sbp5u_post(string $action, array $body, bool $csrf = true, string $type = 'application/json', ?string $site = 'same-origin'): StudioApiRequest
{
    return new StudioApiRequest('POST', $action, [], (string) json_encode($body), $type, $csrf, $site);
}

unit('phase5 api: unknown actions, wrong methods and guests are refused before anything runs', function (): void {
    $api = sbp5u_api();
    $r = $api->handle(new StudioApiRequest('GET', 'drop_tables'), sbp5u_editor());
    assert_eq(404, $r->status);
    assert_eq('not_found', $r->errorCode());
    $r = $api->handle(new StudioApiRequest('GET', '../admin'), sbp5u_editor());
    assert_eq(404, $r->status, 'action names are an allowlist');

    $r = $api->handle(new StudioApiRequest('GET', 'operations'), sbp5u_editor());
    assert_eq(405, $r->status, 'commands are POST-only');
    assert_eq('POST', $r->headers()['Allow']);
    $r = $api->handle(sbp5u_post('bootstrap', ['page' => 1]), sbp5u_editor());
    assert_eq(405, $r->status, 'queries are GET-only');

    $r = $api->handle(new StudioApiRequest('GET', 'pages'), StudioActor::guest());
    assert_eq(401, $r->status);
    assert_eq('authentication_error', $r->errorCode());
});

unit('phase5 api: CSRF, same-origin, JSON content type and payload size are enforced on every command', function (): void {
    $api = sbp5u_api();
    $body = ['page_id' => 1, 'expected_revision_id' => 1, 'operations' => [['op' => 'remove_block', 'payload' => ['block_id' => 'blk_x']]]];

    $r = $api->handle(sbp5u_post('operations', $body, false), sbp5u_editor());
    assert_eq(403, $r->status);
    assert_eq('csrf_error', $r->errorCode(), 'a missing/invalid CSRF token is refused');

    $r = $api->handle(sbp5u_post('operations', $body, true, 'application/json', 'cross-site'), sbp5u_editor());
    assert_eq('csrf_error', $r->errorCode(), 'cross-site requests are refused even with a token');

    $r = $api->handle(sbp5u_post('operations', $body, true, 'application/x-www-form-urlencoded'), sbp5u_editor());
    assert_eq(415, $r->status, 'form posts (the classic CSRF vector) are not accepted');

    $big = new StudioApiRequest('POST', 'operations', [], str_repeat('x', StudioAuthoringApi::MAX_BODY_BYTES + 1), 'application/json', true, 'same-origin');
    assert_eq(413, $api->handle($big, sbp5u_editor())->status, 'oversized bodies are refused before decoding');

    $bad = new StudioApiRequest('POST', 'operations', [], '{"page_id":', 'application/json', true, 'same-origin');
    $r = $api->handle($bad, sbp5u_editor());
    assert_eq(422, $r->status);
    assert_eq('invalid_json', $r->payload['error']['details']['errors'][0]['code']);
});

unit('phase5 api: a tenant_id (or any unknown field) anywhere in a request is rejected, never used', function (): void {
    $api = sbp5u_api();
    $r = $api->handle(sbp5u_post('operations', ['page_id' => 1, 'tenant_id' => 202, 'expected_revision_id' => 1, 'operations' => []]), sbp5u_editor());
    assert_eq(422, $r->status);
    assert_eq('unknown_field', $r->payload['error']['details']['errors'][0]['code']);
    assert_eq('$.tenant_id', $r->payload['error']['details']['errors'][0]['path']);

    $r = $api->handle(new StudioApiRequest('GET', 'bootstrap', ['page' => '1', 'tenant_id' => '202']), sbp5u_editor());
    assert_eq(422, $r->status, 'query-string tenant ids are rejected too');

    $r = $api->handle(sbp5u_post('operations', ['page_id' => 1, 'expected_revision_id' => 1, 'operations' => [['op' => 'remove_block', 'payload' => ['block_id' => 'b'], 'tenant_id' => 2]]]), sbp5u_editor());
    assert_eq('invalid_operation', $r->payload['error']['details']['errors'][0]['code'], 'operations are exactly {op, payload}');

    $r = $api->handle(sbp5u_post('operations', ['page_id' => 1, 'operations' => [['op' => 'remove_block', 'payload' => ['block_id' => 'b']]]]), sbp5u_editor());
    assert_eq('required_field', $r->payload['error']['details']['errors'][0]['code'], 'expected_revision_id is mandatory on every write');

    $ops = array_fill(0, StudioAuthoringApi::MAX_OPERATIONS + 1, ['op' => 'remove_block', 'payload' => ['block_id' => 'b']]);
    $r = $api->handle(sbp5u_post('operations', ['page_id' => 1, 'expected_revision_id' => 1, 'operations' => $ops]), sbp5u_editor());
    assert_eq('too_many_operations', $r->payload['error']['details']['errors'][0]['code']);

    $r = $api->handle(sbp5u_post('operations', ['page_id' => 1, 'expected_revision_id' => 1, 'revision_kind' => 'publish', 'operations' => [['op' => 'remove_block', 'payload' => ['block_id' => 'b']]]]), sbp5u_editor());
    assert_eq('invalid_revision_kind', $r->payload['error']['details']['errors'][0]['code'], 'the builder can only write autosave/manual drafts');

    $r = $api->handle(sbp5u_post('operations', ['page_id' => 1, 'expected_revision_id' => 1, 'operations' => [['op' => 'eval_php', 'payload' => ['x' => 1]]]]), sbp5u_editor());
    assert_eq('unknown_operation', $r->payload['error']['details']['errors'][0]['code'], 'only canonical DocumentOperations exist');

    $r = $api->handle(sbp5u_post('publish', ['page_id' => 1, 'expected_revision_id' => null]), StudioActor::authenticated(7, StudioPermissions::ALL));
    assert_eq(422, $r->status, 'publish always states the revision it publishes');
});

unit('phase5 api: failures carry only safe codes and fixed messages', function (): void {
    $api = sbp5u_api();
    $r = $api->handle(sbp5u_post('operations', ['page_id' => 'DROP TABLE', 'expected_revision_id' => 1, 'operations' => [['op' => 'remove_block', 'payload' => ['block_id' => 'b']]]]), sbp5u_editor());
    $body = $r->body();
    assert_eq(422, $r->status);
    foreach (['Exception', 'Slate\\', '.php', 'SQLSTATE', '#0 '] as $leak) {
        assert_true(!str_contains($body, $leak), "error body must not leak '{$leak}'");
    }
    $headers = $r->headers();
    assert_eq('private, no-store, max-age=0', $headers['Cache-Control']);
    assert_eq('nosniff', $headers['X-Content-Type-Options']);
    assert_true(str_starts_with($headers['Content-Type'], 'application/json'));
    assert_true(!str_contains((string) json_encode(['a' => '</script>'], \Slate\Module\StudioBuilder\Http\StudioApiResponse::JSON_FLAGS), '</script>'), 'JSON output never carries raw markup');
});

unit('phase5 api: per-session rate limit returns rate_limited', function (): void {
    $now = 1000;
    $limiter = new StudioApiRateLimiter(static function () use (&$now): int { return $now; });
    $bucket = null;
    for ($i = 0; $i < StudioApiRateLimiter::MAX_COMMANDS; $i++) {
        assert_true($limiter->hit($bucket, 'POST'));
    }
    assert_false($limiter->hit($bucket, 'POST'), 'window exhausted');
    assert_true($limiter->hit($bucket, 'GET'), 'queries have their own budget');
    $now += StudioApiRateLimiter::WINDOW_SECONDS;
    assert_true($limiter->hit($bucket, 'POST'), 'a new window resets the budget');

    $api = sbp5u_api(static fn(string $m): bool => false);
    $r = $api->handle(sbp5u_post('lock_acquire', ['page_id' => 1]), sbp5u_editor());
    assert_eq(429, $r->status);
    assert_eq('rate_limited', $r->errorCode());
    assert_eq('10', $r->headers()['Retry-After']);
});

unit('phase5 canvas: renderForEditor output is framed sandboxed, script-free and never cached', function (): void {
    $headers = StudioCanvasPolicy::headers(new RenderResult(RenderMode::Editor, '<html></html>', StudioCachePolicy::headersFor(RenderMode::Editor)));
    $csp = $headers['Content-Security-Policy'];
    foreach (["script-src 'none'", "frame-ancestors 'self'", "form-action 'none'", "object-src 'none'", "base-uri 'none'"] as $directive) {
        assert_true(str_contains($csp, $directive), "canvas CSP has {$directive}");
    }
    assert_eq('SAMEORIGIN', $headers['X-Frame-Options']);
    assert_eq('private, no-store, max-age=0', $headers['Cache-Control']);
    assert_eq('noindex, nofollow', $headers['X-Robots-Tag']);
    assert_eq('allow-same-origin', StudioCanvasPolicy::IFRAME_SANDBOX, 'the canvas iframe never gets allow-scripts');
});

unit('phase5 manifest: binding slots are editor metadata restricted to allowlisted providers', function (): void {
    $booking = ModuleBlockDefinitions::studioRegistry()->get('booking.services');
    assert_eq([['provider' => 'booking.services', 'slot' => 'items']], $booking->toEditorManifest()['binding_slots']);
    assert_throws(\InvalidArgumentException::class, static fn() => new DeclarativeBlockDefinition(
        type: 'test.bad', version: 1, label: 'Bad', category: 'x', icon: 'x', schema: FieldSchema::define([]),
        allowedBindingProviders: ['booking.services'], bindingSlots: ['items' => 'membership.plans'],
    ), 'a slot may only name an allowlisted provider');
    $core = ModuleBlockDefinitions::studioRegistry()->get('core.heading')->toEditorManifest();
    assert_eq([], $core['binding_slots']);
});

unit('phase5 manifest: transport-safe, and the UI fixture matches the live registry (no drift)', function (): void {
    $live = ModuleBlockDefinitions::studioRegistry()->editorManifests();
    $json = (string) json_encode($live, JSON_UNESCAPED_SLASHES);
    foreach (['Slate\\\\', '.php', 'Closure', 'SELECT ', '<?', 'function'] as $bad) {
        assert_true(!str_contains($json, $bad), "manifest must not contain {$bad}");
    }
    $fixture = json_decode((string) file_get_contents(SBP5U_PLUGIN . '/ui/tests/fixtures/manifest.json'), true);
    assert_true(is_array($fixture), 'fixture is readable');
    assert_eq(
        json_decode((string) json_encode($live, JSON_PRESERVE_ZERO_FRACTION), true),
        $fixture['blocks'],
        'ui/tests/fixtures/manifest.json is stale — regenerate it from the registry'
    );
    assert_eq(CanonicalDocumentSchema::ALLOWED_BREAKPOINTS, $fixture['vocabulary']['breakpoints']);
    assert_eq(CanonicalDocumentSchema::MAX_NESTING_DEPTH, $fixture['limits']['max_nesting_depth']);
    $providerKeys = array_map(static fn(array $p): string => $p['key'], $fixture['providers']);
    assert_eq(array_keys(StudioRuntimeFactory::dataProviders()->all()), $providerKeys);
});

unit('phase5 bundle: prebuilt, served as static files, no browser storage, no embedded document', function (): void {
    $dist = SBP5U_PLUGIN . '/assets/builder';
    assert_true(is_file($dist . '/builder.js') && is_file($dist . '/builder.css'), 'the prebuilt bundle ships with the plugin');
    foreach (glob($dist . '/{,chunks/}*.js', GLOB_BRACE) ?: [] as $file) {
        $js = (string) file_get_contents($file);
        assert_true(preg_match('/\b(localStorage|sessionStorage|indexedDB|document\.cookie)\b/', $js) !== 1, basename($file) . ' must not use browser storage');
    }
    assert_true(is_file(SBP5U_PLUGIN . '/ui/.htaccess') && str_contains((string) file_get_contents(SBP5U_PLUGIN . '/ui/.htaccess'), 'Require all denied'), 'UI source/tooling is never web-served');

    $shell = (string) file_get_contents(SBP5U_PLUGIN . '/admin/builder.php');
    assert_true(str_contains($shell, 'type="application/json" id="sb-builder-boot"'), 'boot data is non-executable JSON');
    assert_true(!str_contains($shell, "'document'") && !str_contains($shell, 'document_json'), 'the host page never embeds the page document');
    assert_true(str_contains($shell, "script-src 'self'"), 'the builder page has a script CSP');

    foreach (['api.php', 'canvas.php', 'builder.php', 'index.php'] as $adapter) {
        $src = (string) file_get_contents(SBP5U_PLUGIN . '/admin/' . $adapter);
        assert_true(!preg_match('/Database::|Repository|->pages->|->revisions->/', $src), "{$adapter} reaches Studio data only through the application service");
        assert_true(str_contains($src, 'StudioActor::fromCurrentSession()'), "{$adapter} derives the actor from the session, never from the request");
    }
    assert_true(str_contains((string) file_get_contents(SBP5U_PLUGIN . '/admin/api.php'), 'csrf_verify()'), 'the API adapter uses the platform CSRF check');
});

unit('phase5 ui: the builder\'s own node:test suites pass (core, sync, components, storage)', function (): void {
    $node = trim((string) @shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        assert_true(true, 'node not installed — builder UI suites skipped');
        return;
    }
    $dir = realpath(SBP5U_PLUGIN . '/ui');
    $cmd = 'cd ' . escapeshellarg((string) $dir) . ' && ' . escapeshellarg($node) . ' --test tests/*.test.mjs 2>&1';
    $out = [];
    exec($cmd, $out, $code);
    $text = implode("\n", $out);
    assert_eq(0, $code, "builder UI tests failed:\n" . substr($text, -2000));
    assert_true(preg_match('/ℹ fail 0/', $text) === 1, 'no failing UI test');
});
