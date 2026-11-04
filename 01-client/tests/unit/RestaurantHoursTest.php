<?php
/**
 * Restaurant online ordering hours: reading the hours as typed, open/closed status, and the pickup times offered.
 * Pure maths (config + a fixed "now"), no database.
 */

declare(strict_types=1);

if (!defined('SLATE_ROOT')) define('SLATE_ROOT', dirname(__DIR__, 2));
require_once dirname(__DIR__, 2) . '/plugins/restaurant/includes/hours.php';

$__hcfg = static function (array $week, int $lead = 20, int $step = 15, int $ahead = 0): array {
    $w = [];
    foreach (RestaurantHours::DAYS as $d) $w[$d] = RestaurantHours::parseDay($week[$d] ?? '') ?? [];
    return RestaurantHours::clean(['on' => true, 'week' => $w, 'lead' => $lead, 'step' => $step, 'ahead' => $ahead]);
};
// The BBQ house: Thu-Fri lunch and dinner, Sat lunch and dinner. 2026-11-05 is a Thursday.
$__bbq = ['thu' => '12:00-14:30, 17:00-20:00', 'fri' => '12:00-14:30, 17:00-20:00', 'sat' => '12-2pm, 5pm-8pm'];
$__at = static fn(string $t): DateTimeImmutable => new DateTimeImmutable($t, new DateTimeZone('America/Los_Angeles'));

unit('restaurant hours: a day is read in 24-hour or am/pm form, and bad or overlapping text is refused', function (): void {
    assert_eq([[720, 870], [1020, 1200]], RestaurantHours::parseDay('12:00-14:30, 17:00-20:00'));
    assert_eq([[720, 840], [1020, 1200]], RestaurantHours::parseDay('5pm-8pm; 12-2pm'), 'sorted, am/pm and bare hours');
    assert_eq([], RestaurantHours::parseDay(''));
    assert_eq([], RestaurantHours::parseDay('Closed'));
    assert_eq([[0, 1440]], RestaurantHours::parseDay('0:00-24:00'));
    assert_eq([[660, 840], [1020, 1260]], RestaurantHours::parseDay('11am-2pm, 5-9pm'), 'a bare start borrows the end\'s pm');
    assert_eq([[660, 780]], RestaurantHours::parseDay('11-1pm'), '11 stays morning when 11pm would be after 1pm');
    foreach (['noon-2', '14:30-12:00', '12:00-14:00, 13:00-15:00', '13pm-2pm', '12:75-13:00', '12:00'] as $bad) {
        assert_eq(null, RestaurantHours::parseDay($bad), 'refused: ' . $bad);
    }
    assert_eq('17:05', RestaurantHours::hhmm(1025));
});

unit('restaurant hours: open now until the window ends, or closed with the next opening', function () use ($__hcfg, $__bbq, $__at): void {
    $c = $__hcfg($__bbq);
    $s = RestaurantHours::status($c, $__at('2026-11-05 13:10'));
    assert_true($s['open']);
    assert_eq('2026-11-05 14:30', $s['until']->format('Y-m-d H:i'));
    $s = RestaurantHours::status($c, $__at('2026-11-05 15:00'));
    assert_true(!$s['open']);
    assert_eq('2026-11-05 17:00', $s['next']->format('Y-m-d H:i'), 'dinner the same day');
    $s = RestaurantHours::status($c, $__at('2026-11-08 10:00'));   // Sunday
    assert_eq('2026-11-12 12:00', $s['next']->format('Y-m-d H:i'), 'closed Sunday to Wednesday: next is Thursday lunch');
    assert_eq('Closed · opens Thu Nov 12, 12:00 PM', RestaurantHours::line($c, $__at('2026-11-08 10:00')));
    assert_eq('Open now · until 2:30 PM', RestaurantHours::line($c, $__at('2026-11-05 13:10')));
    assert_eq('Closed', RestaurantHours::line($__hcfg([]), $__at('2026-11-08 10:00')), 'no hours at all');
});

