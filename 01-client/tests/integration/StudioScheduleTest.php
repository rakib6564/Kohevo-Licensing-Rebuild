<?php
/**
 * Integration tests for the studio scheduling adapter against the real tables.
 *
 * ScheduleConflict's overlap arithmetic is unit-tested with no DB. What can
 * only be proved here is the wiring: that scheduleBlocks() reads what the
 * admin form wrote, that room_id survives a create and an update, that a
 * deactivated class stops occupying its room, and that editing a class does
 * not report it clashing with itself.
 *
 * Runs under a throwaway tenant and cleans up in a finally block.
 */

declare(strict_types=1);

use Slate\Tenancy\TenantContext;

require_once __DIR__ . '/../../plugins/studio/StudioAPI.php';

function _studio_sched_cleanup(int $tid, array $contactIds): void
{
    foreach (['studio_class_occurrences', 'studio_class_series', 'studio_contact_roles'] as $t) {
        Database::query("DELETE FROM {$t} WHERE tenant_id = ?", [$tid]);
    }
    foreach ($contactIds as $id) {
        Database::query('DELETE FROM contacts WHERE id = ?', [(int) $id]);
    }
}

$_sched_ready = (int) Database::value(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'studio_class_series'"
) > 0;

if (!$_sched_ready) {
    unit('studio schedule: skipped — 0005_studio_core not applied', function () {});
    return;
}

unit('studio schedule: room and instructor clashes across the real timetable', function () {
    $tenants = new TenantContext();
    $tid     = slate_test_tenant(current_tenant_id() + 990002);      // distinct from the other studio tests

    $teacherA = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__sched_teacher_a']);
    $teacherB = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__sched_teacher_b']);

    try {
        $tenants->runAs($tid, function () use ($tid, $teacherA, $teacherB) {
            $mk = static function (string $name, int $dow, string $from, string $to,
                                  ?int $room, int $teacher): int {
                return StudioAPI::createClassSeries([
                    'name'          => $name,
                    'style'         => 'jazz',
                    'instructor_id' => $teacher,
                    'room_id'       => $room,
                    'capacity'      => 20,
                    'day_of_week'   => $dow,
                    'start_time'    => $from,
                    'end_time'      => $to,
                    'session_start' => '2026-09-07',
                    'session_end'   => '2026-09-28',
                    'price_cents'   => 8500,
                    'currency'      => 'USD',
                ]);
            };

            // Monday 16:00–17:00 in room 1 with teacher A.
            $base = $mk('__Sched Base', 1, '16:00', '17:00', 1, $teacherA);

            // room_id must survive the insert — the adapter is worthless if the
            // column silently drops.
            assert_eq(1, (int) Database::value(
                "SELECT room_id FROM studio_class_series WHERE tenant_id = ? AND id = ?", [$tid, $base]),
                'room_id must persist through createClassSeries');

            // ── Back-to-back in the same room with the same teacher: allowed ──
            $after = $mk('__Sched After', 1, '17:00', '18:00', 1, $teacherA);
            assert_eq([], StudioAPI::scheduleConflictsFor([
                'id' => $after, 'day_of_week' => 1, 'start_time' => '17:00', 'end_time' => '18:00',
                'room_id' => 1, 'instructor_id' => $teacherA,
            ]), 'a clean handover is not a clash');

            // ── Overlapping, same room, different teacher: room clash only ──
            $hits = StudioAPI::scheduleConflictsFor([
                'id' => 0, 'day_of_week' => 1, 'start_time' => '16:15', 'end_time' => '16:45',
                'room_id' => 1, 'instructor_id' => $teacherB,
            ]);
            assert_eq(1, count($hits), 'one clash');
            assert_eq('room', $hits[0]['kind']);
            assert_eq('__Sched Base', (string) $hits[0]['with']['name']);

            // ── Overlapping, different room, same teacher: instructor clash ──
            $hits = StudioAPI::scheduleConflictsFor([
                'id' => 0, 'day_of_week' => 1, 'start_time' => '16:15', 'end_time' => '16:45',
                'room_id' => 2, 'instructor_id' => $teacherA,
            ]);
            assert_eq(1, count($hits));
            assert_eq('instructor', $hits[0]['kind']);

            // ── Overlapping on both axes: two findings ──
            $hits = StudioAPI::scheduleConflictsFor([
                'id' => 0, 'day_of_week' => 1, 'start_time' => '16:15', 'end_time' => '16:45',
                'room_id' => 1, 'instructor_id' => $teacherA,
            ]);
            assert_eq(['room', 'instructor'], array_column($hits, 'kind'));

            // ── Editing the base class does not clash with itself ──
            assert_eq([], StudioAPI::scheduleConflictsFor([
                'id' => $base, 'day_of_week' => 1, 'start_time' => '16:00', 'end_time' => '17:00',
                'room_id' => 1, 'instructor_id' => $teacherA,
            ]), 'the row being saved is in the timetable it is checked against');

            // ── A real clash now exists in the timetable ──
            $clash = $mk('__Sched Clash', 1, '16:15', '16:45', 1, $teacherA);
            $all   = StudioAPI::scheduleConflicts();
            $kinds = array_column($all, 'kind');
            sort($kinds);
            assert_eq(['instructor', 'room'], $kinds, 'both axes reported, each once');

            // ── Deactivating frees the room ──
            StudioAPI::setClassSeriesActive($clash, false);
            assert_eq([], StudioAPI::scheduleConflicts(),
                'a deactivated class is not occupying anything');

            // ── room_id survives an update, and can be cleared ──
            StudioAPI::updateClassSeries($base, ['room_id' => 2]);
            assert_eq(2, (int) Database::value(
                "SELECT room_id FROM studio_class_series WHERE tenant_id = ? AND id = ?", [$tid, $base]));

            // With base moved to room 2, the back-to-back class in room 1 is
            // still fine, and nothing new should appear.
            assert_eq([], StudioAPI::scheduleConflicts());
        });
    } finally {
        _studio_sched_cleanup($tid, [$teacherA, $teacherB]);
    }
});
