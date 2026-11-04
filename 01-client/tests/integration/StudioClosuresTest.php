<?php
/**
 * Integration tests for lesson cancellation and holiday closures.
 *
 * HolidayCalendar's parsing is unit-tested with no DB. What can only be proved
 * here is the wiring: that generation skips a closure date entirely, that
 * adding a holiday AFTER a season is generated cancels the lesson that already
 * exists, that a cancellation is idempotent, and that taken attendance
 * survives a later cancellation.
 *
 * Runs under a throwaway tenant and cleans up in a finally block.
 */

declare(strict_types=1);

use Slate\Tenancy\TenantContext;

require_once __DIR__ . '/../../plugins/studio/StudioAPI.php';

function _studio_clos_cleanup(int $tid, array $contactIds): void
{
    foreach (['studio_attendance', 'studio_enrollments', 'studio_class_occurrences',
              'studio_class_series', 'studio_family_members', 'studio_families',
              'studio_contact_roles'] as $t) {
        Database::query("DELETE FROM {$t} WHERE tenant_id = ?", [$tid]);
    }
    Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = 'studio.holidays'", [$tid]);
    foreach ($contactIds as $id) {
        Database::query('DELETE FROM contacts WHERE id = ?', [(int) $id]);
    }
}

$_clos_ready = (int) Database::value(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'studio_class_occurrences'
        AND COLUMN_NAME = 'cancelled_at'"
) > 0;

if (!$_clos_ready) {
    unit('studio closures: skipped — 0009_studio_occurrence_note not applied', function () {});
    return;
}

unit('studio closures: holidays skip generation, cancellation is recorded and reversible', function () {
    $tenants = new TenantContext();
    $tid     = slate_test_tenant(current_tenant_id() + 990005);

    $teacher = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__clos_teacher']);
    $student = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__clos_student']);

    try {
        $tenants->runAs($tid, function () use ($tid, $teacher, $student) {
            // Five Mondays: 7, 14, 21, 28 Dec 2026 and 4 Jan 2027.
            $mk = static fn (): int => StudioAPI::createClassSeries([
                'name' => '__Clos Jazz', 'style' => 'jazz', 'instructor_id' => $teacher,
                'capacity' => 20, 'day_of_week' => 1,
                'start_time' => '16:00', 'end_time' => '17:00',
                'session_start' => '2026-12-07', 'session_end' => '2027-01-04',
                'price_cents' => 8500, 'currency' => 'USD',
            ]);

            // ── No holidays: every Monday generates ──
            $a = $mk();
            assert_eq(5, StudioAPI::generateOccurrences($a), 'five Mondays in the window');

            // ── With a closure range, those dates never generate at all ──
            // saveHolidays must invalidate the memo the previous generate()
            // populated — otherwise the new closures silently do not apply.
            StudioAPI::saveHolidays("2026-12-21..2026-12-28 Winter break");
            assert_eq(8, StudioAPI::holidayCalendar()->count(),
                'the saved range is visible immediately, not on the next request');

            $b = $mk();
            $madeB = StudioAPI::generateOccurrences($b);
            assert_eq(3, $madeB,
                'the 21st and 28th are closed, so three lessons — not five, and not five with two cancelled');
            $datesB = array_column(Database::rows(
                "SELECT occurrence_date FROM studio_class_occurrences
                  WHERE tenant_id = ? AND series_id = ? ORDER BY occurrence_date", [$tid, $b]), 'occurrence_date');
            assert_eq(['2026-12-07', '2026-12-14', '2027-01-04'], $datesB);

            // ── A holiday added AFTER generation cancels what already exists ──
            $n = StudioAPI::applyHolidaysToExisting();
            assert_eq(2, $n, 'the first series had already generated the 21st and 28th');
            $cancelled = Database::rows(
                "SELECT occurrence_date, note, status FROM studio_class_occurrences
                  WHERE tenant_id = ? AND series_id = ? AND status = 'cancelled'
               ORDER BY occurrence_date", [$tid, $a]);
            assert_eq(2, count($cancelled));
            assert_eq('Winter break', (string) $cancelled[0]['note'],
                'the closure label becomes the reason, so the parent is told why');

            // Re-running changes nothing — nothing left to cancel.
            assert_eq(0, StudioAPI::applyHolidaysToExisting(), 'applying holidays twice is a no-op');

            // ── Cancelling one lesson by hand ──
            $live = (int) Database::value(
                "SELECT id FROM studio_class_occurrences
                  WHERE tenant_id = ? AND series_id = ? AND status = 'scheduled'
               ORDER BY occurrence_date LIMIT 1", [$tid, $a]);
            assert_true(StudioAPI::cancelOccurrence($live, 'Snow — Seaford closed'));
            assert_false(StudioAPI::cancelOccurrence($live, 'again'),
                'cancelling twice must not re-fire the hook or rewrite the reason');

            $row = Database::row("SELECT status, note, cancelled_at FROM studio_class_occurrences
                                   WHERE tenant_id = ? AND id = ?", [$tid, $live]);
            assert_eq('cancelled', (string) $row['status']);
            assert_eq('Snow — Seaford closed', (string) $row['note']);
            assert_true(!empty($row['cancelled_at']), 'the timeline is queryable, not just a string');

            // ── Attendance already taken survives a later cancellation ──
            StudioAPI::enrollStudent($student, $a);
            $withAtt = (int) Database::value(
                "SELECT id FROM studio_class_occurrences
                  WHERE tenant_id = ? AND series_id = ? AND status = 'scheduled'
               ORDER BY occurrence_date LIMIT 1", [$tid, $a]);
            StudioAPI::markAttendance($withAtt, [$student => 'present']);
            assert_eq(1, (int) Database::value(
                "SELECT COUNT(*) FROM studio_attendance WHERE tenant_id = ? AND occurrence_id = ?",
                [$tid, $withAtt]));

            StudioAPI::cancelOccurrence($withAtt, 'Called off late');
            assert_eq(1, (int) Database::value(
                "SELECT COUNT(*) FROM studio_attendance WHERE tenant_id = ? AND occurrence_id = ?",
                [$tid, $withAtt]),
                'who actually turned up is history — a later cancellation does not erase it');

            // ── Restoring puts it back and clears the reason ──
            assert_true(StudioAPI::restoreOccurrence($live));
            $row = Database::row("SELECT status, note, cancelled_at FROM studio_class_occurrences
                                   WHERE tenant_id = ? AND id = ?", [$tid, $live]);
            assert_eq('scheduled', (string) $row['status']);
            assert_true($row['note'] === null && $row['cancelled_at'] === null,
                'a restored lesson carries no stale cancellation reason');
            assert_false(StudioAPI::restoreOccurrence($live), 'restoring a live lesson is a no-op');
        });
    } finally {
        _studio_clos_cleanup($tid, [$teacher, $student]);
    }
});
