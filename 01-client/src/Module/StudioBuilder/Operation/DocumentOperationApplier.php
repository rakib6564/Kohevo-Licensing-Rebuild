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
use Slate\Module\StudioBuilder\Document\LayerLock;
use Slate\Module\StudioBuilder\Document\StyleSurface;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

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

        // The layer lock is enforced here, on the one mutation path (builder UI, AI/MCP, imports of operations).
        LayerLock::assertAllowed($document, $operation);

        return match ($operation->op) {
            DocumentOperation::OP_UPDATE_SETTINGS => self::updateSettings($document, $payload),
            DocumentOperation::OP_UPDATE_SEO => self::updateSeo($document, $payload),
            DocumentOperation::OP_UPDATE_TEMPLATE => self::updateTemplate($document, $payload),
            DocumentOperation::OP_INSERT_SECTION => self::insertSection($document, $payload),
            DocumentOperation::OP_REMOVE_SECTION => self::removeSection($document, $payload),
            DocumentOperation::OP_MOVE_SECTION => self::moveSection($document, $payload),
            DocumentOperation::OP_DUPLICATE_SECTION => self::duplicateSection($document, $payload),
            DocumentOperation::OP_UPDATE_SECTION_LABEL => self::updateSectionLabel($document, $payload),
            DocumentOperation::OP_UPDATE_SECTION_LAYOUT => self::updateSectionField($document, $payload, 'layout'),
            DocumentOperation::OP_UPDATE_SECTION_VISIBILITY => self::updateSectionField($document, $payload, 'visibility'),
            DocumentOperation::OP_UPDATE_SECTION_LOCKED => self::updateSectionLocked($document, $payload),
            DocumentOperation::OP_INSERT_BLOCK => self::insertBlock($document, $payload, $registry),
            DocumentOperation::OP_REMOVE_BLOCK => self::removeBlock($document, $payload),
            DocumentOperation::OP_MOVE_BLOCK => self::moveBlock($document, $payload, $registry),
            DocumentOperation::OP_DUPLICATE_BLOCK => self::duplicateBlock($document, $payload),
            DocumentOperation::OP_UPDATE_BLOCK_PROPS => self::updateBlockField($document, $payload, 'props'),
            DocumentOperation::OP_UPDATE_BLOCK_STYLE => self::updateBlockField($document, $payload, 'style'),
            DocumentOperation::OP_UPDATE_BLOCK_VISIBILITY => self::updateBlockField($document, $payload, 'visibility'),
            DocumentOperation::OP_UPDATE_BLOCK_BINDINGS => self::updateBlockField($document, $payload, 'bindings'),
            DocumentOperation::OP_UPDATE_BLOCK_RESPONSIVE => self::updateBlockField($document, $payload, 'responsive'),
            DocumentOperation::OP_UPDATE_BLOCK_CLASS_NAMES => self::updateBlockArrayField($document, $payload, 'classNames'),
            DocumentOperation::OP_UPDATE_BLOCK_ATTRIBUTES => self::updateBlockField($document, $payload, 'attributes'),
            DocumentOperation::OP_UPDATE_BLOCK_INTERACTIONS => self::updateBlockField($document, $payload, 'interactions'),
            DocumentOperation::OP_UPDATE_BLOCK_ANIMATION => self::updateBlockField($document, $payload, 'animation'),
            DocumentOperation::OP_UPDATE_BLOCK_META => self::updateBlockMeta($document, $payload),
            DocumentOperation::OP_UPDATE_BLOCK_STYLE_STATES => self::updateBlockStyleStates($document, $payload),
            DocumentOperation::OP_UPDATE_BLOCK_TAG => self::updateBlockTag($document, $payload),
            DocumentOperation::OP_RESET_BLOCK_STYLE_PROPERTY => self::resetBlockStyleProperty($document, $payload),
            DocumentOperation::OP_UPDATE_SECTION_STYLE => self::updateSectionStyle($document, $payload),
            DocumentOperation::OP_UPDATE_SECTION_ANIMATION => self::updateSectionObjectField($document, $payload, 'animation'),
            DocumentOperation::OP_UPDATE_SECTION_INTERACTIONS => self::updateSectionObjectField($document, $payload, 'interactions'),
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

    /**
     * Duplicate a section directly after itself, minting a fresh section ID
     * and fresh IDs for all blocks in its subtree.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function duplicateSection(array $document, array $payload): array
    {
        $sectionId = self::requireString($payload, 'section_id', '$.payload.section_id');
        $sections = is_array($document['sections'] ?? null) ? array_values($document['sections']) : [];

        $foundIndex = null;
        foreach ($sections as $idx => $section) {
            if (is_array($section) && ($section['id'] ?? null) === $sectionId) {
                $foundIndex = $idx;
                break;
            }
        }
        self::assertFound($foundIndex !== null, 'section_id', $sectionId, 'section');

        $source = $sections[$foundIndex];
        $cloned = $source;
        $cloned['id'] = CanonicalDocumentSchema::newSectionId();
        $cloned['label'] = ($source['label'] ?? 'Section') . ' (Copy)';
        $blocks = is_array($source['blocks'] ?? null) ? $source['blocks'] : [];
        $cloned['blocks'] = DocumentCopier::copyBlocks($blocks);

        array_splice($sections, $foundIndex + 1, 0, [$cloned]);
        $document['sections'] = $sections;
        return $document;
    }

    /**
     * Rename / update section label.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function updateSectionLabel(array $document, array $payload): array
    {
        $sectionId = self::requireString($payload, 'section_id', '$.payload.section_id');
        $label = self::requireString($payload, 'label', '$.payload.label');
        if (mb_strlen($label, 'UTF-8') > 120 || FieldSchema::containsExecutableOrSqlFragment($label)) {
            throw new StudioValidationException([
                ['path' => '$.payload.label', 'code' => 'invalid_section_label', 'message' => 'Section label must be a safe string <= 120 chars.'],
            ]);
        }

        $sections = is_array($document['sections'] ?? null) ? $document['sections'] : [];
        $found = false;
        foreach ($sections as $idx => $section) {
            if (is_array($section) && ($section['id'] ?? null) === $sectionId) {
                $sections[$idx]['label'] = $label;
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
        foreach (CanonicalDocumentSchema::OPTIONAL_BLOCK_KEYS as $optKey) {
            if (isset($block[$optKey])) {
                $newBlock[$optKey] = $block[$optKey];
            }
        }

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
     * Replace an array field (e.g. `classNames`) wholesale for a target block id.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function updateBlockArrayField(array $document, array $payload, string $field): array
    {
        $blockId = self::requireString($payload, 'block_id', '$.payload.block_id');
        $value   = self::requireList($payload, $field, "\$.payload.{$field}");

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

    /**
     * Duplicate a block directly after itself in its parent container or section,
     * re-minting fresh IDs for the block and all children in its subtree.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function duplicateBlock(array $document, array $payload): array
    {
        $blockId = self::requireString($payload, 'block_id', '$.payload.block_id');
        $sections = is_array($document['sections'] ?? null) ? $document['sections'] : [];

        $duplicated = false;
        foreach ($sections as $sIdx => $section) {
            if (!is_array($section)) {
                continue;
            }
            $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];
            $sections[$sIdx]['blocks'] = self::duplicateBlockRecursive($blocks, $blockId, $duplicated);
            if ($duplicated) {
                break;
            }
        }

        self::assertFound($duplicated, 'block_id', $blockId, 'block');
        $document['sections'] = $sections;
        return $document;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private static function duplicateBlockRecursive(array $blocks, string $blockId, bool &$duplicated): array
    {
        $result = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $result[] = $block;
            if (($block['id'] ?? null) === $blockId) {
                $copied = DocumentCopier::copyBlocks([$block]);
                if (!empty($copied[0])) {
                    $result[] = $copied[0];
                }
                $duplicated = true;
                continue;
            }
            $children = is_array($block['children'] ?? null) ? $block['children'] : [];
            if ($children !== [] && !$duplicated) {
                $newChildren = self::duplicateBlockRecursive($children, $blockId, $duplicated);
                if ($duplicated) {
                    $result[count($result) - 1]['children'] = $newChildren;
                }
            }
        }
        return $result;
    }

    /**
     * Lock or unlock a section. `locked: false` removes the key so unlocked
     * documents stay byte-identical to ones that never used the feature.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function updateSectionLocked(array $document, array $payload): array
    {
        $sectionId = self::requireString($payload, 'section_id', '$.payload.section_id');
        if (!is_bool($payload['locked'] ?? null)) {
            throw new StudioValidationException([
                ['path' => '$.payload.locked', 'code' => 'invalid_payload_field', 'message' => 'payload.locked must be a boolean.'],
            ]);
        }

        $sections = is_array($document['sections'] ?? null) ? $document['sections'] : [];
        $found = false;
        foreach ($sections as $idx => $section) {
            if (is_array($section) && ($section['id'] ?? null) === $sectionId) {
                if ($payload['locked']) {
                    $sections[$idx]['locked'] = true;
                } else {
                    unset($sections[$idx]['locked']);
                }
                $found = true;
                break;
            }
        }
        self::assertFound($found, 'section_id', $sectionId, 'section');

        $document['sections'] = $sections;
        return $document;
    }

    // ── B2-P3b: states, tag, section style, single-property reset ──────────

    /**
     * Run `$change` on the block with this id (anywhere in the tree) and store what it returns;
     * a returned null removes nothing — callers unset keys on the block themselves.
     *
     * @param list<mixed> $blocks
     * @param callable(array<string, mixed>): array<string, mixed> $change
     * @return list<mixed>
     */
    private static function changeBlock(array $blocks, string $blockId, callable $change, bool &$found): array
    {
        foreach ($blocks as $idx => $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['id'] ?? null) === $blockId) {
                $blocks[$idx] = $change($block);
                $found = true;
                return $blocks;
            }
            $children = is_array($block['children'] ?? null) ? $block['children'] : [];
            if ($children !== []) {
                $blocks[$idx]['children'] = self::changeBlock($children, $blockId, $change, $found);
                if ($found) {
                    return $blocks;
                }
            }
        }
        return $blocks;
    }

    /**
     * @param array<string, mixed> $document
     * @param callable(array<string, mixed>): array<string, mixed> $change
     * @return array<string, mixed>
     */
    private static function changeBlockInDocument(array $document, string $blockId, callable $change): array
    {
        $sections = is_array($document['sections'] ?? null) ? $document['sections'] : [];
        $found = false;
        foreach ($sections as $sIdx => $section) {
            if (is_array($section)) {
                $sections[$sIdx]['blocks'] = self::changeBlock(is_array($section['blocks'] ?? null) ? $section['blocks'] : [], $blockId, $change, $found);
                if ($found) {
                    break;
                }
            }
        }
        self::assertFound($found, 'block_id', $blockId, 'block');
        $document['sections'] = $sections;
        return $document;
    }

    /**
     * Replace the block's `style_states` wholesale; an empty object removes the key.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function updateBlockStyleStates(array $document, array $payload): array
    {
        $blockId = self::requireString($payload, 'block_id', '$.payload.block_id');
        $states  = self::requireObject($payload, 'style_states', '$.payload.style_states');
        return self::changeBlockInDocument($document, $blockId, static function (array $block) use ($states): array {
            if ($states === []) {
                unset($block['style_states']);
            } else {
                $block['style_states'] = $states;
            }
            return $block;
        });
    }

    /**
     * Set the block's wrapper element; null or `div` (the default) removes the key.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function updateBlockTag(array $document, array $payload): array
    {
        $blockId = self::requireString($payload, 'block_id', '$.payload.block_id');
        $tag = $payload['tag'] ?? null;
        if ($tag !== null && (!is_string($tag) || !in_array($tag, CanonicalDocumentSchema::ALLOWED_BLOCK_TAGS, true))) {
            throw new StudioValidationException([
                ['path' => '$.payload.tag', 'code' => 'invalid_tag', 'message' => 'payload.tag must be null or one of: ' . implode(', ', CanonicalDocumentSchema::ALLOWED_BLOCK_TAGS) . '.'],
            ]);
        }
        return self::changeBlockInDocument($document, $blockId, static function (array $block) use ($tag): array {
            if ($tag === null || $tag === 'div') {
                unset($block['tag']);
            } else {
                $block['tag'] = $tag;
            }
            return $block;
        });
    }

    /**
     * Replace a section's `style` wholesale; an empty object removes the key.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    /**
     * Replace a section's `animation` or `interactions` object; `{}` removes the key (canonical form).
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function updateSectionObjectField(array $document, array $payload, string $field): array
    {
        $sectionId = self::requireString($payload, 'section_id', '$.payload.section_id');
        $value     = self::requireObject($payload, $field, "\$.payload.{$field}");

        $sections = is_array($document['sections'] ?? null) ? $document['sections'] : [];
        $found = false;
        foreach ($sections as $idx => $section) {
            if (is_array($section) && ($section['id'] ?? null) === $sectionId) {
                if ($value === []) {
                    unset($sections[$idx][$field]);
                } else {
                    $sections[$idx][$field] = $value;
                }
                $found = true;
                break;
            }
        }
        self::assertFound($found, 'section_id', $sectionId, 'section');
        $document['sections'] = $sections;
        return $document;
    }

    private static function updateSectionStyle(array $document, array $payload): array
    {
        $sectionId = self::requireString($payload, 'section_id', '$.payload.section_id');
        $style     = self::requireObject($payload, 'style', '$.payload.style');

        $sections = is_array($document['sections'] ?? null) ? $document['sections'] : [];
        $found = false;
        foreach ($sections as $idx => $section) {
            if (is_array($section) && ($section['id'] ?? null) === $sectionId) {
                if ($style === []) {
                    unset($sections[$idx]['style']);
                } else {
                    $sections[$idx]['style'] = $style;
                }
                $found = true;
                break;
            }
        }
        self::assertFound($found, 'section_id', $sectionId, 'section');
        $document['sections'] = $sections;
        return $document;
    }

    /**
     * Remove ONE style property (a dotted path such as `typography.size` or `effects.transform.rotate`),
     * from the block's style or, with `state`, from one interaction state. Emptied parents are removed,
     * and an absent property is a no-op. Unlike `update_block_style` this cannot overwrite a neighbour.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function resetBlockStyleProperty(array $document, array $payload): array
    {
        $blockId  = self::requireString($payload, 'block_id', '$.payload.block_id');
        $property = $payload['property'] ?? null;
        $state    = $payload['state'] ?? null;
        if (!is_string($property) || preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){0,3}$/', $property) !== 1) {
            throw new StudioValidationException([
                ['path' => '$.payload.property', 'code' => 'invalid_property', 'message' => 'payload.property must be a dotted style path such as typography.size.'],
            ]);
        }
        if ($state !== null && (!is_string($state) || !isset(StyleSurface::STATE_SELECTORS[$state]))) {
            throw new StudioValidationException([
                ['path' => '$.payload.state', 'code' => 'invalid_state', 'message' => 'payload.state must be hover, focus, active or disabled.'],
            ]);
        }
        $segments = explode('.', $property);
        $roots = $state === null ? CanonicalDocumentSchema::ALLOWED_STYLE_KEYS : StyleSurface::STATE_KEYS;
        if (!in_array($segments[0], $roots, true)) {
            throw new StudioValidationException([
                ['path' => '$.payload.property', 'code' => 'invalid_property', 'message' => "'{$segments[0]}' is not a style property" . ($state !== null ? ' a state can set.' : '.')],
            ]);
        }

        return self::changeBlockInDocument($document, $blockId, static function (array $block) use ($segments, $state): array {
            if ($state === null) {
                $block['style'] = self::unsetPath(is_array($block['style'] ?? null) ? $block['style'] : [], $segments);
                return $block;
            }
            $states = is_array($block['style_states'] ?? null) ? $block['style_states'] : [];
            if (isset($states[$state]) && is_array($states[$state])) {
                $partial = self::unsetPath($states[$state], $segments);
                if ($partial === []) {
                    unset($states[$state]);
                } else {
                    $states[$state] = $partial;
                }
            }
            if ($states === []) {
                unset($block['style_states']);
            } else {
                $block['style_states'] = $states;
            }
            return $block;
        });
    }

    /**
     * @param array<string, mixed> $tree
     * @param list<string> $segments
     * @return array<string, mixed>
     */
    private static function unsetPath(array $tree, array $segments): array
    {
        $head = array_shift($segments);
        if (!array_key_exists($head, $tree)) {
            return $tree;
        }
        if ($segments === []) {
            unset($tree[$head]);
            return $tree;
        }
        if (is_array($tree[$head])) {
            $child = self::unsetPath($tree[$head], $segments);
            if ($child === []) {
                unset($tree[$head]);
            } else {
                $tree[$head] = $child;
            }
        }
        return $tree;
    }

    /**
     * Patch a block's `metadata`: `label` (editor display name; null or '' clears it)
     * and `locked` (bool; false clears it). Keys not in the payload are untouched, and
     * an emptied metadata object is removed.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function updateBlockMeta(array $document, array $payload): array
    {
        $blockId = self::requireString($payload, 'block_id', '$.payload.block_id');

        $unknown = array_diff(array_keys($payload), ['block_id', ...CanonicalDocumentSchema::ALLOWED_BLOCK_METADATA_KEYS]);
        if ($unknown !== []) {
            throw new StudioValidationException([
                ['path' => '$.payload', 'code' => 'unknown_property', 'message' => 'Block metadata accepts only: ' . implode(', ', CanonicalDocumentSchema::ALLOWED_BLOCK_METADATA_KEYS) . '.'],
            ]);
        }

        $patch = [];
        if (array_key_exists('label', $payload)) {
            $label = $payload['label'];
            if ($label !== null && !is_string($label)) {
                throw new StudioValidationException([
                    ['path' => '$.payload.label', 'code' => 'invalid_block_label', 'message' => 'Block label must be a string or null.'],
                ]);
            }
            $label = $label === null ? '' : trim($label);
            if (mb_strlen($label, 'UTF-8') > CanonicalDocumentSchema::BLOCK_LABEL_MAX_LENGTH
                || ($label !== '' && FieldSchema::containsExecutableOrSqlFragment($label))
                || preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
                throw new StudioValidationException([
                    ['path' => '$.payload.label', 'code' => 'invalid_block_label', 'message' => 'Block label must be a safe string of at most ' . CanonicalDocumentSchema::BLOCK_LABEL_MAX_LENGTH . ' characters.'],
                ]);
            }
            $patch['label'] = $label;
        }
        if (array_key_exists('locked', $payload)) {
            if (!is_bool($payload['locked'])) {
                throw new StudioValidationException([
                    ['path' => '$.payload.locked', 'code' => 'invalid_payload_field', 'message' => 'payload.locked must be a boolean.'],
                ]);
            }
            $patch['locked'] = $payload['locked'];
        }

        $sections = is_array($document['sections'] ?? null) ? $document['sections'] : [];
        $found = false;
        foreach ($sections as $sIdx => $section) {
            if (!is_array($section)) {
                continue;
            }
            $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];
            $sections[$sIdx]['blocks'] = self::patchBlockMetaRecursive($blocks, $blockId, $patch, $found);
            if ($found) {
                break;
            }
        }

        self::assertFound($found, 'block_id', $blockId, 'block');
        $document['sections'] = $sections;
        return $document;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed> $patch
     * @return list<array<string, mixed>>
     */
    private static function patchBlockMetaRecursive(array $blocks, string $blockId, array $patch, bool &$found): array
    {
        foreach ($blocks as $idx => $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['id'] ?? null) === $blockId) {
                $meta = is_array($block['metadata'] ?? null) ? $block['metadata'] : [];
                foreach ($patch as $key => $value) {
                    if ($value === '' || $value === false) {
                        unset($meta[$key]);
                    } else {
                        $meta[$key] = $value;
                    }
                }
                if ($meta === []) {
                    unset($blocks[$idx]['metadata']);
                } else {
                    $blocks[$idx]['metadata'] = $meta;
                }
                $found = true;
                return $blocks;
            }
            $children = is_array($block['children'] ?? null) ? $block['children'] : [];
            if ($children !== []) {
                $blocks[$idx]['children'] = self::patchBlockMetaRecursive($children, $blockId, $patch, $found);
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
     * @return list<mixed>
     */
    private static function requireList(array $payload, string $key, string $path): array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new StudioValidationException([
                ['path' => $path, 'code' => 'invalid_payload_field', 'message' => "payload.{$key} must be a JSON array (list)."],
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
