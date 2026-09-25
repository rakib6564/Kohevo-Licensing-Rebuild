<?php
/**
 * Unit tests for the Studio tuition calculator — pure Money math, no DB.
 * Exercises the stacking multi-class + sibling discount curves and session
 * proration. Autoloaded via Slate\ -> src/ (Slate\Module\Studio\Domain).
 */

declare(strict_types=1);

use Slate\Module\Studio\Domain\TuitionCalculator;
use Slate\Support\Money;

$series = ['price_cents' => 10000, 'currency' => 'USD'];   // $100.00

unit('no discounts: one class, no siblings → full price', function () use ($series) {
    $calc = new TuitionCalculator();
    assert_eq(10000, $calc->calculate($series, 1, 0)->minor);
    assert_eq('USD', $calc->calculate($series, 1, 0)->currency);
});

unit('multi-class discount curve (2→10%, 3→15%, 4+→20%)', function () use ($series) {
    $calc = new TuitionCalculator();
    assert_eq(9000, $calc->calculate($series, 2, 0)->minor);
    assert_eq(8500, $calc->calculate($series, 3, 0)->minor);
    assert_eq(8000, $calc->calculate($series, 4, 0)->minor);
    assert_eq(8000, $calc->calculate($series, 9, 0)->minor, '4+ is the floor tier');
});

unit('sibling discount curve (1→10%, 2+→15%)', function () use ($series) {
    $calc = new TuitionCalculator();
    assert_eq(9000, $calc->calculate($series, 1, 1)->minor);
    assert_eq(8500, $calc->calculate($series, 1, 2)->minor);
});

unit('discounts stack multiplicatively (2nd class + 1 sibling → 0.90*0.90)', function () use ($series) {
    $calc = new TuitionCalculator();
    assert_eq(8100, $calc->calculate($series, 2, 1)->minor);   // 10000*0.9*0.9
    assert_eq(6800, $calc->calculate($series, 4, 2)->minor);   // 10000*0.8*0.85
});

unit('proration: full when joining at/before session start', function () {
    $calc  = new TuitionCalculator();
    $full  = new Money(10000, 'USD');
    $start = new DateTimeImmutable('2026-01-01');
    $end   = new DateTimeImmutable('2026-01-31');           // 30-day span
    assert_eq(10000, $calc->prorate($full, $start, $end, $start)->minor);
    assert_eq(10000, $calc->prorate($full, $start, $end, new DateTimeImmutable('2025-12-15'))->minor);
});

unit('proration: half the span → half the tuition; past end → zero', function () {
    $calc  = new TuitionCalculator();
    $full  = new Money(10000, 'USD');
    $start = new DateTimeImmutable('2026-01-01');
    $end   = new DateTimeImmutable('2026-01-31');           // 30 days total
    assert_eq(5000, $calc->prorate($full, $start, $end, new DateTimeImmutable('2026-01-16'))->minor);  // 15/30
    assert_eq(0,    $calc->prorate($full, $start, $end, new DateTimeImmutable('2026-02-10'))->minor);
});
