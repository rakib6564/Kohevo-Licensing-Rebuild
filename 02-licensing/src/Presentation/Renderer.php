<?php
/**
 * Slate — Renderer contract (Presentation).
 *
 * ONE renderer, one traversal, for CMS pages, storefront, and module public
 * pages — ending the six divergent renderers the audit found (docs/05-Rendering/
 * blocks-and-sections.md §5, rendering-pipeline.md, ADR-0007/0008).
 *
 *   renderPage    → walks a Template + ordered Sections
 *   renderSection → applies a Section's LayoutSpec around its Blocks
 *   renderBlock   → resolves block.type in the BlockRegistry, calls Block::render
 *
 * The Renderer is STORAGE-AGNOSTIC: it consumes the Page/Section value objects
 * and never reads a `contentbuilder_*` (or any) table. Persistence is adapted
 * into Page by the document normalizer (Phase 3A B2); the content-builder bridge
 * (B5) is what feeds existing pages through this contract, byte-parity-gated.
 *
 * Public API surface (platform-foundation §8): part of the Block/Component
 * contract. The concrete implementation lands in Phase 3A B3
 * (Slate\Presentation\Rendering\*).
 */

declare(strict_types=1);

namespace Slate\Presentation;

interface Renderer
{
    /** Render a whole Page (template frame + its Sections) to an HTML document/region. */
    public function renderPage(Page $page, RenderContext $ctx): string;

    /** Render one Section: its LayoutSpec wrapper around its ordered Blocks. */
    public function renderSection(Section $section, RenderContext $ctx): string;

    /**
     * Render one block instance. Resolves $block['type'] in the registry and
     * calls Block::render with normalized props. An unknown type renders soft
     * (an HTML comment), matching content-builder's fail-soft behavior.
     *
     * @param array{type:string,props?:array<string,mixed>} $block
     */
    public function renderBlock(array $block, RenderContext $ctx): string;
}
