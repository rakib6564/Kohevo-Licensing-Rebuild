<?php
/**
 * Phase 9: expiry warning, 7-day commercial grace and full lock after
 * grace — enforced end-to-end through the real entry points by the
 * existing Global License Guard (Phase 6), ModuleGuard (Phase 7) and the
 * License UI (Phase 8), all consuming the one CommercialLicenseWindow
 * derivation.
 *
 * Every child-process probe runs with SLATE_LICENSE_GUARD_LIVE=1 and a
 * remote-authority environment (same convention as ModuleGuardTest.php /
 * ClientLicenseUiTest.php), so the Guard AND remote entitlement authority
 * are both live. Exact-second boundaries are covered deterministically
 * with a fixed clock in tests/unit/CommercialLicenseWindowTest.php; here
 * each boundary is approached with a safe margin (a child process runs a
 * little after the parent seeds), plus the exact lock instant itself,
 * which can only move further into "locked" as wall time advances.
 *
 * Live central-server integration is NOT exercised here: the renewal /
 * suspension cases drive the real RemoteLicenseClient check-in path with a
 * locally-signed response through its injectable transport (the same
 * technique RemoteLicenseClientDetailedTest.php uses), not a live server.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/license_signing.php';

use Slate\Services\Licensing\SlateLicenseCacheStore;

function p9_env_prefix(): string {
    return 'SLATE_LICENSE_GUARD_LIVE=1 '
        . 'LICENSE_SERVER_URL=' . escapeshellarg('https://license-phase9.test') . ' '
        . 'LICENSE_SERVER_PUBLIC_KEY=' . escapeshellarg(license_test_public_key()) . ' '
        . 'LICENSE_PRODUCT=' . escapeshellarg('kohevo') . ' '
        . 'LICENSE_KEY=' . escapeshellarg('phase9-test-key') . ' ';
}

function p9_shell(string $fixture, array $args): array {
    $cmd = p9_env_prefix() . escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/' . $fixture);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string) $arg);
    }
    $cmd .= ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    return ['status' => (int) ($m[1] ?? 0), 'body' => substr($out, strlen($m[0] ?? ''))];
}

function p9_admin(string $page, string $query = '', int $roleId = 1, string $platformAdmin = '0'): array {
    return p9_shell('admin-page-probe.php', [$page, $query, $roleId, $platformAdmin]);
}

function p9_admin_post(string $page, array $fields = [], int $roleId = 1): array {
    return p9_shell('admin-page-post-probe.php', [$page, json_encode($fields), $roleId, '0']);
}

function p9_public(string $page, string $query = ''): array {
    return p9_shell('public-page-probe.php', [$page, $query]);
}

function p9_api(string $routePath, string $method = 'GET'): array {
    return p9_shell('api-request-probe.php', [$routePath, $method, '', '']);
}

function p9_module_cron(string $module): string {
    $cmd = p9_env_prefix() . escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/module-guard-cron-probe.php')
         . ' ' . escapeshellarg($module) . ' 2>/dev/null';
    return trim((string) shell_exec($cmd));
}

function p9_mcp(string $tool, array $args = []): bool {
    $cmd = p9_env_prefix() . escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/mcp-tool-call-probe.php') . ' '
         . escapeshellarg($tool) . ' ' . escapeshellarg(json_encode($args)) . ' 2>/dev/null';
    return str_starts_with((string) shell_exec($cmd), "STATUS OK\n");
}

/** Distinct from every other licensing suite's identity. */
function p9_identity(): string { return str_repeat('5', 32); }

function p9_ensure_identity(?string $installationId = null): void {
    $installationId ??= p9_identity();
    $row = Database::row('SELECT installation_id FROM installation_identity WHERE singleton_id = 1');
    if ($row === null) {
        Database::insert('installation_identity', [
            'singleton_id' => 1, 'tenant_id' => current_tenant_id(), 'installation_id' => $installationId,
        ]);
    } elseif ((string) $row['installation_id'] !== $installationId) {
        Database::update('installation_identity', ['installation_id' => $installationId], 'singleton_id = 1', []);
    }
}

