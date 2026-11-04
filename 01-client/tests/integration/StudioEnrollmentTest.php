<?php
/**
 * Integration tests for the Studio core flow against the real 0005_studio_core
 * tables: create contacts → family → class series → occurrences → enroll.
 *
 * Runs under a throwaway tenant (well above any real tenant id) and cleans up
 * every row it creates — contacts and all studio_* rows — in a finally block,
 * children-first. Requires `php bin/migrate migrate` to have applied 0005.
 */

declare(strict_types=1);

use Slate\Tenancy\TenantContext;

// The studio plugin ships INACTIVE, so PluginLoader never loads its bootstrap
// (it only requires active plugins). Load the facade explicitly for the test —
// this loads the class only; it does not install or activate the plugin.
require_once __DIR__ . '/../../plugins/studio/StudioAPI.php';

/** Delete every studio_* row and probe contact for the throwaway tenant. */
function _studio_cleanup(int $tid, array $contactIds): void
{
    foreach ([
        'studio_attendance',
        'studio_enrollments',
        'studio_class_occurrences',
        'studio_class_series',
        'studio_family_members',
        'studio_families',
        'studio_contact_roles',
    ] as $table) {
        Database::query("DELETE FROM {$table} WHERE tenant_id = ?", [$tid]);
    }
    foreach ($contactIds as $id) {
        Database::query('DELETE FROM contacts WHERE id = ?', [(int) $id]);
    }
}

// Register the real test only if 0005 has been applied. The harness has no
// "skip" state (any throw = fail), so pre-migration we register a passing no-op
// that names the reason — keeping the suite green regardless of migration order.
$_studio_ready = (int) Database::value(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'studio_class_series'"
) > 0;

if (!$_studio_ready) {
    unit('studio core: skipped — 0005_studio_core not applied (run `php bin/migrate migrate`)', function () {
        // no-op: nothing to assert until the migration is applied
    });
    return;
}

unit('studio core: family → series → occurrences → enroll (studio-local)', function () {
    $tenants = new TenantContext();
    $tid     = slate_test_tenant(current_tenant_id() + 990000);      // throwaway, isolated

    // Probe contacts live directly in the throwaway tenant.
    $parent     = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__studio_parent']);
    $studentA   = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__studio_child_a']);
    $studentB   = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__studio_child_b']);
    $instructor = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__studio_instructor']);

    try {
        $tenants->runAs($tid, function () use ($tid, $parent, $studentA, $studentB, $instructor) {
            // ── Family + roles ──
            $familyId = StudioAPI::createFamily($parent, [$studentA, $studentB]);
            assert_true($familyId > 0, 'family created');

            $fam = StudioAPI::getFamilyByParent($parent);
            assert_true($fam !== null, 'getFamilyByParent finds it');
            assert_eq($familyId, (int) $fam['id']);

            $kids = StudioAPI::familyStudentIds($familyId);
            assert_eq(2, count($kids), 'two children in the family');
            assert_true(in_array($studentA, $kids, true) && in_array($studentB, $kids, true));

            // Roles were tagged idempotently; assigning again is a no-op.
            StudioAPI::assignContactRole($instructor, 'instructor');
            StudioAPI::assignContactRole($instructor, 'instructor');
            $roleCount = (int) Database::value(
                'SELECT COUNT(*) FROM studio_contact_roles WHERE tenant_id = ? AND contact_id = ? AND role = ?',
                [$tid, $instructor, 'instructor']
            );
            assert_eq(1, $roleCount, 'assignContactRole is idempotent');

            // ── Class series ──
            $dow = (int) (new DateTimeImmutable('2026-03-02'))->format('w');
            $seriesId = StudioAPI::createClassSeries([
                'name'          => '__Studio Ballet I',
                'style'         => 'ballet',
                'level'         => 'beginner',
                'instructor_id' => $instructor,
                'capacity'      => 1,                     // tiny, to exercise the waitlist path
                'day_of_week'   => $dow,
                'start_time'    => '16:00',
                'end_time'      => '17:00',
                'session_start' => '2026-03-02',
                'session_end'   => '2026-03-30',         // ~4-5 weekly sessions
                'price_cents'   => 12000,
                'currency'      => 'USD',
            ]);
            assert_true($seriesId > 0, 'series created');

            $active = StudioAPI::getActiveClassSeries();
            $ids    = array_map(static fn ($r) => (int) $r['id'], $active);
            assert_true(in_array($seriesId, $ids, true), 'series is listed active');

            // ── Occurrences (weekly, idempotent) ──
            $made = StudioAPI::generateOccurrences($seriesId);
            assert_true($made > 0, 'generated at least one weekly occurrence');
            $again = StudioAPI::generateOccurrences($seriesId);
            assert_eq(0, $again, 'generateOccurrences is idempotent');
            $rowCount = (int) Database::value(
                'SELECT COUNT(*) FROM studio_class_occurrences WHERE tenant_id = ? AND series_id = ?',
                [$tid, $seriesId]
            );
            assert_eq($made, $rowCount, 'occurrence rows match the generated count');

            // ── Enrollment (studio-local) ──
            $e1 = StudioAPI::enrollStudent($studentA, $seriesId);
            assert_true($e1['ok'] && $e1['id'] > 0, 'first enrollment ok');
            assert_eq('active', $e1['status'], 'first enrollee is active (capacity 1)');

            // Re-enroll same student → idempotent (returns the existing row).
            $e1b = StudioAPI::enrollStudent($studentA, $seriesId);
            assert_true(!empty($e1b['existing']), 're-enroll returns existing');
            assert_eq($e1['id'], $e1b['id']);

            // Second student exceeds capacity → waitlist.
            $e2 = StudioAPI::enrollStudent($studentB, $seriesId);
            assert_true($e2['ok'], 'second enrollment ok');
            assert_eq('waitlist', $e2['status'], 'over-capacity enrollee is waitlisted');

            // Tuition: studentB takes 1 class, has 1 sibling (studentA) → 10% off.
            $tuition = StudioAPI::calculateTuition($studentB, $seriesId);
            assert_eq(10800, $tuition->minor, '12000 * 0.90 sibling discount');
        });
    } finally {
        _studio_cleanup($tid, [$parent, $studentA, $studentB, $instructor]);
    }
});
