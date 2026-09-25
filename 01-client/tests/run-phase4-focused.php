<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config.php';
require __DIR__ . '/guard.php';
slate_require_test_database();
require __DIR__ . '/unit/harness.php';
require __DIR__ . '/integration/Phase4SurfaceSecurityTest.php';
require __DIR__ . '/integration/TenantManagementTest.php';
require __DIR__ . '/integration/Phase3EntitlementAuthorityTest.php';
require __DIR__ . '/integration/LicenseGateTest.php';
exit(unit_summary());
