<?php
/**
 * Phase 2: Central Licensing Platform Foundation.
 *
 * Exercises the new Plan/License/Installation domain services
 * (plugins/licensing/{PlanService,LicenseService,InstallationService}.php)
 * and the schema they sit on (migrations/0025_commercial_licensing_
 * rebuild.sql) directly against the shared test DB — same reasoning as
 * LicensingPluginSchemaTest.php: this plugin runs on its own dedicated,
 * single-tenant install, so there is no zip-install path to drive instead.
 *
 * Every table this migration adds is torn down after each test via the
 * plugin's own uninstall.sql (licplug_teardown(), tests/unit/harness.php),
 * which was extended alongside this migration so it never leaks into the
 * shared test DB for other suites.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/licensing/LicensingAPI.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/PlanService.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/LicenseService.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/InstallationService.php';

/** Shared fixture: a product + client every test in this file needs. */
function clf_fixture(): array {
    return [
        'product_id' => Database::insert('licensing_products', ['slug' => 'kohevo', 'name' => 'Kohevo']),
        'client_id'  => Database::insert('licensing_clients', ['name' => 'Acme Surveys', 'email' => 'ops@acme.example']),
    ];
}

// ── 1. Plan creation/persistence ────────────────────────────────────────
unit('central foundation: PlanService creates a plan with description/is_active and a template module set', function () {
    licplug_ensure_schema();
    try {
        $f = clf_fixture();
        $planId = PlanService::create([
            'product_id' => $f['product_id'], 'slug' => 'professional', 'name' => 'Professional',
            'description' => 'Full feature set for a growing business.', 'is_active' => true,
        ]);
        $plan = PlanService::find($planId);
        assert_eq('Professional', $plan['name']);
        assert_eq('professional', $plan['slug']);
        assert_eq('Full feature set for a growing business.', $plan['description']);
        assert_eq(1, (int) $plan['is_active']);

        PlanService::setModules($planId, ['booking', 'membership']);
        $modules = PlanService::modules($planId);
        sort($modules);
        assert_eq(['booking', 'membership'], $modules);
    } finally {
        licplug_teardown();
    }
});

// ── 2. License creation/persistence ─────────────────────────────────────
unit('central foundation: LicenseService::issue persists a License defaulting to status=unactivated and records a create event', function () {
    licplug_ensure_schema();
    try {
        $f = clf_fixture();
        $result = LicenseService::issue([
            'client_id' => $f['client_id'], 'product_id' => $f['product_id'], 'label' => 'Acme production',
        ], 'kohevo');

        assert_true(is_int($result['id']), 'license id must be an int');
        assert_true(str_starts_with($result['license_key'], 'KOHEVO-'), 'license key should carry the product prefix');

        $license = LicenseService::find($result['id']);
        assert_eq('unactivated', $license['status']);
        assert_eq(7, (int) $license['warning_days']);
        assert_eq(7, (int) $license['grace_days']);
        assert_eq(1, (int) $license['activation_limit']);
        assert_eq(64, strlen($license['license_key_hash']));
        assert_eq(hash('sha256', $result['license_key']), $license['license_key_hash']);

        $events = LicenseService::events($result['id']);
        assert_eq(1, count($events));
        assert_eq('create', $events[0]['event_type']);
        assert_eq('system', $events[0]['actor_type']);
    } finally {
        licplug_teardown();
    }
});

// ── 3 & 4. Installation creation / License -> Installation binding ─────
unit('central foundation: InstallationService::activate binds an Installation and transitions the License Unactivated -> Active', function () {
    licplug_ensure_schema();
    try {
        $f = clf_fixture();
        $license = LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id']], 'kohevo');

        $installationId = InstallationService::activate($license['id'], [
            'installation_id' => str_repeat('a', 32), 'domain' => 'acme.example',
        ]);

        assert_true(is_int($installationId), 'installation id must be an int');
        assert_eq('active', LicenseService::find($license['id'])['status']);

        $active = InstallationService::active($license['id']);
        assert_eq($installationId, (int) $active['id']);
        assert_eq('acme.example', $active['domain']);
        assert_eq((int) $license['id'], (int) $active['license_id']);
        assert_eq((int) $license['id'], (int) $active['active_license_id']);

        $events = array_column(LicenseService::events($license['id']), 'event_type');
        assert_true(in_array('activate', $events, true), 'an activate event must be recorded');
    } finally {
        licplug_teardown();
    }
});

