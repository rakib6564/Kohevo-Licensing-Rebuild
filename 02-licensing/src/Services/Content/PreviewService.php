<?php
declare(strict_types=1);

namespace Slate\Services\Content;

use InvalidArgumentException;
use Slate\Presentation\ContentCompiler;
use Slate\Presentation\DocumentValidator;
use Slate\Presentation\RenderContext;

final class PreviewService
{
    /** @param array<string,array<string,mixed>> $registry */
    public function __construct(
        private readonly ContentCompiler $compiler,
        private readonly array $registry,
    ) {}

    /** @param string|array|null $document @return array{html:string,headers:array<string,string>,document:array} */
    public function render(string|array|null $document, RenderContext $context): array
    {
        $result = DocumentValidator::validate($document, $this->registry);
        if (!$result['valid']) throw new InvalidArgumentException(json_encode($result['errors'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return [
            'html' => $this->compiler->compile($result['document'], $context),
            'headers' => ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store'],
            'document' => $result['document'],
        ];
    }
}
