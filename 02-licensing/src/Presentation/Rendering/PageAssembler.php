<?php
/**
 * Slate — PageAssembler: Page → full document (Presentation, Phase 3B B3).
 *
 * The glue that composes the render stack into one output: render the Page's
 * Sections to the content region (via the Renderer), select the Template
 * (content-type-aware), and place the content — plus any chrome regions — into the
 * frame. The Theme in the RenderContext skins it through the Template's token
 * emission.
 *
 * Surfaces differ only here: an API fragment (surface 'fragment') skips document
 * assembly and returns the bare content, exactly as rendering-pipeline.md §8
 * specifies — same render mechanics, chrome stage skipped.
 *
 * Phase 3B: available and tested, but NOT wired into the live public render path
 * (content-builder/public/render.php still owns production output — the cutover is
 * a separate reviewed step, as in 3A).
 *
 * @internal — orchestration below the Renderer/Template contracts.
 */

declare(strict_types=1);

namespace Slate\Presentation\Rendering;

use Slate\Presentation\Page;
use Slate\Presentation\Renderer;
use Slate\Presentation\RenderContext;
use Slate\Presentation\Templates\RegionContent;
use Slate\Presentation\Templates\TemplateResolver;

final class PageAssembler
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly TemplateResolver $templates,
    ) {}

    /**
     * Render a Page to a full document (or bare fragment). $chrome supplies
     * header/footer/head regions; the content region is filled from the render.
     */
    public function assemble(Page $page, RenderContext $ctx, ?RegionContent $chrome = null): string
    {
        $content = $this->renderer->renderPage($page, $ctx);

        // API fragment: no chrome, no document frame (pipeline §8).
        if ($ctx->isFragment()) {
            return $content;
        }

        $template = $this->templates->resolve($page, $ctx);
        if ($template === null) {
            return $content; // fail-soft: no template registered → content only
        }

        $regions = ($chrome ?? new RegionContent())->with('content', $content);
        return $template->render($regions, $ctx);
    }
}