unit('restaurant hours: pickup times start after the kitchen\'s lead time, on the step, and stop at closing', function () use ($__hcfg, $__bbq, $__at): void {
    $c = $__hcfg($__bbq, 20, 15);
    $slots = RestaurantHours::slots($c, $__at('2026-11-05 13:52'));
    assert_eq('asap', $slots[0]['value']);
    assert_eq('2:12 PM', $slots[0]['at']->format('g:i A'), 'ASAP = now + lead');
    $vals = array_column(array_slice($slots, 1), 'value');
    assert_eq('2026-11-05 14:15', $vals[0], 'first step-aligned time after 14:12');
    assert_eq('2026-11-05 14:30', $vals[1], 'closing time is the last lunch pickup');
    assert_eq('2026-11-05 17:00', $vals[2], 'then dinner');
    assert_eq('2026-11-05 20:00', end($vals));
    assert_eq('Today 2:15 PM', $slots[1]['label']);

    $late = RestaurantHours::slots($c, $__at('2026-11-05 14:20'));
    assert_true($late[0]['value'] !== 'asap', 'no ASAP when it cannot be ready before closing');
    assert_eq('2026-11-05 17:00', $late[0]['value']);

    assert_eq([], RestaurantHours::slots($c, $__at('2026-11-08 10:00')), 'Sunday, today only: nothing on offer');
    $pre = RestaurantHours::slots($__hcfg($__bbq, 20, 30, 4), $__at('2026-11-08 10:00'));
    assert_eq('2026-11-12 12:00', $pre[0]['value'], 'with 4 days ahead, Thursday is on offer');
    assert_eq('Thu Nov 12, 12:00 PM', $pre[0]['label']);
    assert_eq('Thu Nov 12', RestaurantHours::dayLabel($pre[0]['at'], $__at('2026-11-08 10:00')));
    assert_true(mb_strlen($pre[0]['label']) <= 40, 'fits the order\'s requested_time column');
    assert_eq(null, RestaurantHours::pick($pre, '2026-11-12 12:10'), 'a time not offered is refused');
    assert_eq('2026-11-12 12:30', RestaurantHours::pick($pre, '2026-11-12 12:30')['value']);
});

unit('restaurant hours: numbers are kept in bounds', function (): void {
    $c = RestaurantHours::clean(['on' => '1', 'lead' => -5, 'step' => 7, 'ahead' => 400]);
    assert_eq([true, 0, 15, RestaurantHours::MAX_DAYS_AHEAD], [$c['on'], $c['lead'], $c['step'], $c['ahead']]);
});

// ── 0.8.0: closed dates ──────────────────────────────────────────

unit('restaurant closed dates: single dates, ranges and notes are read; bad lines are reported', function (): void {
    $p = RestaurantHours::parseClosed("2026-11-26 # Thanksgiving\n\n2026-12-24 to 2026-12-26\n2026-12-31..2027-01-01\n2026-02-30\n2026-12-10 to 2026-12-01\nsoon");
    assert_eq(['2026-11-26', '2026-12-24', '2026-12-25', '2026-12-26', '2026-12-31', '2027-01-01'], $p['dates']);
    assert_eq(['2026-02-30', '2026-12-10 to 2026-12-01', 'soon'], $p['bad']);
    assert_eq(['2026-08-01', '2026-08-02'], RestaurantHours::parseClosed('2026-08-01 au 2026-08-02')['dates'], 'French "au"');
    assert_eq(['2026-01-01 to 2026-12-31'], RestaurantHours::parseClosed('2026-01-01 to 2026-12-31')['bad'], 'a range longer than two months is a typo');
    assert_eq(['dates' => [], 'bad' => []], RestaurantHours::parseClosed(''));
});

unit('restaurant closed dates: no pickup times on a closed date, and the storefront line says closed today', function () use ($__hcfg, $__bbq, $__at): void {
    $c = $__hcfg($__bbq, 20, 15, 7);
    $c['closed'] = ['2026-11-05', '2026-11-07'];   // Thursday and Saturday
    $c = RestaurantHours::clean($c);
    $slots = RestaurantHours::slots($c, $__at('2026-11-05 11:00'));
    assert_true($slots !== [], 'later days are still offered');
    assert_eq('2026-11-06 12:00', $slots[0]['value'], 'Thursday (closed) skipped: first time is Friday, no ASAP');
    assert_true(array_filter($slots, fn($s) => str_starts_with($s['value'], '2026-11-07')) === [], 'Saturday (closed) skipped');
    $s = RestaurantHours::status($c, $__at('2026-11-05 13:00'));
    assert_true(!$s['open'], 'closed in what would be lunch');
    assert_eq('2026-11-06 12:00', $s['next']->format('Y-m-d H:i'));
    assert_eq('Closed today · opens Tomorrow 12:00 PM', RestaurantHours::line($c, $__at('2026-11-05 13:00')));
    assert_true(RestaurantHours::isClosedDate($c, $__at('2026-11-07 09:00')));
    assert_true(!RestaurantHours::isClosedDate($c, $__at('2026-11-06 09:00')));
});

