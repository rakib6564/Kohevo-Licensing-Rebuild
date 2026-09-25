<?php
declare(strict_types=1);

namespace Slate\Services\Content;

final class CompiledContentReader
{
    public function __construct(private readonly CompilationStore $compilations) {}

    /** Return compiled HTML only when all freshness inputs match; null means compile fallback. */
    public function read(string $ownerType, int $ownerId, string $fingerprint, string $rendererVersion, string $themeVersion): ?string
    {
        $row = $this->compilations->fresh($ownerType, $ownerId, $fingerprint, $rendererVersion, $themeVersion);
        return $row === null ? null : (string)$row['content_html'];
    }
}
