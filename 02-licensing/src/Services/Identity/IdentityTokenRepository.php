<?php

declare(strict_types=1);

namespace Slate\Services\Identity;

use Slate\Data\Repository;

/** Tenant-scoped persistence boundary for single-use identity tokens. */
final class IdentityTokenRepository extends Repository
{
    protected string $table = 'identity_tokens';

    public function supersedeOutstanding(int $identityId, string $purpose, string $usedAt): int
    {
        return $this->query()
            ->where('identity_id', $identityId)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->update(['used_at' => $usedAt]);
    }

    public function findConsumable(string $tokenHash, string $purpose, string $now): ?array
    {
        return $this->query()
            ->where('token_hash', $tokenHash)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', $now)
            ->first();
    }
}
