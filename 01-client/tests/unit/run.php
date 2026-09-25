<?php
/**
 * Slate — unit-test runner (Phase 1, dependency-free).
 *
 * Boots ONLY the PSR-4 autoloader (no config.php, no DB), loads the harness,
 * then includes every *Test.php in this directory. Exit 0 = all passed.
 *
 * Usage: php tests/unit/run.php
 */

declare(strict_types=1);

// Never over HTTP. These runners touch the database — the integration suite
// creates and deletes rows — and there is no reason for a web request to
// reach them. .htaccess denies the directory too; this is the backstop for
// when server config does not.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../../src/autoload.php';   // Slate\ -> src/ (no side effects)
require __DIR__ . '/harness.php';

echo "# Slate unit tests\n";

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

exit(unit_summary());
