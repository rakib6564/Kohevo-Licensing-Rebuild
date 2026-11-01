<?php
/**
 * Kohevo Studio — a block that draws its children more than once, each time in its own context (one row of a repeater
 * field). The renderer hands DocumentRenderer one context per row; on the canvas only the first row can be picked.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block;

use Slate\Module\StudioBuilder\Render\RenderContext;

interface RepeatingChildrenInterface
{
    /**
     * @param array<string, mixed> $block
     * @return list<RenderContext>
     */
    public function childContexts(array $block, RenderContext $context): array;
}
