<?php
/**
 * Kohevo Studio (studio-builder) — Phase 8A package <-> canonical document mapping.
 *
 * Pure transforms over canonical document ARRAYS (no database, no tenant):
 *
 *   - media references: collect every media id a document names (block
 *     `media_ref` fields at any depth — through repeaters and objects — and
 *     `seo.og_image_media_id`), and rewrite them through a resolver. On
 *     export the resolver renumbers source ids into package-local keys; on
 *     import it maps package keys to TARGET-tenant media ids.
 *
 *     Downgrade rule for a media reference the resolver cannot map (the
 *     Phase 8A import contract):
 *       * `seo.og_image_media_id`                -> null
 *       * an OPTIONAL `media_ref` field (e.g. core.hero `media`) -> null
 *       * a block with a REQUIRED `media_ref` field (e.g. core.image) ->
 *         the block is omitted from the imported document
 *     Each downgrade is reported (path in the SOURCE document). No URL is
 *     ever written: `media_ref` only holds a tenant media id.
 *
 *   - global references: every `section.global_ref` is rewritten through an
 *     explicit source -> target map. An unmapped reference is downgraded to
 *     an ordinary empty owned section (`global_ref = null`, `blocks = []`,
 *     label/layout/visibility kept) and reported.
 *
 *   - ids: every section, block and nested child id is re-minted with the
 *     given minter (never trusting the ids the package carries, which may
 *     even collide). The default minters are the canonical
 *     `CanonicalDocumentSchema::newSectionId()` / `newBlockId()` — the same
 *     primitive `DocumentCopier` uses; `DocumentCopier` itself is not used
 *     because it also drops every `global_ref`, which import must remap.
 *
 * The result is still RAW: it is only ever persisted after
 * `ValidatedDocument::from()` validated and normalized it.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Package;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

final class PackageDocumentMapper
{
    /**
     * Distinct media ids referenced by a document, in first-appearance order.
     *
     * @param array<string, mixed> $document
     * @return list<int>
     */
    public static function mediaIds(array $document, BlockRegistry $registry): array
    {
        $ids = [];
        self::rewriteMedia($document, $registry, static function (int $id) use (&$ids): int {
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
            return $id;
        });
        return $ids;
    }

    /**
     * Rewrite every media id through `$resolve(int $id): ?int`. A null result
     * is an unresolved reference and is downgraded (see the class docblock).
     *
     * @param array<string, mixed> $document
     * @param callable(int): ?int $resolve
     * @return array{document: array<string, mixed>, unresolved: list<array{path: string, media_key: int, action: string}>}
     */
    public static function rewriteMedia(array $document, BlockRegistry $registry, callable $resolve): array
    {
        $unresolved = [];

        $og = $document['seo']['og_image_media_id'] ?? null;
        if (is_int($og) && $og > 0) {
            $mapped = $resolve($og);
            if ($mapped === null) {
                $unresolved[] = ['path' => '$.seo.og_image_media_id', 'media_key' => $og, 'action' => 'set_null'];
            }
            $document['seo']['og_image_media_id'] = $mapped;
        }

        if (is_array($document['sections'] ?? null)) {
            foreach ($document['sections'] as $s => $section) {
                if (!is_array($section) || !is_array($section['blocks'] ?? null)) {
                    continue;
                }
                $document['sections'][$s]['blocks'] = self::rewriteBlocks($section['blocks'], $registry, $resolve, "\$.sections[{$s}].blocks", $unresolved);
            }
        }
        return ['document' => $document, 'unresolved' => $unresolved];
    }

    /**
     * @param list<mixed> $blocks
     * @param list<array{path: string, media_key: int, action: string}> $unresolved
     * @return list<mixed>
     */
    private static function rewriteBlocks(array $blocks, BlockRegistry $registry, callable $resolve, string $path, array &$unresolved): array
    {
        $out = [];
        foreach ($blocks as $b => $block) {
            $blockPath = "{$path}[{$b}]";
            if (!is_array($block)) {
                $out[] = $block;
                continue;
            }
            $definition = $registry->get((string) ($block['type'] ?? ''));
            if ($definition !== null && is_array($block['props'] ?? null)) {
                $drop = false;
                $block['props'] = self::rewriteProps($block['props'], $definition->schema(), $resolve, "{$blockPath}.props", $unresolved, $drop);
                if ($drop) {
                    continue; // a required image that cannot exist in this tenant: the block is omitted
                }
            }
            if (is_array($block['children'] ?? null)) {
                $block['children'] = self::rewriteBlocks($block['children'], $registry, $resolve, "{$blockPath}.children", $unresolved);
            }
            $out[] = $block;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $props
     * @param list<array{path: string, media_key: int, action: string}> $unresolved
     * @return array<string, mixed>
     */
    private static function rewriteProps(array $props, FieldSchema $schema, callable $resolve, string $path, array &$unresolved, bool &$drop): array
    {
        foreach ($schema->fields() as $field) {
            $key  = (string) $field['key'];
            $type = (string) $field['type'];
            $val  = $props[$key] ?? null;
            if (!is_array($val)) {
                continue;
            }
            if ($type === 'media_ref') {
                $id = $val['media_id'] ?? null;
                if (!is_int($id) || $id <= 0) {
                    continue; // malformed: left for the canonical validator to reject
                }
                $mapped = $resolve($id);
                if ($mapped !== null) {
                    $props[$key]['media_id'] = $mapped;
                    continue;
                }
                $required = (bool) ($field['required'] ?? false);
                $unresolved[] = ['path' => "{$path}.{$key}", 'media_key' => $id, 'action' => $required ? 'omit_block' : 'set_null'];
                if ($required) {
                    $drop = true;
                } else {
                    $props[$key] = null;
                }
            } elseif ($type === 'repeater' && ($field['item_schema'] ?? null) instanceof FieldSchema && array_is_list($val)) {
                foreach ($val as $i => $item) {
                    if (is_array($item)) {
                        $props[$key][$i] = self::rewriteProps($item, $field['item_schema'], $resolve, "{$path}.{$key}[{$i}]", $unresolved, $drop);
                    }
                }
            } elseif ($type === 'object' && ($field['properties'] ?? null) instanceof FieldSchema) {
                $props[$key] = self::rewriteProps($val, $field['properties'], $resolve, "{$path}.{$key}", $unresolved, $drop);
            }
        }
        return $props;
    }

    /**
     * Rewrite every `section.global_ref` through `$resolve(string $ref): ?string`.
     * An unresolved reference becomes an empty owned section.
     *
     * @param array<string, mixed> $document
     * @param callable(string): ?string $resolve
     * @return array{document: array<string, mixed>, unresolved: list<array{path: string, ref: string}>}
     */
    public static function rewriteGlobalRefs(array $document, callable $resolve): array
    {
        $unresolved = [];
        if (!is_array($document['sections'] ?? null)) {
            return ['document' => $document, 'unresolved' => []];
        }
        foreach ($document['sections'] as $s => $section) {
            $ref = is_array($section) ? ($section['global_ref'] ?? null) : null;
            if (!is_string($ref) || $ref === '') {
                continue;
            }
            $mapped = $resolve($ref);
            if ($mapped !== null) {
                $document['sections'][$s]['global_ref'] = $mapped;
                continue;
            }
            $unresolved[] = ['path' => "\$.sections[{$s}].global_ref", 'ref' => $ref];
            $document['sections'][$s]['global_ref'] = null;
            $document['sections'][$s]['blocks'] = [];
        }
        return ['document' => $document, 'unresolved' => $unresolved];
    }

    /** Distinct `section.global_ref` values of a document, in order. @return list<string> */
    public static function globalRefs(array $document): array
    {
        $refs = [];
        foreach ((array) ($document['sections'] ?? []) as $section) {
            $ref = is_array($section) ? ($section['global_ref'] ?? null) : null;
            if (is_string($ref) && $ref !== '' && !in_array($ref, $refs, true)) {
                $refs[] = $ref;
            }
        }
        return $refs;
    }

    /**
     * Re-mint every section, block and nested child id.
     *
     * @param array<string, mixed> $document
     * @param null|callable(): string $sectionId
     * @param null|callable(): string $blockId
     * @return array<string, mixed>
     */
    public static function remintIds(array $document, ?callable $sectionId = null, ?callable $blockId = null): array
    {
        $sectionId ??= [CanonicalDocumentSchema::class, 'newSectionId'];
        $blockId   ??= [CanonicalDocumentSchema::class, 'newBlockId'];
        if (!is_array($document['sections'] ?? null)) {
            return $document;
        }
        foreach ($document['sections'] as $s => $section) {
            if (!is_array($section)) {
                continue;
            }
            $document['sections'][$s]['id'] = $sectionId();
            if (is_array($section['blocks'] ?? null)) {
                $document['sections'][$s]['blocks'] = self::remintBlocks($section['blocks'], $blockId);
            }
        }
        return $document;
    }

    /**
     * @param list<mixed> $blocks
     * @return list<mixed>
     */
    private static function remintBlocks(array $blocks, callable $blockId): array
    {
        foreach ($blocks as $b => $block) {
            if (!is_array($block)) {
                continue;
            }
            $blocks[$b]['id'] = $blockId();
            if (is_array($block['children'] ?? null)) {
                $blocks[$b]['children'] = self::remintBlocks($block['children'], $blockId);
            }
        }
        return $blocks;
    }

    /**
     * Section and block (all depths) counts of a document.
     *
     * @param array<string, mixed> $document
     * @return array{sections: int, blocks: int}
     */
    public static function counts(array $document): array
    {
        $blocks = 0;
        $walk = static function (array $list) use (&$walk, &$blocks): void {
            foreach ($list as $block) {
                if (is_array($block)) {
                    $blocks++;
                    $walk(is_array($block['children'] ?? null) ? $block['children'] : []);
                }
            }
        };
        $sections = 0;
        foreach ((array) ($document['sections'] ?? []) as $section) {
            if (is_array($section)) {
                $sections++;
                $walk(is_array($section['blocks'] ?? null) ? $section['blocks'] : []);
            }
        }
        return ['sections' => $sections, 'blocks' => $blocks];
    }

    /**
     * Deterministic id minters (for a dry run, whose report must be
     * reproducible byte for byte): ids derived from a seed and a counter.
     *
     * @return array{0: callable(): string, 1: callable(): string}
     */
    public static function deterministicMinters(string $seed): array
    {
        $n = 0;
        $next = static function (string $prefix) use (&$n, $seed): string {
            $n++;
            return $prefix . substr(hash('sha256', $seed . '|' . $prefix . '|' . $n), 0, 24);
        };
        return [static fn(): string => $next('sec_'), static fn(): string => $next('blk_')];
    }

    /** A deterministic, well-formed v4-shaped uuid for a planned (not yet created) component. */
    public static function placeholderUuid(string $seed): string
    {
        $h = hash('sha256', 'component|' . $seed);
        return sprintf('%s-%s-4%s-%s%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), '8', substr($h, 17, 3), substr($h, 20, 12));
    }
}
