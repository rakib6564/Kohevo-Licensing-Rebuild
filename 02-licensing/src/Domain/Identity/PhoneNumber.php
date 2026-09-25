<?php
/**
 * Slate — PhoneNumber value object.
 *
 * A secondary match key (identity doc §5.1) — restaurant is phone-primary, so
 * phone is first-class, not an afterthought. Normalization is deliberately
 * conservative for Phase 2A: strip formatting to digits, preserve a leading `+`.
 * Full libphonenumber-grade E.164 (region inference) is deferred; the stored form
 * is stable enough to match on and can be upgraded later without a schema change.
 *
 * Layer: Domain\Identity.
 */

declare(strict_types=1);

namespace Slate\Domain\Identity;

final class PhoneNumber
{
    private function __construct(public readonly string $value) {}

    /** Compact match form: leading '+' preserved, all other non-digits removed. */
    public static function normalize(string $raw): string
    {
        $raw = trim($raw);
        $hasPlus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }
        return ($hasPlus ? '+' : '') . $digits;
    }

    /** Null for empty/unparseable input — a missing phone just means "no match". */
    public static function tryFrom(string $raw): ?self
    {
        $normalized = self::normalize($raw);
        return $normalized === '' ? null : new self($normalized);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
