<?php
declare(strict_types=1);

namespace Slate\Services\Content;

use Slate\Data\Repository;

final class CompilationStore extends Repository
{
    protected string $table = 'content_compilations';

    public function put(string $ownerType, int $ownerId, int $revisionId, string $html, string $fingerprint, string $rendererVersion, string $themeVersion): int
    {
        $existing = $this->query()->where('owner_type', $ownerType)->where('owner_id', $ownerId)->where('revision_id', $revisionId)->first();
        $data = [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'revision_id' => $revisionId,
            'content_html' => $html,
            'content_fingerprint' => $fingerprint,
            'renderer_version' => $rendererVersion,
            'theme_version' => $themeVersion,
        ];
        if ($existing !== null) {
            $this->query()->where('id', (int)$existing['id'])->update($data);
            return (int)$existing['id'];
        }
        return $this->insert($data);
    }

    public function latest(string $ownerType, int $ownerId): ?array
    {
        return $this->query()->where('owner_type', $ownerType)->where('owner_id', $ownerId)->orderBy('revision_id', 'DESC')->first();
    }

    public function fresh(string $ownerType, int $ownerId, string $fingerprint, string $rendererVersion, string $themeVersion): ?array
    {
        $row = $this->latest($ownerType, $ownerId);
        if ($row === null || $row['content_fingerprint'] !== $fingerprint || $row['renderer_version'] !== $rendererVersion || $row['theme_version'] !== $themeVersion) return null;
        return $row;
    }
}
