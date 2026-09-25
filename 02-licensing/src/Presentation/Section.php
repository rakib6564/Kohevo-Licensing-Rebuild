<?php
/**
 * Slate — Section value object (Presentation).
 *
 * A first-class, saveable, reusable layout container arranging one or more
 * Blocks (docs/05-Rendering/blocks-and-sections.md §3, ADR-0007). This is the
 * object today's flat block-array lacks: it carries the LAYOUT (via LayoutSpec),
 * while its Blocks carry the CONTENT.
 *
 * Blocks are kept as plain `['type' => ..., 'props' => ...]` arrays — the exact
 * shape the Renderer contract's renderBlock(array $block) consumes and the shape
 * persisted in the document JSON — so no marshalling sits between storage and
 * render. A Section can be `savedAs` a named pattern for reuse across pages.
 *
 * Immutable value object (platform-foundation §5). Pure — no DB, no rendering.
 */

declare(strict_types=1);

namespace Slate\Presentation;

final class Section implements \JsonSerializable
{
    /**
     * @param string                       $id      stable id (reorder/target in the builder)
     * @param LayoutSpec                    $layout  arrangement of the blocks
     * @param list<array<string,mixed>>     $blocks  ordered ['type'=>..,'props'=>..] entries
     * @param string|null                  $savedAs  reusable pattern name, or null
     */
    public function __construct(
        public readonly string $id,
        public readonly LayoutSpec $layout,
        public readonly array $blocks = [],
        public readonly ?string $savedAs = null,
    ) {}

    /** Rehydrate from a stored section object. Layout + blocks are normalized. */
    public static function fromArray(array $data): self
    {
        $blocks = [];
        foreach (($data['blocks'] ?? []) as $b) {
            if (is_array($b) && isset($b['type'])) {
                $entry = ['type' => (string) $b['type'], 'props' => (array) ($b['props'] ?? [])];
                if (!empty($b['style']) && is_array($b['style'])) {
                    $entry['style'] = $b['style'];
                }
                $blocks[] = $entry;
            }
        }
        return new self(
            (string) ($data['id'] ?? ''),
            LayoutSpec::fromArray((array) ($data['layout'] ?? [])),
            $blocks,
            isset($data['savedAs']) && $data['savedAs'] !== '' ? (string) $data['savedAs'] : null,
        );
    }

    /** Wither: same section with a different id (used by the document normalizer). */
    public function withId(string $id): self
    {
        return new self($id, $this->layout, $this->blocks, $this->savedAs);
    }

    public function isEmpty(): bool
    {
        return $this->blocks === [];
    }

    /** @return array{id:string,layout:array,blocks:list<array<string,mixed>>,savedAs?:string} */
    public function toArray(): array
    {
        $out = [
            'id'     => $this->id,
            'layout' => $this->layout->toArray(),
            'blocks' => $this->blocks,
        ];
        if ($this->savedAs !== null) {
            $out['savedAs'] = $this->savedAs;
        }
        return $out;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
