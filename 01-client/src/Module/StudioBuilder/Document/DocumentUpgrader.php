<?php
/**
 * Kohevo Studio (studio-builder) — Block Version Upgrader.
 *
 * Brings the blocks of a document saved by an older Studio up to the block
 * versions this site registers, BEFORE the document is validated, so raising
 * a block's version does not make earlier content unusable. Called on every
 * read of stored or imported content:
 *   - package import, and package export (exports carry current versions);
 *   - template apply/insert, and detaching a global component;
 *   - the editor's load and every edit (StudioApplicationService), publish
 *     and rollback (StudioRevisionService);
 *   - rendering (RenderDocumentPreparer), for published pages, previews,
 *     partials and templates.
 * Upgrades happen in memory: a stored revision is never rewritten. The next
 * draft, publish or rollback writes a NEW revision at the current versions,
 * and the compilation fingerprint (ModuleBlockDefinitions::registryFingerprint,
 * type@version) makes every page recompile after a bump.
 *
 * - Every block (section blocks and nested children) whose integer `version`
 *   is below its definition's version is stepped up one version at a time
 *   through `BlockDefinitionInterface::upgrade()`.
 * - All or nothing per block: when a step is missing, the block is left
 *   exactly as it was, and the validator then refuses it with
 *   `invalid_block_version` (naming the block, its version and the
 *   supported one).
 * - Unknown types, newer versions and malformed blocks are left alone for
 *   the validator to report. Nothing else in the document is touched.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

use Slate\Module\StudioBuilder\Registry\BlockRegistry;

final class DocumentUpgrader
{
    /**
     * @param array<string, mixed> $document
     * @return array{document: array<string, mixed>, upgraded: list<array{path: string, type: string, from: int, to: int}>}
     */
    public static function upgrade(array $document, BlockRegistry $registry): array
    {
        $upgraded = [];
        if (is_array($document['sections'] ?? null)) {
            foreach ($document['sections'] as $i => $section) {
                if (is_array($section) && is_array($section['blocks'] ?? null)) {
                    $document['sections'][$i]['blocks'] = self::blocks($section['blocks'], $registry, "\$.sections[{$i}].blocks", $upgraded);
                }
            }
        }
        return ['document' => $document, 'upgraded' => $upgraded];
    }

    /**
     * One section of a template (insert path), upgraded the same way.
     *
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    public static function upgradeSection(array $section, BlockRegistry $registry): array
    {
        return self::upgrade(['sections' => [$section]], $registry)['document']['sections'][0];
    }

    /**
     * @param array<mixed> $blocks
     * @param list<array{path: string, type: string, from: int, to: int}> $upgraded
     * @return array<mixed>
     */
    private static function blocks(array $blocks, BlockRegistry $registry, string $path, array &$upgraded): array
    {
        foreach ($blocks as $j => $block) {
            if (!is_array($block)) {
                continue;
            }
            $blockPath = "{$path}[{$j}]";
            $blocks[$j] = self::block($block, $registry, $blockPath, $upgraded);
            if (is_array($blocks[$j]['children'] ?? null)) {
                $blocks[$j]['children'] = self::blocks($blocks[$j]['children'], $registry, "{$blockPath}.children", $upgraded);
            }
        }
        return $blocks;
    }

    /**
     * @param array<string, mixed> $block
     * @param list<array{path: string, type: string, from: int, to: int}> $upgraded
     * @return array<string, mixed>
     */
    private static function block(array $block, BlockRegistry $registry, string $path, array &$upgraded): array
    {
        $type = $block['type'] ?? null;
        $from = $block['version'] ?? null;
        $definition = is_string($type) ? $registry->get($type) : null;
        if ($definition === null || !is_int($from) || $from < 1 || $from >= $definition->version() || !is_array($block['props'] ?? null)) {
            return $block;
        }
        $props = $block['props'];
        for ($v = $from; $v < $definition->version(); $v++) {
            try {
                $props = $definition->upgrade($props, $v);
            } catch (\Throwable) {
                $props = null;
            }
            if (!is_array($props)) {
                return $block;
            }
        }
        $block['props'] = $props;
        $block['version'] = $definition->version();
        $upgraded[] = ['path' => $path, 'type' => (string) $type, 'from' => $from, 'to' => $definition->version()];
        return $block;
    }
}
