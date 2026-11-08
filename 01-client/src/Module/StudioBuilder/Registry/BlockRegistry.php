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
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\ContainerRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\CountdownRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\AudioRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\MarqueeRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\MegaItemRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\MegaMenuRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\NavChrome;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\LocalTimeRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\MessageWidgetRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\TestimonialGridRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\QueryLoopRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\TabSetRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\VideoRenderer;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\Icon\IconLibrary;
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
     * With `$includeLocked`, a block the site is not entitled to is still listed, marked
     * `locked` with the module it needs, so the editor can say WHY it is unavailable
     * instead of silently omitting it. The server still refuses to save such a block.
     *
     * @param null|callable(?string $entitlement): bool $entitlementCheck
     * @param null|callable(string $permission): bool   $permissionCheck
     * @return list<array<string, mixed>>
     */
    public function editorManifests(?callable $entitlementCheck = null, ?callable $permissionCheck = null, bool $includeLocked = false): array
    {
        $manifests = [];
        foreach ($this->all() as $definition) {
            $ent = $definition->requiredEntitlement();
            $locked = $entitlementCheck !== null && !$entitlementCheck($ent);
            if ($locked && !$includeLocked) {
                continue;
            }
            $perm = $definition->requiredPermission();
            if ($permissionCheck !== null && !$permissionCheck($perm)) {
                continue;
            }
            $manifest = $definition->toEditorManifest();
            if ($locked) {
                $manifest['locked'] = true;
                $manifest['locked_module'] = (string) $ent;
            }
            $manifests[] = $manifest;
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
                ['key' => 'highlight', 'type' => 'string', 'label' => 'Highlighted Word', 'required' => false, 'default' => '', 'max_length' => 100],
                ['key' => 'highlight_color_token', 'type' => 'token_ref', 'label' => 'Highlight colour (theme)', 'required' => false, 'default' => null],
                ['key' => 'highlight_color', 'type' => 'string', 'label' => 'Highlight colour (custom)', 'required' => false, 'default' => '', 'max_length' => 40, 'format' => 'color', 'pattern' => Html::HIGHLIGHT_COLOR_PATTERN],
                ['key' => 'highlight_italic', 'type' => 'boolean', 'label' => 'Highlight in italic', 'required' => false, 'default' => false],
                ['key' => 'highlight_weight', 'type' => 'enum', 'label' => 'Highlight weight', 'required' => false, 'allowed_values' => ['inherit', 'normal', 'medium', 'bold'], 'default' => 'inherit'],
                ['key' => 'highlight_underline', 'type' => 'boolean', 'label' => 'Highlight underlined', 'required' => false, 'default' => false],
                ['key' => 'subheading', 'type' => 'text', 'label' => 'Subheading', 'required' => false, 'default' => '', 'max_length' => 1000],
                ['key' => 'primary_cta', 'type' => 'link', 'label' => 'Primary Call to Action', 'required' => false, 'default' => null],
                ['key' => 'media', 'type' => 'media_ref', 'label' => 'Hero Image', 'required' => false, 'default' => null],
                ['key' => 'accent_token', 'type' => 'token_ref', 'label' => 'Accent Token', 'required' => false, 'default' => null],
            ]),
            allowsChildren: false,
            inlineText: [['prop' => 'heading', 'selector' => '.sb-hero__heading'], ['prop' => 'eyebrow', 'selector' => '.sb-hero__eyebrow'], ['prop' => 'subheading', 'selector' => '.sb-hero__subheading']],
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
                ['key' => 'text', 'type' => 'text', 'label' => 'Title', 'required' => true, 'default' => 'Section Heading', 'max_length' => 300],
                // Title, Link, HTML Tag, then the highlight, in the order the reference editor shows them.
                ['key' => 'url', 'type' => 'url', 'label' => 'Link', 'required' => false, 'default' => null],
                ['key' => 'new_tab', 'type' => 'boolean', 'label' => 'Open in a new tab', 'required' => false, 'default' => false],
                // The HTML element: a heading level, or div / span / p for text styled as a heading that is not one.
                ['key' => 'level', 'type' => 'enum', 'label' => 'HTML Tag', 'required' => false, 'allowed_values' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p'], 'default' => 'h2'],
                ['key' => 'highlight', 'type' => 'string', 'label' => 'Highlighted Word', 'required' => false, 'default' => '', 'max_length' => 100],
                ['key' => 'highlight_color_token', 'type' => 'token_ref', 'label' => 'Highlight colour (theme)', 'required' => false, 'default' => null],
                ['key' => 'highlight_color', 'type' => 'string', 'label' => 'Highlight colour (custom)', 'required' => false, 'default' => '', 'max_length' => 40, 'format' => 'color', 'pattern' => Html::HIGHLIGHT_COLOR_PATTERN],
                ['key' => 'highlight_italic', 'type' => 'boolean', 'label' => 'Highlight in italic', 'required' => false, 'default' => false],
                ['key' => 'highlight_weight', 'type' => 'enum', 'label' => 'Highlight weight', 'required' => false, 'allowed_values' => ['inherit', 'normal', 'medium', 'bold'], 'default' => 'inherit'],
                ['key' => 'highlight_underline', 'type' => 'boolean', 'label' => 'Highlight underlined', 'required' => false, 'default' => false],
            ]),
            allowsChildren: false,
            allowedBindingProviders: ['content.posts', 'content.authors', 'content.taxonomy', 'booking.services', 'membership.plans', 'forms.form'],
            // A linked heading's text is inside its link; editing in place edits that text, never the link around it.
            inlineText: [['prop' => 'text', 'selector' => '.sb-heading__link'], ['prop' => 'text', 'selector' => '.sb-heading']],
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
                ['key' => 'drop_cap', 'type' => 'boolean', 'label' => 'Drop Cap', 'required' => false, 'default' => false],
                ['key' => 'columns', 'type' => 'number', 'label' => 'Columns', 'required' => false, 'per_device' => true, 'default' => 1, 'min' => 1, 'max' => 10, 'integer_only' => true],
                ['key' => 'columns_gap', 'type' => 'number', 'label' => 'Columns Gap', 'required' => false, 'default' => 20, 'min' => 0, 'max' => 200, 'integer_only' => true],
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
                ['key' => 'link_to', 'type' => 'enum', 'label' => 'Link', 'required' => false, 'allowed_values' => ['none', 'media', 'custom'], 'default' => 'none'],
                ['key' => 'url', 'type' => 'url', 'label' => 'Link URL', 'required' => false],
                ['key' => 'new_tab', 'type' => 'boolean', 'label' => 'Open in a new tab', 'required' => false, 'default' => false],
                ['key' => 'priority', 'type' => 'boolean', 'label' => 'Load First (main picture)', 'required' => false, 'default' => false],
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
                ['key' => 'icon', 'type' => 'string', 'format' => 'icon', 'max_length' => 40, 'pattern' => '/^[a-z0-9-]{0,40}$/', 'label' => 'Icon', 'required' => false, 'default' => 'none'],
                ['key' => 'icon_position', 'type' => 'enum', 'label' => 'Icon Position', 'required' => false, 'allowed_values' => ['before', 'after'], 'default' => 'before'],
                ['key' => 'icon_spacing', 'type' => 'number', 'label' => 'Icon Spacing', 'required' => false, 'default' => 8, 'min' => 0, 'max' => 50, 'integer_only' => true],
                // Copy to clipboard: a click copies this text (an email address, a code) and the label says so for a moment;
                // without script, or where copying is refused, the button's own link still works.
                ['key' => 'copy_text', 'type' => 'string', 'label' => 'Copy this text when clicked', 'required' => false, 'default' => '', 'max_length' => 300],
                ['key' => 'copied_label', 'type' => 'string', 'label' => 'Label once copied', 'required' => false, 'default' => 'Copied', 'max_length' => 60],
            ]),
            allowsChildren: false,
            allowedBindingProviders: ['content.posts', 'content.authors', 'content.taxonomy', 'booking.services', 'membership.plans', 'forms.form'],
            // With an icon the label has its own span, which is what is edited in place (the icon stays).
            inlineText: [['prop' => 'link.label', 'selector' => '.sb-button__label'], ['prop' => 'link.label', 'selector' => '.sb-button']],
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
                ['key' => 'columns', 'type' => 'number', 'label' => 'Columns', 'required' => false, 'per_device' => true, 'default' => 3, 'min' => 1, 'max' => 4, 'integer_only' => true],
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
            inlineText: [['prop' => 'title', 'selector' => '.sb-feature-list__title']],
        ));

        // 7. core.container (structural container capable of holding nested child blocks)
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.container',
            version: CanonicalDocumentSchema::CONTAINER_VERSION,
            label: 'Container',
            title: 'Container',
            description: 'Inner constraint container',
            category: 'layout',
            icon: 'box',
            schema: FieldSchema::define([
                ['key' => 'gap', 'type' => 'enum', 'label' => 'Gap', 'required' => false, 'allowed_values' => CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, 'default' => 'md'],
                ['key' => 'direction', 'type' => 'enum', 'label' => 'Direction', 'required' => false, 'allowed_values' => ['vertical', 'horizontal'], 'default' => 'vertical'],
                ['key' => 'wrap', 'type' => 'enum', 'label' => 'Wrap', 'required' => false, 'allowed_values' => ['wrap', 'nowrap'], 'default' => 'wrap'],
                // Set from the Style tab: a video behind the children, and a shape divider at the top and the bottom edge.
                ['key' => 'bg_video', 'type' => 'url', 'label' => 'Background Video', 'required' => false],
                [
                    'key'         => 'bg_slides',
                    'type'        => 'repeater',
                    'label'       => 'Background Slideshow',
                    'required'    => false,
                    'default'     => [],
                    'max_items'   => 12,
                    'item_schema' => FieldSchema::define([
                        ['key' => 'url', 'type' => 'url', 'label' => 'Image URL', 'required' => true, 'max_length' => 1000],
                    ]),
                ],
                ['key' => 'bg_slide_seconds', 'type' => 'number', 'label' => 'Seconds per slide', 'required' => false, 'default' => 5, 'min' => 2, 'max' => 30, 'integer_only' => true],
                ['key' => 'shape_top', 'type' => 'enum', 'label' => 'Top Shape', 'required' => false, 'allowed_values' => ContainerRenderer::SHAPE_NAMES, 'default' => 'none'],
                ['key' => 'shape_top_color', 'type' => 'token_ref', 'label' => 'Top Shape Colour', 'required' => false, 'default' => null],
                ['key' => 'shape_top_width', 'type' => 'number', 'label' => 'Top Shape Width', 'required' => false, 'default' => 100, 'min' => 100, 'max' => 300, 'integer_only' => true],
                ['key' => 'shape_top_height', 'type' => 'number', 'label' => 'Top Shape Height', 'required' => false, 'default' => 0, 'min' => 0, 'max' => 500, 'integer_only' => true],
                ['key' => 'shape_top_flip', 'type' => 'boolean', 'label' => 'Flip Top Shape', 'required' => false, 'default' => false],
                ['key' => 'shape_top_front', 'type' => 'boolean', 'label' => 'Top Shape in Front', 'required' => false, 'default' => false],
                ['key' => 'shape_bottom', 'type' => 'enum', 'label' => 'Bottom Shape', 'required' => false, 'allowed_values' => ContainerRenderer::SHAPE_NAMES, 'default' => 'none'],
                ['key' => 'shape_bottom_color', 'type' => 'token_ref', 'label' => 'Bottom Shape Colour', 'required' => false, 'default' => null],
                ['key' => 'shape_bottom_width', 'type' => 'number', 'label' => 'Bottom Shape Width', 'required' => false, 'default' => 100, 'min' => 100, 'max' => 300, 'integer_only' => true],
                ['key' => 'shape_bottom_height', 'type' => 'number', 'label' => 'Bottom Shape Height', 'required' => false, 'default' => 0, 'min' => 0, 'max' => 500, 'integer_only' => true],
                ['key' => 'shape_bottom_flip', 'type' => 'boolean', 'label' => 'Flip Bottom Shape', 'required' => false, 'default' => false],
                ['key' => 'shape_bottom_front', 'type' => 'boolean', 'label' => 'Bottom Shape in Front', 'required' => false, 'default' => false],
            ]),
            allowsChildren: true,
            upgrades: [1 => static fn (array $props): array => $props],
        ));

        return $registry;
    }

    /**
     * The menu button's fields, shared by the Nav menu and the Mega menu.
     *
     * @return list<array<string, mixed>>
     */
    private static function menuToggleFields(string $breakpoint): array
    {
        return [
            ['key' => 'toggle_breakpoint', 'type' => 'enum', 'label' => 'Menu button shows on', 'required' => false, 'allowed_values' => NavChrome::TOGGLES, 'default' => $breakpoint],
            ['key' => 'toggle_mode', 'type' => 'enum', 'label' => 'Menu opens as', 'required' => false, 'allowed_values' => NavChrome::MODES, 'default' => 'dropdown'],
            ['key' => 'toggle_full_width', 'type' => 'boolean', 'label' => 'Dropdown spans the full width', 'required' => false, 'default' => true],
            ['key' => 'toggle_animation', 'type' => 'enum', 'label' => 'Opening animation', 'required' => false, 'allowed_values' => NavChrome::OPENINGS, 'default' => 'none'],
            ['key' => 'toggle_icon', 'type' => 'enum', 'label' => 'Menu button icon', 'required' => false, 'allowed_values' => NavChrome::ICONS, 'default' => 'hamburger'],
            ['key' => 'toggle_label', 'type' => 'string', 'label' => 'Menu button label', 'required' => false, 'default' => 'Menu', 'max_length' => 40],
            ['key' => 'toggle_align', 'type' => 'enum', 'label' => 'Menu button alignment', 'required' => false, 'allowed_values' => NavChrome::ALIGNS, 'default' => 'right'],
            ['key' => 'toggle_cta_label', 'type' => 'string', 'label' => 'Button in the open menu', 'required' => false, 'default' => '', 'max_length' => 60],
            ['key' => 'toggle_cta_url', 'type' => 'url', 'label' => 'Button link', 'required' => false, 'default' => null],
        ];
    }

    /**
     * Register the Sprint 1 layout primitives and content blocks.
     */
    public static function registerLayoutBlocks(self $registry): void
    {
        // 9. layout.container
        $registry->register(new DeclarativeBlockDefinition(
            type: 'layout.container',
            version: 1,
            label: 'Width container',
            title: 'Width container',
            description: 'A container with a set width, alignment and padding',
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
                ['key' => 'columns', 'type' => 'number', 'label' => 'Columns', 'required' => false, 'per_device' => true, 'default' => 2, 'min' => 1, 'max' => 12, 'integer_only' => true],
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
                ['key' => 'url', 'type' => 'url', 'label' => 'Link', 'required' => false, 'default' => null],
                ['key' => 'new_tab', 'type' => 'boolean', 'label' => 'Open in a new tab', 'required' => false, 'default' => false],
                ['key' => 'size', 'type' => 'enum', 'label' => 'Size', 'required' => false, 'allowed_values' => ['xs', 'sm', 'base', 'lg', 'xl', 'lead', 'inherit'], 'default' => 'base'],
                ['key' => 'align', 'type' => 'enum', 'label' => 'Alignment', 'required' => false, 'allowed_values' => ['left', 'center', 'right', 'justify'], 'default' => 'left'],
                ['key' => 'color_token', 'type' => 'token_ref', 'label' => 'Color Token', 'required' => false, 'default' => null],
                ['key' => 'drop_cap', 'type' => 'boolean', 'label' => 'Drop Cap', 'required' => false, 'default' => false],
                ['key' => 'columns', 'type' => 'number', 'label' => 'Columns', 'required' => false, 'per_device' => true, 'default' => 1, 'min' => 1, 'max' => 10, 'integer_only' => true],
                ['key' => 'columns_gap', 'type' => 'number', 'label' => 'Columns Gap', 'required' => false, 'default' => 20, 'min' => 0, 'max' => 200, 'integer_only' => true],
            ]),
            allowsChildren: false,
            allowedBindingProviders: ['content.posts', 'content.authors', 'content.taxonomy', 'booking.services', 'membership.plans', 'forms.form'],
            inlineText: [['prop' => 'content', 'selector' => '.sb-text']],
        ));

        // 13. core.query_loop
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.query_loop',
            version: 1,
            label: 'Query Loop',
            title: 'Posts grid',
            description: 'Live Service & dynamic posts',
            category: 'layout',
            icon: 'grid-panes',
            schema: FieldSchema::define([
                ['key' => 'source', 'type' => 'enum', 'label' => 'Source', 'required' => false, 'allowed_values' => ['posts', 'pages', 'custom'], 'default' => 'posts'],
                ['key' => 'content_type', 'type' => 'string', 'label' => 'Content Type', 'required' => false, 'default' => '', 'max_length' => 64, 'format' => 'content_type'],
                ['key' => 'category', 'type' => 'string', 'label' => 'Category', 'required' => false, 'default' => '', 'max_length' => 80],
                ['key' => 'tag', 'type' => 'string', 'label' => 'Tag', 'required' => false, 'default' => '', 'max_length' => 80],
                ['key' => 'author_id', 'type' => 'number', 'label' => 'Author ID', 'required' => false, 'default' => 0, 'integer_only' => true],
                ['key' => 'order_by', 'type' => 'enum', 'label' => 'Order By', 'required' => false, 'allowed_values' => ['published_at', 'title', 'created_at'], 'default' => 'published_at'],
                ['key' => 'order', 'type' => 'enum', 'label' => 'Order Direction', 'required' => false, 'allowed_values' => ['desc', 'asc'], 'default' => 'desc'],
                ['key' => 'per_page', 'type' => 'number', 'label' => 'Posts Per Page', 'required' => false, 'default' => 6, 'min' => 1, 'max' => 48, 'integer_only' => true],
                ['key' => 'featured_only', 'type' => 'boolean', 'label' => 'Featured items only', 'required' => false, 'default' => false],
                ['key' => 'columns', 'type' => 'number', 'label' => 'Grid Columns', 'required' => false, 'per_device' => true, 'default' => 3, 'min' => 1, 'max' => 6, 'integer_only' => true],
                ['key' => 'gap', 'type' => 'enum', 'label' => 'Gap', 'required' => false, 'allowed_values' => CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, 'default' => 'md'],
                ['key' => 'card_variant', 'type' => 'enum', 'label' => 'Card Variant', 'required' => false, 'allowed_values' => ['card', 'minimal', 'horizontal'], 'default' => 'card'],
                // The cards' heading level, so a loop under an h2 section heading can follow the page's outline.
                ['key' => 'title_level', 'type' => 'enum', 'label' => 'Title Heading Level', 'required' => false, 'allowed_values' => ['h2', 'h3', 'h4', 'h5', 'h6'], 'default' => 'h3'],
                ['key' => 'show_featured_image', 'type' => 'boolean', 'label' => 'Show Image', 'required' => false, 'default' => true],
                ['key' => 'show_date', 'type' => 'boolean', 'label' => 'Show Date', 'required' => false, 'default' => true],
                ['key' => 'show_author', 'type' => 'boolean', 'label' => 'Show Author', 'required' => false, 'default' => true],
                ['key' => 'show_category', 'type' => 'boolean', 'label' => 'Show Category', 'required' => false, 'default' => true],
                ['key' => 'show_excerpt', 'type' => 'boolean', 'label' => 'Show Excerpt', 'required' => false, 'default' => true],
                ['key' => 'read_more_text', 'type' => 'string', 'label' => 'Button Text', 'required' => false, 'default' => 'Read More', 'max_length' => 80],
                ['key' => 'enable_pagination', 'type' => 'boolean', 'label' => 'Pagination', 'required' => false, 'default' => true],
                ['key' => 'pagination_style', 'type' => 'enum', 'label' => 'Pager', 'required' => false, 'allowed_values' => QueryLoopRenderer::PAGER_STYLES, 'default' => 'simple'],
                ['key' => 'filter_field', 'type' => 'string', 'label' => 'Filter chips (a Taxonomy field key, or category)', 'required' => false, 'default' => '', 'max_length' => 64, 'pattern' => '/^$|^[a-z][a-z0-9_]{0,63}$/'],
                ['key' => 'filter_all_label', 'type' => 'string', 'label' => 'First chip', 'required' => false, 'default' => 'All', 'max_length' => 40],
                ['key' => 'filter_counts', 'type' => 'boolean', 'label' => 'Show counts on the chips', 'required' => false, 'default' => true],
                ['key' => 'open_in', 'type' => 'enum', 'label' => 'Open items', 'required' => false, 'allowed_values' => QueryLoopRenderer::OPEN_IN, 'default' => 'page'],
                ['key' => 'loop_template', 'type' => 'string', 'label' => 'Loop Item', 'required' => false, 'default' => '', 'max_length' => 64, 'format' => 'loop_template', 'pattern' => '/^[a-z0-9-]{0,64}$/'],
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
                ['key' => 'link_to_post', 'type' => 'boolean', 'label' => 'Link to the item', 'required' => false, 'default' => false],
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

        // theme.field_value — one custom field of the page being shown
        $registry->register(new DeclarativeBlockDefinition(
            type: 'theme.field_value',
            version: 1,
            label: 'Field value',
            title: 'Field value',
            description: 'A custom field of this page',
            category: 'theme',
            icon: 'list-check',
            schema: FieldSchema::define([
                ['key' => 'field', 'type' => 'string', 'label' => 'Field', 'required' => false, 'default' => '', 'max_length' => 64, 'format' => 'meta_field', 'pattern' => '/^([a-z0-9_]{1,64})?$/'],
                ['key' => 'show_label', 'type' => 'boolean', 'label' => 'Show the field name', 'required' => false, 'default' => false],
                ['key' => 'fallback', 'type' => 'string', 'label' => 'Text when empty', 'required' => false, 'default' => '', 'max_length' => 255],
                ['key' => 'tag', 'type' => 'enum', 'label' => 'HTML tag', 'required' => false, 'allowed_values' => ['div', 'p', 'span', 'h2', 'h3', 'h4'], 'default' => 'div'],
                ['key' => 'link_to_post', 'type' => 'boolean', 'label' => 'Link to the item', 'required' => false, 'default' => false],
                ['key' => 'fit', 'type' => 'enum', 'label' => 'Image fit', 'required' => false, 'allowed_values' => ['auto', 'cover', 'contain'], 'default' => 'auto'],
                ['key' => 'before', 'type' => 'string', 'label' => 'Text before the value', 'required' => false, 'default' => '', 'max_length' => 60],
                ['key' => 'after', 'type' => 'string', 'label' => 'Text after the value', 'required' => false, 'default' => '', 'max_length' => 60],
                ['key' => 'list_style', 'type' => 'enum', 'label' => 'A list shows as', 'required' => false, 'allowed_values' => ['list', 'chips', 'ticker'], 'default' => 'list'],
                ['key' => 'ticker_speed', 'type' => 'number', 'label' => 'Ticker speed', 'required' => false, 'default' => 3, 'min' => 1, 'max' => 10, 'integer_only' => true],
                ['key' => 'url_label', 'type' => 'enum', 'label' => 'A web address shows as', 'required' => false, 'allowed_values' => ['full', 'domain', 'text'], 'default' => 'full'],
                ['key' => 'link_text', 'type' => 'string', 'label' => 'Link text', 'required' => false, 'default' => '', 'max_length' => 80],
                ['key' => 'link_arrow', 'type' => 'boolean', 'label' => 'Arrow after the link', 'required' => false, 'default' => false],
                ['key' => 'alt_field', 'type' => 'string', 'label' => 'Image description from field', 'required' => false, 'default' => '', 'max_length' => 64, 'format' => 'meta_field', 'pattern' => '/^([a-z0-9_]{1,64})?$/'],
                ['key' => 'priority', 'type' => 'boolean', 'label' => 'Load First (main picture)', 'required' => false, 'default' => false],
                ['key' => 'bg_field', 'type' => 'string', 'label' => 'Background colour from field', 'required' => false, 'default' => '', 'max_length' => 64, 'format' => 'meta_field', 'pattern' => '/^([a-z0-9_]{1,64})?$/'],
            ]),
            allowsChildren: false,
            allowedBindingProviders: [],
        ));

        // core.dual_heading — a coloured part between two plain parts
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.dual_heading',
            version: 1,
            label: 'Dual Color Heading',
            title: 'Dual color heading',
            description: 'A heading with a coloured part',
            category: 'content',
            icon: 'heading',
            schema: FieldSchema::define([
                ['key' => 'before', 'type' => 'text', 'label' => 'Before text', 'required' => false, 'default' => 'Heading with', 'max_length' => 300],
                ['key' => 'colored', 'type' => 'text', 'label' => 'Colored text', 'required' => false, 'default' => 'colored', 'max_length' => 300],
                // More words for the coloured part, comma separated: it changes to each in turn, then back to its own.
                ['key' => 'rotate_words', 'type' => 'string', 'label' => 'Rotating words', 'required' => false, 'default' => '', 'max_length' => 400],
                ['key' => 'rotate_ms', 'type' => 'number', 'label' => 'Change every (ms)', 'required' => false, 'default' => 2800, 'min' => 1000, 'max' => 10000, 'integer_only' => true],
                ['key' => 'after', 'type' => 'text', 'label' => 'After text', 'required' => false, 'default' => 'words', 'max_length' => 300],
                ['key' => 'url', 'type' => 'url', 'label' => 'Link', 'required' => false, 'default' => null],
                ['key' => 'new_tab', 'type' => 'boolean', 'label' => 'Open in a new tab', 'required' => false, 'default' => false],
                ['key' => 'level', 'type' => 'enum', 'label' => 'HTML Tag', 'required' => false, 'allowed_values' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p'], 'default' => 'h2'],
            ]),
            allowsChildren: false,
        ));

        // core.icon_list — items with an icon and a link
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.icon_list',
            version: 1,
            label: 'Icon List',
            title: 'Icon list',
            description: 'A list with an icon and a link on each item',
            category: 'content',
            icon: 'list-check',
            schema: FieldSchema::define([
                ['key' => 'layout', 'type' => 'enum', 'label' => 'Layout', 'required' => false, 'allowed_values' => ['list', 'inline'], 'default' => 'list'],
                [
                    'key'        => 'items',
                    'type'       => 'repeater',
                    'label'      => 'Items',
                    'required'   => false,
                    'max_items'  => 100,
                    'default'    => [['text' => 'List item', 'icon' => 'check'], ['text' => 'List item', 'icon' => 'x'], ['text' => 'List item', 'icon' => 'circle-dot']],
                    'item_schema' => FieldSchema::define([
                        ['key' => 'text', 'type' => 'string', 'label' => 'Text', 'required' => false, 'default' => '', 'max_length' => 300],
                        ['key' => 'icon', 'type' => 'string', 'format' => 'icon', 'max_length' => 40, 'pattern' => '/^[a-z0-9-]{0,40}$/', 'label' => 'Icon', 'required' => false, 'default' => 'check'],
                        ['key' => 'url', 'type' => 'url', 'label' => 'Link', 'required' => false, 'default' => null],
                        ['key' => 'new_tab', 'type' => 'boolean', 'label' => 'Open in a new tab', 'required' => false, 'default' => false],
                    ]),
                ],
                ['key' => 'apply_link', 'type' => 'enum', 'label' => 'Apply Link On', 'required' => false, 'allowed_values' => ['full', 'inline'], 'default' => 'full'],
                ['key' => 'space_between', 'type' => 'number', 'label' => 'Space Between', 'required' => false, 'per_device' => true, 'min' => 0, 'max' => 100, 'integer_only' => true, 'default' => 0],
                ['key' => 'list_align', 'type' => 'enum', 'label' => 'List Alignment', 'required' => false, 'per_device' => true, 'allowed_values' => ['left', 'center', 'right'], 'default' => 'left'],
                ['key' => 'divider', 'type' => 'boolean', 'label' => 'Divider', 'required' => false, 'default' => false],
                ['key' => 'icon_size', 'type' => 'number', 'label' => 'Icon Size', 'required' => false, 'per_device' => true, 'min' => 6, 'max' => 100, 'integer_only' => true, 'default' => 16],
                ['key' => 'icon_gap', 'type' => 'number', 'label' => 'Icon Gap', 'required' => false, 'per_device' => true, 'min' => 0, 'max' => 50, 'integer_only' => true, 'default' => 8],
                ['key' => 'icon_h_align', 'type' => 'enum', 'label' => 'Horizontal Alignment', 'required' => false, 'allowed_values' => ['left', 'center', 'right'], 'default' => 'left'],
                ['key' => 'icon_v_align', 'type' => 'enum', 'label' => 'Vertical Alignment', 'required' => false, 'allowed_values' => ['top', 'center', 'bottom'], 'default' => 'center'],
                ['key' => 'icon_offset', 'type' => 'number', 'label' => 'Adjust Vertical Position', 'required' => false, 'per_device' => true, 'min' => -15, 'max' => 15, 'integer_only' => true, 'default' => 0],
            ]),
            allowsChildren: false,
            allowedBindingProviders: [],
        ));

        // theme.post_image, theme.post_excerpt, theme.post_link — the item a Loop Item is drawing
        $registry->register(new DeclarativeBlockDefinition(
            type: 'theme.post_image',
            version: 1,
            label: 'Post Image',
            title: 'Post image',
            description: 'The item\'s featured image, in a Loop Item',
            category: 'theme',
            icon: 'image',
            schema: FieldSchema::define([
                ['key' => 'link_to_post', 'type' => 'boolean', 'label' => 'Link to the item', 'required' => false, 'default' => true],
            ]),
            allowsChildren: false,
            allowedBindingProviders: [],
        ));
        $registry->register(new DeclarativeBlockDefinition(
            type: 'theme.post_excerpt',
            version: 1,
            label: 'Post Excerpt',
            title: 'Post excerpt',
            description: 'The item\'s short description, in a Loop Item',
            category: 'theme',
            icon: 'list-check',
            schema: FieldSchema::define([]),
            allowsChildren: false,
            allowedBindingProviders: [],
        ));
        $registry->register(new DeclarativeBlockDefinition(
            type: 'theme.post_link',
            version: 1,
            label: 'Post Link',
            title: 'Post link',
            description: 'A link to the item, in a Loop Item',
            category: 'theme',
            icon: 'list-check',
            schema: FieldSchema::define([
                ['key' => 'text', 'type' => 'string', 'label' => 'Link text', 'required' => false, 'default' => 'Read more', 'max_length' => 80],
            ]),
            allowsChildren: false,
            allowedBindingProviders: [],
        ));

        // theme.repeater — the rows of one repeater field; children repeat once per row
        $registry->register(new DeclarativeBlockDefinition(
            type: 'theme.repeater',
            version: 1,
            label: 'Repeater',
            title: 'Repeater',
            description: 'The rows of a repeater field',
            category: 'theme',
            icon: 'list-check',
            schema: FieldSchema::define([
                ['key' => 'field', 'type' => 'string', 'label' => 'Field', 'required' => false, 'default' => '', 'max_length' => 64, 'format' => 'meta_field', 'pattern' => '/^([a-z0-9_]{1,64})?$/'],
                ['key' => 'display', 'type' => 'enum', 'label' => 'Show as', 'required' => false, 'allowed_values' => ['list', 'accordion'], 'default' => 'list'],
                ['key' => 'title_field', 'type' => 'string', 'label' => 'Title sub-field', 'required' => false, 'default' => '', 'max_length' => 64, 'format' => 'meta_field', 'pattern' => '/^([a-z0-9_]{1,64})?$/'],
                ['key' => 'body_field', 'type' => 'string', 'label' => 'Text sub-field', 'required' => false, 'default' => '', 'max_length' => 64, 'format' => 'meta_field', 'pattern' => '/^([a-z0-9_]{1,64})?$/'],
                ['key' => 'allow_multiple', 'type' => 'boolean', 'label' => 'Allow several open', 'required' => false, 'default' => false],
                ['key' => 'look', 'type' => 'enum', 'label' => 'Rows look', 'required' => false, 'allowed_values' => ['boxed', 'lines'], 'default' => 'boxed'],
                ['key' => 'icon', 'type' => 'enum', 'label' => 'Open icon', 'required' => false, 'allowed_values' => ['chevron', 'plus'], 'default' => 'chevron'],
            ]),
            allowsChildren: true,
            allowedBindingProviders: [],
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
                ['key' => 'position', 'type' => 'enum', 'label' => 'Drawer Position', 'required' => false, 'allowed_values' => ['left', 'right', 'top', 'bottom', 'fullscreen'], 'default' => 'right'],
                ['key' => 'close_text', 'type' => 'string', 'label' => 'Close button text', 'required' => false, 'default' => '', 'max_length' => 40],
                ['key' => 'trigger_text', 'type' => 'string', 'label' => 'Trigger Button Text', 'required' => false, 'default' => 'Open Menu', 'max_length' => 100],
                ['key' => 'trigger_variant', 'type' => 'enum', 'label' => 'Trigger Variant', 'required' => false, 'allowed_values' => ['primary', 'secondary', 'outline', 'ghost', 'plain'], 'default' => 'outline'],
                ['key' => 'trigger_icon', 'type' => 'string', 'format' => 'icon', 'max_length' => 40, 'pattern' => '/^[a-z0-9-]{0,40}$/', 'label' => 'Trigger Icon', 'required' => false, 'default' => 'none'],
                ['key' => 'trigger_icon_size', 'type' => 'number', 'label' => 'Trigger Icon Size', 'required' => false, 'default' => 24, 'min' => 12, 'max' => 64, 'integer_only' => true],
                ['key' => 'trigger_show_text', 'type' => 'boolean', 'label' => 'Show the trigger text', 'required' => false, 'default' => true],
                ['key' => 'trigger_text_mobile', 'type' => 'boolean', 'label' => 'Show the trigger text on phones', 'required' => false, 'default' => true],
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
                // What happens after a submit (StudioFormSync makes the form a Forms form on publish; Forms stores it).
                ['key' => 'success_message', 'type' => 'text', 'label' => 'Success Message', 'required' => false, 'default' => 'Thanks! We received your message.', 'max_length' => 500],
                ['key' => 'redirect_url', 'type' => 'string', 'label' => 'Redirect To (optional)', 'required' => false, 'default' => '', 'max_length' => 500],
                ['key' => 'notify_email', 'type' => 'string', 'label' => 'Email Submissions To', 'required' => false, 'default' => '', 'max_length' => 200],
                // Layout: hidden labels stay readable to screen readers; inline puts the fields and the button on one row.
                ['key' => 'labels', 'type' => 'enum', 'label' => 'Field Labels', 'required' => false, 'allowed_values' => ['visible', 'hidden'], 'default' => 'visible'],
                ['key' => 'layout', 'type' => 'enum', 'label' => 'Form Layout', 'required' => false, 'allowed_values' => ['stacked', 'inline'], 'default' => 'stacked'],
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
                ['key' => 'field_type', 'type' => 'enum', 'label' => 'Field Type', 'required' => false, 'allowed_values' => ['text', 'email', 'tel', 'number', 'url', 'textarea', 'select', 'checkbox', 'radio', 'checkboxes', 'date', 'time', 'file', 'hidden'], 'default' => 'text'],
                ['key' => 'placeholder', 'type' => 'string', 'label' => 'Placeholder', 'required' => false, 'default' => '', 'max_length' => 120],
                ['key' => 'required', 'type' => 'boolean', 'label' => 'Required', 'required' => false, 'default' => false],
                ['key' => 'help_text', 'type' => 'string', 'label' => 'Help Text', 'required' => false, 'default' => '', 'max_length' => 255],
                ['key' => 'options', 'type' => 'string', 'label' => 'Options (comma-separated)', 'required' => false, 'default' => '', 'max_length' => 500],
                ['key' => 'default_value', 'type' => 'string', 'label' => 'Default Value', 'required' => false, 'default' => '', 'max_length' => 255],
                // Filled from the page address (contact/?reason=cours): which parameters, and what their values become.
                ['key' => 'url_param', 'type' => 'string', 'label' => 'Fill from the page address (parameter names)', 'required' => false, 'default' => '', 'max_length' => 120, 'pattern' => '~^[A-Za-z0-9_\- ,]*$~'],
                ['key' => 'url_values', 'type' => 'text', 'label' => 'Address values (value=Option, one per line)', 'required' => false, 'default' => '', 'max_length' => 2000],
                // A field's share of the form's row; two halves sit side by side. A phone stacks them unless set per device.
                ['key' => 'width', 'type' => 'enum', 'label' => 'Width', 'required' => false, 'per_device' => true, 'allowed_values' => ['100', '75', '66', '50', '33', '25'], 'default' => '100'],
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
                [
                    'key'         => 'images',
                    'type'        => 'repeater',
                    'label'       => 'Gallery Images',
                    'required'    => false,
                    'default'     => [],
                    'max_items'   => 300,
                    'item_schema' => FieldSchema::define([
                        ['key' => 'url', 'type' => 'url', 'label' => 'Image URL', 'required' => true, 'max_length' => 1000],
                        ['key' => 'alt', 'type' => 'string', 'label' => 'Alt Text', 'required' => false, 'default' => '', 'max_length' => 200],
                        ['key' => 'caption', 'type' => 'string', 'label' => 'Caption', 'required' => false, 'default' => '', 'max_length' => 255],
                        ['key' => 'category', 'type' => 'string', 'label' => 'Category', 'required' => false, 'default' => '', 'max_length' => 60],
                        ['key' => 'width', 'type' => 'number', 'label' => 'Width (px)', 'required' => false, 'min' => 0, 'max' => 20000, 'integer_only' => true],
                        ['key' => 'height', 'type' => 'number', 'label' => 'Height (px)', 'required' => false, 'min' => 0, 'max' => 20000, 'integer_only' => true],
                    ]),
                ],
                ['key' => 'order', 'type' => 'enum', 'label' => 'Order By', 'required' => false, 'allowed_values' => ['default', 'random'], 'default' => 'default'],
                ['key' => 'lazy_load', 'type' => 'boolean', 'label' => 'Lazy Load', 'required' => false, 'default' => true],
                ['key' => 'layout', 'type' => 'enum', 'label' => 'Layout', 'required' => false, 'allowed_values' => ['grid', 'masonry', 'justified', 'mosaic', 'slider'], 'default' => 'grid'],
                ['key' => 'columns', 'type' => 'enum', 'label' => 'Columns', 'required' => false, 'per_device' => true, 'allowed_values' => ['1', '2', '3', '4', '5', '6'], 'default' => '3'],
                ['key' => 'spacing', 'type' => 'number', 'label' => 'Spacing', 'required' => false, 'per_device' => true, 'min' => 0, 'max' => 100, 'integer_only' => true],
                ['key' => 'gap', 'type' => 'enum', 'label' => 'Gap', 'required' => false, 'allowed_values' => ['none', 'xs', 'sm', 'md', 'lg'], 'default' => 'md'],
                ['key' => 'aspect_ratio', 'type' => 'enum', 'label' => 'Aspect Ratio', 'required' => false, 'allowed_values' => ['auto', '1:1', '4:3', '3:2', '16:9', '3:4', '4:5'], 'default' => '1:1'],
                ['key' => 'row_height', 'type' => 'number', 'label' => 'Row Height (justified)', 'required' => false, 'default' => 240, 'min' => 120, 'max' => 480, 'integer_only' => true],
                ['key' => 'rounded', 'type' => 'boolean', 'label' => 'Rounded Corners', 'required' => false, 'default' => true],
                ['key' => 'hover', 'type' => 'enum', 'label' => 'Hover Effect', 'required' => false, 'allowed_values' => ['none', 'zoom', 'lift', 'dim', 'color'], 'default' => 'none'],
                ['key' => 'captions', 'type' => 'enum', 'label' => 'Captions', 'required' => false, 'allowed_values' => ['below', 'hover', 'none'], 'default' => 'below'],
                ['key' => 'link_to', 'type' => 'enum', 'label' => 'Link', 'required' => false, 'allowed_values' => ['lightbox', 'file', 'none'], 'default' => 'lightbox'],
                ['key' => 'overlay_text', 'type' => 'enum', 'label' => 'Overlay Text', 'required' => false, 'allowed_values' => ['caption', 'alt'], 'default' => 'caption'],
                ['key' => 'pagination', 'type' => 'enum', 'label' => 'Pagination', 'required' => false, 'allowed_values' => ['none', 'load_more', 'infinite'], 'default' => 'none'],
                ['key' => 'per_page', 'type' => 'number', 'label' => 'Images per Page', 'required' => false, 'default' => 12, 'min' => 2, 'max' => 100, 'integer_only' => true],
                ['key' => 'load_more_text', 'type' => 'string', 'label' => 'Load More Text', 'required' => false, 'default' => 'Load more', 'max_length' => 60],
                ['key' => 'show_filters', 'type' => 'boolean', 'label' => 'Show Category Filters', 'required' => false, 'default' => false],
                ['key' => 'all_label', 'type' => 'string', 'label' => 'All Filter Label', 'required' => false, 'default' => 'All', 'max_length' => 40],
            ]),
            allowsChildren: false,
        ));

        // 24. core.video
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.video',
            version: 1,
            label: 'Video',
            title: 'Video',
            description: 'YouTube, Vimeo or self-hosted video, played on the page',
            category: 'media',
            icon: 'video',
            // Source picks which address plays: `url` for YouTube and Vimeo (the provider is read from the link, so a
            // block saved before Source existed keeps playing), `hosted_url` for a file. An image overlay shows a
            // poster that turns into the player when clicked, in place or in a lightbox.
            schema: FieldSchema::define([
                ['key' => 'source', 'type' => 'enum', 'label' => 'Source', 'required' => false, 'allowed_values' => VideoRenderer::SOURCES, 'default' => 'youtube'],
                ['key' => 'url', 'type' => 'string', 'label' => 'Video URL', 'required' => false, 'default' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'max_length' => 1000],
                ['key' => 'hosted_url', 'type' => 'url', 'label' => 'Video File', 'required' => false, 'max_length' => 1000],
                ['key' => 'start', 'type' => 'number', 'label' => 'Start Time (seconds)', 'required' => false, 'default' => 0, 'min' => 0, 'max' => VideoRenderer::MAX_SECONDS, 'integer_only' => true],
                ['key' => 'end', 'type' => 'number', 'label' => 'End Time (seconds)', 'required' => false, 'default' => 0, 'min' => 0, 'max' => VideoRenderer::MAX_SECONDS, 'integer_only' => true],
                ['key' => 'aspect_ratio', 'type' => 'enum', 'label' => 'Aspect Ratio', 'required' => false, 'allowed_values' => VideoRenderer::ASPECTS, 'default' => '16:9'],
                ['key' => 'autoplay', 'type' => 'boolean', 'label' => 'Autoplay', 'required' => false, 'default' => false],
                ['key' => 'muted', 'type' => 'boolean', 'label' => 'Muted', 'required' => false, 'default' => false],
                ['key' => 'loop', 'type' => 'boolean', 'label' => 'Loop', 'required' => false, 'default' => false],
                ['key' => 'controls', 'type' => 'boolean', 'label' => 'Show Controls', 'required' => false, 'default' => true],
                ['key' => 'privacy', 'type' => 'boolean', 'label' => 'Privacy Mode', 'required' => false, 'default' => true],
                ['key' => 'lazy_load', 'type' => 'boolean', 'label' => 'Lazy Load', 'required' => false, 'default' => true],
                ['key' => 'overlay', 'type' => 'boolean', 'label' => 'Image Overlay', 'required' => false, 'default' => false],
                ['key' => 'overlay_image', 'type' => 'media_ref', 'label' => 'Overlay Image', 'required' => false, 'default' => null],
                ['key' => 'play_icon', 'type' => 'boolean', 'label' => 'Play Icon', 'required' => false, 'default' => true],
                ['key' => 'lightbox', 'type' => 'boolean', 'label' => 'Lightbox', 'required' => false, 'default' => false],
                ['key' => 'title', 'type' => 'string', 'label' => 'Title (read by screen readers)', 'required' => false, 'default' => '', 'max_length' => 200],
            ]),
            allowsChildren: false,
        ));

        // 24b. core.audio — a podcast or radio card. Source picks which address plays, as on the Video.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.audio',
            version: 1,
            label: 'Audio',
            title: 'Audio',
            description: 'A podcast or radio card: SoundCloud, Spotify or a self-hosted file',
            category: 'media',
            icon: 'video',
            schema: FieldSchema::define([
                ['key' => 'source', 'type' => 'enum', 'label' => 'Source', 'required' => false, 'allowed_values' => AudioRenderer::SOURCES, 'default' => 'soundcloud'],
                ['key' => 'url', 'type' => 'string', 'label' => 'Audio Link', 'required' => false, 'default' => '', 'max_length' => 1000],
                ['key' => 'hosted_url', 'type' => 'url', 'label' => 'Audio File', 'required' => false, 'max_length' => 1000],
                ['key' => 'title', 'type' => 'string', 'label' => 'Title', 'required' => false, 'default' => 'Episode title', 'max_length' => 200],
                ['key' => 'subtitle', 'type' => 'string', 'label' => 'Subtitle', 'required' => false, 'default' => 'Show or station', 'max_length' => 200],
            ]),
            allowsChildren: false,
        ));

        // 24c. core.marquee — a line of text that scrolls without end; Style sets its font, size and colour.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.marquee',
            version: 1,
            label: 'Text Marquee',
            title: 'Text Marquee',
            description: 'A line of text that scrolls across the page without end',
            category: 'content',
            icon: 'pilcrow',
            schema: FieldSchema::define([
                ['key' => 'text', 'type' => 'string', 'label' => 'Text', 'required' => false, 'default' => 'Your text here', 'max_length' => 200],
                ['key' => 'duration', 'type' => 'number', 'label' => 'Loop Time (seconds)', 'required' => false, 'default' => 30, 'min' => MarqueeRenderer::MIN_SECONDS, 'max' => MarqueeRenderer::MAX_SECONDS, 'integer_only' => true],
                ['key' => 'direction', 'type' => 'enum', 'label' => 'Direction', 'required' => false, 'allowed_values' => MarqueeRenderer::DIRECTIONS, 'default' => 'left'],
                ['key' => 'pause_on_hover', 'type' => 'boolean', 'label' => 'Pause on Hover', 'required' => false, 'default' => false],
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
                ['key' => 'look', 'type' => 'enum', 'label' => 'Rows look', 'required' => false, 'allowed_values' => ['boxed', 'lines'], 'default' => 'boxed'],
                ['key' => 'icon', 'type' => 'enum', 'label' => 'Open icon', 'required' => false, 'allowed_values' => ['chevron', 'plus'], 'default' => 'chevron'],
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
                        ['key' => 'per_view', 'type' => 'number', 'label' => 'Slides Per View', 'required' => false, 'per_device' => true, 'default' => 1, 'min' => 1, 'max' => 3, 'integer_only' => true],
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

        // 29. core.icon — one icon from the built-in set (names are stored in documents: add, never rename).
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.icon',
            version: 1,
            label: 'Icon',
            title: 'Icon',
            description: 'A single icon, sized and tinted',
            category: 'media',
            icon: 'star',
            schema: FieldSchema::define([
                ['key' => 'name', 'type' => 'string', 'format' => 'icon', 'max_length' => 40, 'pattern' => '/^[a-z0-9-]{0,40}$/', 'label' => 'Icon', 'required' => false, 'default' => 'star'],
                ['key' => 'view', 'type' => 'enum', 'label' => 'View', 'required' => false, 'allowed_values' => ['default', 'stacked', 'framed'], 'default' => 'default'],
                ['key' => 'shape', 'type' => 'enum', 'label' => 'Shape', 'required' => false, 'allowed_values' => ['circle', 'square'], 'default' => 'circle'],
                ['key' => 'url', 'type' => 'url', 'label' => 'Link', 'required' => false],
                ['key' => 'new_tab', 'type' => 'boolean', 'label' => 'Open in a new tab', 'required' => false, 'default' => false],
                ['key' => 'size', 'type' => 'enum', 'label' => 'Size', 'required' => false, 'allowed_values' => ['sm', 'md', 'lg', 'xl'], 'default' => 'md'],
                ['key' => 'align', 'type' => 'enum', 'label' => 'Alignment', 'required' => false, 'allowed_values' => ['left', 'center', 'right'], 'default' => 'left'],
                ['key' => 'color_token', 'type' => 'token_ref', 'label' => 'Colour', 'required' => false, 'default' => null],
                ['key' => 'label', 'type' => 'string', 'label' => 'Accessible label (leave empty if decorative)', 'required' => false, 'default' => '', 'max_length' => 120],
                // Set from the Style tab (the Icon section): the frame's second colour, a pixel size over the preset, the frame's padding and border, a rotation.
                ['key' => 'secondary_token', 'type' => 'token_ref', 'label' => 'Secondary Colour', 'required' => false, 'default' => null],
                ['key' => 'size_px', 'type' => 'number', 'label' => 'Size (px)', 'required' => false, 'default' => 0, 'min' => 0, 'max' => 300, 'integer_only' => true],
                ['key' => 'frame_padding', 'type' => 'number', 'label' => 'Padding', 'required' => false, 'default' => 0, 'min' => 0, 'max' => 100, 'integer_only' => true],
                ['key' => 'frame_border', 'type' => 'number', 'label' => 'Border Width', 'required' => false, 'default' => 0, 'min' => 0, 'max' => 20, 'integer_only' => true],
                ['key' => 'rotate', 'type' => 'number', 'label' => 'Rotate', 'required' => false, 'default' => 0, 'min' => -360, 'max' => 360, 'integer_only' => true],
            ]),
            allowsChildren: false,
        ));

        // 30. core.list — bulleted, numbered, ticked or plain.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.list',
            version: 1,
            label: 'List',
            title: 'List',
            description: 'Bulleted, numbered or ticked items',
            category: 'content',
            icon: 'list',
            schema: FieldSchema::define([
                ['key' => 'style', 'type' => 'enum', 'label' => 'Style', 'required' => false, 'allowed_values' => ['bullet', 'number', 'check', 'dash', 'none'], 'default' => 'bullet'],
                ['key' => 'layout', 'type' => 'enum', 'label' => 'List Layout', 'required' => false, 'allowed_values' => ['stacked', 'inline'], 'default' => 'stacked'],
                ['key' => 'columns', 'type' => 'number', 'label' => 'Columns', 'required' => false, 'per_device' => true, 'default' => 1, 'min' => 1, 'max' => 4, 'integer_only' => true],
                ['key' => 'divider', 'type' => 'boolean', 'label' => 'Lines Between Items', 'required' => false, 'default' => false],
                [
                    'key'         => 'items',
                    'type'        => 'repeater',
                    'label'       => 'Items',
                    'required'    => false,
                    'default'     => [['text' => 'First item'], ['text' => 'Second item'], ['text' => 'Third item']],
                    'max_items'   => 30,
                    'item_schema' => FieldSchema::define([
                        ['key' => 'text', 'type' => 'string', 'label' => 'Text', 'required' => true, 'max_length' => 300],
                        ['key' => 'url', 'type' => 'url', 'label' => 'Link', 'required' => false, 'default' => null],
                    ]),
                ],
            ]),
            allowsChildren: false,
        ));

        // 31. core.quote — a pull quote with attribution.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.quote',
            version: 1,
            label: 'Quote',
            title: 'Quote',
            description: 'A pull quote with its author',
            category: 'content',
            icon: 'quotes',
            schema: FieldSchema::define([
                ['key' => 'text', 'type' => 'text', 'label' => 'Quote', 'required' => true, 'default' => 'A short, memorable line from someone who matters.', 'max_length' => 1000],
                ['key' => 'author', 'type' => 'string', 'label' => 'Author', 'required' => false, 'default' => '', 'max_length' => 120],
                ['key' => 'role', 'type' => 'string', 'label' => 'Role or company', 'required' => false, 'default' => '', 'max_length' => 120],
                ['key' => 'align', 'type' => 'enum', 'label' => 'Alignment', 'required' => false, 'allowed_values' => ['left', 'center'], 'default' => 'left'],
            ]),
            allowsChildren: false,
            inlineText: [['prop' => 'text', 'selector' => '.sb-quote__text p']],
        ));

        // 32. core.link — a plain text link (core.button stays the call-to-action).
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.link',
            version: 1,
            label: 'Link',
            title: 'Link',
            description: 'A plain text link',
            category: 'content',
            icon: 'link',
            schema: FieldSchema::define([
                ['key' => 'link', 'type' => 'link', 'label' => 'Link', 'required' => true],
                ['key' => 'style', 'type' => 'enum', 'label' => 'Style', 'required' => false, 'allowed_values' => ['underline', 'plain', 'arrow'], 'default' => 'underline'],
                ['key' => 'align', 'type' => 'enum', 'label' => 'Alignment', 'required' => false, 'allowed_values' => ['left', 'center', 'right'], 'default' => 'left'],
            ]),
            allowsChildren: false,
            allowedBindingProviders: [],
            inlineText: [['prop' => 'link.label', 'selector' => '.sb-link']],
        ));

        // 33. core.card — a padded surface that holds other blocks.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.card',
            version: 1,
            label: 'Card',
            title: 'Card',
            description: 'A padded surface that holds other elements',
            category: 'layout',
            icon: 'box',
            schema: FieldSchema::define([
                ['key' => 'variant', 'type' => 'enum', 'label' => 'Style', 'required' => false, 'allowed_values' => ['outlined', 'filled', 'shadow'], 'default' => 'outlined'],
                ['key' => 'padding', 'type' => 'enum', 'label' => 'Padding', 'required' => false, 'allowed_values' => ['sm', 'md', 'lg'], 'default' => 'md'],
                ['key' => 'surface_token', 'type' => 'token_ref', 'label' => 'Surface colour', 'required' => false, 'default' => null],
            ]),
            allowsChildren: true,
        ));

        // 34. core.table — header line plus one line per row, cells separated by "|".
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.table',
            version: 1,
            label: 'Table',
            title: 'Table',
            description: 'Rows and columns of plain data',
            category: 'content',
            icon: 'table',
            schema: FieldSchema::define([
                ['key' => 'caption', 'type' => 'string', 'label' => 'Caption', 'required' => false, 'default' => '', 'max_length' => 160],
                ['key' => 'header', 'type' => 'string', 'label' => 'Header (separate columns with |)', 'required' => false, 'default' => 'Plan | Price | Seats', 'max_length' => 400],
                ['key' => 'striped', 'type' => 'boolean', 'label' => 'Striped rows', 'required' => false, 'default' => true],
                [
                    'key'         => 'rows',
                    'type'        => 'repeater',
                    'label'       => 'Rows',
                    'required'    => false,
                    'default'     => [['cells' => 'Starter | $9 | 1'], ['cells' => 'Team | $29 | 5'], ['cells' => 'Business | $99 | 25']],
                    'max_items'   => 50,
                    'item_schema' => FieldSchema::define([
                        ['key' => 'cells', 'type' => 'string', 'label' => 'Cells (separate with |)', 'required' => true, 'max_length' => 600],
                    ]),
                ],
            ]),
            allowsChildren: false,
        ));

        // 35. core.countdown — counts down to a moment; the runtime drives it (core.stats-style progressive enhancement).
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.countdown',
            version: 1,
            label: 'Countdown',
            title: 'Countdown',
            description: 'Days, hours, minutes and seconds to a date',
            category: 'content',
            icon: 'timer',
            schema: FieldSchema::define([
                ['key' => 'countdown_type', 'type' => 'enum', 'label' => 'Type', 'required' => false, 'allowed_values' => CountdownRenderer::TYPES, 'default' => 'due_date'],
                ['key' => 'target', 'type' => 'string', 'label' => 'Due Date', 'required' => true, 'default' => '2030-01-01T00:00:00Z', 'max_length' => 40, 'format' => 'datetime', 'pattern' => CountdownRenderer::TARGET_PATTERN],
                ['key' => 'evergreen_hours', 'type' => 'number', 'label' => 'Hours', 'required' => false, 'default' => 47, 'min' => 0, 'max' => CountdownRenderer::MAX_HOURS, 'integer_only' => true],
                ['key' => 'evergreen_minutes', 'type' => 'number', 'label' => 'Minutes', 'required' => false, 'default' => 59, 'min' => 0, 'max' => 59, 'integer_only' => true],
                ['key' => 'view', 'type' => 'enum', 'label' => 'View', 'required' => false, 'allowed_values' => CountdownRenderer::VIEWS, 'default' => 'block'],
                ['key' => 'show_days', 'type' => 'boolean', 'label' => 'Days', 'required' => false, 'default' => true],
                ['key' => 'show_hours', 'type' => 'boolean', 'label' => 'Hours', 'required' => false, 'default' => true],
                ['key' => 'show_minutes', 'type' => 'boolean', 'label' => 'Minutes', 'required' => false, 'default' => true],
                ['key' => 'show_seconds', 'type' => 'boolean', 'label' => 'Seconds', 'required' => false, 'default' => true],
                ['key' => 'show_labels', 'type' => 'boolean', 'label' => 'Show Labels', 'required' => false, 'default' => true],
                ['key' => 'custom_labels', 'type' => 'boolean', 'label' => 'Custom Labels', 'required' => false, 'default' => false],
                ['key' => 'label_days', 'type' => 'string', 'label' => 'Days Label', 'required' => false, 'default' => '', 'max_length' => 40],
                ['key' => 'label_hours', 'type' => 'string', 'label' => 'Hours Label', 'required' => false, 'default' => '', 'max_length' => 40],
                ['key' => 'label_minutes', 'type' => 'string', 'label' => 'Minutes Label', 'required' => false, 'default' => '', 'max_length' => 40],
                ['key' => 'label_seconds', 'type' => 'string', 'label' => 'Seconds Label', 'required' => false, 'default' => '', 'max_length' => 40],
                ['key' => 'label', 'type' => 'string', 'label' => 'Label', 'required' => false, 'default' => 'Starts in', 'max_length' => 120],
                ['key' => 'expire_action', 'type' => 'enum', 'label' => 'Actions After Expire', 'required' => false, 'allowed_values' => CountdownRenderer::EXPIRE_ACTIONS, 'default' => 'message'],
                ['key' => 'done_text', 'type' => 'string', 'label' => 'Text when it ends', 'required' => false, 'default' => '', 'max_length' => 160],
                ['key' => 'message_align', 'type' => 'enum', 'label' => 'Message Alignment', 'required' => false, 'allowed_values' => CountdownRenderer::MESSAGE_ALIGNS, 'default' => 'left'],
                ['key' => 'redirect_url', 'type' => 'url', 'label' => 'Redirect URL', 'required' => false, 'max_length' => 1000],
            ]),
            allowsChildren: false,
        ));

        // 36. core.divider — a horizontal rule that separates parts of a page.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.divider',
            version: 1,
            label: 'Divider',
            title: 'Divider',
            description: 'A line that separates parts of a page',
            category: 'layout',
            icon: 'divider',
            schema: FieldSchema::define([
                ['key' => 'style', 'type' => 'enum', 'label' => 'Line style', 'required' => false, 'allowed_values' => ['solid', 'dashed', 'dotted'], 'default' => 'solid'],
                ['key' => 'weight', 'type' => 'enum', 'label' => 'Thickness', 'required' => false, 'allowed_values' => ['thin', 'medium', 'thick'], 'default' => 'thin'],
                ['key' => 'width', 'type' => 'enum', 'label' => 'Width', 'required' => false, 'allowed_values' => ['full', 'wide', 'narrow', 'short'], 'default' => 'full'],
                ['key' => 'align', 'type' => 'enum', 'label' => 'Alignment', 'required' => false, 'allowed_values' => ['left', 'center', 'right'], 'default' => 'center'],
            ]),
            allowsChildren: false,
        ));

        // 37. core.spacer — empty vertical space of a set size (smaller on a phone).
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.spacer',
            version: 1,
            label: 'Spacer',
            title: 'Spacer',
            description: 'Empty space between two parts of a page',
            category: 'layout',
            icon: 'spacer',
            schema: FieldSchema::define([
                ['key' => 'size', 'type' => 'enum', 'label' => 'Height', 'required' => false, 'allowed_values' => ['xs', 'sm', 'md', 'lg', 'xl', '2xl'], 'default' => 'md'],
            ]),
            allowsChildren: false,
        ));

        // 38. core.nav_menu — a row or column of links: one of the site's named menus (Menus screen), or links listed by hand, with a built-in menu button (NavMenuRenderer) that folds it behind a hamburger.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.nav_menu',
            version: 1,
            label: 'Nav menu',
            title: 'Nav menu',
            description: 'A row or column of links',
            category: 'layout',
            icon: 'menu',
            schema: FieldSchema::define([
                ['key' => 'menu', 'type' => 'string', 'label' => 'Menu', 'required' => false, 'default' => '', 'max_length' => 63, 'pattern' => '/^([a-z0-9][a-z0-9-]{0,62})?$/'],
                ['key' => 'aria_label', 'type' => 'string', 'label' => 'Menu name (for screen readers)', 'required' => false, 'default' => 'Menu', 'max_length' => 80],
                ['key' => 'layout', 'type' => 'enum', 'label' => 'Layout', 'required' => false, 'allowed_values' => ['horizontal', 'vertical'], 'default' => 'horizontal'],
                ['key' => 'align', 'type' => 'enum', 'label' => 'Alignment', 'required' => false, 'allowed_values' => ['left', 'center', 'right'], 'default' => 'left'],
                ['key' => 'style', 'type' => 'enum', 'label' => 'Link style', 'required' => false, 'allowed_values' => ['plain', 'underline', 'pill'], 'default' => 'plain'],
                ['key' => 'pointer', 'type' => 'enum', 'label' => 'Hover pointer', 'required' => false, 'allowed_values' => ['none', 'underline', 'overline', 'double_line', 'framed', 'background', 'text'], 'default' => 'none'],
                ['key' => 'pointer_animation', 'type' => 'enum', 'label' => 'Pointer animation', 'required' => false, 'allowed_values' => ['none', 'fade', 'slide', 'grow'], 'default' => 'fade'],
                ['key' => 'pointer_color', 'type' => 'token_ref', 'label' => 'Pointer colour', 'required' => false, 'default' => null],
                ['key' => 'divider', 'type' => 'boolean', 'label' => 'Divider between items', 'required' => false, 'default' => false],
                // A big menu, such as a full-screen one: numbers, a line under each link, columns and a picture of the hovered link.
                ['key' => 'numbered', 'type' => 'boolean', 'label' => 'Number the links', 'required' => false, 'default' => false],
                ['key' => 'descriptions', 'type' => 'boolean', 'label' => 'Show each link\'s description', 'required' => false, 'default' => false],
                ['key' => 'arrow', 'type' => 'boolean', 'label' => 'Arrow on the hovered link', 'required' => false, 'default' => false],
                ['key' => 'columns', 'type' => 'number', 'label' => 'Columns (vertical menu)', 'required' => false, 'default' => 1, 'min' => 1, 'max' => 3, 'integer_only' => true],
                ['key' => 'preview', 'type' => 'boolean', 'label' => 'Show the hovered link\'s image', 'required' => false, 'default' => false],
                ['key' => 'reveal', 'type' => 'boolean', 'label' => 'Links rise in when the menu opens', 'required' => false, 'default' => false],
                ...self::menuToggleFields('none'),
                [
                    'key'         => 'items',
                    'type'        => 'repeater',
                    'label'       => 'Custom links (used when no menu is chosen)',
                    'required'    => false,
                    'default'     => [['label' => 'Home', 'url' => '/'], ['label' => 'Services', 'url' => '/services/'], ['label' => 'Contact', 'url' => '/contact/']],
                    'max_items'   => 12,
                    'item_schema' => FieldSchema::define([
                        ['key' => 'label', 'type' => 'string', 'label' => 'Label', 'required' => true, 'max_length' => 80],
                        ['key' => 'url', 'type' => 'url', 'label' => 'URL', 'required' => true, 'max_length' => 1000],
                        ['key' => 'description', 'type' => 'string', 'label' => 'Description', 'required' => false, 'default' => '', 'max_length' => 160],
                        ['key' => 'image', 'type' => 'media_ref', 'label' => 'Preview image', 'required' => false, 'default' => null],
                        [
                            'key'         => 'children',
                            'type'        => 'repeater',
                            'label'       => 'Submenu links',
                            'required'    => false,
                            'default'     => [],
                            'max_items'   => 12,
                            'item_schema' => FieldSchema::define([
                                ['key' => 'label', 'type' => 'string', 'label' => 'Label', 'required' => true, 'max_length' => 80],
                                ['key' => 'url', 'type' => 'url', 'label' => 'URL', 'required' => true, 'max_length' => 1000],
                            ]),
                        ],
                    ]),
                ],
            ]),
            allowsChildren: false,
            // Each link's text edits the matching item (top-level links only: a submenu link is not an item of the list);
            // a menu chosen on the Menus screen shows its own links, so not then.
            inlineText: [['prop' => 'items.*.label', 'selector' => '.sb-nav__list > .sb-nav__item > .sb-nav__link', 'unless' => 'menu']],
        ));

        // 38b. layout.mega_menu — a row of links whose panels hold any blocks; each child is a layout.mega_item (MegaMenuRenderer).
        $registry->register(new DeclarativeBlockDefinition(
            type: 'layout.mega_menu',
            version: 1,
            label: 'Mega menu',
            title: 'Mega menu',
            description: 'Menu links that open wide panels of any content',
            category: 'layout',
            icon: 'menu',
            schema: FieldSchema::define([
                ['key' => 'aria_label', 'type' => 'string', 'label' => 'Menu name (for screen readers)', 'required' => false, 'default' => 'Menu', 'max_length' => 80],
                ['key' => 'align', 'type' => 'enum', 'label' => 'Alignment', 'required' => false, 'allowed_values' => NavChrome::ALIGNS, 'default' => 'left'],
                ['key' => 'panel_width', 'type' => 'enum', 'label' => 'Panel content width', 'required' => false, 'allowed_values' => MegaMenuRenderer::WIDTHS, 'default' => 'content'],
                ['key' => 'panel_look', 'type' => 'enum', 'label' => 'Panel look', 'required' => false, 'allowed_values' => MegaMenuRenderer::LOOKS, 'default' => 'solid'],
                ['key' => 'pointer', 'type' => 'enum', 'label' => 'Hover pointer', 'required' => false, 'allowed_values' => NavChrome::POINTERS, 'default' => 'none'],
                ['key' => 'pointer_animation', 'type' => 'enum', 'label' => 'Pointer animation', 'required' => false, 'allowed_values' => NavChrome::ANIMATIONS, 'default' => 'fade'],
                ['key' => 'pointer_color', 'type' => 'token_ref', 'label' => 'Pointer colour', 'required' => false, 'default' => null],
                ...self::menuToggleFields('tablet'),
            ]),
            allowsChildren: true,
            allowedChildTypes: ['layout.mega_item'],
        ));

        // 38c. layout.mega_item — one link of a Mega menu; its child blocks are the columns of the panel it opens.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'layout.mega_item',
            version: 1,
            label: 'Mega menu item',
            title: 'Mega menu item',
            description: 'One link of a Mega menu and its panel',
            category: 'layout',
            icon: 'menu',
            schema: FieldSchema::define([
                ['key' => 'label', 'type' => 'string', 'label' => 'Label', 'required' => true, 'default' => 'Menu item', 'max_length' => 80],
                ['key' => 'url', 'type' => 'url', 'label' => 'URL', 'required' => false, 'default' => null],
                ['key' => 'columns', 'type' => 'number', 'label' => 'Panel columns', 'required' => false, 'default' => 3, 'min' => MegaItemRenderer::MIN_COLUMNS, 'max' => MegaItemRenderer::MAX_COLUMNS, 'integer_only' => true],
            ]),
            allowsChildren: true,
        ));

        // 38d. layout.tabs — tabs whose panels hold any blocks; each child is a layout.tab (TabSetRenderer).
        $registry->register(new DeclarativeBlockDefinition(
            type: 'layout.tabs',
            version: 1,
            label: 'Tabs with blocks',
            title: 'Tabs with blocks',
            description: 'Tabs whose panels hold any blocks, and can turn by themselves',
            category: 'layout',
            icon: 'grid-panes',
            schema: FieldSchema::define([
                ['key' => 'position', 'type' => 'enum', 'label' => 'Tabs position', 'required' => false, 'allowed_values' => TabSetRenderer::POSITIONS, 'default' => 'top'],
                ['key' => 'look', 'type' => 'enum', 'label' => 'Tabs look', 'required' => false, 'allowed_values' => TabSetRenderer::LOOKS, 'default' => 'tabs'],
                ['key' => 'autoplay_ms', 'type' => 'number', 'label' => 'Turn by themselves every (ms, 0 = never)', 'required' => false, 'default' => 0, 'min' => 0, 'max' => TabSetRenderer::MAX_AUTOPLAY, 'integer_only' => true],
                ['key' => 'pause_on_hover', 'type' => 'boolean', 'label' => 'Pause while the pointer is over them', 'required' => false, 'default' => true],
                ['key' => 'aria_label', 'type' => 'string', 'label' => 'Tabs name (for screen readers)', 'required' => false, 'default' => '', 'max_length' => 80],
            ]),
            allowsChildren: true,
            allowedChildTypes: ['layout.tab'],
        ));

        // 38e. layout.tab — one tab of a Tabs with blocks: its title, and its child blocks are the panel.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'layout.tab',
            version: 1,
            label: 'Tab',
            title: 'Tab',
            description: 'One tab of a Tabs with blocks and its panel',
            category: 'layout',
            icon: 'grid-panes',
            schema: FieldSchema::define([
                ['key' => 'title', 'type' => 'string', 'label' => 'Tab title', 'required' => true, 'default' => 'Tab', 'max_length' => 80],
                ['key' => 'subtitle', 'type' => 'string', 'label' => 'Line under the title', 'required' => false, 'default' => '', 'max_length' => 120],
            ]),
            allowsChildren: true,
        ));

        // 38f. layout.accordion — rows that open and close, whose answers hold any blocks; each child is a layout.accordion_item.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'layout.accordion',
            version: 1,
            label: 'Accordion with blocks',
            title: 'Accordion with blocks',
            description: 'Rows that open and close, with any blocks inside',
            category: 'layout',
            icon: 'list-check',
            schema: FieldSchema::define([
                ['key' => 'allow_multiple', 'type' => 'boolean', 'label' => 'Allow several rows open at once', 'required' => false, 'default' => false],
                ['key' => 'look', 'type' => 'enum', 'label' => 'Rows look', 'required' => false, 'allowed_values' => ['boxed', 'lines'], 'default' => 'boxed'],
                ['key' => 'icon', 'type' => 'enum', 'label' => 'Open icon', 'required' => false, 'allowed_values' => ['chevron', 'plus'], 'default' => 'chevron'],
            ]),
            allowsChildren: true,
            allowedChildTypes: ['layout.accordion_item'],
        ));

        // 38g. layout.accordion_item — one row of an Accordion with blocks: its question, and its child blocks are the answer.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'layout.accordion_item',
            version: 1,
            label: 'Accordion row',
            title: 'Accordion row',
            description: 'One row of an Accordion with blocks and its answer',
            category: 'layout',
            icon: 'list-check',
            schema: FieldSchema::define([
                ['key' => 'title', 'type' => 'string', 'label' => 'Row title', 'required' => true, 'default' => 'Question', 'max_length' => 200],
                ['key' => 'open', 'type' => 'boolean', 'label' => 'Open when the page loads', 'required' => false, 'default' => false],
            ]),
            allowsChildren: true,
        ));

        // 38h. layout.timeline — steps on a line that fills as the page scrolls; each child block is a step.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'layout.timeline',
            version: 1,
            label: 'Timeline',
            title: 'Timeline',
            description: 'Steps on a line that fills as the page scrolls',
            category: 'layout',
            icon: 'list-check',
            schema: FieldSchema::define([
                ['key' => 'line_color', 'type' => 'string', 'label' => 'Line colour', 'required' => false, 'default' => '', 'max_length' => 40, 'format' => 'color', 'pattern' => Html::HIGHLIGHT_COLOR_PATTERN],
                ['key' => 'fill_color', 'type' => 'string', 'label' => 'Filled line colour', 'required' => false, 'default' => '', 'max_length' => 40, 'format' => 'color', 'pattern' => Html::HIGHLIGHT_COLOR_PATTERN],
                ['key' => 'numbered', 'type' => 'boolean', 'label' => 'Number the steps', 'required' => false, 'default' => true],
            ]),
            allowsChildren: true,
        ));

        // 38i. core.progress — labelled bars that grow to their value when they come into view.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.progress',
            version: 1,
            label: 'Progress bars',
            title: 'Progress bars',
            description: 'Labelled bars that grow to their value',
            category: 'content',
            icon: 'grid-dots',
            schema: FieldSchema::define([
                [
                    'key'         => 'items',
                    'type'        => 'repeater',
                    'label'       => 'Bars',
                    'required'    => false,
                    'default'     => [
                        ['label' => 'Design', 'value' => 90],
                        ['label' => 'Development', 'value' => 80],
                        ['label' => 'Search engines', 'value' => 70],
                    ],
                    'max_items'   => 12,
                    'item_schema' => FieldSchema::define([
                        ['key' => 'label', 'type' => 'string', 'label' => 'Label', 'required' => true, 'max_length' => 120],
                        ['key' => 'value', 'type' => 'number', 'label' => 'Value (%)', 'required' => true, 'min' => 0, 'max' => 100],
                        ['key' => 'note', 'type' => 'string', 'label' => 'Shown value (instead of the %)', 'required' => false, 'default' => '', 'max_length' => 20],
                    ]),
                ],
                ['key' => 'show_value', 'type' => 'boolean', 'label' => 'Show the value', 'required' => false, 'default' => true],
                ['key' => 'layout', 'type' => 'enum', 'label' => 'Layout', 'required' => false, 'allowed_values' => ['stacked', 'inline'], 'default' => 'stacked'],
                ['key' => 'bar_color', 'type' => 'string', 'label' => 'Bar colour', 'required' => false, 'default' => '', 'max_length' => 40, 'format' => 'color', 'pattern' => Html::HIGHLIGHT_COLOR_PATTERN],
                ['key' => 'track_color', 'type' => 'string', 'label' => 'Track colour', 'required' => false, 'default' => '', 'max_length' => 40, 'format' => 'color', 'pattern' => Html::HIGHLIGHT_COLOR_PATTERN],
                ['key' => 'height', 'type' => 'number', 'label' => 'Bar height (px)', 'required' => false, 'default' => 8, 'min' => 2, 'max' => 40, 'integer_only' => true],
            ]),
            allowsChildren: false,
        ));

        // 38j. core.circle_text — words set round a circle that turns slowly, with an optional icon in the middle.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.circle_text',
            version: 1,
            label: 'Circular text',
            title: 'Circular text',
            description: 'Words round a turning circle, a badge',
            category: 'content',
            icon: 'star',
            schema: FieldSchema::define([
                ['key' => 'text', 'type' => 'string', 'label' => 'Text round the circle', 'required' => true, 'default' => 'Free website audit • Free website audit • ', 'max_length' => 120],
                ['key' => 'icon', 'type' => 'string', 'format' => 'icon', 'max_length' => 40, 'pattern' => '/^[a-z0-9-]{0,40}$/', 'label' => 'Icon in the middle', 'required' => false, 'default' => 'none'],
                ['key' => 'size', 'type' => 'number', 'label' => 'Size (px)', 'required' => false, 'default' => 120, 'min' => 60, 'max' => 400, 'integer_only' => true],
                ['key' => 'font_size', 'type' => 'number', 'label' => 'Letter size', 'required' => false, 'default' => 11, 'min' => 6, 'max' => 24, 'integer_only' => true],
                ['key' => 'spin_seconds', 'type' => 'number', 'label' => 'Seconds per turn (0 = still)', 'required' => false, 'default' => 14, 'min' => 0, 'max' => 120, 'integer_only' => true],
                ['key' => 'direction', 'type' => 'enum', 'label' => 'Turns', 'required' => false, 'allowed_values' => ['clockwise', 'anticlockwise'], 'default' => 'clockwise'],
            ]),
            allowsChildren: false,
        ));

        // 38l. core.message_widget — a floating "message me" button and panel: online status, topic chips, a Forms form.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.message_widget',
            version: 1,
            label: 'Message widget',
            title: 'Message widget',
            description: 'A floating message button with online status that sends through Forms',
            category: 'forms',
            icon: 'form',
            schema: FieldSchema::define([
                ['key' => 'launcher_label', 'type' => 'string', 'label' => 'Button text', 'required' => false, 'default' => 'Message me', 'max_length' => 40],
                ['key' => 'launcher_icon', 'type' => 'string', 'format' => 'icon', 'max_length' => 40, 'pattern' => '/^[a-z0-9-]{0,40}$/', 'label' => 'Button icon', 'required' => false, 'default' => 'message-circle'],
                ['key' => 'position', 'type' => 'enum', 'label' => 'Corner', 'required' => false, 'allowed_values' => MessageWidgetRenderer::POSITIONS, 'default' => 'right'],
                ['key' => 'theme', 'type' => 'enum', 'label' => 'Theme', 'required' => false, 'allowed_values' => MessageWidgetRenderer::THEMES, 'default' => 'auto'],
                ['key' => 'launcher_photo', 'type' => 'boolean', 'label' => 'Photo on the button', 'required' => false, 'default' => false],
                ['key' => 'launcher_lines', 'type' => 'text', 'label' => 'Lines under the button text (one per line; {status} and {time} fill in)', 'required' => false, 'default' => '', 'max_length' => 400],
                ['key' => 'compact_on_scroll', 'type' => 'boolean', 'label' => 'Shrink to the photo or icon while scrolling down', 'required' => false, 'default' => false],
                ['key' => 'title', 'type' => 'string', 'label' => 'Panel title', 'required' => false, 'default' => 'Let’s talk', 'max_length' => 80],
                ['key' => 'name', 'type' => 'string', 'label' => 'Who answers', 'required' => false, 'default' => '', 'max_length' => 60],
                ['key' => 'avatar', 'type' => 'media_ref', 'label' => 'Photo', 'required' => false, 'default' => null],
                ['key' => 'intro', 'type' => 'text', 'label' => 'Intro text', 'required' => false, 'default' => 'Tell me about your project. I usually reply the same day.', 'max_length' => 300],
                ['key' => 'timezone', 'type' => 'string', 'label' => 'Time zone (e.g. Europe/Brussels)', 'required' => false, 'default' => '', 'max_length' => 60, 'pattern' => MessageWidgetRenderer::ZONE_PATTERN],
                ['key' => 'hours_start', 'type' => 'string', 'label' => 'Online from (HH:MM)', 'required' => false, 'default' => '09:00', 'max_length' => 5, 'pattern' => '/^$|^([01]\d|2[0-3]):[0-5]\d$/'],
                ['key' => 'hours_end', 'type' => 'string', 'label' => 'Online until (HH:MM)', 'required' => false, 'default' => '18:00', 'max_length' => 5, 'pattern' => '/^$|^([01]\d|2[0-3]):[0-5]\d$/'],
                ['key' => 'days', 'type' => 'enum', 'label' => 'Online on', 'required' => false, 'allowed_values' => MessageWidgetRenderer::DAYS, 'default' => 'weekdays'],
                ['key' => 'online_text', 'type' => 'string', 'label' => 'Online text', 'required' => false, 'default' => 'Online now', 'max_length' => 80],
                ['key' => 'away_text', 'type' => 'string', 'label' => 'Away text', 'required' => false, 'default' => 'Away · I reply within a day', 'max_length' => 80],
                ['key' => 'topics', 'type' => 'text', 'label' => 'Topic chips (one per line)', 'required' => false, 'default' => "New website\nRedesign\nSEO\nSomething else", 'max_length' => 600],
                ['key' => 'topics_label', 'type' => 'string', 'label' => 'Topics heading', 'required' => false, 'default' => 'What is it about?', 'max_length' => 80],
                ['key' => 'ask_name', 'type' => 'boolean', 'label' => 'Ask for a name', 'required' => false, 'default' => true],
                ['key' => 'name_label', 'type' => 'string', 'label' => 'Name field label', 'required' => false, 'default' => 'Your name', 'max_length' => 60],
                ['key' => 'email_label', 'type' => 'string', 'label' => 'Email field label', 'required' => false, 'default' => 'Your email', 'max_length' => 60],
                ['key' => 'message_label', 'type' => 'string', 'label' => 'Message field label', 'required' => false, 'default' => 'Message', 'max_length' => 60],
                ['key' => 'message_placeholder', 'type' => 'string', 'label' => 'Message placeholder', 'required' => false, 'default' => '', 'max_length' => 120],
                ['key' => 'submit_text', 'type' => 'string', 'label' => 'Send button text', 'required' => false, 'default' => 'Send', 'max_length' => 40],
                ['key' => 'success_message', 'type' => 'text', 'label' => 'Success Message', 'required' => false, 'default' => 'Thanks! I’ll get back to you soon.', 'max_length' => 500],
                ['key' => 'notify_email', 'type' => 'string', 'label' => 'Email Submissions To', 'required' => false, 'default' => '', 'max_length' => 200],
                ['key' => 'open_after_seconds', 'type' => 'number', 'label' => 'Open by itself after (seconds, 0 = never)', 'required' => false, 'default' => 0, 'min' => 0, 'max' => MessageWidgetRenderer::MAX_DELAY, 'integer_only' => true],
                ['key' => 'open_after_scroll', 'type' => 'number', 'label' => 'Open by itself after scrolling (% of the page, 0 = never)', 'required' => false, 'default' => 0, 'min' => 0, 'max' => 100, 'integer_only' => true],
                ['key' => 'remember_closed', 'type' => 'boolean', 'label' => 'Once closed, stop opening by itself', 'required' => false, 'default' => true],
            ]),
            allowsChildren: false,
        ));

        // 38m. core.local_time — the owner's local time, ticking ("Brussels 14:32").
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.local_time',
            version: 1,
            label: 'Local time',
            title: 'Local time',
            description: 'Your local time, ticking, for a footer',
            category: 'content',
            icon: 'timer',
            schema: FieldSchema::define([
                ['key' => 'label', 'type' => 'string', 'label' => 'Text before', 'required' => false, 'default' => 'Local time', 'max_length' => 60],
                ['key' => 'city', 'type' => 'string', 'label' => 'City', 'required' => false, 'default' => '', 'max_length' => 60],
                ['key' => 'timezone', 'type' => 'string', 'label' => 'Time zone (e.g. Europe/Brussels)', 'required' => false, 'default' => '', 'max_length' => 60, 'pattern' => MessageWidgetRenderer::ZONE_PATTERN],
                ['key' => 'format', 'type' => 'enum', 'label' => 'Clock', 'required' => false, 'allowed_values' => LocalTimeRenderer::FORMATS, 'default' => '24h'],
                ['key' => 'seconds', 'type' => 'boolean', 'label' => 'Show seconds', 'required' => false, 'default' => false],
                ['key' => 'show_offset', 'type' => 'boolean', 'label' => 'Show the GMT offset', 'required' => false, 'default' => false],
            ]),
            allowsChildren: false,
        ));

        // 38n. core.testimonial_grid — testimonials in a grid that pages like a carousel: a count, numbered pages and arrows,
        // cards with a quote mark, stars, clamped text with "read full review" in a dialog, and an initials avatar. The
        // testimonials come from a content type (one item each, its fields mapped here) or are typed on the block.
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.testimonial_grid',
            version: 1,
            label: 'Testimonial grid',
            title: 'Testimonial grid',
            description: 'Reviews in a grid that pages like a carousel, from a content type or typed in',
            category: 'content',
            icon: 'quotes',
            schema: FieldSchema::define([
                ['key' => 'source', 'type' => 'enum', 'label' => 'Testimonials from', 'required' => false, 'allowed_values' => ['items', 'content_type'], 'default' => 'items'],
                ['key' => 'content_type', 'type' => 'string', 'label' => 'Content type', 'required' => false, 'default' => '', 'max_length' => 40, 'pattern' => '/^([a-z][a-z0-9_-]{0,39})?$/'],
                ['key' => 'text_field', 'type' => 'string', 'label' => 'Review text field', 'required' => false, 'default' => 'text', 'max_length' => 64, 'pattern' => TestimonialGridRenderer::FIELD_PATTERN],
                ['key' => 'rating_field', 'type' => 'string', 'label' => 'Rating field (0 to 5)', 'required' => false, 'default' => 'rating', 'max_length' => 64, 'pattern' => TestimonialGridRenderer::FIELD_PATTERN],
                ['key' => 'meta_fields', 'type' => 'string', 'label' => 'Detail fields under the name (comma-separated)', 'required' => false, 'default' => 'country', 'max_length' => 200, 'pattern' => '/^[a-z0-9_, ]{0,200}$/'],
                ['key' => 'date_field', 'type' => 'string', 'label' => 'Date field', 'required' => false, 'default' => '', 'max_length' => 64, 'pattern' => TestimonialGridRenderer::FIELD_PATTERN],
                ['key' => 'photo_field', 'type' => 'string', 'label' => 'Photo field (initials without one)', 'required' => false, 'default' => '', 'max_length' => 64, 'pattern' => TestimonialGridRenderer::FIELD_PATTERN],
                ['key' => 'order', 'type' => 'enum', 'label' => 'Order', 'required' => false, 'allowed_values' => TestimonialGridRenderer::ORDERS, 'default' => 'newest'],
                ['key' => 'min_length', 'type' => 'number', 'label' => 'Only reviews at least this long (characters)', 'required' => false, 'default' => 0, 'min' => 0, 'max' => 2000, 'integer_only' => true],
                ['key' => 'max_length', 'type' => 'number', 'label' => 'Only reviews at most this long (characters, 0 = any)', 'required' => false, 'default' => 0, 'min' => 0, 'max' => 2000, 'integer_only' => true],
                ['key' => 'limit', 'type' => 'number', 'label' => 'Most reviews shown', 'required' => false, 'default' => 60, 'min' => 1, 'max' => TestimonialGridRenderer::MAX_ITEMS, 'integer_only' => true],
                [
                    'key'         => 'items',
                    'type'        => 'repeater',
                    'label'       => 'Testimonials (when typed in)',
                    'required'    => false,
                    'default'     => [
                        ['name' => 'Jordan', 'text' => 'Clear, quick and easy to work with. The new site brings us calls every week.', 'rating' => 5, 'meta' => 'United States', 'date' => '2 months ago'],
                        ['name' => 'Camille', 'text' => 'He understood what we needed at once and delivered ahead of time.', 'rating' => 5, 'meta' => 'Belgium', 'date' => '5 months ago'],
                        ['name' => 'Sam', 'text' => 'Great communication from start to finish. I would hire him again.', 'rating' => 5, 'meta' => 'United Kingdom', 'date' => '1 year ago'],
                    ],
                    'max_items'   => 60,
                    'item_schema' => FieldSchema::define([
                        ['key' => 'name', 'type' => 'string', 'label' => 'Name', 'required' => true, 'max_length' => 80],
                        ['key' => 'text', 'type' => 'text', 'label' => 'Review', 'required' => true, 'max_length' => 3000],
                        ['key' => 'rating', 'type' => 'number', 'label' => 'Stars', 'required' => false, 'default' => 5, 'min' => 0, 'max' => 5],
                        ['key' => 'meta', 'type' => 'string', 'label' => 'Details', 'required' => false, 'default' => '', 'max_length' => 120],
                        ['key' => 'date', 'type' => 'string', 'label' => 'Date', 'required' => false, 'default' => '', 'max_length' => 40],
                        ['key' => 'photo', 'type' => 'media_ref', 'label' => 'Photo', 'required' => false, 'default' => null],
                    ]),
                ],
                ['key' => 'layout', 'type' => 'enum', 'label' => 'Layout', 'required' => false, 'allowed_values' => TestimonialGridRenderer::LAYOUTS, 'default' => 'grid'],
                ['key' => 'ticker_rows', 'type' => 'number', 'label' => 'Ticker rows', 'required' => false, 'default' => 2, 'min' => 1, 'max' => 4, 'integer_only' => true],
                ['key' => 'ticker_speed', 'type' => 'number', 'label' => 'Ticker speed', 'required' => false, 'default' => 3, 'min' => 1, 'max' => 10, 'integer_only' => true],
                ['key' => 'per_page', 'type' => 'number', 'label' => 'Per page', 'required' => false, 'default' => 6, 'min' => 1, 'max' => 24, 'integer_only' => true],
                ['key' => 'per_page_tablet', 'type' => 'number', 'label' => 'Per page on a tablet', 'required' => false, 'default' => 4, 'min' => 1, 'max' => 24, 'integer_only' => true],
                ['key' => 'per_page_mobile', 'type' => 'number', 'label' => 'Per page on a phone', 'required' => false, 'default' => 3, 'min' => 1, 'max' => 24, 'integer_only' => true],
                ['key' => 'columns', 'type' => 'number', 'label' => 'Columns', 'required' => false, 'default' => 3, 'min' => 1, 'max' => 4, 'integer_only' => true],
                ['key' => 'columns_tablet', 'type' => 'number', 'label' => 'Columns on a tablet', 'required' => false, 'default' => 2, 'min' => 1, 'max' => 4, 'integer_only' => true],
                ['key' => 'columns_mobile', 'type' => 'number', 'label' => 'Columns on a phone', 'required' => false, 'default' => 1, 'min' => 1, 'max' => 2, 'integer_only' => true],
                ['key' => 'clamp_lines', 'type' => 'number', 'label' => 'Lines before "read full review" (0 = all)', 'required' => false, 'default' => 6, 'min' => 0, 'max' => 20, 'integer_only' => true],
                ['key' => 'count_label', 'type' => 'string', 'label' => 'Count text ({from}, {to} and {total} fill in)', 'required' => false, 'default' => '{from}–{to} of {total} reviews', 'max_length' => 80],
                ['key' => 'read_more_label', 'type' => 'string', 'label' => 'Read more text', 'required' => false, 'default' => 'Read full review', 'max_length' => 40],
                ['key' => 'source_note', 'type' => 'string', 'label' => 'Note after the name in the full review (e.g. on Fiverr)', 'required' => false, 'default' => '', 'max_length' => 60],
                ['key' => 'show_quote', 'type' => 'boolean', 'label' => 'Quote mark', 'required' => false, 'default' => true],
                ['key' => 'show_stars', 'type' => 'boolean', 'label' => 'Stars', 'required' => false, 'default' => true],
                ['key' => 'show_avatar', 'type' => 'boolean', 'label' => 'Avatar', 'required' => false, 'default' => true],
                ['key' => 'show_name', 'type' => 'boolean', 'label' => 'Name', 'required' => false, 'default' => true],
                ['key' => 'show_meta', 'type' => 'boolean', 'label' => 'Details under the name', 'required' => false, 'default' => true],
                ['key' => 'show_date', 'type' => 'boolean', 'label' => 'Date', 'required' => false, 'default' => true],
                ['key' => 'show_progress', 'type' => 'boolean', 'label' => 'Progress line under the grid', 'required' => false, 'default' => true],
                ['key' => 'highlight_first', 'type' => 'boolean', 'label' => 'First card of each page stands out', 'required' => false, 'default' => true],
            ]),
            allowsChildren: false,
        ));

        // 39. core.map — an OpenStreetMap embed centred on a point (no key, no tracking cookies).
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.map',
            version: 1,
            label: 'Map',
            title: 'Map',
            description: 'An OpenStreetMap centred on a place',
            category: 'content',
            icon: 'pin',
            schema: FieldSchema::define([
                ['key' => 'latitude', 'type' => 'number', 'label' => 'Latitude', 'required' => true, 'default' => 50.8503, 'min' => -85, 'max' => 85],
                ['key' => 'longitude', 'type' => 'number', 'label' => 'Longitude', 'required' => true, 'default' => 4.3517, 'min' => -180, 'max' => 180],
                ['key' => 'zoom', 'type' => 'number', 'label' => 'Zoom (1 world, 19 street)', 'required' => false, 'default' => 13, 'min' => 1, 'max' => 19, 'integer_only' => true],
                ['key' => 'height', 'type' => 'enum', 'label' => 'Height', 'required' => false, 'allowed_values' => ['sm', 'md', 'lg'], 'default' => 'md'],
                ['key' => 'title', 'type' => 'string', 'label' => 'Title (for screen readers)', 'required' => false, 'default' => 'Map', 'max_length' => 120],
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
