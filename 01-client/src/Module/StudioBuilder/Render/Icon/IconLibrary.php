<?php
/**
 * Kohevo Studio — the built-in icon library (icon-data.php).
 *
 * The name is validated against this list and only the stored markup is emitted, so no author-supplied SVG ever reaches a
 * page. Names are stored in documents: add to the list, never rename.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Icon;

final class IconLibrary
{
    /** @var ?array<string, string> */
    private static ?array $icons = null;

    /** @return array<string, string> name => inner SVG markup */
    private static function icons(): array
    {
        return self::$icons ??= require __DIR__ . '/icon-data.php';
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::icons());
    }

    public static function has(string $name): bool
    {
        return isset(self::icons()[$name]);
    }

    /** Inner SVG markup for a known name; an empty string for anything else. */
    public static function markup(string $name): string
    {
        return self::icons()[$name] ?? '';
    }

    /** A whole icon, sized by CSS: `<svg>` around the markup, or '' for an unknown name. */
    public static function svg(string $name): string
    {
        $inner = self::markup($name);
        return $inner === '' ? '' : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $inner . '</svg>';
    }
}
