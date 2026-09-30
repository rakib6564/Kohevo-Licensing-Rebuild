<?php
/**
 * Kohevo Studio (studio-builder) — Proof-Carrying Validated Document.
 *
 * Phase 2 finding: `StudioRevisionService::createDraftRevision()` accepted a
 * plain `array $normalizedDocument` and documented, but did not enforce, that
 * it already passed `DocumentNormalizer::validateAndNormalize()`. A plain
 * array cannot prove where it came from, so nothing stopped a future caller
 * from handing it an unvalidated document.
 *
 * `ValidatedDocument` closes that gap structurally rather than by
 * convention: its constructor is private, so the ONLY way to obtain an
 * instance is `ValidatedDocument::from()`, which always runs the document
 * through `DocumentNormalizer::validateAndNormalize()` first. A method that
 * requires a `ValidatedDocument` (not `array`) cannot be called with
 * something that skipped validation — the type system, not caller
 * discipline, is what enforces it.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

use Slate\Module\StudioBuilder\Registry\BlockRegistry;

final class ValidatedDocument
{
    /**
     * @param array<string, mixed> $document Already validated and normalized.
     */
    private function __construct(
        private readonly array $document,
    ) {}

    /**
     * The only constructor. Runs `$document` through
     * `DocumentNormalizer::validateAndNormalize()` and throws
     * `StudioValidationException` (unchanged, propagated) on any failure.
     *
     * @param string|array<string, mixed> $document
     * @param array<string, mixed>        $validationOptions
     */
    public static function from(
        string|array $document,
        BlockRegistry $registry,
        array $validationOptions = [],
    ): self {
        return new self(DocumentNormalizer::validateAndNormalize($document, $registry, $validationOptions));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->document;
    }
}
