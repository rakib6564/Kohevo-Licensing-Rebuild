<?php
/**
 * Phase 13 — the central legacy licensing screens (admin/installs.php,
 * admin/install.php, the legacy entitlements field on admin/plans.php) may
 * only wind a legacy license down; they can no longer grant
 * (docs/03-implementation/PHASE-13-LEGACY-HANDLING.md, LegacyLicensePolicy).
 * Existing legacy licenses keep checking in unchanged, and the restrictive
 * changes made here reach the signed check-in response.
 *
 * Admin requests run the real pages in child processes
 * (tests/fixtures/admin-page-post*-probe.php, valid CSRF, role 1).
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/licensing/LicensingAPI.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/PlanService.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/LicenseService.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/InstallationService.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/LegacyLicensePolicy.php';

function p13c_teardown(): void {
    $sql = preg_replace('/^--.*$/m', '', (string) file_get_contents(__DIR__ . '/../../plugins/licensing/uninstall.sql'));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) Database::query($stmt);
    Database::query("DELETE FROM settings WHERE setting_key IN ('licensing.signing_public_key', 'licensing.signing_secret_key')");
}

/** A legacy license (licensing_installs) on a plan with legacy entitlements, bound to one installation. */
function p13c_setup(): array {
    $prop = new ReflectionProperty(LicensingAPI::class, 'schemaChecked');
    $prop->setValue(null, false);
    LicensingAPI::ensureSchema();
    LicensingAPI::storeSigningKeypair(LicensingAPI::generateSigningKeypair());
    $productId = Database::insert('licensing_products', ['slug' => 'kohevo', 'name' => 'Kohevo']);
    $clientId  = Database::insert('licensing_clients', ['name' => 'P13 Client']);
    $planId    = Database::insert('licensing_plans', [
        'product_id' => $productId, 'slug' => 'legacy-pro', 'name' => 'Legacy Pro',
        'entitlements_json' => json_encode(['forms', 'booking']),
    ]);
    $installId = Database::insert('licensing_installs', [
        'client_id' => $clientId, 'product_id' => $productId, 'plan_id' => $planId,
        'label' => 'P13 legacy', 'domain' => 'legacy.example', 'domain_normalized' => 'legacy.example',
        'license_key_hash' => hash('sha256', 'p13-legacy-key'), 'status' => 'active',
        'expires_at' => '2030-06-01 00:00:00', 'activation_limit' => 2,
    ]);
    return compact('productId', 'clientId', 'planId', 'installId');
}

function p13c_checkin(?string $installationId = null): array {
    $res = LicensingAPI::handleCheckIn([
        'product' => 'kohevo', 'license_key' => 'p13-legacy-key',
        'install_id' => $installationId ?? str_repeat('5', 32), 'domain' => 'legacy.example', 'app_version' => '1.0',
    ], '127.0.0.1');
    $payload = isset($res['body']['payload']) ? json_decode($res['body']['payload'], true) : null;
    return ['status' => $res['http_status'], 'payload' => $payload];
}

function p13c_post(string $page, array $fields, string $query = ''): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-with-query-probe.php') . ' '
         . escapeshellarg('plugins/licensing/admin/' . $page) . ' ' . escapeshellarg($query) . ' '
         . escapeshellarg((string) json_encode($fields)) . ' ' . escapeshellarg('1') . ' ' . escapeshellarg('0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    return ['status' => (int) ($m[1] ?? 0), 'body' => substr($out, strlen($m[0] ?? ''))];
}

function p13c_install(int $id): array {
    return Database::row('SELECT * FROM licensing_installs WHERE id = ?', [$id]);
}

// ── Policy (pure) ────────────────────────────────────────────────────────

