<?php
/**
 * Slate — FieldSchema value object (Presentation).
 *
 * The editable-field contract for a Block (ADR-0007, docs/05-Rendering/
 * blocks-and-sections.md §2): the ordered list of field descriptors the editor
 * renders, plus the default props for a new block. The Page Builder generates
 * its form from this — no per-block editor code.
 *
 * A "field descriptor" is a plain array (matching content-builder's shape so the
 * bridge in Phase 3A B5 can adapt existing blocks without a rewrite), e.g.
 *   ['key' => 'heading', 'type' => 'text', 'label' => 'Heading']
 * Repeater/nested fields carry an 'item' array of sub-descriptors.
 *
 * Immutable value object (platform-foundation §5). Pure — no DB, no rendering.
 * The one behavior it owns is prop NORMALIZATION: given a stored block's props,
 * return a complete prop set (defaults filled in, provided values winning). This
 * is the "block schema" guarantee — a block's render() always receives every
 * prop it declared a default for.
 */

declare(strict_types=1);

namespace Slate\Presentation;

final class FieldSchema implements \JsonSerializable
{
    /**
     * @param list<array<string,mixed>> $fields   ordered field descriptors
     * @param array<string,mixed>       $defaults  default props for a new block
     */
    public function __construct(
        public readonly array $fields = [],
        public readonly array $defaults = [],
    ) {}

    /** @param list<array<string,mixed>> $fields @param array<string,mixed> $defaults */
    public static function of(array $fields = [], array $defaults = []): self
    {
        return new self(array_values($fields), $defaults);
    }

    /** Rehydrate from the {fields, defaults} shape (adapter / palette payloads). */
    public static function fromArray(array $data): self
    {
        return new self(
            array_values($data['fields'] ?? []),
            $data['defaults'] ?? [],
        );
    }

    /** @return list<array<string,mixed>> */
    public function fields(): array { return $this->fields; }

    /** @return array<string,mixed> */
    public function defaults(): array { return $this->defaults; }

    /** The prop keys this schema knows about (union of default keys + field keys). */
    public function keys(): array
    {
        $keys = array_keys($this->defaults);
        foreach ($this->fields as $f) {
            if (isset($f['key']) && !in_array($f['key'], $keys, true)) {
                $keys[] = $f['key'];
            }
        }
        return $keys;
    }

    /**
     * Complete a stored block's props against this schema: start from defaults,
     * then overlay any provided value (provided wins, including falsy values).
     * Unknown keys in $props are preserved (forward-compatible) — validation is
     * deliberately permissive, matching content-builder's fail-soft renderer.
     *
     * @param array<string,mixed> $props
     * @return array<string,mixed>
     */
    public function normalize(array $props): array
    {
        return array_merge($this->defaults, $props);
    }

    /** @return array{fields:list<array<string,mixed>>,defaults:array<string,mixed>} */
    public function toArray(): array
    {
        return ['fields' => $this->fields, 'defaults' => $this->defaults];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
