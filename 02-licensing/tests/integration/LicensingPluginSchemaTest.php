<?php
/**
 * Phase 1 of the (narrower, chat-agreed) remote license server build: the
 * Licensing plugin's data model only — no admin UI, no check-in endpoint
 * yet. This plugin is meant to run on its own dedicated, single-tenant
 * Kohevo install, so these tests exercise its schema directly against the
 * shared test DB rather than going through a real zip-upload install — the
 * same reasoning first-party bundled plugins already get (Booking,
 * Membership, etc. are never tested via PluginLoader::install()'s zip path
 * either).
 *
 * Every table this plugin creates is torn down after each test via its own
 * uninstall.sql, so it never leaks into the shared test DB for other
 * suites.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/licensing/LicensingAPI.php';

function licplug_teardown(): void {
    $file = __DIR__ . '/../../plugins/licensing/uninstall.sql';
    $sql  = preg_replace('/^--.*$/m', '', (string) file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt === '') continue;
        Database::query($stmt);
    }
}

/**
 * ensureSchema() short-circuits after its first call per process
 * (LicensingAPI::$schemaChecked), which is right for production (boot()
 * only needs to check once) but wrong across these tests — each one drops
 * the tables in its own teardown, so the next test's call must actually
 * re-run the CREATE TABLEs, not no-op against tables that no longer exist.
 */
function licplug_ensure_schema(): void {
    $prop = new ReflectionProperty(LicensingAPI::class, 'schemaChecked');
    $prop->setValue(null, false);
    LicensingAPI::ensureSchema();
}

unit('licensing plugin: manifest is well-formed and passes PluginLoader::validateManifest()', function () {
    $manifest = json_decode((string) file_get_contents(__DIR__ . '/../../plugins/licensing/plugin.json'), true);
    assert_true(is_array($manifest), 'plugin.json must be valid JSON');
    $result = PluginLoader::validateManifest($manifest);
    assert_true($result['ok'], 'validateManifest failed: ' . ($result['error'] ?? ''));
});

unit('licensing plugin: install/uninstall SQL passes PluginLoader::validatePluginSql() — every table carries the "licensing_" prefix, no banned patterns', function () {
    $installSql   = (string) file_get_contents(__DIR__ . '/../../plugins/licensing/install.sql');
    $uninstallSql = (string) file_get_contents(__DIR__ . '/../../plugins/licensing/uninstall.sql');
    $result = PluginLoader::validatePluginSql('licensing', $installSql, $uninstallSql);
    assert_true($result['ok'], 'validatePluginSql failed: ' . ($result['error'] ?? ''));
});

unit('licensing plugin: ensureSchema() creates all five tables and is idempotent (safe to run twice)', function () {
    licplug_ensure_schema();
    try {
        foreach (['licensing_products', 'licensing_clients', 'licensing_plans', 'licensing_installs', 'licensing_checkins'] as $table) {
            $exists = Database::value("SHOW TABLES LIKE '{$table}'");
            assert_true($exists === $table, "table {$table} was not created");
        }
        // Re-run against already-created tables — must not throw, and the
        // table must still be there (CREATE TABLE IF NOT EXISTS is a no-op,
        // not a drop-and-recreate).
        licplug_ensure_schema();
        $exists = Database::value("SHOW TABLES LIKE 'licensing_products'");
        assert_true($exists === 'licensing_products', 'table must still exist after a second ensureSchema() run');
    } finally {
        licplug_teardown();
    }
});

unit('licensing plugin: a product, client, plan, install, and checkin can be inserted and read back', function () {
    licplug_ensure_schema();
    try {
        $productId = Database::insert('licensing_products', ['slug' => 'kohevo', 'name' => 'Kohevo']);
        $clientId  = Database::insert('licensing_clients', ['name' => 'Acme Surveys', 'email' => 'ops@acme.example']);
        $planId    = Database::insert('licensing_plans', [
            'product_id' => $productId, 'slug' => 'pro', 'name' => 'Pro',
            'entitlements_json' => json_encode(['white_label']),
        ]);
        $installId = Database::insert('licensing_installs', [
            'client_id' => $clientId, 'product_id' => $productId, 'plan_id' => $planId,
            'label' => 'Acme production', 'domain' => 'acme.example',
            'license_key_hash' => hash('sha256', 'test-key-1'),
            'status' => 'active',
        ]);
        Database::insert('licensing_checkins', [
            'install_id' => $installId, 'ip' => '203.0.113.7',
            'reported_domain' => 'acme.example', 'reported_version' => '1.0.0',
            'response_status' => 'active',
        ]);

        $install = Database::row('SELECT * FROM licensing_installs WHERE id = ?', [$installId]);
        assert_eq('acme.example', $install['domain']);
        assert_eq('active', $install['status']);
        assert_eq((string)$clientId, (string)$install['client_id']);

        $checkinCount = Database::value('SELECT COUNT(*) FROM licensing_checkins WHERE install_id = ?', [$installId]);
        assert_eq(1, (int)$checkinCount);
    } finally {
        licplug_teardown();
    }
});

unit('licensing plugin: license_key_hash is unique, and domain is unique per product', function () {
    licplug_ensure_schema();
    try {
        $productId = Database::insert('licensing_products', ['slug' => 'kohevo', 'name' => 'Kohevo']);
        $clientId  = Database::insert('licensing_clients', ['name' => 'Acme Surveys']);
        Database::insert('licensing_installs', [
            'client_id' => $clientId, 'product_id' => $productId,
            'domain' => 'acme.example', 'license_key_hash' => hash('sha256', 'dup-key'),
        ]);

        assert_throws(\PDOException::class, function () use ($clientId, $productId) {
            Database::insert('licensing_installs', [
                'client_id' => $clientId, 'product_id' => $productId,
                'domain' => 'a-different-domain.example', 'license_key_hash' => hash('sha256', 'dup-key'),
            ]);
        }, 'duplicate license_key_hash must be rejected');

        assert_throws(\PDOException::class, function () use ($clientId, $productId) {
            Database::insert('licensing_installs', [
                'client_id' => $clientId, 'product_id' => $productId,
                'domain' => 'acme.example', 'license_key_hash' => hash('sha256', 'a-different-key'),
            ]);
        }, 'duplicate (product_id, domain) must be rejected');
    } finally {
        licplug_teardown();
    }
});