unit('Phase 13 policy: only restrictive statuses; expiry only earlier; limit only lower; legacy entitlements only shrink', function () {
    foreach (['expired', 'suspended', 'revoked', 'cancelled'] as $s) assert_true(LegacyLicensePolicy::allowsStatus($s), $s);
    foreach (['trial', 'active', '', 'ACTIVE', 'unknown'] as $s) assert_false(LegacyLicensePolicy::allowsStatus($s), "'$s'");

    assert_eq(['2030-01-01 00:00:00', false], LegacyLicensePolicy::restrictedExpiry('2030-06-01 00:00:00', '2030-01-01 00:00:00'));
    assert_eq(['2030-06-01 00:00:00', true], LegacyLicensePolicy::restrictedExpiry('2030-06-01 00:00:00', '2031-01-01 00:00:00'));
    assert_eq(['2030-06-01 00:00:00', true], LegacyLicensePolicy::restrictedExpiry('2030-06-01 00:00:00', null), 'removing an expiry extends it forever');
    assert_eq(['2030-06-01 00:00:00', false], LegacyLicensePolicy::restrictedExpiry('2030-06-01 00:00:00', '2030-06-01'));
    assert_eq(['2030-01-01 00:00:00', false], LegacyLicensePolicy::restrictedExpiry(null, '2030-01-01'), 'adding an expiry to a perpetual license restricts it');
    assert_eq([null, false], LegacyLicensePolicy::restrictedExpiry(null, null));
    assert_eq(['2030-06-01 00:00:00', true], LegacyLicensePolicy::restrictedExpiry('2030-06-01 00:00:00', 'not a date'));

    assert_eq([1, false], LegacyLicensePolicy::restrictedActivationLimit(2, 1));
    assert_eq([2, true], LegacyLicensePolicy::restrictedActivationLimit(2, 5));
    assert_eq([1, false], LegacyLicensePolicy::restrictedActivationLimit(2, 0), 'floor of 1, as before');

    assert_eq([['forms'], false], LegacyLicensePolicy::restrictedEntitlements(['forms', 'booking'], ['forms']));
    assert_eq([['forms'], true], LegacyLicensePolicy::restrictedEntitlements(['forms', 'booking'], ['forms', 'membership']));
    assert_eq([[], true], LegacyLicensePolicy::restrictedEntitlements([], ['white_label']), 'a new plan starts with none');
});

// ── Existing legacy licenses keep working ────────────────────────────────

unit('Phase 13: an existing legacy license still checks in, signed and installation-bound, exactly as before', function () {
    try {
        $f = p13c_setup();
        $r = p13c_checkin();
        assert_eq(200, $r['status']);
        assert_eq('active', $r['payload']['status']);
        assert_eq(['forms', 'booking'], $r['payload']['entitlements']);
        assert_eq(str_repeat('5', 32), $r['payload']['installation_id']);
        assert_eq(1, (int) Database::value('SELECT COUNT(*) FROM licensing_installation_bindings WHERE install_id = ?', [$f['installId']]));
        assert_eq(0, (int) Database::value('SELECT COUNT(*) FROM licensing_licenses'), 'nothing is migrated into the commercial tables');
        assert_eq(0, (int) Database::value('SELECT COUNT(*) FROM licensing_installations'));
    } finally {
        p13c_teardown();
    }
});

// ── installs.php ─────────────────────────────────────────────────────────

unit('Phase 13: installs.php no longer issues legacy licenses (no row, no key shown)', function () {
    try {
        $f = p13c_setup();
        $res = p13c_post('installs.php', [
            '_action' => 'issue', 'client_id' => (string) $f['clientId'], 'product_id' => (string) $f['productId'],
            'plan_id' => (string) $f['planId'], 'domain' => 'new-legacy.example', 'status' => 'active', 'activation_limit' => '5',
        ]);
        assert_eq(200, $res['status']);
        assert_eq(1, (int) Database::value('SELECT COUNT(*) FROM licensing_installs'), 'no legacy license row is created');
        assert_true(str_contains($res['body'], 'can no longer be issued'), 'the refusal is explained');
        assert_false(str_contains($res['body'], 'KOHEVO-'), 'no raw key is rendered');
        assert_false(str_contains($res['body'], 'name="_action" value="issue"'), 'the issue form is gone');
    } finally {
        p13c_teardown();
    }
});

unit('Phase 13: installs.php status changes — suspend/revoke still apply (and reach check-in); trial/active are refused', function () {
    try {
        $f = p13c_setup();
        p13c_post('installs.php', ['_action' => 'set_status', 'id' => (string) $f['installId'], 'status' => 'suspended']);
        assert_eq('suspended', p13c_install($f['installId'])['status']);
        assert_eq(403, p13c_checkin()['status'], 'a suspended legacy license stops receiving signed state');

        $refusals = fn() => (int) Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'licensing.install_status_refused' AND target = ?", [(string) $f['installId']]);
        $refusedBefore = $refusals();
        foreach (['active', 'trial'] as $grant) {
            p13c_post('installs.php', ['_action' => 'set_status', 'id' => (string) $f['installId'], 'status' => $grant]);
            assert_eq('suspended', p13c_install($f['installId'])['status'], "$grant is refused");
        }
        assert_eq($refusedBefore + 2, $refusals(), 'both refusals are audited');

        p13c_post('installs.php', ['_action' => 'set_status', 'id' => (string) $f['installId'], 'status' => 'revoked', 'reason' => 'p13']);
        assert_eq('revoked', p13c_install($f['installId'])['status']);
    } finally {
        p13c_teardown();
    }
});

// ── install.php ──────────────────────────────────────────────────────────