unit('restaurant closed dates: a long closure still finds the next opening', function () use ($__hcfg, $__bbq, $__at): void {
    $c = $__hcfg($__bbq);
    $c['closed'] = RestaurantHours::parseClosed('2026-11-05 to 2026-11-21')['dates'];
    $c = RestaurantHours::clean($c);
    $s = RestaurantHours::status($c, $__at('2026-11-05 13:00'));
    assert_eq('2026-11-26 12:00', $s['next']->format('Y-m-d H:i'), 'two and a half weeks off: next Thursday after it');
    $c = RestaurantHours::clean(['on' => true, 'closed' => ['nope', '2026-11-05']]);
    assert_eq(['2026-11-05'], $c['closed'], 'clean() keeps only real dates');
});

// ── 0.8.0: pre-order menus ───────────────────────────────────────

require_once dirname(__DIR__, 2) . '/plugins/restaurant/includes/preorder.php';

$__thanks = ['id' => 7, 'preorder' => 1, 'preorder_from' => '2026-11-01 00:00:00', 'preorder_until' => '2026-11-22 20:00:00',
             'preorder_dates' => "2026-11-25 10:00-12:00\n2026-11-26", 'preorder_note' => ''];

unit('restaurant pre-order: pickup dates are read with optional times; bad lines are reported', function (): void {
    $p = RestaurantPreorder::parseDates("2026-11-26\n2026-11-25 10:00-12:00, 4pm-6pm # pickup window\n2026-11-25 13:00-14:00\n2026-11-31\n2026-11-27 noon\n2026-11-28 9:00-11:00\n2026-11-28 10:00-12:00");
    assert_eq(['2026-11-25', '2026-11-26', '2026-11-28'], array_keys($p['dates']), 'sorted by date');
    assert_eq([[600, 720], [780, 840], [960, 1080]], $p['dates']['2026-11-25'], 'the same date twice: times joined');
    assert_eq([], $p['dates']['2026-11-26'], 'a date alone: no times of its own');
    assert_eq(['2026-11-31', '2026-11-27 noon', '2026-11-28 10:00-12:00'], $p['bad'], 'impossible date, bad time, overlap');
    assert_eq("2026-11-25 10:00-12:00, 13:00-14:00, 16:00-18:00\n2026-11-26\n2026-11-28 9:00-11:00", RestaurantPreorder::datesText($p['dates']));
});

unit('restaurant pre-order: the ordering window opens and closes, and needs a pickup time ahead', function () use ($__hcfg, $__bbq, $__at, $__thanks): void {
    $h = $__hcfg($__bbq);
    assert_true(!RestaurantPreorder::isOpen($__thanks, $h, $__at('2026-10-31 23:59')), 'before it opens');
    assert_true(RestaurantPreorder::isOpen($__thanks, $h, $__at('2026-11-01 00:00')), 'from the opening minute');
    assert_true(RestaurantPreorder::isOpen($__thanks, $h, $__at('2026-11-22 19:59')));
    assert_true(!RestaurantPreorder::isOpen($__thanks, $h, $__at('2026-11-22 20:00')), 'closed at the until time');
    $noUntil = ['preorder_until' => null, 'preorder_from' => null] + $__thanks;
    assert_true(RestaurantPreorder::isOpen($noUntil, $h, $__at('2026-11-26 09:00')), 'no until: open while a pickup is ahead');
    assert_true(!RestaurantPreorder::isOpen($noUntil, $h, $__at('2026-11-27 00:00')), 'no until: shut after the last pickup date');
    assert_true(!RestaurantPreorder::isOpen(['preorder' => 0] + $__thanks, $h, $__at('2026-11-10 12:00')), 'not a pre-order category');
    assert_eq(RestaurantPreorder::at('2026-11-20T09:30', RestaurantHours::tz())?->format('Y-m-d H:i'), '2026-11-20 09:30', 'the form\'s datetime-local value reads');
});

