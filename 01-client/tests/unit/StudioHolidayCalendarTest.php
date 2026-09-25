<?php
/**
 * Studio — HolidayCalendar (pure) unit tests.
 *
 * The important ones are the lenient-parsing cases. A studio owner pastes
 * dates from an email; if one bad line threw the list away, classes would be
 * generated on Christmas Day and nobody would notice until a parent turned up.
 */

declare(strict_types=1);

use Slate\Module\Studio\Domain\HolidayCalendar as HC;

unit('a single date is a holiday', function () {
    $h = HC::fromText('2026-12-25');
    assert_true($h->isHoliday('2026-12-25'));
    assert_false($h->isHoliday('2026-12-26'));
    assert_eq(1, $h->count());
});

unit('a range covers both ends inclusively', function () {
    $h = HC::fromText('2026-12-24..2026-12-26');
    assert_true($h->isHoliday('2026-12-24'), 'start included');
    assert_true($h->isHoliday('2026-12-25'));
    assert_true($h->isHoliday('2026-12-26'), 'end included');
    assert_false($h->isHoliday('2026-12-27'));
    assert_eq(3, $h->count());
});

unit('a backwards range is read as intended, not dropped', function () {
    $h = HC::fromText('2026-12-26..2026-12-24');
    assert_eq(3, $h->count(), 'typed the wrong way round, still three days off');
});

unit('labels are kept and returned', function () {
    $h = HC::fromText("2026-12-24..2027-01-02 Winter break\n2026-11-26 Thanksgiving");
    assert_eq('Winter break',  $h->labelFor('2026-12-25'));
    assert_eq('Thanksgiving',  $h->labelFor('2026-11-26'));
    assert_eq('', $h->labelFor('2026-10-01'), 'not a holiday, no label');
});

unit('newline and comma separated lists both parse', function () {
    assert_eq(2, HC::fromText("2026-12-25\n2026-12-26")->count());
    assert_eq(2, HC::fromText('2026-12-25, 2026-12-26')->count());
});

unit('one bad line does not throw the whole list away', function () {
    $h = HC::fromText("2026-12-25 Christmas\nnot a date at all\n2026-12-26 Boxing Day");
    assert_eq(2, $h->count(), 'the two good lines survive');
    assert_true($h->isHoliday('2026-12-25') && $h->isHoliday('2026-12-26'));
});

unit('impossible dates are rejected', function () {
    assert_eq(0, HC::fromText('2026-02-30')->count(), 'February has no 30th');
    assert_eq(0, HC::fromText('2026-13-01')->count());
    assert_eq(1, HC::fromText('2028-02-29')->count(), 'but a real leap day is fine');
});

unit('a runaway range is capped rather than hanging the generator', function () {
    $h = HC::fromText('2026-01-01..2030-01-01');
    assert_true($h->count() <= HC::MAX_RANGE_DAYS + 1,
        'a typo must not expand to thousands of dates');
});

unit('comments and blank lines are ignored', function () {
    $h = HC::fromText("# Seaford school district\n\n2026-12-25\n\n  \n");
    assert_eq(1, $h->count());
});

unit('an empty or null list yields no holidays', function () {
    assert_eq(0, HC::fromText('')->count());
    assert_eq(0, HC::fromText(null)->count());
    assert_eq(0, HC::empty()->count());
    assert_false(HC::empty()->isHoliday('2026-12-25'));
});

unit('a datetime is matched on its date part', function () {
    $h = HC::fromText('2026-12-25');
    assert_true($h->isHoliday('2026-12-25 18:00:00'), 'an occurrence datetime still matches');
});

unit('dates come back in order', function () {
    $h = HC::fromText("2026-12-26\n2026-11-26\n2026-12-25");
    assert_eq(['2026-11-26', '2026-12-25', '2026-12-26'], array_keys($h->all()));
});

unit('upcoming skips what has already passed', function () {
    $h = HC::fromText("2026-01-01\n2026-12-25\n2027-01-01");
    assert_eq(['2026-12-25', '2027-01-01'], array_keys($h->upcoming('2026-06-01')));
    assert_eq(1, count($h->upcoming('2026-06-01', 1)), 'limit respected');
});

unit('duplicate dates collapse, last label wins', function () {
    $h = HC::fromText("2026-12-25 Christmas\n2026-12-25 Xmas");
    assert_eq(1, $h->count());
    assert_eq('Xmas', $h->labelFor('2026-12-25'));
});
