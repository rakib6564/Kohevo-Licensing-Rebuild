<?php
/**
 * Slate — Contact domain model (the canonical party).
 *
 * One row = one real-world person (organization reserved for 2B). Carries only
 * facts true everywhere — name, primary email/phone, kind, lifecycle status —
 * never module-specific data (that lives in profiles keyed by contact_id).
 * Immutable read-model; depends on nothing but the language (Domain → Support only).
 *
 * "Guest vs registered" is NOT a property of Contact — it is the presence of an
 * Identity (identity doc §4), answered by IdentityStore, so a Contact never needs
 * re-creating on registration.
 *
 * Layer: Domain\Identity.
 */

declare(strict_types=1);

namespace Slate\Domain\Identity;

final class Contact
{
    public const KIND_PERSON = 'person';
    public const KIND_ORGANIZATION = 'organization';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_MERGED = 'merged';

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly string $kind,
        public readonly ?string $displayName,
        public readonly ?string $primaryEmail,
        public readonly ?string $primaryPhone,
        public readonly string $status,
    ) {}

    /** Hydrate from a `contacts` row. */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (string) ($row['kind'] ?? self::KIND_PERSON),
            $row['display_name'] ?? null,
            $row['primary_email'] ?? null,
            $row['primary_phone'] ?? null,
            (string) ($row['status'] ?? self::STATUS_ACTIVE),
        );
    }

    public function isPerson(): bool { return $this->kind === self::KIND_PERSON; }
    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }
}
