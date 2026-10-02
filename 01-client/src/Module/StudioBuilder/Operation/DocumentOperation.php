<?php
/**
 * Kohevo Studio (studio-builder) — Canonical Document Operation Value Object.
 *
 * An immutable, transport-safe description of a single atomic mutation against
 * a canonical Studio document tree (`ApplyDocumentOperation` in the target
 * architecture's command family). Operations are the only way the builder, AI,
 * MCP, or importers describe a document mutation — nothing else is permitted
 * to poke at the document array directly.
 *
 * `DocumentOperation` only carries and validates the *shape* of an operation.
 * `DocumentOperationApplier` performs the actual structural mutation. Neither
 * class re-validates the resulting document against `DocumentValidator` — the
 * caller must always run the mutated document back through
 * `DocumentNormalizer::validateAndNormalize()` before persisting it.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Operation;

use Slate\Module\StudioBuilder\Exception\StudioValidationException;

final class DocumentOperation
{
    public const OP_UPDATE_SETTINGS         = 'update_settings';
    public const OP_UPDATE_SEO              = 'update_seo';
    public const OP_UPDATE_TEMPLATE         = 'update_template';
    public const OP_INSERT_SECTION          = 'insert_section';
    public const OP_REMOVE_SECTION          = 'remove_section';
    public const OP_MOVE_SECTION            = 'move_section';
    public const OP_DUPLICATE_SECTION       = 'duplicate_section';
    public const OP_UPDATE_SECTION_LABEL    = 'update_section_label';
    public const OP_UPDATE_SECTION_LAYOUT   = 'update_section_layout';
    public const OP_UPDATE_SECTION_VISIBILITY = 'update_section_visibility';
    public const OP_INSERT_BLOCK            = 'insert_block';
    public const OP_REMOVE_BLOCK            = 'remove_block';
    public const OP_MOVE_BLOCK              = 'move_block';
    public const OP_DUPLICATE_BLOCK         = 'duplicate_block';
    public const OP_UPDATE_BLOCK_PROPS      = 'update_block_props';
    public const OP_UPDATE_BLOCK_STYLE      = 'update_block_style';
    public const OP_UPDATE_BLOCK_VISIBILITY = 'update_block_visibility';
    public const OP_UPDATE_BLOCK_BINDINGS   = 'update_block_bindings';
    public const OP_UPDATE_BLOCK_RESPONSIVE  = 'update_block_responsive';
    public const OP_UPDATE_BLOCK_CLASS_NAMES = 'update_block_class_names';
    public const OP_UPDATE_BLOCK_ATTRIBUTES  = 'update_block_attributes';

    public const ALLOWED_OPS = [
        self::OP_UPDATE_SETTINGS,
        self::OP_UPDATE_SEO,
        self::OP_UPDATE_TEMPLATE,
        self::OP_INSERT_SECTION,
        self::OP_REMOVE_SECTION,
        self::OP_MOVE_SECTION,
        self::OP_DUPLICATE_SECTION,
        self::OP_UPDATE_SECTION_LABEL,
        self::OP_UPDATE_SECTION_LAYOUT,
        self::OP_UPDATE_SECTION_VISIBILITY,
        self::OP_INSERT_BLOCK,
        self::OP_REMOVE_BLOCK,
        self::OP_MOVE_BLOCK,
        self::OP_DUPLICATE_BLOCK,
        self::OP_UPDATE_BLOCK_PROPS,
        self::OP_UPDATE_BLOCK_STYLE,
        self::OP_UPDATE_BLOCK_VISIBILITY,
        self::OP_UPDATE_BLOCK_BINDINGS,
        self::OP_UPDATE_BLOCK_RESPONSIVE,
        self::OP_UPDATE_BLOCK_CLASS_NAMES,
        self::OP_UPDATE_BLOCK_ATTRIBUTES,
    ];

    /**
     * Every payload key required for each op, checked before the applier ever
     * touches the document tree so malformed operations fail closed immediately.
     */
    private const REQUIRED_PAYLOAD_KEYS = [
        self::OP_UPDATE_SETTINGS           => ['settings'],
        self::OP_UPDATE_SEO                => ['seo'],
        self::OP_UPDATE_TEMPLATE           => ['template_key'],
        self::OP_INSERT_SECTION            => ['index'],
        self::OP_REMOVE_SECTION            => ['section_id'],
        self::OP_MOVE_SECTION              => ['section_id', 'to_index'],
        self::OP_DUPLICATE_SECTION         => ['section_id'],
        self::OP_UPDATE_SECTION_LABEL      => ['section_id', 'label'],
        self::OP_UPDATE_SECTION_LAYOUT     => ['section_id', 'layout'],
        self::OP_UPDATE_SECTION_VISIBILITY => ['section_id', 'visibility'],
        self::OP_INSERT_BLOCK              => ['parent_id', 'index', 'block'],
        self::OP_REMOVE_BLOCK              => ['block_id'],
        self::OP_MOVE_BLOCK                => ['block_id', 'parent_id', 'index'],
        self::OP_DUPLICATE_BLOCK           => ['block_id'],
        self::OP_UPDATE_BLOCK_PROPS        => ['block_id', 'props'],
        self::OP_UPDATE_BLOCK_STYLE        => ['block_id', 'style'],
        self::OP_UPDATE_BLOCK_VISIBILITY   => ['block_id', 'visibility'],
        self::OP_UPDATE_BLOCK_BINDINGS     => ['block_id', 'bindings'],
        self::OP_UPDATE_BLOCK_RESPONSIVE   => ['block_id', 'responsive'],
        self::OP_UPDATE_BLOCK_CLASS_NAMES  => ['block_id', 'classNames'],
        self::OP_UPDATE_BLOCK_ATTRIBUTES   => ['block_id', 'attributes'],
    ];

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $op,
        public readonly array $payload,
    ) {
        if (!in_array($this->op, self::ALLOWED_OPS, true)) {
            throw new StudioValidationException([
                ['path' => '$.op', 'code' => 'unknown_operation', 'message' => "Unknown Studio document operation '{$this->op}'."],
            ]);
        }

        foreach (self::REQUIRED_PAYLOAD_KEYS[$this->op] as $required) {
            if (!array_key_exists($required, $this->payload)) {
                throw new StudioValidationException([
                    ['path' => "\$.payload.{$required}", 'code' => 'required_field', "message" => "Operation '{$this->op}' requires payload key '{$required}'."],
                ]);
            }
        }
    }

    /**
     * @param array{op?: mixed, payload?: mixed} $decoded
     */
    public static function fromArray(array $decoded): self
    {
        $op = $decoded['op'] ?? null;
        if (!is_string($op)) {
            throw new StudioValidationException([
                ['path' => '$.op', 'code' => 'invalid_operation', 'message' => 'Operation "op" must be a string.'],
            ]);
        }

        $payload = $decoded['payload'] ?? [];
        if (!is_array($payload) || array_is_list($payload)) {
            throw new StudioValidationException([
                ['path' => '$.payload', 'code' => 'invalid_payload', 'message' => 'Operation "payload" must be a JSON object.'],
            ]);
        }

        return new self($op, $payload);
    }

    /**
     * @return array{op: string, payload: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['op' => $this->op, 'payload' => $this->payload];
    }

    /**
     * @param array<string, mixed> $responsive
     */
    public static function updateBlockResponsive(string $blockId, array $responsive): self
    {
        return new self(self::OP_UPDATE_BLOCK_RESPONSIVE, ['block_id' => $blockId, 'responsive' => $responsive]);
    }

    /**
     * @param list<string> $classNames
     */
    public static function updateBlockClassNames(string $blockId, array $classNames): self
    {
        return new self(self::OP_UPDATE_BLOCK_CLASS_NAMES, ['block_id' => $blockId, 'classNames' => $classNames]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function updateBlockAttributes(string $blockId, array $attributes): self
    {
        return new self(self::OP_UPDATE_BLOCK_ATTRIBUTES, ['block_id' => $blockId, 'attributes' => $attributes]);
    }

    /**
     * @param array<string, mixed> $visibility
     */
    public static function updateBlockVisibility(string $blockId, array $visibility): self
    {
        return new self(self::OP_UPDATE_BLOCK_VISIBILITY, ['block_id' => $blockId, 'visibility' => $visibility]);
    }

    public static function duplicateBlock(string $blockId): self
    {
        return new self(self::OP_DUPLICATE_BLOCK, ['block_id' => $blockId]);
    }

    public static function duplicateSection(string $sectionId): self
    {
        return new self(self::OP_DUPLICATE_SECTION, ['section_id' => $sectionId]);
    }

    public static function updateSectionLabel(string $sectionId, string $label): self
    {
        return new self(self::OP_UPDATE_SECTION_LABEL, ['section_id' => $sectionId, 'label' => $label]);
    }
}
