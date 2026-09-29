<?php
/**
 * Client Dynamic Module Registry & Step 5 Installer Provisioning Tests.
 *
 * Exercises:
 *   1. Explicit authoritative CommercialModuleRegistry (`src/Services/Installation/CommercialModuleRegistry.php`)
 *   2. Arbitrary plugin folder on disk test (§17.1 — never treated as commercial, never selectable, never provisioned)
 *   3. Duplicate commercial module key collision test (§17.2 — explicit validation failure, never silently overwrites)
 *   4. All 8 V1 commercial module combinations (`none`, `forms`, `membership`, `booking`, `forms+membership`,
 *      `forms+booking`, `membership+booking`, `forms+membership+booking`) end-to-end with `stripe-payment`
 *      supporting infrastructure resolution and deduplication (§15)
 *   5. Subset selection in Step 5 when license grants all V1 modules
 *   6. Forged Step 5 requests (`membership`, `booking`, `editor`, `content`, `stripe`, `stripe-payment`,
 *      `unknown-plugin`, duplicates) when license grants only `forms` (§16.2)
 *   7. Step 5 provisioning failure transactional rollback, no `.installed` written, no `finish_anyway` bypass,
 *      and clean retry after fixing failure (§17.3)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/license_signing.php';

use Slate\Data\MigrationRunner;
use Slate\Services\Installation\CommercialModuleRegistry;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Licensing\SlateLicenseCacheStore;

require_once dirname(__DIR__, 2) . '/plugins/licensing/client/RemoteLicenseClient.php';
require_once dirname(__DIR__, 2) . '/includes/installer_flow.php';

const DMI_CORE_MIGRATIONS = [
    '0001_core_init',
    '0002_identity_core',
    '0011_login_attempts',
    '0014_tenant_profiles',
    '0023_installation_identity',
    '0022_remote_license_cache',
    '0024_remote_license_metadata',
    '0025_remote_license_cache_installation_id',
    '0026_remote_license_cache_signed_payload',
];

/**
 * Create a fresh throwaway database with the exact install.php Step 2 core schema
 * so Step 5 plugin install.sql files (forms, membership, booking, stripe-payment)
 * run cleanly in a real fresh installation environment.
 */