// ── 5. Duplicate installation binding rejection ─────────────────────────
unit('central foundation: a second active Installation for the same License is rejected (activation_limit default 1)', function () {
    licplug_ensure_schema();
    try {
        $f = clf_fixture();
        $license = LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id']], 'kohevo');
        InstallationService::activate($license['id'], ['installation_id' => str_repeat('a', 32), 'domain' => 'acme.example']);

        assert_throws(\RuntimeException::class, function () use ($license) {
            InstallationService::activate($license['id'], ['installation_id' => str_repeat('b', 32), 'domain' => 'acme2.example']);
        }, 'a second active binding must be rejected');

        // Rejected at the domain layer AND the schema layer would reject it too
        // (uniq_installation_active_license) — confirm only one active row exists.
        assert_eq(1, (int) Database::value(
            "SELECT COUNT(*) FROM licensing_installations WHERE license_id = ? AND status = 'active'",
            [$license['id']]
        ));
    } finally {
        licplug_teardown();
    }
});

// ── 6 & 7. Entitlement persistence + independent optional-module combos ─
unit('central foundation: LicenseService entitlements persist independently across all 8 optional-module combinations', function () {
    licplug_ensure_schema();
    try {
        $f = clf_fixture();
        $combos = [
            [], ['forms'], ['membership'], ['booking'],
            ['forms', 'membership'], ['forms', 'booking'], ['membership', 'booking'],
            ['forms', 'membership', 'booking'],
        ];
        foreach ($combos as $combo) {
            $license = LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id'], 'modules' => $combo], 'kohevo');
            $granted = LicenseService::modules($license['id']);
            sort($granted);
            $expected = $combo;
            sort($expected);
            assert_eq($expected, $granted);
        }
    } finally {
        licplug_teardown();
    }
});

// ── 8. Core entitlement behavior ─────────────────────────────────────────
unit('central foundation: Core modules can never be granted as explicit license_modules rows (INV-06)', function () {
    licplug_ensure_schema();
    try {
        $f = clf_fixture();
        $license = LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id']], 'kohevo');

        foreach (LicenseService::CORE_MODULE_KEYS as $coreKey) {
            assert_throws(\InvalidArgumentException::class, function () use ($license, $coreKey) {
                LicenseService::grantModules($license['id'], [$coreKey]);
            }, "granting core key '$coreKey' must be rejected");
        }
        assert_eq([], LicenseService::modules($license['id']));

        // Core access is implicit — governed by license status, not a row.
        InstallationService::activate($license['id'], ['installation_id' => str_repeat('a', 32), 'domain' => 'acme.example']);
        $active = LicenseService::find($license['id']);
        assert_true(LicenseService::hasCoreAccess($active), 'Core must be accessible once the license is Active');

        LicenseService::revoke($license['id'], null, 'test');
        $revoked = LicenseService::find($license['id']);
        assert_false(LicenseService::hasCoreAccess($revoked), 'Core must not be accessible once revoked');
    } finally {
        licplug_teardown();
    }
});

// ── 9 & 10. Installation soft-deactivation + historical preservation ────
unit('central foundation: InstallationService::reset soft-deactivates the old binding and preserves full history', function () {
    licplug_ensure_schema();
    try {
        $f = clf_fixture();
        $license = LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id']], 'kohevo');
        $oldId = InstallationService::activate($license['id'], ['installation_id' => str_repeat('a', 32), 'domain' => 'acme-old.example']);

        $newId = InstallationService::reset($license['id'], [
            'installation_id' => str_repeat('b', 32), 'domain' => 'acme-new.example',
        ], null, 'server migration');

        $old = InstallationService::find($oldId);
        assert_eq('superseded', $old['status']);
        assert_true($old['deleted_at'] !== null, 'a superseded row must carry a deleted_at timestamp');
        assert_eq('acme-old.example', $old['domain'], 'superseded row must retain its original domain (history preserved, not wiped)');

        $new = InstallationService::find($newId);
        assert_eq('active', $new['status']);
        assert_null($new['deleted_at']);

        $history = InstallationService::history($license['id']);
        assert_eq(2, count($history), 'both the old and new installation rows must remain queryable');

        // A reset must not consume a second activation slot — one active row only.
        assert_eq(1, (int) Database::value(
            "SELECT COUNT(*) FROM licensing_installations WHERE license_id = ? AND status = 'active'",
            [$license['id']]
        ));
    } finally {
        licplug_teardown();
    }
});

