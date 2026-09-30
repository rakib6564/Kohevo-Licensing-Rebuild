<?php
/**
 * Kohevo Studio (studio-builder) — Dependency-based compilation invalidation.
 *
 * Uses the EXISTING dependency index (`studiobuilder_dependencies`, written by
 * DependencyExtractor on every revision) — no second dependency graph. Given a
 * changed dependency (type, key), every page of the current tenant that
 * references it in ANY revision has its compiled artifacts dropped; the public
 * runtime then recompiles from the published revision on the next request.
 * Over-invalidation (a draft-only reference) only costs one recompile.
 *
 * How each dependency type stays correct:
 *
 *   media        invalidateMedia(id) — core Media fires no change event today,
 *                so this is the explicit hook; the artifact age bound
 *                (StudioCompiler::MAX_ARTIFACT_AGE_SECONDS) is the backstop.
 *   token_group  invalidateTokenGroup(group) for the future token writer; the
 *                resolved theme is ALSO in every content_hash, so a token or
 *                branding change is detected on the next request regardless.
 *   partial      invalidateTemplate(key) — wired into template saves.
 *                Header/footer partial pages are resolved at compile time and
 *                their published revision is in the content_hash.
 *   module /     never baked: entitlement-gated and provider-bound nodes are
 *   entity /     deferred and re-resolved per request, so there is nothing to
 *   form         invalidate (the methods still work generically).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Compile;

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
        $pageIds = $this->dependencies->pageIdsForDependency($type, $key);
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

    /** A template key is indexed both as `template:{key}` (template_key) and `{key}` (section global_ref). */
    public function invalidateTemplate(string $templateKey): int
    {
        return $this->invalidateDependency('partial', 'template:' . $templateKey)
            + $this->invalidateDependency('partial', $templateKey);
    }
}
