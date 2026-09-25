<?php
/**
 * Phase 1B H1 — the diary-entry AJAX IDOR that survives CoachingDiaryOwnershipTest.
 *
 * That earlier test (Phase 0A) proved saveDiaryEntry()'s own UPDATE — the
 * entry's notes/meal_type/etc. — is scoped by `id = ? AND customer_id = ? AND
 * tenant_id = ?` and can't be hijacked. It never touched the foods sub-table
 * or getDiaryEntry(), so it kept passing while two more paths stayed open:
 *
 *   1. The "foods — replace-all" block ran on $id unconditionally, regardless
 *      of whether the UPDATE above actually matched a row customer A owns.
 *      Customer A submitting customer B's entry id wiped B's food list and
 *      replaced it with A's, and re-derived B's denormalized `summary` from
 *      it — corrupting B's data even though B's `notes`/`customer_id` stayed
 *      intact.
 *   2. getDiaryEntry($entryId) took no customer id at all. The AJAX branch of
 *      customer/router.php's save_entry action called it with whatever id
 *      the request produced and echoed the result — including another
 *      customer's notes, foods and photos — straight into the JSON response.
 *
 * The fix: saveDiaryEntry() now verifies ownership via a SELECT before doing
 * anything else when $id > 0 (not via Database::update()'s rowCount(), which
 * counts rows CHANGED rather than MATCHED — a resave of unchanged values
 * would otherwise be misread as "not owned"), and getDiaryEntry() now takes
 * a required $customerId and scopes its query by it.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/coaching/CoachingAPI.php';

CoachingAPI::ensureSchema();

$tenant = current_tenant_id();
$suffix = bin2hex(random_bytes(4));

$cleanup = static function () use ($tenant, $suffix): void {
    $ids = Database::rows(
        'SELECT id FROM customers WHERE tenant_id = ? AND email IN (?, ?)',
        [$tenant, "__probe-cdf-a-$suffix@example.test", "__probe-cdf-b-$suffix@example.test"]
    );
    foreach ($ids as $row) {
        $cid = (int) $row['id'];
        Database::query('DELETE FROM coaching_diary_food WHERE entry_id IN (SELECT id FROM coaching_diary_entry WHERE customer_id = ?)', [$cid]);
        Database::query('DELETE FROM coaching_diary_entry WHERE customer_id = ?', [$cid]);
        Database::query('DELETE FROM customers WHERE id = ?', [$cid]);
    }
};

$cleanup();

$customerA = (int) Database::insert('customers', [
    'tenant_id' => $tenant, 'email' => "__probe-cdf-a-$suffix@example.test", 'name' => 'Probe CDF A', 'status' => 'active',
]);
$customerB = (int) Database::insert('customers', [
    'tenant_id' => $tenant, 'email' => "__probe-cdf-b-$suffix@example.test", 'name' => 'Probe CDF B', 'status' => 'active',
]);

unit('coaching diary: submitting another customer\'s entry id must not touch its foods, and must be rejected', function () use ($customerA, $customerB): void {
    $entryId = CoachingAPI::saveDiaryEntry($customerB, [
        'notes' => 'B original', 'meal_type' => 'lunch',
        'foods' => [['name' => 'Banana', 'category' => 'fruits_vegetables']],
    ]);
    assert_true($entryId > 0, 'setup: B\'s entry must be created');

    try {
        $result = CoachingAPI::saveDiaryEntry($customerA, [
            'id' => $entryId, 'notes' => 'HACKED', 'meal_type' => 'other',
            'foods' => [['name' => 'ATTACKER FOOD', 'category' => 'other']],
        ]);
        assert_eq(0, $result, 'a foreign entry id must be rejected outright, not silently accepted');

        $foods = Database::rows('SELECT name FROM coaching_diary_food WHERE entry_id = ?', [$entryId]);
        assert_eq(1, count($foods), 'B\'s food list must be untouched');
        assert_eq('Banana', $foods[0]['name'] ?? null, 'the attacker\'s food must never replace B\'s');

        $row = Database::row('SELECT customer_id, notes, summary FROM coaching_diary_entry WHERE id = ?', [$entryId]);
        assert_eq($customerB, (int) $row['customer_id'], 'ownership must not change');
        assert_eq('B original', $row['notes'], 'A\'s update must not reach B\'s row');
        assert_true(($row['summary'] ?? '') !== 'ATTACKER FOOD', 'B\'s denormalized summary must not be re-derived from the attacker\'s foods');
    } finally {
        Database::query('DELETE FROM coaching_diary_food WHERE entry_id = ?', [$entryId]);
        Database::query('DELETE FROM coaching_diary_entry WHERE id = ?', [$entryId]);
    }
});

unit('coaching diary: getDiaryEntry() refuses to return another customer\'s entry', function () use ($customerA, $customerB): void {
    $entryId = CoachingAPI::saveDiaryEntry($customerB, [
        'notes' => 'B private notes', 'meal_type' => 'dinner',
        'foods' => [['name' => 'Salmon', 'category' => 'proteins']],
    ]);
    assert_true($entryId > 0, 'setup: B\'s entry must be created');

    try {
        $asAttacker = CoachingAPI::getDiaryEntry($entryId, $customerA);
        assert_null($asAttacker, 'customer A must never receive customer B\'s entry data');

        $asOwner = CoachingAPI::getDiaryEntry($entryId, $customerB);
        assert_true($asOwner !== null, 'the actual owner must still be able to fetch their own entry');
        assert_eq('B private notes', $asOwner['notes'] ?? null);
    } finally {
        Database::query('DELETE FROM coaching_diary_food WHERE entry_id = ?', [$entryId]);
        Database::query('DELETE FROM coaching_diary_entry WHERE id = ?', [$entryId]);
    }
});

unit('coaching diary: a legitimate resave of unchanged values is not mistaken for a foreign id', function () use ($customerB): void {
    // Guards the specific reasoning in the fix: Database::update()'s rowCount()
    // reflects rows CHANGED, not matched, so re-submitting identical values
    // must not be misread as "not owned" and rejected.
    $entryId = CoachingAPI::saveDiaryEntry($customerB, [
        'notes' => 'same notes', 'meal_type' => 'lunch',
        'foods' => [['name' => 'Rice', 'category' => 'starches']],
    ]);
    assert_true($entryId > 0);

    try {
        $again = CoachingAPI::saveDiaryEntry($customerB, [
            'id' => $entryId, 'notes' => 'same notes', 'meal_type' => 'lunch',
            'foods' => [['name' => 'Rice', 'category' => 'starches']],
        ]);
        assert_eq($entryId, $again, 'an unchanged resave by the actual owner must still succeed');
    } finally {
        Database::query('DELETE FROM coaching_diary_food WHERE entry_id = ?', [$entryId]);
        Database::query('DELETE FROM coaching_diary_entry WHERE id = ?', [$entryId]);
    }
});

$cleanup();
