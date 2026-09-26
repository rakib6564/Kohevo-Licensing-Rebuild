<?php
/**
 * Phase 11 — Security Hardening.
 *
 * Two groups:
 *
 *  1. Existing Phase 9/10 protections, pinned BEFORE anything around them
 *     changes: out-of-order signed payloads, a replayed genuine payload
 *     into an empty cache, a forward-edited fetched_at, and a cross-
 *     installation payload. Phase 11 adds no code here — these assert the
 *     offline replay exposure is already bounded by the approved 7-day
 *     offline tolerance (08 §4).
 *
 *  2. Confirmed Phase 11 gaps, fixed:
 *     - V1: Booking entry points that checked only "plugin active", never
 *       the license entitlement (pay-intent, prereq, message, gcal-webhook,
 *       customer/book.php — the last one named in 07 §2).
 *     - V2: Booking/Forms/Membership content blocks rendering an
 *       unentitled module's public surface on any page.
 *     - V3: the MCP business report reading unentitled modules' data.
 *     - E:  the Global License Guard whitelist under a /subdir/ deployment.
 *
 * Every module probe seeds a globally VALID license so the Global License
 * Guard passes and only the entitlement set varies (ModuleGuardTest.php's
 * convention), and runs in a child process with SLATE_LICENSE_GUARD_LIVE=1.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/license_signing.php';

use Slate\Services\Licensing\CommercialLicenseWindow;
use Slate\Services\Licensing\SlateLicenseCacheStore;

function p11_env_prefix(): string {
    return 'SLATE_LICENSE_GUARD_LIVE=1 '
        . 'LICENSE_SERVER_URL=' . escapeshellarg('https://license.test') . ' '
        . 'LICENSE_SERVER_PUBLIC_KEY=' . escapeshellarg(license_test_public_key()) . ' '
        . 'LICENSE_PRODUCT=' . escapeshellarg('kohevo') . ' '
        . 'LICENSE_KEY=' . escapeshellarg('test-key') . ' ';
}

function p11_run(string $fixture, array $args): string {
    $cmd = p11_env_prefix() . escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/' . $fixture);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string) $arg);
    }
    return (string) shell_exec($cmd . ' 2>/dev/null');
}

/** @return array{status:int, body:string} */
function p11_surface(string $page, string $method = 'GET', string $query = '', int $customerId = 0, array $headers = []): array {
    $out = p11_run('phase11-surface-probe.php', [$page, $method, $query, $customerId, json_encode($headers)]);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    return ['status' => (int) ($m[1] ?? 0), 'body' => substr($out, strlen($m[0] ?? ''))];
}

/** Assert $guard appears in a repo file, and before $work does — a "refused before any work" ordering check. */
function p11_assert_guarded_before(string $file, string $guard, string $work, string $msg): void {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
    $g = strpos($src, $guard);
    $w = strpos($src, $work);
    assert_true($g !== false, "{$file} must contain {$guard}");
    assert_true($w !== false, "{$file} must contain {$work}");
    assert_true($g < $w, $msg);
}

/**
 * The report tool is registered by the mcp-gateway plugin's boot; activate
 * it (and its env kill switch) for the child probe, restoring both after —
 * BookingApiAuthorizationTest.php's bkapi_with_mcp_gateway() shape.
 */
function p11_with_mcp_gateway(callable $fn) {
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

/** @return array<string,int> module => rendered bytes (-1 = threw) */
function p11_content_blocks(): array {
    $sizes = [];
    foreach (explode("\n", trim(p11_run('phase11-content-block-probe.php', []))) as $line) {
        if (preg_match('/^(\w+) (-?\d+)$/', $line, $m)) $sizes[$m[1]] = (int) $m[2];
    }
    return $sizes;
}

function p11_mcp_report(): array {
    $cmd = p11_env_prefix() . escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/mcp-tool-call-probe.php') . ' '
         . escapeshellarg('slate_business_report') . ' ' . escapeshellarg('{}') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!str_starts_with($out, "STATUS OK\n")) return ['error' => $out];
    $decoded = json_decode(substr($out, strlen("STATUS OK\n")), true);
    return is_array($decoded) ? $decoded : ['error' => $out];
}

/** Report sections may be wrapped by the gateway; find the report body wherever it sits. */
function p11_report_body(array $result): ?array {
    if (array_key_exists('bookings', $result)) return $result;
    foreach ($result as $value) {
        if (is_array($value) && ($found = p11_report_body($value)) !== null) return $found;
        if (is_string($value) && ($j = json_decode($value, true)) && is_array($j) && ($found = p11_report_body($j)) !== null) return $found;
    }
    return null;
}

/** Distinct from the other licensing suites' identities ('7', 'd', '4' × 32). */
function p11_local_identity(): string { return str_repeat('b', 32); }

