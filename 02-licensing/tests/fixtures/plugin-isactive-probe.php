<?php
/**
 * Boot the app fresh in a CHILD process and report PluginLoader::isActive()
 * for one slug.
 *
 * A child, not the current process, because PluginLoader::boot() only ever
 * runs once per process (guarded by its own `self::$booted` flag) — the
 * parent test runner already booted it before this file's Test.php even
 * loads, so calling PluginLoader::boot() again in-process would be a no-op
 * and prove nothing about a fresh read of the `plugins` table.
 *
 * Usage: php tests/fixtures/plugin-isactive-probe.php <slug>
 * Prints exactly "true" or "false" to stdout.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$slug = $argv[1] ?? '';
if ($slug === '') { fwrite(STDERR, "usage: plugin-isactive-probe.php <slug>\n"); exit(2); }

$root = dirname(__DIR__, 2);
require $root . '/config.php';

echo PluginLoader::isActive($slug) ? 'true' : 'false';
