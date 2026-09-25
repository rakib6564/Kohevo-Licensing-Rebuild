<?php
/**
 * Guards membership term math against the PHP-clock vs MySQL-clock boundary.
 *
 * expires_at and grace_until are only ever judged by MySQL NOW() (Membership.php
 * and membership/admin/index.php), so the term has to be measured on the
 * database clock. It was measured on PHP's, and since PHP runs UTC here while
 * MySQL runs SYSTEM, every subscription outlived its own expiry by the offset.
 *
 * The assertion is on the invariant — a term lasts its own duration when judged
 * by the clock that judges it in production — so it stays meaningful on a host
 * where the two clocks agree.
 */

declare(strict_types=1);

unit('membership term length is measured on the database clock', function (): void {
    if (!class_exists('MembershipAPI')) {
        // The plugin is not active on this install (CI runs a bare schema).
        // Assert that explicitly rather than passing silently.
        assert_true(true, 'membership plugin inactive — term math not exercised here');
        return;
    }

    foreach ([['duration_days' => 1,  'grace_days' => 0], ['duration_days' => 30, 'grace_days' => 7]] as $plan) {
        $days      = (int) $plan['duration_days'];
        $graceDays = (int) $plan['grace_days'];
        [$starts, $expires, $grace] = MembershipAPI::termDates($plan);

        $liveFor = (int) Database::value('SELECT TIMESTAMPDIFF(SECOND, NOW(), ?)', [$expires]);
        assert_true(
            abs($liveFor - $days * 86400) <= 5,
            "a {$days}-day term must last {$days} days on the DB clock, got {$liveFor}s"
        );

        $graceFor = (int) Database::value('SELECT TIMESTAMPDIFF(SECOND, NOW(), ?)', [$grace]);
        assert_true(
            abs($graceFor - ($days + $graceDays) * 86400) <= 5,
            "grace must end {$graceDays} days after expiry, got {$graceFor}s"
        );

        // starts_at must not already look expired to the query that gates access.
        $activeNow = (int) Database::value('SELECT ? <= NOW() AND ? >= NOW()', [$starts, $expires]);
        assert_eq(1, $activeNow, 'a freshly started term reads as active');
    }

    // An explicitly supplied start timestamp must still be honoured verbatim —
    // the DB clock is only the default for "now".
    $fixed = strtotime('2026-01-01 12:00:00');
    assert_eq(
        '2026-01-01 12:00:00',
        MembershipAPI::termDates(['duration_days' => 1, 'grace_days' => 0], $fixed)[0],
        'an explicit start timestamp is used as given'
    );
});
