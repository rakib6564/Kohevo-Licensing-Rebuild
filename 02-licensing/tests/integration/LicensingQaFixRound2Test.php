<?php
/**
 * Phase 3 QA fix round 2 — regression coverage for the two P2 blockers:
 *
 *   1. Raw PDO/SQL exception disclosure in admin/license.php's catch order
 *      (PDOException extends RuntimeException, so it was reaching the
 *      \RuntimeException handler's raw-message fallback before ever
 *      reaching \Throwable) — verified here via a REAL admin-page HTTP
 *      request/response cycle, not just a direct service call, since the
 *      bug lived entirely in admin/license.php's own catch block.
 *   2. PlanService::setModules() accepting a nonexistent plan_id and
 *      inserting orphaned licensing_plan_modules rows, reachable via a
 *      parameter-tampered admin/plans.php `_action=save` POST.
 *
 * Uses the existing admin-page-post-probe.php fixture (already used by
 * tests/integration/LicenseServiceTest.php for the same "run the real admin
 * POST handler in a child process" proof) rather than a new harness.
 *
 * Deliberately does NOT reuse CentralLicensingFoundationTest.php's
 * clf_fixture()/licplug_ensure_schema()/licplug_teardown() — this file must
 * stay loadable on its own regardless of what order a runner requires
 * *Test.php files in, so it defines its own uniquely-named equivalents
 * instead of depending on (or risking redeclaring) those global functions.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/licensing/LicensingAPI.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/PlanService.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/LicenseService.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/InstallationService.php';

function qafix2_ensure_schema(): void {
    $prop = new ReflectionProperty(LicensingAPI::class, 'schemaChecked');
    $prop->setValue(null, false);
    LicensingAPI::ensureSchema();
}

function qafix2_teardown(): void {
    $file = __DIR__ . '/../../plugins/licensing/uninstall.sql';
    $sql  = preg_replace('/^--.*$/m', '', (string) file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt === '') continue;
        Database::query($stmt);
    }
}

function qafix2_fixture(): array {
    return [
        'product_id' => Database::insert('licensing_products', ['slug' => 'kohevo', 'name' => 'Kohevo']),
        'client_id'  => Database::insert('licensing_clients', ['name' => 'Acme Surveys', 'email' => 'ops@acme.example']),
    ];
}

/**
 * Runs an admin page's POST handler in a child process, authenticated as
 * Super Admin (role_id=1, the fixture's default — short-circuits
 * requirePerm('licensing.manage')), via the existing admin-page-post-probe
 * fixture. $page may carry a '?query' suffix for pages that read a scoping
 * id out of $_GET (e.g. admin/license.php?id=5).
 */
function qafix2_post(string $page, array $fields): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
         . escapeshellarg($page) . ' '
         . escapeshellarg((string) json_encode($fields)) . ' '
         . escapeshellarg('1') . ' ' . escapeshellarg('0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    $body = substr($out, strlen($m[0] ?? ''));
    return ['status' => (int) ($m[1] ?? 0), 'body' => $body];
}

