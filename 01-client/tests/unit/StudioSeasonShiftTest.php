<?php
/**
 * Studio — SeasonShift (pure) unit tests.
 *
 * The one that matters: a Monday class must still be on a Monday after the
 * season is duplicated. Everything else follows from measuring the shift in
 * whole weeks.
 */

declare(strict_types=1);

use Slate\Module\Studio\Domain\SeasonShift as SS;

// ── Weekday preservation, the whole point ─────────────────────────────

unit('every shifted date keeps its weekday', function () {
    foreach ([1, 2, 13, 18, 52, -4] as $weeks) {
        foreach (['2026-09-07', '2026-09-12', '2027-01-04'] as $d) {
            $moved = SS::shift($d, $weeks);
            assert_eq(date('w', strtotime($d)), date('w', strtotime($moved)),
                "$d shifted $weeks weeks must stay on the same weekday");
        }
    }
});

unit('a term moved by whole weeks lands where expected', function () {
    assert_eq('2027-01-11', SS::shift('2026-09-07', 18));
    assert_eq('2026-08-31', SS::shift('2026-09-07', -1));
    assert_eq('2026-09-07', SS::shift('2026-09-07', 0));
});

// ── Measuring the gap ─────────────────────────────────────────────────

unit('weeksBetween counts whole weeks', function () {
    assert_eq(18, SS::weeksBetween('2026-09-07', '2027-01-11'));
    assert_eq(1,  SS::weeksBetween('2026-09-07', '2026-09-14'));
    assert_eq(0,  SS::weeksBetween('2026-09-07', '2026-09-07'));
    assert_eq(-1, SS::weeksBetween('2026-09-14', '2026-09-07'), 'backwards is negative');
});

unit('a gap that is not a whole number of weeks rounds to nearest', function () {
    // Mon 7 Sep -> Wed 9 Sep is 2 days: rounds to 0 weeks, not 1.
    assert_eq(0, SS::weeksBetween('2026-09-07', '2026-09-09'));
    // Mon 7 Sep -> Fri 11 Sep is 4 days: rounds up to 1 week.
    assert_eq(1, SS::weeksBetween('2026-09-07', '2026-09-11'));
});

unit('an unusable date yields null, never a silent zero shift', function () {
    assert_true(SS::weeksBetween('', '2027-01-11') === null);
    assert_true(SS::weeksBetween('2026-09-07', 'next term') === null);
    assert_true(SS::weeksBetween('2026-02-30', '2027-01-11') === null, 'February has no 30th');
    assert_true(SS::shift('nope', 4) === null);
});

// ── Owning up to the rounding ─────────────────────────────────────────

unit('driftDays is zero when the new start is the same weekday', function () {
    assert_eq(0, SS::driftDays('2026-09-07', '2027-01-11'), 'Monday to Monday');
});

unit('driftDays reports how far off a mismatched weekday lands', function () {
    // Asking for Wed 9 Sep from Mon 7 Sep rounds to 0 weeks, so the term still
    // starts Mon 7 Sep — two days BEFORE what was typed.
    assert_eq(-2, SS::driftDays('2026-09-07', '2026-09-09'));
    // Asking for Fri 11 Sep rounds up a week to Mon 14 Sep — three days after.
    assert_eq(3, SS::driftDays('2026-09-07', '2026-09-11'));
});

// ── Planning a whole season ───────────────────────────────────────────

unit('every class shifts by the same number of weeks', function () {
    $classes = [
        ['id' => 1, 'session_start' => '2026-09-07', 'session_end' => '2026-12-14'],
        ['id' => 2, 'session_start' => '2026-09-12', 'session_end' => '2026-12-19'],
    ];
    $out = SS::plan($classes, 18);
    assert_eq('2027-01-11', $out[0]['session_start']);
    assert_eq('2027-04-19', $out[0]['session_end']);
    assert_eq('2027-01-16', $out[1]['session_start']);
    assert_eq(18, $out[0]['_shift_weeks']);
});

unit('the shape of the term is preserved, not re-anchored', function () {
    // A class starting three weeks into the term still starts three weeks in.
    $classes = [
        ['id' => 1, 'session_start' => '2026-09-07', 'session_end' => '2026-12-14'],
        ['id' => 2, 'session_start' => '2026-09-28', 'session_end' => '2026-12-14'],
    ];
    $out = SS::plan($classes, 18);
    assert_eq(21, (strtotime($out[1]['session_start']) - strtotime($out[0]['session_start'])) / 86400,
        'the three-week offset survives the clone');
});

unit('a class with unusable dates is carried through, never dropped', function () {
    $out = SS::plan([
        ['id' => 1, 'session_start' => '2026-09-07', 'session_end' => '2026-12-14'],
        ['id' => 2, 'session_start' => '',           'session_end' => ''],
    ], 4);
    assert_eq(2, count($out), 'losing a class silently during a clone is the worse failure');
    assert_true(!empty($out[1]['_shift_failed']), 'but it is flagged for the caller');
    assert_eq(0, $out[1]['_shift_weeks']);
});

unit('plan ignores non-array rows rather than fataling', function () {
    assert_eq(1, count(SS::plan([['session_start' => '2026-09-07', 'session_end' => '2026-09-14'], 'junk', null], 2)));
});

// ── Naming the copy ───────────────────────────────────────────────────

unit('copy names do not stack suffixes', function () {
    assert_eq('Fall 2026 (copy)',   SS::copyName('Fall 2026'));
    assert_eq('Fall 2026 (copy 2)', SS::copyName('Fall 2026 (copy)'));
    assert_eq('Fall 2026 (copy 3)', SS::copyName('Fall 2026 (copy 2)'));
    assert_eq('Untitled season (copy)', SS::copyName('  '));
});