function p9_clear(): void {
    Database::query('DELETE FROM remote_license_cache WHERE tenant_id = ?', [current_tenant_id()]);
}

/** A trusted, fresh snapshot expiring $expiresOffset seconds from now (null = no expiry). */
function p9_seed(?int $expiresOffset, array $overrides = []): void {
    p9_ensure_identity();
    license_test_seed_cache(current_tenant_id(), $overrides + [
        'status' => 'active', 'plan' => 'pro', 'entitlements' => ['forms', 'membership', 'booking'],
        'expires_at' => $expiresOffset === null ? null : gmdate('Y-m-d H:i:s', time() + $expiresOffset),
        'fetched_at' => gmdate('Y-m-d H:i:s'),
        'installation_id' => p9_identity(),
    ]);
}

function p9_banner(string $body): ?string {
    return preg_match('/data-license-banner="([a-z_]+)"/', $body, $m) ? $m[1] : null;
}

function p9_state(string $body): ?string {
    return preg_match('/data-license-state="([a-z_]+)"/', $body, $m) ? $m[1] : null;
}

const P9_DAY = 86400;

// ── A. Before expiry ─────────────────────────────────────────────────────

unit('Phase 9: 8 days before expiry — full access, dashboard shows Active and no expiry banner', function () {
    p9_clear();
    try {
        p9_seed(8 * P9_DAY);
        $res = p9_admin('admin/index.php');
        assert_eq(200, $res['status']);
        assert_null(p9_banner($res['body']), 'no warning more than 7 days before expiry');
    } finally {
        p9_clear();
    }
});

unit('Phase 9: 6 days before expiry — full access with the "expiring soon" banner on admin pages', function () {
    p9_clear();
    try {
        p9_seed(6 * P9_DAY);
        $res = p9_admin('admin/index.php');
        assert_eq(200, $res['status']);
        assert_eq('expiring_soon', p9_banner($res['body']));
        assert_true(str_contains($res['body'], 'License expiring soon'));
        assert_true(str_contains($res['body'], 'UTC'), 'the exact expiry instant is shown in UTC');
        assert_eq(1, substr_count($res['body'], 'data-license-banner='), 'the warning is rendered once per page, not duplicated');

        $settings = p9_admin('admin/settings.php');
        assert_eq(200, $settings['status']);
        assert_eq('expiring_soon', p9_banner($settings['body']), 'the warning stays visible across admin pages');
    } finally {
        p9_clear();
    }
});

unit('Phase 9: the License page shows Expiring soon with its own detailed notice (the chrome banner is not repeated there)', function () {
    p9_clear();
    try {
        p9_seed(3 * P9_DAY);
        $res = p9_admin('admin/license.php');
        assert_eq(200, $res['status']);
        assert_eq('expiring_soon', p9_state($res['body']));
        assert_eq(1, substr_count($res['body'], 'data-license-banner='));
        assert_true(str_contains($res['body'], 'data-license-remaining'), 'time remaining is shown');
    } finally {
        p9_clear();
    }
});

unit('Phase 9: 1 minute before expiry — still Expiring soon, not grace', function () {
    p9_clear();
    try {
        p9_seed(60);
        $res = p9_admin('admin/license.php');
        assert_eq(200, $res['status']);
        assert_eq('expiring_soon', p9_state($res['body']));
    } finally {
        p9_clear();
    }
});

// ── B. Commercial grace ──────────────────────────────────────────────────

