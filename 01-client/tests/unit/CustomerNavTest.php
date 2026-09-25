<?php
/**
 * Unit tests for the customer portal navigation resolver — pure array work,
 * no Hook, no Auth, no DB. Mirrors the item pipeline the admin sidebar runs in
 * admin/partials/header.php (normalise → sort → group → mobile split), so the
 * cases here are the ones that would otherwise only surface as a broken portal.
 */

declare(strict_types=1);

use Slate\Presentation\CustomerNav;

/** A minimally valid contribution; override keys per case. */
$item = static function (string $slug, array $over = []): array {
    return array_merge(['slug' => $slug, 'label' => ucfirst($slug), 'href' => '/' . $slug], $over);
};

// ── resolve(): normalising ────────────────────────────────────────────

unit('resolve sorts by order, defaulting to 500', function () use ($item) {
    $out = CustomerNav::resolve([
        $item('c', ['order' => 900]),
        $item('a', ['order' => 100]),
        $item('b'),                       // no order → 500, lands in the middle
    ]);
    assert_eq(['a', 'b', 'c'], array_column($out, 'slug'));
    assert_eq(CustomerNav::DEFAULT_ORDER, $out[1]['order']);
});

unit('resolve keeps registration order within an equal order value', function () use ($item) {
    $out = CustomerNav::resolve([
        $item('first',  ['order' => 300]),
        $item('second', ['order' => 300]),
        $item('third',  ['order' => 300]),
    ]);
    assert_eq(['first', 'second', 'third'], array_column($out, 'slug'),
        'PHP 8 sort is stable, so a plugin\'s own items keep the sequence it declared');
});

unit('resolve drops malformed contributions instead of fataling the portal', function () {
    $out = CustomerNav::resolve([
        'not an array',
        ['label' => 'No slug', 'href' => '/x'],
        ['slug' => 'no-href'],
        ['slug' => '  ', 'href' => '/blank'],
        ['slug' => 'good', 'href' => '/good'],
    ]);
    assert_eq(['good'], array_column($out, 'slug'));
});

unit('resolve collapses a duplicate slug to its first occurrence', function () use ($item) {
    $out = CustomerNav::resolve([
        $item('studio', ['label' => 'My Studio']),
        $item('studio', ['label' => 'Duplicate']),
    ]);
    assert_eq(1, count($out), 'a plugin registered twice must not double up the bar');
    assert_eq('My Studio', $out[0]['label']);
});

unit('resolve falls back label→slug and supplies a default icon', function () {
    $out = CustomerNav::resolve([['slug' => 'billing', 'href' => '/billing']]);
    assert_eq('billing', $out[0]['label']);
    assert_eq('file', $out[0]['icon']);
});

unit('resolve marks exactly the active slug', function () use ($item) {
    $out = CustomerNav::resolve([$item('home'), $item('studio')], 'studio');
    assert_false($out[0]['is_active']);
    assert_true($out[1]['is_active']);
});

unit('resolve marks nothing active when the slug is null or empty', function () use ($item) {
    foreach ([null, ''] as $active) {
        $out = CustomerNav::resolve([$item('home'), $item('studio')], $active);
        assert_eq([], array_values(array_filter(array_column($out, 'is_active'))));
    }
});

unit('resolve normalises the group key and defaults it', function () use ($item) {
    $out = CustomerNav::resolve([
        $item('a', ['group' => 'My Studio!']),
        $item('b', ['group' => '   ']),
        $item('c'),
    ]);
    assert_eq('my-studio', $out[0]['group']);
    assert_eq(CustomerNav::DEFAULT_GROUP, $out[1]['group']);
    assert_eq(CustomerNav::DEFAULT_GROUP, $out[2]['group']);
});

unit('resolve distinguishes unset mobile_tab from an explicit false', function () use ($item) {
    $out = CustomerNav::resolve([$item('a'), $item('b', ['mobile_tab' => false])]);
    assert_true($out[0]['mobile_tab'] === null, 'unset means "on the bar if there is room"');
    assert_true($out[1]['mobile_tab'] === false);
});

// ── group() ───────────────────────────────────────────────────────────

unit('group buckets by group key, preserving first-seen order', function () use ($item) {
    $groups = CustomerNav::group(CustomerNav::resolve([
        $item('home',   ['group' => 'main',   'order' => 100]),
        $item('studio', ['group' => 'studio', 'order' => 200]),
        $item('profile',['group' => 'main',   'order' => 300]),
    ]));
    assert_eq(['main', 'studio'], array_keys($groups));
    assert_eq(['home', 'profile'], array_column($groups['main'], 'slug'));
});

// ── split(): the mobile tab bar ───────────────────────────────────────

unit('split caps the bar and sends the overflow to more', function () use ($item) {
    $items = CustomerNav::resolve(array_map(
        static fn (int $n): array => ['slug' => "s$n", 'href' => "/s$n", 'order' => $n],
        range(1, 8)
    ));
    $out = CustomerNav::split($items);
    assert_eq(CustomerNav::MAX_TABS, count($out['tabs']));
    assert_eq(['s1', 's2', 's3', 's4', 's5'], array_column($out['tabs'], 'slug'));
    assert_eq(['s6', 's7', 's8'], array_column($out['more'], 'slug'));
});

unit('split promotes mobile_tab=true ahead of earlier-ordered items', function () use ($item) {
    $items = CustomerNav::resolve([
        $item('a', ['order' => 100]),
        $item('b', ['order' => 200]),
        $item('pay', ['order' => 900, 'mobile_tab' => true]),
    ]);
    $out = CustomerNav::split($items, 2);
    assert_eq(['pay', 'a'], array_column($out['tabs'], 'slug'));
});

unit('split never bars mobile_tab=false, but always keeps it reachable in more', function () use ($item) {
    $items = CustomerNav::resolve([
        $item('a', ['order' => 100, 'mobile_tab' => false]),
        $item('b', ['order' => 200]),
    ]);
    $out = CustomerNav::split($items, 5);
    assert_eq(['b'], array_column($out['tabs'], 'slug'));
    assert_eq(['a'], array_column($out['more'], 'slug'),
        'an opted-out destination must not become unreachable on a phone');
});

unit('split with everything opted out yields an empty bar, not a crash', function () use ($item) {
    $items = CustomerNav::resolve([$item('a', ['mobile_tab' => false])]);
    $out = CustomerNav::split($items);
    assert_eq([], $out['tabs']);
    assert_eq(['a'], array_column($out['more'], 'slug'));
});

unit('split treats a non-positive cap as an empty bar', function () use ($item) {
    $items = CustomerNav::resolve([$item('a'), $item('b')]);
    foreach ([0, -3] as $max) {
        $out = CustomerNav::split($items, $max);
        assert_eq([], $out['tabs']);
        assert_eq(2, count($out['more']), 'every item stays reachable');
    }
});

unit('an empty contribution set resolves, groups and splits cleanly', function () {
    $items = CustomerNav::resolve([]);
    assert_eq([], $items);
    assert_eq([], CustomerNav::group($items));
    assert_eq(['tabs' => [], 'more' => []], CustomerNav::split($items));
});