// ── 11. Foreign-key enforcement ──────────────────────────────────────────
unit('central foundation: FK RESTRICT blocks deleting a License with an active Installation; FK CASCADE cleans up modules/events', function () {
    licplug_ensure_schema();
    try {
        $f = clf_fixture();
        $bound = LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id']], 'kohevo');
        InstallationService::activate($bound['id'], ['installation_id' => str_repeat('a', 32), 'domain' => 'acme.example']);

        assert_throws(\PDOException::class, function () use ($bound) {
            Database::delete('licensing_licenses', 'id = ?', [$bound['id']]);
        }, 'deleting a License with an active Installation must violate the RESTRICT FK');

        // A license with no Installation ever bound can be deleted — its
        // modules/events rows must cascade away with it.
        $unbound = LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id'], 'modules' => ['forms']], 'kohevo');
        assert_true(count(LicenseService::modules($unbound['id'])) > 0);
        assert_true(count(LicenseService::events($unbound['id'])) > 0);

        Database::delete('licensing_licenses', 'id = ?', [$unbound['id']]);

        assert_eq(0, (int) Database::value('SELECT COUNT(*) FROM licensing_license_modules WHERE license_id = ?', [$unbound['id']]));
        assert_eq(0, (int) Database::value('SELECT COUNT(*) FROM licensing_license_events WHERE license_id = ?', [$unbound['id']]));
    } finally {
        licplug_teardown();
    }
});

// ── 12. Invalid lifecycle transitions ────────────────────────────────────
unit('central foundation: License lifecycle operations reject invalid preconditions', function () {
    licplug_ensure_schema();
    try {
        $f = clf_fixture();
        $license = LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id']], 'kohevo');
        $id = $license['id'];

        // Suspend is not valid from Unactivated (docs/03-LICENSE-LIFECYCLE.md §3/§5).
        assert_throws(\InvalidArgumentException::class, fn() => LicenseService::suspend($id, null, 'test'));
        // Renew is only valid from Expired.
        assert_throws(\InvalidArgumentException::class, fn() => LicenseService::renew($id, '2099-01-01 00:00:00', null));

        InstallationService::activate($id, ['installation_id' => str_repeat('a', 32), 'domain' => 'acme.example']);
        LicenseService::suspend($id, null, 'nonpayment');
        assert_eq('suspended', LicenseService::find($id)['status']);

        LicenseService::revoke($id, null, 'fraud');
        assert_eq('revoked', LicenseService::find($id)['status']);

        // Revoked is terminal.
        assert_throws(\InvalidArgumentException::class, fn() => LicenseService::revoke($id, null, 'again'));
        assert_throws(\InvalidArgumentException::class, fn() => LicenseService::suspend($id, null, 'again'));
        assert_throws(\InvalidArgumentException::class, fn() => LicenseService::extend($id, '2099-01-01 00:00:00', null));

        // Operating on a license that doesn't exist at all.
        assert_throws(\InvalidArgumentException::class, fn() => LicenseService::suspend(999999, null, 'x'));
    } finally {
        licplug_teardown();
    }
});

// ── 13. Transaction rollback on failure ──────────────────────────────────
unit('central foundation: LicenseService::issue rolls back the whole license row if an entitlement grant fails mid-transaction', function () {
    licplug_ensure_schema();
    try {
        $f = clf_fixture();
        $before = (int) Database::value('SELECT COUNT(*) FROM licensing_licenses');

        assert_throws(\InvalidArgumentException::class, function () use ($f) {
            LicenseService::issue([
                'client_id' => $f['client_id'], 'product_id' => $f['product_id'],
                'modules' => ['forms', 'dashboard'], // 'dashboard' is Core -> must abort the whole issuance
            ], 'kohevo');
        });

        $after = (int) Database::value('SELECT COUNT(*) FROM licensing_licenses');
        assert_eq($before, $after, 'no partial license row should remain after a failed issuance');
    } finally {
        licplug_teardown();
    }
});

