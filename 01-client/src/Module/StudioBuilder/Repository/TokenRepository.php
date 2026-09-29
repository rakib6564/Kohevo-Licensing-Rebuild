<?php
/**
 * Kohevo Studio — Token Repository (`studiobuilder_tokens`).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Repository;

final class TokenRepository extends StudioRepository
{
    protected string $table = 'studiobuilder_tokens';

    /**
     * Find a Studio design token set by token_group within the active tenant scope.
     */
    public function findByGroupKey(string $tokenGroup = 'default'): ?array
    {
        return $this->query()
            ->where('token_group', $tokenGroup)
            ->first();
    }
}
