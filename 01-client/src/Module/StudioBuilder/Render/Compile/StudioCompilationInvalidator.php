<?php
/**
 * Kohevo Studio (studio-builder) — Dependency-based compilation invalidation.
 *
 * Uses the EXISTING dependency index (`studiobuilder_dependencies`, written by
 * DependencyExtractor on every revision) — no second dependency graph. Given a
 * changed dependency (type, key), every page of the current tenant whose
 * current draft or published revision references it has its compiled
 * artifacts dropped; the public runtime then recompiles from the published
 * revision on the next request. Over-invalidation (a draft-only reference)
 * only costs one recompile; a reference held only by a historical revision
 * never triggers a rebuild (Phase 6).
 *
 * How each dependency type stays correct:
 *
 *   media        invalidateMedia(id) — core Media fires no change event today,
 *                so this is the explicit hook; the artifact age bound
 *                (StudioCompiler::MAX_ARTIFACT_AGE_SECONDS) is the backstop.
 *   token_group  invalidateTokenGroup(group) for the future token writer; the
 *                resolved theme is ALSO in every content_hash, so a token or
 *                branding change is detected on the next request regardless.
 *   partial      invalidateTemplate(key) — wired into template saves
 *                (document-level `template_key`). Phase 6: invalidateComponent
 *                (a Global Component's uuid, wired into its publish/archive)
 *                and invalidateChrome (header/footer partial publish/archive).
 *                Header/footer partials and components are ALSO resolved at
 *                compile time with their published revision in the
 *                content_hash, so the fingerprint check remains the exact guard.
 *   module /     never baked: entitlement-gated and provider-bound nodes are
 *   entity /     deferred and re-resolved per request, so there is nothing to
 *   form         invalidate (the methods still work generically).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Compile;

use Slate\Module\StudioBuilder\Dependency\DependencyExtractor;
use Slate\Module\StudioBuilder\Dependency\DependencyRecord;
use Slate\Module\StudioBuilder\Repository\CompilationRepository;
use Slate\Module\StudioBuilder\Repository\DependencyRepository;

final class StudioCompilationInvalidator
{
    public function __construct(
        private readonly DependencyRepository $dependencies,
        private readonly CompilationRepository $compilations,
    ) {}

    /** @return int number of pages whose compilations were dropped */
    public function invalidateDependency(string $type, string $key): int
    {
        if (!in_array($type, DependencyRecord::ALLOWED_TYPES, true) || $key === '' || strlen($key) > 191) {
            return 0;
        }
        // Phase 6: only pages whose CURRENT draft or published revision carries the
        // dependency. A compiled artifact always belongs to the published revision,
        // so historical revisions can never own an artifact — consulting them only
        // rebuilt unrelated pages (e.g. a page that once showed a header and now hides it).
        $pageIds = $this->dependencies->currentDependentPageIds($type, $key);
        if ($pageIds === []) {
            return 0;
        }
        $this->compilations->deleteForPages($pageIds);
        return count($pageIds);
    }

    public function invalidatePage(int $pageId): int
    {
        return $pageId > 0 ? $this->compilations->deleteForPages([$pageId]) : 0;
    }

    public function invalidateMedia(int $mediaId): int
    {
        return $mediaId > 0 ? $this->invalidateDependency('media', (string) $mediaId) : 0;
    }

    public function invalidateTokenGroup(string $tokenGroup): int
    {
        return $this->invalidateDependency('token_group', $tokenGroup);
    }

    /** A template key is indexed as `template:{key}` (document-level template_key). */
    public function invalidateTemplate(string $templateKey): int
    {
        return $this->invalidateDependency('partial', 'template:' . $templateKey);
    }

    /**
     * Phase 6: a Global Component changed (published, archived) — every page
     * whose sections reference it (`partial` / component uuid) recompiles.
     */
    public function invalidateComponent(string $componentRef): int
    {
        return $this->invalidateDependency('partial', $componentRef);
    }

    /**
     * Phase 6: a header/footer partial page changed (published, archived) —
     * every chromed page that does not hide that region recompiles. Pages
     * with the region hidden are untouched (the fingerprint check stays the
     * exact guard for which partial a page actually resolves to).
     */
    public function invalidateChrome(string $region): int
    {
        return match ($region) {
            'header' => $this->invalidateDependency('partial', DependencyExtractor::CHROME_HEADER_KEY),
            'footer' => $this->invalidateDependency('partial', DependencyExtractor::CHROME_FOOTER_KEY),
            default  => 0,
        };
    }
}
