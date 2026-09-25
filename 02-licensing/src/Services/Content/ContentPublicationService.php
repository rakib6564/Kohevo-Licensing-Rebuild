<?php
declare(strict_types=1);

namespace Slate\Services\Content;

use InvalidArgumentException;
use Slate\Data\Database;
use Slate\Presentation\CompilationMetadata;
use Slate\Presentation\ContentCompiler;
use Slate\Presentation\DocumentValidator;
use Slate\Presentation\RenderContext;

final class ContentPublicationService
{
    /** @param array<string,array<string,mixed>> $registry */
    public function __construct(
        private readonly RevisionStore $revisions,
        private readonly CompilationStore $compilations,
        private readonly DependencyStore $dependencies,
        private readonly ContentCompiler $compiler,
        private readonly array $registry,
        private readonly string $rendererVersion = '1.0.0',
    ) {}

    /** @param string|array|null $document @param list<string> $dependencyKeys @return array<string,mixed> */
    public function save(string $ownerType, int $ownerId, string|array|null $document, RenderContext $context, string $themeVersion, array $dependencyKeys = [], ?int $authorId = null, ?string $note = null): array
    {
        $validated = DocumentValidator::validate($document, $this->registry);
        if (!$validated['valid']) throw new InvalidArgumentException(json_encode($validated['errors'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $this->persist($ownerType, $ownerId, $validated['document'], RevisionStore::STATUS_WORKING, $context, $themeVersion, $dependencyKeys, $authorId, $note);
    }

    /** @param list<string> $dependencyKeys @return array<string,mixed> */
    public function publish(string $ownerType, int $ownerId, RenderContext $context, string $themeVersion, array $dependencyKeys = [], ?int $authorId = null): ?array
    {
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $revisionId = $this->revisions->publish($ownerType, $ownerId, $authorId);
            if ($revisionId === null) { $pdo->commit(); return null; }
            $revision = $this->revisions->get($revisionId);
            if ($revision === null) throw new InvalidArgumentException('Published revision was not found after creation.');
            $document = RevisionStore::documentOf($revision);
            $html = $this->compiler->compile($document, $context);
            $metadata = CompilationMetadata::for($document, $this->rendererVersion, $themeVersion, $dependencyKeys);
            $compilationId = $this->compilations->put($ownerType, $ownerId, $revisionId, $html, $metadata->fingerprint, $this->rendererVersion, $themeVersion);
            $this->dependencies->replace($ownerType, $ownerId, $revisionId, $dependencyKeys);
            $pdo->commit();
            return ['revision' => $revision, 'compilation_id' => $compilationId, 'content_html' => $html, 'metadata' => $metadata->jsonSerialize()];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $document @param list<string> $dependencyKeys @return array<string,mixed> */
    private function persist(string $ownerType, int $ownerId, array $document, string $status, RenderContext $context, string $themeVersion, array $dependencyKeys, ?int $authorId, ?string $note): array
    {
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $revisionId = $this->revisions->snapshot($ownerType, $ownerId, $document, $status, $authorId, $note);
            $html = $this->compiler->compile($document, $context);
            $metadata = CompilationMetadata::for($document, $this->rendererVersion, $themeVersion, $dependencyKeys);
            $compilationId = $this->compilations->put($ownerType, $ownerId, $revisionId, $html, $metadata->fingerprint, $this->rendererVersion, $themeVersion);
            $this->dependencies->replace($ownerType, $ownerId, $revisionId, $dependencyKeys);
            $pdo->commit();
            return ['revision_id' => $revisionId, 'compilation_id' => $compilationId, 'document' => $document, 'content_html' => $html, 'metadata' => $metadata->jsonSerialize()];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
