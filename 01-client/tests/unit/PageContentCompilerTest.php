<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/components/load.php';

use Slate\Presentation\FieldSchema;
use Slate\Presentation\RenderContext;
use Slate\Presentation\Rendering\CallbackBlock;
use Slate\Presentation\Rendering\InMemoryBlockRegistry;
use Slate\Presentation\Rendering\PageAssembler;
use Slate\Presentation\Rendering\PageContentCompiler;
use Slate\Presentation\Rendering\PageRenderer;
use Slate\Presentation\Templates\DocumentTemplate;
use Slate\Presentation\Templates\TemplateResolver;

unit('PageContentCompiler renders canonical documents through the shared page assembler', function () {
    $registry = new InMemoryBlockRegistry();
    $registry->register(new CallbackBlock(
        'heading',
        FieldSchema::of([['key' => 'text', 'type' => 'text']], ['text' => '']),
        static fn (array $props, RenderContext $context): string => '<h1>' . htmlspecialchars((string)$props['text'], ENT_QUOTES, 'UTF-8') . '</h1>'
    ));
    $assembler = new PageAssembler(new PageRenderer($registry), (new TemplateResolver())->register(new DocumentTemplate())->setFallback('document'));
    $compiler = new PageContentCompiler($assembler);
    $html = $compiler->compile([
        'schema' => 1,
        'type' => 'page',
        'template' => 'document',
        'sections' => [[
            'id' => 's1',
            'layout' => ['cols' => 1, 'bg' => '', 'pad' => 'normal', 'width' => 'normal'],
            'blocks' => [['type' => 'heading', 'props' => ['text' => 'Parity']]],
        ]],
        'seo' => [],
    ], RenderContext::for(1));
    assert_true(str_starts_with($html, '<!doctype html>'));
    assert_true(str_contains($html, '<h1>Parity</h1>'));
});
