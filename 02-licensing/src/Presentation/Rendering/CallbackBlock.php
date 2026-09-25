<?php
/**
 * Slate — CallbackBlock: a Block built from a type + schema + render closure.
 *
 * A thin, reusable Block implementation so a block need not be its own class.
 * Two uses in Phase 3A:
 *   - tests exercise the Renderer with real blocks without boilerplate;
 *   - the content-builder bridge (B5) wraps each existing array-defined block
 *     (its `tpl`/`render` machinery) as a core Block via this adapter, so the
 *     ~20 existing blocks render through the one Renderer without a rewrite.
 *
 * The render closure receives the NORMALIZED props (the caller applies
 * FieldSchema::normalize first) and the RenderContext, and returns an HTML string.
 *
 * @internal — a convenience implementation of the Block contract.
 */

declare(strict_types=1);

namespace Slate\Presentation\Rendering;

use Slate\Presentation\Block;
use Slate\Presentation\FieldSchema;
use Slate\Presentation\RenderContext;

final class CallbackBlock implements Block
{
    /** @var callable(array<string,mixed>, RenderContext): string */
    private $renderer;

    /** @param callable(array<string,mixed>, RenderContext): string $renderer */
    public function __construct(
        private readonly string $type,
        private readonly FieldSchema $schema,
        callable $renderer,
    ) {
        $this->renderer = $renderer;
    }

    public function type(): string { return $this->type; }

    public function schema(): FieldSchema { return $this->schema; }

    public function render(array $props, RenderContext $ctx): string
    {
        return (string) ($this->renderer)($props, $ctx);
    }
}
