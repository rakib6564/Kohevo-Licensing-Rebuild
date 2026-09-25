<?php
/**
 * Phase 1B H2 — coaching goal check-in IDOR.
 *
 * CoachingAPI::recordCheckIn() looked up an existing check-in by
 * `goal_id = ? AND day = ?` alone — no customer_id or tenant_id anywhere in
 * the query. Reachable from plugins/coaching/customer/router.php's `checkin`
 * POST action, which forwards `$_POST['goal_id']` verbatim: any enrolled
 * customer who knew (or guessed — ids are small sequential integers) another
 * customer's goal_id could overwrite that goal's check-in status/note for a
 * given day, regardless of who actually owned the goal.
 *
 * The fix verifies goal_id belongs to the calling customer/tenant
 * (`coaching_goal.customer_id`/`tenant_id`) before touching any check-in row
 * for it, and re-scopes the check-in lookup/update by customer_id/tenant_id
 * as well, mirroring the ownership-chain pattern used elsewhere in this file
 * (e.g. deleteDiaryPhoto() joining through its parent entity).
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/coaching/CoachingAPI.php';

CoachingAPI::ensureSchema();

$tenant = current_tenant_id();
$suffix = bin2hex(random_bytes(4));

$cleanup = static function () use ($tenant, $suffix): void {
    $ids = Database::rows(
        'SELECT id FROM customers WHERE tenant_id = ? AND email IN (?, ?)',
        [$tenant, "__probe-checkin-a-$suffix@example.test", "__probe-checkin-b-$suffix@example.test"]
    );
    foreach ($ids as $row) {
        $cid = (int) $row['id'];
        Database::query('DELETE FROM coaching_goal_checkin WHERE goal_id IN (SELECT id FROM coaching_goal WHERE customer_id = ?)', [$cid]);
        Database::query('DELETE FROM coaching_goal WHERE customer_id = ?', [$cid]);
        Database::query('DELETE FROM customers WHERE id = ?', [$cid]);
    }
};

$cleanup();

$customerA = (int) Database::insert('customers', [
    'tenant_id' => $tenant, 'email' => "__probe-checkin-a-$suffix@example.test", 'name' => 'Probe Checkin A', 'status' => 'active',
]);
$customerB = (int) Database::insert('customers', [
    'tenant_id' => $tenant, 'email' => "__probe-checkin-b-$suffix@example.test", 'name' => 'Probe Checkin B', 'status' => 'active',
]);

unit('coaching check-in: customer A cannot overwrite customer B\'s goal check-in', function () use ($customerA, $customerB): void {
    $goalB = CoachingAPI::saveGoal(['customer_id' => $customerB, 'title' => 'B goal', 'scope' => 'daily']);
    assert_true($goalB > 0, 'setup: B\'s goal must be created');
    $day = date('Y-m-d');

    try {
        $checkinId = CoachingAPI::recordCheckIn($customerB, $goalB, $day, 'achieved', 'B did it');
        assert_true($checkinId > 0, 'setup: B\'s check-in must be created');

        $attack = CoachingAPI::recordCheckIn($customerA, $goalB, $day, 'not_achieved', 'sabotaged by A');
        assert_eq(0, $attack, 'a customer who does not own the goal must be rejected, not silently applied');

        $row = Database::row('SELECT customer_id, status, note FROM coaching_goal_checkin WHERE id = ?', [$checkinId]);
        assert_true($row !== null, 'B\'s check-in must still exist');
        assert_eq($customerB, (int) $row['customer_id'], 'check-in ownership must not change');
        assert_eq('achieved', $row['status'], 'A\'s status must not overwrite B\'s');
        assert_eq('B did it', $row['note'], 'A\'s note must not overwrite B\'s');
    } finally {
        Database::query('DELETE FROM coaching_goal_checkin WHERE goal_id = ?', [$goalB]);
        Database::query('DELETE FROM coaching_goal WHERE id = ?', [$goalB]);
    }
});

unit('coaching check-in: an attacker also cannot create a fresh check-in against a goal they don\'t own', function () use ($customerA, $customerB): void {
    $goalB = CoachingAPI::saveGoal(['customer_id' => $customerB, 'title' => 'B goal 2', 'scope' => 'daily']);
    assert_true($goalB > 0);
    $day = date('Y-m-d');

    try {
        // No prior check-in exists yet for this goal/day — attack the insert path.
        $attack = CoachingAPI::recordCheckIn($customerA, $goalB, $day, 'achieved', 'planted by A');
        assert_eq(0, $attack, 'creating a check-in against a foreign goal must be rejected');

        $row = Database::row('SELECT id FROM coaching_goal_checkin WHERE goal_id = ? AND day = ?', [$goalB, $day]);
        assert_null($row, 'no check-in row should have been created at all');
    } finally {
        Database::query('DELETE FROM coaching_goal_checkin WHERE goal_id = ?', [$goalB]);
        Database::query('DELETE FROM coaching_goal WHERE id = ?', [$goalB]);
    }
});

unit('coaching check-in: the actual owner can still create and then update their own check-in', function () use ($customerB): void {
    $goalB = CoachingAPI::saveGoal(['customer_id' => $customerB, 'title' => 'B goal 3', 'scope' => 'daily']);
    assert_true($goalB > 0);
    $day = date('Y-m-d');

    try {
        $checkinId = CoachingAPI::recordCheckIn($customerB, $goalB, $day, 'achieved', 'B did it');
        assert_true($checkinId > 0, 'the owner\'s check-in must be created');

        $again = CoachingAPI::recordCheckIn($customerB, $goalB, $day, 'exceeded', 'B did even more');
        assert_eq($checkinId, $again, 'updating the same day must return the same check-in id');

        $row = Database::row('SELECT status, note FROM coaching_goal_checkin WHERE id = ?', [$checkinId]);
        assert_eq('exceeded', $row['status']);
        assert_eq('B did even more', $row['note']);
    } finally {
        Database::query('DELETE FROM coaching_goal_checkin WHERE goal_id = ?', [$goalB]);
        Database::query('DELETE FROM coaching_goal WHERE id = ?', [$goalB]);
    }
});

$cleanup();
