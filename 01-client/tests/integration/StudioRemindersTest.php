<?php
/**
 * Integration tests for fee payment reminders against the real tables.
 *
 * ReminderPolicy's step arithmetic is unit-tested with no DB. What can only be
 * proved here is the part that stops a studio spamming its parents: that a
 * sent step is persisted on the fee, that the next run therefore sends
 * nothing, and that fees are grouped into ONE entry per family rather than one
 * per row.
 *
 * Runs under a throwaway tenant and cleans up in a finally block.
 */

declare(strict_types=1);

use Slate\Tenancy\TenantContext;

require_once __DIR__ . '/../../plugins/studio/StudioAPI.php';

function _studio_rem_cleanup(int $tid, array $contactIds): void
{
    foreach (['studio_fees', 'studio_family_members', 'studio_families', 'studio_contact_roles'] as $t) {
        Database::query("DELETE FROM {$t} WHERE tenant_id = ?", [$tid]);
    }
    foreach ($contactIds as $id) {
        Database::query('DELETE FROM contacts WHERE id = ?', [(int) $id]);
    }
}

$_rem_ready = (int) Database::value(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'studio_fees'"
) > 0;

if (!$_rem_ready) {
    unit('studio reminders: skipped — 0007_studio_fees not applied', function () {});
    return;
}

unit('studio reminders: grouped per family, stamped once, never repeated', function () {
    $tenants = new TenantContext();
    $tid     = slate_test_tenant(current_tenant_id() + 990003);

    $parent = Database::insert('contacts', [
        'tenant_id' => $tid, 'display_name' => '__rem_parent',
        'primary_email' => 'rem.parent@example.test',
    ]);
    $child  = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__rem_child']);
    // A second family whose parent has NO email — nowhere to send.
    $noMail = Database::insert('contacts', ['tenant_id' => $tid, 'display_name' => '__rem_nomail']);

    try {
        $tenants->runAs($tid, function () use ($tid, $parent, $child, $noMail) {
            $famA = StudioAPI::createFamily($parent, [$child]);
            $famB = StudioAPI::createFamily($noMail, []);

            $raise = static function (int $fam, string $label, int $cents, string $due) {
                return StudioAPI::raiseFee([
                    'family_id' => $fam, 'kind' => 'costume', 'label' => $label,
                    'amount_cents' => $cents, 'due_date' => $due,
                    'dedupe_key' => 'rem:' . $fam . ':' . $label,
                ]);
            };

            // Three fees for one family, all due the same day.
            $f1 = $raise($famA, 'Costume A', 9500, '2026-11-01');
            $f2 = $raise($famA, 'Costume B', 9500, '2026-11-01');
            $f3 = $raise($famA, 'Tights',    1000, '2026-11-01');
            $raise($famB, 'Costume C', 9500, '2026-11-01');   // parent has no email

            // ── Too early: nothing due ──
            assert_eq([], StudioAPI::feeRemindersDue('2026-10-01'), '31 days out is too early');

            // ── One week out: ONE entry for the family, not three ──
            $due = StudioAPI::feeRemindersDue('2026-10-25');
            assert_eq(1, count($due),
                'a family with three fees gets one reminder, and the no-email family is skipped');
            assert_eq($famA, (int) $due[0]['family_id']);
            assert_eq(-7, (int) $due[0]['step']);
            assert_eq(3, count($due[0]['fees']), 'all three fees are itemised inside the one entry');
            assert_eq('rem.parent@example.test', (string) $due[0]['parent']['email']);

            $total = 0;
            foreach ($due[0]['fees'] as $f) { $total += (int) $f['amount_cents']; }
            assert_eq(20000, $total, '2 x $95 + $10');

            // ── Stamp them, as the runner does after a successful send ──
            foreach ($due[0]['fees'] as $f) {
                StudioAPI::recordFeeReminder((int) $f['id'], (int) $f['step']);
            }
            assert_eq([-7], StudioAPI::remindersSentFor((string) Database::value(
                "SELECT meta FROM studio_fees WHERE tenant_id = ? AND id = ?", [$tid, $f1])));

            // ── Same day again: nothing. This is the anti-spam guarantee ──
            assert_eq([], StudioAPI::feeRemindersDue('2026-10-25'),
                'a second run the same day must send nothing');
            assert_eq([], StudioAPI::feeRemindersDue('2026-10-29'),
                'still nothing until the next step comes round');

            // ── Due date: the 0 step fires ──
            $due = StudioAPI::feeRemindersDue('2026-11-01');
            assert_eq(1, count($due));
            assert_eq(0, (int) $due[0]['step']);
            foreach ($due[0]['fees'] as $f) {
                StudioAPI::recordFeeReminder((int) $f['id'], (int) $f['step']);
            }
            assert_eq([-7, 0], StudioAPI::remindersSentFor((string) Database::value(
                "SELECT meta FROM studio_fees WHERE tenant_id = ? AND id = ?", [$tid, $f1])),
                'steps accumulate in order');

            // ── Paying one fee drops it out of the next reminder ──
            StudioAPI::markFeePaid($f2, 9500, 'offline');
            $due = StudioAPI::feeRemindersDue('2026-11-08');
            assert_eq(1, count($due));
            assert_eq(7, (int) $due[0]['step']);
            assert_eq(2, count($due[0]['fees']), 'the settled fee is no longer chased');
            $ids = array_map(static fn ($f) => (int) $f['id'], $due[0]['fees']);
            assert_true(!in_array($f2, $ids, true), 'a paid fee must never appear in a reminder');

            // ── Voiding also drops out ──
            StudioAPI::voidFee($f3);
            $due = StudioAPI::feeRemindersDue('2026-11-08');
            assert_eq(1, count($due[0]['fees']), 'only the one unpaid, unvoided fee remains');
            assert_eq($f1, (int) $due[0]['fees'][0]['id']);

            // ── Catching up late sends the most urgent step only ──
            $late = $raise($famA, 'Late costume', 9500, '2026-09-01');
            $due  = StudioAPI::feeRemindersDue('2026-10-11');   // 40 days overdue
            $lateEntry = null;
            foreach ($due[0]['fees'] as $f) {
                if ((int) $f['id'] === $late) { $lateEntry = $f; }
            }
            assert_true($lateEntry !== null, 'the late fee is picked up');
            assert_eq(30, (int) $lateEntry['step'],
                'a fee 40 days overdue sends "a month overdue", not the whole sequence');

            // ── Stamping is idempotent ──
            StudioAPI::recordFeeReminder($late, 30);
            StudioAPI::recordFeeReminder($late, 30);
            assert_eq([30], StudioAPI::remindersSentFor((string) Database::value(
                "SELECT meta FROM studio_fees WHERE tenant_id = ? AND id = ?", [$tid, $late])),
                'recording the same step twice must not duplicate it');
        });
    } finally {
        _studio_rem_cleanup($tid, [$parent, $child, $noMail]);
    }
});
