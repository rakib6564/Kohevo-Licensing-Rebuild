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
            allowedBindingProviders: ['content.posts', 'content.authors', 'content.taxonomy', 'booking.services', 'membership.plans', 'forms.form'],
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
            allowedBindingProviders: ['content.posts', 'content.authors', 'content.taxonomy'],
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
            allowedBindingProviders: ['content.posts'],
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
                ['key' => 'size', 'type' => 'enum', 'label' => 'Button Size', 'required' => false, 'allowed_values' => ['sm', 'md', 'lg'], 'default' => 'md'],
                ['key' => 'full_width', 'type' => 'boolean', 'label' => 'Full Width', 'required' => false, 'default' => false],
            ]),
            allowsChildren: false,
            allowedBindingProviders: ['content.posts', 'content.authors', 'content.taxonomy', 'booking.services', 'membership.plans', 'forms.form'],
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

    /**
     * Register the Sprint 1 layout primitives and content blocks.
     */
    public static function registerLayoutBlocks(self $registry): void
    {
        // 8. layout.section
        $registry->register(new DeclarativeBlockDefinition(
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
            allowsChildren: true,
        ));

        // 9. layout.container
        $registry->register(new DeclarativeBlockDefinition(
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
            allowsChildren: true,
        ));

        // 10. layout.flex
        $registry->register(new DeclarativeBlockDefinition(
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
            allowsChildren: true,
        ));

        // 11. layout.grid
        $registry->register(new DeclarativeBlockDefinition(
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
            allowsChildren: true,
        ));

        // 12. core.text
        $registry->register(new DeclarativeBlockDefinition(
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
            allowsChildren: false,
            allowedBindingProviders: ['content.posts', 'content.authors', 'content.taxonomy', 'booking.services', 'membership.plans', 'forms.form'],
        ));

        // 13. core.query_loop
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.query_loop',
            version: 1,
            label: 'Query Loop',
            category: 'layout',
            icon: 'layout-grid',
            schema: FieldSchema::define([
                ['key' => 'source', 'type' => 'enum', 'label' => 'Source', 'required' => false, 'allowed_values' => ['posts', 'pages', 'custom'], 'default' => 'posts'],
                ['key' => 'category', 'type' => 'string', 'label' => 'Category', 'required' => false, 'default' => '', 'max_length' => 80],
                ['key' => 'tag', 'type' => 'string', 'label' => 'Tag', 'required' => false, 'default' => '', 'max_length' => 80],
                ['key' => 'author_id', 'type' => 'number', 'label' => 'Author ID', 'required' => false, 'default' => 0, 'integer_only' => true],
                ['key' => 'order_by', 'type' => 'enum', 'label' => 'Order By', 'required' => false, 'allowed_values' => ['published_at', 'title', 'created_at'], 'default' => 'published_at'],
                ['key' => 'order', 'type' => 'enum', 'label' => 'Order Direction', 'required' => false, 'allowed_values' => ['desc', 'asc'], 'default' => 'desc'],
                ['key' => 'per_page', 'type' => 'number', 'label' => 'Posts Per Page', 'required' => false, 'default' => 6, 'min' => 1, 'max' => 24, 'integer_only' => true],
                ['key' => 'columns', 'type' => 'number', 'label' => 'Grid Columns', 'required' => false, 'default' => 3, 'min' => 1, 'max' => 6, 'integer_only' => true],
                ['key' => 'gap', 'type' => 'enum', 'label' => 'Gap', 'required' => false, 'allowed_values' => CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, 'default' => 'md'],
                ['key' => 'card_variant', 'type' => 'enum', 'label' => 'Card Variant', 'required' => false, 'allowed_values' => ['card', 'minimal', 'horizontal'], 'default' => 'card'],
                ['key' => 'show_featured_image', 'type' => 'boolean', 'label' => 'Show Image', 'required' => false, 'default' => true],
                ['key' => 'show_date', 'type' => 'boolean', 'label' => 'Show Date', 'required' => false, 'default' => true],
                ['key' => 'show_author', 'type' => 'boolean', 'label' => 'Show Author', 'required' => false, 'default' => true],
                ['key' => 'show_category', 'type' => 'boolean', 'label' => 'Show Category', 'required' => false, 'default' => true],
                ['key' => 'show_excerpt', 'type' => 'boolean', 'label' => 'Show Excerpt', 'required' => false, 'default' => true],
                ['key' => 'read_more_text', 'type' => 'string', 'label' => 'Button Text', 'required' => false, 'default' => 'Read More', 'max_length' => 80],
                ['key' => 'enable_pagination', 'type' => 'boolean', 'label' => 'Pagination', 'required' => false, 'default' => true],
                ['key' => 'empty_message', 'type' => 'string', 'label' => 'Empty Message', 'required' => false, 'default' => 'No posts found.', 'max_length' => 200],
            ]),
            allowsChildren: true,
            allowedBindingProviders: ['content.posts', 'content.authors', 'content.taxonomy'],
            bindingSlots: ['items' => 'content.posts'],
        ));
    }

    /**
     * Return a BlockRegistry with both foundation and layout primitives.
     */
    public static function withAllCoreBlocks(): self
    {
        $registry = self::withCoreFoundationBlocks();
        self::registerLayoutBlocks($registry);
        return $registry;
    }
}
