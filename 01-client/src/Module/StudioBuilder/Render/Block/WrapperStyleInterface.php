<?php
/**
 * Kohevo Studio — a renderer whose wrapper carries custom properties of its own (WrapperClassesInterface's layout blocks,
 * when a value differs per device: a Grid's columns). The string is declarations built only from integers the renderer
 * clamped; it joins the wrapper's one `style` attribute.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block;

interface WrapperStyleInterface
{
    public function wrapperStyle(BlockRenderScope $scope): string;
}
