<?php
/**
 * Central Dynamic Module System & Entitlement Enforcement Tests.
 *
 * Exercises:
 *   1. Authoritative Central ModuleCatalog (`plugins/licensing/ModuleCatalog.php`)
 *   2. Duplicate module_key / plugin_identifier collision rejection
 *   3. Dependency resolution (`stripe-payment` auto-resolved & deduplicated for `membership` / `booking`)
 *   4. All 8 V1 commercial module combinations across Plan creation, License issuance, and signed check-in payloads
 *   5. Plan -> License entitlement subset enforcement (`License entitlements ⊆ Plan entitlements`)
 *   6. Server-side rejection of forged module keys (`editor`, `content`, `stripe`, `stripe-payment`, `unknown`, duplicates, core, system)
 *   7. Central Admin HTTP POST probes (`admin/plans.php`, `admin/licenses.php`, `admin/license.php`) and Dashboard visibility (`admin/index.php`)
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/licensing/LicensingAPI.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/ModuleCatalog.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/PlanService.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/LicenseService.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/InstallationService.php';
require_once dirname(__DIR__, 2) . '/plugins/licensing/client/LicenseSignatureVerifier.php';

if (!function_exists('licplug_teardown')) {
    function licplug_teardown(): void
    {
        $file = dirname(__DIR__, 2) . '/plugins/licensing/uninstall.sql';
        $sql  = preg_replace('/^--.*$/m', '', (string) file_get_contents($file));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt === '') continue;
            Database::query($stmt);
        }
        Database::query("DELETE FROM settings WHERE setting_key IN ('licensing.signing_public_key', 'licensing.signing_secret_key')");
    }
}

if (!function_exists('licplug_ensure_schema')) {
    function licplug_ensure_schema(): void
    {
        $prop = new ReflectionProperty(LicensingAPI::class, 'schemaChecked');
        $prop->setValue(null, false);
        LicensingAPI::ensureSchema();
    }
}

/**
 * Shared fixture for Central Dynamic Module tests.
 *
 * @return array{product_id:int,client_id:int,public_key:string}
 */
function dms_central_fixture(): array
{
    $kp = LicensingAPI::generateSigningKeypair();
    LicensingAPI::storeSigningKeypair($kp);

    return [
        'product_id' => (int) Database::insert('licensing_products', ['slug' => 'kohevo', 'name' => 'Kohevo']),
        'client_id'  => (int) Database::insert('licensing_clients', ['name' => 'Acme Corp', 'email' => 'ops@acme.example']),
        'public_key' => (string) LicensingAPI::signingPublicKey(),
    ];
}

/**
 * Run a Central admin page GET probe in a child process.
 *
 * @return array{status:int,body:string}
 */
function dms_admin_get(string $scriptRel, string $queryString = ''): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-probe.php') . ' '
         . escapeshellarg($scriptRel) . ' '
         . escapeshellarg($queryString) . ' 1 1 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
        return ['status' => 0, 'body' => $out];
    }
    return ['status' => (int) $m[1], 'body' => substr($out, strlen($m[0]))];
}

/**
 * Run a Central admin page POST probe in a child process.
 *
 * @param array<string,mixed> $postFields
 * @return array{status:int,body:string}
 */
function dms_admin_post(string $scriptRel, array $postFields): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
         . escapeshellarg($scriptRel) . ' '
         . escapeshellarg((string) json_encode($postFields)) . ' 1 1 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
        return ['status' => 0, 'body' => $out];
    }
    return ['status' => (int) $m[1], 'body' => substr($out, strlen($m[0]))];
}