unit('Phase 9: just past expiry — commercial grace: full access, "expired — grace period" banner, never labelled Active', function () {
    p9_clear();
    try {
        p9_seed(-1);
        $dash = p9_admin('admin/index.php');
        assert_eq(200, $dash['status'], 'expiry alone must not lock the application');
        assert_eq('grace', p9_banner($dash['body']));
        assert_true(str_contains($dash['body'], 'License expired — grace period'));

        $lic = p9_admin('admin/license.php');
        assert_eq('grace', p9_state($lic['body']));
        assert_false(str_contains($lic['body'], '> Active<'), 'an expired license is never labelled Active');
        assert_true(str_contains($lic['body'], 'data-license-grace-ends'));
        assert_false((bool) preg_match('/grace[^<]{0,200}(network|connect|offline)/i', $lic['body']), 'grace is never attributed to connectivity');
    } finally {
        p9_clear();
    }
});

unit('Phase 9: 6 days 23 hours into grace — still fully usable', function () {
    p9_clear();
    try {
        p9_seed(-(7 * P9_DAY - 3600));
        assert_eq(200, p9_admin('admin/index.php')['status']);
        assert_eq(200, p9_api('')['status']);
        assert_eq(200, p9_public('index.php')['status']);
    } finally {
        p9_clear();
    }
});

unit('Phase 9: a signed status "expired" (Central Server syncExpiry) inside grace is grace, not a lock', function () {
    p9_clear();
    try {
        p9_seed(-2 * P9_DAY, ['status' => 'expired']);
        $res = p9_admin('admin/index.php');
        assert_eq(200, $res['status'], '03 §1: "expired" is the grace trigger, not an immediate lock');
        assert_eq('grace', p9_banner($res['body']));
    } finally {
        p9_clear();
    }
});

// ── C. After grace ───────────────────────────────────────────────────────

unit('Phase 9: exactly at expires_at + 7 days the application is locked (admin, API, public)', function () {
    p9_clear();
    try {
        p9_seed(-7 * P9_DAY); // the child's clock is >= the parent's: at or past the exact boundary
        assert_eq(403, p9_admin('admin/index.php')['status']);
        $api = p9_api('');
        assert_eq(403, $api['status']);
        assert_true(str_contains($api['body'], 'LICENSE_INACTIVE'));
        assert_eq(403, p9_public('index.php')['status']);
    } finally {
        p9_clear();
    }
});

unit('Phase 9: past grace with a signed status "expired" is locked', function () {
    p9_clear();
    try {
        p9_seed(-8 * P9_DAY, ['status' => 'expired']);
        assert_eq(403, p9_admin('admin/index.php')['status']);
    } finally {
        p9_clear();
    }
});

// ── D. Grace never rescues a higher-priority lock ───────────────────────

unit('Phase 9: suspended and revoked lock even within nominal expiry and within the grace window', function () {
    p9_clear();
    try {
        foreach (['suspended', 'revoked'] as $status) {
            foreach ([30 * P9_DAY, -2 * P9_DAY] as $offset) {
                p9_seed($offset, ['status' => $status]);
                assert_eq(403, p9_admin('admin/index.php')['status'], "$status at offset $offset must lock");
            }
        }
    } finally {
        p9_clear();
    }
});

unit('Phase 9: an untrusted row (installation mismatch) inside grace or before expiry is locked', function () {
    p9_clear();
    try {
        p9_ensure_identity(p9_identity());
        foreach ([30 * P9_DAY, -2 * P9_DAY] as $offset) {
            license_test_seed_cache(current_tenant_id(), [
                'status' => 'active', 'plan' => 'pro', 'entitlements' => ['forms', 'membership', 'booking'],
                'expires_at' => gmdate('Y-m-d H:i:s', time() + $offset), 'fetched_at' => gmdate('Y-m-d H:i:s'),
                'installation_id' => str_repeat('6', 32), // a different installation's state
            ]);
            assert_eq(403, p9_admin('admin/index.php')['status'], "untrusted at offset $offset must lock");
        }
    } finally {
        p9_clear();
    }
});