// ── 14. Idempotency ──────────────────────────────────────────────────────
unit('central foundation: activation and plan-module updates are idempotent where required', function () {
    licplug_ensure_schema();
    try {
        $f = clf_fixture();

        $license = LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id']], 'kohevo');
        LicenseService::activate($license['id']);
        LicenseService::activate($license['id']); // must not throw, must not regress status
        assert_eq('active', LicenseService::find($license['id'])['status']);

        $planId = PlanService::create(['product_id' => $f['product_id'], 'slug' => 'starter', 'name' => 'Starter']);
        PlanService::setModules($planId, ['forms', 'booking']);
        PlanService::setModules($planId, ['forms', 'booking']); // re-applying the same set is a no-op
        $modules = PlanService::modules($planId);
        sort($modules);
        assert_eq(['booking', 'forms'], $modules);

        LicenseService::grantModules($license['id'], ['forms']);
        LicenseService::grantModules($license['id'], ['forms']); // granting an already-granted module must not duplicate or error
        assert_eq(['forms'], LicenseService::modules($license['id']));
    } finally {
        licplug_teardown();
    }
});

// ── 15. Existing Central application functionality is untouched ────────
unit('central foundation: the pre-existing licensing_installs/licensing_plans (entitlements_json) path still works unmodified', function () {
    licplug_ensure_schema();
    try {
        $f = clf_fixture();

        // The legacy, still-in-use admin/plans.php write path.
        $planId = Database::insert('licensing_plans', [
            'product_id' => $f['product_id'], 'slug' => 'legacy', 'name' => 'Legacy Plan',
            'entitlements_json' => json_encode(['white_label']),
        ]);
        $plan = Database::row('SELECT * FROM licensing_plans WHERE id = ?', [$planId]);
        assert_eq(json_encode(['white_label']), $plan['entitlements_json']);
        assert_eq(1, (int) $plan['is_active'], 'new is_active column must default to 1 for a row the legacy code never sets it on');

        // The legacy licensing_installs path, exercised the same way
        // LicensingPluginSchemaTest.php already does (that pre-existing
        // suite is the authority for this table; not duplicated in full
        // here — this only confirms Phase 2's migration didn't disturb it).
        $installId = Database::insert('licensing_installs', [
            'client_id' => $f['client_id'], 'product_id' => $f['product_id'], 'plan_id' => $planId,
            'label' => 'Legacy install', 'domain' => 'legacy.example',
            'license_key_hash' => hash('sha256', 'legacy-test-key'), 'status' => 'active',
        ]);
        $install = Database::row('SELECT * FROM licensing_installs WHERE id = ?', [$installId]);
        assert_eq('legacy.example', $install['domain']);
        assert_eq('active', $install['status']);
    } finally {
        licplug_teardown();
    }
});

// ── QA fix round — Finding 1: suspended/revoked/expired reset bypass ────
unit('QA fix (Finding 1): InstallationService::activate/reset reject Suspended, Revoked, and Expired licenses', function () {
    licplug_ensure_schema();
    try {
        $f = clf_fixture();

        foreach (['suspended', 'revoked', 'expired', 'cancelled'] as $badStatus) {
            $license = LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id']], 'kohevo');
            Database::update('licensing_licenses', ['status' => $badStatus], 'id = ?', [$license['id']]);

            assert_throws(\InvalidArgumentException::class, function () use ($license) {
                InstallationService::activate($license['id'], ['installation_id' => str_repeat('a', 32), 'domain' => 'acme.example']);
            }, "activate() must reject a '$badStatus' license");

            assert_throws(\InvalidArgumentException::class, function () use ($license) {
                InstallationService::reset($license['id'], ['installation_id' => str_repeat('b', 32), 'domain' => 'acme2.example'], null, 'test');
            }, "reset() must reject a '$badStatus' license");

            assert_null(InstallationService::active($license['id']), "no installation must have been bound to the '$badStatus' license");
        }
    } finally {
        licplug_teardown();
    }
});