// ── P2 Blocker 1: raw PDO/SQL exception disclosure ───────────────────────
unit('QA fix round 2 (P2 Blocker 1A): a duplicate installation_id (a genuine PDOException) never surfaces raw SQL/SQLSTATE/constraint detail to the admin', function () {
    qafix2_ensure_schema();
    try {
        $f = qafix2_fixture();
        $licenseA = LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id']], 'kohevo');
        $licenseB = LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id']], 'kohevo');
        $sharedInstallationId = str_repeat('a', 32);
        InstallationService::activate($licenseA['id'], ['installation_id' => $sharedInstallationId, 'domain' => 'a.example']);

        // Binding the SAME installation_id to a DIFFERENT (still Unactivated)
        // license violates licensing_installations.uniq_installation_identity
        // — a genuine PDOException, not one of our own validation errors.
        $result = qafix2_post('plugins/licensing/admin/license.php?id=' . $licenseB['id'], [
            '_action'         => 'bind_installation',
            'installation_id' => $sharedInstallationId,
            'domain'          => 'b.example',
        ]);

        assert_eq(200, $result['status'], 'the page must degrade to a flash message, not a fatal error');
        foreach (['SQLSTATE', 'PDOException', '23000', 'uniq_installation_identity', 'Integrity constraint', 'licensing_installations'] as $forbidden) {
            assert_false(
                str_contains($result['body'], $forbidden),
                "response must never contain raw database detail '$forbidden'"
            );
        }
        assert_true(
            str_contains($result['body'], 'already in use') || str_contains($result['body'], 'could not be completed'),
            'a safe (generic, or specifically-worded but still safe) error message must still be shown'
        );

        // The mismatched attempt must not have bound anything to licenseB.
        assert_null(InstallationService::active($licenseB['id']));
    } finally {
        qafix2_teardown();
    }
});

unit('QA fix round 2 (P2 Blocker 1A): a legitimate application validation error still reaches the admin (not swallowed by the PDO fix)', function () {
    qafix2_ensure_schema();
    try {
        $f = qafix2_fixture();
        $license = LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id']], 'kohevo');

        $result = qafix2_post('plugins/licensing/admin/license.php?id=' . $license['id'], [
            '_action'         => 'bind_installation',
            'installation_id' => 'not-32-hex-chars',
            'domain'          => 'a.example',
        ]);

        assert_eq(200, $result['status']);
        assert_true(
            str_contains($result['body'], '32 lowercase hex characters'),
            'the deliberate, safe InvalidArgumentException message must still reach the admin'
        );
    } finally {
        qafix2_teardown();
    }
});

// ── P2 Blocker 2: plan module orphan via parameter tampering ─────────────
unit('QA fix round 2 (P2 Blocker 2): PlanService::setModules() rejects a nonexistent plan_id and leaves no orphan rows or side effects', function () {
    qafix2_ensure_schema();
    try {
        assert_throws(\InvalidArgumentException::class, function () {
            PlanService::setModules(999999, ['forms', 'booking']);
        });
        assert_eq(0, (int) Database::value('SELECT COUNT(*) FROM licensing_plan_modules WHERE plan_id = ?', [999999]));

        // A real, existing plan's modules must be unaffected by the rejected call above.
        $f = qafix2_fixture();
        $planId = PlanService::create(['product_id' => $f['product_id'], 'slug' => 'existing', 'name' => 'Existing']);
        PlanService::setModules($planId, ['forms']);
        assert_eq(['forms'], PlanService::modules($planId));

        assert_throws(\InvalidArgumentException::class, function () {
            PlanService::setModules(999999, ['membership']);
        });
        assert_eq(['forms'], PlanService::modules($planId), 'an unrelated real plan must remain untouched');
    } finally {
        qafix2_teardown();
    }
});

unit('QA fix round 2 (P2 Blocker 2): a parameter-tampered admin/plans.php save (_action=save, id=<nonexistent>) cannot create orphaned plan_modules rows', function () {
    qafix2_ensure_schema();
    try {
        $f = qafix2_fixture();

        $result = qafix2_post('plugins/licensing/admin/plans.php', [
            '_action'    => 'save',
            'id'         => 999999,
            'product_id' => $f['product_id'],
            'name'       => 'Tampered',
            'slug'       => 'tampered',
            'modules'    => ['forms', 'booking'],
        ]);

        assert_eq(200, $result['status'], 'a rejected tampered save must degrade to a flash message, not a redirect/fatal error');
        assert_eq(0, (int) Database::value('SELECT COUNT(*) FROM licensing_plan_modules WHERE plan_id = ?', [999999]));
        assert_null(Database::row('SELECT id FROM licensing_plans WHERE id = ?', [999999]), 'no plan must have been created at id 999999 either');
    } finally {
        qafix2_teardown();
    }
});
