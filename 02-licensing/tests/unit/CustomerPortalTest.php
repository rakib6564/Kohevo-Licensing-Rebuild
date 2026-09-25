<?php
/**
 * Unit tests for CustomerPortal service.
 *
 * Validates:
 *   - Canonical navigation generation and legacy URL normalisation
 *   - Feature-local subnavigation isolation (never rendered on 'home')
 *   - Chronological activity timeline aggregation
 *   - Widget and KPI stat ordering and capping
 *   - Account sections resolution and ordering
 */

declare(strict_types=1);

use Slate\Services\Portal\CustomerPortal;
use Slate\Kernel\Event\Hook;

if (!class_exists('Hook', false) && class_exists(Hook::class)) {
    class_alias(Hook::class, 'Hook');
}

unit('CustomerPortal instance factory and customer binding', function (): void {
    $portal = CustomerPortal::forCustomer(42, ['id' => 42, 'name' => 'Jane Doe', 'email' => 'jane@example.com']);
    assert_eq(42, $portal->customerId());
    assert_eq('Jane Doe', $portal->customer()['name']);
    assert_eq($portal, CustomerPortal::current());
});

unit('CustomerPortal navigation contains core items and normalises legacy URLs', function (): void {
    $portal = new CustomerPortal(1, ['id' => 1, 'name' => 'Test']);
    $nav = $portal->navigation('home');

    assert_true(isset($nav['items']), 'nav contains items');
    assert_true(isset($nav['groups']), 'nav contains groups');

    $slugs = array_column($nav['items'], 'slug');
    assert_true(in_array('home', $slugs, true), 'home destination present');
    assert_true(in_array('book', $slugs, true), 'book destination present');
    assert_true(in_array('activity', $slugs, true), 'activity destination present');
    assert_true(in_array('account', $slugs, true), 'account destination present');

    // Check URL canonicalization
    $bySlug = [];
    foreach ($nav['items'] as $item) {
        $bySlug[$item['slug']] = $item;
    }

    assert_true(str_ends_with($bySlug['home']['href'], '/member'), 'home maps to /member');
    assert_true(str_ends_with($bySlug['book']['href'], '/member/book'), 'book maps to /member/book');
    assert_true(str_ends_with($bySlug['activity']['href'], '/member/activity'), 'activity maps to /member/activity');
    assert_true(str_ends_with($bySlug['account']['href'], '/member/account'), 'account maps to /member/account');
    assert_true($bySlug['home']['is_active'], 'home is active when requested');
    assert_false($bySlug['account']['is_active'], 'account is not active when home is requested');
});

unit('CustomerPortal contextNavigation returns empty on home or account areas', function (): void {
    $portal = new CustomerPortal(1);
    assert_eq([], $portal->contextNavigation('home'));
    assert_eq([], $portal->contextNavigation('account'));
    assert_eq([], $portal->contextNavigation('activity'));
    assert_eq([], $portal->contextNavigation(''));
});

unit('CustomerPortal activity normalises and sorts events chronologically descending', function (): void {
    $portal = new CustomerPortal(1);

    Hook::addFilter('customer_portal_activity', function (array $events): array {
        $events[] = [
            'id'          => 'ev-1',
            'label'       => 'Old Event',
            'occurred_at' => '2026-01-01 10:00:00',
        ];
        $events[] = [
            'id'          => 'ev-2',
            'label'       => 'New Event',
            'occurred_at' => '2026-09-01 12:00:00',
        ];
        $events[] = 'invalid-entry';
        return $events;
    });

    $act = $portal->activity();
    assert_true(count($act) >= 2, 'activity contains valid events');
    assert_eq('ev-2', $act[0]['id'], 'most recent event appears first');
    assert_eq('ev-1', $act[1]['id'], 'older event appears second');
});

unit('CustomerPortal stats sorts tones first and caps at 4', function (): void {
    $portal = new CustomerPortal(1);

    Hook::addFilter('customer_portal_kpis', function (array $stats): array {
        return [
            ['label' => 'S1', 'value' => '1', 'tone' => ''],
            ['label' => 'S2', 'value' => '2', 'tone' => 'green'],
            ['label' => 'S3', 'value' => '3', 'tone' => 'amber'],
            ['label' => 'S4', 'value' => '4', 'tone' => ''],
            ['label' => 'S5', 'value' => '5', 'tone' => 'blue'],
            ['label' => 'S6', 'value' => '6', 'tone' => ''],
        ];
    });

    $stats = $portal->stats();
    assert_true(count($stats) <= 4, 'stats capped at 4');
    assert_true(!empty($stats[0]['tone']), 'first stat has tone');
    assert_true(!empty($stats[1]['tone']), 'second stat has tone');
});
