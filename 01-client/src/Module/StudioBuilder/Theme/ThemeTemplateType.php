<?php
/**
 * Kohevo Studio (studio-builder) — Theme Template Types.
 *
 * Defines the canonical roles for Theme Builder templates:
 *  - header: Site-wide or conditional header
 *  - footer: Site-wide or conditional footer
 *  - single: Template for rendering single post, page, or custom post type
 *  - archive: Template for listing posts by category, tag, author, or date
 *  - search: Template for displaying search results
 *  - not_found / 404: Template for displaying Not Found error responses
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Theme;

final class ThemeTemplateType
{
    public const HEADER    = 'header';
    public const FOOTER    = 'footer';
    public const SINGLE    = 'single';
    public const ARCHIVE   = 'archive';
    public const SEARCH    = 'search';
    public const NOT_FOUND = 'not_found';
    public const CODE_404  = '404';

    public const ALL = [
        self::HEADER,
        self::FOOTER,
        self::SINGLE,
        self::ARCHIVE,
        self::SEARCH,
        self::NOT_FOUND,
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, [
            self::HEADER,
            self::FOOTER,
            self::SINGLE,
            self::ARCHIVE,
            self::SEARCH,
            self::NOT_FOUND,
            self::CODE_404,
        ], true);
    }

    public static function isChrome(string $type): bool
    {
        return $type === self::HEADER || $type === self::FOOTER;
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ALL;
    }
}