// ── 1. Authoritative ModuleCatalog Structure & Classification ───────────
unit('central module catalog: defines required fields and exact V1 commercial / Future / Infrastructure / System classifications', function (): void {
    $all = ModuleCatalog::all();

    $requiredFields = [
        'module_key', 'display_name', 'description', 'status',
        'commercial', 'v1_available', 'plugin_identifier', 'dependencies', 'sort_order',
    ];
    foreach ($all as $key => $meta) {
        foreach ($requiredFields as $field) {
            assert_true(array_key_exists($field, $meta), "Module '$key' must define '$field'");
        }
        assert_eq($key, $meta['module_key']);
    }

    // Exact V1 commercial module keys
    assert_eq(['forms', 'membership', 'booking'], ModuleCatalog::v1CommercialKeys());

    // Dependencies
    assert_eq([], $all['forms']['dependencies']);
    assert_eq(['stripe-payment'], $all['membership']['dependencies']);
    assert_eq(['stripe-payment'], $all['booking']['dependencies']);

    // Future / Non-V1 modules
    $future = ModuleCatalog::futureModules();
    assert_eq(['editor', 'content'], array_keys($future));
    foreach (['editor', 'content'] as $fKey) {
        assert_false($all[$fKey]['commercial'], "$fKey must not be commercial in V1");
        assert_false($all[$fKey]['v1_available'], "$fKey must not be v1_available");
        assert_eq('future', $all[$fKey]['status']);
    }

    // Supporting Infrastructure
    $infra = ModuleCatalog::supportingInfrastructure();
    assert_eq(['stripe-payment'], array_keys($infra));
    assert_false($all['stripe-payment']['commercial']);
    assert_false($all['stripe-payment']['v1_available']);
    assert_eq('infrastructure', $all['stripe-payment']['status']);

    // Dependency resolution deduplication
    assert_eq([], ModuleCatalog::resolveDependencies([]));
    assert_eq([], ModuleCatalog::resolveDependencies(['forms']));
    assert_eq(['stripe-payment'], ModuleCatalog::resolveDependencies(['membership']));
    assert_eq(['stripe-payment'], ModuleCatalog::resolveDependencies(['booking']));
    assert_eq(['stripe-payment'], ModuleCatalog::resolveDependencies(['membership', 'booking']), 'stripe-payment must be resolved once without duplication');
});

// ── 2. Duplicate Module Key & Plugin Identifier Collision Detection ─────
unit('central module catalog: buildValidatedCatalog explicitly rejects duplicate module_key or plugin_identifier collisions', function (): void {
    $dupKeyCatalog = [
        [
            'module_key'        => 'forms',
            'display_name'      => 'Forms A',
            'description'       => 'First forms definition',
            'status'            => 'available',
            'commercial'        => true,
            'v1_available'      => true,
            'plugin_identifier' => 'forms',
            'dependencies'      => [],
            'sort_order'        => 10,
        ],
        [
            'module_key'        => 'forms',
            'display_name'      => 'Forms B',
            'description'       => 'Colliding forms definition',
            'status'            => 'available',
            'commercial'        => true,
            'v1_available'      => true,
            'plugin_identifier' => 'forms-alt',
            'dependencies'      => [],
            'sort_order'        => 20,
        ],
    ];

    $caughtKey = false;
    try {
        ModuleCatalog::buildValidatedCatalog($dupKeyCatalog);
    } catch (\InvalidArgumentException | \RuntimeException $e) {
        $caughtKey = str_contains($e->getMessage(), 'Duplicate commercial module key "forms"');
    }
    assert_true($caughtKey, 'Duplicate module_key collision must fail explicitly');

    $dupPluginCatalog = [
        [
            'module_key'        => 'forms',
            'display_name'      => 'Forms',
            'description'       => 'Forms module',
            'status'            => 'available',
            'commercial'        => true,
            'v1_available'      => true,
            'plugin_identifier' => 'forms',
            'dependencies'      => [],
            'sort_order'        => 10,
        ],
        [
            'module_key'        => 'forms-two',
            'display_name'      => 'Forms Two',
            'description'       => 'Colliding plugin_identifier',
            'status'            => 'available',
            'commercial'        => true,
            'v1_available'      => true,
            'plugin_identifier' => 'forms',
            'dependencies'      => [],
            'sort_order'        => 20,
        ],
    ];

    $caughtPlugin = false;
    try {
        ModuleCatalog::buildValidatedCatalog($dupPluginCatalog);
    } catch (\InvalidArgumentException | \RuntimeException $e) {
        $caughtPlugin = str_contains($e->getMessage(), 'Duplicate plugin_identifier "forms"');
    }
    assert_true($caughtPlugin, 'Duplicate plugin_identifier collision must fail explicitly');
});

