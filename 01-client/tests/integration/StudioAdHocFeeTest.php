<?php
/**
 * Integration tests for StudioAPI::raiseAdHocFee() — the hand-raised fee
 * behind the Fees page's "Add a fee" form.
 *
 * The studio's published policy allows for charges the generators cannot know
 * about in advance ("competition classes may have an additional costume
 * and/or crystal fee"), so this path exists to put one on a statement. What
 * matters here is not the arithmetic but the guards: that the family is
 * derived from the dancer rather than trusted from a form, that a dancer with
 * no family cannot raise a bill addressed to nobody, and that this path
 * deliberately does NOT dedupe the way the generators do.
 *
 * Runs under a throwaway tenant and cleans up in a finally block.
 */

declare(strict_types=1);

use Slate\Tenancy\TenantContext;

require_once __DIR__ . '/../../plugins/studio/StudioAPI.php';

function _studio_adhoc_cleanup(int $tid, array $contactIds): void
{
    foreach ([
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

$_adhoc_ready = (int) Database::value(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'studio_fees'"
) > 0;

if (!$_adhoc_ready) {
    unit('studio ad-hoc fee: skipped — 0007_studio_fees not applied (run `php bin/migrate migrate`)', function () {
    });
    return;
}

unit('studio ad-hoc fee: derives the family, guards the input, and does not dedupe', function () {
    $tenants = new TenantContext();
    $tid     = slate_test_tenant(current_tenant_id() + 990004);

    $parent  = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__adhoc_parent']);
    $child   = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__adhoc_child']);
    $orphan  = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__adhoc_orphan']);

    try {
        $tenants->runAs($tid, function () use ($tid, $parent, $child, $orphan) {
            $familyId = StudioAPI::createFamily($parent, [$child]);

            // ── The family is derived, not supplied ──
            $id = StudioAPI::raiseAdHocFee($child, 'Competition crystals', 4250, [
                'kind'     => 'costume',
                'due_date' => '2027-03-01',
            ]);
            assert_true($id > 0, 'raiseAdHocFee returns an id');

            $row = Database::row("SELECT * FROM studio_fees WHERE tenant_id = ? AND id = ?", [$tid, $id]);
            assert_eq($familyId, (int) $row['family_id'], 'billed to the dancer\'s own family');
            assert_eq($child,    (int) $row['student_id'], 'attributed to the dancer');
            assert_eq(4250,      (int) $row['amount_cents'], 'amount is stored in cents as given');
            assert_eq('costume', (string) $row['kind'], 'kind is honoured');
            assert_eq('pending', (string) $row['status'], 'a new fee starts pending');
            assert_eq('2027-03-01', substr((string) $row['due_date'], 0, 10), 'due date is stored');
            assert_true($row['dedupe_key'] === null, 'hand-raised fees carry no dedupe key');

            // ── No dedupe: the same fee twice is two bills ──
            // The generators dedupe so regeneration is idempotent. Here the
            // opposite is correct: two costumes at the same price on the same
            // day is a real thing a studio needs to charge for.
            $again = StudioAPI::raiseAdHocFee($child, 'Competition crystals', 4250, [
                'kind'     => 'costume',
                'due_date' => '2027-03-01',
            ]);
            assert_true($again !== $id, 'an identical second fee is a separate row');
            assert_eq(2, (int) Database::value(
                "SELECT COUNT(*) FROM studio_fees WHERE tenant_id = ? AND student_id = ? AND kind = 'costume'",
                [$tid, $child]
            ), 'both bills stand');

            // ── An unrecognised kind falls back rather than throwing ──
            $other = StudioAPI::raiseAdHocFee($child, 'Studio jacket', 6000, ['kind' => 'not_a_kind']);
            assert_eq('other', (string) Database::value(
                "SELECT kind FROM studio_fees WHERE tenant_id = ? AND id = ?", [$tid, $other]
            ), 'an unknown kind becomes "other" rather than a database error');

            // ── An omitted due date stays null, so reminders leave it alone ──
            $noDue = StudioAPI::raiseAdHocFee($child, 'Shoe order', 3500);
            assert_true(
                Database::value("SELECT due_date FROM studio_fees WHERE tenant_id = ? AND id = ?", [$tid, $noDue]) === null,
                'no due date given means no due date stored'
            );

            // ── Guards ──
            assert_throws(\RuntimeException::class,
                fn () => StudioAPI::raiseAdHocFee($orphan, 'Crystals', 1000),
                'a dancer with no family cannot be billed');

            assert_throws(\RuntimeException::class,
                fn () => StudioAPI::raiseAdHocFee($child, '', 1000),
                'a fee must say what it is for');

            assert_throws(\RuntimeException::class,
                fn () => StudioAPI::raiseAdHocFee($child, 'Crystals', 0),
                'a zero fee is not a bill');

            assert_throws(\RuntimeException::class,
                fn () => StudioAPI::raiseAdHocFee($child, 'Crystals', -500),
                'a negative fee is not a refund route');

            assert_throws(\RuntimeException::class,
                fn () => StudioAPI::raiseAdHocFee(0, 'Crystals', 1000),
                'a fee needs a dancer');
        });
    } finally {
        _studio_adhoc_cleanup($tid, [$parent, $child, $orphan]);
    }
});
