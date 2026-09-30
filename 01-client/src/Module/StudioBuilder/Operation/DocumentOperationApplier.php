<?php
/**
 * Kohevo Studio (studio-builder) — Canonical Document Operation Applier.
 *
 * Pure, stateless structural transform: applies a sequence of `DocumentOperation`
 * instances to a canonical document array and returns the mutated array.
 *
 * This class does NOT validate or normalize the resulting document — it only
 * performs the requested structural edit, failing closed when a referenced
 * section/block id does not exist, an unregistered block type is inserted, or
 * a block is inserted into a parent that does not allow children. The caller
 * (a future `ApplyDocumentOperation` command / `StudioRevisionService` draft
 * write) MUST always pass the result through
 * `DocumentNormalizer::validateAndNormalize()` before persisting it, exactly
 * like any other authored document.
 *
 * Section/block updates use whole-value replace semantics for `props`, `style`,
 * `visibility`, `bindings`, and section `layout`/`visibility` (the client sends
 * the complete new sub-object, the same contract a block property panel would
 * use). `update_settings` / `update_seo` use a shallow merge into the existing
 * map, since those are typically edited one field at a time.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Operation;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentCopier;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;

final class DocumentOperationApplier
{
    /**
     * Apply a sequence of operations to a document array, in order.
     *
     * @param array<string, mixed>      $document
     * @param list<DocumentOperation>   $operations
     * @return array<string, mixed>
     */
    public static function apply(array $document, array $operations, BlockRegistry $registry): array
    {
        foreach ($operations as $operation) {
            $document = self::applyOne($document, $operation, $registry);
        }
        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function applyOne(array $document, DocumentOperation $operation, BlockRegistry $registry): array
    {
        $payload = $operation->payload;

        return match ($operation->op) {
            DocumentOperation::OP_UPDATE_SETTINGS => self::updateSettings($document, $payload),
            DocumentOperation::OP_UPDATE_SEO => self::updateSeo($document, $payload),
            DocumentOperation::OP_UPDATE_TEMPLATE => self::updateTemplate($document, $payload),
            DocumentOperation::OP_INSERT_SECTION => self::insertSection($document, $payload),
            DocumentOperation::OP_REMOVE_SECTION => self::removeSection($document, $payload),
            DocumentOperation::OP_MOVE_SECTION => self::moveSection($document, $payload),
            DocumentOperation::OP_UPDATE_SECTION_LAYOUT => self::updateSectionField($document, $payload, 'layout'),
            DocumentOperation::OP_UPDATE_SECTION_VISIBILITY => self::updateSectionField($document, $payload, 'visibility'),
            DocumentOperation::OP_INSERT_BLOCK => self::insertBlock($document, $payload, $registry),
            DocumentOperation::OP_REMOVE_BLOCK => self::removeBlock($document, $payload),
            DocumentOperation::OP_MOVE_BLOCK => self::moveBlock($document, $payload, $registry),
            DocumentOperation::OP_UPDATE_BLOCK_PROPS => self::updateBlockField($document, $payload, 'props'),
            DocumentOperation::OP_UPDATE_BLOCK_STYLE => self::updateBlockField($document, $payload, 'style'),
            DocumentOperation::OP_UPDATE_BLOCK_VISIBILITY => self::updateBlockField($document, $payload, 'visibility'),
            DocumentOperation::OP_UPDATE_BLOCK_BINDINGS => self::updateBlockField($document, $payload, 'bindings'),
            default => throw new StudioValidationException([
                ['path' => '$.op', 'code' => 'unknown_operation', 'message' => "Unknown Studio document operation '{$operation->op}'."],
            ]),
        };
    }

    // ── Document-level operations ──────────────────────────────────────────

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function updateSettings(array $document, array $payload): array
    {
        $patch = self::requireObject($payload, 'settings', '$.payload.settings');
        $current = is_array($document['settings'] ?? null) ? $document['settings'] : [];
        $document['settings'] = array_merge($current, $patch);
        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function updateSeo(array $document, array $payload): array
    {
        $patch = self::requireObject($payload, 'seo', '$.payload.seo');
        $current = is_array($document['seo'] ?? null) ? $document['seo'] : [];
        $document['seo'] = array_merge($current, $patch);
        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function updateTemplate(array $document, array $payload): array
    {
        $templateKey = $payload['template_key'] ?? null;
        if (!is_string($templateKey) || $templateKey === '') {
            throw new StudioValidationException([
                ['path' => '$.payload.template_key', 'code' => 'invalid_template_key', 'message' => 'payload.template_key must be a non-empty string.'],
            ]);
        }
        $document['template_key'] = $templateKey;
        return $document;
    }

    // ── Section operations ─────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function insertSection(array $document, array $payload): array
    {
        $index = self::requireInt($payload, 'index', '$.payload.index');
        $section = $payload['section'] ?? null;

        if ($section !== null && (!is_array($section) || array_is_list($section))) {
            throw new StudioValidationException([
                ['path' => '$.payload.section', 'code' => 'invalid_section', 'message' => 'payload.section must be an object or null.'],
            ]);
        }

        $newSection = is_array($section) ? $section : [];
        $newSection['id']         = CanonicalDocumentSchema::newSectionId();
        $newSection['label']      ??= 'New Section';
        $newSection['global_ref'] ??= null;
        $newSection['layout']     = is_array($newSection['layout'] ?? null) ? $newSection['layout'] : CanonicalDocumentSchema::defaultSectionLayout();
        $newSection['visibility'] = is_array($newSection['visibility'] ?? null) ? $newSection['visibility'] : CanonicalDocumentSchema::defaultVisibility();
        $newSection['blocks']     = is_array($newSection['blocks'] ?? null) ? $newSection['blocks'] : [];

        $sections = is_array($document['sections'] ?? null) ? array_values($document['sections']) : [];
        $clampedIndex = max(0, min($index, count($sections)));
        array_splice($sections, $clampedIndex, 0, [$newSection]);

        $document['sections'] = $sections;
        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function removeSection(array $document, array $payload): array
    {
        $sectionId = self::requireString($payload, 'section_id', '$.payload.section_id');
        $sections  = is_array($document['sections'] ?? null) ? $document['sections'] : [];

        $found = false;
        $out = [];
        foreach ($sections as $section) {
            if (is_array($section) && ($section['id'] ?? null) === $sectionId) {
                $found = true;
                continue;
            }
            $out[] = $section;
        }

        self::assertFound($found, 'section_id', $sectionId, 'section');
        $document['sections'] = $out;
        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function moveSection(array $document, array $payload): array
    {
        $sectionId = self::requireString($payload, 'section_id', '$.payload.section_id');
        $toIndex   = self::requireInt($payload, 'to_index', '$.payload.to_index');

        $sections = is_array($document['sections'] ?? null) ? array_values($document['sections']) : [];

        $foundIndex = null;
        foreach ($sections as $idx => $section) {
            if (is_array($section) && ($section['id'] ?? null) === $sectionId) {
                $foundIndex = $idx;
                break;
            }
        }
        self::assertFound($foundIndex !== null, 'section_id', $sectionId, 'section');

        $section = $sections[$foundIndex];
        array_splice($sections, $foundIndex, 1);
        $clampedIndex = max(0, min($toIndex, count($sections)));
        array_splice($sections, $clampedIndex, 0, [$section]);

        $document['sections'] = $sections;
        return $document;
    }

    /**
     * Replace `section.layout` or `section.visibility` wholesale.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function updateSectionField(array $document, array $payload, string $field): array
    {
        $sectionId = self::requireString($payload, 'section_id', '$.payload.section_id');
        $value     = self::requireObject($payload, $field, "\$.payload.{$field}");

        $sections = is_array($document['sections'] ?? null) ? $document['sections'] : [];
        $found = false;
        foreach ($sections as $idx => $section) {
            if (is_array($section) && ($section['id'] ?? null) === $sectionId) {
                $sections[$idx][$field] = $value;
                $found = true;
                break;
            }
        }
        self::assertFound($found, 'section_id', $sectionId, 'section');

        $document['sections'] = $sections;
        return $document;
    }

    // ── Block operations ────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function insertBlock(array $document, array $payload, BlockRegistry $registry): array
    {
        $parentId = self::requireString($payload, 'parent_id', '$.payload.parent_id');
        $index    = self::requireInt($payload, 'index', '$.payload.index');
        $block    = self::requireObject($payload, 'block', '$.payload.block');

        $type = $block['type'] ?? null;
        if (!is_string($type) || $type === '') {
            throw new StudioValidationException([
                ['path' => '$.payload.block.type', 'code' => 'required_field', 'message' => 'payload.block.type is required to insert a block.'],
            ]);
        }
        $definition = $registry->get($type);
        if ($definition === null) {
            throw new StudioValidationException([
                ['path' => '$.payload.block.type', 'code' => 'unknown_block_type', 'message' => "Unknown or unregistered Studio block type '{$type}'."],
            ]);
        }

        // Children are accepted only for a child-capable definition (a reusable
        // block preset carries its subtree); every id in the subtree is minted
        // fresh here, exactly like the block's own id, so a preset can be
        // inserted any number of times.
        $children = ($definition->allowsChildren() && is_array($block['children'] ?? null) && array_is_list($block['children']))
            ? DocumentCopier::copyBlocks($block['children'])
            : [];

        $newBlock = [
            'bindings'   => is_array($block['bindings'] ?? null) ? $block['bindings'] : [],
            'children'   => $children,
            'id'         => CanonicalDocumentSchema::newBlockId(),
            'props'      => is_array($block['props'] ?? null) ? $block['props'] : $definition->schema()->defaults(),
            'style'      => is_array($block['style'] ?? null) ? $block['style'] : CanonicalDocumentSchema::defaultBlockStyle(),
            'type'       => $type,
            'version'    => $definition->version(),
            'visibility' => is_array($block['visibility'] ?? null) ? $block['visibility'] : CanonicalDocumentSchema::defaultVisibility(),
        ];

        $sections = is_array($document['sections'] ?? null) ? $document['sections'] : [];

        // Try inserting directly into a section's top-level blocks first.
        foreach ($sections as $sIdx => $section) {
            if (!is_array($section) || ($section['id'] ?? null) !== $parentId) {
                continue;
            }
            $blocks = is_array($section['blocks'] ?? null) ? array_values($section['blocks']) : [];
            $clampedIndex = max(0, min($index, count($blocks)));
            array_splice($blocks, $clampedIndex, 0, [$newBlock]);
            $sections[$sIdx]['blocks'] = $blocks;
            $document['sections'] = $sections;
            return $document;
        }

        // Otherwise, the parent must be an existing block that allows children.
        $inserted = false;
        foreach ($sections as $sIdx => $section) {
            if (!is_array($section)) {
                continue;
            }
            $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];
            $blocks = self::insertIntoBlockChildren($blocks, $parentId, $index, $newBlock, $registry, $inserted);
            $sections[$sIdx]['blocks'] = $blocks;
            if ($inserted) {
                break;
            }
        }

        self::assertFound($inserted, 'parent_id', $parentId, 'section or child-capable block');
        $document['sections'] = $sections;
        return $document;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed> $newBlock
     * @return list<array<string, mixed>>
     */
    private static function insertIntoBlockChildren(
        array $blocks,
        string $parentId,
        int $index,
        array $newBlock,
        BlockRegistry $registry,
        bool &$inserted,
    ): array {
        foreach ($blocks as $idx => $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['id'] ?? null) === $parentId) {
                $definition = $registry->get((string) ($block['type'] ?? ''));
                if ($definition === null || !$definition->allowsChildren()) {
                    throw new StudioValidationException([
                        ['path' => '$.payload.parent_id', 'code' => 'children_not_allowed', 'message' => "Block type '{$block['type']}' does not allow nested children."],
                    ]);
                }
                $children = is_array($block['children'] ?? null) ? array_values($block['children']) : [];
                $clampedIndex = max(0, min($index, count($children)));
                array_splice($children, $clampedIndex, 0, [$newBlock]);
                $blocks[$idx]['children'] = $children;
                $inserted = true;
                return $blocks;
            }

            $children = is_array($block['children'] ?? null) ? $block['children'] : [];
            if ($children !== []) {
                $blocks[$idx]['children'] = self::insertIntoBlockChildren($children, $parentId, $index, $newBlock, $registry, $inserted);
                if ($inserted) {
                    return $blocks;
                }
            }
        }
        return $blocks;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function removeBlock(array $document, array $payload): array
    {
        $blockId = self::requireString($payload, 'block_id', '$.payload.block_id');
        $sections = is_array($document['sections'] ?? null) ? $document['sections'] : [];

        $found = false;
        foreach ($sections as $sIdx => $section) {
            if (!is_array($section)) {
                continue;
            }
            $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];
            $sections[$sIdx]['blocks'] = self::removeBlockRecursive($blocks, $blockId, $found);
        }

        self::assertFound($found, 'block_id', $blockId, 'block');
        $document['sections'] = $sections;
        return $document;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private static function removeBlockRecursive(array $blocks, string $blockId, bool &$found): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['id'] ?? null) === $blockId) {
                $found = true;
                continue;
            }
            $children = is_array($block['children'] ?? null) ? $block['children'] : [];
            if ($children !== []) {
                $block['children'] = self::removeBlockRecursive($children, $blockId, $found);
            }
            $out[] = $block;
        }
        return $out;
    }

    /**
     * Detach a block by id from wherever it currently lives and re-insert it as
     * a child of `parent_id` (a section id or another block id) at `index`.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function moveBlock(array $document, array $payload, BlockRegistry $registry): array
    {
        $blockId  = self::requireString($payload, 'block_id', '$.payload.block_id');
        $parentId = self::requireString($payload, 'parent_id', '$.payload.parent_id');
        $index    = self::requireInt($payload, 'index', '$.payload.index');

        $sections = is_array($document['sections'] ?? null) ? $document['sections'] : [];

        $extracted = null;
        foreach ($sections as $sIdx => $section) {
            if (!is_array($section)) {
                continue;
            }
            $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];
            $blocks = self::extractBlockRecursive($blocks, $blockId, $extracted);
            $sections[$sIdx]['blocks'] = $blocks;
        }

        self::assertFound($extracted !== null, 'block_id', $blockId, 'block');

        $insertPayload = ['parent_id' => $parentId, 'index' => $index, 'block' => $extracted];
        // Re-use insertBlock's registry/allowsChildren checks, but with the
        // already-existing block (preserve id/version instead of minting new ones).
        $document['sections'] = $sections;
        return self::reinsertExtractedBlock($document, $parentId, $index, $extracted, $registry);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param null|array<string, mixed> $extracted
     * @return list<array<string, mixed>>
     */
    private static function extractBlockRecursive(array $blocks, string $blockId, ?array &$extracted): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if ($extracted === null && ($block['id'] ?? null) === $blockId) {
                $extracted = $block;
                continue;
            }
            $children = is_array($block['children'] ?? null) ? $block['children'] : [];
            if ($children !== [] && $extracted === null) {
                $block['children'] = self::extractBlockRecursive($children, $blockId, $extracted);
            }
            $out[] = $block;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private static function reinsertExtractedBlock(array $document, string $parentId, int $index, array $block, BlockRegistry $registry): array
    {
        $sections = is_array($document['sections'] ?? null) ? $document['sections'] : [];

        foreach ($sections as $sIdx => $section) {
            if (!is_array($section) || ($section['id'] ?? null) !== $parentId) {
                continue;
            }
            $blocks = is_array($section['blocks'] ?? null) ? array_values($section['blocks']) : [];
            $clampedIndex = max(0, min($index, count($blocks)));
            array_splice($blocks, $clampedIndex, 0, [$block]);
            $sections[$sIdx]['blocks'] = $blocks;
            $document['sections'] = $sections;
            return $document;
        }

        $inserted = false;
        foreach ($sections as $sIdx => $section) {
            if (!is_array($section)) {
                continue;
            }
            $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];
            $blocks = self::insertIntoBlockChildren($blocks, $parentId, $index, $block, $registry, $inserted);
            $sections[$sIdx]['blocks'] = $blocks;
            if ($inserted) {
                break;
            }
        }

        self::assertFound($inserted, 'parent_id', $parentId, 'section or child-capable block');
        $document['sections'] = $sections;
        return $document;
    }

    /**
     * Replace `block.props` / `style` / `visibility` / `bindings` wholesale for a target block id.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function updateBlockField(array $document, array $payload, string $field): array
    {
        $blockId = self::requireString($payload, 'block_id', '$.payload.block_id');
        $value   = self::requireObject($payload, $field, "\$.payload.{$field}");

        $sections = is_array($document['sections'] ?? null) ? $document['sections'] : [];
        $found = false;
        foreach ($sections as $sIdx => $section) {
            if (!is_array($section)) {
                continue;
            }
            $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];
            $sections[$sIdx]['blocks'] = self::updateBlockFieldRecursive($blocks, $blockId, $field, $value, $found);
        }

        self::assertFound($found, 'block_id', $blockId, 'block');
        $document['sections'] = $sections;
        return $document;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed> $value
     * @return list<array<string, mixed>>
     */
    private static function updateBlockFieldRecursive(array $blocks, string $blockId, string $field, array $value, bool &$found): array
    {
        foreach ($blocks as $idx => $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['id'] ?? null) === $blockId) {
                $blocks[$idx][$field] = $value;
                $found = true;
                return $blocks;
            }
            $children = is_array($block['children'] ?? null) ? $block['children'] : [];
            if ($children !== []) {
                $blocks[$idx]['children'] = self::updateBlockFieldRecursive($children, $blockId, $field, $value, $found);
                if ($found) {
                    return $blocks;
                }
            }
        }
        return $blocks;
    }

    // ── Payload guards ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function requireObject(array $payload, string $key, string $path): array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new StudioValidationException([
                ['path' => $path, 'code' => 'invalid_payload_field', 'message' => "payload.{$key} must be a JSON object."],
            ]);
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function requireString(array $payload, string $key, string $path): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new StudioValidationException([
                ['path' => $path, 'code' => 'invalid_payload_field', 'message' => "payload.{$key} must be a non-empty string."],
            ]);
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function requireInt(array $payload, string $key, string $path): int
    {
        $value = $payload[$key] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new StudioValidationException([
                ['path' => $path, 'code' => 'invalid_payload_field', 'message' => "payload.{$key} must be a non-negative integer."],
            ]);
        }
        return $value;
    }

    private static function assertFound(bool $found, string $key, string $value, string $noun): void
    {
        if (!$found) {
            throw new StudioValidationException([
                ['path' => "\$.payload.{$key}", 'code' => 'operation_target_not_found', 'message' => "No {$noun} with {$key} '{$value}' exists in the document."],
            ]);
        }
    }
}
