<?php
/**
 * Kohevo Studio (studio-builder) suites — the CI entry point.
 *
 *   php tests/run-studio-builder.php unit          # tests/unit/StudioBuilder*Test.php (autoloader only, no DB)
 *   php tests/run-studio-builder.php integration   # tests/integration/StudioBuilder*Test.php (test database)
 *
 * The full tests/unit/run.php and tests/integration/run.php also include
 * these files; this runner selects exactly the Studio Builder suites so CI
 * can gate on them without inheriting unrelated pre-existing failures.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$suite = $argv[1] ?? '';
if (!in_array($suite, ['unit', 'integration'], true)) {
    fwrite(STDERR, "usage: php tests/run-studio-builder.php unit|integration\n");
    exit(2);
}

define('SLATE_TESTING', true);
if ($suite === 'unit') {
    require __DIR__ . '/../src/autoload.php';
} else {
    require __DIR__ . '/../config.php';
    require __DIR__ . '/guard.php';
    slate_require_test_database();
}
require __DIR__ . '/unit/harness.php';

echo "# Kohevo Studio {$suite} tests\n";
$files = glob(__DIR__ . "/{$suite}/StudioBuilder*Test.php") ?: [];
if ($files === []) {
    fwrite(STDERR, "no Studio Builder {$suite} tests found\n");
    exit(1);
}
foreach ($files as $file) {
    require $file;
}
exit(unit_summary());
