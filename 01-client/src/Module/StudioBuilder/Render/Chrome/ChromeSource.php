<?php
/**
 * Kohevo Studio (studio-builder) — The resolved source of one chrome region (header / footer).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Chrome;

final class ChromeSource
{
    public const HIDDEN  = 'hidden';
    public const BUILTIN = 'builtin';
    public const PARTIAL = 'partial';

    private function __construct(
        public readonly string $kind,
        public readonly ?int $revisionId = null,
        public readonly ?string $documentJson = null,
    ) {}

    public static function hidden(): self
    {
        return new self(self::HIDDEN);
    }

    public static function builtin(): self
    {
        return new self(self::BUILTIN);
    }

    public static function partial(int $revisionId, string $documentJson): self
    {
        return new self(self::PARTIAL, $revisionId, $documentJson);
    }

    /** Identity of this source for the compilation fingerprint (a republished partial changes it). */
    public function key(): string
    {
        return $this->kind . ':' . ($this->revisionId ?? '-') . ':' . ($this->documentJson !== null ? hash('sha256', $this->documentJson) : '-');
    }
}
