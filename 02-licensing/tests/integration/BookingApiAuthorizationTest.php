<?php
/**
 * C1 regression: /api/v1/booking/appointments/{ref-or-id} and /api/v1/booking/sync
 * had no authentication at all — any caller could enumerate every appointment
 * in the tenant by walking numeric ids, or bulk-dump every appointment and
 * service via sync.
 *
 * The fix reuses two pieces the app already has rather than inventing a new
 * auth system:
 *
 *   - BookingAPI::findByManageToken() — the same 128-bit possession credential
 *     the confirmation email and /book/manage page already require. This is
 *     the customer self-service boundary: knowing the token IS the proof of
 *     ownership, so it stays available with no bearer token at all.
 *   - The core /api/v1 Bearer-token hook (ApiRouter::resolveAuth(), populated
 *     today by McpGateway::authenticateApiV1Token()) — the existing staff/
 *     integration credential, already passed into every handler as $auth and
 *     simply never checked. A raw numeric id or the public "BK-XXXXXXXX" ref
 *     is not a secret and must never substitute for either credential.
 *
 * These tests exercise the real HTTP entry point (api/v1.php) in child
 * processes — see tests/fixtures/api-request-probe.php — because the
 * authenticated cases depend on which api_v1_authenticate filter is
 * registered, which is decided once at boot.
 */

declare(strict_types=1);

use Slate\Tenancy\TenantContext;

/** Run api/v1.php in a CHILD process. Returns [status, decodedJsonOrNull, rawBody]. */
function bkapi_probe(string $routePath, string $method = 'GET', string $bearer = '', string $query = ''): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/api-request-probe.php') . ' '
         . escapeshellarg($routePath) . ' ' . escapeshellarg($method) . ' '
         . escapeshellarg($bearer) . ' ' . escapeshellarg($query) . ' 2>/dev/null';

    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) return [0, null, $out];

    $raw = substr($out, strlen($m[0]));
    return [(int) $m[1], json_decode($raw, true), $raw];
}

/**
 * Ensure the mcp-gateway plugin (today's only api_v1_authenticate provider)
 * is active, AND its independent MCP_GATEWAY_ENABLED env kill-switch is on,
 * for the duration of $fn — restoring both afterward. Same shape as
 * BookingMessagePublicGuardTest's capability toggle. The env var is set via
 * putenv() so the CHILD process api-request-probe.php spawns (which reads it
 * fresh via config.php's env()) inherits it; this parent process's own
 * MCP_GATEWAY_ENABLED constant (already defined from config.php's own boot)
 * is unaffected either way.
 */
function bkapi_with_mcp_gateway(callable $fn)
{
    $wasActive = PluginLoader::isActive('mcp-gateway');
    if (!$wasActive) {
        $res = PluginLoader::installFromDisk('mcp-gateway');
        if (empty($res['ok'])) {
            throw new RuntimeException('could not activate mcp-gateway for test: ' . ($res['error'] ?? 'unknown error'));
        }
    }
    $priorEnv = getenv('MCP_GATEWAY_ENABLED');
    putenv('MCP_GATEWAY_ENABLED=1');
    try {
        return $fn();
    } finally {
        putenv($priorEnv === false ? 'MCP_GATEWAY_ENABLED' : "MCP_GATEWAY_ENABLED={$priorEnv}");
        if (!$wasActive) PluginLoader::deactivate('mcp-gateway');
    }
}

/** Mint a raw MCP bearer token scoped to $tenantId directly (test-only shortcut around the admin-UI scope catalog). */
function bkapi_mint_token(int $tenantId): string
{
    // McpGatewayAPI is a plain global class, require_once'd by McpGateway::boot()
    // — never autoloaded — so it must be loaded explicitly here: this parent
    // process only activates the plugin's DB row for the child probes to boot
    // fresh against, it never runs the plugin's own boot() itself.
    require_once dirname(__DIR__, 2) . '/plugins/mcp-gateway/McpGatewayAPI.php';
    McpGatewayAPI::ensureSchema();
    $raw = 'mcpg_test_' . bin2hex(random_bytes(24));
    Database::insert('mcp_tokens', [
        'tenant_id'    => $tenantId,
        'label'        => '__probe booking-api token',
        'token_prefix' => substr($raw, 0, 16),
        'token_hash'   => hash('sha256', $raw),
        'scopes_json'  => json_encode(['booking.read']),
        'created_by'   => 0,
        'expires_at'   => null,
    ]);
    return $raw;
}

