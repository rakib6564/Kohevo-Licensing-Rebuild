<?php
/**
 * Slate — LayoutSpec value object (Presentation).
 *
 * The layout a Section carries (docs/05-Rendering/blocks-and-sections.md §3):
 * columns, background token, vertical spacing, and content max-width. This is
 * the "arrangement" half of the layout-vs-content split — Sections own layout,
 * Blocks own content — which is what lets a page be rearranged without touching
 * block internals.
 *
 * Token-named, never raw values: `bg` names a design token (ADR-0008), resolved
 * to a colour by the Theme in Phase 3B. `pad`/`width` are named scale steps, not
 * pixels. Keeping these symbolic is what makes a Section re-skin when the Theme
 * changes.
 *
 * Immutable value object (platform-foundation §5). Pure — no DB, no rendering.
 */

declare(strict_types=1);

namespace Slate\Presentation;

final class LayoutSpec implements \JsonSerializable
{
    public function __construct(
        public readonly int    $cols  = 1,          // 1..N column arrangement
        public readonly string $bg    = '',         // background design-token name ('' = none)
        public readonly string $pad   = 'normal',   // vertical spacing step: compact|normal|spacious
        public readonly string $width = 'normal',   // content max-width step: narrow|normal|wide|full
    ) {}

    /** Rehydrate from a stored section's `layout` object; unknown keys ignored. */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['cols']) ? max(1, (int) $data['cols']) : 1,
            (string) ($data['bg'] ?? ''),
            (string) ($data['pad'] ?? 'normal'),
            (string) ($data['width'] ?? 'normal'),
        );
    }

    /** The neutral single-column layout used for the legacy flat-array bridge. */
    public static function default(): self
    {
        return new self();
    }

    /**
     * True when this layout arranges nothing (single column, no background, normal
     * spacing/width). The Renderer treats a default-layout Section as TRANSPARENT —
     * it emits no wrapper, just the blocks — which is what keeps every legacy page
     * (one default-layout implicit Section) byte-identical to the old renderer.
     */
    public function isDefault(): bool
    {
        return $this->cols === 1
            && $this->bg === ''
            && $this->pad === 'normal'
            && $this->width === 'normal';
    }

    /** @return array{cols:int,bg:string,pad:string,width:string} */
    public function toArray(): array
    {
        return ['cols' => $this->cols, 'bg' => $this->bg, 'pad' => $this->pad, 'width' => $this->width];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
