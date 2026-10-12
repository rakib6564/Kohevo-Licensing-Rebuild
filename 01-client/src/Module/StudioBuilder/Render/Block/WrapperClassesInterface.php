<?php
/**
 * Kohevo Studio — a renderer whose block IS the box its children sit in.
 *
 * Most blocks render markup inside the wrapper element the pipeline puts around every block. A layout block such as the
 * Container has no markup of its own: its children go straight into that wrapper, so the flex settings an author gives
 * the block (direction, wrap, alignment, gaps, per device) reach the children. Such a renderer names the classes the
 * wrapper carries instead.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block;

interface WrapperClassesInterface
{
    /** @return list<string> */
    public function wrapperClasses(BlockRenderScope $scope): array;
}
