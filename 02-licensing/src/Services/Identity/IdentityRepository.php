<?php

declare(strict_types=1);

namespace Slate\Services\Identity;

use Slate\Data\Repository;
use Slate\Domain\Identity\Identity;

/** Tenant-scoped persistence boundary for identities. */
final class IdentityRepository extends Repository
{
    protected string $table = 'identities';

    public function findByCredential(string $provider, string $credentialRef): ?array
    {
        return $this->query()
            ->where('provider', $provider)
            ->where('credential_ref', $credentialRef)
            ->first();
    }

    public function activeById(int $id): ?array
    {
        return $this->query()->where('id', $id)->where('status', Identity::STATUS_ACTIVE)->first();
    }

    public function contactId(int $id): ?int
    {
        $row = $this->query()->select('contact_id')->where('id', $id)->first();
        return $row === null ? null : (int) $row['contact_id'];
    }

    public function updateCredentials(int $id, string $passwordHash): int
    {
        return $this->query()->where('id', $id)->update(['password_hash' => $passwordHash]);
    }

    public function stampLastLogin(int $id, string $timestamp): int
    {
        return $this->query()->where('id', $id)->update(['last_login_at' => $timestamp]);
    }

    public function markEmailVerified(int $id): int
    {
        return $this->query()->where('id', $id)->update(['email_verified' => 1]);
    }

    public function suspend(int $id): int
    {
        return $this->query()->where('id', $id)->update(['status' => Identity::STATUS_SUSPENDED]);
    }
}
