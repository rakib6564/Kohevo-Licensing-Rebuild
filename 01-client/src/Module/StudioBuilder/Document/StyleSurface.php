<?php
/**
 * Kohevo Studio (studio-builder) — the closed style surface added in B2-P3.
 *
 * One table defines, per style key, every field an author may set, how it is
 * validated, and the single CSS declaration it produces. The validator and the
 * renderer both read this table, so a field cannot be accepted without a
 * known, server-formatted declaration, and nothing outside the table can reach
 * a stylesheet.
 *
 * Field kinds:
 *   enum    value must be one of a list; the CSS value is the value itself
 *   map     value must be a key of a map; the CSS value is the map's value
 *   length  a StyleValueGuard length (units or calc/clamp/min/max), optionally `auto`
 *   int     an integer within bounds
 *   number  an int/float within bounds, formatted by the server with a unit
 *   ratio   `w/h` with 1-3 digit integers
 *   tracks  grid column sizes: up to 12 of `auto`, `<n>fr`, a length or `minmax(a,b)` (see isTracks)
 *   span    how many grid columns a child covers: 1-12, or `full` for the whole row
 *   gradient a StyleValueGuard gradient (`linear-gradient(...)`)
 *   opacity 0-100, written as a fraction
 *   maxlen  a length, or `none`
 *
 * `position: fixed` is deliberately absent (it could cover the platform
 * signature); `layout.display: none` is absent too (visibility has its own control).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

use Slate\Module\StudioBuilder\Registry\BlockParts;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

final class StyleSurface
{
    /** Style keys introduced by B2-P3b, in the order their declarations are emitted. */
    public const KEYS = ['layout', 'position', 'effects', 'margin', 'padding'];

    /** Sub-fields B2-P3b adds to the existing `dimensions` object (the original four stay as they were). */
    public const DIMENSION_EXTRAS = ['min_width', 'max_height', 'aspect_ratio', 'overflow', 'object_fit', 'object_position'];

    private const JUSTIFY = ['start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end', 'between' => 'space-between', 'around' => 'space-around', 'evenly' => 'space-evenly'];
    private const ALIGN   = ['start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end', 'stretch' => 'stretch', 'baseline' => 'baseline'];
    private const ALIGN_CONTENT = ['start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end', 'stretch' => 'stretch', 'between' => 'space-between', 'around' => 'space-around', 'evenly' => 'space-evenly'];
    private const ALIGN_SELF    = ['auto' => 'auto', 'start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end', 'stretch' => 'stretch', 'baseline' => 'baseline'];
    private const POSITIONS = ['center' => 'center', 'top' => 'top', 'bottom' => 'bottom', 'left' => 'left', 'right' => 'right',
        'top-left' => 'top left', 'top-right' => 'top right', 'bottom-left' => 'bottom left', 'bottom-right' => 'bottom right'];
    private const EASINGS = ['ease' => 'ease', 'ease-in' => 'ease-in', 'ease-out' => 'ease-out', 'ease-in-out' => 'ease-in-out', 'linear' => 'linear'];
    private const BLENDS  = ['normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten'];
    private const CURSORS = ['auto', 'default', 'pointer', 'text', 'move', 'not-allowed', 'grab'];

    /**
     * @return array<string, array<string, array{0: string, 1: string, 2?: mixed, 3?: mixed, 4?: string}>>
     *   key => field => [kind, css property, param, param, template]
     */
    private static function fields(): array
    {
        return [
            'layout' => [
                'display'   => ['enum', 'display', ['block', 'flex', 'grid', 'inline-block', 'inline-flex']],
                'direction' => ['enum', 'flex-direction', ['row', 'row-reverse', 'column', 'column-reverse']],
                'wrap'      => ['enum', 'flex-wrap', ['nowrap', 'wrap', 'wrap-reverse']],
                'justify'   => ['map', 'justify-content', self::JUSTIFY],
                'align'     => ['map', 'align-items', self::ALIGN],
                'align_content' => ['map', 'align-content', self::ALIGN_CONTENT],
                'gap'       => ['length', 'gap'],
                'row_gap'   => ['length', 'row-gap'],
                'column_gap' => ['length', 'column-gap'],
                'order'     => ['int', 'order', -99, 99],
                'grow'      => ['int', 'flex-grow', 0, 10],
                'shrink'    => ['int', 'flex-shrink', 0, 10],
                'basis'     => ['length', 'flex-basis', true],
                'align_self' => ['map', 'align-self', self::ALIGN_SELF],
                'columns'   => ['int', 'grid-template-columns', 1, 12, 'repeat(%d,minmax(0,1fr))'],
                // Unequal or fixed columns (`2fr 1fr`, `300px auto`); after `columns`, so it wins when both are set.
                'tracks'    => ['tracks', 'grid-template-columns'],
                'rows'      => ['int', 'grid-template-rows', 1, 12, 'repeat(%d,minmax(0,1fr))'],
                // On a grid's child: the columns it covers.
                'column_span' => ['span', 'grid-column'],
                // ...and the rows it covers (a form beside a heading and the cards under it).
                'row_span'    => ['span', 'grid-row'],
                // Boxed content width: the block stays full width (its background too) and its children are held to
                // `content_max` by side padding (see emitBoxed). No CSS property of their own, so the property is ''.
                'content_width' => ['enum', '', ['boxed', 'full']],
                'content_max'   => ['length', ''],
            ],
            'position' => [
                'mode'   => ['enum', 'position', ['static', 'relative', 'absolute', 'sticky']],
                'top'    => ['length', 'top', true],
                'right'  => ['length', 'right', true],
                'bottom' => ['length', 'bottom', true],
                'left'   => ['length', 'left', true],
            ],
            'dimensions' => [
                'min_width'       => ['length', 'min-width', true],
                'max_height'      => ['length', 'max-height', true],
                'aspect_ratio'    => ['ratio', 'aspect-ratio'],
                'overflow'        => ['enum', 'overflow', ['visible', 'hidden', 'auto', 'scroll']],
                'object_fit'      => ['enum', 'object-fit', ['cover', 'contain', 'fill', 'none', 'scale-down']],
                'object_position' => ['map', 'object-position', self::POSITIONS],
            ],
        ];
    }

    private const SIDES   = ['top', 'right', 'bottom', 'left'];
    private const CORNERS = ['tl' => 'top-left', 'tr' => 'top-right', 'br' => 'bottom-right', 'bl' => 'bottom-left'];

    /** Sub-fields B2-P3b adds to `typography` (size, weight, ... keep their existing handling). */
    public const TYPOGRAPHY_EXTRAS = ['style', 'decoration', 'decoration_style', 'decoration_color', 'decoration_thickness', 'decoration_offset', 'stroke_width', 'stroke_color'];

    /** Pseudo-classes an author may style, keyed by the `style_states` name. */
    /**
     * When a hover may move something. A touch screen keeps :hover after a tap, which would leave the block lifted, and
     * a visitor who asked for less motion has no transition, so the move would be a jump.
     */
    public const HOVER_MOTION = '(hover:hover) and (prefers-reduced-motion:no-preference)';

    public const STATE_SELECTORS = ['hover' => ':hover', 'focus' => ':focus-within', 'active' => ':active', 'disabled' => ':has(:disabled)'];

    /** @return array<string, array<string, array<int, mixed>>> */
    private static function moreFields(): array
    {
        $margin = [];
        $padding = [];
        foreach (self::SIDES as $side) {
            $margin[$side]  = ['length', 'margin-' . $side, true];
            $padding[$side] = ['padlen', 'padding-' . $side];
        }
        $sideDef = [];
        foreach (self::SIDES as $side) {
            $sideDef[$side] = [
                'width' => ['length', 'border-' . $side . '-width'],
                'style' => ['enum', 'border-' . $side . '-style', CanonicalDocumentSchema::ALLOWED_BORDER_STYLES],
                'color' => ['tcolor', 'border-' . $side . '-color'],
            ];
        }
        $corners = [];
        foreach (self::CORNERS as $short => $long) {
            $corners[$short] = ['length', 'border-' . $long . '-radius'];
        }
        return [
            'margin'     => $margin,
            'padding'    => $padding,
            'border'     => $sideDef,
            'corners'    => $corners,
            'typography' => [
                'style'                => ['enum', 'font-style', ['normal', 'italic']],
                'decoration'           => ['enum', 'text-decoration-line', ['none', 'underline', 'line-through', 'overline']],
                'decoration_style'     => ['enum', 'text-decoration-style', ['solid', 'dashed', 'dotted', 'wavy', 'double']],
                'decoration_color'     => ['tcolor', 'text-decoration-color'],
                'decoration_thickness' => ['length', 'text-decoration-thickness'],
                'decoration_offset'    => ['length', 'text-underline-offset'],
                // Text stroke: an outline drawn around each letter.
                'stroke_width'         => ['length', '-webkit-text-stroke-width'],
                'stroke_color'         => ['tcolor', '-webkit-text-stroke-color'],
            ],
            'text_shadow' => [
                'x'     => ['length', 'x'],
                'y'     => ['length', 'y'],
                'blur'  => ['length', 'blur'],
                'color' => ['tcolor', 'color'],
            ],
            'shadow' => [
                'x'      => ['length', 'x'],
                'y'      => ['length', 'y'],
                'blur'   => ['length', 'blur'],
                'spread' => ['length', 'spread'],
                'color'  => ['tcolor', 'color'],
            ],
        ];
    }

    private const REPEATS = ['no-repeat', 'repeat', 'repeat-x', 'repeat-y'];
    private const ATTACHMENTS = ['scroll', 'fixed'];
    private const FITS    = ['cover', 'contain', 'auto'];

    /**
     * A URL that is safe to place inside `url("...")` in a stylesheet, or null.
     * The URL comes from the media resolver (never from the document), and is
     * still checked against a whitelist: no quotes, backslashes, parentheses,
     * whitespace, angle brackets or braces can get through.
     */
    public static function safeCssUrl(string $url): ?string
    {
        if ($url === '' || strlen($url) > 2000 || preg_match('~^(?:https?:)?//?[A-Za-z0-9._\~:/?#@!$&*+,=%\-\[\]]*$~', $url) !== 1) {
            return null;
        }
        return $url;
    }

    /**
     * Issues for the B2-P3b parts of `background`: image (a media_ref), fit,
     * repeat, position and overlay. A string `image` (the pre-P3b editor wrote
     * a typed URL that nothing ever rendered) is left alone, as before.
     *
     * @param array<string, mixed> $bg
     * @return list<array{path: string, code: string, message: string}>
     */
    public static function backgroundIssues(array $bg, string $path): array
    {
        $errors = [];
        if (isset($bg['image']) && is_array($bg['image'])) {
            (FieldSchema::define([]))->validateMediaRef($bg['image'], "{$path}.image", $errors);
            if (isset($bg['gradient']) && $bg['gradient'] !== '') {
                $errors[] = self::issue("{$path}.gradient", 'Choose an image or a gradient, not both.');
            }
        }
        if (array_key_exists('fit', $bg) && !(is_string($bg['fit']) && in_array($bg['fit'], self::FITS, true))) {
            $errors[] = self::issue("{$path}.fit", 'fit must be cover, contain or auto.');
        }
        if (array_key_exists('repeat', $bg) && !(is_string($bg['repeat']) && in_array($bg['repeat'], self::REPEATS, true))) {
            $errors[] = self::issue("{$path}.repeat", 'Unknown repeat mode.');
        }
        if (array_key_exists('position', $bg) && !(is_string($bg['position']) && isset(self::POSITIONS[$bg['position']]))) {
            $errors[] = self::issue("{$path}.position", 'Unknown position.');
        }
        if (array_key_exists('attachment', $bg) && !(is_string($bg['attachment']) && in_array($bg['attachment'], self::ATTACHMENTS, true))) {
            $errors[] = self::issue("{$path}.attachment", 'attachment must be scroll or fixed.');
        }
        if (array_key_exists('overlay', $bg)) {
            self::checkLimitedObject($bg['overlay'], ['color' => ['color', 'color']], "{$path}.overlay", $errors);
            if (is_array($bg['overlay']) && !array_key_exists('color', $bg['overlay'])) {
                $errors[] = self::issue("{$path}.overlay.color", 'An overlay needs a colour.');
            }
        }
        return $errors;
    }

    /**
     * Declarations for a background image already resolved to a safe URL. With `$parallax` (ScrollEffects) the node does
     * not paint the image itself: it goes into custom properties for the parallax layer (`.sb-parallax-bg::after`), which
     * studio-runtime.js moves as the page scrolls.
     *
     * @param array<string, mixed> $style
     * @param list<string> $out
     */
    private static function emitBackgroundImage(array $style, ?string $url, array &$out, bool $parallax = false): void
    {
        $bg = is_array($style['background'] ?? null) ? $style['background'] : [];
        if ($url === null || !is_array($bg['image'] ?? null) || self::safeCssUrl($url) === null) {
            return;
        }
        $layers = [];
        $overlay = is_array($bg['overlay'] ?? null) ? $bg['overlay'] : [];
        if (isset($overlay['color']) && self::fieldAccepts($overlay['color'], ['color', 'color'])) {
            $c = trim((string) $overlay['color']);
            $layers[] = 'linear-gradient(' . $c . ',' . $c . ')';
        }
        $layers[] = 'url("' . $url . '")';
        // `!important`: an image the author chose in the Inspector must show even when a CSS class on the block (a `background:`
        // shorthand in the page's custom CSS, say) sets a background of its own, which would otherwise cover it.
        $size = isset($bg['fit']) && is_string($bg['fit']) && in_array($bg['fit'], self::FITS, true) ? $bg['fit'] : 'cover';
        $repeat = isset($bg['repeat']) && is_string($bg['repeat']) && in_array($bg['repeat'], self::REPEATS, true) ? $bg['repeat'] : 'no-repeat';
        $position = 'center';
        $fp = $bg['image']['focal_point'] ?? null;
        if (is_array($fp) && count($fp) === 2 && self::inRange($fp[0] ?? null, 0, 1) && self::inRange($fp[1] ?? null, 0, 1)) {
            // Only computed numbers: the focal point is two floats in [0, 1].
            $position = self::num($fp[0] * 100) . '% ' . self::num($fp[1] * 100) . '%';
        } elseif (isset($bg['position']) && is_string($bg['position']) && isset(self::POSITIONS[$bg['position']])) {
            $position = self::POSITIONS[$bg['position']];
        }
        if ($parallax) {
            array_push($out, '--sb-pbg-image:' . implode(',', $layers), '--sb-pbg-size:' . $size, '--sb-pbg-repeat:' . $repeat, '--sb-pbg-position:' . $position);
            return;
        }
        $out[] = 'background-image:' . implode(',', $layers) . ' !important';
        $out[] = 'background-size:' . $size . ' !important';
        $out[] = 'background-repeat:' . $repeat . ' !important';
        $out[] = 'background-position:' . $position . ' !important';
        if (isset($bg['attachment']) && is_string($bg['attachment']) && in_array($bg['attachment'], self::ATTACHMENTS, true)) {
            $out[] = 'background-attachment:' . $bg['attachment'] . ' !important';
        }
    }

    /**
     * A boxed block keeps its full width (and its background) and holds its children to `layout.content_max`, or to the
     * site's content width when it has none: each side's
     * padding becomes the larger of the author's padding and half the room left over, as a centred inner box would give.
     * The percentage is the parent's width, which is the block's own while it is boxed (its width is never set then).
     *
     * @param array<string, mixed> $style
     * @param list<string> $out
     */
    private static function emitBoxed(array $style, array &$out): void
    {
        $layout = is_array($style['layout'] ?? null) ? $style['layout'] : [];
        if (($layout['content_width'] ?? null) !== 'boxed') {
            return;
        }
        // With no width of its own a boxed block holds its children to the site's content width (Site settings › Layout).
        $max = isset($layout['content_max']) && self::fieldAccepts($layout['content_max'], ['length', ''])
            ? trim((string) $layout['content_max'])
            : 'var(--sb-layout-content,80rem)';
        $padding = is_array($style['padding'] ?? null) ? $style['padding'] : [];
        foreach (['left', 'right'] as $side) {
            $own = isset($padding[$side]) && self::fieldAccepts($padding[$side], ['padlen', '']) ? trim((string) $padding[$side]) : '0px';
            // One declaration per side: the author's own padding is folded into this one, never left beside it.
            $out = array_values(array_filter($out, static fn (string $d): bool => !str_starts_with($d, 'padding-' . $side . ':')));
            $out[] = 'padding-' . $side . ':max(' . $own . ',calc((100% - ' . $max . ')/2))';
        }
    }

    /** Numeric parts of `effects`: group => field => [min, max, unit]. */
    private const FLIPS = ['flip_x' => 'scaleX(-1)', 'flip_y' => 'scaleY(-1)'];
    private const TRANSFORM = ['rotate' => [-360, 360, 'deg'], 'scale' => [0, 5, ''], 'skew_x' => [-90, 90, 'deg'], 'skew_y' => [-90, 90, 'deg']];
    private const FILTER    = ['blur' => [0, 50, 'px'], 'brightness' => [0, 300, '%'], 'contrast' => [0, 300, '%'], 'saturate' => [0, 300, '%'], 'grayscale' => [0, 100, '%']];

    // ── validation ────────────────────────────────────────────────────────────

    /**
     * Issues for the B2-P3b keys (and dimension extras) of a block `style`.
     *
     * @param array<string, mixed> $style
     * @return list<array{path: string, code: string, message: string}>
     */
    public static function issues(array $style, string $path): array
    {
        $errors = [];
        foreach (['layout', 'position'] as $key) {
            if (!array_key_exists($key, $style) || $style[$key] === null) {
                continue;
            }
            self::checkObject($style[$key], self::fields()[$key], "{$path}.{$key}", $errors);
        }
        if (isset($style['dimensions']) && is_array($style['dimensions'])) {
            $extras = array_intersect_key(self::fields()['dimensions'], $style['dimensions']);
            foreach ($extras as $field => $def) {
                self::checkField($style['dimensions'][$field], $def, "{$path}.dimensions.{$field}", $errors);
            }
        }
        if (array_key_exists('effects', $style) && $style['effects'] !== null) {
            self::checkEffects($style['effects'], "{$path}.effects", $errors);
        }
        $more = self::moreFields();
        foreach (['margin', 'padding'] as $key) {
            if (array_key_exists($key, $style) && $style[$key] !== null) {
                self::checkObject($style[$key], $more[$key], "{$path}.{$key}", $errors);
            }
        }
        if (isset($style['border']) && is_array($style['border'])) {
            foreach (self::SIDES as $side) {
                if (array_key_exists($side, $style['border'])) {
                    self::checkObject($style['border'][$side], $more['border'][$side], "{$path}.border.{$side}", $errors);
                }
            }
            if (array_key_exists('radius_corners', $style['border'])) {
                self::checkObject($style['border']['radius_corners'], $more['corners'], "{$path}.border.radius_corners", $errors);
            }
        }
        if (isset($style['typography']) && is_array($style['typography'])) {
            foreach (array_intersect_key($more['typography'], $style['typography']) as $field => $def) {
                self::checkField($style['typography'][$field], $def, "{$path}.typography.{$field}", $errors);
            }
        }
        if (array_key_exists('text_shadow', $style) && $style['text_shadow'] !== null) {
            self::checkTextShadow($style['text_shadow'], "{$path}.text_shadow", $errors);
        }
        if (array_key_exists('overlay', $style) && $style['overlay'] !== null) {
            self::checkOverlay($style['overlay'], "{$path}.overlay", $errors, false);
        }
        if (isset($style['shadow']) && is_array($style['shadow'])) {
            self::checkShadowObject($style['shadow'], "{$path}.shadow", $errors);
        }
        if (isset($style['background']) && is_array($style['background'])) {
            array_push($errors, ...self::backgroundIssues($style['background'], "{$path}.background"));
        }
        return $errors;
    }

    /**
     * @param array<string, array<int, mixed>> $defs
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function checkObject(mixed $value, array $defs, string $path, array &$errors): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors[] = self::issue($path, "{$path} must be an object.");
            return;
        }
        foreach ($value as $field => $v) {
            if (!isset($defs[(string) $field])) {
                $errors[] = self::issue("{$path}.{$field}", "Unknown field '{$field}'.");
                continue;
            }
            self::checkField($v, $defs[(string) $field], "{$path}.{$field}", $errors);
        }
    }

    /**
     * @param array<int, mixed> $def
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function checkField(mixed $v, array $def, string $path, array &$errors): void
    {
        if (!self::fieldAccepts($v, $def)) {
            $errors[] = self::issue($path, 'Invalid value.');
        }
    }

    /** @param array<int, mixed> $def */
    private static function fieldAccepts(mixed $v, array $def): bool
    {
        switch ($def[0]) {
            case 'enum':
                return is_string($v) && in_array($v, $def[2], true);
            case 'map':
                return is_string($v) && array_key_exists($v, $def[2]);
            case 'length':
                return self::isMeasure($v, (bool) ($def[2] ?? false));
            case 'int':
                return is_int($v) && $v >= $def[2] && $v <= $def[3];
            case 'ratio':
                return is_string($v) && preg_match('/^[1-9][0-9]{0,2}\/[1-9][0-9]{0,2}$/', $v) === 1;
            case 'padlen':
                return self::isMeasure($v, false) && !str_starts_with(trim((string) $v), '-');
            case 'color':
                // A literal colour only: a token is not a CSS value, and would emit a declaration the browser ignores.
                return is_string($v) && StyleValueGuard::isColor($v) && !StyleValueGuard::isToken(trim($v));
            case 'tcolor':
                // A literal colour, or a theme colour token that is written out as the theme's own custom property.
                return is_string($v) && (self::isColourToken(trim($v)) || (StyleValueGuard::isColor($v) && !StyleValueGuard::isToken(trim($v))));
            case 'tracks':
                return self::isTracks($v);
            case 'span':
                return $v === 'full' || (is_int($v) && $v >= 1 && $v <= 12);
            case 'gradient':
                return StyleValueGuard::isGradient($v);
            case 'opacity':
                return is_int($v) && $v >= 0 && $v <= 100;
            case 'maxlen':
                return $v === 'none' || self::isMeasure($v, false);
        }
        return false;
    }

    /**
     * Grid column sizes: 1-12 tracks separated by spaces, each `auto`, `min-content`, `max-content`, `<n>fr`, a plain
     * length (px, rem, em, %, vw) or `minmax(a,b)` of two of those. A closed grammar, so nothing else reaches the CSS.
     */
    public static function isTracks(mixed $v): bool
    {
        if (!is_string($v) || strlen($v) > 200) {
            return false;
        }
        $one = '(?:auto|min-content|max-content|0|(?:\d{1,4}(?:\.\d{1,3})?|\.\d{1,3})(?:fr|px|rem|em|%|vw))';
        $track = '(?:' . $one . '|minmax\(\s*' . $one . '\s*,\s*' . $one . '\s*\))';
        $tracks = preg_split('/\s+(?![^(]*\))/', trim($v)) ?: [];
        if ($tracks === [] || count($tracks) > 12) {
            return false;
        }
        foreach ($tracks as $t) {
            if (preg_match('/^' . $track . '$/', $t) !== 1) {
                return false;
            }
        }
        return true;
    }

    /** The token categories that hold a colour. */
    private const COLOUR_TOKEN_CATEGORIES = ['surface', 'text', 'color', 'border'];

    /** A theme token that holds a colour (`color.accent`, `text.muted`, `border.default`...). */
    public static function isColourToken(string $v): bool
    {
        return StyleValueGuard::isToken($v) && in_array(explode('.', $v, 2)[0], self::COLOUR_TOKEN_CATEGORIES, true);
    }

    /** What goes in the CSS for a colour field: the theme's custom property for a token, the colour as written otherwise. */
    public static function colourCss(string $v): string
    {
        $v = trim($v);
        // The name is ResolvedTheme::cssVarName's (a unit test keeps the two the same); this layer does not depend on the renderer.
        return self::isColourToken($v) ? 'var(--sb-' . str_replace('.', '-', $v) . ')' : $v;
    }

    /** A size that is never negative (px, %, rem, vw...), as padding takes it: a section's custom width. */
    public static function isSize(mixed $v): bool
    {
        return self::fieldAccepts($v, ['padlen', '']);
    }

    /** A length with no CSS-wide keywords; `auto` only where the property takes it. */
    private static function isMeasure(mixed $v, bool $allowAuto): bool
    {
        // Strings only, and a unit unless it is zero: `12` is not a CSS length, so
        // it would be stored, shown as accepted, and silently ignored by browsers.
        if (!is_string($v)) {
            return false;
        }
        $bare = trim($v);
        if (preg_match('/^-?(\d+\.?\d*|\.\d+)$/', $bare) === 1 && (float) $bare !== 0.0) {
            return false;
        }
        if (is_string($v) && strtolower(trim($v)) === 'auto') {
            return $allowAuto;
        }
        if (is_string($v) && in_array(strtolower(trim($v)), ['none', 'inherit', 'initial', 'unset', 'fit-content', 'min-content', 'max-content'], true)) {
            return false;
        }
        return StyleValueGuard::isLength($v);
    }

    /**
     * `shadow` as an object: {x, y, blur, spread, color, inset}. Required: x, y, color.
     *
     * @param array<string, mixed> $sh
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function checkShadowObject(array $sh, string $path, array &$errors): void
    {
        $defs = self::moreFields()['shadow'];
        foreach ($sh as $field => $v) {
            if ($field === 'inset') {
                if (!is_bool($v)) {
                    $errors[] = self::issue("{$path}.inset", 'inset must be true or false.');
                }
            } elseif (!isset($defs[(string) $field])) {
                $errors[] = self::issue("{$path}.{$field}", "Unknown field '{$field}'.");
            } else {
                self::checkField($v, $defs[(string) $field], "{$path}.{$field}", $errors);
            }
        }
        foreach (['x', 'y', 'color'] as $required) {
            if (!array_key_exists($required, $sh)) {
                $errors[] = self::issue("{$path}.{$required}", "A custom shadow needs '{$required}'.");
            }
        }
    }

    /**
     * A text shadow `{x, y, blur?, color}`: x, y and the colour are required, nothing else is accepted.
     *
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function checkTextShadow(mixed $ts, string $path, array &$errors): void
    {
        if (!is_array($ts) || ($ts !== [] && array_is_list($ts))) {
            $errors[] = self::issue($path, 'A text shadow must be an object {x, y, blur, color}.');
            return;
        }
        self::checkObject($ts, self::moreFields()['text_shadow'], $path, $errors);
        foreach (['x', 'y', 'color'] as $required) {
            if (!array_key_exists($required, $ts)) {
                $errors[] = self::issue("{$path}.{$required}", "A text shadow needs '{$required}'.");
            }
        }
    }

    /** `text-shadow` for a valid text shadow, or null. @param array<string, mixed> $ts */
    private static function textShadowValue(array $ts): ?string
    {
        $defs = self::moreFields()['text_shadow'];
        foreach (['x', 'y', 'color'] as $required) {
            if (!array_key_exists($required, $ts) || !self::fieldAccepts($ts[$required], $defs[$required])) {
                return null;
            }
        }
        $parts = [trim((string) $ts['x']), trim((string) $ts['y'])];
        if (array_key_exists('blur', $ts) && self::fieldAccepts($ts['blur'], $defs['blur'])) {
            $parts[] = trim((string) $ts['blur']);
        }
        $parts[] = self::colourCss((string) $ts['color']);
        return implode(' ', $parts);
    }

    /**
     * A Container's background overlay `{color | gradient, opacity?, blend?}`, a layer between its background and its
     * children. The base style needs a colour (a literal or a theme colour) or a gradient, not both; a state may change
     * any part, and takes a literal colour, as every state colour does.
     *
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function checkOverlay(mixed $ov, string $path, array &$errors, bool $state): void
    {
        if (!is_array($ov) || ($ov !== [] && array_is_list($ov))) {
            $errors[] = self::issue($path, 'An overlay must be an object {color, gradient, opacity, blend}.');
            return;
        }
        foreach ($ov as $key => $value) {
            $p = "{$path}.{$key}";
            switch ((string) $key) {
                case 'color':
                    if (!self::fieldAccepts($value, ['tcolor', 'color'])) {
                        $errors[] = self::issue($p, 'Invalid overlay colour.');
                    }
                    break;
                case 'gradient':
                    if (!StyleValueGuard::isGradient($value)) {
                        $errors[] = self::issue($p, 'Invalid overlay gradient.');
                    }
                    break;
                case 'opacity':
                    if (!self::inRange($value, 0, 1)) {
                        $errors[] = self::issue($p, 'opacity must be a number from 0 to 1.');
                    }
                    break;
                case 'blend':
                    if (!is_string($value) || !in_array($value, self::BLENDS, true)) {
                        $errors[] = self::issue($p, 'Unknown blend mode.');
                    }
                    break;
                default:
                    $errors[] = self::issue($p, "An overlay cannot set '{$key}'.");
            }
        }
        if (isset($ov['color'], $ov['gradient'])) {
            $errors[] = self::issue("{$path}.gradient", 'Choose a colour or a gradient for the overlay, not both.');
        }
        if (!$state && !isset($ov['color']) && !isset($ov['gradient'])) {
            $errors[] = self::issue("{$path}.color", 'An overlay needs a colour or a gradient.');
        }
    }

    /**
     * The overlay as custom properties the stylesheet's Container layer reads (`.sb-block--core-container::before`).
     *
     * @param array<string, mixed> $ov
     * @return list<string>
     */
    private static function overlayDeclarations(array $ov, bool $state): array
    {
        $out = [];
        $paint = null;
        if (isset($ov['color']) && self::fieldAccepts($ov['color'], ['tcolor', 'color'])) {
            $paint = self::colourCss(trim((string) $ov['color']));
        } elseif (isset($ov['gradient']) && StyleValueGuard::isGradient($ov['gradient'])) {
            $paint = trim((string) $ov['gradient']);
        }
        if ($paint !== null) {
            $out[] = '--sb-ov-bg:' . $paint;
        }
        if (isset($ov['opacity']) && self::inRange($ov['opacity'], 0, 1)) {
            $out[] = '--sb-ov-op:' . self::num($ov['opacity']);
        }
        if (isset($ov['blend']) && is_string($ov['blend']) && in_array($ov['blend'], self::BLENDS, true)) {
            $out[] = '--sb-ov-blend:' . $ov['blend'];
        }
        if ($out !== [] && ($state || $paint !== null)) {
            array_unshift($out, '--sb-ov-display:block');
        } else {
            $out = [];
        }
        return $out;
    }

    /** @param array<string, mixed> $sh */
    private static function shadowValue(array $sh): ?string
    {
        $defs = self::moreFields()['shadow'];
        foreach (['x', 'y', 'color'] as $required) {
            if (!array_key_exists($required, $sh) || !self::fieldAccepts($sh[$required], $defs[$required])) {
                return null;
            }
        }
        $parts = [];
        if (($sh['inset'] ?? false) === true) {
            $parts[] = 'inset';
        }
        foreach (['x', 'y', 'blur', 'spread'] as $f) {
            if (array_key_exists($f, $sh) && self::fieldAccepts($sh[$f], $defs[$f])) {
                $parts[] = trim((string) $sh[$f]);
            } elseif ($f === 'blur' || $f === 'spread') {
                if (array_key_exists('spread', $sh) && $f === 'blur') {
                    $parts[] = '0';
                }
            }
        }
        $parts[] = self::colourCss((string) $sh['color']);
        return implode(' ', $parts);
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function checkEffects(mixed $fx, string $path, array &$errors): void
    {
        if (!is_array($fx) || ($fx !== [] && array_is_list($fx))) {
            $errors[] = self::issue($path, 'effects must be an object.');
            return;
        }
        foreach ($fx as $key => $value) {
            $p = "{$path}.{$key}";
            switch ((string) $key) {
                case 'transform':
                    $flips = is_array($value) ? array_intersect_key($value, self::FLIPS) : [];
                    foreach ($flips as $flip => $on) {
                        if (!is_bool($on)) {
                            $errors[] = self::issue("{$p}.{$flip}", 'Must be true or false.');
                        }
                    }
                    self::checkNumberGroup(is_array($value) ? array_diff_key($value, self::FLIPS) : $value, self::TRANSFORM + ['translate_x' => null, 'translate_y' => null], $p, $errors, true);
                    break;
                case 'filter':
                    self::checkNumberGroup($value, self::FILTER, $p, $errors, false);
                    break;
                case 'backdrop_blur':
                    if (!self::inRange($value, 0, 50)) {
                        $errors[] = self::issue($p, 'backdrop_blur must be a number from 0 to 50.');
                    }
                    break;
                case 'blend':
                    if (!is_string($value) || !in_array($value, self::BLENDS, true)) {
                        $errors[] = self::issue($p, 'Unknown blend mode.');
                    }
                    break;
                case 'cursor':
                    if (!is_string($value) || !in_array($value, self::CURSORS, true)) {
                        $errors[] = self::issue($p, 'Unknown cursor.');
                    }
                    break;
                case 'transition':
                    if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                        $errors[] = self::issue($p, 'transition must be an object.');
                        break;
                    }
                    foreach ($value as $tk => $tv) {
                        if ($tk === 'duration_ms' && is_int($tv) && $tv >= 0 && $tv <= 4000) {
                            continue;
                        }
                        if ($tk === 'easing' && is_string($tv) && isset(self::EASINGS[$tv])) {
                            continue;
                        }
                        $errors[] = self::issue("{$p}.{$tk}", 'Invalid transition field.');
                    }
                    break;
                default:
                    $errors[] = self::issue($p, "Unknown effects field '{$key}'.");
            }
        }
    }

    /**
     * @param array<string, mixed> $ranges field => [min, max, unit] (null = a length)
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function checkNumberGroup(mixed $value, array $ranges, string $path, array &$errors, bool $allowLengths): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors[] = self::issue($path, "{$path} must be an object.");
            return;
        }
        foreach ($value as $field => $v) {
            // A null entry means "a length", so absence is tested by key, not by `??`.
            $range = array_key_exists((string) $field, $ranges) ? $ranges[(string) $field] : false;
            if ($range === false) {
                $errors[] = self::issue("{$path}.{$field}", "Unknown field '{$field}'.");
            } elseif ($range === null) {
                if (!$allowLengths || !self::isMeasure($v, false)) {
                    $errors[] = self::issue("{$path}.{$field}", 'Invalid length.');
                }
            } elseif (!self::inRange($v, $range[0], $range[1])) {
                $errors[] = self::issue("{$path}.{$field}", "Must be a number from {$range[0]} to {$range[1]}.");
            }
        }
    }

    private static function inRange(mixed $v, int|float $min, int|float $max): bool
    {
        return (is_int($v) || is_float($v)) && is_finite((float) $v) && $v >= $min && $v <= $max;
    }

    /** @return array{path: string, code: string, message: string} */
    private static function issue(string $path, string $message): array
    {
        return ['path' => $path, 'code' => 'invalid_style_value', 'message' => $message];
    }

    // ── emission ──────────────────────────────────────────────────────────────

    /**
     * The declarations (without braces) for the B2-P3b keys of a block `style`,
     * or '' when there are none. Every value that fails validation is skipped,
     * so a stored document can never write anything the table does not define.
     *
     * @param array<string, mixed> $style
     */
    public static function declarations(array $style, ?string $backgroundUrl = null, bool $parallax = false): string
    {
        $out = [];
        foreach (['layout', 'position'] as $key) {
            if (isset($style[$key]) && is_array($style[$key])) {
                self::emitFields($style[$key], self::fields()[$key], $out);
            }
        }
        $more = self::moreFields();
        foreach (['margin', 'padding'] as $key) {
            if (isset($style[$key]) && is_array($style[$key])) {
                self::emitFields($style[$key], $more[$key], $out);
            }
        }
        self::emitBoxed($style, $out);
        if (isset($style['dimensions']) && is_array($style['dimensions'])) {
            self::emitFields($style['dimensions'], self::fields()['dimensions'], $out);
        }
        self::emitBackgroundImage($style, $backgroundUrl, $out, $parallax);
        if (isset($style['border']) && is_array($style['border'])) {
            foreach (self::SIDES as $side) {
                if (isset($style['border'][$side]) && is_array($style['border'][$side])) {
                    self::emitFields($style['border'][$side], $more['border'][$side], $out);
                }
            }
            if (isset($style['border']['radius_corners']) && is_array($style['border']['radius_corners'])) {
                self::emitFields($style['border']['radius_corners'], $more['corners'], $out);
            }
        }
        if (isset($style['typography']) && is_array($style['typography'])) {
            self::emitFields($style['typography'], $more['typography'], $out);
        }
        if (isset($style['shadow']) && is_array($style['shadow'])) {
            $shadow = self::shadowValue($style['shadow']);
            if ($shadow !== null) {
                $out[] = 'box-shadow:' . $shadow;
            }
        }
        if (isset($style['text_shadow']) && is_array($style['text_shadow'])) {
            $textShadow = self::textShadowValue($style['text_shadow']);
            if ($textShadow !== null) {
                $out[] = 'text-shadow:' . $textShadow;
            }
        }
        if (isset($style['overlay']) && is_array($style['overlay'])) {
            $overlay = self::overlayDeclarations($style['overlay'], false);
            if ($overlay !== []) {
                // The layer is absolutely placed under the children: the block is its own stacking context, and its box
                // anchors the layer unless the author already positioned it.
                array_push($out, ...$overlay);
                $out[] = 'isolation:isolate';
                $mode = is_array($style['position'] ?? null) ? ($style['position']['mode'] ?? null) : null;
                if ($mode === null || $mode === 'static') {
                    $out[] = 'position:relative';
                }
            }
        }
        if (isset($style['effects']) && is_array($style['effects'])) {
            self::emitEffects($style['effects'], $out);
        }
        return implode(';', $out);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, array<int, mixed>> $defs
     * @param list<string> $out
     */
    private static function emitFields(array $values, array $defs, array &$out): void
    {
        // Emit in table order, not document order, so the CSS is deterministic.
        foreach ($defs as $field => $def) {
            if ($def[1] === '' || !array_key_exists($field, $values) || !self::fieldAccepts($values[$field], $def)) {
                continue;
            }
            $v = $values[$field];
            $css = match ($def[0]) {
                'map'  => $def[2][$v],
                'int'  => isset($def[4]) ? sprintf($def[4], $v) : (string) $v,
                'length' => trim((string) $v),
                'tcolor' => self::colourCss((string) $v),
                'tracks' => (string) preg_replace('/\s+/', ' ', preg_replace(['/\s*([(,])\s*/', '/\s*\)/'], ['$1', ')'], trim((string) $v)) ?? ''),
                'span'   => $v === 'full' ? '1/-1' : 'span ' . $v,
                'opacity' => self::num($v / 100),
                default => trim((string) $v),
            };
            $out[] = $def[1] . ':' . $css;
        }
    }

    /**
     * @param array<string, mixed> $fx
     * @param list<string> $out
     */
    private static function emitEffects(array $fx, array &$out): void
    {
        $t = is_array($fx['transform'] ?? null) ? $fx['transform'] : [];
        $parts = [];
        foreach (['translate_x', 'translate_y'] as $axis) {
            if (isset($t[$axis]) && self::isMeasure($t[$axis], false)) {
                $parts['t'][$axis] = trim((string) $t[$axis]);
            }
        }
        $tx = $parts['t']['translate_x'] ?? null;
        $ty = $parts['t']['translate_y'] ?? null;
        $fn = [];
        if ($tx !== null || $ty !== null) {
            $fn[] = 'translate(' . ($tx ?? '0') . ',' . ($ty ?? '0') . ')';
        }
        if (isset($t['rotate']) && self::inRange($t['rotate'], -360, 360)) {
            $fn[] = 'rotate(' . self::num($t['rotate']) . 'deg)';
        }
        if (isset($t['scale']) && self::inRange($t['scale'], 0, 5)) {
            $fn[] = 'scale(' . self::num($t['scale']) . ')';
        }
        $sx = isset($t['skew_x']) && self::inRange($t['skew_x'], -90, 90) ? self::num($t['skew_x']) : null;
        $sy = isset($t['skew_y']) && self::inRange($t['skew_y'], -90, 90) ? self::num($t['skew_y']) : null;
        if ($sx !== null || $sy !== null) {
            $fn[] = 'skew(' . ($sx ?? '0') . 'deg,' . ($sy ?? '0') . 'deg)';
        }
        foreach (self::FLIPS as $flip => $css) {
            if (($t[$flip] ?? null) === true) {
                $fn[] = $css;
            }
        }
        if ($fn !== []) {
            $out[] = 'transform:' . implode(' ', $fn);
        }

        $f = is_array($fx['filter'] ?? null) ? $fx['filter'] : [];
        $filters = [];
        foreach (self::FILTER as $name => [$min, $max, $unit]) {
            if (isset($f[$name]) && self::inRange($f[$name], $min, $max)) {
                $filters[] = $name . '(' . self::num($f[$name]) . $unit . ')';
            }
        }
        if ($filters !== []) {
            $out[] = 'filter:' . implode(' ', $filters);
        }
        if (isset($fx['backdrop_blur']) && self::inRange($fx['backdrop_blur'], 0, 50)) {
            $b = 'blur(' . self::num($fx['backdrop_blur']) . 'px)';
            $out[] = '-webkit-backdrop-filter:' . $b;
            $out[] = 'backdrop-filter:' . $b;
        }
        if (isset($fx['blend']) && is_string($fx['blend']) && in_array($fx['blend'], self::BLENDS, true)) {
            $out[] = 'mix-blend-mode:' . $fx['blend'];
        }
        $tr = is_array($fx['transition'] ?? null) ? $fx['transition'] : [];
        if ($tr !== []) {
            $ms = isset($tr['duration_ms']) && is_int($tr['duration_ms']) && $tr['duration_ms'] >= 0 && $tr['duration_ms'] <= 4000 ? $tr['duration_ms'] : 200;
            $ease = isset($tr['easing']) && is_string($tr['easing']) && isset(self::EASINGS[$tr['easing']]) ? self::EASINGS[$tr['easing']] : 'ease';
            $out[] = 'transition:all ' . $ms . 'ms ' . $ease;
        }
        if (isset($fx['cursor']) && is_string($fx['cursor']) && in_array($fx['cursor'], self::CURSORS, true)) {
            $out[] = 'cursor:' . $fx['cursor'];
        }
    }

    /** Server-formatted number: no exponent, at most 3 decimals, no trailing zeros. */
    private static function num(int|float $n): string
    {
        $s = rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
        return $s === '' || $s === '-0' ? '0' : $s;
    }

    // ── section style ─────────────────────────────────────────────────────────

    /**
     * Issues for a section's `style`: {background, padding, border}.
     *
     * @return list<array{path: string, code: string, message: string}>
     */
    public static function sectionIssues(mixed $style, string $path): array
    {
        if (!is_array($style) || ($style !== [] && array_is_list($style))) {
            return [self::issue($path, 'A section style must be an object.')];
        }
        $errors = [];
        foreach ($style as $key => $value) {
            $p = "{$path}.{$key}";
            if ($key === 'padding') {
                self::checkObject($value, self::moreFields()['padding'], $p, $errors);
            } elseif ($key === 'border') {
                self::sectionBorderIssues($value, $p, $errors);
            } elseif ($key === 'background') {
                if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                    $errors[] = self::issue($p, 'background must be an object.');
                    continue;
                }
                foreach ($value as $bgKey => $bgVal) {
                    if ($bgKey === 'color') {
                        self::checkField($bgVal, ['color', 'background-color'], "{$p}.color", $errors);
                    } elseif ($bgKey === 'gradient') {
                        if (!is_string($bgVal) || !StyleValueGuard::isGradient($bgVal)) {
                            $errors[] = self::issue("{$p}.gradient", 'Invalid gradient.');
                        }
                    } elseif (!in_array($bgKey, ['image', 'fit', 'repeat', 'position', 'overlay', 'attachment'], true)) {
                        $errors[] = self::issue("{$p}.{$bgKey}", "Unknown field '{$bgKey}'.");
                    }
                }
                // A section image must be a media_ref; a string here is not tolerated (the section style is new).
                if (isset($value['image']) && !is_array($value['image'])) {
                    $errors[] = self::issue("{$p}.image", 'A background image must be a media reference.');
                }
                array_push($errors, ...self::backgroundIssues($value, $p));
            } else {
                $errors[] = self::issue($p, "A section style cannot set '{$key}'.");
            }
        }
        return $errors;
    }

    /**
     * Declarations for a section's `style` (the background image URL is resolved by the caller).
     *
     * @param array<string, mixed> $style
     */
    public static function sectionDeclarations(array $style, ?string $backgroundUrl = null, bool $parallax = false): string
    {
        $out = [];
        $bg  = is_array($style['background'] ?? null) ? $style['background'] : [];
        if (isset($bg['color']) && self::fieldAccepts($bg['color'], ['color', 'background-color'])) {
            $out[] = 'background-color:' . trim((string) $bg['color']);
        }
        if (isset($bg['gradient']) && !is_array($bg['image'] ?? null) && StyleValueGuard::isGradient($bg['gradient'])) {
            $out[] = 'background-image:' . trim((string) $bg['gradient']);
        }
        self::emitBackgroundImage($style, $backgroundUrl, $out, $parallax);
        if (isset($style['border']) && is_array($style['border'])) {
            self::emitSectionBorder($style['border'], $out);
        }
        if (isset($style['padding']) && is_array($style['padding'])) {
            // `!important`: padding the author set on a section in the Inspector must show even when the site's CSS
            // spaces sections with a stronger selector (`.sb-main .sb-section{padding:…}`), as for a background image.
            $padding = [];
            self::emitFields($style['padding'], self::moreFields()['padding'], $padding);
            foreach ($padding as $declaration) {
                $out[] = $declaration . ' !important';
            }
        }
        return implode(';', $out);
    }

    /**
     * A section's border: the same fields a block's border has — one style, width, colour and radius for every side,
     * then each side's width, style and colour and each corner's radius, which win over the all-sides value.
     *
     * @return array<string, array<int, mixed>>
     */
    private static function sectionBorderFields(): array
    {
        return [
            'style'  => ['enum', 'border-style', CanonicalDocumentSchema::ALLOWED_BORDER_STYLES],
            'width'  => ['length', 'border-width'],
            'color'  => ['tcolor', 'border-color'],
            // The radius presets, as the block classes (.sb-radius-*) write them.
            'radius' => ['map', 'border-radius', [
                'none' => '0', 'sm' => 'var(--sb-radius-sm,.25rem)', 'md' => 'var(--sb-radius-md,.5rem)', 'lg' => 'var(--sb-radius-lg,1rem)',
                'xl' => '1.5rem', '2xl' => '2rem', 'full' => 'var(--sb-radius-full,9999px)',
            ]],
        ];
    }

    /** @param list<array{path: string, code: string, message: string}> $errors */
    private static function sectionBorderIssues(mixed $border, string $path, array &$errors): void
    {
        if (!is_array($border) || ($border !== [] && array_is_list($border))) {
            $errors[] = self::issue($path, 'border must be an object.');
            return;
        }
        $all = self::sectionBorderFields();
        $more = self::moreFields();
        foreach ($border as $field => $v) {
            $p = "{$path}.{$field}";
            if (isset($all[$field])) {
                self::checkField($v, $all[$field], $p, $errors);
            } elseif (isset($more['border'][$field])) {
                self::checkObject($v, $more['border'][$field], $p, $errors);
            } elseif ($field === 'radius_corners') {
                self::checkObject($v, $more['corners'], $p, $errors);
            } else {
                $errors[] = self::issue($p, "Unknown field '{$field}'.");
            }
        }
    }

    /**
     * @param array<string, mixed> $border
     * @param list<string> $out
     */
    private static function emitSectionBorder(array $border, array &$out): void
    {
        // A width with no style anywhere would draw nothing (the CSS default is none): it means a solid line.
        $styled = isset($border['style']);
        $wide = isset($border['width']);
        foreach (self::SIDES as $side) {
            $styled = $styled || isset($border[$side]['style']);
            $wide = $wide || isset($border[$side]['width']);
        }
        if ($wide && !$styled) {
            $out[] = 'border-style:solid';
        }
        self::emitFields($border, self::sectionBorderFields(), $out);
        $more = self::moreFields();
        foreach (self::SIDES as $side) {
            if (isset($border[$side]) && is_array($border[$side])) {
                self::emitFields($border[$side], $more['border'][$side], $out);
            }
        }
        if (isset($border['radius_corners']) && is_array($border['radius_corners'])) {
            self::emitFields($border['radius_corners'], $more['corners'], $out);
        }
    }

    // ── interaction states ────────────────────────────────────────────────────

    /** Style keys a state overlay may carry (a deliberately small, safe subset). */
    public const STATE_KEYS = ['color', 'typography', 'background', 'border', 'shadow', 'opacity', 'effects', 'overlay'];

    /**
     * Issues for a block's `style_states`: {hover|focus|active|disabled: partial style}.
     *
     * @return list<array{path: string, code: string, message: string}>
     */
    public static function stateIssues(mixed $states, string $path): array
    {
        $errors = [];
        if (!is_array($states) || ($states !== [] && array_is_list($states))) {
            return [self::issue($path, 'style_states must be an object.')];
        }
        $more = self::moreFields();
        foreach ($states as $state => $partial) {
            $p = "{$path}.{$state}";
            if (!isset(self::STATE_SELECTORS[(string) $state])) {
                $errors[] = self::issue($p, "Unknown state '{$state}'; use hover, focus, active or disabled.");
                continue;
            }
            if (!is_array($partial) || ($partial !== [] && array_is_list($partial))) {
                $errors[] = self::issue($p, 'A state must be an object.');
                continue;
            }
            foreach ($partial as $key => $value) {
                $kp = "{$p}.{$key}";
                switch ((string) $key) {
                    case 'color':
                        self::checkField($value, ['tcolor', 'color'], $kp, $errors);
                        break;
                    case 'opacity':
                        if (!self::inRange($value, 0, 1)) {
                            $errors[] = self::issue($kp, 'opacity must be a number from 0 to 1.');
                        }
                        break;
                    case 'shadow':
                        if (is_array($value)) {
                            self::checkShadowObject($value, $kp, $errors);
                        } elseif (!is_string($value) || !StyleValueGuard::isShadow($value)) {
                            $errors[] = self::issue($kp, 'Invalid shadow.');
                        }
                        break;
                    case 'background':
                        self::checkLimitedObject($value, ['color' => ['tcolor', 'background-color'], 'gradient' => ['gradient']], $kp, $errors);
                        break;
                    case 'border':
                        self::checkLimitedObject($value, ['color' => ['tcolor', 'border-color']], $kp, $errors);
                        break;
                    case 'typography':
                        self::checkLimitedObject($value, ['color' => ['tcolor', 'color']] + $more['typography'], $kp, $errors);
                        break;
                    case 'overlay':
                        self::checkOverlay($value, $kp, $errors, true);
                        break;
                    case 'effects':
                        self::checkEffects($value, $kp, $errors);
                        foreach (['transition', 'cursor'] as $baseOnly) {
                            if (is_array($value) && array_key_exists($baseOnly, $value)) {
                                $errors[] = self::issue("{$kp}.{$baseOnly}", "'{$baseOnly}' belongs to the base style, not a state.");
                            }
                        }
                        break;
                    default:
                        $errors[] = self::issue($kp, "A state cannot set '{$key}'.");
                }
            }
        }
        return $errors;
    }

    /**
     * @param array<string, array<int, mixed>> $defs
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function checkLimitedObject(mixed $value, array $defs, string $path, array &$errors): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors[] = self::issue($path, "{$path} must be an object.");
            return;
        }
        foreach ($value as $field => $v) {
            $def = $defs[(string) $field] ?? null;
            if ($def === null) {
                $errors[] = self::issue("{$path}.{$field}", "Unknown field '{$field}'.");
            } elseif ($def[0] === 'gradient') {
                if (!is_string($v) || !StyleValueGuard::isGradient($v)) {
                    $errors[] = self::issue("{$path}.{$field}", 'Invalid gradient.');
                }
            } else {
                self::checkField($v, $def, "{$path}.{$field}", $errors);
            }
        }
    }

    /**
     * Declarations for one state's partial style ('' when nothing valid).
     *
     * @param array<string, mixed> $p
     */
    public static function stateDeclarations(array $p): string
    {
        $out = [];
        $colorDef = ['tcolor', 'color'];
        if (isset($p['color']) && self::fieldAccepts($p['color'], $colorDef)) {
            $out[] = 'color:' . self::colourCss((string) $p['color']);
        }
        $t = is_array($p['typography'] ?? null) ? $p['typography'] : [];
        if (isset($t['color']) && self::fieldAccepts($t['color'], $colorDef)) {
            $out[] = 'color:' . self::colourCss((string) $t['color']);
        }
        $bg = is_array($p['background'] ?? null) ? $p['background'] : [];
        if (isset($bg['color']) && self::fieldAccepts($bg['color'], $colorDef)) {
            $out[] = 'background-color:' . self::colourCss((string) $bg['color']);
        }
        if (isset($bg['gradient']) && StyleValueGuard::isGradient($bg['gradient'])) {
            $out[] = 'background-image:' . trim((string) $bg['gradient']);
        }
        $bd = is_array($p['border'] ?? null) ? $p['border'] : [];
        if (isset($bd['color']) && self::fieldAccepts($bd['color'], $colorDef)) {
            $out[] = 'border-color:' . self::colourCss((string) $bd['color']);
        }
        if (isset($p['shadow']) && is_string($p['shadow']) && StyleValueGuard::isShadow($p['shadow'])) {
            $out[] = 'box-shadow:' . trim($p['shadow']);
        }
        if (isset($p['opacity']) && self::inRange($p['opacity'], 0, 1)) {
            $out[] = 'opacity:' . self::num($p['opacity']);
        }
        if (isset($p['overlay']) && is_array($p['overlay'])) {
            array_push($out, ...self::overlayDeclarations($p['overlay'], true));
        }
        // Typography extras, a custom shadow object and effects reuse the base emitter.
        $reuse = [];
        if ($t !== []) {
            $reuse['typography'] = array_intersect_key($t, array_flip(self::TYPOGRAPHY_EXTRAS));
        }
        if (isset($p['shadow']) && is_array($p['shadow'])) {
            $reuse['shadow'] = $p['shadow'];
        }
        if (isset($p['effects']) && is_array($p['effects'])) {
            $reuse['effects'] = array_diff_key($p['effects'], ['transition' => 1, 'cursor' => 1]);
        }
        $rest = self::declarations($reuse);
        if ($rest !== '') {
            $out[] = $rest;
        }
        return implode(';', $out);
    }

    /**
     * state name => declarations, only for states that produce something.
     *
     * @param array<string, mixed> $states
     * @return array<string, string>
     */
    public static function stateRules(array $states): array
    {
        $rules = [];
        foreach (self::STATE_SELECTORS as $name => $selector) {
            if (isset($states[$name]) && is_array($states[$name])) {
                $decl = self::stateDeclarations($states[$name]);
                if ($decl !== '') {
                    // A base colour, background or opacity may be inline on the element; a state value has to
                    // beat it to show at all, and inline style loses only to !important.
                    $rules[$name] = implode(';', array_map(static fn(string $d): string => $d . ' !important', array_filter(explode(';', $decl), static fn(string $d): bool => trim($d) !== '')));
                }
            }
        }
        return $rules;
    }

    /**
     * Split a hover's declarations into what always applies and the `transform` that belongs under HOVER_MOTION.
     *
     * @return array{0: string, 1: string} rest, transform
     */
    public static function splitHoverMotion(string $declarations): array
    {
        $parts = explode(';', $declarations);
        $motion = array_filter($parts, static fn(string $d): bool => str_starts_with($d, 'transform:'));
        return [implode(';', array_diff_key($parts, $motion)), implode(';', $motion)];
    }

    /**
     * A block lifted on hover can slide out from under a still pointer, drop back and lift again. A clear margin as wide
     * as the move, painted behind the content, keeps the pointer on it. Blank when the transform only scales or turns.
     */
    public static function hoverBridge(string $motion): string
    {
        if (preg_match('/translate\(([-\d.]+)(?:px)?,([-\d.]+)(?:px)?\)/', $motion, $m) !== 1) {
            return '';
        }
        $room = max(abs((float) $m[1]), abs((float) $m[2]));
        return $room > 0 ? 'content:"";position:absolute;inset:-' . $room . 'px;z-index:-1' : '';
    }

    // ── parts (BlockParts) ────────────────────────────────────────────────────

    /**
     * What one part of a widget may set: its text (colour, font, size, weight, line height, letter spacing, case, style
     * and decoration), its background (a colour or a gradient), border, corners, padding, margin, shadow, size, its own
     * flex or grid layout, opacity and effects (move, rotate, scale, filters, transition, cursor). A closed table, like the rest.
     *
     * `layout.display` may be `none` here, unlike a block's own style: a part is inside a widget, where the visibility
     * control does not reach (the Read more button of a Query Loop card, say).
     *
     * @return array<string, array<string, array<int, mixed>>|array<int, mixed>>
     */
    private static function partFields(): array
    {
        $more = self::moreFields();
        $layout = self::fields()['layout'];
        return [
            'color'      => ['tcolor', 'color'],
            'typography' => [
                'family'         => ['map', 'font-family', ['heading' => 'var(--sb-font-heading)', 'body' => 'var(--sb-font-body)']],
                'size'           => ['length', 'font-size'],
                'weight'         => ['map', 'font-weight', ['normal' => '400', 'medium' => '500', 'semibold' => '600', 'bold' => '700', 'extrabold' => '800']],
                'line_height'    => ['length', 'line-height'],
                'letter_spacing' => ['length', 'letter-spacing', true],
                'transform'      => ['enum', 'text-transform', ['none', 'uppercase', 'lowercase', 'capitalize']],
                'style'          => $more['typography']['style'],
                'decoration'     => $more['typography']['decoration'],
            ],
            'background' => ['color' => ['tcolor', 'background-color'], 'gradient' => ['gradient', 'background-image']],
            'border'     => [
                'width'  => ['length', 'border-width'],
                'style'  => ['enum', 'border-style', CanonicalDocumentSchema::ALLOWED_BORDER_STYLES],
                'color'  => ['tcolor', 'border-color'],
                'radius' => ['length', 'border-radius'],
                // Which sides the width is drawn on (a line under each menu link, say); written by partDeclarations().
                'sides'  => ['enum', '', array_keys(self::BORDER_SIDES)],
            ],
            // A part placed against its widget (the close button in a drawer's corner). Never `fixed`, as for a block.
            'position'   => self::fields()['position'],
            'padding'    => $more['padding'],
            'margin'     => $more['margin'],
            'shadow'     => $more['shadow'],
            'size'       => [
                'width'      => ['length', 'width', true],
                'height'     => ['length', 'height', true],
                'min_width'  => ['length', 'min-width', true],
                'min_height' => ['length', 'min-height', true],
                // `none` lifts a cap the stylesheet sets (a drawer's panel is held to 85vw by default).
                'max_width'  => ['maxlen', 'max-width'],
                'max_height' => ['maxlen', 'max-height'],
                'object_fit' => ['enum', 'object-fit', ['cover', 'contain', 'fill', 'none', 'scale-down']],
                'overflow'   => ['enum', 'overflow', ['visible', 'hidden', 'auto']],
            ],
            'layout'     => [
                'display'     => ['enum', 'display', ['block', 'flex', 'grid', 'inline-flex', 'inline-block', 'none']],
                'direction'   => $layout['direction'],
                'wrap'        => $layout['wrap'],
                'justify'     => $layout['justify'],
                'align'       => $layout['align'],
                'gap'         => $layout['gap'],
                'row_gap'     => $layout['row_gap'],
                'column_gap'  => $layout['column_gap'],
                'columns'     => $layout['columns'],
                'tracks'      => $layout['tracks'],
            ],
            'opacity'    => ['opacity', 'opacity'],
            // Validated and written by checkEffects()/emitEffects(), as a block's own effects are.
            'effects'    => [],
        ];
    }

    /** `border.sides` of a part => the `border-width` it writes, with W standing for the width. */
    private const BORDER_SIDES = ['all' => 'W', 'top' => 'W 0 0 0', 'right' => '0 W 0 0', 'bottom' => '0 0 W 0', 'left' => '0 0 0 W', 'top_bottom' => 'W 0', 'left_right' => '0 W'];

    /**
     * The subset a part may set per device (`block.responsive.<tablet|mobile>.style_parts`): font size, padding, margin,
     * size, corners and layout.
     *
     * @return array<string, array<string, array<int, mixed>>>
     */
    private static function responsivePartFields(): array
    {
        $all = self::partFields();
        return [
            'typography' => ['size' => $all['typography']['size']],
            'border'     => ['radius' => $all['border']['radius']],
            'padding'    => $all['padding'],
            'margin'     => $all['margin'],
            'size'       => $all['size'],
            'layout'     => $all['layout'],
        ];
    }

    /**
     * Issues for `block.style_parts`: keys are a part of the block's type (BlockParts), alone or with a state
     * (`title:hover`); each value is a partial style from partFields().
     *
     * @return list<array{path: string, code: string, message: string}>
     */
    public static function partIssues(mixed $parts, string $type, string $path): array
    {
        return self::checkParts($parts, $type, $path, self::partFields(), true);
    }

    /**
     * Issues for one device's part overrides (`block.responsive.<device>.style_parts`): parts without a state, each a
     * partial style from responsivePartFields().
     *
     * @return list<array{path: string, code: string, message: string}>
     */
    public static function responsivePartIssues(mixed $parts, string $type, string $path): array
    {
        return self::checkParts($parts, $type, $path, self::responsivePartFields(), false);
    }

    /**
     * @param array<string, mixed> $defs
     * @return list<array{path: string, code: string, message: string}>
     */
    private static function checkParts(mixed $parts, string $type, string $path, array $defs, bool $states): array
    {
        if (!is_array($parts) || ($parts !== [] && array_is_list($parts))) {
            return [self::issue($path, 'style_parts must be an object.')];
        }
        $known = BlockParts::for($type);
        $errors = [];
        foreach ($parts as $key => $partial) {
            $p = "{$path}.{$key}";
            [$part, $state] = array_pad(explode(':', (string) $key, 2), 2, null);
            if (!isset($known[$part]) || ($state !== null && (!$states || !isset(BlockParts::STATES[$state]) || !BlockParts::takesStates($known[$part])))) {
                $errors[] = self::issue($p, "'{$key}' is not a part of {$type}.");
                continue;
            }
            if (!is_array($partial) || ($partial !== [] && array_is_list($partial))) {
                $errors[] = self::issue($p, 'A part style must be an object.');
                continue;
            }
            foreach ($partial as $group => $value) {
                $def = $defs[(string) $group] ?? null;
                if ($def === null) {
                    $errors[] = self::issue("{$p}.{$group}", "A part cannot set '{$group}'.");
                } elseif ($group === 'effects') {
                    self::checkEffects($value, "{$p}.{$group}", $errors);
                } elseif (array_is_list($def)) {
                    self::checkField($value, $def, "{$p}.{$group}", $errors);
                } else {
                    self::checkObject($value, $def, "{$p}.{$group}", $errors);
                }
            }
        }
        return $errors;
    }

    /**
     * The rules of `block.style_parts` (and of each device's `responsive.<device>.style_parts`), for
     * RenderCollector::scopedRule(): each is the part's selectors (with the state's pseudo-class), its declarations and,
     * for a device, the media query it applies under. `!important`, because the base stylesheet makes a widget's inner
     * text follow the widget's own typography with `inherit !important`, and an author's part style has to win over that.
     *
     * @param array<string, mixed> $parts
     * @param array<string, mixed> $responsive block.responsive
     * @return list<array{selectors: list<string>, declarations: string, media?: string, portal?: bool}>
     */
    public static function partRules(array $parts, string $type, array $responsive = []): array
    {
        $known = BlockParts::for($type);
        $rules = [];
        // Base styles first, then states, then devices, so a hover wins over the base and a phone over both at equal weight.
        foreach ([null, ...array_keys(BlockParts::STATES)] as $state) {
            foreach ($known as $part => $definition) {
                if ($state !== null && !BlockParts::takesStates($definition)) {
                    continue;
                }
                $partial = $parts[$state === null ? $part : $part . ':' . $state] ?? null;
                $out = is_array($partial) ? self::partDeclarations($partial, self::partFields()) : [];
                if ($out === []) {
                    continue;
                }
                $pseudo = $state === null ? '' : BlockParts::STATES[$state];
                $selectors = array_map(static fn(string $sel): string => self::withPseudo($sel, $pseudo), $definition['selectors']);
                $portal = BlockParts::isPortal($definition) ? ['portal' => true] : [];
                $declarations = implode(';', array_map(static fn(string $d): string => $d . ' !important', $out));
                [$declarations, $motion] = $state === 'hover' ? self::splitHoverMotion($declarations) : [$declarations, ''];
                if ($declarations !== '') {
                    $rules[] = ['selectors' => $selectors, 'declarations' => $declarations] + $portal;
                }
                if ($motion !== '') {
                    $rules[] = ['selectors' => $selectors, 'declarations' => $motion, 'media' => self::HOVER_MOTION] + $portal;
                    $bridge = self::hoverBridge($motion);
                    if ($bridge !== '') {
                        $rules[] = [
                            'selectors'    => array_map(static fn(string $sel): string => $sel . ':not(.sb-link--arrow):hover::after', array_values(array_filter($definition['selectors'], static fn(string $sel): bool => !str_contains($sel, '::')))),
                            'declarations' => $bridge,
                            'media'        => self::HOVER_MOTION,
                        ] + $portal;
                    }
                }
            }
        }
        foreach (self::RESPONSIVE_DEVICES as $device => $query) {
            $deviceParts = $responsive[$device]['style_parts'] ?? null;
            if (!is_array($deviceParts)) {
                continue;
            }
            foreach ($known as $part => $definition) {
                $partial = $deviceParts[$part] ?? null;
                $out = is_array($partial) ? self::partDeclarations($partial, self::responsivePartFields()) : [];
                if ($out !== []) {
                    $rules[] = [
                        'selectors'    => $definition['selectors'],
                        'declarations' => implode(';', array_map(static fn(string $d): string => $d . ' !important', $out)),
                        'media'        => $query,
                    ] + (BlockParts::isPortal($definition) ? ['portal' => true] : []);
                }
            }
        }
        return $rules;
    }

    /** A state's pseudo-class goes before a pseudo-element (`li:hover::before`), never after it. */
    private static function withPseudo(string $selector, string $pseudo): string
    {
        if ($pseudo === '') {
            return $selector;
        }
        $at = strpos($selector, '::');
        return $at === false ? $selector . $pseudo : substr($selector, 0, $at) . $pseudo . substr($selector, $at);
    }

    /**
     * @param array<string, mixed> $partial
     * @param array<string, mixed> $defs
     * @return list<string>
     */
    private static function partDeclarations(array $partial, array $defs): array
    {
        $out = [];
        foreach ($defs as $group => $def) {
            if (!array_key_exists($group, $partial)) {
                continue;
            }
            if ($group === 'effects') {
                if (is_array($partial['effects'])) {
                    self::emitEffects($partial['effects'], $out);
                }
            } elseif (array_is_list($def)) {
                self::emitFields([$group => $partial[$group]], [$group => $def], $out);
            } elseif ($group === 'shadow' && is_array($partial['shadow'])) {
                $out = array_merge($out, array_filter(explode(';', self::declarations(['shadow' => $partial['shadow']])), static fn(string $d): bool => $d !== ''));
            } elseif (is_array($partial[$group])) {
                self::emitFields($partial[$group], $def, $out);
            }
        }
        $sides = $partial['border']['sides'] ?? null;
        if (is_string($sides) && isset(self::BORDER_SIDES[$sides]) && $sides !== 'all') {
            foreach ($out as $i => $decl) {
                if (str_starts_with($decl, 'border-width:')) {
                    $out[$i] = 'border-width:' . str_replace('W', substr($decl, strlen('border-width:')), self::BORDER_SIDES[$sides]);
                }
            }
        }
        return $out;
    }

    // ── per-device overrides ──────────────────────────────────────────────────

    /**
     * Devices that may carry a style override (`block.responsive.<device>.style`), with the media query each one
     * applies under. Desktop is the block's own style, so tablet (and below) and mobile cascade downwards.
     */
    public const RESPONSIVE_DEVICES = ['tablet' => '(max-width:1023.98px)', 'mobile' => '(max-width:767.98px)'];

    /** @return array<string, array<string, array<int, mixed>>> group => field => definition, for the per-device subset. */
    private static function responsiveFields(): array
    {
        $more   = self::moreFields();
        // The display type and the boxed content width are set for every device at once.
        $layout = array_diff_key(self::fields()['layout'], ['display' => 1, 'content_width' => 1, 'content_max' => 1]);
        return [
            'typography' => ['size' => ['length', 'font-size']],
            'padding'    => $more['padding'],
            'margin'     => $more['margin'],
            'layout'     => $layout,
            // The four base sizes are inline on the element; these !important rules beat them per device.
            'dimensions' => [
                'width'      => ['length', 'width', true],
                'height'     => ['length', 'height', true],
                'min_height' => ['length', 'min-height', true],
                'max_width'  => ['length', 'max-width', true],
            ],
        ];
    }

    /**
     * Issues for one device's style override: a partial style limited to font size, padding, margin, the flex/grid layout (everything but the display type) and width, height, min-height and max-width.
     *
     * @return list<array{path: string, code: string, message: string}>
     */
    public static function responsiveIssues(mixed $style, string $path): array
    {
        if (!is_array($style) || ($style !== [] && array_is_list($style))) {
            return [self::issue($path, 'A device style must be an object.')];
        }
        $errors = [];
        $defs   = self::responsiveFields();
        foreach ($style as $group => $value) {
            if (!isset($defs[(string) $group])) {
                $errors[] = self::issue("{$path}.{$group}", "A device style cannot set '{$group}'.");
                continue;
            }
            self::checkObject($value, $defs[(string) $group], "{$path}.{$group}", $errors);
        }
        return $errors;
    }

    /**
     * query => declarations for every device that produces something, in cascade order (tablet, then mobile).
     * Each declaration is `!important` because the base font size is an inline style, which a class rule would lose to.
     *
     * @param array<string, mixed> $responsive block.responsive
     * @return array<string, string>
     */
    public static function responsiveRules(array $responsive): array
    {
        $rules = [];
        $defs  = self::responsiveFields();
        foreach (self::RESPONSIVE_DEVICES as $device => $query) {
            $style = $responsive[$device]['style'] ?? null;
            if (!is_array($style)) {
                continue;
            }
            $out = [];
            foreach ($defs as $group => $fields) {
                if (isset($style[$group]) && is_array($style[$group])) {
                    self::emitFields($style[$group], $fields, $out);
                }
            }
            if ($out !== []) {
                $rules[$query] = implode(' !important;', $out) . ' !important';
            }
        }
        return $rules;
    }

    /** True when the block's style has a transition (so a reduced-motion override is needed). */
    public static function hasTransition(array $style): bool
    {
        return isset($style['effects']['transition']) && is_array($style['effects']['transition']) && $style['effects']['transition'] !== [];
    }
}
