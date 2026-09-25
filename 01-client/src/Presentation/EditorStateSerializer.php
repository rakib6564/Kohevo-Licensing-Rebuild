<?php
declare(strict_types=1);

namespace Slate\Presentation;

use InvalidArgumentException;

/** Projects Craft.js/dnd-kit state into canonical schema-1 document data. */
final class EditorStateSerializer
{
    /** @param array<string,mixed> $editorState @return array<string,mixed> */
    public static function serialize(array $editorState): array
    {
        $source = $editorState['document'] ?? $editorState;
        if (!is_array($source)) throw new InvalidArgumentException('Editor state does not contain a document.');
        $document = DocumentSchema::normalize($source);
        foreach ($document['sections'] as &$section) {
            foreach ($section['blocks'] as &$block) {
                $block = self::block($block);
            }
            unset($block);
        }
        unset($section);
        return $document;
    }

    /** @param array<string,mixed> $block @return array<string,mixed> */
    private static function block(array $block): array
    {
        $out = ['type' => (string)($block['type'] ?? ''), 'props' => is_array($block['props'] ?? null) ? $block['props'] : []];
        if (isset($block['style']) && is_array($block['style'])) $out['style'] = $block['style'];
        return $out;
    }
}