unit('QA fix (Finding 1): reset() on a still-Unactivated license also transitions the License to Active (no internally-inconsistent state)', function () {
    licplug_ensure_schema();
    try {
        $f = clf_fixture();
        $license = LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id']], 'kohevo');
        assert_eq('unactivated', LicenseService::find($license['id'])['status']);

        $installationId = InstallationService::reset($license['id'], [
            'installation_id' => str_repeat('a', 32), 'domain' => 'acme.example',
        ], null, 'first install via reset');

        assert_eq('active', LicenseService::find($license['id'])['status'], 'reset() must drive Unactivated -> Active, not leave it internally inconsistent');
        assert_eq($installationId, (int) InstallationService::active($license['id'])['id']);
    } finally {
        licplug_teardown();
    }
});

// ── QA fix round — Finding 2: cross-license IDOR on installation revoke ─
unit('QA fix (Finding 2): InstallationService::revoke() rejects revoking an installation that belongs to a different license', function () {
    licplug_ensure_schema();
    try {
        $f = clf_fixture();
        $licenseA = LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id']], 'kohevo');
        $licenseB = LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id']], 'kohevo');
        $installB = InstallationService::activate($licenseB['id'], ['installation_id' => str_repeat('b', 32), 'domain' => 'b.example']);

        assert_throws(\InvalidArgumentException::class, function () use ($installB, $licenseA) {
            InstallationService::revoke($installB, $licenseA['id']);
        }, 'revoking installB while claiming ownership under licenseA must be rejected');

        assert_eq('active', InstallationService::find($installB)['status'], 'the mismatched revoke must not have modified installB at all');

        // The legitimate owner can still revoke it.
        InstallationService::revoke($installB, $licenseB['id']);
        assert_eq('revoked', InstallationService::find($installB)['status']);
    } finally {
        licplug_teardown();
    }
});

// ── QA fix round — Finding 3: no duplicate activate events ──────────────
unit('QA fix (Finding 3): a single Installation activation records exactly one activate event, carrying installation metadata', function () {
    licplug_ensure_schema();
    try {
        $f = clf_fixture();
        $license = LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id']], 'kohevo');
        $installId = InstallationService::activate($license['id'], ['installation_id' => str_repeat('a', 32), 'domain' => 'acme.example']);

        $activateEvents = array_values(array_filter(LicenseService::events($license['id']), fn($e) => $e['event_type'] === 'activate'));
        assert_eq(1, count($activateEvents), 'exactly one activate event must be recorded for one logical activation');
        $metadata = json_decode((string) $activateEvents[0]['metadata_json'], true);
        assert_eq($installId, $metadata['installation_id'] ?? null, 'the single activate event must carry the installation-specific metadata');
    } finally {
        licplug_teardown();
    }
});

// ── QA fix round — Finding 4: Renew is reachable (Active -> Expired) ────
unit('QA fix (Finding 4): LicenseService::syncExpiry/sweepExpired persist Active -> Expired once expires_at has passed, making renew() reachable', function () {
    licplug_ensure_schema();
    try {
        $f = clf_fixture();
        $license = LicenseService::issue([
            'client_id' => $f['client_id'], 'product_id' => $f['product_id'],
            'expires_at' => date('Y-m-d H:i:s', time() - 3600),
        ], 'kohevo');
        InstallationService::activate($license['id'], ['installation_id' => str_repeat('a', 32), 'domain' => 'acme.example']);
        assert_eq('active', LicenseService::find($license['id'])['status'], 'setup: still stored as active before the sync');

        // Renew must be unreachable before the transition is persisted.
        assert_throws(\InvalidArgumentException::class, fn() => LicenseService::renew($license['id'], date('Y-m-d H:i:s', time() + 86400 * 30), null));

        $updated = LicenseService::syncExpiry($license['id']);
        assert_eq('expired', $updated['status'], 'syncExpiry() must persist the Active -> Expired transition once past expires_at');

        $expireEvents = array_values(array_filter(LicenseService::events($license['id']), fn($e) => $e['event_type'] === 'expire'));
        assert_eq(1, count($expireEvents), 'the expiry transition must be audited');

        // Renew is now reachable.
        LicenseService::renew($license['id'], date('Y-m-d H:i:s', time() + 86400 * 30), null);
        assert_eq('active', LicenseService::find($license['id'])['status']);

        // sweepExpired() must be idempotent and count only real transitions.
        assert_eq(0, LicenseService::sweepExpired(), 'nothing left to sweep after the license was already renewed');
    } finally {
        licplug_teardown();
    }
});

