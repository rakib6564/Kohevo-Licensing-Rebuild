<?php
/**
 * Phase 10 focused licensing run: the Central ↔ Client synchronization
 * suite plus every Phase 3–9 licensing integration suite it must not
 * regress. Phase10SynchronizationTest drives the real Central Server from
 * ../02-licensing (its own test database) through
 * 02-licensing/tests/fixtures/phase10-central.php, so both checkouts must
 * be provisioned. Pure unit suites (RemoteLicenseClientSyncTest,
 * CommercialLicenseWindowTest, LicenseStatusPresenterTest, …) run under
 * tests/unit/run.php.
 *
 * Usage: php tests/run-phase10-licensing.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('SLATE_TESTING', true);
require __DIR__ . '/../config.php';
require __DIR__ . '/guard.php';
slate_require_test_database();
require __DIR__ . '/unit/harness.php';
foreach ([
    'Phase10SynchronizationTest.php',     // Phase 10
    'Phase9ExpiryGraceTest.php',          // Phase 9
    'Phase4SurfaceSecurityTest.php',      // Phase 4
    'Phase3EntitlementAuthorityTest.php', // Phase 3/4
    'LicenseGateTest.php',                // Phase 4/5
    'InstallerLicenseActivationFlowTest.php', // Phase 5
    'GlobalLicenseGuardTest.php',         // Phase 6
    'ModuleGuardTest.php',                // Phase 7
    'McpModuleGuardTest.php',             // Phase 7
    'ClientLicenseUiTest.php',            // Phase 8
    'DynamicModuleInstallerTest.php',     // Dynamic Modules (v1.2)
] as $file) {
    require __DIR__ . '/integration/' . $file;
}
exit(unit_summary());
