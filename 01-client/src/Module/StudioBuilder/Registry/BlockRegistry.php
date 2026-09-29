<?php
/**
 * Kohevo Studio (studio-builder) — Block Registry.
 *
 * Central registry of Studio block definitions (`BlockDefinitionInterface`).
 * - Enforces unique namespaced block type keys (duplicate registration fails closed).
 * - Provides core non-business structural blocks via `withCoreFoundationBlocks()`
 *   (`core.hero`, `core.heading`, `core.rich_text`, `core.image`, `core.button`, `core.container`).
 * - Projects transport-safe editor manifests filtered by module entitlement and RBAC permissions.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Registry;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

final class BlockRegistry
{
    /**
     * @var array<string, BlockDefinitionInterface>
     */
    private array $definitions = [];

    /**
     * Register a block definition. Rejects duplicate registration of the same block type.
     */
    public function register(BlockDefinitionInterface $block): void
    {
        $type = $block->type();
        if (preg_match(CanonicalDocumentSchema::BLOCK_TYPE_PATTERN, $type) !== 1) {
            throw new \InvalidArgumentException("Invalid block type '{$type}'. Expected 'namespace.name'.");
        }
        if ($block->version() < 1) {
            throw new \InvalidArgumentException("Block '{$type}' version must be >= 1.");
        }
        if (isset($this->definitions[$type])) {
            throw new \InvalidArgumentException("Duplicate Studio block registration for type '{$type}'.");
        }

        $this->definitions[$type] = $block;
    }

    public function has(string $type): bool
    {
        return isset($this->definitions[$type]);
    }

    public function get(string $type): ?BlockDefinitionInterface
    {
        return $this->definitions[$type] ?? null;
    }

    /**
     * @return array<string, BlockDefinitionInterface>
     */
    public function all(): array
    {
        ksort($this->definitions, SORT_STRING);
        return $this->definitions;
    }

    /**
     * Return transport-safe editor manifests for registered blocks, optionally
     * filtered by entitlement and permission predicates.
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
     * Create a BlockRegistry pre-populated with the non-business Studio Core foundation blocks.
     */
    public static function withCoreFoundationBlocks(): self
    {
        $registry = new self();

        // 1. core.hero
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.hero',
            version: 1,
            label: 'Hero Banner',
            category: 'layout',
            icon: 'layout-hero',
            schema: FieldSchema::define([
                ['key' => 'eyebrow', 'type' => 'string', 'label' => 'Eyebrow', 'required' => false, 'default' => '', 'max_length' => 120],
                ['key' => 'heading', 'type' => 'string', 'label' => 'Heading', 'required' => true, 'default' => 'Welcome', 'max_length' => 255],
                ['key' => 'subheading', 'type' => 'text', 'label' => 'Subheading', 'required' => false, 'default' => '', 'max_length' => 1000],
                ['key' => 'primary_cta', 'type' => 'link', 'label' => 'Primary Call to Action', 'required' => false, 'default' => null],
                ['key' => 'media', 'type' => 'media_ref', 'label' => 'Hero Image', 'required' => false, 'default' => null],
                ['key' => 'accent_token', 'type' => 'token_ref', 'label' => 'Accent Token', 'required' => false, 'default' => null],
            ]),
            allowsChildren: false,
        ));

        // 2. core.heading
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.heading',
            version: 1,
            label: 'Heading',
            category: 'content',
            icon: 'type-heading',
            schema: FieldSchema::define([
                ['key' => 'text', 'type' => 'string', 'label' => 'Heading Text', 'required' => true, 'default' => 'Section Heading', 'max_length' => 300],
                ['key' => 'level', 'type' => 'enum', 'label' => 'Heading Level', 'required' => false, 'allowed_values' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], 'default' => 'h2'],
            ]),
            allowsChildren: false,
        ));

        // 3. core.rich_text
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.rich_text',
            version: 1,
            label: 'Rich Text',
            category: 'content',
            icon: 'type-rich-text',
            schema: FieldSchema::define([
                ['key' => 'content', 'type' => 'rich_text', 'label' => 'Content', 'required' => true, 'default' => '<p></p>'],
            ]),
            allowsChildren: false,
        ));

        // 4. core.image
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.image',
            version: 1,
            label: 'Image',
            category: 'media',
            icon: 'image',
            schema: FieldSchema::define([
                ['key' => 'media', 'type' => 'media_ref', 'label' => 'Image Asset', 'required' => true],
                ['key' => 'caption', 'type' => 'string', 'label' => 'Caption', 'required' => false, 'default' => '', 'max_length' => 300],
                ['key' => 'aspect_ratio', 'type' => 'enum', 'label' => 'Aspect Ratio', 'required' => false, 'allowed_values' => ['auto', '16:9', '4:3', '1:1', '3:4'], 'default' => 'auto'],
                ['key' => 'rounded', 'type' => 'boolean', 'label' => 'Rounded Corners', 'required' => false, 'default' => true],
            ]),
            allowsChildren: false,
        ));

        // 5. core.button
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.button',
            version: 1,
            label: 'Button',
            category: 'content',
            icon: 'cursor-click',
            schema: FieldSchema::define([
                ['key' => 'link', 'type' => 'link', 'label' => 'Button Link', 'required' => true],
                ['key' => 'variant', 'type' => 'enum', 'label' => 'Button Variant', 'required' => false, 'allowed_values' => ['primary', 'secondary', 'outline', 'ghost'], 'default' => 'primary'],
                ['key' => 'full_width', 'type' => 'boolean', 'label' => 'Full Width', 'required' => false, 'default' => false],
            ]),
            allowsChildren: false,
        ));

        // 6. core.feature_list (exercises repeater + object field types)
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.feature_list',
            version: 1,
            label: 'Feature List',
            category: 'content',
            icon: 'list-check',
            schema: FieldSchema::define([
                ['key' => 'title', 'type' => 'string', 'label' => 'Title', 'required' => false, 'default' => '', 'max_length' => 200],
                ['key' => 'columns', 'type' => 'number', 'label' => 'Columns', 'required' => false, 'default' => 3, 'min' => 1, 'max' => 4, 'integer_only' => true],
                [
                    'key'        => 'items',
                    'type'       => 'repeater',
                    'label'      => 'Features',
                    'required'   => false,
                    'default'    => [],
                    'max_items'  => 12,
                    'item_schema' => FieldSchema::define([
                        ['key' => 'heading', 'type' => 'string', 'label' => 'Heading', 'required' => true, 'max_length' => 150],
                        ['key' => 'body', 'type' => 'text', 'label' => 'Description', 'required' => false, 'default' => '', 'max_length' => 600],
                        ['key' => 'url', 'type' => 'url', 'label' => 'Optional URL', 'required' => false, 'default' => null],
                    ]),
                ],
                [
                    'key'        => 'card_options',
                    'type'       => 'object',
                    'label'      => 'Card Options',
                    'required'   => false,
                    'properties' => FieldSchema::define([
                        ['key' => 'bordered', 'type' => 'boolean', 'label' => 'Bordered', 'required' => false, 'default' => true],
                        ['key' => 'surface_token', 'type' => 'token_ref', 'label' => 'Surface Token', 'required' => false, 'default' => null],
                    ]),
                ],
            ]),
            allowsChildren: false,
        ));

        // 7. core.container (structural container capable of holding nested child blocks)
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.container',
            version: 1,
            label: 'Container',
            category: 'layout',
            icon: 'box',
            schema: FieldSchema::define([
                ['key' => 'gap', 'type' => 'enum', 'label' => 'Gap', 'required' => false, 'allowed_values' => CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, 'default' => 'md'],
                ['key' => 'direction', 'type' => 'enum', 'label' => 'Direction', 'required' => false, 'allowed_values' => ['vertical', 'horizontal'], 'default' => 'vertical'],
            ]),
            allowsChildren: true,
        ));

        return $registry;
    }
}
