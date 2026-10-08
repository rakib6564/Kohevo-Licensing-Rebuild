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
 *
 * `position: fixed` is deliberately absent (it could cover the platform
 * signature); `layout.display: none` is absent too (visibility has its own control).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

use Slate\Module\StudioBuilder\Schema\FieldSchema;

final class StyleSurface
{
    /** Style keys introduced by B2-P3b, in the order their declarations are emitted. */
    public const KEYS = ['layout', 'position', 'effects', 'margin', 'padding'];

    /** Sub-fields B2-P3b adds to the existing `dimensions` object (the original four stay as they were). */
    public const DIMENSION_EXTRAS = ['min_width', 'max_height', 'aspect_ratio', 'overflow', 'object_fit', 'object_position'];

    private const JUSTIFY = ['start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end', 'between' => 'space-between', 'around' => 'space-around', 'evenly' => 'space-evenly'];
    private const ALIGN   = ['start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end', 'stretch' => 'stretch', 'baseline' => 'baseline'];
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
                'gap'       => ['length', 'gap'],
                'row_gap'   => ['length', 'row-gap'],
                'column_gap' => ['length', 'column-gap'],
                'order'     => ['int', 'order', -99, 99],
                'grow'      => ['int', 'flex-grow', 0, 10],
                'shrink'    => ['int', 'flex-shrink', 0, 10],
                'basis'     => ['length', 'flex-basis', true],
                'columns'   => ['int', 'grid-template-columns', 1, 12, 'repeat(%d,minmax(0,1fr))'],
                'rows'      => ['int', 'grid-template-rows', 1, 12, 'repeat(%d,minmax(0,1fr))'],
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
    public const TYPOGRAPHY_EXTRAS = ['style', 'decoration', 'decoration_style', 'decoration_color', 'decoration_thickness', 'decoration_offset'];

