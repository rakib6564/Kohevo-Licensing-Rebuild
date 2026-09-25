<?php
/**
 * Slate — IdentityStore (Phase 2A concrete service).
 *
 * The service keeps the legacy identity behavior while delegating persistence to
 * tenant-scoped repositories. Password hashes remain internal to this service;
 * Identity models never expose them.
 */

declare(strict_types=1);

namespace Slate\Services\Identity;

use Slate\Domain\Identity\Contact;
use Slate\Domain\Identity\EmailAddress;
use Slate\Domain\Identity\Identity;
use Slate\Tenancy\TenantContext;

final class IdentityStore
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ContactRepository $contacts,
        private readonly ?IdentityRepository $identities = null,
        private readonly ?IdentityTokenRepository $tokens = null,
    ) {}

    private function identityRepository(): IdentityRepository
    {
        return $this->identities ?? new IdentityRepository($this->tenants);
    }

    private function tokenRepository(): IdentityTokenRepository
    {
        return $this->tokens ?? new IdentityTokenRepository($this->tenants);
    }

    public function findByCredential(string $provider, string $credentialRef): ?Identity
    {
        $row = $this->identityRepository()->findByCredential(
            $provider,
            $this->normalizeCredential($provider, $credentialRef)
        );
        return $row === null ? null : Identity::fromRow($row);
    }

    /** The Contact this login IS (identity doc §4). */
    public function contactFor(int $identityId): ?Contact
    {
        $contactId = $this->identityRepository()->contactId($identityId);
        return $contactId === null ? null : $this->contacts->find($contactId);
    }

    public function register(
        int $contactId,
        string $credentialRef,
        string $plainPassword,
        bool $emailVerified = false,
        string $provider = Identity::PROVIDER_PASSWORD,
    ): Identity {
        $id = $this->identityRepository()->insert([
            'contact_id'     => $contactId,
            'provider'       => $provider,
            'credential_ref' => $this->normalizeCredential($provider, $credentialRef),
            'password_hash'  => password_hash($plainPassword, PASSWORD_DEFAULT),
            'email_verified' => $emailVerified ? 1 : 0,
            'status'         => Identity::STATUS_ACTIVE,
        ]);
        $identity = $this->findById($id);
        if ($identity === null) {
            throw new \RuntimeException('IdentityStore::register failed to read back the new identity.');
        }
        return $identity;
    }

    public function authenticate(string $provider, string $credentialRef, string $secret): ?Identity
    {
        $cred = $this->normalizeCredential($provider, $credentialRef);
        $row = $this->identityRepository()->findByCredential($provider, $cred);

        if ($row === null || ($row['status'] ?? '') !== Identity::STATUS_ACTIVE || empty($row['password_hash'])) {
            return null;
        }
        if (!password_verify($secret, (string) $row['password_hash'])) {
            return null;
        }
        $id = (int) $row['id'];
        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
            $this->identityRepository()->updateCredentials($id, password_hash($secret, PASSWORD_DEFAULT));
        }
        $this->identityRepository()->stampLastLogin($id, slate_db_now());
        return $this->findById($id);
    }

    /** Mint a token, store its SHA-256, return the plaintext for the email link. */
    public function issueToken(int $identityId, string $purpose, int $ttlSeconds): string
    {
        // Validate ownership before supersession so a foreign/nonexistent ID can
        // never cause a token write or mutate another tenant's outstanding token.
        if ($this->identityRepository()->find($identityId) === null) {
            throw new \InvalidArgumentException('Identity does not belong to the current tenant.');
        }
        $now = slate_db_now();
        $this->tokenRepository()->supersedeOutstanding($identityId, $purpose, $now);
        $plaintext = bin2hex(random_bytes(32));
        $this->tokenRepository()->insert([
            'identity_id' => $identityId,
            'purpose'     => $purpose,
            'token_hash'  => hash('sha256', $plaintext),
            // PHP's clock on purpose: consumeToken() passes a PHP-built $now to
            // findConsumable(), so both sides are PHP. If that comparison ever
            // moves into SQL (expires_at > NOW()), this must become
            // slate_db_now() — PHP and MySQL do not share a timezone here.
            'expires_at'  => date('Y-m-d H:i:s', time() + $ttlSeconds),
        ]);
        return $plaintext;
    }

    /** Validate + burn a token. Returns the linked Identity, or null if invalid/expired/used. */
    public function consumeToken(string $rawToken, string $purpose): ?Identity
    {
        if ($rawToken === '' || strlen($rawToken) > 128) return null;
        $row = $this->tokenRepository()->findConsumable(hash('sha256', $rawToken), $purpose, slate_db_now());
        if ($row === null) return null;
        $this->tokenRepository()->update((int) $row['id'], ['used_at' => slate_db_now()]);
        return $this->findById((int) $row['identity_id']);
    }

    public function markEmailVerified(int $identityId): void
    {
        $this->identityRepository()->markEmailVerified($identityId);
    }

    public function suspend(int $identityId): void
    {
        $this->identityRepository()->suspend($identityId);
    }

    private function findById(int $identityId): ?Identity
    {
        $row = $this->identityRepository()->find($identityId);
        return $row === null ? null : Identity::fromRow($row);
    }

    private function normalizeCredential(string $provider, string $ref): string
    {
        return $provider === Identity::PROVIDER_PASSWORD ? EmailAddress::normalize($ref) : trim($ref);
    }
}