/** Insert a minimal fixture appointment for $tenantId. Returns its id/ref/manage_token plus the rows to clean up. */
function bkapi_make_appointment(int $tenantId, string $emailSuffix): array
{
    $tenants = new TenantContext();
    return $tenants->runAs($tenantId, function () use ($tenantId, $emailSuffix): array {
        $serviceId = Database::insert('booking_services', [
            'tenant_id' => $tenantId, 'name' => 'Probe Service', 'slug' => '__probe-svc-' . $tenantId . '-' . $emailSuffix,
            'duration_min' => 30, 'price_cents' => 0, 'payment_mode' => 'free', 'is_active' => 1,
        ]);
        $providerId = Database::insert('booking_providers', [
            'tenant_id' => $tenantId, 'name' => 'Probe Provider', 'is_active' => 1,
        ]);
        $ref   = 'BK-' . strtoupper(bin2hex(random_bytes(4)));
        $token = bin2hex(random_bytes(16));
        $apptId = Database::insert('booking_appointments', [
            'tenant_id' => $tenantId, 'ref' => $ref, 'manage_token' => $token,
            'service_id' => $serviceId, 'provider_id' => $providerId,
            'customer_name' => 'Probe Customer', 'customer_email' => "probe-{$emailSuffix}@example.test",
            'party_size' => 1, 'starts_at' => slate_db_now(), 'ends_at' => slate_db_now(),
            'status' => 'confirmed', 'source' => 'online',
        ]);
        return ['id' => (int) $apptId, 'ref' => $ref, 'token' => $token, 'service_id' => (int) $serviceId, 'provider_id' => (int) $providerId];
    });
}

function bkapi_cleanup_appointment(int $tenantId, array $fixture): void
{
    $tenants = new TenantContext();
    $tenants->runAs($tenantId, function () use ($fixture): void {
        Database::query('DELETE FROM booking_appointments WHERE id = ?', [$fixture['id']]);
        Database::query('DELETE FROM booking_providers WHERE id = ?', [$fixture['provider_id']]);
        Database::query('DELETE FROM booking_services WHERE id = ?', [$fixture['service_id']]);
    });
}

// ── 1. Unauthenticated callers must not retrieve appointment/customer info ──

unit('booking API: an unauthenticated numeric-id lookup is denied', function (): void {
    $tid = current_tenant_id();
    $fx = bkapi_make_appointment($tid, 'anon-id');
    try {
        [$status, $json] = bkapi_probe('booking/appointments/' . $fx['id']);
        assert_eq(404, $status, 'a raw numeric id must never authorize returning appointment data');
        assert_true(empty($json['data']), 'no appointment data is present in the response');
        assert_true(empty($json['success']), 'response reports failure');
    } finally {
        bkapi_cleanup_appointment($tid, $fx);
    }
});

unit('booking API: an unauthenticated lookup by the public reference is denied', function (): void {
    $tid = current_tenant_id();
    $fx = bkapi_make_appointment($tid, 'anon-ref');
    try {
        [$status, $json] = bkapi_probe('booking/appointments/' . $fx['ref']);
        assert_eq(404, $status, 'the public "BK-XXXXXXXX" reference is not a secret and must not authorize a lookup');
        assert_true(empty($json['data']), 'no appointment data is present in the response');
    } finally {
        bkapi_cleanup_appointment($tid, $fx);
    }
});

unit('booking API: sync is denied without an authenticated bearer token', function (): void {
    [$status, $json] = bkapi_probe('booking/sync');
    assert_eq(401, $status, 'a bulk, all-appointments sync requires authentication');
    assert_true(empty($json['data']), 'no appointment or service data leaks without authentication');
});

// ── 2 & 6. Customer ownership: the manage_token flow keeps working and stays scoped to its own appointment ──

unit('booking API: the manage_token customer self-service lookup still works with no bearer token', function (): void {
    $tid = current_tenant_id();
    $fx = bkapi_make_appointment($tid, 'anon-token');
    try {
        [$status, $json] = bkapi_probe('booking/appointments/' . $fx['token']);
        assert_eq(200, $status, 'a valid manage_token authorizes the customer self-service lookup');
        assert_true(!empty($json['data']), 'appointment data is returned');
        assert_eq($fx['id'], (int) ($json['data']['id'] ?? 0), 'the correct appointment is returned');
    } finally {
        bkapi_cleanup_appointment($tid, $fx);
    }
});

