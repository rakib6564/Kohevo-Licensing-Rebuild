<?php
declare(strict_types=1);

namespace Slate\Services\Content;

use Slate\Data\Repository;
final class DependencyStore extends Repository
{
    protected string $table = 'content_dependencies';

    /** @param list<string> $dependencies */
    public function replace(string $ownerType, int $ownerId, int $revisionId, array $dependencies): void
    {
        $this->query()->where('owner_type', $ownerType)->where('owner_id', $ownerId)->where('revision_id', $revisionId)->delete();
        foreach (array_values(array_unique(array_map('strval', $dependencies))) as $dependency) {
            $this->insert([
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'revision_id' => $revisionId,
                'dependency_key' => $dependency,
            ]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function dependents(string $dependencyKey): array
    {
        return $this->query()->where('dependency_key', $dependencyKey)->get();
    }
}
