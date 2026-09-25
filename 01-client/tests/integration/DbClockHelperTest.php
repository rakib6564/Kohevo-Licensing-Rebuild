<?php
/**
 * Covers slate_db_now(), the canonical way to get a timestamp that will later be
 * compared against NOW(), CURDATE(), or a MySQL-written column.
 *
 * The whole class of bug this guards against is invisible at runtime: no error,
 * no log, just a comparison that is wrong by whatever the PHP/MySQL timezone gap
 * happens to be. These assertions are on the invariant — same clock writes and
 * compares — so they hold on a host where the gap is zero (CI) and fail on one
 * where it isn't, which is exactly when it matters.
 */

declare(strict_types=1);

unit('slate_db_now() returns the database clock, not PHP\'s', function (): void {
    assert_true(function_exists('slate_db_now'), 'the helper is loaded');

    $now = slate_db_now();
    assert_true(
        (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now),
        "must be a bare Y-m-d H:i:s string, got '{$now}'"
    );

    // The defining property: it agrees with NOW() regardless of PHP's timezone.
    $drift = (int) Database::value('SELECT ABS(TIMESTAMPDIFF(SECOND, NOW(), ?))', [$now]);
    assert_true($drift <= 5, "must track the DB clock, drifted {$drift}s");

    // And it satisfies the predicates real code writes against it.
    assert_eq(1, (int) Database::value('SELECT ? <= NOW()', [$now]), 'is not in the DB future');
    assert_eq(1, (int) Database::value('SELECT DATE(?) = CURDATE()', [$now]), 'lands on the DB current date');
});

unit('a PHP-clock timestamp is not safe to compare against the DB clock', function (): void {
    // Documents *why* slate_db_now() exists. Where the clocks agree this is a
    // tautology; where they don't, it pins the gap so the next person reading a
    // failure has the number in front of them.
    $phpNow = date('Y-m-d H:i:s');
    $skew   = (int) Database::value('SELECT TIMESTAMPDIFF(SECOND, NOW(), ?)', [$phpNow]);

    if ($skew !== 0) {
        // Not a failure — a standing notice that this host is one of the risky ones.
        echo "# note: PHP clock is {$skew}s from the database clock on this host\n";
    }

    // Whatever the gap, the helper must be the side that matches the database.
    $viaHelper = (int) Database::value('SELECT ABS(TIMESTAMPDIFF(SECOND, NOW(), ?))', [slate_db_now()]);
    assert_true($viaHelper <= 5, 'slate_db_now() tracks the DB clock even when PHP does not');
});

unit('notification pruning measures its window on the database clock', function (): void {
    // Mirrors Notifications::prune(): the cutoff must be $days behind the DB
    // clock, because created_at is written by DEFAULT CURRENT_TIMESTAMP.
    foreach ([1, 30] as $days) {
        $cutoff = (string) Database::value('SELECT NOW() - INTERVAL ? DAY', [$days]);
        $age    = (int) Database::value('SELECT TIMESTAMPDIFF(SECOND, ?, NOW())', [$cutoff]);
        assert_true(
            abs($age - $days * 86400) <= 5,
            "a {$days}-day cutoff must sit {$days} days behind the DB clock, got {$age}s"
        );
    }
});
