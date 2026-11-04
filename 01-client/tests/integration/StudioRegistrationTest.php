<?php
/**
 * Integration tests for the registration fee and the itemised tuition quote.
 *
 * TuitionPlan's dates and splits are unit-tested with no DB. What can only be
 * proved here is the rule that costs a studio money if it is wrong:
 * registration is charged ONCE PER FAMILY — not per dancer, not per term, and
 * never on a waitlisted place, which is not a sign-up.
 *
 * Runs under a throwaway tenant and cleans up in a finally block.
 */

declare(strict_types=1);

use Slate\Tenancy\TenantContext;

require_once __DIR__ . '/../../plugins/studio/StudioAPI.php';

function _studio_reg_cleanup(int $tid, array $contactIds): void
{
    foreach (['studio_fees', 'studio_enrollments', 'studio_class_occurrences',
              'studio_class_series', 'studio_family_members', 'studio_families',
              'studio_contact_roles'] as $t) {
        Database::query("DELETE FROM {$t} WHERE tenant_id = ?", [$tid]);
    }
    foreach (['studio.fee_registration_cents', 'studio.tuition_cadence', 'studio.tuition_instalments'] as $k) {
        Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [$tid, $k]);
    }
    foreach ($contactIds as $id) {
        Database::query('DELETE FROM contacts WHERE id = ?', [(int) $id]);
    }
}