// ── 3. All 8 V1 Commercial Combinations (Plan -> License -> Check-in) ───
unit('central dynamic modules: all 8 V1 commercial combinations persist in Plan, issue in License, and sign in Check-in payload', function (): void {
    licplug_ensure_schema();
    try {
        $f = dms_central_fixture();

        $combinations = [
            'combo_1_none'                    => [],
            'combo_2_forms'                   => ['forms'],
            'combo_3_membership'              => ['membership'],
            'combo_4_booking'                 => ['booking'],
            'combo_5_forms_membership'        => ['forms', 'membership'],
            'combo_6_forms_booking'           => ['forms', 'booking'],
            'combo_7_membership_booking'      => ['membership', 'booking'],
            'combo_8_forms_membership_booking' => ['forms', 'membership', 'booking'],
        ];

        $idx = 0;
        foreach ($combinations as $slug => $expectedModules) {
            $idx++;
            $planId = PlanService::create([
                'product_id'  => $f['product_id'],
                'slug'        => str_replace('_', '-', $slug),
                'name'        => 'Plan ' . $slug,
                'description' => 'Combination test ' . $slug,
                'is_active'   => true,
                'modules'     => $expectedModules,
            ]);

            $expectedSorted = $expectedModules;
            sort($expectedSorted);
            assert_eq($expectedSorted, PlanService::modules($planId), "Plan modules for $slug must match");

            $issued = LicenseService::issue([
                'client_id'  => $f['client_id'],
                'product_id' => $f['product_id'],
                'plan_id'    => $planId,
                'label'      => 'License ' . $slug,
                'modules'    => $expectedModules,
            ], 'kohevo');

            assert_eq($expectedSorted, LicenseService::modules($issued['id']), "License modules for $slug must match");

            $installId = str_pad((string) $idx, 32, 'a');
            $planSlug = str_replace('_', '-', $slug);
            $res = LicensingAPI::handleCheckIn([
                'license_key' => $issued['license_key'],
                'product'     => 'kohevo',
                'install_id'  => $installId,
                'domain'      => $planSlug . '.example.test',
                'app_version' => '1.0.0',
            ], '127.0.0.1');

            assert_eq(200, $res['http_status'], "Check-in for $slug must return HTTP 200");
            $verifier = new LicenseSignatureVerifier($f['public_key']);
            assert_true(
                $verifier->verify((string) $res['body']['payload'], (string) $res['body']['signature']),
                "Signed check-in payload for $slug must verify with Ed25519 public key"
            );
            $verified = json_decode((string) $res['body']['payload'], true);
            assert_true(is_array($verified), "Decoded payload for $slug must be an array");
            assert_eq('active', $verified['status']);
            assert_eq($planSlug, $verified['plan']);
            assert_eq($expectedSorted, $verified['entitlements'], "Signed entitlements for $slug must match exact combination");
        }
    } finally {
        licplug_teardown();
    }
});

