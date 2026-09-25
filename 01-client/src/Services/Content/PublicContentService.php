<?php
declare(strict_types=1);

namespace Slate\Services\Content;

use Slate\Presentation\CompilationMetadata;
use Slate\Presentation\ContentCompiler;
use Slate\Presentation\RenderContext;

final class PublicContentService
{
    public function __construct(
        private readonly CompilationStore $compilations,
        private readonly ContentCompiler $compiler,
        private readonly string $rendererVersion = '1.0.0',
    ) {}

    /** @param array<string,mixed> $document @param list<string> $dependencies @return array{html:string,cache_hit:bool,metadata:array<string,mixed>} */
    public function render(string $ownerType, int $ownerId, int $revisionId, array $document, RenderContext $context, string $themeVersion, array $dependencies = []): array
    {
        $metadata = CompilationMetadata::for($document, $this->rendererVersion, $themeVersion, $dependencies);
        $fresh = $this->compilations->fresh($ownerType, $ownerId, $metadata->fingerprint, $this->rendererVersion, $themeVersion);
        if ($fresh !== null && (int)$fresh['revision_id'] === $revisionId) {
            return ['html' => (string)$fresh['content_html'], 'cache_hit' => true, 'metadata' => $metadata->jsonSerialize()];
        }
        $html = $this->compiler->compile($document, $context);
        $this->compilations->put($ownerType, $ownerId, $revisionId, $html, $metadata->fingerprint, $this->rendererVersion, $themeVersion);
        return ['html' => $html, 'cache_hit' => false, 'metadata' => $metadata->jsonSerialize()];
    }
}