function dmi_fresh_db(string $dbName): \PDO
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    $root = new \PDO($dsn, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `$dbName`");
    $root->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4");
    $dsn2 = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ";dbname=$dbName;charset=" . DB_CHARSET;
    $pdo = new \PDO($dsn2, DB_USER, DB_PASS, [
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    $runner = new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations');
    $runner->migrate(DMI_CORE_MIGRATIONS);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

function dmi_drop_db(string $dbName): void
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `$dbName`");
}

function dmi_with_pdo(\PDO $pdo, callable $fn): mixed
{
    $property = new \ReflectionProperty(\Slate\Data\Database::class, 'pdo');
    $previous = $property->getValue();
    $property->setValue(null, $pdo);

    $activeProp = new \ReflectionProperty(\PluginLoader::class, 'active');
    $prevActive = $activeProp->getValue();

    $slugsProp = new \ReflectionProperty(\PluginLoader::class, 'activeSlugs');
    $prevSlugs = $slugsProp->getValue();

    $bootedProp = new \ReflectionProperty(\PluginLoader::class, 'booted');
    $prevBooted = $bootedProp->getValue();

    $envKeys = ['LICENSE_SERVER_URL', 'LICENSE_SERVER_PUBLIC_KEY', 'LICENSE_PRODUCT', 'LICENSE_KEY'];
    $prevEnv = [];
    foreach ($envKeys as $k) {
        $prevEnv[$k] = $_ENV[$k] ?? null;
    }
    $_ENV['LICENSE_SERVER_URL'] = 'https://license.test';
    $_ENV['LICENSE_SERVER_PUBLIC_KEY'] = license_test_public_key();
    $_ENV['LICENSE_PRODUCT'] = 'kohevo';
    $_ENV['LICENSE_KEY'] = 'test-key';
    try {
        $activeProp->setValue(null, []);
        $slugsProp->setValue(null, null);
        $bootedProp->setValue(null, false);
        return $fn();
    } finally {
        $activeProp->setValue(null, $prevActive);
        $slugsProp->setValue(null, $prevSlugs);
        $bootedProp->setValue(null, $prevBooted);
        $property->setValue(null, $previous);
        foreach ($prevEnv as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
    }
}

/**
 * Execute the exact Step 5 provisioning & rollback pipeline from install.php
 * against the active Database connection and return the resulting state.
 *
 * @param array<string,mixed> $postData
 * @return array{ok:bool,error:?string,marker_written:bool,active_plugins:list<string>,finish_summary:?array}
 */
function dmi_run_step5_post(array $postData, string $markerFile): array
{
    @unlink($markerFile);
    $error = null;
    $finishSummary = null;

    $action = (string) ($postData['_action'] ?? 'finish');
    if ($action !== 'finish') {
        $error = 'Invalid installer action. Module provisioning cannot be bypassed.';
    } else {
        $entitlements = [];
        try {
            $store = new SlateLicenseCacheStore((int) TENANT_ID);
            $state = $store->readTrustState();
            $entitlements = $state['trusted'] ? (array) ($state['data']['entitlements'] ?? []) : [];
        } catch (\Throwable $e) {
            $entitlements = [];
        }

        if (array_key_exists('modules', $postData) || !empty($postData['_modules_form'])) {
            if (array_key_exists('modules', $postData) && !is_array($postData['modules'])) {
                $requested = [''];
            } else {
                $requested = $postData['modules'] ?? [];
            }
        } else {
            $eligibleByDefault = CommercialModuleRegistry::entitledDefinitions($entitlements);
            $requested = array_keys($eligibleByDefault);
        }

        $selection = CommercialModuleRegistry::validateSelection($requested, $entitlements);
        if (!$selection['ok']) {
            $error = (string) $selection['error'];
        } else {
            $selected = $selection['selected'];
            $infra = CommercialModuleRegistry::resolveInfrastructure($selected);
            if (!$infra['ok']) {
                $error = (string) $infra['error'];
            } else {
                $definitions = CommercialModuleRegistry::definitions();
                $pluginByEntitlement = [];
                foreach ($definitions as $key => $definition) {
                    $pluginByEntitlement[$key] = $definition['plugin'];
                }
                $ordered = array_values(array_unique(array_merge(
                    $infra['infrastructure'],
                    array_map(static fn(string $key): string => $pluginByEntitlement[$key], $selected)
                )));
                $before = array_column(\PluginLoader::listAll(), 'status', 'slug');
                $pluginResults = [];
                $failed = null;
                foreach ($ordered as $slug) {
                    $res = \PluginLoader::installFromDisk($slug);
                    $pluginResults[] = [
                        'slug'  => $slug,
                        'name'  => $slug === 'stripe-payment' ? 'Stripe Payment infrastructure' : $slug,
                        'ok'    => !empty($res['ok']),
                        'error' => $res['error'] ?? null,
                    ];
                    if (empty($res['ok'])) {
                        $failed = $res['error'] ?? 'Module provisioning failed.';
                        break;
                    }
                }
                if ($failed !== null) {
                    foreach ($ordered as $slug) {
                        if (($before[$slug] ?? null) !== \PluginLoader::STATUS_ACTIVE) {
                            \PluginLoader::deactivate($slug);
                        }
                    }
                    $finishSummary = $pluginResults;
                    $error = 'Module provisioning failed. No installation marker was written; correct the package issue and retry.';
                } else {
                    file_put_contents($markerFile, 'Installed: ' . date('Y-m-d H:i:s') . ' | Kohevo ' . SLATE_VERSION . "\n");
                }
            }
        }
    }

    $activeRows = \Database::rows("SELECT slug FROM plugins WHERE status = 'active' ORDER BY slug ASC");
    $activePlugins = array_values(array_map(static fn(array $r): string => (string) $r['slug'], $activeRows));

    return [
        'ok'             => $error === null && is_file($markerFile),
        'error'          => $error,
        'marker_written' => is_file($markerFile),
        'active_plugins' => $activePlugins,
        'finish_summary' => $finishSummary,
    ];
}

// ── 1. Client CommercialModuleRegistry Structure & Fields ───────────────
unit('client module registry: defines required fields and exact V1 commercial / Future / Core / Infrastructure classifications', function (): void {
    $catalog = CommercialModuleRegistry::buildValidatedCatalog();
    $requiredFields = [
        'module_key', 'display_name', 'description', 'plugin_slug',
        'v1_available', 'commercial', 'required_infrastructure', 'sort_order',
    ];
    foreach ($catalog as $key => $def) {
        foreach ($requiredFields as $field) {
            assert_true(array_key_exists($field, $def), "Client catalog entry '$key' must define '$field'");
        }
        assert_eq($key, $def['module_key']);
    }

    $definitions = CommercialModuleRegistry::definitions();
    assert_eq(['forms', 'membership', 'booking', 'mcp-gateway', 'coaching', 'studio-builder'], array_keys($definitions));
    assert_eq([], $definitions['forms']['required_infrastructure']);
    assert_eq(['stripe-payment'], $definitions['membership']['required_infrastructure']);
    assert_eq(['stripe-payment'], $definitions['booking']['required_infrastructure']);
    assert_eq([], $definitions['studio-builder']['required_infrastructure']);

    $future = CommercialModuleRegistry::futureDefinitions();
    assert_eq(['editor', 'content'], array_keys($future));
    foreach (['editor', 'content'] as $fKey) {
        assert_false($future[$fKey]['v1_available']);
        assert_false($future[$fKey]['commercial']);
    }

    $core = CommercialModuleRegistry::includedCoreFeatures();
    assert_eq(3, count($core));

    $infra = CommercialModuleRegistry::supportingInfrastructureDefinitions();
    assert_eq(['stripe-payment'], array_keys($infra));
});

// ── 2. Arbitrary Plugin Folder Test (§17.1) ─────────────────────────────
unit('client module registry (§17.1): arbitrary plugin folder on disk is never treated as commercial, never selectable, and never provisioned', function (): void {
    $arbDir = SLATE_ROOT . '/plugins/arbitrary-test-plugin';
    @mkdir($arbDir, 0775, true);
    file_put_contents($arbDir . '/plugin.json', json_encode([
        'slug'        => 'arbitrary-test-plugin',
        'name'        => 'Arbitrary Test Plugin',
        'version'     => '1.0.0',
        'description' => 'Unregistered plugin claiming commercial status on disk',
        'author'      => 'Test',
        'commercial_module' => [
            'entitlement' => 'arbitrary-test-plugin',
            'label'       => 'Arbitrary Test Plugin',
            'description' => 'Should be ignored by authoritative registry',
        ],
    ], JSON_PRETTY_PRINT));

    try {
        // Verify PluginLoader discovers it on disk, but CommercialModuleRegistry ignores it
        $onDiskSlugs = array_column(\PluginLoader::discoverOnDisk(), 'slug');
        assert_true(in_array('arbitrary-test-plugin', $onDiskSlugs, true), 'Sanity: arbitrary-test-plugin is on disk');

        $definitions = CommercialModuleRegistry::definitions();
        assert_false(isset($definitions['arbitrary-test-plugin']), 'Arbitrary plugin must never appear in commercial definitions');
        assert_eq(['forms', 'membership', 'booking', 'mcp-gateway', 'coaching', 'studio-builder'], array_keys($definitions));

        $entitled = CommercialModuleRegistry::entitledDefinitions(['forms', 'membership', 'booking', 'arbitrary-test-plugin']);
        assert_false(isset($entitled['arbitrary-test-plugin']), 'Arbitrary plugin must never appear in entitled definitions');

        $sel = CommercialModuleRegistry::validateSelection(
            ['arbitrary-test-plugin'],
            ['forms', 'membership', 'booking', 'arbitrary-test-plugin']
        );
        assert_false($sel['ok'], 'Selecting arbitrary-test-plugin in Step 5 must be rejected');
    } finally {
        @unlink($arbDir . '/plugin.json');
        @rmdir($arbDir);
    }
});

// ── 3. Duplicate Commercial Module Key Collision Test (§17.2) ───────────
unit('client module registry (§17.2): duplicate commercial module key collision fails explicitly and never silently overwrites', function (): void {
    // A. Duplicate module_key in catalog definitions
    $dupCatalog = [
        [
            'module_key'              => 'forms',
            'display_name'            => 'Forms One',
            'description'             => 'First',
            'plugin_slug'             => 'forms',
            'v1_available'            => true,
            'commercial'              => true,
            'required_infrastructure' => [],
            'sort_order'              => 10,
        ],
        [
            'module_key'              => 'forms',
            'display_name'            => 'Forms Two',
            'description'             => 'Second colliding definition',
            'plugin_slug'             => 'forms-two',
            'v1_available'            => true,
            'commercial'              => true,
            'required_infrastructure' => [],
            'sort_order'              => 20,
        ],
    ];
    $caughtCatalogCollision = false;
    try {
        CommercialModuleRegistry::buildValidatedCatalog($dupCatalog);
    } catch (\RuntimeException $e) {
        $caughtCatalogCollision = str_contains($e->getMessage(), "Duplicate commercial module key 'forms'");
    }
    assert_true($caughtCatalogCollision, 'Catalog duplicate module_key must throw RuntimeException');

    // B. Two plugins on disk claiming the same commercial_module.entitlement ('forms')
    $discoveredWithCollision = \PluginLoader::discoverOnDisk();
    $discoveredWithCollision[] = [
        'slug'        => 'rogue-forms-clone',
        'name'        => 'Rogue Forms Clone',
        'version'     => '1.0.0',
        'description' => 'Colliding commercial entitlement claim',
        'manifest'    => [
            'slug'              => 'rogue-forms-clone',
            'name'              => 'Rogue Forms Clone',
            'version'           => '1.0.0',
            'description'       => 'Colliding commercial entitlement claim',
            'author'            => 'Test',
            'commercial_module' => [
                'entitlement' => 'forms',
                'label'       => 'Rogue Forms',
            ],
        ],
    ];

    $caughtDiskCollision = false;
    try {
        CommercialModuleRegistry::definitions($discoveredWithCollision);
    } catch (\RuntimeException $e) {
        $caughtDiskCollision = str_contains($e->getMessage(), "Duplicate commercial module key 'forms'");
    }
    assert_true($caughtDiskCollision, 'On-disk duplicate commercial_module.entitlement collision must fail explicitly');
});

// ── 4. All 8 V1 Commercial Module Combinations End-to-End (§15) ─────────
unit('client step 5 installer (§15): all 8 V1 commercial module combinations provision exact plugin + stripe-payment states', function (): void {
    $matrix = [
        '1_none' => [
            'entitlements' => [],
            'selected'     => [],
            'expected'     => [],
        ],
        '2_forms' => [
            'entitlements' => ['forms'],
            'selected'     => ['forms'],
            'expected'     => ['forms'],
        ],
        '3_membership' => [
            'entitlements' => ['membership'],
            'selected'     => ['membership'],
            'expected'     => ['membership', 'stripe-payment'],
        ],
        '4_booking' => [
            'entitlements' => ['booking'],
            'selected'     => ['booking'],
            'expected'     => ['booking', 'stripe-payment'],
        ],
        '5_forms_membership' => [
            'entitlements' => ['forms', 'membership'],
            'selected'     => ['forms', 'membership'],
            'expected'     => ['forms', 'membership', 'stripe-payment'],
        ],
        '6_forms_booking' => [
            'entitlements' => ['forms', 'booking'],
            'selected'     => ['forms', 'booking'],
            'expected'     => ['booking', 'forms', 'stripe-payment'],
        ],
        '7_membership_booking' => [
            'entitlements' => ['membership', 'booking'],
            'selected'     => ['membership', 'booking'],
            'expected'     => ['booking', 'membership', 'stripe-payment'],
        ],
        '8_forms_membership_booking' => [
            'entitlements' => ['forms', 'membership', 'booking'],
            'selected'     => ['forms', 'membership', 'booking'],
            'expected'     => ['booking', 'forms', 'membership', 'stripe-payment'],
        ],
    ];

    // Also verify resolveInfrastructure deduplicates stripe-payment when both membership & booking are selected
    $infraBoth = CommercialModuleRegistry::resolveInfrastructure(['membership', 'booking']);
    assert_true($infraBoth['ok']);
    assert_eq(['stripe-payment'], $infraBoth['infrastructure'], 'stripe-payment must be resolved once without duplicate error');

    $dbName = 'slate_dmi_matrix_' . slate_test_ns();
    $pdo = dmi_fresh_db($dbName);
    $markerFile = sys_get_temp_dir() . '/kohevo_dmi_marker_' . bin2hex(random_bytes(4));

    try {
        dmi_with_pdo($pdo, static function () use ($matrix, $markerFile): void {
            $core = InstallationService::provisionCore();
            InstallationService::createAdminAccount(
                $core['tenant_id'],
                'Matrix Admin',
                'matrix@example.test',
                password_hash('password123', PASSWORD_DEFAULT)
            );

            foreach ($matrix as $label => $spec) {
                // Reset plugins table before each combination
                \Database::query('DELETE FROM plugins');
                $activeProp = new \ReflectionProperty(\PluginLoader::class, 'activeSlugs');
                $activeProp->setValue(null, null);

                // Seed verified signed license cache with the combination's entitlements
                license_test_seed_cache((int) TENANT_ID, [
                    'installation_id' => $core['installation_id'],
                    'status'          => 'active',
                    'plan'            => 'plan-' . $label,
                    'entitlements'    => $spec['entitlements'],
                    'expires_at'      => null,
                    'fetched_at'      => gmdate('Y-m-d H:i:s'),
                ]);

                $result = dmi_run_step5_post([
                    '_action'       => 'finish',
                    '_modules_form' => '1',
                    'modules'       => $spec['selected'],
                ], $markerFile);

                assert_true($result['ok'], "Combination [$label] must succeed (error: " . ($result['error'] ?? 'none') . ')');
                assert_true($result['marker_written'], "Combination [$label] must write .installed marker");
                assert_eq($spec['expected'], $result['active_plugins'], "Combination [$label] active plugins must match exact expected set");
            }
        });
    } finally {
        @unlink($markerFile);
        dmi_drop_db($dbName);
    }
});

// ── 5. Subset Selection & Forged Step 5 Requests (§16.2) ────────────────
unit('client step 5 installer (§16.2): allows valid subset selection and rejects forged/unlicensed/future/infrastructure/duplicate module requests', function (): void {
    $dbName = 'slate_dmi_forged_' . slate_test_ns();
    $pdo = dmi_fresh_db($dbName);
    $markerFile = sys_get_temp_dir() . '/kohevo_dmi_forged_' . bin2hex(random_bytes(4));

    try {
        dmi_with_pdo($pdo, static function () use ($markerFile): void {
            $core = InstallationService::provisionCore();
            InstallationService::createAdminAccount(
                $core['tenant_id'],
                'Security Admin',
                'security@example.test',
                password_hash('password123', PASSWORD_DEFAULT)
            );

            // Seed license with ONLY ['forms']
            license_test_seed_cache((int) TENANT_ID, [
                'installation_id' => $core['installation_id'],
                'status'          => 'active',
                'plan'            => 'forms-only',
                'entitlements'    => ['forms'],
                'expires_at'      => null,
                'fetched_at'      => gmdate('Y-m-d H:i:s'),
            ]);

            $forgedCases = [
                'unlicensed membership'         => ['membership'],
                'unlicensed booking'            => ['booking'],
                'forms + unlicensed booking'    => ['forms', 'booking'],
                'future editor'                 => ['editor'],
                'future content'                => ['content'],
                'infrastructure stripe'         => ['stripe'],
                'infrastructure stripe-payment' => ['stripe-payment'],
                'unknown-plugin'                => ['unknown-plugin'],
                'duplicate forms'               => ['forms', 'forms'],
                'empty string'                  => [''],
            ];

            foreach ($forgedCases as $label => $modules) {
                \Database::query('DELETE FROM plugins');
                $res = dmi_run_step5_post([
                    '_action'       => 'finish',
                    '_modules_form' => '1',
                    'modules'       => $modules,
                ], $markerFile);

                assert_false($res['ok'], "Forged Step 5 request [$label] must be rejected");
                assert_false($res['marker_written'], "Forged Step 5 request [$label] must NOT write .installed");
                assert_eq([], $res['active_plugins'], "Forged Step 5 request [$label] must NOT activate any plugin");
            }

            // Also verify _action=finish_anyway bypass attempt is rejected
            $bypassRes = dmi_run_step5_post([
                '_action' => 'finish_anyway',
            ], $markerFile);
            assert_false($bypassRes['ok'], 'finish_anyway bypass must be rejected');
            assert_false($bypassRes['marker_written'], 'finish_anyway must NOT write .installed');

            // Subset selection: license has ['forms', 'membership', 'booking'], operator selects only ['forms']
            license_test_seed_cache((int) TENANT_ID, [
                'installation_id' => $core['installation_id'],
                'status'          => 'active',
                'plan'            => 'all-modules',
                'entitlements'    => ['forms', 'membership', 'booking'],
                'expires_at'      => null,
                'fetched_at'      => gmdate('Y-m-d H:i:s'),
            ]);

            \Database::query('DELETE FROM plugins');
            $subsetRes = dmi_run_step5_post([
                '_action'       => 'finish',
                '_modules_form' => '1',
                'modules'       => ['forms'],
            ], $markerFile);
            assert_true($subsetRes['ok'], 'Valid subset selection [forms] must succeed');
            assert_eq(['forms'], $subsetRes['active_plugins'], 'Only selected subset [forms] must be activated (no stripe-payment)');
        });
    } finally {
        @unlink($markerFile);
        dmi_drop_db($dbName);
    }
});

// ── 6. Step 5 Provisioning Failure & Rollback (§17.3) ───────────────────
unit('client step 5 installer (§17.3): rolls back newly activated plugins on mid-provisioning failure, does not write .installed, and succeeds on retry', function (): void {
    $dbName = 'slate_dmi_rollback_' . slate_test_ns();
    $pdo = dmi_fresh_db($dbName);
    $markerFile = sys_get_temp_dir() . '/kohevo_dmi_rollback_' . bin2hex(random_bytes(4));
    $bookingInstallSql = SLATE_ROOT . '/plugins/booking/install.sql';
    $originalBookingSql = (string) file_get_contents($bookingInstallSql);

    try {
        dmi_with_pdo($pdo, static function () use ($markerFile, $bookingInstallSql, $originalBookingSql): void {
            $core = InstallationService::provisionCore();
            InstallationService::createAdminAccount(
                $core['tenant_id'],
                'Rollback Admin',
                'rollback@example.test',
                password_hash('password123', PASSWORD_DEFAULT)
            );

            license_test_seed_cache((int) TENANT_ID, [
                'installation_id' => $core['installation_id'],
                'status'          => 'active',
                'plan'            => 'forms-booking',
                'entitlements'    => ['forms', 'booking'],
                'expires_at'      => null,
                'fetched_at'      => gmdate('Y-m-d H:i:s'),
            ]);

            // Inject a deliberate SQL syntax error into booking/install.sql so:
            //   1. stripe-payment activates first (succeeds)
            //   2. forms activates second (succeeds)
            //   3. booking activates third (fails!)
            file_put_contents($bookingInstallSql, "THIS IS INVALID SQL SYNTAX FOR ROLLBACK TEST;\n");

            $failedAttempt = dmi_run_step5_post([
                '_action'       => 'finish',
                '_modules_form' => '1',
                'modules'       => ['forms', 'booking'],
            ], $markerFile);

            assert_false($failedAttempt['ok'], 'Step 5 must fail when booking install.sql fails');
            assert_false($failedAttempt['marker_written'], '.installed must NOT be written when provisioning fails');
            assert_true(
                str_contains((string) $failedAttempt['error'], 'Module provisioning failed. No installation marker was written'),
                'Clear error message must be returned on provisioning failure'
            );
            assert_eq([], $failedAttempt['active_plugins'], 'Newly activated plugins (stripe-payment, forms) must be rolled back to inactive');

            // Restore valid booking/install.sql and retry Step 5
            file_put_contents($bookingInstallSql, $originalBookingSql);

            $retryAttempt = dmi_run_step5_post([
                '_action'       => 'finish',
                '_modules_form' => '1',
                'modules'       => ['forms', 'booking'],
            ], $markerFile);

            assert_true($retryAttempt['ok'], 'Retry after fixing package failure must succeed');
            assert_true($retryAttempt['marker_written'], 'Retry must write .installed marker');
            assert_eq(['booking', 'forms', 'stripe-payment'], $retryAttempt['active_plugins'], 'All selected modules + stripe-payment must be active after retry');
        });
    } finally {
        file_put_contents($bookingInstallSql, $originalBookingSql);
        @unlink($markerFile);
        dmi_drop_db($dbName);
    }
});

// ── 7. Central ↔ Client End-to-End Check-in & Mid-Lifecycle Module Changes (§14.4 / §15) ──
unit('central <-> client dynamic modules (§14.4 / §15): real Central check-in across all 8 V1 combinations and mid-lifecycle module add/remove', function (): void {
    $centralFixture = dirname(__DIR__, 3) . '/02-licensing/tests/fixtures/phase10-central.php';
    if (!is_file($centralFixture)) {
        return;
    }

    $callCentral = static function (array $args, ?string $stdin = null) use ($centralFixture): array {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($centralFixture);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg((string) $arg);
        }
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], (string) $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $decoded = json_decode($out, true);
        if ($code !== 0 || !is_array($decoded)) {
            throw new \RuntimeException('central fixture failed: ' . ($args[0] ?? '') . ' ' . trim($err . ' ' . $out));
        }
        return $decoded;
    };

    $dbName = 'slate_dmi_e2e_' . slate_test_ns();
    $pdo = dmi_fresh_db($dbName);
    $markerFile = sys_get_temp_dir() . '/kohevo_dmi_e2e_' . bin2hex(random_bytes(4));

    try {
        $callCentral(['reset', base64_encode(license_test_secret_key())]);

        dmi_with_pdo($pdo, static function () use ($callCentral, $markerFile): void {
            $core = InstallationService::provisionCore();
            InstallationService::createAdminAccount(
                $core['tenant_id'],
                'E2E Admin',
                'e2e@example.test',
                password_hash('password123', PASSWORD_DEFAULT)
            );

            $transport = static function (string $url, string $body) use ($callCentral): array {
                $res = $callCentral(['checkin'], $body);
                $raw = (string) json_encode($res['body'], JSON_UNESCAPED_SLASHES);
                return [(int) $res['http_status'], $raw];
            };

            // Issue license on Central with Plan permitting [forms, membership, booking] and initial License modules = [forms]
            $issued = $callCentral(['issue', json_encode([
                'plan_slug'    => 'enterprise-dynamic',
                'plan_modules' => ['forms', 'membership', 'booking'],
                'modules'      => ['forms'],
                'expires_at'   => gmdate('Y-m-d H:i:s', time() + 86400 * 365),
            ])]);

            $client = new RemoteLicenseClient([
                'server_url'  => 'https://license-dmi.test',
                'public_key'  => license_test_public_key(),
                'product'     => 'kohevo',
                'license_key' => $issued['license_key'],
                'install_id'  => $core['installation_id'],
                'domain'      => 'localhost',
                'app_version' => '1.2.0',
            ], new SlateLicenseCacheStore((int) TENANT_ID, license_test_public_key()), $transport);

            // 1. Initial check-in with [forms]
            assert_true($client->checkIn(), 'Initial Central -> Client check-in must succeed');
            $trust = (new SlateLicenseCacheStore((int) TENANT_ID, license_test_public_key()))->readTrustState();
            assert_true($trust['trusted']);
            assert_eq(['forms'], $trust['data']['entitlements']);

            // Step 5 install with [forms]
            \Database::query('DELETE FROM plugins');
            $step5 = dmi_run_step5_post([
                '_action'       => 'finish',
                '_modules_form' => '1',
                'modules'       => ['forms'],
            ], $markerFile);
            assert_true($step5['ok']);
            assert_eq(['forms'], $step5['active_plugins']);

            // 2. Mid-lifecycle ADD module on Central: [forms] -> [forms, booking]
            $callCentral(['set-modules', (string) $issued['license_id'], json_encode(['forms', 'booking'])]);
            assert_true($client->checkIn(), 'Check-in after adding booking on Central must succeed');
            $trustAfterAdd = (new SlateLicenseCacheStore((int) TENANT_ID, license_test_public_key()))->readTrustState();
            assert_true($trustAfterAdd['trusted']);
            assert_eq(['booking', 'forms'], $trustAfterAdd['data']['entitlements']);
            assert_true(\Slate\Services\Licensing\EntitlementService::canAccessCapability((int) TENANT_ID, 'forms'));
            assert_true(\Slate\Services\Licensing\EntitlementService::canAccessCapability((int) TENANT_ID, 'booking'));
            assert_false(\Slate\Services\Licensing\EntitlementService::canAccessCapability((int) TENANT_ID, 'membership'));
            // Activate booking + stripe-payment on Client and verify ModuleGuard::isEntitled
            \PluginLoader::installFromDisk('stripe-payment');
            \PluginLoader::installFromDisk('booking');
            assert_true(\Slate\Services\Licensing\ModuleGuard::isEntitled('forms'));
            assert_true(\Slate\Services\Licensing\ModuleGuard::isEntitled('booking'));
            assert_false(\Slate\Services\Licensing\ModuleGuard::isEntitled('membership'));

            // 3. Mid-lifecycle ADD membership -> [forms, membership, booking] then REMOVE membership + booking -> [forms]
            $callCentral(['set-modules', (string) $issued['license_id'], json_encode(['forms', 'membership', 'booking'])]);
            assert_true($client->checkIn());
            \PluginLoader::installFromDisk('membership');
            assert_true(\Slate\Services\Licensing\EntitlementService::canAccessCapability((int) TENANT_ID, 'membership'));
            assert_true(\Slate\Services\Licensing\ModuleGuard::isEntitled('membership'));

            // Now remove membership + booking on Central while plugins remain active in Client DB:
            // next check-in must immediately revoke runtime entitlement and ModuleGuard access!
            $callCentral(['set-modules', (string) $issued['license_id'], json_encode(['forms'])]);
            assert_true($client->checkIn(), 'Check-in after removing membership + booking on Central must succeed');
            $trustAfterRemove = (new SlateLicenseCacheStore((int) TENANT_ID, license_test_public_key()))->readTrustState();
            assert_true($trustAfterRemove['trusted']);
            assert_eq(['forms'], $trustAfterRemove['data']['entitlements']);
            assert_true(\Slate\Services\Licensing\EntitlementService::canAccessCapability((int) TENANT_ID, 'forms'));
            assert_false(\Slate\Services\Licensing\EntitlementService::canAccessCapability((int) TENANT_ID, 'membership'), 'Removed module membership must lose entitlement after check-in');
            assert_false(\Slate\Services\Licensing\EntitlementService::canAccessCapability((int) TENANT_ID, 'booking'), 'Removed module booking must lose entitlement after check-in');
            assert_true(\Slate\Services\Licensing\ModuleGuard::isEntitled('forms'));
            assert_false(\Slate\Services\Licensing\ModuleGuard::isEntitled('membership'), 'ModuleGuard must deny membership even when plugin row is active');
            assert_false(\Slate\Services\Licensing\ModuleGuard::isEntitled('booking'), 'ModuleGuard must deny booking even when plugin row is active');
        });
    } finally {
        try { $callCentral(['teardown']); } catch (\Throwable $e) {}
        @unlink($markerFile);
        dmi_drop_db($dbName);
    }
});

