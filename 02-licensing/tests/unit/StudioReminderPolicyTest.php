<?php
/**
 * Studio — ReminderPolicy (pure) unit tests.
 *
 * The two that matter: a step never repeats (or the studio spams parents), and
 * catching up on a long-overdue fee sends the MOST urgent step rather than
 * replaying the whole sequence.
 */

declare(strict_types=1);

use Slate\Module\Studio\Domain\ReminderPolicy as RP;

// ── Offsets ───────────────────────────────────────────────────────────

unit('offsetDays is positive when overdue, negative when upcoming', function () {
    assert_eq(0,   RP::offsetDays('2026-11-01', '2026-11-01'));
    assert_eq(7,   RP::offsetDays('2026-11-01', '2026-11-08'));
    assert_eq(-7,  RP::offsetDays('2026-11-01', '2026-10-25'));
    assert_eq(92,  RP::offsetDays('2026-11-01', '2027-02-01'), 'spans a year boundary');
});

unit('an unparseable date yields no offset rather than a wrong one', function () {
    assert_true(RP::offsetDays('', '2026-11-01') === null);
    assert_true(RP::offsetDays('2026-11-01', 'soon') === null);
});

// ── Step selection ────────────────────────────────────────────────────

unit('nothing fires before the first step', function () {
    $p = RP::default();
    assert_true($p->stepFor('2026-11-01', '2026-10-01') === null, '31 days out is too early');
    assert_true($p->stepFor('2026-11-01', '2026-10-24') === null, '8 days out is still too early');
});

unit('the upcoming nudge fires from a week out', function () {
    $p = RP::default();
    assert_eq(-7, $p->stepFor('2026-11-01', '2026-10-25'));
    assert_eq(-7, $p->stepFor('2026-11-01', '2026-10-29'), 'still the -7 step 3 days out');
});

unit('due today fires the 0 step', function () {
    assert_eq(0, RP::default()->stepFor('2026-11-01', '2026-11-01', [-7]));
});

unit('overdue steps fire in order', function () {
    $p = RP::default();
    assert_eq(7,  $p->stepFor('2026-11-01', '2026-11-08', [-7, 0]));
    assert_eq(14, $p->stepFor('2026-11-01', '2026-11-15', [-7, 0, 7]));
    assert_eq(30, $p->stepFor('2026-11-01', '2026-12-01', [-7, 0, 7, 14]));
});

unit('a step already sent never sends again', function () {
    $p = RP::default();
    assert_true($p->stepFor('2026-11-01', '2026-11-01', [-7, 0]) === null,
        'due-today already went out; nothing else qualifies yet');
    assert_true($p->stepFor('2026-11-01', '2026-10-26', [-7]) === null);
});

unit('catching up sends the most urgent step, not the whole sequence', function () {
    // 40 days overdue and never reminded: the parent gets "a month overdue",
    // not "due in a week" followed by four more emails.
    assert_eq(30, RP::default()->stepFor('2026-11-01', '2026-12-11', []));
});

unit('past the last step nothing further fires', function () {
    $p = RP::default();
    assert_true($p->stepFor('2026-11-01', '2027-06-01', [-7, 0, 7, 14, 30]) === null,
        'the schedule ends; chasing becomes a phone call');
});

unit('a fee with no due date is never chased', function () {
    assert_true(RP::default()->stepFor('', '2026-11-01') === null);
});

// ── Configuration ─────────────────────────────────────────────────────

unit('a custom schedule is honoured and sorted', function () {
    $p = RP::fromString('14, 0, -3');
    assert_eq([-3, 0, 14], $p->steps());
    assert_eq(-3, $p->stepFor('2026-11-01', '2026-10-29'));
    assert_true($p->stepFor('2026-11-01', '2026-10-25') === null, '-7 is not in this schedule');
});

unit('an unparseable schedule falls back rather than silently stopping all chasing', function () {
    foreach (['', '   ', 'soon, later', 'abc'] as $bad) {
        assert_eq(RP::STEPS, RP::fromString($bad)->steps(), var_export($bad, true));
    }
});

unit('duplicates in a schedule collapse', function () {
    assert_eq([0, 7], RP::fromString('0,7,7,0')->steps());
});

unit('labels track the schedule so wording cannot drift', function () {
    assert_eq('upcoming', RP::label(-7));
    assert_eq('due',      RP::label(0));
    assert_eq('overdue',  RP::label(7));
    assert_eq('overdue',  RP::label(30));
});
