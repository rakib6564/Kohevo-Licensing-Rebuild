<?php
/**
 * Slate — minimal unit-test harness (Phase 1, dependency-free).
 *
 * No PHPUnit / no composer install — runs on shared hosting exactly as
 * production does. Isolated: unit tests exercise `Slate\*` classes via the
 * autoloader ONLY; they never boot config.php or touch the database, so a
 * value object or pure helper is tested with zero side effects.
 *
 * TAP-ish output, matching tests/smoke.php. Usage: see tests/unit/run.php.
 */

declare(strict_types=1);

// Per-checkout identifier namespacing. Loaded here because both the unit and
// integration runners include this harness, and the integration suite is the
// one that writes rows two checkouts could collide over.
require_once __DIR__ . '/../isolation.php';

$GLOBALS['_UNIT'] = ['pass' => 0, 'fail' => 0];

/** Register + run one test case. A test passes iff its body throws nothing. */
function unit(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['_UNIT']['pass']++;
        echo "ok   - $name\n";
    } catch (\Throwable $e) {
        $GLOBALS['_UNIT']['fail']++;
        echo "FAIL - $name  (" . $e->getMessage() . ")\n";
    }
}

function assert_true($cond, string $msg = ''): void
{
    if ($cond !== true) {
        throw new \Exception($msg !== '' ? $msg : 'expected true, got ' . var_export($cond, true));
    }
}

function assert_false($cond, string $msg = ''): void
{
    if ($cond !== false) {
        throw new \Exception($msg !== '' ? $msg : 'expected false, got ' . var_export($cond, true));
    }
}

function assert_null($actual, string $msg = ''): void
{
    if ($actual !== null) {
        throw new \Exception($msg !== '' ? $msg : 'expected null, got ' . var_export($actual, true));
    }
}

function assert_eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \Exception(
            ($msg !== '' ? $msg . ': ' : '') .
            'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true)
        );
    }
}

/** Assert that $fn throws an instance of $exceptionClass. */
function assert_throws(string $exceptionClass, callable $fn, string $msg = ''): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($e instanceof $exceptionClass) {
            return;
        }
        throw new \Exception("expected $exceptionClass, got " . get_class($e) . " ({$e->getMessage()})");
    }
    throw new \Exception($msg !== '' ? $msg : "expected $exceptionClass to be thrown, nothing thrown");
}

/** Print the summary and return a shell exit code (0 = all passed). */
function unit_summary(): int
{
    $pass = $GLOBALS['_UNIT']['pass'];
    $fail = $GLOBALS['_UNIT']['fail'];
    $total = $pass + $fail;
    echo "1..$total\n# passed $pass / $total\n";
    return $fail === 0 ? 0 : 1;
}
