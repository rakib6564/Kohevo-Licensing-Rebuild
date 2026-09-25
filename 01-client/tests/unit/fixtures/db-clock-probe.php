<?php
/**
 * Probe: does slate_db_time() read the database clock or PHP's?
 *
 * Needs its own process. helpers.php guards every definition with
 * function_exists, so a stub only wins if it is declared BEFORE the file is
 * loaded — and by the time any test runs, another test has usually loaded it
 * already. Running here means the stub is genuinely first.
 *
 * The stub returns a fixed instant far from now, so "derives from the DB
 * clock" and "happens to equal PHP time()" cannot both be true.
 *
 * Usage: php db-clock-probe.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const PROBE_INSTANT = '2020-01-02 03:04:05';

/** Stands in for the real DB round trip. Declared before helpers.php loads. */
function slate_db_now(): string { return PROBE_INSTANT; }

require dirname(__DIR__, 3) . '/includes/helpers.php';

echo json_encode([
    'db_now'        => slate_db_now(),
    'db_time'       => slate_db_time(),
    'expected'      => strtotime(PROBE_INSTANT),
    'php_time'      => time(),
    // No database was configured in this process. If slate_db_time() had
    // reached for one — or fallen back to PHP's clock — this would show it.
    'used_db_clock' => slate_db_time() === strtotime(PROBE_INSTANT),
]), "\n";
