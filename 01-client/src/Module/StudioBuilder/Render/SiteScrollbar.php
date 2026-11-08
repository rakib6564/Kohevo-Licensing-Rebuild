<?php
/**
 * Kohevo Studio — the page's own scroll bar (Site & SEO › Scrolling), for every public page and preview.
 *
 *  - default  the browser's scroll bar, untouched (nothing is emitted)
 *  - thin     the browser's thin scroll bar
 *  - branded  a thin rounded bar in the site's colours: the thumb in the accent (or a colour of its own), on a track
 *             that is see-through (or a colour of its own)
 *  - hidden   no scroll bar; the page still scrolls with the wheel, touch, keyboard and its links. The site's own menu
 *             panels (an off-canvas or full-screen menu, a folded Nav menu's panel) hide theirs too, so an open menu
 *             shows no bar either
 *
 * Otherwise only the page's bar (the <html> element) is styled: a scrolling box inside a page keeps the browser's. Colours are
 * written as #rrggbb only, so nothing else can reach the stylesheet.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

final class SiteScrollbar
{
    public const SETTING = 'studio_scrollbar';
    public const THUMB_SETTING = 'studio_scrollbar_thumb';
    public const TRACK_SETTING = 'studio_scrollbar_track';
    public const STYLES = ['default', 'thin', 'branded', 'hidden'];

    /** A style the setting may hold, or 'default'. */
    public static function style(?string $value): string
    {
        return in_array($value, self::STYLES, true) ? $value : 'default';
    }

    /** `#rrggbb` (lower case) or '' for anything else. */
    public static function colour(?string $value): string
    {
        $v = strtolower(trim((string) $value));
        return preg_match('/^#[0-9a-f]{6}$/', $v) === 1 ? $v : '';
    }

    /** The CSS for a style and its colours ('' for the browser's own bar). */
    public static function cssFor(string $style, string $thumb = '', string $track = ''): string
    {
        $thumb = self::colour($thumb) ?: 'var(--sb-color-accent,#888)';
        $track = self::colour($track) ?: 'transparent';
        return match (self::style($style)) {
            'thin' => 'html{scrollbar-width:thin}',
            'hidden' => 'html{scrollbar-width:none}html::-webkit-scrollbar{display:none;width:0;height:0}'
                . ':is(.sb-offcanvas__body,.sb-nav__list){scrollbar-width:none}:is(.sb-offcanvas__body,.sb-nav__list)::-webkit-scrollbar{display:none;width:0;height:0}',
            // scrollbar-color is inherited: the page's bar takes it from <html>, and <body> hands the browser's back
            // to every scrolling box inside the page (a chat panel, a table) so only the page's bar is branded.
            'branded' => 'html{scrollbar-width:thin;scrollbar-color:' . $thumb . ' ' . $track . '}body{scrollbar-color:auto}'
                . 'html::-webkit-scrollbar{width:10px;height:10px;background:' . $track . '}'
                . 'html::-webkit-scrollbar-thumb{background:' . $thumb . ';border-radius:999px;border:2px solid transparent;background-clip:padding-box}'
                . 'html::-webkit-scrollbar-thumb:hover{border-width:1px}',
            default => '',
        };
    }

    /** The CSS for a site, from its settings ('' when it keeps the browser's bar or the settings cannot be read). */
    public static function css(int $tenantId): string
    {
        try {
            return self::cssFor(
                (string) \Database::setting(self::SETTING, $tenantId),
                (string) \Database::setting(self::THUMB_SETTING, $tenantId),
                (string) \Database::setting(self::TRACK_SETTING, $tenantId),
            );
        } catch (\Throwable $ignored) {
            return '';
        }
    }
}