// ── 4. Plan -> License Entitlement Subset Enforcement ───────────────────
unit('central dynamic modules: License entitlements must be a subset of Plan entitlements (out-of-plan modules rejected)', function (): void {
    licplug_ensure_schema();
    try {
        $f = dms_central_fixture();

        // Case A: Plan allows only ['forms'], attempt License with ['forms', 'membership', 'booking']
        $formsPlanId = PlanService::create([
            'product_id' => $f['product_id'],
            'slug'       => 'forms-only',
            'name'       => 'Forms Only',
            'modules'    => ['forms'],
        ]);

        $caughtOutOfPlan = false;
        try {
            LicenseService::issue([
                'client_id'  => $f['client_id'],
                'product_id' => $f['product_id'],
                'plan_id'    => $formsPlanId,
                'modules'    => ['forms', 'membership', 'booking'],
            ], 'kohevo');
        } catch (\InvalidArgumentException $e) {
            $caughtOutOfPlan = str_contains($e->getMessage(), 'not allowed by the selected plan');
        }
        assert_true($caughtOutOfPlan, 'Issuing license with membership+booking on a Forms-only plan must be rejected');

        // Subset ['forms'] or [] on Forms-only plan is allowed
        $validFormsLic = LicenseService::issue([
            'client_id'  => $f['client_id'],
            'product_id' => $f['product_id'],
            'plan_id'    => $formsPlanId,
            'modules'    => ['forms'],
        ], 'kohevo');
        assert_eq(['forms'], LicenseService::modules($validFormsLic['id']));

        // Attempting setModules or grantModules with out-of-plan module 'booking' is rejected
        $caughtUpdateOutOfPlan = false;
        try {
            LicenseService::setModules($validFormsLic['id'], ['forms', 'booking']);
        } catch (\InvalidArgumentException $e) {
            $caughtUpdateOutOfPlan = str_contains($e->getMessage(), 'not allowed by the selected plan');
        }
        assert_true($caughtUpdateOutOfPlan, 'Updating license with out-of-plan module must be rejected');
        assert_eq(['forms'], LicenseService::modules($validFormsLic['id']), 'License modules must remain unchanged after rejected update');

        // Case B: Core-only plan (modules = []), attempt License with ['forms']
        $corePlanId = PlanService::create([
            'product_id' => $f['product_id'],
            'slug'       => 'core-only',
            'name'       => 'Core Only',
            'modules'    => [],
        ]);
        $caughtCorePlan = false;
        try {
            LicenseService::issue([
                'client_id'  => $f['client_id'],
                'product_id' => $f['product_id'],
                'plan_id'    => $corePlanId,
                'modules'    => ['forms'],
            ], 'kohevo');
        } catch (\InvalidArgumentException $e) {
            $caughtCorePlan = str_contains($e->getMessage(), 'not allowed by the selected plan');
        }
        assert_true($caughtCorePlan, 'Issuing license with forms on a Core-only plan must be rejected');
    } finally {
        licplug_teardown();
    }
});

// ── 5. Forged / Non-V1 / Infrastructure / Unknown Module Rejection ──────
unit('central dynamic modules: forged module selections (editor, content, stripe, stripe-payment, unknown, core, system, duplicates) are rejected', function (): void {
    licplug_ensure_schema();
    try {
        $f = dms_central_fixture();
        $planId = PlanService::create([
            'product_id' => $f['product_id'],
            'slug'       => 'test-forged',
            'name'       => 'Test Forged',
            'modules'    => [],
        ]);

        $forgedInputs = [
            'future editor'                 => ['editor'],
            'future content'                => ['content'],
            'forms + future editor'         => ['forms', 'editor'],
            'infrastructure stripe'         => ['stripe'],
            'infrastructure stripe-payment' => ['stripe-payment'],
            'unknown module'                => ['unknown-module'],
            'core admin-user'               => ['admin-user'],
            'core dashboard'                => ['dashboard'],
            'core site-settings'            => ['site-settings'],
            'system media-library'          => ['media-library'],
            'system licensing'              => ['licensing'],
            'duplicate forms'               => ['forms', 'forms'],
            'empty string'                  => [''],
        ];

        foreach ($forgedInputs as $label => $forgedModules) {
            $caughtPlan = false;
            try {
                PlanService::setModules($planId, $forgedModules);
            } catch (\InvalidArgumentException $e) {
                $caughtPlan = true;
            }
            assert_true($caughtPlan, "PlanService::setModules must reject forged input [$label]");
            assert_eq([], PlanService::modules($planId), "Plan modules must remain empty after rejected [$label]");

            $caughtLicense = false;
            try {
                LicenseService::issue([
                    'client_id'  => $f['client_id'],
                    'product_id' => $f['product_id'],
                    'modules'    => $forgedModules,
                ], 'kohevo');
            } catch (\InvalidArgumentException $e) {
                $caughtLicense = true;
            }
            assert_true($caughtLicense, "LicenseService::issue must reject forged input [$label]");
        }
    } finally {
        licplug_teardown();
    }
});

