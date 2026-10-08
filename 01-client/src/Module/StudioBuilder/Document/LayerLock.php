<?php
/**
 * Kohevo Studio (studio-builder) — layer lock rules.
 *
 * A section carries `locked: true`; a block carries `metadata.locked: true`.
 * A node is *effectively* locked when it or any ancestor is locked. The lock
 * stops accidental or automated edits (the AI/MCP path goes through the same
 * operation applier): while a node is effectively locked, its content cannot be
 * edited, moved, restyled, renamed or removed, and nothing can be inserted into
 * it. Removing a node that CONTAINS a locked descendant is refused too, so a
 * lock can never be destroyed by deleting its parent. Duplicating is allowed;
 * the copy keeps its lock flags.
 *
 * Unlocking is the one edit a locked node accepts, and only when no ancestor is
 * locked (unlock the outermost lock first).
 *
 * Pure and stateless: reads a canonical document array, never mutates it.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;

final class LayerLock
{
    /** Block operations that edit a block's own content or look. */
    private const BLOCK_EDIT_OPS = [
        DocumentOperation::OP_UPDATE_BLOCK_PROPS,
        DocumentOperation::OP_UPDATE_BLOCK_STYLE,
        DocumentOperation::OP_UPDATE_BLOCK_VISIBILITY,
        DocumentOperation::OP_UPDATE_BLOCK_BINDINGS,
        DocumentOperation::OP_UPDATE_BLOCK_RESPONSIVE,
        DocumentOperation::OP_UPDATE_BLOCK_CLASS_NAMES,
        DocumentOperation::OP_UPDATE_BLOCK_ATTRIBUTES,
        DocumentOperation::OP_UPDATE_BLOCK_INTERACTIONS,
        DocumentOperation::OP_UPDATE_BLOCK_ANIMATION,
        DocumentOperation::OP_UPDATE_BLOCK_STYLE_STATES,
        DocumentOperation::OP_UPDATE_BLOCK_TAG,
        DocumentOperation::OP_RESET_BLOCK_STYLE_PROPERTY,
    ];

    /** Section operations that edit a section's own label, layout or visibility, or move it. */
    private const SECTION_EDIT_OPS = [
        DocumentOperation::OP_UPDATE_SECTION_LABEL,
        DocumentOperation::OP_UPDATE_SECTION_LAYOUT,
        DocumentOperation::OP_UPDATE_SECTION_VISIBILITY,
        DocumentOperation::OP_UPDATE_SECTION_STYLE,
        DocumentOperation::OP_MOVE_SECTION,
    ];

    /** True when the node itself (not its ancestors) is locked. */
    public static function isLocked(array $node): bool
    {
        if (($node['locked'] ?? null) === true) {
            return true;
        }
        $meta = $node['metadata'] ?? null;
        return is_array($meta) && ($meta['locked'] ?? null) === true;
    }

    /**
     * Flat id → {parent, locked} map for every section and block.
     *
     * @param array<string, mixed> $document
     * @return array<string, array{parent: ?string, locked: bool}>
     */
    public static function index(array $document): array
    {
        $index = [];
        $walk = static function (array $blocks, string $parent) use (&$walk, &$index): void {
            foreach ($blocks as $block) {
                if (!is_array($block) || !is_string($block['id'] ?? null)) {
                    continue;
                }
                $index[$block['id']] = ['parent' => $parent, 'locked' => self::isLocked($block)];
                $children = $block['children'] ?? null;
                if (is_array($children)) {
                    $walk($children, $block['id']);
                }
            }
        };
        foreach ((array) ($document['sections'] ?? []) as $section) {
            if (!is_array($section) || !is_string($section['id'] ?? null)) {
                continue;
            }
            $index[$section['id']] = ['parent' => null, 'locked' => self::isLocked($section)];
            $blocks = $section['blocks'] ?? null;
            if (is_array($blocks)) {
                $walk($blocks, $section['id']);
            }
        }
        return $index;
    }

    /**
     * @param array<string, array{parent: ?string, locked: bool}> $index
     */
    public static function effectivelyLocked(array $index, string $id): bool
    {
        for ($cur = $id; $cur !== null && isset($index[$cur]); $cur = $index[$cur]['parent']) {
            if ($index[$cur]['locked']) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, array{parent: ?string, locked: bool}> $index
     */
    public static function ancestorLocked(array $index, string $id): bool
    {
        $parent = $index[$id]['parent'] ?? null;
        return $parent !== null && self::effectivelyLocked($index, $parent);
    }

    /**
     * @param array<string, array{parent: ?string, locked: bool}> $index
     */
    public static function hasLockedDescendant(array $index, string $id): bool
    {
        foreach ($index as $nodeId => $entry) {
            if (!$entry['locked'] || $nodeId === $id) {
                continue;
            }
            for ($cur = $entry['parent']; $cur !== null && isset($index[$cur]); $cur = $index[$cur]['parent']) {
                if ($cur === $id) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Refuse an operation the lock forbids. Unknown ids are left for the
     * applier to report as "not found".
     *
     * @param array<string, mixed> $document
     * @throws StudioValidationException with code `node_locked`
     */
    public static function assertAllowed(array $document, DocumentOperation $operation): void
    {
        $op = $operation->op;
        $p  = $operation->payload;

        $touchesLock = in_array($op, self::BLOCK_EDIT_OPS, true)
            || in_array($op, self::SECTION_EDIT_OPS, true)
            || in_array($op, [
                DocumentOperation::OP_INSERT_BLOCK,
                DocumentOperation::OP_MOVE_BLOCK,
                DocumentOperation::OP_REMOVE_BLOCK,
                DocumentOperation::OP_REMOVE_SECTION,
                DocumentOperation::OP_UPDATE_BLOCK_META,
            ], true);
        if (!$touchesLock) {
            return;
        }

        $index = self::index($document);
        $id = static fn (string $key): ?string => is_string($p[$key] ?? null) ? $p[$key] : null;

        if (in_array($op, self::BLOCK_EDIT_OPS, true)) {
            self::refuseIf(self::locked($index, $id('block_id')), 'block_id', 'edit');
            return;
        }
        if (in_array($op, self::SECTION_EDIT_OPS, true)) {
            self::refuseIf(self::locked($index, $id('section_id')), 'section_id', 'edit');
            return;
        }

        switch ($op) {
            case DocumentOperation::OP_INSERT_BLOCK:
                self::refuseIf(self::locked($index, $id('parent_id')), 'parent_id', 'insert into');
                break;
            case DocumentOperation::OP_MOVE_BLOCK:
                self::refuseIf(self::locked($index, $id('block_id')), 'block_id', 'move');
                self::refuseIf(self::locked($index, $id('parent_id')), 'parent_id', 'move into');
                break;
            case DocumentOperation::OP_REMOVE_BLOCK:
                self::refuseIf(self::lockedOrHolds($index, $id('block_id')), 'block_id', 'remove');
                break;
            case DocumentOperation::OP_REMOVE_SECTION:
                self::refuseIf(self::lockedOrHolds($index, $id('section_id')), 'section_id', 'remove');
                break;
            case DocumentOperation::OP_UPDATE_BLOCK_META:
                $blockId = $id('block_id');
                $changesMore = count(array_diff(array_keys($p), ['block_id', 'locked'])) > 0;
                if ($changesMore) {
                    self::refuseIf(self::locked($index, $blockId), 'block_id', 'rename');
                }
                if (array_key_exists('locked', $p) && $blockId !== null && isset($index[$blockId])) {
                    self::refuseIf(self::ancestorLocked($index, $blockId), 'block_id', 'change the lock of');
                }
                break;
            default:
                break;
        }
    }

    /** @param array<string, array{parent: ?string, locked: bool}> $index */
    private static function locked(array $index, ?string $id): bool
    {
        return $id !== null && isset($index[$id]) && self::effectivelyLocked($index, $id);
    }

    /** @param array<string, array{parent: ?string, locked: bool}> $index */
    private static function lockedOrHolds(array $index, ?string $id): bool
    {
        return $id !== null && isset($index[$id])
            && (self::effectivelyLocked($index, $id) || self::hasLockedDescendant($index, $id));
    }

    private static function refuseIf(bool $refuse, string $key, string $verb): void
    {
        if ($refuse) {
            throw new StudioValidationException([
                ['path' => "\$.payload.{$key}", 'code' => 'node_locked', 'message' => "Cannot {$verb} a locked layer (or one that contains locked layers). Unlock it first."],
            ]);
        }
    }
}
