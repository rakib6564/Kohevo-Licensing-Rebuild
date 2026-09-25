<?php
/**
 * Slate — Block contract (Presentation).
 *
 * A Block is an editable content unit: a field schema (what the editor exposes)
 * plus a render() that COMPOSES Components — docs/05-Rendering/
 * blocks-and-sections.md §2, ADR-0007.
 *
 *   Block = FieldSchema + render() that composes Components
 *
 * Blocks are owned by MODULES, not core: Shop registers `product-grid`, Booking
 * registers `booking-widget`, the CMS registers content blocks. Core ships only
 * generic blocks. Modules register via the `blocks.register` extension point.
 * (This is the fix for `rx-*`/`sb-*` verticals baked into the old core registry.)
 *
 * Public API surface (platform-foundation §8): the Block/Component contract is
 * semver'd. Implementations receive a normalized prop set (FieldSchema::normalize)
 * and MUST NOT arrange other blocks, know page chrome, or hit the DB directly
 * (blocks-and-sections.md, "Boundaries recap").
 */

declare(strict_types=1);

namespace Slate\Presentation;

interface Block
{
    /** Stable type key, e.g. 'hero', 'donation-form'. Unique within the registry. */
    public function type(): string;

    /** The editable fields + defaults the Page Builder generates a form from. */
    public function schema(): FieldSchema;

    /**
     * Render this block's props to an HTML fragment, composing Components.
     * $props is the block instance's stored props (the caller normalizes them
     * against schema() first). Must return a string; must not echo.
     *
     * @param array<string,mixed> $props
     */
    public function render(array $props, RenderContext $ctx): string;
}