function p11_ensure_local_identity(?string $installationId = null): void {
    $installationId ??= p11_local_identity();
    $row = Database::row('SELECT installation_id FROM installation_identity WHERE singleton_id = 1');
    if ($row === null) {
        Database::insert('installation_identity', [
            'singleton_id' => 1, 'tenant_id' => current_tenant_id(), 'installation_id' => $installationId,
        ]);
    } elseif ((string) $row['installation_id'] !== $installationId) {
        Database::update('installation_identity', ['installation_id' => $installationId], 'singleton_id = 1', []);
    }
}

function p11_clear(): void {
    Database::query('DELETE FROM remote_license_cache WHERE tenant_id = ?', [current_tenant_id()]);
}

/** A globally valid, trusted license with exactly the given module entitlements. */
function p11_seed(array $entitlements): void {
    p11_ensure_local_identity();
    license_test_seed_cache(current_tenant_id(), [
        'status' => 'active', 'plan' => 'pro', 'entitlements' => $entitlements,
        'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
        'installation_id' => p11_local_identity(),
    ]);
}

function p11_store(): SlateLicenseCacheStore {
    return new SlateLicenseCacheStore(current_tenant_id(), license_test_public_key());
}

/** Evaluate the current cache row exactly as the Global License Guard does. */
function p11_guard_decision(): array {
    return CommercialLicenseWindow::evaluate(p11_store()->readTrustState(), time());
}

// ── 1. Existing protections (Phase 9/10), pinned ─────────────────────────

unit('Phase 11 existing: an older signed payload is rejected as stale_response and never overwrites a newer one', function () {
    p11_ensure_local_identity();
    try {
        p11_clear();
        $store = p11_store();
        $store->save(license_test_signed_state([
            'status' => 'suspended', 'plan' => 'pro', 'entitlements' => [], 'expires_at' => null,
            'fetched_at' => gmdate('Y-m-d H:i:s'), 'installation_id' => p11_local_identity(),
        ]));
        assert_throws(\LicenseCacheStaleException::class, function () use ($store) {
            $store->save(license_test_signed_state([
                'status' => 'active', 'plan' => 'pro', 'entitlements' => ['booking'], 'expires_at' => null,
                'fetched_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'installation_id' => p11_local_identity(),
            ]));
        });
        assert_eq('suspended', $store->readTrustState()['data']['status'] ?? null, 'the newer signed state must survive');
    } finally {
        p11_clear();
    }
});

unit('Phase 11 existing: a genuine signed payload replayed into an EMPTY cache is trusted no longer than 7 days after its signed checked_at', function () {
    p11_ensure_local_identity();
    try {
        p11_clear();
        p11_store()->save(license_test_signed_state([
            'status' => 'active', 'plan' => 'pro', 'entitlements' => ['booking'], 'expires_at' => null,
            'fetched_at' => gmdate('Y-m-d H:i:s', time() - 6 * 86400), 'installation_id' => p11_local_identity(),
        ]));
        assert_true(p11_guard_decision()['allowed'], 'inside the approved offline tolerance, as for any snapshot');

        p11_clear();
        p11_store()->save(license_test_signed_state([
            'status' => 'active', 'plan' => 'pro', 'entitlements' => ['booking'], 'expires_at' => null,
            'fetched_at' => gmdate('Y-m-d H:i:s', time() - 8 * 86400), 'installation_id' => p11_local_identity(),
        ]));
        $decision = p11_guard_decision();
        assert_false($decision['allowed'], 'a replayed payload older than 7 days must lock');
        assert_eq('stale', $decision['reason']);
    } finally {
        p11_clear();
    }
});

unit('Phase 11 existing: editing fetched_at forward in the database cannot extend a signed snapshot\'s freshness', function () {
    p11_ensure_local_identity();
    try {
        p11_clear();
        p11_store()->save(license_test_signed_state([
            'status' => 'active', 'plan' => 'pro', 'entitlements' => ['booking'], 'expires_at' => null,
            'fetched_at' => gmdate('Y-m-d H:i:s', time() - 8 * 86400), 'installation_id' => p11_local_identity(),
        ]));
        Database::query('UPDATE remote_license_cache SET fetched_at = ? WHERE tenant_id = ?',
            [gmdate('Y-m-d H:i:s'), current_tenant_id()]);

        $trust = p11_store()->readTrustState();
        assert_true($trust['trusted'], 'fetched_at is not part of the signed comparison — the row stays trusted');
        $decision = CommercialLicenseWindow::evaluate($trust, time());
        assert_false($decision['allowed']);
        assert_eq('stale', $decision['reason'], 'freshness comes from the signed checked_at, not the edited column');
    } finally {
        p11_clear();
    }
});

