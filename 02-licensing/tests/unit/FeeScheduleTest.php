<?php
/**
 * Studio — FeeSchedule.
 *
 * Covers the rules that aren't just "read a number back": the per-family
 * recital taper, the costume exemptions, and the instalment split — which is
 * the one that can silently bill a parent the wrong amount.
 */

declare(strict_types=1);

use Slate\Module\Studio\Domain\FeeSchedule;

// ── Registration ──────────────────────────────────────────────────────

unit('registration is free by default, and says so', function () {
    $f = FeeSchedule::default();
    assert_eq(0, $f->registrationCents());
    assert_true($f->registrationIsFree());
});

unit('a studio that charges registration is not "free"', function () {
    $f = FeeSchedule::fromArray(['registration_cents' => 3500]);
    assert_eq(3500, $f->registrationCents());
    assert_false($f->registrationIsFree());
});

// ── Recital performance fee ───────────────────────────────────────────

unit('recital fee tapers after the first child', function () {
    $f = FeeSchedule::default();
    assert_eq(0,     $f->recitalFeeForFamily(0), 'no children, no fee');
    assert_eq(10000, $f->recitalFeeForFamily(1));
    assert_eq(15000, $f->recitalFeeForFamily(2), '$100 + $50');
    assert_eq(20000, $f->recitalFeeForFamily(3), '$100 + $50 + $50');
});

unit('a negative child count cannot produce a charge', function () {
    assert_eq(0, FeeSchedule::default()->recitalFeeForFamily(-2));
});

unit('the breakdown re-sums to the family total', function () {
    $f = FeeSchedule::default();
    foreach ([0, 1, 2, 5] as $n) {
        assert_eq($f->recitalFeeForFamily($n), array_sum($f->recitalFeeBreakdown($n)),
            "breakdown must reconcile for $n children");
    }
});

unit('recital due date anchors to the season year', function () {
    assert_eq('2027-05-01', FeeSchedule::default()->recitalDueDate(2027));
});

// ── Costume exemptions ────────────────────────────────────────────────

unit('technique classes never carry a costume fee', function () {
    $f = FeeSchedule::default();
    assert_true($f->costumeExempt('technique'));
    assert_true($f->costumeExempt('Leaps and Turns Technique', 6),
        'the exemption is on the style containing "technique", at any age');
});

unit('ballet is exempt only from age 8 up', function () {
    $f = FeeSchedule::default();
    assert_true($f->costumeExempt('ballet', 8));
    assert_true($f->costumeExempt('ballet', 12));
    assert_false($f->costumeExempt('ballet', 7), 'the little ones still costume');
    assert_false($f->costumeExempt('ballet', 4));
});

unit('an unknown age is charged, not silently exempted', function () {
    $f = FeeSchedule::default();
    assert_false($f->costumeExempt('ballet', null),
        'under-billing a whole class is worse than a refundable overcharge');
});

unit('ordinary styles always costume', function () {
    $f = FeeSchedule::default();
    foreach (['jazz', 'tap', 'hiphop', 'musical theatre', 'contemporary'] as $s) {
        assert_false($f->costumeExempt($s, 14), "$s should costume");
    }
});

// ── Costume totals ────────────────────────────────────────────────────

unit('costume total charges per class and tights once', function () {
    $f = FeeSchedule::default();
    $total = $f->costumeTotalForStudent([
        ['style' => 'jazz', 'age_min' => 9],
        ['style' => 'tap',  'age_min' => 9],
    ]);
    assert_eq(20000, $total, '2 x $95 + $10 tights, tights NOT doubled');
});

unit('exempt classes drop out of the costume total', function () {
    $f = FeeSchedule::default();
    $total = $f->costumeTotalForStudent([
        ['style' => 'jazz',      'age_min' => 10],
        ['style' => 'ballet',    'age_min' => 10],   // exempt, 8 & up
        ['style' => 'technique', 'age_min' => 10],   // exempt
    ]);
    assert_eq(10500, $total, 'one costumed class + tights');
});

unit('a fully exempt student owes nothing, tights included', function () {
    $f = FeeSchedule::default();
    $total = $f->costumeTotalForStudent([
        ['style' => 'ballet',    'age_min' => 11],
        ['style' => 'technique', 'age_min' => 11],
    ]);
    assert_eq(0, $total, 'tights exist to go under a costume');
});

unit('tights can be excluded for a student who already has a pair', function () {
    $f = FeeSchedule::default();
    assert_eq(9500, $f->costumeTotalForStudent([['style' => 'jazz', 'age_min' => 9]], false));
});