unit('studio registration: charged once per family, never on a waitlist', function () {
    $tenants = new TenantContext();
    $tid     = slate_test_tenant(current_tenant_id() + 990008);

    $teacher = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__reg_teacher']);
    $parent  = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__reg_parent']);
    $kidA    = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__reg_kid_a']);
    $kidB    = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__reg_kid_b']);

    try {
        $tenants->runAs($tid, function () use ($tid, $teacher, $parent, $kidA, $kidB) {
            $famId = StudioAPI::createFamily($parent, [$kidA, $kidB]);

            $mk = static fn (string $n, int $cap = 20): int => StudioAPI::createClassSeries([
                'name' => $n, 'style' => 'jazz', 'instructor_id' => $teacher,
                'capacity' => $cap, 'day_of_week' => 1, 'start_time' => '16:00', 'end_time' => '17:00',
                'session_start' => '2026-09-07', 'session_end' => '2026-12-14',
                'price_cents' => 8500, 'currency' => 'USD',
            ]);
            $jazz = $mk('__Reg Jazz');
            $tap  = $mk('__Reg Tap');
            $tiny = $mk('__Reg Tiny', 1);

            // ── Free registration (the shipped default) raises nothing ──
            StudioAPI::enrollStudent($kidA, $jazz);
            assert_eq(0, (int) Database::value(
                "SELECT COUNT(*) FROM studio_fees WHERE tenant_id = ? AND kind = 'registration'", [$tid]),
                'free registration must not put a $0.00 line on a statement');
            assert_false(StudioAPI::registrationCharged($famId));

            // ── Now the studio starts charging ──
            // Via the real setter, which must invalidate the memo the read
            // above populated — otherwise "save the fees, then bill against
            // them" charges the old amounts for the rest of the request.
            StudioAPI::saveFeeSchedule(['registration_cents' => 4000]);
            assert_eq(4000, StudioAPI::feeSchedule()->registrationCents(),
                'a saved fee is live immediately, not on the next request');
        });

        // A second runAs gives a clean memo, as a new request would.
        $tenants->runAs($tid, function () use ($tid, $parent, $kidA, $kidB, $teacher) {
            $famId = (int) StudioAPI::getFamilyByParent($parent)['id'];
            $ids   = array_column(Database::rows(
                "SELECT id FROM studio_class_series WHERE tenant_id = ? ORDER BY id", [$tid]), 'id');
            [$jazz, $tap, $tiny] = array_map('intval', $ids);

            assert_eq(4000, StudioAPI::feeSchedule()->registrationCents(), 'the new setting is live');

            // ── First real enrolment raises it once ──
            StudioAPI::enrollStudent($kidA, $tap);
            assert_true(StudioAPI::registrationCharged($famId));
            assert_eq(1, (int) Database::value(
                "SELECT COUNT(*) FROM studio_fees WHERE tenant_id = ? AND kind = 'registration'", [$tid]));
            assert_eq(4000, (int) Database::value(
                "SELECT amount_cents FROM studio_fees WHERE tenant_id = ? AND kind = 'registration'", [$tid]));

            // ── A SECOND CHILD does not pay it again ──
            StudioAPI::enrollStudent($kidB, $tap);
            assert_eq(1, (int) Database::value(
                "SELECT COUNT(*) FROM studio_fees WHERE tenant_id = ? AND kind = 'registration'", [$tid]),
                'registration is per family — a sibling joining must not re-charge it');

            // ── Nor does a third enrolment for the same child ──
            StudioAPI::enrollStudent($kidA, $jazz);
            assert_eq(1, (int) Database::value(
                "SELECT COUNT(*) FROM studio_fees WHERE tenant_id = ? AND kind = 'registration'", [$tid]));

            // ── A waitlisted place is not a sign-up ──
            $solo  = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__reg_solo']);
            $solo2 = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__reg_solo_parent']);
            $fam2  = StudioAPI::createFamily($solo2, [$solo]);
            $filler = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__reg_filler']);
            StudioAPI::createFamily($filler, []);
            StudioAPI::enrollStudent($filler, $tiny);          // takes the only seat
            $r = StudioAPI::enrollStudent($solo, $tiny);
            assert_eq('waitlist', $r['status']);
            assert_false(StudioAPI::registrationCharged($fam2),
                'a family waiting for a place has not signed up to anything yet');

            Database::query("DELETE FROM contacts WHERE id IN (?,?,?)", [$solo, $solo2, $filler]);
        });

        // ── The itemised quote ──
        $tenants->runAs($tid, function () use ($tid, $kidB) {
            $ids = array_column(Database::rows(
                "SELECT id FROM studio_class_series WHERE tenant_id = ? ORDER BY id", [$tid]), 'id');
            $jazz = (int) $ids[0];

            $q = StudioAPI::tuitionQuote($kidB, $jazz);
            assert_true(count($q['lines']) >= 1);
            assert_eq('tuition', $q['lines'][0]['kind']);
            assert_eq(8500, $q['lines'][0]['amount_cents'], 'the LIST price is shown, then discounts');

            // Registration already charged for this family, so it must not
            // reappear on a later quote.
            $kinds = array_column($q['lines'], 'kind');
            assert_true(!in_array('registration', $kinds, true),
                'a family already charged registration must not be quoted it again');

            // Discount lines are negative so the arithmetic reads correctly.
            foreach ($q['lines'] as $l) {
                if ($l['kind'] === 'discount') {
                    assert_true($l['amount_cents'] < 0, 'a discount must subtract');
                }
            }

            // Lines reconcile to the total.
            $sum = 0;
            foreach ($q['lines'] as $l) { $sum += $l['amount_cents']; }
            assert_eq($q['total_cents'], $sum, 'the itemisation must add up to what is charged');
        });

        // ── A plan splits the tuition across dated instalments ──
        $tenants->runAs($tid, function () use ($tid, $kidB) {
            StudioAPI::saveTuitionPlan('advance', 3);
            assert_eq('advance', (string) Database::setting('studio.tuition_cadence'));
            assert_eq('3',       (string) Database::setting('studio.tuition_instalments'));
        });

        $tenants->runAs($tid, function () use ($tid, $kidB) {
            $ids  = array_column(Database::rows(
                "SELECT id FROM studio_class_series WHERE tenant_id = ? ORDER BY id", [$tid]), 'id');
            $q = StudioAPI::tuitionQuote($kidB, (int) $ids[0]);
            assert_eq(3, count($q['schedule']), 'three instalments');
            assert_eq('2026-08-07', $q['schedule'][0]['due_date'], 'advance bills a month early');
            $sum = 0;
            foreach ($q['schedule'] as $p) { $sum += $p['amount_cents']; }
            assert_eq($q['tuition_cents'], $sum, 'the schedule must reconcile to the tuition');
        });
    } finally {
        _studio_reg_cleanup($tid, [$teacher, $parent, $kidA, $kidB]);
    }
});