// ── 6. Central Admin HTTP POST & Dashboard Probes ───────────────────────
unit('central admin endpoints: plans.php, licenses.php, license.php reject forged POST requests and index.php renders full catalog & license visibility', function (): void {
    licplug_ensure_schema();
    try {
        $f = dms_central_fixture();

        // 1. Forged POST to admin/plans.php with editor (Future module)
        $resPlanForged = dms_admin_post('plugins/licensing/admin/plans.php', [
            '_action'     => 'save',
            'product_id'  => $f['product_id'],
            'name'        => 'Forged Editor Plan',
            'slug'        => 'forged-editor-plan',
            'description' => 'Should fail',
            'is_active'   => '1',
            'modules'     => ['forms', 'editor'],
        ]);
        assert_eq(200, $resPlanForged['status']);
        assert_true(str_contains($resPlanForged['body'], 'Not V1 / Future'), 'plans.php must display error when future module editor is submitted');
        assert_null(PlanService::findByProductAndSlug($f['product_id'], 'forged-editor-plan'), 'Forged plan must not be created');

        // 2. Valid POST to admin/plans.php with ['forms']
        $resPlanValid = dms_admin_post('plugins/licensing/admin/plans.php', [
            '_action'     => 'save',
            'product_id'  => $f['product_id'],
            'name'        => 'Valid Forms Plan',
            'slug'        => 'valid-forms-plan',
            'description' => 'Forms only',
            'is_active'   => '1',
            'modules'     => ['forms'],
        ]);
        $validPlan = PlanService::findByProductAndSlug($f['product_id'], 'valid-forms-plan');
        assert_true($validPlan !== null, 'Valid Forms plan must be created');
        assert_eq(['forms'], PlanService::modules((int) $validPlan['id']));

        // 3. Forged POST to admin/licenses.php requesting ['forms', 'membership', 'booking'] on Forms-only plan
        $countBefore = (int) Database::value("SELECT COUNT(*) FROM licensing_licenses");
        $resLicForged = dms_admin_post('plugins/licensing/admin/licenses.php', [
            '_action'          => 'issue',
            'client_id'        => $f['client_id'],
            'product_id'       => $f['product_id'],
            'plan_id'          => (int) $validPlan['id'],
            'label'            => 'Forged Out-Of-Plan License',
            'activation_limit' => '1',
            'warning_days'     => '7',
            'grace_days'       => '7',
            'modules'          => ['forms', 'membership', 'booking'],
        ]);
        assert_eq(200, $resLicForged['status']);
        assert_true(str_contains($resLicForged['body'], 'not allowed by the selected plan'), 'licenses.php must reject out-of-plan modules');
        assert_eq($countBefore, (int) Database::value("SELECT COUNT(*) FROM licensing_licenses"), 'Out-of-plan license must not be issued');

        // 4. Valid license + forged POST to admin/license.php (set_modules with stripe-payment)
        $issued = LicenseService::issue([
            'client_id'  => $f['client_id'],
            'product_id' => $f['product_id'],
            'plan_id'    => (int) $validPlan['id'],
            'label'      => 'Valid Forms License',
            'modules'    => ['forms'],
        ], 'kohevo');

        $resDetailForged = dms_admin_post('plugins/licensing/admin/license.php', [
            '_action' => 'set_modules',
            'id'      => $issued['id'],
            'modules' => ['forms', 'stripe-payment'],
        ]);
        assert_eq(200, $resDetailForged['status']);
        assert_true(str_contains($resDetailForged['body'], 'Supporting infrastructure'), 'license.php must reject stripe-payment in modules[]');
        assert_eq(['forms'], LicenseService::modules($issued['id']), 'License entitlements must remain unchanged');

        // 5. Central Licensing Dashboard visibility (plugins/licensing/admin/index.php)
        $dash = dms_admin_get('plugins/licensing/admin/index.php');
        assert_eq(200, $dash['status']);
        assert_true(str_contains($dash['body'], 'Commercial Module Catalog'), 'Dashboard must show Commercial Module Catalog');
        assert_true(str_contains($dash['body'], 'Commercial Licenses &amp; Entitlements'), 'Dashboard must show Commercial Licenses & Entitlements');
        assert_true(str_contains($dash['body'], 'Not V1 / Future'), 'Dashboard must mark Editor/Content as Not V1 / Future');
        assert_true(str_contains($dash['body'], 'Supporting Infrastructure'), 'Dashboard must show Stripe Payment as Supporting Infrastructure');
        assert_true(str_contains($dash['body'], 'Valid Forms License'), 'Dashboard must list issued commercial license');
    } finally {
        licplug_teardown();
    }
});
