<?php
/**
 * Integration tests for the studio fee ledger against the real 0007_studio_fees
 * table: costume + tights generation with exemptions, the per-family recital
 * taper, dedupe on re-run, voiding on a drop, and idempotent settlement.
 *
 * FeeSchedule's arithmetic is already unit-tested with no DB. What can only be
 * proved here is the wiring: that the right classes are read, that the UNIQUE
 * dedupe index actually stops a second bill, and that the money written to the
 * table re-sums to what was quoted.
 *
 * Runs under a throwaway tenant and cleans up in a finally block.
 */

declare(strict_types=1);

use Slate\Tenancy\TenantContext;

require_once __DIR__ . '/../../plugins/studio/StudioAPI.php';

function _studio_fees_cleanup(int $tid, array $contactIds): void
{
    foreach ([
        // Children first. The costume/recital tables are here because the
        // wardrobe-vs-ledger test creates a show; without them the throwaway
        // tenant accumulates rows every run.
        'studio_costumes',
        'studio_recital_pieces',
        'studio_recital_tickets',
        'studio_recitals',
        'studio_fees',
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

$_fees_ready = (int) Database::value(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'studio_fees'"
) > 0;

if (!$_fees_ready) {
    unit('studio fees: skipped — 0007_studio_fees not applied (run `php bin/migrate migrate`)', function () {
    });
    return;
}

unit('studio fees: costumes, exemptions, recital taper, dedupe and voiding', function () {
    $tenants = new TenantContext();
    $tid     = slate_test_tenant(current_tenant_id() + 990001);      // distinct from StudioEnrollmentTest

    $parent   = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__fee_parent']);
    $childA   = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__fee_child_a']);
    $childB   = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__fee_child_b']);
    $teacher  = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__fee_instructor']);

    try {
        $tenants->runAs($tid, function () use ($tid, $parent, $childA, $childB, $teacher) {
            $familyId = StudioAPI::createFamily($parent, [$childA, $childB]);

            $mk = static function (string $name, string $style, ?int $ageMin) use ($teacher): int {
                return StudioAPI::createClassSeries([
                    'name'          => $name,
                    'style'         => $style,
                    'age_min'       => $ageMin,
                    'instructor_id' => $teacher,
                    'capacity'      => 20,
                    'day_of_week'   => 1,
                    'start_time'    => '16:00',
                    'end_time'      => '17:00',
                    'session_start' => '2026-09-07',
                    'session_end'   => '2026-09-28',
                    'price_cents'   => 8500,
                    'currency'      => 'USD',
                ]);
            };

            $jazz      = $mk('__Fee Jazz',       'jazz',      9);
            $tap       = $mk('__Fee Tap',        'tap',       9);
            $balletBig = $mk('__Fee Ballet 8up', 'ballet',    9);   // exempt: ballet 8 & up
            $technique = $mk('__Fee Technique',  'technique', 9);   // exempt: technique
            $balletWee = $mk('__Fee Ballet 4-7', 'ballet',    5);   // NOT exempt: under 8

            foreach ([$jazz, $tap, $balletBig, $technique] as $s) {
                StudioAPI::enrollStudent($childA, $s);
            }
            StudioAPI::enrollStudent($childB, $balletWee);

            // ── Costume generation, with exemptions ──
            StudioAPI::generateCostumeFeesForStudent($childA, 2026);

            $costumeRows = Database::rows(
                "SELECT * FROM studio_fees
                  WHERE tenant_id = ? AND student_id = ? AND kind = 'costume'
                  ORDER BY series_id, instalment_no",
                [$tid, $childA]
            );
            // 2 costumed classes (jazz, tap) x 2 instalments = 4 rows.
            // Ballet 8-up and technique must contribute nothing.
            assert_eq(4, count($costumeRows), 'only non-exempt classes raise costume fees');

            $seriesBilled = array_unique(array_map(static fn ($r) => (int) $r['series_id'], $costumeRows));
            sort($seriesBilled);
            $expect = [$jazz, $tap];
            sort($expect);
            assert_eq($expect, $seriesBilled, 'ballet 8+ and technique are exempt');

            // The money must re-sum to 2 x $95, not drift across instalments.
            $costumeTotal = 0;
            foreach ($costumeRows as $r) { $costumeTotal += (int) $r['amount_cents']; }
            assert_eq(2 * 9500, $costumeTotal, 'costume instalments re-sum to what was quoted');

            // Due dates: November this season, February the next year.
            $due = array_unique(array_map(static fn ($r) => (string) $r['due_date'], $costumeRows));
            sort($due);
            assert_eq(['2026-11-01', '2027-02-01'], $due, 'February rolls into the following year');

            // ── Tights: once per student, not once per class ──
            $tightsRows = Database::rows(
                "SELECT * FROM studio_fees WHERE tenant_id = ? AND student_id = ? AND kind = 'tights'",
                [$tid, $childA]
            );
            $tightsTotal = 0;
            foreach ($tightsRows as $r) { $tightsTotal += (int) $r['amount_cents']; }
            assert_eq(1000, $tightsTotal, 'one $10 tights charge however many costumes');

            // ── A fully exempt student is billed nothing at all ──
            $balletOnly = $mk('__Fee Ballet Only', 'ballet', 12);
            StudioAPI::enrollStudent($childB, $balletOnly);
            // childB is in ballet 4-7 (billable) + ballet 12 (exempt) — so still
            // billable; assert the exempt one contributes no row of its own.
            StudioAPI::generateCostumeFeesForStudent($childB, 2026);
            $bExempt = (int) Database::value(
                "SELECT COUNT(*) FROM studio_fees
                  WHERE tenant_id = ? AND student_id = ? AND series_id = ?",
                [$tid, $childB, $balletOnly]
            );
            assert_eq(0, $bExempt, 'an exempt class never raises a row');

            // ── A retired class must not raise a costume fee ──
            // Deactivating a series keeps its enrolments (attendance history
            // matters), so the enrolment stays 'active' against an inactive
            // class. Billing a costume for a class that is not running is the
            // exact bug the live preview caught.
            $retired = $mk('__Fee Retired Jazz', 'jazz', 9);
            StudioAPI::enrollStudent($childA, $retired);
            StudioAPI::setClassSeriesActive($retired, false);
            StudioAPI::generateCostumeFeesForStudent($childA, 2026);
            assert_eq(0, (int) Database::value(
                "SELECT COUNT(*) FROM studio_fees WHERE tenant_id = ? AND series_id = ?",
                [$tid, $retired]
            ), 'a deactivated class must never raise a costume fee');

            // ── Re-running must not double-bill ──
            $before = (int) Database::value(
                "SELECT COUNT(*) FROM studio_fees WHERE tenant_id = ?", [$tid]
            );
            StudioAPI::generateCostumeFeesForStudent($childA, 2026);
            StudioAPI::generateCostumeFeesForStudent($childB, 2026);
            $after = (int) Database::value(
                "SELECT COUNT(*) FROM studio_fees WHERE tenant_id = ?", [$tid]
            );
            assert_eq($before, $after, 'the dedupe key makes generation idempotent');

            // ── Costume money lives in ONE place ──
            // syncCostumesForPiece creates a wardrobe record per dancer. It
            // must not price anything: the fee ledger already raised the $95
            // against the enrolment, and pricing here as well billed the same
            // costume twice and showed it twice in the parent portal.
            $recitalId = Database::insert('studio_recitals', [
                'tenant_id' => $tid, 'name' => '__Fee Show', 'status' => 'published',
                'recital_date' => '2027-06-12',
            ]);
            $pieceId = Database::insert('studio_recital_pieces', [
                'tenant_id' => $tid, 'recital_id' => $recitalId, 'series_id' => $jazz, 'position' => 1,
            ]);
            $made = StudioAPI::syncCostumesForPiece($pieceId, 9500);   // cost passed and ignored
            assert_true($made > 0, 'a wardrobe record is created per dancer in the piece');

            assert_eq(0, (int) Database::value(
                "SELECT COALESCE(SUM(cost_cents),0) FROM studio_costumes WHERE tenant_id = ?", [$tid]),
                'studio_costumes must never carry money, even when a cost is passed in');

            // The ledger is unchanged by wardrobe generation — no second bill.
            $afterSync = (int) Database::value(
                "SELECT COUNT(*) FROM studio_fees WHERE tenant_id = ? AND kind = 'costume'", [$tid]);
            StudioAPI::syncCostumesForPiece($pieceId, 9500);
            assert_eq($afterSync, (int) Database::value(
                "SELECT COUNT(*) FROM studio_fees WHERE tenant_id = ? AND kind = 'costume'", [$tid]),
                'generating costumes must not raise fees');

            // recitalSummary reports the fee-ledger figure, not the wardrobe one.
            $sum = StudioAPI::recitalSummary($recitalId);
            $ledgerDue = (int) Database::value(
                "SELECT COALESCE(SUM(amount_cents),0) FROM studio_fees
                  WHERE tenant_id = ? AND status = 'pending' AND kind IN ('costume','tights')",
                [$tid]);
            assert_eq($ledgerDue, (int) $sum['costume_due_cents'],
                'the admin recital KPI reads the ledger, so it cannot disagree with the Fees page');

            // ── Recital fee: per family, tapered, not per student ──
            StudioAPI::generateRecitalFeeForFamily($familyId, 4242, 2027);
            $recital = Database::rows(
                "SELECT * FROM studio_fees WHERE tenant_id = ? AND kind = 'recital' ORDER BY id",
                [$tid]
            );
            assert_eq(2, count($recital), 'two enrolled children → two lines, not two full fees');
            $recTotal = 0;
            foreach ($recital as $r) { $recTotal += (int) $r['amount_cents']; }
            assert_eq(15000, $recTotal, '$100 first child + $50 second');
            assert_eq('2027-05-01', (string) $recital[0]['due_date'], 'processed May 1');

            StudioAPI::generateRecitalFeeForFamily($familyId, 4242, 2027);
            assert_eq(2, (int) Database::value(
                "SELECT COUNT(*) FROM studio_fees WHERE tenant_id = ? AND kind = 'recital'", [$tid]
            ), 'recital generation is idempotent too');

            // ── Outstanding balance rolls the family up ──
            $outstanding = StudioAPI::feesOutstandingForFamily($familyId);
            $sumAll = (int) Database::value(
                "SELECT SUM(amount_cents) FROM studio_fees
                  WHERE tenant_id = ? AND family_id = ? AND status = 'pending'",
                [$tid, $familyId]
            );
            assert_eq($sumAll, $outstanding, 'outstanding matches the pending rows');

            // ── Settlement is idempotent ──
            $one = (int) $costumeRows[0]['id'];
            assert_true(StudioAPI::markFeePaid($one, 4750, 'offline'), 'first settlement takes');
            assert_false(StudioAPI::markFeePaid($one, 4750, 'offline'),
                'a late webhook after a checkout-return reconcile must not re-fire');
            assert_eq('paid', (string) Database::value(
                "SELECT status FROM studio_fees WHERE tenant_id = ? AND id = ?", [$tid, $one]
            ));

            // Paying reduces what is outstanding.
            assert_eq($outstanding - 4750, StudioAPI::feesOutstandingForFamily($familyId));

            // ── Dropping an enrolment stops the chasing ──
            $voided = StudioAPI::voidFeesForEnrollment($childA, $tap);
            assert_true($voided > 0, 'pending fees for the dropped class are voided');
            assert_eq(0, (int) Database::value(
                "SELECT COUNT(*) FROM studio_fees
                  WHERE tenant_id = ? AND student_id = ? AND series_id = ? AND status = 'pending'",
                [$tid, $childA, $tap]
            ), 'nothing pending remains for that class');

            // A paid fee is never retro-voided — refunding is a separate act.
            assert_eq('paid', (string) Database::value(
                "SELECT status FROM studio_fees WHERE tenant_id = ? AND id = ?", [$tid, $one]
            ), 'voiding must not rewrite a settled row');
        });
    } finally {
        _studio_fees_cleanup($tid, [$parent, $childA, $childB, $teacher]);
    }
});
