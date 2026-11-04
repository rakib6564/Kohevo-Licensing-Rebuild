<?php
/**
 * Integration tests for seasons and duplication against the real tables.
 *
 * SeasonShift's week arithmetic is unit-tested with no DB. What can only be
 * proved here is the wiring, and one rule that matters more than any of it:
 * duplicating a season must NOT carry enrolments forward. A new term is a new
 * commitment, and copying last term's roster would silently bill families for
 * classes nobody signed up to.
 *
 * Runs under a throwaway tenant and cleans up in a finally block.
 */

declare(strict_types=1);

use Slate\Tenancy\TenantContext;

require_once __DIR__ . '/../../plugins/studio/StudioAPI.php';

function _studio_season_cleanup(int $tid, array $contactIds): void
{
    foreach (['studio_attendance', 'studio_enrollments', 'studio_class_occurrences',
              'studio_class_series', 'studio_seasons', 'studio_family_members',
              'studio_families', 'studio_contact_roles'] as $t) {
        Database::query("DELETE FROM {$t} WHERE tenant_id = ?", [$tid]);
    }
    foreach ($contactIds as $id) {
        Database::query('DELETE FROM contacts WHERE id = ?', [(int) $id]);
    }
}

$_season_ready = (int) Database::value(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'studio_seasons'"
) > 0;

if (!$_season_ready) {
    unit('studio seasons: skipped — 0010_studio_seasons not applied', function () {});
    return;
}