unit('Phase 9: a stale snapshot is locked even inside commercial grace or before expiry (offline tolerance is separate)', function () {
    p9_clear();
    try {
        foreach ([30 * P9_DAY, -2 * P9_DAY] as $offset) {
            p9_seed($offset, ['fetched_at' => gmdate('Y-m-d H:i:s', time() - 8 * P9_DAY)]);
            $res = p9_admin('admin/index.php');
            assert_eq(403, $res['status'], "stale at expiry offset $offset must lock — neither window extends the other");
        }
        p9_seed(-2 * P9_DAY, ['fetched_at' => gmdate('Y-m-d H:i:s', time() - 8 * P9_DAY)]);
        $lic = p9_admin('admin/license.php');
        assert_eq('stale', p9_state($lic['body']), 'the recovery screen names verification, not commercial expiry');
    } finally {
        p9_clear();
    }
});

unit('Phase 9: a malformed (zero-date) expires_at in the cache fails closed', function () {
    p9_clear();
    try {
        p9_seed(30 * P9_DAY);
        try {
            Database::query("UPDATE remote_license_cache SET expires_at = '0000-00-00 00:00:00' WHERE tenant_id = ?", [current_tenant_id()]);
        } catch (\Throwable $e) {
            return; // strict SQL mode refuses zero dates outright — nothing malformed can be stored
        }
        $stored = (string) Database::value('SELECT expires_at FROM remote_license_cache WHERE tenant_id = ?', [current_tenant_id()]);
        if (!str_starts_with($stored, '0000-00-00')) return;
        assert_eq(403, p9_admin('admin/index.php')['status'], 'an unreadable expiry is never treated as "no expiry"');
    } finally {
        p9_clear();
    }
});

// ── E. Request-level manipulation (20–27) ───────────────────────────────

unit('Phase 9 security: query parameters (?grace=1, ?expired=false, ?license_status=active, ?now=…) cannot unlock after grace', function () {
    p9_clear();
    try {
        p9_seed(-8 * P9_DAY);
        $q = http_build_query([
            'grace' => '1', 'expired' => 'false', 'license_status' => 'active', 'status' => 'active',
            'now' => gmdate('Y-m-d H:i:s', time() - 30 * P9_DAY), 'time' => (string) (time() - 30 * P9_DAY),
            'expires_at' => '2099-01-01 00:00:00', 'grace_days' => '365',
        ]);
        assert_eq(403, p9_admin('admin/index.php', $q)['status']);
        assert_eq(403, p9_admin('admin/settings.php', $q)['status']);
        assert_eq(403, p9_public('index.php', $q)['status']);
    } finally {
        p9_clear();
    }
});

unit('Phase 9 security: POST fields cannot unlock after grace', function () {
    p9_clear();
    try {
        p9_seed(-8 * P9_DAY);
        $res = p9_admin_post('admin/notifications-read.php', [
            'grace' => '1', 'expired' => 'false', 'license_status' => 'active', 'expires_at' => '2099-01-01 00:00:00',
        ]);
        assert_eq(403, $res['status']);
    } finally {
        p9_clear();
    }
});

unit('Phase 9 security: cookies, session values, request headers and superglobals never influence the lock decision', function () {
    p9_clear();
    $saved = [$_GET, $_POST, $_COOKIE, $_REQUEST, $_SERVER, $_SESSION ?? null];
    try {
        p9_seed(-8 * P9_DAY);
        $forged = ['grace' => '1', 'expired' => 'false', 'license_status' => 'active', 'status' => 'active',
                   'expires_at' => '2099-01-01 00:00:00', 'grace_days' => '365', 'now' => '2020-01-01 00:00:00'];
        $_GET = $forged; $_POST = $forged; $_COOKIE = $forged; $_REQUEST = $forged;
        $_SESSION = $forged + ['slate_license' => ['locked' => false], 'license_grace' => true];
        $_SERVER['HTTP_X_LICENSE_STATUS'] = 'active';
        $_SERVER['HTTP_X_CLIENT_TIME'] = '2020-01-01T00:00:00Z';
        $_SERVER['HTTP_DATE'] = 'Wed, 01 Jan 2020 00:00:00 GMT';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        $state = p9_guard_state();
        assert_true($state['locked'], 'the Guard reads only the trusted cache and the server clock');
        assert_eq('expired', $state['reason']);
    } finally {
        [$_GET, $_POST, $_COOKIE, $_REQUEST, $_SERVER, $sess] = $saved;
        if ($sess === null) { unset($_SESSION); } else { $_SESSION = $sess; }
        p9_clear();
    }
});

