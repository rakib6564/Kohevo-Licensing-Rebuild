<?php
/**
 * Phase 13 focused run: legacy handling (Phase13LegacyHandlingTest) plus the
 * suites whose code or expectations Phase 13 touched — EntitlementService's
 * legacy authority (Phase 3), the License page / dashboard presenter
 * (Phase 8), and both guards (Phases 6–7). The full Phase 3–12 set stays in
 * run-phase11-security.php. The central legacy screens are covered by
 * 02-licensing/tests/integration/Phase13LegacyAdminTest.php.
 *
 * Usage: php tests/run-phase13-legacy.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('SLATE_TESTING', true);
require __DIR__ . '/../config.php';
require __DIR__ . '/guard.php';
slate_require_test_database();
require __DIR__ . '/unit/harness.php';
foreach ([
    'Phase13LegacyHandlingTest.php',      // Phase 13
    'Phase3EntitlementAuthorityTest.php', // legacy authority expectation changed in Phase 13
    'ClientLicenseUiTest.php',            // presenter notice now also covers legacy mode
    'GlobalLicenseGuardTest.php',
    'ModuleGuardTest.php',
    'McpModuleGuardTest.php',
] as $file) {
    require __DIR__ . '/integration/' . $file;
}
require __DIR__ . '/unit/LicenseStatusPresenterTest.php';
exit(unit_summary());
