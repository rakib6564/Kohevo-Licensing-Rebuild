<?php
/**
 * Kohevo Studio (studio-builder) — Canonical Document Schema ("2.0").
 *
 * Defines the structural constants, limits, allowed keys, node ID patterns,
 * symbolic token reference patterns, and default envelope structures for
 * the Kohevo-owned Canonical Studio Document.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

use Slate\Module\StudioBuilder\Schema\FieldSchema;

final class CanonicalDocumentSchema
{
    public const SCHEMA_VERSION = '2.0';
    /** The format before Containers replaced sections; DocumentUpgrader::structure() turns it into SCHEMA_VERSION on read. */
    public const LEGACY_SCHEMA_VERSION = '1.0';

    /** `core.container`'s version: BlockRegistry declares it, and a document built here carries it. */
    public const CONTAINER_VERSION = 2;

    public const MAX_DOCUMENT_BYTES      = 1048576; // 1 MiB
    public const MAX_JSON_DEPTH          = 32;
    public const MAX_CONTAINERS          = 50;
    public const MAX_BLOCKS_PER_DOCUMENT = 300;
    public const MAX_NESTING_DEPTH       = 7;
    public const MAX_REPEATER_ITEMS      = 50;
    public const MAX_STRING_LENGTH       = 2000;
    public const MAX_TEXT_LENGTH         = 20000;
    public const MAX_RICH_TEXT_LENGTH    = 50000;

    public const ALLOWED_DOCUMENT_TYPES = [
        'page',
        'landing',
        'system',
        'header_partial',
        'footer_partial',
        'container_preset',
    ];

    /**
     * Phase 6 — live global references. A top-level Container whose `global_ref`
     * is set references the canonical content of a Global Component: a Studio page
     * of type `container_preset` (COMPONENT_DOCUMENT_TYPE), identified by its
     * immutable page `uuid`. Only these document types may hold such a
     * reference; a component, header or footer partial may not (references
     * are one level deep by construction — no cycles, no nested resolution).
     */
    public const COMPONENT_DOCUMENT_TYPE   = 'container_preset';
    public const GLOBAL_REF_DOCUMENT_TYPES = ['page', 'landing', 'system'];
    public const CHROMED_DOCUMENT_TYPES    = ['page', 'landing'];

    public const ALLOWED_TOP_LEVEL_KEYS = [
        'schema_version',
        'document_type',
        'template_key',
        'settings',
        'seo',
        'containers',
    ];

    /** The one block type a document's top level may hold. */
    public const CONTAINER_TYPE = 'core.container';

    public const ALLOWED_SETTINGS_KEYS = [
        'container_width',
        'token_group',
        'header_mode',
        'footer_mode',
        'category',
        'tags',
        'featured',
        'excerpt',
        'template_type',
        'conditions',
        // A header partial's "Sticky header": the whole header stays at the top while the page scrolls (ScrollEffects' sticky shape).
        'sticky_header',
    ];

    /** Display-condition rules (Theme Builder): how many, which keys and which `condition` names a rule may use. */
    public const MAX_CONDITION_RULES = 30;
    public const ALLOWED_CONDITION_KEYS = [
        'action', 'type', 'condition', 'scope', 'value', 'category', 'tag', 'author_id', 'id', 'slug',
        'post_type', 'archive_type', 'term', 'field', 'field_value',
    ];
    public const ALLOWED_CONDITION_SCOPES = [
        'entire_site', 'all', 'global', 'front_page', 'home', 'category', 'tag', 'author', 'singular_id', 'id', 'slug',
        'singular', 'post_type', 'page_type', 'archive', '404', 'not_found', 'search',
    ];

    /** `default` is the site's content width (Site settings › Layout); the others are the site's Narrow, Normal and Wide, or no limit. */
    public const ALLOWED_CONTAINER_WIDTHS = ['default', 'narrow', 'normal', 'wide', 'full'];
    public const ALLOWED_CHROME_MODES     = ['inherit', 'custom', 'hidden'];

    public const ALLOWED_SEO_KEYS = [
        'title',
        'description',
        'canonical_url',
        'robots',
        'og_image_media_id',
    ];

    /** Character limits the validator enforces on `seo.title` / `seo.description` (the Builder shows the same numbers). */
    public const SEO_TITLE_MAX_LENGTH       = 255;
    public const SEO_DESCRIPTION_MAX_LENGTH = 1000;

    public const ALLOWED_ROBOTS_DIRECTIVES = [
        'index,follow',
        'noindex,follow',
        'index,nofollow',
        'noindex,nofollow',
    ];

    /** `animation.type` values (the entrance presets); the stylesheet owns the matching keyframes. */
    public const ALLOWED_ANIMATION_TYPES = ['fade_in', 'fade_up', 'fade_down', 'scale_up', 'slide_in', 'move_left', 'move_right', 'reveal_left', 'reveal_up', 'fade_left', 'fade_right', 'zoom_in', 'zoom_out', 'bounce_in', 'flip_x', 'flip_y', 'rotate_in', 'light_speed', 'pulse', 'none'];

    /** `interactions.trigger` values. */
    public const ALLOWED_INTERACTION_TRIGGERS = ['hover', 'focus', 'click', 'viewport-enter', 'scroll', 'load'];

    /** Wrapper elements an author may choose for a block (`tag`); `div` is the default and is not stored. */
    public const ALLOWED_BLOCK_TAGS = ['div', 'section', 'article', 'aside', 'header', 'footer', 'nav', 'figure', 'main', 'a'];

    /**
     * The only keys a block's optional `metadata` object may carry: an editor
     * display name (Layers panel) and the layer lock. Both are authoring aids;
     * neither reaches rendered output.
     */
    public const ALLOWED_BLOCK_METADATA_KEYS = ['label', 'locked'];

    /** Max length of a block's `metadata.label`. */
    public const BLOCK_LABEL_MAX_LENGTH = 80;

    public const ALLOWED_SPACING_SCALE = ['none', 'xs', 'sm', 'md', 'lg', 'xl', '2xl'];

    public const ALLOWED_VISIBILITY_KEYS = [
        'devices',
        'auth_state',
    ];

    public const ALLOWED_BREAKPOINTS = ['base', 'sm', 'md', 'lg'];
    public const ALLOWED_RESPONSIVE_BREAKPOINTS = ['desktop', 'tablet', 'mobile', 'base', 'sm', 'md', 'lg'];
    public const ALLOWED_AUTH_STATES = ['any', 'authenticated', 'guest'];

    /**
     * Mandatory keys that every block in a canonical document must possess.
     */
    public const REQUIRED_BLOCK_KEYS = [
        'id',
        'type',
        'version',
        'props',
        'style',
        'visibility',
        'bindings',
        'children',
    ];

    /**
     * All allowed block keys, including required keys and optional extension keys
     * (responsive overrides, attributes, classNames, conditions, metadata).
     */
    public const ALLOWED_BLOCK_KEYS = [
        'id',
        'type',
        'version',
        'props',
        'style',
        'visibility',
        'bindings',
        'children',
        'responsive',
        'attributes',
        'classNames',
        'conditions',
        'metadata',
        'interactions',
        'animation',
        'style_states',
        'style_parts',
        'tag',
        'link',
        'custom_css',
        'global_ref',
    ];

    /** Exact attribute names an author may set on a block wrapper. */
    public const ALLOWED_BLOCK_ATTRIBUTE_NAMES = ['id', 'role', 'tabindex', 'title', 'lang'];

    /** Attribute name prefixes an author may use (`aria-*`, `data-*`). */
    public const ALLOWED_BLOCK_ATTRIBUTE_PREFIXES = ['aria-', 'data-'];

    /** Reserved for editor/runtime metadata; authors must not spoof it. */
    public const RESERVED_BLOCK_ATTRIBUTE_PREFIX = 'data-sb-';

    public const MAX_BLOCK_ATTRIBUTES = 32;

    /**
     * Why a block attribute is refused, or null when it is allowed.
     *
     * Attributes are emitted verbatim into the block wrapper's tag, so this is a
     * closed list: event handlers (`on*`), `style`, `srcdoc`, `href`/`src` and
     * anything else not named here can never reach the page.
     */
    public static function blockAttributeIssue(mixed $name, mixed $value): ?string
    {
        if (!is_string($name) || preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1 || strlen($name) > 64) {
            return 'Attribute names must be lowercase letters, digits and hyphens.';
        }
        if (str_starts_with($name, self::RESERVED_BLOCK_ATTRIBUTE_PREFIX)) {
            return "Attribute '{$name}' is reserved for the builder.";
        }
        $allowed = in_array($name, self::ALLOWED_BLOCK_ATTRIBUTE_NAMES, true);
        foreach (self::ALLOWED_BLOCK_ATTRIBUTE_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix) && strlen($name) > strlen($prefix)) {
                $allowed = true;
            }
        }
        if (!$allowed) {
            return "Attribute '{$name}' is not allowed; use aria-*, data-*, id, role, tabindex, title or lang.";
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return "Attribute '{$name}' must be a string or number.";
        }
        if (strlen((string) $value) > self::MAX_STRING_LENGTH) {
            return "Attribute '{$name}' is too long.";
        }
        return null;
    }

    /**
     * Why a block's `link` (where an `a` wrapper points) is refused, or null when it is fine: `{url, new_tab?}`, with
     * the same URL allowlist as every other link in a document. `href` itself is never an author attribute.
     */
    public static function blockLinkIssue(mixed $link): ?string
    {
        if (!is_array($link) || array_is_list($link)) {
            return 'block.link must be an object {url, new_tab}.';
        }
        foreach (array_keys($link) as $key) {
            if (!in_array($key, ['url', 'new_tab'], true)) {
                return "block.link cannot have '{$key}'.";
            }
        }
        if (!is_string($link['url'] ?? null) || !FieldSchema::isSafeUrl($link['url'])) {
            return 'block.link.url must be a safe URL (http, https, mailto, tel, a path or an #anchor).';
        }
        if (array_key_exists('new_tab', $link) && !is_bool($link['new_tab'])) {
            return 'block.link.new_tab must be true or false.';
        }
        return null;
    }

    /**
     * Optional extension keys for blocks.
     */
    public const OPTIONAL_BLOCK_KEYS = [
        'responsive',
        'attributes',
        'classNames',
        'conditions',
        'metadata',
        'interactions',
        'animation',
        'style_states',
        'style_parts',
        'tag',
        'link',
        'custom_css',
        'global_ref',
    ];

    /** An element's own stylesheet: a few rules, not a file. */
    public const MAX_ELEMENT_CSS_BYTES = 8192;

    /**
     * Range of an author's `style.z_index`. The ceiling sits below the platform
     * signature's own stacking level (`StudioCodePolicy::SIGNATURE_Z_INDEX`), so
     * no block can be stacked above it.
     */
    public const Z_INDEX_MIN = -999;
    public const Z_INDEX_MAX = 999;

    public const ALLOWED_STYLE_KEYS = [
        'align',
        'surface_token',
        'text_token',
        'spacing_token',
        'radius_token',
        'shadow_token',
        'font_token',
        'border_token',
        'typography',
        'color',
        'background',
        'border',
        'shadow',
        'dimensions',
        'opacity',
        'z_index',
        // B2-P3b: defined by StyleSurface (closed fields, server-formatted CSS).
        'layout',
        'position',
        'effects',
        'margin',
        'padding',
        'text_shadow',
        'overlay',
    ];

    public const ALLOWED_FONT_WEIGHTS = ['normal', 'medium', 'semibold', 'bold', 'extrabold', '100', '200', '300', '400', '500', '600', '700', '800', '900'];
    public const ALLOWED_TEXT_TRANSFORMS = ['none', 'uppercase', 'lowercase', 'capitalize'];
    public const ALLOWED_BORDER_STYLES = ['none', 'solid', 'dashed', 'dotted', 'double', 'groove'];
    public const ALLOWED_SHADOW_PRESETS = ['none', 'sm', 'md', 'lg', 'xl', '2xl', 'inner'];
    public const ALLOWED_RADIUS_PRESETS = ['none', 'sm', 'md', 'lg', 'xl', '2xl', 'full'];

    public const ALLOWED_ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    public const BLOCK_ID_PATTERN     = '/^blk_[a-z0-9]{16,32}$/';
    public const BLOCK_TYPE_PATTERN   = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';
    public const TEMPLATE_KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,119}$/';
    public const TOKEN_GROUP_PATTERN  = '/^[a-z0-9][a-z0-9_-]{0,63}$/';
    public const GLOBAL_REF_PATTERN   = '/^[a-z0-9][a-z0-9_.-]{0,119}$/';
    /** A Global Component reference is the component page's immutable v4 uuid (a subset of GLOBAL_REF_PATTERN). */
    public const COMPONENT_REF_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    public const TOKEN_REF_PATTERN    = '/^(surface|text|space|radius|shadow|font|type|color|border|layout)\.[a-z0-9_]+(\.[a-z0-9_]+)?$/';
    public const PROVIDER_KEY_PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    /**
     * Transient keys that an editor UI might attach in-memory and that DocumentNormalizer
     * strips before persistence if present via `stripTransientMetadata()`.
     */
    public const TRANSIENT_EDITOR_KEYS = [
        '_editor',
        '_ui',
        '_selected',
        '_hovered',
        '_collapsed',
        '_dirty',
    ];

    /**
     * Generate a cryptographically random opaque Block ID (`blk_<24 hex chars>`).
     */
    public static function newBlockId(): string
    {
        return 'blk_' . bin2hex(random_bytes(12));
    }

    /**
     * Default document-level settings map.
     *
     * @return array<string, string>
     */
    public static function defaultSettings(): array
    {
        return [
            'container_width' => 'wide',
            'footer_mode'     => 'inherit',
            'header_mode'     => 'inherit',
            'token_group'     => 'default',
        ];
    }

    /**
     * Default document-level SEO map.
     *
     * @return array<string, mixed>
     */
    public static function defaultSeo(string $title = ''): array
    {
        return [
            'canonical_url'     => null,
            'description'       => '',
            'og_image_media_id' => null,
            'robots'            => 'index,follow',
            'title'             => $title,
        ];
    }

    /**
     * A new top-level Container: a `<section>`, boxed to the site's content width with the site's gutter, spaced like the
     * site's default band. Its children are stacked until the author says otherwise.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function newContainer(array $overrides = []): array
    {
        return array_replace([
            'id'         => self::newBlockId(),
            'type'       => self::CONTAINER_TYPE,
            'version'    => self::CONTAINER_VERSION,
            'tag'        => 'section',
            'props'      => [],
            'style'      => ['surface_token' => 'surface.primary', 'layout' => ['content_width' => 'boxed', 'gutter' => 'site']],
            'visibility' => self::defaultVisibility(),
            'bindings'   => [],
            'children'   => [],
        ], $overrides);
    }

    /**
     * Default visibility map for blocks.
     *
     * @return array<string, mixed>
     */
    public static function defaultVisibility(): array
    {
        return [
            'auth_state' => 'any',
            'devices'    => self::ALLOWED_BREAKPOINTS,
        ];
    }

    /**
     * Default block style map.
     *
     * @return array<string, mixed>
     */
    public static function defaultBlockStyle(): array
    {
        return [
            'align'         => ['base' => 'left'],
            'font_token'    => null,
            'radius_token'  => null,
            'shadow_token'  => null,
            'spacing_token' => null,
            'surface_token' => null,
            'text_token'    => null,
        ];
    }

    /**
     * Construct a blank, valid canonical Studio document (schema_version = "2.0").
     *
     * @return array<string, mixed>
     */
    public static function emptyDocument(
        string $documentType = 'page',
        string $templateKey = 'default',
        string $seoTitle = '',
    ): array {
        return [
            'document_type'  => $documentType,
            'schema_version' => self::SCHEMA_VERSION,
            'containers'     => [],
            'seo'            => self::defaultSeo($seoTitle),
            'settings'       => self::defaultSettings(),
            'template_key'   => $templateKey,
        ];
    }
}
