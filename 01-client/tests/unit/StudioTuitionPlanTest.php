<?php
/**
 * Studio — TuitionPlan (pure) unit tests.
 *
 * Two things must never go wrong: the instalments must sum to exactly what was
 * quoted, and the cadence must put the charge in the month the studio means.
 * Being a month out is a real dispute with a real parent.
 */

declare(strict_types=1);

use Slate\Module\Studio\Domain\TuitionPlan as TP;

// ── In full: the behaviour Studio had before plans existed ────────────

unit('in full is one charge on the term start', function () {
    $s = TP::inFull()->schedule(8500, '2026-09-07');
    assert_eq(1, count($s));
    assert_eq('2026-09-07', $s[0]['due_date']);
    assert_eq(8500, $s[0]['amount_cents']);
    assert_eq('Tuition', $s[0]['label'], 'no "1 of 1" noise on a single charge');
});

// ── Cadence: which month the money covers ─────────────────────────────

unit('current charges in the month it covers', function () {
    $s = TP::make('current', 3)->schedule(30000, '2026-09-07');
    assert_eq(['2026-09-07', '2026-10-07', '2026-11-07'], array_column($s, 'due_date'));
});

unit('advance charges a month early', function () {
    $s = TP::make('advance', 3)->schedule(30000, '2026-09-07');
    assert_eq(['2026-08-07', '2026-09-07', '2026-10-07'], array_column($s, 'due_date'),
        'August pays for September');
});

unit('arrears charges a month late', function () {
    $s = TP::make('arrears', 3)->schedule(30000, '2026-09-07');
    assert_eq(['2026-10-07', '2026-11-07', '2026-12-07'], array_column($s, 'due_date'),
        'September is paid for in October');
});

unit('an unknown cadence falls back to current rather than guessing', function () {
    assert_eq('current', TP::make('whenever', 2)->cadence());
    assert_eq('current', TP::make(null)->cadence());
    assert_eq('advance', TP::make(' ADVANCE ')->cadence(), 'case and spacing tolerated');
});

// ── The money invariant ───────────────────────────────────────────────

unit('instalments always sum to exactly the total', function () {
    foreach ([1, 2, 3, 4, 7, 12] as $n) {
        foreach ([8500, 12800, 14000, 10001, 99999, 7] as $total) {
            $s   = TP::make('current', $n)->schedule($total, '2026-09-07');
            $sum = 0;
            foreach ($s as $p) { $sum += $p['amount_cents']; }
            assert_eq($total, $sum, "$n instalments of $total must reconcile");
        }
    }
});

unit('the rounding remainder rides on the first instalment', function () {
    $s = TP::make('current', 3)->schedule(10000, '2026-09-07');
    assert_eq([3334, 3333, 3333], array_column($s, 'amount_cents'));
});

unit('a zero total produces no charges at all', function () {
    assert_eq([], TP::make('current', 4)->schedule(0, '2026-09-07'),
        'a fully discounted place must not raise $0.00 invoices');
});

// ── Instalments that would be too small ───────────────────────────────

unit('a small total is not split into unpayable pieces', function () {
    // $5 over twelve months would be 41c a month.
    $n = TP::make('current', 12)->usableInstalments(500);
    assert_eq(1, $n, 'reduced until each part is worth invoicing');
    $s = TP::make('current', 12)->schedule(500, '2026-09-07');
    assert_eq(1, count($s));
    assert_eq(500, $s[0]['amount_cents']);
});

unit('a normal term still splits as asked', function () {
    assert_eq(4, TP::make('current', 4)->usableInstalments(8500), '$21.25 a month is fine');
});

unit('no instalment is ever zero', function () {
    foreach ([1, 50, 499, 500, 1200, 8500] as $total) {
        foreach ([1, 3, 12] as $n) {
            foreach (TP::make('current', $n)->schedule($total, '2026-09-07') as $p) {
                assert_true($p['amount_cents'] > 0, "$total over $n produced a zero line");
            }
        }
    }
});

// ── Month-end dates ───────────────────────────────────────────────────

unit('a 31st start clamps rather than rolling into the next month', function () {
    $s = TP::make('current', 4)->schedule(40000, '2026-01-31');
    assert_eq(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'],
        array_column($s, 'due_date'),
        'PHP\'s native +1 month would have rolled February into March');
});

unit('a leap February is respected', function () {
    $s = TP::make('current', 2)->schedule(20000, '2028-01-31');
    assert_eq('2028-02-29', $s[1]['due_date']);
});

// ── Guards and labels ─────────────────────────────────────────────────

unit('instalment count is clamped to something sane', function () {
    assert_eq(1,  TP::make('current', 0)->instalments());
    assert_eq(1,  TP::make('current', -5)->instalments());
    assert_eq(TP::MAX_INSTALMENTS, TP::make('current', 99)->instalments());
});

unit('an unusable term start yields no schedule, not a wrong one', function () {
    assert_eq([], TP::make('current', 3)->schedule(30000, ''));
    assert_eq([], TP::make('current', 3)->schedule(30000, '2026-02-30'));
});

unit('labels number the instalments for the parent', function () {
    $s = TP::make('current', 3)->schedule(30000, '2026-09-07');
    assert_eq('Tuition (1 of 3)', $s[0]['label']);
    assert_eq('Tuition (3 of 3)', $s[2]['label']);
    assert_eq(3, $s[0]['of']);
});

unit('describe reads as a sentence a studio owner would recognise', function () {
    assert_eq('Paid in full at sign-up', TP::inFull()->describe());
    assert_eq('4 monthly payments, billed a month in advance', TP::make('advance', 4)->describe());
    assert_eq('3 monthly payments, billed for the current month', TP::make('current', 3)->describe());
});
