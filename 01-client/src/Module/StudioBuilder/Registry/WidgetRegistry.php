<?php
/**
 * Kohevo Studio (studio-builder) — Widget Registry.
 *
 * Authoritative registry of Studio visual widgets and layout primitives.
 * Implements WidgetRegistryInterface and coordinates with BlockRegistry:
 * - Unique block/widget identity
 * - Category grouping (layout, basic, content, media, marketing, etc.)
 * - Safe resolution and manifest projection for the visual editor
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Registry;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

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

    /**
     * Pre-populate WidgetRegistry with rich declarative definitions for visual builder.
     */
    public static function withCoreWidgets(): self
    {
        $registry = new self();

        // 1. layout.section
        $registry->register(new DeclarativeWidgetDefinition(
            type: 'layout.section',
            version: 1,
            label: 'Section',
            category: 'layout',
            icon: 'layout-section',
            schema: FieldSchema::define([
                ['key' => 'tag', 'type' => 'enum', 'label' => 'HTML Tag', 'required' => false, 'allowed_values' => ['section', 'header', 'footer', 'article', 'aside', 'div'], 'default' => 'section'],
                ['key' => 'content_width', 'type' => 'enum', 'label' => 'Content Width', 'required' => false, 'allowed_values' => ['boxed', 'full', 'narrow'], 'default' => 'boxed'],
                ['key' => 'min_height', 'type' => 'enum', 'label' => 'Minimum Height', 'required' => false, 'allowed_values' => ['auto', 'screen', 'half_screen', 'sm', 'md', 'lg', 'xl'], 'default' => 'auto'],
                ['key' => 'padding_y', 'type' => 'enum', 'label' => 'Vertical Padding', 'required' => false, 'allowed_values' => CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, 'default' => 'md'],
                ['key' => 'background_token', 'type' => 'token_ref', 'label' => 'Background Token', 'required' => false, 'default' => null],
            ]),
            description: 'Full-width semantic layout section capable of nesting containers, stacks, and content.',
            controls: \Slate\Module\StudioBuilder\Schema\ControlSchema::standard(
                contentControls: [
                    ['key' => 'tag', 'type' => 'select', 'label' => 'HTML Tag'],
                    ['key' => 'content_width', 'type' => 'select', 'label' => 'Content Width'],
                    ['key' => 'min_height', 'type' => 'select', 'label' => 'Minimum Height'],
                ],
                styleControls: [
                    ['key' => 'background_token', 'type' => 'token', 'label' => 'Background'],
                    ['key' => 'padding_y', 'type' => 'select', 'label' => 'Vertical Padding'],
                ],
                advancedControls: [
                    ['key' => 'custom_classes', 'type' => 'text', 'label' => 'CSS Classes'],
                ],
            ),
            allowsChildren: true,
            supports: ['responsive', 'custom_attributes', 'style_tokens'],
        ));

        // 2. layout.container
        $registry->register(new DeclarativeWidgetDefinition(
            type: 'layout.container',
            version: 1,
            label: 'Container',
            category: 'layout',
            icon: 'layout-container',
            schema: FieldSchema::define([
                ['key' => 'width', 'type' => 'enum', 'label' => 'Width Mode', 'required' => false, 'allowed_values' => ['full', 'constrained', 'compact'], 'default' => 'constrained'],
                ['key' => 'alignment', 'type' => 'enum', 'label' => 'Alignment', 'required' => false, 'allowed_values' => ['left', 'center', 'right'], 'default' => 'center'],
                ['key' => 'padding', 'type' => 'enum', 'label' => 'Padding', 'required' => false, 'allowed_values' => CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, 'default' => 'none'],
            ]),
            description: 'Inner constraint container controlling max-width, alignment, and internal padding.',
            controls: \Slate\Module\StudioBuilder\Schema\ControlSchema::standard(
                contentControls: [
                    ['key' => 'width', 'type' => 'select', 'label' => 'Width Mode'],
                    ['key' => 'alignment', 'type' => 'select', 'label' => 'Alignment'],
                ],
                styleControls: [
                    ['key' => 'padding', 'type' => 'select', 'label' => 'Padding'],
                ],
            ),
            allowsChildren: true,
            supports: ['responsive', 'custom_attributes'],
        ));

        // 3. layout.flex
        $registry->register(new DeclarativeWidgetDefinition(
            type: 'layout.flex',
            version: 1,
            label: 'Flex',
            category: 'layout',
            icon: 'layout-flex',
            schema: FieldSchema::define([
                ['key' => 'direction', 'type' => 'enum', 'label' => 'Direction', 'required' => false, 'allowed_values' => ['row', 'column', 'row_reverse', 'column_reverse'], 'default' => 'row'],
                ['key' => 'wrap', 'type' => 'enum', 'label' => 'Wrap', 'required' => false, 'allowed_values' => ['nowrap', 'wrap', 'wrap_reverse'], 'default' => 'nowrap'],
                ['key' => 'justify', 'type' => 'enum', 'label' => 'Justify Content', 'required' => false, 'allowed_values' => ['start', 'center', 'end', 'between', 'around', 'evenly'], 'default' => 'start'],
                ['key' => 'align', 'type' => 'enum', 'label' => 'Align Items', 'required' => false, 'allowed_values' => ['start', 'center', 'end', 'stretch', 'baseline'], 'default' => 'start'],
                ['key' => 'gap', 'type' => 'enum', 'label' => 'Gap', 'required' => false, 'allowed_values' => CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, 'default' => 'md'],
            ]),
            description: 'CSS Flexbox container for row/column layouts with alignment and gap controls.',
            controls: \Slate\Module\StudioBuilder\Schema\ControlSchema::standard(
                contentControls: [
                    ['key' => 'direction', 'type' => 'select', 'label' => 'Direction'],
                    ['key' => 'wrap', 'type' => 'select', 'label' => 'Wrap'],
                    ['key' => 'justify', 'type' => 'select', 'label' => 'Justify Content'],
                    ['key' => 'align', 'type' => 'select', 'label' => 'Align Items'],
                ],
                styleControls: [
                    ['key' => 'gap', 'type' => 'select', 'label' => 'Gap'],
                ],
            ),
            allowsChildren: true,
            supports: ['responsive', 'custom_attributes'],
        ));

        // 4. layout.grid
        $registry->register(new DeclarativeWidgetDefinition(
            type: 'layout.grid',
            version: 1,
            label: 'Grid',
            category: 'layout',
            icon: 'layout-grid',
            schema: FieldSchema::define([
                ['key' => 'columns', 'type' => 'number', 'label' => 'Columns', 'required' => false, 'default' => 2, 'min' => 1, 'max' => 12, 'integer_only' => true],
                ['key' => 'gap', 'type' => 'enum', 'label' => 'Gap', 'required' => false, 'allowed_values' => CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, 'default' => 'md'],
                ['key' => 'align', 'type' => 'enum', 'label' => 'Alignment', 'required' => false, 'allowed_values' => ['start', 'center', 'end', 'stretch'], 'default' => 'stretch'],
            ]),
            description: 'CSS Grid container for multi-column grid compositions with responsive track control.',
            controls: \Slate\Module\StudioBuilder\Schema\ControlSchema::standard(
                contentControls: [
                    ['key' => 'columns', 'type' => 'number', 'label' => 'Columns'],
                    ['key' => 'align', 'type' => 'select', 'label' => 'Alignment'],
                ],
                styleControls: [
                    ['key' => 'gap', 'type' => 'select', 'label' => 'Gap'],
                ],
            ),
            allowsChildren: true,
            supports: ['responsive', 'custom_attributes'],
        ));

        // 5. core.heading
        $registry->register(new DeclarativeWidgetDefinition(
            type: 'core.heading',
            version: 1,
            label: 'Heading',
            category: 'content',
            icon: 'type-heading',
            schema: FieldSchema::define([
                ['key' => 'text', 'type' => 'string', 'label' => 'Heading Text', 'required' => true, 'default' => 'Section Heading', 'max_length' => 300],
                ['key' => 'level', 'type' => 'enum', 'label' => 'Heading Level', 'required' => false, 'allowed_values' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], 'default' => 'h2'],
            ]),
            description: 'Section or page heading typography element.',
            controls: \Slate\Module\StudioBuilder\Schema\ControlSchema::standard(
                contentControls: [
                    ['key' => 'text', 'type' => 'text', 'label' => 'Text'],
                    ['key' => 'level', 'type' => 'select', 'label' => 'HTML Level'],
                ],
                styleControls: [
                    ['key' => 'align', 'type' => 'select', 'label' => 'Alignment'],
                ],
            ),
            allowsChildren: false,
            supports: ['responsive_typography', 'custom_attributes', 'style_tokens'],
        ));

        // 6. core.text
        $registry->register(new DeclarativeWidgetDefinition(
            type: 'core.text',
            version: 1,
            label: 'Text',
            category: 'content',
            icon: 'type',
            schema: FieldSchema::define([
                ['key' => 'content', 'type' => 'text', 'label' => 'Text Content', 'required' => false, 'default' => '', 'max_length' => 5000],
                ['key' => 'size', 'type' => 'enum', 'label' => 'Size', 'required' => false, 'allowed_values' => ['xs', 'sm', 'base', 'lg', 'xl', 'lead'], 'default' => 'base'],
                ['key' => 'align', 'type' => 'enum', 'label' => 'Alignment', 'required' => false, 'allowed_values' => ['left', 'center', 'right', 'justify'], 'default' => 'left'],
                ['key' => 'color_token', 'type' => 'token_ref', 'label' => 'Color Token', 'required' => false, 'default' => null],
            ]),
            description: 'Typography paragraph or lead body text block.',
            controls: \Slate\Module\StudioBuilder\Schema\ControlSchema::standard(
                contentControls: [
                    ['key' => 'content', 'type' => 'textarea', 'label' => 'Content'],
                ],
                styleControls: [
                    ['key' => 'size', 'type' => 'select', 'label' => 'Font Size'],
                    ['key' => 'align', 'type' => 'select', 'label' => 'Alignment'],
                    ['key' => 'color_token', 'type' => 'token', 'label' => 'Color Token'],
                ],
            ),
            allowsChildren: false,
            supports: ['responsive_typography', 'custom_attributes', 'style_tokens'],
        ));

        // 7. core.button
        $registry->register(new DeclarativeWidgetDefinition(
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
            description: 'Call-to-action button linking to internal routes or external URLs.',
            controls: \Slate\Module\StudioBuilder\Schema\ControlSchema::standard(
                contentControls: [
                    ['key' => 'link', 'type' => 'link', 'label' => 'Link'],
                ],
                styleControls: [
                    ['key' => 'variant', 'type' => 'select', 'label' => 'Variant'],
                    ['key' => 'full_width', 'type' => 'toggle', 'label' => 'Full Width'],
                ],
            ),
            allowsChildren: false,
            supports: ['responsive', 'custom_attributes', 'style_tokens'],
        ));

        // Also add the foundation blocks
        $foundation = BlockRegistry::withCoreFoundationBlocks();
        foreach ($foundation->all() as $type => $block) {
            if (!$registry->has($type)) {
                $registry->register($block);
            }
        }

        return $registry;
    }
}
