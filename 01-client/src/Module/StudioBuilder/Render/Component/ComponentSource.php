<?php
/**
 * Kohevo Studio (studio-builder) — The resolved source of one Global Component reference.
 *
 * Mirrors `Render\Chrome\ChromeSource`: a reference resolves to the component
 * page's PUBLISHED revision (never a draft), to "unpublished" (the component
 * exists but has no published revision yet), or to "missing" (no such
 * non-archived component in the active tenant). Only the published case
 * carries content.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Component;

final class ComponentSource
{
    public const MISSING     = 'missing';
    public const UNPUBLISHED = 'unpublished';
    public const PUBLISHED   = 'published';

    private function __construct(
        public readonly string $ref,
        public readonly string $kind,
        public readonly ?int $pageId = null,
        public readonly ?int $revisionId = null,
        public readonly ?string $documentJson = null,
        public readonly ?string $title = null,
    ) {}

    public static function missing(string $ref): self
    {
        return new self($ref, self::MISSING);
    }

    public static function unpublished(string $ref, int $pageId, string $title): self
    {
        return new self($ref, self::UNPUBLISHED, $pageId, null, null, $title);
    }

    public static function published(string $ref, int $pageId, int $revisionId, string $documentJson, string $title): self
    {
        return new self($ref, self::PUBLISHED, $pageId, $revisionId, $documentJson, $title);
    }

    public function isPublished(): bool
    {
        return $this->kind === self::PUBLISHED && $this->documentJson !== null;
    }

    /** Identity of this source for the compilation fingerprint (a republished component changes it). */
    public function key(): string
    {
        return $this->kind . ':' . ($this->revisionId ?? '-') . ':' . ($this->documentJson !== null ? hash('sha256', $this->documentJson) : '-');
    }
}
