<?php
/**
 * Slate — EmailAddress value object.
 *
 * The normalized email is the primary dedup/match key for Contacts (identity
 * doc §5.1). Normalization = lower-case + trim; validation is RFC-ish via
 * filter_var. Immutable; depends only on Support-level PHP built-ins.
 *
 * Layer: Domain\Identity (depends on nothing but the language).
 */

declare(strict_types=1);

namespace Slate\Domain\Identity;

final class EmailAddress
{
    private function __construct(public readonly string $value) {}

    /** Canonical form used everywhere as the match key: lower-cased, trimmed. */
    public static function normalize(string $raw): string
    {
        return strtolower(trim($raw));
    }

    public static function fromString(string $raw): self
    {
        $normalized = self::normalize($raw);
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException("Invalid email address: '{$raw}'.");
        }
        return new self($normalized);
    }

    /** Null instead of throwing — for match paths where a bad email just means "no hit". */
    public static function tryFrom(string $raw): ?self
    {
        try {
            return self::fromString($raw);
        } catch (\InvalidArgumentException) {
            return null;
        }
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
