<?php
declare(strict_types=1);

namespace Slate\Presentation\Rendering;

use Slate\Presentation\ContentCompiler;
use Slate\Presentation\DocumentSchema;
use Slate\Presentation\RenderContext;

final class PageContentCompiler implements ContentCompiler
{
    public function __construct(private readonly PageAssembler $assembler) {}

    public function compile(array $document, RenderContext $context): string
    {
        return $this->assembler->assemble(DocumentSchema::toPage($document, (string)($document['type'] ?? 'page')), $context);
    }
}