unit('Phase 11 existing: a payload signed for a DIFFERENT installation is untrusted at read time', function () {
    p11_ensure_local_identity();
    try {
        p11_clear();
        p11_store()->save(license_test_signed_state([
            'status' => 'active', 'plan' => 'pro', 'entitlements' => ['booking'], 'expires_at' => null,
            'fetched_at' => gmdate('Y-m-d H:i:s'), 'installation_id' => str_repeat('c', 32),
        ]));
        $trust = p11_store()->readTrustState();
        assert_false($trust['trusted']);
        assert_false(p11_guard_decision()['allowed']);
    } finally {
        p11_clear();
    }
});

// ── 2a. V1 — Booking side-door entry points ──────────────────────────────

unit('Phase 11 V1: booking pay-intent returns 404 JSON when booking is not entitled', function () {
    try {
        p11_seed(['forms']);
        $res = p11_surface('plugins/booking/public/pay-intent.php');
        assert_eq(404, $res['status']);
        assert_true(is_array(json_decode($res['body'], true)), 'the widget\'s fetch() still receives JSON');
        p11_assert_guarded_before('plugins/booking/public/pay-intent.php', "ModuleGuard::allows('booking')", 'StripePaymentAPI::isConfigured',
            'refused before any payment work');
    } finally {
        p11_clear();
    }
});

unit('Phase 11 V1: booking prereq page returns 404 when booking is not entitled', function () {
    try {
        p11_seed(['forms']);
        // No context: an entitled install answers 400 itself (see the
        // entitled test below), so a 404 here can only be the Module Guard's.
        assert_eq(404, p11_surface('plugins/booking/public/prereq.php')['status']);
    } finally {
        p11_clear();
    }
});

unit('Phase 11 V1: booking message page returns 404 when booking is not entitled', function () {
    try {
        p11_seed(['forms']);
        // A malformed token: an entitled install answers 400/503 itself, so
        // a 404 here can only be the Module Guard's.
        $res = p11_surface('plugins/booking/public/message.php', 'GET', 't=short');
        assert_eq(404, $res['status']);
        p11_assert_guarded_before('plugins/booking/public/message.php', "ModuleGuard::requirePublic('booking')", 'BookingPlusAPI::ensureSchema',
            'no schema work, manage-token lookup or message write on the way to refusing');
    } finally {
        p11_clear();
    }
});

unit('Phase 11 V1: the gcal webhook still acknowledges 200 but performs no sync when booking is not entitled', function () {
    $googleHeaders = [
        'X-Goog-Channel-Id' => 'p11-channel', 'X-Goog-Resource-Id' => 'p11-resource',
        'X-Goog-Channel-Token' => 'p11-token', 'X-Goog-Resource-State' => 'exists',
    ];
    try {
        p11_seed(['forms']);
        $res = p11_surface('plugins/booking/public/gcal-webhook.php', 'POST', '', 0, $googleHeaders);
        assert_eq(200, $res['status'], 'a non-2xx would make Google retry and then drop the channel');
        assert_eq('ok', trim($res['body']));
        p11_assert_guarded_before('plugins/booking/public/gcal-webhook.php', "ModuleGuard::allows('booking')", 'GoogleCalendarSync::handleWebhook',
            'no sync work may begin for an unentitled installation');
    } finally {
        p11_clear();
    }
});

unit('Phase 11 V1: the customer portal /member/book is blocked (403) when booking is not entitled', function () {
    try {
        p11_seed(['membership']);
        $res = p11_surface('customer/book.php', 'GET', '', 990001);
        assert_eq(403, $res['status']);
        assert_false(str_contains($res['body'], 'Book an appointment'), 'no portal booking page is rendered');
    } finally {
        p11_clear();
    }
});

unit('Phase 11 V1: each booking surface still works when booking IS entitled', function () {
    try {
        p11_seed(['booking']);
        assert_eq(405, p11_surface('plugins/booking/public/pay-intent.php')['status'], 'reaches its own POST-only check');
        assert_eq(400, p11_surface('plugins/booking/public/prereq.php')['status'], 'reaches its own missing-context check');
        assert_true(in_array(p11_surface('plugins/booking/public/message.php', 'GET', 't=short')['status'], [400, 503], true),
            'reaches its own capability/token handling');

        $hook = p11_surface('plugins/booking/public/gcal-webhook.php', 'POST', '', 0, [
            'X-Goog-Channel-Id' => 'p11-channel', 'X-Goog-Resource-Id' => 'p11-resource',
            'X-Goog-Channel-Token' => 'p11-token', 'X-Goog-Resource-State' => 'exists',
        ]);
        assert_eq(200, $hook['status']);
        assert_eq('ok', trim($hook['body']));

        assert_eq(200, p11_surface('customer/book.php', 'GET', '', 990001)['status']);
    } finally {
        p11_clear();
    }
});

