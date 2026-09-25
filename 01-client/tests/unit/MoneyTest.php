<?php
/**
 * Unit tests for Slate\Support\Money (ADR-0011).
 */

declare(strict_types=1);

use Slate\Support\Money;

// ── Construction & validation ─────────────────────────────
unit('Money::of stores minor units + currency', function () {
    $m = Money::of(1234, 'USD');
    assert_eq(1234, $m->minor());
    assert_eq('USD', $m->currency());
});

unit('Money::of upper-cases the currency', function () {
    assert_eq('EUR', Money::of(100, 'eur')->currency());
});

unit('Money rejects a non-ISO currency code', function () {
    assert_throws(\InvalidArgumentException::class, fn () => Money::of(100, 'US'));
    assert_throws(\InvalidArgumentException::class, fn () => Money::of(100, 'US$'));
    assert_throws(\InvalidArgumentException::class, fn () => Money::of(100, 'usdd'));
});

unit('Money::zero is zero in the currency', function () {
    $z = Money::zero('GBP');
    assert_true($z->isZero());
    assert_eq('GBP', $z->currency());
});

// ── fromDecimal / exponent-aware ──────────────────────────
unit('fromDecimal converts major units to minor (2dp default)', function () {
    assert_eq(1234, Money::fromDecimal('12.34', 'USD')->minor());
    assert_eq(1200, Money::fromDecimal('12', 'USD')->minor());
    assert_eq(1235, Money::fromDecimal('12.345', 'USD')->minor()); // half-up
});

unit('fromDecimal respects zero-decimal currencies', function () {
    assert_eq(500, Money::fromDecimal('500', 'JPY')->minor());
    assert_eq(0, Money::of(0, 'JPY')->exponent());
});

unit('fromDecimal respects three-decimal currencies', function () {
    assert_eq(12345, Money::fromDecimal('12.345', 'BHD')->minor());
    assert_eq(3, Money::of(0, 'BHD')->exponent());
});

// ── Arithmetic (immutable) ────────────────────────────────
unit('plus / minus return new instances, leave original unchanged', function () {
    $a = Money::of(1000, 'USD');
    $b = Money::of(250, 'USD');
    assert_eq(1250, $a->plus($b)->minor());
    assert_eq(750, $a->minus($b)->minor());
    assert_eq(1000, $a->minor(), 'original a must be unchanged');
});

unit('times rounds to whole minor units (half-up)', function () {
    assert_eq(1088, Money::of(1000, 'USD')->times(1.0875)->minor()); // 1087.5 -> 1088
    assert_eq(333, Money::of(1000, 'USD')->times(1 / 3)->minor());   // 333.33 -> 333
    assert_eq(2000, Money::of(1000, 'USD')->times(2)->minor());
});

unit('negated / abs', function () {
    assert_eq(-500, Money::of(500, 'USD')->negated()->minor());
    assert_eq(500, Money::of(-500, 'USD')->abs()->minor());
    assert_eq(500, Money::of(500, 'USD')->abs()->minor());
});

unit('withMinor keeps currency, replaces amount', function () {
    $m = Money::of(100, 'USD')->withMinor(9999);
    assert_eq(9999, $m->minor());
    assert_eq('USD', $m->currency());
});

// ── Currency-mismatch guard ───────────────────────────────
unit('cross-currency arithmetic throws', function () {
    $usd = Money::of(100, 'USD');
    $eur = Money::of(100, 'EUR');
    assert_throws(\InvalidArgumentException::class, fn () => $usd->plus($eur));
    assert_throws(\InvalidArgumentException::class, fn () => $usd->minus($eur));
    assert_throws(\InvalidArgumentException::class, fn () => $usd->compareTo($eur));
});

// ── Comparison ────────────────────────────────────────────
unit('equals requires same amount AND currency', function () {
    assert_true(Money::of(100, 'USD')->equals(Money::of(100, 'USD')));
    assert_false(Money::of(100, 'USD')->equals(Money::of(101, 'USD')));
    assert_false(Money::of(100, 'USD')->equals(Money::of(100, 'EUR')));
});

unit('ordering helpers', function () {
    $a = Money::of(100, 'USD');
    $b = Money::of(200, 'USD');
    assert_true($a->lessThan($b));
    assert_true($a->lessThanOrEqual($a));
    assert_true($b->greaterThan($a));
    assert_true($b->greaterThanOrEqual($b));
    assert_eq(-1, $a->compareTo($b));
    assert_eq(0, $a->compareTo($a));
    assert_eq(1, $b->compareTo($a));
});

unit('sign predicates', function () {
    assert_true(Money::of(1, 'USD')->isPositive());
    assert_true(Money::of(-1, 'USD')->isNegative());
    assert_true(Money::of(0, 'USD')->isZero());
});

// ── Serialisation / formatting ────────────────────────────
unit('jsonSerialize / toArray use {amount, currency} shape', function () {
    $m = Money::of(1234, 'USD');
    assert_eq(['amount' => 1234, 'currency' => 'USD'], $m->toArray());
    assert_eq('{"amount":1234,"currency":"USD"}', json_encode($m));
});

unit('fromArray round-trips jsonSerialize', function () {
    $m = Money::of(-4200, 'EUR');
    assert_true($m->equals(Money::fromArray($m->toArray())));
});

unit('fromArray requires both keys', function () {
    assert_throws(\InvalidArgumentException::class, fn () => Money::fromArray(['amount' => 5]));
    assert_throws(\InvalidArgumentException::class, fn () => Money::fromArray(['currency' => 'USD']));
});

unit('toDecimal formats per currency exponent', function () {
    assert_eq('12.34', Money::of(1234, 'USD')->toDecimal());
    assert_eq('12.04', Money::of(1204, 'USD')->toDecimal());
    assert_eq('-0.05', Money::of(-5, 'USD')->toDecimal());
    assert_eq('500', Money::of(500, 'JPY')->toDecimal());
    assert_eq('12.345', Money::of(12345, 'BHD')->toDecimal());
});

unit('__toString is "CUR decimal"', function () {
    assert_eq('USD 12.34', (string) Money::of(1234, 'USD'));
});
