<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config.php';
require __DIR__ . '/guard.php';
slate_require_test_database();
require __DIR__ . '/unit/harness.php';
// These suites run under an explicit, finite compatibility window.
// Production does not enable this implicitly. Since Phase 13 the window no
// longer grants anything from the local licenses/plans tables (see
// docs/03-implementation/PHASE-13-LEGACY-HANDLING.md), so running under it
// checks that legacy mode stays non-authoritative.
//
// EntitlementServiceTest.php and PlatformIdentityWhiteLabelTest.php were
// removed from this runner in Phase 13: their grant assertions ("active local
// license + entitled local plan = access") describe the legacy authority
// Phase 13 removed. They still run in tests/integration/run.php, where they
// have been recorded as pre-existing failures since Phase 3. The replacement
// assertions live in Phase3EntitlementAuthorityTest.php and
// Phase13LegacyHandlingTest.php.
$_ENV['LICENSE_COMPAT_MODE'] = 'legacy';
$_ENV['LICENSE_COMPAT_UNTIL'] = gmdate('Y-m-d', time() + 86400);
putenv('LICENSE_COMPAT_MODE=legacy');
putenv('LICENSE_COMPAT_UNTIL=' . $_ENV['LICENSE_COMPAT_UNTIL']);
require __DIR__ . '/integration/LicenseServiceTest.php';
require __DIR__ . '/integration/Phase3EntitlementAuthorityTest.php';
require __DIR__ . '/integration/LicenseGateTest.php';
exit(unit_summary());
