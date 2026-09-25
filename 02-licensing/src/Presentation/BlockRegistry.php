<?php
/**
 * Slate — BlockRegistry contract (Presentation).
 *
 * The single core registry of available Block types (docs/05-Rendering/
 * blocks-and-sections.md §2, ADR-0007). One registry — not the two parallel
 * systems (`content-builder` + `small-business-kit`) the audit found. Modules
 * populate it through the `blocks.register` extension point; the Renderer
 * resolves a block instance's `type` here to find the Block to render.
 *
 * Public API surface (platform-foundation §8): part of the Block/Component
 * contract. The concrete in-memory implementation and the content-builder bridge
 * arrive in Phase 3A B3/B5; this interface is the promise they honour.
 */

declare(strict_types=1);

namespace Slate\Presentation;

interface BlockRegistry
{
    /** Register (or replace) a Block under its type() key. */
    public function register(Block $block): void;

    public function has(string $type): bool;

    /** The Block for a type, or null if none is registered (Renderer fails soft). */
    public function get(string $type): ?Block;

    /**
     * All registered blocks, keyed by type.
     *
     * @return array<string,Block>
     */
    public function all(): array;
}
