<?php
/**
 * Slate — Identity domain model (a portal login linkage).
 *
 * An Identity is the credential by which a Contact logs into the portal
 * (identity doc §4). One Contact may have several (password + OAuth later); many
 * Contacts (guests, orgs) have none. The presence of an Identity is exactly what
 * makes a Contact "registered" vs "guest".
 *
 * Read-model only — it deliberately does NOT expose `password_hash`; secrets stay
 * inside IdentityStore. `email_verified` is a soft flag, never an authz gate.
 *
 * Layer: Domain\Identity (Support-only).
 */

declare(strict_types=1);

namespace Slate\Domain\Identity;

final class Identity
{
    public const PROVIDER_PASSWORD = 'password';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    public function __construct(
        public readonly int $id,
        public readonly int $contactId,
        public readonly int $tenantId,
        public readonly string $provider,
        public readonly string $credentialRef,
        public readonly bool $emailVerified,
        public readonly string $status,
        public readonly ?string $lastLoginAt,
    ) {}

    /** Hydrate from an `identities` row (password_hash intentionally ignored). */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['contact_id'],
            (int) $row['tenant_id'],
            (string) $row['provider'],
            (string) $row['credential_ref'],
            !empty($row['email_verified']),
            (string) ($row['status'] ?? self::STATUS_ACTIVE),
            $row['last_login_at'] ?? null,
        );
    }

    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }
}