unit('Phase 9 security: AJAX, API and direct module URLs are all locked after grace', function () {
    p9_clear();
    try {
        p9_seed(-8 * P9_DAY);
        assert_eq(403, p9_admin('admin/notifications-poll.php')['status'], 'AJAX endpoint');
        $api = p9_api('booking/services');
        assert_eq(403, $api['status'], 'module API');
        assert_true(str_contains($api['body'], 'LICENSE_INACTIVE'), 'the Global Guard (not ModuleGuard) answers first');
        assert_eq(403, p9_admin('plugins/forms/admin/index.php')['status'], 'Forms admin URL');
        assert_eq(403, p9_admin('plugins/membership/admin/index.php')['status'], 'Membership admin URL');
        assert_eq(403, p9_admin('plugins/booking/admin/index.php')['status'], 'Booking admin URL');
        assert_eq(403, p9_public('plugins/forms/public/router.php')['status'], 'Forms public URL');
    } finally {
        p9_clear();
    }
});

unit('Phase 9 security: Super Admin and Platform Admin sessions do not bypass the post-grace lock', function () {
    p9_clear();
    try {
        p9_seed(-8 * P9_DAY);
        assert_eq(403, p9_admin('admin/index.php', '', 1, '0')['status'], 'Super Admin');
        assert_eq(403, p9_admin('admin/index.php', '', 1, '1')['status'], 'Platform Admin');
    } finally {
        p9_clear();
    }
});

unit('Phase 9 security: MCP module tools are refused after grace', function () {
    p9_clear();
    try {
        p9_seed(-8 * P9_DAY);
        assert_false(p9_mcp('slate_forms_list_definitions'));
        assert_false(p9_mcp('slate_booking_list_services'));
        assert_false(p9_mcp('slate_membership_list_plans'));
    } finally {
        p9_clear();
    }
});

// ── F. Modules during grace (30–33) ─────────────────────────────────────

unit('Phase 9 modules: entitled Forms, Membership and Booking keep working during grace (admin, public, API, MCP, cron)', function () {
    p9_clear();
    try {
        p9_seed(-3 * P9_DAY);
        assert_eq(200, p9_admin('plugins/forms/admin/index.php')['status'], 'Forms admin');
        assert_eq(200, p9_admin('plugins/membership/admin/index.php')['status'], 'Membership admin');
        assert_eq(200, p9_admin('plugins/booking/admin/index.php')['status'], 'Booking admin');
        assert_eq(200, p9_api('booking/services')['status'], 'Booking API');
        assert_true(p9_mcp('slate_forms_list_definitions'), 'Forms MCP');
        assert_true(p9_mcp('slate_booking_list_services'), 'Booking MCP');
        assert_true(p9_mcp('slate_membership_list_plans'), 'Membership MCP');
        assert_eq('ALLOW', p9_module_cron('forms'));
        assert_eq('ALLOW', p9_module_cron('membership'));
        assert_eq('ALLOW', p9_module_cron('booking'), 'module background jobs continue during grace');
    } finally {
        p9_clear();
    }
});

unit('Phase 9 modules: an unentitled module stays blocked during grace — grace never adds entitlements', function () {
    p9_clear();
    try {
        p9_seed(-3 * P9_DAY, ['entitlements' => ['forms']]);
        assert_eq(200, p9_admin('plugins/forms/admin/index.php')['status'], 'sanity: entitled Forms works');
        assert_eq(403, p9_admin('plugins/booking/admin/index.php')['status'], 'unentitled Booking stays blocked');
        assert_eq(403, p9_admin('plugins/membership/admin/index.php')['status'], 'unentitled Membership stays blocked');
        $api = p9_api('booking/services');
        assert_eq(403, $api['status']);
        assert_true(str_contains($api['body'], 'MODULE_NOT_ENTITLED'));
        assert_eq('DENY', p9_module_cron('booking'));
        assert_false(p9_mcp('slate_booking_list_services'));
    } finally {
        p9_clear();
    }
});

