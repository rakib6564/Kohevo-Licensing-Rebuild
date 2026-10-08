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
            title: 'Hero',
            description: 'Lead with a clear proposition',
            category: 'layout',
            icon: 'rocket',
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
            title: 'Heading',
            description: 'Create hierarchy',
            category: 'content',
            icon: 'heading',
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
            title: 'Rich text',
            description: 'Formatted copy & body content',
            category: 'content',
            icon: 'pilcrow',
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
            title: 'Image',
            description: 'Media library asset',
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
            title: 'Button',
            description: 'A link styled as a call to action',
            category: 'content',
            icon: 'arrow-up-right',
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
            title: 'Feature list',
            description: 'Highlights & feature cards',
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
            title: 'Container',
            description: 'Inner constraint container',
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
            title: 'Section',
            description: 'Full-width layout section',
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
            title: 'Container',
            description: 'Constrained width container',
            category: 'layout',
            icon: 'box',
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
            title: 'Flex',
            description: 'Flexible row or column layout',
            category: 'layout',
            icon: 'box',
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
            title: 'Grid',
            description: 'Multi-column responsive grid',
            category: 'layout',
            icon: 'grid-panes',
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
            title: 'Text',
            description: 'Plain-text body copy',
            category: 'content',
            icon: 'pilcrow',
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
            title: 'Services grid',
            description: 'Live Service & dynamic posts',
            category: 'layout',
            icon: 'grid-panes',
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

        // 14. theme.post_title
        $registry->register(new DeclarativeBlockDefinition(
            type: 'theme.post_title',
            version: 1,
            label: 'Post Title',
            title: 'Post title',
            description: 'Dynamic article heading',
            category: 'theme',
            icon: 'heading',
            schema: FieldSchema::define([
                ['key' => 'level', 'type' => 'enum', 'label' => 'Heading Level', 'required' => false, 'allowed_values' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], 'default' => 'h1'],
                ['key' => 'align', 'type' => 'enum', 'label' => 'Alignment', 'required' => false, 'allowed_values' => ['left', 'center', 'right'], 'default' => 'left'],
                ['key' => 'text', 'type' => 'string', 'label' => 'Fallback Title', 'required' => false, 'default' => '', 'max_length' => 255],
            ]),
            allowsChildren: false,
            allowedBindingProviders: ['content.posts'],
        ));

        // 15. theme.post_content
        $registry->register(new DeclarativeBlockDefinition(
            type: 'theme.post_content',
            version: 1,
            label: 'Post Content',
            title: 'Post content',
            description: 'Dynamic post body copy',
            category: 'theme',
            icon: 'pilcrow',
            schema: FieldSchema::define([
                ['key' => 'content', 'type' => 'text', 'label' => 'Fallback Content', 'required' => false, 'default' => '', 'max_length' => 50000],
            ]),
            allowsChildren: false,
            allowedBindingProviders: ['content.posts'],
        ));

        // 16. theme.post_meta
        $registry->register(new DeclarativeBlockDefinition(
            type: 'theme.post_meta',
            version: 1,
            label: 'Post Meta',
            title: 'Post meta',
            description: 'Author, date & category info',
            category: 'theme',
            icon: 'list-check',
            schema: FieldSchema::define([
                ['key' => 'show_author', 'type' => 'boolean', 'label' => 'Show Author', 'required' => false, 'default' => true],
                ['key' => 'show_date', 'type' => 'boolean', 'label' => 'Show Date', 'required' => false, 'default' => true],
                ['key' => 'show_category', 'type' => 'boolean', 'label' => 'Show Category', 'required' => false, 'default' => true],
                ['key' => 'separator', 'type' => 'string', 'label' => 'Separator', 'required' => false, 'default' => ' • ', 'max_length' => 10],
            ]),
            allowsChildren: false,
            allowedBindingProviders: ['content.posts', 'content.authors'],
        ));

        // 17. theme.archive_title
        $registry->register(new DeclarativeBlockDefinition(
            type: 'theme.archive_title',
            version: 1,
            label: 'Archive Title',
            title: 'Archive title',
            description: 'Taxonomy & archive heading',
            category: 'theme',
            icon: 'heading',
            schema: FieldSchema::define([
                ['key' => 'level', 'type' => 'enum', 'label' => 'Heading Level', 'required' => false, 'allowed_values' => ['h1', 'h2', 'h3'], 'default' => 'h1'],
                ['key' => 'title', 'type' => 'string', 'label' => 'Custom Title', 'required' => false, 'default' => '', 'max_length' => 255],
            ]),
            allowsChildren: false,
            allowedBindingProviders: ['content.taxonomy'],
        ));

        // 18. theme.search_box
        $registry->register(new DeclarativeBlockDefinition(
            type: 'theme.search_box',
            version: 1,
            label: 'Search Box',
            title: 'Search box',
            description: 'Site search input box',
            category: 'theme',
            icon: 'search',
            schema: FieldSchema::define([
                ['key' => 'placeholder', 'type' => 'string', 'label' => 'Placeholder', 'required' => false, 'default' => 'Search articles...', 'max_length' => 120],
                ['key' => 'button_text', 'type' => 'string', 'label' => 'Button Text', 'required' => false, 'default' => 'Search', 'max_length' => 60],
            ]),
            allowsChildren: false,
        ));

        // 19. core.modal
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.modal',
            version: 1,
            label: 'Modal Popup',
            title: 'Modal',
            description: 'Pop-up modal dialog',
            category: 'advanced',
            icon: 'box',
            schema: FieldSchema::define([
                ['key' => 'modal_id', 'type' => 'string', 'label' => 'Modal ID', 'required' => false, 'default' => 'modal-1', 'max_length' => 64],
                ['key' => 'title', 'type' => 'string', 'label' => 'Modal Title', 'required' => false, 'default' => 'Modal Title', 'max_length' => 255],
                ['key' => 'size', 'type' => 'enum', 'label' => 'Dialog Size', 'required' => false, 'allowed_values' => ['sm', 'md', 'lg', 'full'], 'default' => 'md'],
                ['key' => 'trigger_text', 'type' => 'string', 'label' => 'Trigger Button Text', 'required' => false, 'default' => 'Open Modal', 'max_length' => 100],
                ['key' => 'trigger_variant', 'type' => 'enum', 'label' => 'Trigger Variant', 'required' => false, 'allowed_values' => ['primary', 'secondary', 'outline', 'ghost'], 'default' => 'primary'],
            ]),
            allowsChildren: true,
        ));

        // 20. layout.offcanvas
        $registry->register(new DeclarativeBlockDefinition(
            type: 'layout.offcanvas',
            version: 1,
            label: 'Offcanvas Drawer',
            title: 'Offcanvas',
            description: 'Slide-out navigation drawer',
            category: 'layout',
            icon: 'layout-section',
            schema: FieldSchema::define([
                ['key' => 'drawer_id', 'type' => 'string', 'label' => 'Drawer ID', 'required' => false, 'default' => 'drawer-1', 'max_length' => 64],
                ['key' => 'title', 'type' => 'string', 'label' => 'Drawer Title', 'required' => false, 'default' => 'Menu', 'max_length' => 255],
                ['key' => 'position', 'type' => 'enum', 'label' => 'Drawer Position', 'required' => false, 'allowed_values' => ['left', 'right', 'top', 'bottom'], 'default' => 'right'],
                ['key' => 'trigger_text', 'type' => 'string', 'label' => 'Trigger Button Text', 'required' => false, 'default' => 'Open Menu', 'max_length' => 100],
                ['key' => 'trigger_variant', 'type' => 'enum', 'label' => 'Trigger Variant', 'required' => false, 'allowed_values' => ['primary', 'secondary', 'outline', 'ghost'], 'default' => 'outline'],
            ]),
            allowsChildren: true,
        ));

        // 21. core.form
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.form',
            version: 1,
            label: 'Form',
            title: 'Form',
            description: 'Interactive form builder',
            category: 'forms',
            icon: 'form',
            schema: FieldSchema::define([
                ['key' => 'action', 'type' => 'string', 'label' => 'Action URL', 'required' => false, 'default' => '', 'max_length' => 500],
                ['key' => 'method', 'type' => 'enum', 'label' => 'Method', 'required' => false, 'allowed_values' => ['post', 'get'], 'default' => 'post'],
                ['key' => 'form_name', 'type' => 'string', 'label' => 'Form Name', 'required' => false, 'default' => 'contact_form', 'max_length' => 64],
                ['key' => 'submit_text', 'type' => 'string', 'label' => 'Submit Button Text', 'required' => false, 'default' => 'Submit', 'max_length' => 60],
                ['key' => 'submit_variant', 'type' => 'enum', 'label' => 'Submit Variant', 'required' => false, 'allowed_values' => ['primary', 'secondary', 'outline'], 'default' => 'primary'],
            ]),
            allowsChildren: true,
        ));

        // 22. core.form_field
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.form_field',
            version: 1,
            label: 'Form Field',
            title: 'Form Field',
            description: 'A single input for a form',
            category: 'forms',
            icon: 'form',
            schema: FieldSchema::define([
                ['key' => 'name', 'type' => 'string', 'label' => 'Field Name', 'required' => true, 'default' => 'field_name', 'max_length' => 64],
                ['key' => 'label', 'type' => 'string', 'label' => 'Field Label', 'required' => true, 'default' => 'Field Label', 'max_length' => 120],
                ['key' => 'field_type', 'type' => 'enum', 'label' => 'Field Type', 'required' => false, 'allowed_values' => ['text', 'email', 'tel', 'number', 'url', 'textarea', 'select', 'checkbox', 'radio'], 'default' => 'text'],
                ['key' => 'placeholder', 'type' => 'string', 'label' => 'Placeholder', 'required' => false, 'default' => '', 'max_length' => 120],
                ['key' => 'required', 'type' => 'boolean', 'label' => 'Required', 'required' => false, 'default' => false],
                ['key' => 'help_text', 'type' => 'string', 'label' => 'Help Text', 'required' => false, 'default' => '', 'max_length' => 255],
                ['key' => 'options', 'type' => 'string', 'label' => 'Options (comma-separated)', 'required' => false, 'default' => '', 'max_length' => 500],
            ]),
            allowsChildren: false,
        ));

        // 23. core.gallery
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.gallery',
            version: 1,
            label: 'Gallery',
            title: 'Gallery',
            description: 'Media image gallery',
            category: 'media',
            icon: 'gallery',
            schema: FieldSchema::define([
                ['key' => 'columns', 'type' => 'enum', 'label' => 'Columns', 'required' => false, 'allowed_values' => ['2', '3', '4', '6'], 'default' => '3'],
                ['key' => 'gap', 'type' => 'enum', 'label' => 'Gap', 'required' => false, 'allowed_values' => ['none', 'xs', 'sm', 'md', 'lg'], 'default' => 'md'],
                ['key' => 'aspect_ratio', 'type' => 'enum', 'label' => 'Aspect Ratio', 'required' => false, 'allowed_values' => ['auto', '1:1', '4:3', '16:9'], 'default' => '1:1'],
                ['key' => 'rounded', 'type' => 'boolean', 'label' => 'Rounded Corners', 'required' => false, 'default' => true],
                [
                    'key'         => 'images',
                    'type'        => 'repeater',
                    'label'       => 'Gallery Images',
                    'required'    => false,
                    'default'     => [],
                    'max_items'   => 50,
                    'item_schema' => FieldSchema::define([
                        ['key' => 'url', 'type' => 'url', 'label' => 'Image URL', 'required' => true, 'max_length' => 1000],
                        ['key' => 'alt', 'type' => 'string', 'label' => 'Alt Text', 'required' => false, 'default' => '', 'max_length' => 200],
                        ['key' => 'caption', 'type' => 'string', 'label' => 'Caption', 'required' => false, 'default' => '', 'max_length' => 255],
                    ]),
                ],
            ]),
            allowsChildren: false,
        ));

        // 24. core.video
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.video',
            version: 1,
            label: 'Video',
            title: 'Video',
            description: 'Responsive video player',
            category: 'media',
            icon: 'video',
            schema: FieldSchema::define([
                ['key' => 'url', 'type' => 'string', 'label' => 'Video URL', 'required' => true, 'default' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'max_length' => 1000],
                ['key' => 'aspect_ratio', 'type' => 'enum', 'label' => 'Aspect Ratio', 'required' => false, 'allowed_values' => ['16:9', '4:3', '1:1'], 'default' => '16:9'],
                ['key' => 'autoplay', 'type' => 'boolean', 'label' => 'Autoplay', 'required' => false, 'default' => false],
                ['key' => 'controls', 'type' => 'boolean', 'label' => 'Show Controls', 'required' => false, 'default' => true],
                ['key' => 'muted', 'type' => 'boolean', 'label' => 'Muted', 'required' => false, 'default' => false],
                ['key' => 'loop', 'type' => 'boolean', 'label' => 'Loop', 'required' => false, 'default' => false],
            ]),
            allowsChildren: false,
        ));

        // 25. core.tabs — `items[].label` names the tab, `content` is its panel.
        //     The renderer pairs each tab to its panel via generated `t1`, `t2` …
        //     ids, which is the contract the runtime matches on.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.tabs',
            version: 1,
            label: 'Tabs',
            title: 'Tabs',
            description: 'Switch between panels in place',
            category: 'content',
            icon: 'grid-panes',
            schema: FieldSchema::define([
                [
                    'key'        => 'items',
                    'type'       => 'repeater',
                    'label'      => 'Tabs',
                    'required'   => false,
                    'default'    => [
                        ['label' => 'Overview', 'content' => 'What the programme covers at a glance.'],
                        ['label' => 'Detail', 'content' => 'The finer points, schedules and requirements.'],
                        ['label' => 'Next steps', 'content' => 'How to get started in under a minute.'],
                    ],
                    'max_items'  => 8,
                    'item_schema' => FieldSchema::define([
                        ['key' => 'label', 'type' => 'string', 'label' => 'Tab Label', 'required' => true, 'max_length' => 60],
                        ['key' => 'content', 'type' => 'text', 'label' => 'Panel Content', 'required' => false, 'default' => '', 'max_length' => 2000],
                    ]),
                ],
                [
                    'key'        => 'options',
                    'type'       => 'object',
                    'label'      => 'Options',
                    'required'   => false,
                    'properties' => FieldSchema::define([
                        ['key' => 'position', 'type' => 'enum', 'label' => 'Tab Position', 'required' => false, 'allowed_values' => ['top', 'bottom'], 'default' => 'top'],
                    ]),
                ],
            ]),
            allowsChildren: false,
        ));

        // 26. core.accordion — FAQ rows. The first row renders open, so the
        //     no-JS state is readable instead of everything collapsed.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.accordion',
            version: 1,
            label: 'Accordion',
            title: 'Accordion',
            description: 'Stacked rows that expand, ideal for FAQs',
            category: 'content',
            icon: 'list-check',
            schema: FieldSchema::define([
                [
                    'key'        => 'items',
                    'type'       => 'repeater',
                    'label'      => 'Rows',
                    'required'   => false,
                    'default'    => [
                        ['heading' => 'How do I get started?', 'body' => 'Pick a track, book a trial class, and we handle the rest.'],
                        ['heading' => 'Can I freeze my membership?', 'body' => 'Yes — freeze for up to three months, in one go or in slices.'],
                        ['heading' => 'What should I bring?', 'body' => 'Comfortable clothes, water, and socks you can move in.'],
                    ],
                    'max_items'  => 20,
                    'item_schema' => FieldSchema::define([
                        ['key' => 'heading', 'type' => 'string', 'label' => 'Question', 'required' => true, 'max_length' => 200],
                        ['key' => 'body', 'type' => 'text', 'label' => 'Answer', 'required' => false, 'default' => '', 'max_length' => 2000],
                    ]),
                ],
                ['key' => 'allow_multiple', 'type' => 'boolean', 'label' => 'Allow Multiple Open', 'required' => false, 'default' => false],
            ]),
            allowsChildren: false,
        ));

        // 27. core.carousel — image and/or testimonial slides with dots and
        //     optional autoplay, matching the runtime's data-sb-carousel contract.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.carousel',
            version: 1,
            label: 'Carousel',
            title: 'Carousel',
            description: 'Image and testimonial slides with dots',
            category: 'media',
            icon: 'gallery',
            schema: FieldSchema::define([
                [
                    'key'        => 'slides',
                    'type'       => 'repeater',
                    'label'      => 'Slides',
                    'required'   => false,
                    'default'    => [
                        ['image' => null, 'quote' => 'The small classes are the reason I stayed.', 'author' => 'Mara, two years in', 'caption' => ''],
                        ['image' => null, 'quote' => 'First class I have not talked myself out of.', 'author' => 'Tobias, member', 'caption' => ''],
                        ['image' => null, 'quote' => 'A syllabus that actually changes each season.', 'author' => 'Saartje, teacher', 'caption' => ''],
                    ],
                    'max_items'  => 20,
                    'item_schema' => FieldSchema::define([
                        ['key' => 'image', 'type' => 'media_ref', 'label' => 'Slide Image', 'required' => false, 'default' => null],
                        ['key' => 'quote', 'type' => 'text', 'label' => 'Quote', 'required' => false, 'default' => '', 'max_length' => 600],
                        ['key' => 'author', 'type' => 'string', 'label' => 'Attribution', 'required' => false, 'default' => '', 'max_length' => 120],
                        ['key' => 'caption', 'type' => 'string', 'label' => 'Caption', 'required' => false, 'default' => '', 'max_length' => 255],
                    ]),
                ],
                [
                    'key'        => 'options',
                    'type'       => 'object',
                    'label'      => 'Options',
                    'required'   => false,
                    'properties' => FieldSchema::define([
                        ['key' => 'per_view', 'type' => 'number', 'label' => 'Slides Per View', 'required' => false, 'default' => 1, 'min' => 1, 'max' => 3, 'integer_only' => true],
                        ['key' => 'loop', 'type' => 'boolean', 'label' => 'Loop', 'required' => false, 'default' => true],
                        ['key' => 'autoplay_ms', 'type' => 'number', 'label' => 'Autoplay (ms, 0 = off)', 'required' => false, 'default' => 0, 'min' => 0, 'max' => 60000, 'integer_only' => true],
                    ]),
                ],
            ]),
            allowsChildren: false,
        ));

        // 28. core.stats — animated figures. `decimals` caps at 3 so the
        //     pre-rendered number and the runtime's count-up format identically
        //     and never visibly jump.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.stats',
            version: 1,
            label: 'Stats',
            title: 'Stats',
            description: 'Animated figures that count up',
            category: 'content',
            icon: 'grid-dots',
            schema: FieldSchema::define([
                [
                    'key'        => 'items',
                    'type'       => 'repeater',
                    'label'      => 'Figures',
                    'required'   => false,
                    'default'    => [
                        ['value' => 1840, 'label' => 'Students taught', 'prefix' => '', 'suffix' => '', 'decimals' => 0],
                        ['value' => 96, 'label' => 'Continue past year one', 'prefix' => '', 'suffix' => '%', 'decimals' => 0],
                        ['value' => 11, 'label' => 'Working artists on staff', 'prefix' => '+', 'suffix' => '', 'decimals' => 0],
                        ['value' => 4, 'label' => 'Days a week, year round', 'prefix' => '', 'suffix' => '', 'decimals' => 0],
                    ],
                    'max_items'  => 6,
                    'item_schema' => FieldSchema::define([
                        ['key' => 'value', 'type' => 'number', 'label' => 'Value', 'required' => true, 'min' => -1000000000, 'max' => 1000000000],
                        ['key' => 'label', 'type' => 'string', 'label' => 'Label', 'required' => true, 'max_length' => 120],
                        ['key' => 'prefix', 'type' => 'string', 'label' => 'Prefix', 'required' => false, 'default' => '', 'max_length' => 8],
                        ['key' => 'suffix', 'type' => 'string', 'label' => 'Suffix', 'required' => false, 'default' => '', 'max_length' => 8],
                        ['key' => 'decimals', 'type' => 'number', 'label' => 'Decimals', 'required' => false, 'default' => 0, 'min' => 0, 'max' => 3, 'integer_only' => true],
                    ]),
                ],
            ]),
            allowsChildren: false,
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
