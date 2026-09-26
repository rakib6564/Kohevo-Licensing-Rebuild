<?php
/**
 * Phase 9 focused licensing run: the new expiry/grace suite plus every
 * Phase 4–8 licensing integration suite it must not regress. Pure unit
 * suites (CommercialLicenseWindowTest, LicenseStatusPresenterTest,
 * RemoteLicenseClientDetailedTest, Phase3RemoteAuthorityTest) run under
 * tests/unit/run.php.
 *
 * Usage: php tests/run-phase9-licensing.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('SLATE_TESTING', true);
require __DIR__ . '/../config.php';
require __DIR__ . '/guard.php';
slate_require_test_database();
require __DIR__ . '/unit/harness.php';
foreach ([
    'Phase9ExpiryGraceTest.php',          // Phase 9
    'Phase4SurfaceSecurityTest.php',      // Phase 4
    'Phase3EntitlementAuthorityTest.php', // Phase 3/4
    'LicenseGateTest.php',                // Phase 4/5
    'InstallerLicenseActivationFlowTest.php', // Phase 5
    'GlobalLicenseGuardTest.php',         // Phase 6
    'ModuleGuardTest.php',                // Phase 7
    'McpModuleGuardTest.php',             // Phase 7
    'ClientLicenseUiTest.php',            // Phase 8
] as $file) {
    if (is_file(__DIR__ . '/integration/' . $file)) require __DIR__ . '/integration/' . $file;
}
exit(unit_summary());