unit('Phase 9 modules: after grace, module background jobs stop (same lock, no second cron mechanism)', function () {
    p9_clear();
    try {
        p9_seed(-7 * P9_DAY);
        assert_eq('DENY', p9_module_cron('forms'));
        assert_eq('DENY', p9_module_cron('membership'));
        assert_eq('DENY', p9_module_cron('booking'));
    } finally {
        p9_clear();
    }
});

unit('Phase 9 cron: cron.php stays reachable after grace — its own CRON_SECRET gate answers, not the license lock', function () {
    p9_clear();
    try {
        p9_seed(-8 * P9_DAY);
        $res = p9_shell('cron-probe.php', ['wrong']);
        assert_eq(403, $res['status']);
        assert_true(str_contains($res['body'], 'forbidden') && !str_contains($res['body'], 'license_inactive'));
    } finally {
        p9_clear();
    }
});

// ── G. Recovery (34–35) ──────────────────────────────────────────────────

unit('Phase 9 recovery: after grace the License page stays reachable, explains the commercial lock, and offers the key form', function () {
    p9_clear();
    try {
        p9_seed(-8 * P9_DAY);
        $res = p9_admin('admin/license.php');
        assert_eq(200, $res['status']);
        assert_eq('expired', p9_state($res['body']));
        assert_true(str_contains($res['body'], 'currently <strong>locked</strong>'));
        assert_true(str_contains($res['body'], 'grace period has ended'));
        assert_true(str_contains($res['body'], 'data-license-grace-ends'), 'the grace end date is shown');
        assert_true(str_contains($res['body'], 'name="license_key"'), 'the recovery form is present');
        assert_null(p9_banner($res['body']), 'no "still usable" grace banner once locked');
        assert_eq(200, p9_public('admin/login.php')['status'], 'login stays reachable to get to recovery');
    } finally {
        p9_clear();
    }
});

/** The in-process Guard decision, verifying the cache with the key the probes are configured with. */
function p9_guard_state(): array {
    $previous = $_ENV['LICENSE_SERVER_PUBLIC_KEY'] ?? null;
    $_ENV['LICENSE_SERVER_PUBLIC_KEY'] = license_test_public_key();
    try {
        return slate_license_guard_state();
    } finally {
        if ($previous === null) unset($_ENV['LICENSE_SERVER_PUBLIC_KEY']); else $_ENV['LICENSE_SERVER_PUBLIC_KEY'] = $previous;
    }
}

/** Signs a payload as the Central Server stand-in and returns [publicKeyB64, transport] for RemoteLicenseClient. */
function p9_signed_transport(array $payload): array {
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $body = json_encode(['payload' => $json, 'signature' => license_test_sign($json)]);
    return [license_test_public_key(), static fn(string $url, string $b): array => [200, $body]];
}

function p9_check_in(string $publicKey, callable $transport): bool {
    require_once dirname(__DIR__, 2) . '/plugins/licensing/client/RemoteLicenseClient.php';
    $client = new RemoteLicenseClient([
        'server_url' => 'https://license-phase9.test', 'public_key' => $publicKey, 'product' => 'kohevo',
        'license_key' => 'phase9-test-key', 'install_id' => p9_identity(), 'domain' => 'localhost',
    ], new SlateLicenseCacheStore(current_tenant_id(), $publicKey), $transport);
    return $client->checkIn();
}