    /** Pseudo-classes an author may style, keyed by the `style_states` name. */
    public const STATE_SELECTORS = ['hover' => ':hover', 'focus' => ':focus', 'active' => ':active', 'disabled' => ':disabled'];

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
                'color' => ['color', 'border-' . $side . '-color'],
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
                'decoration_color'     => ['color', 'text-decoration-color'],
                'decoration_thickness' => ['length', 'text-decoration-thickness'],
                'decoration_offset'    => ['length', 'text-underline-offset'],
            ],
            'shadow' => [
                'x'      => ['length', 'x'],
                'y'      => ['length', 'y'],
                'blur'   => ['length', 'blur'],
                'spread' => ['length', 'spread'],
                'color'  => ['color', 'color'],
            ],
        ];
    }

    private const REPEATS = ['no-repeat', 'repeat', 'repeat-x', 'repeat-y'];
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
        if (array_key_exists('overlay', $bg)) {
            self::checkLimitedObject($bg['overlay'], ['color' => ['color', 'color']], "{$path}.overlay", $errors);
            if (is_array($bg['overlay']) && !array_key_exists('color', $bg['overlay'])) {
                $errors[] = self::issue("{$path}.overlay.color", 'An overlay needs a colour.');
            }
        }
        return $errors;
    }

    /**
     * Declarations for a background image already resolved to a safe URL.
     *
     * @param array<string, mixed> $style
     * @param list<string> $out
     */
    private static function emitBackgroundImage(array $style, ?string $url, array &$out): void
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
        $out[] = 'background-image:' . implode(',', $layers);
        $out[] = 'background-size:' . (isset($bg['fit']) && is_string($bg['fit']) && in_array($bg['fit'], self::FITS, true) ? $bg['fit'] : 'cover');
        $out[] = 'background-repeat:' . (isset($bg['repeat']) && is_string($bg['repeat']) && in_array($bg['repeat'], self::REPEATS, true) ? $bg['repeat'] : 'no-repeat');
        $position = 'center';
        $fp = $bg['image']['focal_point'] ?? null;
        if (is_array($fp) && count($fp) === 2 && self::inRange($fp[0] ?? null, 0, 1) && self::inRange($fp[1] ?? null, 0, 1)) {
            // Only computed numbers: the focal point is two floats in [0, 1].
            $position = self::num($fp[0] * 100) . '% ' . self::num($fp[1] * 100) . '%';
        } elseif (isset($bg['position']) && is_string($bg['position']) && isset(self::POSITIONS[$bg['position']])) {
            $position = self::POSITIONS[$bg['position']];
        }
        $out[] = 'background-position:' . $position;
    }

    /** Numeric parts of `effects`: group => field => [min, max, unit]. */
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
        }
        return false;
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
        $parts[] = trim((string) $sh['color']);
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
                    self::checkNumberGroup($value, self::TRANSFORM + ['translate_x' => null, 'translate_y' => null], $p, $errors, true);
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
    public static function declarations(array $style, ?string $backgroundUrl = null): string
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
        if (isset($style['dimensions']) && is_array($style['dimensions'])) {
            self::emitFields($style['dimensions'], self::fields()['dimensions'], $out);
        }
        self::emitBackgroundImage($style, $backgroundUrl, $out);
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
            if (!array_key_exists($field, $values) || !self::fieldAccepts($values[$field], $def)) {
                continue;
            }
            $v = $values[$field];
            $css = match ($def[0]) {
                'map'  => $def[2][$v],
                'int'  => isset($def[4]) ? sprintf($def[4], $v) : (string) $v,
                'length' => trim((string) $v),
                default => (string) $v,
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

    // ── interaction states ────────────────────────────────────────────────────

    /** Style keys a state overlay may carry (a deliberately small, safe subset). */
    private const STATE_KEYS = ['color', 'typography', 'background', 'border', 'shadow', 'opacity', 'effects'];

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
                        self::checkField($value, ['color', 'color'], $kp, $errors);
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
                        self::checkLimitedObject($value, ['color' => ['color', 'background-color'], 'gradient' => ['gradient']], $kp, $errors);
                        break;
                    case 'border':
                        self::checkLimitedObject($value, ['color' => ['color', 'border-color']], $kp, $errors);
                        break;
                    case 'typography':
                        self::checkLimitedObject($value, ['color' => ['color', 'color']] + $more['typography'], $kp, $errors);
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
        $colorDef = ['color', 'color'];
        if (isset($p['color']) && self::fieldAccepts($p['color'], $colorDef)) {
            $out[] = 'color:' . trim((string) $p['color']);
        }
        $t = is_array($p['typography'] ?? null) ? $p['typography'] : [];
        if (isset($t['color']) && self::fieldAccepts($t['color'], $colorDef)) {
            $out[] = 'color:' . trim((string) $t['color']);
        }
        $bg = is_array($p['background'] ?? null) ? $p['background'] : [];
        if (isset($bg['color']) && self::fieldAccepts($bg['color'], $colorDef)) {
            $out[] = 'background-color:' . trim((string) $bg['color']);
        }
        if (isset($bg['gradient']) && StyleValueGuard::isGradient($bg['gradient'])) {
            $out[] = 'background-image:' . trim((string) $bg['gradient']);
        }
        $bd = is_array($p['border'] ?? null) ? $p['border'] : [];
        if (isset($bd['color']) && self::fieldAccepts($bd['color'], $colorDef)) {
            $out[] = 'border-color:' . trim((string) $bd['color']);
        }
        if (isset($p['shadow']) && is_string($p['shadow']) && StyleValueGuard::isShadow($p['shadow'])) {
            $out[] = 'box-shadow:' . trim($p['shadow']);
        }
        if (isset($p['opacity']) && self::inRange($p['opacity'], 0, 1)) {
            $out[] = 'opacity:' . self::num($p['opacity']);
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
                    $rules[$name] = $decl;
                }
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
