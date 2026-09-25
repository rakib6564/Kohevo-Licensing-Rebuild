<?php
/**
 * Integration parity test (Phase 1 C2): SettingsRepository (new data stack) must
 * return identical results to the legacy Database::setting() path, and enforce
 * tenant scoping structurally.
 *
 * Boots the full app (via tests/integration/run.php). Read-mostly; the one probe
 * row it writes is cleaned up.
 */

declare(strict_types=1);

use Slate\Services\Settings\SettingsRepository;
use Slate\Tenancy\TenantContext;

$repo = new SettingsRepository(new TenantContext());
$tid  = current_tenant_id();

// ── Parity on every existing setting key for this tenant ──
unit('SettingsRepository::value matches Database::setting for existing keys', function () use ($repo, $tid) {
    $keys = array_map('strval', Database::get()
        ->query('SELECT setting_key FROM settings WHERE tenant_id = ' . (int) $tid . ' LIMIT 25')
        ->fetchAll(\PDO::FETCH_COLUMN));
    // There should be at least one core setting to compare.
    assert_true(count($keys) >= 1, 'expected at least one existing setting to compare');
    foreach ($keys as $key) {
        assert_eq(Database::setting($key), $repo->value($key), "parity for key '{$key}'");
    }
});

// ── Parity on a definitely-missing key (both null) ──
unit('SettingsRepository::value matches Database::setting for a missing key', function () use ($repo) {
    $missing = '__no_such_setting_key_parity__';
    assert_eq(Database::setting($missing), $repo->value($missing));
    assert_eq(null, $repo->value($missing));
});

// ── Parity on a freshly written value (probe, cleaned up) ──
unit('parity on a written value, then isolation across tenants', function () use ($repo, $tid) {
    $key = '__c2_parity_probe__';
    Database::delete('settings', 'tenant_id = ? AND setting_key = ?', [$tid, $key]); // pre-clean
    Database::setSetting($key, 'parity-42', $tid);
    try {
        // Both paths read the same value for the current tenant.
        assert_eq('parity-42', Database::setting($key));
        assert_eq('parity-42', $repo->value($key));
        assert_eq(Database::setting($key), $repo->value($key));

        // Tenant isolation: a repo scoped to a different tenant must NOT see it,
        // and must agree with the legacy path run under that tenant.
        $other = $tid + 99999;
        $tenants = new TenantContext();
        $seenByOther = $tenants->runAs($other, fn () => (new SettingsRepository(new TenantContext()))->value($key));
        assert_eq(null, $seenByOther, 'other tenant must not see this tenant\'s setting');
        $legacyOther = $tenants->runAs($other, fn () => Database::setting($key));
        assert_eq($legacyOther, $seenByOther, 'repo and legacy agree under the other tenant');
    } finally {
        Database::delete('settings', 'tenant_id = ? AND setting_key = ?', [$tid, $key]);
    }
});

// ── allAsMap parity with a direct read ──
unit('SettingsRepository::allAsMap matches a direct tenant-scoped read', function () use ($repo, $tid) {
    $direct = [];
    foreach (Database::rows('SELECT setting_key, setting_value FROM settings WHERE tenant_id = ?', [$tid]) as $r) {
        $direct[(string) $r['setting_key']] = $r['setting_value'] === null ? null : (string) $r['setting_value'];
    }
    $viaRepo = $repo->allAsMap();
    ksort($direct);
    ksort($viaRepo);
    assert_eq($direct, $viaRepo);
});
