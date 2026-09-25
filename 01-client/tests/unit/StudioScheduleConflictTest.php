<?php
/**
 * Studio — ScheduleConflict (pure) unit tests.
 *
 * The one that matters most is back-to-back: a studio runs classes nose to
 * tail all afternoon, so treating a shared endpoint as a clash would flag the
 * entire timetable and make the feature worthless.
 */

declare(strict_types=1);

use Slate\Module\Studio\Domain\ScheduleConflict as SC;

/** @return array a schedule block */
function _blk(int $id, int $dow, string $from, string $to, ?int $room = null, ?int $instr = null): array
{
    return ['id' => $id, 'name' => "Class $id", 'day_of_week' => $dow,
            'start_time' => $from, 'end_time' => $to,
            'room_id' => $room, 'instructor_id' => $instr];
}

// ── Time parsing ──────────────────────────────────────────────────────

unit('minutes parses H:MM and HH:MM:SS', function () {
    assert_eq(960, SC::minutes('16:00'));
    assert_eq(960, SC::minutes('16:00:00'));
    assert_eq(570, SC::minutes('9:30'));
    assert_eq(0,   SC::minutes('00:00'));
});

unit('an unparseable time drops out instead of landing at midnight', function () {
    foreach (['', 'abc', '25:00', '16:99', '16', null] as $bad) {
        assert_true(SC::minutes($bad) === null, var_export($bad, true) . ' must not parse');
    }
});

// ── Overlap ───────────────────────────────────────────────────────────

unit('back-to-back classes in one room do not clash', function () {
    assert_false(SC::overlaps(_blk(1, 1, '10:00', '11:00'), _blk(2, 1, '11:00', '12:00')),
        'a clean handover is how a studio actually runs');
});

unit('genuinely overlapping times clash', function () {
    assert_true(SC::overlaps(_blk(1, 1, '10:00', '11:00'), _blk(2, 1, '10:30', '11:30')));
    assert_true(SC::overlaps(_blk(1, 1, '10:00', '12:00'), _blk(2, 1, '10:30', '11:00')),
        'fully contained still overlaps');
    assert_true(SC::overlaps(_blk(1, 1, '10:30', '11:00'), _blk(2, 1, '10:00', '12:00')),
        'containment is symmetric');
});

unit('different weekdays never clash', function () {
    assert_false(SC::overlaps(_blk(1, 1, '10:00', '11:00'), _blk(2, 2, '10:00', '11:00')));
});

unit('a zero-length or backwards block cannot clash', function () {
    assert_false(SC::overlaps(_blk(1, 1, '10:00', '10:00'), _blk(2, 1, '09:00', '11:00')));
    assert_false(SC::overlaps(_blk(1, 1, '11:00', '10:00'), _blk(2, 1, '09:00', '12:00')));
});

// ── against(): one proposed class vs the timetable ────────────────────

unit('same room at the same time is a room conflict', function () {
    $out = SC::against(_blk(0, 1, '10:00', '11:00', 5), [_blk(2, 1, '10:30', '11:30', 5)]);
    assert_eq(1, count($out));
    assert_eq(SC::KIND_ROOM, $out[0]['kind']);
});

unit('same instructor at the same time is an instructor conflict', function () {
    $out = SC::against(_blk(0, 1, '10:00', '11:00', null, 9), [_blk(2, 1, '10:30', '11:30', null, 9)]);
    assert_eq(1, count($out));
    assert_eq(SC::KIND_INSTRUCTOR, $out[0]['kind']);
});

unit('one clash can be both room and instructor', function () {
    $out = SC::against(_blk(0, 1, '10:00', '11:00', 5, 9), [_blk(2, 1, '10:30', '11:30', 5, 9)]);
    assert_eq(2, count($out), 'the studio needs to know both are double-booked');
    assert_eq([SC::KIND_ROOM, SC::KIND_INSTRUCTOR], array_column($out, 'kind'));
});

unit('different rooms at the same time are fine', function () {
    assert_eq([], SC::against(_blk(0, 1, '10:00', '11:00', 5), [_blk(2, 1, '10:00', '11:00', 6)]));
});

unit('two unassigned rooms are not a conflict', function () {
    assert_eq([], SC::against(_blk(0, 1, '10:00', '11:00', null), [_blk(2, 1, '10:00', '11:00', null)]),
        'scheduling before allocating space must not look broken');
});

unit('editing a class does not report it clashing with itself', function () {
    $existing = [_blk(7, 1, '10:00', '11:00', 5, 9)];
    assert_eq([], SC::against(_blk(7, 1, '10:00', '11:00', 5, 9), $existing),
        'the row being saved is in the timetable it is checked against');
});

unit('a new class (id 0) is checked against everything', function () {
    $out = SC::against(_blk(0, 1, '10:00', '11:00', 5), [_blk(7, 1, '10:00', '11:00', 5)]);
    assert_eq(1, count($out));
});

// ── findAll(): the whole timetable ────────────────────────────────────

unit('findAll reports each clashing pair once, not twice', function () {
    $out = SC::findAll([
        _blk(1, 1, '10:00', '11:00', 5),
        _blk(2, 1, '10:30', '11:30', 5),
    ]);
    assert_eq(1, count($out), 'one problem is one row, not one per side');
    assert_eq(SC::KIND_ROOM, $out[0]['kind']);
});

unit('findAll orders each pair by id so the report is stable', function () {
    $out = SC::findAll([
        _blk(9, 1, '10:30', '11:30', 5),
        _blk(3, 1, '10:00', '11:00', 5),
    ]);
    assert_eq(3, (int) $out[0]['a']['id'], 'lower id first regardless of input order');
    assert_eq(9, (int) $out[0]['b']['id']);
});

unit('findAll separates a room clash from an instructor clash', function () {
    $out = SC::findAll([
        _blk(1, 1, '10:00', '11:00', 5, 9),
        _blk(2, 1, '10:30', '11:30', 5, 9),
    ]);
    assert_eq(2, count($out));
    assert_eq([SC::KIND_ROOM, SC::KIND_INSTRUCTOR], array_column($out, 'kind'));
});

unit('a clean timetable reports nothing', function () {
    assert_eq([], SC::findAll([
        _blk(1, 1, '10:00', '11:00', 5, 9),
        _blk(2, 1, '11:00', '12:00', 5, 9),   // back to back, same room AND teacher
        _blk(3, 2, '10:00', '11:00', 5, 9),   // different day
        _blk(4, 1, '10:00', '11:00', 6, 8),   // different room and teacher
    ]));
});

unit('findAll ignores non-array entries rather than fataling', function () {
    assert_eq([], SC::findAll(['nonsense', 42, null]));
});

unit('describe summarises the other side of a conflict', function () {
    $c = ['kind' => SC::KIND_ROOM, 'with' => _blk(2, 1, '10:30', '11:30', 5)];
    assert_eq('Class 2 · Mon 10:30–11:30', SC::describe($c));
});