unit('booking API: one customer\'s manage_token cannot retrieve another customer\'s appointment', function (): void {
    $tid = current_tenant_id();
    $fxA = bkapi_make_appointment($tid, 'custA');
    $fxB = bkapi_make_appointment($tid, 'custB');
    try {
        [$status, $json] = bkapi_probe('booking/appointments/' . $fxA['token']);
        assert_eq(200, $status);
        assert_eq($fxA['id'], (int) ($json['data']['id'] ?? 0), "customer A's token resolves to customer A's appointment");
        assert_true((int) ($json['data']['id'] ?? 0) !== $fxB['id'], "customer A's token must never resolve to customer B's appointment");
        assert_true(($json['data']['customer_email'] ?? '') !== 'probe-custB@example.test', "customer B's data is not exposed via customer A's token");
    } finally {
        bkapi_cleanup_appointment($tid, $fxA);
        bkapi_cleanup_appointment($tid, $fxB);
    }
});

// ── 3, 4 & 7. Authenticated staff/integration access: succeeds for its own tenant, denied cross-tenant, existing shape preserved ──

unit('booking API: an authenticated bearer token can look up its own tenant\'s appointment by id, and sync succeeds', function (): void {
    bkapi_with_mcp_gateway(function (): void {
        $tid = current_tenant_id();
        $fx = null;
        $token = null;
        try {
            $fx = bkapi_make_appointment($tid, 'staff-own');
            $token = bkapi_mint_token($tid);

            [$status, $json] = bkapi_probe('booking/appointments/' . $fx['id'], 'GET', $token);
            assert_eq(200, $status, 'a valid bearer token authorizes looking up its own tenant\'s appointment by id');
            assert_eq($fx['id'], (int) ($json['data']['id'] ?? 0));

            [$syncStatus, $syncJson] = bkapi_probe('booking/sync', 'GET', $token);
            assert_eq(200, $syncStatus, 'sync succeeds for an authenticated bearer token');
            assert_true(is_array($syncJson['data']['appointments'] ?? null), 'sync still returns the appointments payload shape');
        } finally {
            if ($fx !== null) bkapi_cleanup_appointment($tid, $fx);
            if ($token !== null) Database::query('DELETE FROM mcp_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
        }
    });
});

unit('booking API: an authenticated bearer token for tenant A cannot retrieve tenant B\'s appointment', function (): void {
    bkapi_with_mcp_gateway(function (): void {
        $tenantA = current_tenant_id();
        $tenantB = $tenantA + 97100; // synthetic foreign tenant, same shape as RbacTenantIsolationTest
        $fxB = null;
        $tokenA = null;
        try {
            $fxB = bkapi_make_appointment($tenantB, 'tenantB');
            $tokenA = bkapi_mint_token($tenantA);

            [$status, $json] = bkapi_probe('booking/appointments/' . $fxB['id'], 'GET', $tokenA);
            assert_eq(404, $status, "tenant A's bearer token must not retrieve tenant B's appointment by id");
            assert_true(empty($json['data']), 'no cross-tenant appointment data leaks');

            [$refStatus, $refJson] = bkapi_probe('booking/appointments/' . $fxB['ref'], 'GET', $tokenA);
            assert_eq(404, $refStatus, "tenant A's bearer token must not retrieve tenant B's appointment by ref either");
            assert_true(empty($refJson['data']), 'no cross-tenant appointment data leaks via ref');

            [$syncStatus, $syncJson] = bkapi_probe('booking/sync', 'GET', $tokenA);
            assert_eq(200, $syncStatus);
            $ids = array_map(static fn($a) => (int) $a['id'], $syncJson['data']['appointments'] ?? []);
            assert_true(!in_array($fxB['id'], $ids, true), "tenant A's sync must never include tenant B's appointment");
        } finally {
            if ($fxB !== null) bkapi_cleanup_appointment($tenantB, $fxB);
            if ($tokenA !== null) Database::query('DELETE FROM mcp_tokens WHERE token_hash = ?', [hash('sha256', $tokenA)]);
        }
    });
});

// ── Existing legitimate public behaviour is unaffected ──

unit('booking API: the public services catalogue is still reachable with no credential', function (): void {
    [$status, $json] = bkapi_probe('booking/services');
    assert_eq(200, $status, 'listServices() was not touched by the C1 fix and must remain public');
    assert_true(array_key_exists('data', (array) $json), 'a services list is returned');
});
