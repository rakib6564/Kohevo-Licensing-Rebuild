<?php
/**
 * Coaching diary entry — ownership must be enforced on update, not just tenancy.
 *
 * CoachingAPI::saveDiaryEntry()'s update branch scoped its UPDATE by
 * `id = ? AND tenant_id = ?` only. Any authenticated, enrolled customer can
 * reach this via plugins/coaching/customer/router.php's `save_entry` POST
 * action, which passes the caller's own $cid as $customerId but forwards
 * `$_POST['id']` verbatim as the row to update — so a logged-in customer A
 * could overwrite ANY other customer B's diary entry in the same tenant by
 * submitting B's entry id, because $customerId was never checked against the
 * row's own customer_id.
 *
 * Every sibling mutator in this file (deleteDiaryEntry, deleteDiaryPhoto,
 * deleteActivity, completeChallenge) already scopes by
 * `id = ? AND customer_id = ? AND tenant_id = ?` — this pins saveDiaryEntry()
 * to the same pattern.
 *
 * Deliberately same-tenant throughout: this is a horizontal (customer vs.
 * customer) authorization bug, not a tenant-isolation bug, so the fix and
 * this test both operate entirely within one tenant.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/coaching/CoachingAPI.php';

CoachingAPI::ensureSchema();

$tenant = current_tenant_id();
$suffix = bin2hex(random_bytes(4));

$cleanupCustomers = static function () use ($tenant, $suffix): void {
    $ids = Database::rows(
        'SELECT id FROM customers WHERE tenant_id = ? AND email IN (?, ?)',
        [$tenant, "__probe-coaching-a-$suffix@example.test", "__probe-coaching-b-$suffix@example.test"]
    );
    foreach ($ids as $row) {
        $cid = (int) $row['id'];
        Database::query('DELETE FROM coaching_diary_food WHERE entry_id IN (SELECT id FROM coaching_diary_entry WHERE customer_id = ?)', [$cid]);
        Database::query('DELETE FROM coaching_diary_entry WHERE customer_id = ?', [$cid]);
        Database::query('DELETE FROM customers WHERE id = ?', [$cid]);
    }
};

// Defensive pre-clean in case a prior interrupted run leaked rows under this
// (random, per-run) suffix — practically never fires, but costs nothing.
$cleanupCustomers();

$customerA = (int) Database::insert('customers', [
    'tenant_id' => $tenant,
    'email'     => "__probe-coaching-a-$suffix@example.test",
    'name'      => 'Probe Customer A',
    'status'    => 'active',
]);
$customerB = (int) Database::insert('customers', [
    'tenant_id' => $tenant,
    'email'     => "__probe-coaching-b-$suffix@example.test",
    'name'      => 'Probe Customer B',
    'status'    => 'active',
]);

unit('coaching diary: customer A cannot overwrite customer B\'s entry via its id', function () use ($customerA, $customerB): void {
    $entryId = CoachingAPI::saveDiaryEntry($customerB, [
        'notes'     => 'B original',
        'meal_type' => 'lunch',
    ]);
    assert_true($entryId > 0, 'setup: B\'s entry must be created');

    try {
        // The attack: A submits B's entry id as their own.
        CoachingAPI::saveDiaryEntry($customerA, [
            'id'        => $entryId,
            'notes'     => 'HACKED BY A',
            'meal_type' => 'other',
        ]);

        $row = Database::row('SELECT customer_id, notes FROM coaching_diary_entry WHERE id = ?', [$entryId]);
        assert_true($row !== null, 'B\'s entry must still exist');
        assert_eq($customerB, (int) $row['customer_id'], 'entry ownership must not change');
        assert_eq('B original', $row['notes'], 'A\'s update must not reach B\'s row');
    } finally {
        Database::query('DELETE FROM coaching_diary_food WHERE entry_id = ?', [$entryId]);
        Database::query('DELETE FROM coaching_diary_entry WHERE id = ?', [$entryId]);
    }
});

unit('coaching diary: customer B can update their own entry', function () use ($customerB): void {
    $entryId = CoachingAPI::saveDiaryEntry($customerB, [
        'notes'     => 'B original',
        'meal_type' => 'lunch',
    ]);
    assert_true($entryId > 0, 'setup: B\'s entry must be created');

    try {
        $again = CoachingAPI::saveDiaryEntry($customerB, [
            'id'        => $entryId,
            'notes'     => 'B updated',
            'meal_type' => 'dinner',
        ]);
        assert_eq($entryId, $again, 'updating an existing entry must return the same id');

        $row = Database::row('SELECT customer_id, notes, meal_type FROM coaching_diary_entry WHERE id = ?', [$entryId]);
        assert_true($row !== null);
        assert_eq($customerB, (int) $row['customer_id'], 'ownership stays with B');
        assert_eq('B updated', $row['notes'], 'B\'s own edit must apply');
        assert_eq('dinner', $row['meal_type']);
    } finally {
        Database::query('DELETE FROM coaching_diary_food WHERE entry_id = ?', [$entryId]);
        Database::query('DELETE FROM coaching_diary_entry WHERE id = ?', [$entryId]);
    }
});

unit('coaching diary: customer A can create their own new entry', function () use ($customerA): void {
    $entryId = CoachingAPI::saveDiaryEntry($customerA, [
        'notes'     => 'A new entry',
        'meal_type' => 'breakfast',
    ]);
    assert_true($entryId > 0, 'a fresh entry (no id) must insert');

    try {
        $row = Database::row('SELECT customer_id, notes FROM coaching_diary_entry WHERE id = ?', [$entryId]);
        assert_true($row !== null);
        assert_eq($customerA, (int) $row['customer_id']);
        assert_eq('A new entry', $row['notes']);
    } finally {
        Database::query('DELETE FROM coaching_diary_food WHERE entry_id = ?', [$entryId]);
        Database::query('DELETE FROM coaching_diary_entry WHERE id = ?', [$entryId]);
    }
});

$cleanupCustomers();
