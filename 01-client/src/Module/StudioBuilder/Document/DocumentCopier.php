<?php
/**
 * Kohevo Studio (studio-builder) — Copy semantics for canonical content.
 *
 * The ONE place that turns canonical content into an independent copy:
 * every section and block id is re-minted (`CanonicalDocumentSchema::
 * newSectionId()` / `newBlockId()`), so the copy can be inserted into any
 * document without colliding with the source or with an earlier copy.
 * Nothing else about the content is interpreted here — the copy still goes
 * through the full validate/normalize pipeline of whatever document it lands
 * in.
 *
 * This is the "Reusable preset" concept (target architecture §9): the
 * receiving document OWNS the copy. It is deliberately distinct from a live
 * "Global reference" (`section.global_ref`), which copies nothing.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

final class DocumentCopier
{
    /**
     * A deep copy of a section with fresh ids everywhere. The copy never
     * carries a live reference: a copy owns its content.
     *
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    public static function copySection(array $section): array
    {
        $copy = $section;
        $copy['id']         = CanonicalDocumentSchema::newSectionId();
        $copy['global_ref'] = null;
        $copy['blocks']     = self::copyBlocks(is_array($section['blocks'] ?? null) ? $section['blocks'] : []);
        return $copy;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    public static function copyBlock(array $block): array
    {
        $copy = $block;
        $copy['id']       = CanonicalDocumentSchema::newBlockId();
        $copy['children'] = self::copyBlocks(is_array($block['children'] ?? null) ? $block['children'] : []);
        return $copy;
    }

    /**
     * @param list<mixed> $blocks
     * @return list<array<string, mixed>>
     */
    public static function copyBlocks(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (is_array($block)) {
                $out[] = self::copyBlock($block);
            }
        }
        return $out;
    }

    /**
     * Find a section or block by id anywhere in a document.
     *
     * @param array<string, mixed> $document
     * @return null|array{kind: 'section'|'block', node: array<string, mixed>, index: int}
     */
    public static function findNode(array $document, string $nodeId): ?array
    {
        foreach ((array) ($document['sections'] ?? []) as $index => $section) {
            if (!is_array($section)) {
                continue;
            }
            if (($section['id'] ?? null) === $nodeId) {
                return ['kind' => 'section', 'node' => $section, 'index' => (int) $index];
            }
            $found = self::findBlock(is_array($section['blocks'] ?? null) ? $section['blocks'] : [], $nodeId);
            if ($found !== null) {
                return ['kind' => 'block', 'node' => $found, 'index' => (int) $index];
            }
        }
        return null;
    }

    /**
     * @param list<mixed> $blocks
     * @return null|array<string, mixed>
     */
    private static function findBlock(array $blocks, string $blockId): ?array
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['id'] ?? null) === $blockId) {
                return $block;
            }
            $found = self::findBlock(is_array($block['children'] ?? null) ? $block['children'] : [], $blockId);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }
}