// ── QA fix round — Finding 5: domain/date validation, no raw disclosure ─
unit('QA fix (Finding 5): InstallationService rejects an invalid domain rather than storing a NULL normalized domain', function () {
    licplug_ensure_schema();
    try {
        $f = clf_fixture();
        $license = LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id']], 'kohevo');

        assert_throws(\InvalidArgumentException::class, function () use ($license) {
            InstallationService::activate($license['id'], [
                'installation_id' => str_repeat('a', 32), 'domain' => 'http://acme.example/some/path?x=1',
            ]);
        }, 'a domain with a path/query must be rejected, not silently stored with a NULL normalized domain');

        assert_null(InstallationService::active($license['id']));
    } finally {
        licplug_teardown();
    }
});

unit('QA fix (Finding 5): LicenseService::parseDate() rejects malformed dates and renew()/extend() reject a non-future expiry', function () {
    licplug_ensure_schema();
    try {
        assert_throws(\InvalidArgumentException::class, fn() => LicenseService::parseDate('not-a-date'));
        assert_throws(\InvalidArgumentException::class, fn() => LicenseService::parseDate('2024-02-30')); // not a real calendar date
        assert_eq('2030-01-15 00:00:00', LicenseService::parseDate('2030-01-15'));

        $f = clf_fixture();
        $license = LicenseService::issue([
            'client_id' => $f['client_id'], 'product_id' => $f['product_id'],
            'expires_at' => date('Y-m-d H:i:s', time() - 3600),
        ], 'kohevo');
        InstallationService::activate($license['id'], ['installation_id' => str_repeat('a', 32), 'domain' => 'acme.example']);
        LicenseService::syncExpiry($license['id']);

        assert_throws(\InvalidArgumentException::class, function () use ($license) {
            LicenseService::renew($license['id'], date('Y-m-d H:i:s', time() - 86400), null);
        }, 'renew() must reject a new expiry date that is not in the future');
    } finally {
        licplug_teardown();
    }
});

// ── QA fix round — Finding 6: foreign reference validation / orphans ────
unit('QA fix (Finding 6): LicenseService::issue() rejects an unknown or cross-product plan_id', function () {
    licplug_ensure_schema();
    try {
        $f = clf_fixture();
        $otherProductId = Database::insert('licensing_products', ['slug' => 'other', 'name' => 'Other Product']);

        assert_throws(\InvalidArgumentException::class, function () use ($f) {
            LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id'], 'plan_id' => 999999], 'kohevo');
        }, 'a nonexistent plan_id must be rejected');

        $planId = PlanService::create(['product_id' => $otherProductId, 'slug' => 'cross', 'name' => 'Cross-product plan']);
        assert_throws(\InvalidArgumentException::class, function () use ($f, $planId) {
            LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id'], 'plan_id' => $planId], 'kohevo');
        }, "a plan belonging to a different product must be rejected");

        $inactivePlanId = PlanService::create(['product_id' => $f['product_id'], 'slug' => 'retired', 'name' => 'Retired', 'is_active' => false]);
        assert_throws(\InvalidArgumentException::class, function () use ($f, $inactivePlanId) {
            LicenseService::issue(['client_id' => $f['client_id'], 'product_id' => $f['product_id'], 'plan_id' => $inactivePlanId], 'kohevo');
        }, 'an inactive plan must be rejected for new issuance');
    } finally {
        licplug_teardown();
    }
});

unit('QA fix (Finding 6): PlanService::create() rejects an unknown product_id', function () {
    licplug_ensure_schema();
    try {
        assert_throws(\InvalidArgumentException::class, function () {
            PlanService::create(['product_id' => 999999, 'slug' => 'ghost', 'name' => 'Ghost Plan']);
        });
    } finally {
        licplug_teardown();
    }
});
