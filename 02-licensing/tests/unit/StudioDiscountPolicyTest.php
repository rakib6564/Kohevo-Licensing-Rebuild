<?php
/**
 * Unit tests for the configurable studio discount curves — pure, no DB.
 * The cases that matter are the ones a studio owner can create by typing into
 * the settings form: gaps, out-of-order tiers, nonsense percentages, and an
 * empty policy that must charge full price rather than nothing.
 */

declare(strict_types=1);

use Slate\Module\Studio\Domain\DiscountPolicy;
use Slate\Module\Studio\Domain\TuitionCalculator;

$series = ['price_cents' => 10000, 'currency' => 'USD'];

unit('default policy reproduces the shipped curves', function () {
    $p = DiscountPolicy::default();
    assert_eq(1.0,  $p->multiClassFactor(1));
    assert_eq(0.90, $p->multiClassFactor(2));
    assert_eq(0.85, $p->multiClassFactor(3));
    assert_eq(0.80, $p->multiClassFactor(4));
    assert_eq(0.80, $p->multiClassFactor(9), '4+ is the floor tier');
    assert_eq(1.0,  $p->siblingFactor(0));
    assert_eq(0.90, $p->siblingFactor(1));
    assert_eq(0.85, $p->siblingFactor(2));
});

unit('an omitted policy leaves the calculator behaving exactly as before', function () use ($series) {
    $calc = new TuitionCalculator();
    assert_eq(9000, $calc->calculate($series, 2, 0)->minor);
    assert_eq(8100, $calc->calculate($series, 2, 1)->minor);   // 0.90 * 0.90
    assert_eq(6800, $calc->calculate($series, 4, 2)->minor);   // 0.80 * 0.85
});

unit('a custom policy actually changes the price', function () use ($series) {
    $calc = new TuitionCalculator();
    $p = new DiscountPolicy([2 => 50], [1 => 50]);
    assert_eq(5000, $calc->calculate($series, 2, 0, $p)->minor);
    assert_eq(2500, $calc->calculate($series, 2, 1, $p)->minor, 'still stacks multiplicatively');
});

unit('an empty policy charges full price, never zero', function () use ($series) {
    $p = new DiscountPolicy([], []);
    assert_eq(1.0, $p->multiClassFactor(9));
    assert_eq(10000, (new TuitionCalculator())->calculate($series, 4, 3, $p)->minor);
});

unit('the highest qualifying tier wins, and gaps are allowed', function () {
    $p = new DiscountPolicy([2 => 10, 7 => 40], []);
    assert_eq(0.90, $p->multiClassFactor(2));
    assert_eq(0.90, $p->multiClassFactor(6), 'still on the 2-tier until 7');
    assert_eq(0.60, $p->multiClassFactor(7));
});

unit('tiers declared out of order still resolve correctly', function () {
    $p = new DiscountPolicy([4 => 20, 2 => 10, 3 => 15], []);
    assert_eq([2, 3, 4], array_keys($p->multiClassTiers()));
    assert_eq(0.85, $p->multiClassFactor(3));
});

unit('nonsense tiers are dropped rather than clamped', function () {
    // A typo must lose that tier, not make every class free or negative.
    $p = new DiscountPolicy([0 => 10, -3 => 10, 2 => -5, 3 => 150, 4 => 20, 'x' => 'y'], []);
    assert_eq([4], array_keys($p->multiClassTiers()));
    assert_eq(1.0,  $p->multiClassFactor(3), 'the bad tiers do not apply');
    assert_eq(0.80, $p->multiClassFactor(4));
});

unit('a 100% tier is allowed (a comped class) but cannot go past free', function () use ($series) {
    $p = new DiscountPolicy([2 => 100], []);
    assert_eq(0.0, $p->multiClassFactor(2));
    assert_eq(0, (new TuitionCalculator())->calculate($series, 2, 0, $p)->minor);
});

unit('fromJson round-trips stored settings', function () {
    $p = DiscountPolicy::fromJson('{"2":25,"5":40}', '{"1":5}');
    assert_eq(0.75, $p->multiClassFactor(2));
    assert_eq(0.60, $p->multiClassFactor(5));
    assert_eq(0.95, $p->siblingFactor(1));
});

unit('fromJson falls back to defaults on unusable input', function () {
    foreach ([null, '', '   ', 'not json', '"a string"', '42'] as $bad) {
        $p = DiscountPolicy::fromJson($bad, $bad);
        assert_eq(0.90, $p->multiClassFactor(2), 'corrupt settings must not stop billing');
        assert_eq(0.90, $p->siblingFactor(1));
    }
});

unit('fromJson keeps the default for whichever half is missing', function () {
    $p = DiscountPolicy::fromJson('{"2":50}', null);
    assert_eq(0.50, $p->multiClassFactor(2), 'the configured half applies');
    assert_eq(0.90, $p->siblingFactor(1), 'the missing half stays default');
});