unit('studio seasons: duplication shifts by whole weeks and never carries enrolments', function () {
    $tenants = new TenantContext();
    $tid     = slate_test_tenant(current_tenant_id() + 990006);

    $teacher = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__season_teacher']);
    $student = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__season_student']);

    try {
        $tenants->runAs($tid, function () use ($tid, $teacher, $student) {
            // Autumn term: Mondays 7 Sep – 5 Oct 2026 (five Mondays).
            $autumn = StudioAPI::createSeason([
                'name' => 'Autumn 2026', 'starts_on' => '2026-09-07', 'ends_on' => '2026-10-05',
                'is_current' => true,
            ]);
            assert_eq($autumn, (int) StudioAPI::currentSeason()['id'], 'is_current takes effect');

            $mk = static function (string $name, int $dow, string $from, string $to) use ($teacher, $autumn, $tid): int {
                $id = StudioAPI::createClassSeries([
                    'name' => $name, 'style' => 'jazz', 'instructor_id' => $teacher,
                    'capacity' => 20, 'day_of_week' => $dow,
                    'start_time' => '16:00', 'end_time' => '17:00',
                    'session_start' => $from, 'session_end' => $to,
                    'price_cents' => 8500, 'currency' => 'USD',
                ]);
                Database::update('studio_class_series', ['season_id' => $autumn],
                    'tenant_id = ? AND id = ?', [$tid, $id]);
                return $id;
            };

            $monday = $mk('__Season Ballet', 1, '2026-09-07', '2026-10-05');
            // Starts two weeks late — the shape of the term must survive.
            $late   = $mk('__Season Tap',    3, '2026-09-23', '2026-10-07');

            StudioAPI::generateOccurrences($monday);
            StudioAPI::generateOccurrences($late);
            StudioAPI::enrollStudent($student, $monday);

            assert_eq(2, count(StudioAPI::seasonClasses($autumn)));
            assert_eq(1, (int) Database::value(
                "SELECT COUNT(*) FROM studio_enrollments WHERE tenant_id = ? AND series_id = ?",
                [$tid, $monday]));

            // ── Preview before committing ──
            $prev = StudioAPI::previewSeasonClone($autumn, '2027-01-11');   // 18 weeks on, a Monday
            assert_eq(18, $prev['weeks']);
            assert_eq(0,  $prev['drift'], 'Monday to Monday needs no apology');
            assert_eq('Autumn 2026 (copy)', $prev['name']);
            assert_eq(0, (int) Database::value(
                "SELECT COUNT(*) FROM studio_seasons WHERE tenant_id = ? AND name LIKE '%copy%'", [$tid]),
                'previewing must not create anything');

            // ── A mismatched weekday is reported, not silently absorbed ──
            $odd = StudioAPI::previewSeasonClone($autumn, '2027-01-13');    // a Wednesday
            assert_eq(18, $odd['weeks'], 'still rounds to whole weeks');
            assert_eq(-2, $odd['drift'], 'lands two days before what was typed, and says so');

            // ── Clone ──
            $res = StudioAPI::cloneSeason($autumn, '2027-01-11', 'Spring 2027');
            assert_eq(2, $res['classes']);
            assert_eq(0, $res['failed']);
            assert_true($res['occurrences'] > 0, 'a term with no lessons on the calendar is unusable');

            $new = StudioAPI::getSeason($res['season_id']);
            assert_eq('Spring 2027', (string) $new['name']);
            assert_eq('2027-01-11', (string) $new['starts_on']);
            assert_eq('2027-02-08', (string) $new['ends_on'], 'the end shifts by the same 18 weeks');

            // ── Weekdays preserved, shape preserved ──
            $copies = StudioAPI::seasonClasses($res['season_id']);
            assert_eq(2, count($copies));
            $byName = [];
            foreach ($copies as $c) { $byName[(string) $c['name']] = $c; }

            assert_eq('2027-01-11', (string) $byName['__Season Ballet']['session_start']);
            assert_eq(1, (int) $byName['__Season Ballet']['day_of_week'], 'Monday stays Monday');
            assert_eq('2027-01-27', (string) $byName['__Season Tap']['session_start'],
                'the two-week-late class is still two weeks late');
            assert_eq(3, (int) $byName['__Season Tap']['day_of_week'], 'Wednesday stays Wednesday');

            // ── THE rule: no enrolments carried forward ──
            $copyIds = array_map(static fn ($c) => (int) $c['id'], $copies);
            $ph = implode(',', array_fill(0, count($copyIds), '?'));
            assert_eq(0, (int) Database::value(
                "SELECT COUNT(*) FROM studio_enrollments WHERE tenant_id = ? AND series_id IN ($ph)",
                array_merge([$tid], $copyIds)),
                'a new term is a new commitment — copying the roster would bill families for classes nobody joined');

            // The original is untouched.
            assert_eq(1, (int) Database::value(
                "SELECT COUNT(*) FROM studio_enrollments WHERE tenant_id = ? AND series_id = ?",
                [$tid, $monday]), 'cloning must not disturb the season it copied');

            // ── Duplicating one class keeps it in the same season ──
            $dup = StudioAPI::duplicateClass($monday);
            $dupRow = StudioAPI::getClassSeries($dup);
            assert_eq('__Season Ballet (copy)', (string) $dupRow['name']);
            assert_eq($autumn, (int) $dupRow['season_id'], 'a second section belongs to the same term');
            assert_eq(0, (int) Database::value(
                "SELECT COUNT(*) FROM studio_enrollments WHERE tenant_id = ? AND series_id = ?",
                [$tid, $dup]), 'nor does duplicating one class carry its roster');

            // ── Deleting a season unassigns its classes, never deletes them ──
            $before = (int) Database::value(
                "SELECT COUNT(*) FROM studio_class_series WHERE tenant_id = ?", [$tid]);
            assert_true(StudioAPI::deleteSeason($res['season_id']));
            assert_eq($before, (int) Database::value(
                "SELECT COUNT(*) FROM studio_class_series WHERE tenant_id = ?", [$tid]),
                'removing the folder must not remove what was in it');
            assert_eq(0, (int) Database::value(
                "SELECT COUNT(*) FROM studio_class_series WHERE tenant_id = ? AND season_id = ?",
                [$tid, $res['season_id']]));

            // ── A season with no start date cannot be cloned ──
            $vague = StudioAPI::createSeason(['name' => 'Someday']);
            $threw = false;
            try { StudioAPI::previewSeasonClone($vague, '2027-01-11'); }
            catch (\InvalidArgumentException $e) { $threw = true; }
            assert_true($threw, 'shifting from nothing has no meaningful answer');
        });
    } finally {
        _studio_season_cleanup($tid, [$teacher, $student]);
    }
});