unit('restaurant pre-order: slots on the pickup dates only, after lead time, ignoring closed dates and days-ahead', function () use ($__hcfg, $__bbq, $__at, $__thanks): void {
    $h = $__hcfg($__bbq, 20, 30, 0);
    $h['closed'] = ['2026-11-26'];    // Thanksgiving closed to regular orders
    $h = RestaurantHours::clean($h);
    $slots = RestaurantPreorder::slots($__thanks, $h, $__at('2026-11-10 12:00'));
    $vals = array_column($slots, 'value');
    assert_eq(['2026-11-25 10:00', '2026-11-25 10:30', '2026-11-25 11:00', '2026-11-25 11:30', '2026-11-25 12:00'], array_slice($vals, 0, 5), 'own times, every 30 min');
    assert_eq('2026-11-26 12:00', $vals[5], 'a date alone (a Thursday) uses Thursday\'s hours even though it is a closed date');
    assert_eq('2026-11-26 20:00', end($vals));
    assert_eq('Wed Nov 25, 10:00 AM', $slots[0]['label']);
    assert_eq('Pre-order · Wed Nov 25, 10:00 AM', RestaurantPreorder::savedText($slots[0]));

    $late = RestaurantPreorder::slots($__thanks, $h, $__at('2026-11-25 10:50'));
    assert_eq('2026-11-25 11:30', $late[0]['value'], 'on the day: after the 20-minute lead time, on the step');

    $sunday = ['preorder_dates' => '2026-11-29'] + $__thanks;   // Sunday: no opening hours
    $one = RestaurantPreorder::slots($sunday, $h, $__at('2026-11-10 12:00'));
    assert_eq(1, count($one));
    assert_eq('2026-11-29', $one[0]['value'], 'no hours that day: one all-day choice');
    assert_true($one[0]['all_day']);
    assert_eq('Pre-order · Sun Nov 29', RestaurantPreorder::savedText($one[0]));
    assert_eq(null, RestaurantHours::pick($slots, '2026-11-24 10:00'), 'a date not offered is refused');
    assert_true(mb_strlen(RestaurantPreorder::savedText($slots[0])) <= 40);
});

unit('restaurant pre-order: the note shows the order-by time and pickup days, unless the category has its own', function () use ($__hcfg, $__bbq, $__at, $__thanks): void {
    $h = $__hcfg($__bbq);
    assert_eq('Pre-order · order by Sun Nov 22, 8:00 PM · pickup Wed Nov 25, Thu Nov 26', RestaurantPreorder::note($__thanks, $h, $__at('2026-11-10 12:00')));
    assert_eq('Pre-order · pickup Wed Nov 25, Thu Nov 26', RestaurantPreorder::note(['preorder_until' => null] + $__thanks, $h, $__at('2026-11-10 12:00')));
    assert_eq('Turkey by Sunday!', RestaurantPreorder::note(['preorder_note' => 'Turkey by Sunday!'] + $__thanks, $h, $__at('2026-11-10 12:00')));
});

unit('restaurant pre-order: a cart is regular items or one pre-order menu, never a mix', function () use ($__thanks): void {
    $cats = [1 => null, 2 => ['id' => 3, 'preorder' => 0], 10 => $__thanks, 11 => $__thanks, 20 => ['id' => 8] + $__thanks];
    $of = fn(int $id): ?array => $cats[$id] ?? null;
    $line = fn(int $id): array => ['item_id' => $id, 'qty' => 1];
    assert_eq('empty', RestaurantPreorder::group([], $of)['kind']);
    assert_eq('regular', RestaurantPreorder::group([$line(1), $line(2)], $of)['kind'], 'uncategorised and a normal category');
    $g = RestaurantPreorder::group([$line(10), $line(11)], $of);
    assert_eq(['preorder', 7], [$g['kind'], $g['category']['id']], 'two items of one pre-order menu');
    assert_eq('mixed', RestaurantPreorder::group([$line(1), $line(10)], $of)['kind'], 'regular + pre-order');
    assert_eq('mixed', RestaurantPreorder::group([$line(10), $line(1)], $of)['kind'], 'pre-order + regular');
    assert_eq('mixed', RestaurantPreorder::group([$line(10), $line(20)], $of)['kind'], 'two pre-order menus');
});
