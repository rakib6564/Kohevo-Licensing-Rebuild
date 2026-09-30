<?php
/**
 * Kohevo Studio (studio-builder) — Block Renderer Contract.
 *
 * Rendering is deliberately kept OUT of `BlockDefinitionInterface` (registry
 * metadata stays transport-safe and side-effect free). A renderer turns one
 * already-validated, normalized block into deterministic HTML using ONLY what
 * its `BlockRenderScope` hands it:
 *
 *  - normalized props (never raw input),
 *  - media resolved through the tenant-scoped media resolver,
 *  - provider rows the pipeline already fetched through `DataProviderRegistry`,
 *  - pre-rendered children.
 *
 * A renderer must never run SQL, touch a repository, read a tenant id, execute
 * authored expressions/PHP/JS, or emit an authored string without escaping.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block;

interface BlockRendererInterface
{
    /** The block type this renderer renders (must match a `BlockRegistry` type). */
    public function type(): string;

    /**
     * True when output depends on request-time data (provider bindings). Such
     * blocks are never baked into a compiled artifact — they are resolved on
     * every request under the live entitlement/permission rules.
     */
    public function isDynamic(): bool;

    public function render(BlockRenderScope $scope): string;
}
