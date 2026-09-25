<?php
declare(strict_types=1);

namespace Slate\Presentation;

/** Immutable metadata for a derived content_html artifact. */
final class CompilationMetadata implements \JsonSerializable
{
    /** @param list<string> $dependencies */
    public function __construct(
        public readonly string $fingerprint,
        public readonly string $rendererVersion,
        public readonly string $themeVersion,
        public readonly array $dependencies,
    ) {}

    /** @param array<string,mixed> $document @param list<string> $dependencies */
    public static function for(array $document, string $rendererVersion, string $themeVersion, array $dependencies): self
    {
        $dependencies = array_values(array_unique(array_map('strval', $dependencies)));
        sort($dependencies);
        $payload = json_encode([
            'document' => $document,
            'renderer' => $rendererVersion,
            'theme' => $themeVersion,
            'dependencies' => $dependencies,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return new self(hash('sha256', $payload), $rendererVersion, $themeVersion, $dependencies);
    }

    public function jsonSerialize(): array
    {
        return [
            'content_fingerprint' => $this->fingerprint,
            'renderer_version' => $this->rendererVersion,
            'theme_version' => $this->themeVersion,
            'dependencies' => $this->dependencies,
        ];
    }
}
