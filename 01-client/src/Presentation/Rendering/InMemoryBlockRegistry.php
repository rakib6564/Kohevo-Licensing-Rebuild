<?php
/**
 * Slate — InMemoryBlockRegistry: the default BlockRegistry implementation.
 *
 * A plain in-process map of type → Block. The one core registry modules populate
 * via the `blocks.register` extension point (docs/05-Rendering/blocks-and-sections.md
 * §2). The content-builder bridge (Phase 3A B5) registers adapters for the existing
 * blocks into an instance of this.
 *
 * @internal — implementation detail below the BlockRegistry contract
 * (platform-foundation §8). Depend on the Slate\Presentation\BlockRegistry
 * interface, not this class.
 */

declare(strict_types=1);

namespace Slate\Presentation\Rendering;

use Slate\Presentation\Block;
use Slate\Presentation\BlockRegistry;

final class InMemoryBlockRegistry implements BlockRegistry
{
    /** @var array<string,Block> */
    private array $blocks = [];

    public function register(Block $block): void
    {
        $this->blocks[$block->type()] = $block;
    }

    public function has(string $type): bool
    {
        return isset($this->blocks[$type]);
    }

    public function get(string $type): ?Block
    {
        return $this->blocks[$type] ?? null;
    }

    /** @return array<string,Block> */
    public function all(): array
    {
        return $this->blocks;
    }
}
