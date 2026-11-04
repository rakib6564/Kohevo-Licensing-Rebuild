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
