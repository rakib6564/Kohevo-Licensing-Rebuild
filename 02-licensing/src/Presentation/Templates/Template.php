<?php
/**
 * Slate — Template contract (Presentation).
 *
 * A Template owns the document FRAME a Page renders into (docs/05-Rendering/
 * theme-and-template-engine.md §1): the html/head assembly, header/footer chrome,
 * and the named regions Sections slot into. It is selected content-type-aware in
 * the pipeline (a 'product' page picks the storefront template; a 'landing' page
 * the minimal one) — rendering-pipeline.md §4.
 *
 *   name()     'document', 'landing', 'storefront', 'embed-bare'
 *   regions()  the region names this frame exposes (e.g. head, header, content, footer)
 *   render()   place the RegionContent into the frame → a full document string
 *
 * One frame for all surfaces (admin, public, storefront, email) — no bespoke
 * documents. Public API surface (platform-foundation §8). Concrete: DocumentTemplate.
 */

declare(strict_types=1);

namespace Slate\Presentation\Templates;

use Slate\Presentation\RenderContext;

interface Template
{
    /** Stable template name, unique within the TemplateResolver. */
    public function name(): string;

    /**
     * The region names this frame fills.
     * @return list<string>
     */
    public function regions(): array;

    /** Assemble the full document (or bare frame) from the region content. */
    public function render(RegionContent $regions, RenderContext $ctx): string;
}
