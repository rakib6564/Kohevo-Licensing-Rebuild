<?php
/**
 * Slate — Money value object (integer minor units + currency).
 *
 * The canonical platform money type (ADR-0011): money is represented end to end
 * as **integer minor units** (e.g. cents) plus an ISO-4217 currency code. Floats
 * and DECIMAL are prohibited for money — they invite rounding drift and don't
 * match payment providers, which are cents-based.
 *
 * Immutable value object (platform-foundation §5): no setters; every operation
 * returns a new instance. Depends on nothing (Support is the pure leaf layer).
 *
 * Arithmetic is exponent-agnostic — it operates on raw minor units, so it is
 * correct regardless of how many decimal places a currency has. The exponent is
 * only consulted for human-facing decimal formatting.
 */

declare(strict_types=1);

namespace Slate\Support;

final class Money implements \JsonSerializable
{
    /**
     * Currencies whose minor unit is NOT 1/100 of the major unit. Anything not
     * listed defaults to 2 decimal places. Used for formatting only.
     */
    private const EXPONENTS = [
        // Zero-decimal currencies (the minor unit IS the major unit).
        'JPY' => 0, 'KRW' => 0, 'VND' => 0, 'CLP' => 0, 'ISK' => 0,
        'XAF' => 0, 'XOF' => 0, 'XPF' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0,
        // Three-decimal currencies.
        'BHD' => 3, 'KWD' => 3, 'OMR' => 3, 'TND' => 3, 'JOD' => 3, 'IQD' => 3, 'LYD' => 3,
    ];

    public function __construct(
        public readonly int $minor,
        public readonly string $currency,
    ) {
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException(
                "Money currency must be a 3-letter ISO-4217 code, got '{$currency}'."
            );
        }
    }

    // ── Construction ──────────────────────────────────────────

    /** Primary factory: minor units + currency (currency normalised to upper-case). */
    public static function of(int $minor, string $currency): self
    {
        return new self($minor, strtoupper($currency));
    }

    /** Zero in the given currency. */
    public static function zero(string $currency): self
    {
        return self::of(0, $currency);
    }

    /**
     * Build from a decimal major-unit amount (string or float), rounding to the
     * currency's minor unit. Prefer string input to avoid float artefacts at the
     * boundary. e.g. fromDecimal('12.34', 'USD') → 1234 minor.
     */
    public static function fromDecimal(string|float $amount, string $currency): self
    {
        $currency = strtoupper($currency);
        $exp      = self::exponentFor($currency);
        $minor    = (int) round(((float) $amount) * (10 ** $exp), 0, PHP_ROUND_HALF_UP);
        return new self($minor, $currency);
    }

    /** Rehydrate from the {amount, currency} shape (API payloads, JSON columns). */
    public static function fromArray(array $data): self
    {
        if (!array_key_exists('amount', $data) || !array_key_exists('currency', $data)) {
            throw new \InvalidArgumentException("Money array requires 'amount' and 'currency' keys.");
        }
        return self::of((int) $data['amount'], (string) $data['currency']);
    }

    // ── Accessors ─────────────────────────────────────────────

    public function minor(): int { return $this->minor; }
    public function currency(): string { return $this->currency; }

    /** Decimal places for this money's currency (2 unless overridden). */
    public function exponent(): int { return self::exponentFor($this->currency); }

    // ── Arithmetic (all return new instances) ─────────────────

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->minor - $other->minor, $this->currency);
    }

    /**
     * Multiply by a scalar factor, rounding the result to whole minor units.
     * e.g. tax, discount, quantity. Rounding mode defaults to half-up.
     */
    public function times(int|float $factor, int $roundingMode = PHP_ROUND_HALF_UP): self
    {
        $minor = (int) round($this->minor * $factor, 0, $roundingMode);
        return new self($minor, $this->currency);
    }

    public function negated(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    public function abs(): self
    {
        return new self($this->minor < 0 ? -$this->minor : $this->minor, $this->currency);
    }

    /** Immutable wither: same currency, replaced minor amount. */
    public function withMinor(int $minor): self
    {
        return new self($minor, $this->currency);
    }

    // ── Comparison ────────────────────────────────────────────

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    /** -1 / 0 / 1. Throws on currency mismatch. */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);
        return $this->minor <=> $other->minor;
    }

    public function lessThan(self $other): bool           { return $this->compareTo($other) < 0; }
    public function lessThanOrEqual(self $other): bool     { return $this->compareTo($other) <= 0; }
    public function greaterThan(self $other): bool         { return $this->compareTo($other) > 0; }
    public function greaterThanOrEqual(self $other): bool  { return $this->compareTo($other) >= 0; }

    public function isZero(): bool     { return $this->minor === 0; }
    public function isPositive(): bool { return $this->minor > 0; }
    public function isNegative(): bool { return $this->minor < 0; }

    // ── Serialisation / formatting ────────────────────────────

    /** The covered wire shape (ADR-0011): a {amount, currency} object, never a scalar. */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->minor, 'currency' => $this->currency];
    }

    /** Same shape as jsonSerialize(), for non-JSON callers. */
    public function toArray(): array
    {
        return $this->jsonSerialize();
    }

    /** Plain decimal string using the currency's exponent, e.g. "1234" USD → "12.34". */
    public function toDecimal(): string
    {
        $exp = $this->exponent();
        if ($exp === 0) {
            return (string) $this->minor;
        }
        $neg   = $this->minor < 0;
        $abs   = $neg ? -$this->minor : $this->minor;
        $divisor = 10 ** $exp;
        $whole = intdiv($abs, $divisor);
        $frac  = $abs % $divisor;
        return ($neg ? '-' : '') . $whole . '.' . str_pad((string) $frac, $exp, '0', STR_PAD_LEFT);
    }

    public function __toString(): string
    {
        return $this->currency . ' ' . $this->toDecimal();
    }

    // ── Internals ─────────────────────────────────────────────

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException(
                "Cannot operate on Money of different currencies: {$this->currency} vs {$other->currency}."
            );
        }
    }

    private static function exponentFor(string $currency): int
    {
        return self::EXPONENTS[$currency] ?? 2;
    }
}
