<?php
/**
 * Studio — DiscountPolicy (pure).
 *
 * The multi-class and sibling discount curves, lifted out of TuitionCalculator
 * so a studio can set its own rates without a code change. Every studio prices
 * differently; the previous hardcoded 10/15/20 was Company B's guess baked into
 * the domain.
 *
 * A tier is `threshold => percent off`: [2 => 10, 3 => 15, 4 => 20] means "two
 * concurrent classes take 10% off each, three take 15%, four or more take 20%".
 * The highest threshold at or below the count wins, so gaps are fine and the
 * list needn't be contiguous.
 *
 * Pure and DB-free — StudioAPI loads the numbers from settings and hands them
 * here, which keeps the maths unit-testable with no database.
 */

declare(strict_types=1);

namespace Slate\Module\Studio\Domain;

final class DiscountPolicy
{
    /** @var array<int,float> threshold => percent off */
    private array $multiClass;

    /** @var array<int,float> threshold => percent off */
    private array $sibling;

    /**
     * @param array<int|string,int|float> $multiClass
     * @param array<int|string,int|float> $sibling
     */
    public function __construct(array $multiClass = [], array $sibling = [])
    {
        $this->multiClass = self::normalise($multiClass);
        $this->sibling    = self::normalise($sibling);
    }

    /** The curves Studio shipped with, and what an unconfigured tenant gets. */
    public static function default(): self
    {
        return new self([2 => 10, 3 => 15, 4 => 20], [1 => 10, 2 => 15]);
    }

    /**
     * Build from stored JSON, falling back to the defaults on anything
     * unparseable — a corrupt setting must not stop the studio taking money.
     */
    public static function fromJson(?string $multiJson, ?string $siblingJson): self
    {
        $decode = static function (?string $raw): ?array {
            if ($raw === null || trim($raw) === '') { return null; }
            $v = json_decode($raw, true);
            return is_array($v) ? $v : null;
        };
        $m = $decode($multiJson);
        $s = $decode($siblingJson);
        if ($m === null && $s === null) { return self::default(); }

        $d = self::default();
        return new self($m ?? $d->multiClassTiers(), $s ?? $d->siblingTiers());
    }

    /** Multiplier for a student taking $count concurrent classes (1.0 = no discount). */
    public function multiClassFactor(int $count): float
    {
        return self::factor($this->multiClass, $count);
    }

    /** Multiplier for a family with $count other enrolled children. */
    public function siblingFactor(int $count): float
    {
        return self::factor($this->sibling, $count);
    }

    /** @return array<int,float> threshold => percent off */
    public function multiClassTiers(): array { return $this->multiClass; }

    /** @return array<int,float> threshold => percent off */
    public function siblingTiers(): array { return $this->sibling; }

    // ── internals ─────────────────────────────────────────────

    /**
     * Highest threshold at or below $count decides the rate. Returns a
     * multiplier, so 20% off becomes 0.80.
     *
     * @param array<int,float> $tiers
     */
    private static function factor(array $tiers, int $count): float
    {
        $pct = 0.0;
        foreach ($tiers as $threshold => $percent) {
            if ($count >= $threshold && $percent > $pct) { $pct = $percent; }
        }
        return (100.0 - $pct) / 100.0;
    }

    /**
     * Keep only sane tiers: integer thresholds of 1+, percentages within 0–100.
     * A negative or >100 discount would invert or zero the price, so those are
     * dropped rather than clamped — a typo in settings should lose that tier,
     * not silently charge everyone nothing.
     *
     * @param  array<int|string,int|float> $tiers
     * @return array<int,float>
     */
    private static function normalise(array $tiers): array
    {
        $out = [];
        foreach ($tiers as $threshold => $percent) {
            if (!is_numeric($threshold) || !is_numeric($percent)) { continue; }
            $t = (int) $threshold;
            $p = (float) $percent;
            if ($t < 1 || $p < 0 || $p > 100) { continue; }
            $out[$t] = $p;
        }
        ksort($out);
        return $out;
    }
}
