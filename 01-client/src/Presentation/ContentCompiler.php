<?php
declare(strict_types=1);

namespace Slate\Presentation;

interface ContentCompiler
{
    /** Compile canonical schema-1 content to a zero-JavaScript HTML artifact. */
    public function compile(array $document, RenderContext $context): string;
}