unit('Phase 13: install.php refuses key regeneration and binding reset; the existing key and binding stay as they were', function () {
    try {
        $f = p13c_setup();
        assert_eq(200, p13c_checkin()['status']);
        $before = p13c_install($f['installId']);
        $q = 'id=' . $f['installId'];

        $res = p13c_post('install.php', ['_action' => 'regenerate'], $q);
        assert_eq(200, $res['status']);
        assert_true(str_contains($res['body'], 'can no longer be re-keyed'));
        assert_eq($before['license_key_hash'], p13c_install($f['installId'])['license_key_hash'], 'key unchanged');

        p13c_post('install.php', ['_action' => 'reset_bindings'], $q);
        assert_eq(1, (int) Database::value('SELECT COUNT(*) FROM licensing_installation_bindings WHERE install_id = ?', [$f['installId']]), 'binding kept');
        assert_eq((int) $before['activation_count'], (int) p13c_install($f['installId'])['activation_count']);

        $page = p13c_post('install.php', ['_action' => 'noop'], $q)['body'];
        assert_false(str_contains($page, 'value="regenerate"'), 'no regenerate button');
        assert_false(str_contains($page, 'value="reset_bindings"'), 'no reset button');
        assert_true(str_contains($page, 'value="delete"'), 'delete is unchanged');
    } finally {
        p13c_teardown();
    }
});

unit('Phase 13: install.php update — label free, expiry only earlier, activation limit only lower; the signed expiry follows', function () {
    try {
        $f = p13c_setup();
        $q = 'id=' . $f['installId'];

        p13c_post('install.php', ['_action' => 'update', 'label' => 'Renamed', 'expires_at' => '2035-01-01', 'activation_limit' => '9'], $q);
        $row = p13c_install($f['installId']);
        assert_eq('Renamed', $row['label']);
        assert_eq('2030-06-01 00:00:00', $row['expires_at'], 'extension refused');
        assert_eq(2, (int) $row['activation_limit'], 'raise refused');

        p13c_post('install.php', ['_action' => 'update', 'label' => 'Renamed', 'expires_at' => '', 'activation_limit' => '2'], $q);
        assert_eq('2030-06-01 00:00:00', p13c_install($f['installId'])['expires_at'], 'clearing the expiry (perpetual) refused');

        p13c_post('install.php', ['_action' => 'update', 'label' => 'Renamed', 'expires_at' => '2029-01-01', 'activation_limit' => '1'], $q);
        $row = p13c_install($f['installId']);
        assert_eq('2029-01-01 00:00:00', $row['expires_at'], 'shortening applies');
        assert_eq(1, (int) $row['activation_limit'], 'lowering applies');
        $r = p13c_checkin();
        assert_eq(200, $r['status']);
        assert_eq('2029-01-01 00:00:00', $r['payload']['expires_at'], 'the shortened expiry is what the client receives');
        assert_eq(403, p13c_checkin(str_repeat('6', 32))['status'], 'a second installation is refused at the lowered limit');
    } finally {
        p13c_teardown();
    }
});

// ── plans.php legacy entitlements ────────────────────────────────────────

unit('Phase 13: plans.php legacy entitlements can be removed but not added; a new plan starts with none; plan modules unaffected', function () {
    try {
        $f = p13c_setup();
        $save = fn(int $id, string $ents, array $modules = []) => p13c_post('plans.php', [
            '_action' => 'save', 'id' => (string) $id, 'product_id' => (string) $f['productId'],
            'name' => 'Legacy Pro', 'slug' => 'legacy-pro', 'is_active' => '1', 'entitlements' => $ents, 'modules' => $modules,
        ]);
        $ents = fn(int $id) => json_decode((string) Database::value('SELECT entitlements_json FROM licensing_plans WHERE id = ?', [$id]), true);

        $save($f['planId'], "forms\nbooking\nmembership\nwhite_label");
        assert_eq(['forms', 'booking'], $ents($f['planId']), 'additions refused');
        assert_eq(['forms', 'booking'], p13c_checkin()['payload']['entitlements']);

        $save($f['planId'], "forms", ['membership']);
        assert_eq(['forms'], $ents($f['planId']), 'removal applies');
        assert_eq(['forms'], p13c_checkin()['payload']['entitlements'], 'and reaches the signed legacy response');
        assert_eq(['membership'], PlanService::modules($f['planId']), 'the current module template still saves');

        p13c_post('plans.php', ['_action' => 'save', 'id' => '0', 'product_id' => (string) $f['productId'],
            'name' => 'Fresh', 'slug' => 'fresh', 'is_active' => '1', 'entitlements' => "forms\nbooking"]);
        $fresh = (int) Database::value("SELECT id FROM licensing_plans WHERE slug = 'fresh'");
        assert_true($fresh > 0);
        assert_eq([], $ents($fresh), 'a new plan has no legacy entitlements');
    } finally {
        p13c_teardown();
    }
});