unit('Phase 9 recovery: a renewed, signed check-in through the existing RemoteLicenseClient restores access after the lock', function () {
    p9_clear();
    try {
        p9_seed(-8 * P9_DAY, ['status' => 'expired']);
        assert_eq(403, p9_admin('admin/index.php')['status'], 'precondition: locked past grace');

        [$pub, $transport] = p9_signed_transport([
            'installation_id' => p9_identity(), 'status' => 'active', 'plan' => 'pro',
            'entitlements' => ['forms', 'membership', 'booking'],
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 365 * P9_DAY),
            'checked_at' => gmdate('c'), 'next_check_after' => 86400,
        ]);
        assert_true(p9_check_in($pub, $transport), 'the renewed state is accepted through the existing check-in path');

        assert_false(p9_guard_state()['locked']);
        $res = p9_admin('admin/index.php');
        assert_eq(200, $res['status'], 'renewal restores normal access with no other intervention');
        assert_null(p9_banner($res['body']), 'no expiry banner after renewal to a year out');
        assert_eq(200, p9_admin('plugins/booking/admin/index.php')['status'], 'modules return with the renewed entitlements');
    } finally {
        p9_clear();
    }
});

unit('Phase 9 recovery: an extension received during grace ends grace immediately', function () {
    p9_clear();
    try {
        p9_seed(-2 * P9_DAY);
        [$pub, $transport] = p9_signed_transport([
            'installation_id' => p9_identity(), 'status' => 'active', 'plan' => 'pro',
            'entitlements' => ['forms', 'membership', 'booking'],
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 30 * P9_DAY), 'checked_at' => gmdate('c'),
        ]);
        assert_true(p9_check_in($pub, $transport));
        assert_null(p9_banner(p9_admin('admin/index.php')['body']));
    } finally {
        p9_clear();
    }
});

unit('Phase 9 recovery: a signed "suspended" received during grace locks immediately (grace never overrides it)', function () {
    p9_clear();
    try {
        p9_seed(-2 * P9_DAY);
        [$pub, $transport] = p9_signed_transport([
            'installation_id' => p9_identity(), 'status' => 'suspended', 'plan' => 'pro',
            'entitlements' => ['forms'], 'expires_at' => gmdate('Y-m-d H:i:s', time() - 2 * P9_DAY), 'checked_at' => gmdate('c'),
        ]);
        assert_true(p9_check_in($pub, $transport));
        assert_eq(403, p9_admin('admin/index.php')['status']);
        assert_eq('suspended', p9_state(p9_admin('admin/license.php')['body']));
    } finally {
        p9_clear();
    }
});

unit('Phase 9 recovery: a failed check-in (network down) changes nothing — it neither resets nor extends expiry', function () {
    p9_clear();
    try {
        p9_seed(-2 * P9_DAY);
        $before = Database::row('SELECT status, expires_at, fetched_at FROM remote_license_cache WHERE tenant_id = ?', [current_tenant_id()]);
        [$pub] = p9_signed_transport([]);
        assert_false(p9_check_in($pub, static fn(string $u, string $b): ?array => null));
        $after = Database::row('SELECT status, expires_at, fetched_at FROM remote_license_cache WHERE tenant_id = ?', [current_tenant_id()]);
        assert_eq($before, $after, 'network failure leaves the trusted state untouched');
        assert_eq('grace', p9_banner(p9_admin('admin/index.php')['body']), 'still in the same grace window');
    } finally {
        p9_clear();
    }
});

unit('Phase 9 recovery: a response signed by a different key cannot extend expiry (tampered response)', function () {
    p9_clear();
    try {
        p9_seed(-8 * P9_DAY);
        [, $transport] = p9_signed_transport([
            'installation_id' => p9_identity(), 'status' => 'active', 'plan' => 'pro', 'entitlements' => ['forms'],
            'expires_at' => '2099-01-01 00:00:00', 'checked_at' => gmdate('c'),
        ]);
        $otherPub = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
        assert_false(p9_check_in($otherPub, $transport), 'signature does not verify against the configured key');
        assert_eq(403, p9_admin('admin/index.php')['status'], 'still locked');
    } finally {
        p9_clear();
    }
});