// ── Instalments — the money-splitting ─────────────────────────────────

unit('costume splits 50/50 across November and February', function () {
    $parts = FeeSchedule::default()->splitCostume(20000, 2026);
    assert_eq(2, count($parts));
    assert_eq('2026-11-01', $parts[0]['due_date']);
    assert_eq('2027-02-01', $parts[1]['due_date'], 'February falls in the FOLLOWING year');
    assert_eq(10000, $parts[0]['amount_cents']);
    assert_eq(10000, $parts[1]['amount_cents']);
});

unit('an odd total never loses or invents a cent', function () {
    $f = FeeSchedule::default();
    foreach ([1, 3, 99, 10500, 10501, 33333] as $total) {
        $parts = $f->splitCostume($total, 2026);
        assert_eq($total, array_sum(array_column($parts, 'amount_cents')),
            "instalments must re-sum to $total exactly");
    }
});

unit('the rounding remainder lands on the first instalment', function () {
    $parts = FeeSchedule::default()->splitCostume(10501, 2026);
    assert_eq(5251, $parts[0]['amount_cents']);
    assert_eq(5250, $parts[1]['amount_cents']);
});

unit('a zero total produces no instalments to chase', function () {
    assert_eq([], FeeSchedule::default()->splitCostume(0, 2026));
});

unit('instalments are numbered for display', function () {
    $parts = FeeSchedule::default()->splitCostume(20000, 2026);
    assert_eq(1, $parts[0]['instalment_no']);
    assert_eq(2, $parts[1]['instalment_no']);
    assert_eq(2, $parts[0]['instalment_of']);
});

// ── Configuration ─────────────────────────────────────────────────────

unit('a studio can reconfigure every fee', function () {
    $f = FeeSchedule::fromArray([
        'registration_cents'       => 2500,
        'recital_first_cents'      => 12000,
        'recital_additional_cents' => 6000,
        'recital_due'              => '4-15',
        'costume_cents'            => 8000,
        'tights_cents'             => 1200,
    ]);
    assert_eq(2500,  $f->registrationCents());
    assert_eq(18000, $f->recitalFeeForFamily(2));
    assert_eq('2027-04-15', $f->recitalDueDate(2027), 'a terse M-D still normalises');
    assert_eq(9200,  $f->costumeTotalForStudent([['style' => 'jazz', 'age_min' => 9]]));
});

unit('a three-way instalment plan splits and dates correctly', function () {
    $f = FeeSchedule::fromArray(['costume_instalments' => [
        ['month_day' => '09-01', 'percent' => 50],
        ['month_day' => '12-01', 'percent' => 25],
        ['month_day' => '03-01', 'percent' => 25],
    ]]);
    $parts = $f->splitCostume(20000, 2026);
    assert_eq(3, count($parts));
    assert_eq(['2026-09-01', '2026-12-01', '2027-03-01'], array_column($parts, 'due_date'));
    assert_eq([10000, 5000, 5000], array_column($parts, 'amount_cents'));
});

unit('the terse instalment form is accepted', function () {
    $f = FeeSchedule::fromArray(['costume_instalments' => ['11-01' => 50, '02-01' => 50]]);
    assert_eq(2, count($f->costumeInstalments()));
});

unit('an instalment plan that does not total 100% is rejected, not applied', function () {
    $f = FeeSchedule::fromArray(['costume_instalments' => [
        ['month_day' => '11-01', 'percent' => 50],
        ['month_day' => '02-01', 'percent' => 40],   // 90% — under-bills
    ]]);
    assert_eq(FeeSchedule::default()->costumeInstalments(), $f->costumeInstalments(),
        'a wrong plan must fall back, never silently bill 90%');
});

unit('unparseable settings fall back per key rather than throwing', function () {
    $f = FeeSchedule::fromArray([
        'costume_cents'       => 'ninety-five',
        'tights_cents'        => '',
        'recital_due'         => '99-99',
        'costume_instalments' => 'not json',
    ]);
    $d = FeeSchedule::default();
    assert_eq($d->costumeCents(), $f->costumeCents());
    assert_eq($d->tightsCents(),  $f->tightsCents());
    assert_eq('2027-05-01',       $f->recitalDueDate(2027));
    assert_eq($d->costumeInstalments(), $f->costumeInstalments());
});

unit('negative configured fees clamp to zero', function () {
    $f = FeeSchedule::fromArray(['costume_cents' => -500, 'recital_first_cents' => -1]);
    assert_eq(0, $f->costumeCents());
    assert_eq(0, $f->recitalFeeForFamily(1));
});
