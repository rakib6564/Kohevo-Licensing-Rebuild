<?php
/**
 * Kohevo Studio (studio-builder) — Widget Registry.
 *
 * Registry of Studio visual widgets and layout primitives.
 * Implements WidgetRegistryInterface and coordinates with BlockRegistry, which
 * holds the one real registration (build a filled registry with
 * fromBlockRegistry()):
 * - Unique block/widget identity
 * - Category grouping (layout, basic, content, media, marketing, etc.)
 * - Safe resolution and manifest projection for the visual editor
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Registry;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;

final class WidgetRegistry implements WidgetRegistryInterface
{
    /**
     * @var array<string, BlockDefinitionInterface>
     */
    private array $widgets = [];

    public function register(BlockDefinitionInterface $widget): void
    {
        $type = $widget->type();
        if (preg_match(CanonicalDocumentSchema::BLOCK_TYPE_PATTERN, $type) !== 1) {
            throw new \InvalidArgumentException("Invalid widget type '{$type}'. Expected 'namespace.name'.");
        }
        if ($widget->version() < 1) {
            throw new \InvalidArgumentException("Widget '{$type}' version must be >= 1.");
        }
        if (isset($this->widgets[$type])) {
            throw new \InvalidArgumentException("Duplicate Studio widget registration for type '{$type}'.");
        }

        $this->widgets[$type] = $widget;
    }

    public function has(string $type): bool
    {
        return isset($this->widgets[$type]);
    }

    public function get(string $type): ?BlockDefinitionInterface
    {
        return $this->widgets[$type] ?? null;
    }

    public function resolve(string $type): BlockDefinitionInterface
    {
        $widget = $this->get($type);
        if ($widget === null) {
            throw new \InvalidArgumentException("Unregistered Studio widget type '{$type}'.");
        }
        return $widget;
    }

    /**
     * @return array<string, BlockDefinitionInterface>
     */
    public function all(): array
    {
        ksort($this->widgets, SORT_STRING);
        return $this->widgets;
    }

    /**
     * Group registered widgets by category.
     *
     * @return array<string, list<BlockDefinitionInterface>>
     */
    public function categories(): array
    {
        $byCat = [];
        foreach ($this->all() as $def) {
            $cat = $def->category();
            $byCat[$cat][] = $def;
        }
        ksort($byCat, SORT_STRING);
        return $byCat;
    }

    /**
     * Produce editor manifests filtered by entitlement and permission predicates.
     *
     * @param null|callable(?string $entitlement): bool $entitlementCheck
     * @param null|callable(string $permission): bool   $permissionCheck
     * @return list<array<string, mixed>>
     */
    public function editorManifests(?callable $entitlementCheck = null, ?callable $permissionCheck = null): array
    {
        $manifests = [];
        foreach ($this->all() as $definition) {
            $ent = $definition->requiredEntitlement();
            if ($entitlementCheck !== null && !$entitlementCheck($ent)) {
                continue;
            }
            $perm = $definition->requiredPermission();
            if ($permissionCheck !== null && !$permissionCheck($perm)) {
                continue;
            }
            $manifests[] = $definition->toEditorManifest();
        }
        return $manifests;
    }

    /**
     * Build from an existing BlockRegistry.
     */
    public static function fromBlockRegistry(BlockRegistry $blockRegistry): self
    {
        $registry = new self();
        foreach ($blockRegistry->all() as $block) {
            $registry->register($block);
        }
        return $registry;
    }

    /**
     * Populate registered widgets into a target BlockRegistry.
     */
    public function populateIntoBlockRegistry(BlockRegistry $registry): void
    {
        foreach ($this->all() as $widget) {
            if (!$registry->has($widget->type())) {
                $registry->register($widget);
            }
        }
    }
}
