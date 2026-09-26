<?php
/**
 * Phase 7: the Module Guard also covers the MCP AI-gateway tool handlers
 * (FormsMcpHandler.php, BookingMcpHandler.php, MembershipMcpHandler.php).
 *
 * These are a distinct API surface from the admin/public/api/v1 entry
 * points ModuleGuardTest.php exercises: a caller reaches them with a valid
 * MCP token carrying scopes (forms.read, booking.write, ...), which is an
 * independent axis of authorization from license entitlement (07 §6) — a
 * token minted with every scope must still be refused a module the current
 * license does not include. Before this phase's fix, `callTool()` in all
 * three handlers checked ONLY the token's scope and never consulted
 * ModuleGuard/EntitlementService at all, so an MCP caller could read and
 * mutate Forms/Booking/Membership data (e.g. actually create a Booking
 * appointment) on an installation where that module was never licensed.
 *
 * Every probe below shells out to tests/fixtures/mcp-tool-call-probe.php in
 * a CHILD process with SLATE_LICENSE_GUARD_LIVE=1, exactly like
 * ModuleGuardTest.php and GlobalLicenseGuardTest.php do — SLATE_TESTING is a
 * define() that cannot be un-set once set, so exercising the real guard
 * in-process inside this shared runner (which defines SLATE_TESTING
 * globally so every OTHER existing Forms/Membership/Booking suite is
 * unaffected by this phase) would permanently bypass ModuleGuard for the
 * rest of the run, not just this one call.
 *
 * McpGatewayAPI::runAsAdmin() (used by the probe) grants every scope key
 * that exists (self::allScopeKeys()), which isolates these assertions to
 * the entitlement check alone — scope can never be the reason a call is
 * refused here.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/license_signing.php';

use Slate\Services\Licensing\SlateLicenseCacheStore;

/** Mirrors ModuleGuardTest.php's mg_env_prefix() — real environment variables for the child process. */
function mcpmg_env_prefix(): string {
    return 'SLATE_LICENSE_GUARD_LIVE=1 '
        . 'LICENSE_SERVER_URL=' . escapeshellarg('https://license.test') . ' '
        . 'LICENSE_SERVER_PUBLIC_KEY=' . escapeshellarg(license_test_public_key()) . ' '
        . 'LICENSE_PRODUCT=' . escapeshellarg('kohevo') . ' '
        . 'LICENSE_KEY=' . escapeshellarg('test-key') . ' ';
}

function mcpmg_call(string $tool, array $args = []): array {
    $cmd = mcpmg_env_prefix() . escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/mcp-tool-call-probe.php') . ' '
         . escapeshellarg($tool) . ' ' . escapeshellarg(json_encode($args))
         . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    $ok = str_starts_with($out, "STATUS OK\n");
    $body = substr($out, strlen($ok ? "STATUS OK\n" : "STATUS ERROR\n"));
    return ['ok' => $ok, 'message' => $body];
}

/** Distinct from ModuleGuardTest's mg_local_identity() ('7'*32) and GlobalLicenseGuardTest's ('d'*32). */
function mcpmg_local_identity(): string { return str_repeat('4', 32); }

function mcpmg_ensure_local_identity(?string $installationId = null): void {
    $installationId ??= mcpmg_local_identity();
    $row = Database::row('SELECT installation_id FROM installation_identity WHERE singleton_id = 1');
    if ($row === null) {
        Database::insert('installation_identity', [
            'singleton_id' => 1, 'tenant_id' => current_tenant_id(), 'installation_id' => $installationId,
        ]);
    } elseif ((string) $row['installation_id'] !== $installationId) {
        Database::update('installation_identity', ['installation_id' => $installationId], 'singleton_id = 1', []);
    }
}

function mcpmg_clear(): void {
    Database::query('DELETE FROM remote_license_cache WHERE tenant_id = ?', [current_tenant_id()]);
}

/** A globally valid, trusted license with exactly the given module entitlements. */
function mcpmg_seed(array $entitlements): void {
    mcpmg_ensure_local_identity();
    license_test_seed_cache(current_tenant_id(), [
        'status' => 'active', 'plan' => 'pro', 'entitlements' => $entitlements,
        'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
        'installation_id' => mcpmg_local_identity(),
    ]);
}

unit('MCP module guard: with no entitlements, Forms/Booking/Membership MCP tools are all refused, even with every scope granted', function () {
    mcpmg_clear();
    try {
        mcpmg_seed([]); // valid license, zero optional modules

        $forms = mcpmg_call('slate_forms_list_definitions');
        assert_false($forms['ok'], 'forms tool must be refused with no forms entitlement');
        assert_true(str_contains($forms['message'], 'not included in your current license'));

        $booking = mcpmg_call('slate_booking_list_services');
        assert_false($booking['ok'], 'booking tool must be refused with no booking entitlement');
        assert_true(str_contains($booking['message'], 'not included in your current license'));

        $membership = mcpmg_call('slate_membership_list_plans');
        assert_false($membership['ok'], 'membership tool must be refused with no membership entitlement');
        assert_true(str_contains($membership['message'], 'not included in your current license'));
    } finally {
        mcpmg_clear();
    }
});

unit('MCP module guard: an unentitled module\'s WRITE tool is refused before any mutation runs (data integrity)', function () {
    mcpmg_clear();
    try {
        mcpmg_seed(['forms']); // booking NOT entitled

        $before = (int) Database::value('SELECT COUNT(*) FROM booking_services', []);
        $res = mcpmg_call('slate_booking_upsert_service', ['name' => 'MCP Guard Probe Service']);
        assert_false($res['ok'], 'an unentitled booking write tool must be refused');
        $after = (int) Database::value('SELECT COUNT(*) FROM booking_services', []);
        assert_eq($before, $after, 'no booking_services row may be created while booking is unentitled');
    } finally {
        mcpmg_clear();
    }
});

unit('MCP module guard: cross-module isolation — Booking-only entitlement does not unlock Forms or Membership MCP tools', function () {
    mcpmg_clear();
    try {
        mcpmg_seed(['booking']);

        assert_true(mcpmg_call('slate_booking_list_services')['ok'], 'sanity: booking IS entitled here');
        assert_false(mcpmg_call('slate_forms_list_definitions')['ok'], 'booking entitlement must not leak to forms');
        assert_false(mcpmg_call('slate_membership_list_plans')['ok'], 'booking entitlement must not leak to membership');
    } finally {
        mcpmg_clear();
    }
});

unit('MCP module guard: all three entitled — every module\'s MCP tools work', function () {
    mcpmg_clear();
    try {
        mcpmg_seed(['forms', 'membership', 'booking']);

        assert_true(mcpmg_call('slate_forms_list_definitions')['ok']);
        assert_true(mcpmg_call('slate_booking_list_services')['ok']);
        assert_true(mcpmg_call('slate_membership_list_plans')['ok']);
    } finally {
        mcpmg_clear();
    }
});

unit('MCP module guard: a cache row bound to a DIFFERENT installation (tampered/copied) cannot unlock a module\'s MCP tools', function () {
    mcpmg_clear();
    try {
        mcpmg_ensure_local_identity(); // this install's real identity ('4'*32)
        license_test_seed_cache(current_tenant_id(), [
            'status' => 'active', 'plan' => 'pro', 'entitlements' => ['forms', 'membership', 'booking'],
            'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
            'installation_id' => str_repeat('9', 32), // a DIFFERENT installation's identity
        ]);

        assert_false(mcpmg_call('slate_forms_list_definitions')['ok'], 'an untrusted row must not grant entitlement to the MCP surface either');
    } finally {
        mcpmg_clear();
    }
});