// ── 2b. V2 — content blocks ──────────────────────────────────────────────

unit('Phase 11 V2: booking/forms/membership content blocks render nothing when unentitled and render normally when entitled', function () {
    try {
        p11_seed([]);
        $none = p11_content_blocks();
        assert_eq(0, $none['booking'] ?? null, 'booking block');
        assert_eq(0, $none['forms'] ?? null, 'forms block');
        assert_eq(0, $none['membership'] ?? null, 'membership block');

        p11_seed(['booking', 'forms', 'membership']);
        $all = p11_content_blocks();
        assert_true(($all['booking'] ?? 0) > 0, 'booking block renders when entitled');
        assert_true(($all['forms'] ?? 0) > 0, 'forms block renders when entitled');
        assert_true(($all['membership'] ?? 0) > 0, 'membership block renders when entitled');

        p11_seed(['forms']);
        $formsOnly = p11_content_blocks();
        assert_true(($formsOnly['forms'] ?? 0) > 0);
        assert_eq(0, $formsOnly['booking'] ?? null, 'one module never unlocks another');
        assert_eq(0, $formsOnly['membership'] ?? null);
    } finally {
        p11_clear();
    }
});

// ── 2c. V3 — MCP business report ─────────────────────────────────────────

unit('Phase 11 V3: the MCP business report omits unentitled module sections', function () {
    try {
        p11_seed(['forms']);
        $raw = p11_with_mcp_gateway(fn () => p11_mcp_report());
        $report = p11_report_body($raw);
        assert_true($report !== null, 'the report tool itself still runs: ' . json_encode($raw));
        assert_null($report['bookings'], 'booking data is not reported without the booking entitlement');
        assert_null($report['membership'], 'membership data is not reported without the membership entitlement');
        assert_true(is_array($report['forms']), 'an entitled module is still reported');
        assert_true(array_key_exists('activity', $report), 'Core activity is unaffected');
    } finally {
        p11_clear();
    }
});

// ── 2d. E — Global License Guard under a /subdir/ deployment ─────────────

unit('Phase 11 E: the guard whitelist matches under the configured /subdir/ base path', function () {
    foreach (['admin/login.php', 'admin/logout.php', 'admin/license.php', 'cron.php', 'install.php'] as $page) {
        assert_eq($page, slate_license_guard_strip_base('slate/' . $page, 'https://example.test/slate'), $page);
    }
    assert_eq('admin/license.php', slate_license_guard_strip_base('apps/slate/admin/license.php', 'https://example.test/apps/slate/'));
    assert_eq('api/v1.php', slate_license_guard_strip_base('slate/api/v1.php', 'https://example.test/Slate'));
});

unit('Phase 11 E: only the exact configured base is stripped — anything else stays guarded', function () {
    assert_eq('admin/login.php', slate_license_guard_strip_base('admin/login.php', 'https://example.test'), 'root deployment unchanged');
    assert_eq('slate/admin/login.php', slate_license_guard_strip_base('slate/admin/login.php', 'https://example.test'),
        'no configured base: a prefixed path is not whitelisted');
    assert_eq('other/admin/login.php', slate_license_guard_strip_base('other/admin/login.php', 'https://example.test/slate'));
    assert_eq('slatex/admin/login.php', slate_license_guard_strip_base('slatex/admin/login.php', 'https://example.test/slate'),
        'a partial segment match is not a base-path match');
    assert_eq('slate', slate_license_guard_strip_base('slate', 'https://example.test/slate'));
    assert_eq('admin/index.php', slate_license_guard_strip_base('slate/admin/index.php', 'https://example.test/slate'),
        'stripping never whitelists a non-whitelisted page');
});

unit('Phase 11 E: end to end, a locked install still blocks a prefixed path that is not the configured base', function () {
    // This environment's APP_URL has no path, so "/slate/admin/login.php"
    // is NOT the whitelisted login page here and must stay locked.
    try {
        p11_ensure_local_identity();
        p11_clear(); // no cache row: locked
        $cmd = 'SLATE_LICENSE_GUARD_LIVE=1 ' . license_test_env_prefix() . escapeshellarg(PHP_BINARY) . ' -r '
             . escapeshellarg(
                 '$_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["HTTP_HOST"]="localhost";'
               . '$_SERVER["SCRIPT_NAME"]="/slate/admin/login.php";'
               . 'require ' . var_export(dirname(__DIR__, 2) . '/config.php', true) . ';'
               . 'echo "REACHED";'
             ) . ' 2>/dev/null';
        $out = (string) shell_exec($cmd);
        assert_false(str_contains($out, 'REACHED'), 'the Guard must stop the request inside config.php');
    } finally {
        p11_clear();
    }
});
