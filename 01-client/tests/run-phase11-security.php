<?php
/**
 * Phase 11 focused security run: the Phase 11 hardening suite plus every
 * Phase 3–10 licensing suite it must not regress (the Phase 10 runner's
 * list) and the Booking suites whose entry points Phase 11 touched.
 * Phase10SynchronizationTest drives the real Central Server from
 * ../02-licensing, so both checkouts must be provisioned.
 *
 * Usage: php tests/run-phase11-security.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('SLATE_TESTING', true);
require __DIR__ . '/../config.php';
require __DIR__ . '/guard.php';
slate_require_test_database();
require __DIR__ . '/unit/harness.php';
foreach ([
    'Phase11SecurityHardeningTest.php',   // Phase 11
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
    'BookingMessagePublicGuardTest.php',  // touched: booking/public/message.php
    'BookingApiAuthorizationTest.php',    // same mcp-gateway activation pattern as Phase 11 V3
    'BookingCanBookGateTest.php',
] as $file) {
    require __DIR__ . '/integration/' . $file;
}
exit(unit_summary());
