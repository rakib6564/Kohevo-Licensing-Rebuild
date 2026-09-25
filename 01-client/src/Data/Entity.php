<?php
/**
 * Slate — Entity (hydrated row base).
 *
 * A lightweight, immutable wrapper a Repository hydrates a database row into, so
 * application code works with a typed object instead of a raw associative array
 * (repository-service-pattern §5). This is an explicit extension point: modules
 * subclass it (e.g. `final class Appointment extends Entity`) and add typed
 * accessors — including hydrating money columns to Slate\Support\Money.
 *
 * Kept deliberately small; richer mapping (relations, casts) is a later concern.
 * Layer: Data — depends only on Support.
 */

declare(strict_types=1);

namespace Slate\Data;

class Entity
{
    /** @param array<string,mixed> $attributes the raw row, keyed by column */
    public function __construct(protected readonly array $attributes) {}

    /** Named constructor used by Repository hydration. */
    public static function fromRow(array $row): static
    {
        return new static($row);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    /** Convenience for the near-universal integer primary key. */
    public function id(): ?int
    {
        return isset($this->attributes['id']) ? (int) $this->attributes['id'] : null;
    }

    /** @return array<string,mixed> the underlying row */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
