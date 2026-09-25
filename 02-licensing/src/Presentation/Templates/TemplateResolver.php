<?php
/**
 * Slate — TemplateResolver: content-type-aware Template selection (Phase 3B B3).
 *
 * Picks the document skeleton for a Page + surface in a fixed precedence, so a
 * tenant can override without touching code (rendering-pipeline.md §4):
 *
 *   1. Explicit page override   (Page.template pins a name)
 *   2. Content-type default     (type 'product' → 'storefront')
 *   3. Surface default          (surface 'widget' → 'embed-bare')
 *   4. Platform fallback        ('document')
 *
 * This is the seam that lets one engine serve every content type — a new type
 * selects an existing template or registers its own, with NO new renderer. In
 * Phase 3B only 'document' is registered; the precedence is real and tested so the
 * later templates drop in without logic changes.
 *
 * @internal — Presentation selection logic below the Template contract.
 */

declare(strict_types=1);

namespace Slate\Presentation\Templates;

use Slate\Presentation\Page;
use Slate\Presentation\RenderContext;

final class TemplateResolver
{
    /** @var array<string,Template> name => template */
    private array $templates = [];

    /** @var array<string,string> content type => template name */
    private array $byContentType = [];

    /** @var array<string,string> surface => template name */
    private array $bySurface = [];

    private string $fallback = 'document';

    public function register(Template $template): self
    {
        $this->templates[$template->name()] = $template;
        return $this;
    }

    public function mapContentType(string $type, string $templateName): self
    {
        $this->byContentType[$type] = $templateName;
        return $this;
    }

    public function mapSurface(string $surface, string $templateName): self
    {
        $this->bySurface[$surface] = $templateName;
        return $this;
    }

    public function setFallback(string $templateName): self
    {
        $this->fallback = $templateName;
        return $this;
    }

    /** Resolve the Template for a Page + context, or null if none is registered. */
    public function resolve(Page $page, RenderContext $ctx): ?Template
    {
        $candidates = [
            $page->template,                              // 1 explicit page override
            $this->byContentType[$page->type] ?? '',      // 2 content-type default
            $this->bySurface[$ctx->surface] ?? '',        // 3 surface default
            $this->fallback,                              // 4 platform fallback
        ];
        foreach ($candidates as $name) {
            if ($name !== '' && isset($this->templates[$name])) {
                return $this->templates[$name];
            }
        }
        return null;
    }
}
