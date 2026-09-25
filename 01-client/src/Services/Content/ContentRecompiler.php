<?php
declare(strict_types=1);

namespace Slate\Services\Content;

interface ContentRecompiler
{
    /** Recompile one tenant-scoped dependent content owner. */
    public function recompile(string $ownerType, int $ownerId): void;
}
