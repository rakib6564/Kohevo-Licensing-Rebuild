<?php
declare(strict_types=1);

namespace Slate\Services\Content;

final class DependencyInvalidationService
{
    public function __construct(
        private readonly DependencyStore $dependencies,
        private readonly ContentRecompiler $recompiler,
    ) {}

    /** Recompile every current-tenant owner that references a changed dependency. */
    public function invalidate(string $dependencyKey): int
    {
        $seen = [];
        $count = 0;
        foreach ($this->dependencies->dependents($dependencyKey) as $row) {
            $key = (string)$row['owner_type'] . ':' . (int)$row['owner_id'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $this->recompiler->recompile((string)$row['owner_type'], (int)$row['owner_id']);
            $count++;
        }
        return $count;
    }
}
