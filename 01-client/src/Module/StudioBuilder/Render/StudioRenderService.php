<?php
/**
 * Kohevo Studio (studio-builder) — Render service (the one pipeline, three runtimes).
 *
 * Preview, Editor and Public all run the identical sequence
 *
 *     compile (static, dynamic deferred) -> fill dynamic nodes -> assemble
 *
 * and differ only in (a) which revision is the source and (b) whether the
 * compiled artifact is persisted:
 *
 *   renderRevision()  — Preview/Editor. Any revision of the page (already
 *                       authorized by StudioApplicationService). Compiled in
 *                       memory and discarded: no write of any kind.
 *   renderPublished() — Public. The page's `published_revision_id` ONLY —
 *                       there is no code path here that reads
 *                       `active_draft_revision_id`. Uses/refreshes the stored
 *                       `published` compilation.
 *
 * This service does not authorize actors; its authoring entry points are only
 * reachable through StudioApplicationService, and its public entry point only
 * through StudioPublicRuntime.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Render\Cache\StudioCacheKey;
use Slate\Module\StudioBuilder\Render\Cache\StudioCachePolicy;
use Slate\Module\StudioBuilder\Render\Compile\CompiledPage;
use Slate\Module\StudioBuilder\Render\Compile\DynamicSlotResolver;
use Slate\Module\StudioBuilder\Render\Compile\StudioCompiler;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;

final class StudioRenderService
{
    public const PUBLIC_PAGE_TYPES = ['page', 'landing'];

    private readonly \Closure $siteFactory;

    public function __construct(
        private readonly RevisionRepository $revisions,
        private readonly StudioCompiler $compiler,
        private readonly DynamicSlotResolver $slots,
        private readonly PageDocumentAssembler $assembler,
        ?\Closure $siteFactory = null,
    ) {
        $this->siteFactory = $siteFactory ?? static fn(): SiteContext => SiteContext::fromEnvironment();
    }

    /** The server-side site context for the CURRENT tenant (never request-derived). */
    public function siteContext(): SiteContext
    {
        return ($this->siteFactory)();
    }

    /**
     * Authoring render of a specific revision (Preview / Editor). No persistence.
     *
     * @param array<string, mixed> $revision a revision row of $page
     */
    public function renderRevision(PageAddress $page, array $revision, RenderContext $context): RenderResult
    {
        if ($context->mode->isPublic()) {
            throw new \LogicException('renderRevision() serves authoring contexts only; public output comes from renderPublished().');
        }
        $compiled = $this->compiler->compile($page, $revision, $context);
        $html = $this->assembler->assemble($compiled, $this->slots->fill($compiled, $context), $context);
        return new RenderResult($context->mode, $html, StudioCachePolicy::headersFor($context->mode));
    }

    /**
     * Public render of the page's published revision, or null when the page is
     * not publicly renderable (not published, wrong type, revision missing).
     */
    public function renderPublished(PageAddress $page, RenderContext $context): ?RenderResult
    {
        if (!$context->mode->isPublic()) {
            throw new \LogicException('renderPublished() serves the public context only.');
        }
        if ($page->id === null || !$page->isPublished() || !in_array($page->pageType, self::PUBLIC_PAGE_TYPES, true)) {
            return null;
        }

        $revision = $this->revisions->findByIdForPage($page->id, (int) $page->publishedRevisionId);
        if ($revision === null) {
            return null;
        }

        $compiled = $this->compiler->publishedArtifact($page, $revision, $context);
        $html = $this->assembler->assemble($compiled, $this->slots->fill($compiled, $context), $context);

        $etag = StudioCacheKey::etag(
            StudioCacheKey::published($context->tenantId, $context->site->siteKey, $page->id, $compiled->revisionId, $compiled->contentHash),
            $html,
        );
        return new RenderResult($context->mode, $html, StudioCachePolicy::headersFor($context->mode, $etag), $etag);
    }

    /**
     * Compile + persist the published artifact for a just-published revision
     * (called by StudioApplicationService::publish() INSIDE the publish
     * transaction, so a compilation failure rolls the publish back).
     *
     * @param array<string, mixed> $revision
     */
    public function compilePublished(PageAddress $page, array $revision, RenderContext $context): CompiledPage
    {
        return $this->compiler->compileAndStorePublished($page, $revision, $context);
    }
}
