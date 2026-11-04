<?php
/**
 * Integration tests for class descriptions, location and the online-class link.
 *
 * The one that matters is access control: a meeting URL for a children's dance
 * class must never be obtainable from the public catalog. classJoinUrl() is
 * the gate, so it is tested from every angle a real viewer can arrive from —
 * anonymous, a parent with no family, a parent whose children are in OTHER
 * classes, and a parent who is actually entitled to it.
 *
 * Runs under a throwaway tenant and cleans up in a finally block.
 */

declare(strict_types=1);

use Slate\Tenancy\TenantContext;

require_once __DIR__ . '/../../plugins/studio/StudioAPI.php';

function _studio_meta_cleanup(int $tid, array $contactIds): void
{
    foreach (['studio_enrollments', 'studio_class_occurrences', 'studio_class_series',
              'studio_family_members', 'studio_families', 'studio_contact_roles'] as $t) {
        Database::query("DELETE FROM {$t} WHERE tenant_id = ?", [$tid]);
    }
    foreach ($contactIds as $id) {
        Database::query('DELETE FROM contacts WHERE id = ?', [(int) $id]);
    }
}

unit('studio class meta: descriptive fields persist, and the join link is gated', function () {
    $tenants = new TenantContext();
    $tid     = slate_test_tenant(current_tenant_id() + 990007);

    $teacher   = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__meta_teacher']);
    $parentIn  = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__meta_parent_in']);
    $childIn   = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__meta_child_in']);
    $parentOut = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__meta_parent_out']);
    $childOut  = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__meta_child_out']);
    $stranger  = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__meta_stranger']);

    try {
        $tenants->runAs($tid, function () use ($tid, $teacher, $parentIn, $childIn, $parentOut, $childOut, $stranger) {
            StudioAPI::createFamily($parentIn,  [$childIn]);
            StudioAPI::createFamily($parentOut, [$childOut]);

            $mk = static fn (string $name, array $extra = []): int => StudioAPI::createClassSeries($extra + [
                'name' => $name, 'style' => 'ballet', 'instructor_id' => $teacher,
                'capacity' => 20, 'day_of_week' => 1, 'start_time' => '16:00', 'end_time' => '17:00',
                'session_start' => '2026-09-07', 'session_end' => '2026-09-28',
                'price_cents' => 8500, 'currency' => 'USD',
            ]);

            $online = $mk('__Meta Online', [
                'description' => "Ballet fundamentals.\nBring soft shoes.",
                'location'    => 'Main studio, 21 Jackson Ave',
                'virtual_url' => 'https://meet.example.test/ballet-4',
            ]);
            $other = $mk('__Meta Other');

            StudioAPI::enrollStudent($childIn,  $online);
            StudioAPI::enrollStudent($childOut, $other);

            // ── The descriptive fields round-trip ──
            $row = StudioAPI::getClassSeries($online);
            assert_eq("Ballet fundamentals.\nBring soft shoes.", StudioAPI::classMeta($row, 'description'),
                'newlines survive, so a description keeps its paragraphs');
            assert_eq('Main studio, 21 Jackson Ave', StudioAPI::classMeta($row, 'location'));
            assert_eq('', StudioAPI::classMeta($row, 'image'), 'an unset key reads empty, not null');

            // ── THE gate ──
            assert_eq('', StudioAPI::classJoinUrl($row, null),
                'an anonymous reader of the public catalog must never get the link');
            assert_eq('', StudioAPI::classJoinUrl($row, 0));
            assert_eq('', StudioAPI::classJoinUrl($row, $stranger),
                'a signed-in customer with no studio family gets nothing');
            assert_eq('', StudioAPI::classJoinUrl($row, $parentOut),
                'a parent whose child is in a DIFFERENT class gets nothing');
            assert_eq('https://meet.example.test/ballet-4', StudioAPI::classJoinUrl($row, $parentIn),
                'only a parent with a dancer in THIS class');

            // A class with no link never yields one, however entitled the viewer.
            assert_eq('', StudioAPI::classJoinUrl(StudioAPI::getClassSeries($other), $parentOut));

            // ── Dropping the enrolment closes the door again ──
            $eid = (int) Database::value(
                "SELECT id FROM studio_enrollments WHERE tenant_id = ? AND student_id = ? AND series_id = ?",
                [$tid, $childIn, $online]);
            StudioAPI::dropEnrollment($eid, 'left');
            assert_eq('', StudioAPI::classJoinUrl($row, $parentIn),
                'a dropped family loses access to the link');

            // ── Editing one meta key must not wipe the others ──
            StudioAPI::updateClassSeries($online, ['description' => 'Updated text only']);
            $row = StudioAPI::getClassSeries($online);
            assert_eq('Updated text only', StudioAPI::classMeta($row, 'description'));
            assert_eq('Main studio, 21 Jackson Ave', StudioAPI::classMeta($row, 'location'),
                'a partial edit must not clear the fields it did not touch');
            assert_eq('https://meet.example.test/ballet-4', StudioAPI::classMeta($row, 'virtual_url'));

            // Posting a key empty IS a deliberate clear.
            StudioAPI::updateClassSeries($online, ['location' => '']);
            assert_eq('', StudioAPI::classMeta(StudioAPI::getClassSeries($online), 'location'));
            assert_eq('Updated text only',
                StudioAPI::classMeta(StudioAPI::getClassSeries($online), 'description'),
                'clearing one key leaves the rest alone');

            // ── Duplication carries every descriptive field, not just the image ──
            $dup = StudioAPI::duplicateClass($online);
            $dupRow = StudioAPI::getClassSeries($dup);
            assert_eq('Updated text only', StudioAPI::classMeta($dupRow, 'description'),
                'a duplicate that lost its description would need retyping');
            assert_eq('https://meet.example.test/ballet-4', StudioAPI::classMeta($dupRow, 'virtual_url'));
        });
    } finally {
        _studio_meta_cleanup($tid, [$teacher, $parentIn, $childIn, $parentOut, $childOut, $stranger]);
    }
});
