<?php
/**
 * Studio — TuitionCalculator (pure Money math).
 *
 * Owns the studio's discount + proration policy. Membership has NO proration of
 * its own, so Studio computes tuition here and only hands a final figure to the
 * billing adapters (Batch 3+). Pure and dependency-light: takes plain series
 * rows / ints, returns Slate\Support\Money. Unit-tested in isolation (no DB).
 *
 * Discount policy (multiplicative, applied in order):
 *   1. Multi-class: the more concurrent classes ONE student takes, the cheaper
 *      each additional class.
 *   2. Family sibling: households with more enrolled children get a further
 *      break per class.
 *
 * The rates live in DiscountPolicy so a studio can set its own on the Settings
 * page. Passing no policy uses the shipped defaults (2 → 10% off, 3 → 15%,
 * 4+ → 20%; 1 sibling → 10%, 2+ → 15%), which is what every existing caller
 * gets and why this change is invisible to them.
 *
 * Money arithmetic uses ->times() (ADR-0011: integer minor units, half-up
 * rounding), never floats-as-money.
 */

declare(strict_types=1);

namespace Slate\Module\Studio\Domain;

use Slate\Support\Money;

final class TuitionCalculator
{
    /**
     * Tuition for one class series for one student.
     *
     * @param array $series                 A studio_class_series row (needs price_cents + currency).
     * @param int   $activeCountForStudent  How many classes this student is (or will be) enrolled in.
     * @param int   $siblingCount           Other children from the same family also enrolled.
     */
    public function calculate(array $series, int $activeCountForStudent, int $siblingCount, ?DiscountPolicy $policy = null): Money
    {
        // Omitting the policy keeps the rates this class shipped with, so every
        // existing caller (and every existing test) behaves identically.
        $policy = $policy ?? DiscountPolicy::default();

        $base = new Money((int) $series['price_cents'], (string) ($series['currency'] ?? 'USD'));

        // 1. Multi-class discount (this student).
        $base = $base->times($policy->multiClassFactor($activeCountForStudent));

        // 2. Family sibling discount — multiplicative on the already-discounted
        //    figure, which is what "stacked" has always meant here.
        return $base->times($policy->siblingFactor($siblingCount));
    }

    /**
     * Prorate a full-session tuition for a mid-session enrollment.
     *
     * Fraction = remaining days / total session days, clamped to [0, 1]. If the
     * session hasn't started, the caller passes "now" before session_start and
     * gets the full amount; once it's over, zero.
     */
    public function prorate(
        Money $full,
        \DateTimeImmutable $sessionStart,
        \DateTimeImmutable $sessionEnd,
        ?\DateTimeImmutable $asOf = null
    ): Money {
        $asOf = $asOf ?? $sessionStart;

        $totalDays = (int) $sessionStart->diff($sessionEnd)->days;
        if ($totalDays <= 0) {
            return $full;
        }

        if ($asOf <= $sessionStart) {
            return $full;
        }
        if ($asOf >= $sessionEnd) {
            return $full->times(0.0);
        }

        $remainingDays = (int) $asOf->diff($sessionEnd)->days;
        $fraction      = min(1.0, max(0.0, $remainingDays / $totalDays));

        return $full->times($fraction);
    }
}
