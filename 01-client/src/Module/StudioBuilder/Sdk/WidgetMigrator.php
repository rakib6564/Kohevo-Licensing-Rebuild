<?php
/**
 * Kohevo Studio (studio-builder) — Widget Version Migrator.
 *
 * Implements deterministic document migration for versioned widgets (Section 31.2):
 * - Detects blocks saved with older schema versions (e.g. block version 1 when definition is version 2)
 * - Sequentially executes registered migration callbacks without re-interpreting old documents
 * - Recurses through nested sections, containers, and children
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Sdk;

use Slate\Module\StudioBuilder\Document\CanonicalJson;

final class WidgetMigrator
{
    /**
     * Migrate a single block if its version is older than the target registered version.
     *
     * @param array<string, mixed> $block
     * @param ?CustomWidgetDefinition $widget
     * @return array<string, mixed> Migrated block
     */
    public static function migrateBlock(array $block, ?CustomWidgetDefinition $widget = null): array
    {
        $currentVersion = (int) ($block['version'] ?? 1);
        if ($widget === null) {
            $type = (string) ($block['type'] ?? '');
            $widget = WidgetSdk::instance()->get($type);
        }

        if ($widget !== null && $widget->version() > $currentVersion) {
            $targetVersion = $widget->version();
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];

            // Execute migration step(s)
            $migratedProps = $widget->migrate($currentVersion, $targetVersion, $props);

            $block['props']   = $migratedProps;
            $block['version'] = $targetVersion;
        }

        // Migrate children recursively if present
        if (isset($block['children']) && is_array($block['children'])) {
            $migratedChildren = [];
            foreach ($block['children'] as $child) {
                if (is_array($child)) {
                    $migratedChildren[] = self::migrateBlock($child);
                }
            }
            $block['children'] = $migratedChildren;
        }

        return $block;
    }

    /**
     * Migrate all blocks in a full canonical document.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed> Migrated document
     */
    public static function migrateDocument(array $document): array
    {
        if (!isset($document['sections']) || !is_array($document['sections'])) {
            return $document;
        }

        $migratedSections = [];
        foreach ($document['sections'] as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (isset($section['blocks']) && is_array($section['blocks'])) {
                $migratedBlocks = [];
                foreach ($section['blocks'] as $block) {
                    if (is_array($block)) {
                        $migratedBlocks[] = self::migrateBlock($block);
                    }
                }
                $section['blocks'] = $migratedBlocks;
            }
            $migratedSections[] = $section;
        }

        $document['sections'] = $migratedSections;
        return CanonicalJson::sortKeysRecursively($document);
    }
}
