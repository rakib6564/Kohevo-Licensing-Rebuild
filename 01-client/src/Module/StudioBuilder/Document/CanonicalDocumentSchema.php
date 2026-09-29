<?php
/**
 * Kohevo Studio (studio-builder) — Canonical Document Schema ("1.0").
 *
 * Defines the structural constants, limits, allowed keys, node ID patterns,
 * symbolic token reference patterns, and default envelope structures for
 * the Kohevo-owned Canonical Studio Document.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

final class CanonicalDocumentSchema
{
    public const SCHEMA_VERSION = '1.0';

    public const MAX_DOCUMENT_BYTES      = 1048576; // 1 MiB
    public const MAX_JSON_DEPTH          = 32;
    public const MAX_SECTIONS            = 50;
    public const MAX_BLOCKS_PER_DOCUMENT = 250;
    public const MAX_NESTING_DEPTH       = 4;
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
        'section_preset',
    ];

    public const ALLOWED_TOP_LEVEL_KEYS = [
        'schema_version',
        'document_type',
        'template_key',
        'settings',
        'seo',
        'sections',
    ];

    public const ALLOWED_SETTINGS_KEYS = [
        'container_width',
        'token_group',
        'header_mode',
        'footer_mode',
    ];

    public const ALLOWED_CONTAINER_WIDTHS = ['narrow', 'normal', 'wide', 'full'];
    public const ALLOWED_CHROME_MODES     = ['inherit', 'custom', 'hidden'];

    public const ALLOWED_SEO_KEYS = [
        'title',
        'description',
        'canonical_url',
        'robots',
        'og_image_media_id',
    ];

    public const ALLOWED_ROBOTS_DIRECTIVES = [
        'index,follow',
        'noindex,follow',
        'index,nofollow',
        'noindex,nofollow',
    ];

    public const ALLOWED_SECTION_KEYS = [
        'id',
        'label',
        'global_ref',
        'layout',
        'visibility',
        'blocks',
    ];

    public const ALLOWED_LAYOUT_KEYS = [
        'columns',
        'width',
        'gap',
        'padding_y',
        'background_token',
    ];

    public const ALLOWED_SPACING_SCALE = ['none', 'xs', 'sm', 'md', 'lg', 'xl', '2xl'];

    public const ALLOWED_VISIBILITY_KEYS = [
        'devices',
        'auth_state',
    ];

    public const ALLOWED_BREAKPOINTS = ['base', 'sm', 'md', 'lg'];
    public const ALLOWED_AUTH_STATES = ['any', 'authenticated', 'guest'];

    public const ALLOWED_BLOCK_KEYS = [
        'id',
        'type',
        'version',
        'props',
        'style',
        'visibility',
        'bindings',
        'children',
    ];

    public const ALLOWED_STYLE_KEYS = [
        'align',
        'surface_token',
        'text_token',
        'spacing_token',
        'radius_token',
        'shadow_token',
        'font_token',
    ];

    public const ALLOWED_ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    public const SECTION_ID_PATTERN   = '/^sec_[a-z0-9]{16,32}$/';
    public const BLOCK_ID_PATTERN     = '/^blk_[a-z0-9]{16,32}$/';
    public const BLOCK_TYPE_PATTERN   = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';
    public const TEMPLATE_KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,119}$/';
    public const TOKEN_GROUP_PATTERN  = '/^[a-z0-9][a-z0-9_-]{0,63}$/';
    public const GLOBAL_REF_PATTERN   = '/^[a-z0-9][a-z0-9_.-]{0,119}$/';
    public const TOKEN_REF_PATTERN    = '/^(surface|text|space|radius|shadow|font|color|border)\.[a-z0-9_]+(\.[a-z0-9_]+)?$/';
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
     * Generate a cryptographically random opaque Section ID (`sec_<24 hex chars>`).
     */
    public static function newSectionId(): string
    {
        return 'sec_' . bin2hex(random_bytes(12));
    }

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
     * Default section layout map.
     *
     * @return array<string, mixed>
     */
    public static function defaultSectionLayout(): array
    {
        return [
            'background_token' => 'surface.primary',
            'columns'          => ['base' => 1, 'md' => 12],
            'gap'              => 'md',
            'padding_y'        => ['base' => 'md', 'md' => 'lg'],
            'width'            => 'wide',
        ];
    }

    /**
     * Default visibility map for sections and blocks.
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
     * Construct a blank, valid canonical Studio document (schema_version = "1.0").
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
            'sections'       => [],
            'seo'            => self::defaultSeo($seoTitle),
            'settings'       => self::defaultSettings(),
            'template_key'   => $templateKey,
        ];
    }
}
