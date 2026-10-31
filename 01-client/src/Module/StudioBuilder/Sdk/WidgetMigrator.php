<?php
/**
 * Kohevo Studio (studio-builder) — Widget Version Migrator.
 *
 * Implements deterministic document migration for versioned widgets (Section 31.2):
 * - Detects blocks saved with older schema versions (e.g. block version 1 when definition is version 2)
 * - Sequentially executes registered migration callbacks without re-interpreting old documents
 * - Recurses through Containers and children
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Sdk;

use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\DocumentUpgrader;

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
        $document = DocumentUpgrader::structure($document);
        if (!isset($document['containers']) || !is_array($document['containers'])) {
            return $document;
        }

        $migrated = [];
        foreach ($document['containers'] as $container) {
            if (is_array($container)) {
                $migrated[] = self::migrateBlock($container);
            }
        }

        $document['containers'] = $migrated;
        return CanonicalJson::sortKeysRecursively($document);
    }
}
