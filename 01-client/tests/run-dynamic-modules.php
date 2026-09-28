<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
define('SLATE_TESTING', true);
require __DIR__ . '/../config.php';
require __DIR__ . '/guard.php';
slate_require_test_database();
require __DIR__ . '/unit/harness.php';
require __DIR__ . '/integration/DynamicModuleInstallerTest.php';
exit(unit_summary());
