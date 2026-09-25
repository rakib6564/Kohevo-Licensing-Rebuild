<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config.php';
require __DIR__ . '/guard.php';
slate_require_test_database();
require __DIR__ . '/unit/harness.php';
// Existing local-authority tests run under an explicit, finite compatibility
// window. Production does not enable this implicitly.
$_ENV['LICENSE_COMPAT_MODE'] = 'legacy';
$_ENV['LICENSE_COMPAT_UNTIL'] = gmdate('Y-m-d', time() + 86400);
putenv('LICENSE_COMPAT_MODE=legacy');
putenv('LICENSE_COMPAT_UNTIL=' . $_ENV['LICENSE_COMPAT_UNTIL']);
require __DIR__ . '/integration/LicenseServiceTest.php';
require __DIR__ . '/integration/EntitlementServiceTest.php';
require __DIR__ . '/integration/PlatformIdentityWhiteLabelTest.php';
require __DIR__ . '/integration/Phase3EntitlementAuthorityTest.php';
require __DIR__ . '/integration/LicenseGateTest.php';
exit(unit_summary());
