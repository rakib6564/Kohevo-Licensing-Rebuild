<?php
declare(strict_types=1);

namespace Slate\Presentation;

use InvalidArgumentException;

/** Pure immutable operations used by React/Craft.js/dnd-kit adapters. */
final class DocumentOperations
{
    /** @param array<string,mixed> $document @param array<string,mixed> $block @return array<string,mixed> */
    public static function insertBlock(array $document, string $sectionId, int $index, array $block): array
    {
        return self::insertBlockAtPath($document, [$sectionId], $index, $block);
    }

    /** Insert into a nested block collection. Path: [sectionId, blockIndex, collection, childIndex, ...]. */
    public static function insertBlockAtPath(array $document, array $path, int $index, array $block): array
    {
        $out = DocumentSchema::normalize($document);
        $collection =& self::collectionAtPath($out, $path);
        if ($index < 0 || $index > count($collection)) throw new InvalidArgumentException('Block insertion index is out of range.');
        array_splice($collection, $index, 0, [$block]);
        return $out;
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    public static function moveBlock(array $document, string $fromSectionId, int $fromIndex, string $toSectionId, int $toIndex): array
    {
        return self::moveBlockAtPaths($document, [$fromSectionId], $fromIndex, [$toSectionId], $toIndex);
    }

    /** Move between top-level or nested block collections. */
    public static function moveBlockAtPaths(array $document, array $fromPath, int $fromIndex, array $toPath, int $toIndex): array
    {
        $out = DocumentSchema::normalize($document);
        $from =& self::collectionAtPath($out, $fromPath);
        if (!isset($from[$fromIndex])) throw new InvalidArgumentException('Source block does not exist.');
        $block = array_splice($from, $fromIndex, 1)[0];
        $to =& self::collectionAtPath($out, $toPath);
        if ($toIndex < 0 || $toIndex > count($to)) throw new InvalidArgumentException('Block destination index is out of range.');
        array_splice($to, $toIndex, 0, [$block]);
        return $out;
    }

    /** @param array<string,mixed> $document @param array<string,mixed> $patch @return array<string,mixed> */
    public static function updateBlockProps(array $document, string $sectionId, int $index, array $patch): array
    {
        return self::updateBlockPropsAtPath($document, [$sectionId], $index, $patch);
    }

    /** Update a block in a nested collection without mutating the source document. */
    public static function updateBlockPropsAtPath(array $document, array $path, int $index, array $patch): array
    {
        $out = DocumentSchema::normalize($document);
        $collection =& self::collectionAtPath($out, $path);
        if (!isset($collection[$index]) || !is_array($collection[$index])) throw new InvalidArgumentException('Block does not exist.');
        $props = is_array($collection[$index]['props'] ?? null) ? $collection[$index]['props'] : [];
        $collection[$index]['props'] = array_merge($props, $patch);
        return $out;
    }

    /** @param array<string,mixed> $document @return array<int|string,mixed> */
    private static function &collectionAtPath(array &$document, array $path): array
    {
        if ($path === [] || !is_string($path[0])) throw new InvalidArgumentException('Block collection path must begin with a section ID.');
        $sectionIndex = self::sectionIndex($document, $path[0]);
        $collection =& $document['sections'][$sectionIndex]['blocks'];
        for ($i = 1; $i < count($path); $i++) {
            $token = $path[$i];
            if (is_int($token)) {
                if (!isset($collection[$token]) || !is_array($collection[$token])) throw new InvalidArgumentException('Nested block path does not exist.');
                $collection =& $collection[$token];
                continue;
            }
            if (!is_string($token) || !array_key_exists($token, $collection) || !is_array($collection[$token])) {
                throw new InvalidArgumentException('Nested block collection does not exist.');
            }
            $collection =& $collection[$token];
        }
        if (!array_is_list($collection)) throw new InvalidArgumentException('Nested block target must be a list.');
        return $collection;
    }

    private static function sectionIndex(array $document, string $sectionId): int
    {
        foreach ($document['sections'] as $index => $section) if (($section['id'] ?? '') === $sectionId) return $index;
        throw new InvalidArgumentException('Section does not exist.');
    }
}
